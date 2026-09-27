<?php
/**
 * Knowledge Graph — Entity Store.
 *
 * The central data layer for the Knowledge Graph module's "definitive" features:
 *
 *   (2) topic → Wikidata sameAs grounding — a topic per URL is resolved to its
 *       authority URLs (Wikipedia / Wikidata) and emitted as a Thing the page
 *       is `about`.
 *   (3) about / mentions — each URL is mapped to the entities it is primarily
 *       about and the entities it merely mentions.
 *   (4) entity relationships — entities are linked to one another with schema
 *       predicates (knows / founder / parentOrganization / memberOf, …).
 *
 * Everything is defined centrally on the Knowledge Graph admin page — there are
 * deliberately no per-post metaboxes. Data lives in three options:
 *
 *   twtaeo_kg_entities      list of { id, type, name, url, description, same_as[] }
 *   twtaeo_kg_relationships list of { subject, predicate, object }  (ids)
 *   twtaeo_kg_mappings      list of { url, about[], mentions[], topic, topic_same_as[] }
 *
 * TWTAEO_Knowledge_Graph::build_graph() reads these and folds the resulting
 * nodes (cross-referenced by @id) into the single unified page @graph.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_KG_Entities {

	const OPTION_ENTITIES      = 'twtaeo_kg_entities';
	const OPTION_RELATIONSHIPS = 'twtaeo_kg_relationships';
	const OPTION_MAPPINGS      = 'twtaeo_kg_mappings';

	const NONCE_SAVE = 'twtaeo_kg_save';
	const NONCE_AJAX = 'twtaeo_kg_nonce';

	/**
	 * schema.org types offered for an entity, grouped for the picker.
	 *
	 * @return array<string,string> value => label
	 */
	public static function entity_types() {
		return array(
			'Organization' => 'Organization',
			'Corporation'  => 'Corporation',
			'LocalBusiness' => 'Local Business',
			'Person'       => 'Person',
			'Place'        => 'Place',
			'Product'      => 'Product',
			'Brand'        => 'Brand',
			'CreativeWork' => 'Creative Work',
			'Service'      => 'Service',
			'Event'        => 'Event',
			'Thing'        => 'Thing / Topic',
		);
	}

	/**
	 * Curated relationship predicates. Each maps to a schema.org property emitted
	 * as an @id reference on the subject entity's node.
	 *
	 * @return array<string,string> property => label
	 */
	public static function predicates() {
		return array(
			'knows'              => 'knows',
			'founder'            => 'founder',
			'parentOrganization' => 'parent organization',
			'subOrganization'    => 'sub-organization',
			'memberOf'           => 'member of',
			'member'             => 'has member',
			'worksFor'           => 'works for',
			'employee'           => 'has employee',
			'owns'               => 'owns',
			'alumniOf'           => 'alumni of',
			'sponsor'            => 'sponsor',
			'brand'              => 'brand',
			'manufacturer'       => 'manufacturer',
			'relatedTo'          => 'related to',
		);
	}

	// ── Data access ──────────────────────────────────────────────────────────────

	/** @return array list of entity rows */
	public static function get_entities() {
		$rows = get_option( self::OPTION_ENTITIES, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array list of relationship rows */
	public static function get_relationships() {
		$rows = get_option( self::OPTION_RELATIONSHIPS, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array list of mapping rows */
	public static function get_mappings() {
		$rows = get_option( self::OPTION_MAPPINGS, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find a single entity row by its id.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public static function find_entity( $id ) {
		foreach ( self::get_entities() as $e ) {
			if ( ( $e['id'] ?? '' ) === $id ) {
				return $e;
			}
		}
		return null;
	}

	/** id => name map, for building <select> pickers. */
	public static function entity_options() {
		$out = array();
		foreach ( self::get_entities() as $e ) {
			if ( ! empty( $e['id'] ) && ! empty( $e['name'] ) ) {
				$out[ $e['id'] ] = $e['name'];
			}
		}
		return $out;
	}

	/** Canonical @id for an entity (a hash node off the site root). */
	public static function entity_id( $id ) {
		return home_url( '/#' . $id );
	}

	// ── Save (from the admin form) ───────────────────────────────────────────────

	/**
	 * Sanitize and persist the entity list. Each row keeps its existing id so
	 * relationships and mappings that reference it survive an edit; rows without
	 * an id get a fresh stable one.
	 *
	 * @param array $raw Raw $_POST['entities'] rows.
	 * @return array The saved, normalized rows.
	 */
	public static function save_entities( $raw ) {
		$types = self::entity_types();
		$out   = array();
		$seen  = array();

		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = sanitize_text_field( wp_unslash( $row['name'] ?? '' ) );
			if ( '' === trim( $name ) ) {
				continue; // A nameless entity is meaningless — drop empty rows.
			}

			$id = self::sanitize_id( $row['id'] ?? '' );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = self::generate_id( $name, $seen );
			}
			$seen[ $id ] = true;

			$type = sanitize_text_field( wp_unslash( $row['type'] ?? '' ) );
			if ( ! isset( $types[ $type ] ) ) {
				$type = 'Thing';
			}

			$out[] = array(
				'id'          => $id,
				'type'        => $type,
				'name'        => $name,
				'url'         => esc_url_raw( wp_unslash( $row['url'] ?? '' ) ),
				'description' => sanitize_textarea_field( wp_unslash( $row['description'] ?? '' ) ),
				'same_as'     => self::sanitize_url_lines( $row['same_as'] ?? '' ),
			);
		}

		update_option( self::OPTION_ENTITIES, $out );
		return $out;
	}

	/**
	 * Sanitize and persist relationships. Only rows whose subject, predicate, and
	 * object all resolve are kept, so the emitted graph never dangles.
	 *
	 * @param array $raw Raw $_POST['relationships'] rows.
	 * @return array
	 */
	public static function save_relationships( $raw ) {
		$valid_ids  = array_keys( self::entity_options() );
		$predicates = self::predicates();
		$out        = array();

		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$subject   = self::sanitize_id( $row['subject'] ?? '' );
			$object    = self::sanitize_id( $row['object'] ?? '' );
			$predicate = sanitize_text_field( wp_unslash( $row['predicate'] ?? '' ) );

			if ( ! in_array( $subject, $valid_ids, true ) ) {
				continue;
			}
			if ( ! in_array( $object, $valid_ids, true ) ) {
				continue;
			}
			if ( ! isset( $predicates[ $predicate ] ) || $subject === $object ) {
				continue;
			}

			$out[] = array(
				'subject'   => $subject,
				'predicate' => $predicate,
				'object'    => $object,
			);
		}

		update_option( self::OPTION_RELATIONSHIPS, $out );
		return $out;
	}

	/**
	 * Sanitize and persist URL → entity mappings.
	 *
	 * @param array $raw Raw $_POST['mappings'] rows.
	 * @return array
	 */
	public static function save_mappings( $raw ) {
		$valid_ids = array_keys( self::entity_options() );
		$out       = array();

		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = esc_url_raw( wp_unslash( $row['url'] ?? '' ) );
			if ( '' === trim( $url ) ) {
				continue;
			}

			$about    = self::sanitize_id_list( $row['about'] ?? array(), $valid_ids );
			$mentions = self::sanitize_id_list( $row['mentions'] ?? array(), $valid_ids );
			$topic    = sanitize_text_field( wp_unslash( $row['topic'] ?? '' ) );
			$topic_sa = self::sanitize_url_lines( $row['topic_same_as'] ?? '' );

			// Skip a mapping that carries no signal at all.
			if ( empty( $about ) && empty( $mentions ) && '' === trim( $topic ) ) {
				continue;
			}

			$out[] = array(
				'url'           => $url,
				'about'         => $about,
				'mentions'      => $mentions,
				'topic'         => $topic,
				'topic_same_as' => $topic_sa,
			);
		}

		update_option( self::OPTION_MAPPINGS, $out );
		return $out;
	}

	// ── Graph node builders (consumed by TWTAEO_Knowledge_Graph) ─────────────────

	/**
	 * Build a schema node for every defined entity, with its relationships folded
	 * in as @id references. These are emitted globally so that any about/mentions
	 * reference from a WebPage resolves within the same @graph.
	 *
	 * @return array list of schema nodes
	 */
	public static function build_entity_nodes() {
		$entities = self::get_entities();
		if ( empty( $entities ) ) {
			return array();
		}

		$rels_by_subject = self::relationships_by_subject();
		$valid_ids       = array_keys( self::entity_options() );
		$nodes           = array();

		foreach ( $entities as $e ) {
			$id = $e['id'] ?? '';
			if ( '' === $id || empty( $e['name'] ) ) {
				continue;
			}

			$node = array(
				'@type' => ! empty( $e['type'] ) ? $e['type'] : 'Thing',
				'@id'   => self::entity_id( $id ),
				'name'  => $e['name'],
			);
			if ( ! empty( $e['url'] ) ) {
				$node['url'] = $e['url'];
			}
			if ( ! empty( $e['description'] ) ) {
				$node['description'] = $e['description'];
			}
			if ( ! empty( $e['same_as'] ) ) {
				$node['sameAs'] = array_values( $e['same_as'] );
			}

			// Relationships → predicate => ref (or array of refs).
			foreach ( ( $rels_by_subject[ $id ] ?? array() ) as $predicate => $objects ) {
				$refs = array();
				foreach ( $objects as $object_id ) {
					if ( in_array( $object_id, $valid_ids, true ) ) {
						$refs[] = array( '@id' => self::entity_id( $object_id ) );
					}
				}
				if ( ! empty( $refs ) ) {
					$node[ $predicate ] = ( count( $refs ) === 1 ) ? $refs[0] : $refs;
				}
			}

			$nodes[] = $node;
		}

		return $nodes;
	}

	/**
	 * Resolve the mapping for a given front-end URL (normalized comparison).
	 *
	 * @param string $url
	 * @return array|null
	 */
	public static function get_mapping_for_url( $url ) {
		$target = self::normalize_url( $url );
		foreach ( self::get_mappings() as $m ) {
			if ( self::normalize_url( $m['url'] ?? '' ) === $target ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Build `about` / `mentions` @id reference lists plus any synthesized topic
	 * node for a resolved mapping.
	 *
	 * @param array  $mapping  A row from get_mapping_for_url().
	 * @param string $page_url The current URL (used to anchor the topic node @id).
	 * @return array { about: array, mentions: array, topic_node: array|null }
	 */
	public static function refs_for_mapping( $mapping, $page_url ) {
		$valid_ids = array_keys( self::entity_options() );

		$about = array();
		foreach ( (array) ( $mapping['about'] ?? array() ) as $id ) {
			if ( in_array( $id, $valid_ids, true ) ) {
				$about[] = array( '@id' => self::entity_id( $id ) );
			}
		}

		$mentions = array();
		foreach ( (array) ( $mapping['mentions'] ?? array() ) as $id ) {
			if ( in_array( $id, $valid_ids, true ) ) {
				$mentions[] = array( '@id' => self::entity_id( $id ) );
			}
		}

		// Topic grounding: a Thing the page is additionally `about`, carrying its
		// resolved Wikipedia / Wikidata sameAs.
		$topic_node = null;
		$topic      = trim( (string) ( $mapping['topic'] ?? '' ) );
		$topic_sa   = (array) ( $mapping['topic_same_as'] ?? array() );
		if ( '' !== $topic && ! empty( $topic_sa ) ) {
			$topic_id   = trailingslashit( $page_url ) . '#topic';
			$topic_node = array(
				'@type'  => 'Thing',
				'@id'    => $topic_id,
				'name'   => $topic,
				'sameAs' => array_values( $topic_sa ),
			);
			$about[] = array( '@id' => $topic_id );
		}

		return array(
			'about'      => $about,
			'mentions'   => $mentions,
			'topic_node' => $topic_node,
		);
	}

	// ── AI grounding (feature 2) ─────────────────────────────────────────────────

	/**
	 * Resolve an entity or topic name to verified authority URLs (official site,
	 * Wikipedia, Wikidata) via a web-grounded retrieval provider. This mirrors
	 * TWTAEO_Product_Enricher::resolve_brand_sameas, generalized to any entity type.
	 *
	 * @param string      $name
	 * @param string      $type_hint  organization | person | place | topic | thing …
	 * @param string|null $provider   Override; defaults to the retrieval provider.
	 * @return string[]|WP_Error  Candidate authority URLs (may be empty).
	 */
	public static function resolve_same_as( $name, $type_hint = 'thing', $provider = null ) {
		if ( ! class_exists( 'TWTAEO_AI_Client' ) ) {
			return new WP_Error( 'no_client', __( 'AI client unavailable.', 'twt-aeo-ultimate' ) );
		}

		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'no_name', __( 'Enter a name to ground first.', 'twt-aeo-ultimate' ) );
		}

		$type_hint  = strtolower( trim( (string) $type_hint ) );
		$descriptor = self::type_descriptor( $type_hint );

		$provider = $provider ?: TWTAEO_AI_Client::retrieval_provider();

		$prompt = "Identify the single real-world $descriptor named \"$name\".\n"
			. 'Return strict JSON: {"sameAs":["url", ...]} listing only authoritative URLs that identify this exact '
			. "entity — its English Wikipedia page, its Wikidata entity (https://www.wikidata.org/wiki/Q...), and its "
			. "official homepage if one exists. Include a URL only if you are confident it is the correct entity; omit "
			. "anything uncertain or ambiguous. Return at most 4 URLs. Output JSON only.";

		$raw = TWTAEO_AI_Client::complete( $provider, $prompt, array(
			'grounding'   => true,
			'max_tokens'  => 500,
			'temperature' => 0,
			'system'      => 'You resolve named entities and topics to their verified authority URLs (Wikipedia, '
				. 'Wikidata, official site) using live web knowledge. Only return URLs you are confident identify the '
				. 'exact entity. Never fabricate or guess URLs.',
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
			$u = trim( (string) $u );
			if ( ! preg_match( '#^https?://#i', $u ) ) {
				continue; // Reject anything without an explicit scheme (no coercion).
			}
			$u = esc_url_raw( $u );
			if ( '' !== $u ) {
				$urls[] = $u;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	// ── Internals ────────────────────────────────────────────────────────────────

	/** Group relationships as [ subject_id => [ predicate => [object_id, …] ] ]. */
	private static function relationships_by_subject() {
		$grouped = array();
		foreach ( self::get_relationships() as $r ) {
			$s = $r['subject'] ?? '';
			$p = $r['predicate'] ?? '';
			$o = $r['object'] ?? '';
			if ( '' === $s || '' === $p || '' === $o ) {
				continue;
			}
			$grouped[ $s ][ $p ][] = $o;
		}
		return $grouped;
	}

	/** A human descriptor for the resolver prompt, keyed off the type hint. */
	private static function type_descriptor( $type_hint ) {
		$map = array(
			'organization'  => 'organization or company',
			'corporation'   => 'company',
			'localbusiness' => 'local business',
			'person'        => 'person',
			'place'         => 'place or location',
			'product'       => 'product',
			'brand'         => 'brand',
			'creativework'  => 'creative work',
			'service'       => 'service',
			'event'         => 'event',
			'thing'         => 'entity or topic',
			'topic'         => 'topic or subject',
		);
		return $map[ $type_hint ] ?? 'entity or topic';
	}

	/** Restrict an id to a safe slug charset. */
	private static function sanitize_id( $id ) {
		$id = strtolower( (string) wp_unslash( $id ) );
		$id = preg_replace( '/[^a-z0-9\-_]/', '', $id );
		return $id;
	}

	/** Sanitize a submitted list of entity ids against the valid set. */
	private static function sanitize_id_list( $raw, array $valid_ids ) {
		$out = array();
		foreach ( (array) $raw as $id ) {
			$id = self::sanitize_id( $id );
			if ( '' !== $id && in_array( $id, $valid_ids, true ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** Split a textarea of URLs (one per line) into a clean http(s) list. */
	private static function sanitize_url_lines( $raw ) {
		$raw   = (string) wp_unslash( $raw );
		$lines = preg_split( '/[\r\n]+/', $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			// Require an explicit http(s) scheme up front — otherwise esc_url_raw()
			// would silently coerce arbitrary text into "http://text".
			if ( ! preg_match( '#^https?://#i', $line ) ) {
				continue;
			}
			$u = esc_url_raw( $line );
			if ( '' !== $u && ! in_array( $u, $out, true ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/** Generate a stable, unique entity id from a name. */
	private static function generate_id( $name, array $seen ) {
		$base = 'ent-' . sanitize_title( $name );
		$base = trim( $base, '-' );
		if ( 'ent-' === $base || '' === $base ) {
			$base = 'ent';
		}
		$id = $base;
		$n  = 2;
		while ( isset( $seen[ $id ] ) ) {
			$id = $base . '-' . $n;
			$n++;
		}
		return $id;
	}

	/**
	 * Normalize a URL for equality comparison — drops scheme, "www.", query,
	 * fragment, and a trailing slash, and lowercases the host/path.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		// Make a bare path absolute against the site root so "/about/" matches.
		if ( preg_match( '#^/#', $url ) ) {
			$url = home_url( $url );
		}
		$url = preg_replace( '#^https?://#i', '', $url );
		$url = preg_replace( '#^www\.#i', '', $url );
		// Strip query and fragment.
		$url = preg_replace( '/[?#].*$/', '', $url );
		$url = rtrim( $url, '/' );
		return strtolower( $url );
	}
}
