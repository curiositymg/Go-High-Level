<?php
/**
 * One contact's own page.
 *
 * Replaces the grid when the URL names a contact. Reuses contact-detail.php for
 * the body, so a change to what a contact shows lands in both the page and the
 * modal.
 *
 * Override by copying this file to your-theme/gohighlevel-integration/contact-profile.php.
 *
 * @package GoHighLevel_Integration
 * @var array $data contact, scope, back.
 */

defined( 'ABSPATH' ) || exit;

$ghld_contact = $data['contact'];
$ghld_scope   = $data['scope'];

// The page heading carries the same "Name, Title" line as the card.
$ghld_heading = GHLD_Shortcode::display_name( $ghld_contact, $ghld_scope );
?>
<div class="ghld-directory ghld-profile-wrap" data-ghld-profile<?php echo GHLD_Shortcode::color_scheme_attr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute or nothing. ?>>
	<p class="ghld-profile-back">
		<a href="<?php echo esc_url( $data['back'] ); ?>" class="btn-blue">
			<span aria-hidden="true">&larr;</span>
			<?php esc_html_e( 'See Full Directory', 'gohighlevel-integration' ); ?>
		</a>
	</p>

	<?php
	$ghld_sidebar = GHLD_Settings::profile_sidebar();
	?>
	<div class="ghld-profile-columns<?php echo ( '' === trim( $ghld_sidebar ) ) ? '' : ' has-sidebar'; ?>">
		<article class="ghld-profile">
			<?php
			GHLD_Template::render(
				'contact-detail',
				array(
					'contact' => $ghld_contact,
					'heading' => $ghld_heading,
					'scope'   => array_merge(
						$ghld_scope,
						// A page has room for everything the modal shows.
						array( 'modal_show' => isset( $ghld_scope['modal_show'] ) ? $ghld_scope['modal_show'] : $ghld_scope['show'] )
					),
				)
			);
			?>
		</article>

		<?php if ( '' !== trim( $ghld_sidebar ) ) : ?>
			<aside class="ghld-profile-sidebar">
				<?php
				// Author-supplied markup, filtered on save by the same rule
				// WordPress uses for its own Custom HTML block. Placeholders
				// are filled and shortcodes run, with the contact made
				// available so a shortcode can address this physician.
				GHLD_Shortcode::set_current_contact( $ghld_contact );
				echo do_shortcode( GHLD_Shortcode::fill_placeholders( $ghld_sidebar, $ghld_contact ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				GHLD_Shortcode::set_current_contact( null );
				?>
			</aside>
		<?php endif; ?>
	</div>
</div>
