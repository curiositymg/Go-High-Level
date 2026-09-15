<?php
/**
 * Local copies of headshots that GoHighLevel will not serve publicly.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloads protected headshots into the uploads directory.
 *
 * A file-upload custom field stores a URL like
 * `https://services.leadconnectorhq.com/documents/download/<id>`. That is an API
 * endpoint, not a public asset: it answers only to a request carrying the API
 * token, so a visitor's browser gets a 401 and the <img> fails silently. The
 * file is fetched here, with the token, and the card points at the local copy.
 */
class GHLD_Photos {

	const SUBDIR   = 'gohighlevel-photos';
	const MAX_SIZE = 8388608;

	/**
	 * Whether a URL has to be fetched server-side to be usable.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function needs_local_copy( $url ) {
		$url = (string) $url;

		if ( '' === $url || 0 === strpos( $url, self::base_url() ) ) {
			return false;
		}

		$protected = ( false !== strpos( $url, 'leadconnectorhq.com' ) && false !== strpos( $url, '/documents/' ) )
			|| false !== strpos( $url, 'services.leadconnectorhq.com/documents/download' );

		/**
		 * Filter whether a headshot URL needs fetching server-side.
		 *
		 * @param bool   $protected Whether the URL is behind the API token.
		 * @param string $url       The URL.
		 */
		return (bool) apply_filters( 'ghld_photo_needs_local_copy', $protected, $url );
	}

	/**
	 * Local path for the directory holding cached headshots.
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
	}

	/**
	 * Public URL for that directory.
	 *
	 * @return string
	 */
	public static function base_url() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::SUBDIR;
	}

	/**
	 * Filename a given contact/URL pair caches to.
	 *
	 * The URL is hashed into the name so a replaced upload becomes a different
	 * file rather than being served from a stale copy.
	 *
	 * @param string $contact_id Contact ID.
	 * @param string $url        Source URL.
	 * @param string $extension  File extension, without the dot.
	 * @return string
	 */
	public static function filename( $contact_id, $url, $extension = 'jpg' ) {
		$contact_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $contact_id );
		$extension  = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $extension ) );

		return $contact_id . '-' . substr( md5( (string) $url ), 0, 12 ) . '.' . ( '' === $extension ? 'jpg' : $extension );
	}

	/**
	 * Return the local URL for a headshot, downloading it if necessary.
	 *
	 * @param string $url        Source URL.
	 * @param string $contact_id Contact ID.
	 * @param bool   $download   False to only report an existing copy.
	 * @return string Local URL, or '' when there is nothing usable.
	 */
	public static function localize( $url, $contact_id, $download = true ) {
		$url = (string) $url;
		if ( '' === $url || '' === (string) $contact_id ) {
			return '';
		}

		// An existing copy under any known extension wins, with no request.
		foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) as $extension ) {
			$name = self::filename( $contact_id, $url, $extension );
			if ( file_exists( trailingslashit( self::base_dir() ) . $name ) ) {
				return trailingslashit( self::base_url() ) . $name;
			}
		}

		if ( ! $download ) {
			return '';
		}

		return self::download( $url, $contact_id );
	}

	/**
	 * Fetch a headshot with the API token and store it.
	 *
	 * @param string $url        Source URL.
	 * @param string $contact_id Contact ID.
	 * @return string Local URL, or '' on failure.
	 */
	protected static function download( $url, $contact_id ) {
		$headers = array( 'Accept' => 'image/*' );
		$token   = GHLD_Settings::token();

		// Only send credentials to GoHighLevel itself.
		if ( '' !== $token && false !== strpos( $url, 'leadconnectorhq.com' ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
			$headers['Version']       = GHLD_Client::V2_API_HEADER;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( $code < 200 || $code >= 300 || '' === $body ) {
			return '';
		}
		if ( strlen( $body ) > self::MAX_SIZE ) {
			return '';
		}
		if ( 0 !== strpos( $type, 'image/' ) ) {
			return '';
		}

		$extension = self::extension_for( $type );
		$directory = self::base_dir();

		if ( ! wp_mkdir_p( $directory ) ) {
			return '';
		}

		// Nothing in here should be browsable as a listing.
		$index = trailingslashit( $directory ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$name = self::filename( $contact_id, $url, $extension );
		$path = trailingslashit( $directory ) . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, $body ) ) {
			return '';
		}

		return trailingslashit( self::base_url() ) . $name;
	}

	/**
	 * File extension for an image content type.
	 *
	 * @param string $type Content-Type header.
	 * @return string
	 */
	public static function extension_for( $type ) {
		$type = strtolower( trim( (string) $type ) );
		$type = explode( ';', $type );
		$type = trim( $type[0] );

		$map = array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : 'jpg';
	}

	/**
	 * Delete every cached headshot.
	 *
	 * @return void
	 */
	public static function flush() {
		$directory = self::base_dir();
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$files = glob( trailingslashit( $directory ) . '*' );
		foreach ( (array) $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
