<?php
/**
 * Product FAQ Generator
 *
 * Programmatic (PHP) and AI-driven FAQ generator for WooCommerce products.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Product_FAQ_Generator {

	/**
	 * Meta key for storing product FAQs.
	 */
	const META_FAQS = '_twtaeo_product_faqs';

	/**
	 * Register hooks for frontend rendering and post saving.
	 */
	public static function register_hooks() {
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'add_faq_tab' ), 30 );
		add_action( 'save_post_product', array( __CLASS__, 'on_save_product' ), 10, 2 );
	}

	/**
	 * Add custom FAQ tab to WooCommerce product pages.
	 *
	 * @param array $tabs
	 * @return array
	 */
	public static function add_faq_tab( $tabs ) {
		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return $tabs;
		}

		$faqs = self::get_faqs( $product_id );
		if ( empty( $faqs ) ) {
			return $tabs;
		}

		$tabs['aeo_product_faqs'] = array(
			'title'    => __( 'FAQs', 'twt-aeo-ultimate' ),
			'priority' => 50,
			'callback' => array( __CLASS__, 'render_faq_tab_content' ),
		);

		return $tabs;
	}

	/**
	 * Render the FAQs tab HTML content.
	 */
	public static function render_faq_tab_content() {
		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return;
		}

		$faqs = self::get_faqs( $product_id );
		if ( empty( $faqs ) ) {
			return;
		}

		echo '<div class="twt-aeo-faqs-container">';
		echo '<h2 class="twt-aeo-faqs-title">' . esc_html__( 'Frequently Asked Questions', 'twt-aeo-ultimate' ) . '</h2>';
		echo '<div class="twt-aeo-faqs-list">';
		
		foreach ( $faqs as $index => $faq ) {
			$q = trim( $faq['question'] ?? '' );
			$a = trim( $faq['answer'] ?? '' );
			if ( ! $q || ! $a ) {
				continue;
			}
			
			echo '<div class="twt-aeo-faq-item">';
			echo '<h3 class="twt-aeo-faq-question">' . esc_html( $q ) . '</h3>';
			echo '<div class="twt-aeo-faq-answer"><p>' . wp_kses_post( wpautop( $a ) ) . '</p></div>';
			echo '</div>';
		}
		
		echo '</div>';
		echo '</div>';

		// Styled inline for clean, premium appearance.
		?>
		<style>
			.twt-aeo-faqs-container {
				margin-top: 10px;
				font-family: inherit;
			}
			.twt-aeo-faqs-title {
				margin-bottom: 20px;
				font-size: 1.5em;
			}
			.twt-aeo-faq-item {
				border-bottom: 1px solid #e2e8f0;
				padding: 15px 0;
			}
			.twt-aeo-faq-item:last-child {
				border-bottom: none;
			}
			.twt-aeo-faq-question {
				font-size: 1.1em;
				font-weight: 600;
				margin: 0 0 8px 0 !important;
				color: #1e293b;
			}
			.twt-aeo-faq-answer {
				color: #475569;
				line-height: 1.6;
			}
			.twt-aeo-faq-answer p {
				margin: 0 !important;
			}
		</style>
		<?php
	}

	/**
	 * Get stored FAQs for a product.
	 *
	 * @param int $product_id
	 * @return array
	 */
	public static function get_faqs( $product_id ) {
		$raw = get_post_meta( $product_id, self::META_FAQS, true );
		if ( ! $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Save FAQs for a product.
	 *
	 * @param int   $product_id
	 * @param array $faqs List of Q&A arrays: array( array( 'question' => '...', 'answer' => '...' ) )
	 * @return bool
	 */
	public static function save_faqs( $product_id, $faqs ) {
		// Filter out empty rows.
		$cleaned = array();
		foreach ( (array) $faqs as $row ) {
			$q = trim( $row['question'] ?? '' );
			$a = trim( $row['answer'] ?? '' );
			if ( $q && $a ) {
				$cleaned[] = array( 'question' => $q, 'answer' => $a );
			}
		}

		if ( empty( $cleaned ) ) {
			delete_post_meta( $product_id, self::META_FAQS );
			if ( class_exists( 'TWTAEO_Custom_Schema_Writer' ) ) {
				TWTAEO_Custom_Schema_Writer::delete( $product_id, 'FAQPage' );
			}
			return true;
		}

		$saved = update_post_meta( $product_id, self::META_FAQS, TWTAEO_Custom_Schema_Writer::encode_for_meta( $cleaned ) );

		if ( class_exists( 'TWTAEO_Custom_Schema_Writer' ) && class_exists( 'TWTAEO_FAQ_Detector' ) ) {
			$schema = TWTAEO_FAQ_Detector::build_faqpage_schema( $product_id, $cleaned );
			TWTAEO_Custom_Schema_Writer::save( $product_id, 'FAQPage', wp_json_encode( $schema ) );
		}

		return $saved;
	}

	/**
	 * Programmatically generate FAQs based on product data.
	 *
	 * @param int $product_id
	 * @return array List of Q&As
	 */
	public static function generate_php_faqs( $product_id ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		$faqs = array();
		$name = $product->get_name();

		// 1. Stock / Availability
		$stock_status = $product->get_stock_status();
		/* translators: %s: product name. */
		$q_stock      = sprintf( __( 'Is %s currently in stock?', 'twt-aeo-ultimate' ), $name );
		if ( 'instock' === $stock_status ) {
			/* translators: %s: product name. */
			$a_stock = sprintf( __( 'Yes, %s is currently in stock and available for purchase.', 'twt-aeo-ultimate' ), $name );
		} elseif ( 'onbackorder' === $stock_status ) {
			/* translators: %s: product name. */
			$a_stock = sprintf( __( 'Yes, %s is available on backorder and can be ordered now.', 'twt-aeo-ultimate' ), $name );
		} else {
			/* translators: %s: product name. */
			$a_stock = sprintf( __( 'No, %s is currently out of stock.', 'twt-aeo-ultimate' ), $name );
		}
		$faqs[] = array( 'question' => $q_stock, 'answer' => $a_stock );

		// 2. Sizing / Variations (if variable product)
		if ( TWTAEO_WC_Extensions::is_variable_like( $product ) ) {
			$variations = $product->get_available_variations();
			$in_stock_options = array();
			
			foreach ( $variations as $var_data ) {
				if ( ! $var_data['is_in_stock'] ) {
					continue;
				}
				$attr_desc = array();
				foreach ( $var_data['attributes'] as $attr_key => $attr_val ) {
					$label = wc_attribute_label( str_replace( 'attribute_', '', $attr_key ), $product );
					$value = $attr_val;
					if ( taxonomy_exists( str_replace( 'attribute_', '', $attr_key ) ) ) {
						$term = get_term_by( 'slug', $attr_val, str_replace( 'attribute_', '', $attr_key ) );
						if ( $term && ! is_wp_error( $term ) ) {
							$value = $term->name;
						}
					}
					$attr_desc[] = $label . ' ' . $value;
				}
				if ( ! empty( $attr_desc ) ) {
					$in_stock_options[] = implode( ' / ', $attr_desc );
				}
			}

			if ( ! empty( $in_stock_options ) ) {
				/* translators: %s: product name. */
				$q_var = sprintf( __( 'What sizes or variations are available for %s?', 'twt-aeo-ultimate' ), $name );
				$a_var = sprintf(
					/* translators: %s: comma-separated list of in-stock variation options. */
					__( 'The following in-stock options are currently available: %s. Sizing and option availability can be selected on this page.', 'twt-aeo-ultimate' ),
					implode( ', ', $in_stock_options )
				);
				$faqs[] = array( 'question' => $q_var, 'answer' => $a_var );
			}
		}

		// 3. Shipping Details
		if ( class_exists( 'TWTAEO_WooCommerce_Detector' ) ) {
			$shipping = TWTAEO_WooCommerce_Detector::build_shipping_details();
			if ( $shipping ) {
				$shipping_nodes = isset( $shipping['@type'] ) ? array( $shipping ) : $shipping;
				$shipping_desc = array();

				foreach ( $shipping_nodes as $node ) {
					if ( empty( $node['shippingRate'] ) || empty( $node['shippingDestination'] ) ) {
						continue;
					}
					
					$rate = $node['shippingRate']['value'] ?? '';
					$currency = $node['shippingRate']['currency'] ?? 'USD';
					$country = $node['shippingDestination']['addressCountry'] ?? '';
					
					$rate_text = ( floatval( $rate ) === 0.0 ) ? __( 'Free shipping', 'twt-aeo-ultimate' ) : $rate . ' ' . $currency;
					
					$deliv_text = '';
					if ( ! empty( $node['deliveryTime'] ) ) {
						$h_min = $node['deliveryTime']['handlingTime']['minValue'] ?? 0;
						$h_max = $node['deliveryTime']['handlingTime']['maxValue'] ?? 1;
						$t_min = $node['deliveryTime']['transitTime']['minValue'] ?? 1;
						$t_max = $node['deliveryTime']['transitTime']['maxValue'] ?? 5;
						
						$min_days = intval( $h_min ) + intval( $t_min );
						$max_days = intval( $h_max ) + intval( $t_max );
						$deliv_text = sprintf(
							/* translators: 1: minimum delivery days, 2: maximum delivery days. */
							__( ' Delivery typically takes %1$d-%2$d business days.', 'twt-aeo-ultimate' ),
							$min_days,
							$max_days
						);
					}

					$shipping_desc[] = sprintf(
						/* translators: 1: destination country, 2: shipping rate, 3: optional delivery-time sentence. */
						__( 'To %1$s: %2$s.%3$s', 'twt-aeo-ultimate' ),
						$country,
						$rate_text,
						$deliv_text
					);
				}

				if ( ! empty( $shipping_desc ) ) {
					/* translators: %s: product name. */
					$q_ship = sprintf( __( 'What are the shipping options and times for %s?', 'twt-aeo-ultimate' ), $name );
					$a_ship = sprintf(
						/* translators: %s: semicolon-separated list of shipping destinations and rates. */
						__( 'We offer the following shipping options for this product: %s.', 'twt-aeo-ultimate' ),
						implode( '; ', $shipping_desc )
					);
					$faqs[] = array( 'question' => $q_ship, 'answer' => $a_ship );
				}
			}
		}

		// 4. Return Policy
		if ( class_exists( 'TWTAEO_WooCommerce_Detector' ) ) {
			$returns = TWTAEO_WooCommerce_Detector::build_return_policy();
			if ( $returns ) {
				/* translators: %s: product name. */
				$q_ret = sprintf( __( 'What is the return policy for %s?', 'twt-aeo-ultimate' ), $name );
				
				$days = $returns['merchantReturnDays'] ?? 30;
				$fees = $returns['returnFees'] ?? '';
				$link = $returns['merchantReturnLink'] ?? '';
				
				$fees_text = ( strpos( $fees, 'FreeReturn' ) !== false ) 
					? __( 'free of charge', 'twt-aeo-ultimate' ) 
					: __( 'customer-responsible return shipping', 'twt-aeo-ultimate' );

				if ( isset( $returns['returnPolicyCategory'] ) && strpos( $returns['returnPolicyCategory'], 'MerchantReturnUnspecified' ) !== false ) {
					// No placeholders here, so no sprintf() wrapper is needed.
					$a_ret = __( 'This product is eligible for returns under our store refund policy. For more information, please check our returns link.', 'twt-aeo-ultimate' );
				} else {
					$a_ret = sprintf(
						/* translators: 1: number of days returns are accepted within, 2: who pays return shipping. */
						__( 'We accept returns for this product within %1$d days. Return shipping is %2$s.', 'twt-aeo-ultimate' ),
						intval( $days ),
						$fees_text
					);
				}

				if ( $link ) {
					$a_ret .= ' ' . sprintf(
						/* translators: %s: URL of the store's return policy page. */
						__( 'Read our complete return policy at %s.', 'twt-aeo-ultimate' ),
						$link
					);
				}
				
				$faqs[] = array( 'question' => $q_ret, 'answer' => $a_ret );
			}
		}

		return $faqs;
	}

	/**
	 * AI completion wrapper to discover and generate contextual product FAQs.
	 *
	 * @param int $product_id
	 * @return array|WP_Error List of Q&As or WP_Error.
	 */
	public static function generate_ai_faqs( $product_id ) {
		if ( ! class_exists( 'TWTAEO_AI_Client' ) ) {
			return new WP_Error( 'missing_client', 'AI Client class not found.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'missing_product', 'Product not found.' );
		}

		$name       = $product->get_name();
		$desc       = wp_strip_all_tags( $product->get_description() );
		$short_desc = wp_strip_all_tags( $product->get_short_description() );
		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		$cat_list   = is_array( $categories ) ? implode( ', ', $categories ) : '';
		
		$attributes = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( $attr->is_taxonomy() ) {
				$terms = $attr->get_terms();
				$vals  = array();
				foreach ( $terms as $t ) {
					$vals[] = $t->name;
				}
				$attributes[ wc_attribute_label( $attr->get_name(), $product ) ] = implode( ', ', $vals );
			} else {
				$attributes[ wc_attribute_label( $attr->get_name(), $product ) ] = implode( ', ', $attr->get_options() );
			}
		}
		
		$attr_str = '';
		foreach ( $attributes as $lbl => $val ) {
			$attr_str .= "- {$lbl}: {$val}\n";
		}

		$system = "You are an expert e-commerce copywriter. Generate a JSON list of exactly 3 to 5 frequently asked questions (FAQs) and detailed, factual answers for a product.
Guidelines:
1. Base the questions and answers ONLY on the factual product information provided (description, attributes, category).
2. Do NOT hallucinate features, warranties, or dimensions that are not explicitly stated.
3. Keep answers concise, helpful, and professional.
4. Output strict JSON only. Do not wrap in markdown tags or include conversational prose.

JSON Format:
[
  {
    \"question\": \"Is the fabric waterproof?\",
    \"answer\": \"Yes, the jacket is made of waterproof nylon fabric...\"
  }
]";

		$prompt = "Product Title: {$name}\n";
		$prompt .= "Category: {$cat_list}\n";
		if ( $short_desc ) {
			$prompt .= "Short Description: {$short_desc}\n";
		}
		if ( $desc ) {
			$prompt .= "Full Description: {$desc}\n";
		}
		if ( $attr_str ) {
			$prompt .= "Attributes:\n{$attr_str}\n";
		}

		$provider = TWTAEO_AI_Client::complete( '', $prompt, array(
			'system'      => $system,
			'json'        => true,
			'temperature' => 0.1,
		) );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$faq_data = TWTAEO_AI_Client::extract_json( $provider );
		if ( is_wp_error( $faq_data ) ) {
			return $faq_data;
		}

		if ( ! is_array( $faq_data ) ) {
			return new WP_Error( 'invalid_data', 'AI returned empty or invalid data structure.' );
		}

		return $faq_data;
	}

	/**
	 * Render the Product FAQs metabox on the product edit screen.
	 *
	 * @param WP_Post $post
	 */
	public static function render_metabox( $post ) {
		wp_nonce_field( 'twtaeo_product_faqs_save', 'twtaeo_product_faqs_nonce' );
		$faqs = self::get_faqs( $post->ID );
		?>
		<div class="twt-aeo-faqs-metabox">
			<p class="description" style="margin-bottom:15px;">
				<?php esc_html_e( 'Frequently Asked Questions about this product. These are automatically added to the frontend product tab and emitted in JSON-LD schema so answer engines and search engines can read them.', 'twt-aeo-ultimate' ); ?>
			</p>
			
			<div style="margin-bottom:15px; display:flex; align-items:center; gap:10px;">
				<button type="button" class="button twt-aeo-generate-faqs-php" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'Generate from Product Data (PHP)', 'twt-aeo-ultimate' ); ?>
				</button>
				<button type="button" class="button twt-aeo-generate-faqs-ai" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'Generate with AI', 'twt-aeo-ultimate' ); ?>
				</button>
				<span class="twt-aeo-faq-generation-spinner spinner" style="float:none; margin: 0 10px;"></span>
				<span class="twt-aeo-faq-generation-status" style="font-weight:600;"></span>
			</div>

			<table class="widefat twt-aeo-faqs-table" style="margin-bottom:12px; width:100%;">
				<thead>
					<tr>
						<th style="width:35%;"><?php esc_html_e( 'Question', 'twt-aeo-ultimate' ); ?></th>
						<th><?php esc_html_e( 'Answer', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:70px; text-align:center;"><?php esc_html_e( 'Delete', 'twt-aeo-ultimate' ); ?></th>
					</tr>
				</thead>
				<tbody class="twt-aeo-faqs-rows">
					<?php if ( ! empty( $faqs ) ) : ?>
						<?php foreach ( $faqs as $index => $faq ) : ?>
							<tr class="twt-aeo-faq-row">
								<td>
									<input type="text" name="twtaeo_faq_question[]" class="large-text" value="<?php echo esc_attr( $faq['question'] ); ?>" required placeholder="<?php esc_attr_e( 'Question', 'twt-aeo-ultimate' ); ?>">
								</td>
								<td>
									<textarea name="twtaeo_faq_answer[]" class="large-text" rows="2" required placeholder="<?php esc_attr_e( 'Answer', 'twt-aeo-ultimate' ); ?>"><?php echo esc_textarea( $faq['answer'] ); ?></textarea>
								</td>
								<td style="text-align:center; vertical-align:middle;">
									<button type="button" class="button twt-aeo-delete-faq-row" style="color:#d63638; border-color:#d63638;">✕</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<button type="button" class="button button-secondary twt-aeo-add-faq-row">
				+ <?php esc_html_e( 'Add FAQ Row', 'twt-aeo-ultimate' ); ?>
			</button>
		</div>
		<style>
			.twt-aeo-faqs-table th {
				font-weight: 600;
			}
			.twt-aeo-faqs-table td {
				padding: 10px;
			}
			.twt-aeo-faqs-table textarea {
				resize: vertical;
			}
		</style>
		<?php
	}

	/**
	 * Hooked into save_post_product to capture and save FAQs.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function on_save_product( $post_id, $post ) {
		if ( ! isset( $_POST['twtaeo_product_faqs_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['twtaeo_product_faqs_nonce'] ) ), 'twtaeo_product_faqs_save' ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Nonce and capability are both verified above. Each value is sanitized
		// individually below, so the arrays are only unslashed here.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is sanitized in the loop below.
		$questions = isset( $_POST['twtaeo_faq_question'] ) ? (array) wp_unslash( $_POST['twtaeo_faq_question'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is sanitized in the loop below.
		$answers   = isset( $_POST['twtaeo_faq_answer'] ) ? (array) wp_unslash( $_POST['twtaeo_faq_answer'] ) : array();
		$faqs      = array();

		// Iterate the questions rather than a counted index: a mismatched pair of
		// arrays would otherwise read past the end of $answers.
		foreach ( $questions as $i => $question ) {
			$q = sanitize_text_field( is_scalar( $question ) ? (string) $question : '' );
			$a = sanitize_textarea_field( isset( $answers[ $i ] ) && is_scalar( $answers[ $i ] ) ? (string) $answers[ $i ] : '' );
			if ( $q && $a ) {
				$faqs[] = array(
					'question' => $q,
					'answer'   => $a,
				);
			}
		}

		self::save_faqs( $post_id, $faqs );
	}
}
