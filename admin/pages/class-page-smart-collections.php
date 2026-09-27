<?php
/**
 * Smart Collections admin page.
 *
 * Three tabs in the order the work happens: find the categories that are already
 * intent-shaped, map the ones you agree with, then decide what gets published.
 *
 * Every form posts to itself with a nonce and a capability check. Nothing is AJAX,
 * because every save here changes what the site publishes and that is a moment
 * worth a full reload.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Smart_Collections {

	const NONCE_SETTINGS = 'twtaeo_collection_settings';
	const NONCE_DRAFT    = 'twtaeo_collection_draft';
	const NONCE_BUILD    = 'twtaeo_collection_build';
	const NONCE_UNDO     = 'twtaeo_collection_undo';

	/**
	 * AJAX: draft a collection for a query. Returns a proposal only — nothing is
	 * written, and nothing can be written from here. Creation is a separate POST
	 * the merchant makes after reading what came back.
	 */
	public static function ajax_draft() {
		check_ajax_referer( self::NONCE_DRAFT, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'twt-aeo-ultimate' ) ), 403 );
		}

		$query     = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$archetype = isset( $_POST['archetype'] ) ? sanitize_key( wp_unslash( $_POST['archetype'] ) ) : '';

		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => __( 'No query given.', 'twt-aeo-ultimate' ) ) );
		}

		$draft = TWTAEO_Smart_Collections::ai_draft( $query, $archetype );
		if ( is_wp_error( $draft ) ) {
			wp_send_json_error( array( 'message' => $draft->get_error_message() ) );
		}

		wp_send_json_success( $draft );
	}

	private static function tabs() {
		return array(
			'collections' => __( 'Collections', 'twt-aeo-ultimate' ),
			'discover'    => __( 'Discover', 'twt-aeo-ultimate' ),
			'settings'    => __( 'Settings', 'twt-aeo-ultimate' ),
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Saves run before any output so each tab renders what was just stored.
		$notice = self::handle_post();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'collections';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'collections';
		}
		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'WooCommerce', 'twt-aeo-ultimate' ); ?>
					</h1>
				</div>
			</div>

			<?php TWTAEO_Page_WooCommerce_Detector::render_commerce_tabs( 'collections' ); ?>
			<h2 style="margin-top:0;"><?php esc_html_e( 'Smart Collections', 'twt-aeo-ultimate' ); ?></h2>
			<p style="max-width:820px;color:#50575e;">
				<?php esc_html_e( 'A catalogue answers "what do you sell". It does not answer "what should a beginner buy", which is the question a shopper asks two steps before they know what they want. This turns the categories you already built — Starter Kits, Pro Setups, Under $500 — into published Collections: a group of products, a written explanation of who it is for, and structured data an AI assistant can quote. It creates no pages and no categories of its own.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php self::render_status_banner(); ?>
			<?php
			// The sniff is EscapeOutput, not EscapingOutput — the old annotation named a
			// rule that does not exist, so it silenced nothing and the error stayed.
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup assembled in notice() from esc_html'd parts.
			?>

			<h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-collections&tab=' . $slug ) ); ?>"
						class="nav-tab <?php echo ( $tab === $slug ) ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $tab ) {
				case 'discover':
					self::render_discover_tab();
					break;
				case 'settings':
					self::render_settings_tab();
					break;
				default:
					self::render_collections_tab();
			}
			?>
		</div>
		<?php
	}

	// ── POST handling ─────────────────────────────────────────────────────────

	/**
	 * Dispatch whichever form was submitted.
	 *
	 * @return string Rendered notice markup, or ''.
	 */
	private static function handle_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		if ( 'POST' !== $method || ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		// Save or update one collection.
		if ( isset( $_POST['twtaeo_collection_save'] ) ) {
			check_admin_referer( TWTAEO_Smart_Collections::NONCE_SAVE );

			$raw = array(
				'id'           => isset( $_POST['collection_id'] ) ? sanitize_key( wp_unslash( $_POST['collection_id'] ) ) : '',
				'title'        => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
				'archetype'    => isset( $_POST['archetype'] ) ? sanitize_key( wp_unslash( $_POST['archetype'] ) ) : '',
				'custom_terms' => isset( $_POST['custom_terms'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_terms'] ) ) : '',
				'source'       => isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'category',
				'term_id'      => isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0,
				'target_query' => isset( $_POST['target_query'] ) ? sanitize_text_field( wp_unslash( $_POST['target_query'] ) ) : '',
				'summary'      => isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '',
				'active'       => isset( $_POST['active'] ) ? 1 : 0,
			);

			$result = TWTAEO_Smart_Collections::save( $raw );
			if ( is_wp_error( $result ) ) {
				return self::notice( 'error', $result->get_error_message() );
			}
			return self::notice( 'success', __( 'Collection saved.', 'twt-aeo-ultimate' ) );
		}

		// Delete.
		if ( isset( $_POST['twtaeo_collection_delete'] ) ) {
			check_admin_referer( TWTAEO_Smart_Collections::NONCE_DELETE );
			$id = isset( $_POST['collection_id'] ) ? sanitize_key( wp_unslash( $_POST['collection_id'] ) ) : '';
			if ( TWTAEO_Smart_Collections::delete( $id ) ) {
				return self::notice( 'success', __( 'Collection deleted. The category itself is untouched.', 'twt-aeo-ultimate' ) );
			}
			return self::notice( 'error', __( 'That collection no longer exists.', 'twt-aeo-ultimate' ) );
		}

		// Write a summary — computed from the catalogue, or phrased by the AI.
		if ( isset( $_POST['twtaeo_collection_summary'] ) ) {
			check_admin_referer( TWTAEO_Smart_Collections::NONCE_SUMMARY );

			$id         = isset( $_POST['collection_id'] ) ? sanitize_key( wp_unslash( $_POST['collection_id'] ) ) : '';
			$collection = TWTAEO_Smart_Collections::get( $id );
			if ( ! $collection ) {
				return self::notice( 'error', __( 'That collection no longer exists.', 'twt-aeo-ultimate' ) );
			}

			$mode = isset( $_POST['summary_mode'] ) ? sanitize_key( wp_unslash( $_POST['summary_mode'] ) ) : 'computed';

			if ( 'ai' === $mode ) {
				$text = TWTAEO_Smart_Collections::ai_summary( $collection );
				if ( is_wp_error( $text ) ) {
					return self::notice( 'error', $text->get_error_message() );
				}
				TWTAEO_Smart_Collections::set_summary( $id, $text, 'ai' );
				return self::notice( 'success', __( 'Summary written by AI. Read it before you publish — you own what it says.', 'twt-aeo-ultimate' ) );
			}

			$text = TWTAEO_Smart_Collections::computed_summary( $collection );
			if ( '' === $text ) {
				return self::notice( 'error', __( 'This collection resolves to no products, so there is nothing to describe yet.', 'twt-aeo-ultimate' ) );
			}
			TWTAEO_Smart_Collections::set_summary( $id, $text, 'computed' );
			return self::notice( 'success', __( 'Summary built from the catalogue. Every figure in it is read from your products.', 'twt-aeo-ultimate' ) );
		}

		// Build a category + collection from a reviewed draft.
		if ( isset( $_POST['twtaeo_collection_build'] ) ) {
			check_admin_referer( self::NONCE_BUILD );

			$product_ids = isset( $_POST['build_products'] )
				? array_map( 'absint', (array) wp_unslash( $_POST['build_products'] ) )
				: array();

			$result = TWTAEO_Smart_Collections::create_from_query(
				array(
					'title'        => isset( $_POST['build_title'] ) ? sanitize_text_field( wp_unslash( $_POST['build_title'] ) ) : '',
					'summary'      => isset( $_POST['build_summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['build_summary'] ) ) : '',
					'archetype'    => isset( $_POST['build_archetype'] ) ? sanitize_key( wp_unslash( $_POST['build_archetype'] ) ) : '',
					'target_query' => isset( $_POST['build_query'] ) ? sanitize_text_field( wp_unslash( $_POST['build_query'] ) ) : '',
					'product_ids'  => $product_ids,
					'evidence'     => array(
						'query'       => isset( $_POST['build_query'] ) ? sanitize_text_field( wp_unslash( $_POST['build_query'] ) ) : '',
						'impressions' => isset( $_POST['build_impressions'] ) ? (int) $_POST['build_impressions'] : 0,
						'clicks'      => isset( $_POST['build_clicks'] ) ? (int) $_POST['build_clicks'] : 0,
						'source'      => 'search-console',
					),
				)
			);

			if ( is_wp_error( $result ) ) {
				return self::notice( 'error', $result->get_error_message() );
			}

			return self::notice(
				'success',
				sprintf(
					/* translators: 1: collection title, 2: number of products. */
					__( 'Created the "%1$s" category and added %2$d products to it. Nothing was removed from any category they were already in. The collection is saved but NOT live — review it, then tick "Publish this collection".', 'twt-aeo-ultimate' ),
					$result['title'],
					count( $result['assigned_products'] )
				)
			);
		}

		// Undo a generated collection — delete the category we made.
		if ( isset( $_POST['twtaeo_collection_undo'] ) ) {
			check_admin_referer( self::NONCE_UNDO );

			$id     = isset( $_POST['collection_id'] ) ? sanitize_key( wp_unslash( $_POST['collection_id'] ) ) : '';
			$result = TWTAEO_Smart_Collections::delete_with_term( $id );

			if ( is_wp_error( $result ) ) {
				return self::notice( 'error', $result->get_error_message() );
			}

			return self::notice(
				'success',
				sprintf(
					/* translators: %d: number of products detached. */
					__( 'Category deleted and %d products detached from it. Their other categories are untouched.', 'twt-aeo-ultimate' ),
					(int) $result['detached']
				)
			);
		}

		// Settings.
		if ( isset( $_POST['twtaeo_collection_settings'] ) ) {
			check_admin_referer( self::NONCE_SETTINGS );

			TWTAEO_Smart_Collections::save_settings(
				array(
					'publish'      => isset( $_POST['publish'] ) ? 1 : 0,
					'takeover'     => isset( $_POST['takeover'] ) ? 1 : 0,
					'llms_txt'     => isset( $_POST['llms_txt'] ) ? 1 : 0,
					'exclude_oos'  => isset( $_POST['exclude_oos'] ) ? 1 : 0,
					'ai_summaries' => isset( $_POST['ai_summaries'] ) ? 1 : 0,
					'ai_daily_cap' => isset( $_POST['ai_daily_cap'] ) ? (int) $_POST['ai_daily_cap'] : 20,
				)
			);
			return self::notice( 'success', __( 'Settings saved.', 'twt-aeo-ultimate' ) );
		}

		return '';
	}

	private static function notice( $type, $message ) {
		$class = ( 'error' === $type ) ? 'notice-error' : 'notice-success';
		return '<div class="notice ' . esc_attr( $class ) . '" style="margin:12px 0;"><p>' . esc_html( $message ) . '</p></div>';
	}

	// ── Banners ───────────────────────────────────────────────────────────────

	/**
	 * State of play at the top of every tab: whether anything is being published,
	 * and who else is writing the node we want.
	 */
	private static function render_status_banner() {
		$settings = TWTAEO_Smart_Collections::settings();

		if ( empty( $settings['publish'] ) ) {
			?>
			<div class="notice notice-info inline" style="margin:12px 0;">
				<p>
					<strong><?php esc_html_e( 'Nothing is being published yet.', 'twt-aeo-ultimate' ); ?></strong>
					<?php esc_html_e( 'Map your collections first, check they resolve to the right products, then switch publishing on under Settings. Turning this module on does not by itself change a single tag on your site.', 'twt-aeo-ultimate' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-collections&tab=settings' ) ); ?>">
						<?php esc_html_e( 'Open Settings', 'twt-aeo-ultimate' ); ?> &rarr;
					</a>
				</p>
			</div>
			<?php
			return;
		}

		$owners = TWTAEO_Collection_Schema_Writer::competing_owners();
		if ( empty( $owners ) ) {
			return;
		}

		$names = wp_list_pluck( $owners, 'label' );
		$stuck = wp_list_pluck( TWTAEO_Collection_Schema_Writer::unsuppressable_owners(), 'label' );
		?>
		<div class="notice <?php echo empty( $settings['takeover'] ) ? 'notice-warning' : 'notice-info'; ?> inline" style="margin:12px 0;">
			<p>
				<strong><?php esc_html_e( 'Something else already publishes collection schema here:', 'twt-aeo-ultimate' ); ?></strong>
				<?php echo esc_html( implode( ', ', $names ) ); ?>.
			</p>
			<?php if ( empty( $settings['takeover'] ) ) : ?>
				<p>
					<?php esc_html_e( 'Right now both are writing on your category archives, and two CollectionPage nodes on one page is worse than either alone. Either switch take-over on under Settings, or turn that plugin\'s collection schema off — but pick one.', 'twt-aeo-ultimate' ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Take-over is on, so their CollectionPage and ItemList are suppressed on the archives you have mapped. Everywhere else is still theirs, untouched.', 'twt-aeo-ultimate' ); ?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $stuck ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Needs switching off by hand:', 'twt-aeo-ultimate' ); ?></strong>
					<?php echo esc_html( implode( ', ', $stuck ) ); ?> —
					<?php esc_html_e( 'this one offers no reliable way for us to suppress its archive schema, so turn that feature off in its own settings.', 'twt-aeo-ultimate' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Tab: Collections ──────────────────────────────────────────────────────

	private static function render_collections_tab() {
		$collections = TWTAEO_Smart_Collections::get_all();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only edit target.
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill from the Discover tab.
		$prefill_term = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill from the Discover tab.
		$prefill_archetype = isset( $_GET['archetype'] ) ? sanitize_key( wp_unslash( $_GET['archetype'] ) ) : '';

		$editing = $edit_id ? TWTAEO_Smart_Collections::get( $edit_id ) : null;

		if ( ! empty( $collections ) ) {
			?>
			<table class="wp-list-table widefat striped" style="margin-bottom:24px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Collection', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Intent', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Publishes on', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Products', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Summary', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Live', 'twt-aeo-ultimate' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$archetypes = TWTAEO_Smart_Collections::archetypes();
				foreach ( $collections as $c ) :
					$facts = TWTAEO_Smart_Collections::resolve_items( $c );
					?>
					<tr>
						<td><strong><?php echo esc_html( $c['title'] ); ?></strong></td>
						<td><?php echo esc_html( $archetypes[ $c['archetype'] ]['label'] ?? $c['archetype'] ); ?></td>
						<td>
							<?php
							if ( 'category' === $c['source'] && $c['term_id'] ) {
								$term = get_term( (int) $c['term_id'], 'product_cat' );
								if ( $term && ! is_wp_error( $term ) ) {
									$link = get_term_link( $term );
									echo is_wp_error( $link )
										? esc_html( $term->name )
										: '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $term->name ) . '</a>';
								} else {
									echo '<em>' . esc_html__( 'category deleted', 'twt-aeo-ultimate' ) . '</em>';
								}
							} else {
								echo '<code>[' . esc_html( TWTAEO_Collection_Schema_Writer::SHORTCODE ) . ' id="' . esc_attr( $c['id'] ) . '"]</code>';
							}
							?>
						</td>
						<td>
							<?php
							echo esc_html( (string) $facts['count'] );
							if ( $facts['count'] && $facts['in_stock'] < $facts['count'] ) {
								echo ' <span style="color:#d97706;">('
									. esc_html(
										sprintf(
											/* translators: %d: number of in-stock products. */
											__( '%d in stock', 'twt-aeo-ultimate' ),
											$facts['in_stock']
										)
									)
									. ')</span>';
							}
							?>
						</td>
						<td>
							<?php
							if ( '' !== trim( (string) $c['summary'] ) ) {
								$labels = array(
									'manual'   => __( 'written by you', 'twt-aeo-ultimate' ),
									'ai'       => __( 'written by AI', 'twt-aeo-ultimate' ),
									'computed' => __( 'from the catalogue', 'twt-aeo-ultimate' ),
								);
								echo esc_html( $labels[ $c['summary_src'] ] ?? __( 'set', 'twt-aeo-ultimate' ) );
							} else {
								echo '<span style="color:#50575e;">' . esc_html__( 'auto (from the catalogue)', 'twt-aeo-ultimate' ) . '</span>';
							}
							?>
						</td>
						<td>
							<?php
							// Never report liveness from $c['active'] alone. That flag is one
							// of several gates and a green tick beside a store publishing
							// nothing is the worst kind of wrong — it reads as confirmation.
							$status = TWTAEO_Smart_Collections::publish_status( $c, $facts );
							?>
							<?php if ( $status['live'] ) : ?>
								<span style="color:#16a34a;font-weight:600;">&#10003; <?php echo esc_html( $status['label'] ); ?></span>
								<?php if ( '' !== $status['caveat'] ) : ?>
									<br><span class="description" style="color:#d97706;"><?php echo esc_html( $status['caveat'] ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span style="color:#8c8f94;font-weight:600;">&mdash; <?php echo esc_html( $status['label'] ); ?></span>
								<br><span class="description"><?php echo esc_html( $status['detail'] ); ?></span>
								<?php if ( '' !== $status['fix_url'] ) : ?>
									<br><a href="<?php echo esc_url( $status['fix_url'] ); ?>"><?php echo esc_html( $status['fix_label'] ); ?></a>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td style="white-space:nowrap;">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-collections&edit=' . rawurlencode( $c['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?></a>
							&nbsp;
							<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this collection? Your category and products are not touched.', 'twt-aeo-ultimate' ) ); ?>');">
								<?php wp_nonce_field( TWTAEO_Smart_Collections::NONCE_DELETE ); ?>
								<input type="hidden" name="collection_id" value="<?php echo esc_attr( $c['id'] ); ?>">
								<button type="submit" name="twtaeo_collection_delete" value="1" class="button-link delete" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'twt-aeo-ultimate' ); ?></button>
							</form>
							<?php if ( ! empty( $c['created_term'] ) ) : ?>
								&nbsp;
								<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete the collection AND the category this plugin created for it? Products added by that step are detached; every other category they belong to is untouched.', 'twt-aeo-ultimate' ) ); ?>');">
									<?php wp_nonce_field( self::NONCE_UNDO ); ?>
									<input type="hidden" name="collection_id" value="<?php echo esc_attr( $c['id'] ); ?>">
									<button type="submit" name="twtaeo_collection_undo" value="1" class="button-link delete" style="color:#b32d2e;"
										title="<?php esc_attr_e( 'This plugin created this category, so it can remove it again.', 'twt-aeo-ultimate' ); ?>">
										<?php esc_html_e( 'Undo build', 'twt-aeo-ultimate' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		self::render_edit_form( $editing, $prefill_term, $prefill_archetype );

		if ( $editing ) {
			self::render_summary_box( $editing );
		}
	}

	private static function render_edit_form( $editing, $prefill_term = 0, $prefill_archetype = '' ) {
		$defaults = TWTAEO_Smart_Collections::record_defaults();
		$c        = $editing ? $editing : $defaults;

		if ( ! $editing ) {
			if ( $prefill_term ) {
				$c['term_id'] = $prefill_term;
				$term         = get_term( $prefill_term, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$c['title'] = $term->name;
				}
			}
			if ( $prefill_archetype ) {
				$c['archetype'] = $prefill_archetype;
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		?>
		<h2><?php echo $editing ? esc_html__( 'Edit collection', 'twt-aeo-ultimate' ) : esc_html__( 'Add a collection', 'twt-aeo-ultimate' ); ?></h2>

		<form method="post">
			<?php wp_nonce_field( TWTAEO_Smart_Collections::NONCE_SAVE ); ?>
			<input type="hidden" name="collection_id" value="<?php echo esc_attr( $c['id'] ); ?>">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="twt-aeo-col-title"><?php esc_html_e( 'Title', 'twt-aeo-ultimate' ); ?></label></th>
					<td>
						<input type="text" id="twt-aeo-col-title" name="title" class="regular-text" value="<?php echo esc_attr( $c['title'] ); ?>" required>
						<p class="description"><?php esc_html_e( 'Becomes the name of the CollectionPage. Write it the way a shopper would describe what they want — "Photography Starter Kit", not "Category 12".', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="twt-aeo-col-archetype"><?php esc_html_e( 'Intent archetype', 'twt-aeo-ultimate' ); ?></label></th>
					<td>
						<select id="twt-aeo-col-archetype" name="archetype">
							<?php foreach ( TWTAEO_Smart_Collections::archetypes_grouped() as $group => $options ) : ?>
								<optgroup label="<?php echo esc_attr( $group ); ?>">
									<?php foreach ( $options as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $c['archetype'], $key ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'What kind of shopper this group answers. It shapes the written summary and drives which of your categories get suggested on the Discover tab.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="twt-aeo-col-terms"><?php esc_html_e( 'Your own intent phrases', 'twt-aeo-ultimate' ); ?></label></th>
					<td>
						<input type="text" id="twt-aeo-col-terms" name="custom_terms" class="large-text" value="<?php echo esc_attr( $c['custom_terms'] ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated, e.g. Starter Kit, Pro Setup, Heavy Duty. Required if you picked "Other"; optional otherwise, where they are added to the preset\'s own phrases.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Where it publishes', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="source" value="category" <?php checked( $c['source'], 'category' ); ?>>
							<?php esc_html_e( 'On an existing product category archive', 'twt-aeo-ultimate' ); ?>
						</label>
						<select name="term_id" style="margin:0 0 8px 24px;">
							<option value="0"><?php esc_html_e( '— choose a category —', 'twt-aeo-ultimate' ); ?></option>
							<?php if ( ! is_wp_error( $terms ) ) : ?>
								<?php foreach ( $terms as $term ) : ?>
									<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( (int) $c['term_id'], (int) $term->term_id ); ?>>
										<?php echo esc_html( $term->name . ' (' . $term->count . ')' ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>

						<label style="display:block;">
							<input type="radio" name="source" value="shortcode" <?php checked( $c['source'], 'shortcode' ); ?>>
							<?php esc_html_e( 'Anywhere you place the shortcode — no new URLs, no taxonomy changes', 'twt-aeo-ultimate' ); ?>
						</label>
						<p class="description" style="margin-left:24px;">
							<?php if ( $c['id'] ) : ?>
								<code>[<?php echo esc_html( TWTAEO_Collection_Schema_Writer::SHORTCODE ); ?> id="<?php echo esc_attr( $c['id'] ); ?>"]</code>
							<?php else : ?>
								<?php esc_html_e( 'Save first — the shortcode contains the collection\'s ID.', 'twt-aeo-ultimate' ); ?>
							<?php endif; ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'This module never creates a page or a category. If you want a collection that has no home yet, make the page yourself and drop the shortcode in — auto-generating landing pages from search queries is the doorway-page pattern search engines penalise.', 'twt-aeo-ultimate' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="twt-aeo-col-query"><?php esc_html_e( 'Question it answers', 'twt-aeo-ultimate' ); ?></label></th>
					<td>
						<input type="text" id="twt-aeo-col-query" name="target_query" class="large-text" value="<?php echo esc_attr( $c['target_query'] ); ?>" placeholder="<?php esc_attr_e( 'what should a beginner photographer buy', 'twt-aeo-ultimate' ); ?>">
						<p class="description"><?php esc_html_e( 'Optional, and never published as-is. It steers the wording of the summary so the text reads as an answer rather than a product list.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="twt-aeo-col-summary"><?php esc_html_e( 'Summary', 'twt-aeo-ultimate' ); ?></label></th>
					<td>
						<textarea id="twt-aeo-col-summary" name="summary" rows="4" class="large-text"><?php echo esc_textarea( $c['summary'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Leave blank and one is built from your catalogue automatically — product count, price range and stock, all read live. Anything you type here wins and is never overwritten.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Publishing', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="active" value="1" <?php checked( ! empty( $c['active'] ) ); ?>>
							<?php esc_html_e( 'Publish this collection', 'twt-aeo-ultimate' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Also requires publishing to be switched on under Settings. Both gates, deliberately — one to arm the module, one per collection.', 'twt-aeo-ultimate' ); ?></p>
						<?php
						// Ticking this box is not the same as being live, so while editing a
						// saved collection say where it actually stands rather than leaving
						// the merchant to infer it from the checkbox.
						if ( $editing ) :
							$twtaeo_status = TWTAEO_Smart_Collections::publish_status( $c );
							?>
							<p class="description">
								<strong>
								<?php if ( $twtaeo_status['live'] ) : ?>
									<span style="color:#16a34a;">&#10003; <?php echo esc_html( $twtaeo_status['label'] ); ?></span>
								<?php else : ?>
									<span style="color:#8c8f94;">&mdash; <?php echo esc_html( $twtaeo_status['label'] ); ?></span>
								<?php endif; ?>
								</strong>
								<?php
								echo esc_html(
									( $twtaeo_status['live'] && '' !== $twtaeo_status['caveat'] )
										? $twtaeo_status['caveat']
										: $twtaeo_status['detail']
								);
								?>
								<?php if ( ! $twtaeo_status['live'] && '' !== $twtaeo_status['fix_url'] ) : ?>
									<a href="<?php echo esc_url( $twtaeo_status['fix_url'] ); ?>"><?php echo esc_html( $twtaeo_status['fix_label'] ); ?></a>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" name="twtaeo_collection_save" value="1" class="button button-primary">
					<?php echo $editing ? esc_html__( 'Save collection', 'twt-aeo-ultimate' ) : esc_html__( 'Add collection', 'twt-aeo-ultimate' ); ?>
				</button>
				<?php if ( $editing ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-collections' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'twt-aeo-ultimate' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/** Summary generation, shown only while editing an existing collection. */
	private static function render_summary_box( array $c ) {
		$settings = TWTAEO_Smart_Collections::settings();
		$preview  = TWTAEO_Smart_Collections::computed_summary( $c );
		?>
		<hr>
		<h2><?php esc_html_e( 'Write the summary', 'twt-aeo-ultimate' ); ?></h2>
		<p style="max-width:820px;color:#50575e;">
			<?php esc_html_e( 'This is the sentence an assistant quotes when someone asks what to buy. Built from the catalogue it contains only facts your products actually state; written by AI it reads better and still gets handed nothing but those same facts.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php if ( '' !== $preview ) : ?>
			<p style="background:#f6f7f7;border-left:4px solid #7f54b3;padding:10px 12px;max-width:820px;">
				<?php echo esc_html( $preview ); ?>
			</p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'This collection resolves to no products yet, so there is nothing to summarise.', 'twt-aeo-ultimate' ); ?></em></p>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( TWTAEO_Smart_Collections::NONCE_SUMMARY ); ?>
			<input type="hidden" name="collection_id" value="<?php echo esc_attr( $c['id'] ); ?>">
			<input type="hidden" name="summary_mode" value="computed">
			<p>
				<button type="submit" name="twtaeo_collection_summary" value="1" class="button">
					<?php esc_html_e( 'Use the catalogue summary', 'twt-aeo-ultimate' ); ?>
				</button>
			</p>
		</form>

		<form method="post">
			<?php wp_nonce_field( TWTAEO_Smart_Collections::NONCE_SUMMARY ); ?>
			<input type="hidden" name="collection_id" value="<?php echo esc_attr( $c['id'] ); ?>">
			<input type="hidden" name="summary_mode" value="ai">
			<p>
				<button type="submit" name="twtaeo_collection_summary" value="1" class="button" <?php disabled( empty( $settings['ai_summaries'] ) ); ?>>
					<?php esc_html_e( 'Write it with AI', 'twt-aeo-ultimate' ); ?>
				</button>
				<?php if ( empty( $settings['ai_summaries'] ) ) : ?>
					<span style="color:#50575e;margin-left:8px;">
						<?php esc_html_e( 'Switch AI summaries on under Settings first — it spends your API budget.', 'twt-aeo-ultimate' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-collections&tab=settings' ) ); ?>">
							<?php esc_html_e( 'Open Settings', 'twt-aeo-ultimate' ); ?> &rarr;
						</a>
					</span>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	// ── Tab: Discover ─────────────────────────────────────────────────────────

	private static function render_discover_tab() {
		$candidates = TWTAEO_Smart_Collections::category_candidates();
		$queries    = TWTAEO_Smart_Collections::query_candidates( 25 );
		?>
		<h2><?php esc_html_e( 'Categories that already look like collections', 'twt-aeo-ultimate' ); ?></h2>
		<p style="max-width:820px;color:#50575e;">
			<?php esc_html_e( 'Your category names are read against the intent archetypes. A category called "Cameras" is a catalogue division and is not listed; one called "Starter Kits" is an answer to a question, and that is what belongs here. Nothing below has been changed — these are suggestions.', 'twt-aeo-ultimate' ); ?>
		</p>

		<?php if ( empty( $candidates ) ) : ?>
			<p><em><?php esc_html_e( 'No intent-shaped categories found. That is a normal result for a catalogue organised by product type — map a collection by hand, or use the shortcode on a page you have already written.', 'twt-aeo-ultimate' ); ?></em></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped" style="margin-bottom:28px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Products', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Reads as', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Because of', 'twt-aeo-ultimate' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $candidates as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
						<td><?php echo esc_html( (string) $row['count'] ); ?></td>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td><code><?php echo esc_html( implode( ', ', $row['matched'] ) ); ?></code></td>
						<td>
							<a class="button button-small"
								href="<?php echo esc_url( admin_url( 'admin.php?page=twt-aeo-collections&term_id=' . (int) $row['term_id'] . '&archetype=' . rawurlencode( $row['archetype'] ) ) ); ?>">
								<?php esc_html_e( 'Map it', 'twt-aeo-ultimate' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Intent queries you already rank for', 'twt-aeo-ultimate' ); ?></h2>
		<?php if ( empty( $queries ) ) : ?>
			<p style="max-width:820px;color:#50575e;">
				<?php esc_html_e( 'Nothing to show. This reads your connected Search Console property for queries carrying intent words — "for beginners", "complete", "commercial", "under". Connect Google under Settings to populate it. An empty list here means no data, not no opportunity.', 'twt-aeo-ultimate' ); ?>
			</p>
		<?php else : ?>
			<p style="max-width:820px;color:#50575e;">
				<?php esc_html_e( 'Real queries from the last 90 days that carry an intent modifier. Each one is a collection somebody is already looking for.', 'twt-aeo-ultimate' ); ?>
			</p>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Query', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Impressions', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Reads as', 'twt-aeo-ultimate' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $queries as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['query'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td>
							<button type="button"
								class="button button-small twt-aeo-build-trigger"
								data-query="<?php echo esc_attr( $row['query'] ); ?>"
								data-archetype="<?php echo esc_attr( $row['archetype'] ); ?>"
								data-impressions="<?php echo esc_attr( (string) $row['impressions'] ); ?>"
								data-clicks="<?php echo esc_attr( (string) $row['clicks'] ); ?>">
								<?php esc_html_e( 'Build one', 'twt-aeo-ultimate' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="max-width:820px;color:#50575e;margin-top:14px;">
				<?php esc_html_e( '"Build one" is for a query where none of your existing categories is a good answer. It drafts a grouping from products you actually stock, you edit it, and only then is anything created. There is no way to build them all at once, on purpose — one at a time, each one reviewed, is what separates a useful landing page from the kind of bulk-generated page search engines penalise.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php self::render_build_modal(); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * The build modal.
	 *
	 * Rendered empty and populated over AJAX. The AJAX round-trip writes nothing —
	 * it returns a proposal. The form inside posts normally, because creating a
	 * category changes the site and that is a full-reload moment.
	 */
	private static function render_build_modal() {
		?>
		<div id="twt-aeo-build-modal" class="twt-aeo-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="twt-aeo-build-heading">
			<div class="twt-aeo-modal__panel">
				<h2 id="twt-aeo-build-heading"><?php esc_html_e( 'Build a collection', 'twt-aeo-ultimate' ); ?></h2>

				<p class="twt-aeo-modal__query">
					<?php esc_html_e( 'For the search:', 'twt-aeo-ultimate' ); ?>
					<strong><span id="twt-aeo-build-querytext"></span></strong>
				</p>

				<div id="twt-aeo-build-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>
					<?php esc_html_e( 'Reading your catalogue and drafting a grouping…', 'twt-aeo-ultimate' ); ?>
				</div>

				<div id="twt-aeo-build-error" class="notice notice-error inline" style="display:none;"><p></p></div>

				<form method="post" id="twt-aeo-build-form" style="display:none;">
					<?php wp_nonce_field( self::NONCE_BUILD ); ?>
					<input type="hidden" name="build_query" id="twt-aeo-build-query">
					<input type="hidden" name="build_archetype" id="twt-aeo-build-archetype">
					<input type="hidden" name="build_impressions" id="twt-aeo-build-impressions">
					<input type="hidden" name="build_clicks" id="twt-aeo-build-clicks">

					<p>
						<label for="twt-aeo-build-title"><strong><?php esc_html_e( 'Category name', 'twt-aeo-ultimate' ); ?></strong></label><br>
						<input type="text" name="build_title" id="twt-aeo-build-title" class="large-text" required>
						<span class="description"><?php esc_html_e( 'This becomes a real product category with a live URL. Edit it before you create it.', 'twt-aeo-ultimate' ); ?></span>
					</p>

					<p>
						<label for="twt-aeo-build-summary"><strong><?php esc_html_e( 'Summary', 'twt-aeo-ultimate' ); ?></strong></label><br>
						<textarea name="build_summary" id="twt-aeo-build-summary" rows="4" class="large-text"></textarea>
					</p>

					<p><strong><?php esc_html_e( 'Products', 'twt-aeo-ultimate' ); ?></strong><br>
						<span class="description"><?php esc_html_e( 'Ticked products are added to the new category. They keep every category they are already in — nothing is moved.', 'twt-aeo-ultimate' ); ?></span>
					</p>
					<div id="twt-aeo-build-products" class="twt-aeo-modal__products"></div>

					<div class="notice notice-warning inline" style="margin:14px 0;">
						<p>
							<?php esc_html_e( 'Creating this makes the category live immediately — it appears in your shop navigation and your sitemap. The collection itself is saved switched off, so no schema is published until you turn it on. If you change your mind, the collection list has an Undo that deletes the category and detaches only the products added here.', 'twt-aeo-ultimate' ); ?>
						</p>
					</div>

					<p>
						<button type="submit" name="twtaeo_collection_build" value="1" class="button button-primary">
							<?php esc_html_e( 'Create category & collection', 'twt-aeo-ultimate' ); ?>
						</button>
						<button type="button" class="button twt-aeo-modal__close"><?php esc_html_e( 'Cancel', 'twt-aeo-ultimate' ); ?></button>
					</p>
				</form>

				<p id="twt-aeo-build-dismiss" style="display:none;">
					<button type="button" class="button twt-aeo-modal__close"><?php esc_html_e( 'Close', 'twt-aeo-ultimate' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	// ── Tab: Settings ─────────────────────────────────────────────────────────

	private static function render_settings_tab() {
		$s      = TWTAEO_Smart_Collections::settings();
		$owners = TWTAEO_Collection_Schema_Writer::competing_owners();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_SETTINGS ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish collections', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="publish" value="1" <?php checked( ! empty( $s['publish'] ) ); ?>>
							<?php esc_html_e( 'Publish CollectionPage and ItemList schema for my live collections', 'twt-aeo-ultimate' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off until you say so. Activating the module arms it; this switch is what changes your site\'s output.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Take over from other plugins', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="takeover" value="1" <?php checked( ! empty( $s['takeover'] ) ); ?>>
							<?php esc_html_e( 'Suppress other plugins\' collection schema on the categories I have mapped', 'twt-aeo-ultimate' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Only on mapped categories — every other archive stays theirs. Leave this off and both plugins write a CollectionPage on the same page, which is worse than either one alone.', 'twt-aeo-ultimate' ); ?>
						</p>
						<?php if ( ! empty( $owners ) ) : ?>
							<p class="description">
								<strong><?php esc_html_e( 'Detected:', 'twt-aeo-ultimate' ); ?></strong>
								<?php echo esc_html( implode( ', ', wp_list_pluck( $owners, 'label' ) ) ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'llms.txt', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="llms_txt" value="1" <?php checked( ! empty( $s['llms_txt'] ) ); ?>>
							<?php esc_html_e( 'List collections and their summaries in /llms.txt', 'twt-aeo-ultimate' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Category-mapped collections only — a shortcode collection has no single URL this file can point at.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Out-of-stock products', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="exclude_oos" value="1" <?php checked( ! empty( $s['exclude_oos'] ) ); ?>>
							<?php esc_html_e( 'Leave out-of-stock products out of collections', 'twt-aeo-ultimate' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Availability is worked out from each product\'s variations, not its parent record — a variable product routinely reports itself out of stock while every variation is available.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'AI summaries', 'twt-aeo-ultimate' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ai_summaries" value="1" <?php checked( ! empty( $s['ai_summaries'] ) ); ?>>
							<?php esc_html_e( 'Let me use AI to phrase collection summaries', 'twt-aeo-ultimate' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Spends your API budget, one collection at a time, only when you click the button. Never runs during an import, a cron job or a bulk edit.', 'twt-aeo-ultimate' ); ?></p>
						<p>
							<label>
								<?php esc_html_e( 'Daily cap', 'twt-aeo-ultimate' ); ?>
								<input type="number" name="ai_daily_cap" min="0" max="200" value="<?php echo esc_attr( (string) $s['ai_daily_cap'] ); ?>" class="small-text">
							</label>
						</p>
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" name="twtaeo_collection_settings" value="1" class="button button-primary">
					<?php esc_html_e( 'Save settings', 'twt-aeo-ultimate' ); ?>
				</button>
			</p>
		</form>
		<?php
	}
}
