<?php
/**
 * TWT AEO Product Enricher
 *
 * Per-product, on-demand AI enrichment for WooCommerce products. Reads a
 * product's unstructured text and proposes structured schema attributes
 * (color, material, size, weight, dimensions, custom spec pairs). Results are
 * cached to postmeta and only ever *suggested* in the modal — nothing is written
 * to schema until the merchant reviews and saves.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Product_Enricher {

	/** Cached AI attribute suggestions: { provider, time, data }. */
	const META_ATTRIBUTES = '_twtaeo_ai_attributes';

	/** Cached AI vision suggestions: { provider, time, data }. */
	const META_VISION = '_twtaeo_ai_vision';

	/** Cached Google Product Taxonomy resolution: { provider, time, data:{ code, path } }. */
	const META_GCATEGORY = '_twtaeo_ai_google_category';

	/**
	 * Official Google Product Taxonomy code set (numeric-id edition). Emitted as the
	 * `inCodeSet` of the CategoryCode object so the id is unambiguously resolvable.
	 */
	const GOOGLE_TAXONOMY_URL = 'https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt';

	/**
	 * Extract structured attributes from a product's text via the AI client.
	 *
	 * @param int         $post_id
	 * @param string|null $provider Override; defaults to the enrichment provider.
	 * @return array|WP_Error  Normalized suggestions { color, material, size, additional[] }.
	 */
	public static function extract_attributes( $post_id, $provider = null ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return new WP_Error( 'bad_product', __( 'Product not found.', 'twt-aeo-ultimate' ) );
		}

		$source = self::gather_source_text( $post );
		if ( str_word_count( $source ) < 5 ) {
			return new WP_Error( 'thin_content', __( 'Not enough product text to analyze.', 'twt-aeo-ultimate' ) );
		}

		$provider = $provider ?: TWTAEO_AI_Client::enrich_provider();
		$prompt   = self::build_prompt( $source );

		$raw = TWTAEO_AI_Client::complete( $provider, $prompt, array(
			'json'        => true,
			'max_tokens'  => 700,
			'temperature' => 0.1, // Low temperature forces strict factual extraction.
			'system'      => 'You are a structured-data extraction engine for e-commerce products. '
				. 'Return only a raw JSON object matching the requested schema. Do not wrap it in markdown, '
				. 'do not add commentary, and never invent facts that are not present in the source text.',
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$normalized = self::normalize( $data );

		update_post_meta( $post_id, self::META_ATTRIBUTES, array(
			'provider' => $provider,
			'time'     => time(),
			'data'     => $normalized,
		) );

		return $normalized;
	}

	/**
	 * Return cached suggestions for the modal, or null if none.
	 *
	 * @param int $post_id
	 * @return array|null { provider, time, data }
	 */
	public static function get_cached_attributes( $post_id ) {
		$cached = get_post_meta( $post_id, self::META_ATTRIBUTES, true );
		return is_array( $cached ) && ! empty( $cached['data'] ) ? $cached : null;
	}

	// ── Competitive intent aligner ───────────────────────────────────────────────

	/**
	 * Use web-grounded retrieval to surface attributes/specs that competing
	 * listings commonly show for similar products but this one is missing.
	 *
	 * @param int         $post_id
	 * @param string|null $provider Override; defaults to the retrieval provider.
	 * @return array|WP_Error  List of [{ field, reason }].
	 */
	public static function competitive_gaps( $post_id, $provider = null ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return new WP_Error( 'bad_product', __( 'Product not found.', 'twt-aeo-ultimate' ) );
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		$name    = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );

		$cats = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
		$cat  = is_array( $cats ) && ! empty( $cats ) ? implode( ', ', $cats ) : '';

		// What we already expose, so the model only flags genuine gaps.
		$have = array();
		if ( $product ) {
			$attrs = class_exists( 'TWTAEO_WooCommerce_Detector' )
				? TWTAEO_WooCommerce_Detector::detect_attributes( $product )
				: array();
			foreach ( array( 'brand', 'color', 'material', 'size' ) as $k ) {
				if ( ! empty( $attrs[ $k ] ) ) {
					$have[] = ucfirst( $k );
				}
			}
			foreach ( (array) ( $attrs['additional'] ?? array() ) as $row ) {
				$have[] = $row['name'];
			}
		}

		$provider = $provider ?: TWTAEO_AI_Client::retrieval_provider();

		$prompt = "Product: \"$name\".\n"
			. ( $cat ? "Category: $cat.\n" : '' )
			. ( ! empty( $have ) ? 'Attributes we already list: ' . implode( ', ', $have ) . ".\n" : '' )
			. "\nLooking at how comparable products are listed by major retailers and shopping results, identify the "
			. "product attributes, specifications, or trust signals that buyers and shopping engines expect for this "
			. "kind of product but that we are NOT already listing. Focus on structured, factual fields (specs, "
			. "compatibility, certifications, materials, dimensions, capacity, etc.) — not marketing language.\n\n"
			. 'Return strict JSON: {"recommended":[{"field":"","reason":""}]} with at most 6 items, most important '
			. "first. Omit anything already listed above. Output JSON only.";

		$raw = TWTAEO_AI_Client::complete( $provider, $prompt, array(
			'grounding'   => true,
			'max_tokens'  => 700,
			'temperature' => 0.2,
			'system'      => 'You are a competitive merchandising analyst. Use live web knowledge of how similar '
				. 'products are listed. Recommend only concrete, factual attribute fields. Return raw JSON only.',
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$data = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$out = array();
		foreach ( (array) ( $data['recommended'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$field  = self::clean_scalar( $row['field'] ?? '' );
			$reason = self::clean_scalar( $row['reason'] ?? '' );
			if ( '' !== $field ) {
				$out[] = array( 'field' => $field, 'reason' => $reason );
			}
		}
		return $out;
	}

	// ── Dynamic schema standardization ───────────────────────────────────────────

	/**
	 * Map custom WooCommerce attribute names to standard schema.org Product fields
	 * where one applies; clean the rest into concise additionalProperty rows.
	 *
	 * @param array       $rows     Current additionalProperty rows [{name,value}].
	 * @param string|null $provider Override; defaults to the enrichment provider.
	 * @return array|WP_Error  { brand, color, material, size, gtin, mpn, additional[] }
	 */
	public static function standardize_attributes( $rows, $provider = null ) {
		$clean = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$n = self::clean_scalar( $row['name'] ?? '' );
			$v = self::clean_scalar( $row['value'] ?? '' );
			if ( '' !== $n && '' !== $v ) {
				$clean[] = array( 'name' => $n, 'value' => $v );
			}
		}
		if ( empty( $clean ) ) {
			return new WP_Error( 'no_rows', __( 'No custom attributes to standardize.', 'twt-aeo-ultimate' ) );
		}

		$lines = array();
		foreach ( $clean as $r ) {
			$lines[] = $r['name'] . ': ' . $r['value'];
		}

		$prompt = "Here are custom product attributes as name/value pairs:\n- " . implode( "\n- ", $lines ) . "\n\n"
			. "Map any attribute that clearly corresponds to a standard schema.org Product identity field into that "
			. "field: brand, color, material, size, gtin (UPC/EAN/ISBN/GTIN), or mpn. Leave everything else as an "
			. "additionalProperty, but rewrite its name to a clean, concise Title-Case label (fix casing, expand "
			. "obvious abbreviations, drop noise). Never invent or alter values — only re-map and re-label.\n\n"
			. 'Return strict JSON: {"brand":"","color":"","material":"","size":"","gtin":"","mpn":"",'
			. '"additionalProperty":[{"name":"","value":""}]} (omit empty keys). Output JSON only.';

		$provider = $provider ?: TWTAEO_AI_Client::enrich_provider();
		$raw = TWTAEO_AI_Client::complete( $provider, $prompt, array(
			'json'        => true,
			'max_tokens'  => 700,
			'temperature' => 0.1,
			'system'      => 'You are a schema.org mapping engine. Re-map and re-label product attributes only; never '
				. 'change a value. Return raw JSON only.',
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$data = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$out = array(
			'brand'      => self::clean_scalar( $data['brand'] ?? '' ),
			'color'      => self::clean_scalar( $data['color'] ?? '' ),
			'material'   => self::clean_scalar( $data['material'] ?? '' ),
			'size'       => self::clean_scalar( $data['size'] ?? '' ),
			'gtin'       => self::clean_scalar( $data['gtin'] ?? '' ),
			'mpn'        => self::clean_scalar( $data['mpn'] ?? '' ),
			'additional' => array(),
		);
		foreach ( (array) ( $data['additionalProperty'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$n = self::clean_scalar( $row['name'] ?? '' );
			$v = self::clean_scalar( $row['value'] ?? '' );
			if ( '' !== $n && '' !== $v ) {
				$out['additional'][] = array( 'name' => $n, 'value' => $v );
			}
		}
		return $out;
	}

	// ── Brand sameAs (entity resolution) ─────────────────────────────────────────

	/**
	 * Resolve a brand/manufacturer name to verified authority URLs (official site,
	 * Wikipedia, Wikidata) using a web-grounded retrieval provider.
	 *
	 * @param string      $brand
	 * @param string|null $provider Override; defaults to the retrieval provider.
	 * @return string[]|WP_Error  Candidate authority URLs (may be empty).
	 */
	public static function resolve_brand_sameas( $brand, $provider = null ) {
		$brand = trim( wp_strip_all_tags( (string) $brand ) );
		if ( '' === $brand ) {
			return new WP_Error( 'no_brand', __( 'Enter a brand name first.', 'twt-aeo-ultimate' ) );
		}

		$provider = $provider ?: TWTAEO_AI_Client::retrieval_provider();

		$prompt = "Identify the official entity for the product brand or manufacturer named \"$brand\".\n"
			. 'Return strict JSON: {"sameAs":["url", ...]} listing only authoritative URLs that identify this exact '
			. "brand — its official website homepage, its English Wikipedia page, and its Wikidata entity "
			. "(https://www.wikidata.org/wiki/Q...). Include a URL only if you are confident it is the correct entity; "
			. "omit anything uncertain or ambiguous. Return at most 4 URLs. Output JSON only.";

		// Gemini grounding can't combine with forced JSON mime, so rely on the
		// prompt + extract_json for both retrieval providers.
		$raw = TWTAEO_AI_Client::complete( $provider, $prompt, array(
			'grounding'   => true,
			'max_tokens'  => 500,
			'temperature' => 0,
			'system'      => 'You resolve brand and manufacturer names to their verified authority URLs using live web '
				. 'knowledge. Only return URLs you are confident are the correct entity. Never fabricate or guess URLs.',
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$urls = array();
		foreach ( (array) ( $data['sameAs'] ?? array() ) as $u ) {
			$u = esc_url_raw( trim( (string) $u ) );
			if ( '' !== $u && preg_match( '#^https?://#i', $u ) ) {
				$urls[] = $u;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	// ── Google Product Taxonomy resolution ───────────────────────────────────────

	/**
	 * Resolve a product to its official Google Product Category using web-grounded
	 * retrieval, so the page can emit the new schema.org CategoryCode object and
	 * mirror the exact taxonomy value used in the Merchant Center feed.
	 *
	 * @param int         $post_id
	 * @param string|null $provider Override; defaults to the retrieval provider.
	 * @return array|WP_Error  { code:string, path:string } — code is the numeric ID.
	 */
	public static function resolve_google_category( $post_id, $provider = null ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return new WP_Error( 'bad_product', __( 'Product not found.', 'twt-aeo-ultimate' ) );
		}

		$name = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$cats = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
		$cat  = ( is_array( $cats ) && ! empty( $cats ) ) ? implode( ', ', $cats ) : '';

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		$desc    = '';
		if ( $product ) {
			$desc = wp_strip_all_tags( $product->get_short_description() );
			if ( '' === $desc ) {
				$desc = wp_strip_all_tags( $post->post_content );
			}
			$desc = mb_substr( trim( $desc ), 0, 500 );
		}

		$provider = $provider ?: TWTAEO_AI_Client::retrieval_provider();

		$prompt = "Map this product to the single most specific matching category in Google's official "
			. "Product Taxonomy (the taxonomy-with-ids list).\n\n"
			. "Product: \"$name\".\n"
			. ( '' !== $cat ? "Store category: $cat.\n" : '' )
			. ( '' !== $desc ? "Description: $desc\n" : '' )
			. "\nReturn strict JSON: {\"code\":\"\",\"path\":\"\"} where code is the numeric Google Product "
			. "Category ID (e.g. \"2271\") and path is its full category path (e.g. "
			. "\"Apparel & Accessories > Clothing > Shirts & Tops\"). Choose the deepest category that is clearly "
			. "correct — never guess a more specific leaf than the product supports. If you cannot confidently "
			. "identify the category, return {\"code\":\"\",\"path\":\"\"}. Output JSON only.";

		$raw = TWTAEO_AI_Client::complete( $provider, $prompt, array(
			'grounding'   => true,
			'max_tokens'  => 300,
			'temperature' => 0,
			'system'      => 'You classify e-commerce products into Google\'s official Product Taxonomy. Use live web '
				. 'knowledge of the current taxonomy. Return only the numeric ID and full path of a category you are '
				. 'confident matches. Never invent an ID. Return raw JSON only.',
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$code = preg_replace( '/\D/', '', self::clean_scalar( $data['code'] ?? '' ) );
		$path = self::clean_scalar( $data['path'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error( 'no_category', __( 'Could not confidently match a Google Product Category.', 'twt-aeo-ultimate' ) );
		}

		$result = array( 'code' => $code, 'path' => $path );
		update_post_meta( $post_id, self::META_GCATEGORY, array(
			'provider' => $provider,
			'time'     => time(),
			'data'     => $result,
		) );

		return $result;
	}

	/**
	 * Return the cached Google Product Category resolution, or null if none.
	 *
	 * @param int $post_id
	 * @return array|null { provider, time, data:{ code, path } }
	 */
	public static function get_cached_google_category( $post_id ) {
		$cached = get_post_meta( $post_id, self::META_GCATEGORY, true );
		return ( is_array( $cached ) && ! empty( $cached['data']['code'] ) ) ? $cached : null;
	}

	// ── Description enhancement ──────────────────────────────────────────────────

	/**
	 * Rewrite a product description into a clearer, information-dense version for
	 * shoppers and AI shopping agents — grounded strictly in known facts.
	 *
	 * @param int         $post_id
	 * @param string      $current  The text currently in the modal (may be edited).
	 * @param string|null $provider Override; defaults to the enrichment provider.
	 * @return string|WP_Error  The rewritten description.
	 */
	public static function enhance_description( $post_id, $current = '', $provider = null ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return new WP_Error( 'bad_product', __( 'Product not found.', 'twt-aeo-ultimate' ) );
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;

		$current = trim( wp_strip_all_tags( (string) $current ) );
		if ( '' === $current && $product ) {
			$short   = wp_strip_all_tags( $product->get_short_description() );
			$current = $short ?: wp_strip_all_tags( $post->post_content );
		}

		// Ground the rewrite in known facts so the model can't invent specs.
		$name  = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$facts = array();
		if ( $product && class_exists( 'TWTAEO_WooCommerce_Detector' ) ) {
			$attrs = TWTAEO_WooCommerce_Detector::detect_attributes( $product );
			foreach ( array( 'brand', 'color', 'material', 'size' ) as $k ) {
				if ( ! empty( $attrs[ $k ] ) ) {
					$facts[] = ucfirst( $k ) . ': ' . $attrs[ $k ];
				}
			}
			foreach ( (array) ( $attrs['additional'] ?? array() ) as $row ) {
				$facts[] = $row['name'] . ': ' . $row['value'];
			}
		}

		if ( '' === $current && empty( $facts ) ) {
			return new WP_Error( 'thin_content', __( 'Not enough product information to enhance.', 'twt-aeo-ultimate' ) );
		}

		$prompt = "Product name: \"$name\".\n";
		if ( $current !== '' ) {
			$prompt .= "Current description:\n" . mb_substr( $current, 0, 4000 ) . "\n\n";
		}
		if ( ! empty( $facts ) ) {
			$prompt .= "Known attributes (use only these — do not invent others):\n- " . implode( "\n- ", $facts ) . "\n\n";
		}
		$prompt .= "Rewrite the description so it is clear, specific, and useful to a shopper and to an AI shopping "
			. "assistant. Lead with what the product is and its most relevant features. Keep it factual and concise "
			. "(2-4 sentences). Do not add a heading or the product name as a title.";

		$provider = $provider ?: TWTAEO_AI_Client::enrich_provider();
		$raw = TWTAEO_AI_Client::complete( $provider, $prompt, array(
			'max_tokens'  => 400,
			'temperature' => 0.5,
			'system'      => 'You are an expert e-commerce copywriter. Rewrite product descriptions to be clear, '
				. 'specific, and engaging. Use ONLY the facts provided — never invent materials, measurements, '
				. 'certifications, performance claims, or compatibility. Return plain text only: no markdown, no quotes, no headings.',
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$text = trim( wp_strip_all_tags( (string) $raw ) );
		$text = trim( $text, "\"' \t\n\r" );
		if ( '' === $text ) {
			return new WP_Error( 'empty', __( 'The AI returned an empty description.', 'twt-aeo-ultimate' ) );
		}
		return $text;
	}

	// ── Vision attribute tagging ─────────────────────────────────────────────────

	/**
	 * Extract visual attributes from the product's image via AI vision.
	 *
	 * @param int         $post_id
	 * @param string|null $provider Override; defaults to the enrichment provider.
	 * @return array|WP_Error  Normalized suggestions { color, material, size, additional[] }.
	 */
	public static function extract_vision_attributes( $post_id, $provider = null ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return new WP_Error( 'bad_product', __( 'Product not found.', 'twt-aeo-ultimate' ) );
		}

		$ids = self::product_image_ids( $post_id );
		if ( empty( $ids ) ) {
			return new WP_Error( 'no_images', __( 'This product has no images to analyze.', 'twt-aeo-ultimate' ) );
		}
		$url = wp_get_attachment_url( $ids[0] );
		if ( ! $url ) {
			return new WP_Error( 'no_image_url', __( 'Could not resolve the product image URL.', 'twt-aeo-ultimate' ) );
		}

		$provider = $provider ?: TWTAEO_AI_Client::enrich_provider();
		$name     = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );

		$raw = TWTAEO_AI_Client::complete( $provider, self::build_vision_prompt( $name ), array(
			'images'      => array( $url ),
			'json'        => true,
			'max_tokens'  => 500,
			'temperature' => 0.1,
			'system'      => 'You are a visual product analyst. Describe ONLY the inherent, permanent physical '
				. 'attributes of the product itself — its own colour, material finish, pattern, texture, and shape. '
				. 'Completely ignore the photographic scene: backgrounds, the surface the product rests on, props, '
				. 'other objects, hands or people, food, liquids, dirt, packaging, lighting and reflections, and any '
				. 'temporary or usage-related state. Never report another object in the frame as a property. '
				. 'Return a raw JSON object only — no markdown, no commentary — and never invent details you cannot see.',
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$normalized = self::normalize_vision( $data );

		update_post_meta( $post_id, self::META_VISION, array(
			'provider' => $provider,
			'time'     => time(),
			'data'     => $normalized,
		) );

		return $normalized;
	}

	/**
	 * Return cached vision suggestions for the modal, or null if none.
	 *
	 * @param int $post_id
	 * @return array|null { provider, time, data }
	 */
	public static function get_cached_vision( $post_id ) {
		$cached = get_post_meta( $post_id, self::META_VISION, true );
		return is_array( $cached ) && ! empty( $cached['data'] ) ? $cached : null;
	}

	private static function build_vision_prompt( $name ) {
		return "Analyze this product image and extract only attributes that are clearly visible.\n"
			. "Product name (for context): \"$name\".\n\n"
			. "Return a strict JSON object with this shape (omit any key you cannot determine from the image):\n"
			. '{"color":"","pattern":"","texture":"","shape":"","additionalProperty":[{"name":"","value":""}]}' . "\n\n"
			. "Rules:\n"
			. "- Describe the PRODUCT only. Ignore the background, the surface it sits on, props, other objects, "
			. "people/hands, food, liquids, dirt, packaging, and lighting. Never list another object as a property.\n"
			. "- Ignore temporary state (wet, dirty, mid-use, residue) — report only permanent attributes.\n"
			. "- color: the product's dominant colour(s), e.g. \"Navy Blue\" or \"Natural Wood / Light Tan\".\n"
			. "- pattern: e.g. Solid, Striped, Floral, Plaid, Geometric.\n"
			. "- texture: the product surface finish, e.g. Smooth, Ribbed, Woven, Matte, Glossy.\n"
			. "- shape: overall form when meaningful, e.g. Round, Rectangular, A-line.\n"
			. "- additionalProperty: ONLY durable visual design traits of the product itself (e.g. Finish: Matte, "
			. "Edge: Bevelled, Style: Mid-Century). Do NOT include scene, staging, condition, or other objects. "
			. "For example, never output things like \"Knife present\" or \"Wet with juice\".\n"
			. "- Do NOT guess material composition or measurements — those are not reliably visible.\n"
			. "- Output JSON only.";
	}

	/**
	 * Normalize the vision output into { color, material, size, additional[] }
	 * so it reuses the same modal fill path as text extraction.
	 * Pattern / texture / shape become additionalProperty rows.
	 *
	 * @param array $data
	 * @return array
	 */
	private static function normalize_vision( $data ) {
		$out = array(
			'color'      => self::clean_scalar( $data['color'] ?? '' ),
			'material'   => '', // Material is not reliably inferable from an image.
			'size'       => '',
			'additional' => array(),
		);

		$map = array( 'pattern' => 'Pattern', 'texture' => 'Texture', 'shape' => 'Shape' );
		foreach ( $map as $key => $label ) {
			$value = self::clean_scalar( $data[ $key ] ?? '' );
			if ( '' !== $value ) {
				$out['additional'][] = array( 'name' => $label, 'value' => $value );
			}
		}

		foreach ( (array) ( $data['additionalProperty'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pname = self::clean_scalar( $row['name'] ?? '' );
			$pval  = self::clean_scalar( $row['value'] ?? '' );
			if ( '' !== $pname && '' !== $pval && ! self::is_staging_property( $pname, $pval ) ) {
				$out['additional'][] = array( 'name' => $pname, 'value' => $pval );
			}
		}

		return $out;
	}

	/**
	 * Safety net: reject vision properties that describe the photo scene / staging
	 * or a temporary state rather than a permanent product attribute (e.g.
	 * "Knife present", "Wet with tomato juice", "Resting on a table").
	 *
	 * @param string $name
	 * @param string $value
	 * @return bool
	 */
	private static function is_staging_property( $name, $value ) {
		$haystack = strtolower( $name . ' ' . $value );
		$scene = array(
			'present', 'background', 'staging', 'scene', 'surface condition', 'resting on',
			'sitting on', 'next to', 'in the frame', 'wet', 'damp', 'dirty', 'residue',
			'juice', 'crumbs', 'seeds', 'mid-use', 'in use', 'being used', 'hand ', 'person',
			'people', 'table', 'countertop', 'plate', 'utensil', 'prop ',
		);
		foreach ( $scene as $needle ) {
			if ( strpos( $haystack, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	// ── Image alt text ───────────────────────────────────────────────────────────

	/** Cap vision calls per run to bound cost on large galleries. */
	const ALT_MAX_IMAGES = 10;
	const ALT_MAX_LENGTH = 125;

	/**
	 * Return the product's image attachment IDs (featured first, then gallery).
	 *
	 * @param int $post_id
	 * @return int[]
	 */
	public static function product_image_ids( $post_id ) {
		$ids     = array();
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;

		$featured = get_post_thumbnail_id( $post_id );
		if ( $featured ) {
			$ids[] = (int) $featured;
		}
		if ( $product && method_exists( $product, 'get_gallery_image_ids' ) ) {
			foreach ( $product->get_gallery_image_ids() as $gid ) {
				$ids[] = (int) $gid;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Count how many of a product's images are missing alt text.
	 *
	 * @param int $post_id
	 * @return array { total:int, missing:int }
	 */
	public static function image_alt_summary( $post_id ) {
		$ids     = self::product_image_ids( $post_id );
		$missing = 0;
		foreach ( $ids as $id ) {
			if ( '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				$missing++;
			}
		}
		return array( 'total' => count( $ids ), 'missing' => $missing );
	}

	/**
	 * Render an image-alt coverage badge for the products table (escaped).
	 *
	 * @param array $summary Result of image_alt_summary(): { total, missing }.
	 * @return string
	 */
	public static function alt_badge_html( $summary ) {
		$total   = (int) ( $summary['total'] ?? 0 );
		$missing = (int) ( $summary['missing'] ?? 0 );
		if ( 0 === $total ) {
			// An explicit warning, not a muted dash: no image means nothing for
			// rich results, social shares, or AI answers to show — a worse gap
			// than missing alt text, and one a "—" successfully hid from every
			// merchant who read it as "nothing to do here".
			return '<span class="twt-aeo-badge twt-aeo-badge--warn" title="'
				. esc_attr__( 'No images at all. Answer engines cite the pages that give them the most to work with — an image plus its description is data a text-only page simply does not have. Add an image in the editor.', 'twt-aeo-ultimate' ) . '">'
				. esc_html__( 'No image', 'twt-aeo-ultimate' ) . '</span>';
		}
		$have = $total - $missing;
		if ( 0 === $missing ) {
			return '<span class="twt-aeo-badge" style="background:rgba(22,163,74,.1);color:#16a34a;">'
				. esc_html( sprintf( '%d/%d', $have, $total ) ) . ' &#10003;</span>';
		}
		return '<span class="twt-aeo-badge twt-aeo-badge--warn" title="'
			. esc_attr( sprintf(
				/* translators: %d: number of images missing alt text. */
				_n( '%d image missing alt text', '%d images missing alt text', $missing, 'twt-aeo-ultimate' ),
				$missing
			) ) . '">'
			. esc_html( sprintf( '%d/%d', $have, $total ) ) . '</span>';
	}

	/**
	 * Generate and write alt text for product images that have none.
	 * Non-destructive: images that already have alt text are left untouched.
	 *
	 * @param int         $post_id
	 * @param string|null $provider Vision-capable provider; defaults to enrichment provider.
	 * @return array|WP_Error { filled, skipped, total, items[] }
	 */
	public static function fill_image_alt( $post_id, $provider = null ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return new WP_Error( 'bad_product', __( 'Product not found.', 'twt-aeo-ultimate' ) );
		}

		$ids = self::product_image_ids( $post_id );
		if ( empty( $ids ) ) {
			return new WP_Error( 'no_images', __( 'This product has no images.', 'twt-aeo-ultimate' ) );
		}

		$provider  = $provider ?: TWTAEO_AI_Client::enrich_provider();
		$name      = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$filled    = 0;
		$skipped   = 0;
		$processed = 0;
		$items     = array();
		$last_error = null;

		foreach ( $ids as $id ) {
			if ( '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				$skipped++;
				continue;
			}
			if ( $processed >= self::ALT_MAX_IMAGES ) {
				break;
			}
			$url = wp_get_attachment_url( $id );
			if ( ! $url ) {
				continue;
			}
			$processed++;

			$alt = TWTAEO_AI_Client::complete( $provider, self::build_alt_prompt( $name ), array(
				'images'      => array( $url ),
				'max_tokens'  => 120,
				'temperature' => 0.3,
				'system'      => 'You write concise, factual alt text for e-commerce product images. '
					. 'Describe only what is visible. Return plain text with no quotes, labels, or markdown.',
			) );

			if ( is_wp_error( $alt ) ) {
				$last_error = $alt; // Keep going; report at the end if nothing landed.
				continue;
			}

			$alt = self::clean_alt( $alt );
			if ( '' === $alt ) {
				continue;
			}
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			$filled++;
			$items[] = array( 'id' => $id, 'alt' => $alt );
		}

		if ( 0 === $filled && $last_error ) {
			return $last_error;
		}

		return array(
			'filled'  => $filled,
			'skipped' => $skipped,
			'total'   => count( $ids ),
			'items'   => $items,
		);
	}

	private static function build_alt_prompt( $name ) {
		return "Write descriptive alt text for this product image, max " . self::ALT_MAX_LENGTH . " characters. "
			. "Product name: \"$name\". Focus on the visible subject, colour, and material for accessibility and search. "
			. "Do not start with \"image of\" or \"photo of\".";
	}

	private static function clean_alt( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$text = trim( $text, "\"' \t\n\r" );
		if ( mb_strlen( $text ) > self::ALT_MAX_LENGTH ) {
			$text = rtrim( mb_substr( $text, 0, self::ALT_MAX_LENGTH ) );
		}
		return $text;
	}

	// ── Internals ────────────────────────────────────────────────────────────────

	private static function gather_source_text( WP_Post $post ) {
		$parts   = array( get_the_title( $post ) );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
		if ( $product ) {
			$parts[] = wp_strip_all_tags( $product->get_short_description() );
		}
		$parts[] = wp_strip_all_tags( $post->post_content );

		// Include existing attribute labels for additional grounding.
		if ( $product && class_exists( 'TWTAEO_WooCommerce_Detector' ) ) {
			$attrs = TWTAEO_WooCommerce_Detector::detect_attributes( $product );
			foreach ( (array) ( $attrs['additional'] ?? array() ) as $row ) {
				$parts[] = $row['name'] . ': ' . $row['value'];
			}
		}

		$text = trim( implode( "\n", array_filter( $parts ) ) );
		// Keep the prompt bounded.
		return mb_substr( $text, 0, 6000 );
	}

	private static function build_prompt( $source ) {
		$dim_unit    = get_option( 'woocommerce_dimension_unit', 'cm' );
		$weight_unit = get_option( 'woocommerce_weight_unit', 'kg' );

		return "From the product text below, extract ONLY attributes that are explicitly stated. "
			. "Do not guess, infer, or invent values. Omit any field you are unsure about.\n\n"
			. "Return a strict JSON object with this shape (omit keys with no evidence):\n"
			. '{"color":"","material":"","size":"","weight":"","dimensions":{"width":"","height":"","depth":"","unit":""},'
			. '"additionalProperty":[{"name":"","value":""}]}' . "\n\n"
			. "Rules:\n"
			. "- color/material/size: short literal values (e.g. \"Navy Blue\", \"100% Egyptian Cotton\", \"Large\").\n"
			. "- additionalProperty: any other spec-like facts (e.g. Water Resistance: 50m, Thread Count: 400).\n"
			. "- This store measures dimensions in \"$dim_unit\" and weight in \"$weight_unit\". "
			. "If the text states a measurement in a different unit, CONVERT it to the store's unit and return the converted value. "
			. "Always set dimensions.unit to \"$dim_unit\" and express weight in \"$weight_unit\".\n"
			. "- Output JSON only, no commentary.\n\n"
			. "PRODUCT TEXT:\n" . $source;
	}

	/**
	 * Normalize the model output into { color, material, size, additional[] }.
	 * Dimensions and weight are folded into additionalProperty rows.
	 *
	 * @param array $data
	 * @return array
	 */
	private static function normalize( $data ) {
		$out = array(
			'color'      => self::clean_scalar( $data['color'] ?? '' ),
			'material'   => self::clean_scalar( $data['material'] ?? '' ),
			'size'       => self::clean_scalar( $data['size'] ?? '' ),
			'additional' => array(),
		);

		// Custom spec pairs.
		foreach ( (array) ( $data['additionalProperty'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name  = self::clean_scalar( $row['name'] ?? '' );
			$value = self::clean_scalar( $row['value'] ?? '' );
			if ( '' !== $name && '' !== $value ) {
				$out['additional'][] = array( 'name' => $name, 'value' => $value );
			}
		}

		// Weight → a spec row.
		$weight = self::clean_scalar( $data['weight'] ?? '' );
		if ( '' !== $weight ) {
			$out['additional'][] = array( 'name' => 'Weight', 'value' => $weight );
		}

		// Dimensions → a single combined spec row.
		$dim = $data['dimensions'] ?? array();
		if ( is_array( $dim ) ) {
			$w = self::clean_scalar( $dim['width'] ?? '' );
			$h = self::clean_scalar( $dim['height'] ?? '' );
			$d = self::clean_scalar( $dim['depth'] ?? '' );
			$u = self::clean_scalar( $dim['unit'] ?? '' );
			$nums = array_filter( array( $w, $h, $d ) );
			if ( ! empty( $nums ) ) {
				$value = implode( ' × ', $nums );
				if ( '' !== $u ) {
					$value .= ' ' . $u;
				}
				$out['additional'][] = array( 'name' => 'Dimensions', 'value' => $value );
			}
		}

		return $out;
	}

	private static function clean_scalar( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'strval', $value ) );
		}
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}
