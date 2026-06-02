<?php
/**
 * Local Pack
 *
 * Stores LocalBusiness profile data and outputs JSON-LD schema on configured pages.
 * Supports all common schema.org LocalBusiness subtypes, opening hours,
 * geo coordinates, service area, and price range.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Local_Pack {

	const OPTION_SETTINGS = 'twtaeo_local_pack';
	const NONCE_SETTINGS  = 'twtaeo_local_pack_save';

	// ── Hooks ─────────────────────────────────────────────────────────────────

	public static function register_hooks() {
		add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 20 );
	}

	// ── Schema Output ─────────────────────────────────────────────────────────

	public static function output_schema() {
		$settings = self::get_settings();

		if ( empty( $settings['business_name'] ) ) {
			return;
		}

		if ( ! self::should_output( $settings ) ) {
			return;
		}

		$schema = self::build_schema( $settings );

		echo '<script type="application/ld+json">' . "\n"
			. wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
			. "\n</script>\n";
	}

	private static function should_output( array $settings ) {
		$output_on = $settings['output_on'] ?? 'front_page';

		if ( 'front_page' === $output_on ) {
			return is_front_page();
		}

		if ( 'all' === $output_on ) {
			return ! is_admin();
		}

		if ( 'specific' === $output_on && is_singular() ) {
			$ids = self::parse_id_list( $settings['output_pages'] ?? '' );
			return in_array( get_the_ID(), $ids, true );
		}

		return false;
	}

	public static function build_schema( array $settings ) {
		$type   = ! empty( $settings['business_type'] ) ? $settings['business_type'] : 'LocalBusiness';
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => $settings['business_name'],
			'url'      => ! empty( $settings['business_url'] ) ? $settings['business_url'] : home_url( '/' ),
		);

		if ( ! empty( $settings['phone'] ) ) {
			$schema['telephone'] = $settings['phone'];
		}
		if ( ! empty( $settings['email'] ) ) {
			$schema['email'] = $settings['email'];
		}
		if ( ! empty( $settings['description'] ) ) {
			$schema['description'] = $settings['description'];
		}
		if ( ! empty( $settings['price_range'] ) ) {
			$schema['priceRange'] = $settings['price_range'];
		}
		if ( ! empty( $settings['logo_url'] ) ) {
			$schema['logo'] = $settings['logo_url'];
		}
		if ( ! empty( $settings['image_url'] ) ) {
			$schema['image'] = $settings['image_url'];
		}
		if ( ! empty( $settings['maps_url'] ) ) {
			$schema['hasMap'] = $settings['maps_url'];
		}

		// Address
		if ( ! empty( $settings['street_address'] ) || ! empty( $settings['city'] ) ) {
			$addr = array( '@type' => 'PostalAddress' );
			if ( ! empty( $settings['street_address'] ) ) $addr['streetAddress']   = $settings['street_address'];
			if ( ! empty( $settings['city'] ) )           $addr['addressLocality'] = $settings['city'];
			if ( ! empty( $settings['state'] ) )          $addr['addressRegion']   = $settings['state'];
			if ( ! empty( $settings['zip'] ) )            $addr['postalCode']      = $settings['zip'];
			if ( ! empty( $settings['country'] ) )        $addr['addressCountry']  = $settings['country'];
			$schema['address'] = $addr;
		}

		// Geo
		if ( ! empty( $settings['latitude'] ) && ! empty( $settings['longitude'] ) ) {
			$schema['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $settings['latitude'],
				'longitude' => (float) $settings['longitude'],
			);
		}

		// Area served
		if ( ! empty( $settings['area_served'] ) ) {
			$areas = array_filter( array_map( 'trim', explode( ',', $settings['area_served'] ) ) );
			if ( count( $areas ) === 1 ) {
				$schema['areaServed'] = reset( $areas );
			} elseif ( count( $areas ) > 1 ) {
				$schema['areaServed'] = array_values( $areas );
			}
		}

		// Opening hours
		$hours = self::build_opening_hours( $settings['hours'] ?? array() );
		if ( ! empty( $hours ) ) {
			$schema['openingHoursSpecification'] = $hours;
		}

		return $schema;
	}

	private static function build_opening_hours( array $hours ) {
		$specs = array();
		foreach ( $hours as $group ) {
			if ( empty( $group['days'] ) || ! isset( $group['opens'], $group['closes'] ) ) {
				continue;
			}
			$specs[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => array_map(
					function( $d ) { return 'https://schema.org/' . $d; },
					(array) $group['days']
				),
				'opens'     => $group['opens'],
				'closes'    => $group['closes'],
			);
		}
		return $specs;
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	public static function get_settings() {
		return get_option( self::OPTION_SETTINGS, array() );
	}

	public static function save_settings( array $raw ) {
		$settings = array(
			'business_name'     => sanitize_text_field( $raw['business_name']     ?? '' ),
			'business_type'     => sanitize_text_field( $raw['business_type']     ?? 'LocalBusiness' ),
			'phone'             => sanitize_text_field( $raw['phone']             ?? '' ),
			'email'             => sanitize_email(      $raw['email']             ?? '' ),
			'business_url'      => esc_url_raw(         $raw['business_url']      ?? '' ),
			'street_address'    => sanitize_text_field( $raw['street_address']    ?? '' ),
			'city'              => sanitize_text_field( $raw['city']              ?? '' ),
			'state'             => sanitize_text_field( $raw['state']             ?? '' ),
			'zip'               => sanitize_text_field( $raw['zip']               ?? '' ),
			'country'           => sanitize_text_field( $raw['country']           ?? 'US' ),
			'latitude'          => sanitize_text_field( $raw['latitude']          ?? '' ),
			'longitude'         => sanitize_text_field( $raw['longitude']         ?? '' ),
			'maps_url'          => esc_url_raw(         $raw['maps_url']          ?? '' ),
			'price_range'       => sanitize_text_field( $raw['price_range']       ?? '' ),
			'description'       => sanitize_textarea_field( $raw['description']   ?? '' ),
			'logo_url'          => esc_url_raw(         $raw['logo_url']          ?? '' ),
			'image_url'         => esc_url_raw(         $raw['image_url']         ?? '' ),
			'area_served'       => sanitize_text_field( $raw['area_served']       ?? '' ),
			'output_on'         => sanitize_key(        $raw['output_on']         ?? 'front_page' ),
			'output_pages'      => sanitize_text_field( $raw['output_pages']      ?? '' ),
			'hours'             => self::sanitize_hours( $raw['hours']            ?? array() ),
			// NAP / API settings
			'google_nap_method' => sanitize_key(        $raw['google_nap_method'] ?? 'kg' ),
			'google_kg_api_key' => sanitize_text_field( $raw['google_kg_api_key'] ?? '' ),
			'bing_api_key'      => sanitize_text_field( $raw['bing_api_key']      ?? '' ),
		);

		update_option( self::OPTION_SETTINGS, $settings );
		return $settings;
	}

	private static function sanitize_hours( array $raw ) {
		$valid_days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$result     = array();

		foreach ( $raw as $group ) {
			if ( empty( $group['days'] ) ) {
				continue;
			}
			$days = array_values( array_intersect( (array) $group['days'], $valid_days ) );
			if ( empty( $days ) ) {
				continue;
			}
			$result[] = array(
				'days'   => $days,
				'opens'  => sanitize_text_field( $group['opens']  ?? '09:00' ),
				'closes' => sanitize_text_field( $group['closes'] ?? '17:00' ),
			);
		}

		return $result;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function parse_id_list( $raw ) {
		return array_values( array_filter( array_map( 'intval', explode( ',', (string) $raw ) ) ) );
	}

	// ── Auto-fill ─────────────────────────────────────────────────────────────

	/**
	 * Gather business data from all available on-site sources.
	 * Returns an array keyed by field name; each entry has 'value' and 'source'.
	 * Priority: Rank Math → Yoast → AIOSEO → WP core → contact page scan.
	 *
	 * @return array
	 */
	public static function gather_autofill_data() {
		$data = array();

		foreach ( array(
			self::source_rank_math(),
			self::source_yoast(),
			self::source_aioseo(),
			self::source_wp_core(),
			self::source_contact_page(),
		) as $source_data ) {
			foreach ( $source_data as $field => $info ) {
				if ( ! isset( $data[ $field ] ) && '' !== trim( (string) $info['value'] ) ) {
					$data[ $field ] = $info;
				}
			}
		}

		return $data;
	}

	// ── Auto-fill sources ─────────────────────────────────────────────────────

	private static function source_wp_core() {
		$label  = 'WordPress Settings';
		$result = array();

		$result['business_name']  = array( 'value' => get_bloginfo( 'name' ),        'source' => $label );
		$result['description']    = array( 'value' => get_bloginfo( 'description' ), 'source' => $label );
		$result['business_url']   = array( 'value' => home_url( '/' ),               'source' => $label );
		$result['email']          = array( 'value' => get_option( 'admin_email', '' ), 'source' => $label . ' (admin email)' );

		// Custom logo
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo_url ) {
				$result['logo_url'] = array( 'value' => $logo_url, 'source' => $label . ' (custom logo)' );
			}
		}

		// Site icon
		$icon_id = get_option( 'site_icon' );
		if ( $icon_id && ! isset( $result['logo_url'] ) ) {
			$icon_url = wp_get_attachment_image_url( $icon_id, 'full' );
			if ( $icon_url ) {
				$result['logo_url'] = array( 'value' => $icon_url, 'source' => $label . ' (site icon)' );
			}
		}

		return $result;
	}

	private static function source_rank_math() {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return array();
		}

		$rm     = get_option( 'rank_math_titles', array() );
		$label  = 'Rank Math';
		$result = array();

		if ( ! empty( $rm['knowledgegraph_name'] ) ) {
			$result['business_name'] = array( 'value' => $rm['knowledgegraph_name'], 'source' => $label );
		}

		// Business type
		if ( ! empty( $rm['local_business_type'] ) ) {
			$result['business_type'] = array( 'value' => $rm['local_business_type'], 'source' => $label );
		}

		// Logo (may be an attachment ID or a URL)
		if ( ! empty( $rm['knowledgegraph_logo'] ) ) {
			$logo = $rm['knowledgegraph_logo'];
			if ( is_numeric( $logo ) ) {
				$logo = wp_get_attachment_image_url( (int) $logo, 'full' ) ?: '';
			}
			if ( $logo ) {
				$result['logo_url'] = array( 'value' => $logo, 'source' => $label );
			}
		}

		// Local SEO fields (Rank Math PRO)
		$local_map = array(
			'local_address_street_address' => 'street_address',
			'local_address_locality'       => 'city',
			'local_address_region'         => 'state',
			'local_address_postal_code'    => 'zip',
			'local_address_country'        => 'country',
			'local_phone'                  => 'phone',
			'local_email'                  => 'email',
			'local_geo_coordinates_lat'    => 'latitude',
			'local_geo_coordinates_long'   => 'longitude',
			'local_price_range'            => 'price_range',
		);
		foreach ( $local_map as $rm_key => $field ) {
			if ( ! empty( $rm[ $rm_key ] ) ) {
				$result[ $field ] = array( 'value' => $rm[ $rm_key ], 'source' => $label );
			}
		}

		return $result;
	}

	private static function source_yoast() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return array();
		}

		$titles = get_option( 'wpseo_titles', array() );
		$label  = 'Yoast SEO';
		$result = array();

		if ( ! empty( $titles['company_name'] ) ) {
			$result['business_name'] = array( 'value' => $titles['company_name'], 'source' => $label );
		}

		if ( ! empty( $titles['company_logo_id'] ) ) {
			$logo = wp_get_attachment_image_url( (int) $titles['company_logo_id'], 'full' );
			if ( $logo ) {
				$result['logo_url'] = array( 'value' => $logo, 'source' => $label );
			}
		}

		// Yoast stores address in wpseo_local options when Local SEO is active
		$local = get_option( 'wpseo_local', array() );
		$yoast_local_map = array(
			'location_address'  => 'street_address',
			'location_city'     => 'city',
			'location_state'    => 'state',
			'location_zipcode'  => 'zip',
			'location_country'  => 'country',
			'location_phone'    => 'phone',
			'location_email'    => 'email',
			'location_phone_2'  => null, // skip
		);
		foreach ( $yoast_local_map as $yk => $field ) {
			if ( $field && ! empty( $local[ $yk ] ) ) {
				$result[ $field ] = array( 'value' => $local[ $yk ], 'source' => $label . ' Local SEO' );
			}
		}

		return $result;
	}

	private static function source_aioseo() {
		if ( ! defined( 'AIOSEO_VERSION' ) ) {
			return array();
		}

		$opts  = json_decode( (string) get_option( 'aioseo_options', '{}' ), true );
		$label = 'All in One SEO';
		$result = array();

		$name = $opts['searchAppearance']['global']['schema']['organizationName']
			?? $opts['searchAppearance']['global']['schema']['personName']
			?? '';
		if ( $name ) {
			$result['business_name'] = array( 'value' => $name, 'source' => $label );
		}

		$phone = $opts['searchAppearance']['global']['schema']['phone'] ?? '';
		if ( $phone ) {
			$result['phone'] = array( 'value' => $phone, 'source' => $label );
		}

		return $result;
	}

	private static function source_contact_page() {
		$label  = 'Contact Page';
		$result = array();

		// Find contact page: slug contains 'contact', or title contains 'Contact'
		$candidates = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'no_found_rows'  => true,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			's'              => 'contact',
		) );

		// Also try direct slug lookup
		$slug_match = get_page_by_path( 'contact' );
		if ( $slug_match ) {
			array_unshift( $candidates, $slug_match );
		}

		$post   = null;
		$text   = '';

		foreach ( $candidates as $candidate ) {
			if (
				stripos( $candidate->post_name, 'contact' ) !== false ||
				stripos( $candidate->post_title, 'contact' ) !== false
			) {
				$post = $candidate;
				break;
			}
		}

		if ( $post ) {
			$label = 'Contact Page (' . $post->post_title . ')';
			$raw   = $post->post_content;
			// Expand shortcodes so we can read through them, then strip tags
			$raw   = do_shortcode( $raw );
			$text  = wp_strip_all_tags( $raw );

			// Also check page JSON-LD stored by our custom schema writer
			$stored_schema = get_post_meta( $post->ID, '_twtaeo_custom_schemas', true );
			if ( $stored_schema ) {
				$schemas = json_decode( $stored_schema, true );
				foreach ( (array) $schemas as $schema ) {
					$result = array_merge( self::extract_from_schema( $schema, $label ), $result );
				}
			}
		}

		// Fall back to scanning the homepage content
		if ( '' === $text ) {
			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id ) {
				$front = get_post( $front_id );
				if ( $front ) {
					$text  = wp_strip_all_tags( do_shortcode( $front->post_content ) );
					$label = 'Homepage';
				}
			}
		}

		if ( '' === $text ) {
			return $result;
		}

		// Phone
		if ( ! isset( $result['phone'] ) ) {
			preg_match(
				'/(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]\d{3}[-.\s]\d{4}/',
				$text, $m
			);
			if ( ! empty( $m[0] ) ) {
				$result['phone'] = array( 'value' => trim( $m[0] ), 'source' => $label );
			}
		}

		// Email (skip generic WordPress admin emails)
		if ( ! isset( $result['email'] ) ) {
			preg_match(
				'/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
				$text, $m
			);
			if ( ! empty( $m[0] ) ) {
				$result['email'] = array( 'value' => $m[0], 'source' => $label );
			}
		}

		// Street address
		if ( ! isset( $result['street_address'] ) ) {
			preg_match(
				'/\d+\s+[\w\s]+(?:Street|St|Avenue|Ave|Boulevard|Blvd|Road|Rd|Lane|Ln|Drive|Dr|Court|Ct|Circle|Cir|Place|Pl|Way|Terrace|Ter|Parkway|Pkwy|Highway|Hwy)\.?/i',
				$text, $m
			);
			if ( ! empty( $m[0] ) ) {
				$result['street_address'] = array( 'value' => trim( $m[0] ), 'source' => $label );
			}
		}

		// City / State / ZIP  e.g. "Tampa, FL 33601"
		if ( ! isset( $result['city'] ) ) {
			preg_match(
				'/([A-Za-z][\w\s]{1,25}),\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)/',
				$text, $m
			);
			if ( ! empty( $m[1] ) ) {
				$result['city']  = array( 'value' => trim( $m[1] ), 'source' => $label );
				$result['state'] = array( 'value' => $m[2],         'source' => $label );
				$result['zip']   = array( 'value' => $m[3],         'source' => $label );
			}
		}

		// Google Maps URL (in the raw HTML before stripping tags)
		if ( ! isset( $result['maps_url'] ) && $post ) {
			preg_match(
				'/(https?:\/\/(?:maps\.google\.com|goo\.gl\/maps|maps\.app\.goo\.gl)[^\s"\'<>]+)/',
				$post->post_content, $m
			);
			if ( ! empty( $m[1] ) ) {
				$result['maps_url'] = array( 'value' => $m[1], 'source' => $label );
			}
		}

		return $result;
	}

	private static function extract_from_schema( array $schema, $label ) {
		$result = array();
		$addr   = $schema['address'] ?? array();

		if ( ! empty( $addr['streetAddress'] ) ) {
			$result['street_address'] = array( 'value' => $addr['streetAddress'],   'source' => $label . ' (schema)' );
		}
		if ( ! empty( $addr['addressLocality'] ) ) {
			$result['city']  = array( 'value' => $addr['addressLocality'], 'source' => $label . ' (schema)' );
		}
		if ( ! empty( $addr['addressRegion'] ) ) {
			$result['state'] = array( 'value' => $addr['addressRegion'],   'source' => $label . ' (schema)' );
		}
		if ( ! empty( $addr['postalCode'] ) ) {
			$result['zip']   = array( 'value' => $addr['postalCode'],      'source' => $label . ' (schema)' );
		}
		if ( ! empty( $schema['telephone'] ) ) {
			$result['phone'] = array( 'value' => $schema['telephone'],     'source' => $label . ' (schema)' );
		}
		if ( ! empty( $schema['name'] ) ) {
			$result['business_name'] = array( 'value' => $schema['name'],  'source' => $label . ' (schema)' );
		}

		return $result;
	}

	// ── Subtypes ──────────────────────────────────────────────────────────────

	public static function get_subtypes() {
		return array(
			'LocalBusiness'               => 'Local Business (Generic)',
			// Automotive
			'AutomotiveBusiness'          => 'Automotive Business',
			'AutoBodyShop'                => 'Auto Body Shop',
			'AutoDealer'                  => 'Auto Dealer / Car Dealership',
			'AutoPartsStore'              => 'Auto Parts Store',
			'AutoRental'                  => 'Auto Rental',
			'AutoRepair'                  => 'Auto Repair Shop',
			'AutoWash'                    => 'Car Wash',
			'GasStation'                  => 'Gas Station',
			'MotorcycleDealer'            => 'Motorcycle Dealer',
			'MotorcycleRepair'            => 'Motorcycle Repair',
			// Food & Drink
			'FoodEstablishment'           => 'Food Establishment',
			'Bakery'                      => 'Bakery',
			'BarOrPub'                    => 'Bar or Pub',
			'Brewery'                     => 'Brewery',
			'CafeOrCoffeeShop'            => 'Cafe or Coffee Shop',
			'FastFoodRestaurant'          => 'Fast Food Restaurant',
			'IceCreamShop'                => 'Ice Cream Shop',
			'Restaurant'                  => 'Restaurant',
			'Winery'                      => 'Winery',
			// Health & Beauty
			'HealthAndBeautyBusiness'     => 'Health & Beauty Business',
			'BeautySalon'                 => 'Beauty Salon',
			'DaySpa'                      => 'Day Spa',
			'HairSalon'                   => 'Hair Salon',
			'HealthClub'                  => 'Health Club / Gym',
			'NailSalon'                   => 'Nail Salon',
			'TattooParlor'                => 'Tattoo Parlor',
			// Medical
			'MedicalBusiness'             => 'Medical Business',
			'Dentist'                     => 'Dentist',
			'MedicalClinic'               => 'Medical Clinic',
			'Optician'                    => 'Optician / Eye Care',
			'Pharmacy'                    => 'Pharmacy / Drug Store',
			'Physician'                   => 'Physician / Doctor',
			'VeterinaryCare'              => 'Veterinary / Animal Hospital',
			// Home & Construction
			'HomeAndConstructionBusiness' => 'Home & Construction',
			'Electrician'                 => 'Electrician',
			'GeneralContractor'           => 'General Contractor',
			'HVACBusiness'                => 'HVAC',
			'HousePainter'                => 'House Painter',
			'Locksmith'                   => 'Locksmith',
			'MovingCompany'               => 'Moving Company',
			'Plumber'                     => 'Plumber',
			'RoofingContractor'           => 'Roofing Contractor',
			// Legal & Financial
			'LegalService'                => 'Legal Service',
			'Attorney'                    => 'Attorney / Law Firm',
			'Notary'                      => 'Notary',
			'FinancialService'            => 'Financial Service',
			'AccountingService'           => 'Accounting / CPA',
			'BankOrCreditUnion'           => 'Bank or Credit Union',
			'InsuranceAgency'             => 'Insurance Agency',
			// Retail
			'Store'                       => 'Store (Generic)',
			'BikeStore'                   => 'Bike Store',
			'BookStore'                   => 'Book Store',
			'ClothingStore'               => 'Clothing Store',
			'ComputerStore'               => 'Computer Store',
			'ConvenienceStore'            => 'Convenience Store',
			'DepartmentStore'             => 'Department Store',
			'ElectronicsStore'            => 'Electronics Store',
			'Florist'                     => 'Florist / Flower Shop',
			'FurnitureStore'              => 'Furniture Store',
			'GroceryStore'                => 'Grocery Store',
			'HardwareStore'               => 'Hardware Store',
			'JewelryStore'                => 'Jewelry Store',
			'LiquorStore'                 => 'Liquor Store',
			'PetStore'                    => 'Pet Store',
			'ShoeStore'                   => 'Shoe Store',
			'SportingGoodsStore'          => 'Sporting Goods Store',
			'TireShop'                    => 'Tire Shop',
			'ToyStore'                    => 'Toy Store',
			// Lodging
			'LodgingBusiness'             => 'Lodging Business',
			'BedAndBreakfast'             => 'Bed & Breakfast',
			'Campground'                  => 'Campground',
			'Hostel'                      => 'Hostel',
			'Hotel'                       => 'Hotel',
			'Motel'                       => 'Motel',
			'Resort'                      => 'Resort',
			// Entertainment
			'EntertainmentBusiness'       => 'Entertainment Business',
			'AmusementPark'               => 'Amusement Park',
			'ArtGallery'                  => 'Art Gallery',
			'Casino'                      => 'Casino',
			'MovieTheater'                => 'Movie Theater',
			'NightClub'                   => 'Night Club',
			// Sports & Fitness
			'SportsActivityLocation'      => 'Sports Activity Location',
			'BowlingAlley'                => 'Bowling Alley',
			'ExerciseGym'                 => 'Gym / Fitness Center',
			'GolfCourse'                  => 'Golf Course',
			'SportsClub'                  => 'Sports Club',
			'TennisComplex'               => 'Tennis Complex',
			// Professional & Other Services
			'ProfessionalService'         => 'Professional Service',
			'RealEstateAgent'             => 'Real Estate Agent / Agency',
			'TravelAgency'                => 'Travel Agency',
			'EmploymentAgency'            => 'Employment Agency / Staffing',
			'ChildCare'                   => 'Child Care / Daycare',
			'DryCleaningOrLaundry'        => 'Dry Cleaning / Laundry',
			'Library'                     => 'Library',
			'SelfStorage'                 => 'Self Storage',
			'ShoppingCenter'              => 'Shopping Center / Mall',
			'RecyclingCenter'             => 'Recycling Center',
			'EmergencyService'            => 'Emergency Service',
			'InternetCafe'                => 'Internet Cafe',
		);
	}
}
