<?php
/**
 * AI Visibility — where a run searches from.
 *
 * The consumer assistants know roughly where the person asking is, and
 * grounded search results change with it. An API call carries no such
 * signal: without a location the search runs wherever the provider's
 * infrastructure is, which is not where a Tampa HVAC company's customers
 * are. So every run can carry a location, and by default it carries the
 * business's own (Local Pack address), or the store's country.
 *
 * It reaches the engines two ways:
 *  - a real search location (`user_location`) on the engines whose APIs
 *    document one: Claude, ChatGPT and Perplexity, and DeepSeek through its
 *    Anthropic-compatible API (unconfirmed there; a rejection falls back);
 *  - a context line in the system instructions for every engine, where the
 *    persona goes, the way the apps know where their user is. Gemini, Grok
 *    and Le Chat document no search location, so for them this line is the
 *    whole of it and the board calls their location approximate.
 * The question itself is never changed.
 *
 * A location is { country (ISO 3166-1 alpha-2), region, city, lat, lng,
 * source }. Only country is required.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Location {

	/** Engines whose search API takes a location. */
	const SEARCH_ENGINES = array( 'claude', 'chatgpt', 'perplexity', 'deepseek', 'meta' );

	/** Where a location came from. */
	const SOURCES = array( 'owner', 'local_pack', 'store', 'language' );

	/** Countries, for sites without WooCommerce (which has its own list). ISO 3166-1 alpha-2. */
	const COUNTRIES = array(
		'AF' => 'Afghanistan', 'AX' => 'Åland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa', 'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan',
		'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BQ' => 'Bonaire, Sint Eustatius and Saba', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BV' => 'Bouvet Island', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
		'CV' => 'Cabo Verde', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CX' => 'Christmas Island', 'CC' => 'Cocos (Keeling) Islands', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo (Democratic Republic)', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'CI' => "Côte d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba', 'CW' => 'Curaçao', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
		'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia',
		'FK' => 'Falkland Islands', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia', 'TF' => 'French Southern Territories', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
		'HT' => 'Haiti', 'HM' => 'Heard Island and McDonald Islands', 'VA' => 'Holy See', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle of Man', 'IL' => 'Israel', 'IT' => 'Italy',
		'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => 'North Korea', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan',
		'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
		'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar',
		'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island', 'MK' => 'North Macedonia', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway',
		'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico', 'QA' => 'Qatar',
		'RE' => 'Réunion', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'BL' => 'Saint Barthélemy', 'SH' => 'Saint Helena', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia', 'MF' => 'Saint Martin', 'PM' => 'Saint Pierre and Miquelon', 'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SX' => 'Sint Maarten', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'GS' => 'South Georgia and the South Sandwich Islands', 'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SJ' => 'Svalbard and Jan Mayen', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
		'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Türkiye', 'TM' => 'Turkmenistan', 'TC' => 'Turks and Caicos Islands', 'TV' => 'Tuvalu',
		'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States', 'UM' => 'United States Minor Outlying Islands', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'VG' => 'Virgin Islands (British)', 'VI' => 'Virgin Islands (U.S.)', 'WF' => 'Wallis and Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
	);

	/**
	 * Regions for the English-first markets clients most often sell into, for
	 * sites without WooCommerce (which has its own state lists). Everywhere
	 * else the region is typed. Code => name; the name is what the APIs are sent.
	 */
	const REGIONS = array(
		'US' => array(
			'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'PR' => 'Puerto Rico',
		),
		'CA' => array(
			'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon',
		),
		'AU' => array(
			'ACT' => 'Australian Capital Territory', 'NSW' => 'New South Wales', 'NT' => 'Northern Territory', 'QLD' => 'Queensland', 'SA' => 'South Australia', 'TAS' => 'Tasmania', 'VIC' => 'Victoria', 'WA' => 'Western Australia',
		),
		'IN' => array(
			'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh', 'AS' => 'Assam', 'BR' => 'Bihar', 'CT' => 'Chhattisgarh', 'GA' => 'Goa', 'GJ' => 'Gujarat', 'HR' => 'Haryana', 'HP' => 'Himachal Pradesh', 'JH' => 'Jharkhand', 'KA' => 'Karnataka', 'KL' => 'Kerala', 'MP' => 'Madhya Pradesh', 'MH' => 'Maharashtra', 'MN' => 'Manipur', 'ML' => 'Meghalaya', 'MZ' => 'Mizoram', 'NL' => 'Nagaland', 'OR' => 'Odisha', 'PB' => 'Punjab', 'RJ' => 'Rajasthan', 'SK' => 'Sikkim', 'TN' => 'Tamil Nadu', 'TG' => 'Telangana', 'TR' => 'Tripura', 'UP' => 'Uttar Pradesh', 'UK' => 'Uttarakhand', 'WB' => 'West Bengal', 'AN' => 'Andaman and Nicobar Islands', 'CH' => 'Chandigarh', 'DN' => 'Dadra and Nagar Haveli and Daman and Diu', 'DL' => 'Delhi', 'JK' => 'Jammu and Kashmir', 'LA' => 'Ladakh', 'LD' => 'Lakshadweep', 'PY' => 'Puducherry',
		),
		'GB' => array(
			'ENG' => 'England', 'SCT' => 'Scotland', 'WLS' => 'Wales', 'NIR' => 'Northern Ireland',
		),
		'IE' => array(
			'CW' => 'Carlow', 'CN' => 'Cavan', 'CE' => 'Clare', 'CO' => 'Cork', 'DL' => 'Donegal', 'D' => 'Dublin', 'G' => 'Galway', 'KY' => 'Kerry', 'KE' => 'Kildare', 'KK' => 'Kilkenny', 'LS' => 'Laois', 'LM' => 'Leitrim', 'LK' => 'Limerick', 'LD' => 'Longford', 'LH' => 'Louth', 'MO' => 'Mayo', 'MH' => 'Meath', 'MN' => 'Monaghan', 'OY' => 'Offaly', 'RN' => 'Roscommon', 'SO' => 'Sligo', 'TA' => 'Tipperary', 'WD' => 'Waterford', 'WH' => 'Westmeath', 'WX' => 'Wexford', 'WW' => 'Wicklow',
		),
		'NZ' => array(
			'NTL' => 'Northland', 'AUK' => 'Auckland', 'WKO' => 'Waikato', 'BOP' => 'Bay of Plenty', 'GIS' => 'Gisborne', 'HKB' => "Hawke's Bay", 'TKI' => 'Taranaki', 'MWT' => 'Manawatū-Whanganui', 'WGN' => 'Wellington', 'TAS' => 'Tasman', 'NSN' => 'Nelson', 'MBH' => 'Marlborough', 'WTC' => 'West Coast', 'CAN' => 'Canterbury', 'OTA' => 'Otago', 'STL' => 'Southland',
		),
		'SG' => array(
			'CR' => 'Central Region', 'ER' => 'East Region', 'NR' => 'North Region', 'NER' => 'North-East Region', 'WR' => 'West Region',
		),
		'ZA' => array(
			'EC' => 'Eastern Cape', 'FS' => 'Free State', 'GP' => 'Gauteng', 'KZN' => 'KwaZulu-Natal', 'LP' => 'Limpopo', 'MP' => 'Mpumalanga', 'NW' => 'North West', 'NC' => 'Northern Cape', 'WC' => 'Western Cape',
		),
	);

	/** US states → the time zone most of the state keeps, for the APIs that take one. */
	const US_TIMEZONES = array(
		'Alabama' => 'America/Chicago', 'Alaska' => 'America/Anchorage', 'Arizona' => 'America/Phoenix', 'Arkansas' => 'America/Chicago', 'California' => 'America/Los_Angeles', 'Colorado' => 'America/Denver', 'Connecticut' => 'America/New_York', 'Delaware' => 'America/New_York', 'District of Columbia' => 'America/New_York', 'Florida' => 'America/New_York', 'Georgia' => 'America/New_York', 'Hawaii' => 'Pacific/Honolulu', 'Idaho' => 'America/Boise', 'Illinois' => 'America/Chicago', 'Indiana' => 'America/Indiana/Indianapolis', 'Iowa' => 'America/Chicago', 'Kansas' => 'America/Chicago', 'Kentucky' => 'America/New_York', 'Louisiana' => 'America/Chicago', 'Maine' => 'America/New_York', 'Maryland' => 'America/New_York', 'Massachusetts' => 'America/New_York', 'Michigan' => 'America/Detroit', 'Minnesota' => 'America/Chicago', 'Mississippi' => 'America/Chicago', 'Missouri' => 'America/Chicago', 'Montana' => 'America/Denver', 'Nebraska' => 'America/Chicago', 'Nevada' => 'America/Los_Angeles', 'New Hampshire' => 'America/New_York', 'New Jersey' => 'America/New_York', 'New Mexico' => 'America/Denver', 'New York' => 'America/New_York', 'North Carolina' => 'America/New_York', 'North Dakota' => 'America/Chicago', 'Ohio' => 'America/New_York', 'Oklahoma' => 'America/Chicago', 'Oregon' => 'America/Los_Angeles', 'Pennsylvania' => 'America/New_York', 'Rhode Island' => 'America/New_York', 'South Carolina' => 'America/New_York', 'South Dakota' => 'America/Chicago', 'Tennessee' => 'America/Chicago', 'Texas' => 'America/Chicago', 'Utah' => 'America/Denver', 'Vermont' => 'America/New_York', 'Virginia' => 'America/New_York', 'Washington' => 'America/Los_Angeles', 'West Virginia' => 'America/New_York', 'Wisconsin' => 'America/Chicago', 'Wyoming' => 'America/Denver', 'Puerto Rico' => 'America/Puerto_Rico',
	);

	private function __construct() {}

	/* ─────────────────────────── lists ─────────────────────────── */

	/** Country code => name: WooCommerce's list when it is active, else the bundled one. */
	public static function countries() {
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && isset( $wc->countries ) && is_object( $wc->countries ) && method_exists( $wc->countries, 'get_countries' ) ) {
				$list = array();
				foreach ( (array) $wc->countries->get_countries() as $code => $name ) {
					$list[ strtoupper( (string) $code ) ] = html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
				}
				if ( $list ) {
					return $list;
				}
			}
		}
		return self::COUNTRIES;
	}

	/** Region code => name for a country; empty when the region is typed. */
	public static function regions( $country ) {
		$country = strtoupper( (string) $country );
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && isset( $wc->countries ) && is_object( $wc->countries ) && method_exists( $wc->countries, 'get_states' ) ) {
				$states = $wc->countries->get_states( $country );
				if ( is_array( $states ) && $states ) {
					return array_map(
						static function ( $n ) {
							return html_entity_decode( (string) $n, ENT_QUOTES, 'UTF-8' );
						},
						$states
					);
				}
			}
		}
		return isset( self::REGIONS[ $country ] ) ? self::REGIONS[ $country ] : array();
	}

	/* ─────────────────────────── values ─────────────────────────── */

	/**
	 * A location as stored and sent, or null when there is none (no country).
	 * A region given as its code ("FL") is stored as its name ("Florida").
	 *
	 * @param mixed $raw
	 * @return array|null
	 */
	public static function clean( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$country   = strtoupper( sanitize_text_field( isset( $raw['country'] ) ? (string) $raw['country'] : '' ) );
		$countries = self::countries();
		if ( ! isset( $countries[ $country ] ) ) {
			return null;
		}
		$region  = self::short_text( isset( $raw['region'] ) ? $raw['region'] : '' );
		$regions = self::regions( $country );
		if ( '' !== $region && isset( $regions[ strtoupper( $region ) ] ) ) {
			$region = $regions[ strtoupper( $region ) ];
		}
		$loc = array(
			'country' => $country,
			'region'  => $region,
			'city'    => self::short_text( isset( $raw['city'] ) ? $raw['city'] : '' ),
			'source'  => isset( $raw['source'] ) && in_array( $raw['source'], self::SOURCES, true ) ? (string) $raw['source'] : 'owner',
		);
		$lat = isset( $raw['lat'] ) ? $raw['lat'] : ( isset( $raw['latitude'] ) ? $raw['latitude'] : '' );
		$lng = isset( $raw['lng'] ) ? $raw['lng'] : ( isset( $raw['longitude'] ) ? $raw['longitude'] : '' );
		if ( is_numeric( $lat ) && is_numeric( $lng ) && abs( (float) $lat ) <= 90 && abs( (float) $lng ) <= 180 && ( 0.0 !== (float) $lat || 0.0 !== (float) $lng ) ) {
			$loc['lat'] = round( (float) $lat, 4 );
			$loc['lng'] = round( (float) $lng, 4 );
		}
		return $loc;
	}

	private static function short_text( $v ) {
		$v = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $v ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 80 ) : substr( $v, 0, 80 );
	}

	/**
	 * Where a run searches from when the owner has not said: the Local Pack
	 * address for a local business, the store's country for a store, the
	 * site language's country otherwise, or null.
	 *
	 * @return array|null
	 */
	public static function site_default() {
		if ( class_exists( 'TWTAEO_Local_Pack' ) ) {
			$lp = (array) TWTAEO_Local_Pack::get_settings();
			if ( ! empty( $lp['city'] ) ) {
				$loc = self::clean(
					array(
						'country'   => ! empty( $lp['country'] ) ? $lp['country'] : 'US',
						'region'    => isset( $lp['state'] ) ? $lp['state'] : '',
						'city'      => $lp['city'],
						'latitude'  => isset( $lp['latitude'] ) ? $lp['latitude'] : '',
						'longitude' => isset( $lp['longitude'] ) ? $lp['longitude'] : '',
						'source'    => 'local_pack',
					)
				);
				if ( $loc ) {
					return $loc;
				}
			}
		}
		// A store sells beyond its own town: its country, not its city.
		if ( function_exists( 'wc_get_base_location' ) ) {
			$base = wc_get_base_location();
			if ( ! empty( $base['country'] ) ) {
				$loc = self::clean( array( 'country' => $base['country'], 'source' => 'store' ) );
				if ( $loc ) {
					return $loc;
				}
			}
		}
		$locale = (string) get_locale();
		if ( preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', $locale, $m ) ) {
			return self::clean( array( 'country' => $m[1], 'source' => 'language' ) );
		}
		return null;
	}

	/** "Tampa, Florida, United States"; '' for no location. */
	public static function label( $loc ) {
		if ( ! is_array( $loc ) || empty( $loc['country'] ) ) {
			return '';
		}
		$countries = self::countries();
		$parts     = array_filter(
			array(
				isset( $loc['city'] ) ? $loc['city'] : '',
				isset( $loc['region'] ) ? $loc['region'] : '',
				isset( $countries[ $loc['country'] ] ) ? $countries[ $loc['country'] ] : $loc['country'],
			),
			'strlen'
		);
		return implode( ', ', $parts );
	}

	/**
	 * What two runs must share to be compared: '' for no location. Source is
	 * left out on purpose: Tampa is Tampa whether typed or taken from Local Pack.
	 */
	public static function key( $loc ) {
		if ( ! is_array( $loc ) || empty( $loc['country'] ) ) {
			return '';
		}
		return strtolower( $loc['country'] . '|' . ( isset( $loc['region'] ) ? $loc['region'] : '' ) . '|' . ( isset( $loc['city'] ) ? $loc['city'] : '' ) );
	}

	/** "User location: The user is in Tampa, Florida, United States." '' for none. */
	public static function context_line( $loc ) {
		$label = self::label( $loc );
		return '' === $label ? '' : 'User location: The user is in ' . $label . '.';
	}

	/** An IANA time zone when one follows from the location, else ''. */
	public static function timezone( $loc ) {
		if ( ! is_array( $loc ) || empty( $loc['country'] ) ) {
			return '';
		}
		if ( 'US' === $loc['country'] && ! empty( $loc['region'] ) && isset( self::US_TIMEZONES[ $loc['region'] ] ) ) {
			return self::US_TIMEZONES[ $loc['region'] ];
		}
		$zones = DateTimeZone::listIdentifiers( DateTimeZone::PER_COUNTRY, $loc['country'] );
		return 1 === count( $zones ) ? (string) $zones[0] : '';
	}

	/**
	 * The location as a search API expects it.
	 *
	 * @param array  $loc
	 * @param string $style anthropic (also DeepSeek) | openai | perplexity | meta
	 * @return array
	 */
	public static function for_api( array $loc, $style ) {
		if ( 'meta' === $style ) {
			// Meta's documented shape: OpenAI's fields without `type`.
			$out = self::for_api( $loc, 'openai' );
			unset( $out['type'] );
			return $out;
		}
		if ( 'perplexity' === $style ) {
			$out = array( 'country' => $loc['country'] );
			if ( ! empty( $loc['region'] ) ) {
				$out['region'] = $loc['region'];
			}
			if ( ! empty( $loc['city'] ) ) {
				$out['city'] = $loc['city'];
			}
			// Perplexity accepts coordinates only together with country.
			if ( isset( $loc['lat'], $loc['lng'] ) ) {
				$out['latitude']  = $loc['lat'];
				$out['longitude'] = $loc['lng'];
			}
			return $out;
		}
		$out = array(
			'type'    => 'approximate',
			'country' => $loc['country'],
		);
		if ( ! empty( $loc['region'] ) ) {
			$out['region'] = $loc['region'];
		}
		if ( ! empty( $loc['city'] ) ) {
			$out['city'] = $loc['city'];
		}
		$tz = self::timezone( $loc );
		if ( '' !== $tz ) {
			$out['timezone'] = $tz;
		}
		return $out;
	}
}
