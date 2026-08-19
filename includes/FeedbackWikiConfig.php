<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Generic on-wiki override for non-secret operational knobs: rate limit,
 * notify-user list, captcha-required, show-public-counts, enable-talk-link.
 *
 * Mirrors FeedbackAccess's MediaWiki:-page pattern: one page per setting,
 * PHP config is the fallback when the page is missing/empty, WAN-cached,
 * invalidated on save/delete/move via maybeInvalidate().
 *
 * Never use this for secrets (hCaptcha secret key, LLM webhook token) —
 * MediaWiki-namespace pages are readable by anyone even though editing is
 * restricted to editinterface, so only non-sensitive values belong here.
 */
class FeedbackWikiConfig {

	public const CACHE_KEY = 'saintapediafeedback-wikiconfig';

	/**
	 * Registered on-wiki-overridable pages: config key that names the page
	 * (for LocalSettings rename) => default DB key. Used by Hooks to
	 * invalidate cache on save/delete/move without hardcoding each page.
	 *
	 * @return array<string,string>
	 */
	public static function pages(): array {
		return [
			'SaintapediaFeedbackRateLimitPage' => 'SaintapediaFeedback-ratelimit',
			'SaintapediaFeedbackNotifyUsersPage' => 'SaintapediaFeedback-notify-users',
			'SaintapediaFeedbackRequireCaptchaPage' => 'SaintapediaFeedback-require-captcha',
			'SaintapediaFeedbackShowPublicCountsPage' => 'SaintapediaFeedback-show-public-counts',
			'SaintapediaFeedbackEnableTalkLinkPage' => 'SaintapediaFeedback-enable-talklink',
		];
	}

	private static function pageName( string $pageConfigKey, string $pageDefault ): string {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$name = $config->get( $pageConfigKey );
		return ( is_string( $name ) && $name !== '' ) ? $name : $pageDefault;
	}

	/** Raw trimmed page text, or '' when the page is missing/empty. Cached. */
	private static function loadText( string $pageConfigKey, string $pageDefault ): string {
		$pageName = self::pageName( $pageConfigKey, $pageDefault );
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();

		return $cache->getWithSetCallback(
			$cache->makeKey( self::CACHE_KEY, md5( $pageName ) ),
			$cache::TTL_HOUR,
			static function () use ( $pageName ) {
				$title = Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
				if ( !$title || !$title->exists() ) {
					return '';
				}
				$wikipage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
				$content = $wikipage->getContent();
				if ( !$content ) {
					return '';
				}
				$text = method_exists( $content, 'getText' )
					? $content->getText()
					: $content->getTextForSearchIndex();
				return trim( (string)$text );
			}
		);
	}

	/**
	 * Non-empty, non-comment lines (# or ; prefix), same convention as
	 * FeedbackAccess::parseGroupList.
	 *
	 * @return string[]
	 */
	private static function significantLines( string $text ): array {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' || $line[0] === ';' ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/** Effective bool: on-wiki override wins when the page holds a recognizable value. */
	public static function effectiveBool( string $pageConfigKey, string $pageDefault, bool $phpValue ): bool {
		$lines = self::significantLines( self::loadText( $pageConfigKey, $pageDefault ) );
		if ( !$lines ) {
			return $phpValue;
		}
		$value = strtolower( $lines[0] );
		if ( in_array( $value, [ 'true', 'yes', 'on', '1' ], true ) ) {
			return true;
		}
		if ( in_array( $value, [ 'false', 'no', 'off', '0' ], true ) ) {
			return false;
		}
		return $phpValue;
	}

	/** Effective int: on-wiki override wins when the page holds a non-negative integer. */
	public static function effectiveInt( string $pageConfigKey, string $pageDefault, int $phpValue ): int {
		$lines = self::significantLines( self::loadText( $pageConfigKey, $pageDefault ) );
		if ( !$lines || !ctype_digit( $lines[0] ) ) {
			return $phpValue;
		}
		return (int)$lines[0];
	}

	/** Effective list (e.g. usernames): on-wiki override wins when the page has any lines. */
	public static function effectiveList( string $pageConfigKey, string $pageDefault, array $phpValue ): array {
		$lines = self::significantLines( self::loadText( $pageConfigKey, $pageDefault ) );
		if ( !$lines ) {
			return $phpValue;
		}
		$out = [];
		foreach ( $lines as $line ) {
			if ( !in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public static function getPageTitle( string $pageConfigKey, string $pageDefault ): ?Title {
		return Title::makeTitleSafe( NS_MEDIAWIKI, self::pageName( $pageConfigKey, $pageDefault ) );
	}

	public static function invalidate( string $pageConfigKey, string $pageDefault ): void {
		$pageName = self::pageName( $pageConfigKey, $pageDefault );
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cache->delete( $cache->makeKey( self::CACHE_KEY, md5( $pageName ) ) );
	}

	/** Invalidate whichever registered page this title matches, if any. */
	public static function maybeInvalidate( Title $title ): void {
		foreach ( self::pages() as $pageConfigKey => $pageDefault ) {
			$pageTitle = self::getPageTitle( $pageConfigKey, $pageDefault );
			if ( $pageTitle && $title->equals( $pageTitle ) ) {
				self::invalidate( $pageConfigKey, $pageDefault );
			}
		}
	}
}
