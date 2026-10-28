<?php
/**
 * AI Visibility — directories the engines leaned on (pure half).
 *
 * When an assistant answers "best HVAC company in Tampa" by citing Yelp, or
 * by writing "they have strong reviews on Angi" without a link, the business
 * learns something it can act on: that directory is where the engine looked.
 * A link and a plain mention lead to the same advice — have a complete
 * profile there — so both are counted, and the board says which it was.
 *
 * This never claims the business is or is not listed: we cannot see that.
 * It reports what the engines used, and whether the owner has registered a
 * profile on that directory under Brands (company or brand profile URLs).
 *
 * Directory shape: [ name, hosts[], paths[], names[], signup ]
 *   hosts  — matched exactly or as a subdomain (www.yelp.com, m.yelp.com).
 *   paths  — optional path prefixes for hosts that are not all directory
 *            (google.com/maps is, google.com/search is not).
 *   names  — what an answer calls it in prose. Only names that cannot be
 *            mistaken for an ordinary word: "Clutch.co", never "Clutch".
 *
 * Runs WITHOUT WordPress loaded: `parse_url` not `wp_parse_url`, no esc_*,
 * no __(). Deterministic — no time(), no rand().
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Directories {

	/** Example questions kept per directory — enough to show where, not a transcript. */
	const MAX_EXAMPLES = 3;

	private function __construct() {}

	/**
	 * Every directory we recognise, keyed by a stable id. Sign-up links match
	 * the Local Pack → Entity Validation cards so both screens send the owner
	 * to the same page.
	 *
	 * @return array
	 */
	public static function registry() {
		return array(
			// Universal local listings.
			'google'      => array( 'name' => 'Google Business Profile', 'hosts' => array( 'business.google.com', 'maps.google.com', 'g.page', 'maps.app.goo.gl', 'google.com' ), 'paths' => array( 'google.com' => '/maps' ), 'names' => array( 'Google Business Profile', 'Google My Business', 'Google Maps', 'Google reviews' ), 'signup' => 'https://business.google.com/' ),
			'bing'        => array( 'name' => 'Bing Places', 'hosts' => array( 'bingplaces.com', 'bing.com' ), 'paths' => array( 'bing.com' => '/maps' ), 'names' => array( 'Bing Places', 'Bing Maps' ), 'signup' => 'https://www.bingplaces.com/' ),
			'apple'       => array( 'name' => 'Apple Business Connect', 'hosts' => array( 'maps.apple.com', 'mapsconnect.apple.com', 'businessconnect.apple.com' ), 'paths' => array(), 'names' => array( 'Apple Maps', 'Apple Business Connect' ), 'signup' => 'https://businessconnect.apple.com/' ),
			'yelp'        => array( 'name' => 'Yelp', 'hosts' => array( 'yelp.com', 'yelp.ca', 'yelp.co.uk', 'yelp.com.au' ), 'paths' => array(), 'names' => array( 'Yelp' ), 'signup' => 'https://biz.yelp.com/' ),
			'bbb'         => array( 'name' => 'Better Business Bureau', 'hosts' => array( 'bbb.org' ), 'paths' => array(), 'names' => array( 'Better Business Bureau', 'BBB' ), 'signup' => 'https://www.bbb.org/start-with-trust/' ),
			'foursquare'  => array( 'name' => 'Foursquare', 'hosts' => array( 'foursquare.com' ), 'paths' => array(), 'names' => array( 'Foursquare' ), 'signup' => 'https://business.foursquare.com/' ),
			'nextdoor'    => array( 'name' => 'Nextdoor', 'hosts' => array( 'nextdoor.com' ), 'paths' => array(), 'names' => array( 'Nextdoor' ), 'signup' => 'https://business.nextdoor.com/' ),
			'trustpilot'  => array( 'name' => 'Trustpilot', 'hosts' => array( 'trustpilot.com' ), 'paths' => array(), 'names' => array( 'Trustpilot' ), 'signup' => 'https://business.trustpilot.com/' ),
			// Home services.
			'angi'        => array( 'name' => 'Angi', 'hosts' => array( 'angi.com', 'angieslist.com' ), 'paths' => array(), 'names' => array( 'Angi', "Angie's List", 'Angies List' ), 'signup' => 'https://www.angi.com/pro/signup' ),
			'homeadvisor' => array( 'name' => 'HomeAdvisor', 'hosts' => array( 'homeadvisor.com' ), 'paths' => array(), 'names' => array( 'HomeAdvisor' ), 'signup' => 'https://pro.homeadvisor.com/' ),
			'thumbtack'   => array( 'name' => 'Thumbtack', 'hosts' => array( 'thumbtack.com' ), 'paths' => array(), 'names' => array( 'Thumbtack' ), 'signup' => 'https://www.thumbtack.com/pro' ),
			'houzz'       => array( 'name' => 'Houzz', 'hosts' => array( 'houzz.com' ), 'paths' => array(), 'names' => array( 'Houzz' ), 'signup' => 'https://www.houzz.com/for-professionals' ),
			'buildzoom'   => array( 'name' => 'BuildZoom', 'hosts' => array( 'buildzoom.com' ), 'paths' => array(), 'names' => array( 'BuildZoom' ), 'signup' => 'https://www.buildzoom.com/contractor-signup' ),
			// Legal.
			'avvo'        => array( 'name' => 'Avvo', 'hosts' => array( 'avvo.com' ), 'paths' => array(), 'names' => array( 'Avvo' ), 'signup' => 'https://www.avvo.com/for-lawyers' ),
			'findlaw'     => array( 'name' => 'FindLaw', 'hosts' => array( 'findlaw.com' ), 'paths' => array(), 'names' => array( 'FindLaw' ), 'signup' => 'https://lawyers.findlaw.com/' ),
			'justia'      => array( 'name' => 'Justia', 'hosts' => array( 'justia.com' ), 'paths' => array(), 'names' => array( 'Justia' ), 'signup' => 'https://lawyers.justia.com/' ),
			'martindale'  => array( 'name' => 'Martindale-Hubbell', 'hosts' => array( 'martindale.com' ), 'paths' => array(), 'names' => array( 'Martindale-Hubbell', 'Martindale' ), 'signup' => 'https://www.martindale.com/attorneys/claim-your-profile/' ),
			'superlawyers' => array( 'name' => 'Super Lawyers', 'hosts' => array( 'superlawyers.com' ), 'paths' => array(), 'names' => array( 'Super Lawyers' ), 'signup' => 'https://www.superlawyers.com/lawyers/' ),
			// Healthcare.
			'healthgrades' => array( 'name' => 'Healthgrades', 'hosts' => array( 'healthgrades.com' ), 'paths' => array(), 'names' => array( 'Healthgrades' ), 'signup' => 'https://www.healthgrades.com/office/claim-profile' ),
			'zocdoc'      => array( 'name' => 'Zocdoc', 'hosts' => array( 'zocdoc.com' ), 'paths' => array(), 'names' => array( 'Zocdoc' ), 'signup' => 'https://www.zocdoc.com/about/for-providers/' ),
			'webmd'       => array( 'name' => 'WebMD Care', 'hosts' => array( 'doctor.webmd.com' ), 'paths' => array(), 'names' => array( 'WebMD Care' ), 'signup' => 'https://doctor.webmd.com/physician/claim' ),
			'vitals'      => array( 'name' => 'Vitals', 'hosts' => array( 'vitals.com' ), 'paths' => array(), 'names' => array( 'Vitals.com' ), 'signup' => 'https://www.vitals.com/about/claim_your_profile' ),
			'ratemds'     => array( 'name' => 'RateMDs', 'hosts' => array( 'ratemds.com' ), 'paths' => array(), 'names' => array( 'RateMDs' ), 'signup' => 'https://www.ratemds.com/signup/doctor/' ),
			// Restaurants, travel and lodging.
			'tripadvisor' => array( 'name' => 'Tripadvisor', 'hosts' => array( 'tripadvisor.com', 'tripadvisor.co.uk', 'tripadvisor.ca', 'tripadvisor.com.au' ), 'paths' => array(), 'names' => array( 'Tripadvisor' ), 'signup' => 'https://www.tripadvisor.com/Owners' ),
			'opentable'   => array( 'name' => 'OpenTable', 'hosts' => array( 'opentable.com' ), 'paths' => array(), 'names' => array( 'OpenTable' ), 'signup' => 'https://restaurant.opentable.com/' ),
			'booking'     => array( 'name' => 'Booking.com', 'hosts' => array( 'booking.com' ), 'paths' => array(), 'names' => array( 'Booking.com' ), 'signup' => 'https://partner.booking.com/' ),
			'expedia'     => array( 'name' => 'Expedia', 'hosts' => array( 'expedia.com' ), 'paths' => array(), 'names' => array( 'Expedia' ), 'signup' => 'https://welcome.expediagroup.com/en/solutions' ),
			// Real estate.
			'zillow'      => array( 'name' => 'Zillow', 'hosts' => array( 'zillow.com' ), 'paths' => array(), 'names' => array( 'Zillow' ), 'signup' => 'https://www.zillow.com/agent-resources/agent-toolkit/' ),
			'realtor'     => array( 'name' => 'Realtor.com', 'hosts' => array( 'realtor.com' ), 'paths' => array(), 'names' => array( 'Realtor.com' ), 'signup' => 'https://www.realtor.com/realestateagents/claim/' ),
			'homes'       => array( 'name' => 'Homes.com', 'hosts' => array( 'homes.com' ), 'paths' => array(), 'names' => array( 'Homes.com' ), 'signup' => 'https://agent.homes.com/' ),
			// Automotive.
			'repairpal'   => array( 'name' => 'RepairPal', 'hosts' => array( 'repairpal.com' ), 'paths' => array(), 'names' => array( 'RepairPal' ), 'signup' => 'https://repairpal.com/certified/apply' ),
			'cargurus'    => array( 'name' => 'CarGurus', 'hosts' => array( 'cargurus.com' ), 'paths' => array(), 'names' => array( 'CarGurus' ), 'signup' => 'https://www.cargurus.com/Cars/dealer-signup.action' ),
			'dealerrater' => array( 'name' => 'DealerRater', 'hosts' => array( 'dealerrater.com' ), 'paths' => array(), 'names' => array( 'DealerRater' ), 'signup' => 'https://www.dealerrater.com/' ),
			'carfax'      => array( 'name' => 'CARFAX', 'hosts' => array( 'carfax.com' ), 'paths' => array(), 'names' => array( 'CARFAX' ), 'signup' => 'https://www.carfax.com/service/' ),
			// Beauty and wellness.
			'styleseat'   => array( 'name' => 'StyleSeat', 'hosts' => array( 'styleseat.com' ), 'paths' => array(), 'names' => array( 'StyleSeat' ), 'signup' => 'https://www.styleseat.com/beauty-professionals/' ),
			'booksy'      => array( 'name' => 'Booksy', 'hosts' => array( 'booksy.com' ), 'paths' => array(), 'names' => array( 'Booksy' ), 'signup' => 'https://booksy.com/pro/' ),
			'fresha'      => array( 'name' => 'Fresha', 'hosts' => array( 'fresha.com' ), 'paths' => array(), 'names' => array( 'Fresha' ), 'signup' => 'https://www.fresha.com/for-business' ),
			'vagaro'      => array( 'name' => 'Vagaro', 'hosts' => array( 'vagaro.com' ), 'paths' => array(), 'names' => array( 'Vagaro' ), 'signup' => 'https://www.vagaro.com/pro' ),
			'mindbody'    => array( 'name' => 'Mindbody', 'hosts' => array( 'mindbodyonline.com' ), 'paths' => array(), 'names' => array( 'Mindbody' ), 'signup' => 'https://www.mindbodyonline.com/business' ),
			// Business-to-business and national.
			'clutch'      => array( 'name' => 'Clutch', 'hosts' => array( 'clutch.co' ), 'paths' => array(), 'names' => array( 'Clutch.co' ), 'signup' => 'https://clutch.co/get-listed' ),
			'g2'          => array( 'name' => 'G2', 'hosts' => array( 'g2.com' ), 'paths' => array(), 'names' => array( 'G2.com' ), 'signup' => 'https://sell.g2.com/' ),
			'capterra'    => array( 'name' => 'Capterra', 'hosts' => array( 'capterra.com' ), 'paths' => array(), 'names' => array( 'Capterra' ), 'signup' => 'https://www.capterra.com/vendors/' ),
			'thomasnet'   => array( 'name' => 'Thomasnet', 'hosts' => array( 'thomasnet.com' ), 'paths' => array(), 'names' => array( 'Thomasnet' ), 'signup' => 'https://www.thomasnet.com/' ),
		);
	}

	/**
	 * Which directory a cited URL belongs to.
	 *
	 * @param mixed $url Cited URL.
	 * @return string Directory id, or '' for none.
	 */
	public static function for_url( $url ) {
		$parts = self::host_path( $url );
		if ( null === $parts ) {
			return '';
		}
		foreach ( self::registry() as $id => $dir ) {
			foreach ( $dir['hosts'] as $h ) {
				if ( $parts['host'] !== $h && ! self::ends_with( $parts['host'], '.' . $h ) ) {
					continue;
				}
				if ( isset( $dir['paths'][ $h ] ) && $parts['host'] === $h ) {
					$want = $dir['paths'][ $h ];
					if ( $parts['path'] !== $want && 0 !== strpos( $parts['path'], $want . '/' ) ) {
						continue;
					}
				}
				return $id;
			}
		}
		return '';
	}

	/**
	 * Directories one answer named in its text.
	 *
	 * @param string $text Answer text.
	 * @return string[] Directory ids.
	 */
	public static function named_in( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) || ! class_exists( 'TWTAEO_Visibility_Verdict' ) ) {
			return array();
		}
		$out = array();
		foreach ( self::registry() as $id => $dir ) {
			foreach ( $dir['names'] as $name ) {
				if ( TWTAEO_Visibility_Verdict::mentions_name( $text, $name ) ) {
					$out[] = $id;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Every directory the engines used in a set of checks, most-used first.
	 *
	 * Counted once per answer: an answer that both links Yelp and says "Yelp"
	 * counts as one cited use. A directory where the owner registered a
	 * profile is still listed, flagged `have_profile`, because the engines
	 * reading it is reason to keep it complete — it just sorts last.
	 *
	 * @param array $checks     Checks (and journey turns) with engine, question_id, cited_urls, answer.
	 * @param array $questions  Run questions, for example wording.
	 * @param array $properties Owned properties (TWTAEO_Visibility_Brands shape).
	 * @return array Rows of [ id, name, signup, answers, cited, named, engines[], examples[], have_profile ].
	 */
	public static function used_in( array $checks, array $questions, array $properties ) {
		$q_text = array();
		foreach ( $questions as $q ) {
			if ( is_array( $q ) && isset( $q['id'], $q['text'] ) ) {
				$q_text[ (string) $q['id'] ] = (string) $q['text'];
			}
		}
		$registry = self::registry();
		$rows     = array();
		foreach ( $checks as $c ) {
			if ( ! is_array( $c ) || ( isset( $c['verdict'] ) && 'unavailable' === $c['verdict'] ) ) {
				continue;
			}
			$hits = array();
			foreach ( isset( $c['cited_urls'] ) ? (array) $c['cited_urls'] : array() as $u ) {
				$id = self::for_url( $u );
				if ( '' !== $id ) {
					$hits[ $id ] = 'cited';
				}
			}
			foreach ( self::named_in( isset( $c['answer'] ) ? $c['answer'] : '' ) as $id ) {
				if ( ! isset( $hits[ $id ] ) ) {
					$hits[ $id ] = 'named';
				}
			}
			$engine   = isset( $c['engine'] ) ? (string) $c['engine'] : '';
			$question = isset( $c['question_id'], $q_text[ (string) $c['question_id'] ] ) ? $q_text[ (string) $c['question_id'] ] : '';
			if ( '' === $question && isset( $c['asked'] ) ) {
				$question = (string) $c['asked'];
			}
			foreach ( $hits as $id => $how ) {
				if ( ! isset( $rows[ $id ] ) ) {
					$rows[ $id ] = array(
						'id'           => $id,
						'name'         => $registry[ $id ]['name'],
						'signup'       => $registry[ $id ]['signup'],
						'answers'      => 0,
						'cited'        => 0,
						'named'        => 0,
						'engines'      => array(),
						'examples'     => array(),
						'have_profile' => self::has_profile( $id, $properties ),
					);
				}
				++$rows[ $id ]['answers'];
				++$rows[ $id ][ $how ];
				if ( '' !== $engine && ! in_array( $engine, $rows[ $id ]['engines'], true ) ) {
					$rows[ $id ]['engines'][] = $engine;
				}
				if ( '' !== $question && count( $rows[ $id ]['examples'] ) < self::MAX_EXAMPLES && ! in_array( $question, $rows[ $id ]['examples'], true ) ) {
					$rows[ $id ]['examples'][] = $question;
				}
			}
		}
		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['have_profile'] !== $b['have_profile'] ) {
					return $a['have_profile'] ? 1 : -1;
				}
				if ( $a['answers'] !== $b['answers'] ) {
					return $b['answers'] - $a['answers'];
				}
				return strcmp( $a['id'], $b['id'] );
			}
		);
		return $rows;
	}

	/**
	 * Has the owner registered a page on this directory (company or brand profile)?
	 *
	 * @param string $id         Directory id.
	 * @param array  $properties Owned properties.
	 * @return bool
	 */
	public static function has_profile( $id, array $properties ) {
		foreach ( $properties as $p ) {
			if ( ! is_array( $p ) || empty( $p['host'] ) ) {
				continue;
			}
			$url = 'https://' . $p['host'] . ( isset( $p['path'] ) ? (string) $p['path'] : '' );
			if ( self::for_url( $url ) === $id ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────────── helpers ───────────────────────────── */

	/** Lowercase host without www, and lowercase path without a trailing slash; null when unusable. */
	private static function host_path( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return null;
		}
		$raw   = trim( $url );
		$raw   = preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $raw ) ? $raw : 'https://' . $raw;
		$parts = parse_url( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, WP is not loaded.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = preg_replace( '/^www\./', '', strtolower( rtrim( $parts['host'], '.' ) ) );
		$path = isset( $parts['path'] ) ? rtrim( strtolower( (string) $parts['path'] ), '/' ) : '';
		return array( 'host' => (string) $host, 'path' => $path );
	}

	private static function ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return $len <= strlen( $haystack ) && substr( $haystack, -$len ) === $needle;
	}
}
