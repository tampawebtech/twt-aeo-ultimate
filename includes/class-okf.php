<?php
/**
 * Open Knowledge Format (OKF) bundle.
 *
 * Serves the site as a directory of markdown concept files with YAML
 * frontmatter at /okf/, per Google's OKF v0.2 spec
 * (github.com/GoogleCloudPlatform/knowledge-catalog, June 2026).
 *
 * Spec facts this class is built against (verified against the spec, not
 * assumed — ported from the AEO Ultimate Shopify app, whose builders carry the
 * same facts and the same tests):
 *   - `index.md` is OPTIONAL; when present its ONLY frontmatter key is
 *     `okf_version`. The body is markdown sections of linked entries.
 *   - Concept files require exactly one frontmatter field: `type`.
 *     `title`, `description`, `resource`, `tags` are recommended.
 *   - Trust: `generated: { by, at }` with the actor convention
 *     `process:<id>` for automated producers. We are
 *     `process:twt-aeo-ultimate`.
 *   - Cross-links are plain markdown links; bundle-relative starts with `/`.
 *     So `/products/foo.md` is CORRECT even though it looks like a site URL —
 *     it is relative to the bundle root (/okf/), not the site root. Do not
 *     "fix" it.
 *   - Consumers must tolerate unknown types and broken links.
 *
 * ⚠️ Output is served RAW as text/markdown. Never HTML-escape it. This plugin
 * once shipped llms.txt starting "&gt; description" because wp_kses_post()
 * entity-encoded the blockquote, and that corrupts the one output the feature
 * exists to provide. Everything the builders emit is already plain text.
 *
 * Layout: the pure builders (yaml_*, one_line, build_*) use no WordPress
 * functions so tests/okf/run-tests.php can exercise them without a WP
 * bootstrap. The data layer (bundle(), load_*, render_path()) reads the live
 * site and may use WordPress and WooCommerce freely.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_OKF {

	const VERSION   = '0.2';
	const ACTOR     = 'process:twt-aeo-ultimate';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** Listing caps for index.md. Every item still resolves at its own path. */
	const PRODUCT_LIMIT    = 500;
	const POST_LIMIT       = 500;
	const COLLECTION_LIMIT = 100;

	/** Products listed on one collection page. */
	const COLLECTION_PRODUCTS = 50;

	/**
	 * Cache version option. Bumped on content change so every transient key
	 * changes at once — cheaper than enumerating and deleting transients.
	 */
	const CACHE_VERSION_OPTION = 'twtaeo_okf_cache_ver';

	/** Transient key prefix; the key is prefix + md5( version . ':' . path ). */
	const TRANSIENT_PREFIX = 'twtaeo_okf_';

	/** Policy slugs this bundle can mint → display labels. Nothing else exists. */
	public static function policy_types() {
		return array(
			'privacy-policy'   => 'Privacy policy',
			'refund-policy'    => 'Refund policy',
			'shipping-policy'  => 'Shipping policy',
			'terms-of-service' => 'Terms of service',
		);
	}

	// ── YAML emission (pure) ───────────────────────────────────────────────────
	// Flat strings and string lists only, always double-quoted, so no YAML edge
	// case (colons in titles, leading @, quotes, newlines) can break the
	// frontmatter.

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function yaml_string( $value ) {
		$s = (string) ( null === $value ? '' : $value );
		$s = str_replace( '\\', '\\\\', $s );
		$s = str_replace( '"', '\\"', $s );
		$s = preg_replace( '/\r?\n/', ' ', $s );
		return '"' . $s . '"';
	}

	/**
	 * @param array $values
	 * @return string
	 */
	public static function yaml_string_list( array $values ) {
		$out = array();
		foreach ( $values as $v ) {
			$out[] = self::yaml_string( $v );
		}
		return '[' . implode( ', ', $out ) . ']';
	}

	/**
	 * Collapse prose to one line for `description`, truncated with an ellipsis.
	 *
	 * @param string|null $text
	 * @param int         $max
	 * @return string
	 */
	public static function one_line( $text, $max = 200 ) {
		if ( null === $text || '' === $text ) {
			return '';
		}
		$flat = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( mb_strlen( $flat ) > $max ) {
			return rtrim( mb_substr( $flat, 0, $max - 1 ) ) . '…';
		}
		return $flat;
	}

	/**
	 * The `generated:` trust block.
	 *
	 * @param string|null $at ISO-8601 timestamp; defaults to now (UTC).
	 * @return string
	 */
	public static function generated_block( $at = null ) {
		if ( null === $at ) {
			$at = gmdate( 'Y-m-d\TH:i:s\Z' );
		}
		return "generated:\n  by: " . self::yaml_string( self::ACTOR ) . "\n  at: " . self::yaml_string( $at );
	}

	/**
	 * HTML → readable plain text. Block-level closers become newlines and list
	 * items become dashes so the structure survives; everything else is
	 * stripped. Output is raw markdown-ish text — never HTML-escaped (the OKF
	 * rule), and entities are decoded for the same reason. Pure PHP.
	 *
	 * @param string $html
	 * @return string
	 */
	public static function policy_text( $html ) {
		$text = (string) $html;
		$text = preg_replace( '/<!--.*?-->/s', '', $text );
		$text = preg_replace( '/<(?:script|style)[^>]*>.*?<\/(?:script|style)>/is', '', $text );
		$text = preg_replace( '/<(?:br|\/p|\/h[1-6]|\/li|\/ul|\/ol|\/div|\/tr)[^>]*>/i', "\n", $text );
		$text = preg_replace( '/<li[^>]*>/i', '- ', $text );
		$text = preg_replace( '/<[^>]*>/', ' ', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text ); // &nbsp; decodes to U+00A0.
		$lines = explode( "\n", $text );
		foreach ( $lines as $i => $line ) {
			$lines[ $i ] = trim( preg_replace( '/[ \t]+/', ' ', $line ) );
		}
		$text = implode( "\n", $lines );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	// ── Pure builders ──────────────────────────────────────────────────────────

	/**
	 * index.md — the manifest. Frontmatter is `okf_version` and NOTHING else.
	 *
	 * @param array $bundle {
	 *   site_name, summary, site_url,
	 *   has_organization (bool),
	 *   policies    [ { slug, label } ],
	 *   collections [ { title, slug } ],
	 *   products    [ { title, slug, note } ], product_total_note,
	 *   posts       [ { title, slug, note } ], post_total_note,
	 *   generated_at (optional)
	 * }
	 * @return string
	 */
	public static function build_index( array $bundle ) {
		$site_name = (string) ( $bundle['site_name'] ?? '' );
		$site_url  = (string) ( $bundle['site_url'] ?? '' );
		$lines     = array();

		// Per spec §manifest: okf_version is the ONLY frontmatter key allowed here.
		$lines[] = '---';
		$lines[] = 'okf_version: ' . self::yaml_string( self::VERSION );
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $site_name;
		$lines[] = '';
		if ( ! empty( $bundle['summary'] ) ) {
			$lines[] = '> ' . $bundle['summary'];
			$lines[] = '';
		}
		$lines[] = 'Knowledge bundle for [' . $site_name . '](' . $site_url . '), generated live by ' . self::ACTOR
			. '. Every entry is a markdown concept file with YAML frontmatter (OKF ' . self::VERSION . ').';
		$lines[] = '';

		if ( ! empty( $bundle['has_organization'] ) ) {
			$lines[] = '## Who runs this site';
			$lines[] = '';
			$lines[] = '- [Organization](/organization.md): identity, social profiles, and verified off-site records';
			$lines[] = '';
		}

		if ( ! empty( $bundle['policies'] ) ) {
			$lines[] = '## Site policies';
			$lines[] = '';
			foreach ( $bundle['policies'] as $p ) {
				$lines[] = '- [' . $p['label'] . '](/policies/' . $p['slug'] . '.md)';
			}
			$lines[] = '';
		}

		if ( ! empty( $bundle['collections'] ) ) {
			$lines[] = '## Collections';
			$lines[] = '';
			foreach ( $bundle['collections'] as $c ) {
				$lines[] = '- [' . $c['title'] . '](/collections/' . $c['slug'] . '.md)';
			}
			$lines[] = '';
		}

		if ( ! empty( $bundle['products'] ) ) {
			$lines[] = '## Products';
			$lines[] = '';
			foreach ( $bundle['products'] as $p ) {
				$note    = ! empty( $p['note'] ) ? ': ' . $p['note'] : '';
				$lines[] = '- [' . $p['title'] . '](/products/' . $p['slug'] . '.md)' . $note;
			}
			$lines[] = '';
			if ( ! empty( $bundle['product_total_note'] ) ) {
				$lines[] = $bundle['product_total_note'];
				$lines[] = '';
			}
		}

		if ( ! empty( $bundle['posts'] ) ) {
			$lines[] = '## Articles';
			$lines[] = '';
			foreach ( $bundle['posts'] as $p ) {
				$note    = ! empty( $p['note'] ) ? ': ' . $p['note'] : '';
				$lines[] = '- [' . $p['title'] . '](/posts/' . $p['slug'] . '.md)' . $note;
			}
			$lines[] = '';
			if ( ! empty( $bundle['post_total_note'] ) ) {
				$lines[] = $bundle['post_total_note'];
				$lines[] = '';
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * organization.md.
	 *
	 * @param array $org { name, description, url, social { key => url }, same_as [], generated_at }
	 * @return string
	 */
	public static function build_organization( array $org ) {
		$name  = (string) ( $org['name'] ?? '' );
		$lines = array();

		$lines[] = '---';
		$lines[] = 'type: "Organization"';
		$lines[] = 'title: ' . self::yaml_string( $name );
		$description = self::one_line( $org['description'] ?? '' );
		if ( '' !== $description ) {
			$lines[] = 'description: ' . self::yaml_string( $description );
		}
		if ( ! empty( $org['url'] ) ) {
			$lines[] = 'resource: ' . self::yaml_string( $org['url'] );
		}
		$lines[] = 'status: "stable"';
		$lines[] = self::generated_block( $org['generated_at'] ?? null );
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $name;
		$lines[] = '';
		if ( ! empty( $org['description'] ) ) {
			$lines[] = trim( (string) $org['description'] );
			$lines[] = '';
		}

		$links = array();
		if ( ! empty( $org['social'] ) && is_array( $org['social'] ) ) {
			foreach ( $org['social'] as $key => $url ) {
				if ( is_string( $url ) && '' !== trim( $url ) ) {
					$links[] = '- [' . $key . '](' . trim( $url ) . ')';
				}
			}
		}
		if ( ! empty( $org['same_as'] ) && is_array( $org['same_as'] ) ) {
			foreach ( $org['same_as'] as $url ) {
				if ( is_string( $url ) && '' !== trim( $url ) ) {
					$links[] = '- <' . trim( $url ) . '>';
				}
			}
		}
		if ( $links ) {
			$lines[] = '## Verified elsewhere';
			$lines[] = '';
			foreach ( $links as $l ) {
				$lines[] = $l;
			}
			$lines[] = '';
		}

		$facts = array();
		if ( ! empty( $org['email'] ) ) {
			$facts[] = '- Email: ' . $org['email'];
		}
		if ( ! empty( $org['phone'] ) ) {
			$facts[] = '- Phone: ' . $org['phone'];
		}
		if ( ! empty( $org['founding_year'] ) ) {
			$facts[] = '- Founded: ' . $org['founding_year'];
		}
		if ( $facts ) {
			$lines[] = '## Facts';
			$lines[] = '';
			foreach ( $facts as $f ) {
				$lines[] = $f;
			}
			$lines[] = '';
		}

		$lines[] = 'Runs [this site](/index.md).';
		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * products/<slug>.md.
	 *
	 * @param array $p {
	 *   title, slug, description (plain text / markdown), url,
	 *   brand, product_type, tags [], sku, gtin, availability,
	 *   price_min, price_max, currency,
	 *   attributes { label => value }, collections [ { title, slug } ],
	 *   faq [ { question, answer } ], generated_at
	 * }
	 * @return string
	 */
	public static function build_product( array $p ) {
		$title = (string) ( $p['title'] ?? '' );
		$lines = array();

		$lines[] = '---';
		$lines[] = 'type: "Product"';
		$lines[] = 'title: ' . self::yaml_string( $title );
		$description = self::one_line( $p['description'] ?? '' );
		if ( '' !== $description ) {
			$lines[] = 'description: ' . self::yaml_string( $description );
		}
		if ( ! empty( $p['url'] ) ) {
			$lines[] = 'resource: ' . self::yaml_string( $p['url'] );
		}
		if ( ! empty( $p['tags'] ) && is_array( $p['tags'] ) ) {
			$lines[] = 'tags: ' . self::yaml_string_list( $p['tags'] );
		}
		$lines[] = 'status: "stable"';
		$lines[] = self::generated_block( $p['generated_at'] ?? null );
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $title;
		$lines[] = '';

		if ( ! empty( $p['description'] ) ) {
			$lines[] = trim( (string) $p['description'] );
			$lines[] = '';
		}

		$facts = array();
		if ( ! empty( $p['brand'] ) ) {
			$facts[] = '- Brand: ' . $p['brand'];
		}
		if ( ! empty( $p['product_type'] ) ) {
			$facts[] = '- Product type: ' . $p['product_type'];
		}
		if ( ! empty( $p['sku'] ) ) {
			$facts[] = '- SKU: ' . $p['sku'];
		}
		if ( ! empty( $p['gtin'] ) ) {
			$facts[] = '- GTIN: ' . $p['gtin'];
		}
		$price_min = (string) ( $p['price_min'] ?? '' );
		$price_max = (string) ( $p['price_max'] ?? '' );
		$currency  = (string) ( $p['currency'] ?? '' );
		if ( '' !== $price_min ) {
			$facts[] = ( '' === $price_max || $price_min === $price_max )
				? '- Price: ' . $price_min . ' ' . $currency
				: '- Price: ' . $price_min . '–' . $price_max . ' ' . $currency;
		}
		if ( ! empty( $p['availability'] ) ) {
			$facts[] = '- Availability: ' . $p['availability'];
		}
		if ( ! empty( $p['attributes'] ) && is_array( $p['attributes'] ) ) {
			foreach ( $p['attributes'] as $key => $value ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$label   = (string) $key;
					$label   = self::u_ucfirst( $label );
					$facts[] = '- ' . $label . ': ' . trim( $value );
				}
			}
		}
		if ( $facts ) {
			$lines[] = '## Facts';
			$lines[] = '';
			foreach ( $facts as $f ) {
				$lines[] = $f;
			}
			$lines[] = '';
		}

		// Merchant-curated Q&A is exactly the content an answer engine wants from
		// a knowledge bundle — emit the pairs themselves, not just "FAQs exist".
		if ( ! empty( $p['faq'] ) && is_array( $p['faq'] ) ) {
			$pairs = array();
			foreach ( $p['faq'] as $pair ) {
				$q = trim( (string) ( $pair['question'] ?? '' ) );
				$a = trim( (string) ( $pair['answer'] ?? '' ) );
				if ( '' !== $q && '' !== $a ) {
					$pairs[] = array( $q, $a );
				}
			}
			if ( $pairs ) {
				$lines[] = '## Frequently asked questions';
				$lines[] = '';
				foreach ( $pairs as $pair ) {
					$lines[] = '### ' . $pair[0];
					$lines[] = '';
					$lines[] = $pair[1];
					$lines[] = '';
				}
			}
		}

		if ( ! empty( $p['collections'] ) && is_array( $p['collections'] ) ) {
			$lines[] = '## Belongs to';
			$lines[] = '';
			foreach ( $p['collections'] as $c ) {
				$lines[] = '- [' . $c['title'] . '](/collections/' . $c['slug'] . '.md)';
			}
			$lines[] = '';
		}

		$lines[] = "Part of [this site's catalog](/index.md).";
		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * collections/<slug>.md — a WooCommerce product category.
	 *
	 * @param array $c { title, slug, description, url, products [ { title, slug } ], generated_at }
	 * @return string
	 */
	public static function build_collection( array $c ) {
		$title = (string) ( $c['title'] ?? '' );
		$lines = array();

		$lines[] = '---';
		$lines[] = 'type: "Product Collection"';
		$lines[] = 'title: ' . self::yaml_string( $title );
		$description = self::one_line( $c['description'] ?? '' );
		if ( '' !== $description ) {
			$lines[] = 'description: ' . self::yaml_string( $description );
		}
		if ( ! empty( $c['url'] ) ) {
			$lines[] = 'resource: ' . self::yaml_string( $c['url'] );
		}
		$lines[] = 'status: "stable"';
		$lines[] = self::generated_block( $c['generated_at'] ?? null );
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $title;
		$lines[] = '';
		if ( ! empty( $c['description'] ) ) {
			$lines[] = trim( (string) $c['description'] );
			$lines[] = '';
		}
		if ( ! empty( $c['products'] ) && is_array( $c['products'] ) ) {
			$lines[] = '## Products';
			$lines[] = '';
			foreach ( $c['products'] as $p ) {
				$lines[] = '- [' . $p['title'] . '](/products/' . $p['slug'] . '.md)';
			}
			$lines[] = '';
		}
		$lines[] = "Part of [this site's catalog](/index.md).";
		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * posts/<slug>.md — a WordPress post as an Article concept.
	 *
	 * `Article` is the type name: OKF consumers must tolerate unknown types, and
	 * Article is the schema.org-familiar word every answer engine already maps.
	 *
	 * @param array $post {
	 *   title, slug, description, url, tags [], categories [],
	 *   author, published, modified, body (markdown / plain text), generated_at
	 * }
	 * @return string
	 */
	public static function build_post( array $post ) {
		$title = (string) ( $post['title'] ?? '' );
		$lines = array();

		$lines[] = '---';
		$lines[] = 'type: "Article"';
		$lines[] = 'title: ' . self::yaml_string( $title );
		$description = self::one_line( $post['description'] ?? '' );
		if ( '' !== $description ) {
			$lines[] = 'description: ' . self::yaml_string( $description );
		}
		if ( ! empty( $post['url'] ) ) {
			$lines[] = 'resource: ' . self::yaml_string( $post['url'] );
		}
		$tags = array();
		foreach ( array( 'tags', 'categories' ) as $k ) {
			if ( ! empty( $post[ $k ] ) && is_array( $post[ $k ] ) ) {
				$tags = array_merge( $tags, $post[ $k ] );
			}
		}
		$tags = array_values( array_unique( array_filter( array_map( 'strval', $tags ) ) ) );
		if ( $tags ) {
			$lines[] = 'tags: ' . self::yaml_string_list( $tags );
		}
		$lines[] = 'status: "stable"';
		$lines[] = self::generated_block( $post['generated_at'] ?? null );
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $title;
		$lines[] = '';

		$facts = array();
		if ( ! empty( $post['author'] ) ) {
			$facts[] = '- Author: ' . $post['author'];
		}
		if ( ! empty( $post['published'] ) ) {
			$facts[] = '- Published: ' . $post['published'];
		}
		if ( ! empty( $post['modified'] ) && ( empty( $post['published'] ) || $post['modified'] !== $post['published'] ) ) {
			$facts[] = '- Updated: ' . $post['modified'];
		}
		if ( $facts ) {
			foreach ( $facts as $f ) {
				$lines[] = $f;
			}
			$lines[] = '';
		}

		if ( ! empty( $post['body'] ) ) {
			$lines[] = trim( (string) $post['body'] );
			$lines[] = '';
		} elseif ( ! empty( $post['description'] ) ) {
			$lines[] = trim( (string) $post['description'] );
			$lines[] = '';
		}

		$lines[] = "Part of [this site's catalog](/index.md).";
		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * policies/<slug>.md.
	 *
	 * @param array $policy { label, body (plain text), url, generated_at }
	 * @return string
	 */
	public static function build_policy( array $policy ) {
		$label = (string) ( $policy['label'] ?? '' );
		$body  = (string) ( $policy['body'] ?? '' );
		$lines = array();

		$lines[] = '---';
		$lines[] = 'type: "Policy"';
		$lines[] = 'title: ' . self::yaml_string( $label );
		$description = self::one_line( $body );
		if ( '' !== $description ) {
			$lines[] = 'description: ' . self::yaml_string( $description );
		}
		if ( ! empty( $policy['url'] ) ) {
			$lines[] = 'resource: ' . self::yaml_string( $policy['url'] );
		}
		$lines[] = 'status: "stable"';
		$lines[] = self::generated_block( $policy['generated_at'] ?? null );
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $label;
		$lines[] = '';
		$lines[] = $body;
		$lines[] = '';
		$lines[] = "Part of [this site's catalog](/index.md).";
		$lines[] = '';
		return implode( "\n", $lines );
	}

	// ── Path router + cache (WordPress) ───────────────────────────────────────

	/**
	 * Resolve a bundle-relative path to rendered markdown, or null for 404.
	 *
	 * Slugs are validated before any lookup so a hostile path can never reach a
	 * query unvetted; percent signs are allowed because WordPress stores
	 * non-ASCII slugs percent-encoded.
	 *
	 * @param string $path e.g. '' | 'index.md' | 'products/foo.md'.
	 * @return string|null
	 */
	public static function render_path( $path ) {
		$path = trim( (string) $path );
		$path = trim( $path, '/' );

		if ( '' === $path || 'index.md' === $path ) {
			return self::cached( 'index.md', array( __CLASS__, 'render_index' ) );
		}
		if ( 'organization.md' === $path ) {
			return self::cached( $path, array( __CLASS__, 'render_organization' ) );
		}
		$slug = '([A-Za-z0-9%][A-Za-z0-9%\-_.]*)';
		if ( preg_match( '#^products/' . $slug . '\.md$#', $path, $m ) ) {
			return self::cached( $path, array( __CLASS__, 'render_product' ), $m[1] );
		}
		if ( preg_match( '#^collections/' . $slug . '\.md$#', $path, $m ) ) {
			return self::cached( $path, array( __CLASS__, 'render_collection' ), $m[1] );
		}
		if ( preg_match( '#^posts/' . $slug . '\.md$#', $path, $m ) ) {
			return self::cached( $path, array( __CLASS__, 'render_post' ), $m[1] );
		}
		if ( preg_match( '#^policies/([a-z0-9-]+)\.md$#', $path, $m ) ) {
			return self::cached( $path, array( __CLASS__, 'render_policy' ), $m[1] );
		}
		return null;
	}

	/**
	 * Transient-backed render. Misses (null) are not cached so a product that
	 * is published later shows up without waiting for the TTL.
	 *
	 * @param string   $path
	 * @param callable $render
	 * @param string   $arg
	 * @return string|null
	 */
	private static function cached( $path, $render, $arg = '' ) {
		$key = self::transient_key( $path );
		$hit = get_transient( $key );
		if ( is_string( $hit ) && '' !== $hit ) {
			return $hit;
		}
		$body = '' !== $arg ? call_user_func( $render, $arg ) : call_user_func( $render );
		if ( is_string( $body ) && '' !== $body ) {
			set_transient( $key, $body, self::CACHE_TTL );
			return $body;
		}
		return null;
	}

	/** @return string */
	private static function transient_key( $path ) {
		$ver = (string) get_option( self::CACHE_VERSION_OPTION, '1' );
		return self::TRANSIENT_PREFIX . md5( $ver . ':' . $path );
	}

	/**
	 * Invalidate every cached path by bumping the version in the key. Old
	 * transients expire on their own TTL.
	 */
	public static function flush_cache() {
		$ver = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		update_option( self::CACHE_VERSION_OPTION, (string) ( $ver + 1 ), true );
	}

	/** Content-change hooks that flush the cache. Cheap: one option write. */
	public static function register_invalidation_hooks() {
		add_action( 'save_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'delete_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'flush_cache' ) );
		add_action( 'edited_term', array( __CLASS__, 'flush_cache' ) );
		add_action( 'created_term', array( __CLASS__, 'flush_cache' ) );
		add_action( 'delete_term', array( __CLASS__, 'flush_cache' ) );
		if ( class_exists( 'TWTAEO_Company_Profile' ) ) {
			add_action( 'update_option_' . TWTAEO_Company_Profile::OPTION_KEY, array( __CLASS__, 'flush_cache' ) );
		}
	}

	// ── Data layer (WordPress + WooCommerce) ──────────────────────────────────

	/** @return bool */
	private static function has_woo() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
	}

	/**
	 * Plain text from stored HTML: shortcodes gone, tags gone, entities decoded,
	 * whitespace collapsed. For `description` fields and short notes.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function plain( $html ) {
		$text = strip_shortcodes( (string) $html );
		$text = preg_replace( '/<!--.*?-->/s', '', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Markdown body from stored HTML, via the plugin's converter when loaded.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function markdown( $html ) {
		$html = strip_shortcodes( (string) $html );
		if ( class_exists( 'TWTAEO_HTML_To_Markdown' ) ) {
			return trim( (string) TWTAEO_HTML_To_Markdown::convert( $html ) );
		}
		return self::policy_text( $html );
	}

	/**
	 * Uppercase the first character, UTF-8 aware, without assuming mbstring.
	 *
	 * WordPress polyfills mb_substr() and mb_strlen() in wp-includes/compat.php
	 * but NOT mb_strtoupper(), so calling it directly fatals the whole endpoint
	 * on a host built without the mbstring extension.
	 *
	 * @param string $s
	 * @return string
	 */
	private static function u_ucfirst( $s ) {
		$s = (string) $s;
		if ( '' === $s ) {
			return '';
		}
		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( mb_substr( $s, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $s, 1, null, 'UTF-8' );
		}
		// ASCII fallback: ucfirst() touches only the first byte, and a leading
		// multi-byte character is left as-is rather than corrupted.
		return ucfirst( $s );
	}

	/** @return string */
	private static function decode( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Site identity: the company profile when the module has one, else core.
	 *
	 * @return array { name, description, url, social, same_as, email, phone, founding_year }
	 */
	public static function organization_data() {
		$name        = self::decode( get_bloginfo( 'name' ) );
		$description = self::decode( get_bloginfo( 'description' ) );
		$url         = home_url( '/' );
		$social      = array();
		$same_as     = array();
		$email       = '';
		$phone       = '';
		$founded     = '';

		if ( class_exists( 'TWTAEO_Company_Profile' ) ) {
			$c = TWTAEO_Company_Profile::get();
			if ( ! empty( $c['name'] ) ) {
				$name = self::decode( $c['name'] );
			}
			if ( ! empty( $c['description'] ) ) {
				$description = self::decode( $c['description'] );
			}
			if ( ! empty( $c['url'] ) ) {
				$url = $c['url'];
			}
			foreach ( array( 'twitter', 'linkedin', 'facebook', 'instagram', 'youtube', 'pinterest' ) as $k ) {
				if ( ! empty( $c[ 'social_' . $k ] ) ) {
					$social[ $k ] = $c[ 'social_' . $k ];
				}
			}
			if ( ! empty( $c['social_wikipedia'] ) ) {
				$same_as[] = $c['social_wikipedia'];
			}
			// Email/phone are published only when the merchant typed them into the
			// profile — the admin_email default is not a public contact.
			$saved = get_option( TWTAEO_Company_Profile::OPTION_KEY, array() );
			if ( is_array( $saved ) && ! empty( $saved['email'] ) && is_email( $saved['email'] ) ) {
				$email = $saved['email'];
			}
			if ( ! empty( $c['phone'] ) ) {
				$phone = $c['phone'];
			}
			if ( ! empty( $c['founding_year'] ) ) {
				$founded = (string) $c['founding_year'];
			}
		}

		return array(
			'name'          => $name,
			'description'   => $description,
			'url'           => $url,
			'social'        => $social,
			'same_as'       => $same_as,
			'email'         => $email,
			'phone'         => $phone,
			'founding_year' => $founded,
		);
	}

	/**
	 * Published products, most recently modified first.
	 *
	 * @param int $limit
	 * @return array<int,array{title:string,slug:string,note:string}>
	 */
	private static function list_products( $limit ) {
		if ( ! self::has_woo() ) {
			return array();
		}
		$products = wc_get_products( array(
			'status'  => 'publish',
			'limit'   => $limit,
			'orderby' => 'modified',
			'order'   => 'DESC',
			'return'  => 'objects',
		) );
		$out = array();
		foreach ( (array) $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_slug' ) ) {
				continue;
			}
			$note  = self::plain( $product->get_short_description() );
			if ( '' === $note ) {
				$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
				$note = ( is_array( $cats ) && $cats ) ? implode( ', ', array_map( array( __CLASS__, 'decode' ), $cats ) ) : '';
			}
			$out[] = array(
				'title' => self::decode( $product->get_name() ),
				'slug'  => $product->get_slug(),
				'note'  => self::one_line( $note, 120 ),
			);
		}
		return $out;
	}

	/** @return int */
	private static function count_products() {
		if ( ! self::has_woo() ) {
			return 0;
		}
		$counts = wp_count_posts( 'product' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Product categories with at least one product.
	 *
	 * @param int $limit
	 * @return array<int,array{title:string,slug:string}>
	 */
	private static function list_collections( $limit ) {
		if ( ! self::has_woo() ) {
			return array();
		}
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => $limit,
			'orderby'    => 'count',
			'order'      => 'DESC',
		) );
		$out = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( 'uncategorized' === $t->slug ) {
					continue;
				}
				$out[] = array(
					'title' => self::decode( $t->name ),
					'slug'  => $t->slug,
				);
			}
		}
		return $out;
	}

	/**
	 * Published posts, most recently modified first.
	 *
	 * @param int $limit
	 * @return array<int,array{title:string,slug:string,note:string}>
	 */
	private static function list_posts( $limit ) {
		$posts = get_posts( array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		) );
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'title' => self::decode( get_the_title( $p ) ),
				'slug'  => $p->post_name,
				'note'  => self::one_line( self::post_excerpt( $p ), 120 ),
			);
		}
		return $out;
	}

	/** @return int */
	private static function count_posts() {
		$counts = wp_count_posts( 'post' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * @param WP_Post $p
	 * @return string
	 */
	private static function post_excerpt( $p ) {
		$text = $p->post_excerpt ? $p->post_excerpt : $p->post_content;
		return self::plain( $text );
	}

	/**
	 * Policy pages found on this site, in bundle order. Only pages with real
	 * text count — an empty policy is not knowledge.
	 *
	 * @return array<string,array{slug:string,label:string,post:WP_Post}>
	 */
	private static function find_policies() {
		$found = array();
		$types = self::policy_types();

		foreach ( $types as $slug => $label ) {
			$post = self::find_policy_page( $slug );
			if ( $post instanceof WP_Post && '' !== self::policy_text( strip_shortcodes( $post->post_content ) ) ) {
				$found[ $slug ] = array(
					'slug'  => $slug,
					'label' => $label,
					'post'  => $post,
				);
			}
		}
		return $found;
	}

	/**
	 * Locate one policy page. Reuses the plugin's existing detection (the
	 * WooCommerce detector's privacy/returns lookups) and WordPress/WooCommerce's
	 * own page settings before falling back to a slug scan.
	 *
	 * @param string $slug One of policy_types() keys.
	 * @return WP_Post|null
	 */
	private static function find_policy_page( $slug ) {
		$post = null;
		switch ( $slug ) {
			case 'privacy-policy':
				$id = (int) get_option( 'wp_page_for_privacy_policy' );
				if ( ! $id && class_exists( 'TWTAEO_WooCommerce_Detector' ) && method_exists( 'TWTAEO_WooCommerce_Detector', 'find_privacy_policy_url' ) ) {
					$id = (int) url_to_postid( (string) TWTAEO_WooCommerce_Detector::find_privacy_policy_url() );
				}
				$post = $id ? get_post( $id ) : null;
				if ( ! $post ) {
					$post = self::page_by_slugs( array( 'privacy-policy', 'privacy', 'privacy-notice' ) );
				}
				break;

			case 'refund-policy':
				$id = 0;
				if ( class_exists( 'TWTAEO_WooCommerce_Detector' ) && method_exists( 'TWTAEO_WooCommerce_Detector', 'find_return_policy_url' ) ) {
					$id = (int) url_to_postid( (string) TWTAEO_WooCommerce_Detector::find_return_policy_url() );
				}
				if ( ! $id ) {
					$id = (int) get_option( 'woocommerce_refund_returns_page_id' );
				}
				$post = $id ? get_post( $id ) : null;
				if ( ! $post ) {
					$post = self::page_by_slugs( array(
						'refund_returns', 'refund-and-returns-policy', 'refunds-and-returns',
						'returns-and-refunds', 'return-policy', 'returns-policy', 'refund-policy',
						'returns', 'refunds', 'shipping-and-returns',
					) );
				}
				break;

			case 'shipping-policy':
				$post = self::page_by_slugs( array(
					'shipping-policy', 'shipping', 'shipping-and-delivery', 'delivery-policy',
					'shipping-information', 'delivery', 'shipping-delivery',
				) );
				break;

			case 'terms-of-service':
				$id   = (int) get_option( 'woocommerce_terms_page_id' );
				$post = $id ? get_post( $id ) : null;
				if ( ! $post ) {
					$post = self::page_by_slugs( array(
						'terms-of-service', 'terms-and-conditions', 'terms-conditions', 'terms',
						'terms-of-sale', 'terms-of-use',
					) );
				}
				break;
		}
		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
			return $post;
		}
		return null;
	}

	/**
	 * @param string[] $slugs
	 * @return WP_Post|null
	 */
	private static function page_by_slugs( array $slugs ) {
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * Everything index.md needs, assembled from the live site.
	 *
	 * @return array
	 */
	public static function bundle() {
		$org       = self::organization_data();
		$products  = self::list_products( self::PRODUCT_LIMIT );
		$posts     = self::list_posts( self::POST_LIMIT );
		$total_p   = self::count_products();
		$total_a   = self::count_posts();
		$policies  = array();
		foreach ( self::find_policies() as $entry ) {
			$policies[] = array(
				'slug'  => $entry['slug'],
				'label' => $entry['label'],
			);
		}

		return array(
			'site_name'          => $org['name'],
			'summary'            => self::one_line( $org['description'], 300 ),
			'site_url'           => $org['url'],
			'has_organization'   => true,
			'policies'           => $policies,
			'collections'        => self::list_collections( self::COLLECTION_LIMIT ),
			'products'           => $products,
			'product_total_note' => $total_p > count( $products )
				? sprintf(
					'The %1$d most recently updated of %2$d products are listed; every product resolves at /products/<slug>.md regardless.',
					count( $products ),
					$total_p
				)
				: '',
			'posts'              => $posts,
			'post_total_note'    => $total_a > count( $posts )
				? sprintf(
					'The %1$d most recently updated of %2$d articles are listed; every article resolves at /posts/<slug>.md regardless.',
					count( $posts ),
					$total_a
				)
				: '',
		);
	}

	// ── Renderers (called through cached()) ───────────────────────────────────

	/** @return string */
	public static function render_index() {
		return self::build_index( self::bundle() );
	}

	/** @return string */
	public static function render_organization() {
		return self::build_organization( self::organization_data() );
	}

	/**
	 * @param string $slug
	 * @return string|null
	 */
	public static function render_product( $slug ) {
		if ( ! self::has_woo() ) {
			return null;
		}
		$posts = get_posts( array(
			'name'           => $slug,
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		) );
		if ( ! $posts ) {
			return null;
		}
		$product = wc_get_product( $posts[0]->ID );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return null;
		}
		$id = $product->get_id();

		// Brand: WooCommerce's own product_brand taxonomy (9.6+), else a "Brand"
		// attribute. Never the store's own name.
		$brand = '';
		foreach ( array( 'product_brand', 'pa_brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $id, $tax );
				if ( is_array( $terms ) && $terms ) {
					$brand = self::decode( $terms[0]->name );
					break;
				}
			}
		}

		$cats        = get_the_terms( $id, 'product_cat' );
		$collections = array();
		$cat_names   = array();
		if ( is_array( $cats ) ) {
			foreach ( $cats as $t ) {
				if ( 'uncategorized' === $t->slug ) {
					continue;
				}
				$collections[] = array(
					'title' => self::decode( $t->name ),
					'slug'  => $t->slug,
				);
				$cat_names[]   = self::decode( $t->name );
			}
		}

		$tags  = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'names' ) );
		$tags  = is_array( $tags ) ? array_map( array( __CLASS__, 'decode' ), $tags ) : array();

		$attributes = array();
		foreach ( (array) $product->get_attributes() as $attr ) {
			if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_name' ) ) {
				continue;
			}
			if ( method_exists( $attr, 'get_visible' ) && ! $attr->get_visible() ) {
				continue;
			}
			$raw   = $attr->get_name();
			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $raw, $product ) : $raw;
			$value = '';
			if ( method_exists( $attr, 'is_taxonomy' ) && $attr->is_taxonomy() && function_exists( 'wc_get_product_terms' ) ) {
				$names = wc_get_product_terms( $id, $raw, array( 'fields' => 'names' ) );
				$value = is_array( $names ) ? implode( ', ', $names ) : '';
			} elseif ( method_exists( $attr, 'get_options' ) ) {
				$value = implode( ', ', array_map( 'strval', (array) $attr->get_options() ) );
			}
			$value = self::decode( $value );
			if ( '' !== trim( $value ) && strcasecmp( $label, 'brand' ) !== 0 ) {
				$attributes[ self::decode( $label ) ] = $value;
			} elseif ( '' !== trim( $value ) && '' === $brand ) {
				$brand = $value;
			}
		}

		$price_min = '';
		$price_max = '';
		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
			$price_min = (string) $product->get_variation_price( 'min', true );
			$price_max = (string) $product->get_variation_price( 'max', true );
		} else {
			$price_min = (string) $product->get_price();
			$price_max = $price_min;
		}
		if ( '' !== $price_min && function_exists( 'wc_format_decimal' ) ) {
			$decimals  = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
			$price_min = wc_format_decimal( $price_min, $decimals );
			$price_max = '' !== $price_max ? wc_format_decimal( $price_max, $decimals ) : $price_min;
		}

		$gtin = '';
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = (string) $product->get_global_unique_id();
		}

		$faq = array();
		if ( class_exists( 'TWTAEO_Product_FAQ' ) && method_exists( 'TWTAEO_Product_FAQ', 'collect' ) ) {
			$pairs = TWTAEO_Product_FAQ::collect( $id );
			if ( is_array( $pairs ) ) {
				foreach ( $pairs as $pair ) {
					if ( is_array( $pair ) && isset( $pair['question'], $pair['answer'] ) ) {
						$faq[] = array(
							'question' => self::plain( $pair['question'] ),
							'answer'   => self::plain( $pair['answer'] ),
						);
					}
				}
			}
		}

		$short = self::plain( $product->get_short_description() );
		$full  = self::markdown( $product->get_description() );

		return self::build_product( array(
			'title'        => self::decode( $product->get_name() ),
			'slug'         => $product->get_slug(),
			'description'  => '' !== $full ? $full : $short,
			'url'          => get_permalink( $id ),
			'brand'        => $brand,
			'product_type' => implode( ', ', $cat_names ),
			'tags'         => $tags,
			'sku'          => (string) $product->get_sku(),
			'gtin'         => $gtin,
			'availability' => $product->is_in_stock() ? 'In stock' : 'Out of stock',
			'price_min'    => $price_min,
			'price_max'    => $price_max,
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'attributes'   => $attributes,
			'collections'  => $collections,
			'faq'          => $faq,
		) );
	}

	/**
	 * @param string $slug
	 * @return string|null
	 */
	public static function render_collection( $slug ) {
		if ( ! self::has_woo() ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		$products = wc_get_products( array(
			'status'   => 'publish',
			'limit'    => self::COLLECTION_PRODUCTS,
			'category' => array( $term->slug ),
			'orderby'  => 'title',
			'order'    => 'ASC',
			'return'   => 'objects',
		) );
		$list = array();
		foreach ( (array) $products as $p ) {
			if ( is_object( $p ) && method_exists( $p, 'get_slug' ) ) {
				$list[] = array(
					'title' => self::decode( $p->get_name() ),
					'slug'  => $p->get_slug(),
				);
			}
		}
		$link = get_term_link( $term );
		return self::build_collection( array(
			'title'       => self::decode( $term->name ),
			'slug'        => $term->slug,
			'description' => self::plain( $term->description ),
			'url'         => is_wp_error( $link ) ? '' : $link,
			'products'    => $list,
		) );
	}

	/**
	 * @param string $slug
	 * @return string|null
	 */
	public static function render_post( $slug ) {
		$posts = get_posts( array(
			'name'           => $slug,
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		) );
		if ( ! $posts ) {
			return null;
		}
		$p = $posts[0];

		$tags = wp_get_post_terms( $p->ID, 'post_tag', array( 'fields' => 'names' ) );
		$cats = wp_get_post_terms( $p->ID, 'category', array( 'fields' => 'names' ) );
		$cats = is_array( $cats ) ? array_values( array_diff( $cats, array( 'Uncategorized' ) ) ) : array();

		// Core the_content filter, applied via a variable hook name (core's, not ours).
		$core_filter = 'the_content';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hook, not ours to prefix.
		$rendered = apply_filters( $core_filter, $p->post_content );

		return self::build_post( array(
			'title'       => self::decode( get_the_title( $p ) ),
			'slug'        => $p->post_name,
			'description' => self::post_excerpt( $p ),
			'url'         => get_permalink( $p ),
			'tags'        => is_array( $tags ) ? array_map( array( __CLASS__, 'decode' ), $tags ) : array(),
			'categories'  => array_map( array( __CLASS__, 'decode' ), $cats ),
			'author'      => self::decode( get_the_author_meta( 'display_name', (int) $p->post_author ) ),
			'published'   => get_the_date( 'Y-m-d', $p ),
			'modified'    => get_the_modified_date( 'Y-m-d', $p ),
			'body'        => self::markdown( $rendered ),
		) );
	}

	/**
	 * @param string $slug
	 * @return string|null
	 */
	public static function render_policy( $slug ) {
		$types = self::policy_types();
		if ( ! isset( $types[ $slug ] ) ) {
			return null;
		}
		$post = self::find_policy_page( $slug );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$body = self::policy_text( strip_shortcodes( $post->post_content ) );
		if ( '' === $body ) {
			return null;
		}
		return self::build_policy( array(
			'label' => $types[ $slug ],
			'body'  => $body,
			'url'   => get_permalink( $post ),
		) );
	}
}
