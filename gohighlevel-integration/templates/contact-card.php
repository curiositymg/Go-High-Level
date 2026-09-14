<?php
/**
 * One contact card.
 *
 * Override by copying this file to your-theme/gohighlevel-integration/contact-card.php.
 *
 * @package GoHighLevel_Integration
 * @var array $data contact, scope.
 */

defined( 'ABSPATH' ) || exit;

$ghld_contact = $data['contact'];
$ghld_show    = (array) $data['scope']['show'];
$ghld_modal   = ! empty( $data['scope']['modal'] );

// When the directory prints "Name, Title", the title rides on the name line
// instead of taking a line of its own.
$ghld_inline_title = GHLD_Shortcode::inline_title( $ghld_contact, $data['scope'] );
$ghld_display_name = GHLD_Shortcode::display_name( $ghld_contact, $data['scope'] );

/**
 * Whether a card element is enabled.
 *
 * @param string $key Element key.
 * @return bool
 */
$ghld_showing = static function ( $key ) use ( $ghld_show ) {
	return in_array( $key, $ghld_show, true );
};
?>
<article
	class="ghld-card<?php echo $ghld_modal ? ' ghld-card-clickable' : ''; ?>"
	data-ghld-contact="<?php echo esc_attr( $ghld_contact['id'] ); ?>"
	data-ghld-name="<?php echo esc_attr( $ghld_display_name ); ?>"
>
	<?php if ( $ghld_showing( 'photo' ) ) : ?>
		<div class="ghld-card-media">
			<?php if ( '' !== $ghld_contact['photo'] ) : ?>
				<img
					class="ghld-avatar"
					src="<?php echo esc_url( $ghld_contact['photo'] ); ?>"
					alt="<?php echo esc_attr( $ghld_contact['name'] ); ?>"
					loading="lazy"
					decoding="async"
					width="160"
					height="160"
				/>
			<?php else : ?>
				<span class="ghld-avatar ghld-avatar-initials" aria-hidden="true"><?php echo esc_html( $ghld_contact['initials'] ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="ghld-card-body">
		<h3 class="ghld-name">
			<?php if ( $ghld_modal ) : ?>
				<button type="button" class="ghld-name-button" data-ghld-open>
					<?php echo esc_html( $ghld_contact['name'] ); ?><?php
					if ( '' !== $ghld_inline_title ) :
						?><span class="ghld-name-title">, <?php echo esc_html( $ghld_inline_title ); ?></span><?php
					endif;
					?>
				</button>
			<?php else : ?>
				<?php echo esc_html( $ghld_contact['name'] ); ?><?php
				if ( '' !== $ghld_inline_title ) :
					?><span class="ghld-name-title">, <?php echo esc_html( $ghld_inline_title ); ?></span><?php
				endif;
				?>
			<?php endif; ?>
		</h3>

		<?php if ( $ghld_showing( 'title' ) && '' === $ghld_inline_title && '' !== $ghld_contact['title'] ) : ?>
			<p class="ghld-title"><?php echo esc_html( $ghld_contact['title'] ); ?></p>
		<?php endif; ?>

		<?php if ( $ghld_showing( 'company' ) && '' !== $ghld_contact['company'] ) : ?>
			<p class="ghld-company"><?php echo esc_html( $ghld_contact['company'] ); ?></p>
		<?php endif; ?>

		<?php
		if ( $ghld_showing( 'location' ) ) {
			$ghld_location = array_filter( array( $ghld_contact['city'], $ghld_contact['state'] ) );
			if ( ! empty( $ghld_location ) ) {
				printf( '<p class="ghld-location">%s</p>', esc_html( implode( ', ', $ghld_location ) ) );
			}
		}
		?>

		<?php if ( $ghld_showing( 'address' ) && '' !== $ghld_contact['address'] ) : ?>
			<p class="ghld-address"><?php echo esc_html( $ghld_contact['address'] ); ?></p>
		<?php endif; ?>

		<?php if ( $ghld_showing( 'bio' ) && '' !== $ghld_contact['bio'] ) : ?>
			<p class="ghld-bio"><?php echo esc_html( $ghld_contact['bio'] ); ?></p>
		<?php endif; ?>

		<?php
		foreach ( $ghld_show as $ghld_key ) {
			if ( 0 !== strpos( $ghld_key, 'cf:' ) ) {
				continue;
			}
			$ghld_field_key = substr( $ghld_key, 3 );
			$ghld_value     = isset( $ghld_contact['custom'][ $ghld_field_key ] ) ? $ghld_contact['custom'][ $ghld_field_key ] : '';
			if ( '' === $ghld_value ) {
				continue;
			}
			printf(
				'<p class="ghld-custom ghld-custom-%1$s">%2$s</p>',
				esc_attr( $ghld_field_key ),
				esc_html( $ghld_value )
			);
		}
		?>

		<?php
		// A tag the whole directory is scoped to is true of every card, so it
		// is not worth a pill on each one.
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

		<?php
		$ghld_links = array();
		if ( $ghld_showing( 'email' ) && '' !== $ghld_contact['email'] ) {
			$ghld_links[] = sprintf(
				'<a class="ghld-link ghld-link-email" href="%1$s">%2$s</a>',
				esc_url( 'mailto:' . $ghld_contact['email'] ),
				esc_html( $ghld_contact['email'] )
			);
		}
		if ( $ghld_showing( 'phone' ) && '' !== $ghld_contact['phone'] ) {
			$ghld_links[] = sprintf(
				'<a class="ghld-link ghld-link-phone" href="%1$s">%2$s</a>',
				esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $ghld_contact['phone'] ) ),
				esc_html( $ghld_contact['phone'] )
			);
		}
		if ( $ghld_showing( 'website' ) && '' !== $ghld_contact['website'] ) {
			$ghld_links[] = sprintf(
				'<a class="ghld-link ghld-link-website" href="%1$s" rel="nofollow noopener" target="_blank">%2$s</a>',
				esc_url( $ghld_contact['website'] ),
				esc_html( preg_replace( '#^https?://#', '', $ghld_contact['website'] ) )
			);
		}

		if ( ! empty( $ghld_links ) ) {
			printf(
				'<p class="ghld-contact-links">%s</p>',
				implode( ' ', $ghld_links ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is escaped above.
			);
		}
		?>
	</div>

	<?php if ( $ghld_modal ) : ?>
		<div class="ghld-card-detail" data-ghld-detail hidden>
			<?php
			GHLD_Template::render(
				'contact-detail',
				array(
					'contact' => $ghld_contact,
					'scope'   => $data['scope'],
				)
			);
			?>
		</div>
	<?php endif; ?>
</article>
