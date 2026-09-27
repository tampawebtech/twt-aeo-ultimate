<?php
/**
 * Author Schema Writer
 *
 * Outputs a Person JSON-LD block via wp_head on single posts and author
 * archive pages. Includes hasCredential (EducationalOccupationalCredential)
 * and sameAs arrays from TWTAEO_Author_Meta.
 *
 * Only outputs when:
 *   1. The author-schema module is active.
 *   2. We are on a single post or author archive.
 *   3. No Person schema has already been output by Rank Math or Yoast.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Author_Schema_Writer {

	public static function register_hooks() {
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 20 );
		// Fold the author Person node into the Knowledge Graph when active.
		add_filter( 'twtaeo_kg_nodes', array( __CLASS__, 'kg_nodes' ), 10, 2 );
	}

	/**
	 * Output Person JSON-LD if conditions are met.
	 */
	public static function output_schema() {
		// The Knowledge Graph module folds this node into its unified @graph.
		if ( class_exists( 'TWTAEO_Knowledge_Graph' ) && TWTAEO_Knowledge_Graph::is_folding() ) {
			return;
		}
		// Only on singular posts and author archives.
		if ( ! is_singular( 'post' ) && ! is_author() ) {
			return;
		}

		// Skip if Rank Math or Yoast will handle Person schema.
		if ( self::seo_plugin_handles_person() ) {
			return;
		}

		$user_id = is_author() ? get_queried_object_id() : (int) get_the_author_meta( 'ID' );
		if ( ! $user_id ) {
			return;
		}

		$schema = self::build_schema( $user_id );
		if ( ! $schema ) {
			return;
		}

		// On the author archive the page IS the profile: wrap the Person in a
		// ProfilePage node (Google's profile-page shape — mainEntity: Person).
		if ( is_author() ) {
			$person = $schema;
			unset( $person['@context'] );
			$schema = self::profile_page_node( $user_id );
			$schema['@context']   = 'https://schema.org';
			$schema['mainEntity'] = $person;
		}

		// JSON-LD output. esc_html() would corrupt the JSON, so safety comes from the
		// HEX_* flags: <, >, &, ' and " are all encoded as \uXXXX, so the value cannot
		// break out of the script element or carry HTML/JS into the page.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML-inert JSON-LD; see note above.
		echo "\n" . '<script type="application/ld+json">' . "\n"
			. wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
			. "\n" . '</script>' . "\n";
	}

	// ── Knowledge Graph contribution ──────────────────────────────────────────

	/**
	 * Contribute the author Person node to the unified @graph. It carries the same
	 * @id ( {profile_url}#person ) the Article/News writers reference, so merge_node()
	 * keeps a single Person node whichever source runs first.
	 *
	 * @param array $nodes
	 * @param array $context
	 * @return array
	 */
	public static function kg_nodes( $nodes, $context ) {
		if ( ! is_singular( 'post' ) && ! is_author() ) {
			return $nodes;
		}
		if ( self::seo_plugin_handles_person() ) {
			return $nodes;
		}

		$user_id = is_author() ? get_queried_object_id() : (int) get_the_author_meta( 'ID' );
		if ( ! $user_id ) {
			return $nodes;
		}

		$schema = self::build_schema( $user_id );
		if ( ! $schema ) {
			return $nodes;
		}

		unset( $schema['@context'] );
		$new = array( $schema );

		// Author archive: contribute a ProfilePage node as the page entity
		// (the Knowledge Graph builds no WebPage on author archives, so this
		// is the page node) with mainEntity referencing the Person by @id.
		if ( is_author() && ! empty( $schema['@id'] ) ) {
			$profile               = self::profile_page_node( $user_id );
			$profile['mainEntity'] = array( '@id' => $schema['@id'] );
			if ( class_exists( 'TWTAEO_Knowledge_Graph' ) ) {
				$profile['isPartOf'] = array( '@id' => TWTAEO_Knowledge_Graph::website_id() );
			}
			$new[] = $profile;
		}

		return array_merge( $nodes, $new );
	}

	/**
	 * Bare ProfilePage node for a user's author archive (no @context, no
	 * mainEntity — callers attach those per delivery mode). dateCreated is the
	 * account registration date; dateModified the author's latest content
	 * change, per Google's profile-page guidance.
	 *
	 * @param int $user_id
	 * @return array
	 */
	private static function profile_page_node( $user_id ) {
		$url  = get_author_posts_url( $user_id );
		$node = array(
			'@type' => 'ProfilePage',
			'@id'   => $url . '#profilepage',
			'url'   => $url,
		);

		$user = get_userdata( $user_id );
		if ( $user && ! empty( $user->user_registered ) ) {
			$node['dateCreated'] = mysql2date( 'c', $user->user_registered, false );
		}

		$latest = get_posts( array(
			'author'      => $user_id,
			'numberposts' => 1,
			'orderby'     => 'modified',
			'order'       => 'DESC',
			'fields'      => 'ids',
		) );
		if ( ! empty( $latest ) ) {
			$node['dateModified'] = get_post_modified_time( 'c', true, $latest[0] );
		}

		return $node;
	}

	/**
	 * Build the Person schema array for a user.
	 *
	 * @param int $user_id
	 * @return array|null Null if the author has no displayable data.
	 */
	public static function build_schema( $user_id ) {
		$data   = TWTAEO_Author_Meta::get_author_data( $user_id );
		$social = TWTAEO_Author_Meta::get_same_as( $user_id );
		$certs  = TWTAEO_Author_Meta::get_certifications( $user_id );

		if ( empty( $data['name'] ) ) {
			return null;
		}

		$person = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Person',
			'@id'      => $data['profile_url'] . '#person',
			'name'     => $data['name'],
			'url'      => $data['profile_url'],
		);

		if ( ! empty( $data['bio'] ) ) {
			$person['description'] = $data['bio'];
		}

		// The contextual authority statement rides in the description too — the
		// prose form of the credential→institution relationship, for models
		// reading the page text rather than the hasCredential structure.
		$snippet = TWTAEO_Author_Meta::get_authority_snippet( $user_id );
		if ( '' !== $snippet ) {
			$person['description'] = ! empty( $person['description'] )
				? $person['description'] . ' ' . $snippet
				: $snippet;
		}

		if ( ! empty( $data['job_title'] ) ) {
			$person['jobTitle'] = $data['job_title'];
		}

		if ( ! empty( $data['avatar_url'] ) ) {
			$person['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $data['avatar_url'],
			);
		}

		// knowsAbout from expertise field.
		if ( ! empty( $data['expertise'] ) ) {
			$areas = array_filter( array_map( 'trim', explode( ',', $data['expertise'] ) ) );
			if ( ! empty( $areas ) ) {
				$person['knowsAbout'] = count( $areas ) === 1 ? $areas[0] : array_values( $areas );
			}
		}

		// sameAs from social profiles.
		if ( ! empty( $social ) ) {
			$person['sameAs'] = $social;
		}

		// hasCredential from certifications.
		if ( ! empty( $certs ) ) {
			$credentials = array();
			foreach ( $certs as $cert ) {
				if ( empty( $cert['name'] ) ) {
					continue;
				}

				$credential = array(
					'@type'               => 'EducationalOccupationalCredential',
					'name'                => $cert['name'],
					'credentialCategory'  => ! empty( $cert['category'] ) ? $cert['category'] : 'Professional Certification',
				);

				if ( ! empty( $cert['organization'] ) ) {
					$recognized_by = array(
						'@type' => 'Organization',
						'name'  => $cert['organization'],
					);

					if ( ! empty( $cert['org_url'] ) ) {
						$recognized_by['url'] = $cert['org_url'];
					}

					// Entity grounding: sameAs links (Wikipedia/Wikidata) connect the
					// issuing body to the knowledge graph AI models already know, so
					// regional/non-English credentials read as real authority signals.
					if ( ! empty( $cert['org_sameas'] ) ) {
						$same_as = array_filter( array_map( 'trim', explode( ',', $cert['org_sameas'] ) ) );
						if ( ! empty( $same_as ) ) {
							$recognized_by['sameAs'] = count( $same_as ) === 1 ? reset( $same_as ) : array_values( $same_as );
						}
					}

					$credential['recognizedBy'] = $recognized_by;
				}

				if ( ! empty( $cert['credential_id'] ) ) {
					$credential['identifier'] = $cert['credential_id'];
				}

				if ( ! empty( $cert['issue_date'] ) ) {
					$credential['dateCreated'] = $cert['issue_date'];
				}

				if ( ! empty( $cert['expiry_date'] ) ) {
					$credential['expires'] = $cert['expiry_date'];
				}

				if ( ! empty( $cert['verification_url'] ) ) {
					$credential['url'] = $cert['verification_url'];
				}

				if ( ! empty( $cert['description'] ) ) {
					$credential['description'] = $cert['description'];
				}

				$credentials[] = $credential;
			}

			if ( ! empty( $credentials ) ) {
				$person['hasCredential'] = $credentials;
			}
		}

		return $person;
	}

	/**
	 * Check whether the active SEO plugin already outputs Person schema.
	 * If so, we defer to avoid duplication.
	 *
	 * @return bool
	 */
	private static function seo_plugin_handles_person() {
		// Rank Math: person knowledge graph.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm = get_option( 'rank_math_titles', array() );
			if ( isset( $rm['knowledgegraph_type'] ) && $rm['knowledgegraph_type'] === 'person' ) {
				return true;
			}
		}

		// Yoast: person company type.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$wpseo = get_option( 'wpseo_social', array() );
			if ( ! empty( $wpseo['person_name'] ) ) {
				return true;
			}
		}

		return false;
	}
}
