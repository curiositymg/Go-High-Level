<?php
/**
 * The contact detail shown inside the modal.
 *
 * Rendered into each card (hidden) and cloned into the dialog on open, so the
 * modal needs no extra request and can only ever show a contact this directory
 * already lists.
 *
 * Override by copying this file to your-theme/gohighlevel-integration/contact-detail.php.
 *
 * @package GoHighLevel_Integration
 * @var array $data contact, scope.
 */

defined( 'ABSPATH' ) || exit;

$ghld_contact = $data['contact'];
$ghld_show    = isset( $data['scope']['modal_show'] ) ? (array) $data['scope']['modal_show'] : (array) $data['scope']['show'];

/**
 * Whether a detail element is enabled.
 *
 * @param string $key Element key.
 * @return bool
 */
$ghld_showing = static function ( $key ) use ( $ghld_show ) {
	return in_array( $key, $ghld_show, true );
};

$ghld_place = array_filter(
	array(
		$ghld_contact['city'],
		trim( $ghld_contact['state'] . ' ' . $ghld_contact['postal_code'] ),
	)
);
?>
<?php if ( $ghld_showing( 'specialty' ) && ! empty( $ghld_contact['specialty'] ) ) : ?>
	<p class="ghld-detail-specialty"><?php echo esc_html( $ghld_contact['specialty'] ); ?></p>
<?php endif; ?>

<?php if ( $ghld_showing( 'title' ) && '' === GHLD_Shortcode::inline_title( $ghld_contact, $data['scope'] ) && '' !== $ghld_contact['title'] ) : ?>
	<p class="ghld-detail-title"><?php echo esc_html( $ghld_contact['title'] ); ?></p>
<?php endif; ?>

<div class="ghld-detail">
	<?php if ( $ghld_showing( 'photo' ) ) : ?>
		<div class="ghld-detail-media">
			<?php if ( '' !== $ghld_contact['photo'] ) : ?>
				<img
					class="ghld-avatar ghld-avatar-large"
					src="<?php echo esc_url( $ghld_contact['photo'] ); ?>"
					alt="<?php echo esc_attr( $ghld_contact['name'] ); ?>"
					loading="lazy"
					decoding="async"
					width="200"
					height="200"
				/>
			<?php else : ?>
				<span class="ghld-avatar ghld-avatar-large ghld-avatar-initials" aria-hidden="true"><?php echo esc_html( $ghld_contact['initials'] ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="ghld-detail-body">
		<div class="ghld-detail-practice">
			<?php if ( $ghld_showing( 'company' ) && '' !== $ghld_contact['company'] ) : ?>
				<p class="ghld-detail-company"><?php echo esc_html( $ghld_contact['company'] ); ?></p>
			<?php endif; ?>

			<?php
			// Street address and "City, ST ZIP" read as one postal block.
			$ghld_lines = array();
			if ( $ghld_showing( 'address' ) && '' !== $ghld_contact['address'] ) {
				$ghld_lines[] = $ghld_contact['address'];
			}
			if ( ( $ghld_showing( 'address' ) || $ghld_showing( 'location' ) ) && ! empty( $ghld_place ) ) {
				$ghld_lines[] = implode( ', ', $ghld_place );
			}
			if ( ! empty( $ghld_lines ) ) {
				printf(
					'<p class="ghld-detail-address">%s</p>',
					nl2br( esc_html( implode( "\n", $ghld_lines ) ) )
				);
			}

			// "P." and "F." the way a practice listing prints them.
			if ( $ghld_showing( 'phone' ) && '' !== $ghld_contact['phone'] ) {
				printf(
					'<p class="ghld-detail-tel"><span class="ghld-tel-label">%1$s</span> <a href="%2$s">%3$s</a></p>',
					esc_html__( 'P.', 'gohighlevel-integration' ),
					esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $ghld_contact['phone'] ) ),
					esc_html( $ghld_contact['phone'] )
				);
			}

			if ( $ghld_showing( 'fax' ) && ! empty( $ghld_contact['fax'] ) ) {
				printf(
					'<p class="ghld-detail-tel"><span class="ghld-tel-label">%1$s</span> %2$s</p>',
					esc_html__( 'F.', 'gohighlevel-integration' ),
					esc_html( $ghld_contact['fax'] )
				);
			}

			if ( $ghld_showing( 'email' ) && '' !== $ghld_contact['email'] ) {
				printf(
					'<p class="ghld-detail-tel"><a href="%1$s">%2$s</a></p>',
					esc_url( 'mailto:' . $ghld_contact['email'] ),
					esc_html( $ghld_contact['email'] )
				);
			}

			if ( $ghld_showing( 'website' ) && '' !== $ghld_contact['website'] ) {
				printf(
					'<p class="ghld-detail-tel"><a href="%1$s" rel="nofollow noopener" target="_blank">%2$s</a></p>',
					esc_url( $ghld_contact['website'] ),
					esc_html( preg_replace( '#^https?://#', '', $ghld_contact['website'] ) )
				);
			}
			?>
		</div>

		<?php if ( $ghld_showing( 'bio' ) && '' !== $ghld_contact['bio'] ) : ?>
			<p class="ghld-detail-bio"><?php echo esc_html( $ghld_contact['bio'] ); ?></p>
		<?php endif; ?>

		<?php
		// Anything else the site ticked on, as labelled rows. Fields already
		// claimed by a mapping are printed above, not repeated here.
		$ghld_claimed = GHLD_Shortcode::mapped_custom_keys();
		$ghld_rows    = '';

		foreach ( $ghld_show as $ghld_key ) {
			if ( 0 !== strpos( $ghld_key, 'cf:' ) ) {
				continue;
			}
			$ghld_field_key = substr( $ghld_key, 3 );
			if ( in_array( $ghld_field_key, $ghld_claimed, true ) ) {
				continue;
			}
			$ghld_value = isset( $ghld_contact['custom'][ $ghld_field_key ] ) ? $ghld_contact['custom'][ $ghld_field_key ] : '';
			if ( '' === $ghld_value ) {
				continue;
			}

			$ghld_label = $ghld_field_key;
			foreach ( GHLD_Repository::custom_fields() as $ghld_field ) {
				if ( $ghld_field['key'] === $ghld_field_key ) {
					$ghld_label = $ghld_field['name'];
					break;
				}
			}

			$ghld_rows .= sprintf(
				'<div class="ghld-detail-row"><dt class="ghld-detail-label">%1$s</dt><dd class="ghld-detail-value">%2$s</dd></div>',
				esc_html( $ghld_label ),
				esc_html( $ghld_value )
			);
		}

		if ( '' !== $ghld_rows ) {
			printf( '<dl class="ghld-detail-rows">%s</dl>', $ghld_rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		?>

		<?php
		$ghld_hidden_tag = GHLD_Repository::scope_tag( $data['scope'] );
		$ghld_tags       = array();
		foreach ( $ghld_contact['tags'] as $ghld_tag ) {
			if ( '' === $ghld_hidden_tag || GHLD_Contact::lower( $ghld_tag ) !== $ghld_hidden_tag ) {
				$ghld_tags[] = $ghld_tag;
			}
		}
		?>
		<?php if ( $ghld_showing( 'tags' ) && ! empty( $ghld_tags ) ) : ?>
			<ul class="ghld-tags" role="list">
				<?php foreach ( $ghld_tags as $ghld_tag ) : ?>
					<li class="ghld-tag"><?php echo esc_html( $ghld_tag ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>
