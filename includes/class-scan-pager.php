<?php
/**
 * Scan-block pager shared by the detector screens.
 *
 * Every detector scans the catalogue in fixed-size blocks (scanning everything
 * in one page load times out on large sites), and several of them then FILTER
 * the block — a block of 200 posts may yield three pages with FAQ content. So
 * this pager pages over *scanned blocks*, not over result rows: each page of
 * the UI is one block of the catalogue, labelled by the range of items it
 * scanned. Without it, everything past the first block is invisible — and a
 * gap nobody can see is a gap nobody fixes.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Scan_Pager {

	/**
	 * Resolve the current 1-based block number from the `paged` query arg,
	 * clamped to the number of blocks the catalogue actually has.
	 *
	 * @param int $total_items Published items the detector can see in total.
	 * @param int $block_size  Items per scan block.
	 * @return int
	 */
	public static function current_block( $total_items, $block_size ) {
		$total_blocks = max( 1, (int) ceil( $total_items / max( 1, $block_size ) ) );
		$paged        = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		return max( 1, min( $total_blocks, $paged ) );
	}

	/**
	 * Render the pager: Prev / windowed block numbers / Next, plus a
	 * "Scanned items X–Y of Z" caption naming what this block covered.
	 *
	 * Prints nothing when the catalogue fits in one block.
	 *
	 * @param int    $block       Current 1-based block.
	 * @param int    $total_items Published items the detector can see in total.
	 * @param int    $block_size  Items per scan block.
	 * @param string $base_url    Page URL to hang the `paged` query arg on.
	 */
	public static function render( $block, $total_items, $block_size, $base_url ) {
		$total_blocks = max( 1, (int) ceil( $total_items / max( 1, $block_size ) ) );
		if ( $total_blocks <= 1 ) {
			return;
		}

		$first = ( $block - 1 ) * $block_size + 1;
		$last  = min( $block * $block_size, $total_items );
		?>
		<div class="twt-og-pagination">
			<?php if ( $block > 1 ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'paged', $block - 1, $base_url ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'twt-aeo-ultimate' ); ?></a>
			<?php endif; ?>
			<?php
			$window = array( 1, $total_blocks );
			for ( $p = $block - 2; $p <= $block + 2; $p++ ) {
				$window[] = $p;
			}
			$window = array_values( array_unique( array_filter( $window, static function ( $p ) use ( $total_blocks ) {
				return $p >= 1 && $p <= $total_blocks;
			} ) ) );
			sort( $window );
			$prev = 0;
			foreach ( $window as $p ) :
				if ( $p > $prev + 1 ) : ?>
					<span style="color:#8c8f94;">&hellip;</span>
				<?php endif;
				$prev = $p;
				if ( $p === $block ) : ?>
					<span class="current"><?php echo esc_html( $p ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'paged', $p, $base_url ) ); ?>"><?php echo esc_html( $p ); ?></a>
				<?php endif;
			endforeach; ?>
			<?php if ( $block < $total_blocks ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'paged', $block + 1, $base_url ) ); ?>"><?php esc_html_e( 'Next', 'twt-aeo-ultimate' ); ?> &raquo;</a>
			<?php endif; ?>
			<span style="font-size:12px;color:#666;margin-left:6px;">
				<?php
				// translators: %1$d: first item scanned in this block. %2$d: last item. %3$d: total published items.
				printf( esc_html__( 'Scanned items %1$d–%2$d of %3$d (newest first)', 'twt-aeo-ultimate' ), absint( $first ), absint( $last ), absint( $total_items ) ); ?>
			</span>
		</div>
		<?php
	}
}
