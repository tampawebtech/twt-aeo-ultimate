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
		$raw = get_user_meta( $user_id, 'twtaeo_certifications', true );
		if ( empty( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get decoded social profiles object for a user.
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function get_social( $user_id ) {
		$raw = get_user_meta( $user_id, 'twtaeo_social', true );
		if ( ! empty( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Fall back to native WP keys.
		$user = get_userdata( $user_id );
		return array(
			'twitter'  => get_user_meta( $user_id, 'twitter', true ),
			'website'  => $user ? $user->user_url : '',
		);
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
			$result[] = array(
				'user'         => $user,
				'data'         => self::get_author_data( $user->ID ),
				'completeness' => self::get_completeness( $user->ID ),
			);
		}

		return $result;
	}

	// ── Save ───────────────────────────────────────────────────────────────────

	/**
	 * Save profile fields on profile update.
	 *
	 * @param int $user_id
	 */
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
		update_user_meta( $user_id, 'twtaeo_social', wp_json_encode( $social ) );

		// Certifications (POSTed as parallel arrays).
		$names          = array_map( 'sanitize_text_field', wp_unslash( $_POST['twtaeo_cert_name'] ?? array() ) );
		$organizations  = array_map( 'sanitize_text_field', wp_unslash( $_POST['twtaeo_cert_org'] ?? array() ) );
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
			$certifications[] = array(
				'name'             => $name,
				'organization'     => $organizations[ $i ] ?? '',
				'credential_id'    => $credential_ids[ $i ] ?? '',
				'issue_date'       => $issue_dates[ $i ] ?? '',
				'expiry_date'      => $expiry_dates[ $i ] ?? '',
				'verification_url' => filter_var( $verify_urls[ $i ] ?? '', FILTER_VALIDATE_URL ) ? $verify_urls[ $i ] : '',
				'description'      => $descriptions[ $i ] ?? '',
			);
		}

		update_user_meta( $user_id, 'twtaeo_certifications', wp_json_encode( $certifications ) );
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
			<?php if ( ! empty( $certs ) ) :
				foreach ( $certs as $i => $cert ) :
					self::render_cert_row( $i, $cert );
				endforeach;
			endif; ?>
		</div>

		<button type="button" id="twt-aeo-add-cert" class="button" style="margin-top:8px;">
			+ <?php esc_html_e( 'Add Certification', 'twt-aeo-ultimate' ); ?>
		</button>

		<!-- Cert row template (hidden) -->
		<script type="text/html" id="twt-aeo-cert-template">
			<?php self::render_cert_row( '__INDEX__', array() ); ?>
		</script>

		<?php
		ob_start();
		?>
		( function() {
			var wrap  = document.getElementById( 'twt-aeo-certs-wrap' );
			var btn   = document.getElementById( 'twt-aeo-add-cert' );
			var tmpl  = document.getElementById( 'twt-aeo-cert-template' ).innerHTML;
			var index = <?php echo count( $certs ); ?>;

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
		$name     = $cert['name'] ?? '';
		$org      = $cert['organization'] ?? '';
		$cred_id  = $cert['credential_id'] ?? '';
		$issue    = $cert['issue_date'] ?? '';
		$expiry   = $cert['expiry_date'] ?? '';
		$url      = $cert['verification_url'] ?? '';
		$desc     = $cert['description'] ?? '';
		$idx      = $index;
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
						<input type="text" name="twtaeo_cert_name[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" required>
					</td>
				</tr>
				<tr>
					<th style="padding:4px 10px 4px 0;"><label><?php esc_html_e( 'Issuing Organization', 'twt-aeo-ultimate' ); ?></label></th>
					<td style="padding:4px 0;">
						<input type="text" name="twtaeo_cert_org[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $org ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Google, HubSpot, AWS', 'twt-aeo-ultimate' ); ?>">
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
