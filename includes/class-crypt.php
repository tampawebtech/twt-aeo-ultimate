<?php
/**
 * TWT AEO Crypt — symmetric encryption for secrets at rest.
 *
 * Encrypts API keys and auth tokens before they touch the database so a DB dump
 * never leaks usable credentials. Reversible by design (these secrets must be
 * replayed to external services), using authenticated symmetric encryption.
 *
 * Method:
 *   - Primary: libsodium crypto_secretbox (XSalsa20-Poly1305), 256-bit key,
 *     random 24-byte nonce. Stored as  twtenc:s1:base64(nonce . ciphertext).
 *   - Fallback: OpenSSL AES-256-GCM. Stored as twtenc:o1:base64(iv . tag . ct).
 *
 * Master key:
 *   - If TWTAEO_ENCRYPTION_KEY (base64 of 32 bytes) is defined in wp-config, it
 *     is used directly — define it so you can decrypt values offline.
 *   - Otherwise a key is derived from WordPress's SECURE_AUTH salt, so the
 *     plugin protects secrets out of the box on every site with no setup. (If
 *     those salts are rotated, encrypted values can no longer be decrypted and
 *     the affected key must be re-entered — handled gracefully: decrypt() of an
 *     unreadable value returns '' rather than corrupt data.)
 *
 * Migration is transparent: decrypt() returns any un-prefixed (legacy plaintext)
 * value unchanged, and encrypt() is applied on the next save.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Crypt {

	const PREFIX_SODIUM  = 'twtenc:s1:';
	const PREFIX_OPENSSL = 'twtenc:o1:';

	/**
	 * Resolve the 32-byte master key.
	 *
	 * @return string Raw 32-byte binary key.
	 */
	private static function key() {
		if ( defined( 'TWTAEO_ENCRYPTION_KEY' ) && TWTAEO_ENCRYPTION_KEY ) {
			$raw = base64_decode( (string) TWTAEO_ENCRYPTION_KEY, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a base64-encoded 32-byte binary key, not obfuscation.
			if ( false !== $raw && strlen( $raw ) >= 32 ) {
				return substr( $raw, 0, 32 );
			}
			// Defined but not a clean 32-byte base64 value — derive deterministically.
			return hash( 'sha256', 'twtaeo|' . TWTAEO_ENCRYPTION_KEY, true );
		}

		// No constant — derive a stable per-site key from WordPress's auth salt.
		// wp_salt() lives outside the plugin's option rows, so a dump of our
		// options alone does not reveal the key.
		return hash( 'sha256', 'twtaeo|' . wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Encrypt a string for storage. Empty input returns '' (nothing to protect).
	 *
	 * @param string $plaintext
	 * @return string Prefixed, base64 ciphertext — or '' on empty/failure.
	 */
	/**
	 * Best-effort wipe of key material.
	 *
	 * WordPress bundles the pure-PHP sodium_compat polyfill, so sodium_memzero()
	 * *exists* on hosts with no ext-sodium — but the polyfill throws, because PHP
	 * cannot truly wipe memory. function_exists() is therefore not a safe guard
	 * on its own: it was letting a throw escape and fatal the request on every
	 * secret read and write. Require the real extension, still catch, and fall
	 * back to overwriting the variable.
	 *
	 * @param string $key Key material, cleared by reference.
	 */
	private static function memzero( &$key ) {
		if ( extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
			try {
				sodium_memzero( $key );
				return;
			} catch ( \Throwable $e ) {
				$key = '';
			}
		}
		$key = '';
	}

	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			self::memzero( $key );
			return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 transport encoding for binary ciphertext, not obfuscation.
		}

		// OpenSSL AES-256-GCM fallback.
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $cipher ) {
				return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 transport encoding for binary ciphertext, not obfuscation.
			}
		}

		// No working crypto backend — fail closed. Never store plaintext as a
		// "fallback"; surface it so the operator can fix the PHP build.
		if ( class_exists( 'TWTAEO_Logger' ) ) {
			TWTAEO_Logger::error( 'TWTAEO_Crypt: no encryption backend (sodium/openssl) available — secret not stored.' );
		}
		return '';
	}

	/**
	 * Decrypt a stored value. Legacy plaintext (no recognised prefix) is returned
	 * unchanged so existing data keeps working until its next save.
	 *
	 * @param string $value
	 * @return string Plaintext, or '' if an encrypted value cannot be decrypted.
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		$key = self::key();

		if ( 0 === strncmp( $value, self::PREFIX_SODIUM, strlen( self::PREFIX_SODIUM ) ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $value, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own base64 ciphertext, not obfuscation.
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			self::memzero( $key );
			return false === $plain ? '' : $plain;
		}

		if ( 0 === strncmp( $value, self::PREFIX_OPENSSL, strlen( self::PREFIX_OPENSSL ) ) ) {
			$raw = base64_decode( substr( $value, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own base64 ciphertext, not obfuscation.
			if ( false === $raw || strlen( $raw ) <= 28 ) { // 12 IV + 16 tag
				return '';
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}

		// Unrecognised prefix → legacy plaintext. Return as-is for transparent migration.
		return $value;
	}

	/**
	 * Whether a stored value is already in encrypted form.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		return 0 === strncmp( $value, self::PREFIX_SODIUM, strlen( self::PREFIX_SODIUM ) )
			|| 0 === strncmp( $value, self::PREFIX_OPENSSL, strlen( self::PREFIX_OPENSSL ) );
	}
}
