<?php
/**
 * Contact grid.
 *
 * Override by copying this file to your-theme/gohighlevel-integration/results.php.
 *
 * @package GoHighLevel_Integration
 * @var array $data items, scope, total.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $data['items'] ) ) {
	printf( '<p class="ghld-empty">%s</p>', esc_html( $data['scope']['empty'] ) );

	return;
}
?>
<ul class="ghld-grid" role="list">
	<?php foreach ( $data['items'] as $ghld_contact ) : ?>
		<li class="ghld-grid-item">
			<?php
			GHLD_Template::render(
				'contact-card',
				array(
					'contact' => $ghld_contact,
					'scope'   => $data['scope'],
					'request' => isset( $data['request'] ) ? $data['request'] : array(),
				)
			);
			?>
		</li>
	<?php endforeach; ?>
</ul>
