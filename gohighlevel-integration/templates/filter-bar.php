<?php
/**
 * Filter bar.
 *
 * Override by copying this file to your-theme/gohighlevel-integration/filter-bar.php.
 *
 * @package GoHighLevel_Integration
 * @var array $data scope, request, facets.
 */

defined( 'ABSPATH' ) || exit;

$ghld_scope   = $data['scope'];
$ghld_request = $data['request'];
$ghld_facets  = $data['facets'];
$ghld_filters = (array) $ghld_scope['filters'];
$ghld_prefix  = GHLD_Shortcode::QUERY_PREFIX;

/**
 * Render one <select> of facet values.
 *
 * @param string $name    Field name.
 * @param string $label   Visible label.
 * @param array  $values  value => count.
 * @param string $current Selected value.
 * @param string $any     "Any" option label.
 * @return void
 */
$ghld_select = static function ( $name, $label, array $values, $current, $any ) {
	if ( empty( $values ) ) {
		return;
	}
	$id = 'ghld-' . sanitize_html_class( $name );
	?>
	<div class="ghld-field">
		<label class="ghld-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<select class="ghld-select" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" data-ghld-control>
			<option value=""><?php echo esc_html( $any ); ?></option>
			<?php foreach ( $values as $value => $count ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( GHLD_Contact::lower( $current ), GHLD_Contact::lower( $value ) ); ?>>
					<?php
					printf(
						'%1$s (%2$s)',
						esc_html( $value ),
						esc_html( number_format_i18n( $count ) )
					);
					?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
};
?>
<form class="ghld-filter-bar" method="get" data-ghld-form role="search">
	<?php if ( in_array( 'search', $ghld_filters, true ) ) : ?>
		<div class="ghld-field ghld-field-search">
			<?php // Kept in the markup, hidden visually: the placeholder is not a label, and screen readers need one. ?>
			<label class="ghld-label ghld-label-hidden" for="<?php echo esc_attr( 'ghld-' . $ghld_prefix . 's' ); ?>"><?php esc_html_e( 'Search', 'gohighlevel-integration' ); ?></label>
			<input
				type="search"
				id="<?php echo esc_attr( 'ghld-' . $ghld_prefix . 's' ); ?>"
				class="ghld-input"
				name="<?php echo esc_attr( $ghld_prefix . 's' ); ?>"
				value="<?php echo esc_attr( $ghld_request['typed'] ); ?>"
				placeholder="<?php esc_attr_e( 'Search by name, company, tag…', 'gohighlevel-integration' ); ?>"
				data-ghld-control
				autocomplete="off"
			/>
		</div>
	<?php endif; ?>

	<?php
	if ( in_array( 'tag', $ghld_filters, true ) ) {
		$ghld_select( $ghld_prefix . 'tag', __( 'Tag', 'gohighlevel-integration' ), $ghld_facets['tag'], $ghld_request['tag'], __( 'All tags', 'gohighlevel-integration' ) );
	}
	if ( in_array( 'city', $ghld_filters, true ) ) {
		$ghld_select( $ghld_prefix . 'city', __( 'City', 'gohighlevel-integration' ), $ghld_facets['city'], $ghld_request['city'], __( 'All cities', 'gohighlevel-integration' ) );
	}
	if ( in_array( 'state', $ghld_filters, true ) ) {
		$ghld_select( $ghld_prefix . 'state', __( 'State', 'gohighlevel-integration' ), $ghld_facets['state'], $ghld_request['state'], __( 'All states', 'gohighlevel-integration' ) );
	}
	if ( in_array( 'company', $ghld_filters, true ) ) {
		$ghld_select( $ghld_prefix . 'company', __( 'Company', 'gohighlevel-integration' ), $ghld_facets['company'], $ghld_request['company'], __( 'All companies', 'gohighlevel-integration' ) );
	}

	foreach ( $ghld_filters as $ghld_filter ) {
		if ( 0 !== strpos( $ghld_filter, 'cf:' ) ) {
			continue;
		}
		$ghld_key = substr( $ghld_filter, 3 );
		if ( empty( $ghld_facets['custom'][ $ghld_key ] ) ) {
			continue;
		}
		$ghld_label = $ghld_key;
		foreach ( GHLD_Repository::custom_fields() as $ghld_field ) {
			if ( $ghld_field['key'] === $ghld_key ) {
				$ghld_label = $ghld_field['name'];
				break;
			}
		}
		$ghld_select(
			$ghld_prefix . 'cf_' . $ghld_key,
			$ghld_label,
			$ghld_facets['custom'][ $ghld_key ],
			isset( $ghld_request['custom'][ $ghld_key ] ) ? $ghld_request['custom'][ $ghld_key ] : '',
			/* translators: %s: custom field name. */
			sprintf( __( 'All: %s', 'gohighlevel-integration' ), $ghld_label )
		);
	}
	?>

	<?php if ( in_array( 'sort', $ghld_filters, true ) ) : ?>
		<div class="ghld-field">
			<label class="ghld-label" for="<?php echo esc_attr( 'ghld-' . $ghld_prefix . 'sort' ); ?>"><?php esc_html_e( 'Sort by', 'gohighlevel-integration' ); ?></label>
			<select class="ghld-select" id="<?php echo esc_attr( 'ghld-' . $ghld_prefix . 'sort' ); ?>" name="<?php echo esc_attr( $ghld_prefix . 'sort' ); ?>" data-ghld-control>
				<?php
				$ghld_current = $ghld_request['orderby'] . '-' . $ghld_request['order'];
				$ghld_options = array(
					'name-asc'        => __( 'Name (A–Z)', 'gohighlevel-integration' ),
					'name-desc'       => __( 'Name (Z–A)', 'gohighlevel-integration' ),
					'company-asc'     => __( 'Company (A–Z)', 'gohighlevel-integration' ),
					'date_added-desc' => __( 'Newest first', 'gohighlevel-integration' ),
					'date_added-asc'  => __( 'Oldest first', 'gohighlevel-integration' ),
				);
				foreach ( $ghld_options as $ghld_value => $ghld_option_label ) :
					?>
					<option value="<?php echo esc_attr( $ghld_value ); ?>" <?php selected( $ghld_current, $ghld_value ); ?>><?php echo esc_html( $ghld_option_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	<?php endif; ?>

	<div class="ghld-field ghld-field-actions">
		<button type="submit" class="ghld-button ghld-submit"><?php esc_html_e( 'Filter', 'gohighlevel-integration' ); ?></button>
		<?php
		// No ghld-button class here on purpose: the Clear button takes the
		// theme's own button styling via `blue-button`. `.ghld-reset` stays as
		// this plugin's styling hook.
		?>
		<button type="reset" class="ghld-reset blue-button" data-ghld-reset><?php esc_html_e( 'Clear', 'gohighlevel-integration' ); ?></button>
	</div>
</form>
