<?php
/**
 * Owner actions — the changes the site owner's Claude connector may make.
 *
 * The read-only side lives in TWTAEO_Abilities. Everything here changes the
 * site or spends the owner's API credits, so it is held to stricter rules and
 * is deliberately NOT registered with the Abilities API, which has no notion
 * of a confirmation step.
 *
 * ── Rules every action here follows ──────────────────────────────────────────
 *
 * 1. 🛑 **Off until the owner allows changes** (TWT AEO → Settings → MCPs),
 *    separately from switching the connector on.
 *
 * 2. 🛑 **Preview, then confirm.** Every action has a preview() that changes
 *    nothing and an apply() that does the work. TWTAEO_Owner_MCP only calls
 *    apply() with a confirm token issued by a preview of the *same* arguments,
 *    so what runs is what the owner was shown.
 *
 * 3. 🛑 **One page or one run per call.** No bulk edits: the owner approves
 *    each change on its own.
 *
 * 4. 🛑 **Logged.** Every applied action lands in the activity log on the
 *    Settings → MCPs tab, with what it replaced where that is text.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Owner_Actions {

	/** Last 50 applied actions: { time, user, action, target, summary, before }. */
	const OPTION_LOG = 'twtaeo_owner_mcp_log';
	const LOG_MAX    = 50;

	/**
	 * slug => label, description, input_schema, preview, apply, annotations.
	 * preview() and apply() take the validated input and return array|WP_Error;
	 * apply() also returns 'log' => { target, summary, before? }.
	 */
	public static function definitions() {
		return array(
			'run-ai-visibility-check' => array(
				'label'        => __( 'Run an AI Visibility check', 'twt-aeo-ultimate' ),
				'description'  => __( 'Asks the AI engines the site has API keys for (ChatGPT, Gemini, Claude and others) the tracked questions and records whether each answer cites the site. Runs in the background for a few minutes and spends the owner\'s own API credits: one paid call per question per engine. Read the results afterwards with get_ai_visibility_summary.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'max_questions' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 200,
							'description' => __( 'Ask only the first N tracked questions, to keep the cost down. Leave out to ask them all.', 'twt-aeo-ultimate' ),
						),
					),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'visibility_preview' ),
				'apply'        => array( __CLASS__, 'visibility_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => false,
					'openWorldHint'   => true,
				),
			),
			'set-meta-description'    => array(
				'label'        => __( 'Set a page\'s meta description', 'twt-aeo-ultimate' ),
				'description'  => __( 'Replaces one published page\'s meta description with text you write (about 140 to 155 characters; longer is cut at 160). It is also written to the active SEO plugin (Yoast, Rank Math, AIOSEO or SEOPress) so that plugin shows it. Costs nothing.', 'twt-aeo-ultimate' ),
				'input_schema' => TWTAEO_Abilities::page_input_schema(
					array(
						'description' => array(
							'type'      => 'string',
							'minLength' => 50,
							'maxLength' => 300,
						),
					)
				) + array( 'required' => array( 'description' ) ),
				'preview'      => array( __CLASS__, 'meta_preview' ),
				'apply'        => array( __CLASS__, 'meta_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'sync-faq-schema'         => array(
				'label'        => __( 'Update a page\'s FAQ schema', 'twt-aeo-ultimate' ),
				'description'  => __( 'Rewrites one page\'s FAQPage schema from the questions and answers on the page now: adds schema to a page whose FAQ has none, or brings out-of-date FAQ schema back in line. Use get_faq_schema_status to find pages that need it. Costs nothing.', 'twt-aeo-ultimate' ),
				'input_schema' => TWTAEO_Abilities::page_input_schema(),
				'preview'      => array( __CLASS__, 'faq_preview' ),
				'apply'        => array( __CLASS__, 'faq_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'edit-tracked-questions'  => array(
				'label'        => __( 'Edit tracked questions', 'twt-aeo-ultimate' ),
				'description'  => __( 'Adds questions for AI Visibility to ask (the owner\'s own questions are always asked), and switches existing questions off or on by question_id from get_tracked_questions. Good questions are what a real customer would type into ChatGPT. Costs nothing until a check runs.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'add'        => array(
							'type'     => 'array',
							'maxItems' => 10,
							'items'    => array(
								'type'      => 'string',
								'minLength' => 3,
								'maxLength' => 300,
							),
						),
						'switch_off' => array(
							'type'     => 'array',
							'maxItems' => 50,
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 191,
							),
						),
						'switch_on'  => array(
							'type'     => 'array',
							'maxItems' => 50,
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 191,
							),
						),
					),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'questions_preview' ),
				'apply'        => array( __CLASS__, 'questions_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'set-image-alt-text'      => array(
				'label'        => __( 'Write image alt text', 'twt-aeo-ultimate' ),
				'description'  => __( 'Saves alt text you write for one page\'s images (up to 125 characters each), by image_key from get_image_alt_gaps for that page. Updates the media library and the page content. Costs nothing.', 'twt-aeo-ultimate' ),
				'input_schema' => TWTAEO_Abilities::page_input_schema(
					array(
						'alts' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 40,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'image_key' => array(
										'type'    => 'string',
										'pattern' => '^(featured|c[0-9]{1,3})$',
									),
									'alt'       => array(
										'type'      => 'string',
										'minLength' => 3,
										'maxLength' => 250,
									),
								),
								'required'             => array( 'image_key', 'alt' ),
								'additionalProperties' => false,
							),
						),
					)
				) + array( 'required' => array( 'alts' ) ),
				'preview'      => array( __CLASS__, 'alt_preview' ),
				'apply'        => array( __CLASS__, 'alt_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'set-open-graph'          => array(
				'label'        => __( 'Set a page\'s Open Graph text', 'twt-aeo-ultimate' ),
				'description'  => __( 'Sets the title and/or description a page shows when shared on social media and in link previews (Open Graph). The share image is left as it is. Costs nothing.', 'twt-aeo-ultimate' ),
				'input_schema' => TWTAEO_Abilities::page_input_schema(
					array(
						'og_title'       => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'og_description' => array(
							'type'      => 'string',
							'maxLength' => 400,
						),
					)
				),
				'preview'      => array( __CLASS__, 'og_preview' ),
				'apply'        => array( __CLASS__, 'og_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'add-404-redirect'        => array(
				'label'        => __( 'Add a 404 redirect', 'twt-aeo-ultimate' ),
				'description'  => __( 'Permanently redirects an old URL on this site that now returns 404 to a live page (to_post_id or to_url). Only for URLs that are not live pages. get_404_report lists dead URLs with suggested targets.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'from'       => array(
							'type'      => 'string',
							'minLength' => 2,
							'maxLength' => 500,
						),
						'to_post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'to_url'     => array(
							'type'      => 'string',
							'format'    => 'uri',
							'maxLength' => 500,
						),
					),
					'required'             => array( 'from' ),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'redirect_preview' ),
				'apply'        => array( __CLASS__, 'redirect_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'suppress-duplicate-schema' => array(
				'label'        => __( 'Suppress a duplicate schema type', 'twt-aeo-ultimate' ),
				'description'  => __( 'Stops (or lets again) another SEO plugin output one schema type that AEO Ultimate also outputs, so the page carries one version. Use the plugin key and type from get_schema_conflicts.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin'   => array(
							'type' => 'string',
							'enum' => array_keys( self::SUPPRESSIBLE ),
						),
						'type'     => array(
							'type'    => 'string',
							'pattern' => '^[A-Za-z]{2,40}$',
						),
						'suppress' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'plugin', 'type', 'suppress' ),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'suppress_preview' ),
				'apply'        => array( __CLASS__, 'suppress_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'set-ai-ready-feature'    => array(
				'label'        => __( 'Turn an AI Ready feature on or off', 'twt-aeo-ultimate' ),
				'description'  => __( 'Switches one feature from the AI Ready screen (feature key from get_ai_readiness), such as llms.txt or Markdown for AI agents. Takes effect site-wide immediately.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'feature' => array(
							'type' => 'string',
							'enum' => array_keys( self::AI_READY_FEATURES ),
						),
						'on'      => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'feature', 'on' ),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'ai_ready_preview' ),
				'apply'        => array( __CLASS__, 'ai_ready_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'recheck-google-index'    => array(
				'label'        => __( 'Re-check a page in Google', 'twt-aeo-ultimate' ),
				'description'  => __( 'Asks Google Search Console for one page\'s current index status and re-checks the page for problems that keep pages out of the index; the result also updates get_index_status. Uses one of Google\'s daily URL inspections. Changes nothing on the site.', 'twt-aeo-ultimate' ),
				'input_schema' => TWTAEO_Abilities::page_input_schema(),
				'preview'      => array( __CLASS__, 'gsc_preview' ),
				'apply'        => array( __CLASS__, 'gsc_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => true,
				),
			),
			'set-buyer-personas'      => array(
				'label'        => __( 'Set buyer personas', 'twt-aeo-ultimate' ),
				'description'  => __( 'Replaces the list of buyer personas AI Visibility can ask as (for example "a homeowner comparing roofers"). Short labels, at most 10. An empty list clears it.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'personas' => array(
							'type'     => 'array',
							'maxItems' => TWTAEO_Visibility_Types::PERSONAS_MAX,
							'items'    => array(
								'type'      => 'string',
								'minLength' => 2,
								'maxLength' => 80,
							),
						),
					),
					'required'             => array( 'personas' ),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'personas_preview' ),
				'apply'        => array( __CLASS__, 'personas_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'update-company-profile'  => array(
				'label'        => __( 'Update the company profile', 'twt-aeo-ultimate' ),
				'description'  => __( 'Fills or corrects company profile fields that feed the Organization schema: legal_name, description, founding_year, phone, email and social profile URLs (social_facebook, social_twitter, social_linkedin, social_instagram, social_youtube, social_wikipedia, social_pinterest). Only fields passed change. Only facts the owner confirms.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'fields' => array(
							'type'                 => 'object',
							'minProperties'        => 1,
							'properties'           => array_fill_keys(
								self::COMPANY_FIELDS,
								array(
									'type'      => 'string',
									'maxLength' => 1000,
								)
							),
							'additionalProperties' => false,
						),
					),
					'required'             => array( 'fields' ),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'company_preview' ),
				'apply'        => array( __CLASS__, 'company_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'update-author-profile'   => array(
				'label'        => __( 'Update an author profile', 'twt-aeo-ultimate' ),
				'description'  => __( 'Fills or corrects one author\'s E-E-A-T details (user_id from get_eeat_scorecard): bio, job_title, credentials, expertise, years_experience, and profile links under social (linkedin, facebook, twitter, github, youtube, website, industry). Only fields passed change. Never invent credentials.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'fields'  => array(
							'type'                 => 'object',
							'properties'           => array(
								'bio'              => array(
									'type'      => 'string',
									'maxLength' => 2000,
								),
								'job_title'        => array(
									'type'      => 'string',
									'maxLength' => 120,
								),
								'credentials'      => array(
									'type'      => 'string',
									'maxLength' => 200,
								),
								'expertise'        => array(
									'type'      => 'string',
									'maxLength' => 200,
								),
								'years_experience' => array(
									'type'    => 'integer',
									'minimum' => 0,
									'maximum' => 80,
								),
							),
							'additionalProperties' => false,
						),
						'social'  => array(
							'type'                 => 'object',
							'properties'           => array_fill_keys(
								array_keys( TWTAEO_Author_Meta::$social_networks ),
								array(
									'type'      => 'string',
									'maxLength' => 300,
								)
							),
							'additionalProperties' => false,
						),
					),
					'required'             => array( 'user_id' ),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'author_preview' ),
				'apply'        => array( __CLASS__, 'author_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			'undo-change'             => array(
				'label'        => __( 'Undo a change', 'twt-aeo-ultimate' ),
				'description'  => __( 'Puts back exactly what one earlier change through this connector replaced (meta description, Open Graph text, FAQ schema, image alt text, a redirect, a schema or AI Ready setting, tracked questions, personas, or a company or author profile field), whoever made it. Find the change_id with get_change_log. An AI Visibility check cannot be undone. The undo is itself logged and can be undone.', 'twt-aeo-ultimate' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'change_id' => array(
							'type'    => 'string',
							'pattern' => '^[A-Za-z0-9]{12}$',
						),
					),
					'required'             => array( 'change_id' ),
					'additionalProperties' => false,
				),
				'preview'      => array( __CLASS__, 'undo_preview' ),
				'apply'        => array( __CLASS__, 'undo_apply' ),
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => false,
					'openWorldHint'   => false,
				),
			),
		);
	}

	public static function undo_preview( array $input ) {
		$check = self::revert_check( $input['change_id'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$e   = $check['entry'];
		$who = get_userdata( (int) $e['user'] );
		$out = array(
			'will_do'       => sprintf(
				/* translators: 1: summary of the change, 2: page title. */
				__( 'Undo "%1$s" on %2$s.', 'twt-aeo-ultimate' ),
				$e['summary'],
				$e['target']
			),
			'change_made'   => gmdate( 'c', (int) $e['time'] ),
			'change_made_by' => $who ? self::plain( $who->display_name ) : '',
		);
		// Before/after are text values only for these; for the rest the summary says it all.
		if ( in_array( $e['snapshot']['kind'], array( 'meta', 'postmeta', 'personas' ), true ) && ( '' !== $e['before'] || '' !== $e['after'] ) ) {
			// What is there now, which differs from the change's own text when edited since.
			$out['current']     = 'meta' === $e['snapshot']['kind'] && ! empty( $e['post_id'] )
				? self::plain( TWTAEO_AI_Description::get_existing_description( (int) $e['post_id'] ), 300 )
				: $e['after'];
			$out['restored_to'] = '' !== $e['before'] ? $e['before'] : __( '(nothing: the field was empty before)', 'twt-aeo-ultimate' );
		}
		if ( $check['changed_since'] ) {
			$out['warning'] = __( 'This was edited again after the change was made. Undoing it also throws away those later edits.', 'twt-aeo-ultimate' );
		}
		return $out;
	}

	public static function undo_apply( array $input ) {
		// The owner approved a preview that carried any "edited since" warning.
		$row = self::revert( $input['change_id'], true );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return array(
			'undone'    => true,
			'change_id' => $row['id'],
			'note'      => __( 'The undo is logged as its own change; undo that to restore the change.', 'twt-aeo-ultimate' ),
		);
	}

	/**
	 * Run one half of an action, turning a throwable into a WP_Error with a
	 * Diagnostics reference (as TWTAEO_Abilities::safely() does).
	 *
	 * @param string $slug
	 * @param string $half 'preview' | 'apply'
	 * @return array|WP_Error
	 */
	public static function run( $slug, $half, array $input ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $slug ] ) || ! in_array( $half, array( 'preview', 'apply' ), true ) ) {
			return new WP_Error( 'twtaeo_unknown_action', __( 'Unknown AEO Ultimate action.', 'twt-aeo-ultimate' ) );
		}
		try {
			$result = call_user_func( $defs[ $slug ][ $half ], $input );
		} catch ( Throwable $e ) {
			$ref = class_exists( 'TWTAEO_Logger' ) ? TWTAEO_Logger::log_exception( $e, array( 'owner_action' => $slug ) ) : '';
			return new WP_Error(
				'twtaeo_action_failed',
				/* translators: %s: Diagnostics reference code. */
				sprintf( __( 'AEO Ultimate could not do that. Reference %s on the Diagnostics page.', 'twt-aeo-ultimate' ), $ref )
			);
		}
		if ( 'apply' === $half && is_array( $result ) && isset( $result['log'] ) ) {
			self::log( $slug, (array) $result['log'] );
			unset( $result['log'] );
		}
		return $result;
	}

	/* ─────────────────────────── AI Visibility run ─────────────────────────── */

	/** @return array|WP_Error { alloc, opts, plan } */
	private static function visibility_plan( array $input ) {
		$loader = class_exists( 'TWTAEO_Module_Loader' ) ? new TWTAEO_Module_Loader() : null;
		if ( ! $loader || ! $loader->is_active( TWTAEO_Visibility_Types::MODULE_SLUG ) ) {
			return new WP_Error( 'twtaeo_visibility_off', __( 'The AI Visibility module is off. Turn it on under AEO Ultimate → Modules first.', 'twt-aeo-ultimate' ) );
		}
		if ( '' !== TWTAEO_Visibility::active_run_id() ) {
			return new WP_Error( 'twtaeo_visibility_running', __( 'An AI Visibility check is already running. Wait for it to finish, then read the results with get_ai_visibility_summary.', 'twt-aeo-ultimate' ) );
		}

		// The board's saved allocation, with every engine that has a key — the
		// same engines a run started from the AI Visibility screen would ask.
		$settings = TWTAEO_Visibility_Store::get_settings();
		$settings = is_array( $settings ) ? $settings : array();
		$alloc    = ! empty( $settings['allocation'] ) && is_array( $settings['allocation'] )
			? $settings['allocation']
			: TWTAEO_Visibility_Questions::apply_preset( 'standard', array() );
		$keyed    = array();
		foreach ( TWTAEO_Visibility_Engines::availability() as $row ) {
			if ( ! empty( $row['available'] ) ) {
				$keyed[] = $row['engine'];
			}
		}
		$alloc['engines'] = TWTAEO_Visibility_Store::effective_engines( $settings, $keyed );

		$opts = array( 'journey' => 0 );
		if ( ! empty( $input['max_questions'] ) ) {
			$opts['max_questions'] = (int) $input['max_questions'];
		}
		if ( class_exists( 'TWTAEO_Visibility_Location' ) ) {
			$location = TWTAEO_Visibility_Location::site_default();
			if ( $location ) {
				$opts['location'] = $location;
			}
		}

		$plan = TWTAEO_Visibility_Store::plan_run( $alloc, $opts );
		if ( empty( $plan['engines'] ) ) {
			return new WP_Error( 'twtaeo_visibility_keys', __( 'No AI engine has an API key yet. Add at least one under AEO Ultimate → Settings.', 'twt-aeo-ultimate' ) );
		}
		if ( empty( $plan['questions'] ) ) {
			return new WP_Error( 'twtaeo_visibility_questions', __( 'There are no tracked questions to ask. Set them up on the AI Visibility screen.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'alloc' => $alloc,
			'opts'  => $opts,
			'plan'  => $plan,
		);
	}

	public static function visibility_preview( array $input ) {
		$p = self::visibility_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$labels = array();
		foreach ( $p['plan']['engines'] as $engine ) {
			$labels[] = isset( TWTAEO_Visibility_Types::ENGINES[ $engine ]['label'] ) ? (string) TWTAEO_Visibility_Types::ENGINES[ $engine ]['label'] : $engine;
		}
		$questions = count( $p['plan']['questions'] );
		$checks    = $questions * count( $labels );
		$budget    = TWTAEO_Visibility_Budget::check();

		$out = array(
			'will_do'          => sprintf(
				/* translators: 1: number of questions, 2: number of engines, 3: number of checks. */
				__( 'Ask %1$d tracked questions to %2$d AI engines: %3$d paid API calls on the site\'s own keys.', 'twt-aeo-ultimate' ),
				$questions,
				count( $labels ),
				$checks
			),
			'engines'          => $labels,
			'questions'        => $questions,
			'paid_checks'      => $checks,
			'daily_cap'        => (int) $budget['cap'],
			'used_today'       => (int) $budget['used'],
			'runs_in'          => __( 'The background, a few minutes for a typical site. Nobody needs to keep a page open.', 'twt-aeo-ultimate' ),
		);
		if ( $budget['used'] + $checks > $budget['cap'] ) {
			$out['warning'] = __( 'This run goes past today\'s check cap, so it will pause at the cap and finish tomorrow.', 'twt-aeo-ultimate' );
		}
		return $out;
	}

	public static function visibility_apply( array $input ) {
		$p = self::visibility_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$run = TWTAEO_Visibility::start_background_run( $p['alloc'], $p['opts'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( ! is_array( $run ) || empty( $run['id'] ) ) {
			return new WP_Error( 'twtaeo_visibility_start', __( 'The check could not be started.', 'twt-aeo-ultimate' ) );
		}
		$checks = isset( $run['queue'] ) ? count( (array) $run['queue'] ) : 0;
		return array(
			'started'   => true,
			'checks'    => $checks,
			'next_step' => __( 'The check is running in the background. Ask again in a few minutes; get_ai_visibility_summary shows the results so far, and the run status says "done" when it has finished.', 'twt-aeo-ultimate' ),
			'log'       => array(
				'target'  => __( 'AI Visibility', 'twt-aeo-ultimate' ),
				/* translators: %d: number of checks. */
				'summary' => sprintf( __( 'Started a check: %d paid calls.', 'twt-aeo-ultimate' ), $checks ),
			),
		);
	}

	/* ─────────────────────────── Meta description ─────────────────────────── */

	public static function meta_preview( array $input ) {
		$post = TWTAEO_Abilities::resolve_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$new = TWTAEO_AI_Description::prepare_description( isset( $input['description'] ) ? $input['description'] : '' );
		if ( mb_strlen( $new ) < 50 ) {
			return new WP_Error( 'twtaeo_meta_short', __( 'That description is too short to be useful. Write at least 50 characters.', 'twt-aeo-ultimate' ) );
		}
		$old = TWTAEO_AI_Description::get_existing_description( $post->ID );
		$out = self::page( $post ) + array(
			'current'    => '' !== $old ? $old : null,
			'new'        => $new,
			'new_length' => mb_strlen( $new ),
			'written_to' => self::meta_targets(),
		);
		if ( trim( (string) $input['description'] ) !== $new ) {
			$out['note'] = __( 'The text was tidied or shortened to fit; "new" is exactly what will be saved.', 'twt-aeo-ultimate' );
		}
		if ( $old === $new ) {
			$out['note'] = __( 'This is already the page\'s description; nothing would change.', 'twt-aeo-ultimate' );
		}
		return $out;
	}

	public static function meta_apply( array $input ) {
		$preview = self::meta_preview( $input );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$spec   = array(
			'kind'    => 'meta',
			'post_id' => $preview['post_id'],
		);
		$before = self::snapshot( $spec );
		TWTAEO_AI_Description::save_description( $preview['post_id'], $preview['new'] );
		return array(
			'saved'      => true,
			'post_id'    => $preview['post_id'],
			'url'        => $preview['url'],
			'saved_text' => $preview['new'],
			'log'        => array(
				'target'   => $preview['title'],
				'post_id'  => $preview['post_id'],
				'summary'  => __( 'Meta description changed.', 'twt-aeo-ultimate' ),
				'before'   => (string) $preview['current'],
				'after'    => $preview['new'],
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/** Where save_description() writes, in words. */
	private static function meta_targets() {
		$targets = array( 'AEO Ultimate' );
		foreach ( array(
			'WPSEO_VERSION'     => 'Yoast SEO',
			'RANK_MATH_VERSION' => 'Rank Math',
			'SEOPRESS_VERSION'  => 'SEOPress',
			'AIOSEO_VERSION'    => 'All in One SEO',
		) as $const => $name ) {
			if ( defined( $const ) ) {
				$targets[] = $name;
			}
		}
		return $targets;
	}

	/* ─────────────────────────── FAQ schema ─────────────────────────── */

	/** @return array|WP_Error { post, pairs, drift, mode } */
	private static function faq_plan( array $input ) {
		$post = TWTAEO_Abilities::resolve_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'product' === $post->post_type ) {
			return new WP_Error( 'twtaeo_faq_product', __( 'Product FAQs are managed in the Product FAQ generator on the WooCommerce screen, not from the page.', 'twt-aeo-ultimate' ) );
		}
		$drift = TWTAEO_FAQ_Detector::drift( $post );
		$pairs = TWTAEO_FAQ_Detector::extract_qa_pairs( $post );

		switch ( $drift['state'] ) {
			case 'in_sync':
				return new WP_Error( 'twtaeo_faq_in_sync', __( 'This page\'s FAQ schema already matches its questions. Nothing to update.', 'twt-aeo-ultimate' ) );
			case 'orphaned':
				return new WP_Error( 'twtaeo_faq_orphaned', __( 'The FAQ section is gone from this page but its FAQ schema is still published. Remove the schema on AEO Ultimate → Schema Detector; this tool only writes schema from questions on the page.', 'twt-aeo-ultimate' ) );
			case 'unverifiable':
				return new WP_Error( 'twtaeo_faq_unreadable', __( 'This page\'s FAQ is in a format AEO Ultimate cannot read questions out of (an accordion or similar), so it cannot rewrite the schema safely. Edit it on the Schema Detector screen.', 'twt-aeo-ultimate' ) );
		}
		if ( ! $pairs ) {
			return new WP_Error( 'twtaeo_faq_none', __( 'No FAQ questions were found on this page.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'post'  => $post,
			'pairs' => $pairs,
			'drift' => $drift,
			'mode'  => 'changed' === $drift['state'] ? 'update' : 'create',
		);
	}

	public static function faq_preview( array $input ) {
		$p = self::faq_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$questions = array();
		foreach ( array_slice( $p['pairs'], 0, 30 ) as $pair ) {
			$questions[] = self::plain( isset( $pair['question'] ) ? $pair['question'] : '' );
		}
		$out = self::page( $p['post'] ) + array(
			'will_do'   => 'create' === $p['mode']
				? __( 'Add FAQPage schema to this page from the questions below.', 'twt-aeo-ultimate' )
				: __( 'Replace this page\'s FAQPage schema with the questions and answers on the page now.', 'twt-aeo-ultimate' ),
			'questions' => $questions,
		);
		if ( 'update' === $p['mode'] ) {
			$out['changes'] = array(
				'questions_added'   => array_map( array( __CLASS__, 'plain' ), array_slice( $p['drift']['added'], 0, 10 ) ),
				'questions_removed' => array_map( array( __CLASS__, 'plain' ), array_slice( $p['drift']['removed'], 0, 10 ) ),
				'answers_edited'    => array_map( array( __CLASS__, 'plain' ), array_slice( $p['drift']['edited'], 0, 10 ) ),
			);
			if ( 'edited' === $p['drift']['origin'] ) {
				$out['warning'] = __( 'The current FAQ schema was edited by hand. Updating it replaces those edits with what the page says now.', 'twt-aeo-ultimate' );
			}
		}
		return $out;
	}

	public static function faq_apply( array $input ) {
		$p = self::faq_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$spec   = array(
			'kind'    => 'faq',
			'post_id' => $p['post']->ID,
		);
		$before = self::snapshot( $spec );
		$saved  = TWTAEO_FAQ_Detector::save_generated( $p['post']->ID, $p['pairs'] );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		self::refresh_faq_cache( $p['post']->ID );

		$count = count( $p['pairs'] );
		return array(
			'saved'     => true,
			'post_id'   => $p['post']->ID,
			'questions' => $count,
			'log'       => array(
				'target'   => self::plain( get_the_title( $p['post'] ) ),
				'post_id'  => $p['post']->ID,
				'summary'  => 'create' === $p['mode']
					/* translators: %d: number of questions. */
					? sprintf( __( 'FAQ schema added (%d questions).', 'twt-aeo-ultimate' ), $count )
					/* translators: %d: number of questions. */
					: sprintf( __( 'FAQ schema updated (%d questions).', 'twt-aeo-ultimate' ), $count ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/** Refresh the cached scan the Schema Detector screen and get_faq_schema_status read. */
	private static function refresh_faq_cache( $post_id ) {
		if ( class_exists( 'TWTAEO_Scan_Store' ) ) {
			delete_post_meta( $post_id, TWTAEO_Scan_Store::META_SCHEMA );
		}
		$post = get_post( $post_id );
		if ( $post ) {
			update_post_meta( $post_id, TWTAEO_FAQ_Detector::META_FAQ_SCAN, TWTAEO_FAQ_Detector::scan( $post ) );
		}
	}

	/* ─────────────────────────── Tracked questions ─────────────────────────── */

	/**
	 * The question list a run would work from: built from the saved allocation,
	 * with the saved ticks, edits and the owner's own questions laid over it.
	 *
	 * @return array Question[]
	 */
	public static function effective_questions() {
		$settings = TWTAEO_Visibility_Store::get_settings();
		$settings = is_array( $settings ) ? $settings : array();
		$alloc    = ! empty( $settings['allocation'] ) && is_array( $settings['allocation'] )
			? $settings['allocation']
			: TWTAEO_Visibility_Questions::apply_preset( 'standard', array() );
		$input    = TWTAEO_Visibility_Inputs::load( $alloc );
		$fresh    = TWTAEO_Visibility_Questions::build_questions( is_array( $input ) ? $input : array(), $alloc );
		return TWTAEO_Visibility_Store::merge_saved_questions( is_array( $fresh ) ? array_values( $fresh ) : array(), TWTAEO_Visibility_Store::load_questions() );
	}

	/** @return array|WP_Error { list, added, off, on } */
	private static function questions_plan( array $input ) {
		$add = isset( $input['add'] ) ? (array) $input['add'] : array();
		$off = isset( $input['switch_off'] ) ? array_map( 'strval', (array) $input['switch_off'] ) : array();
		$on  = isset( $input['switch_on'] ) ? array_map( 'strval', (array) $input['switch_on'] ) : array();
		if ( ! $add && ! $off && ! $on ) {
			return new WP_Error( 'twtaeo_q_nothing', __( 'Pass questions to add, or question_ids to switch off or on.', 'twt-aeo-ultimate' ) );
		}

		$list  = self::effective_questions();
		$by_id = array();
		foreach ( $list as $i => $q ) {
			$by_id[ (string) $q['id'] ] = $i;
		}
		$unknown = array_diff( array_merge( $off, $on ), array_keys( $by_id ) );
		if ( $unknown ) {
			return new WP_Error( 'twtaeo_q_unknown', __( 'Some question_ids were not found. Get the current ids with get_tracked_questions.', 'twt-aeo-ultimate' ) );
		}

		$changed = array(
			'added' => array(),
			'off'   => array(),
			'on'    => array(),
		);
		foreach ( $off as $id ) {
			if ( ! empty( $list[ $by_id[ $id ] ]['enabled'] ) ) {
				$list[ $by_id[ $id ] ]['enabled'] = false;
				$changed['off'][]                 = self::plain( $list[ $by_id[ $id ] ]['text'] );
			}
		}
		foreach ( $on as $id ) {
			if ( empty( $list[ $by_id[ $id ] ]['enabled'] ) ) {
				$list[ $by_id[ $id ] ]['enabled'] = true;
				$changed['on'][]                  = self::plain( $list[ $by_id[ $id ] ]['text'] );
			}
		}

		$custom = TWTAEO_Visibility_Store::count_custom_questions( $list );
		foreach ( $add as $text ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $text ) ) );
			if ( mb_strlen( $text ) < 3 ) {
				continue;
			}
			$id = TWTAEO_Visibility_Store::custom_question_id( $text );
			if ( isset( $by_id[ $id ] ) ) {
				if ( empty( $list[ $by_id[ $id ] ]['enabled'] ) ) {
					$list[ $by_id[ $id ] ]['enabled'] = true;
					$changed['on'][]                  = $text;
				}
				continue;
			}
			if ( $custom >= TWTAEO_Visibility::CUSTOM_MAX ) {
				return new WP_Error(
					'twtaeo_q_full',
					/* translators: %d: maximum number of the owner's own questions. */
					sprintf( __( 'The site already has %d questions of its own, the most allowed. Switch some off or remove them on the AI Visibility screen first.', 'twt-aeo-ultimate' ), TWTAEO_Visibility::CUSTOM_MAX )
				);
			}
			array_unshift(
				$list,
				array(
					'id'          => $id,
					'level'       => 'company',
					'scope_id'    => '',
					'scope_label' => __( 'Your question', 'twt-aeo-ultimate' ),
					'family'      => 'custom',
					'text'        => $text,
					'source'      => 'custom',
					'truth'       => null,
					'enabled'     => true,
				)
			);
			$by_id = array_flip( array_map( 'strval', wp_list_pluck( $list, 'id' ) ) );
			++$custom;
			$changed['added'][] = $text;
		}
		if ( ! $changed['added'] && ! $changed['off'] && ! $changed['on'] ) {
			return new WP_Error( 'twtaeo_q_same', __( 'Nothing would change: those questions are already in that state.', 'twt-aeo-ultimate' ) );
		}
		return array( 'list' => $list ) + $changed;
	}

	public static function questions_preview( array $input ) {
		$p = self::questions_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$enabled = 0;
		foreach ( $p['list'] as $q ) {
			$enabled += empty( $q['enabled'] ) ? 0 : 1;
		}
		return array(
			'add'                => $p['added'],
			'switch_off'         => $p['off'],
			'switch_on'          => $p['on'],
			'enabled_afterwards' => $enabled,
			'note'               => __( 'Questions you add are always asked; the per-page counts on the AI Visibility screen size the built-in ones.', 'twt-aeo-ultimate' ),
		);
	}

	public static function questions_apply( array $input ) {
		$p = self::questions_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$spec   = array(
			'kind'   => 'option',
			'option' => TWTAEO_Visibility_Types::OPTION_QUESTIONS,
		);
		$before = self::snapshot( $spec );
		TWTAEO_Visibility_Store::save_questions( $p['list'] );
		$parts = array();
		if ( $p['added'] ) {
			/* translators: %d: number of questions. */
			$parts[] = sprintf( _n( '%d added', '%d added', count( $p['added'] ), 'twt-aeo-ultimate' ), count( $p['added'] ) );
		}
		if ( $p['off'] ) {
			/* translators: %d: number of questions. */
			$parts[] = sprintf( __( '%d switched off', 'twt-aeo-ultimate' ), count( $p['off'] ) );
		}
		if ( $p['on'] ) {
			/* translators: %d: number of questions. */
			$parts[] = sprintf( __( '%d switched on', 'twt-aeo-ultimate' ), count( $p['on'] ) );
		}
		return array(
			'saved' => true,
			'log'   => array(
				'target'   => __( 'AI Visibility questions', 'twt-aeo-ultimate' ),
				/* translators: %s: what changed, e.g. "2 added, 1 switched off". */
				'summary'  => sprintf( __( 'Tracked questions: %s.', 'twt-aeo-ultimate' ), implode( ', ', $parts ) ),
				'after'    => implode( ' | ', array_merge( $p['added'], $p['on'] ) ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── Image alt text ─────────────────────────── */

	/** @return array|WP_Error { post, changes: [ key => { image, current, new } ] } */
	private static function alt_plan( array $input ) {
		$post = TWTAEO_Abilities::resolve_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! in_array( $post->post_type, TWTAEO_Image_Optimizer::post_types(), true ) ) {
			return new WP_Error( 'twtaeo_alt_type', __( 'Alt text here covers posts and pages. Product images are handled on the WooCommerce screen.', 'twt-aeo-ultimate' ) );
		}
		$images = array();
		foreach ( TWTAEO_Image_Optimizer::collect( $post ) as $img ) {
			$images[ $img['key'] ] = $img;
		}
		$changes = array();
		foreach ( (array) $input['alts'] as $row ) {
			$key = isset( $row['image_key'] ) ? (string) $row['image_key'] : '';
			if ( ! isset( $images[ $key ] ) ) {
				return new WP_Error( 'twtaeo_alt_key', __( 'An image_key does not match this page. Get the keys with get_image_alt_gaps for this page.', 'twt-aeo-ultimate' ) );
			}
			$alt = self::plain( isset( $row['alt'] ) ? $row['alt'] : '', TWTAEO_Image_Optimizer::ALT_MAX );
			if ( '' === $alt || $alt === $images[ $key ]['alt'] ) {
				continue;
			}
			$changes[ $key ] = array(
				'image'   => wp_basename( (string) wp_parse_url( (string) $images[ $key ]['src'], PHP_URL_PATH ) ),
				'current' => '' !== $images[ $key ]['alt'] ? $images[ $key ]['alt'] : null,
				'new'     => $alt,
			);
		}
		if ( ! $changes ) {
			return new WP_Error( 'twtaeo_alt_same', __( 'Nothing would change: the alt text given is empty or already in place.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'post'    => $post,
			'changes' => $changes,
		);
	}

	public static function alt_preview( array $input ) {
		$p = self::alt_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		return self::page( $p['post'] ) + array(
			'images' => array_values( $p['changes'] ),
			'note'   => __( 'An image in the media library keeps its alt text everywhere it is used, so this also updates other pages showing the same image.', 'twt-aeo-ultimate' ),
		);
	}

	public static function alt_apply( array $input ) {
		$p = self::alt_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$spec   = array(
			'kind'    => 'alt',
			'post_id' => $p['post']->ID,
		);
		$before = self::snapshot( $spec );
		$map    = array();
		foreach ( $p['changes'] as $key => $c ) {
			$map[ $key ] = $c['new'];
		}
		$saved = TWTAEO_Image_Optimizer::save_manual( $p['post']->ID, $map );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return array(
			'saved'  => true,
			'images' => count( $map ),
			'log'    => array(
				'target'   => self::plain( get_the_title( $p['post'] ) ),
				'post_id'  => $p['post']->ID,
				/* translators: %d: number of images. */
				'summary'  => sprintf( _n( 'Alt text written for %d image.', 'Alt text written for %d images.', count( $map ), 'twt-aeo-ultimate' ), count( $map ) ),
				'after'    => implode( ' | ', $map ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── Open Graph ─────────────────────────── */

	const OG_KEYS = array(
		TWTAEO_OG_Writer::META_TITLE,
		TWTAEO_OG_Writer::META_DESC,
		TWTAEO_OG_Writer::META_TYPE,
		TWTAEO_OG_Writer::META_IMAGE,
		TWTAEO_OG_Writer::META_IMAGE_ALT,
	);

	/** @return array|WP_Error { post, current, new } */
	private static function og_plan( array $input ) {
		$post = TWTAEO_Abilities::resolve_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( empty( $input['og_title'] ) && empty( $input['og_description'] ) ) {
			return new WP_Error( 'twtaeo_og_empty', __( 'Pass og_title, og_description or both.', 'twt-aeo-ultimate' ) );
		}
		$current = TWTAEO_OG_Writer::get( $post->ID );
		$new     = $current;
		if ( ! empty( $input['og_title'] ) ) {
			$new['og_title'] = self::plain( $input['og_title'], 100 );
		}
		if ( ! empty( $input['og_description'] ) ) {
			$new['og_description'] = self::plain( $input['og_description'], 300 );
		}
		if ( empty( $new['og_type'] ) ) {
			$new['og_type'] = 'page' === $post->post_type ? 'website' : 'article';
		}
		return array(
			'post'    => $post,
			'current' => $current,
			'new'     => $new,
		);
	}

	public static function og_preview( array $input ) {
		$p = self::og_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$out = self::page( $p['post'] ) + array(
			'current' => array(
				'og_title'       => '' !== (string) $p['current']['og_title'] ? (string) $p['current']['og_title'] : null,
				'og_description' => '' !== (string) $p['current']['og_description'] ? (string) $p['current']['og_description'] : null,
			),
			'new'     => array(
				'og_title'       => (string) $p['new']['og_title'],
				'og_description' => (string) $p['new']['og_description'],
			),
			'note'    => __( 'The share image is left as it is.', 'twt-aeo-ultimate' ),
		);
		if ( TWTAEO_OG_Writer::seo_plugin_active() ) {
			$out['warning'] = __( 'An SEO plugin is active and may output its own Open Graph tags. Check which plugin owns Open Graph on AEO Ultimate → Schema Conflicts.', 'twt-aeo-ultimate' );
		}
		return $out;
	}

	public static function og_apply( array $input ) {
		$p = self::og_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$spec   = array(
			'kind'    => 'postmeta',
			'post_id' => $p['post']->ID,
			'keys'    => self::OG_KEYS,
		);
		$before = self::snapshot( $spec );
		TWTAEO_OG_Writer::save( $p['post']->ID, $p['new'] );
		return array(
			'saved' => true,
			'log'   => array(
				'target'   => self::plain( get_the_title( $p['post'] ) ),
				'post_id'  => $p['post']->ID,
				'summary'  => __( 'Open Graph title/description changed.', 'twt-aeo-ultimate' ),
				'before'   => trim( $p['current']['og_title'] . ' — ' . $p['current']['og_description'], ' —' ),
				'after'    => trim( $p['new']['og_title'] . ' — ' . $p['new']['og_description'], ' —' ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── 404 redirect ─────────────────────────── */

	/** @return array|WP_Error { key, from, target } */
	private static function redirect_plan( array $input ) {
		$from = (string) wp_parse_url( (string) $input['from'], PHP_URL_PATH );
		$from = '/' . ltrim( $from, '/' );
		$key  = strtolower( trim( $from, '/' ) );
		if ( '' === $key ) {
			return new WP_Error( 'twtaeo_redirect_home', __( 'The homepage cannot be redirected.', 'twt-aeo-ultimate' ) );
		}
		if ( TWTAEO_Smart_404::is_hostile_probe( $from ) ) {
			return new WP_Error( 'twtaeo_redirect_probe', __( 'That path looks like an attack probe (wp-config, .env and the like). Those are left to return 404.', 'twt-aeo-ultimate' ) );
		}
		$live = url_to_postid( home_url( $from ) );
		if ( $live && 'publish' === get_post_status( $live ) ) {
			return new WP_Error( 'twtaeo_redirect_live', __( 'That URL is a live page, so a 404 redirect would never be used.', 'twt-aeo-ultimate' ) );
		}
		$target = TWTAEO_Abilities::resolve_post(
			array(
				'post_id' => isset( $input['to_post_id'] ) ? $input['to_post_id'] : 0,
				'url'     => isset( $input['to_url'] ) ? $input['to_url'] : '',
			)
		);
		if ( is_wp_error( $target ) ) {
			return new WP_Error( 'twtaeo_redirect_target', __( 'Choose the target with to_post_id or to_url: a published page on this site.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'key'    => $key,
			'from'   => $from,
			'target' => $target,
		);
	}

	public static function redirect_preview( array $input ) {
		$p = self::redirect_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$existing = TWTAEO_Smart_404::get_redirects();
		$out      = array(
			'will_do' => sprintf(
				/* translators: 1: old path, 2: target page title. */
				__( 'Send visitors and crawlers who request %1$s to "%2$s" with a permanent (301) redirect.', 'twt-aeo-ultimate' ),
				$p['from'],
				self::plain( get_the_title( $p['target'] ) )
			),
			'from'    => $p['from'],
			'to'      => self::page( $p['target'] ),
		);
		if ( isset( $existing[ $p['key'] ] ) ) {
			$out['warning'] = __( 'A redirect from this path already exists; it will be replaced.', 'twt-aeo-ultimate' );
		}
		return $out;
	}

	public static function redirect_apply( array $input ) {
		$p = self::redirect_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$spec   = array(
			'kind' => 'redirect',
			'key'  => $p['key'],
		);
		$before = self::snapshot( $spec );
		TWTAEO_Smart_404::add_manual_redirect( $p['from'], (string) get_permalink( $p['target'] ) );
		return array(
			'saved' => true,
			'log'   => array(
				'target'   => self::plain( get_the_title( $p['target'] ) ),
				'post_id'  => $p['target']->ID,
				'summary'  => __( '404 redirect added.', 'twt-aeo-ultimate' ),
				'before'   => $p['from'],
				'after'    => (string) get_permalink( $p['target'] ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── Schema suppression ─────────────────────────── */

	const SUPPRESSIBLE = array(
		'yoast'     => 'Yoast SEO',
		'rank_math' => 'Rank Math',
		'aioseo'    => 'All in One SEO',
		'saswp'     => 'Schema & Structured Data for WP',
	);

	public static function suppress_preview( array $input ) {
		$plugin = (string) $input['plugin'];
		$type   = self::plain( $input['type'], 60 );
		$now    = false;
		foreach ( TWTAEO_Schema_Conflict_Detector::get_suppressions() as $e ) {
			if ( $e['plugin'] === $plugin && $e['type'] === $type ) {
				$now = ! empty( $e['active'] );
			}
		}
		if ( $now === (bool) $input['suppress'] ) {
			return new WP_Error( 'twtaeo_suppress_same', __( 'Nothing would change: that is already the setting.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'will_do' => $input['suppress']
				/* translators: 1: plugin name, 2: schema type. */
				? sprintf( __( 'Stop %1$s from outputting its %2$s schema, leaving AEO Ultimate\'s version as the only one.', 'twt-aeo-ultimate' ), self::SUPPRESSIBLE[ $plugin ], $type )
				/* translators: 1: plugin name, 2: schema type. */
				: sprintf( __( 'Let %1$s output its %2$s schema again, alongside AEO Ultimate\'s.', 'twt-aeo-ultimate' ), self::SUPPRESSIBLE[ $plugin ], $type ),
			'note'    => __( 'Only that one schema type is affected; the other plugin\'s other schema and settings are untouched.', 'twt-aeo-ultimate' ),
		);
	}

	public static function suppress_apply( array $input ) {
		$preview = self::suppress_preview( $input );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$plugin = (string) $input['plugin'];
		$type   = self::plain( $input['type'], 60 );
		$spec   = array(
			'kind'   => 'option',
			'option' => TWTAEO_Schema_Conflict_Detector::OPTION_SUPPRESS,
		);
		$before = self::snapshot( $spec );
		TWTAEO_Schema_Conflict_Detector::save_suppression( $plugin, $type, (bool) $input['suppress'] );
		return array(
			'saved' => true,
			'log'   => array(
				'target'   => __( 'Schema', 'twt-aeo-ultimate' ),
				'summary'  => $input['suppress']
					/* translators: 1: plugin name, 2: schema type. */
					? sprintf( __( '%1$s %2$s schema suppressed.', 'twt-aeo-ultimate' ), self::SUPPRESSIBLE[ $plugin ], $type )
					/* translators: 1: plugin name, 2: schema type. */
					: sprintf( __( '%1$s %2$s schema allowed again.', 'twt-aeo-ultimate' ), self::SUPPRESSIBLE[ $plugin ], $type ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── AI Ready feature ─────────────────────────── */

	const AI_READY_FEATURES = array(
		'markdown_negotiation' => 'Markdown for AI agents',
		'llms_txt'             => 'llms.txt',
		'agent_skills_index'   => 'Agent skills index',
		'semantic_breadcrumbs' => 'Semantic breadcrumbs',
		'agent_search_api'     => 'Agent search API',
		'api_catalog'          => 'API catalog',
		'mcp_integration'      => 'Public MCP server',
		'rate_limit_enabled'   => 'AI bot rate limiting',
		'oauth_discovery'      => 'OAuth discovery',
	);

	public static function ai_ready_preview( array $input ) {
		$key = (string) $input['feature'];
		$s   = TWTAEO_AI_Ready::get_settings();
		if ( ! empty( $s[ $key ] ) === (bool) $input['on'] ) {
			return new WP_Error( 'twtaeo_air_same', __( 'Nothing would change: that feature is already in that state.', 'twt-aeo-ultimate' ) );
		}
		$out = array(
			'will_do' => sprintf(
				/* translators: 1: "Turn on" or "Turn off", 2: feature name. */
				__( '%1$s %2$s.', 'twt-aeo-ultimate' ),
				$input['on'] ? __( 'Turn on', 'twt-aeo-ultimate' ) : __( 'Turn off', 'twt-aeo-ultimate' ),
				self::AI_READY_FEATURES[ $key ]
			),
			'note'    => __( 'Takes effect immediately for every visitor and crawler. Files under /.well-known/ are written or removed as the AI Ready screen would.', 'twt-aeo-ultimate' ),
		);
		if ( 'rate_limit_enabled' === $key && $input['on'] ) {
			$out['warning'] = __( 'Rate limiting throttles AI crawlers. Set limits that leave room for the crawlers you want, on the AI Ready screen.', 'twt-aeo-ultimate' );
		}
		return $out;
	}

	public static function ai_ready_apply( array $input ) {
		$preview = self::ai_ready_preview( $input );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$key      = (string) $input['feature'];
		$spec     = array(
			'kind' => 'ai_ready',
			'key'  => $key,
		);
		$before   = self::snapshot( $spec );
		$settings = get_option( TWTAEO_AI_Ready::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings[ $key ] = $input['on'] ? 1 : 0;
		self::save_ai_ready( $settings );
		return array(
			'saved' => true,
			'log'   => array(
				'target'   => __( 'AI Ready', 'twt-aeo-ultimate' ),
				'summary'  => sprintf(
					/* translators: 1: feature name, 2: "on" or "off". */
					__( '%1$s turned %2$s.', 'twt-aeo-ultimate' ),
					self::AI_READY_FEATURES[ $key ],
					$input['on'] ? __( 'on', 'twt-aeo-ultimate' ) : __( 'off', 'twt-aeo-ultimate' )
				),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── Google re-check ─────────────────────────── */

	/** @return array|WP_Error { post, site_url } */
	private static function gsc_plan( array $input ) {
		$post = TWTAEO_Abilities::resolve_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return new WP_Error( 'twtaeo_gsc_off', __( 'Google Search Console is not connected. Connect it in AEO Ultimate → Settings.', 'twt-aeo-ultimate' ) );
		}
		$config   = TWTAEO_Google_OAuth::get_config();
		$site_url = isset( $config['gsc_site_url'] ) ? (string) $config['gsc_site_url'] : '';
		if ( '' === $site_url ) {
			return new WP_Error( 'twtaeo_gsc_property', __( 'No Search Console property is chosen. Pick one in AEO Ultimate → Settings.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'post'     => $post,
			'site_url' => $site_url,
		);
	}

	public static function gsc_preview( array $input ) {
		$p = self::gsc_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		return self::page( $p['post'] ) + array(
			'will_do' => __( 'Ask Google Search Console for this page\'s current index status and re-check the page for problems that keep pages out of the index.', 'twt-aeo-ultimate' ),
			'note'    => __( 'Uses one of the Search Console property\'s daily URL inspections (Google allows about 2,000 a day). Changes nothing on the site; it cannot be undone because there is nothing to undo.', 'twt-aeo-ultimate' ),
		);
	}

	public static function gsc_apply( array $input ) {
		$p = self::gsc_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$url = (string) get_permalink( $p['post'] );
		$gsc = TWTAEO_Index_Status::inspect_url( $url, $p['site_url'] );
		if ( is_wp_error( $gsc ) ) {
			return $gsc;
		}
		$heuristics = TWTAEO_Index_Heuristics::analyze( $p['post']->ID );
		TWTAEO_Index_Status::save_result( $p['post']->ID, $gsc, is_array( $heuristics ) ? $heuristics : array() );

		$problems = array();
		foreach ( (array) ( isset( $heuristics['flags'] ) ? $heuristics['flags'] : array() ) as $f ) {
			$problems[] = self::plain( isset( $f['label'] ) ? $f['label'] : '' );
		}
		return array(
			'indexed'       => empty( $gsc['not_indexed'] ),
			'google_status' => self::plain( $gsc['coverage_state'] ),
			'last_crawl'    => '' !== (string) $gsc['last_crawl'] ? (string) $gsc['last_crawl'] : null,
			'page_problems' => $problems,
			'log'           => array(
				'target'  => self::plain( get_the_title( $p['post'] ) ),
				'post_id' => $p['post']->ID,
				/* translators: %s: Google's coverage state. */
				'summary' => sprintf( __( 'Re-checked in Google: %s', 'twt-aeo-ultimate' ), self::plain( $gsc['coverage_state'] ) ),
			),
		);
	}

	/* ─────────────────────────── Buyer personas ─────────────────────────── */

	public static function personas_preview( array $input ) {
		$new = TWTAEO_Visibility_Personas::clean( (array) $input['personas'] );
		$old = TWTAEO_Visibility_Personas::all();
		if ( wp_list_pluck( $new, 'label' ) === wp_list_pluck( $old, 'label' ) ) {
			return new WP_Error( 'twtaeo_personas_same', __( 'Nothing would change: that is already the persona list.', 'twt-aeo-ultimate' ) );
		}
		$out = array(
			'current' => array_values( wp_list_pluck( $old, 'label' ) ),
			'new'     => array_values( wp_list_pluck( $new, 'label' ) ),
			'note'    => sprintf(
				/* translators: %d: maximum number of personas. */
				__( 'Replaces the whole list (at most %d). A run asks as one persona, chosen when it starts on the AI Visibility screen.', 'twt-aeo-ultimate' ),
				TWTAEO_Visibility_Types::PERSONAS_MAX
			),
		);
		if ( ! TWTAEO_Visibility_Personas::enabled() ) {
			$out['warning'] = __( 'Personas are switched off on this site, so runs will not use them until they are switched on on the AI Visibility screen.', 'twt-aeo-ultimate' );
		}
		return $out;
	}

	public static function personas_apply( array $input ) {
		$preview = self::personas_preview( $input );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$spec   = array( 'kind' => 'personas' );
		$before = self::snapshot( $spec );
		TWTAEO_Visibility_Personas::save( (array) $input['personas'] );
		return array(
			'saved' => true,
			'log'   => array(
				'target'   => __( 'Buyer personas', 'twt-aeo-ultimate' ),
				'summary'  => __( 'Buyer personas replaced.', 'twt-aeo-ultimate' ),
				'before'   => implode( ', ', $preview['current'] ),
				'after'    => implode( ', ', $preview['new'] ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── Company profile ─────────────────────────── */

	const COMPANY_FIELDS = array( 'legal_name', 'description', 'founding_year', 'phone', 'email', 'social_facebook', 'social_twitter', 'social_linkedin', 'social_instagram', 'social_youtube', 'social_wikipedia', 'social_pinterest' );

	/** @return array|WP_Error { current, new, changes } */
	private static function company_plan( array $input ) {
		$current = TWTAEO_Company_Profile::get();
		$new     = $current;
		$changes = array();
		foreach ( (array) $input['fields'] as $k => $v ) {
			$v = trim( (string) $v );
			if ( 0 === strpos( $k, 'social_' ) && '' !== $v && ! wp_http_validate_url( $v ) ) {
				return new WP_Error( 'twtaeo_company_url', __( 'Social profile fields must be full https:// URLs.', 'twt-aeo-ultimate' ) );
			}
			if ( (string) $current[ $k ] === $v ) {
				continue;
			}
			$new[ $k ]     = $v;
			$changes[ $k ] = array(
				'current' => '' !== (string) $current[ $k ] ? self::plain( $current[ $k ], 500 ) : null,
				'new'     => self::plain( $v, 500 ),
			);
		}
		if ( ! $changes ) {
			return new WP_Error( 'twtaeo_company_same', __( 'Nothing would change: those fields already hold those values.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'new'     => $new,
			'changes' => $changes,
		);
	}

	public static function company_preview( array $input ) {
		$p = self::company_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		return array(
			'changes' => $p['changes'],
			'note'    => __( 'These feed the Organization schema on every page. Only enter facts the owner has confirmed.', 'twt-aeo-ultimate' ),
		);
	}

	public static function company_apply( array $input ) {
		$p = self::company_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$spec   = array(
			'kind'   => 'option',
			'option' => TWTAEO_Company_Profile::OPTION_KEY,
		);
		$before = self::snapshot( $spec );
		TWTAEO_Company_Profile::save( $p['new'] );
		delete_transient( TWTAEO_Aeo_Score::SUMMARY_CACHE );
		return array(
			'saved' => true,
			'log'   => array(
				'target'   => __( 'Company profile', 'twt-aeo-ultimate' ),
				/* translators: %s: field names. */
				'summary'  => sprintf( __( 'Company profile updated: %s.', 'twt-aeo-ultimate' ), implode( ', ', array_keys( $p['changes'] ) ) ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── Author profile ─────────────────────────── */

	const AUTHOR_META = array(
		'bio'              => 'description',
		'job_title'        => 'twtaeo_job_title',
		'credentials'      => 'twtaeo_credentials',
		'expertise'        => 'twtaeo_expertise',
		'years_experience' => 'twtaeo_years_experience',
	);

	/** @return array|WP_Error { user, meta: [ key => value ], social, changes } */
	private static function author_plan( array $input ) {
		$user = get_userdata( (int) $input['user_id'] );
		if ( ! $user || ! count_user_posts( $user->ID, 'post', true ) && ! count_user_posts( $user->ID, 'page', true ) ) {
			return new WP_Error( 'twtaeo_author_none', __( 'No author with published posts has that user_id. Get the ids with get_eeat_scorecard.', 'twt-aeo-ultimate' ) );
		}
		$data    = TWTAEO_Author_Meta::get_author_data( $user->ID );
		$fields  = isset( $input['fields'] ) ? (array) $input['fields'] : array();
		$meta    = array();
		$changes = array();
		foreach ( self::AUTHOR_META as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}
			$v   = 'years_experience' === $field ? (string) absint( $fields[ $field ] ) : ( 'bio' === $field ? sanitize_textarea_field( (string) $fields[ $field ] ) : sanitize_text_field( (string) $fields[ $field ] ) );
			$was = (string) $data[ $field ];
			if ( $was === $v ) {
				continue;
			}
			$meta[ $meta_key ] = $v;
			$changes[ $field ] = array(
				'current' => '' !== $was && '0' !== $was ? self::plain( $was, 600 ) : null,
				'new'     => self::plain( $v, 600 ),
			);
		}

		$social = null;
		if ( ! empty( $input['social'] ) ) {
			$social = array_merge( array_fill_keys( array_keys( TWTAEO_Author_Meta::$social_networks ), '' ), (array) $data['social'] );
			foreach ( (array) $input['social'] as $network => $url ) {
				$url = trim( (string) $url );
				if ( '' !== $url && ! wp_http_validate_url( $url ) ) {
					return new WP_Error( 'twtaeo_author_url', __( 'Profile links must be full https:// URLs.', 'twt-aeo-ultimate' ) );
				}
				if ( (string) $social[ $network ] !== $url ) {
					$changes[ 'social_' . $network ] = array(
						'current' => '' !== (string) $social[ $network ] ? (string) $social[ $network ] : null,
						'new'     => $url,
					);
					$social[ $network ]              = esc_url_raw( $url );
				}
			}
		}
		if ( ! $changes ) {
			return new WP_Error( 'twtaeo_author_same', __( 'Nothing would change: those fields already hold those values.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'user'    => $user,
			'meta'    => $meta,
			'social'  => $social,
			'changes' => $changes,
		);
	}

	public static function author_preview( array $input ) {
		$p = self::author_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		return array(
			'author'  => self::plain( $p['user']->display_name ),
			'changes' => $p['changes'],
			'note'    => __( 'Shown in author bios and the Person schema on this author\'s posts. Only enter facts the author has confirmed; never invent credentials.', 'twt-aeo-ultimate' ),
		);
	}

	public static function author_apply( array $input ) {
		$p = self::author_plan( $input );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$spec   = array(
			'kind'    => 'usermeta',
			'user_id' => $p['user']->ID,
			'keys'    => array_merge( array_values( self::AUTHOR_META ), array( 'twtaeo_social' ) ),
		);
		$before = self::snapshot( $spec );
		foreach ( $p['meta'] as $key => $value ) {
			update_user_meta( $p['user']->ID, $key, wp_slash( $value ) );
		}
		if ( null !== $p['social'] ) {
			update_user_meta( $p['user']->ID, 'twtaeo_social', TWTAEO_Custom_Schema_Writer::encode_for_meta( $p['social'] ) );
		}
		delete_transient( TWTAEO_Aeo_Score::SUMMARY_CACHE );
		return array(
			'saved' => true,
			'log'   => array(
				'target'   => self::plain( $p['user']->display_name ),
				/* translators: %s: field names. */
				'summary'  => sprintf( __( 'Author profile updated: %s.', 'twt-aeo-ultimate' ), implode( ', ', array_keys( $p['changes'] ) ) ),
				'snapshot' => self::snap_block( $spec, $before ),
			),
		);
	}

	/* ─────────────────────────── Snapshots & revert ─────────────────────────── */

	/** Every meta key save_description() writes, whether or not that SEO plugin is active now. */
	const META_SNAPSHOT_KEYS = array(
		TWTAEO_AI_Description::META_KEY,
		'_yoast_wpseo_metadesc',
		'rank_math_description',
		'_seopress_titles_desc',
		'_aioseop_description',
	);

	/** Stands in for "this option does not exist" while snapshotting. */
	const ABSENT = '__twtaeo_absent__';

	/**
	 * Exactly what an action touched, so a revert puts back what was there —
	 * including "nothing was there" (null).
	 *
	 * @param array $spec { kind, post_id? | user_id? | keys? | option? | key? }
	 *   kind: meta (meta description) | faq | postmeta | usermeta | option |
	 *   redirect | alt | ai_ready | personas.
	 * @return mixed
	 */
	private static function snapshot( array $spec ) {
		$post_id = isset( $spec['post_id'] ) ? (int) $spec['post_id'] : 0;
		switch ( $spec['kind'] ) {
			case 'meta':
				return self::meta_values( 'post', $post_id, self::META_SNAPSHOT_KEYS );
			case 'postmeta':
				return self::meta_values( 'post', $post_id, (array) $spec['keys'] );
			case 'usermeta':
				return self::meta_values( 'user', (int) $spec['user_id'], (array) $spec['keys'] );
			case 'faq':
				$fingerprint = TWTAEO_FAQ_Detector::META_FINGERPRINT;
				return array(
					'schema'      => TWTAEO_Custom_Schema_Writer::get_by_type( $post_id, 'FAQPage' ),
					'fingerprint' => metadata_exists( 'post', $post_id, $fingerprint ) ? (string) get_post_meta( $post_id, $fingerprint, true ) : null,
				);
			case 'option':
				$value = get_option( $spec['option'], self::ABSENT );
				return self::ABSENT === $value ? null : $value;
			case 'redirect':
				$all = TWTAEO_Smart_404::get_redirects();
				return isset( $all[ $spec['key'] ] ) ? $all[ $spec['key'] ] : null;
			case 'ai_ready':
				$settings = TWTAEO_AI_Ready::get_settings();
				return isset( $settings[ $spec['key'] ] ) ? $settings[ $spec['key'] ] : null;
			case 'personas':
				return TWTAEO_Visibility_Personas::all();
			case 'alt':
				$post   = get_post( $post_id );
				$images = array();
				$att    = array();
				foreach ( $post ? TWTAEO_Image_Optimizer::collect( $post ) : array() as $img ) {
					$images[ $img['key'] ] = (string) $img['alt'];
					if ( $img['id'] ) {
						$att[ (int) $img['id'] ] = metadata_exists( 'post', (int) $img['id'], '_wp_attachment_image_alt' )
							? (string) get_post_meta( (int) $img['id'], '_wp_attachment_image_alt', true )
							: null;
					}
				}
				return array(
					'images'      => $images,
					'attachments' => $att,
				);
		}
		return null;
	}

	private static function meta_values( $type, $object_id, array $keys ) {
		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = metadata_exists( $type, $object_id, $key ) ? get_metadata( $type, $object_id, $key, true ) : null;
		}
		return $out;
	}

	/** @return true|WP_Error */
	private static function restore( array $spec, $snap ) {
		$post_id = isset( $spec['post_id'] ) ? (int) $spec['post_id'] : 0;
		switch ( $spec['kind'] ) {
			case 'meta':
			case 'postmeta':
			case 'usermeta':
				$type = 'usermeta' === $spec['kind'] ? 'user' : 'post';
				$id   = 'user' === $type ? (int) $spec['user_id'] : $post_id;
				$keys = 'meta' === $spec['kind'] ? self::META_SNAPSHOT_KEYS : (array) $spec['keys'];
				foreach ( $keys as $key ) {
					$value = is_array( $snap ) && array_key_exists( $key, $snap ) ? $snap[ $key ] : null;
					if ( null === $value ) {
						delete_metadata( $type, $id, $key );
					} else {
						update_metadata( $type, $id, $key, wp_slash( $value ) );
					}
				}
				return true;

			case 'faq':
				if ( empty( $snap['schema'] ) || ! is_array( $snap['schema'] ) ) {
					TWTAEO_Custom_Schema_Writer::delete( $post_id, 'FAQPage' );
				} else {
					$saved = TWTAEO_Custom_Schema_Writer::save( $post_id, 'FAQPage', (string) wp_json_encode( $snap['schema'] ) );
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}
				}
				if ( null === $snap['fingerprint'] ) {
					delete_post_meta( $post_id, TWTAEO_FAQ_Detector::META_FINGERPRINT );
				} else {
					update_post_meta( $post_id, TWTAEO_FAQ_Detector::META_FINGERPRINT, wp_slash( $snap['fingerprint'] ) );
				}
				self::refresh_faq_cache( $post_id );
				return true;

			case 'option':
				if ( null === $snap ) {
					delete_option( $spec['option'] );
				} else {
					update_option( $spec['option'], $snap, false );
				}
				return true;

			case 'redirect':
				$all = TWTAEO_Smart_404::get_redirects();
				if ( null === $snap ) {
					unset( $all[ $spec['key'] ] );
				} else {
					$all[ $spec['key'] ] = $snap;
				}
				update_option( TWTAEO_Smart_404::OPTION_REDIRECTS, $all, false );
				return true;

			case 'ai_ready':
				$settings                  = get_option( TWTAEO_AI_Ready::OPTION_KEY, array() );
				$settings                  = is_array( $settings ) ? $settings : array();
				$settings[ $spec['key'] ]  = $snap;
				self::save_ai_ready( $settings );
				return true;

			case 'personas':
				TWTAEO_Visibility_Personas::save( (array) $snap );
				return true;

			case 'alt':
				$images = isset( $snap['images'] ) ? (array) $snap['images'] : array();
				if ( $images ) {
					TWTAEO_Image_Optimizer::save_manual( $post_id, $images );
				}
				foreach ( (array) ( isset( $snap['attachments'] ) ? $snap['attachments'] : array() ) as $att => $value ) {
					if ( null === $value ) {
						delete_post_meta( (int) $att, '_wp_attachment_image_alt' );
					} else {
						update_post_meta( (int) $att, '_wp_attachment_image_alt', wp_slash( $value ) );
					}
				}
				return true;
		}
		return new WP_Error( 'twtaeo_revert_kind', __( 'This kind of change cannot be reverted.', 'twt-aeo-ultimate' ) );
	}

	/**
	 * Save AI Ready settings and do what the AI Ready screen does after a save:
	 * write or remove the /.well-known/ files and flush rewrite rules.
	 */
	private static function save_ai_ready( array $settings ) {
		update_option( TWTAEO_AI_Ready::OPTION_KEY, $settings );
		$files = array(
			'api_catalog'        => array( 'write_api_catalog_file', 'delete_api_catalog_file' ),
			'agent_skills_index' => array( 'write_agent_skills_index', 'delete_agent_skills_index' ),
			'oauth_discovery'    => array( 'write_oauth_discovery_files', 'delete_oauth_discovery_files' ),
			'mcp_integration'    => array( 'write_mcp_server_card_file', 'delete_mcp_server_card_file' ),
		);
		foreach ( $files as $key => $calls ) {
			call_user_func( array( 'TWTAEO_AI_Ready', ! empty( $settings[ $key ] ) ? $calls[0] : $calls[1] ) );
		}
		delete_option( 'twtaeo_air_rules_flushed' );
		TWTAEO_AI_Ready::flush_rewrites();
	}

	/** The spec a logged change was made against (entries from before specs existed carry only a kind). */
	private static function entry_spec( array $entry ) {
		if ( ! empty( $entry['snapshot']['spec'] ) ) {
			return $entry['snapshot']['spec'];
		}
		return array(
			'kind'    => $entry['snapshot']['kind'],
			'post_id' => (int) $entry['post_id'],
		);
	}

	/** A snapshot block for the log: { kind, spec, before, after }. */
	private static function snap_block( array $spec, $before ) {
		return array(
			'kind'   => $spec['kind'],
			'spec'   => $spec,
			'before' => $before,
			'after'  => self::snapshot( $spec ),
		);
	}

	/**
	 * What reverting one logged change would do, without doing it.
	 *
	 * @return array|WP_Error { entry, changed_since }
	 */
	public static function revert_check( $change_id ) {
		$entry = self::find( $change_id );
		if ( ! $entry ) {
			return new WP_Error( 'twtaeo_revert_missing', __( 'That change is no longer in the activity log (only the last 50 are kept).', 'twt-aeo-ultimate' ) );
		}
		if ( empty( $entry['snapshot']['kind'] ) ) {
			return new WP_Error( 'twtaeo_revert_none', __( 'This change cannot be reverted: an AI Visibility check has already spent its API credits, and a Google re-check only refreshed a report.', 'twt-aeo-ultimate' ) );
		}
		if ( ! empty( $entry['reverted'] ) ) {
			return new WP_Error( 'twtaeo_revert_done', __( 'This change has already been reverted.', 'twt-aeo-ultimate' ) );
		}
		$spec = self::entry_spec( $entry );
		if ( ! empty( $spec['post_id'] ) && ! get_post( (int) $spec['post_id'] ) ) {
			return new WP_Error( 'twtaeo_revert_gone', __( 'The page this change was made on no longer exists.', 'twt-aeo-ultimate' ) );
		}
		if ( ! empty( $spec['user_id'] ) && ! get_userdata( (int) $spec['user_id'] ) ) {
			return new WP_Error( 'twtaeo_revert_gone', __( 'The author this change was made on no longer exists.', 'twt-aeo-ultimate' ) );
		}
		return array(
			'entry'         => $entry,
			'changed_since' => self::snapshot( $spec ) !== $entry['snapshot']['after'],
		);
	}

	/**
	 * Put back what a logged change replaced. Refuses when the value has been
	 * edited again since, unless $force — someone else's later work would be
	 * lost. The revert is logged as a change of its own, so it can be undone.
	 *
	 * @return array|WP_Error The new log entry.
	 */
	public static function revert( $change_id, $force = false ) {
		$check = self::revert_check( $change_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( $check['changed_since'] && ! $force ) {
			return new WP_Error( 'twtaeo_revert_changed', __( 'This was edited again after the change was made. Reverting now would also undo those later edits.', 'twt-aeo-ultimate' ) );
		}
		$entry = $check['entry'];
		$spec  = self::entry_spec( $entry );
		$now   = self::snapshot( $spec );

		$done = self::restore( $spec, $entry['snapshot']['before'] );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$log = self::get_log();
		foreach ( $log as &$row ) {
			if ( isset( $row['id'] ) && $row['id'] === $entry['id'] ) {
				$row['reverted'] = array(
					'time' => time(),
					'user' => get_current_user_id(),
				);
			}
		}
		unset( $row );
		update_option( self::OPTION_LOG, $log, false );

		return self::log(
			'revert',
			array(
				'target'   => $entry['target'],
				'post_id'  => $entry['post_id'],
				/* translators: %s: summary of the change that was reverted. */
				'summary'  => sprintf( __( 'Reverted: %s', 'twt-aeo-ultimate' ), $entry['summary'] ),
				'before'   => $entry['after'],
				'after'    => $entry['before'],
				'snapshot' => self::snap_block( $spec, $now ),
			)
		);
	}

	private static function find( $change_id ) {
		foreach ( self::get_log() as $row ) {
			if ( isset( $row['id'] ) && hash_equals( (string) $row['id'], (string) $change_id ) ) {
				return $row;
			}
		}
		return null;
	}

	/* ─────────────────────────── Activity log ─────────────────────────── */

	/** @return array The stored entry. */
	private static function log( $slug, array $entry ) {
		$log = self::get_log();
		$row = array(
			'id'       => wp_generate_password( 12, false ),
			'time'     => time(),
			'user'     => get_current_user_id(),
			'action'   => $slug,
			'target'   => isset( $entry['target'] ) ? self::plain( $entry['target'] ) : '',
			'post_id'  => isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0,
			'summary'  => isset( $entry['summary'] ) ? self::plain( $entry['summary'] ) : '',
			'before'   => isset( $entry['before'] ) ? self::plain( $entry['before'], 300 ) : '',
			'after'    => isset( $entry['after'] ) ? self::plain( $entry['after'], 300 ) : '',
			// Exact values for revert(); never shown or sent anywhere as-is.
			'snapshot' => isset( $entry['snapshot'] ) ? $entry['snapshot'] : null,
			'reverted' => null,
		);
		array_unshift( $log, $row );
		update_option( self::OPTION_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
		return $row;
	}

	public static function get_log() {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/** The log as Claude may read it: no snapshots. */
	public static function log_summary( $limit = 25 ) {
		$out = array();
		foreach ( array_slice( self::get_log(), 0, (int) $limit ) as $row ) {
			$who   = get_userdata( (int) $row['user'] );
			$out[] = array(
				'change_id'  => isset( $row['id'] ) ? (string) $row['id'] : '',
				'when'       => gmdate( 'c', (int) $row['time'] ),
				'by'         => $who ? self::plain( $who->display_name ) : '',
				'what'       => $row['summary'],
				'page'       => $row['target'],
				'post_id'    => (int) $row['post_id'],
				'before'     => $row['before'],
				'after'      => $row['after'],
				'can_revert' => ! empty( $row['snapshot']['kind'] ) && empty( $row['reverted'] ),
				'reverted'   => ! empty( $row['reverted'] ) ? gmdate( 'c', (int) $row['reverted']['time'] ) : null,
			);
		}
		return $out;
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	/** { post_id, title, url } */
	private static function page( WP_Post $post ) {
		return array(
			'post_id' => (int) $post->ID,
			'title'   => self::plain( get_the_title( $post ) ),
			'url'     => (string) get_permalink( $post ),
		);
	}

	/** Single-line plain text, entities decoded, capped. */
	private static function plain( $text, $max = 200 ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}
}
