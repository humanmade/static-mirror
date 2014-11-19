<?php

namespace Static_Mirror;

class WP_CLI_Command extends \WP_CLI_Command {

	/**
	 * List all the mirrors of the site
	 * 
	 * @subcommand list
	 * @synopsis [--format=<format>] [--fields=<fields>]
	 */
	public function _list( $args, $args_assoc ) {

		$args_assoc = wp_parse_args( array(
			'format' => 'table',
			'fields' => array( 'date', 'changelog', 'dir' )
		), $args_assoc );

		$mirrors = array_map( function( $mirror ) {

			$mirror['date'] = date( 'c', $mirror['date'] );
			$mirror['changelog'] = implode( ', ', wp_list_pluck( $mirror['changelog'], 'text' ) );

			return $mirror;

		}, Plugin::get_instance()->get_mirrors() );

		\WP_CLI\Utils\format_items( $args_assoc['format'], $mirrors, $args_assoc['fields'] );
	}

	/**
	 * Create a new mirror of the site
	 * 
	 * @subcommand create-mirror
	 * @synopsis [--changelog=<changelog>]
	 */
	public function create_mirror( $args, $args_assoc ) {

		$args_assoc = wp_parse_args( array(
			'changelog' => 'A manual mirror triggered from CLI'
		), $args_assoc );

		$plugin = Plugin::get_instance();
		$status = $plugin->complete_mirror( array( array( 'date' => time(), 'text' => $args_assoc['changelog'] ) ) );

		if ( is_wp_error( $status ) ) {
			\WP_CLI::error( $status->get_error_message() );
		}

		\WP_CLI::success( 'Created mirror' );
	}
}