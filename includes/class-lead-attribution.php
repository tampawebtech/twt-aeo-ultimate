<?php
/**
 * Lead Attribution
 *
 * Tags each lead — a form entry, an email-link click, a phone-link tap — with
 * how likely it is that an AI assistant sent it, and the evidence:
 *
 *   confirmed     the visit that converted came from an AI engine's referrer
 *                 or utm_source (chatgpt.com, perplexity.ai, …)
 *   correlated    no AI referrer, but an AI assistant's live fetcher
 *                 (ChatGPT-User, Claude-User, Perplexity-User) read the
 *                 landing page shortly before a direct visit landed on it
 *   self_reported the visitor picked an AI tool in "How did you hear about us?"
 *   earlier_ai    this visit came another way, but the visitor's first visit
 *                 came from an AI engine
 *   none          no AI signal
 *
 * Each lead gets ONE primary tier (the list above is the precedence), so a
 * lead is never counted twice; the other signals ride along as evidence.
 *
 * How the data flows: public/js/twt-lead-attribution.js keeps first and last
 * touch in the visitor's own browser storage and, on submit, adds one hidden
 * input (twtaeo_attr) to the form. Gravity Forms, Contact Form 7 and WPForms
 * are read server-side from that input when the form plugin confirms the
 * submission; other forms and email/phone clicks arrive as a beacon. Nothing
 * here stores IP addresses, user agents, names or email addresses.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Lead_Attribution {

	const MODULE         = 'lead-attribution';
	const TABLE          = 'twtaeo_leads';
	const DB_VERSION     = '1';
	const OPTION_DB      = 'twtaeo_leads_db_version';
	const OPTION_HITS    = 'twtaeo_lead_agent_hits';
	const OPTION_SET     = 'twtaeo_lead_settings';
	const FIELD          = 'twtaeo_attr';
	const HITS_KEEP_DAYS = 7;
	const HITS_MAX       = 2000;
	const KEEP_DAYS      = 365;
	const CRON_HOOK      = 'twtaeo_leads_prune';

	/**
	 * User agents that fetch a page live, while a person waits for an answer.
	 * Index crawlers (GPTBot, ClaudeBot, OAI-SearchBot) are left out on
	 * purpose: they read pages for training or the index, not for someone
	 * who might click through in the next few minutes.
	 *
	 * @var array<string,string> UA substring => engine label.
	 */
	private static $live_agents = array(
		'ChatGPT-User'    => 'ChatGPT',
		'Claude-User'     => 'Claude',
		'Perplexity-User' => 'Perplexity',
		'MistralAI-User'  => 'Le Chat',
	);

	/**
	 * Words in a "How did you hear about us?" answer that name an AI tool.
	 *
	 * @var array<string,string> lowercase needle => engine label.
	 */
	private static $self_report_words = array(
		'chatgpt'    => 'ChatGPT',
		'chat gpt'   => 'ChatGPT',
		'openai'     => 'ChatGPT',
		'gemini'     => 'Gemini',
		'bard'       => 'Gemini',
		'perplexity' => 'Perplexity',
		'copilot'    => 'Copilot',
		'claude'     => 'Claude',
		'deepseek'   => 'DeepSeek',
		'grok'       => 'Grok',
		'meta ai'    => 'Meta AI',
		'le chat'    => 'Le Chat',
		'mistral'    => 'Le Chat',
		'ai tool'    => 'AI (unspecified)',
		'ai assistant' => 'AI (unspecified)',
		'ai chat'    => 'AI (unspecified)',
		'chatbot'    => 'AI (unspecified)',
	);

	/**
	 * Field labels/names that mark a "How did you hear about us?" question.
	 *
	 * @var string[]
	 */
	private static $source_question_words = array( 'hear', 'find us', 'found us', 'referr', 'source', 'how did you' );

	/** @var array<string,array> Attribution already resolved this request, by token. */
	private static $resolved = array();

	/** @var string[] Recipients of the WPForms notification being built. */
	private static $wpforms_to = array();

	// ── Registration ─────────────────────────────────────────────────────────

	public static function is_module_active() {
		return in_array( self::MODULE, (array) get_option( TWTAEO_Module_Loader::OPTION_KEY, array() ), true );
	}

	public static function init() {
		if ( ! self::is_module_active() ) {
			return;
		}

		add_action( 'admin_init', array( __CLASS__, 'maybe_create_table' ) );
		add_action( 'wp', array( __CLASS__, 'record_agent_hit' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// "Delay JavaScript" in performance plugins holds scripts back until the
		// visitor interacts. A visitor who autofills a form and clicks Submit
		// wakes the script with the same click that sends the form, so the
		// attribution never makes it in. The script is small and only listens.
		add_filter( 'wphb_delay_js_exclusions', array( __CLASS__, 'optimizer_exclusions' ) );   // Hummingbird.
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'optimizer_exclusions' ) ); // WP Rocket.
		add_filter( 'script_loader_tag', array( __CLASS__, 'no_delay_tag' ), 10, 2 );
		add_filter( 'wp_inline_script_attributes', array( __CLASS__, 'no_delay_inline' ), 10, 2 );

		add_action( 'wp_ajax_twtaeo_lead_beacon', array( __CLASS__, 'ajax_beacon' ) );
		add_action( 'wp_ajax_nopriv_twtaeo_lead_beacon', array( __CLASS__, 'ajax_beacon' ) );

		// Gravity Forms.
		add_action( 'gform_after_submission', array( __CLASS__, 'gf_after_submission' ), 10, 2 );
		add_filter( 'gform_notification', array( __CLASS__, 'gf_notification' ), 10, 3 );

		// Contact Form 7.
		add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'cf7_before_send_mail' ), 10, 1 );
		add_filter( 'wpcf7_mail_components', array( __CLASS__, 'cf7_mail_components' ), 10, 3 );

		// WPForms.
		add_action( 'wpforms_process_complete', array( __CLASS__, 'wpforms_complete' ), 10, 4 );
		add_filter( 'wpforms_emails_mailer_to_email', array( __CLASS__, 'wpforms_capture_to' ), 10, 1 );
		add_filter( 'wpforms_emails_notifications_message', array( __CLASS__, 'wpforms_email_message' ), 10, 1 );
		add_filter( 'wpforms_email_message', array( __CLASS__, 'wpforms_email_message' ), 10, 1 );

		// Forminator.
		add_action( 'forminator_custom_form_submit_before_set_fields', array( __CLASS__, 'forminator_before_set_fields' ), 10, 3 );
		add_filter( 'forminator_custom_form_mail_admin_message', array( __CLASS__, 'forminator_admin_message' ), 10, 6 );

		add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function maybe_create_table() {
		global $wpdb;
		if ( get_option( self::OPTION_DB ) === self::DB_VERSION ) {
			return;
		}
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			token varchar(40) NOT NULL,
			kind varchar(20) NOT NULL DEFAULT 'form',
			via varchar(20) NOT NULL DEFAULT '',
			form_ref varchar(100) NOT NULL DEFAULT '',
			entry_id varchar(40) NOT NULL DEFAULT '',
			confirmed tinyint(1) NOT NULL DEFAULT 0,
			page_url varchar(500) NOT NULL DEFAULT '',
			landing_url varchar(500) NOT NULL DEFAULT '',
			landing_ref varchar(255) NOT NULL DEFAULT '',
			landing_at datetime NULL,
			first_source varchar(255) NOT NULL DEFAULT '',
			first_at datetime NULL,
			tier varchar(20) NOT NULL DEFAULT 'none',
			engine varchar(40) NOT NULL DEFAULT '',
			self_reported varchar(100) NOT NULL DEFAULT '',
			evidence text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY created_at (created_at),
			KEY tier (tier)
		) {$charset};" );
		update_option( self::OPTION_DB, self::DB_VERSION, false );
	}

	// ── Settings ─────────────────────────────────────────────────────────────

	public static function get_settings() {
		$s = get_option( self::OPTION_SET, array() );
		return array(
			'window_minutes' => isset( $s['window_minutes'] ) ? max( 1, min( 240, (int) $s['window_minutes'] ) ) : 30,
			'email_line'     => isset( $s['email_line'] ) ? (bool) $s['email_line'] : true,
		);
	}

	public static function save_settings( $window_minutes, $email_line ) {
		update_option(
			self::OPTION_SET,
			array(
				'window_minutes' => max( 1, min( 240, (int) $window_minutes ) ),
				'email_line'     => (bool) $email_line,
			),
			false
		);
	}

	// ── Front end ────────────────────────────────────────────────────────────

	public static function enqueue() {
		wp_enqueue_script(
			'twtaeo-lead-attribution',
			TWTAEO_PLUGIN_URL . 'public/js/twt-lead-attribution.js',
			array(),
			TWTAEO_VERSION,
			true
		);
		wp_localize_script(
			'twtaeo-lead-attribution',
			'twtaeoLeadAttr',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'field'   => self::FIELD,
				'host'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			)
		);
	}

	/**
	 * Patterns matched against the script tag (its id and src) and the inline
	 * config, so both run on page load.
	 *
	 * @param array $exclusions Existing exclusions.
	 * @return array
	 */
	public static function optimizer_exclusions( $exclusions ) {
		$exclusions   = (array) $exclusions;
		$exclusions[] = 'twtaeo-lead-attribution';
		$exclusions[] = 'twtaeoLeadAttr';
		return $exclusions;
	}

	/**
	 * Opt-out attributes honoured by LiteSpeed Cache, Cloudflare Rocket
	 * Loader, PageSpeed and others that read them from the tag itself.
	 */
	public static function no_delay_tag( $tag, $handle ) {
		if ( 'twtaeo-lead-attribution' !== $handle || false !== strpos( $tag, 'data-no-optimize' ) ) {
			return $tag;
		}
		return str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer ', $tag );
	}

	public static function no_delay_inline( $attributes, $javascript = '' ) {
		if ( isset( $attributes['id'] ) && 0 === strpos( (string) $attributes['id'], 'twtaeo-lead-attribution-js' ) ) {
			$attributes['data-no-optimize'] = '1';
			$attributes['data-no-defer']    = '1';
			$attributes['data-no-delay']    = '1';
			$attributes['data-cfasync']     = 'false';
		}
		return $attributes;
	}

	/**
	 * Remember live AI fetches (ChatGPT-User etc.) of front-end pages so a
	 * direct visit that lands on the same page soon after can be matched.
	 * A page served from a full-page cache never reaches PHP, so on cached
	 * hosts some fetches are missed — the correlated tier undercounts there,
	 * it never invents a match.
	 */
	public static function record_agent_hit() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a fixed list only.
		if ( '' === $ua || isset( $_SERVER['HTTP_X_TWTAEO_BOT_VIEW'] ) ) {
			return;
		}
		$engine = '';
		$agent  = '';
		foreach ( self::$live_agents as $needle => $label ) {
			if ( false !== stripos( $ua, $needle ) ) {
				$engine = $label;
				$agent  = $needle;
				break;
			}
		}
		if ( '' === $engine ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = self::normalize_path( $uri );

		$hits   = get_option( self::OPTION_HITS, array() );
		$hits   = is_array( $hits ) ? $hits : array();
		$cutoff = time() - self::HITS_KEEP_DAYS * DAY_IN_SECONDS;
		$hits   = array_values(
			array_filter(
				$hits,
				function ( $h ) use ( $cutoff ) {
					return isset( $h['t'] ) && $h['t'] >= $cutoff;
				}
			)
		);
		array_unshift(
			$hits,
			array(
				'p' => $path,
				't' => time(),
				'e' => $engine,
				'a' => $agent,
			)
		);
		if ( count( $hits ) > self::HITS_MAX ) {
			$hits = array_slice( $hits, 0, self::HITS_MAX );
		}
		update_option( self::OPTION_HITS, $hits, false );
	}

	// ── Beacon (forms without an adapter, email/phone clicks) ────────────────

	public static function ajax_beacon() {
		// No nonce on purpose: this runs for logged-out visitors on pages a
		// full-page cache may have served for days, so any nonce would be
		// stale. The payload is validated field by field and rate-limited.
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $kind, array( 'form', 'email_click', 'phone_tap' ), true ) ) {
			wp_send_json_error( 'invalid_kind', 400 );
		}
		if ( ! self::within_rate_limit() ) {
			wp_send_json_error( 'rate_limited', 429 );
		}

		$raw  = isset( $_POST['attr'] ) ? wp_unslash( $_POST['attr'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitized field by field in parse_payload().
		$data = self::parse_payload( $raw );
		if ( ! $data ) {
			wp_send_json_error( 'invalid_payload', 400 );
		}

		$form_ref = isset( $_POST['form_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['form_ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// HubSpot forms post to HubSpot, never to WordPress, so their success
		// event in the browser is the only confirmation there is.
		$via     = ( isset( $_POST['via'] ) && 'hubspot' === $_POST['via'] && 'form' === $kind ) ? 'hubspot' : 'beacon'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$entry   = 'hubspot' === $via && isset( $_POST['entry'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['entry'] ) ), 0, 40 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$answers = array();
		if ( 'hubspot' === $via && isset( $_POST['answers'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_answers = json_decode( (string) wp_unslash( $_POST['answers'] ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded, then each pair sanitized below.
			foreach ( array_slice( is_array( $raw_answers ) ? $raw_answers : array(), 0, 10, true ) as $name => $value ) {
				$answers[ substr( sanitize_text_field( (string) $name ), 0, 100 ) ] = substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, 200 );
			}
		}

		$attr = self::resolve( $data, self::self_report( $answers ) );
		self::save( $attr, $kind, $via, substr( $form_ref, 0, 100 ), $entry, 'hubspot' === $via );
		wp_send_json_success();
	}

	/**
	 * At most 30 beacons per visitor network per hour. The address is hashed
	 * into a transient key and never stored.
	 */
	private static function within_rate_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'twtaeo_lb_' . substr( md5( $ip . wp_salt( 'nonce' ) ), 0, 16 );
		$n   = (int) get_transient( $key );
		if ( $n >= 30 ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	// ── Gravity Forms ────────────────────────────────────────────────────────

	public static function gf_after_submission( $entry, $form ) {
		$attr = self::from_post( self::gf_answers( $entry, $form ) );
		if ( ! $attr ) {
			return;
		}
		$form_ref = 'GF #' . ( isset( $form['id'] ) ? (int) $form['id'] : 0 ) . ( ! empty( $form['title'] ) ? ' ' . $form['title'] : '' );
		self::save( $attr, 'form', 'gravityforms', $form_ref, isset( $entry['id'] ) ? (string) $entry['id'] : '', true );
	}

	public static function gf_notification( $notification, $form, $entry ) {
		$settings = self::get_settings();
		if ( ! $settings['email_line'] || ! is_array( $notification ) ) {
			return $notification;
		}
		// Notifications addressed to the visitor (an email field, or a merge tag
		// that points at one) are confirmations, not the owner's copy.
		$to = (string) ( $notification['to'] ?? '' );
		if ( 'field' === ( $notification['toType'] ?? '' ) || preg_match( '/\{[^}]*:\d+/', $to ) ) {
			return $notification;
		}
		$attr = self::from_post( self::gf_answers( $entry, $form ) );
		if ( ! $attr ) {
			return $notification;
		}
		$is_html = ! isset( $notification['message_format'] ) || 'html' === $notification['message_format'];
		$line    = self::email_line( $attr );
		$notification['message'] = (string) ( $notification['message'] ?? '' ) . ( $is_html ? '<p>' . esc_html( $line ) . '</p>' : "\n\n" . $line );
		return $notification;
	}

	/**
	 * Label => answer pairs from a Gravity Forms entry, for self-report detection.
	 */
	private static function gf_answers( $entry, $form ) {
		$out = array();
		if ( empty( $form['fields'] ) || ! is_array( $entry ) ) {
			return $out;
		}
		foreach ( $form['fields'] as $field ) {
			$id    = is_object( $field ) ? $field->id : ( $field['id'] ?? '' );
			$label = is_object( $field ) ? (string) $field->label : (string) ( $field['label'] ?? '' );
			$value = '';
			foreach ( $entry as $k => $v ) {
				if ( (string) $k === (string) $id || 0 === strpos( (string) $k, $id . '.' ) ) {
					$value .= ' ' . (string) $v;
				}
			}
			$out[ $label ] = trim( $value );
		}
		return $out;
	}

	// ── Contact Form 7 ───────────────────────────────────────────────────────

	public static function cf7_before_send_mail( $contact_form ) {
		$attr = self::from_post( self::cf7_answers() );
		if ( ! $attr ) {
			return;
		}
		$id       = is_object( $contact_form ) && method_exists( $contact_form, 'id' ) ? (int) $contact_form->id() : 0;
		$title    = is_object( $contact_form ) && method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : '';
		self::save( $attr, 'form', 'cf7', trim( 'CF7 #' . $id . ' ' . $title ), '', true );
	}

	public static function cf7_mail_components( $components, $contact_form = null, $mail = null ) {
		$settings = self::get_settings();
		if ( ! $settings['email_line'] || ! is_array( $components ) || ! isset( $components['body'] ) ) {
			return $components;
		}
		// Only the main mail (to the site owner), never Mail (2) — the
		// autoresponder that goes back to the visitor.
		if ( is_object( $mail ) && method_exists( $mail, 'name' ) && 'mail' !== $mail->name() ) {
			return $components;
		}
		$attr = self::from_post( self::cf7_answers() );
		if ( ! $attr ) {
			return $components;
		}
		$is_html = is_object( $mail ) && method_exists( $mail, 'get' ) && $mail->get( 'use_html' );
		$line    = self::email_line( $attr );
		$components['body'] .= $is_html ? '<p>' . esc_html( $line ) . '</p>' : "\n\n" . $line;
		return $components;
	}

	private static function cf7_answers() {
		$out = array();
		if ( class_exists( 'WPCF7_Submission' ) ) {
			$sub = WPCF7_Submission::get_instance();
			if ( $sub ) {
				foreach ( (array) $sub->get_posted_data() as $name => $value ) {
					$out[ (string) $name ] = is_array( $value ) ? implode( ' ', array_map( 'strval', $value ) ) : (string) $value;
				}
			}
		}
		return $out;
	}

	// ── WPForms ──────────────────────────────────────────────────────────────

	public static function wpforms_complete( $fields, $entry, $form_data, $entry_id ) {
		$attr = self::from_post( self::wpforms_answers( $fields ) );
		if ( ! $attr ) {
			return;
		}
		$id    = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;
		$title = isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : '';
		self::save( $attr, 'form', 'wpforms', trim( 'WPForms #' . $id . ' ' . $title ), $entry_id ? (string) $entry_id : '', true );
	}

	public static function wpforms_email_message( $message ) {
		$settings = self::get_settings();
		if ( ! $settings['email_line'] ) {
			return $message;
		}
		$fields = array();
		if ( function_exists( 'wpforms' ) && isset( wpforms()->process->fields ) ) {
			$fields = (array) wpforms()->process->fields;
		}
		// Skip the confirmation that goes to the address the visitor typed in.
		foreach ( $fields as $f ) {
			if ( is_array( $f ) && 'email' === ( $f['type'] ?? '' ) && '' !== (string) ( $f['value'] ?? '' ) ) {
				foreach ( self::$wpforms_to as $to ) {
					if ( 0 === strcasecmp( trim( (string) $to ), trim( (string) $f['value'] ) ) ) {
						return $message;
					}
				}
			}
		}
		$attr = self::from_post( self::wpforms_answers( $fields ) );
		if ( ! $attr ) {
			return $message;
		}
		$line = self::email_line( $attr );
		// WPForms' default template is HTML; its plain-text template keeps
		// newlines. A paragraph tag reads fine in both.
		return (string) $message . ( false !== stripos( (string) $message, '<' ) ? '<p>' . esc_html( $line ) . '</p>' : "\n\n" . $line );
	}

	/**
	 * Note who the WPForms notification being built is addressed to; the
	 * Mailer keeps it private, and the message filter runs right after.
	 */
	public static function wpforms_capture_to( $email ) {
		self::$wpforms_to = (array) $email;
		return $email;
	}

	private static function wpforms_answers( $fields ) {
		$out = array();
		foreach ( (array) $fields as $f ) {
			if ( is_array( $f ) ) {
				$out[ (string) ( $f['name'] ?? '' ) ] = (string) ( $f['value'] ?? '' );
			}
		}
		return $out;
	}

	// ── Forminator ───────────────────────────────────────────────────────────

	public static function forminator_before_set_fields( $entry, $form_id, $field_data_array ) {
		// The same hook runs when a visitor saves a draft or abandons a form;
		// only a finished entry is a lead.
		if ( is_object( $entry ) && ( ! empty( $entry->draft_id ) || ( isset( $entry->status ) && 'active' !== $entry->status ) ) ) {
			return;
		}
		$attr = self::from_post( self::forminator_answers( $form_id, $field_data_array ) );
		if ( ! $attr ) {
			return;
		}
		$entry_id = is_object( $entry ) && ! empty( $entry->entry_id ) ? (string) $entry->entry_id : '';
		self::save( $attr, 'form', 'forminator', trim( 'Forminator #' . (int) $form_id . ' ' . get_the_title( (int) $form_id ) ), $entry_id, true );
	}

	/**
	 * The site owner's copy only. Forminator sends every notification through
	 * this one filter, confirmations to the visitor included, so the line goes
	 * only where the notification says it's addressed to the owner.
	 *
	 * @param string     $message      Email body.
	 * @param object     $custom_form  Form model.
	 * @param array      $data         Submitted data.
	 * @param object     $entry        Entry model.
	 * @param object     $mailer       Forminator's mail class.
	 * @param array|null $notification This notification's settings (Forminator 1.57+).
	 * @return string
	 */
	public static function forminator_admin_message( $message, $custom_form = null, $data = array(), $entry = null, $mailer = null, $notification = null ) {
		$settings = self::get_settings();
		if ( ! $settings['email_line'] || ! self::forminator_is_owner_copy( $notification ) ) {
			return $message;
		}
		$form_id = is_object( $custom_form ) && isset( $custom_form->id ) ? (int) $custom_form->id : 0;
		$attr    = self::from_post( self::forminator_answers( $form_id, $data ) );
		if ( ! $attr ) {
			return $message;
		}
		return (string) $message . '<p>' . esc_html( self::email_line( $attr ) ) . '</p>';
	}

	/**
	 * Whether a Forminator notification goes only to fixed addresses. A recipient
	 * taken from a form field ({email-1}) is someone the visitor named: them, or
	 * a colleague. Forminator before 1.57 doesn't say which notification this is,
	 * so there the line stays out: a missing line costs less than a customer
	 * reading a note about how they were tracked.
	 *
	 * @param array|null $notification Notification settings.
	 * @return bool
	 */
	private static function forminator_is_owner_copy( $notification ) {
		if ( ! is_array( $notification ) || 'save_draft' === ( $notification['type'] ?? '' ) ) {
			return false;
		}
		$to = (string) ( $notification['recipients'] ?? '' );
		if ( 'routing' === ( $notification['email-recipients'] ?? '' ) ) {
			$to = implode( ',', array_column( (array) ( $notification['routing'] ?? array() ), 'email' ) );
		}
		return '' !== trim( $to ) && ! preg_match( '/\{[^}]+\}/', $to );
	}

	/**
	 * Label => answer pairs. Forminator names fields by slug (select-1), so
	 * the labels come from the form definition.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $data    Either field_data_array (list of name/value) or slug => value.
	 * @return array
	 */
	private static function forminator_answers( $form_id, $data ) {
		$labels = array();
		if ( $form_id && class_exists( 'Forminator_API' ) ) {
			$fields = Forminator_API::get_form_fields( $form_id );
			if ( is_array( $fields ) ) {
				foreach ( $fields as $f ) {
					if ( is_object( $f ) && ! empty( $f->slug ) ) {
						$labels[ $f->slug ] = (string) $f->__get( 'field_label' );
					}
				}
			}
		}
		$out = array();
		foreach ( (array) $data as $key => $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) ) {
				$slug  = (string) $item['name'];
				$value = $item['value'] ?? '';
			} else {
				$slug  = (string) $key;
				$value = $item;
			}
			$value = is_array( $value ) ? implode( ' ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) ) : (string) $value;
			$out[ trim( ( $labels[ $slug ] ?? '' ) . ' ' . $slug ) ] = $value;
		}
		return $out;
	}

	// ── Resolution ───────────────────────────────────────────────────────────

	/**
	 * Resolve attribution from the hidden input in the current form post.
	 * Cached per token, so the email filter and the completion hook — which
	 * fire in a different order in each form plugin — always agree.
	 *
	 * @param array $answers Field label/name => answer, for self-report detection.
	 * @return array|null
	 */
	private static function from_post( $answers ) {
		if ( empty( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the form plugin verified its own submission.
			return null;
		}
		$data = self::parse_payload( wp_unslash( $_POST[ self::FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in parse_payload().
		if ( ! $data ) {
			return null;
		}
		if ( isset( self::$resolved[ $data['token'] ] ) ) {
			return self::$resolved[ $data['token'] ];
		}
		$attr                              = self::resolve( $data, self::self_report( $answers ) );
		self::$resolved[ $data['token'] ] = $attr;
		return $attr;
	}

	/**
	 * Validate the browser payload. Every field is optional except the token.
	 *
	 * @param mixed $raw JSON string.
	 * @return array|null
	 */
	private static function parse_payload( $raw ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > 4000 ) {
			return null;
		}
		$j = json_decode( $raw, true );
		if ( ! is_array( $j ) || empty( $j['token'] ) || ! preg_match( '/^[a-z0-9]{12,40}$/', (string) $j['token'] ) ) {
			return null;
		}
		$touch = function ( $t ) {
			if ( ! is_array( $t ) ) {
				return null;
			}
			return array(
				'ref' => substr( esc_url_raw( (string) ( $t['ref'] ?? '' ) ), 0, 255 ),
				'utm' => substr( sanitize_text_field( (string) ( $t['utm'] ?? '' ) ), 0, 100 ),
				'url' => substr( esc_url_raw( (string) ( $t['url'] ?? '' ) ), 0, 500 ),
				// Age in seconds, measured by the browser's own clock on both
				// ends, so a wrong clock on the visitor's device cancels out.
				'age' => max( 0, min( 400 * DAY_IN_SECONDS, (int) ( $t['age'] ?? 0 ) ) ),
			);
		};
		return array(
			'token' => (string) $j['token'],
			'page'  => substr( esc_url_raw( (string) ( $j['page'] ?? '' ) ), 0, 500 ),
			'first' => $touch( $j['first'] ?? null ),
			'land'  => $touch( $j['land'] ?? null ),
		);
	}

	/**
	 * Work out the tier for one lead.
	 *
	 * @param array  $data          Parsed payload.
	 * @param string $self_reported Engine label from the visitor's answer, or ''.
	 * @return array
	 */
	private static function resolve( $data, $self_reported ) {
		$now      = time();
		$land     = $data['land'];
		$first    = $data['first'];
		$evidence = array();

		$land_engine  = $land ? self::touch_engine( $land ) : '';
		$first_engine = $first ? self::touch_engine( $first ) : '';
		$land_at      = $land ? $now - $land['age'] : 0;

		$tier   = 'none';
		$engine = '';

		if ( '' !== $land_engine ) {
			$tier       = 'confirmed';
			$engine     = $land_engine;
			$evidence[] = sprintf( 'Landing visit came from %s (%s).', $land_engine, '' !== $land['utm'] ? 'utm_source=' . $land['utm'] : self::host( $land['ref'] ) );
		}

		// Correlate only a visit with no outside referrer: one that came from
		// Google or Facebook already has its explanation.
		if ( 'none' === $tier && $land && self::is_direct( $land ) ) {
			$hit = self::find_agent_hit( $land['url'], $land_at );
			if ( $hit ) {
				$tier       = 'correlated';
				$engine     = $hit['e'];
				$evidence[] = sprintf(
					'%1$s fetched %2$s %3$s before this direct visit landed there.',
					$hit['a'],
					$hit['p'],
					human_time_diff( $hit['t'], max( $hit['t'] + 1, $land_at ) )
				);
			}
		}

		if ( '' !== $self_reported ) {
			$evidence[] = sprintf( 'Visitor answered: %s.', $self_reported );
			if ( 'none' === $tier ) {
				$tier   = 'self_reported';
				$engine = $self_reported;
			}
		}

		if ( 'none' === $tier && '' !== $first_engine ) {
			$tier       = 'earlier_ai';
			$engine     = $first_engine;
			$evidence[] = sprintf( 'First visit, %s ago, came from %s; this visit came another way.', human_time_diff( $now - $first['age'], $now ), $first_engine );
		}

		if ( 'none' === $tier && $land ) {
			$evidence[] = $land['ref'] ? sprintf( 'Landing visit came from %s.', self::host( $land['ref'] ) ) : 'Landing visit had no referrer (direct).';
		}

		return array(
			'token'         => $data['token'],
			'tier'          => $tier,
			'engine'        => $engine,
			'self_reported' => $self_reported,
			'evidence'      => $evidence,
			'page_url'      => $data['page'],
			'landing_url'   => $land ? $land['url'] : '',
			'landing_ref'   => $land ? ( '' !== $land['utm'] ? 'utm_source=' . $land['utm'] : self::host( $land['ref'] ) ) : '',
			'landing_at'    => $land_at,
			'first_source'  => $first ? ( '' !== $first['utm'] ? 'utm_source=' . $first['utm'] : ( self::host( $first['ref'] ) ? self::host( $first['ref'] ) : 'direct' ) ) : '',
			'first_at'      => $first ? $now - $first['age'] : 0,
		);
	}

	private static function touch_engine( $t ) {
		if ( '' !== $t['utm'] ) {
			$e = TWTAEO_AI_Referrals::match( $t['utm'] );
			if ( '' !== $e ) {
				return $e;
			}
		}
		return '' !== $t['ref'] ? TWTAEO_AI_Referrals::match( (string) self::host( $t['ref'] ) ) : '';
	}

	private static function is_direct( $t ) {
		if ( '' !== $t['utm'] ) {
			return false;
		}
		if ( '' === $t['ref'] ) {
			return true;
		}
		return self::host( $t['ref'] ) === self::host( home_url() );
	}

	/**
	 * Most recent live AI fetch of the landing path inside the match window
	 * before the visit (plus one minute after, for clock and log ordering).
	 */
	private static function find_agent_hit( $url, $land_at ) {
		if ( '' === $url || ! $land_at ) {
			return null;
		}
		$path   = self::normalize_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$window = self::get_settings()['window_minutes'] * MINUTE_IN_SECONDS;
		foreach ( (array) get_option( self::OPTION_HITS, array() ) as $h ) {
			if ( ! isset( $h['p'], $h['t'] ) || $h['p'] !== $path ) {
				continue;
			}
			if ( $h['t'] <= $land_at + MINUTE_IN_SECONDS && $h['t'] >= $land_at - $window ) {
				return $h;
			}
		}
		return null;
	}

	/**
	 * Find an AI tool named in a "How did you hear about us?" style answer.
	 *
	 * @param array $answers Label/name => answer.
	 * @return string Engine label or ''.
	 */
	private static function self_report( $answers ) {
		foreach ( (array) $answers as $label => $value ) {
			$label = strtolower( str_replace( array( '-', '_' ), ' ', (string) $label ) );
			$asks  = false;
			foreach ( self::$source_question_words as $w ) {
				if ( false !== strpos( $label, $w ) ) {
					$asks = true;
					break;
				}
			}
			if ( ! $asks ) {
				continue;
			}
			$value = ' ' . strtolower( (string) $value ) . ' ';
			foreach ( self::$self_report_words as $needle => $engine ) {
				if ( false !== strpos( $value, $needle ) ) {
					return $engine;
				}
			}
			// A bare "AI" option.
			if ( preg_match( '/\bai\b/', $value ) ) {
				return 'AI (unspecified)';
			}
		}
		return '';
	}

	// ── Storage ──────────────────────────────────────────────────────────────

	/**
	 * Insert a lead, or confirm the existing row for the same token. One
	 * token = one lead, so a beacon and a form adapter can never count the
	 * same submission twice.
	 */
	private static function save( $attr, $kind, $via, $form_ref, $entry_id, $confirmed ) {
		global $wpdb;
		self::maybe_create_table();
		$table = self::table();

		$row = array(
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'token'         => $attr['token'],
			'kind'          => $kind,
			'via'           => $via,
			'form_ref'      => $form_ref,
			'entry_id'      => $entry_id,
			'confirmed'     => $confirmed ? 1 : 0,
			'page_url'      => $attr['page_url'],
			'landing_url'   => $attr['landing_url'],
			'landing_ref'   => $attr['landing_ref'],
			'landing_at'    => $attr['landing_at'] ? gmdate( 'Y-m-d H:i:s', $attr['landing_at'] ) : null,
			'first_source'  => $attr['first_source'],
			'first_at'      => $attr['first_at'] ? gmdate( 'Y-m-d H:i:s', $attr['first_at'] ) : null,
			'tier'          => $attr['tier'],
			'engine'        => $attr['engine'],
			'self_reported' => $attr['self_reported'],
			'evidence'      => wp_json_encode( $attr['evidence'] ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; name built from $wpdb->prefix.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE token = %s", $attr['token'] ) );
		if ( $existing ) {
			unset( $row['created_at'], $row['token'] );
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return;
		}
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( $wpdb->last_error && class_exists( 'TWTAEO_Logger' ) ) {
			TWTAEO_Logger::error( 'Lead attribution insert failed', array( 'error' => $wpdb->last_error ) );
		}
	}

	public static function prune() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Leads for the report, newest first.
	 *
	 * @param int $days  Window.
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_leads( $days = 30, $limit = 200 ) {
		global $wpdb;
		if ( get_option( self::OPTION_DB ) !== self::DB_VERSION ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY created_at DESC LIMIT %d", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), $limit ), ARRAY_A );
		foreach ( (array) $rows as $i => $r ) {
			$ev                     = json_decode( (string) $r['evidence'], true );
			$rows[ $i ]['evidence'] = is_array( $ev ) ? $ev : array();
		}
		return (array) $rows;
	}

	/**
	 * Lead counts per tier.
	 */
	public static function get_counts( $days = 30 ) {
		$counts = array_fill_keys( array_keys( self::tier_labels() ), 0 );
		foreach ( self::get_leads( $days, 5000 ) as $r ) {
			if ( isset( $counts[ $r['tier'] ] ) ) {
				$counts[ $r['tier'] ]++;
			}
		}
		return $counts;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	public static function tier_labels() {
		return array(
			'confirmed'     => __( 'Confirmed AI', 'twt-aeo-ultimate' ),
			'correlated'    => __( 'Correlated AI', 'twt-aeo-ultimate' ),
			'self_reported' => __( 'Self-reported AI', 'twt-aeo-ultimate' ),
			'earlier_ai'    => __( 'Earlier AI visit', 'twt-aeo-ultimate' ),
			'none'          => __( 'No AI signal', 'twt-aeo-ultimate' ),
		);
	}

	private static function email_line( $attr ) {
		$labels = self::tier_labels();
		$line   = 'AEO attribution: ' . $labels[ $attr['tier'] ];
		if ( '' !== $attr['engine'] ) {
			$line .= ' — ' . $attr['engine'];
		}
		if ( ! empty( $attr['evidence'] ) ) {
			$line .= ' (' . implode( ' ', $attr['evidence'] ) . ')';
		}
		return $line;
	}

	private static function host( $url ) {
		$h = wp_parse_url( (string) $url, PHP_URL_HOST );
		return $h ? strtolower( preg_replace( '/^www\./i', '', $h ) ) : '';
	}

	private static function normalize_path( $uri ) {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '/' . trim( strtolower( $path ), '/' );
		return '/' === $path ? '/' : $path . '/';
	}
}
