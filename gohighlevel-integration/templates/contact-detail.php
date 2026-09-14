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

/**
 * One labelled row.
 *
 * @param string $label Row label.
 * @param string $value Already-escaped value markup.
 * @return void
 */
$ghld_row = static function ( $label, $value ) {
	printf(
		'<div class="ghld-detail-row"><dt class="ghld-detail-label">%1$s</dt><dd class="ghld-detail-value">%2$s</dd></div>',
		esc_html( $label ),
		$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers escape.
	);
};

$ghld_place = array_filter(
	array(
		$ghld_contact['city'],
		trim( $ghld_contact['state'] . ' ' . $ghld_contact['postal_code'] ),
	)
);
?>
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
		<?php if ( $ghld_showing( 'title' ) && '' === GHLD_Shortcode::inline_title( $ghld_contact, $data['scope'] ) && '' !== $ghld_contact['title'] ) : ?>
			<p class="ghld-detail-title"><?php echo esc_html( $ghld_contact['title'] ); ?></p>
		<?php endif; ?>

		<?php if ( $ghld_showing( 'company' ) && '' !== $ghld_contact['company'] ) : ?>
			<p class="ghld-detail-company"><?php echo esc_html( $ghld_contact['company'] ); ?></p>
		<?php endif; ?>

		<?php if ( $ghld_showing( 'bio' ) && '' !== $ghld_contact['bio'] ) : ?>
			<p class="ghld-detail-bio"><?php echo esc_html( $ghld_contact['bio'] ); ?></p>
		<?php endif; ?>

		<dl class="ghld-detail-rows">
			<?php
			if ( $ghld_showing( 'address' ) || $ghld_showing( 'location' ) ) {
				$ghld_lines = array();
				if ( $ghld_showing( 'address' ) && '' !== $ghld_contact['address'] ) {
					$ghld_lines[] = $ghld_contact['address'];
				}
				if ( ! empty( $ghld_place ) ) {
					$ghld_lines[] = implode( ', ', $ghld_place );
				}
				if ( $ghld_showing( 'address' ) && '' !== $ghld_contact['country'] ) {
					$ghld_lines[] = $ghld_contact['country'];
				}
				if ( ! empty( $ghld_lines ) ) {
					$ghld_row( __( 'Location', 'gohighlevel-integration' ), nl2br( esc_html( implode( "\n", $ghld_lines ) ) ) );
				}
			}

			if ( $ghld_showing( 'phone' ) && '' !== $ghld_contact['phone'] ) {
				$ghld_row(
					__( 'Phone', 'gohighlevel-integration' ),
					sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $ghld_contact['phone'] ) ),
						esc_html( $ghld_contact['phone'] )
					)
				);
			}

			if ( $ghld_showing( 'email' ) && '' !== $ghld_contact['email'] ) {
				$ghld_row(
					__( 'Email', 'gohighlevel-integration' ),
					sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( 'mailto:' . $ghld_contact['email'] ),
						esc_html( $ghld_contact['email'] )
					)
				);
			}

			if ( $ghld_showing( 'website' ) && '' !== $ghld_contact['website'] ) {
				$ghld_row(
					__( 'Website', 'gohighlevel-integration' ),
					sprintf(
						'<a href="%1$s" rel="nofollow noopener" target="_blank">%2$s</a>',
						esc_url( $ghld_contact['website'] ),
						esc_html( preg_replace( '#^https?://#', '', $ghld_contact['website'] ) )
					)
				);
			}

			foreach ( $ghld_show as $ghld_key ) {
				if ( 0 !== strpos( $ghld_key, 'cf:' ) ) {
					continue;
				}
				$ghld_field_key = substr( $ghld_key, 3 );
				$ghld_value     = isset( $ghld_contact['custom'][ $ghld_field_key ] ) ? $ghld_contact['custom'][ $ghld_field_key ] : '';
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

				$ghld_row( $ghld_label, esc_html( $ghld_value ) );
			}
			?>
		</dl>

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
