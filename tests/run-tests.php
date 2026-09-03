<?php
/**
 * Standalone test suite for the plugin's pure logic.
 *
 * Run with: php wordpress-plugin/tests/run-tests.php
 *
 * @package GoHighLevel_Integration
 */

require_once __DIR__ . '/stubs.php';

$GLOBALS['ghld_failures'] = 0;
$GLOBALS['ghld_checks']   = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition to hold.
 * @param string $message   What is being checked.
 * @return void
 */
function ghld_ok( $condition, $message ) {
	$GLOBALS['ghld_checks']++;
	if ( $condition ) {
		return;
	}

	$GLOBALS['ghld_failures']++;
	echo "FAIL: {$message}\n";
}

/**
 * Assert equality, printing both sides on failure.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  What is being checked.
 * @return void
 */
function ghld_same( $expected, $actual, $message ) {
	$GLOBALS['ghld_checks']++;
	if ( $expected === $actual ) {
		return;
	}

	$GLOBALS['ghld_failures']++;
	echo "FAIL: {$message}\n";
	echo '      expected: ' . var_export( $expected, true ) . "\n";
	echo '      actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Custom field definitions used across the tests.
 *
 * @return array
 */
function ghld_test_fields() {
	return array(
		'fld_photo' => array(
			'key'  => 'headshot',
			'name' => 'Headshot',
			'type' => 'TEXT',
		),
		'fld_title' => array(
			'key'  => 'job_title',
			'name' => 'Job Title',
			'type' => 'TEXT',
		),
	);
}

/**
 * Settings used across the tests.
 *
 * @param array $overrides Values to override.
 * @return array
 */
function ghld_test_settings( array $overrides = array() ) {
	return array_merge(
		GHLD_Settings::defaults(),
		array(
			'photo_field' => 'cf:headshot',
			'title_field' => 'cf:job_title',
		),
		$overrides
	);
}

/**
 * Seed the contact cache with normalized contacts.
 *
 * @param array $raws Raw API payloads.
 * @param array $settings Settings to normalize against.
 * @return array
 */
function ghld_seed( array $raws, array $settings ) {
	GHLD_Repository::flush();
	update_option( 'ghld_settings', $settings );
	update_option( 'ghld_custom_fields', ghld_test_fields() );

	$contacts = array();
	foreach ( $raws as $raw ) {
		$contacts[] = GHLD_Contact::normalize( $raw, ghld_test_fields(), $settings );
	}

	update_option( 'ghld_contacts', $contacts );
	update_option(
		'ghld_sync_state',
		array(
			'synced_at' => time(),
			'count'     => count( $contacts ),
			'mapping'   => GHLD_Contact::mapping_hash( $settings ),
		)
	);

	return $contacts;
}

/**
 * A v2-shaped contact.
 *
 * @param array $overrides Fields to override.
 * @return array
 */
function ghld_v2_contact( array $overrides = array() ) {
	return array_merge(
		array(
			'id'           => 'c1',
			'firstName'    => 'Ada',
			'lastName'     => 'Lovelace',
			'email'        => 'ada@example.com',
			'phone'        => '+1 555-0100',
			'companyName'  => 'Analytical Engines',
			'city'         => 'London',
			'state'        => 'England',
			'website'      => 'analytical.example.com',
			'tags'         => array( 'Speaker', 'Directory' ),
			'dateAdded'    => '2024-03-01T10:00:00Z',
			'customFields' => array(
				array(
					'id'    => 'fld_photo',
					'value' => 'https://cdn.example.com/ada.jpg',
				),
				array(
					'id'    => 'fld_title',
					'value' => 'Mathematician',
				),
			),
		),
		$overrides
	);
}

/* -------------------------------------------------------------------------
 * Normalization
 * ---------------------------------------------------------------------- */

$settings = ghld_test_settings();
$contact  = GHLD_Contact::normalize( ghld_v2_contact(), ghld_test_fields(), $settings );

ghld_same( 'Ada Lovelace', $contact['name'], 'v2: builds a display name from first/last' );
ghld_same( 'AL', $contact['initials'], 'v2: derives two initials' );
ghld_same( 'https://cdn.example.com/ada.jpg', $contact['photo'], 'v2: photo comes from the mapped custom field' );
ghld_same( 'Mathematician', $contact['title'], 'v2: title comes from the mapped custom field' );
ghld_same( 'Mathematician', $contact['custom']['job_title'], 'v2: custom values are keyed by field key' );
ghld_same( 'https://analytical.example.com', $contact['website'], 'v2: a bare domain is upgraded to https' );
ghld_same( array( 'Directory', 'Speaker' ), $contact['tags'], 'v2: tags are de-duplicated and sorted' );
ghld_same( 'lovelace ada', $contact['sort_name'], 'v2: sorts on last name first' );
ghld_ok( $contact['date_added'] > 0, 'v2: parses an ISO date' );
ghld_ok( false !== strpos( $contact['search_key'], 'mathematician' ), 'v2: custom values are searchable' );

$v1 = GHLD_Contact::normalize(
	array(
		'id'          => 'c2',
		'firstName'   => 'Grace',
		'lastName'    => 'Hopper',
		'email'       => 'not-an-email',
		'dateAdded'   => '1709287200000',
		'tags'        => 'Directory, Speaker, Directory',
		'customField' => array(
			array(
				'id'    => 'fld_title',
				'value' => 'Rear Admiral',
			),
		),
	),
	ghld_test_fields(),
	$settings
);

ghld_same( 'Rear Admiral', $v1['title'], 'v1: reads the singular customField key' );
ghld_same( array( 'Directory', 'Speaker' ), $v1['tags'], 'v1: accepts a comma-separated tag string' );
ghld_same( '', $v1['email'], 'invalid email addresses are dropped' );
ghld_same( 1709287200, $v1['date_added'], 'epoch milliseconds are converted to seconds' );

$hostile = GHLD_Contact::normalize(
	ghld_v2_contact(
		array(
			'website'      => 'javascript:alert(1)',
			'customFields' => array(
				array(
					'id'    => 'fld_photo',
					'value' => 'javascript:alert(document.cookie)',
				),
			),
		)
	),
	ghld_test_fields(),
	$settings
);

ghld_same( '', $hostile['photo'], 'a javascript: photo URL is rejected' );
ghld_same( '', $hostile['website'], 'a javascript: website URL is rejected' );

$remapped = GHLD_Contact::apply_mapping( $contact, ghld_test_settings( array( 'title_field' => 'company' ) ) );
ghld_same( 'Analytical Engines', $remapped['title'], 're-mapping a field re-derives it without a re-fetch' );
ghld_ok(
	GHLD_Contact::mapping_hash( $settings ) !== GHLD_Contact::mapping_hash( ghld_test_settings( array( 'title_field' => 'company' ) ) ),
	'the mapping hash changes when a mapping changes'
);

ghld_same( 'job_title', GHLD_Client::field_key( array( 'fieldKey' => 'contact.job_title' ) ), 'field keys drop the contact. prefix' );
ghld_same( 'head_shot', GHLD_Client::field_key( array( 'name' => 'Head Shot' ) ), 'field keys fall back to the field name' );

/* -------------------------------------------------------------------------
 * Querying
 * ---------------------------------------------------------------------- */

$people = array(
	ghld_v2_contact(),
	ghld_v2_contact(
		array(
			'id'          => 'c2',
			'firstName'   => 'Grace',
			'lastName'    => 'Hopper',
			'city'        => 'New York',
			'companyName' => 'US Navy',
			'tags'        => array( 'Directory' ),
			'email'       => 'grace@example.com',
		)
	),
	ghld_v2_contact(
		array(
			'id'          => 'c3',
			'firstName'   => 'Katherine',
			'lastName'    => 'Johnson',
			'city'        => 'New York',
			'companyName' => 'NASA',
			'tags'        => array( 'Speaker' ),
			'email'       => 'katherine@example.com',
		)
	),
	ghld_v2_contact(
		array(
			'id'          => 'c4',
			'firstName'   => 'Alan',
			'lastName'    => 'Turing',
			'city'        => 'London',
			'companyName' => 'NPL',
			'tags'        => array( 'Directory', 'Private' ),
			'email'       => 'alan@example.com',
		)
	),
);

ghld_seed( $people, $settings );

$scope = GHLD_Shortcode::build_scope( array() );
$scope = array_merge( $scope, array( 'per_page' => 10 ) );

$blank = GHLD_Shortcode::parse_request( array(), $scope );
$all   = GHLD_Repository::query( $scope, $blank );
ghld_same( 4, $all['total'], 'an unfiltered query returns every cached contact' );
ghld_same( 'Katherine Johnson', $all['items'][1]['name'], 'default sort is by last name ascending' );

$scoped = array_merge( $scope, array( 'tags' => array( 'directory' ) ) );
$result = GHLD_Repository::query( $scoped, GHLD_Shortcode::parse_request( array(), $scoped ) );
ghld_same( 3, $result['total'], 'the include-tag scope is case-insensitive and narrows the set' );

$excluded = array_merge( $scoped, array( 'exclude_tags' => array( 'Private' ) ) );
$result   = GHLD_Repository::query( $excluded, GHLD_Shortcode::parse_request( array(), $excluded ) );
ghld_same( 2, $result['total'], 'excluded tags win over included ones' );

$result = GHLD_Repository::query( $scope, GHLD_Shortcode::parse_request( array( 'ghld_s' => 'ada lovelace' ), $scope ) );
ghld_same( 1, $result['total'], 'search terms are ANDed across the contact' );

$result = GHLD_Repository::query( $scope, GHLD_Shortcode::parse_request( array( 'ghld_s' => 'ada turing' ), $scope ) );
ghld_same( 0, $result['total'], 'a term that matches nobody returns nothing' );

$result = GHLD_Repository::query( $scope, GHLD_Shortcode::parse_request( array( 'ghld_s' => 'NASA' ), $scope ) );
ghld_same( 'Katherine Johnson', $result['items'][0]['name'], 'search is case-insensitive and covers the company' );

$city_scope = array_merge( $scope, array( 'filters' => array( 'search', 'tag', 'city', 'sort' ) ) );
$result     = GHLD_Repository::query( $city_scope, GHLD_Shortcode::parse_request( array( 'ghld_city' => 'new york' ), $city_scope ) );
ghld_same( 2, $result['total'], 'the city filter matches case-insensitively' );

$result = GHLD_Repository::query( $scope, GHLD_Shortcode::parse_request( array( 'ghld_city' => 'new york' ), $scope ) );
ghld_same( 4, $result['total'], 'a filter that the scope does not enable is ignored' );

$result = GHLD_Repository::query( $city_scope, GHLD_Shortcode::parse_request( array( 'ghld_sort' => 'company-desc' ), $city_scope ) );
ghld_same( 'US Navy', $result['items'][0]['company'], 'the sort control reorders by company descending' );

$result = GHLD_Repository::query( $city_scope, GHLD_Shortcode::parse_request( array( 'ghld_sort' => 'bogus-desc' ), $city_scope ) );
ghld_same( 'Grace Hopper', $result['items'][0]['name'], 'an unknown sort key falls back to the scope default (name, ascending)' );

$paged = array_merge( $scope, array( 'per_page' => 2 ) );
$page2 = GHLD_Repository::query( $paged, GHLD_Shortcode::parse_request( array( 'ghld_page' => '2' ), $paged ) );
ghld_same( 2, $page2['pages'], 'pagination reports the page count' );
ghld_same( 2, count( $page2['items'] ), 'a page holds per_page contacts' );
ghld_same( 'Alan Turing', $page2['items'][1]['name'], 'page two continues the sort' );

$over = GHLD_Repository::query( $paged, GHLD_Shortcode::parse_request( array( 'ghld_page' => '99' ), $paged ) );
ghld_same( 2, $over['page'], 'a page number past the end clamps to the last page' );

$facets = $all['facets'];
ghld_same( 3, $facets['tag']['Directory'], 'tag facets are counted' );
ghld_same( 2, $facets['city']['New York'], 'city facets are counted' );

/* -------------------------------------------------------------------------
 * Scope and request parsing
 * ---------------------------------------------------------------------- */

$wide = GHLD_Shortcode::build_scope(
	array(
		'columns'  => '9',
		'per_page' => '5000',
		'filters'  => 'search,tag,bogus',
		'show'     => 'photo,email,not-a-thing',
		'order'    => 'DESC',
		'layout'   => 'list',
		'tags'     => 'Directory, Speaker',
	)
);

ghld_same( 6, $wide['columns'], 'columns are clamped to 6' );
ghld_same( 200, $wide['per_page'], 'per_page is clamped to 200' );
ghld_same( array( 'search', 'tag' ), $wide['filters'], 'unknown filter names are dropped' );
ghld_same( array( 'photo', 'email' ), $wide['show'], 'unknown card elements are dropped' );
ghld_same( 'desc', $wide['order'], 'order is normalized to lowercase' );
ghld_same( 'list', $wide['layout'], 'the list layout is accepted' );
ghld_same( array( 'Directory', 'Speaker' ), $wide['tags'], 'the tags attribute is split into a list' );

$none = GHLD_Shortcode::build_scope( array( 'filters' => 'none' ) );
ghld_same( array(), $none['filters'], 'filters="none" hides the whole bar' );

$preset  = array_merge( $scope, array( 'search' => 'London' ) );
$request = GHLD_Shortcode::parse_request( array( 'ghld_s' => 'Ada' ), $preset );
ghld_same( 'London Ada', $request['search'], 'a shortcode search preset is ANDed with what the visitor typed' );
ghld_same( 'Ada', $request['typed'], 'the typed term is tracked separately for the search box' );

$query = GHLD_Shortcode::request_to_query( $request );
ghld_same( 'Ada', $query['ghld_s'], 'the query string carries only the typed term' );

$dirty = GHLD_Shortcode::parse_request( array( 'ghld_page' => '-3' ), $scope );
ghld_same( 1, $dirty['page'], 'a negative page number falls back to page one' );

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

$xss = GHLD_Contact::normalize(
	ghld_v2_contact(
		array(
			'id'        => 'x1',
			'firstName' => '<script>alert(1)</script>',
			'lastName'  => '',
		)
	),
	ghld_test_fields(),
	$settings
);

$html = GHLD_Template::get(
	'results',
	array(
		'items' => array( $xss ),
		'scope' => array_merge( $scope, array( 'show' => array( 'photo', 'title', 'company', 'tags' ) ) ),
		'total' => 1,
	)
);

ghld_ok( false === strpos( $html, '<script>alert' ), 'contact names are escaped before output' );
ghld_ok( false !== strpos( $html, '&lt;script&gt;' ), 'the escaped name is still rendered' );
ghld_ok( false !== strpos( $html, 'ghld-card' ), 'the card markup is rendered' );

$empty = GHLD_Template::get(
	'results',
	array(
		'items' => array(),
		'scope' => array_merge( $scope, array( 'empty' => 'Nobody here' ) ),
		'total' => 0,
	)
);
ghld_ok( false !== strpos( $empty, 'Nobody here' ), 'the empty-state message is rendered' );

$pagination = GHLD_Template::get(
	'pagination',
	array(
		'page'    => 1,
		'pages'   => 3,
		'request' => $blank,
		'scope'   => $scope,
	)
);
ghld_ok( false !== strpos( $pagination, 'data-ghld-page="2"' ), 'pagination links carry the target page' );
ghld_ok( false !== strpos( $pagination, 'ghld_page=2' ), 'pagination links work without JavaScript' );

/* -------------------------------------------------------------------------
 * Failure handling
 * ---------------------------------------------------------------------- */

GHLD_Repository::flush();
update_option( 'ghld_settings', GHLD_Settings::defaults() );
ghld_same( array(), GHLD_Repository::get_contacts(), 'an unconfigured site returns an empty set instead of erroring' );
ghld_ok( is_wp_error( GHLD_Repository::sync() ), 'syncing without credentials returns a WP_Error' );
ghld_ok( ! GHLD_Settings::is_configured(), 'a site with no token is not considered configured' );

update_option(
	'ghld_settings',
	array_merge(
		GHLD_Settings::defaults(),
		array(
			'api_token'   => 'pit-test',
			'location_id' => 'loc-test',
		)
	)
);
ghld_ok( GHLD_Settings::is_configured(), 'a token plus a location ID is enough to be configured' );

update_option(
	'ghld_settings',
	array_merge(
		GHLD_Settings::defaults(),
		array(
			'api_version' => 'v1',
			'api_token'   => 'v1-key',
		)
	)
);
ghld_ok( GHLD_Settings::is_configured(), 'the v1 API needs no location ID' );

printf( "\n%d checks, %d failures\n", $GLOBALS['ghld_checks'], $GLOBALS['ghld_failures'] );

exit( $GLOBALS['ghld_failures'] > 0 ? 1 : 0 );
