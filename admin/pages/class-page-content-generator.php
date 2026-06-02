<?php
/**
 * Content Generator Page
 *
 * Renders the AI Content Generator admin page. Users select a page/post,
 * choose an industry and subtopic, and generate content from Claude,
 * OpenAI, and Perplexity — each prompted for its specific strength.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Page_Content_Generator {

	const NONCE = 'twtaeo_content_gen_nonce';

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'twt-aeo-ultimate' ) );
		}

		$settings    = get_option( 'twtaeo_settings', array() );
		$has_claude  = ! empty( trim( $settings['api_claude'] ?? '' ) );
		$has_openai  = ! empty( trim( $settings['api_openai'] ?? '' ) );
		$has_perp    = ! empty( trim( $settings['api_perplexity'] ?? '' ) );
		$any_key     = $has_claude || $has_openai || $has_perp;

		// Build post lists per type for the optgroup selector.
		$_qargs   = array( 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' );
		$hubs     = get_posts( array_merge( $_qargs, array( 'post_type' => 'twtaeo_hub' ) ) );
		$pages    = get_posts( array_merge( $_qargs, array( 'post_type' => 'page' ) ) );
		$reg_posts = get_posts( array_merge( $_qargs, array( 'post_type' => 'post' ) ) );

		// Industry config — passed to JS so the subtopic dropdown cascades client-side.
		$industry_config = TWTAEO_Industry_Config::get();
		$subtopics_map   = array();
		foreach ( $industry_config as $ind_slug => $industry ) {
			$subtopics_map[ $ind_slug ] = array();
			foreach ( $industry['subtopics'] as $sub_slug => $subtopic ) {
				$subtopics_map[ $ind_slug ][] = array(
					'slug'  => $sub_slug,
					'label' => $subtopic['label'],
				);
			}
		}

		?>
		<div class="wrap twt-aeo-wrap">

			<div class="twt-aeo-header">
				<div class="twt-aeo-header__inner">
					<h1 class="twt-aeo-header__title">
						<span class="twt-aeo-logo">AEO</span>
						<?php esc_html_e( 'Content Generator', 'twt-aeo-ultimate' ); ?>
					</h1>
					<p class="twt-aeo-header__sub">
						<?php esc_html_e( 'Generate page content using Claude (body copy), OpenAI (concise summary), and Perplexity (deep research) — each prompted for its strength.', 'twt-aeo-ultimate' ); ?>
					</p>
				</div>
			</div>

			<?php if ( ! $any_key ) : ?>
			<div class="twt-aeo-notice twt-aeo-notice--warn">
				<span class="dashicons dashicons-warning"></span>
				<?php
				printf(
					wp_kses(
						// translators: %s: URL to the AEO Settings admin page.
						__( 'No API keys configured. Add at least one key in <a href="%s">AEO → Settings</a> to use the Content Generator.', 'twt-aeo-ultimate' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( admin_url( 'admin.php?page=twt-aeo-settings' ) )
				);
				?>
			</div>
			<?php endif; ?>

			<!-- Config bar -->
			<div class="twt-aeo-cg-controls twt-aeo-card">
				<div class="twt-aeo-cg-controls__row">

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-post" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Page / Post / Hub', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-post" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Select or create —', 'twt-aeo-ultimate' ); ?></option>
							<optgroup label="<?php esc_attr_e( '+ Create new', 'twt-aeo-ultimate' ); ?>">
								<option value="__new:post"><?php esc_html_e( 'New Post', 'twt-aeo-ultimate' ); ?></option>
								<option value="__new:page"><?php esc_html_e( 'New Page', 'twt-aeo-ultimate' ); ?></option>
								<option value="__new:twtaeo_hub"><?php esc_html_e( 'New Hub (Pillar)', 'twt-aeo-ultimate' ); ?></option>
								<option value="__new:twtaeo_hub_sub"><?php esc_html_e( 'New Hub Sub-page', 'twt-aeo-ultimate' ); ?></option>
							</optgroup>
							<?php if ( $hubs ) : ?>
							<optgroup label="<?php esc_attr_e( 'Hubs', 'twt-aeo-ultimate' ); ?>">
								<?php foreach ( $hubs as $p ) : ?>
								<option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<?php endif; ?>
							<?php if ( $pages ) : ?>
							<optgroup label="<?php esc_attr_e( 'Pages', 'twt-aeo-ultimate' ); ?>">
								<?php foreach ( $pages as $p ) : ?>
								<option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<?php endif; ?>
							<?php if ( $reg_posts ) : ?>
							<optgroup label="<?php esc_attr_e( 'Posts', 'twt-aeo-ultimate' ); ?>">
								<?php foreach ( $reg_posts as $p ) : ?>
								<option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<?php endif; ?>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-industry" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Industry', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-industry" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Select industry —', 'twt-aeo-ultimate' ); ?></option>
							<?php foreach ( $industry_config as $slug => $industry ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>">
									<?php echo esc_html( $industry['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-subtopic" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Content Type', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-subtopic" class="twt-aeo-cg-select" disabled>
							<option value=""><?php esc_html_e( '— Select industry first —', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

					<div class="twt-aeo-cg-field twt-aeo-cg-field--action">
						<button id="twt-aeo-cg-generate" class="button button-primary twt-aeo-cg-btn" disabled>
							<?php esc_html_e( 'Generate Content', 'twt-aeo-ultimate' ); ?>
						</button>
					</div>

				</div>

				<!-- New-post fields (shown only when a "Create new" option is selected) -->
				<div id="twt-aeo-cg-new-fields" class="twt-aeo-cg-new-fields" style="display:none;">
					<div class="twt-aeo-cg-controls__row">
						<div class="twt-aeo-cg-field twt-aeo-cg-field--full">
							<label for="twt-aeo-cg-new-title" class="twt-aeo-cg-field__label">
								<?php esc_html_e( 'Title', 'twt-aeo-ultimate' ); ?>
							</label>
							<input type="text" id="twt-aeo-cg-new-title" class="twt-aeo-cg-input"
							       placeholder="<?php esc_attr_e( 'Enter a title for the new content…', 'twt-aeo-ultimate' ); ?>">
						</div>
					</div>
					<div id="twt-aeo-cg-hub-parent-row" class="twt-aeo-cg-controls__row" style="display:none;">
						<div class="twt-aeo-cg-field">
							<label for="twt-aeo-cg-hub-parent" class="twt-aeo-cg-field__label">
								<?php esc_html_e( 'Parent Hub', 'twt-aeo-ultimate' ); ?>
							</label>
							<select id="twt-aeo-cg-hub-parent" class="twt-aeo-cg-select">
								<option value=""><?php esc_html_e( '— Select parent hub —', 'twt-aeo-ultimate' ); ?></option>
								<?php foreach ( $hubs as $h ) : ?>
								<option value="<?php echo esc_attr( $h->ID ); ?>"><?php echo esc_html( $h->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<!-- Prompt options divider -->
				<div class="twt-aeo-cg-controls__divider">
					<span><?php esc_html_e( 'Prompt Options', 'twt-aeo-ultimate' ); ?></span>
				</div>

				<!-- Prompt options row 1: Intent / Tone / Audience / POV / Length -->
				<div class="twt-aeo-cg-controls__row">

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-intent" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Content Intent', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-intent" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Prompt default —', 'twt-aeo-ultimate' ); ?></option>
							<option value="commercial"><?php esc_html_e( 'Commercial / Product Guide', 'twt-aeo-ultimate' ); ?></option>
							<option value="informational"><?php esc_html_e( 'Informational / Deep-Dive Educational', 'twt-aeo-ultimate' ); ?></option>
							<option value="faq-local"><?php esc_html_e( 'FAQ / Local Search Answer', 'twt-aeo-ultimate' ); ?></option>
							<option value="news-trend"><?php esc_html_e( 'News / Trend Analysis (prioritise recency)', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-tone" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Tone', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-tone" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Any / prompt default —', 'twt-aeo-ultimate' ); ?></option>
							<option value="professional"><?php esc_html_e( 'Professional & authoritative', 'twt-aeo-ultimate' ); ?></option>
							<option value="friendly"><?php esc_html_e( 'Friendly & conversational', 'twt-aeo-ultimate' ); ?></option>
							<option value="empathetic"><?php esc_html_e( 'Empathetic & caring', 'twt-aeo-ultimate' ); ?></option>
							<option value="enthusiastic"><?php esc_html_e( 'Enthusiastic & energetic', 'twt-aeo-ultimate' ); ?></option>
							<option value="neutral"><?php esc_html_e( 'Neutral & informational', 'twt-aeo-ultimate' ); ?></option>
							<option value="educational"><?php esc_html_e( 'Educational & instructive', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-audience" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Target Audience', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-audience" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— General audience —', 'twt-aeo-ultimate' ); ?></option>
							<option value="homeowners"><?php esc_html_e( 'Homeowners / Consumers', 'twt-aeo-ultimate' ); ?></option>
							<option value="business-owners"><?php esc_html_e( 'Business owners / SMBs', 'twt-aeo-ultimate' ); ?></option>
							<option value="corporate"><?php esc_html_e( 'Corporate decision-makers', 'twt-aeo-ultimate' ); ?></option>
							<option value="healthcare-pros"><?php esc_html_e( 'Healthcare professionals', 'twt-aeo-ultimate' ); ?></option>
							<option value="technical"><?php esc_html_e( 'Technical / Expert audience', 'twt-aeo-ultimate' ); ?></option>
							<option value="parents"><?php esc_html_e( 'Parents & families', 'twt-aeo-ultimate' ); ?></option>
							<option value="seniors"><?php esc_html_e( 'Seniors & older adults', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-pov" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Point of View', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-pov" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Prompt default —', 'twt-aeo-ultimate' ); ?></option>
							<option value="first-person"><?php esc_html_e( 'First person ("We / Our")', 'twt-aeo-ultimate' ); ?></option>
							<option value="second-person"><?php esc_html_e( 'Second person ("You / Your")', 'twt-aeo-ultimate' ); ?></option>
							<option value="third-person"><?php esc_html_e( 'Third person ("They / The business")', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-length" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Length', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-length" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Prompt default —', 'twt-aeo-ultimate' ); ?></option>
							<option value="brief"><?php esc_html_e( 'Brief (~100–150 words)', 'twt-aeo-ultimate' ); ?></option>
							<option value="short"><?php esc_html_e( 'Short (~200–250 words)', 'twt-aeo-ultimate' ); ?></option>
							<option value="medium"><?php esc_html_e( 'Medium (~300–400 words)', 'twt-aeo-ultimate' ); ?></option>
							<option value="long"><?php esc_html_e( 'Long (~500–600 words)', 'twt-aeo-ultimate' ); ?></option>
							<option value="comprehensive"><?php esc_html_e( 'Comprehensive (~700–900 words)', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

				</div>

				<!-- Prompt options row 2: Format / Focus / CTA / Reading Level -->
				<div class="twt-aeo-cg-controls__row">

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-format" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Output Format', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-format" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Prompt default —', 'twt-aeo-ultimate' ); ?></option>
							<option value="prose"><?php esc_html_e( 'Flowing prose', 'twt-aeo-ultimate' ); ?></option>
							<option value="paragraphs-headers"><?php esc_html_e( 'Short paragraphs with subheadings', 'twt-aeo-ultimate' ); ?></option>
							<option value="bullets"><?php esc_html_e( 'Bullet points & lists', 'twt-aeo-ultimate' ); ?></option>
							<option value="faq"><?php esc_html_e( 'FAQ format (Q&A)', 'twt-aeo-ultimate' ); ?></option>
							<option value="narrative"><?php esc_html_e( 'Narrative / Story-led', 'twt-aeo-ultimate' ); ?></option>
							<option value="step-by-step"><?php esc_html_e( 'Step-by-step guide', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-focus" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Writing Focus', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-focus" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Balanced —', 'twt-aeo-ultimate' ); ?></option>
							<option value="benefits"><?php esc_html_e( 'Benefits-focused (customer outcomes)', 'twt-aeo-ultimate' ); ?></option>
							<option value="features"><?php esc_html_e( 'Features-focused (what we offer)', 'twt-aeo-ultimate' ); ?></option>
							<option value="trust"><?php esc_html_e( 'Trust & credibility (E-E-A-T)', 'twt-aeo-ultimate' ); ?></option>
							<option value="conversion"><?php esc_html_e( 'Urgency & conversion (CTA-heavy)', 'twt-aeo-ultimate' ); ?></option>
							<option value="educational"><?php esc_html_e( 'Educational & informational', 'twt-aeo-ultimate' ); ?></option>
							<option value="local-seo"><?php esc_html_e( 'Local SEO (location-specific)', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-cta" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Call to Action', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-cta" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Prompt default —', 'twt-aeo-ultimate' ); ?></option>
							<option value="strong"><?php esc_html_e( 'Include a strong CTA', 'twt-aeo-ultimate' ); ?></option>
							<option value="soft"><?php esc_html_e( 'Soft suggestion / light CTA', 'twt-aeo-ultimate' ); ?></option>
							<option value="none"><?php esc_html_e( 'No call to action', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

					<div class="twt-aeo-cg-field">
						<label for="twt-aeo-cg-reading-level" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Reading Level', 'twt-aeo-ultimate' ); ?>
						</label>
						<select id="twt-aeo-cg-reading-level" class="twt-aeo-cg-select">
							<option value=""><?php esc_html_e( '— Prompt default —', 'twt-aeo-ultimate' ); ?></option>
							<option value="simple"><?php esc_html_e( 'Simple (6th grade)', 'twt-aeo-ultimate' ); ?></option>
							<option value="standard"><?php esc_html_e( 'Standard (8th–10th grade)', 'twt-aeo-ultimate' ); ?></option>
							<option value="advanced"><?php esc_html_e( 'Advanced (college level)', 'twt-aeo-ultimate' ); ?></option>
							<option value="expert"><?php esc_html_e( 'Expert / Technical', 'twt-aeo-ultimate' ); ?></option>
						</select>
					</div>

				</div>

				<!-- AEO Semantic Elements -->
				<div class="twt-aeo-cg-controls__row twt-aeo-cg-controls__row--semantic">
					<div class="twt-aeo-cg-field twt-aeo-cg-field--full">
						<span class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'AEO Semantic Elements', 'twt-aeo-ultimate' ); ?>
							<span class="twt-aeo-cg-field__hint"><?php esc_html_e( 'Structural directives injected into the prompt — check all that apply', 'twt-aeo-ultimate' ); ?></span>
						</span>
						<div class="twt-aeo-cg-checks">
							<label class="twt-aeo-cg-check">
								<input type="checkbox" name="twt-aeo-cg-semantic[]" value="voice-search">
								<?php esc_html_e( 'Format answers for voice search (Speakable Schema ready)', 'twt-aeo-ultimate' ); ?>
							</label>
							<label class="twt-aeo-cg-check">
								<input type="checkbox" name="twt-aeo-cg-semantic[]" value="featured-snippet">
								<?php esc_html_e( 'Structure for featured snippet / position zero', 'twt-aeo-ultimate' ); ?>
							</label>
							<label class="twt-aeo-cg-check">
								<input type="checkbox" name="twt-aeo-cg-semantic[]" value="comparison-table">
								<?php esc_html_e( 'Include a 3-column structured comparison table', 'twt-aeo-ultimate' ); ?>
							</label>
							<label class="twt-aeo-cg-check">
								<input type="checkbox" name="twt-aeo-cg-semantic[]" value="misconceptions">
								<?php esc_html_e( 'Add a "Common Misconceptions" Q&A section', 'twt-aeo-ultimate' ); ?>
							</label>
							<label class="twt-aeo-cg-check">
								<input type="checkbox" name="twt-aeo-cg-semantic[]" value="local-trust">
								<?php esc_html_e( 'Include local trust signals (credentials, service area, reviews)', 'twt-aeo-ultimate' ); ?>
							</label>
						</div>
					</div>
				</div>

				<!-- Unique value proposition -->
				<div class="twt-aeo-cg-controls__row">
					<div class="twt-aeo-cg-field twt-aeo-cg-field--full">
						<label for="twt-aeo-cg-notes" class="twt-aeo-cg-field__label">
							<?php esc_html_e( 'Your Unique Value Proposition', 'twt-aeo-ultimate' ); ?>
							<span class="twt-aeo-cg-field__hint"><?php esc_html_e( 'The AI will weave this competitive advantage naturally throughout the content', 'twt-aeo-ultimate' ); ?></span>
						</label>
						<textarea id="twt-aeo-cg-notes" class="twt-aeo-cg-notes" rows="2" placeholder="<?php esc_attr_e( 'e.g. Local plumbing agency in Tampa — 24/7 emergency response, never charge extra for weekends, family-owned for 20+ years, licensed & insured.', 'twt-aeo-ultimate' ); ?>"></textarea>
					</div>
				</div>

				<div id="twt-aeo-cg-title-preview" class="twt-aeo-cg-title-preview" style="display:none;">
					<span class="twt-aeo-cg-title-preview__label"><?php esc_html_e( 'Generating for:', 'twt-aeo-ultimate' ); ?></span>
					<strong id="twt-aeo-cg-title-text"></strong>
				</div>
			</div>

			<!-- Results panels -->
			<div id="twt-aeo-cg-results" class="twt-aeo-cg-results" style="display:none;">

				<!-- Claude panel -->
				<div class="twt-aeo-cg-panel" id="twt-aeo-cg-panel-claude">
					<div class="twt-aeo-cg-panel__head">
						<div class="twt-aeo-cg-panel__provider">
							<span class="twt-aeo-cg-panel__dot twt-aeo-cg-panel__dot--claude"></span>
							<?php esc_html_e( 'Claude', 'twt-aeo-ultimate' ); ?>
							<span class="twt-aeo-cg-panel__strength"><?php esc_html_e( 'Body Copy', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<span id="twt-aeo-cg-label-claude" class="twt-aeo-cg-panel__task"></span>
					</div>
					<div class="twt-aeo-cg-panel__body">
						<div class="twt-aeo-cg-panel__spinner" style="display:none;">
							<span class="twt-aeo-cg-spinner"></span>
							<?php esc_html_e( 'Writing…', 'twt-aeo-ultimate' ); ?>
						</div>
						<div class="twt-aeo-cg-panel__error" style="display:none;"></div>
						<textarea id="twt-aeo-cg-content-claude" class="twt-aeo-cg-panel__textarea" rows="14" readonly style="display:none;"></textarea>
					</div>
					<div class="twt-aeo-cg-panel__foot" style="display:none;">
						<button class="button twt-aeo-cg-copy" data-target="twt-aeo-cg-content-claude">
							<?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?>
						</button>
					</div>
				</div>

				<!-- OpenAI panel -->
				<div class="twt-aeo-cg-panel" id="twt-aeo-cg-panel-openai">
					<div class="twt-aeo-cg-panel__head">
						<div class="twt-aeo-cg-panel__provider">
							<span class="twt-aeo-cg-panel__dot twt-aeo-cg-panel__dot--openai"></span>
							<?php esc_html_e( 'ChatGPT', 'twt-aeo-ultimate' ); ?>
							<span class="twt-aeo-cg-panel__strength"><?php esc_html_e( 'Concise Copy', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<span id="twt-aeo-cg-label-openai" class="twt-aeo-cg-panel__task"></span>
					</div>
					<div class="twt-aeo-cg-panel__body">
						<div class="twt-aeo-cg-panel__spinner" style="display:none;">
							<span class="twt-aeo-cg-spinner"></span>
							<?php esc_html_e( 'Writing…', 'twt-aeo-ultimate' ); ?>
						</div>
						<div class="twt-aeo-cg-panel__error" style="display:none;"></div>
						<textarea id="twt-aeo-cg-content-openai" class="twt-aeo-cg-panel__textarea" rows="14" readonly style="display:none;"></textarea>
					</div>
					<div class="twt-aeo-cg-panel__foot" style="display:none;">
						<button class="button twt-aeo-cg-copy" data-target="twt-aeo-cg-content-openai">
							<?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?>
						</button>
					</div>
				</div>

				<!-- Perplexity panel -->
				<div class="twt-aeo-cg-panel" id="twt-aeo-cg-panel-perplexity">
					<div class="twt-aeo-cg-panel__head">
						<div class="twt-aeo-cg-panel__provider">
							<span class="twt-aeo-cg-panel__dot twt-aeo-cg-panel__dot--perplexity"></span>
							<?php esc_html_e( 'Perplexity', 'twt-aeo-ultimate' ); ?>
							<span class="twt-aeo-cg-panel__strength"><?php esc_html_e( 'Deep Research', 'twt-aeo-ultimate' ); ?></span>
						</div>
						<span id="twt-aeo-cg-label-perplexity" class="twt-aeo-cg-panel__task"></span>
					</div>
					<div class="twt-aeo-cg-panel__body">
						<div class="twt-aeo-cg-panel__spinner" style="display:none;">
							<span class="twt-aeo-cg-spinner"></span>
							<?php esc_html_e( 'Researching…', 'twt-aeo-ultimate' ); ?>
						</div>
						<div class="twt-aeo-cg-panel__error" style="display:none;"></div>
						<textarea id="twt-aeo-cg-content-perplexity" class="twt-aeo-cg-panel__textarea" rows="14" readonly style="display:none;"></textarea>
					</div>
					<div class="twt-aeo-cg-panel__foot" style="display:none;">
						<button class="button twt-aeo-cg-copy" data-target="twt-aeo-cg-content-perplexity">
							<?php esc_html_e( 'Copy', 'twt-aeo-ultimate' ); ?>
						</button>
					</div>
				</div>

			</div><!-- /#twt-aeo-cg-results -->

		</div><!-- /.wrap -->

		<?php
		ob_start();
		?>
		window.twtAeoCgSubtopics = <?php echo wp_json_encode( $subtopics_map ); ?>;
		window.twtAeoCgNonce     = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
		window.twtAeoCgAjaxUrl   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		<?php
		$js = ob_get_clean();
		wp_add_inline_script( 'twt-aeo-admin', $js );
	}
}
