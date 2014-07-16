<?php

/*
Plugin Name: Static Mirror
Description: Create a static mirror of your site
Author: Human Made Limited
Version: 1.0
Author URI: http://hmn.md
*/

require_once dirname( __FILE__ ) . '/inc/class-plugin.php';
require_once dirname( __FILE__ ) . '/inc/class-mirrorer.php';
require_once dirname( __FILE__ ) . '/inc/class-admin.php';
require_once dirname( __FILE__ ) . '/inc/class-admin-list-table.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once dirname( __FILE__ ) . '/inc/class-wp-cli-command.php';

	WP_CLI::add_command( 'static-mirror', 'Static_Mirror\\WP_CLI_Command' );
}

add_action( 'init', array( Static_Mirror\Plugin::get_instance(), 'setup_trigger_hooks' ), 999 );
add_action( 'admin_menu', array( Static_Mirror\Admin::get_instance(), 'add_tools_page' ), 1 );