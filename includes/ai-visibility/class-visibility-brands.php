<?php
/**
 * AI Visibility — owned properties and tracked brand items (pure half).
 *
 * The verdict used to ask one question: "is the cited URL on our domain?".
 * That reads a citation of our own LinkedIn page as somebody else's — and
 * worse, `share_of_voice()` then files it under competitors. This class
 * teaches the module the difference between "nobody cited us" and "we were
 * cited somewhere that is also us".
 *
 * Two registries, matching how a merchant thinks about it:
 *
 *   company items — the business itself: its hosts and the profiles it owns
 *                   (LinkedIn company page, YouTube channel, Wikipedia entry).
 *   brand items   — a product or sub-brand tracked in its own right: a phrase
 *                   to watch for, plus whatever that brand owns — its OWN
 *                   WEBSITE as a whole domain and/or pages on shared
 *                   platforms. "AEO
 *                   Ultimate" can have aeoultimate.com AND a LinkedIn page
 *                   and no X account;
 *                   the empty one is simply not tracked, never a false gap.
 *
 * Property shape:  [ url, host, path, kind ('site'|'profile'), owner, label ]
 * Brand shape:     [ label, phrases[], urls[], enabled ]
 * Phrase shape:    [ text, exact ]  — `"in quotes"` is the exact phrase.
 *
 * Runs WITHOUT WordPress loaded: `parse_url` not `wp_parse_url`, no esc_*,
 * no __(). Deterministic — no time(), no rand().
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Brands {

	/** Tracked brand items a site may keep. */
	const MAX_ITEMS = 25;

	/** Phrases and owned URLs per item — a brand needs a handful, not a corpus. */
	const MAX_PHRASES = 12;
	const MAX_URLS    = 12;

	/** Shortest word that carries meaning in a loose phrase match ("of" does not). */
	const MIN_WORD = 3;

	/**
	 * Hosts where a page belongs to whoever claimed the handle, so owning a page
	 * there is never owning the host. A property on one of these MUST carry a
	 * path: accepting bare `linkedin.com` would book every LinkedIn citation on
	 * the internet as ours.
	 *
	 * Everywhere else, a bare domain is taken at face value — a brand with its
	 * own website (aeoultimate.com) owns the whole host, and making somebody
	 * invent a path for it would be nonsense.
	 */
	const SHARED_HOSTS = array(
		'linkedin.com', 'x.com', 'twitter.com', 'facebook.com', 'fb.com',
		'instagram.com', 'threads.net', 'youtube.com', 'youtu.be', 'tiktok.com',
		'pinterest.com', 'reddit.com', 'tumblr.com', 'bsky.app', 'mastodon.social',
		'medium.com', 'substack.com', 'wordpress.com', 'blogger.com', 'wixsite.com',
		'github.com', 'gitlab.com', 'bitbucket.org', 'stackoverflow.com',
		'wordpress.org', 'npmjs.com', 'packagist.org', 'producthunt.com',
		'crunchbase.com', 'wikipedia.org', 'wikidata.org', 'quora.com',
		'g2.com', 'capterra.com', 'trustpilot.com', 'getapp.com', 'softwareadvice.com',
		'yelp.com', 'glassdoor.com', 'indeed.com', 'clutch.co', 'sitejabber.com',
		'trustradius.com', 'appsumo.com', 'gumroad.com', 'patreon.com', 'ko-fi.com',
		'etsy.com', 'ebay.com', 'amazon.com', 'shopify.com', 'apps.apple.com',
		'play.google.com', 'microsoft.com', 'discord.com', 'discord.gg', 'slack.com',
		'twitch.tv', 'vimeo.com', 'soundcloud.com', 'spotify.com', 'behance.net',
		'dribbble.com', 'wellfound.com', 'angel.co', 'meetup.com', 'eventbrite.com',
	);

	/*
	 * Deliberately NOT listed: the PaaS and static-hosting apexes (netlify.app,
	 * vercel.app, pages.dev, herokuapp.com, github.io, gitlab.io, firebaseapp.com,
	 * notion.site, gitbook.io, readthedocs.io). On those a SUBDOMAIN is somebody's
	 * own site, and subdomains were never caught by this list anyway — matching is
	 * exact — so their only effect was to refuse a bare apex nobody owns or cites.
	 * Keeping them also tripped PluginCheck's Offloading sniff, which reads a list
	 * of hosting domains as an intent to serve assets from them.
	 */

	/** Where a citation of ours landed. */
	const SURFACE_SITE    = 'site';
	const SURFACE_PROFILE = 'profile';
	const SURFACE_BOTH    = 'both';

	private function __construct() {}

	/* ───────────────────────────── properties ──────────────────────────── */

	/**
	 * A pasted URL becomes a matchable property, or null when it cannot be one.
	 *
	 * On a shared platform the path is what makes a page ours:
	 * `linkedin.com/company/aeo-ultimate` is us, `linkedin.com/company/somebody-else`
	 * is not, so a bare `linkedin.com` is refused — claiming it would book every
	 * LinkedIn citation on earth as our own, inflating the score just as
	 * dishonestly as the host-only rule used to deflate it.
	 *
	 * Anywhere else a bare domain is accepted whole and comes back as kind
	 * 'site'. A brand routinely owns its own website (a product site, a
	 * campaign domain); demanding a path for it would be nonsense, and every
	 * page under it is legitimately ours.
	 *
	 * Paths are compared lowercased: `/company/AEO-Ultimate` and
	 * `/company/aeo-ultimate` are one page, and a false miss here would
	 * under-count a citation we actually earned.
	 *
	 * @param mixed  $url   URL as typed.
	 * @param string $kind  'profile' (needs a path) or 'site' (host-level, ours already).
	 * @param string $owner 'company' or a brand label.
	 * @return array|null Property, or null when the value is unusable.
	 */
	public static function normalize_property( $url, $kind = 'profile', $owner = 'company' ) {
		$raw = trim( self::to_string( $url ) );
		if ( '' === $raw ) {
			return null;
		}
		$has_scheme = (bool) preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $raw );
		$candidate  = $has_scheme ? $raw : 'https://' . $raw;
		$parts      = parse_url( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, WP is not loaded.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if ( ! preg_match( '/^[a-z0-9\-._\x80-\xff]+$/', $host ) ) {
			return null;
		}
		$host = preg_replace( '/^www\./', '', $host );
		$host = preg_replace( '/\.$/', '', $host );

		$path = isset( $parts['path'] ) ? strtolower( (string) $parts['path'] ) : '';
		$path = rtrim( $path, '/' );
		if ( '/' === $path ) {
			$path = '';
		}
		if ( 'profile' === $kind && '' === $path ) {
			// A whole host was given. Only the shared platforms can refuse it.
			if ( self::is_shared_host( $host ) ) {
				return null;
			}
			$kind = 'site';
		}
		return array(
			'url'   => $raw,
			'host'  => $host,
			'path'  => $path,
			'kind'  => ( 'site' === $kind || '' === $path ) ? 'site' : 'profile',
			'owner' => self::to_string( $owner ),
		);
	}

	/**
	 * Is this a host where pages belong to whoever claimed the handle?
	 * Exact match after www-stripping; `foo.github.io` is somebody's own site
	 * and is deliberately NOT caught by the `github.io` entry.
	 *
	 * @param string $host Normalized host.
	 * @return bool
	 */
	public static function is_shared_host( $host ) {
		$h = strtolower( trim( self::to_string( $host ) ) );
		$h = preg_replace( '/^www\./', '', $h );
		$h = preg_replace( '/\.$/', '', (string) $h );
		return in_array( $h, self::SHARED_HOSTS, true );
	}

	/**
	 * Why `normalize_property()` refused, phrased for the person who pasted it.
	 * Returns '' when the URL is fine.
	 *
	 * @param mixed $url URL as typed.
	 * `needs_path` fires ONLY for the shared platforms — a bare domain anywhere
	 * else is a legitimate whole-host property and is accepted as kind 'site'.
	 *
	 * @return string Reason key: '', 'empty', 'malformed', 'needs_path'.
	 */
	public static function property_problem( $url ) {
		$raw = trim( self::to_string( $url ) );
		if ( '' === $raw ) {
			return 'empty';
		}
		if ( null !== self::normalize_property( $raw, 'profile' ) ) {
			return '';
		}
		// It parsed as a host but carried no path: a whole-host claim.
		if ( null !== self::normalize_property( $raw, 'site' ) ) {
			return 'needs_path';
		}
		return 'malformed';
	}

	/**
	 * Does this cited URL land on that property?
	 *
	 * Host must match (www-insensitive); the path must be the property's path or
	 * sit beneath it, so `/company/aeo-ultimate/posts` counts and
	 * `/company/aeo-ultimate-clone` does not. Query strings are ignored — the
	 * assistants append their own (`utm_source=chatgpt.com`).
	 *
	 * @param mixed $cited_url URL from the answer.
	 * @param array $property  Normalized property.
	 * @return bool
	 */
	public static function property_matches( $cited_url, array $property ) {
		if ( empty( $property['host'] ) ) {
			return false;
		}
		$cited = self::normalize_property( $cited_url, 'site' );
		if ( null === $cited ) {
			return false;
		}
		if ( $cited['host'] !== $property['host'] ) {
			return false;
		}
		$want = isset( $property['path'] ) ? (string) $property['path'] : '';
		if ( '' === $want ) {
			return true;
		}
		$have = $cited['path'];
		return $have === $want || 0 === strpos( $have, $want . '/' );
	}

	/**
	 * The properties a cited URL belongs to.
	 *
	 * @param mixed $cited_url  URL from the answer.
	 * @param array $properties Property[].
	 * @return array Matching Property[].
	 */
	public static function properties_for( $cited_url, array $properties ) {
		$out = array();
		foreach ( $properties as $p ) {
			if ( is_array( $p ) && self::property_matches( $cited_url, $p ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/* ─────────────────────────────── phrases ───────────────────────────── */

	/**
	 * `"AEO Ultimate"` → exact phrase; `AEO Ultimate` → every word, any order.
	 * Straight and curly quotes both count — people paste from documents.
	 *
	 * @param mixed $raw Phrase as typed.
	 * @return array|null [ text, exact ], or null when nothing is left.
	 */
	public static function parse_phrase( $raw ) {
		$text = trim( self::to_string( $raw ) );
		if ( '' === $text ) {
			return null;
		}
		$exact = false;
		$pairs = array(
			array( '"', '"' ),
			array( "\xe2\x80\x9c", "\xe2\x80\x9d" ),
		);
		foreach ( $pairs as $pair ) {
			$open  = $pair[0];
			$close = $pair[1];
			$olen  = strlen( $open );
			$clen  = strlen( $close );
			if ( strlen( $text ) >= $olen + $clen
				&& substr( $text, 0, $olen ) === $open
				&& substr( $text, -$clen ) === $close ) {
				$text  = trim( substr( $text, $olen, strlen( $text ) - $olen - $clen ) );
				$exact = true;
				break;
			}
		}
		if ( '' === $text ) {
			return null;
		}
		return array( 'text' => $text, 'exact' => $exact );
	}

	/**
	 * Exact phrases must appear as written (whitespace may differ). Loose ones
	 * need every word of three characters or more, in any order — so "Bank of
	 * America" is not defeated by the two-letter "of", and a brand mentioned as
	 * "Ultimate AEO" is still a hit. Word boundaries are respected either way:
	 * "Ace" never matches "Aceto".
	 *
	 * @param string $answer_text The answer.
	 * @param array  $phrase      [ text, exact ].
	 * @return bool
	 */
	public static function phrase_matches( $answer_text, array $phrase ) {
		$text = isset( $phrase['text'] ) ? (string) $phrase['text'] : '';
		if ( '' === $text ) {
			return false;
		}
		if ( ! empty( $phrase['exact'] ) ) {
			return self::mentions( $answer_text, $text );
		}
		$words = preg_split( '/\s+/u', $text );
		if ( ! is_array( $words ) ) {
			$words = array( $text );
		}
		$checked = 0;
		foreach ( $words as $w ) {
			$w = trim( (string) $w );
			if ( '' === $w || self::u_length( $w ) < self::MIN_WORD ) {
				continue;
			}
			$checked++;
			if ( ! self::mentions( $answer_text, $w ) ) {
				return false;
			}
		}
		// Nothing long enough to test on its own — fall back to the whole phrase.
		return $checked > 0 ? true : self::mentions( $answer_text, $text );
	}

	/**
	 * Any phrase on the item hitting the answer text.
	 *
	 * @param string $answer_text The answer.
	 * @param array  $phrases     Raw phrase strings.
	 * @return string The phrase that matched, or '' for none.
	 */
	public static function first_phrase_hit( $answer_text, array $phrases ) {
		foreach ( $phrases as $raw ) {
			$parsed = self::parse_phrase( $raw );
			if ( null === $parsed ) {
				continue;
			}
			if ( self::phrase_matches( $answer_text, $parsed ) ) {
				return (string) $raw;
			}
		}
		return '';
	}

	/* ────────────────────────────── brand items ────────────────────────── */

	/**
	 * A stored brand item, cleaned. Returns null when nothing usable survives —
	 * an item with neither a phrase nor an owned URL cannot be tracked.
	 *
	 * @param mixed $item Raw item.
	 * @return array|null [ label, phrases[], urls[], enabled ].
	 */
	public static function normalize_item( $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}
		$label = trim( self::to_string( isset( $item['label'] ) ? $item['label'] : '' ) );

		$phrases = array();
		$raw_p   = isset( $item['phrases'] ) ? $item['phrases'] : array();
		if ( is_string( $raw_p ) ) {
			$raw_p = preg_split( '/\r\n|\r|\n/', $raw_p );
		}
		foreach ( (array) $raw_p as $p ) {
			$p = trim( self::to_string( $p ) );
			if ( '' === $p || null === self::parse_phrase( $p ) ) {
				continue;
			}
			if ( ! in_array( $p, $phrases, true ) ) {
				$phrases[] = $p;
			}
			if ( count( $phrases ) >= self::MAX_PHRASES ) {
				break;
			}
		}

		$urls  = array();
		$raw_u = isset( $item['urls'] ) ? $item['urls'] : array();
		if ( is_string( $raw_u ) ) {
			$raw_u = preg_split( '/[\r\n,]+/', $raw_u );
		}
		foreach ( (array) $raw_u as $u ) {
			$u = trim( self::to_string( $u ) );
			if ( '' === $u || null === self::normalize_property( $u, 'profile' ) ) {
				continue;
			}
			if ( ! in_array( $u, $urls, true ) ) {
				$urls[] = $u;
			}
			if ( count( $urls ) >= self::MAX_URLS ) {
				break;
			}
		}

		// No label but something to track: name the item after its first phrase.
		if ( '' === $label && ! empty( $phrases ) ) {
			$parsed = self::parse_phrase( $phrases[0] );
			$label  = $parsed ? $parsed['text'] : '';
		}
		if ( '' === $label || ( empty( $phrases ) && empty( $urls ) ) ) {
			return null;
		}
		return array(
			'label'   => $label,
			'phrases' => $phrases,
			'urls'    => $urls,
			'enabled' => ! isset( $item['enabled'] ) || (bool) $item['enabled'],
		);
	}

	/**
	 * Clean a whole list, dropping unusable rows and duplicate labels.
	 *
	 * @param mixed $items Raw items.
	 * @return array Item[].
	 */
	public static function normalize_items( $items ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $items as $raw ) {
			$item = self::normalize_item( $raw );
			if ( null === $item ) {
				continue;
			}
			$key = strtolower( $item['label'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( count( $out ) >= self::MAX_ITEMS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The owned properties a brand item contributes.
	 *
	 * @param array $item Normalized item.
	 * @return array Property[].
	 */
	public static function item_properties( array $item ) {
		$out   = array();
		$label = isset( $item['label'] ) ? (string) $item['label'] : '';
		foreach ( (array) ( isset( $item['urls'] ) ? $item['urls'] : array() ) as $u ) {
			$p = self::normalize_property( $u, 'profile', $label );
			if ( null !== $p ) {
				$p['label'] = $label;
				$out[]      = $p;
			}
		}
		return $out;
	}

	/**
	 * Every property in play: the site's own hosts, the company's profiles, and
	 * each enabled brand item's profiles.
	 *
	 * @param array  $hosts        Our hosts.
	 * @param array  $company_urls Company profile URLs.
	 * @param array  $items        Normalized brand items.
	 * @param string $company_name Label for the company's own rows.
	 * @return array Property[].
	 */
	public static function all_properties( array $hosts, array $company_urls, array $items, $company_name = '' ) {
		$out = array();
		foreach ( $hosts as $h ) {
			$p = self::normalize_property( $h, 'site', 'company' );
			if ( null !== $p ) {
				$p['label'] = self::to_string( $company_name );
				$out[]      = $p;
			}
		}
		foreach ( $company_urls as $u ) {
			$p = self::normalize_property( $u, 'profile', 'company' );
			if ( null !== $p ) {
				$p['label'] = self::to_string( $company_name );
				$out[]      = $p;
			}
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ( isset( $item['enabled'] ) && ! $item['enabled'] ) ) {
				continue;
			}
			foreach ( self::item_properties( $item ) as $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Per-item outcome for one answer, using the same cited > named > absent
	 * vocabulary as the site-wide verdict.
	 *
	 * @param array $answer [ text, cited_urls[] ].
	 * @param array $items  Normalized brand items.
	 * @return array Rows of [ label, verdict, surface, urls[], phrase ].
	 */
	public static function evaluate( array $answer, array $items ) {
		$text = isset( $answer['text'] ) ? self::to_string( $answer['text'] ) : '';
		$urls = isset( $answer['cited_urls'] ) && is_array( $answer['cited_urls'] ) ? $answer['cited_urls'] : array();
		$out  = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ( isset( $item['enabled'] ) && ! $item['enabled'] ) ) {
				continue;
			}
			$props   = self::item_properties( $item );
			$hits    = array();
			$surface = '';
			foreach ( $urls as $u ) {
				$matched = self::properties_for( $u, $props );
				if ( empty( $matched ) ) {
					continue;
				}
				$hits[] = self::to_string( $u );
				foreach ( $matched as $match ) {
					$kind = isset( $match['kind'] ) ? (string) $match['kind'] : self::SURFACE_PROFILE;
					if ( '' === $surface ) {
						$surface = $kind;
					} elseif ( $surface !== $kind ) {
						$surface = self::SURFACE_BOTH;
					}
				}
			}
			$phrase = self::first_phrase_hit( $text, isset( $item['phrases'] ) ? (array) $item['phrases'] : array() );
			if ( ! empty( $hits ) ) {
				$verdict = 'cited';
			} elseif ( '' !== $phrase ) {
				$verdict = 'named';
			} else {
				$verdict = 'absent';
			}
			$out[] = array(
				'label'   => isset( $item['label'] ) ? (string) $item['label'] : '',
				'verdict' => $verdict,
				'surface' => $surface,
				'urls'    => $hits,
				'phrase'  => $phrase,
			);
		}
		return $out;
	}

	/* ─────────────────────────────── helpers ───────────────────────────── */

	/** Whole-word mention, delegated to the one implementation that owns it. */
	private static function mentions( $text, $name ) {
		if ( class_exists( 'TWTAEO_Visibility_Verdict' ) && method_exists( 'TWTAEO_Visibility_Verdict', 'mentions_name' ) ) {
			return (bool) TWTAEO_Visibility_Verdict::mentions_name( $text, $name );
		}
		return false;
	}

	private static function u_length( $s ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $s, 'UTF-8' );
		}
		$n = preg_match_all( '/./us', $s, $m );
		return false === $n ? strlen( $s ) : $n;
	}

	private static function to_string( $value ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return (string) $value;
	}
}
