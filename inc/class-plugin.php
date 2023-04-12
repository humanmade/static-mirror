<?php

namespace Static_Mirror;

use DirectoryIterator;
use Exception;
use WP_Query;
use WP_CLI;

class Plugin {

	private static $instance;
	private $queued = false;
	private $cron_queued = false;
	private $changelog = array();

	/**
	 * @return Plugin
	 */
	public static function get_instance() {

		if ( ! self::$instance ) {
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
	 * @param array $args Arguments to pass to `get_posts()`.
	 * @return Array
	 */
	public function get_mirrors( array $args = [] ) {

		$args = array_merge(
			array(
				'post_type'   => 'static-mirror',
				'showposts'   => -1,
				'post_status' => 'private',
			),
			$args
		);
		$mirrors = get_posts( $args );

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
	 * Get the default timestamp for scheduled tasks.
	 */
	public function get_daily_schedule_time() {
		return apply_filters( 'static_mirror_daily_schedule_time', strtotime( 'today 11:59pm' ) );
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
			wp_schedule_event( $this->get_daily_schedule_time(), 'daily', 'static_mirror_create_mirror' );
		}

		if ( ! wp_next_scheduled( 'static_mirror_delete_expired_mirrors' ) ) {
			wp_schedule_event( $this->get_daily_schedule_time(), 'daily', 'static_mirror_delete_expired_mirrors' );
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

			$this->schedule_mirror_url_cron(
				sprintf(
					'The %s %s was %s.',
					$post_type_object->labels->singular_name,
					$post->post_title,
					$update ? 'updated' : 'published'
				),
				get_permalink( $post_id )
			);

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

			$this->schedule_mirror_url_cron(
				sprintf(
					'The %s %s was assigned the %s %s.',
					$post_type_object->labels->singular_name,
					$post->post_title,
					$taxonomy_object->labels->name,
					implode( ', ' , $terms )
				),
				get_permalink( $object_id )
			);
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

	/**
	 * Add a cron job to mirror a single URL.
	 *
	 * @param string $changelog Message to add to the changelog.
	 * @param string $url       URL to mirror.
	 */
	public function schedule_mirror_url_cron( $changelog, $url ) {
		if ( $this->cron_queued ) {
			return;
		}

		$this->cron_queued = true;

		wp_schedule_single_event(
			time(),
			'static_mirror_create_mirror_for_url',
			[
				[
					[
						'date' => time(),
						'text' => $changelog,
					],
				],
				[
					$url,
				],
			]
		);
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

		return $this->mirror( $changelog, $this->get_base_urls(), true );
	}

	public function mirror( Array $changelog, Array $urls, $recursive = false ) {

		$mirrorer = new Mirrorer();
		$start_time = time();

		$destination = $this->get_destination_directory() . date( '/Y/m/j/H-i-s/' );
		$mirrorer->create( $urls, $destination, $recursive );

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

	/**
	 * Handle deleting expired mirrors.
	 *
	 * Works via the CLI and WP Cron.
	 *
	 * @param array $args Optional CLI arguments.
	 * @return void
	 */
	public function delete_expired_mirrors( $args = [] ) {

		$delete_before = date( 'Y-m-d', time() - SM_TTL );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::line( "Deleting mirrors created before $delete_before" );
		}

		$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;

		$args = array(
			'post_type' => 'static-mirror',
			'showposts' => $doing_cron ? 400 : -1, // Set a high limit for cron jobs, run for everything on a CLI invocation.
			'post_status' => 'private',
			'date_query' => array(
				// Before TTL with a safety buffer.
				'before' => $delete_before,
			),
			'order' => 'ASC',
			'order_by' => 'date',
			'fields' => 'ids',
		);

		$mirrors = new WP_Query( $args );
		$errors = [];

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// Show progress.
			WP_CLI::line( "Found {$mirrors->found_posts} expired mirrors to delete." );
			// Don't prompt if running via cavalcade, WP CLI is also in play.
			if ( ! $doing_cron ) {
				WP_CLI::confirm( 'Are you sure you want to delete the expired mirrors?', $args );
			}
			$progress = WP_CLI\Utils\make_progress_bar( 'Deleting mirrors', $mirrors->found_posts );
		}

		// The mirrors directory is at the same level as uploads in S3 / wp-content directory.
		$base_dir = untrailingslashit( dirname( wp_upload_dir()['basedir'] ) );

		$processed = 0;

		foreach ( $mirrors->posts as $mirror_id ) {
			// Avoid any potential for removing non local files e.g. after a db import
			// without a search replace for S3 URLs.
			$dir_rel = get_post_meta( $mirror_id, '_dir_rel', true );
			$dir = $base_dir . $dir_rel;

			// Delete the directory if it exists.
			$deleted = empty( scandir( $dir ) );
			// We use scandir as standard file checks and methods behave differently with
			// S3 due to the stream wrapper and S3's lack of real directories.
			if ( ! $deleted ) {
				if ( S3::is_supported() ) {
					$s3 = new S3();
					$deleted = $s3->rrmdir( $dir_rel );
				} else {
					$deleted = self::rrmdir( $dir );
				}
			}

			if ( ! $deleted ) {
				trigger_error( 'Failed to delete mirror directory: ' . $dir, E_USER_WARNING );
				$errors[] = [ 'id' => $mirror_id, 'dir' => $dir, 'type' => 'dir' ];
			}

			// Delete the post if the directory was deleted or already missing.
			if ( $deleted ) {
				$deleted_post = wp_delete_post( $mirror_id, true );
				if ( ! $deleted_post ) {
					trigger_error( 'Failed to delete mirror post: ' . $mirror_id, E_USER_WARNING );
					$errors[] = [ 'id' => $mirror_id, 'dir' => $dir, 'type' => 'post' ];
				}
			}

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				$progress->tick();
			}

			$processed++;

			// Clean the cache to reduce memory usage every 100 posts.
			if ( function_exists( 'wp_clear_object_cache' ) && $processed % 100 === 0 ) {
				wp_clear_object_cache();
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$progress->finish();
			if ( ! empty( $errors ) ) {
				WP_CLI::error_multi_line( array_map( function ( $error ) {
					return "Failed to delete mirror {$error['type']} with ID {$error['id']} at {$error['dir']}.";
				}, $errors ) );
			}
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Path to directory.
	 * @return bool
	 */
	public static function rrmdir( string $path ) : bool {
		try {
			$iterator = new DirectoryIterator( $path );
			foreach ( $iterator as $fileinfo ) {
				if ( $fileinfo->isDot() ) {
					continue;
				}
				if ( $fileinfo->isDir() && self::rrmdir( $fileinfo->getPathname() ) ) {
					rmdir( $fileinfo->getPathname() );
				}
				if( $fileinfo->isFile() ) {
					unlink( $fileinfo->getPathname() );
				}
			}
		} catch ( Exception $e ){
			trigger_error( $e->getMessage(), E_USER_WARNING );
			return false;
		}

		return true;
	}
}
