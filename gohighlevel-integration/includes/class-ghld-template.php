<?php
/**
 * Template loading with theme overrides.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads a template from the child theme, then the parent theme, then the plugin.
 *
 * Copy any file from the plugin's `templates/` directory into
 * `your-theme/gohighlevel-integration/` to override it.
 */
class GHLD_Template {

	/**
	 * Render a template to a string.
	 *
	 * @param string $name Template name without extension.
	 * @param array  $data Variables exposed to the template as `$data`.
	 * @return string
	 */
	public static function get( $name, array $data = array() ) {
		$file = self::locate( $name );
		if ( '' === $file ) {
			return '';
		}

		ob_start();
		// Templates read from $data; keeping it in one variable avoids extract().
		include $file;

		return (string) ob_get_clean();
	}

	/**
	 * Echo a template.
	 *
	 * @param string $name Template name without extension.
	 * @param array  $data Variables exposed to the template as `$data`.
	 * @return void
	 */
	public static function render( $name, array $data = array() ) {
		// Template output is escaped inside the template files themselves.
		echo self::get( $name, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Find the file backing a template name.
	 *
	 * @param string $name Template name without extension.
	 * @return string Absolute path, or '' when nothing matches.
	 */
	public static function locate( $name ) {
		$name = sanitize_file_name( $name );
		if ( '' === $name ) {
			return '';
		}

		$candidates = array(
			trailingslashit( get_stylesheet_directory() ) . 'gohighlevel-integration/' . $name . '.php',
			trailingslashit( get_template_directory() ) . 'gohighlevel-integration/' . $name . '.php',
			GHLD_PATH . 'templates/' . $name . '.php',
		);

		/**
		 * Filter the template lookup order.
		 *
		 * @param string[] $candidates Absolute paths, most specific first.
		 * @param string   $name       Template name.
		 */
		$candidates = apply_filters( 'ghld_template_candidates', $candidates, $name );

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}
}
