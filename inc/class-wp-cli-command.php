<?php

namespace Static_Mirror;

class WP_CLI_Command extends \WP_CLI_Command {

	/**
	 * List all the mirrors of the site
	 *
	 * @subcommand list
	 * @synopsis [--showposts=<showposts>] [--paged=<paged>] [--format=<format>] [--fields=<fields>]
	 */
	public function _list( $args, $args_assoc ) {

		$args_assoc = array_merge( array(
			'showposts' => 100,
			'paged' => 1,
			'format' => 'table',
			'fields' => array( 'date', 'changelog', 'dir' )
		), $args_assoc );

		$mirrors = array_map( function( $mirror ) {
			foreach ( $mirror['changelog'] as $key => $change ) {
				if ( ! is_array( $change ) ) {
					// A few very old mirrors only contain the page URL as a text string in the changelog.
					$mirror['changelog'][ $key ] = [
						'text' => $change,
					];
				}
			}

			$mirror['date'] = date( 'c', $mirror['date'] );
			$mirror['changelog'] = implode( ', ', wp_list_pluck( $mirror['changelog'], 'text' ) );

			return $mirror;

		}, Plugin::get_instance()->get_mirrors( [
			'showposts' => $args_assoc['showposts'],
			'paged' => $args_assoc['paged'],
		] ) );

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

		try {
			$plugin->complete_mirror( array( array( 'date' => time(), 'text' => $args_assoc['changelog'] ) ) );
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e );
		}

		\WP_CLI::success( 'Created mirror' );
	}

	/**
	 * Delete expired mirrors of the site
	 *
	 * @subcommand delete-expired
	 */
	public function delete_expired( $args, $args_assoc ) {
		$plugin = Plugin::get_instance();

		try {
			$plugin->delete_expired_mirrors( $args_assoc );
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e );
		}

		\WP_CLI::success( 'Deleted expired mirrors' );
	}
}
