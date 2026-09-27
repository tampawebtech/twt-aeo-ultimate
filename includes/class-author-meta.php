<?php
/**
 * Author Meta
 *
 * Manages E-E-A-T author fields: core profile data, repeatable certifications,
 * and social/sameAs profiles. Renders fields on the WP user profile page and
 * saves via the standard profile_update hook.
 *
 * Usermeta keys:
 *   twtaeo_job_title         — job title (string)
 *   twtaeo_credentials       — credential line (string, e.g. "Ph.D., MBA")
 *   twtaeo_expertise         — comma-separated expertise areas (string)
 *   twtaeo_years_experience  — years of experience (integer)
 *   twtaeo_certifications    — JSON-encoded array of certification objects
 *   twtaeo_social            — JSON-encoded object of social profile URLs
 *
 * Native WP keys used for maximum SEO-plugin compat:
 *   description  — biographical bio
 *   twitter      — X/Twitter handle or URL
 *   user_url     — personal website (wp_users column)
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Author_Meta {

	/**
	 * Social network definitions.
	 */
	public static $social_networks = array(
		'linkedin'  => array( 'label' => 'LinkedIn',              'placeholder' => 'https://linkedin.com/in/yourprofile' ),
		'facebook'  => array( 'label' => 'Facebook',              'placeholder' => 'https://facebook.com/yourpage' ),
		'twitter'   => array( 'label' => 'X / Twitter',           'placeholder' => 'https://x.com/yourusername' ),
		'github'    => array( 'label' => 'GitHub',                'placeholder' => 'https://github.com/yourusername' ),
		'youtube'   => array( 'label' => 'YouTube',               'placeholder' => 'https://youtube.com/@yourchannel' ),
		'website'   => array( 'label' => 'Personal Website',      'placeholder' => 'https://yoursite.com' ),
		'industry'  => array( 'label' => 'Industry Association',  'placeholder' => 'https://association.org/member/you' ),
	);

	/**
	 * Register profile form hooks.
	 */
	public static function register_hooks() {
		add_action( 'show_user_profile',        array( __CLASS__, 'render_profile_fields' ) );
		add_action( 'edit_user_profile',         array( __CLASS__, 'render_profile_fields' ) );
		add_action( 'personal_options_update',   array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update',  array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'admin_enqueue_scripts',     array( __CLASS__, 'enqueue_profile_assets' ) );
		add_filter( 'get_the_author_description', array( __CLASS__, 'append_snippet_to_bio' ), 10, 2 );
	}

	/**
	 * Ensure the shared admin script handle is enqueued on the user profile
	 * screens. The profile fields attach the "Add Certification" button handler
	 * via wp_add_inline_script( 'twt-aeo-admin', ... ), which silently no-ops if
	 * the handle is not enqueued — and TWTAEO_Admin_Assets only loads it on
	 * plugin pages (hooks containing "twt-aeo"), not profile.php / user-edit.php.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_profile_assets( $hook ) {
		if ( 'profile.php' !== $hook && 'user-edit.php' !== $hook ) {
			return;
		}

		if ( ! wp_script_is( 'twt-aeo-admin', 'registered' ) && ! wp_script_is( 'twt-aeo-admin', 'enqueued' ) ) {
			wp_enqueue_script(
				'twt-aeo-admin',
				TWTAEO_PLUGIN_URL . 'admin/assets/js/admin.js',
				array( 'jquery', 'wp-i18n' ),
				TWTAEO_VERSION,
				true
			);
		} else {
			wp_enqueue_script( 'twt-aeo-admin' );
		}
	}

	// ── Data access ────────────────────────────────────────────────────────────

	/**
	 * Get all AEO author data for a user.
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function get_author_data( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		return array(
			'id'               => $user_id,
			'name'             => $user->display_name,
			'email'            => $user->user_email,
			'url'              => $user->user_url,
			'bio'              => get_user_meta( $user_id, 'description', true ),
			'job_title'        => get_user_meta( $user_id, 'twtaeo_job_title', true ),
			'credentials'      => get_user_meta( $user_id, 'twtaeo_credentials', true ),
			'expertise'        => get_user_meta( $user_id, 'twtaeo_expertise', true ),
			'years_experience' => (int) get_user_meta( $user_id, 'twtaeo_years_experience', true ),
			'certifications'   => self::get_certifications( $user_id ),
			'social'           => self::get_social( $user_id ),
			'avatar_url'       => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			'profile_url'      => get_author_posts_url( $user_id ),
			'edit_url'         => get_edit_user_link( $user_id ),
		);
	}

	/**
	 * Get decoded certifications array for a user.
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function get_certifications( $user_id ) {
		$decoded = self::decode_meta( get_user_meta( $user_id, 'twtaeo_certifications', true ) );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get decoded social profiles object for a user.
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function get_social( $user_id ) {
		$decoded = self::decode_meta( get_user_meta( $user_id, 'twtaeo_social', true ) );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Fall back to native WP keys.
		$user = get_userdata( $user_id );
		return array(
			'twitter'  => get_user_meta( $user_id, 'twitter', true ),
			'website'  => $user ? $user->user_url : '',
		);
	}

	/**
	 * Decode a meta value that may be a JSON string (profile-page writes) or
	 * already an array (historical Setup Wizard writes). PHP 8 fatals if an
	 * array reaches json_decode(), so neither write path may assume the other.
	 *
	 * @param mixed $raw
	 * @return array|null
	 */
	private static function decode_meta( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}
		return null;
	}

	/**
	 * Build a sameAs array from social profiles (non-empty URLs only).
	 *
	 * @param int $user_id
	 * @return string[]
	 */
	public static function get_same_as( $user_id ) {
		$social  = self::get_social( $user_id );
		$same_as = array();

		foreach ( $social as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$url = trim( $url );
			if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$same_as[] = $url;
			}
		}

		return array_values( array_unique( $same_as ) );
	}

	/**
	 * Returns the editable AEO field definitions used by the E-E-A-T author modal.
	 * Each entry: key (usermeta suffix), label, type, placeholder.
	 *
	 * @return array[]
	 */
	public static function get_fields() {
		return array(
			array(
				'key'         => 'job_title',
				'label'       => __( 'Job Title', 'twt-aeo-ultimate' ),
				'type'        => 'text',
				'placeholder' => __( 'e.g. Senior Editor', 'twt-aeo-ultimate' ),
			),
			array(
				'key'         => 'credentials',
				'label'       => __( 'Credentials', 'twt-aeo-ultimate' ),
				'type'        => 'text',
				'placeholder' => __( 'e.g. Ph.D., MBA', 'twt-aeo-ultimate' ),
			),
			array(
				'key'         => 'expertise',
				'label'       => __( 'Expertise', 'twt-aeo-ultimate' ),
				'type'        => 'text',
				'placeholder' => __( 'e.g. SEO, Content Marketing', 'twt-aeo-ultimate' ),
			),
			array(
				'key'         => 'years_experience',
				'label'       => __( 'Years of Experience', 'twt-aeo-ultimate' ),
				'type'        => 'number',
				'placeholder' => '10',
			),
		);
	}

	/**
	 * Calculate author profile completeness (0–100).
	 *
	 * @param int $user_id
	 * @return array { score: int, missing: string[], total: int }
	 */
	public static function get_completeness( $user_id ) {
		$data    = self::get_author_data( $user_id );
		$missing = array();

		$checks = array(
			'bio'              => array( 'label' => 'Bio',              'value' => $data['bio'] ),
			'job_title'        => array( 'label' => 'Job Title',        'value' => $data['job_title'] ),
			'avatar'           => array( 'label' => 'Avatar',           'value' => get_avatar_url( $user_id ) ),
			'expertise'        => array( 'label' => 'Expertise',        'value' => $data['expertise'] ),
			'years_experience' => array( 'label' => 'Years Experience', 'value' => $data['years_experience'] ),
			'certifications'   => array( 'label' => 'Certifications',   'value' => ! empty( $data['certifications'] ) ? 'yes' : '' ),
			'social_any'       => array( 'label' => 'Social Profile',   'value' => ! empty( self::get_same_as( $user_id ) ) ? 'yes' : '' ),
			'credentials'      => array( 'label' => 'Credentials',      'value' => $data['credentials'] ),
		);

		$passed = 0;
		foreach ( $checks as $key => $check ) {
			if ( ! empty( $check['value'] ) ) {
				$passed++;
			} else {
				$missing[] = $check['label'];
			}
		}

		$total = count( $checks );
		$score = (int) round( ( $passed / $total ) * 100 );

		return array(
			'score'   => $score,
			'missing' => $missing,
			'total'   => $total,
			'passed'  => $passed,
		);
	}

	/**
	 * Get all authors (admins, editors, authors) with their data and completeness.
	 *
	 * @return array
	 */
	public static function get_all_authors() {
		$users = get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author', 'contributor' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );

		$result = array();
		foreach ( $users as $user ) {
			$data = self::get_author_data( $user->ID );

			// Expose both shapes from one call: the flat author-data fields at
			// the top level (author cards read $author['certifications'], ['id'],
			// etc.) plus 'user'/'data'/'completeness' for the E-E-A-T table.
			$result[] = array_merge( $data, array(
				'user'         => $user,
				'data'         => $data,
				'completeness' => self::get_completeness( $user->ID ),
			) );
		}

		return $result;
	}

	// ── Save ───────────────────────────────────────────────────────────────────

	/**
	 * Save profile fields on profile update.
	 *
	 * @param int $user_id
	 */
	/**
	 * Get the saved contextual authority statement for a user.
	 *
	 * @param int $user_id
	 * @return string
	 */
	public static function get_authority_snippet( $user_id ) {
		return trim( (string) get_user_meta( $user_id, 'twtaeo_authority_snippet', true ) );
	}

	/**
	 * Whether the authority statement should be appended to the public bio.
	 * Defaults to on when the meta has never been saved.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function authority_snippet_shown( $user_id ) {
		$val = get_user_meta( $user_id, 'twtaeo_authority_snippet_show', true );
		return '' === $val || '1' === $val;
	}

	/**
	 * Append the authority statement to the author bio on the front end, so the
	 * credential→institution relationship exists in plain prose wherever the theme
	 * renders the bio — not only in JSON-LD.
	 *
	 * Hooked on get_the_author_description.
	 *
	 * @param string $value
	 * @param int    $user_id
	 * @return string
	 */
	public static function append_snippet_to_bio( $value, $user_id ) {
		if ( is_admin() ) {
			return $value;
		}
		$user_id = (int) $user_id;
		if ( ! $user_id || ! self::authority_snippet_shown( $user_id ) ) {
			return $value;
		}
		$snippet = self::get_authority_snippet( $user_id );
		if ( '' === $snippet || false !== strpos( (string) $value, $snippet ) ) {
			return $value;
		}
		return trim( (string) $value . "\n\n" . $snippet );
	}

	/**
	 * AJAX: draft a contextual authority statement from the author's saved
	 * credentials via the shared AI client. Returns the draft only — nothing is
	 * saved until the user updates their profile.
	 */
	public static function ajax_generate_snippet() {
		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		$nonce   = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

		if ( ! $user_id || ! wp_verify_nonce( $nonce, 'twtaeo_author_profile_' . $user_id ) ) {
			wp_send_json_error( __( 'Session expired — reload the page and try again.', 'twt-aeo-ultimate' ) );
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'twt-aeo-ultimate' ) );
		}
		if ( ! class_exists( 'TWTAEO_AI_Client' ) ) {
			wp_send_json_error( __( 'The AI client module is not available.', 'twt-aeo-ultimate' ) );
		}

		$prompt = self::build_snippet_prompt( $user_id );
		if ( is_wp_error( $prompt ) ) {
			wp_send_json_error( $prompt->get_error_message() );
		}

		$system = 'You write short, factual authority statements for article author bios. '
			. 'Your job is to make professional credentials legible: name who issued each credential and what that institution is — its country and role (regulatory body, professional association, university) — so a reader or an AI system unfamiliar with the credential understands the expertise it represents.';

		$text = TWTAEO_AI_Client::complete( '', $prompt, array(
			'max_tokens'  => 300,
			'temperature' => 0.3,
			'system'      => $system,
			'timeout'     => 45,
		) );

		if ( is_wp_error( $text ) ) {
			wp_send_json_error( $text->get_error_message() );
		}

		$text = sanitize_textarea_field( trim( (string) $text ) );
		if ( '' === $text ) {
			wp_send_json_error( __( 'The AI returned an empty response — try again.', 'twt-aeo-ultimate' ) );
		}

		wp_send_json_success( array( 'snippet' => $text ) );
	}

	/**
	 * Build the generation prompt from the author's saved profile data.
	 *
	 * @param int $user_id
	 * @return string|WP_Error WP_Error when there is nothing to write from.
	 */
	private static function build_snippet_prompt( $user_id ) {
		$data  = self::get_author_data( $user_id );
		$certs = self::get_certifications( $user_id );

		if ( empty( $certs ) && empty( $data['credentials'] ) ) {
			return new WP_Error( 'no_credentials', __( 'Add at least one certification (or fill in the Credentials field) first — the statement is written from them.', 'twt-aeo-ultimate' ) );
		}

		$lines   = array();
		$lines[] = 'Author: ' . $data['name'];
		if ( ! empty( $data['job_title'] ) ) {
			$lines[] = 'Job title: ' . $data['job_title'];
		}
		if ( ! empty( $data['expertise'] ) ) {
			$lines[] = 'Expertise areas: ' . $data['expertise'];
		}
		if ( ! empty( $data['years_experience'] ) ) {
			$lines[] = 'Years of experience: ' . $data['years_experience'];
		}
		if ( ! empty( $data['credentials'] ) ) {
			$lines[] = 'Credential abbreviations: ' . $data['credentials'];
		}

		foreach ( $certs as $i => $cert ) {
			if ( empty( $cert['name'] ) ) {
				continue;
			}
			$parts   = array();
			$parts[] = 'name: ' . $cert['name'];
			$parts[] = 'type: ' . ( ! empty( $cert['category'] ) ? $cert['category'] : 'Professional Certification' );
			if ( ! empty( $cert['organization'] ) ) {
				$parts[] = 'issued by: ' . $cert['organization'];
			}
			if ( ! empty( $cert['org_url'] ) ) {
				$parts[] = 'issuer website: ' . $cert['org_url'];
			}
			if ( ! empty( $cert['org_sameas'] ) ) {
				$parts[] = 'issuer Wikipedia/Wikidata: ' . $cert['org_sameas'];
			}
			$lines[] = 'Credential ' . ( $i + 1 ) . ' — ' . implode( '; ', $parts );
		}

		return "Write a contextual authority statement for this author from the facts below.\n\n"
			. implode( "\n", $lines ) . "\n\n"
			. "Rules:\n"
			. "- 2 to 3 sentences, third person, plain prose.\n"
			. "- For each credential, spell out who issued it and what that institution is (e.g. \"certified by X, the regulatory authority governing Y in Country\"). Use the provided links and well-established public knowledge of the named institutions; if you do not recognize an institution, describe it only with the facts given.\n"
			. "- Never invent credentials, memberships, employers, or dates that are not listed above.\n"
			. "- No marketing language or superlatives (no \"renowned\", \"leading\", \"expert in\").\n"
			. "- Output the statement only: plain text, no quotes, no markdown, no preamble.";
	}

	/**
	 * Allowed credentialCategory values. Keys are what's stored/output in schema;
	 * values are the translated labels for the profile UI select.
	 *
	 * @return array
	 */
	public static function credential_categories() {
		return array(
			'Professional Certification' => __( 'Professional Certification', 'twt-aeo-ultimate' ),
			'Professional License'       => __( 'Professional License', 'twt-aeo-ultimate' ),
			'Degree'                     => __( 'Degree', 'twt-aeo-ultimate' ),
			'Certificate'                => __( 'Certificate', 'twt-aeo-ultimate' ),
			'Membership'                 => __( 'Professional Membership', 'twt-aeo-ultimate' ),
			'Accreditation'              => __( 'Accreditation', 'twt-aeo-ultimate' ),
		);
	}

	/**
	 * Sanitize a comma/newline-separated list of URLs into a clean comma-joined string.
	 * Invalid entries are dropped.
	 *
	 * @param string $raw
	 * @return string
	 */
	private static function sanitize_url_list( $raw ) {
		$urls = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out  = array();
		foreach ( $urls as $url ) {
			$url = sanitize_url( $url );
			if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$out[] = $url;
			}
		}
		return implode( ', ', $out );
	}

	public static function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['twtaeo_author_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['twtaeo_author_nonce'] ) ), 'twtaeo_author_profile_' . $user_id )
		) {
			return;
		}

		// Core fields.
		update_user_meta( $user_id, 'twtaeo_job_title',       sanitize_text_field( wp_unslash( $_POST['twtaeo_job_title'] ?? '' ) ) );
		update_user_meta( $user_id, 'twtaeo_credentials',     sanitize_text_field( wp_unslash( $_POST['twtaeo_credentials'] ?? '' ) ) );
		update_user_meta( $user_id, 'twtaeo_expertise',       sanitize_text_field( wp_unslash( $_POST['twtaeo_expertise'] ?? '' ) ) );
		update_user_meta( $user_id, 'twtaeo_years_experience', absint( wp_unslash( $_POST['twtaeo_years_experience'] ?? 0 ) ) );

		// Contextual authority statement.
		update_user_meta( $user_id, 'twtaeo_authority_snippet', sanitize_textarea_field( wp_unslash( $_POST['twtaeo_authority_snippet'] ?? '' ) ) );
		update_user_meta( $user_id, 'twtaeo_authority_snippet_show', isset( $_POST['twtaeo_authority_snippet_show'] ) ? '1' : '0' );

		// Social profiles.
		$social = array();
		foreach ( array_keys( self::$social_networks ) as $network ) {
			$url = sanitize_url( wp_unslash( $_POST[ 'twtaeo_social_' . $network ] ?? '' ) );
			if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$social[ $network ] = $url;
			} else {
				$social[ $network ] = '';
			}
		}
		update_user_meta( $user_id, 'twtaeo_social', TWTAEO_Custom_Schema_Writer::encode_for_meta( $social ) );

		// Certifications (POSTed as parallel arrays).
		$names          = array_map( 'sanitize_text_field', wp_unslash( $_POST['twtaeo_cert_name'] ?? array() ) );
		$organizations  = array_map( 'sanitize_text_field', wp_unslash( $_POST['twtaeo_cert_org'] ?? array() ) );
		$categories     = array_map( 'sanitize_text_field', wp_unslash( $_POST['twtaeo_cert_category'] ?? array() ) );
		$org_urls       = array_map( 'sanitize_url', wp_unslash( $_POST['twtaeo_cert_org_url'] ?? array() ) );
		$org_sameas     = array_map( 'sanitize_textarea_field', wp_unslash( $_POST['twtaeo_cert_org_sameas'] ?? array() ) );
		$credential_ids = array_map( 'sanitize_text_field', wp_unslash( $_POST['twtaeo_cert_id'] ?? array() ) );
		$issue_dates    = array_map( 'sanitize_text_field', wp_unslash( $_POST['twtaeo_cert_issue_date'] ?? array() ) );
		$expiry_dates   = array_map( 'sanitize_text_field', wp_unslash( $_POST['twtaeo_cert_expiry_date'] ?? array() ) );
		$verify_urls    = array_map( 'sanitize_url', wp_unslash( $_POST['twtaeo_cert_url'] ?? array() ) );
		$descriptions   = array_map( 'sanitize_textarea_field', wp_unslash( $_POST['twtaeo_cert_desc'] ?? array() ) );

		$certifications = array();
		foreach ( $names as $i => $name ) {
			if ( empty( $name ) ) {
				continue;
			}
			$category = $categories[ $i ] ?? '';
			if ( ! array_key_exists( $category, self::credential_categories() ) ) {
				$category = 'Professional Certification';
			}
			$certifications[] = array(
				'name'             => $name,
				'organization'     => $organizations[ $i ] ?? '',
				'category'         => $category,
				'org_url'          => filter_var( $org_urls[ $i ] ?? '', FILTER_VALIDATE_URL ) ? $org_urls[ $i ] : '',
				'org_sameas'       => self::sanitize_url_list( $org_sameas[ $i ] ?? '' ),
				'credential_id'    => $credential_ids[ $i ] ?? '',
				'issue_date'       => $issue_dates[ $i ] ?? '',
				'expiry_date'      => $expiry_dates[ $i ] ?? '',
				'verification_url' => filter_var( $verify_urls[ $i ] ?? '', FILTER_VALIDATE_URL ) ? $verify_urls[ $i ] : '',
				'description'      => $descriptions[ $i ] ?? '',
			);
		}

		update_user_meta( $user_id, 'twtaeo_certifications', TWTAEO_Custom_Schema_Writer::encode_for_meta( $certifications ) );
	}

	// ── Profile UI ─────────────────────────────────────────────────────────────

	/**
	 * Render AEO fields on the WP user profile/edit screen.
	 *
	 * @param WP_User $user
	 */
	public static function render_profile_fields( WP_User $user ) {
		$user_id    = $user->ID;
		$data       = self::get_author_data( $user_id );
		$social     = $data['social'];
		$certs      = $data['certifications'];
		$nonce      = wp_create_nonce( 'twtaeo_author_profile_' . $user_id );
		?>
		<input type="hidden" name="twtaeo_author_nonce" value="<?php echo esc_attr( $nonce ); ?>">

		<h2 style="margin-top:2em;padding-bottom:.5em;border-bottom:1px solid #dcdcde;">
			<?php esc_html_e( 'AEO Author Entity', 'twt-aeo-ultimate' ); ?>
		</h2>
		<p style="color:#646970;margin-top:0;">
			<?php esc_html_e( 'These fields strengthen E-E-A-T signals, author schema, and AI entity recognition.', 'twt-aeo-ultimate' ); ?>
		</p>

		<!-- Core Fields -->
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="twtaeo_job_title"><?php esc_html_e( 'Job Title', 'twt-aeo-ultimate' ); ?></label></th>
				<td>
					<input type="text" name="twtaeo_job_title" id="twtaeo_job_title"
						value="<?php echo esc_attr( $data['job_title'] ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. Senior Marketing Strategist', 'twt-aeo-ultimate' ); ?>">
					<p class="description"><?php esc_html_e( 'Used in Person schema as jobTitle.', 'twt-aeo-ultimate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="twtaeo_credentials"><?php esc_html_e( 'Credentials', 'twt-aeo-ultimate' ); ?></label></th>
				<td>
					<input type="text" name="twtaeo_credentials" id="twtaeo_credentials"
						value="<?php echo esc_attr( $data['credentials'] ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. MBA, Ph.D., CPA', 'twt-aeo-ultimate' ); ?>">
					<p class="description"><?php esc_html_e( 'Degrees and credential abbreviations shown after your name.', 'twt-aeo-ultimate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="twtaeo_expertise"><?php esc_html_e( 'Expertise Areas', 'twt-aeo-ultimate' ); ?></label></th>
				<td>
					<input type="text" name="twtaeo_expertise" id="twtaeo_expertise"
						value="<?php echo esc_attr( $data['expertise'] ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. SEO, Content Strategy, Analytics', 'twt-aeo-ultimate' ); ?>">
					<p class="description"><?php esc_html_e( 'Comma-separated. Used in knowsAbout schema property.', 'twt-aeo-ultimate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="twtaeo_years_experience"><?php esc_html_e( 'Years of Experience', 'twt-aeo-ultimate' ); ?></label></th>
				<td>
					<input type="number" name="twtaeo_years_experience" id="twtaeo_years_experience"
						value="<?php echo esc_attr( $data['years_experience'] ?: '' ); ?>"
						class="small-text" min="0" max="99">
				</td>
			</tr>
		</table>

		<!-- Social Profiles -->
		<h3><?php esc_html_e( 'Social Profiles (sameAs)', 'twt-aeo-ultimate' ); ?></h3>
		<p class="description" style="margin-bottom:1em;"><?php esc_html_e( 'These URLs are added to the sameAs array in your Person schema to establish entity identity across the web.', 'twt-aeo-ultimate' ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( self::$social_networks as $network => $info ) :
				$value = $social[ $network ] ?? '';
				?>
			<tr>
				<th><label for="twtaeo_social_<?php echo esc_attr( $network ); ?>"><?php echo esc_html( $info['label'] ); ?></label></th>
				<td>
					<input type="url" name="twtaeo_social_<?php echo esc_attr( $network ); ?>"
						id="twtaeo_social_<?php echo esc_attr( $network ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="regular-text"
						placeholder="<?php echo esc_attr( $info['placeholder'] ); ?>">
				</td>
			</tr>
			<?php endforeach; ?>
		</table>

		<!-- Certifications -->
		<h3><?php esc_html_e( 'Certifications', 'twt-aeo-ultimate' ); ?></h3>
		<p class="description" style="margin-bottom:1em;"><?php esc_html_e( 'Each certification is added to your Person schema as an EducationalOccupationalCredential.', 'twt-aeo-ultimate' ); ?></p>

		<div id="twt-aeo-certs-wrap">
			<?php
			if ( empty( $certs ) ) {
				// Show one empty row so the fields are visible; rows without a name are never saved.
				self::render_cert_row( 0, array() );
			} else {
				foreach ( $certs as $i => $cert ) {
					self::render_cert_row( $i, $cert );
				}
			}
			?>
		</div>

		<button type="button" id="twt-aeo-add-cert" class="button" style="margin-top:8px;">
			+ <?php esc_html_e( 'Add Certification', 'twt-aeo-ultimate' ); ?>
		</button>

		<!-- Cert row template (hidden) -->
		<template id="twt-aeo-cert-template">
			<?php self::render_cert_row( '__INDEX__', array() ); ?>
		</template>

		<!-- Contextual Authority Statement -->
		<h3><?php esc_html_e( 'Contextual Authority Statement', 'twt-aeo-ultimate' ); ?></h3>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'A short paragraph that spells out what your credentials mean — who issued them and what those institutions are. AI systems trained mostly on English content often cannot recognize regional or non-English credentials from the name alone; stating the relationship in plain prose (alongside the schema) makes the expertise machine-readable. Shown after your bio and included in your Person schema.', 'twt-aeo-ultimate' ); ?>
		</p>
		<textarea name="twtaeo_authority_snippet" id="twtaeo_authority_snippet" rows="3" class="large-text"
			placeholder="<?php esc_attr_e( 'e.g. Jane Doe is a First-Class Registered Architect certified by the Ministry of Land, Infrastructure, Transport and Tourism, the government body that licenses architects in Japan.', 'twt-aeo-ultimate' ); ?>"><?php echo esc_textarea( self::get_authority_snippet( $user_id ) ); ?></textarea>
		<p style="margin:8px 0 0;">
			<button type="button" id="twt-aeo-gen-authority" class="button" data-user="<?php echo esc_attr( $user_id ); ?>">
				<?php esc_html_e( 'Generate with AI', 'twt-aeo-ultimate' ); ?>
			</button>
			<span id="twt-aeo-authority-msg" style="margin-left:8px;color:#646970;"></span>
		</p>
		<p class="description" style="margin-top:4px;">
			<?php esc_html_e( 'Drafts from your certifications using your configured AI provider (one API call). Review and edit before saving — nothing is stored until you click "Update Profile".', 'twt-aeo-ultimate' ); ?>
		</p>
		<label style="display:block;margin-top:8px;">
			<input type="checkbox" name="twtaeo_authority_snippet_show" value="1" <?php checked( self::authority_snippet_shown( $user_id ) ); ?>>
			<?php esc_html_e( 'Append this statement to my public author bio', 'twt-aeo-ultimate' ); ?>
		</label>

		<?php
		ob_start();
		?>
		( function() {
			var wrap  = document.getElementById( 'twt-aeo-certs-wrap' );
			var btn   = document.getElementById( 'twt-aeo-add-cert' );
			var tmpl  = document.getElementById( 'twt-aeo-cert-template' ).innerHTML;
			var index = <?php echo (int) max( 1, count( $certs ) ); ?>;

			btn.addEventListener( 'click', function() {
				var html = tmpl.replace( /__INDEX__/g, index++ );
				var div  = document.createElement( 'div' );
				div.innerHTML = html;
				wrap.appendChild( div.firstElementChild );
			} );

			wrap.addEventListener( 'click', function( e ) {
				if ( e.target.classList.contains( 'twt-aeo-remove-cert' ) ) {
					e.target.closest( '.twt-aeo-cert-row' ).remove();
				}
			} );

			var genBtn = document.getElementById( 'twt-aeo-gen-authority' );
			if ( genBtn ) {
				genBtn.addEventListener( 'click', function() {
					var out   = document.getElementById( 'twtaeo_authority_snippet' );
					var msg   = document.getElementById( 'twt-aeo-authority-msg' );
					var nonce = document.querySelector( 'input[name="twtaeo_author_nonce"]' );

					genBtn.disabled = true;
					msg.textContent = '<?php echo esc_js( __( 'Generating…', 'twt-aeo-ultimate' ) ); ?>';

					var body = new URLSearchParams();
					body.append( 'action', 'twtaeo_generate_authority' );
					body.append( 'user_id', genBtn.dataset.user );
					body.append( 'nonce', nonce ? nonce.value : '' );

					fetch( ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					} ).then( function( r ) { return r.json(); } ).then( function( r ) {
						genBtn.disabled = false;
						if ( r.success && r.data && r.data.snippet ) {
							out.value = r.data.snippet;
							msg.textContent = '<?php echo esc_js( __( 'Draft ready — review it, then click "Update Profile" to save.', 'twt-aeo-ultimate' ) ); ?>';
						} else {
							msg.textContent = ( r.data && 'string' === typeof r.data ) ? r.data : '<?php echo esc_js( __( 'Generation failed.', 'twt-aeo-ultimate' ) ); ?>';
						}
					} ).catch( function() {
						genBtn.disabled = false;
						msg.textContent = '<?php echo esc_js( __( 'Request failed — check your connection and try again.', 'twt-aeo-ultimate' ) ); ?>';
					} );
				} );
			}
		} )();
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}

	/**
	 * Render a single certification row.
	 *
	 * @param int|string $index Row index (or __INDEX__ for the template).
	 * @param array      $cert  Certification data.
	 */
	private static function render_cert_row( $index, array $cert ) {
		$name       = $cert['name'] ?? '';
		$org        = $cert['organization'] ?? '';
		$category   = $cert['category'] ?? 'Professional Certification';
		$org_url    = $cert['org_url'] ?? '';
		$org_sameas = $cert['org_sameas'] ?? '';
		$cred_id    = $cert['credential_id'] ?? '';
		$issue      = $cert['issue_date'] ?? '';
		$expiry     = $cert['expiry_date'] ?? '';
		$url        = $cert['verification_url'] ?? '';
		$desc       = $cert['description'] ?? '';
		$idx        = $index;
		?>
		<div class="twt-aeo-cert-row" style="background:#f9f9f9;border:1px solid #dcdcde;border-radius:6px;padding:16px;margin-bottom:12px;position:relative;">
			<button type="button" class="twt-aeo-remove-cert" title="<?php esc_attr_e( 'Remove', 'twt-aeo-ultimate' ); ?>"
				style="position:absolute;top:10px;right:10px;background:none;border:none;cursor:pointer;font-size:18px;color:#dc2626;line-height:1;">
				&times;
			</button>
			<table class="form-table" role="presentation" style="margin:0;">
				<tr>
					<th style="width:160px;padding:4px 10px 4px 0;">
						<label><?php esc_html_e( 'Certification Name', 'twt-aeo-ultimate' ); ?> <span style="color:#dc2626;">*</span></label>
					</th>
					<td style="padding:4px 0;">
						<input type="text" name="twtaeo_cert_name[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. First-Class Registered Architect', 'twt-aeo-ultimate' ); ?>">
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Credential Type', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<select name="twtaeo_cert_category[<?php echo esc_attr( $idx ); ?>]">
							<?php foreach ( self::credential_categories() as $cat_key => $cat_label ) : ?>
								<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $category, $cat_key ); ?>><?php echo esc_html( $cat_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" style="margin:2px 0 0;"><?php esc_html_e( 'Output as credentialCategory. Use "Professional License" for government/regulatory licenses.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Issuing Organization', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<input type="text" name="twtaeo_cert_org[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $org ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Google, HubSpot, AWS', 'twt-aeo-ultimate' ); ?>">
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Organization Website', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<input type="url" name="twtaeo_cert_org_url[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_url( $org_url ); ?>" class="regular-text" placeholder="https://...">
						<p class="description" style="margin:2px 0 0;"><?php esc_html_e( 'Official website of the issuing organization.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Organization Wikipedia / Wikidata', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<input type="text" name="twtaeo_cert_org_sameas[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $org_sameas ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'https://en.wikipedia.org/wiki/..., https://www.wikidata.org/wiki/Q...', 'twt-aeo-ultimate' ); ?>">
						<p class="description" style="margin:2px 0 0;"><?php esc_html_e( 'Comma-separated. Grounds the issuing body to a known entity so AI models can recognize what the credential represents — especially important for regional or non-English credentials.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Credential ID', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<input type="text" name="twtaeo_cert_id[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $cred_id ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional credential ID or certificate number', 'twt-aeo-ultimate' ); ?>">
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Issue Date', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<input type="date" name="twtaeo_cert_issue_date[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $issue ); ?>">
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Expiry Date', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<input type="date" name="twtaeo_cert_expiry_date[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $expiry ); ?>">
						<p class="description" style="margin:2px 0 0;"><?php esc_html_e( 'Leave blank if the certification does not expire.', 'twt-aeo-ultimate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Verification URL', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<input type="url" name="twtaeo_cert_url[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_url( $url ); ?>" class="regular-text" placeholder="https://...">
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Description / Notes', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<textarea name="twtaeo_cert_desc[<?php echo esc_attr( $idx ); ?>]" rows="2" class="large-text"><?php echo esc_textarea( $desc ); ?></textarea>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}
