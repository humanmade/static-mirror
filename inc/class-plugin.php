<?php 

namespace Static_Mirror;

class Plugin {

	private static $instance;
	private $queued = false;
	private $changelog = array();

	public function get_instance() {

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
		return get_option( 'static_mirror_base_urls', array( get_option( 'siteurl' ) ) );
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

		return $uploads_dir['basedir'] . '/mirrors';
	}

	/**
	 * Get a list of previously created mirrors
	 * 
	 * @return Array
	 */
	public function get_mirrors() {
		return get_option( 'static_mirror_mirrors', array() );
	}

	/**
	 * Setup the hooks that will be used to trigger a mirror. 
	 *
	 * A hook may be something like publish_post, which will "queue" a mirror to be made 
	 * at the end of the script.
	 * 
	 */
	public function setup_trigger_hooks() {

		add_action( 'save_post', function( $post_id, $post, $update ) {

			if ( $post->post_status != 'publish' ) {
				return;
			}

			$post_type_object = get_post_type_object( $post->post_type );

			$this->queue_mirror( sprintf( 
				'The %s %s was %s.',
				$post_type_object->labels->singular_name,
				$post->post_title,
				$update ? 'updated' : 'published' 
			) );

		}, 10, 3 );

		add_action( 'set_object_terms', function( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {

			$post = get_post( $object_id );
			$post_type_object = get_post_type_object( $post->post_type );

			if ( $post->post_status != 'publish' ) {
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

			error_log( print_r($terms, true));

			$this->queue_mirror( sprintf( 
				'The %s %s was assigned the %s %s.',
				$post_type_object->labels->singular_name,
				$post->post_title,
				$taxonomy_object->labels->name,
				implode( ', ' , $terms )
			) );

		}, 10, 6 );
	}

	/**
	 * Queue a mirror of the site
	 *
	 * The mirrorer will be run on the end of the script execution to allow other
	 * code to queue mirrors too. 
	 *
	 * @param String $changelog A changelog of what happened to cause a mirror.
	 */
	public function queue_mirror( $changelog ) {

		if ( ! $this->queued ) {
			add_action( 'shutdown', array( $this, 'mirror_on_shutdown' ) );
		}

		$this->queued = true;
		$this->changelog[] = $changelog;
	}

	/**
	 * Mirror the site on shutdown.
	 * @return WP_Error|null
	 */
	public function mirror_on_shutdown() {

		/**
		 * We don't want to block on shutdown, so let's send the body if we can
		 */
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$status = $this->mirror( $this->changelog );

		if ( is_wp_error( $status ) ) {
			update_option( 'static_mirror_last_error', $status->get_error_message() );
			return;
		}

		delete_option( 'static_mirror_last_error' );
	}

	public function mirror( Array $changelog ) {

		$mirrorer = new Mirrorer();

		$destination = $this->get_destination_directory() . date( '/Y/m/j/H-i-s/' );
		$status = $mirrorer->create( $this->get_base_urls(), $destination );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		//save the changelog in a file too
		file_put_contents( $destination . 'changelog.txt', implode( "\n", $changelog ) );

		$mirror = array(
			'dir' => $destination,
			'date' => time(),
			'changelog' => $changelog
		);

		$mirrors = $this->get_mirrors();
		$mirrors[] = $mirror;

		update_option( 'static_mirror_mirrors', $mirrors );
	}
}