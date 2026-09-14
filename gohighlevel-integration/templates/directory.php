<?php
/**
 * Directory wrapper.
 *
 * Override by copying this file to your-theme/gohighlevel-integration/directory.php.
 *
 * @package GoHighLevel_Integration
 * @var array $data scope, request, instance, results, pagination, facets, total, summary, dom_id.
 */

defined( 'ABSPATH' ) || exit;

$ghld_scope = $data['scope'];
?>
<div
	id="<?php echo esc_attr( $data['dom_id'] ); ?>"
	class="ghld-directory ghld-layout-<?php echo esc_attr( $ghld_scope['layout'] ); ?>"
	data-ghld-instance="<?php echo esc_attr( $data['instance'] ); ?>"
	style="--ghld-columns:<?php echo esc_attr( (string) $ghld_scope['columns'] ); ?>"
>
	<?php if ( ! empty( $ghld_scope['filters'] ) ) : ?>
		<?php
		GHLD_Template::render(
			'filter-bar',
			array(
				'scope'   => $ghld_scope,
				'request' => $data['request'],
				'facets'  => $data['facets'],
			)
		);
		?>
	<?php endif; ?>

	<p class="ghld-summary" data-ghld-summary aria-live="polite"><?php echo esc_html( $data['summary'] ); ?></p>

	<div class="ghld-results" data-ghld-results aria-busy="false">
		<?php echo $data['results']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in results.php. ?>
	</div>

	<div class="ghld-pagination-wrap" data-ghld-pagination>
		<?php echo $data['pagination']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pagination.php. ?>
	</div>

	<?php if ( ! empty( $ghld_scope['modal'] ) ) : ?>
		<div class="ghld-modal" data-ghld-modal hidden>
			<div class="ghld-modal-backdrop" data-ghld-modal-close></div>
			<div
				class="ghld-modal-dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $data['dom_id'] ); ?>-modal-title"
			>
				<button
					type="button"
					class="ghld-modal-close"
					data-ghld-modal-close
					aria-label="<?php esc_attr_e( 'Close', 'gohighlevel-integration' ); ?>"
				>&times;</button>
				<h2 class="ghld-modal-title" id="<?php echo esc_attr( $data['dom_id'] ); ?>-modal-title"></h2>
				<div class="ghld-modal-body" data-ghld-modal-body></div>
			</div>
		</div>
	<?php endif; ?>
</div>
