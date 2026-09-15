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
<div class="ghld-directory ghld-profile-wrap" data-ghld-profile>
	<p class="ghld-profile-back">
		<a href="<?php echo esc_url( $data['back'] ); ?>" class="ghld-back-link">
			<span aria-hidden="true">&larr;</span>
			<?php esc_html_e( 'Back to the directory', 'gohighlevel-integration' ); ?>
		</a>
	</p>

	<article class="ghld-profile">
		<h1 class="ghld-profile-name"><?php echo esc_html( $ghld_heading ); ?></h1>

		<?php
		GHLD_Template::render(
			'contact-detail',
			array(
				'contact' => $ghld_contact,
				'scope'   => array_merge(
					$ghld_scope,
					// A page has room for everything the modal shows.
					array( 'modal_show' => isset( $ghld_scope['modal_show'] ) ? $ghld_scope['modal_show'] : $ghld_scope['show'] )
				),
			)
		);
		?>
	</article>
</div>
