<?php
/**
 * AI Visibility — allocation arithmetic and the question families (pure half).
 *
 * Ported from the Shopify app's `aiVisibility.ts` (AEO Ultimate, 2026-08-18)
 * with one addition: the `post` level for WordPress posts.
 *
 * Everything here is deterministic and runs WITHOUT WordPress loaded — no WP
 * functions, no time(), no rand(). The same input always yields the same
 * questions with the same ids, which is what lets a run in September be
 * compared with a run in August.
 *
 * Rules the families follow (docs/ai-visibility-plan.md):
 *
 * - Fill from data we HOLD. A family that needs a fact we do not have for this
 *   site (no shipping policy, no ships-to list, no brand) is skipped, never
 *   filled with a guess. "If the allocation exceeds what the data can fill,
 *   fill fewer."
 * - A `truth` is attached only where the plugin can state the fact in one plain
 *   sentence from site data. That sentence is what the accuracy judge compares
 *   the assistant's answer against, so it must be literally true.
 * - Question ids are "family:scope_id:n" and the order within a scope is fixed,
 *   so a merchant's enable/disable edits and the trend line both survive
 *   re-generation.
 *
 * QuestionInput shape (assoc arrays, every key optional unless noted):
 *
 *   site        = [ name, hosts[], brands[], x_handle ]
 *   policies    = [ refund, shipping, privacy ]
 *   ships_to    = [ 'Canada', ... ]                       display names, not codes
 *   collections = [ [ id, title, slug, product_ids[] ] ]  WooCommerce product categories
 *   types       = [ [ name, count ] ]
 *   products    = [ [ id, title, brand, slug, type, options[ [ name, values[] ] ], price[ amount, currency ]|null, available|null, url, is_gift_card ] ]
 *   posts       = [ [ id, title, slug, url, categories[], tags[], headings[], excerpt, date ] ]
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Questions {

	/** Bumped when a family's wording changes, so a trend line can be annotated. */
	const TEMPLATE_VERSION = 1;

	/** A policy truth is the first N characters of the policy text. */
	const POLICY_TRUTH_CHARS = 400;

	/** Symbols for the currencies merchants mostly sell in; others print as "100 SEK". */
	const CURRENCY_SYMBOL = array(
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'NZD' => 'NZ$',
		'JPY' => '¥',
		'INR' => '₹',
	);

	/** Fallback site name when the input carries none. */
	const FALLBACK_SITE_NAME = 'this site';

	private function __construct() {}

	/* ──────────────────────────── allocation ──────────────────────────── */

	/**
	 * Questions and checks an allocation produces for a site of this size.
	 *
	 * @param array $alloc  [ company, per_collection, per_type, per_product, per_post, engines[] ].
	 * @param array $counts [ collections, types, products, posts ].
	 * @return array [ questions => int, checks => int, by_level => [ company, collection, type, product, post ] ].
	 */
	public static function allocation_totals( array $alloc, array $counts ) {
		$by_level = array(
			'company'    => self::int_at_least_zero( self::get( $alloc, 'company' ) ),
			'brand'      => self::int_at_least_zero( self::get( $alloc, 'per_brand' ) ) * self::int_at_least_zero( self::get( $counts, 'brands' ) ),
			'collection' => self::int_at_least_zero( self::get( $alloc, 'per_collection' ) ) * self::int_at_least_zero( self::get( $counts, 'collections' ) ),
			'type'       => self::int_at_least_zero( self::get( $alloc, 'per_type' ) ) * self::int_at_least_zero( self::get( $counts, 'types' ) ),
			'product'    => self::int_at_least_zero( self::get( $alloc, 'per_product' ) ) * self::int_at_least_zero( self::get( $counts, 'products' ) ),
			'post'       => self::int_at_least_zero( self::get( $alloc, 'per_post' ) ) * self::int_at_least_zero( self::get( $counts, 'posts' ) ),
		);
		$questions = 0;
		foreach ( $by_level as $n ) {
			$questions += $n;
		}
		$engines = self::get( $alloc, 'engines' );
		$engines = is_array( $engines ) ? count( $engines ) : 0;
		return array(
			'questions' => $questions,
			'checks'    => $questions * $engines,
			'by_level'  => $by_level,
		);
	}

	/**
	 * A preset's numbers plus the chosen engines, clamped. Unknown preset = standard.
	 *
	 * @param string $name    light | standard | deep.
	 * @param array  $engines Engine ids.
	 * @return array Allocation.
	 */
	public static function apply_preset( $name, array $engines ) {
		$presets = TWTAEO_Visibility_Types::PRESETS;
		$preset  = isset( $presets[ $name ] ) ? $presets[ $name ] : $presets['standard'];
		$preset['engines'] = $engines;
		return self::clamp_allocation( $preset );
	}

	/**
	 * Non-negative integers within ALLOCATION_MAX; engines de-duplicated and known.
	 *
	 * @param array $a Partial allocation.
	 * @return array Allocation with every key present.
	 */
	public static function clamp_allocation( array $a ) {
		$max     = TWTAEO_Visibility_Types::ALLOCATION_MAX;
		$known   = TWTAEO_Visibility_Types::ENGINES;
		$engines = array();
		$given   = self::get( $a, 'engines' );
		if ( is_array( $given ) ) {
			foreach ( $given as $e ) {
				if ( ! is_string( $e ) ) {
					continue;
				}
				if ( isset( $known[ $e ] ) && ! in_array( $e, $engines, true ) ) {
					$engines[] = $e;
				}
			}
		}
		return array(
			'company'        => self::clamp_count( self::get( $a, 'company' ), $max['company'] ),
			'per_brand'      => self::clamp_count( self::get( $a, 'per_brand' ), $max['per_brand'] ),
			'per_collection' => self::clamp_count( self::get( $a, 'per_collection' ), $max['per_collection'] ),
			'per_type'       => self::clamp_count( self::get( $a, 'per_type' ), $max['per_type'] ),
			'per_product'    => self::clamp_count( self::get( $a, 'per_product' ), $max['per_product'] ),
			'per_post'       => self::clamp_count( self::get( $a, 'per_post' ), $max['per_post'] ),
			'engines'        => $engines,
		);
	}

	/* ─────────────────────────────── text ──────────────────────────────── */

	/**
	 * Plain text: tags out, entities for the common few, whitespace collapsed.
	 *
	 * @param mixed $value Anything stringable.
	 * @return string
	 */
	public static function strip_html( $value ) {
		$s = self::to_string( $value );
		$s = preg_replace( '/<[^>]*>/', ' ', $s );
		$s = preg_replace( '/&nbsp;/i', ' ', $s );
		$s = preg_replace( '/&amp;/i', '&', $s );
		$s = preg_replace( '/&quot;/i', '"', $s );
		$s = preg_replace( '/&#39;|&apos;/i', "'", $s );
		$s = preg_replace( '/&lt;/i', '<', $s );
		$s = preg_replace( '/&gt;/i', '>', $s );
		$s = preg_replace( '/\s+/', ' ', $s );
		return trim( $s );
	}

	/**
	 * "under $50" — a nice round ceiling at or above the median price.
	 *
	 * @param array|float $amounts  Prices (numbers or numeric strings); a scalar is accepted.
	 * @param string      $currency ISO code.
	 * @return string|null Null when no usable price.
	 */
	public static function price_band( $amounts, $currency ) {
		if ( ! is_array( $amounts ) ) {
			$amounts = array( $amounts );
		}
		$values = array();
		foreach ( $amounts as $n ) {
			if ( ! is_numeric( $n ) ) {
				continue;
			}
			$f = (float) $n;
			if ( ! is_finite( $f ) || $f <= 0 ) {
				continue;
			}
			$values[] = $f;
		}
		if ( empty( $values ) ) {
			return null;
		}
		sort( $values );
		$median = $values[ (int) floor( ( count( $values ) - 1 ) / 2 ) ];
		if ( $median < 20 ) {
			$step = 5;
		} elseif ( $median < 100 ) {
			$step = 10;
		} elseif ( $median < 500 ) {
			$step = 50;
		} elseif ( $median < 2000 ) {
			$step = 100;
		} else {
			$step = 500;
		}
		$ceiling = max( $step, (int) ceil( $median / $step ) * $step );
		$code    = strtoupper( trim( self::to_string( $currency ) ) );
		$symbols = self::CURRENCY_SYMBOL;
		if ( isset( $symbols[ $code ] ) ) {
			return $symbols[ $code ] . $ceiling;
		}
		return trim( $ceiling . ' ' . $code );
	}

	/* ───────────────────────────── questions ───────────────────────────── */

	/**
	 * Fill the families from site data. Deterministic: same input → same ids in
	 * the same order. Every question is enabled and source "template"; the
	 * merchant's edits are applied by the caller.
	 *
	 * @param array $input QuestionInput (see file docblock).
	 * @param array $alloc Allocation.
	 * @return array Question[]
	 */
	public static function build_questions( array $input, array $alloc ) {
		$site_arr = self::get( $input, 'site' );
		$site_arr = is_array( $site_arr ) ? $site_arr : array();
		$site     = self::strip_html( self::get( $site_arr, 'name' ) );
		if ( '' === $site ) {
			$site = self::FALLBACK_SITE_NAME;
		}
		$products    = self::list_of( $input, 'products' );
		$collections = self::list_of( $input, 'collections' );
		$types       = self::list_of( $input, 'types' );
		$posts       = self::list_of( $input, 'posts' );
		$out         = array();

		$company_take = self::int_at_least_zero( self::get( $alloc, 'company' ) );
		if ( $company_take > 0 ) {
			$out = array_merge( $out, self::emit( 'company', '', $site, self::company_drafts( $input, $site, $products, $types ), $company_take ) );
		}

		// Tracked brands, asked about by name. Without these a brand item is
		// purely passive — it can only be spotted in answers to questions about
		// something else, so a brand nobody happens to mention reads as absent
		// when in truth it was never asked about.
		$per_brand = self::int_at_least_zero( self::get( $alloc, 'per_brand' ) );
		if ( $per_brand > 0 ) {
			$items = self::get( $site_arr, 'brand_items' );
			$items = is_array( $items ) ? $items : array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || ( isset( $item['enabled'] ) && ! $item['enabled'] ) ) {
					continue;
				}
				$label = self::strip_html( self::get( $item, 'label' ) );
				if ( '' === $label ) {
					continue;
				}
				// scope_id is a slug of the label: there is no numeric id to lean
				// on, and question ids must stay stable so trends stay comparable.
				$scope_id = self::brand_scope_id( $label );
				$out      = array_merge( $out, self::emit( 'brand', $scope_id, $label, self::brand_drafts( $site, $label ), $per_brand ) );
			}
		}

		// Product prices, by id, for the catalogue price bands.
		$price_of = array();
		foreach ( $products as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$price = self::get( $p, 'price' );
			if ( is_array( $price ) && is_numeric( self::get( $price, 'amount' ) ) ) {
				$n = (float) $price['amount'];
				if ( is_finite( $n ) && $n > 0 ) {
					$price_of[ (string) self::get( $p, 'id' ) ] = array(
						'amount' => $n,
						'code'   => self::to_string( self::get( $price, 'currency' ) ),
					);
				}
			}
		}

		$per_collection = self::int_at_least_zero( self::get( $alloc, 'per_collection' ) );
		if ( $per_collection > 0 ) {
			foreach ( $collections as $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$title = self::strip_html( self::get( $c, 'title' ) );
				if ( '' === $title ) {
					continue;
				}
				$ids  = self::get( $c, 'product_ids' );
				$band = self::band_for( is_array( $ids ) ? $ids : array(), $price_of );
				$out  = array_merge(
					$out,
					self::emit( 'collection', (string) self::get( $c, 'id' ), $title, self::catalog_drafts( $site, $title, $band, false ), $per_collection )
				);
			}
		}

		$per_type = self::int_at_least_zero( self::get( $alloc, 'per_type' ) );
		if ( $per_type > 0 ) {
			foreach ( $types as $t ) {
				if ( ! is_array( $t ) ) {
					continue;
				}
				$name = self::strip_html( self::get( $t, 'name' ) );
				if ( '' === $name ) {
					continue;
				}
				$ids = array();
				foreach ( $products as $p ) {
					if ( is_array( $p ) && self::norm( self::get( $p, 'type' ) ) === self::norm( $name ) ) {
						$ids[] = (string) self::get( $p, 'id' );
					}
				}
				$band = self::band_for( $ids, $price_of );
				$out  = array_merge( $out, self::emit( 'type', $name, $name, self::catalog_drafts( $site, $name, $band, true ), $per_type ) );
			}
		}

		$per_product = self::int_at_least_zero( self::get( $alloc, 'per_product' ) );
		if ( $per_product > 0 ) {
			foreach ( $products as $p ) {
				if ( ! is_array( $p ) || self::is_gift_card( $p ) ) {
					continue;
				}
				$title = self::strip_html( self::get( $p, 'title' ) );
				if ( '' === $title ) {
					continue;
				}
				$out = array_merge(
					$out,
					self::emit( 'product', (string) self::get( $p, 'id' ), $title, self::product_drafts( $site, $site_arr, $p ), $per_product )
				);
			}
		}

		$per_post = self::int_at_least_zero( self::get( $alloc, 'per_post' ) );
		if ( $per_post > 0 ) {
			foreach ( $posts as $post ) {
				if ( ! is_array( $post ) ) {
					continue;
				}
				$title = self::strip_html( self::get( $post, 'title' ) );
				if ( '' === $title ) {
					continue;
				}
				$out = array_merge(
					$out,
					self::emit( 'post', (string) self::get( $post, 'id' ), $title, self::post_drafts( $site, $post, $title ), $per_post )
				);
			}
		}

		return $out;
	}

	/**
	 * `is_site_name` semantics: the brand/vendor is the site itself, so it is not a brand.
	 * Compares against the site name and against each host's first label.
	 *
	 * @param string $candidate Brand text.
	 * @param string $site_name Site name.
	 * @param array  $hosts     Site hosts.
	 * @return bool
	 */
	public static function is_site_name( $candidate, $site_name, array $hosts ) {
		$c = self::norm( $candidate );
		if ( '' === $c ) {
			return false;
		}
		if ( $c === self::norm( $site_name ) ) {
			return true;
		}
		foreach ( $hosts as $host ) {
			$parts  = explode( '.', self::to_string( $host ) );
			$handle = isset( $parts[0] ) ? $parts[0] : '';
			if ( '' !== $handle && $c === self::norm( $handle ) ) {
				return true;
			}
		}
		return false;
	}

	/* ───────────────────────────── drafts ──────────────────────────────── */

	/**
	 * Turn drafts into Questions with deterministic ids "family:scope_id:n".
	 *
	 * @param string $level       Level.
	 * @param string $scope_id    Scope id ("" for company).
	 * @param string $scope_label Human label.
	 * @param array  $drafts      [ [ family, text, truth? ] ].
	 * @param int    $take        How many to emit.
	 * @return array Question[]
	 */
	private static function emit( $level, $scope_id, $scope_label, array $drafts, $take ) {
		$out        = array();
		$per_family = array();
		$take       = max( 0, (int) $take );
		foreach ( array_slice( $drafts, 0, $take ) as $draft ) {
			$family = $draft['family'];
			$n      = isset( $per_family[ $family ] ) ? $per_family[ $family ] : 0;
			$per_family[ $family ] = $n + 1;
			$out[] = array(
				'id'          => $family . ':' . $scope_id . ':' . $n,
				'level'       => $level,
				'scope_id'    => $scope_id,
				'scope_label' => $scope_label,
				'family'      => $family,
				'text'        => $draft['text'],
				'source'      => 'template',
				'truth'       => isset( $draft['truth'] ) ? $draft['truth'] : null,
				'enabled'     => true,
			);
		}
		return $out;
	}

	private static function draft( $family, $text, $truth = null ) {
		$d = array(
			'family' => $family,
			'text'   => $text,
		);
		if ( null !== $truth ) {
			$d['truth'] = $truth;
		}
		return $d;
	}

	private static function fact( $kind, $statement ) {
		return array(
			'kind'      => $kind,
			'statement' => $statement,
		);
	}

	/** A stable, url-safe scope id from a brand label. */
	public static function brand_scope_id( $label ) {
		$slug = strtolower( self::to_string( $label ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );
		return 'brand-' . ( '' === $slug ? 'x' : $slug );
	}

	/**
	 * Brand families, in priority order — the questions a person actually asks
	 * about a product by name. Deliberately not "where do I buy" first: the
	 * earliest ones are the ones an assistant answers by naming or linking
	 * somebody, which is exactly what the verdict measures.
	 *
	 * @param string $site  Site name, for the comparison question.
	 * @param string $brand Brand label.
	 * @return array Draft[].
	 */
	private static function brand_drafts( $site, $brand ) {
		$b = self::to_string( $brand );
		return array(
			self::draft( 'brand_what', sprintf( 'What is %s?', $b ) ),
			self::draft( 'brand_who', sprintf( 'Who makes %s?', $b ) ),
			self::draft( 'brand_good', sprintf( 'Is %s any good?', $b ) ),
			self::draft( 'brand_alternatives', sprintf( 'What are the best alternatives to %s?', $b ) ),
			self::draft( 'brand_reviews', sprintf( 'What do reviews say about %s?', $b ) ),
			self::draft( 'brand_features', sprintf( 'What does %s do?', $b ) ),
			self::draft( 'brand_pricing', sprintf( 'How much does %s cost?', $b ) ),
			self::draft( 'brand_where', sprintf( 'Where can I get %s?', $b ) ),
			self::draft( 'brand_trust', sprintf( 'Is %s legitimate and safe to use?', $b ) ),
			self::draft( 'brand_for_whom', sprintf( 'Who is %s for?', $b ) ),
			self::draft( 'brand_compare', sprintf( 'How does %s compare to its competitors?', $b ) ),
			self::draft( 'brand_support', sprintf( 'What support and documentation does %s offer?', $b ) ),
			self::draft( 'brand_start', sprintf( 'How do I get started with %s?', $b ) ),
			self::draft( 'brand_problems', sprintf( 'What are the common complaints about %s?', $b ) ),
			self::draft( 'brand_worth', sprintf( 'Is %s worth it?', $b ) ),
			self::draft( 'brand_free', sprintf( 'Is there a free version of %s?', $b ) ),
			self::draft( 'brand_latest', sprintf( 'What is new in %s?', $b ) ),
			self::draft( 'brand_site', sprintf( 'What is the official website for %s?', $b ) ),
			self::draft( 'brand_socials', sprintf( 'Where can I follow %s online?', $b ) ),
			self::draft( 'brand_maker', sprintf( 'Is %s made by %s?', $b, self::to_string( $site ) ) ),
		);
	}

	/**
	 * Company families, in priority order. Take the first `company`.
	 */
	private static function company_drafts( array $input, $site, array $products, array $types ) {
		$site_arr     = self::get( $input, 'site' );
		$site_arr     = is_array( $site_arr ) ? $site_arr : array();
		$hosts        = self::get( $site_arr, 'hosts' );
		$hosts        = is_array( $hosts ) ? $hosts : array();
		$policies     = self::get( $input, 'policies' );
		$policies     = is_array( $policies ) ? $policies : array();
		$has_products = count( $products ) > 0;
		$drafts       = array();

		$drafts[] = self::draft( 'legit', $has_products ? "Is {$site} a legitimate store?" : "Is {$site} a legitimate website?" );
		$drafts[] = self::draft( 'reviews', "What do customers say about {$site}?" );

		$refund = self::strip_html( self::get( $policies, 'refund' ) );
		if ( '' !== $refund ) {
			$drafts[] = self::draft(
				'returns',
				"What is {$site}'s return policy?",
				self::fact( 'policy_refund', TWTAEO_Visibility_Verdict::excerpt_of( $refund, self::POLICY_TRUTH_CHARS ) )
			);
		}

		$countries = array();
		foreach ( self::list_of( $input, 'ships_to' ) as $country ) {
			$clean = self::strip_html( $country );
			if ( '' !== $clean ) {
				$countries[] = $clean;
			}
			if ( count( $countries ) >= 5 ) {
				break;
			}
		}
		foreach ( $countries as $country ) {
			$drafts[] = self::draft(
				'shipping',
				"Does {$site} ship to {$country}?",
				self::fact( 'ships_to', "Yes — {$site} ships to {$country}" )
			);
		}

		$shipping = self::strip_html( self::get( $policies, 'shipping' ) );
		if ( '' !== $shipping ) {
			$drafts[] = self::draft(
				'shipping_policy',
				"How long does shipping from {$site} take?",
				self::fact( 'policy_shipping', TWTAEO_Visibility_Verdict::excerpt_of( $shipping, self::POLICY_TRUTH_CHARS ) )
			);
		}

		$brands = array();
		$seen   = array();
		$given  = self::get( $site_arr, 'brands' );
		foreach ( is_array( $given ) ? $given : array() as $brand ) {
			$clean = self::strip_html( $brand );
			if ( '' === $clean || self::is_site_name( $clean, self::get( $site_arr, 'name' ), $hosts ) ) {
				continue;
			}
			$key = self::norm( $clean );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$brands[]     = $clean;
			if ( count( $brands ) >= 6 ) {
				break;
			}
		}
		foreach ( $brands as $brand ) {
			$drafts[] = self::draft( 'brands', "Where can I buy {$brand} online?" );
		}

		$drafts[] = self::draft( 'contact', "How do I contact {$site}?" );
		$drafts[] = self::draft( 'about', $has_products ? "What does {$site} sell?" : "What is {$site} about?" );
		if ( $has_products ) {
			$drafts[] = self::draft( 'trust', "Is it safe to order from {$site}?" );
		}

		$top = array();
		foreach ( $types as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$name = self::strip_html( self::get( $t, 'name' ) );
			if ( '' === $name ) {
				continue;
			}
			$top[] = array(
				'name'  => $name,
				'count' => (int) self::get( $t, 'count' ),
			);
		}
		usort(
			$top,
			function ( $a, $b ) {
				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] - $a['count'];
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);
		foreach ( array_slice( $top, 0, 3 ) as $t ) {
			$drafts[] = self::draft( 'compare', "Is {$site} better than other stores for {$t['name']}?" );
		}
		return $drafts;
	}

	/**
	 * The catalogue families, shared by collections (product categories) and product types.
	 */
	private static function catalog_drafts( $site, $label, $band, $top_brands ) {
		$drafts   = array();
		$drafts[] = self::draft( 'best', "What is the best {$label} to buy?" );
		$drafts[] = self::draft( 'brands', "Which brands make the best {$label}?" );
		$drafts[] = self::draft( 'where', "Where can I buy {$label} online?" );
		$drafts[] = self::draft( 'lookfor', "What should I look for when buying {$label}?" );
		if ( null !== $band && '' !== $band ) {
			$drafts[] = self::draft( 'under', "Best {$label} under {$band}" );
		}
		$drafts[] = self::draft( 'store', "Is {$site} a good place to buy {$label}?" );
		if ( $top_brands ) {
			$drafts[] = self::draft( 'topbrands', "What are the top {$label} brands?" );
		}
		$drafts[] = self::draft( 'beginners', "What {$label} do you recommend for beginners?" );
		$drafts[] = self::draft( 'toprated', "Top-rated {$label} this year" );
		return $drafts;
	}

	/**
	 * Product families. Gift cards are skipped by the caller.
	 */
	private static function product_drafts( $site, array $site_arr, array $p ) {
		$title  = self::strip_html( self::get( $p, 'title' ) );
		$hosts  = self::get( $site_arr, 'hosts' );
		$hosts  = is_array( $hosts ) ? $hosts : array();
		$drafts = array();

		$drafts[] = self::draft( 'good', "Is {$title} good?" );

		$where_truth = null;
		if ( true === self::get( $p, 'available' ) ) {
			$where_truth = self::fact( 'availability', "Yes — {$site} sells {$title}" );
		}
		$drafts[] = self::draft( 'where', "Where can I buy {$title}?", $where_truth );

		foreach ( self::option_values( $p ) as $value ) {
			$drafts[] = self::draft(
				'variant',
				"Does {$site} sell {$title} in {$value}?",
				self::fact( 'variant_option', "Yes — {$site} sells {$title} in {$value}" )
			);
		}

		$price       = self::get( $p, 'price' );
		$price_truth = null;
		if ( is_array( $price ) && is_numeric( self::get( $price, 'amount' ) ) && (float) $price['amount'] > 0 ) {
			$price_truth = self::fact( 'price', self::format_price( $price ) . " at {$site}" );
		}
		$drafts[] = self::draft( 'price', "How much does {$title} cost?", $price_truth );

		$brand = self::strip_html( self::get( $p, 'brand' ) );
		if ( '' !== $brand && ! self::is_site_name( $brand, self::get( $site_arr, 'name' ), $hosts ) ) {
			$drafts[] = self::draft( 'review', "{$brand} {$title} review" );
		}

		$drafts[] = self::draft( 'vs', "{$title} vs alternatives" );
		return $drafts;
	}

	/**
	 * Post families (the WordPress addition). Informational questions a reader
	 * would ask an assistant, filled from the post's own title, headings and
	 * categories. Take the first `per_post`.
	 *
	 * Truth: only `post_claim`, only when the post has an excerpt — attached to
	 * the `from` family because "what does {site} say about X" is the question
	 * that claim answers.
	 */
	private static function post_drafts( $site, array $post, $title ) {
		$drafts = array();
		$clean  = self::strip_trailing_punct( $title );

		if ( preg_match( '/^(how to|how do|what is|why)\b/i', $clean ) ) {
			// The title already reads as a question; ask it the way a reader would.
			if ( preg_match( '/^how to\b\s*(.*)$/i', $clean, $m ) ) {
				$rest = trim( $m[1] );
				$text = 'How do I ' . self::lower( $rest ) . '?';
			} elseif ( preg_match( '/^how do\b\s*(?:(?:i|you|we)\b\s*)?(.*)$/i', $clean, $m ) ) {
				$rest = trim( $m[1] );
				$text = 'How do I ' . self::lower( $rest ) . '?';
			} else {
				$text = $clean . '?';
			}
			$text     = preg_replace( '/\s+\?$/', '?', $text );
			$drafts[] = self::draft( 'howto', $text );
		} else {
			$drafts[] = self::draft( 'what', "What is {$clean}?" );
		}

		$headings = self::get( $post, 'headings' );
		if ( is_array( $headings ) ) {
			foreach ( $headings as $h ) {
				if ( is_array( $h ) ) {
					$h = self::get( $h, 'text' );
				}
				$h = self::strip_html( $h );
				if ( '' !== $h ) {
					$drafts[] = self::draft( 'explain', 'Can you explain ' . self::strip_trailing_punct( $h ) . '?' );
					break;
				}
			}
		}

		if ( preg_match( '/\s(vs\.?|or)\s/i', $clean ) ) {
			$drafts[] = self::draft( 'vs', "{$clean} vs alternatives" );
		}

		$categories = self::get( $post, 'categories' );
		if ( is_array( $categories ) ) {
			foreach ( $categories as $cat ) {
				if ( is_array( $cat ) ) {
					$cat = self::get( $cat, 'name' );
				}
				$cat = self::strip_html( $cat );
				if ( '' !== $cat ) {
					$drafts[] = self::draft( 'best', "What are the best {$cat} tips?" );
					break;
				}
			}
		}

		$truth   = null;
		$excerpt = self::strip_html( self::get( $post, 'excerpt' ) );
		if ( '' !== $excerpt ) {
			$date      = self::strip_html( self::get( $post, 'date' ) );
			$statement = "{$site} published \"{$title}\"";
			if ( '' !== $date ) {
				$statement .= " on {$date}";
			}
			$truth = self::fact( 'post_claim', $statement );
		}
		$drafts[] = self::draft( 'from', "What does {$site} say about {$clean}?", $truth );

		return $drafts;
	}

	/* ───────────────────────────── helpers ─────────────────────────────── */

	private static function band_for( array $ids, array $price_of ) {
		$amounts = array();
		$code    = '';
		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( ! isset( $price_of[ $id ] ) ) {
				continue;
			}
			if ( empty( $amounts ) ) {
				$code = $price_of[ $id ]['code'];
			}
			$amounts[] = $price_of[ $id ]['amount'];
		}
		if ( empty( $amounts ) ) {
			return null;
		}
		return self::price_band( $amounts, $code );
	}

	private static function format_price( array $price ) {
		$raw  = self::get( $price, 'amount' );
		$code = strtoupper( trim( self::to_string( self::get( $price, 'currency' ) ) ) );
		if ( is_numeric( $raw ) ) {
			$n = (float) $raw;
			if ( floor( $n ) === $n ) {
				$amount = (string) (int) $n;
			} else {
				$amount = number_format( $n, 2, '.', '' );
			}
		} else {
			$amount = self::to_string( $raw );
		}
		return trim( $amount . ' ' . $code );
	}

	private static function is_gift_card( array $p ) {
		if ( ! empty( $p['is_gift_card'] ) ) {
			return true;
		}
		return (bool) preg_match( '/gift\s*card/i', self::to_string( self::get( $p, 'type' ) ) );
	}

	/** Option values worth asking about — never the "Default Title" / "Any" placeholders. */
	private static function option_values( array $p ) {
		$values  = array();
		$options = self::get( $p, 'options' );
		if ( ! is_array( $options ) ) {
			return $values;
		}
		foreach ( array_slice( $options, 0, 2 ) as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			if ( 'title' === self::norm( self::get( $option, 'name' ) ) ) {
				continue;
			}
			$list = self::get( $option, 'values' );
			foreach ( is_array( $list ) ? $list : array() as $v ) {
				$clean = self::strip_html( $v );
				$key   = self::norm( $clean );
				if ( '' === $clean || 'default title' === $key || 'any' === $key ) {
					continue;
				}
				if ( ! in_array( $clean, $values, true ) ) {
					$values[] = $clean;
				}
				if ( count( $values ) >= 3 ) {
					return $values;
				}
			}
		}
		return $values;
	}

	/** Lowercase, non-alphanumerics to single spaces, trimmed. */
	private static function norm( $s ) {
		$s = strtolower( self::to_string( $s ) );
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return trim( $s );
	}

	private static function lower( $s ) {
		$s = self::to_string( $s );
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $s, 'UTF-8' );
		}
		return strtolower( $s );
	}

	private static function strip_trailing_punct( $s ) {
		return rtrim( trim( self::to_string( $s ) ), " \t\n\r?!.:;" );
	}

	private static function clamp_count( $value, $max ) {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		$n = (float) $value;
		if ( ! is_finite( $n ) ) {
			return 0;
		}
		return (int) min( $max, max( 0, floor( $n ) ) );
	}

	private static function int_at_least_zero( $value ) {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		$n = (float) $value;
		if ( ! is_finite( $n ) ) {
			return 0;
		}
		return max( 0, (int) $n );
	}

	private static function list_of( array $input, $key ) {
		$v = self::get( $input, $key );
		return is_array( $v ) ? array_values( $v ) : array();
	}

	private static function get( array $arr, $key ) {
		return isset( $arr[ $key ] ) ? $arr[ $key ] : null;
	}

	private static function to_string( $value ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return (string) $value;
	}
}
