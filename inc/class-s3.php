<?php
/**
 * Integration layer for S3 Uploads plugin.
 *
 * Provides optimised methods for interacting with S3 when S3 Uploads is available.
 */

namespace Static_Mirror;

use Aws;

class S3 {

	/**
	 * The S3 client.
	 *
	 * @var Aws\S3\S3Client
	 */
	protected $client;

	/**
	 * Create a new S3 client.
	 */
	public function __construct() {
		// Create client using S3 Uploads constants.
		$client_args = [
			'version' => '2006-03-01',
			'region'  => S3_UPLOADS_REGION,
		];

		if ( defined( 'S3_UPLOADS_ENDPOINT' ) ) {
			$client_args['endpoint'] = S3_UPLOADS_ENDPOINT;
		}

		if ( defined( 'S3_UPLOADS_KEY' ) && defined( 'S3_UPLOADS_SECRET' ) ) {
			$client_args['credentials'] = [
				'key' => S3_UPLOADS_KEY,
				'secret' => S3_UPLOADS_SECRET,
			];
		}

		// Apply any modifications from the current app / environment.
		$client_args = apply_filters( 's3_uploads_s3_client_params', $client_args );

		$this->client = new Aws\S3\S3Client( $client_args );
	}

	/**
	 * Checks if S3 uploads is available and configured.
	 *
	 * @return boolean
	 */
	public static function is_supported() : bool {
		return defined( 'S3_UPLOADS_REGION' )
			&& defined( 'S3_UPLOADS_BUCKET' )
			&& class_exists( 'Aws\S3\S3Client' )
			&& in_array( 's3', stream_get_wrappers(), true );
	}

	/**
	 * Batch delete items by prefix.
	 *
	 * @param string $path The path to delete items from.
	 * @return boolean
	 */
	public function rrmdir( string $path ) : bool {
		try {
			// S3 Uploads bucket can be in the format of `bucket/path/to/uploads`.
			$bucket_parts = explode( '/', S3_UPLOADS_BUCKET, 2 );

			$list_params = [
				'Bucket' => $bucket_parts[0],
				'Prefix' => ltrim( ( $bucket_parts[1] ?? '' ) . '/' . ltrim( $path, '/' ), '/' ),
			];

			$delete = Aws\S3\BatchDelete::fromListObjects( $this->client, $list_params );

			// Force synchronous completion
			$delete->delete();
			return true;
		} catch ( Aws\S3\Exception\DeleteMultipleObjectsException $e ) {
			trigger_error( $e->getMessage(), E_USER_WARNING );
			return false;
		}
	}
}
