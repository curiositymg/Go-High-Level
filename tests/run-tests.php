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
 * Call a protected static method.
 *
 * @param string $class  Class name.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function ghld_call_protected( $class, $method, array $args ) {
	$reflection = new ReflectionMethod( $class, $method );
	$reflection->setAccessible( true );

	return $reflection->invokeArgs( null, $args );
}

/**
 * Find one contact in a result set by display name.
 *
 * @param array  $items Result items.
 * @param string $name  Display name.
 * @return array|null
 */
function ghld_find( array $items, $name ) {
	foreach ( $items as $item ) {
		if ( $item['name'] === $name ) {
			return $item;
		}
	}

	return null;
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
			// The shipped default scopes to one tag; the general cases below
			// exercise unscoped behaviour and test that default separately.
			'include_tags' => '',
			'photo_field'  => 'cf:headshot',
			'title_field'  => 'cf:job_title',
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
ghld_same( 'Ada Lovelace', $all['items'][0]['name'], 'the default sort is alphabetical by the name as printed' );
ghld_same( 'Alan Turing', $all['items'][1]['name'], 'the default sort keeps going by first name' );

$by_surname = array_merge( $scope, array( 'orderby' => 'name' ) );
$surnames   = GHLD_Repository::query( $by_surname, GHLD_Shortcode::parse_request( array(), $by_surname ) );
ghld_same( 'Grace Hopper', $surnames['items'][0]['name'], 'orderby="name" still groups by surname' );
ghld_same( 'Katherine Johnson', $surnames['items'][1]['name'], 'surname sort runs Hopper, Johnson, Lovelace, Turing' );

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
ghld_same( 'Ada Lovelace', $result['items'][0]['name'], 'an unknown sort key falls back to the scope default' );

$paged = array_merge( $scope, array( 'per_page' => 2 ) );
$page2 = GHLD_Repository::query( $paged, GHLD_Shortcode::parse_request( array( 'ghld_page' => '2' ), $paged ) );
ghld_same( 2, $page2['pages'], 'pagination reports the page count' );
ghld_same( 2, count( $page2['items'] ), 'a page holds per_page contacts' );
ghld_same( 'Katherine Johnson', $page2['items'][1]['name'], 'page two continues the sort' );

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
			'firstName' => '<script>Alert(1)</script>',
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

ghld_ok( false === strpos( $html, '<script>Alert' ), 'contact names are escaped before output' );
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
 * Shipped defaults: physicians only, headshots from member_profile_photo
 * ---------------------------------------------------------------------- */

$defaults = GHLD_Settings::defaults();
ghld_same( 'member - physician', $defaults['include_tags'], 'the default scope is the physician tag' );
ghld_same( 'cf:member_profile_photo', $defaults['photo_field'], 'the default photo field is member_profile_photo' );

$physician_fields = array(
	'fld_mpp'     => array(
		'key'  => 'member_profile_photo',
		'name' => 'Member Profile Photo',
		'type' => 'TEXT',
	),
	'fld_docmail' => array(
		'key'  => 'doctors_email_address',
		'name' => "Doctor's email address",
		'type' => 'TEXT',
	),
);

$roster = array(
	array(
		'id'           => 'p1',
		'firstName'    => 'rosalind',
		'lastName'     => 'franklin',
		'phone'        => '+1 555-0142',
		'address1'     => '12 Clinic Way',
		'city'         => 'Chipley',
		'state'        => 'Florida',
		'postalCode'   => '32428',
		'country'      => 'US',
		'tags'         => array( 'Member - Physician', 'Cardiology' ),
		'customFields' => array(
			array(
				'id'    => 'fld_mpp',
				'value' => 'https://cdn.example.com/franklin.jpg',
			),
			array(
				'id'    => 'fld_docmail',
				'value' => 'rosalind@example.com',
			),
		),
	),
	array(
		'id'        => 'p2',
		'firstName' => 'jonas',
		'lastName'  => 'salk',
		'tags'      => array( 'member - physician' ),
	),
	array(
		'id'        => 'p3',
		'firstName' => 'Office',
		'lastName'  => 'Manager',
		'tags'      => array( 'member - staff' ),
	),
);

GHLD_Repository::flush();
update_option( 'ghld_settings', $defaults );
update_option( 'ghld_custom_fields', $physician_fields );

$normalized = array();
foreach ( $roster as $raw ) {
	$normalized[] = GHLD_Contact::normalize( $raw, $physician_fields, $defaults );
}
update_option( 'ghld_contacts', $normalized );
update_option(
	'ghld_sync_state',
	array(
		'synced_at' => time(),
		'count'     => count( $normalized ),
		'mapping'   => GHLD_Contact::mapping_hash( $defaults ),
	)
);

$default_scope = GHLD_Shortcode::build_scope( array() );
ghld_same( array( 'member - physician' ), $default_scope['tags'], 'a bare shortcode inherits the physician scope' );

$physicians = GHLD_Repository::query( $default_scope, GHLD_Shortcode::parse_request( array(), $default_scope ) );
ghld_same( 2, $physicians['total'], 'contacts without the physician tag are never listed' );
ghld_same( 'Jonas Salk', $physicians['items'][0]['name'], 'lowercase records are capitalized and sorted by printed name' );

$franklin = ghld_find( $physicians['items'], 'Rosalind Franklin' );
$salk     = ghld_find( $physicians['items'], 'Jonas Salk' );
ghld_ok( null !== $franklin, 'a lowercase first/last name is title-cased for display' );
ghld_same( 'https://cdn.example.com/franklin.jpg', $franklin['photo'], 'headshots come from the member_profile_photo field' );
ghld_same( '', $salk['photo'], 'a physician with no headshot falls back to initials' );
ghld_same( 'JS', $salk['initials'], 'the initials fallback uses the capitalized name' );

ghld_ok( ! isset( $physicians['facets']['tag']['Member - Physician'] ), 'the tag the whole directory is scoped to is hidden from the filter bar' );
ghld_same( 1, $physicians['facets']['tag']['Cardiology'], 'tags that do distinguish contacts stay in the filter bar' );

$card = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $franklin,
		'scope'   => $default_scope,
	)
);
ghld_ok( false === stripos( $card, 'Member - Physician' ), 'the scope tag is not printed on every card' );
ghld_ok( false !== strpos( $card, 'Cardiology' ), 'other tags are still printed on the card' );

$two_tags = array_merge( $default_scope, array( 'tags' => array( 'member - physician', 'member - staff' ) ) );
ghld_same( '', GHLD_Repository::scope_tag( $two_tags ), 'two scope tags do distinguish contacts, so neither is hidden' );

$unmapped = GHLD_Contact::normalize(
	array(
		'id'           => 'p4',
		'firstName'    => 'Chien-Shiung',
		'lastName'     => 'Wu',
		'tags'         => array( 'member - physician' ),
		'customFields' => array(
			array(
				'id'          => 'unknown-id',
				'fieldKey'    => 'contact.member_profile_photo',
				'field_value' => 'https://cdn.example.com/wu.jpg',
			),
		),
	),
	array(),
	$defaults
);
ghld_same( 'https://cdn.example.com/wu.jpg', $unmapped['photo'], 'the photo still resolves from the key in the payload when field definitions are missing' );

/* -------------------------------------------------------------------------
 * Name capitalization
 * ---------------------------------------------------------------------- */

ghld_same( 'Ayman Aboulela', GHLD_Contact::capitalize_name( 'ayman aboulela' ), 'an all-lowercase name is title-cased' );
ghld_same( 'Hari K. R. Baddigam', GHLD_Contact::capitalize_name( 'hari k. r. baddigam' ), 'initials inside a name are capitalized' );
ghld_same( 'Eshraq Al-Jaghbeer', GHLD_Contact::capitalize_name( 'eshraq al-jaghbeer' ), 'hyphenated names capitalize both halves' );
ghld_same( "Shaun O'Brien", GHLD_Contact::capitalize_name( "shaun o'brien" ), 'a name prefix before an apostrophe is capitalized' );
ghld_same( 'DeShawn McDonald', GHLD_Contact::capitalize_name( 'DeShawn McDonald' ), 'deliberate internal capitals are never flattened' );
ghld_same( 'van der Berg', GHLD_Contact::capitalize_name( 'van der Berg' ), 'a name that already carries a capital is left alone' );
ghld_same( '', GHLD_Contact::capitalize_name( '   ' ), 'an empty name stays empty' );

$lowercased = GHLD_Contact::normalize(
	array(
		'id'                => 'n1',
		'fullNameLowerCase' => 'ayman aboulela',
	),
	array(),
	$defaults
);
ghld_same( 'Ayman Aboulela', $lowercased['name'], "GoHighLevel's lowercased name field is capitalized for display" );

$named = GHLD_Contact::normalize(
	array(
		'id'                => 'n2',
		'contactName'       => 'Amanda Aronchick',
		'fullNameLowerCase' => 'amanda aronchick',
	),
	array(),
	$defaults
);
ghld_same( 'Amanda Aronchick', $named['name'], 'a properly cased name wins over the lowercased copy' );

/* -------------------------------------------------------------------------
 * Detail modal
 * ---------------------------------------------------------------------- */

$modal_scope = array_merge( $default_scope, array( 'view' => 'modal' ) );
$card_html   = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $franklin,
		'scope'   => $modal_scope,
	)
);

ghld_ok( false !== strpos( $card_html, 'ghld-card-clickable' ), 'cards are marked clickable when the modal is on' );
ghld_ok( false !== strpos( $card_html, 'data-ghld-open' ), 'the name is a button, so the modal is reachable by keyboard' );
ghld_ok( false !== strpos( $card_html, 'data-ghld-detail' ), 'each card carries its own detail panel' );
ghld_ok( false !== strpos( $card_html, 'data-ghld-name="Rosalind Franklin"' ), 'the card names the contact for the dialog title' );
ghld_ok( false !== strpos( $card_html, 'data-ghld-initials="RF"' ), 'the headshot carries initials to fall back to if it fails to load' );

$inert_scope = array_merge( $modal_scope, array( 'modal' => false ) );
$inert_html  = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $franklin,
		'scope'   => $inert_scope,
	)
);
ghld_ok( false === strpos( $inert_html, 'data-ghld-open' ), 'modal="no" leaves the card inert' );
ghld_ok( false === strpos( $inert_html, 'data-ghld-detail' ), 'modal="no" ships no hidden detail markup' );

$detail = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => $franklin,
		'scope'   => $default_scope,
	)
);
ghld_ok( false !== strpos( $detail, 'Cardiology' ), 'the modal lists the contact tags' );
preg_match( '#<p class="ghld-detail-address">(.*?)</p>#s', $detail, $ghld_address );
$ghld_address = isset( $ghld_address[1] ) ? $ghld_address[1] : '';

ghld_ok( false !== strpos( $ghld_address, '12 Clinic Way' ), 'the modal carries the street address' );
ghld_ok( false !== strpos( $ghld_address, 'Chipley, Florida 32428' ), 'city, state and ZIP share the next line' );
ghld_ok( false === strpos( $ghld_address, 'US' ), 'the country is not printed in the address block' );
ghld_ok( false !== strpos( $detail, 'tel:+15550142' ), 'the modal links the phone number' );
ghld_ok( false !== strpos( $detail, '<span class="ghld-tel-label">P.</span>' ), 'the phone number is prefixed with P.' );
ghld_ok( false === strpos( $detail, 'rosalind@example.com' ), 'the modal withholds an email address that was not ticked' );

$contactable = array_merge(
	$default_scope,
	array( 'modal_show' => array_merge( $default_scope['modal_show'], array( 'email' ) ) )
);
$reachable   = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => $franklin,
		'scope'   => $contactable,
	)
);
ghld_ok( false !== strpos( $reachable, 'mailto:rosalind@example.com' ), 'ticking Email adds a mailto link to the modal' );

$unreachable = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => $franklin,
		'scope'   => array_merge( $default_scope, array( 'modal_show' => array( 'photo', 'company' ) ) ),
	)
);
ghld_ok( false === strpos( $unreachable, '555-0142' ), 'unticking Phone removes it from the modal' );

$hostile_detail = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => GHLD_Contact::normalize(
			array(
				'id'          => 'n3',
				'contactName' => 'Test Person',
				'companyName' => '<img src=x onerror=alert(1)>',
			),
			array(),
			$defaults
		),
		'scope'   => $default_scope,
	)
);
ghld_ok( false === strpos( $hostile_detail, '<img src=x' ), 'modal content is escaped like everything else' );

ghld_same( true, GHLD_Shortcode::is_truthy( 'yes' ), 'modal="yes" enables the modal' );
ghld_same( false, GHLD_Shortcode::is_truthy( 'no' ), 'modal="no" disables it' );
ghld_same( false, GHLD_Shortcode::build_scope( array( 'modal' => 'no' ) )['modal'], 'the shortcode attribute reaches the scope' );

/* -------------------------------------------------------------------------
 * "Name, Title" on the name line
 * ---------------------------------------------------------------------- */

$titled = GHLD_Contact::normalize(
	array(
		'id'           => 't1',
		'firstName'    => 'emily',
		'lastName'     => 'billingsley',
		'tags'         => array( 'member - physician' ),
		'customFields' => array(
			array(
				'id'    => 'fld_cred',
				'value' => 'MD',
			),
		),
	),
	array(
		'fld_cred' => array(
			'key'  => 'credential',
			'name' => 'Credential',
			'type' => 'TEXT',
		),
	),
	array_merge( $defaults, array( 'title_field' => 'cf:credential' ) )
);

ghld_same( 'Emily Billingsley', $titled['name'], 'the mapped title leaves the name itself alone' );
ghld_same( 'MD', $titled['title'], 'the credential comes from the mapped title field' );

ghld_same( 'name_title', GHLD_Shortcode::build_scope( array() )['name_format'], 'the name line defaults to "Name, Title"' );
ghld_same( 'name', GHLD_Shortcode::build_scope( array( 'name_format' => 'name' ) )['name_format'], 'the shortcode can put the title back on its own line' );
ghld_same( 'name_title', GHLD_Shortcode::build_scope( array( 'name_format' => 'bogus' ) )['name_format'], 'an unknown name format falls back to the default' );

ghld_same( 'Emily Billingsley, MD', GHLD_Shortcode::display_name( $titled, $default_scope ), 'the name line reads "Name, Title"' );
ghld_same( 'Jonas Salk', GHLD_Shortcode::display_name( $salk, $default_scope ), 'a contact with no title gets no trailing comma' );

$titled_card = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $titled,
		'scope'   => $default_scope,
	)
);
ghld_ok( false !== strpos( $titled_card, '<span class="ghld-name-title">, MD</span>' ), 'a single credential sits on the name line' );

// Several credentials: the comma stays with the name, they take a line.
$many = GHLD_Contact::normalize(
	array(
		'id'           => 'cred1',
		'firstName'    => 'Kamel',
		'lastName'     => 'Elzawahry',
		'tags'         => array( 'member - physician' ),
		'customFields' => array(
			array(
				'id'    => 'fld_cred',
				'value' => 'MD, FACP, FAAN, FAHA, FAHS',
			),
		),
	),
	array(
		'fld_cred' => array(
			'key'  => 'credential',
			'name' => 'Credential',
			'type' => 'TEXT',
		),
	),
	array_merge( $defaults, array( 'title_field' => 'cf:credential' ) )
);

ghld_same( true, GHLD_Shortcode::has_several_credentials( 'MD, FACP' ), 'a comma in the title means several credentials' );
ghld_same( false, GHLD_Shortcode::has_several_credentials( 'MD' ), 'one credential is not several' );
ghld_same( false, GHLD_Shortcode::has_several_credentials( '' ), 'and neither is none' );

$many_line = GHLD_Shortcode::name_line_html( $many, $default_scope );
ghld_ok( false !== strpos( $many_line, 'Kamel Elzawahry,<span' ), 'the first comma stays attached to the name' );
ghld_ok( false !== strpos( $many_line, 'ghld-name-credentials">MD, FACP, FAAN, FAHA, FAHS</span>' ), 'and the credentials start their own line without it' );
ghld_ok( false === strpos( $many_line, '>, MD' ), 'the credentials do not carry a leading comma' );

$one_line = GHLD_Shortcode::name_line_html( $titled, $default_scope );
ghld_ok( false !== strpos( $one_line, '<span class="ghld-name-title">, MD</span>' ), 'a single credential is left inline' );
ghld_ok( false === strpos( $one_line, 'ghld-name-credentials' ), 'and gets no line of its own' );

ghld_same( 'Jonas Salk', GHLD_Shortcode::name_line_html( $salk, $default_scope ), 'a contact with no credentials is just their name' );
ghld_ok( false !== strpos( $titled_card, 'data-ghld-name="Emily Billingsley, MD"' ), 'the dialog heading carries the title too' );
ghld_ok( false === strpos( $titled_card, '<p class="ghld-title">' ), 'the title does not also take a line of its own' );

$stacked      = array_merge( $default_scope, array( 'name_format' => 'name' ) );
$stacked_card = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $titled,
		'scope'   => $stacked,
	)
);
ghld_ok( false === strpos( $stacked_card, 'ghld-name-title' ), 'name_format="name" keeps the name line bare' );
ghld_ok( false !== strpos( $stacked_card, '<p class="ghld-title">MD</p>' ), 'name_format="name" puts the title back on its own line' );

$hidden_title = array_merge( $default_scope, array( 'show' => array( 'photo', 'company' ) ) );
ghld_same( '', GHLD_Shortcode::inline_title( $titled, $hidden_title ), 'unticking the title removes it from the name line as well' );

$titled_detail = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => $titled,
		'scope'   => $default_scope,
	)
);
ghld_ok( false === strpos( $titled_detail, 'ghld-detail-title' ), 'the modal body does not repeat the title its heading already carries' );
ghld_ok( false === strpos( $titled_detail, '<h1' ), 'and adds no heading of its own, which the dialog already provides' );

/* -------------------------------------------------------------------------
 * Primary specialty
 * ---------------------------------------------------------------------- */

ghld_same( 'cf:primary_specialty', GHLD_Settings::defaults()['specialty_field'], 'the specialty maps to primary_specialty by default' );
ghld_same( '', GHLD_Settings::defaults()['specialty_field_2'], 'no second specialty field is mapped by default' );

$specialty_fields = array(
	'fld_spec1' => array(
		'key'  => 'specialty_1',
		'name' => 'Specialty 1',
		'type' => 'TEXT',
	),
	'fld_spec2' => array(
		'key'  => 'specialty_2',
		'name' => 'Specialty 2',
		'type' => 'TEXT',
	),
	'fld_cred'  => array(
		'key'  => 'credential',
		'name' => 'Credential',
		'type' => 'TEXT',
	),
);

update_option( 'ghld_custom_fields', $specialty_fields );
$specialty_settings = array_merge(
	$defaults,
	array(
		'title_field'       => 'cf:credential',
		// Two fields joined, for sites that split specialties across them.
		'specialty_field'   => 'cf:specialty_1',
		'specialty_field_2' => 'cf:specialty_2',
	)
);
update_option( 'ghld_settings', $specialty_settings );

$bone = GHLD_Contact::normalize(
	array(
		'id'           => 's1',
		'firstName'    => 'william',
		'lastName'     => 'bone',
		'companyName'  => 'Panama City Infectious Disease',
		'tags'         => array( 'member - physician' ),
		'customFields' => array(
			array(
				'id'    => 'fld_cred',
				'value' => 'MD',
			),
			array(
				'id'    => 'fld_spec1',
				'value' => 'Infectious Disease',
			),
			array(
				'id'    => 'fld_spec2',
				'value' => 'Internal Medicine',
			),
		),
	),
	$specialty_fields,
	$specialty_settings
);

ghld_same( 'Infectious Disease, Internal Medicine', $bone['specialty'], 'the two specialty fields join into one line' );
ghld_ok( false !== strpos( $bone['search_key'], 'internal medicine' ), 'specialties are searchable' );

$bone_scope  = GHLD_Shortcode::build_scope( array() );
$bone_detail = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => $bone,
		'scope'   => $bone_scope,
	)
);

ghld_ok( false !== strpos( $bone_detail, 'Specialty: Infectious Disease, Internal Medicine' ), 'the modal prints the specialties behind their label' );
ghld_ok( false !== strpos( $bone_detail, 'Panama City Infectious Disease' ), 'the modal prints the practice name' );
ghld_same( 'William Bone, MD', GHLD_Shortcode::display_name( $bone, $bone_scope ), 'the heading reads "Name, Title"' );

// A field with its own place on the card must not also render as a generic row.
$doubled = array_merge(
	$bone_scope,
	array( 'modal_show' => array_merge( $bone_scope['modal_show'], array( 'cf:specialty_1', 'cf:specialty_2', 'cf:credential' ) ) )
);
$doubled_detail = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => $bone,
		'scope'   => $doubled,
	)
);
ghld_same( 1, substr_count( $doubled_detail, 'Infectious Disease, Internal Medicine' ), 'a mapped field is never printed twice' );
ghld_ok( false === strpos( $doubled_detail, 'ghld-detail-label">Specialty 1' ), 'the mapped specialty does not also appear as a labelled row' );
ghld_ok( false === strpos( $doubled_detail, 'ghld-detail-label">Specialty 2' ), 'neither does the second specialty field' );

$one_only = GHLD_Contact::normalize(
	array(
		'id'           => 's2',
		'contactName'  => 'Single Specialty',
		'customFields' => array(
			array(
				'id'    => 'fld_spec1',
				'value' => 'Infectious Disease',
			),
		),
	),
	$specialty_fields,
	$specialty_settings
);
ghld_same( 'Infectious Disease', $one_only['specialty'], 'one specialty alone leaves no trailing comma' );
ghld_same( 'Cardiology', GHLD_Contact::join_values( array( '', 'Cardiology', '' ) ), 'empty specialty values are skipped' );
ghld_same( 'Cardiology', GHLD_Contact::join_values( array( 'Cardiology', 'Cardiology' ) ), 'a duplicated specialty is not printed twice' );

$no_specialty = array_merge( $bone_scope, array( 'modal_show' => array( 'photo', 'company' ) ) );
$plain_detail = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => $bone,
		'scope'   => $no_specialty,
	)
);
ghld_ok( false === strpos( $plain_detail, 'ghld-detail-specialty' ), 'unticking the specialty removes it from the modal' );

// Restore the earlier fixtures for the sections that follow.
update_option( 'ghld_custom_fields', $physician_fields );
update_option( 'ghld_settings', $defaults );

/* -------------------------------------------------------------------------
 * Filter bar
 * ---------------------------------------------------------------------- */

$bar = GHLD_Template::get(
	'filter-bar',
	array(
		'scope'   => $default_scope,
		'request' => GHLD_Shortcode::parse_request( array(), $default_scope ),
		'facets'  => array(
			'tag'     => array( 'Cardiology' => 1 ),
			'city'    => array(),
			'state'   => array(),
			'company' => array(),
			'custom'  => array(),
		),
	)
);

ghld_ok( false !== strpos( $bar, 'ghld-reset' ), 'the Clear button keeps ghld-reset as a styling hook' );
ghld_ok( false !== strpos( $bar, 'blue-button' ), "the Clear button carries the theme's blue-button class" );
ghld_ok( false === strpos( $bar, 'ghld-button ghld-reset' ), 'the Clear button carries no ghld-button class, so the theme styles it' );
ghld_ok( false !== strpos( $bar, 'data-ghld-reset' ), 'the Clear button is still wired up' );
ghld_ok( false === strpos( $bar, 'type="reset"' ), 'Clear is not a form reset, which would restore the filters it is meant to drop' );

$_GET = array(
	'ghld_s'     => 'cardiology',
	'ghld_page'  => '4',
	'utm_source' => 'newsletter',
);
$clear = GHLD_Shortcode::clear_url();
ghld_ok( false === strpos( $clear, 'ghld_s' ), 'clearing drops the search' );
ghld_ok( false === strpos( $clear, 'ghld_page' ), 'and the page' );
ghld_ok( false !== strpos( $clear, 'utm_source=newsletter' ), 'but leaves arguments that are not ours alone' );

$_GET = array( 'ghld_s' => 'cardiology' );
ghld_same( '/directory/', GHLD_Shortcode::clear_url(), 'with nothing else in the URL it returns to the plain directory' );

$filtered_bar = GHLD_Template::get(
	'filter-bar',
	array(
		'scope'   => $default_scope,
		'request' => GHLD_Shortcode::parse_request( array( 'ghld_s' => 'cardiology' ), $default_scope ),
		'facets'  => array(
			'tag'     => array(),
			'city'    => array(),
			'state'   => array(),
			'company' => array(),
			'custom'  => array(),
		),
	)
);
ghld_ok( false !== strpos( $filtered_bar, 'value="cardiology"' ), 'a filtered page renders the term in the box' );
ghld_ok( false !== strpos( $filtered_bar, 'href="/directory/"' ), 'and Clear points away from it, not back to it' );

$_GET = array();
ghld_ok( false !== strpos( $bar, 'ghld-label ghld-label-hidden' ), 'the search label is hidden visually, not removed' );
ghld_ok( false !== strpos( $bar, 'for="ghld-ghld_s"' ), 'the search label still points at its input' );

/* -------------------------------------------------------------------------
 * Awkward custom field value shapes
 * ---------------------------------------------------------------------- */

$photo_map = array(
	'fld_mpp' => array(
		'key'  => 'member_profile_photo',
		'name' => 'Member Profile Photo',
		'type' => 'FILE_UPLOAD',
	),
);

/**
 * Normalize one contact carrying a given photo field value.
 *
 * @param mixed $value Raw custom field value.
 * @return array
 */
function ghld_photo_contact( $value ) {
	return GHLD_Contact::normalize(
		array(
			'id'           => 'ph',
			'contactName'  => 'Photo Test',
			'customFields' => array(
				array(
					'id'    => 'fld_mpp',
					'value' => $value,
				),
			),
		),
		array(
			'fld_mpp' => array(
				'key'  => 'member_profile_photo',
				'name' => 'Member Profile Photo',
				'type' => 'FILE_UPLOAD',
			),
		),
		GHLD_Settings::defaults()
	);
}

$shot = 'https://cdn.example.com/headshot.jpg';

ghld_same( $shot, ghld_photo_contact( $shot )['photo'], 'a plain URL string resolves' );
ghld_same( $shot, ghld_photo_contact( array( $shot ) )['photo'], 'a single-item list resolves' );
ghld_same( $shot, ghld_photo_contact( array( array( 'url' => $shot ) ) )['photo'], 'a list of {url} objects resolves' );
ghld_same( $shot, ghld_photo_contact( array( 'url' => $shot ) )['photo'], 'a single {url} object resolves' );
ghld_same( $shot, ghld_photo_contact( array( 'doc_abc123' => $shot ) )['photo'], 'an object keyed by document ID resolves' );
ghld_same(
	$shot,
	ghld_photo_contact( array( array( 'name' => 'headshot.jpg' ), array( 'url' => $shot ) ) )['photo'],
	'the first usable URL wins when a file field carries several values'
);
ghld_same( '', ghld_photo_contact( 'headshot.jpg' )['photo'], 'a bare filename is not treated as a URL' );
ghld_same( '', ghld_photo_contact( array() )['photo'], 'an empty file field leaves the initials fallback in place' );

$multi = ghld_photo_contact( array( $shot, 'https://cdn.example.com/second.jpg' ) );
ghld_same( $shot, $multi['photo'], 'several uploaded files use the first' );
ghld_ok( false === strpos( $multi['search_key'], 'headshot.jpg' ), 'URL values stay out of the search index' );

/* -------------------------------------------------------------------------
 * Headshot URLs
 * ---------------------------------------------------------------------- */

// The exact payload a GoHighLevel file-upload field sends: an object keyed by
// upload UUID, each carrying meta, url and documentId.
$upload_value = array(
	'a214983a-5e71-446d-b3ed-e831e5241f25' => array(
		'meta'       => array(
			'originalname' => 'stock-photo-golden-retriever.jpg',
			'mimetype'     => 'image/jpeg',
			'size'         => 414306,
		),
		'url'        => 'https://services.leadconnectorhq.com/documents/download/kKg9m01DoiWvuDN6GhxD',
		'documentId' => 'kKg9m01DoiWvuDN6GhxD',
	),
);

// The real field holds both: a replaced upload flagged deleted, and the live
// one. Deleted first, exactly as GoHighLevel orders it.
$two_uploads = array(
	'a214983a-5e71-446d-b3ed-e831e5241f25' => array(
		'meta'       => array(
			'originalname' => 'old.jpg',
			'mimetype'     => 'image/jpeg',
			'deleted'      => true,
		),
		'url'        => 'https://services.leadconnectorhq.com/documents/download/kKg9m01DoiWvuDN6GhxD',
		'documentId' => 'kKg9m01DoiWvuDN6GhxD',
	),
	'7316bbc6-0b6e-40d4-808f-83852c065fe2' => array(
		'meta'       => array(
			'originalname' => 'stock-photo-golden-retriever.jpg',
			'mimetype'     => 'image/jpeg',
			'size'         => 414306,
		),
		'url'        => 'https://services.leadconnectorhq.com/documents/download/C4H0ySqrrNoo5JQMnsVa',
		'documentId' => 'C4H0ySqrrNoo5JQMnsVa',
	),
);

$replaced = GHLD_Contact::normalize(
	array(
		'id'           => 'u2',
		'contactName'  => 'Replaced Upload',
		'customFields' => array(
			array(
				'id'    => 'fld_mpp',
				'value' => $two_uploads,
			),
		),
	),
	array(
		'fld_mpp' => array(
			'key'  => 'member_profile_photo',
			'name' => 'Member Profile Photo',
			'type' => 'FILE_UPLOAD',
		),
	),
	array_merge( GHLD_Settings::defaults(), array( 'photo_field' => 'cf:member_profile_photo' ) )
);

ghld_same(
	'https://services.leadconnectorhq.com/documents/download/C4H0ySqrrNoo5JQMnsVa',
	$replaced['photo'],
	'the live upload wins over one that was replaced'
);
ghld_ok(
	false === strpos( $replaced['photo'], 'kKg9m01DoiWvuDN6GhxD' ),
	'the deleted upload is never used as the headshot'
);

$replaced_card = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $replaced,
		'scope'   => GHLD_Shortcode::build_scope( array( 'show' => 'photo' ) ),
	)
);
ghld_ok(
	false !== strpos( $replaced_card, 'src="https://services.leadconnectorhq.com/documents/download/C4H0ySqrrNoo5JQMnsVa"' ),
	'the card renders the live upload as the img src'
);

$uploaded = GHLD_Contact::normalize(
	array(
		'id'           => 'u1',
		'contactName'  => 'Upload Test',
		'customFields' => array(
			array(
				'id'    => 'fld_mpp',
				'value' => $upload_value,
			),
		),
	),
	array(
		'fld_mpp' => array(
			'key'  => 'member_profile_photo',
			'name' => 'Member Profile Photo',
			'type' => 'FILE_UPLOAD',
		),
	),
	array_merge( GHLD_Settings::defaults(), array( 'photo_field' => 'cf:member_profile_photo' ) )
);

ghld_same(
	'https://services.leadconnectorhq.com/documents/download/kKg9m01DoiWvuDN6GhxD',
	$uploaded['photo'],
	'the download URL is lifted out of a file-upload field'
);
ghld_ok( false === strpos( $uploaded['photo'], 'originalname' ), 'the upload metadata is not mistaken for the URL' );

$uploaded_card = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $uploaded,
		'scope'   => GHLD_Shortcode::build_scope( array( 'show' => 'photo' ) ),
	)
);
ghld_ok(
	false !== strpos( $uploaded_card, 'src="https://services.leadconnectorhq.com/documents/download/kKg9m01DoiWvuDN6GhxD"' ),
	'the card renders that URL as the img src'
);
ghld_ok( false !== strpos( $uploaded_card, 'class="ghld-avatar"' ), 'the headshot uses the round avatar class' );

ghld_same( true, GHLD_Photos::needs_local_copy( 'https://services.leadconnectorhq.com/documents/download/abc' ), 'a document endpoint is flagged for local copying' );
ghld_same( false, GHLD_Photos::needs_local_copy( 'https://cdn.example.com/headshot.jpg' ), 'an ordinary image URL is left alone' );
ghld_same( false, GHLD_Photos::needs_local_copy( '' ), 'an empty URL needs nothing' );
ghld_same( 'png', GHLD_Photos::extension_for( 'image/png; charset=binary' ), 'the extension comes from the content type' );
ghld_same( 'jpg', GHLD_Photos::extension_for( 'application/octet-stream' ), 'an unknown type falls back to jpg' );
ghld_same( '', GHLD_Photos::localize( 'https://services.leadconnectorhq.com/documents/download/abc', 'c1', false ), 'no download happens while rendering' );

/* -------------------------------------------------------------------------
 * An uploaded headshot with no usable field definitions
 * ---------------------------------------------------------------------- */

// GoHighLevel identifies custom fields by ID in the contact payload, with no
// field key, so without the definitions nothing matches cf:member_profile_photo.
$unmappable = GHLD_Contact::normalize(
	array(
		'id'           => 'u3',
		'contactName'  => 'No Definitions',
		'customFields' => array(
			array(
				'id'    => 'WQdGude1zQ2bIljJx6vB',
				'value' => 'MD',
			),
			array(
				'id'    => 'E2TFT7iBjzqpkFEZQ9o8',
				'value' => $two_uploads,
			),
		),
	),
	array(),
	array_merge( GHLD_Settings::defaults(), array( 'photo_field' => 'cf:member_profile_photo' ) )
);

ghld_same(
	'https://services.leadconnectorhq.com/documents/download/C4H0ySqrrNoo5JQMnsVa',
	$unmappable['photo'],
	'an uploaded headshot is found even with no field definitions to match on'
);
ghld_ok( ! empty( $unmappable['file_urls'] ), 'uploaded files are tracked separately from text values' );

$text_only = GHLD_Contact::normalize(
	array(
		'id'           => 'u4',
		'contactName'  => 'Text Only',
		'customFields' => array(
			array(
				'id'    => '6AmvayNaOusB6sOQZaMV',
				'value' => 'https://example.com/a-website',
			),
		),
	),
	array(),
	array_merge( GHLD_Settings::defaults(), array( 'photo_field' => 'cf:member_profile_photo' ) )
);
ghld_same( '', $text_only['photo'], 'a plain URL in a text field is never mistaken for a headshot' );

/* -------------------------------------------------------------------------
 * A sync must not undo what enrichment gathered
 * ---------------------------------------------------------------------- */

$photo_settings = array_merge( GHLD_Settings::defaults(), array( 'photo_field' => 'cf:member_profile_photo' ) );
$photo_defs     = array(
	'fld_mpp' => array(
		'key'  => 'member_profile_photo',
		'name' => 'Member Profile Photo',
		'type' => 'FILE_UPLOAD',
	),
);

// What an individual fetch produced: custom values, including the upload.
$enriched = GHLD_Contact::normalize(
	array(
		'id'           => 'carry1',
		'contactName'  => 'Ayman Aboulela',
		'customFields' => array(
			array(
				'id'    => 'fld_mpp',
				'value' => $two_uploads,
			),
		),
	),
	$photo_defs,
	$photo_settings
);
$enriched['enriched_at'] = time();
ghld_ok( '' !== $enriched['photo'], 'the enriched copy has a headshot to lose' );

// What the contact list gives back for the same person: no custom values.
$from_list = GHLD_Contact::normalize(
	array(
		'id'          => 'carry1',
		'contactName' => 'Ayman Aboulela',
	),
	$photo_defs,
	$photo_settings
);
ghld_same( '', $from_list['photo'], 'the contact list alone carries no headshot' );

$merged = ghld_call_protected( 'GHLD_Repository', 'carry_over', array( $from_list, $enriched, $photo_settings ) );

ghld_same( $enriched['photo'], $merged['photo'], 'a full sync keeps the headshot enrichment found' );
ghld_same( $enriched['custom'], $merged['custom'], 'and the custom values it came from' );
ghld_same( $enriched['enriched_at'], $merged['enriched_at'], 'and remembers the contact was already asked' );

// The opposite direction: a fuller fresh record must still win.
$richer = GHLD_Contact::normalize(
	array(
		'id'           => 'carry1',
		'contactName'  => 'Ayman Aboulela',
		'customFields' => array(
			array(
				'id'    => 'fld_mpp',
				'value' => $two_uploads,
			),
			array(
				'id'    => 'fld_other',
				'value' => 'Cardiology',
			),
		),
	),
	$photo_defs,
	$photo_settings
);
$kept = ghld_call_protected( 'GHLD_Repository', 'carry_over', array( $richer, $enriched, $photo_settings ) );
ghld_same( count( $richer['custom'] ), count( $kept['custom'] ), 'a fresher, fuller record is not overwritten by the cached one' );

/* -------------------------------------------------------------------------
 * A contact's own page
 * ---------------------------------------------------------------------- */

ghld_same( 'page', GHLD_Settings::defaults()['view'], 'cards open a contact page by default' );
ghld_same( 'page', GHLD_Shortcode::build_scope( array() )['view'], 'and the shortcode inherits that' );
ghld_same( 'modal', GHLD_Shortcode::build_scope( array( 'view' => 'modal' ) )['view'], 'the shortcode can ask for the modal instead' );
ghld_same( 'page', GHLD_Shortcode::build_scope( array( 'view' => 'nonsense' ) )['view'], 'an unknown view falls back to the page' );

$slugged = ghld_call_protected(
	'GHLD_Repository',
	'assign_slugs',
	array(
		array(
			array( 'name' => 'Robert Bain' ),
			array( 'name' => 'Ayman Aboulela' ),
			array( 'name' => 'Robert Bain' ),
			array( 'name' => '' ),
		),
	)
);

ghld_same( 'robert-bain', $slugged[0]['slug'], 'a contact slug comes from the name' );
ghld_same( 'ayman-aboulela', $slugged[1]['slug'], 'names are slugified' );
ghld_same( 'robert-bain-2', $slugged[2]['slug'], 'a repeated name gets a numbered slug' );
ghld_same( 'contact', $slugged[3]['slug'], 'a nameless contact still gets a slug' );

$linked        = $franklin;
$linked['slug'] = 'rosalind-franklin';

ghld_same( '?ghld_contact=rosalind-franklin', GHLD_Shortcode::profile_url( $linked ), 'the profile URL hangs off the directory page' );
ghld_same( '', GHLD_Shortcode::profile_url( array( 'name' => 'No Slug' ) ), 'a contact with no slug has no page' );

$page_card = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $linked,
		'scope'   => $default_scope,
	)
);

ghld_ok( false !== strpos( $page_card, 'href="?ghld_contact=rosalind-franklin"' ), 'the card links to the contact page' );
ghld_ok( false !== strpos( $page_card, 'data-ghld-profile-link' ), 'the link is marked for the whole-card click' );
ghld_ok( false === strpos( $page_card, 'data-ghld-open' ), 'no modal trigger is rendered in page mode' );
ghld_ok( false === strpos( $page_card, 'data-ghld-detail' ), 'and no hidden detail panel is shipped' );

$profile = GHLD_Template::get(
	'contact-profile',
	array(
		'contact' => $linked,
		'scope'   => $default_scope,
		'back'    => '/directory/',
	)
);

ghld_ok( false !== strpos( $profile, '<h1 class="ghld-detail-name">Rosalind Franklin</h1>' ), 'the name is the page\'s only H1, beside the photo' );
ghld_same( 1, substr_count( $profile, '<h1' ), 'the contact page carries exactly one H1' );
ghld_ok( false !== strpos( $profile, 'See Full Directory' ), 'the back control says where it goes' );
ghld_ok( false !== strpos( $profile, 'href="/directory/"' ), 'and links back to the directory' );
ghld_ok( false !== strpos( $profile, '12 Clinic Way' ), 'the page carries the full details' );

// The scope has to hold on a URL as much as it does on the grid.
GHLD_Repository::flush();
update_option( 'ghld_settings', $defaults );
update_option( 'ghld_contacts', ghld_call_protected( 'GHLD_Repository', 'assign_slugs', array( $normalized ) ) );
update_option(
	'ghld_sync_state',
	array(
		'synced_at' => time(),
		'count'     => count( $normalized ),
		'mapping'   => GHLD_Contact::mapping_hash( $defaults ),
	)
);

$physician_scope = GHLD_Shortcode::build_scope( array() );
ghld_ok( null !== GHLD_Repository::find_by_slug( 'rosalind-franklin', $physician_scope ), 'a listed contact resolves by slug' );
ghld_ok( null === GHLD_Repository::find_by_slug( 'office-manager', $physician_scope ), 'a contact outside the scope has no page, whatever the URL says' );
ghld_ok( null === GHLD_Repository::find_by_slug( 'nobody-at-all', $physician_scope ), 'an unknown slug resolves to nothing' );

/* -------------------------------------------------------------------------
 * Keeping generated views out of search results
 * ---------------------------------------------------------------------- */

update_option( 'ghld_settings', $defaults );

$_GET = array();
ghld_same( false, GHLD_Seo::is_restricted(), 'the directory page itself stays indexable' );

$_GET = array( 'ghld_contact' => 'rosalind-franklin' );
ghld_same( true, GHLD_Seo::is_restricted(), 'a contact page is kept out of search results' );

$_GET = array( 'ghld_s' => 'cardiology' );
ghld_same( true, GHLD_Seo::is_restricted(), 'a filtered view is kept out too' );

$_GET = array( 'ghld_page' => '3' );
ghld_same( true, GHLD_Seo::is_restricted(), 'so is a paginated one' );

$_GET = array( 'utm_source' => 'newsletter' );
ghld_same( false, GHLD_Seo::is_restricted(), 'an unrelated query argument changes nothing' );

$_GET = array( 'ghld_contact' => 'rosalind-franklin' );

ob_start();
GHLD_Seo::print_robots();
$ghld_robots = ob_get_clean();
ghld_ok( false !== strpos( $ghld_robots, 'content="noindex, follow"' ), 'the robots tag is printed on a contact page' );

ghld_same( 'noindex, follow', GHLD_Seo::filter_robots_string( 'index, follow' ), 'an SEO plugin cannot override it with a string' );
$ghld_array = GHLD_Seo::filter_robots_array( array( 'index' => 'index', 'follow' => 'follow' ) );
ghld_same( 'noindex', $ghld_array['index'], 'nor with an array' );
ghld_same( 'follow', $ghld_array['follow'], 'and following links is left alone' );

update_option( 'ghld_settings', array_merge( $defaults, array( 'noindex_generated' => 0 ) ) );
ghld_same( false, GHLD_Seo::is_restricted(), 'turning the setting off lets them be indexed again' );
ghld_same( 'index, follow', GHLD_Seo::filter_robots_string( 'index, follow' ), 'and leaves the SEO plugin alone' );

$_GET = array();
update_option( 'ghld_settings', $defaults );

/* -------------------------------------------------------------------------
 * Going back to where you were
 * ---------------------------------------------------------------------- */

$paged_request = GHLD_Shortcode::parse_request(
	array(
		'ghld_s'    => 'cardiology',
		'ghld_page' => '3',
	),
	array_merge( $default_scope, array( 'filters' => array( 'search', 'tag' ) ) )
);

$deep_link = GHLD_Shortcode::profile_url( $linked, $paged_request );
ghld_ok( false !== strpos( $deep_link, 'ghld_contact=rosalind-franklin' ), 'the profile link names the contact' );
ghld_ok( false !== strpos( $deep_link, 'ghld_page=3' ), 'and remembers which page they were on' );
ghld_ok( false !== strpos( $deep_link, 'ghld_s=cardiology' ), 'and what they had searched for' );

$_GET = array(
	'ghld_contact' => 'rosalind-franklin',
	'ghld_page'    => '3',
	'ghld_s'       => 'cardiology',
);
$back = GHLD_Shortcode::directory_url();
ghld_ok( false === strpos( $back, 'ghld_contact' ), 'going back drops the contact' );
ghld_ok( false !== strpos( $back, 'ghld_page=3' ), 'and returns to the same page of results' );
ghld_ok( false !== strpos( $back, 'ghld_s=cardiology' ), 'with the same search still applied' );

$_GET = array( 'ghld_contact' => 'rosalind-franklin' );
ghld_same( '/directory/', GHLD_Shortcode::directory_url(), 'arriving straight at a contact goes back to the directory itself' );

/* -------------------------------------------------------------------------
 * A contact page shows only the contact
 * ---------------------------------------------------------------------- */

$page_content = '<p>Intro copy about the directory.</p>[ghl_directory columns="3"]<p>Promote your business!</p>';

$_GET = array( 'ghld_contact' => 'rosalind-franklin' );
ghld_same( '[ghl_directory columns="3"]', GHLD_Shortcode::isolate_profile( $page_content ), 'a contact page keeps only the directory shortcode' );
ghld_ok( in_array( 'ghld-contact-page', GHLD_Shortcode::body_class( array( 'page' ) ), true ), 'and marks the body for the theme' );

$_GET = array();
ghld_same( $page_content, GHLD_Shortcode::isolate_profile( $page_content ), 'the directory page itself is untouched' );
ghld_ok( ! in_array( 'ghld-contact-page', GHLD_Shortcode::body_class( array( 'page' ) ), true ), 'and carries no extra body class' );

$_GET = array( 'ghld_contact' => 'rosalind-franklin' );
update_option( 'ghld_settings', array_merge( $defaults, array( 'isolate_profile' => 0 ) ) );
ghld_same( $page_content, GHLD_Shortcode::isolate_profile( $page_content ), 'turning the setting off leaves the page alone' );

$_GET = array();
update_option( 'ghld_settings', $defaults );

/* -------------------------------------------------------------------------
 * Clearing the field in GoHighLevel clears the headshot
 * ---------------------------------------------------------------------- */

// Emptying a file-upload field does not remove the entries: every upload the
// field ever held stays, flagged deleted.
$all_deleted = array(
	'd1931bd6-a23b-4b67-bdcc-f1193086de81' => array(
		'meta'       => array(
			'originalname' => 'beach.jpg',
			'mimetype'     => 'image/jpeg',
			'deleted'      => true,
		),
		'url'        => 'https://services.leadconnectorhq.com/documents/download/iqgAsv7O80w610HvTaQU',
		'documentId' => 'iqgAsv7O80w610HvTaQU',
	),
	'54049598-6868-4545-b98e-2eaa3670f3c0' => array(
		'meta'       => array(
			'originalname' => 'stock-photo-golden-retriever.jpg',
			'mimetype'     => 'image/jpeg',
			'deleted'      => true,
		),
		'url'        => 'https://services.leadconnectorhq.com/documents/download/VdveR30LSI2ZyO8i5gSB',
		'documentId' => 'VdveR30LSI2ZyO8i5gSB',
	),
);

$emptied = GHLD_Contact::normalize(
	array(
		'id'           => 'e1',
		'contactName'  => 'Emptied Field',
		'customFields' => array(
			array(
				'id'    => 'fld_mpp',
				'value' => $all_deleted,
			),
		),
	),
	array(
		'fld_mpp' => array(
			'key'  => 'member_profile_photo',
			'name' => 'Member Profile Photo',
			'type' => 'FILE_UPLOAD',
		),
	),
	array_merge( GHLD_Settings::defaults(), array( 'photo_field' => 'cf:member_profile_photo' ) )
);

ghld_same( '', $emptied['photo'], 'clearing the field leaves no headshot, not the last deleted one' );
ghld_same( '', isset( $emptied['custom']['member_profile_photo'] ) ? $emptied['custom']['member_profile_photo'] : '', 'and no value behind it' );
ghld_ok( empty( $emptied['file_urls'] ), 'deleted uploads are not offered as files either' );

$emptied_card = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $emptied,
		'scope'   => GHLD_Shortcode::build_scope( array( 'show' => 'photo' ) ),
	)
);
ghld_ok( false === strpos( $emptied_card, 'iqgAsv7O80w610HvTaQU' ), 'the card does not fall back to a deleted upload' );
ghld_ok( false !== strpos( $emptied_card, 'ghld-avatar-placeholder' ), 'it shows the placeholder instead' );

/* -------------------------------------------------------------------------
 * The panel beside a contact
 * ---------------------------------------------------------------------- */

ghld_same( '', GHLD_Settings::defaults()['profile_sidebar'], 'no panel is shown until one is written' );

$plain_profile = GHLD_Template::get(
	'contact-profile',
	array(
		'contact' => $linked,
		'scope'   => $default_scope,
		'back'    => '/directory/',
	)
);
ghld_ok( false === strpos( $plain_profile, 'ghld-profile-sidebar' ), 'an empty panel adds no column' );
ghld_ok( false === strpos( $plain_profile, 'has-sidebar' ), 'and the contact takes the full width' );
ghld_ok( false !== strpos( $plain_profile, 'class="btn-blue"' ), 'the back control carries only the theme class' );
ghld_ok( false === strpos( $plain_profile, 'ghld-back-link' ), 'and none of the plugin\'s own' );

update_option(
	'ghld_settings',
	array_merge(
		$defaults,
		array( 'profile_sidebar' => '<h3>Are you this physician?</h3><p>Contact us.</p>[ghld_test_shortcode]' )
	)
);

$with_sidebar = GHLD_Template::get(
	'contact-profile',
	array(
		'contact' => $linked,
		'scope'   => $default_scope,
		'back'    => '/directory/',
	)
);

ghld_ok( false !== strpos( $with_sidebar, 'ghld-profile-sidebar' ), 'a written panel gets its own column' );
ghld_ok( false !== strpos( $with_sidebar, 'has-sidebar' ), 'and the layout makes room for it' );
ghld_ok( false !== strpos( $with_sidebar, '<h3>Are you this physician?</h3>' ), 'the markup is rendered, not escaped' );
ghld_ok( false !== strpos( $with_sidebar, 'SHORTCODE OUTPUT' ), 'shortcodes inside it run' );
ghld_ok( false === strpos( $with_sidebar, '[ghld_test_shortcode]' ), 'and are not left showing as text' );

// The panel is global but sits beside one physician, so it can name them.
update_option(
	'ghld_settings',
	array_merge(
		$defaults,
		array( 'profile_sidebar' => '<p>Are you {name}?</p><p>{specialty} in {city}.</p>' )
	)
);

$personal = GHLD_Template::get(
	'contact-profile',
	array(
		'contact' => array_merge( $linked, array( 'specialty' => 'Cardiology', 'city' => 'Panama City' ) ),
		'scope'   => $default_scope,
		'back'    => '/directory/',
	)
);

ghld_ok( false !== strpos( $personal, 'Are you Rosalind Franklin?' ), 'the panel can name the physician it sits beside' );
ghld_ok( false !== strpos( $personal, 'Cardiology in Panama City.' ), 'and use their other details' );
ghld_ok( false === strpos( $personal, '{name}' ), 'no placeholder is left showing' );

ghld_same( null, GHLD_Shortcode::current_contact(), 'the contact is cleared once the panel is rendered' );

update_option(
	'ghld_settings',
	array_merge( $defaults, array( 'profile_sidebar' => '<p>Are you {name}?</p>' ) )
);
$escaped = GHLD_Template::get(
	'contact-profile',
	array(
		'contact' => array_merge( $linked, array( 'name' => '<script>alert(1)</script>' ) ),
		'scope'   => $default_scope,
		'back'    => '/directory/',
	)
);
ghld_ok( false === strpos( $escaped, '<script>alert' ), 'a contact value dropped into the panel is escaped' );

$sanitized = GHLD_Settings::sanitize(
	array_merge( $defaults, array( 'profile_sidebar' => '<p>Keep me</p>' ) )
);
ghld_ok( false !== strpos( $sanitized['profile_sidebar'], '<p>Keep me</p>' ), 'saving the panel keeps its markup' );

update_option( 'ghld_settings', $defaults );

/* -------------------------------------------------------------------------
 * The placeholder for a contact with no headshot
 * ---------------------------------------------------------------------- */

update_option( 'ghld_settings', $defaults );

$no_photo = GHLD_Contact::normalize(
	array(
		'id'          => 'np1',
		'contactName' => 'No Photo',
	),
	array(),
	$defaults
);

$no_photo_card = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $no_photo,
		'scope'   => GHLD_Shortcode::build_scope( array( 'show' => 'photo' ) ),
	)
);

ghld_ok( false !== strpos( $no_photo_card, 'ghld-avatar-placeholder' ), 'a contact with no headshot gets the placeholder image' );
ghld_ok( false !== strpos( $no_photo_card, 'default-headshot.jpg' ), 'which is the one bundled with the plugin' );
ghld_ok( false === strpos( $no_photo_card, 'ghld-avatar-initials' ), 'and not the initials circle' );
ghld_same( '', $no_photo['photo'], 'the placeholder is never written into the contact, so enrichment still knows they need one' );

update_option( 'ghld_settings', array_merge( $defaults, array( 'default_photo' => 'https://example.test/ours.png' ) ) );
$custom_placeholder = GHLD_Template::get(
	'contact-card',
	array(
		'contact' => $no_photo,
		'scope'   => GHLD_Shortcode::build_scope( array( 'show' => 'photo' ) ),
	)
);
ghld_ok( false !== strpos( $custom_placeholder, 'https://example.test/ours.png' ), 'a placeholder of your own replaces the bundled one' );

update_option( 'ghld_settings', $defaults );

/* -------------------------------------------------------------------------
 * Specialty labels
 * ---------------------------------------------------------------------- */

$labelled = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => array_merge(
			$bone,
			array( 'custom' => array( 'secondary_speciality' => 'Geriatrics' ) )
		),
		'scope'   => array_merge(
			$bone_scope,
			array( 'modal_show' => array( 'specialty', 'cf:secondary_speciality' ) )
		),
	)
);

ghld_ok( false !== strpos( $labelled, 'Specialty: Infectious Disease, Internal Medicine' ), 'the primary specialties are prefixed with their label' );
ghld_ok( false !== strpos( $labelled, ':</dt>' ), 'a field label ends with a colon' );
ghld_ok( false !== strpos( $labelled, 'Geriatrics' ), 'and its value sits beside it' );

/* -------------------------------------------------------------------------
 * Only the tagged contacts are cached at all
 * ---------------------------------------------------------------------- */

$location = array(
	GHLD_Contact::normalize(
		array(
			'id'          => 'loc1',
			'contactName' => 'A Physician',
			'tags'        => array( 'member - physician' ),
		),
		array(),
		$defaults
	),
	GHLD_Contact::normalize(
		array(
			'id'          => 'loc2',
			'contactName' => 'An Administrator',
			'tags'        => array( 'member - staff' ),
		),
		array(),
		$defaults
	),
	GHLD_Contact::normalize(
		array(
			'id'          => 'loc3',
			'contactName' => 'Someone Untagged',
			'tags'        => array(),
		),
		array(),
		$defaults
	),
);

$narrowed = ghld_call_protected(
	'GHLD_Repository',
	'apply_scope',
	array(
		$location,
		array(
			'tags'         => GHLD_Settings::to_list( $defaults['include_tags'] ),
			'exclude_tags' => array(),
		),
	)
);

ghld_same( 1, count( $narrowed ), 'a location of mixed contacts narrows to the tagged ones before anything is fetched' );
ghld_same( 'A Physician', $narrowed[0]['name'], 'and it is the right one' );

$unrestricted = ghld_call_protected(
	'GHLD_Repository',
	'apply_scope',
	array(
		$location,
		array(
			'tags'         => array(),
			'exclude_tags' => array(),
		),
	)
);
ghld_same( 3, count( $unrestricted ), 'with no include tags set, everyone is kept' );

/* -------------------------------------------------------------------------
 * The published email address
 * ---------------------------------------------------------------------- */

$email_fields = array(
	'fld_docmail' => array(
		'key'  => 'doctors_email_address',
		'name' => "Doctor's email address",
		'type' => 'TEXT',
	),
);

$with_practice_email = GHLD_Contact::normalize(
	array(
		'id'           => 'em1',
		'contactName'  => 'Mapped Email',
		'email'        => 'personal@example.com',
		'customFields' => array(
			array(
				'id'    => 'fld_docmail',
				'value' => 'practice@example.com',
			),
		),
	),
	$email_fields,
	$defaults
);
ghld_same( 'practice@example.com', $with_practice_email['email'], 'the mapped field is the address that gets published' );
ghld_same( 'personal@example.com', $with_practice_email['email_raw'], "and the contact's own address is still kept" );

$no_mapped_email = GHLD_Contact::normalize(
	array(
		'id'          => 'em2',
		'contactName' => 'Unmapped Email',
		'email'       => 'personal@example.com',
	),
	$email_fields,
	$defaults
);
ghld_same( '', $no_mapped_email['email'], 'an empty mapped field publishes no address at all' );
ghld_same( 'personal@example.com', $no_mapped_email['email_raw'], 'though the address on the record is still held' );

$bad_mapped_email = GHLD_Contact::normalize(
	array(
		'id'           => 'em3',
		'contactName'  => 'Bad Email',
		'email'        => 'personal@example.com',
		'customFields' => array(
			array(
				'id'    => 'fld_docmail',
				'value' => 'not an address',
			),
		),
	),
	$email_fields,
	$defaults
);
ghld_same( '', $bad_mapped_email['email'], 'nor does a mapped value that is not a valid address' );

$own_email = GHLD_Contact::normalize(
	array(
		'id'          => 'em4',
		'contactName' => 'Own Email',
		'email'       => 'personal@example.com',
	),
	$email_fields,
	array_merge( $defaults, array( 'email_field' => '' ) )
);
ghld_same( 'personal@example.com', $own_email['email'], "mapping set to the contact's own address publishes that one" );
ghld_same( 'cf:doctors_email_address', GHLD_Settings::defaults()['email_field'], "email maps to the doctor's email field by default" );

/* -------------------------------------------------------------------------
 * The published phone number
 * ---------------------------------------------------------------------- */

$phone_fields = array(
	'fld_office' => array(
		'key'  => 'office_phone',
		'name' => 'Office Phone',
		'type' => 'TEXT',
	),
);

ghld_same( '', GHLD_Settings::defaults()['phone_field'], 'no phone field is mapped until one is chosen' );

$own_phone = GHLD_Contact::normalize(
	array(
		'id'          => 'ph1',
		'contactName' => 'Own Phone',
		'phone'       => '+1 555-0100',
	),
	$phone_fields,
	$defaults
);
ghld_same( '+1 555-0100', $own_phone['phone'], "unmapped, the contact's own number is published" );

$office_settings = array_merge( $defaults, array( 'phone_field' => 'cf:office_phone' ) );

$mapped_phone = GHLD_Contact::normalize(
	array(
		'id'           => 'ph2',
		'contactName'  => 'Office Phone',
		'phone'        => '+1 555-0100',
		'customFields' => array(
			array(
				'id'    => 'fld_office',
				'value' => '(850) 769-1644',
			),
		),
	),
	$phone_fields,
	$office_settings
);
ghld_same( '(850) 769-1644', $mapped_phone['phone'], 'the mapped field is the number that gets published' );
ghld_same( '+1 555-0100', $mapped_phone['phone_raw'], "and the contact's own number is still held" );

$no_office_phone = GHLD_Contact::normalize(
	array(
		'id'          => 'ph3',
		'contactName' => 'No Office Phone',
		'phone'       => '+1 555-0100',
	),
	$phone_fields,
	$office_settings
);
ghld_same( '', $no_office_phone['phone'], 'an empty mapped field publishes no number, not the mobile on the record' );

$office_card = GHLD_Template::get(
	'contact-detail',
	array(
		'contact' => $mapped_phone,
		'scope'   => array_merge( $default_scope, array( 'modal_show' => array( 'phone' ) ) ),
	)
);
ghld_ok( false !== strpos( $office_card, 'tel:8507691644' ), 'the published number is what gets dialled' );
ghld_ok( false === strpos( $office_card, '5550100' ), "and the record's own number is nowhere on the page" );

/* -------------------------------------------------------------------------
 * Clearing the page cache when the directory changes
 * ---------------------------------------------------------------------- */

update_option( 'ghld_settings', $defaults );

ghld_test_reset_actions();
GHLD_Purge::flush( 'settings' );
ghld_ok( ghld_test_action_fired( 'ghld_purged_cache' ), 'a purge reports itself' );
ghld_ok( ghld_test_action_fired( 'litespeed_purge_all' ), 'and asks the caches that listen for an action' );

update_option( 'ghld_settings', array_merge( $defaults, array( 'purge_cache' => 0 ) ) );
ghld_test_reset_actions();
GHLD_Purge::flush( 'settings' );
ghld_ok( ! ghld_test_action_fired( 'ghld_purged_cache' ), 'turning the setting off stops it' );

update_option( 'ghld_settings', $defaults );

// The fingerprint decides whether a sync purges at all.
$before_contacts = array(
	array( 'id' => 'f1', 'name' => 'Ayman Aboulela', 'photo' => 'https://cdn.example.com/a.jpg', 'specialty' => 'Internal Medicine' ),
	array( 'id' => 'f2', 'name' => 'Azzam Adhal', 'photo' => '', 'specialty' => 'Geriatrics' ),
);

ghld_same(
	GHLD_Purge::fingerprint( $before_contacts ),
	GHLD_Purge::fingerprint( $before_contacts ),
	'an unchanged directory fingerprints the same, so an hourly sync purges nothing'
);

$after_photo = $before_contacts;
$after_photo[1]['photo'] = 'https://cdn.example.com/b.jpg';
ghld_ok(
	GHLD_Purge::fingerprint( $before_contacts ) !== GHLD_Purge::fingerprint( $after_photo ),
	'a headshot arriving changes it'
);

$after_specialty = $before_contacts;
$after_specialty[0]['specialty'] = 'Cardiology';
ghld_ok(
	GHLD_Purge::fingerprint( $before_contacts ) !== GHLD_Purge::fingerprint( $after_specialty ),
	'and so does a specialty being edited in GoHighLevel'
);

$reordered = array( $before_contacts[1], $before_contacts[0] );
ghld_ok(
	GHLD_Purge::fingerprint( $before_contacts ) !== GHLD_Purge::fingerprint( $reordered ),
	'a different order is a different page, so that counts too'
);

/* -------------------------------------------------------------------------
 * Telling a cached page from a live one
 * ---------------------------------------------------------------------- */

update_option( 'ghld_settings', $defaults );

// A purge says what it was actually able to reach, and nothing on a bare test
// install exposes a page cache — which is exactly the report that matters when
// somebody says the old page is still showing.
ghld_same( array(), GHLD_Purge::flush( 'manual' ), 'a purge with no cache present reaches nothing' );

$last = GHLD_Purge::last();
ghld_same( 'manual', isset( $last['reason'] ) ? $last['reason'] : '', 'and records why it ran' );
ghld_ok( ! empty( $last['at'] ), 'and when' );
ghld_same( array(), isset( $last['ran'] ) ? $last['ran'] : null, 'and that it cleared nothing, rather than implying success' );

foreach ( GHLD_Purge::targets() as $label => $target ) {
	ghld_ok( isset( $target['call'], $target['available'] ), 'cache target "' . $label . '" is described for the settings screen' );
}

// The render stamp is what dates a page from outside.
$stamp = GHLD_Diagnostics::marker( '<!-- ghld v=1.17.1 view=directory post=12 contacts=305 synced=2026-10-07T14:02Z rendered=2026-10-07T15:30:01Z -->' );
ghld_same( '1.17.1', isset( $stamp['v'] ) ? $stamp['v'] : '', 'the render stamp gives up the version that built the page' );
ghld_same( '2026-10-07T15:30:01Z', $stamp['rendered'], 'and when that HTML was built' );
ghld_same( '305', $stamp['contacts'], 'and how many contacts it knew about' );
ghld_same( array(), GHLD_Diagnostics::marker( '<html><body>no directory here</body></html>' ), 'a page without the directory has no stamp' );

// The whole point of the stamp is that the probe can read what the render
// wrote, so the two halves are checked against each other rather than against a
// fixture of the format.
$GLOBALS['ghld_test_post_id'] = 12;
$rendered = GHLD_Shortcode::render( array() );
$read     = GHLD_Diagnostics::marker( $rendered );

ghld_ok( 0 === strpos( $rendered, '<!-- ghld ' ), 'a rendered directory carries a render stamp' );
ghld_same( 'directory', isset( $read['view'] ) ? $read['view'] : '', 'which the probe reads back off the real output' );
ghld_same( '12', isset( $read['post'] ) ? $read['post'] : '', 'naming the page it came from, so a second stale copy of the page is visible' );
ghld_ok( ! empty( $read['rendered'] ) && false === strpos( $read['rendered'], ' ' ), 'with a timestamp that survives being split on whitespace' );
ghld_same( GHLD_VERSION, isset( $read['v'] ) ? $read['v'] : '', 'and the version that built it' );

/* The headshot failure: a file-upload field stores an API address, not a public
 * file, so an <img> pointing at it loads for a browser signed in to GoHighLevel
 * and for nobody else. The directory then swaps the failed image for initials,
 * which is why other people's cards look emptier. */

ghld_ok(
	GHLD_Photos::needs_local_copy( 'https://services.leadconnectorhq.com/documents/download/kKg9m01DoiWvuDN6GhxD' ),
	'a GoHighLevel documents address is recognised as needing a local copy'
);
ghld_ok(
	! GHLD_Photos::needs_local_copy( 'https://example.test/wp-content/uploads/gohighlevel-photos/abc-123.jpg' ),
	'and a copy already on this site does not'
);
ghld_ok(
	empty( GHLD_Settings::defaults()['cache_photos'] ),
	'copying headshots here stays off by default: GoHighLevel does serve them, and a copy on this domain would sit behind a staging password gate that an external image escapes'
);

// The probe requests the headshots with no cookies, which is the only request
// that answers whether a visitor can see them.
$ghld_cards = '<!-- ghld v=1.17.3 view=directory post=12 contacts=305 synced=2026-10-07T14:02Z rendered=2026-10-07T17:00:00Z css=head -->'
	. '<img class="ghld-avatar" src="https://services.leadconnectorhq.com/documents/download/aaa" data-ghld-initials="AA" />'
	. '<img class="ghld-avatar" src="https://services.leadconnectorhq.com/documents/download/bbb" data-ghld-initials="BB" />';

ghld_test_http( 'https://services.leadconnectorhq.com/documents/download/aaa', array( 'status' => 401, 'body' => 'Unauthorized' ) );
ghld_test_http( 'https://services.leadconnectorhq.com/documents/download/bbb', array( 'status' => 401, 'body' => 'Unauthorized' ) );

$ghld_shots = GHLD_Diagnostics::photos( $ghld_cards );
ghld_same( 2, $ghld_shots['checked'], 'the probe samples the headshots on the page' );
ghld_same( 2, $ghld_shots['failing'], 'and reports the ones a visitor cannot load' );
ghld_same( 2, $ghld_shots['external'], 'noting that they point at GoHighLevel rather than this site' );

$ghld_said = GHLD_Diagnostics::photo_verdict( array( 'fresh' => array( 'photos' => $ghld_shots ) ) );
ghld_same( 'error', $ghld_said['state'], 'which is a fault, not a pass' );
ghld_ok( false !== strpos( $ghld_said['body'], 'API endpoint' ), 'and the explanation names the cause' );
ghld_ok( false !== strpos( $ghld_said['body'], 'signed in' ), 'including why it looks fine to the person checking' );

// Local copies, served by this site, load for everybody.
$ghld_local = '<!-- ghld v=1.17.3 view=directory post=12 contacts=305 synced=2026-10-07T14:02Z rendered=2026-10-07T17:00:00Z css=head -->'
	. '<img class="ghld-avatar" src="https://example.test/wp-content/uploads/gohighlevel-photos/c1-abc.jpg" data-ghld-initials="AA" />';
ghld_test_http( 'https://example.test/wp-content/uploads/gohighlevel-photos/c1-abc.jpg', array( 'status' => 200, 'body' => 'JPEGDATA' ) );

$ghld_shots = GHLD_Diagnostics::photos( $ghld_local );
ghld_same( 1, $ghld_shots['loading'], 'a headshot copied here loads for a visitor' );
ghld_same( 0, $ghld_shots['external'], 'and is not pointing at GoHighLevel any more' );
ghld_same( 'ok', GHLD_Diagnostics::photo_verdict( array( 'fresh' => array( 'photos' => $ghld_shots ) ) )['state'], 'so the headshots are reported as working' );

ghld_same( array( 'checked' => 0, 'loading' => 0, 'failing' => 0, 'external' => 0, 'examples' => array() ), GHLD_Diagnostics::photos( '<p>no cards</p>' ), 'a page with no headshots reports none rather than guessing' );

/* Asking downstream caches not to keep the page is the only lever that reaches a
 * copy held in somebody else's browser, office proxy or CDN edge — and it only
 * fires on a page the plugin recognises as showing the directory, so that
 * recognition has to cope with a layout a page builder keeps in post meta rather
 * than in post_content. */

ghld_test_set_post( '<p>Our physicians</p>[ghl_directory]' );
ghld_ok( GHLD_Shortcode::page_has_directory(), 'a shortcode in the page content is found' );

ghld_test_set_post( '<p>Nothing here</p>' );
ghld_ok( ! GHLD_Shortcode::page_has_directory(), 'a page without the directory is not claimed' );

ghld_test_set_post( '', array( '_elementor_data' => '[{"widgetType":"shortcode","settings":{"shortcode":"[ghl_directory]"}}]' ) );
ghld_ok( GHLD_Shortcode::page_has_directory(), 'a directory placed with Elementor is found, though post_content is empty' );

ghld_test_set_post( '', array( 'panels_data' => '{"widgets":[{"text":"[ghl_directory layout=list]"}]}' ) );
ghld_ok( GHLD_Shortcode::page_has_directory(), 'and one placed with another builder' );

ghld_test_set_post( '', array( '_elementor_data' => '[{"widgetType":"heading"}]' ) );
ghld_ok( ! GHLD_Shortcode::page_has_directory(), 'while a builder page without it is still not claimed' );

// The no-cache headers only go out when asked for, and only on the right page.
ghld_test_set_post( '[ghl_directory]' );
update_option( 'ghld_settings', $defaults );
ghld_test_reset_actions();
GHLD_Purge::maybe_no_cache();
ghld_ok( ! ghld_test_action_fired( 'litespeed_control_set_nocache' ), 'left alone, the directory page stays cacheable' );

update_option( 'ghld_settings', array_merge( $defaults, array( 'no_cache_directory' => 1 ) ) );
ghld_test_reset_actions();
GHLD_Purge::maybe_no_cache();
ghld_ok( ghld_test_action_fired( 'litespeed_control_set_nocache' ), 'turned on, it asks caches not to keep the page' );

ghld_test_set_post( '<p>Some other page</p>' );
ghld_test_reset_actions();
GHLD_Purge::maybe_no_cache();
ghld_ok( ! ghld_test_action_fired( 'litespeed_control_set_nocache' ), 'and leaves every other page on the site cacheable' );

ghld_test_set_post( '[ghl_directory]' );
update_option( 'ghld_settings', $defaults );

/* A server-level password gate — a staging or coming-soon lock — answers before
 * WordPress runs, so every file on the domain is refused to anyone without the
 * credentials while images hosted elsewhere load normally. That is a styled page
 * for the person holding the password and an unstyled one with working
 * photographs for everybody else, and it is not a cache. */

$ghld_gated = '<!-- ghld v=1.17.4 view=directory post=12 contacts=305 synced=2026-10-07T14:02Z rendered=2026-10-07T17:30:00Z css=head -->'
	. '<link rel="stylesheet" href="https://example.test/wp-content/plugins/gohighlevel-integration/assets/css/gohighlevel-integration.css?ver=1.17.4" />';

ghld_test_http(
	'https://example.test/wp-content/plugins/gohighlevel-integration/assets/css/gohighlevel-integration.css?ver=1.17.4',
	array( 'status' => 401, 'body' => 'Authorization Required', 'headers' => array( 'www-authenticate' => 'Basic realm="Restricted"' ) )
);

$css = GHLD_Diagnostics::css( $ghld_gated, 'https://example.test/physician-directory/' );
ghld_same( 401, $css['status'], 'a stylesheet refused by a password gate is seen as refused' );
$ghld_said = GHLD_Diagnostics::css_verdict( array( 'fresh' => array( 'css' => $css ) ) );
ghld_same( 'error', $ghld_said['state'], 'and reported as a fault' );
ghld_ok( false !== strpos( $ghld_said['title'], 'password gate' ), 'named as the gate rather than a missing file' );
ghld_ok( false !== strpos( $ghld_said['body'], 'Write the styling into the page' ), 'with the setting that works around it' );

$ghld_said = GHLD_Diagnostics::verdict(
	array(
		'cached' => array( 'status' => 401, 'marker' => array(), 'cards' => 0, 'headers' => array( 'www-authenticate' => 'Basic realm="Restricted"' ) ),
		'fresh'  => array( 'status' => 401, 'marker' => array(), 'cards' => 0, 'headers' => array() ),
	)
);
ghld_ok( false !== strpos( $ghld_said['title'], 'password gate' ), 'a gated site is reported as gated, not as the wrong page' );
ghld_ok( false !== strpos( $ghld_said['body'], 'not a cache' ), 'and says plainly that this is not a caching problem' );

ghld_ok(
	in_array( 'www-authenticate', array_keys( GHLD_Diagnostics::cache_headers( array( 'headers' => array( 'www-authenticate' => 'Basic realm="x"' ) ) ) ), true ),
	'the gate\'s own header is kept in the report'
);

/* The stylesheet has to actually reach the page, which enqueuing alone does not
 * promise: a directory rendered after <head> has been sent, or a page whose CSS
 * an optimiser rewrote, loses it — and loses it only for the visitors who are
 * not logged in. */

$GLOBALS['ghld_test_did_wp_head'] = true;
$GLOBALS['ghld_test_style_done']  = true;
$styled = GHLD_Shortcode::render( array() );
ghld_ok( false === strpos( $styled, 'ghld-css-late' ), 'a page that already printed the stylesheet is left alone' );
ghld_same( 'head', GHLD_Diagnostics::marker( $styled )['css'], 'and says so in the stamp' );

$GLOBALS['ghld_test_style_done'] = false;
$rescued = GHLD_Shortcode::render( array() );
ghld_ok( false !== strpos( $rescued, 'ghld-css-late' ), 'a page that did not gets the stylesheet beside the directory instead' );
ghld_ok( false !== strpos( $rescued, 'gohighlevel-integration.css?ver=' . GHLD_VERSION ), 'at this version, so an old cached copy is not reused' );
ghld_same( 'late', GHLD_Diagnostics::marker( $rescued )['css'], 'and the stamp records which way it got there' );

update_option( 'ghld_settings', array_merge( $defaults, array( 'inline_css' => 1 ) ) );
$inlined = GHLD_Shortcode::render( array() );
ghld_ok( false !== strpos( $inlined, '<style id="ghld-css-inline">' ), 'asked to, it writes the CSS into the page instead of linking it' );
ghld_ok( false !== strpos( $inlined, '.ghld-' ), 'with the real rules in it' );
ghld_ok( false === strpos( $inlined, 'ghld-css-late' ), 'and then does not also link the file' );
ghld_same( 'inline', GHLD_Diagnostics::marker( $inlined )['css'], 'which the stamp reports too' );

$GLOBALS['ghld_test_did_wp_head'] = false;
update_option( 'ghld_settings', $defaults );
$early = GHLD_Shortcode::render( array() );
ghld_ok( false === strpos( $early, 'ghld-css-late' ), 'before the head is sent, the normal enqueue is left to do its job' );
ghld_same( 'queued', GHLD_Diagnostics::marker( $early )['css'], 'and the stamp says it is still queued' );

$GLOBALS['ghld_test_did_wp_head'] = true;
$GLOBALS['ghld_test_style_done']  = true;

// The probe says whether a visitor's styling actually loaded, which is a
// different question from whether the page was cached.
$ghld_page = '<!-- ghld v=1.17.2 view=directory post=12 contacts=305 synced=2026-10-07T14:02Z rendered=2026-10-07T16:40:00Z css=head -->'
	. '<link rel="stylesheet" href="https://example.test/wp-content/plugins/gohighlevel-integration/assets/css/gohighlevel-integration.css?ver=1.17.2" />';

ghld_test_http(
	'https://example.test/wp-content/plugins/gohighlevel-integration/assets/css/gohighlevel-integration.css?ver=1.17.2',
	array( 'status' => 200, 'body' => '.ghld-card{border:1px solid #ddd}', 'headers' => array() )
);

$css = GHLD_Diagnostics::css( $ghld_page, 'https://example.test/physician-directory/' );
ghld_ok( $css['linked'], 'the probe sees the page asking for the stylesheet' );
ghld_same( 200, $css['status'], 'fetches that address itself, with no login' );
ghld_ok( $css['ours'], 'and confirms the response really is the plugin\'s CSS' );
ghld_same( 'ok', GHLD_Diagnostics::css_verdict( array( 'fresh' => array( 'css' => $css ) ) )['state'], 'so the styling is reported as working' );

// A stylesheet stripped out of the HTML: the symptom being reported.
$ghld_stripped = '<!-- ghld v=1.17.2 view=directory post=12 contacts=305 synced=2026-10-07T14:02Z rendered=2026-10-07T16:40:00Z css=head -->'
	. '<link rel="stylesheet" href="https://example.test/wp-content/cache/combined-9f2.css" />';
$css = GHLD_Diagnostics::css( $ghld_stripped, 'https://example.test/physician-directory/' );
ghld_ok( ! $css['linked'], 'a page whose CSS was combined away is seen as not asking for it' );
$ghld_said = GHLD_Diagnostics::css_verdict( array( 'fresh' => array( 'css' => $css ) ) );
ghld_same( 'error', $ghld_said['state'], 'which is reported as a fault, not a pass' );
ghld_ok( false !== strpos( $ghld_said['body'], 'combines' ), 'and names the usual cause' );

// Linked, but the address does not serve it.
$ghld_missing = str_replace( 'gohighlevel-integration.css?ver=1.17.2', 'gohighlevel-integration.css?ver=9.9.9', $ghld_page );
ghld_test_http(
	'https://example.test/wp-content/plugins/gohighlevel-integration/assets/css/gohighlevel-integration.css?ver=9.9.9',
	array( 'status' => 404, 'body' => 'Not found', 'headers' => array() )
);
$css = GHLD_Diagnostics::css( $ghld_missing, 'https://example.test/physician-directory/' );
ghld_same( 404, $css['status'], 'a stylesheet address that does not serve is caught' );
ghld_ok( ! $css['ours'], 'and not mistaken for the real file' );
ghld_same( 'error', GHLD_Diagnostics::css_verdict( array( 'fresh' => array( 'css' => $css ) ) )['state'], 'and reported as a fault' );

// A 200 from something that is not the stylesheet at all.
ghld_test_http(
	'https://example.test/wp-content/plugins/gohighlevel-integration/assets/css/gohighlevel-integration.css?ver=9.9.9',
	array( 'status' => 200, 'body' => '<html><body>Access denied</body></html>', 'headers' => array() )
);
$css = GHLD_Diagnostics::css( $ghld_missing, 'https://example.test/physician-directory/' );
ghld_ok( ! $css['ours'], 'a 200 from something that is not the CSS does not count as working' );
$ghld_said = GHLD_Diagnostics::css_verdict( array( 'fresh' => array( 'css' => $css ) ) );
ghld_same( 'error', $ghld_said['state'], 'and is reported as something else answering' );

ghld_same(
	'1.16.1',
	GHLD_Diagnostics::stylesheet( '<link rel="stylesheet" href="https://example.test/wp-content/plugins/gohighlevel-integration/assets/css/gohighlevel-integration.css?ver=1.16.1" />' ),
	'stale HTML is dated a second way, by the stylesheet version it asks for'
);
ghld_same( '', GHLD_Diagnostics::stylesheet( '<link rel="stylesheet" href="/theme.css" />' ), 'and a page not loading it at all says so'
);

$headers = GHLD_Diagnostics::cache_headers(
	array(
		'headers' => array(
			'x-cache'       => 'HIT: 3',
			'age'           => '86400',
			'content-type'  => 'text/html',
			'cache-control' => 'max-age=600',
		),
	)
);
ghld_same( 'HIT: 3', $headers['x-cache'], 'the cache headers are picked out of the response' );
ghld_same( '86400', $headers['age'], 'including how long the copy has been held' );
ghld_ok( ! isset( $headers['content-type'] ), 'and nothing that says nothing about caching' );

$ghld_old = '<!-- ghld v=1.16.1 view=directory post=12 contacts=305 synced=2026-10-01T09:00Z rendered=2026-10-01T09:04:11Z -->'
	. str_repeat( '<li class="ghld-grid-item"><h3>Ayman Aboulela</h3></li>', 24 );
$ghld_new = '<!-- ghld v=1.17.1 view=directory post=12 contacts=305 synced=2026-10-07T14:02Z rendered=2026-10-07T15:30:01Z -->'
	. str_repeat( '<li class="ghld-grid-item"><h3>Ayman Aboulela</h3><p class="ghld-specialty">Specialty: Internal Medicine</p></li>', 24 );

ghld_test_http(
	'https://example.test/physician-directory/',
	array(
		'status'  => 200,
		'body'    => $ghld_old,
		'headers' => array( 'x-cache' => 'HIT', 'age' => '91000' ),
	)
);

$probe = GHLD_Diagnostics::probe( 'https://example.test/physician-directory/' );

// Both URLs resolve to the queued response here, which is the "no cache in
// front" case: identical stamps.
ghld_same( 24, $probe['cached']['cards'], 'the probe counts the cards a visitor actually receives' );
ghld_same( 0, $probe['cached']['specialties'], 'and notices when the specialty lines are missing from them' );
ghld_same( 24, $probe['cached']['cards'], 'from the anonymous response, not the admin view' );

$verdict = GHLD_Diagnostics::verdict( $probe );
ghld_same( 'ok', $verdict['state'], 'two identical responses mean nothing is caching the page' );

// Now the response that proves a cache: the plain URL is old, the unique one is
// built fresh.
$probe['fresh'] = array(
	'status'      => 200,
	'bytes'       => strlen( $ghld_new ),
	'marker'      => GHLD_Diagnostics::marker( $ghld_new ),
	'cards'       => 24,
	'specialties' => 24,
	'stylesheet'  => '1.17.1',
	'headers'     => array(),
);

$verdict = GHLD_Diagnostics::verdict( $probe );
ghld_same( 'warning', $verdict['state'], 'an older plain response and a fresh unique one means a cache is in front' );
ghld_ok( false !== strpos( $verdict['body'], '1.16.1' ), 'and the verdict names the stale version' );
ghld_ok( false !== strpos( $verdict['body'], '1.17.1' ), 'alongside the current one' );

$verdict = GHLD_Diagnostics::verdict(
	array(
		'cached' => array( 'error' => 'Could not resolve host' ),
		'fresh'  => array( 'error' => 'Could not resolve host' ),
	)
);
ghld_same( 'error', $verdict['state'], 'a request that fails is reported as a failed check, not a healthy page' );

$verdict = GHLD_Diagnostics::verdict(
	array(
		'cached' => array( 'status' => 200, 'marker' => array(), 'cards' => 0 ),
		'fresh'  => array( 'status' => 200, 'marker' => array(), 'cards' => 0 ),
	)
);
ghld_same( 'error', $verdict['state'], 'a page with no stamp at all is the wrong page, and says so' );

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
