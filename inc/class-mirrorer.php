<?php

namespace Static_Mirror;

use Exception;
use WP_Error;

class Mirrorer {

	/**
	 * Create a static mirror of the site by given urls
	 *
	 * @param  Array  $urls
	 * @param  String $destination
	 * @param  bool   Whether to make the mirror recursivly crawl pages
	 * @throws Exception
	 * @return void
	 */
	public function create( Array $urls, $destination, $recursive ) {

		static::check_dependancies();

		$temp_destination = sys_get_temp_dir() . '/' . 'static-mirror-' . rand( 0,99999 );

		wp_mkdir_p( $destination );

		$mirror_cookies = apply_filters( 'static_mirror_crawler_cookies', array( 'wp_static_mirror' => 1 ) );
		$resource_domains = apply_filters( 'static_mirror_resource_domains', array() );

		$cookie_string = implode( ';', array_map( function( $v, $k ) {
			return $k . '=' . $v;
		}, $mirror_cookies, array_keys( $mirror_cookies ) ) );

		foreach ( $urls as $url ) {

			$allowed_domains = $resource_domains;
			$allowed_domains[] = parse_url( $url )['host'];

			// Wget args. Broken into an array for better readability.
			$args = array(
				sprintf( '--user-agent="%s"', 'WordPress/Static-Mirror; ' . get_bloginfo( 'url' ) ),
				'--no-clobber', // Prevent multiple versions of files, don't download a file if already exists.
				'--page-requisites', // Download all necessary files.
				'--convert-links', // Rewrite links so the downloaded version is functional and independent of original.
				'--backup-converted', // Keep copy of file prior to converting links as this is mangling image srccset.
				sprintf( '%s', $recursive ? '--recursive' : '' ),
				'-erobots=off', // Ignore robots.
				'--restrict-file-names=windows',
				sprintf(
					'--reject-regex "%s"',
					implode(
						'|',
						[
							'.+\/feed\/?$',
							'.+\/wp-json\/?(.+)?$',
						]
					)
				),
				'--html-extension',
				'--content-on-error',
				'--trust-server-names', // Prevent duplicate files for redirected pages.
				sprintf( '--header "Cookie: %s"', $cookie_string ),
				'--span-hosts',
				sprintf( '--domains="%s"', implode( ',', $allowed_domains ) ), // Given span hosts, restrict to defined domains.
				sprintf( '--directory-prefix=%s', escapeshellarg( $temp_destination ) ),
			);

			// Allow bypassing cert check for local.
			if ( defined( 'SM_NO_CHECK_CERT' ) && SM_NO_CHECK_CERT ) {
				$args[] = '--no-check-certificate';
			}

			$cmd = sprintf(
				'wget %s %s 2>&1',
				implode( ' ', $args ),
				escapeshellarg( esc_url_raw( $url ) )
			);

			$data = shell_exec( $cmd );

			// we can infer the command failed if the temp dir does not exist.
			if ( ! is_dir( $temp_destination ) ) {
				throw new Exception( 'wget command failed to return any data (cmd: ' . $cmd . ', data: ' . $data . ')' );
			}

		}

		static::move_directory( untrailingslashit( $temp_destination ), untrailingslashit( $destination ) );

	}

	/**
	 * Copies contents from $source to $dest, optionally ignoring SVN meta-data
	 * folders (default).
	 * @param string $source
	 * @param string $dest
	 * @return boolean true on success false otherwise
	 */
	public static function move_directory( $source, $dest ) {

		$sourceHandle = opendir( $source );

		if ( ! $sourceHandle ) {
			return false;
		}

		while ( $file = readdir( $sourceHandle ) ) {
			if ( $file == '.' || $file == '..' ) {
				continue;
			}

			if ( is_dir( $source . '/' . $file ) ) {

				wp_mkdir_p( $dest . '/' . $file );

				self::move_directory( $source . '/' . $file, $dest . '/' . $file );
			} else {

				// we want to get the mimetype of the file as wget will not use extensions
				// very well.
				$options = stream_context_get_options( stream_context_get_default() );

				if ( pathinfo( $source . '/' . $file, PATHINFO_EXTENSION ) === 'html' && isset( $options['s3'] ) ) {
					$finfo = finfo_open( FILEINFO_MIME_TYPE );
					$mimetype = finfo_file( $finfo, $source . '/' . $file );
					finfo_close($finfo);

					$options = stream_context_get_options( stream_context_get_default() );
					$options['s3']['ContentType'] = $mimetype;
					$context = stream_context_create( $options );

					@copy( $source . '/' . $file, $dest . '/' . $file, $context );
				} else {
					@copy( $source . '/' . $file, $dest . '/' . $file );
				}

				unlink( $source . '/' . $file );
			}

		}

		return true;
	}

	/**
	 * Check if we have all the needed dependancies for the mirroring
	 * @throws Exception
	 * @return void
	 */
	public static function check_dependancies() {

		static::is_shell_exec_available();

		if ( ! is_null( shell_exec( 'hash wget 2>&1' ) ) ) {
			throw new Exception( 'wget is not available.' );
		}

	}

	/**
	 * Check whether shell_exec has been disabled.
	 *
	 * @throws Exception
	 * @return void
	 */
	private static function is_shell_exec_available() {

		// Are we in Safe Mode
		if ( self::is_safe_mode_active() )
			throw new Exception( 'Safe mode is active.' );

		// Is shell_exec or escapeshellcmd or escapeshellarg disabled?
		if ( array_intersect( array( 'shell_exec', 'escapeshellarg', 'escapeshellcmd' ), array_map( 'trim', explode( ',', @ini_get( 'disable_functions' ) ) ) ) )
			throw new Exception( 'Shell exec is disabled via disable_functions.' );

		// Functions can also be disabled via suhosin
		if ( array_intersect( array( 'shell_exec', 'escapeshellarg', 'escapeshellcmd' ), array_map( 'trim', explode( ',', @ini_get( 'suhosin.executor.func.blacklist' ) ) ) ) )
			throw new Exception( 'Shell exec is disabled via Suhosin.' );

		// Can we issue a simple echo command?
		if ( ! @shell_exec( 'echo backupwordpress' ) )
			throw new Exception( 'Shell exec is not functional.' );
	}

	/**
	 * Check whether safe mode is active or not
	 *
	 * @param string $ini_get_callback
	 * @return bool
	 */
	private static function is_safe_mode_active( $ini_get_callback = 'ini_get' ) {

		if ( ( $safe_mode = @call_user_func( $ini_get_callback, 'safe_mode' ) ) && strtolower( $safe_mode ) != 'off' )
			return true;

		return false;

	}
}
