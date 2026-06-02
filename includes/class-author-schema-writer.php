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
	}

	/**
	 * Output Person JSON-LD if conditions are met.
	 */
	public static function output_schema() {
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

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n" . '<script type="application/ld+json">' . "\n"
			. wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
			. "\n" . '</script>' . "\n";
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
					'credentialCategory'  => 'Professional Certification',
				);

				if ( ! empty( $cert['organization'] ) ) {
					$credential['recognizedBy'] = array(
						'@type' => 'Organization',
						'name'  => $cert['organization'],
					);
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
