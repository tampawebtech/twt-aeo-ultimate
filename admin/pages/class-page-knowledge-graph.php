<?php
/**
 * Admin Page: Knowledge Graph.
 *
 * The central control surface for the Knowledge Graph module — defined here in
 * the admin area, never in per-post metaboxes. Three tabs:
 *
 *   Entities       Define the people, organizations, places, and topics that
 *                  make up your knowledge graph, each with its authority sameAs
 *                  links (Wikipedia / Wikidata) — groundable with one AI click.
 *   Relationships  Link entities to one another (knows / founder /
 *                  parentOrganization / memberOf, …).
 *   Page Mapping   Map a URL to the entities it is `about` and `mentions`, plus
 *                  a topic that gets grounded to Wikidata sameAs.
 *
 * All three feed TWTAEO_KG_Entities, which TWTAEO_Knowledge_Graph folds into the
 * single unified page @graph.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Knowledge_Graph {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'twt-aeo-ultimate' ) );
		}

		$notice = self::handle_post();

		$tab        = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = in_array( $tab, array( 'entities', 'relationships', 'mapping' ), true ) ? $tab : 'entities';
		$base_url   = admin_url( 'admin.php?page=twt-aeo-knowledge-graph' );

		// The unified graph is always on now; the old "turn the module on" notice
		// no longer applies.
		$module_on = true;

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Knowledge Graph', 'twt-aeo-ultimate' ); ?>
					</h1>
				</div>
			</div>

			<p class="description" style="max-width:820px;margin-top:8px;">
				<?php esc_html_e( 'Define your entities once, centrally, and this module weaves them into the single unified @graph on every page — cross-referenced by @id, with about/mentions declarations, entity relationships, and Wikidata-grounded topics. No per-post metaboxes.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php if ( ! $module_on ) : ?>
			<div class="notice notice-warning" style="margin-top:12px;">
				<p>
					<?php
					printf(
						/* translators: %s: link to the Modules page. */
						wp_kses_post( __( 'The <strong>Knowledge Graph</strong> module is currently off — nothing here is emitted until you enable it on the <a href="%s">Modules</a> page.', 'twt-aeo-ultimate' ) ),
						esc_url( admin_url( 'admin.php?page=twt-aeo-modules' ) )
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Knowledge Graph saved.', 'twt-aeo-ultimate' ); ?></p></div>
			<?php endif; ?>

			<nav class="twt-aeo-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'entities', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo 'entities' === $active_tab ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-networking"></span>
					<?php esc_html_e( 'Entities', 'twt-aeo-ultimate' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'relationships', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo 'relationships' === $active_tab ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-admin-links"></span>
					<?php esc_html_e( 'Relationships', 'twt-aeo-ultimate' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'mapping', $base_url ) ); ?>"
				   class="twt-aeo-tab <?php echo 'mapping' === $active_tab ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-admin-page"></span>
					<?php esc_html_e( 'Page Mapping', 'twt-aeo-ultimate' ); ?>
				</a>
			</nav>

			<?php
			if ( 'entities' === $active_tab ) {
				self::render_entities_tab();
			} elseif ( 'relationships' === $active_tab ) {
				self::render_relationships_tab();
			} else {
				self::render_mapping_tab();
			}
			?>

		</div><!-- .twt-aeo-wrap -->
		<?php

		self::render_script();
	}

	/**
	 * Process a POSTed tab form. Returns 'saved' or ''.
	 */
	public static function handle_post(): string {
		if ( ! isset( $_POST['twtaeo_kg_save'] ) ) {
			return '';
		}
		check_admin_referer( TWTAEO_KG_Entities::NONCE_SAVE );
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$which = sanitize_key( wp_unslash( $_POST['twtaeo_kg_save'] ) );

		// Slashes are stripped inside the sanitizers (wp_unslash per field).
		if ( 'entities' === $which ) {
			TWTAEO_KG_Entities::save_entities( $_POST['entities'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( 'relationships' === $which ) {
			TWTAEO_KG_Entities::save_relationships( $_POST['relationships'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( 'mapping' === $which ) {
			TWTAEO_KG_Entities::save_mappings( $_POST['mappings'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		return 'saved';
	}

	// ── Entities tab ─────────────────────────────────────────────────────────────

	private static function render_entities_tab() {
		$entities = TWTAEO_KG_Entities::get_entities();
		?>
		<form method="post">
			<?php wp_nonce_field( TWTAEO_KG_Entities::NONCE_SAVE ); ?>

			<div class="twt-aeo-card" style="padding:20px;margin-top:16px;">
				<p class="description" style="margin:0 0 14px;">
					<?php esc_html_e( 'Each entity becomes a node in your @graph, referenceable by @id. Add authority sameAs links (official site, Wikipedia, Wikidata) so AI systems can ground the entity — or let AI find them for you.', 'twt-aeo-ultimate' ); ?>
				</p>

				<div id="twt-kg-entities-rows">
					<?php
					if ( empty( $entities ) ) {
						echo self::entity_row_html( 0, array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping internally.
					} else {
						foreach ( array_values( $entities ) as $i => $e ) {
							echo self::entity_row_html( $i, $e ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping internally.
						}
					}
					?>
				</div>

				<p style="margin-top:12px;">
					<button type="button" class="button twt-kg-add" data-target="twt-kg-entities-rows" data-template="twt-kg-entity-tpl">
						<span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom;"></span>
						<?php esc_html_e( 'Add Entity', 'twt-aeo-ultimate' ); ?>
					</button>
				</p>
			</div>

			<p style="margin-top:16px;">
				<button type="submit" name="twtaeo_kg_save" value="entities" class="button button-primary">
					<?php esc_html_e( 'Save Entities', 'twt-aeo-ultimate' ); ?>
				</button>
			</p>
		</form>

		<template id="twt-kg-entity-tpl"><?php echo self::entity_row_html( '__INDEX__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></template>
		<?php
	}

	/**
	 * Render one entity repeater row.
	 *
	 * @param int|string $i    Row index (numeric, or '__INDEX__' for the template).
	 * @param array      $e    Entity data.
	 * @return string HTML.
	 */
	private static function entity_row_html( $i, array $e ) {
		$types    = TWTAEO_KG_Entities::entity_types();
		$id       = $e['id'] ?? '';
		$type     = $e['type'] ?? 'Organization';
		$name     = $e['name'] ?? '';
		$url      = $e['url'] ?? '';
		$desc     = $e['description'] ?? '';
		$same_as  = ! empty( $e['same_as'] ) ? implode( "\n", (array) $e['same_as'] ) : '';
		$field    = 'entities[' . $i . ']';

		ob_start();
		?>
		<div class="twt-kg-row" style="border:1px solid #dcdcde;border-radius:6px;padding:16px;margin-bottom:12px;background:#fff;">
			<input type="hidden" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />
			<div style="display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end;">
				<label style="display:block;">
					<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Name', 'twt-aeo-ultimate' ); ?></span>
					<input type="text" class="regular-text twt-kg-entity-name" style="width:100%;" name="<?php echo esc_attr( $field ); ?>[name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Acme Corporation', 'twt-aeo-ultimate' ); ?>" />
				</label>
				<label style="display:block;">
					<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Type', 'twt-aeo-ultimate' ); ?></span>
					<select class="twt-kg-entity-type" style="width:100%;" name="<?php echo esc_attr( $field ); ?>[type]">
						<?php foreach ( $types as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button-link twt-kg-remove" title="<?php esc_attr_e( 'Remove entity', 'twt-aeo-ultimate' ); ?>" style="color:#b32d2e;padding-bottom:6px;">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</div>

			<div style="margin-top:10px;">
				<label style="display:block;">
					<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'URL (optional)', 'twt-aeo-ultimate' ); ?></span>
					<input type="url" style="width:100%;" name="<?php echo esc_attr( $field ); ?>[url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://example.com" />
				</label>
			</div>

			<div style="margin-top:10px;">
				<label style="display:block;">
					<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Description (optional)', 'twt-aeo-ultimate' ); ?></span>
					<textarea rows="2" style="width:100%;" name="<?php echo esc_attr( $field ); ?>[description]" placeholder="<?php esc_attr_e( 'A short factual description of this entity.', 'twt-aeo-ultimate' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
				</label>
			</div>

			<div style="margin-top:10px;">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
					<span style="font-weight:600;font-size:12px;"><?php esc_html_e( 'sameAs — authority links (one URL per line)', 'twt-aeo-ultimate' ); ?></span>
					<span>
						<button type="button" class="button button-small twt-kg-ground" data-type-from="type">
							<span class="dashicons dashicons-superhero" style="vertical-align:text-bottom;font-size:15px;"></span>
							<?php esc_html_e( 'Ground with AI', 'twt-aeo-ultimate' ); ?>
						</button>
						<span class="twt-kg-ground-spinner" style="display:none;"><span class="spinner is-active" style="float:none;margin:0;"></span></span>
					</span>
				</div>
				<textarea rows="3" class="twt-kg-sameas" style="width:100%;font-family:monospace;font-size:12px;" name="<?php echo esc_attr( $field ); ?>[same_as]" placeholder="https://en.wikipedia.org/wiki/Acme&#10;https://www.wikidata.org/wiki/Q..."><?php echo esc_textarea( $same_as ); ?></textarea>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Relationships tab ────────────────────────────────────────────────────────

	private static function render_relationships_tab() {
		$rels    = TWTAEO_KG_Entities::get_relationships();
		$options = TWTAEO_KG_Entities::entity_options();
		?>
		<form method="post">
			<?php wp_nonce_field( TWTAEO_KG_Entities::NONCE_SAVE ); ?>

			<div class="twt-aeo-card" style="padding:20px;margin-top:16px;">
				<?php if ( empty( $options ) ) : ?>
					<p class="twt-aeo-empty"><?php esc_html_e( 'Define some entities first, then come back to link them.', 'twt-aeo-ultimate' ); ?></p>
				<?php else : ?>
					<p class="description" style="margin:0 0 14px;">
						<?php esc_html_e( 'Link one entity to another. Each relationship is emitted on the subject\'s node as an @id reference (e.g. Person knows Person, Organization founder Person).', 'twt-aeo-ultimate' ); ?>
					</p>

					<div id="twt-kg-rel-rows">
						<?php
						if ( empty( $rels ) ) {
							echo self::relationship_row_html( 0, array() ); // phpcs:ignore WordPress.Security.EscapeOutput
						} else {
							foreach ( array_values( $rels ) as $i => $r ) {
								echo self::relationship_row_html( $i, $r ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
						}
						?>
					</div>

					<p style="margin-top:12px;">
						<button type="button" class="button twt-kg-add" data-target="twt-kg-rel-rows" data-template="twt-kg-rel-tpl">
							<span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom;"></span>
							<?php esc_html_e( 'Add Relationship', 'twt-aeo-ultimate' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $options ) ) : ?>
			<p style="margin-top:16px;">
				<button type="submit" name="twtaeo_kg_save" value="relationships" class="button button-primary">
					<?php esc_html_e( 'Save Relationships', 'twt-aeo-ultimate' ); ?>
				</button>
			</p>
			<?php endif; ?>
		</form>

		<?php if ( ! empty( $options ) ) : ?>
		<template id="twt-kg-rel-tpl"><?php echo self::relationship_row_html( '__INDEX__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></template>
		<?php endif;
	}

	/** Render one relationship repeater row. */
	private static function relationship_row_html( $i, array $r ) {
		$options    = TWTAEO_KG_Entities::entity_options();
		$predicates = TWTAEO_KG_Entities::predicates();
		$subject    = $r['subject'] ?? '';
		$predicate  = $r['predicate'] ?? '';
		$object     = $r['object'] ?? '';
		$field      = 'relationships[' . $i . ']';

		ob_start();
		?>
		<div class="twt-kg-row" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:center;border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:10px;background:#fff;">
			<select name="<?php echo esc_attr( $field ); ?>[subject]" style="width:100%;">
				<option value=""><?php esc_html_e( '— Subject —', 'twt-aeo-ultimate' ); ?></option>
				<?php foreach ( $options as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $subject, $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="<?php echo esc_attr( $field ); ?>[predicate]" style="width:100%;">
				<?php foreach ( $predicates as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $predicate, $val ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="<?php echo esc_attr( $field ); ?>[object]" style="width:100%;">
				<option value=""><?php esc_html_e( '— Object —', 'twt-aeo-ultimate' ); ?></option>
				<?php foreach ( $options as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $object, $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button-link twt-kg-remove" title="<?php esc_attr_e( 'Remove', 'twt-aeo-ultimate' ); ?>" style="color:#b32d2e;">
				<span class="dashicons dashicons-trash"></span>
			</button>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Page Mapping tab ─────────────────────────────────────────────────────────

	private static function render_mapping_tab() {
		$mappings = TWTAEO_KG_Entities::get_mappings();
		$options  = TWTAEO_KG_Entities::entity_options();
		?>
		<form method="post">
			<?php wp_nonce_field( TWTAEO_KG_Entities::NONCE_SAVE ); ?>

			<div class="twt-aeo-card" style="padding:20px;margin-top:16px;">
				<p class="description" style="margin:0 0 14px;">
					<?php esc_html_e( 'Map a page URL to what it is primarily about and what it merely mentions, and optionally ground a topic to Wikidata. On that URL, the WebPage node gets about/mentions @id references and a topic Thing.', 'twt-aeo-ultimate' ); ?>
				</p>

				<div id="twt-kg-map-rows">
					<?php
					if ( empty( $mappings ) ) {
						echo self::mapping_row_html( 0, array() ); // phpcs:ignore WordPress.Security.EscapeOutput
					} else {
						foreach ( array_values( $mappings ) as $i => $m ) {
							echo self::mapping_row_html( $i, $m ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
					}
					?>
				</div>

				<p style="margin-top:12px;">
					<button type="button" class="button twt-kg-add" data-target="twt-kg-map-rows" data-template="twt-kg-map-tpl">
						<span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom;"></span>
						<?php esc_html_e( 'Add Page Mapping', 'twt-aeo-ultimate' ); ?>
					</button>
				</p>
			</div>

			<p style="margin-top:16px;">
				<button type="submit" name="twtaeo_kg_save" value="mapping" class="button button-primary">
					<?php esc_html_e( 'Save Page Mappings', 'twt-aeo-ultimate' ); ?>
				</button>
			</p>
		</form>

		<template id="twt-kg-map-tpl"><?php echo self::mapping_row_html( '__INDEX__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></template>
		<?php
		if ( empty( $options ) ) {
			echo '<p class="description" style="margin-top:8px;">' . esc_html__( 'Tip: the about / mentions pickers are empty until you define entities on the Entities tab.', 'twt-aeo-ultimate' ) . '</p>';
		}
	}

	/** Render one page-mapping repeater row. */
	private static function mapping_row_html( $i, array $m ) {
		$options   = TWTAEO_KG_Entities::entity_options();
		$url       = $m['url'] ?? '';
		$about     = (array) ( $m['about'] ?? array() );
		$mentions  = (array) ( $m['mentions'] ?? array() );
		$topic     = $m['topic'] ?? '';
		$topic_sa  = ! empty( $m['topic_same_as'] ) ? implode( "\n", (array) $m['topic_same_as'] ) : '';
		$field     = 'mappings[' . $i . ']';

		ob_start();
		?>
		<div class="twt-kg-row" style="border:1px solid #dcdcde;border-radius:6px;padding:16px;margin-bottom:12px;background:#fff;">
			<div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
				<label style="display:block;">
					<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Page URL', 'twt-aeo-ultimate' ); ?></span>
					<input type="text" style="width:100%;" name="<?php echo esc_attr( $field ); ?>[url]" value="<?php echo esc_attr( $url ); ?>" placeholder="<?php echo esc_attr( home_url( '/about/' ) ); ?>" />
				</label>
				<button type="button" class="button-link twt-kg-remove" title="<?php esc_attr_e( 'Remove mapping', 'twt-aeo-ultimate' ); ?>" style="color:#b32d2e;padding-bottom:6px;">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</div>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
				<label style="display:block;">
					<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'About (primary subject)', 'twt-aeo-ultimate' ); ?></span>
					<select multiple size="4" style="width:100%;" name="<?php echo esc_attr( $field ); ?>[about][]">
						<?php foreach ( $options as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php echo in_array( $id, $about, true ) ? 'selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="display:block;">
					<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Mentions (secondary)', 'twt-aeo-ultimate' ); ?></span>
					<select multiple size="4" style="width:100%;" name="<?php echo esc_attr( $field ); ?>[mentions][]">
						<?php foreach ( $options as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php echo in_array( $id, $mentions, true ) ? 'selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>

			<div style="margin-top:12px;border-top:1px dashed #dcdcde;padding-top:12px;">
				<div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
					<label style="display:block;">
						<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Topic (grounded to Wikidata)', 'twt-aeo-ultimate' ); ?></span>
						<input type="text" class="twt-kg-topic" style="width:100%;" name="<?php echo esc_attr( $field ); ?>[topic]" value="<?php echo esc_attr( $topic ); ?>" placeholder="<?php esc_attr_e( 'e.g. Content marketing', 'twt-aeo-ultimate' ); ?>" />
					</label>
					<span style="padding-bottom:2px;">
						<button type="button" class="button button-small twt-kg-ground" data-name-from="topic" data-fixed-type="topic">
							<span class="dashicons dashicons-superhero" style="vertical-align:text-bottom;font-size:15px;"></span>
							<?php esc_html_e( 'Ground topic', 'twt-aeo-ultimate' ); ?>
						</button>
						<span class="twt-kg-ground-spinner" style="display:none;"><span class="spinner is-active" style="float:none;margin:0;"></span></span>
					</span>
				</div>
				<div style="margin-top:8px;">
					<span style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php esc_html_e( 'Topic sameAs (one URL per line)', 'twt-aeo-ultimate' ); ?></span>
					<textarea rows="2" class="twt-kg-sameas" style="width:100%;font-family:monospace;font-size:12px;" name="<?php echo esc_attr( $field ); ?>[topic_same_as]" placeholder="https://en.wikipedia.org/wiki/Content_marketing&#10;https://www.wikidata.org/wiki/Q..."><?php echo esc_textarea( $topic_sa ); ?></textarea>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Shared JS ────────────────────────────────────────────────────────────────

	private static function render_script() {
		$nonce = wp_create_nonce( TWTAEO_KG_Entities::NONCE_AJAX );
		ob_start();
		?>
		(function () {
			var ajaxurl = window.ajaxurl;
			var nonce   = '<?php echo esc_js( $nonce ); ?>';
			var seq     = 100000; // Fresh, collision-proof indices for new rows.

			var container = document.querySelector( '.twt-aeo-wrap' );
			if ( ! container ) { return; }

			// ── Add row (clone a <template> and re-index its field names) ──────────
			container.addEventListener( 'click', function ( e ) {
				var addBtn = e.target.closest( '.twt-kg-add' );
				if ( ! addBtn ) { return; }
				e.preventDefault();

				var tpl  = document.getElementById( addBtn.getAttribute( 'data-template' ) );
				var dest = document.getElementById( addBtn.getAttribute( 'data-target' ) );
				if ( ! tpl || ! dest ) { return; }

				var idx  = seq++;
				var html = tpl.innerHTML.split( '__INDEX__' ).join( idx );
				var wrap = document.createElement( 'div' );
				wrap.innerHTML = html.trim();
				var row = wrap.firstElementChild;
				if ( row ) { dest.appendChild( row ); }
			} );

			// ── Remove row ─────────────────────────────────────────────────────────
			container.addEventListener( 'click', function ( e ) {
				var rm = e.target.closest( '.twt-kg-remove' );
				if ( ! rm ) { return; }
				e.preventDefault();
				var row = rm.closest( '.twt-kg-row' );
				if ( row ) { row.remove(); }
			} );

			// ── Ground with AI (entity sameAs OR topic sameAs) ─────────────────────
			container.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.twt-kg-ground' );
				if ( ! btn ) { return; }
				e.preventDefault();

				var row = btn.closest( '.twt-kg-row' );
				if ( ! row ) { return; }

				var name, type;
				if ( btn.getAttribute( 'data-fixed-type' ) === 'topic' ) {
					var topicInput = row.querySelector( '.twt-kg-topic' );
					name = topicInput ? topicInput.value : '';
					type = 'topic';
				} else {
					var nameInput = row.querySelector( '.twt-kg-entity-name' );
					var typeInput = row.querySelector( '.twt-kg-entity-type' );
					name = nameInput ? nameInput.value : '';
					type = typeInput ? typeInput.value : 'thing';
				}

				name = ( name || '' ).trim();
				if ( ! name ) {
					window.alert( '<?php echo esc_js( __( 'Enter a name first.', 'twt-aeo-ultimate' ) ); ?>' );
					return;
				}

				var target  = row.querySelector( '.twt-kg-sameas' );
				var spinner = btn.parentNode.querySelector( '.twt-kg-ground-spinner' );
				if ( ! target ) { return; }

				btn.disabled = true;
				if ( spinner ) { spinner.style.display = 'inline-block'; }

				var body = new URLSearchParams();
				body.append( 'action', 'twtaeo_kg_resolve_sameas' );
				body.append( 'nonce', nonce );
				body.append( 'name', name );
				body.append( 'type', type );

				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						btn.disabled = false;
						if ( spinner ) { spinner.style.display = 'none'; }
						if ( ! res || ! res.success ) {
							window.alert( ( res && res.data && res.data.message ) ? res.data.message : '<?php echo esc_js( __( 'Grounding failed.', 'twt-aeo-ultimate' ) ); ?>' );
							return;
						}
						var urls = res.data.urls || [];
						if ( ! urls.length ) {
							window.alert( '<?php echo esc_js( __( 'No confident authority URLs were found for that name.', 'twt-aeo-ultimate' ) ); ?>' );
							return;
						}
						// Merge with any URLs already present, de-duplicated.
						var existing = target.value.split( /\r?\n/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
						urls.forEach( function ( u ) {
							if ( existing.indexOf( u ) === -1 ) { existing.push( u ); }
						} );
						target.value = existing.join( '\n' );
					} )
					.catch( function () {
						btn.disabled = false;
						if ( spinner ) { spinner.style.display = 'none'; }
						window.alert( '<?php echo esc_js( __( 'Request failed. Check your connection.', 'twt-aeo-ultimate' ) ); ?>' );
					} );
			} );
		}());
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}
