<?php

namespace Static_Mirror;


class Admin {

	static $instance;

	public static function get_instance() {

		if ( ! self::$instance ) {
			$class = get_called_class();
			self::$instance = new $class();
		}

		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_init', array( $this, 'check_form_submission' ) );
		add_action( 'admin_init', array( $this, 'check_manual_mirror' ) );
	}

	/**
	 * Add the Tools page page
	 */
	public function add_tools_page() {
		add_submenu_page( 'tools.php', 'Static Mirrors', 'Static Mirror', 'static_mirror_manage_mirrors', 'static-mirror-tools-page', array( $this, 'output_tools_page' ) );
	}

	public function output_tools_page() {

		include dirname( __FILE__ ) . '/../templates/admin-tools-page.php';
	}

	public function check_manual_mirror() {

		if ( empty( $_GET['action'] ) || $_GET['action'] !== 'static-mirror-create-mirror' ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'static-mirror-create' ) ) {
			wp_die( 'Failed to verify nonce, sorry' );
		}

		Plugin::get_instance()->queue_complete_mirror( 'Manually triggered mirror', 0 );

		wp_safe_redirect( remove_query_arg( array( '_wpnonce', 'action' ) ) );
		exit;
	}

	public function check_form_submission() {

		if ( empty( $_POST['action'] ) || $_POST['action'] !== 'update-static-mirror' ) {
			return;
		}

		check_admin_referer( 'static-mirror.update' );

		$urls = array_filter(
			array_map( 'esc_url_raw', explode( "\n", $_POST['static-mirror-urls'] ) )
		);

		Plugin::get_instance()->set_base_urls( $urls );
	}
}