<?php
/**
 * REST endpoints behind the Publish Ready panel.
 *
 * One controller for both surfaces. The classic meta box and the block-editor
 * sidebar show the same fields, so they read the same state and send the same
 * writes rather than each growing its own handlers that then disagree.
 *
 * Three actions, all POST: state, generate, save. State is a POST and not a GET
 * because the block editor sends the body the author is looking at, which is
 * not in the database yet and does not belong in a query string.
 *
 * Every write here is something the author clicked. Nothing on this controller
 * runs on its own, and none of it is reachable without the plugin capability
 * and edit rights on the post.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Publish_Ready_REST {

	const NAMESPACE_V1 = 'aeo/v1';
	const ROUTE        = 'publish-ready';

	private function __construct() {}

	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$post_arg = array(
			'post_id' => array(
				'required'          => true,
				'type'             => 'integer',
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . self::ROUTE . '/state',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_state' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => $post_arg + array(
					'content' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . self::ROUTE . '/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_generate' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => $post_arg + array(
					'field'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					// Sent by the inline confirm's "don't ask again" box, so
					// the choice is remembered in the same call that acts on
					// it rather than needing a second round trip.
					'remember' => array(
						'required' => false,
						'type'     => 'boolean',
					),
					// The body the caller believes is current. Checked before
					// anything writes into post_content.
					'content'  => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . self::ROUTE . '/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_save' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => $post_arg + array(
					'field' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'value' => array(
						'required' => false,
					),
					// The body the caller believes is current. Checked before
					// anything writes into post_content.
					'content'  => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * The plugin capability plus edit rights on this particular post. Both:
	 * Access & Roles can grant the plugin capability to someone who still has
	 * no business editing every post.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public static function can_edit( $request ) {
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'twtaeo_forbidden', __( 'You cannot manage AEO settings.', 'twt-aeo-ultimate' ), array( 'status' => 403 ) );
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'twtaeo_forbidden', __( 'You cannot edit this page.', 'twt-aeo-ultimate' ), array( 'status' => 403 ) );
		}

		return true;
	}

	// ── Actions ───────────────────────────────────────────────────────────────

	/**
	 * Current state of every field, read against the body the editor holds.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_state( $request ) {
		$post = get_post( absint( $request->get_param( 'post_id' ) ) );
		if ( ! $post ) {
			return new WP_Error( 'twtaeo_no_post', __( 'Page not found.', 'twt-aeo-ultimate' ), array( 'status' => 404 ) );
		}

		return self::state_response( $post, self::content_param( $request, $post ) );
	}

	/**
	 * Generate one field with AI and store it.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_generate( $request ) {
		$post = get_post( absint( $request->get_param( 'post_id' ) ) );
		if ( ! $post ) {
			return new WP_Error( 'twtaeo_no_post', __( 'Page not found.', 'twt-aeo-ultimate' ), array( 'status' => 404 ) );
		}

		$field = (string) $request->get_param( 'field' );

		// "Don't ask again" is recorded before the work runs, so a generation
		// that then fails does not also lose the preference the person set.
		if ( true === $request->get_param( 'remember' ) ) {
			TWTAEO_Publish_Ready::set_asks_before_spending( false );
		}

		// Everything here reads the saved post: the generators summarise the
		// page, and alt text is written back into post_content. Working from a
		// body the database has not seen would either summarise the wrong text
		// or overwrite the author's unsaved edits.
		if ( '' === trim( (string) $post->post_content ) ) {
			return new WP_Error(
				'twtaeo_unsaved',
				__( 'Save a draft first, then generate.', 'twt-aeo-ultimate' ),
				array( 'status' => 409 )
			);
		}

		switch ( $field ) {
			case 'seo_title':
				$result = self::generate_title( $post );
				break;

			case 'meta_description':
				$result = self::generate_description( $post );
				break;

			case 'share_image':
				$result = self::generate_share_image( $post );
				break;

			case 'image_alt':
				// Same hazard as the manual save: this writes into post_content.
				$current = self::content_is_current( $request, $post );
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				$result = self::generate_alt_text( $post );
				break;

			default:
				return new WP_Error( 'twtaeo_bad_field', __( 'That field cannot be generated.', 'twt-aeo-ultimate' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// post_content may have been rewritten (alt text), so the state is read
		// from the stored post and the new body goes back for the editor to
		// adopt. Without that the author's next save would undo the change.
		$fresh = get_post( $post->ID );

		return self::state_response(
			$fresh,
			(string) $fresh->post_content,
			$result['notice'],
			! empty( $result['content_changed'] ) ? (string) $fresh->post_content : null
		);
	}

	/**
	 * Store a value the author typed or chose.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_save( $request ) {
		$post = get_post( absint( $request->get_param( 'post_id' ) ) );
		if ( ! $post ) {
			return new WP_Error( 'twtaeo_no_post', __( 'Page not found.', 'twt-aeo-ultimate' ), array( 'status' => 404 ) );
		}

		$field = (string) $request->get_param( 'field' );
		$value = $request->get_param( 'value' );

		switch ( $field ) {
			case 'seo_title':
				TWTAEO_Publish_Ready::save_title( $post->ID, is_scalar( $value ) ? (string) $value : '' );
				$notice          = __( 'SEO title saved.', 'twt-aeo-ultimate' );
				$content_changed = false;
				break;

			case 'meta_description':
				TWTAEO_AI_Description::save_description( $post->ID, is_scalar( $value ) ? (string) $value : '' );
				$notice          = __( 'Meta description saved.', 'twt-aeo-ultimate' );
				$content_changed = false;
				break;

			case 'share_image':
				$saved = self::save_share_image( $post, $value );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$notice          = $saved;
				$content_changed = false;
				break;

			case 'featured_image':
				$saved = self::save_featured_image( $post, $value );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$notice          = $saved;
				$content_changed = false;
				break;

			case 'image_alt':
				$current = self::content_is_current( $request, $post );
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				$saved = self::save_alt_text( $post, $value );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$notice          = $saved;
				$content_changed = true;
				break;

			// Not a field on the page: the person's own preference about being
			// asked before a billed call. It rides this route so turning the
			// confirm back on needs no separate endpoint.
			case 'cost_confirm':
				$ask = ! empty( $value );
				TWTAEO_Publish_Ready::set_asks_before_spending( $ask );
				$notice          = $ask
					? __( 'You will be asked again before each AI call.', 'twt-aeo-ultimate' )
					: __( 'AI calls will run without asking.', 'twt-aeo-ultimate' );
				$content_changed = false;
				break;

			default:
				return new WP_Error( 'twtaeo_bad_field', __( 'That field cannot be saved.', 'twt-aeo-ultimate' ), array( 'status' => 400 ) );
		}

		$fresh = get_post( $post->ID );

		return self::state_response(
			$fresh,
			(string) $fresh->post_content,
			$notice,
			$content_changed ? (string) $fresh->post_content : null
		);
	}

	// ── Generators ────────────────────────────────────────────────────────────

	private static function generate_title( WP_Post $post ) {
		$title = TWTAEO_AI_Description::generate_for_post( $post->ID, null, 'title' );
		if ( is_wp_error( $title ) ) {
			return $title;
		}
		TWTAEO_Publish_Ready::save_title( $post->ID, $title );

		return array( 'notice' => __( 'SEO title written.', 'twt-aeo-ultimate' ) );
	}

	private static function generate_description( WP_Post $post ) {
		$desc = TWTAEO_AI_Description::generate_for_post( $post->ID, null, 'meta' );
		if ( is_wp_error( $desc ) ) {
			return $desc;
		}
		TWTAEO_AI_Description::save_description( $post->ID, $desc );

		return array( 'notice' => __( 'Meta description written.', 'twt-aeo-ultimate' ) );
	}

	private static function generate_share_image( WP_Post $post ) {
		$image = TWTAEO_AI_Description::generate_og_image( $post->ID );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		// Keep the page's other share fields: this sets the image, nothing else.
		$og                 = TWTAEO_OG_Writer::get( $post->ID );
		$og['og_image']     = $image['url'];
		$og['og_image_alt'] = $image['alt'];
		TWTAEO_OG_Writer::save( $post->ID, $og );

		return array( 'notice' => __( 'Share image generated and set.', 'twt-aeo-ultimate' ) );
	}

	private static function generate_alt_text( WP_Post $post ) {
		$result = TWTAEO_Image_Optimizer::generate_alt( $post->ID );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'content_changed' => true,
			'notice'          => sprintf(
				/* translators: %d: number of images. */
				_n( 'Alt text written for %d image.', 'Alt text written for %d images.', (int) $result['filled'], 'twt-aeo-ultimate' ),
				(int) $result['filled']
			),
		);
	}

	// ── Manual saves ──────────────────────────────────────────────────────────

	private static function save_share_image( WP_Post $post, $value ) {
		$url = '';
		$alt = '';

		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$attachment_id = (int) $value;
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				return new WP_Error( 'twtaeo_bad_image', __( 'That is not an image in the media library.', 'twt-aeo-ultimate' ), array( 'status' => 400 ) );
			}
			$url = (string) wp_get_attachment_url( $attachment_id );
			$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
			$url = esc_url_raw( trim( $value ) );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'twtaeo_bad_image', __( 'That share image address is not usable.', 'twt-aeo-ultimate' ), array( 'status' => 400 ) );
			}
		}

		$og             = TWTAEO_OG_Writer::get( $post->ID );
		$og['og_image'] = $url;
		if ( '' !== $alt ) {
			$og['og_image_alt'] = $alt;
		}
		TWTAEO_OG_Writer::save( $post->ID, $og );

		return '' === $url
			? __( 'Share image cleared. The page falls back to the next image it has.', 'twt-aeo-ultimate' )
			: __( 'Share image set.', 'twt-aeo-ultimate' );
	}

	private static function save_featured_image( WP_Post $post, $value ) {
		// The panel's one-click offer: the picture the post already leads with.
		if ( 'first-content-image' === $value ) {
			$candidate = TWTAEO_OG_Writer::first_content_image( $post->ID );
			if ( ! $candidate || ! $candidate['id'] ) {
				return new WP_Error(
					'twtaeo_no_candidate',
					__( 'No image in the content belongs to this site\'s media library, so it cannot be the featured image.', 'twt-aeo-ultimate' ),
					array( 'status' => 400 )
				);
			}
			set_post_thumbnail( $post->ID, (int) $candidate['id'] );

			return __( 'Featured image set from the first image in the content.', 'twt-aeo-ultimate' );
		}

		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$attachment_id = (int) $value;
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				return new WP_Error( 'twtaeo_bad_image', __( 'That is not an image in the media library.', 'twt-aeo-ultimate' ), array( 'status' => 400 ) );
			}
			set_post_thumbnail( $post->ID, $attachment_id );

			return __( 'Featured image set.', 'twt-aeo-ultimate' );
		}

		delete_post_thumbnail( $post->ID );

		return __( 'Featured image removed.', 'twt-aeo-ultimate' );
	}

	private static function save_alt_text( WP_Post $post, $value ) {
		if ( ! is_array( $value ) || ! $value ) {
			return new WP_Error( 'twtaeo_bad_alt', __( 'No alt text was sent.', 'twt-aeo-ultimate' ), array( 'status' => 400 ) );
		}

		$map = array();
		foreach ( $value as $key => $alt ) {
			if ( ! is_scalar( $alt ) ) {
				continue;
			}
			// The keys come from TWTAEO_Image_Optimizer::collect(): "featured"
			// and "c0", "c1" and so on. save_manual() ignores anything it does
			// not recognise, so a stale key from an edited body is dropped
			// rather than applied to the wrong image.
			$map[ sanitize_key( $key ) ] = sanitize_text_field( (string) $alt );
		}

		$result = TWTAEO_Image_Optimizer::save_manual( $post->ID, $map );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return sprintf(
			/* translators: %d: number of images whose alt text was saved. */
			_n( 'Alt text saved for %d image.', 'Alt text saved for %d images.', (int) $result['saved'], 'twt-aeo-ultimate' ),
			(int) $result['saved']
		);
	}

	// ── Shared ────────────────────────────────────────────────────────────────

	/**
	 * The body to read state against: what the editor sent, else the saved post.
	 *
	 * @param WP_REST_Request $request
	 * @param WP_Post         $post
	 * @return string
	 */
	private static function content_param( $request, WP_Post $post ) {
		$content = $request->get_param( 'content' );

		return ( null === $content ) ? (string) $post->post_content : (string) $content;
	}

	/**
	 * Refuse to touch alt text when the caller is looking at a different body
	 * from the one stored.
	 *
	 * Alt text is the one thing here written back into post_content, and the
	 * image optimizer rewrites the SAVED body by position: the first <img>, the
	 * second, and so on. The panel reads the editor's live body so it can count
	 * images as they are pasted, and those two bodies diverge the moment
	 * anything is unsaved. Writing then rewrites the wrong tag, or a tag that
	 * is not there, and the saved body that comes back is missing everything
	 * the author has not saved.
	 *
	 * Comparing the two is an optimistic-concurrency check: the caller says
	 * which body it believes is current, and this refuses when it is not. It
	 * also catches a second tab, or anyone else, having saved in between.
	 *
	 * @param WP_REST_Request $request
	 * @param WP_Post         $post
	 * @return true|WP_Error
	 */
	private static function content_is_current( $request, WP_Post $post ) {
		$claimed = $request->get_param( 'content' );
		if ( null === $claimed ) {
			// No claim made, so the caller is working from the stored body.
			return true;
		}

		if ( (string) $claimed === (string) $post->post_content ) {
			return true;
		}

		return new WP_Error(
			'twtaeo_stale_content',
			__( 'Save the page first. Alt text is written into the page content, and this page has changes that are not saved yet.', 'twt-aeo-ultimate' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * One response shape for all three actions, so a panel never has to work
	 * out what changed: it redraws from this.
	 *
	 * @param WP_Post     $post
	 * @param string      $content
	 * @param string      $notice
	 * @param string|null $new_content Body the server rewrote, for the editor to adopt.
	 * @return WP_REST_Response
	 */
	private static function state_response( WP_Post $post, $content, $notice = '', $new_content = null ) {
		$fields = TWTAEO_Publish_Ready::fields( $post, $content );

		$payload = array(
			'post_id'     => (int) $post->ID,
			'fields'      => $fields,
			'summary'     => TWTAEO_Publish_Ready::summary( $fields ),
			'notice'      => (string) $notice,
			'images'      => self::image_rows( $post, $content ),
			// Travels with every response so both panels, and a second tab,
			// stay in step with the preference rather than caching the value
			// they were loaded with.
			'ask_before_spending' => TWTAEO_Publish_Ready::asks_before_spending(),
		);

		if ( null !== $new_content ) {
			$payload['content'] = $new_content;
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * The page's images for the alt-text editor: enough to show a thumbnail and
	 * write a description, and nothing else.
	 *
	 * @param WP_Post $post
	 * @param string  $content
	 * @return array[]
	 */
	private static function image_rows( WP_Post $post, $content ) {
		if ( ! class_exists( 'TWTAEO_Image_Optimizer' ) ) {
			return array();
		}

		$probe               = clone $post;
		$probe->post_content = (string) $content;

		$rows = array();
		foreach ( TWTAEO_Image_Optimizer::collect( $probe ) as $image ) {
			$rows[] = array(
				'key'     => $image['key'],
				'context' => $image['context'],
				'src'     => $image['src'],
				'alt'     => $image['alt'],
			);
		}

		return $rows;
	}
}
