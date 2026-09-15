<?php
/**
 * Stored settings for the GoHighLevel Integration plugin.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the single `ghld_settings` option and applies wp-config.php
 * constant overrides for the credentials.
 */
class GHLD_Settings {

	const OPTION = 'ghld_settings';

	/**
	 * Default value for every supported setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// `include_tags` and `photo_field` ship set to this site's own
			// values so a fresh install lists the right people with the right
			// headshots; both are editable under Settings -> GoHighLevel.
			'api_version'   => 'v2',
			'api_token'     => '',
			'location_id'   => '',
			'cache_minutes' => 60,
			'deep_sync'     => 1,
			'cache_photos'  => 0,
			'include_tags'  => 'member - physician',
			'exclude_tags'  => '',
			'photo_field'   => 'cf:member_profile_photo',
			'title_field'   => '',
			'specialty_field' => 'cf:primary_specialty',
			'specialty_field_2' => '',
			'company_field' => 'cf:organization_name',
			'fax_field'     => 'cf:fax',
			'bio_field'     => '',
			'extra_fields'  => array(),
			'filters'       => array( 'search', 'tag' ),
			'show'          => array( 'photo', 'title', 'company', 'location', 'tags' ),
			'name_format'   => 'name_title',
			'view'          => 'page',
			'noindex_generated' => 1,
			'modal'         => 1,
			// Drives the contact page as well as the modal; the key is kept as
			// modal_show so existing installs do not lose their choices.
			'modal_show'    => array( 'photo', 'title', 'specialty', 'company', 'location', 'address', 'phone', 'fax', 'tags', 'bio' ),
			'use_gravatar'  => 0,
			'columns'       => 3,
			'per_page'      => 24,
			'orderby'       => 'first_name',
			'order'         => 'asc',
		);
	}

	/**
	 * All settings, defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Returned when the setting is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a partial settings array.
	 *
	 * @param array $values Values to merge over the stored ones.
	 * @return void
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * API token, preferring the GHLD_API_TOKEN constant when it is defined.
	 *
	 * Keeping the token in wp-config.php instead of the options table is the
	 * recommended setup: it stays out of database dumps and out of the admin UI.
	 *
	 * @return string
	 */
	public static function token() {
		if ( defined( 'GHLD_API_TOKEN' ) && '' !== (string) GHLD_API_TOKEN ) {
			return (string) GHLD_API_TOKEN;
		}

		return (string) self::get( 'api_token', '' );
	}

	/**
	 * Whether the token comes from a constant rather than the database.
	 *
	 * @return bool
	 */
	public static function token_is_constant() {
		return defined( 'GHLD_API_TOKEN' ) && '' !== (string) GHLD_API_TOKEN;
	}

	/**
	 * GoHighLevel location (sub-account) ID.
	 *
	 * @return string
	 */
	public static function location_id() {
		if ( defined( 'GHLD_LOCATION_ID' ) && '' !== (string) GHLD_LOCATION_ID ) {
			return (string) GHLD_LOCATION_ID;
		}

		return (string) self::get( 'location_id', '' );
	}

	/**
	 * True when there is enough configuration to attempt an API call.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		if ( '' === self::token() ) {
			return false;
		}

		// The v1 API key is location-scoped already; v2 needs an explicit location.
		return 'v1' === self::get( 'api_version' ) || '' !== self::location_id();
	}

	/**
	 * Cache lifetime in seconds.
	 *
	 * @return int
	 */
	public static function cache_seconds() {
		$minutes = (int) self::get( 'cache_minutes', 60 );

		return max( 5, $minutes ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Split a comma-separated setting into a clean list.
	 *
	 * @param string $value Raw comma-separated string.
	 * @return string[]
	 */
	public static function to_list( $value ) {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = explode( ',', (string) $value );
		}

		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize the settings form payload.
	 *
	 * @param mixed $input Raw $_POST value for the option.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$input   = is_array( $input ) ? $input : array();
		$clean   = $current;

		$clean['api_version'] = ( isset( $input['api_version'] ) && 'v1' === $input['api_version'] ) ? 'v1' : 'v2';

		// An empty token field means "leave the stored token alone" — the field
		// is rendered blank so the secret is never echoed back to the browser.
		if ( isset( $input['api_token'] ) && '' !== trim( $input['api_token'] ) ) {
			$clean['api_token'] = trim( sanitize_text_field( $input['api_token'] ) );
		}
		if ( ! empty( $input['clear_api_token'] ) ) {
			$clean['api_token'] = '';
		}

		$clean['location_id']   = isset( $input['location_id'] ) ? sanitize_text_field( trim( $input['location_id'] ) ) : '';
		$clean['cache_minutes'] = isset( $input['cache_minutes'] ) ? max( 5, (int) $input['cache_minutes'] ) : 60;
		$clean['include_tags']  = isset( $input['include_tags'] ) ? sanitize_text_field( $input['include_tags'] ) : '';
		$clean['exclude_tags']  = isset( $input['exclude_tags'] ) ? sanitize_text_field( $input['exclude_tags'] ) : '';
		$clean['photo_field']   = isset( $input['photo_field'] ) ? sanitize_text_field( $input['photo_field'] ) : '';
		$clean['title_field']   = isset( $input['title_field'] ) ? sanitize_text_field( $input['title_field'] ) : '';
		$clean['specialty_field'] = isset( $input['specialty_field'] ) ? sanitize_text_field( $input['specialty_field'] ) : '';
		$clean['specialty_field_2'] = isset( $input['specialty_field_2'] ) ? sanitize_text_field( $input['specialty_field_2'] ) : '';
		$clean['company_field'] = isset( $input['company_field'] ) ? sanitize_text_field( $input['company_field'] ) : '';
		$clean['fax_field']     = isset( $input['fax_field'] ) ? sanitize_text_field( $input['fax_field'] ) : '';
		$clean['bio_field']     = isset( $input['bio_field'] ) ? sanitize_text_field( $input['bio_field'] ) : '';
		$clean['use_gravatar']  = empty( $input['use_gravatar'] ) ? 0 : 1;
		$clean['modal']         = empty( $input['modal'] ) ? 0 : 1;
		$clean['view']          = self::sanitize_choice( isset( $input['view'] ) ? $input['view'] : '', self::view_choices(), 'page' );
		$clean['noindex_generated'] = empty( $input['noindex_generated'] ) ? 0 : 1;
		$clean['deep_sync']     = empty( $input['deep_sync'] ) ? 0 : 1;
		$clean['cache_photos']  = empty( $input['cache_photos'] ) ? 0 : 1;
		$clean['name_format']   = GHLD_Settings::sanitize_choice( isset( $input['name_format'] ) ? $input['name_format'] : '', self::name_format_choices(), 'name_title' );
		$clean['columns']       = isset( $input['columns'] ) ? min( 6, max( 1, (int) $input['columns'] ) ) : 3;
		$clean['per_page']      = isset( $input['per_page'] ) ? min( 200, max( 1, (int) $input['per_page'] ) ) : 24;
		$clean['orderby']       = self::sanitize_choice( isset( $input['orderby'] ) ? $input['orderby'] : '', self::orderby_choices(), 'name' );
		$clean['order']         = ( isset( $input['order'] ) && 'desc' === $input['order'] ) ? 'desc' : 'asc';

		$clean['extra_fields'] = array_map( 'sanitize_text_field', isset( $input['extra_fields'] ) && is_array( $input['extra_fields'] ) ? $input['extra_fields'] : array() );
		$clean['filters']      = array_map( 'sanitize_text_field', isset( $input['filters'] ) && is_array( $input['filters'] ) ? $input['filters'] : array() );
		$clean['show']         = array_map( 'sanitize_text_field', isset( $input['show'] ) && is_array( $input['show'] ) ? $input['show'] : array() );
		$clean['modal_show']   = array_map( 'sanitize_text_field', isset( $input['modal_show'] ) && is_array( $input['modal_show'] ) ? $input['modal_show'] : array() );

		// Any change to credentials or tag scope invalidates what is cached.
		if ( $clean['api_version'] !== $current['api_version']
			|| $clean['api_token'] !== $current['api_token']
			|| $clean['location_id'] !== $current['location_id'] ) {
			GHLD_Repository::flush();
		}

		return $clean;
	}

	/**
	 * What happens when a card is opened.
	 *
	 * @return array
	 */
	public static function view_choices() {
		return array(
			'page'  => __( 'Open the contact\'s own page', 'gohighlevel-integration' ),
			'modal' => __( 'Open a modal over the directory', 'gohighlevel-integration' ),
		);
	}

	/**
	 * How the name line is composed.
	 *
	 * @return array
	 */
	public static function name_format_choices() {
		return array(
			'name_title' => __( 'Name, Title — e.g. "Emily Billingsley, MD"', 'gohighlevel-integration' ),
			'name'       => __( 'Name only, with the title on its own line', 'gohighlevel-integration' ),
		);
	}

	/**
	 * Allowed sort keys, label keyed by value.
	 *
	 * @return array
	 */
	public static function orderby_choices() {
		return array(
			// `first_name` sorts on the name as it is printed on the card, which
			// is what reads as "alphabetical" to someone scanning the page;
			// `name` sorts on surname, the convention for a clinical directory.
			'first_name' => __( 'First name', 'gohighlevel-integration' ),
			'name'       => __( 'Last name', 'gohighlevel-integration' ),
			'company'    => __( 'Company', 'gohighlevel-integration' ),
			'date_added' => __( 'Date added', 'gohighlevel-integration' ),
		);
	}

	/**
	 * Constrain a value to a set of allowed keys.
	 *
	 * @param string $value    Candidate value.
	 * @param array  $choices  Allowed values (as array keys).
	 * @param string $fallback Value returned when the candidate is not allowed.
	 * @return string
	 */
	public static function sanitize_choice( $value, array $choices, $fallback ) {
		$value = is_string( $value ) ? $value : '';

		return isset( $choices[ $value ] ) ? $value : $fallback;
	}
}
