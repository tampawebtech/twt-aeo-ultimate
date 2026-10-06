<?php
/**
 * AI Meta Description
 *
 * Generates concise meta descriptions for posts/pages with the user's chosen AI
 * provider (Claude, OpenAI, or Gemini). Used three ways:
 *   - Per-post "Generate" button in the editor (manual).
 *   - Bulk "fill missing" action on the dashboard.
 *   - Automatic generation on save when the feature is enabled in settings.
 *
 * The result is stored in a plugin-owned meta key, written through to the active
 * SEO plugin's own field when one is present, and — when no SEO plugin is active
 * — output as a <meta name="description"> tag on the front end.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_AI_Description {

	/** Plugin-owned meta key holding the description. */
	const META_KEY = '_twtaeo_meta_description';

	/** Settings keys within the twtaeo_settings option. */
	const SETTING_ENABLED  = 'ai_desc_enabled';   // Auto-generate on save.
	const SETTING_PROVIDER = 'ai_desc_provider';  // claude | openai | gemini.

	const MAX_LENGTH = 160;

	/** Minimum body words for a page to be worth summarising — thinner pages
	 *  only yield generic, low-value descriptions, so we skip them. */
	const MIN_CONTENT_WORDS = 20;

	// Background bulk job.
	const JOB_HOOK   = 'twtaeo_ai_desc_batch';
	const JOB_OPTION = 'twtaeo_ai_desc_job';
	const JOB_LOCK   = 'twtaeo_ai_desc_lock';
	const JOB_BATCH  = 3;   // Posts per cron tick (AI calls are slow + billed).
	const JOB_GAP    = 12;  // Seconds between batches — eases rate limits.
	const JOB_MAX_NET_FAILS = 5; // Consecutive network errors before giving up.

	// Text models are the site's choice — see TWTAEO_AI_Models.

	// OpenAI image generation (used for AI-generated og:image).
	const OPENAI_IMAGE_MODEL = 'gpt-image-2';
	const OPENAI_IMAGE_SIZE  = '1536x1024'; // Landscape — closest to the 1.91:1 OG ratio.

	// ── Hooks ─────────────────────────────────────────────────────────────────

	const NONCE = 'twtaeo_ai_desc';

	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'save_metabox' ), 10, 1 );
		add_action( 'save_post', array( __CLASS__, 'maybe_auto_generate' ), 20, 3 );
		add_action( 'wp_head', array( __CLASS__, 'output_meta_tag' ), 1 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );

		// Background bulk job.
		add_action( self::JOB_HOOK, array( __CLASS__, 'run_job_batch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_job_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_job_notice' ) );
	}

	// ── Editor metabox ──────────────────────────────────────────────────────────

	public static function add_metabox() {
		foreach ( array( 'post', 'page' ) as $type ) {
			add_meta_box(
				'twtaeo_ai_description',
				__( 'AEO Meta Description', 'twt-aeo-ultimate' ),
				array( __CLASS__, 'render_metabox' ),
				$type,
				'side',
				'default'
			);
		}
	}

	public static function render_metabox( $post ) {
		wp_nonce_field( 'twtaeo_ai_desc_meta', 'twtaeo_ai_desc_meta_nonce' );
		$desc      = self::get_existing_description( $post->ID );
		$providers = self::available_providers();
		?>
		<p style="margin:0 0 6px;">
			<textarea id="twtaeo-ai-desc-text" name="twtaeo_meta_description" rows="4"
				style="width:100%;" maxlength="160"
				placeholder="<?php esc_attr_e( 'Meta description…', 'twt-aeo-ultimate' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
		</p>
		<?php if ( empty( $providers ) ) : ?>
			<p style="font-size:11px;color:#b32d2e;margin:0;">
				<?php esc_html_e( 'Add an AI key under TWT AEO → Settings to enable generation.', 'twt-aeo-ultimate' ); ?>
			</p>
		<?php else : ?>
			<p style="margin:0;display:flex;align-items:center;gap:8px;">
				<button type="button" class="button" id="twtaeo-ai-desc-btn"
					data-post="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'Generate with AI', 'twt-aeo-ultimate' ); ?>
				</button>
				<span id="twtaeo-ai-desc-status" style="font-size:11px;color:#646970;"></span>
			</p>
			<p style="font-size:11px;color:#646970;margin:6px 0 0;">
				<?php
				printf(
					/* translators: %s: AI provider name. */
					esc_html__( 'Uses %s. Change the provider on the TWT AEO dashboard.', 'twt-aeo-ultimate' ),
					esc_html( ucfirst( self::get_provider() ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function enqueue_editor_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		$data = array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'working'    => __( 'Generating…', 'twt-aeo-ultimate' ),
			'savedText'  => __( 'Saved ✓', 'twt-aeo-ultimate' ),
			'errText'    => __( 'Error — see message', 'twt-aeo-ultimate' ),
		);

		$js = '(function(){document.addEventListener("DOMContentLoaded",function(){'
			. 'var cfg=' . wp_json_encode( $data ) . ';'
			. 'var btn=document.getElementById("twtaeo-ai-desc-btn");'
			. 'var ta=document.getElementById("twtaeo-ai-desc-text");'
			. 'var st=document.getElementById("twtaeo-ai-desc-status");'
			. 'if(!btn||!ta){return;}'
			. 'btn.addEventListener("click",function(){'
			. 'btn.disabled=true;st.style.color="#646970";st.textContent=cfg.working;'
			. 'var fd=new FormData();fd.append("action","twtaeo_ai_desc_generate");'
			. 'fd.append("nonce",cfg.nonce);fd.append("post_id",btn.getAttribute("data-post"));'
			. 'fetch(cfg.ajaxUrl,{method:"POST",body:fd,credentials:"same-origin"})'
			. '.then(function(r){return r.json();}).then(function(res){'
			. 'if(res.success){ta.value=res.data.description;st.style.color="#1a6629";st.textContent=cfg.savedText;}'
			. 'else{st.style.color="#b32d2e";st.textContent=(res.data||cfg.errText);}btn.disabled=false;'
			. '}).catch(function(){st.style.color="#b32d2e";st.textContent=cfg.errText;btn.disabled=false;});'
			. '});});})();';

		wp_add_inline_script( 'jquery', $js );
	}

	/**
	 * Persist a manually-edited description from the editor metabox.
	 *
	 * @param int $post_id
	 */
	public static function save_metabox( $post_id ) {
		if ( ! isset( $_POST['twtaeo_ai_desc_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['twtaeo_ai_desc_meta_nonce'] ) ), 'twtaeo_ai_desc_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$desc = isset( $_POST['twtaeo_meta_description'] )
			? sanitize_textarea_field( wp_unslash( $_POST['twtaeo_meta_description'] ) )
			: '';

		if ( '' === trim( $desc ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		self::save_description( $post_id, $desc );
	}

	// ── Settings access ─────────────────────────────────────────────────────────

	public static function is_enabled() {
		$settings = get_option( 'twtaeo_settings', array() );
		return ! empty( $settings[ self::SETTING_ENABLED ] );
	}

	public static function get_provider() {
		$settings = get_option( 'twtaeo_settings', array() );
		$provider = $settings[ self::SETTING_PROVIDER ] ?? 'claude';
		return in_array( $provider, array( 'claude', 'openai', 'gemini' ), true ) ? $provider : 'claude';
	}

	/**
	 * User-facing notes about each provider: the model used for descriptions,
	 * its relative speed, and any caveat. Surfaced on the dashboard so users
	 * know what to expect from a bulk run — notably that Gemini is slow.
	 *
	 * @return array<string,array{label:string,model:string,note:string}>
	 */
	public static function provider_info() {
		return array(
			'claude' => array(
				'label' => 'Claude',
				'model' => TWTAEO_AI_Models::get( 'claude' ),
				'note'  => __( 'Fast and reliable. Haiku is tuned for short summaries — usually a second or two per post.', 'twt-aeo-ultimate' ),
			),
			'openai' => array(
				'label' => 'OpenAI (ChatGPT)',
				'model' => TWTAEO_AI_Models::get( 'openai' ),
				'note'  => __( 'Fast and low-cost. The Luna and mini models are quick for this task — comparable to Claude.', 'twt-aeo-ultimate' ),
			),
			'gemini' => array(
				'label' => 'Gemini',
				'model' => TWTAEO_AI_Models::get( 'gemini' ),
				'note'  => __( 'Slower than Claude or OpenAI, especially on the free tier — a large bulk run can take several minutes. It keeps working in the background, so you can leave this page and check back later.', 'twt-aeo-ultimate' ),
			),
		);
	}

	/** Provider slugs that have a usable credential (or the WP AI Client). */
	public static function available_providers() {
		$available = array();
		foreach ( array( 'claude', 'openai', 'gemini' ) as $provider ) {
			if ( '' !== TWTAEO_Key_Resolver::get( $provider ) ) {
				$available[] = $provider;
			} elseif ( in_array( $provider, array( 'claude', 'openai' ), true ) && TWTAEO_Key_Resolver::ai_client_available() ) {
				$available[] = $provider;
			}
		}
		return $available;
	}

	// ── Auto-generate on save ─────────────────────────────────────────────────

	/**
	 * Generate a description automatically when the post is saved, but only when
	 * the feature is on and the post has no description yet (never clobber a
	 * hand-written one).
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param bool    $update
	 */
	public static function maybe_auto_generate( $post_id, $post, $update ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		if ( '' !== self::get_existing_description( $post_id ) ) {
			return; // Already has one — leave it alone.
		}

		$result = self::generate_for_post( $post_id );
		if ( is_wp_error( $result ) ) {
			if ( class_exists( 'TWTAEO_Logger' ) ) {
				TWTAEO_Logger::warning( 'Auto description failed: ' . $result->get_error_message(), array( 'post_id' => $post_id ) );
			}
			return;
		}

		self::save_description( $post_id, $result );
	}

	// ── Generation ──────────────────────────────────────────────────────────────

	/**
	 * Generate (but do not save) a description for a post.
	 *
	 * @param int         $post_id
	 * @param string|null $provider Override provider; null uses the configured one.
	 * @param string      $style    'meta' (search snippet) or 'social' (OG/share copy).
	 * @return string|WP_Error
	 */
	public static function generate_for_post( $post_id, $provider = null, $style = 'meta' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'no_post', __( 'Post not found.', 'twt-aeo-ultimate' ) );
		}

		$provider = $provider ?: self::get_provider();
		$title    = get_the_title( $post_id );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, not ours to prefix.
		$content  = wp_strip_all_tags( wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ) );
		$content  = trim( preg_replace( '/\s+/', ' ', $content ) );

		// Fold in the excerpt — for products that is the short description, often
		// the whole sales pitch, and without it many products read as "thin".
		$excerpt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $post->post_excerpt ) ) );
		if ( '' !== $excerpt && false === strpos( $content, $excerpt ) ) {
			$content = trim( $excerpt . ' ' . $content );
		}

		if ( '' === $content && '' === $title ) {
			return new WP_Error( 'empty', __( 'This post has no content to summarise.', 'twt-aeo-ultimate' ) );
		}

		// Skip pages without enough body text to summarise meaningfully — a thin
		// page only produces a generic, low-value description.
		$word_count = ( '' === $content ) ? 0 : count( preg_split( '/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY ) );
		if ( $word_count < self::MIN_CONTENT_WORDS ) {
			return new WP_Error( 'thin_content', __( 'Not enough page content to write a useful description.', 'twt-aeo-ultimate' ) );
		}

		// Cap the content we send to keep token use (and cost) low.
		$content = self::truncate_words( $content, 600 );

		if ( 'social' === $style ) {
			$prompt = "Write a single social share description (for Open Graph / og:description) for the web page below.\n"
				. "Requirements: plain text only, no quotation marks, no line breaks, "
				. "between 120 and 155 characters. Make it engaging and shareable — conversational and benefit-driven, "
				. "the kind of blurb that earns clicks when the link is posted to Facebook, LinkedIn, or X. "
				. "Do not just repeat the title.\n\n"
				. "Title: {$title}\n\n"
				. "Content:\n{$content}";
		} else {
			$prompt = "Write a single meta description for the web page below.\n"
				. "Requirements: plain text only, no quotation marks, no line breaks, "
				. "between 140 and 155 characters, written to make a searcher click. "
				. "Summarise the page's main value — do not just repeat the title.\n\n"
				. "Title: {$title}\n\n"
				. "Content:\n{$content}";
		}

		$text = self::dispatch( $provider, $prompt );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$clean = self::clean_description( $text );
		if ( '' === $clean ) {
			return new WP_Error( 'empty_result', __( 'The AI returned an empty description.', 'twt-aeo-ultimate' ) );
		}

		return $clean;
	}

	// ── Provider dispatch ─────────────────────────────────────────────────────

	private static function dispatch( $provider, $prompt ) {
		$key = TWTAEO_Key_Resolver::get( $provider );

		if ( '' === $key ) {
			// No plugin key — fall back to the WP 7.0 AI Client for Claude/OpenAI.
			if ( in_array( $provider, array( 'claude', 'openai' ), true ) && TWTAEO_Key_Resolver::ai_client_available() ) {
				return self::call_via_ai_client( $provider, $prompt );
			}
			return new WP_Error(
				'no_key',
				/* translators: %s: provider name. */
				sprintf( __( 'No API key configured for %s. Add one under TWT AEO → Settings.', 'twt-aeo-ultimate' ), ucfirst( $provider ) )
			);
		}

		if ( ! in_array( $provider, array( 'claude', 'openai', 'gemini' ), true ) ) {
			return new WP_Error( 'unknown_provider', __( 'Unknown AI provider.', 'twt-aeo-ultimate' ) );
		}

		// The shared client knows each model family's request rules (temperature,
		// thinking, reasoning effort), so descriptions follow the model picked in
		// Settings without a second copy of those rules here.
		return TWTAEO_AI_Client::complete(
			$provider,
			$prompt,
			array(
				'max_tokens'  => 200,
				'temperature' => 0.7,
			)
		);
	}

	private static function call_via_ai_client( $provider, $prompt ) {
		$ai_prompt = 'wp_ai_client_prompt';
		if ( ! function_exists( $ai_prompt ) ) {
			return new WP_Error( 'no_ai_client', __( 'The WordPress AI Client is not available.', 'twt-aeo-ultimate' ) );
		}

		$model = TWTAEO_AI_Models::get( $provider );

		try {
			$builder = $ai_prompt( $prompt );
			if ( ! is_object( $builder ) ) {
				return new WP_Error( 'ai_client_shape', __( 'The WordPress AI Client returned an unexpected response.', 'twt-aeo-ultimate' ) );
			}
			// 🛑 The WP wrapper serves every fluent method through `__call`, mapping
			// snake_case onto the SDK's camelCase builder — so `is_callable()` is
			// true for ANY name. Calling a name the SDK lacks does NOT throw: the
			// wrapper records a `prompt_builder_error` internally and the eventual
			// generate_text() returns it ("Method using_max_output_tokens does not
			// exist on WordPress\AiClient\Builders\PromptBuilder"), aborting the
			// generation. try/catch cannot save a poisoned builder, so the ONLY
			// safe probe is method_exists() on the underlying SDK class itself.
			// (This SDK's real cap method is usingMaxTokens; usingMaxOutputTokens
			// never existed.)
			$sdk_builder = '\\WordPress\\AiClient\\Builders\\PromptBuilder';
			$sdk_has     = static function ( $camel ) use ( $sdk_builder ) {
				return class_exists( $sdk_builder ) && method_exists( $sdk_builder, $camel );
			};
			if ( $sdk_has( 'usingModelPreference' ) ) {
				$preferred = $builder->using_model_preference( $model );
				if ( is_object( $preferred ) ) {
					$builder = $preferred;
				}
			}
			if ( $sdk_has( 'usingMaxTokens' ) ) {
				$capped = $builder->using_max_tokens( 200 );
				if ( is_object( $capped ) ) {
					$builder = $capped;
				}
			}
			$text = $builder->generate_text();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ai_client_failed', $e->getMessage() );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}
		return is_string( $text ) ? $text : '';
	}

	// ── AI image generation (og:image) ──────────────────────────────────────────

	/**
	 * Style presets offered in the modal. Each maps to a fragment that steers
	 * the look of the generated image. Keep these short and concrete.
	 *
	 * @return array<string,array{label:string,prompt:string}>
	 */
	public static function image_styles() {
		return array(
			'clean' => array(
				'label'  => __( 'Clean & professional', 'twt-aeo-ultimate' ),
				'prompt' => 'a clean, professional, corporate-friendly design with balanced composition, a refined colour palette, and tasteful use of space',
			),
			'bold' => array(
				'label'  => __( 'Bold & vibrant', 'twt-aeo-ultimate' ),
				'prompt' => 'a bold, vibrant, high-contrast design with energetic colours and strong focal shapes that stand out in a busy social feed',
			),
			'minimal' => array(
				'label'  => __( 'Minimal & modern', 'twt-aeo-ultimate' ),
				'prompt' => 'a minimal, modern design with generous negative space, a restrained two or three colour palette, and simple geometric elements',
			),
			'photographic' => array(
				'label'  => __( 'Photographic / realistic', 'twt-aeo-ultimate' ),
				'prompt' => 'a realistic, editorial-quality photographic scene with natural lighting and depth, relevant to the topic',
			),
		);
	}

	/**
	 * Generate an Open Graph image for a post with OpenAI, add it to the media
	 * library, and return the new attachment. Requires an OpenAI key (the WP AI
	 * Client text fallback does not cover image generation).
	 *
	 * @param int    $post_id
	 * @param string $style     One of image_styles() keys.
	 * @param array  $modifiers { title?:bool, description?:bool, brand?:bool } — extra context to feed the prompt.
	 * @return array{attachment_id:int,url:string,alt:string}|WP_Error
	 */
	public static function generate_og_image( $post_id, $style = 'clean', $modifiers = array() ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'no_post', __( 'Post not found.', 'twt-aeo-ultimate' ) );
		}

		$api_key = TWTAEO_Key_Resolver::get( 'openai' );
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', __( 'AI image generation needs an OpenAI API key. Add one under TWT AEO → Settings.', 'twt-aeo-ultimate' ) );
		}

		$prompt = self::build_image_prompt( $post_id, $style, $modifiers );
		$image  = self::call_openai_image( $api_key, $prompt );
		if ( is_wp_error( $image ) ) {
			if ( class_exists( 'TWTAEO_Logger' ) ) {
				TWTAEO_Logger::warning( 'AI og:image failed: ' . $image->get_error_message(), array( 'post_id' => $post_id ) );
			}
			return $image;
		}

		// Resolve to raw bytes — gpt-image-1 returns base64; older models a URL.
		if ( isset( $image['b64'] ) ) {
			$binary = base64_decode( $image['b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a base64 image payload returned by the AI image API, not obfuscation.
			if ( false === $binary || '' === $binary ) {
				return new WP_Error( 'decode_failed', __( 'The generated image could not be decoded.', 'twt-aeo-ultimate' ) );
			}
		} else {
			$fetched = wp_remote_get( $image['url'], array( 'timeout' => 60 ) );
			if ( is_wp_error( $fetched ) ) {
				return $fetched;
			}
			$binary = wp_remote_retrieve_body( $fetched );
			if ( '' === $binary ) {
				return new WP_Error( 'fetch_failed', __( 'The generated image could not be downloaded.', 'twt-aeo-ultimate' ) );
			}
		}

		$title    = get_the_title( $post_id );
		$alt      = $title
			/* translators: %s: post title. */
			? sprintf( __( 'Social share image for %s', 'twt-aeo-ultimate' ), $title )
			: __( 'AI-generated social share image', 'twt-aeo-ultimate' );
		$filename = 'og-' . (int) $post_id . '-' . gmdate( 'YmdHis' ) . '.png';

		$attachment_id = self::sideload_image_binary( $binary, $filename, $post_id, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'alt'           => $alt,
		);
	}

	/**
	 * Generate an image from a fully-formed prompt and return the raw bytes.
	 * Shared entry point for callers that do their own sideloading (e.g. the
	 * bulk OG image job, which suppresses crop sizes around the import).
	 *
	 * @param string $prompt
	 * @return string|WP_Error PNG bytes.
	 */
	public static function request_image_bytes( $prompt ) {
		$api_key = TWTAEO_Key_Resolver::get( 'openai' );
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', __( 'AI image generation needs an OpenAI API key. Add one under TWT AEO → Settings.', 'twt-aeo-ultimate' ) );
		}

		$image = self::call_openai_image( $api_key, $prompt );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( isset( $image['b64'] ) ) {
			$binary = base64_decode( $image['b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a base64 image payload returned by the AI image API, not obfuscation.
			if ( false === $binary || '' === $binary ) {
				return new WP_Error( 'decode_failed', __( 'The generated image could not be decoded.', 'twt-aeo-ultimate' ) );
			}
			return $binary;
		}

		$fetched = wp_remote_get( $image['url'], array( 'timeout' => 60 ) );
		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}
		$binary = wp_remote_retrieve_body( $fetched );
		return '' !== $binary ? $binary : new WP_Error( 'fetch_failed', __( 'The generated image could not be downloaded.', 'twt-aeo-ultimate' ) );
	}

	/**
	 * Generate several images concurrently (curl_multi via WordPress's bundled
	 * Requests library), for bulk OG-image generation. Results are keyed the same
	 * as $prompts; each value is raw PNG bytes or a WP_Error. A WP_Error with code
	 * 'rate_limited' (HTTP 429) signals the caller to back off and retry, rather
	 * than treating the post as a permanent failure.
	 *
	 * Falls back to sequential single calls if the multi transport is unavailable.
	 *
	 * @param array<int|string,string> $prompts
	 * @return array<int|string,string|WP_Error>
	 */
	public static function request_images_bytes( array $prompts ) {
		if ( empty( $prompts ) ) {
			return array();
		}

		$api_key = TWTAEO_Key_Resolver::get( 'openai' );
		if ( '' === $api_key ) {
			$err = new WP_Error( 'no_key', __( 'AI image generation needs an OpenAI API key. Add one under TWT AEO → Settings.', 'twt-aeo-ultimate' ) );
			return array_fill_keys( array_keys( $prompts ), $err );
		}

		// Resolve the bundled Requests class across WordPress versions.
		$requests_class = class_exists( '\\WpOrg\\Requests\\Requests' )
			? '\\WpOrg\\Requests\\Requests'
			: ( class_exists( '\\Requests' ) ? '\\Requests' : '' );

		if ( '' === $requests_class || ! method_exists( $requests_class, 'request_multiple' ) ) {
			// No concurrent transport — degrade gracefully to one-at-a-time.
			$out = array();
			foreach ( $prompts as $key => $prompt ) {
				$out[ $key ] = self::request_image_bytes( $prompt );
			}
			return $out;
		}

		$requests = array();
		foreach ( $prompts as $key => $prompt ) {
			$requests[ $key ] = array(
				'url'     => 'https://api.openai.com/v1/images/generations',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'data'    => wp_json_encode( array(
					'model'  => self::OPENAI_IMAGE_MODEL,
					'prompt' => $prompt,
					'n'      => 1,
					'size'   => self::OPENAI_IMAGE_SIZE,
				) ),
				'type'    => 'POST',
			);
		}

		try {
			$responses = $requests_class::request_multiple( $requests, array( 'timeout' => 120 ) );
		} catch ( \Throwable $e ) {
			$err = new WP_Error( 'http_request_failed', $e->getMessage() );
			return array_fill_keys( array_keys( $prompts ), $err );
		}

		$out = array();
		foreach ( $prompts as $key => $prompt ) {
			$out[ $key ] = self::parse_image_response( $responses[ $key ] ?? null );
		}
		return $out;
	}

	/**
	 * Turn one Requests response (or thrown exception object) into image bytes
	 * or a WP_Error.
	 *
	 * @param mixed $resp Requests Response object, an exception, or null.
	 * @return string|WP_Error
	 */
	private static function parse_image_response( $resp ) {
		if ( ! is_object( $resp ) || ! isset( $resp->status_code ) ) {
			$msg = ( is_object( $resp ) && method_exists( $resp, 'getMessage' ) )
				? $resp->getMessage()
				: __( 'No response from the image API.', 'twt-aeo-ultimate' );
			return new WP_Error( 'http_request_failed', $msg );
		}

		$code = (int) $resp->status_code;
		$body = json_decode( (string) $resp->body, true );

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limited', $body['error']['message'] ?? __( 'OpenAI rate limit reached.', 'twt-aeo-ultimate' ) );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'openai_image_error', $body['error']['message'] ?? "OpenAI image API error (HTTP {$code})" );
		}

		if ( ! empty( $body['data'][0]['b64_json'] ) ) {
			$binary = base64_decode( $body['data'][0]['b64_json'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a base64 image payload returned by the AI image API, not obfuscation.
			return ( false === $binary || '' === $binary )
				? new WP_Error( 'decode_failed', __( 'The generated image could not be decoded.', 'twt-aeo-ultimate' ) )
				: $binary;
		}
		if ( ! empty( $body['data'][0]['url'] ) ) {
			$fetched = wp_remote_get( $body['data'][0]['url'], array( 'timeout' => 60 ) );
			if ( is_wp_error( $fetched ) ) {
				return $fetched;
			}
			$binary = wp_remote_retrieve_body( $fetched );
			return '' !== $binary ? $binary : new WP_Error( 'fetch_failed', __( 'The generated image could not be downloaded.', 'twt-aeo-ultimate' ) );
		}
		return new WP_Error( 'openai_image_empty', __( 'OpenAI returned no image.', 'twt-aeo-ultimate' ) );
	}

	/** Assemble the image prompt from the chosen style and context toggles. */
	private static function build_image_prompt( $post_id, $style, $modifiers ) {
		$styles       = self::image_styles();
		$style_prompt = isset( $styles[ $style ] ) ? $styles[ $style ]['prompt'] : $styles['clean']['prompt'];

		$title   = get_the_title( $post_id );
		$subject = $title ?: get_bloginfo( 'name' );

		$prompt  = 'Create a social share image (Open Graph / og:image) for a web page. ';
		$prompt .= 'It is a graphic banner in 1.91:1 landscape that appears as the link preview when the page is shared on Facebook, LinkedIn, or X. ';
		$prompt .= "Style: {$style_prompt}. ";
		$prompt .= "The page is about: \"{$subject}\". ";

		if ( ! empty( $modifiers['title'] ) && $title ) {
			$prompt .= "You may render the headline \"{$title}\" cleanly and legibly as part of the design. ";
		} else {
			$prompt .= 'Keep any text minimal; do not render large blocks of text. ';
		}

		if ( ! empty( $modifiers['description'] ) ) {
			$desc = self::best_description_for_image( $post_id );
			if ( '' !== $desc ) {
				$prompt .= "Convey this idea visually: \"{$desc}\". ";
			}
		}

		if ( ! empty( $modifiers['brand'] ) ) {
			$prompt .= 'Give it a cohesive, on-brand feel for "' . get_bloginfo( 'name' ) . '". ';
		}

		$prompt .= 'No watermarks, no invented logos, no placeholder/lorem-ipsum text, and no misspelled words.';

		return $prompt;
	}

	/** Best available descriptive text for an image prompt: OG, then meta. */
	private static function best_description_for_image( $post_id ) {
		if ( class_exists( 'TWTAEO_OG_Writer' ) ) {
			$og = TWTAEO_OG_Writer::get( $post_id );
			if ( ! empty( $og['og_description'] ) ) {
				return (string) $og['og_description'];
			}
		}
		return self::get_existing_description( $post_id );
	}

	/**
	 * Call the OpenAI Images API.
	 *
	 * @return array{b64:string}|array{url:string}|WP_Error
	 */
	private static function call_openai_image( $api_key, $prompt ) {
		$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
			'timeout' => 120, // Image generation is slow — well beyond the 30s text budget.
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'  => self::OPENAI_IMAGE_MODEL,
				'prompt' => $prompt,
				'n'      => 1,
				'size'   => self::OPENAI_IMAGE_SIZE,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'openai_image_error', $body['error']['message'] ?? "OpenAI image API error (HTTP $code)" );
		}

		if ( ! empty( $body['data'][0]['b64_json'] ) ) {
			return array( 'b64' => $body['data'][0]['b64_json'] );
		}
		if ( ! empty( $body['data'][0]['url'] ) ) {
			return array( 'url' => $body['data'][0]['url'] );
		}
		return new WP_Error( 'openai_image_empty', __( 'OpenAI returned no image.', 'twt-aeo-ultimate' ) );
	}

	/**
	 * Write raw image bytes to the uploads dir and register a media attachment.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	private static function sideload_image_binary( $binary, $filename, $post_id, $alt ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( sanitize_file_name( $filename ), null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'] );
		}

		$filetype   = wp_check_filetype( $upload['file'], null );
		$attachment = array(
			'post_mime_type' => $filetype['type'] ?: 'image/png',
			'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'attach_failed', __( 'Could not add the image to the media library.', 'twt-aeo-ultimate' ) );
		}

		$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );

		return (int) $attach_id;
	}

	// ── Storage ───────────────────────────────────────────────────────────────

	/**
	 * Save a description to the plugin meta key and the active SEO plugin field.
	 *
	 * @param int    $post_id
	 * @param string $description
	 */
	public static function save_description( $post_id, $description ) {
		$description = self::clean_description( $description );
		update_post_meta( $post_id, self::META_KEY, $description );

		// Write through to whichever SEO plugin is active so it controls output.
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $post_id, 'rank_math_description', $description );
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			update_post_meta( $post_id, '_seopress_titles_desc', $description );
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_aioseop_description', $description );
		}
	}

	/**
	 * Save a description into the Open Graph description without disturbing the
	 * post's other saved OG fields (title, type, image).
	 *
	 * @param int    $post_id
	 * @param string $description
	 * @return bool
	 */
	public static function save_og_description( $post_id, $description ) {
		if ( ! class_exists( 'TWTAEO_OG_Writer' ) ) {
			return false;
		}
		$og = TWTAEO_OG_Writer::get( $post_id );
		$og['og_description'] = self::clean_description( $description );
		return TWTAEO_OG_Writer::save( $post_id, $og );
	}

	/**
	 * The currently stored description from any known source.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function get_existing_description( $post_id ) {
		$keys = array(
			self::META_KEY,
			'_yoast_wpseo_metadesc',
			'rank_math_description',
			'_aioseop_description',
			'_seopress_titles_desc',
		);
		foreach ( $keys as $key ) {
			$val = get_post_meta( $post_id, $key, true );
			if ( ! empty( $val ) ) {
				return (string) $val;
			}
		}
		return '';
	}

	// ── Front-end output ─────────────────────────────────────────────────────

	/**
	 * Output a meta description tag from the plugin's stored value — but only
	 * when no SEO plugin is active (those emit their own from the written-through
	 * field), to avoid duplicate tags.
	 */
	public static function output_meta_tag() {
		if ( ! is_singular() || self::seo_plugin_active() ) {
			return;
		}

		// Switched off on the Schema Conflicts screen, or handed to AEO Ultimate for
		// WooCommerce.
		//
		// That plugin is deliberately *not* added to seo_plugin_active() above: that
		// list is the five general SEO plugins this one has always stepped aside for
		// automatically, and adding a sixth would mean deferring whenever it is
		// installed rather than when the merchant asked.
		if ( ! TWTAEO_Output_Control::should_write( 'meta_description' ) ) {
			return;
		}

		$desc = get_post_meta( get_the_ID(), self::META_KEY, true );
		if ( empty( $desc ) ) {
			return;
		}

		echo "\n" . '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
	}

	private static function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	// ── Bulk helper ───────────────────────────────────────────────────────────

	/**
	 * Published posts/pages that currently have no description anywhere.
	 *
	 * @param int $limit
	 * @return int[] Post IDs.
	 */
	/**
	 * Post types the bulk description tools cover. Must match what the Social
	 * Graph screen scans (TWTAEO_OG_Detector::post_types()) — on a store, most
	 * rows on that screen ARE products, and a bulk button that silently skips
	 * them reports "0 created" to a merchant staring at 900 missing rows.
	 *
	 * @return string[]
	 */
	private static function bulk_post_types() {
		$types = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	public static function get_posts_missing_description( $limit = 200 ) {
		$posts = get_posts( array(
			'post_type'      => self::bulk_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		return array_values( array_filter( $posts, function ( $id ) {
			return '' === self::get_existing_description( $id );
		} ) );
	}

	/**
	 * Published posts/pages that have no Open Graph description yet.
	 *
	 * @param int $limit
	 * @return int[] Post IDs.
	 */
	public static function get_posts_missing_og( $limit = 200 ) {
		$posts = get_posts( array(
			'post_type'      => self::bulk_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		return array_values( array_filter( $posts, function ( $id ) {
			$og = class_exists( 'TWTAEO_OG_Writer' ) ? TWTAEO_OG_Writer::get( $id ) : array();
			return empty( $og['og_description'] );
		} ) );
	}

	// ── Background bulk job ──────────────────────────────────────────────────────

	public static function get_job_state() {
		return wp_parse_args( get_option( self::JOB_OPTION, array() ), array(
			'status'      => 'idle', // idle | running | done | error
			'queue'       => array(),
			'total'       => 0,
			'done'        => 0,   // attempted
			'created'     => 0,   // saved successfully
			'errors'      => 0,   // skipped (content / network) failures
			'net_fails'   => 0,   // consecutive network failures
			'last_error'  => '',
			'provider'    => 'claude',
			'mode'        => 'meta', // meta | og
			'started_at'  => 0,
			'finished_at' => 0,
			'notice'      => false,
		) );
	}

	/** Job state without the (potentially large) queue, for AJAX/JS. */
	public static function job_payload() {
		$state = self::get_job_state();
		unset( $state['queue'] );
		$state['running'] = 'running' === $state['status'];
		return $state;
	}

	/**
	 * Queue every post missing a description and schedule background processing.
	 * No-op (returns current state) when a job is already running.
	 *
	 * @param string|null $provider
	 * @return array Job state payload.
	 */
	public static function start_job( $provider = null, $mode = 'meta' ) {
		$state = self::get_job_state();
		if ( 'running' === $state['status'] ) {
			return self::job_payload();
		}

		$provider = $provider ?: self::get_provider();
		if ( ! in_array( $provider, array( 'claude', 'openai', 'gemini' ), true ) ) {
			$provider = 'claude';
		}
		$mode = ( 'og' === $mode ) ? 'og' : 'meta';

		$ids = ( 'og' === $mode )
			? self::get_posts_missing_og( 2000 )
			: self::get_posts_missing_description( 2000 );
		if ( empty( $ids ) ) {
			return self::job_payload();
		}

		update_option( self::JOB_OPTION, array(
			'status'      => 'running',
			'queue'       => array_map( 'intval', $ids ),
			'total'       => count( $ids ),
			'done'        => 0,
			'created'     => 0,
			'errors'      => 0,
			'last_error'  => '',
			'provider'    => $provider,
			'mode'        => $mode,
			'started_at'  => time(),
			'finished_at' => 0,
			'notice'      => false,
		), false );

		if ( ! wp_next_scheduled( self::JOB_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::JOB_HOOK );
		}

		return self::job_payload();
	}

	/**
	 * Stop a running job: clear the queue, mark it stopped, and unschedule the
	 * pending cron event. Descriptions already created are kept. An in-flight
	 * batch finishes its current posts but will not reschedule (see the status
	 * re-check in run_job_batch()).
	 *
	 * @return array Job state payload.
	 */
	public static function stop_job() {
		$state = self::get_job_state();
		if ( 'running' !== $state['status'] ) {
			return self::job_payload();
		}

		$state['queue']       = array();
		$state['status']      = 'stopped';
		$state['finished_at'] = time();
		$state['notice']      = false; // Inline status already reflects the stop.
		update_option( self::JOB_OPTION, $state, false );

		$timestamp = wp_next_scheduled( self::JOB_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::JOB_HOOK );
		}
		delete_transient( self::JOB_LOCK );

		if ( class_exists( 'TWTAEO_Logger' ) ) {
			TWTAEO_Logger::info( 'AI description bulk job stopped by user after ' . (int) $state['created'] . ' created.' );
		}

		return self::job_payload();
	}

	/** Process one batch, then chain the next event until the queue drains. */
	public static function run_job_batch() {
		if ( get_transient( self::JOB_LOCK ) ) {
			return;
		}
		set_transient( self::JOB_LOCK, 1, 3 * MINUTE_IN_SECONDS );

		$state = self::get_job_state();
		if ( 'running' !== $state['status'] ) {
			delete_transient( self::JOB_LOCK );
			return;
		}

		$batch  = array_splice( $state['queue'], 0, self::JOB_BATCH );
		$is_og  = ( 'og' === ( $state['mode'] ?? 'meta' ) );
		$style  = $is_og ? 'social' : 'meta';

		foreach ( $batch as $post_id ) {
			$result = self::generate_for_post( $post_id, $state['provider'], $style );

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();

				// Content-level problems (incl. pages too thin to summarise): skip
				// the post and keep going.
				if ( in_array( $code, array( 'no_post', 'empty', 'empty_result', 'thin_content' ), true ) ) {
					$state['errors']++;
					$state['done']++;
					$state['net_fails'] = 0;
					continue;
				}

				// Transient network problems — e.g. a single slow API call timing
				// out. Skip this post rather than killing the whole run, but stop if
				// they pile up (a real outage, not one slow response).
				if ( 'http_request_failed' === $code ) {
					$state['errors']++;
					$state['done']++;
					$state['net_fails'] = ( $state['net_fails'] ?? 0 ) + 1;
					if ( $state['net_fails'] < self::JOB_MAX_NET_FAILS ) {
						continue;
					}
				}

				// Systemic problems (no credits, bad key, model) or too many network
				// failures in a row: stop the job and surface the message.
				$state['status']      = 'error';
				$state['last_error']  = $result->get_error_message();
				$state['finished_at'] = time();
				$state['notice']      = true;
				update_option( self::JOB_OPTION, $state, false );
				delete_transient( self::JOB_LOCK );
				if ( class_exists( 'TWTAEO_Logger' ) ) {
					TWTAEO_Logger::warning( 'AI description bulk job stopped: ' . $state['last_error'] );
				}
				return;
			}

			if ( $is_og ) {
				self::save_og_description( $post_id, $result );
			} else {
				self::save_description( $post_id, $result );
			}
			$state['created']++;
			$state['done']++;
			$state['net_fails'] = 0;
		}

		// The user may have stopped the job while this batch was generating.
		// Honour it: keep the descriptions we just saved, but do not reschedule
		// or overwrite the stopped state.
		$persisted = get_option( self::JOB_OPTION, array() );
		if ( isset( $persisted['status'] ) && 'running' !== $persisted['status'] ) {
			delete_transient( self::JOB_LOCK );
			return;
		}

		if ( empty( $state['queue'] ) ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
			$state['notice']      = true;
		} elseif ( ! wp_next_scheduled( self::JOB_HOOK ) ) {
			wp_schedule_single_event( time() + self::JOB_GAP, self::JOB_HOOK );
		}

		update_option( self::JOB_OPTION, $state, false );
		delete_transient( self::JOB_LOCK );
	}

	// ── Completion notice ───────────────────────────────────────────────────────

	public static function maybe_render_job_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = self::get_job_state();
		if ( empty( $state['notice'] ) || ! in_array( $state['status'], array( 'done', 'error' ), true ) ) {
			return;
		}

		$label = ( 'og' === ( $state['mode'] ?? 'meta' ) )
			? __( 'AI social descriptions', 'twt-aeo-ultimate' )
			: __( 'AI meta descriptions', 'twt-aeo-ultimate' );

		if ( 'error' === $state['status'] ) {
			$message = sprintf(
				/* translators: 1: feature label, 2: number created, 3: error message. */
				__( '%1$s stopped after creating %2$d — %3$s', 'twt-aeo-ultimate' ),
				$label,
				(int) $state['created'],
				$state['last_error']
			);
			$class = 'notice-warning';
		} else {
			$skips = (int) $state['errors'];
			if ( $skips > 0 ) {
				$message = sprintf(
					/* translators: 1: feature label, 2: number created, 3: number skipped. */
					__( '%1$s finished — %2$d created, %3$d skipped (not enough content to summarise).', 'twt-aeo-ultimate' ),
					$label,
					(int) $state['created'],
					$skips
				);
			} else {
				$message = sprintf(
					/* translators: 1: feature label, 2: number of descriptions created. */
					__( '%1$s finished — %2$d created.', 'twt-aeo-ultimate' ),
					$label,
					(int) $state['created']
				);
			}
			$class = ( (int) $state['created'] > 0 ) ? 'notice-success' : 'notice-warning';
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'twtaeo_ai_desc_dismiss', 1 ), 'twtaeo_ai_desc_dismiss' );

		printf(
			'<div class="notice %s is-dismissible"><p>%s &middot; <a href="%s">%s</a></p></div>',
			esc_attr( $class ),
			esc_html( $message ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'twt-aeo-ultimate' )
		);
	}

	public static function maybe_dismiss_job_notice() {
		if ( ! isset( $_GET['twtaeo_ai_desc_dismiss'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'twtaeo_ai_desc_dismiss' );

		$state           = self::get_job_state();
		$state['notice'] = false;
		update_option( self::JOB_OPTION, $state, false );

		wp_safe_redirect( remove_query_arg( array( 'twtaeo_ai_desc_dismiss', '_wpnonce' ) ) );
		exit;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** The description exactly as save_description() would store it (for previews). */
	public static function prepare_description( $text ) {
		return self::clean_description( $text );
	}

	private static function clean_description( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		$text = trim( $text, " \t\n\r\0\x0B\"'" );

		// Models asked for 140–155 characters regularly overshoot. Cutting at
		// the last whole word left descriptions ending mid-sentence ("…stands
		// out with"), which is what search and AI engines then showed. End at
		// the last full sentence when one ends late enough to still say
		// something; otherwise end at a word and mark the cut with "…".
		if ( mb_strlen( $text ) > self::MAX_LENGTH ) {
			$cut = rtrim( mb_substr( $text, 0, self::MAX_LENGTH ) );
			if ( preg_match( '/^(.{60,}[.!?])(?=\s|$)/us', $cut, $m ) ) {
				$text = $m[1];
			} else {
				$cut = rtrim( mb_substr( $text, 0, self::MAX_LENGTH - 1 ) );
				$cut = (string) preg_replace( '/\s+\S*$/u', '', $cut ); // Drop the partial last word.
				// Never end on a word that promises more ("…to get…", "…with…").
				$cut  = (string) preg_replace( '/(\s+(?:a|an|and|as|at|by|for|from|in|into|of|on|or|the|to|with|your|our|that|which))+$/iu', '', $cut );
				$text = rtrim( $cut, " ,;:-–—" ) . '…';
			}
		}

		return $text;
	}

	/**
	 * Whether a description reads as cut off: long enough to have hit a
	 * length limit, and not ending the way a sentence ends. Used to flag
	 * descriptions already saved by older versions, other plugins or hand.
	 *
	 * @param string $text
	 * @return bool
	 */
	public static function looks_cut_off( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( mb_strlen( $text ) < 100 ) {
			return false;
		}
		return ! preg_match( '/[.!?…"\'”’)\]]$/u', $text );
	}

	private static function truncate_words( $text, $words ) {
		$parts = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $parts ) <= $words ) {
			return $text;
		}
		return implode( ' ', array_slice( $parts, 0, $words ) );
	}
}
