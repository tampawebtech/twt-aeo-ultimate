<?php
/**
 * Setup Autopilot — the background half of the setup wizard.
 *
 * Everything here shares three rules:
 *
 *   1. **No AI.** Nothing in this class makes a billed call. Every fill is
 *      derived from content the site already has (detected Q&A pairs, page
 *      titles and excerpts, existing meta descriptions, the business profile).
 *      AI actions stay behind their own explicit, cost-labelled buttons.
 *   2. **Another plugin doing the job means we skip it.** Checked per surface
 *      before writing: the FAQ/Service detectors already exclude pages that
 *      carry schema from any source, contact rows are only filled where the
 *      detector found gaps, and Open Graph is skipped wholesale when an SEO
 *      plugin owns og:* output (TWTAEO_OG_Writer::seo_plugin_active()).
 *   3. **It runs in the background.** The wizard queues a single cron event
 *      and returns immediately; the completion screen polls status(), which
 *      also drives the run inline when WP-Cron is not firing (common on
 *      local / loopback-restricted sites — same pattern as the AI description
 *      job's poll).
 *
 * The report option is the single source of truth for what happened, one row
 * per task: done (with counts), skipped (with the reason another plugin or a
 * missing prerequisite earned the skip), or error.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Autopilot {

	/** Cron hook for the queued run. */
	const HOOK = 'twtaeo_autopilot_run';

	/** Option holding the queued/running/done report. Not autoloaded. */
	const OPTION_REPORT = 'twtaeo_autopilot_report';

	/** Transient lock so a poll-driven run and a cron run never overlap. */
	const LOCK = 'twtaeo_autopilot_lock';

	/** How long a queued run may sit before a status poll drives it inline. */
	const OVERDUE_AFTER = 10;

	public static function register_hooks() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	/**
	 * Queue a background run. Idempotent: a second call while queued/running
	 * changes nothing.
	 */
	public static function queue() {
		$report = get_option( self::OPTION_REPORT, array() );
		if ( in_array( $report['state'] ?? '', array( 'queued', 'running' ), true ) ) {
			return;
		}
		update_option( self::OPTION_REPORT, array(
			'state'     => 'queued',
			'queued_at' => time(),
			'tasks'     => array(),
		), false );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 2, self::HOOK );
		}
	}

	/**
	 * Current report. When the queued event is overdue — WP-Cron not firing —
	 * the poll itself drives the run, so setup completes on every host.
	 *
	 * @return array
	 */
	public static function status() {
		$report = get_option( self::OPTION_REPORT, array() );
		if ( ( $report['state'] ?? '' ) === 'queued'
			&& ( time() - (int) ( $report['queued_at'] ?? 0 ) ) > self::OVERDUE_AFTER ) {
			self::run();
			$report = get_option( self::OPTION_REPORT, array() );
		}
		return $report;
	}

	/**
	 * Execute every task once. Each task is isolated: one failing records an
	 * error row and the rest still run.
	 */
	public static function run() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$report          = get_option( self::OPTION_REPORT, array() );
		$report['state'] = 'running';
		update_option( self::OPTION_REPORT, $report, false );

		$tasks = array(
			'page_schema'     => array( __CLASS__, 'task_page_schema' ),
			'contact_schema'  => array( __CLASS__, 'task_contact_schema' ),
			'og_descriptions' => array( __CLASS__, 'task_og_descriptions' ),
		);

		foreach ( $tasks as $id => $callback ) {
			try {
				$report['tasks'][ $id ] = call_user_func( $callback );
			} catch ( \Throwable $e ) {
				$report['tasks'][ $id ] = array(
					'status' => 'error',
					'detail' => $e->getMessage(),
				);
			}
			// Persist after every task so a fatal mid-run still leaves a
			// truthful partial report rather than an eternal "running".
			update_option( self::OPTION_REPORT, $report, false );
		}

		$report['state']       = 'done';
		$report['finished_at'] = time();
		update_option( self::OPTION_REPORT, $report, false );

		delete_transient( self::LOCK );
	}

	// ── Tasks ─────────────────────────────────────────────────────────────────

	/**
	 * FAQ + Service schema for every detected page still missing it.
	 *
	 * The per-page conflict check lives in the detectors: `needs_schema` is
	 * false when a page already carries FAQPage/Service schema from ANY plugin,
	 * so nothing here can duplicate Yoast, Rank Math, or hand-written JSON-LD.
	 *
	 * @return array
	 */
	private static function task_page_schema() {
		$faq_filled     = 0;
		$faq_skipped    = 0;
		$service_filled = 0;

		if ( class_exists( 'TWTAEO_FAQ_Detector' ) ) {
			foreach ( TWTAEO_FAQ_Detector::scan_all() as $item ) {
				if ( empty( $item['faq_data']['needs_schema'] ) ) {
					continue;
				}
				$post     = $item['post'];
				$qa_pairs = TWTAEO_FAQ_Detector::extract_qa_pairs( $post );
				if ( empty( $qa_pairs ) ) {
					$faq_skipped++;
					continue;
				}
				TWTAEO_FAQ_Detector::save_generated( $post->ID, $qa_pairs );
				$faq_filled++;
			}
		}

		if ( class_exists( 'TWTAEO_Service_Detector' ) && class_exists( 'TWTAEO_Service_Schema_Writer' ) ) {
			foreach ( TWTAEO_Service_Detector::scan_all( false ) as $item ) {
				if ( empty( $item['service_data']['needs_schema'] ) ) {
					continue;
				}
				$prefill = TWTAEO_Service_Schema_Writer::get_prefill( $item['post']->ID );
				if ( empty( $prefill['name'] ) ) {
					continue;
				}
				TWTAEO_Service_Schema_Writer::save( $item['post']->ID, array(
					'name'          => $prefill['name'],
					'description'   => $prefill['description'] ?? '',
					'service_type'  => $prefill['service_type'] ?? '',
					'area_served'   => $prefill['area_served'] ?? '',
					'provider_name' => $prefill['provider_name'] ?? '',
					'phone'         => $prefill['phone'] ?? '',
				) );
				$service_filled++;
			}
		}

		return array(
			'status' => 'done',
			'detail' => sprintf(
				/* translators: %1$d: FAQ pages filled. %2$d: FAQ pages skipped. %3$d: service pages filled. */
				__( '%1$d FAQ page(s) filled, %2$d skipped (Q&A not cleanly extractable), %3$d service page(s) filled. Pages already carrying schema from another plugin were never candidates.', 'twt-aeo-ultimate' ),
				$faq_filled,
				$faq_skipped,
				$service_filled
			),
		);
	}

	/**
	 * Contact-page schema from the business profile — only when a profile
	 * exists to draw from, and only on pages where the detector found gaps.
	 *
	 * @return array
	 */
	private static function task_contact_schema() {
		if ( ! class_exists( 'TWTAEO_Contact_Detector' ) || ! class_exists( 'TWTAEO_Contact_Schema_Writer' ) ) {
			return array( 'status' => 'skipped', 'detail' => __( 'Contact detector module not available.', 'twt-aeo-ultimate' ) );
		}

		$lp     = class_exists( 'TWTAEO_Local_Pack' ) ? TWTAEO_Local_Pack::get_settings() : array();
		$fields = array(
			'name'           => $lp['business_name'] ?? get_bloginfo( 'name' ),
			'business_type'  => 'Organization',
			'phone'          => $lp['phone'] ?? '',
			'email'          => $lp['email'] ?? get_bloginfo( 'admin_email' ),
			'contact_type'   => 'customer service',
			'street_address' => $lp['street_address'] ?? '',
			'city'           => $lp['city'] ?? '',
			'state'          => $lp['state'] ?? '',
			'zip'            => $lp['zip'] ?? '',
			'country'        => $lp['country'] ?? '',
		);

		// A name plus at least one real way to reach the business — otherwise
		// the schema would claim a contact surface that does not exist. The
		// admin default email only counts when it is a publishable address.
		$email_ok = class_exists( 'TWTAEO_Knowledge_Graph' )
			? TWTAEO_Knowledge_Graph::is_public_email( $fields['email'] )
			: is_email( $fields['email'] );
		if ( '' === $fields['name'] || ( '' === $fields['phone'] && ! $email_ok && '' === $fields['street_address'] ) ) {
			return array(
				'status' => 'skipped',
				'detail' => __( 'No business contact details on file yet — fill in the Business Info panel (Schema Detector → Contact) and re-run from there.', 'twt-aeo-ultimate' ),
			);
		}

		$filled = 0;
		foreach ( TWTAEO_Contact_Detector::scan_all() as $item ) {
			// Pages whose contact schema is already complete — from this plugin
			// or any other — report no gaps and are left alone.
			if ( empty( $item['contact_data']['missing'] ) ) {
				continue;
			}
			if ( TWTAEO_Contact_Schema_Writer::save( $item['post']->ID, $fields ) ) {
				$filled++;
			}
		}

		return array(
			'status' => 'done',
			'detail' => sprintf(
				/* translators: %d: number of contact pages that received schema. */
				__( 'Organization + ContactPoint schema added to %d contact page(s) with gaps.', 'twt-aeo-ultimate' ),
				$filled
			),
		);
	}

	/**
	 * Social (og:) descriptions from meta descriptions the site already has.
	 * Free, no AI. Skipped wholesale when an SEO plugin owns Open Graph output
	 * — filling would store values the page never ships.
	 *
	 * @return array
	 */
	private static function task_og_descriptions() {
		if ( class_exists( 'TWTAEO_OG_Writer' ) && TWTAEO_OG_Writer::seo_plugin_active() ) {
			return array(
				'status' => 'skipped',
				'detail' => __( 'Your SEO plugin owns Open Graph output — nothing was written, so the two never disagree.', 'twt-aeo-ultimate' ),
			);
		}
		if ( ! class_exists( 'TWTAEO_AI_Description' ) ) {
			return array( 'status' => 'skipped', 'detail' => __( 'Description module not available.', 'twt-aeo-ultimate' ) );
		}

		$filled  = 0;
		$no_meta = 0;
		foreach ( TWTAEO_AI_Description::get_posts_missing_og( 2000 ) as $post_id ) {
			$meta = TWTAEO_AI_Description::get_existing_description( $post_id );
			if ( '' === $meta ) {
				$no_meta++;
				continue;
			}
			TWTAEO_AI_Description::save_og_description( $post_id, $meta );
			$filled++;
		}

		return array(
			'status' => 'done',
			'detail' => sprintf(
				/* translators: %1$d: posts whose og:description was filled. %2$d: posts with no meta description to reuse. */
				__( '%1$d social description(s) filled from existing meta descriptions. %2$d post(s) have no meta description to reuse — the AI generator on the Social Graph page can write those (billed, and it always asks first).', 'twt-aeo-ultimate' ),
				$filled,
				$no_meta
			),
		);
	}
}
