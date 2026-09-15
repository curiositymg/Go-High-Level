<?php
/**
 * Minimal WordPress stubs so the plugin's pure logic can be exercised with the
 * plain PHP CLI — no WordPress install, no network.
 *
 * Only the functions the tested classes actually call are defined, and each one
 * mirrors the real behaviour closely enough for the assertions that depend on it
 * (escaping really escapes, sanitizing really strips).
 *
 * @package GoHighLevel_Integration
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

define( 'GHLD_VERSION', 'test' );
define( 'GHLD_FILE', dirname( __DIR__ ) . '/gohighlevel-integration/gohighlevel-integration.php' );
define( 'GHLD_PATH', dirname( __DIR__ ) . '/gohighlevel-integration/' );
define( 'GHLD_URL', 'https://example.test/wp-content/plugins/gohighlevel-integration/' );

$GLOBALS['ghld_test_options']    = array();
$GLOBALS['ghld_test_transients'] = array();

/**
 * Error object.
 */
class WP_Error {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	protected $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	protected $message;

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param mixed  $data    Unused.
	 */
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		unset( $data );
	}

	/**
	 * Message accessor.
	 *
	 * @return string
	 */
	public function get_error_message() {
		return $this->message;
	}

	/**
	 * Code accessor.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->code;
	}
}

/**
 * Error test.
 *
 * @param mixed $thing Candidate.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Option read.
 *
 * @param string $name    Option name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['ghld_test_options'] ) ? $GLOBALS['ghld_test_options'][ $name ] : $default;
}

/**
 * Option write.
 *
 * @param string $name     Option name.
 * @param mixed  $value    Value.
 * @param bool   $autoload Ignored.
 * @return bool
 */
function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	$GLOBALS['ghld_test_options'][ $name ] = $value;

	return true;
}

/**
 * Option delete.
 *
 * @param string $name Option name.
 * @return bool
 */
function delete_option( $name ) {
	unset( $GLOBALS['ghld_test_options'][ $name ] );

	return true;
}

/**
 * Transient read.
 *
 * @param string $name Transient name.
 * @return mixed
 */
function get_transient( $name ) {
	return array_key_exists( $name, $GLOBALS['ghld_test_transients'] ) ? $GLOBALS['ghld_test_transients'][ $name ] : false;
}

/**
 * Transient write.
 *
 * @param string $name       Transient name.
 * @param mixed  $value      Value.
 * @param int    $expiration Ignored.
 * @return bool
 */
function set_transient( $name, $value, $expiration = 0 ) {
	unset( $expiration );
	$GLOBALS['ghld_test_transients'][ $name ] = $value;

	return true;
}

/**
 * Filter pass-through.
 *
 * @param string $tag   Hook name.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $tag, $value ) {
	unset( $tag );

	return $value;
}

/**
 * Action no-op.
 *
 * @return void
 */
function do_action() {}

/**
 * Hook registration no-ops.
 *
 * @return bool
 */
function add_action() {
	return true;
}

/**
 * Hook registration no-op.
 *
 * @return bool
 */
function add_filter() {
	return true;
}

/**
 * Shortcode registration no-op.
 *
 * @return bool
 */
function add_shortcode() {
	return true;
}

/**
 * Translation pass-through.
 *
 * @param string $text   Text.
 * @param string $domain Ignored.
 * @return string
 */
function __( $text, $domain = null ) {
	unset( $domain );

	return $text;
}

/**
 * Plural pass-through.
 *
 * @param string $single Singular.
 * @param string $plural Plural.
 * @param int    $number Count.
 * @param string $domain Ignored.
 * @return string
 */
function _n( $single, $plural, $number, $domain = null ) {
	unset( $domain );

	return ( 1 === (int) $number ) ? $single : $plural;
}

/**
 * Escaped translation echo.
 *
 * @param string $text   Text.
 * @param string $domain Ignored.
 * @return void
 */
function esc_html_e( $text, $domain = null ) {
	unset( $domain );
	echo esc_html( $text );
}

/**
 * Escaped attribute translation echo.
 *
 * @param string $text   Text.
 * @param string $domain Ignored.
 * @return void
 */
function esc_attr_e( $text, $domain = null ) {
	unset( $domain );
	echo esc_attr( $text );
}

/**
 * Escaped translation.
 *
 * @param string $text   Text.
 * @param string $domain Ignored.
 * @return string
 */
function esc_html__( $text, $domain = null ) {
	unset( $domain );

	return esc_html( $text );
}

/**
 * Escaped attribute translation.
 *
 * @param string $text   Text.
 * @param string $domain Ignored.
 * @return string
 */
function esc_attr__( $text, $domain = null ) {
	unset( $domain );

	return esc_attr( $text );
}

/**
 * HTML escape.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Attribute escape.
 *
 * @param string $text Text.
 * @return string
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * URL escape; only http(s), mailto and tel survive.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url( $url ) {
	$url = trim( (string) $url );
	if ( ! preg_match( '#^(https?://|mailto:|tel:|\?|/)#i', $url ) ) {
		return '';
	}

	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

/**
 * Raw URL escape.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url_raw( $url ) {
	$url = trim( (string) $url );

	return preg_match( '#^https?://#i', $url ) ? $url : '';
}

/**
 * Text sanitization.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_text_field( $value ) {
	$value = wp_strip_all_tags( (string) $value );

	return trim( preg_replace( '/[\r\n\t\0\x0B]+/', ' ', $value ) );
}

/**
 * Tag stripper.
 *
 * @param string $value Value.
 * @return string
 */
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

/**
 * Key sanitization.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

/**
 * Slug sanitization.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_title( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );

	return trim( (string) $value, '-' );
}

/**
 * Query var reader.
 *
 * @param string $name    Query var.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_query_var( $name, $default = '' ) {
	unset( $name );

	return $default;
}

/**
 * Home URL.
 *
 * @param string $path Path.
 * @return string
 */
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/**
 * Current-request query arguments.
 *
 * @return string
 */
function add_query_arg() {
	return '/directory/';
}

/**
 * URL parser.
 *
 * @param string $url       URL.
 * @param int    $component Component.
 * @return mixed
 */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * File name sanitization.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_file_name( $value ) {
	return preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $value );
}

/**
 * CSS class sanitization.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_html_class( $value ) {
	return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
}

/**
 * Email sanitization.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_email( $value ) {
	return trim( (string) $value );
}

/**
 * Email validation.
 *
 * @param string $value Value.
 * @return bool
 */
function is_email( $value ) {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}

/**
 * JSON encode.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode( $value ) {
	return json_encode( $value );
}

/**
 * Number formatting.
 *
 * @param int|float $number   Number.
 * @param int       $decimals Decimals.
 * @return string
 */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

/**
 * Trailing slash helper.
 *
 * @param string $value Path.
 * @return string
 */
function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

/**
 * Stylesheet directory (deliberately outside the plugin so overrides miss).
 *
 * @return string
 */
function get_stylesheet_directory() {
	return __DIR__ . '/no-theme';
}

/**
 * Template directory.
 *
 * @return string
 */
function get_template_directory() {
	return __DIR__ . '/no-theme';
}

/**
 * Selected attribute helper.
 *
 * @param mixed $one     Current value.
 * @param mixed $two     Compared value.
 * @param bool  $display Whether to echo.
 * @return string
 */
function selected( $one, $two = true, $display = true ) {
	$result = ( (string) $one === (string) $two ) ? ' selected="selected"' : '';
	if ( $display ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $result;
}

/**
 * Checked attribute helper.
 *
 * @param mixed $one     Current value.
 * @param mixed $two     Compared value.
 * @param bool  $display Whether to echo.
 * @return string
 */
function checked( $one, $two = true, $display = true ) {
	return selected( $one, $two, $display );
}

/**
 * Shortcode attribute merge.
 *
 * @param array  $pairs     Defaults.
 * @param array  $atts      Supplied attributes.
 * @param string $shortcode Ignored.
 * @return array
 */
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	unset( $shortcode );
	$atts = (array) $atts;
	$out  = array();

	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}

	return $out;
}

/**
 * Uploads directory.
 *
 * @return array
 */
function wp_upload_dir() {
	return array(
		'basedir' => sys_get_temp_dir() . '/ghld-test-uploads',
		'baseurl' => 'https://example.test/wp-content/uploads',
	);
}

require_once GHLD_PATH . 'includes/class-ghld-settings.php';
require_once GHLD_PATH . 'includes/class-ghld-client.php';
require_once GHLD_PATH . 'includes/class-ghld-contact.php';
require_once GHLD_PATH . 'includes/class-ghld-photos.php';
require_once GHLD_PATH . 'includes/class-ghld-repository.php';
require_once GHLD_PATH . 'includes/class-ghld-template.php';
require_once GHLD_PATH . 'includes/class-ghld-shortcode.php';
