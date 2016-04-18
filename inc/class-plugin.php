<?php

namespace Static_Mirror;

class Plugin {

	private static $instance;
	private $queued = false;
	private $changelog = array();


	public static function get_instance() {

		if ( ! self::$instance ) {
			$class = get_called_class();
			self::$instance = new Plugin();
		}

		return self::$instance;
	}

	/**
	 * Get the Base URLs that will be mirrored
	 *
	 * As all urls on a site may not be cross linked, crawling the site might not
	 * discover all pages on the site, so we can pass multiple bases to
	 * catch everything
	 *
	 * @return Array
	 */
	public function get_base_urls() {
		return get_option( 'static_mirror_base_urls', array( home_url() ) );
	}

	/**
	 * Set the base URLs that will be mirrored
	 *
	 * @param Array $urls
	 */
	public function set_base_urls( Array $urls ) {
		update_option( 'static_mirror_base_urls', $urls );
	}

	/**
	 * Get the destination where the site mirrors will be stored
	 *
	 * @return string
	 */
	public function get_destination_directory() {
		$uploads_dir = wp_upload_dir();

		return dirname( $uploads_dir['basedir'] ) . '/mirrors';
	}

	/**
	 * Get a list of previously created mirrors
	 *
	 * @return Array
	 */
	public function get_mirrors() {

		$mirrors = get_posts( array(
			'post_type'   => 'static-mirror',
			'showposts'   => -1,
			'post_status' => 'private',
		) );

		$wp_upload_dir = wp_upload_dir();
		$basurl = apply_filters( 'static_mirror_baseurl', dirname( $wp_upload_dir['baseurl'] ) );
		return array_map( function( $post ) use ( $basurl ) {

			$url = $basurl . get_post_meta( $post->ID, '_dir_rel', true );
			return array(
				'dir'       => get_post_meta( $post->ID, '_dir', true ),
				'date'      => strtotime( $post->post_date_gmt ),
				'changelog' => get_post_meta( $post->ID, '_changelog', true ),
				'url'       => $url,
			);
		}, $mirrors );
	}

	/**
	 * Setup the hooks that will be used to trigger a mirror.
	 *
	 * A hook may be something like publish_post, which will "queue" a mirror to be made
	 * at the end of the script.
	 *
	 */
	public function setup_trigger_hooks() {

		// Timeout sttic mirrors for 60 minutes
		if ( $current = get_option( 'static_mirror_in_progress' ) ) {
			if ( $current['time'] < strtotime( '-60 minutes' ) ) {
				delete_option( 'static_mirror_in_progress' );
			}
		}

		if ( ! wp_next_scheduled( 'static_mirror_create_mirror' ) ) {
			wp_schedule_event( apply_filters( 'static_mirror_daily_schedule_time', strtotime( 'today 11:59pm' ) ), 'daily', 'static_mirror_create_mirror' );
		}

		// don't trigger the hooks if the request is from a static mirrir happening
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) && strpos( $_SERVER['HTTP_USER_AGENT'], 'WordPress/Static-Mirror' ) !== false ) {
			return;
		}

		$this->register_post_type();

		add_action( 'save_post', function( $post_id, $post, $update ) {

			if ( $post->post_status != 'publish' ) {
				return;
			}

			$post_type_object = get_post_type_object( $post->post_type );

			if ( $post_type_object->public != true ) {
				return;
			}

			$this->queue_mirror_url( sprintf(
				'The %s %s was %s.',
				$post_type_object->labels->singular_name,
				$post->post_title,
				$update ? 'updated' : 'published'
			), get_permalink( $post_id ), $post_id );

		}, 10, 3 );

		add_action( 'set_object_terms', function( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {

			$post = get_post( $object_id );
			$post_type_object = get_post_type_object( $post->post_type );

			if ( $post->post_status != 'publish' ) {
				return;
			}

			if ( $post_type_object->public != true ) {
				return;
			}

			sort( $tt_ids );
			sort( $old_tt_ids );

			if ( $tt_ids == $old_tt_ids ) {
				return;
			}

			$taxonomy_object = get_taxonomy( $taxonomy );

			if ( $taxonomy_object->public != true ) {
				return;
			}

			$this->queue_mirror_url( sprintf(
				'The %s %s was assigned the %s %s.',
				$post_type_object->labels->singular_name,
				$post->post_title,
				$taxonomy_object->labels->name,
				implode( ', ' , $terms )
			), get_permalink( $object_id ), $object_id );

		}, 10, 6 );
	}

	public function setup_capabilities() {

		if ( get_option( 'static_mirror_added_roles' ) ) {
			return;
		}

		$admin = get_role( 'administrator' );

		if ( ! $admin ) {
			return;
		}

		$admin->add_cap( 'static_mirror_manage_mirrors' );

		update_option( 'static_mirror_added_roles', true );
	}

	protected function register_post_type() {
		$labels = array(
			'not_found'           => 'Not found',
			'not_found_in_trash'  => 'Not found in Trash',
		);
		$args = array(
			'labels'              => $labels,
			'supports'            => array( ),
			'public'              => false,
			'show_ui'             => false,
		);
		register_post_type( 'static-mirror', $args );
	}

	public function queue_mirror_url( $changelog, $url, $key = null ) {

		$this->changelog[] = array( 'date' => time(), 'text' => $changelog );

		if ( $key ) {
			$this->urls[$key] = $url;
		} else {
			$this->urls[] = $url;
		}

		if ( ! $this->queued ) {
			add_action( 'shutdown', array( $this, 'mirror_on_shutdown' ), 11 );
			$this->queued = true;
		}
	}

	public function mirror_on_shutdown() {

		/**
		 * We don't want to block on shutdown, so let's send the body if we can
		 */
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$status = $this->mirror( $this->changelog, $this->urls, false );

		if ( is_wp_error( $status ) ) {
			update_option( 'static_mirror_last_error', $status->get_error_message() );
			trigger_error( $status->get_error_code() . ': ' . $status->get_error_message(), E_USER_WARNING );
			return;
		}

		delete_option( 'static_mirror_last_error' );
	}

	/**
	 * Queue a mirror of the site
	 *
	 * The mirrorer will be run on the end of the script execution to allow other
	 * code to queue mirrors too.
	 *
	 * @param String $changelog A changelog of what happened to cause a mirror.
	 */
	public function queue_complete_mirror( $changelog, $when = 60 ) {

		// we queue one to happen in 5 minutes, if one is already queued, we push that back
		$next_queue_changelog   = get_option( 'static_mirror_next_changelog', array() );
		$next_queue_changelog[] = array( 'date' => time(), 'text' => $changelog );

		update_option( 'static_mirror_next_changelog', $next_queue_changelog );

		wp_schedule_single_event( time() + $when, 'static_mirror_create_mirror' );
	}

	public function mirror_on_cron() {

		$changelog = get_option( 'static_mirror_next_changelog', array() );
		delete_option( 'static_mirror_next_changelog' );

		if ( ! $changelog ) {
			$changelog = array( array( 'date' => time(), 'text' => 'Scheduled Mirror' ) );
		}

		update_option( 'static_mirror_in_progress', array( 'time' => time(), 'changelog' => $changelog ) );

		$status = $this->complete_mirror( $changelog );

		delete_option( 'static_mirror_in_progress' );

		if ( is_wp_error( $status ) ) {
			update_option( 'static_mirror_last_error', $status->get_error_message() );
			trigger_error( $status->get_error_code() . ': ' . $status->get_error_message(), E_USER_WARNING );
			return;
		}

		delete_option( 'static_mirror_last_error' );
	}

	public function complete_mirror( Array $changelog ) {

		$this->mirror( $changelog, $this->get_base_urls(), true );
	}

	public function mirror( Array $changelog, Array $urls, $recursive = false ) {

		$mirrorer = new Mirrorer();
		$start_time = time();

		$destination = $this->get_destination_directory() . date( '/Y/m/j/H-i-s/' );
		$status = $mirrorer->create( $urls, $destination, $recursive );

		/**
		 * Running mirror() probably took quite a while, so lets
		 * throw away the internal object cache, as calling
		 * *_option() will push stale data to the object cache and
		 * cause all sorts of nasty prodblems.
		 *
		 * @see https://core.trac.wordpress.org/ticket/25623
		 */
		global $wp_object_cache;
		$wp_object_cache->cache = array();

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		// make an index page
		$files = array_map( function( $url ) {
			$url = parse_url( $url );
			return $url['host'] . untrailingslashit( $url['path'] );
		}, $urls );

		$end_time = time();
		ob_start();

		include dirname( __FILE__ ) . '/template-index.php';

		file_put_contents( $destination . 'index.html', ob_get_clean() );

		$post_id = wp_insert_post( array(
			'post_type' => 'static-mirror',
			'post_title' => date( 'c' ),
			'post_status' => 'private'
		) );

		$uploads_dir = wp_upload_dir();

		update_post_meta( $post_id, '_changelog', $changelog );
		update_post_meta( $post_id, '_dir', $destination );
		update_post_meta( $post_id, '_dir_rel', str_replace( dirname( $uploads_dir['basedir'] ), '', $destination ) );
		update_post_meta( $post_id, 'mirror_start', $start_time );
		update_post_meta( $post_id, 'mirror_end', $end_time );

		return $uploads_dir['basedir'] . '/mirrors';
	}
}
