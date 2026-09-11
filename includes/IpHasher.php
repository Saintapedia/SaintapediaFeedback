<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

/**
 * Pseudonymizes an IP address for rate limiting (F-07).
 *
 * The previous scheme hashed the raw IP with the global $wgSecretKey and no
 * time component, producing a stable, indefinitely linkable identifier and
 * unnecessarily coupling this extension to a high-value application secret.
 *
 * This scheme HMACs the IP with a dedicated secret (so it can be rotated
 * without touching $wgSecretKey) and folds in a UTC-date bucket, so the
 * resulting hash for a given address changes every day and two days'
 * submissions from the same address cannot be linked by comparing hashes.
 */
class IpHasher {

	/**
	 * @param string $ip Raw client IP
	 * @param string $secret Dedicated pseudonymization secret
	 * @param string|null $dateBucket UTC "Y-m-d" bucket; defaults to today (injectable for tests)
	 */
	public static function hash( string $ip, string $secret, ?string $dateBucket = null ): string {
		$dateBucket ??= gmdate( 'Y-m-d' );
		return hash_hmac( 'sha256', $dateBucket . '|' . $ip, $secret );
	}
}
