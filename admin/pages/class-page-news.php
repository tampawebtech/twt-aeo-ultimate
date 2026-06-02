<?php
/**
 * Admin Page — Google News
 *
 * Two tabs:
 *   Settings   — post types, publication name, ping Google, schema toggle
 *   Sitemap    — live preview of the current news sitemap entries
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_News {

	const NONCE_SETTINGS = 'twtaeo_news_save';

	// ── Render ────────────────────────────────────────────────────────────────

	public static function render() {
		self::maybe_handle_post();

		$tab      = sanitize_key( wp_unslash( $_GET['twtaeo_tab'] ?? 'settings' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = TWTAEO_News_Sitemap::get_settings();

		$sitemap_url  = TWTAEO_News_Sitemap::get_url();
		$fallback_url = TWTAEO_News_Sitemap::get_fallback_url();
		?>
		<div class="wrap twt-aeo-page">
			<h1 style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
				<span class="dashicons dashicons-rss" style="font-size:24px;color:#4285f4;"></span>
				<?php esc_html_e( 'Google News', 'twt-aeo-ultimate' ); ?>
			</h1>
			<p style="color:#646970;margin-top:0;margin-bottom:20px;">
				<?php esc_html_e( 'Manage your Google News sitemap and NewsArticle schema. Only posts published in the last 2 days appear in the news sitemap — this is a Google requirement.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php /* ── Sitemap URL banner ── */ ?>
			<div style="background:#f0f6fc;border:1px solid #c3d9ef;border-radius:6px;padding:12px 16px;margin-bottom:24px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<span style="font-size:13px;color:#444;">
					<?php esc_html_e( 'News Sitemap:', 'twt-aeo-ultimate' ); ?>
					<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener"
					   style="font-family:monospace;font-size:13px;margin-left:4px;">
						<?php echo esc_html( $sitemap_url ); ?>
					</a>
				</span>
				<span style="font-size:11px;color:#888;">
					<?php esc_html_e( 'Fallback:', 'twt-aeo-ultimate' ); ?>
					<a href="<?php echo esc_url( $fallback_url ); ?>" target="_blank" rel="noopener"
					   style="font-family:monospace;font-size:11px;margin-left:2px;">
						<?php echo esc_html( $fallback_url ); ?>
					</a>
				</span>
			</div>

			<nav class="twt-aeo-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'twtaeo_tab', 'settings', menu_page_url( 'twt-aeo-news', false ) ) ); ?>"
				   class="twt-aeo-tab <?php echo $tab === 'settings' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-admin-settings"></span>
					<?php esc_html_e( 'Settings', 'twt-aeo-ultimate' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'twtaeo_tab', 'sitemap', menu_page_url( 'twt-aeo-news', false ) ) ); ?>"
				   class="twt-aeo-tab <?php echo $tab === 'sitemap' ? 'twt-aeo-tab--active' : ''; ?>">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'Sitemap Preview', 'twt-aeo-ultimate' ); ?>
				</a>
			</nav>

			<?php if ( $tab === 'settings' ) : ?>
				<?php self::render_settings_tab( $settings ); ?>
			<?php else : ?>
				<?php self::render_sitemap_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Settings Tab ─────────────────────────────────────────────────────────

	private static function render_settings_tab( array $settings ) {
		$all_post_types   = get_post_types( array( 'public' => true ), 'objects' );
		$enabled_types    = (array) ( $settings['post_types'] ?? array( 'post' ) );
		$publication_name = $settings['publication_name'] ?? '';
		$ping_google      = ! empty( $settings['ping_google'] );
		$schema_enabled   = ! empty( $settings['schema_enabled'] );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_SETTINGS, '_twtaeo_news_nonce' ); ?>
			<input type="hidden" name="twtaeo_news_action" value="save_settings">

			<?php /* ── Publication ── */ ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;margin-bottom:24px;">
				<h2 style="margin:0 0 6px;font-size:15px;"><?php esc_html_e( 'Publication', 'twt-aeo-ultimate' ); ?></h2>
				<p style="margin:0 0 20px;color:#646970;font-size:13px;"><?php esc_html_e( 'These values populate the &lt;news:publication&gt; element in your news sitemap.', 'twt-aeo-ultimate' ); ?></p>

				<table class="form-table" style="margin:0;">
					<tr>
						<th style="width:200px;padding:8px 16px 8px 0;">
							<label for="twtaeo_news_pub_name"><?php esc_html_e( 'Publication Name', 'twt-aeo-ultimate' ); ?></label>
						</th>
						<td style="padding:8px 0;">
							<input type="text" id="twtaeo_news_pub_name" name="twtaeo_news[publication_name]"
							       value="<?php echo esc_attr( $publication_name ); ?>"
							       class="regular-text"
							       placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Defaults to your site name if left blank.', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th style="padding:8px 16px 8px 0;">
							<?php esc_html_e( 'Language', 'twt-aeo-ultimate' ); ?>
						</th>
						<td style="padding:8px 0;">
							<code style="background:#f6f7f7;padding:4px 8px;border-radius:3px;">
								<?php echo esc_html( strtolower( substr( get_locale(), 0, 2 ) ) ?: 'en' ); ?>
							</code>
							<p class="description"><?php esc_html_e( 'Derived from your WordPress site language setting.', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<?php /* ── Post Types ── */ ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;margin-bottom:24px;">
				<h2 style="margin:0 0 6px;font-size:15px;"><?php esc_html_e( 'Post Types', 'twt-aeo-ultimate' ); ?></h2>
				<p style="margin:0 0 16px;color:#646970;font-size:13px;"><?php esc_html_e( 'Choose which post types are eligible for the news sitemap and NewsArticle schema. The Google News metabox will appear on all enabled types.', 'twt-aeo-ultimate' ); ?></p>

				<div style="display:flex;flex-wrap:wrap;gap:12px;">
					<?php foreach ( $all_post_types as $slug => $obj ) :
						if ( in_array( $slug, array( 'attachment' ), true ) ) continue;
					?>
					<label style="display:flex;align-items:center;gap:6px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:8px 12px;cursor:pointer;">
						<input type="checkbox" name="twtaeo_news[post_types][]"
						       value="<?php echo esc_attr( $slug ); ?>"
						       <?php checked( in_array( $slug, $enabled_types, true ) ); ?>>
						<span><?php echo esc_html( $obj->labels->singular_name ); ?></span>
						<code style="font-size:11px;color:#888;"><?php echo esc_html( $slug ); ?></code>
					</label>
					<?php endforeach; ?>
				</div>
			</div>

			<?php /* ── Options ── */ ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;margin-bottom:24px;">
				<h2 style="margin:0 0 6px;font-size:15px;"><?php esc_html_e( 'Options', 'twt-aeo-ultimate' ); ?></h2>

				<table class="form-table" style="margin:0;">
					<tr>
						<th style="width:200px;padding:8px 16px 8px 0;">
							<?php esc_html_e( 'Ping Google on Publish', 'twt-aeo-ultimate' ); ?>
						</th>
						<td style="padding:8px 0;">
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
								<input type="checkbox" name="twtaeo_news[ping_google]" value="1" <?php checked( $ping_google ); ?>>
								<span><?php esc_html_e( 'Notify Google when a new article is published', 'twt-aeo-ultimate' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Sends a GET request to google.com/ping with your news sitemap URL. Fires instantly on publish_post.', 'twt-aeo-ultimate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th style="padding:8px 16px 8px 0;">
							<?php esc_html_e( 'NewsArticle Schema', 'twt-aeo-ultimate' ); ?>
						</th>
						<td style="padding:8px 0;">
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
								<input type="checkbox" name="twtaeo_news[schema_enabled]" value="1" <?php checked( $schema_enabled ); ?>>
								<span><?php esc_html_e( 'Output NewsArticle JSON-LD on enabled post types', 'twt-aeo-ultimate' ); ?></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'Includes full author Person node (from Author Entity) and publisher Organization. Automatically deferred if Yoast or Rank Math already declares NewsArticle schema.', 'twt-aeo-ultimate' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<?php /* ── NewsArticle Schema Preview ── */ ?>
			<?php if ( $schema_enabled ) : ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;margin-bottom:24px;">
				<h2 style="margin:0 0 6px;font-size:15px;"><?php esc_html_e( 'Schema Structure', 'twt-aeo-ultimate' ); ?></h2>
				<p style="margin:0 0 12px;color:#646970;font-size:13px;"><?php esc_html_e( 'This is the NewsArticle schema structure output on each eligible post.', 'twt-aeo-ultimate' ); ?></p>
				<pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;font-size:12px;overflow-x:auto;line-height:1.6;margin:0;"><?php echo esc_html( '{
  "@context": "https://schema.org",
  "@type": "NewsArticle",
  "headline": "Post title",
  "url": "https://example.com/post",
  "datePublished": "2025-01-01T00:00:00+00:00",
  "dateModified": "2025-01-01T00:00:00+00:00",
  "articleSection": "Technology",       ← from Google News metabox
  "keywords": ["keyword1", "keyword2"], ← from Google News metabox
  "description": "Post excerpt",
  "image": { "@type": "ImageObject", "url": "..." },
  "author": {
    "@type": "Person",
    "@id": "https://example.com/author/john#person",  ← Author Entity @id
    "name": "John Doe",
    "url": "https://example.com/author/john",
    "jobTitle": "Senior Editor",        ← Author Entity field
    "sameAs": ["https://linkedin.com/in/john"]  ← Author Entity sameAs
  },
  "publisher": {
    "@type": "Organization",            ← from Company Profile
    "name": "Site Name",
    "url": "https://example.com",
    "logo": { "@type": "ImageObject", "url": "..." }
  }
}' ); ?></pre>
			</div>
			<?php endif; ?>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save Settings', 'twt-aeo-ultimate' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	// ── Sitemap Preview Tab ───────────────────────────────────────────────────

	private static function render_sitemap_tab() {
		$posts = TWTAEO_News_Sitemap::get_recent_posts( 100 );
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px 28px;">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
				<h2 style="margin:0;font-size:15px;"><?php esc_html_e( 'Current News Sitemap Entries', 'twt-aeo-ultimate' ); ?></h2>
				<a href="<?php echo esc_url( TWTAEO_News_Sitemap::get_url() ); ?>" target="_blank" rel="noopener"
				   class="button button-small">
					<?php esc_html_e( 'View Raw XML', 'twt-aeo-ultimate' ); ?>
				</a>
			</div>
			<p style="margin:0 0 20px;color:#646970;font-size:13px;">
				<?php esc_html_e( 'Only posts published within the last 48 hours qualify. This is a Google News requirement — older posts are intentionally excluded.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php if ( empty( $posts ) ) : ?>
				<div style="text-align:center;padding:40px 0;color:#646970;">
					<p style="font-size:28px;margin:0 0 8px;">📰</p>
					<p style="margin:0;font-size:14px;"><?php esc_html_e( 'No posts published in the last 48 hours.', 'twt-aeo-ultimate' ); ?></p>
					<p style="margin:6px 0 0;font-size:12px;color:#999;"><?php esc_html_e( 'Publish a new post and it will appear here within minutes.', 'twt-aeo-ultimate' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped" style="border-radius:4px;overflow:hidden;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'twt-aeo-ultimate' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Published', 'twt-aeo-ultimate' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Section', 'twt-aeo-ultimate' ); ?></th>
							<th><?php esc_html_e( 'Keywords', 'twt-aeo-ultimate' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Schema', 'twt-aeo-ultimate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $posts as $post ) :
						$section   = TWTAEO_News_Meta::get_section( $post->ID );
						$keywords  = TWTAEO_News_Meta::get_keywords( $post->ID );
						$schema_on = ! empty( TWTAEO_News_Sitemap::get_settings()['schema_enabled'] );
						$pub_time  = get_post_time( 'U', true, $post->ID );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener"
							   style="font-weight:500;">
								<?php echo esc_html( get_the_title( $post->ID ) ); ?>
							</a>
							<div style="margin-top:2px;">
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"
								   style="font-size:11px;color:#646970;text-decoration:none;">
									<?php esc_html_e( 'Edit', 'twt-aeo-ultimate' ); ?> &rarr;
								</a>
							</div>
						</td>
						<td style="font-size:12px;color:#646970;white-space:nowrap;">
							<?php echo esc_html( human_time_diff( $pub_time, time() ) . ' ' . __( 'ago', 'twt-aeo-ultimate' ) ); ?>
						</td>
						<td style="font-size:13px;">
							<?php echo $section ? esc_html( $section ) : '<span style="color:#ccc;">—</span>'; ?>
						</td>
						<td style="font-size:12px;">
							<?php if ( ! empty( $keywords ) ) : ?>
								<?php foreach ( $keywords as $kw ) : ?>
									<span style="display:inline-block;background:#f0f0f0;border-radius:3px;padding:1px 6px;margin:1px 2px 1px 0;font-size:11px;">
										<?php echo esc_html( $kw ); ?>
									</span>
								<?php endforeach; ?>
							<?php else : ?>
								<span style="color:#ccc;">—</span>
							<?php endif; ?>
						</td>
						<td style="text-align:center;">
							<?php if ( $schema_on ) : ?>
								<span style="color:#10b981;font-size:16px;" title="<?php esc_attr_e( 'NewsArticle schema active', 'twt-aeo-ultimate' ); ?>">&#10003;</span>
							<?php else : ?>
								<span style="color:#d1d5db;font-size:16px;" title="<?php esc_attr_e( 'Schema disabled in settings', 'twt-aeo-ultimate' ); ?>">&#8212;</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin:12px 0 0;font-size:11px;color:#aaa;">
					<?php printf(
						/* translators: %d: number of entries */
						esc_html( _n( '%d entry in the news sitemap.', '%d entries in the news sitemap.', count( $posts ), 'twt-aeo-ultimate' ) ),
						count( $posts )
					); ?>
					<?php esc_html_e( 'Cache TTL: 5 minutes.', 'twt-aeo-ultimate' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── POST handling ─────────────────────────────────────────────────────────

	public static function maybe_handle_post() {
		if ( empty( $_POST['twtaeo_news_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_twtaeo_news_nonce'] ?? '' ) ), self::NONCE_SETTINGS ) ) {
			return;
		}

		if ( 'save_settings' === sanitize_key( wp_unslash( $_POST['twtaeo_news_action'] ) ) ) {
			$raw = isset( $_POST['twtaeo_news'] ) ? map_deep( (array) wp_unslash( $_POST['twtaeo_news'] ), 'sanitize_text_field' ) : array();
			TWTAEO_News_Sitemap::save_settings( $raw );

			add_settings_error(
				'twtaeo_news',
				'saved',
				__( 'News settings saved.', 'twt-aeo-ultimate' ),
				'updated'
			);
		}

		settings_errors( 'twtaeo_news' );
	}
}
