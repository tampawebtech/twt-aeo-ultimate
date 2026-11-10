<?php
/**
 * Publish Ready: the fields a page still needs before it goes out.
 *
 * One registry answering "what is missing on this page", so the editor meta
 * box, the block-editor sidebar and anything added later all read the same
 * state. The fields themselves are owned elsewhere and stay there: this class
 * reads them, names what is absent, and says which existing generator fills
 * it. It writes nothing.
 *
 * The case it exists for is a draft written somewhere else and pasted in. The
 * body arrives finished and everything around it does not: no SEO title, no
 * meta description, no featured image, no share image, and no alt text on the
 * images that came with it. Each of those already has a screen in this plugin,
 * and an author finishing a page will not visit three of them.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Publish_Ready {

	/** Nonce action for every write this panel makes. */
	const NONCE = 'twtaeo_publish_ready';

	/** This plugin's own SEO title. Published only when no SEO plugin is active. */
	const META_TITLE = '_twtaeo_seo_title';

	/**
	 * Per-user: this person has said they do not need asking before a single
	 * billed call.
	 *
	 * Deliberately user meta and not a site option. The confirm exists so that
	 * nobody spends the owner's API budget by accident, and an administrator
	 * who is deep in a page and knows exactly what a click costs is not the
	 * same person as a client's marketing team using the same site. One of
	 * them turning the guard off must not turn it off for the other.
	 *
	 * Cleaned up on uninstall by the twtaeo_ user-meta sweep.
	 */
	const META_SKIP_CONFIRM = 'twtaeo_pr_skip_cost_confirm';

	/** A real value is stored. */
	const FILLED = 'filled';

	/** Nothing is stored and nothing is inherited. */
	const MISSING = 'missing';

	/**
	 * An SEO plugin holds a pattern rather than words ("%%title%% %%sep%%
	 * %%sitename%%"). Not a filled field, but not an empty one either: the
	 * plugin is generating this and the panel must not offer to overwrite it.
	 */
	const TEMPLATED = 'templated';

	/** Nothing is stored for this page, but a value is inherited from elsewhere. */
	const INHERITED = 'inherited';

	/**
	 * An SEO plugin is active whose storage this plugin cannot read in full,
	 * so an empty read proves nothing. Never shown as missing, and never
	 * generated over unless the author chooses to.
	 */
	const UNKNOWN = 'unknown';

	/**
	 * Template markers: Yoast and SEOPress %%var%%, Rank Math %var%, AIOSEO
	 * #var, and {var} as used by several themes.
	 */
	const TEMPLATE_PATTERN = '/%%|%[a-z_]+%|\{[a-z_]+\}|#[a-z_]+/i';

	/** Lengths the panel measures against. Guidance, never enforced. */
	const TITLE_TARGET = 60;
	const DESC_TARGET  = 160;

	private function __construct() {}

	/** Bind the one front-end hook this class owns. */
	public static function register_hooks() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ) );
	}

	// ── The title tag ─────────────────────────────────────────────────────────

	/**
	 * Publish this plugin's SEO title, and only where nothing else owns the
	 * title tag.
	 *
	 * Same stand-down as TWTAEO_AI_Description::output_meta_tag() does for the
	 * description: an SEO plugin owns the title the moment it is active, and
	 * two plugins filtering one tag is how a site ends up with a title nobody
	 * can account for. A value already set by something else is left alone,
	 * since this filter runs for every theme that asks for a document title.
	 *
	 * @param string $title
	 * @return string
	 */
	public static function filter_document_title( $title ) {
		if ( '' !== trim( (string) $title ) || ! is_singular() ) {
			return $title;
		}
		if ( ! class_exists( 'TWTAEO_AI_Description' ) || TWTAEO_AI_Description::seo_plugin_active() ) {
			return $title;
		}

		$ours = trim( (string) get_post_meta( get_the_ID(), self::META_TITLE, true ) );
		if ( '' === $ours || self::is_templated( $ours ) ) {
			return $title;
		}

		// Escaped on the way out as well as sanitized on the way in. Core does
		// not escape what this filter returns before printing it inside
		// <title>, and save_title() is not the only way a row could ever get
		// into this meta key: an import, a migration or a direct database edit
		// would all bypass it. A plain title is unchanged by this.
		return esc_html( $ours );
	}

	/**
	 * Save an SEO title to this plugin's key and to each active SEO plugin's
	 * own field, the way save_description() already does for descriptions, so
	 * the author finds the generated title in the box they are used to rather
	 * than only in ours.
	 *
	 * Storage only: this will overwrite a template if one is there. Deciding
	 * whether that is wanted belongs to the caller, which can see the field's
	 * state and ask. A plugin whose storage this one cannot read in full
	 * (see TWTAEO_SEO_Compatibility::META_UNVERIFIED) is still written to,
	 * matching what the description has always done, but the write may not be
	 * the field that plugin actually reads.
	 *
	 * @param int    $post_id
	 * @param string $title
	 * @return string The title as stored.
	 */
	public static function save_title( $post_id, $title ) {
		$title = sanitize_text_field( wp_strip_all_tags( (string) $title ) );
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );

		update_post_meta( $post_id, self::META_TITLE, $title );

		if ( class_exists( 'TWTAEO_SEO_Compatibility' ) ) {
			foreach ( TWTAEO_SEO_Compatibility::active_meta_fields() as $plugin ) {
				update_post_meta( $post_id, $plugin['title'], $title );
			}
		}

		return $title;
	}

	// ── Spending confirmation ─────────────────────────────────────────────────

	/**
	 * Whether this person still wants to be asked before a billed call.
	 *
	 * Defaults to yes. Nobody is opted out of the guard without choosing it.
	 *
	 * @param int $user_id Defaults to the current user.
	 * @return bool
	 */
	public static function asks_before_spending( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return true;
		}

		return '1' !== (string) get_user_meta( $user_id, self::META_SKIP_CONFIRM, true );
	}

	/**
	 * Remember whether to keep asking. Stored only when the answer is "stop
	 * asking", so the default costs no row.
	 *
	 * @param bool $ask
	 * @param int  $user_id Defaults to the current user.
	 * @return bool The setting as stored.
	 */
	public static function set_asks_before_spending( $ask, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return true;
		}

		if ( $ask ) {
			delete_user_meta( $user_id, self::META_SKIP_CONFIRM );
		} else {
			update_user_meta( $user_id, self::META_SKIP_CONFIRM, '1' );
		}

		return (bool) $ask;
	}

	/**
	 * Whether this post type gets a panel. The same set the rest of the plugin
	 * scans, so a type with no AEO Status box does not get this one either.
	 *
	 * @param int|WP_Post $post
	 * @return bool
	 */
	public static function supports( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		$types = class_exists( 'TWTAEO_Scan_Store' )
			? TWTAEO_Scan_Store::get_scannable_post_types()
			: array( 'post', 'page' );

		return in_array( $post->post_type, $types, true );
	}

	/**
	 * Every field for one page, in the order the panel shows them.
	 *
	 * @param int|WP_Post $post
	 * @param string|null $content The editor's current body, for a draft that
	 *                             is not saved yet. Defaults to the saved post.
	 * @return array[] Each: { id, label, state, value, source, detail,
	 *                 can_generate, generate_cost, action }
	 */
	public static function fields( $post, $content = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}
		if ( null === $content ) {
			$content = (string) $post->post_content;
		}

		$fields = array(
			self::field_title( $post ),
			self::field_description( $post ),
			self::field_share_image( $post, $content ),
			self::field_featured_image( $post, $content ),
			self::field_alt_text( $post, $content ),
		);

		// A field whose surface is switched off is dropped rather than shown:
		// offering a write that will never be published is worse than silence.
		return array_values( array_filter( $fields ) );
	}

	/**
	 * How much of the page is still outstanding, for a panel heading.
	 *
	 * UNKNOWN is deliberately not counted. The plugin being unable to read a
	 * value is not the same as the author not having written one, and counting
	 * it would nag about work that may already be done.
	 *
	 * @param array[] $fields
	 * @return array { total: int, outstanding: int }
	 */
	public static function summary( array $fields ) {
		$outstanding = 0;
		foreach ( $fields as $field ) {
			if ( in_array( $field['state'], array( self::MISSING, self::INHERITED ), true ) ) {
				$outstanding++;
			}
		}

		return array(
			'total'       => count( $fields ),
			'outstanding' => $outstanding,
		);
	}

	/**
	 * Whether a stored value is a pattern rather than words.
	 *
	 * @param string $value
	 * @return bool
	 */
	public static function is_templated( $value ) {
		$value = trim( (string) $value );

		return '' !== $value && (bool) preg_match( self::TEMPLATE_PATTERN, $value );
	}

	// ── Fields ────────────────────────────────────────────────────────────────

	/**
	 * SEO title. Never the post title: a page can have a perfectly good H1 and
	 * no search title at all, which is exactly what a pasted draft looks like.
	 *
	 * @param WP_Post $post
	 * @return array
	 */
	private static function field_title( WP_Post $post ) {
		$stored = self::stored_value( $post->ID, 'title' );

		return array(
			'id'            => 'seo_title',
			'label'         => __( 'SEO title', 'twt-aeo-ultimate' ),
			'state'         => $stored['state'],
			'value'         => $stored['value'],
			'source'        => $stored['source'],
			'detail'        => self::length_detail( $stored, self::TITLE_TARGET ),
			'can_generate'  => self::can_generate(),
			'generate_cost' => 1,
			'action'        => 'title',
		);
	}

	/**
	 * Meta description. Dropped when the description surface is delegated or
	 * switched off, since nothing written here would reach a page.
	 *
	 * @param WP_Post $post
	 * @return array|null
	 */
	private static function field_description( WP_Post $post ) {
		if ( ! self::surface_on( 'meta_description' ) ) {
			return null;
		}

		$stored = self::stored_value( $post->ID, 'desc' );

		// The excerpt is a real fallback for the description on many themes and
		// is what this plugin's own Markdown output prefers, so a page with an
		// excerpt and no description is not empty-handed.
		if ( self::MISSING === $stored['state'] && has_excerpt( $post ) ) {
			$stored['state']  = self::INHERITED;
			$stored['value']  = wp_strip_all_tags( get_the_excerpt( $post ) );
			$stored['source'] = __( 'the excerpt', 'twt-aeo-ultimate' );
		}

		return array(
			'id'            => 'meta_description',
			'label'         => __( 'Meta description', 'twt-aeo-ultimate' ),
			'state'         => $stored['state'],
			'value'         => $stored['value'],
			'source'        => $stored['source'],
			'detail'        => self::length_detail( $stored, self::DESC_TARGET ),
			'can_generate'  => self::can_generate(),
			'generate_cost' => 1,
			'action'        => 'description',
		);
	}

	/**
	 * The image this page will actually share, and which step of the chain
	 * produced it. Naming the step is the point: an author who cannot see where
	 * the share image comes from cannot tell whether changing the featured
	 * image will change what a social scraper shows.
	 *
	 * @param WP_Post $post
	 * @param string  $content
	 * @return array|null
	 */
	private static function field_share_image( WP_Post $post, $content ) {
		if ( ! class_exists( 'TWTAEO_OG_Writer' ) || ! self::module_on( 'social-graph' ) ) {
			return null;
		}

		$image  = TWTAEO_OG_Writer::effective_image( $post->ID, $content );
		$labels = array(
			'og'       => __( 'set for this page', 'twt-aeo-ultimate' ),
			'featured' => __( 'from the featured image', 'twt-aeo-ultimate' ),
			'content'  => __( 'from the first image in the content', 'twt-aeo-ultimate' ),
			'gallery'  => __( 'from the product gallery', 'twt-aeo-ultimate' ),
		);

		if ( ! $image ) {
			$state  = self::MISSING;
			$source = '';
		} elseif ( 'og' === $image['source'] ) {
			$state  = self::FILLED;
			$source = $labels['og'];
		} else {
			// Inherited, not filled: it works today and changes silently the
			// day the featured image or the first content image changes.
			$state  = self::INHERITED;
			$source = isset( $labels[ $image['source'] ] ) ? $labels[ $image['source'] ] : '';
		}

		return array(
			'id'            => 'share_image',
			'label'         => __( 'Share image', 'twt-aeo-ultimate' ),
			'state'         => $state,
			'value'         => $image ? $image['url'] : '',
			'source'        => $source,
			'detail'        => '',
			// Drawing an image is not the same capability as writing a
			// sentence: generate_og_image() needs OpenAI specifically, so a
			// site with only a Claude or Gemini key must not be offered a
			// button that can only fail.
			'can_generate'  => self::can_generate_image(),
			'generate_cost' => 1,
			'action'        => 'share_image',
		);
	}

	/**
	 * Featured image. The theme, the share-image chain and the Article schema
	 * all want one, and a pasted draft almost never has it.
	 *
	 * @param WP_Post $post
	 * @param string  $content
	 * @return array
	 */
	private static function field_featured_image( WP_Post $post, $content ) {
		$thumb_id = (int) get_post_thumbnail_id( $post->ID );
		$state    = $thumb_id ? self::FILLED : self::MISSING;
		$detail   = '';

		if ( ! $thumb_id && class_exists( 'TWTAEO_OG_Writer' ) ) {
			// Offer the obvious candidate rather than sending the author to the
			// media library for a picture already in the post.
			$candidate = TWTAEO_OG_Writer::first_content_image( $post->ID, $content );
			if ( $candidate && $candidate['id'] ) {
				$detail = __( 'the first image in the content can be used', 'twt-aeo-ultimate' );
			}
		}

		return array(
			'id'            => 'featured_image',
			'label'         => __( 'Featured image', 'twt-aeo-ultimate' ),
			'state'         => $state,
			'value'         => $thumb_id ? (string) wp_get_attachment_url( $thumb_id ) : '',
			'source'        => '',
			'detail'        => $detail,
			'can_generate'  => false,
			'generate_cost' => 0,
			'action'        => 'featured_image',
		);
	}

	/**
	 * Images on the page with no alt text. Counts the body as the editor has
	 * it, so the number moves while the author pastes rather than after a save.
	 *
	 * @param WP_Post $post
	 * @param string  $content
	 * @return array|null
	 */
	private static function field_alt_text( WP_Post $post, $content ) {
		if ( ! class_exists( 'TWTAEO_Image_Optimizer' ) ) {
			return null;
		}

		$probe               = clone $post;
		$probe->post_content = (string) $content;

		$images = TWTAEO_Image_Optimizer::collect( $probe );
		if ( ! $images ) {
			// No images is not a gap. Reporting "0 missing" invites a click that
			// does nothing.
			return null;
		}

		$missing = 0;
		foreach ( $images as $image ) {
			if ( '' === trim( (string) $image['alt'] ) ) {
				$missing++;
			}
		}
		$total = count( $images );

		if ( $missing && 1 === $total ) {
			// "1 of 1 images" reads badly, and the two-number form below cannot
			// be made to agree in English: the noun follows the total while the
			// verb follows the count, which is two plurals in one string and
			// more than _n() can express. Phrasing around it keeps every
			// translation grammatical.
			$detail = __( 'The image on this page has no alt text', 'twt-aeo-ultimate' );
		} elseif ( $missing ) {
			$detail = sprintf(
				/* translators: 1: number of images with no alt text, 2: total images on the page, always 2 or more here. */
				__( 'No alt text on %1$d of %2$d images', 'twt-aeo-ultimate' ),
				$missing,
				$total
			);
		} else {
			$detail = sprintf(
				/* translators: %d: number of images on the page. */
				_n( '%d image, described', '%d images, all described', $total, 'twt-aeo-ultimate' ),
				$total
			);
		}

		return array(
			'id'            => 'image_alt',
			'label'         => __( 'Image alt text', 'twt-aeo-ultimate' ),
			'state'         => $missing ? self::MISSING : self::FILLED,
			'value'         => '',
			'source'        => '',
			'detail'        => $detail,
			'can_generate'  => $missing > 0 && self::can_generate(),
			'generate_cost' => $missing,
			'action'        => 'image_alt',
		);
	}

	// ── Reading stored values ─────────────────────────────────────────────────

	/**
	 * The stored title or description for a page, and where it came from.
	 *
	 * This plugin's own key is read first so an AEO Ultimate value wins, then
	 * each active SEO plugin's. A plugin that is installed but not active holds
	 * nothing worth reporting, so the keys come from the active set.
	 *
	 * @param int    $post_id
	 * @param string $field 'title' or 'desc'.
	 * @return array { value: string, state: string, source: string }
	 */
	private static function stored_value( $post_id, $field ) {
		$own_key = ( 'title' === $field ) ? self::META_TITLE : '_twtaeo_meta_description';
		$own     = trim( (string) get_post_meta( $post_id, $own_key, true ) );

		if ( '' !== $own ) {
			return array(
				'value'  => $own,
				'state'  => self::is_templated( $own ) ? self::TEMPLATED : self::FILLED,
				'source' => __( 'AEO Ultimate', 'twt-aeo-ultimate' ),
			);
		}

		$active = class_exists( 'TWTAEO_SEO_Compatibility' )
			? TWTAEO_SEO_Compatibility::active_meta_fields()
			: array();

		$unverified = '';

		foreach ( $active as $plugin ) {
			$value = trim( (string) get_post_meta( $post_id, $plugin[ $field ], true ) );

			if ( '' !== $value ) {
				return array(
					'value'  => $value,
					'state'  => self::is_templated( $value ) ? self::TEMPLATED : self::FILLED,
					'source' => $plugin['name'],
				);
			}
			if ( $plugin['unverified'] && '' === $unverified ) {
				$unverified = $plugin['name'];
			}
		}

		if ( '' !== $unverified ) {
			return array(
				'value'  => '',
				'state'  => self::UNKNOWN,
				'source' => $unverified,
			);
		}

		return array(
			'value'  => '',
			'state'  => self::MISSING,
			'source' => '',
		);
	}

	/**
	 * Character count against a target, for a filled value only. A length note
	 * on an empty field is noise.
	 *
	 * @param array $stored
	 * @param int   $target
	 * @return string
	 */
	private static function length_detail( array $stored, $target ) {
		if ( self::FILLED !== $stored['state'] || '' === $stored['value'] ) {
			return '';
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $stored['value'] ) : strlen( $stored['value'] );

		return sprintf(
			/* translators: 1: character count of the current value, 2: suggested character count. */
			__( '%1$d characters, around %2$d reads well', 'twt-aeo-ultimate' ),
			$length,
			$target
		);
	}

	// ── Environment ───────────────────────────────────────────────────────────

	/**
	 * Whether anything could answer a generate request for text: a stored key
	 * for one of the four providers, or the WordPress AI Client standing in.
	 */
	private static function can_generate() {
		return class_exists( 'TWTAEO_AI_Description' )
			&& TWTAEO_AI_Description::can_generate();
	}

	/**
	 * Whether an image can be generated, which is a narrower question.
	 *
	 * TWTAEO_AI_Description::generate_og_image() calls OpenAI's image API and
	 * nothing else, so a key for any other provider does not help here.
	 */
	private static function can_generate_image() {
		return class_exists( 'TWTAEO_Key_Resolver' )
			&& '' !== TWTAEO_Key_Resolver::get( 'openai' );
	}

	/**
	 * Whether this plugin still publishes a surface, asked the way every writer
	 * asks it.
	 *
	 * @param string $surface
	 * @return bool
	 */
	private static function surface_on( $surface ) {
		if ( ! class_exists( 'TWTAEO_Output_Control' ) ) {
			return true;
		}

		return (bool) TWTAEO_Output_Control::should_write( $surface );
	}

	/**
	 * Whether a module is switched on.
	 *
	 * @param string $slug
	 * @return bool
	 */
	private static function module_on( $slug ) {
		$modules = isset( $GLOBALS['twtaeo_plugin'] ) ? $GLOBALS['twtaeo_plugin']->modules : null;

		return $modules ? (bool) $modules->is_active( $slug ) : true;
	}
}
