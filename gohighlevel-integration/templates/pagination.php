<?php
/**
 * Pagination.
 *
 * Links carry the whole filter state so the no-JS fallback keeps working;
 * the front-end script intercepts clicks when JavaScript is available.
 *
 * Override by copying this file to your-theme/gohighlevel-integration/pagination.php.
 *
 * @package GoHighLevel_Integration
 * @var array $data page, pages, request, scope.
 */

defined( 'ABSPATH' ) || exit;

$ghld_page  = (int) $data['page'];
$ghld_pages = (int) $data['pages'];

if ( $ghld_pages < 2 ) {
	return;
}

$ghld_base = GHLD_Shortcode::request_to_query( $data['request'] );

/**
 * Build a relative href for a page number.
 *
 * @param int $number Page number.
 * @return string
 */
$ghld_href = static function ( $number ) use ( $ghld_base ) {
	$query = $ghld_base;

	$query[ GHLD_Shortcode::QUERY_PREFIX . 'page' ] = (int) $number;

	return '?' . http_build_query( $query );
};

$ghld_window = array();
for ( $i = 1; $i <= $ghld_pages; $i++ ) {
	if ( $i <= 2 || $i > $ghld_pages - 2 || abs( $i - $ghld_page ) <= 1 ) {
		$ghld_window[] = $i;
	}
}
?>
<nav class="ghld-pagination" aria-label="<?php esc_attr_e( 'Directory pages', 'gohighlevel-integration' ); ?>">
	<?php if ( $ghld_page > 1 ) : ?>
		<a class="ghld-page ghld-page-prev" href="<?php echo esc_url( $ghld_href( $ghld_page - 1 ) ); ?>" data-ghld-page="<?php echo esc_attr( (string) ( $ghld_page - 1 ) ); ?>" rel="prev">
			<?php esc_html_e( 'Previous', 'gohighlevel-integration' ); ?>
		</a>
	<?php endif; ?>

	<?php
	$ghld_previous = 0;
	foreach ( $ghld_window as $ghld_number ) :
		if ( $ghld_previous && $ghld_number > $ghld_previous + 1 ) :
			?>
			<span class="ghld-page-gap" aria-hidden="true">…</span>
			<?php
		endif;
		$ghld_previous = $ghld_number;

		if ( $ghld_number === $ghld_page ) :
			?>
			<span class="ghld-page ghld-page-current" aria-current="page"><?php echo esc_html( number_format_i18n( $ghld_number ) ); ?></span>
			<?php
		else :
			?>
			<a class="ghld-page" href="<?php echo esc_url( $ghld_href( $ghld_number ) ); ?>" data-ghld-page="<?php echo esc_attr( (string) $ghld_number ); ?>">
				<?php echo esc_html( number_format_i18n( $ghld_number ) ); ?>
			</a>
			<?php
		endif;
	endforeach;
	?>

	<?php if ( $ghld_page < $ghld_pages ) : ?>
		<a class="ghld-page ghld-page-next" href="<?php echo esc_url( $ghld_href( $ghld_page + 1 ) ); ?>" data-ghld-page="<?php echo esc_attr( (string) ( $ghld_page + 1 ) ); ?>" rel="next">
			<?php esc_html_e( 'Next', 'gohighlevel-integration' ); ?>
		</a>
	<?php endif; ?>
</nav>
