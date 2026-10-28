<?php
/**
 * Listing Copy Kit — the business details a directory sign-up form asks for,
 * laid out once so the owner pastes the same name, address, phone and
 * description on every directory.
 *
 * Built from what the owner already entered: Local Pack → Business Profile
 * first (it is the local listing data), the Company profile for whatever
 * Local Pack leaves blank. Nothing is invented: a field with no source is
 * left out, and the descriptions are the owner's own text, shortened where
 * a form wants something short. Owners edit before pasting.
 *
 * Pure: no database reads, no WordPress calls — the page passes the data in.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Listing_Copy_Kit {

	/** Short description length — the tighter directory forms sit around here. */
	const SHORT_MAX = 160;

	/** Days in week order, as Local Pack stores them. */
	const DAYS = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	private function __construct() {}

	/**
	 * The kit's fields, in the order sign-up forms usually ask for them.
	 *
	 * @param array  $local      Local Pack settings.
	 * @param array  $company    Company profile.
	 * @param string $type_label Business type as a person would say it ("HVAC Business").
	 * @param string $area_label Translated label for the service-area line.
	 * @return array Field key => value; blank fields are omitted.
	 */
	public static function build( array $local, array $company, $type_label = '', $area_label = 'Service area' ) {
		$name    = self::first( $local, 'business_name', $company, 'name' );
		$phone   = self::first( $local, 'phone', $company, 'phone' );
		$email   = self::first( $local, 'email', $company, 'email' );
		$website = self::first( $local, 'business_url', $company, 'url' );
		$desc    = self::first( $local, 'description', $company, 'description' );
		$area    = self::str( $local, 'area_served' );
		$type    = trim( (string) $type_label );

		$street = self::str( $local, 'street_address' );
		$city   = self::str( $local, 'city' );
		$state  = self::str( $local, 'state' );
		$zip    = self::str( $local, 'zip' );
		$region = trim( implode( ' ', array_filter( array( $state, $zip ), 'strlen' ) ) );
		$line2  = implode( ', ', array_filter( array( $city, $region ), 'strlen' ) );
		$addr   = implode( "\n", array_filter( array( $street, $line2 ), 'strlen' ) );

		$fields = array(
			'name'      => $name,
			'category'  => $type,
			'phone'     => $phone,
			'website'   => $website,
			'email'     => $email,
			'address'   => $addr,
			'street'    => $street,
			'city'      => $city,
			'state'     => $state,
			'zip'       => $zip,
			'area'      => $area,
			'hours'     => self::hours( isset( $local['hours'] ) && is_array( $local['hours'] ) ? $local['hours'] : array() ),
			'short'     => self::short_description( $desc, $name, $type, $city, $state ),
			'long'      => self::long_description( $desc, $area, $area_label ),
			'profiles'  => implode( "\n", self::profiles( $company ) ),
		);
		return array_filter( $fields, 'strlen' );
	}

	/**
	 * Website address tagged so visits from a directory show up as that
	 * directory in analytics.
	 *
	 * @param string $url    Website.
	 * @param string $source Directory id (yelp, angi …).
	 * @return string
	 */
	public static function tracked_url( $url, $source ) {
		$url    = trim( (string) $url );
		$source = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $source ) );
		if ( '' === $url || '' === $source ) {
			return $url;
		}
		$hash = '';
		$pos  = strpos( $url, '#' );
		if ( false !== $pos ) {
			$hash = substr( $url, $pos );
			$url  = substr( $url, 0, $pos );
		}
		$sep = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $sep . 'utm_source=' . $source . '&utm_medium=directory&utm_campaign=listing' . $hash;
	}

	/**
	 * The owner's description cut to whole sentences that fit SHORT_MAX, or
	 * failing that whole words with an ellipsis. With no description at all,
	 * a plain line from the name, type and town — facts the owner entered.
	 */
	public static function short_description( $desc, $name = '', $type = '', $city = '', $state = '' ) {
		$desc = self::squash( $desc );
		if ( '' === $desc ) {
			$where = implode( ', ', array_filter( array( (string) $city, (string) $state ), 'strlen' ) );
			if ( '' === $name || ( '' === $type && '' === $where ) ) {
				return '';
			}
			$line = $name;
			$line .= '' !== $type ? ' — ' . $type : '';
			$line .= '' !== $where ? ( '' !== $type ? ' in ' : ' — ' ) . $where : '';
			return self::fit( $line . '.' );
		}
		return self::fit( $desc );
	}

	/** The owner's full description, with the service area added when the text does not already say it. */
	public static function long_description( $desc, $area = '', $area_label = 'Service area' ) {
		$desc = trim( preg_replace( "/[ \t]+/", ' ', str_replace( "\r", '', (string) $desc ) ) );
		$area = trim( (string) $area );
		if ( '' === $desc ) {
			return '';
		}
		if ( '' !== $area && false === stripos( $desc, $area ) ) {
			$desc = rtrim( $desc ) . "\n\n" . $area_label . ': ' . $area;
		}
		return $desc;
	}

	/**
	 * Opening hours as one line per group: "Monday–Friday: 9:00 AM – 5:00 PM".
	 *
	 * @param array $groups Local Pack hour groups [ days[], opens, closes ].
	 * @return string
	 */
	public static function hours( array $groups ) {
		$lines = array();
		foreach ( $groups as $g ) {
			if ( ! is_array( $g ) || empty( $g['days'] ) || ! isset( $g['opens'], $g['closes'] ) ) {
				continue;
			}
			$lines[] = self::day_span( (array) $g['days'] ) . ': ' . self::clock( $g['opens'] ) . ' – ' . self::clock( $g['closes'] );
		}
		return implode( "\n", $lines );
	}

	/* ─────────────────────────────── helpers ───────────────────────────── */

	/** Consecutive days collapse to a range: Monday–Friday, Saturday. */
	private static function day_span( array $days ) {
		$idx = array();
		foreach ( $days as $d ) {
			$i = array_search( $d, self::DAYS, true );
			if ( false !== $i ) {
				$idx[] = $i;
			}
		}
		sort( $idx );
		$idx  = array_values( array_unique( $idx ) );
		$out  = array();
		$from = null;
		$prev = null;
		foreach ( $idx as $i ) {
			if ( null === $from ) {
				$from = $i;
			} elseif ( $i !== $prev + 1 ) {
				$out[] = self::range( $from, $prev );
				$from  = $i;
			}
			$prev = $i;
		}
		if ( null !== $from ) {
			$out[] = self::range( $from, $prev );
		}
		return implode( ', ', $out );
	}

	private static function range( $a, $b ) {
		if ( $a === $b ) {
			return self::DAYS[ $a ];
		}
		return self::DAYS[ $a ] . ( $b === $a + 1 ? ', ' : '–' ) . self::DAYS[ $b ];
	}

	/** 24-hour "13:30" as "1:30 PM"; anything else as given. */
	private static function clock( $t ) {
		$t = trim( (string) $t );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $t, $m ) || (int) $m[1] > 24 ) {
			return $t;
		}
		$h  = (int) $m[1] % 24;
		$ap = $h >= 12 ? 'PM' : 'AM';
		$h  = 0 === $h % 12 ? 12 : $h % 12;
		return $h . ':' . $m[2] . ' ' . $ap;
	}

	private static function fit( $text ) {
		if ( self::len( $text ) <= self::SHORT_MAX ) {
			return $text;
		}
		// Whole sentences first.
		$parts = preg_split( '/(?<=[.!?])\s+/', $text );
		$out   = '';
		foreach ( (array) $parts as $s ) {
			$try = '' === $out ? $s : $out . ' ' . $s;
			if ( self::len( $try ) > self::SHORT_MAX ) {
				break;
			}
			$out = $try;
		}
		if ( '' !== $out ) {
			return $out;
		}
		$cut = ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::SHORT_MAX - 1, 'UTF-8' ) : substr( $text, 0, self::SHORT_MAX - 1 ) );
		$sp  = strrpos( $cut, ' ' );
		$cut = false !== $sp ? substr( $cut, 0, $sp ) : $cut;
		return rtrim( $cut, " ,;:-" ) . '…';
	}

	/** Length in characters, not bytes: "é" is one. */
	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s );
	}

	private static function profiles( array $company ) {
		$out = array();
		foreach ( $company as $k => $v ) {
			if ( 0 === strpos( (string) $k, 'social_' ) && is_string( $v ) && '' !== trim( $v ) ) {
				$out[] = trim( $v );
			}
		}
		return $out;
	}

	private static function squash( $text ) {
		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	private static function str( array $a, $key ) {
		return isset( $a[ $key ] ) && is_scalar( $a[ $key ] ) ? trim( (string) $a[ $key ] ) : '';
	}

	private static function first( array $a, $ka, array $b, $kb ) {
		$v = self::str( $a, $ka );
		return '' !== $v ? $v : self::str( $b, $kb );
	}
}
