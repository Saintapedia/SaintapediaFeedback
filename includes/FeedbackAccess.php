<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;

/**
 * Who may view/process the feedback dashboard.
 *
 * Configurable via a MediaWiki namespace page (default:
 * MediaWiki:SaintapediaFeedback-access). One group name per line.
 *
 * Special tokens:
 * - sysop — administrators [default; matches saintapediafeedback-view]
 * - user  — any persistent named account (not temp / IP); opt-in option C
 * - *     — everyone including anons (rarely appropriate)
 * - autoconfirmed, editor, … — normal MediaWiki groups
 *
 * Lines starting with # or ; and blank lines are ignored.
 *
 * Default when the page is missing or empty: [ 'sysop' ].
 * Users who hold saintapediafeedback-view via LocalSettings always pass.
 */
class FeedbackAccess {

	public const DEFAULT_GROUPS = [ 'sysop' ];

	public const CACHE_KEY = 'saintapediafeedback-access-groups';

	/**
	 * Named account with a durable identity (not anon, not a MW temp account).
	 *
	 * Temp users are isRegistered() === true on MW 1.39+; they must not get
	 * the "user" dashboard token or a stored fb_user_id. isTemp() is absent
	 * on some 1.39 builds — those users are treated as named if registered.
	 *
	 * @param object $user User / UserIdentity / test double
	 */
	public static function isPersistentAccount( $user ): bool {
		if ( !is_object( $user ) || !method_exists( $user, 'isRegistered' ) || !$user->isRegistered() ) {
			return false;
		}
		if ( method_exists( $user, 'isTemp' ) && $user->isTemp() ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether this user may open the dashboard / toolbox / export.
	 */
	public static function userCanManage( UserIdentity $user ): bool {
		$userObj = $user instanceof User
			? $user
			: MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user );

		// Blocks revoke dashboard access (including option-C named accounts).
		// Mirrors ApiSubmitFeedback: any block (incl. partial) is enough to deny.
		// Admins must not assume "block" alone is a no-op under the broad default.
		if ( self::userIsBlocked( $userObj ) ) {
			return false;
		}

		// Explicit right from LocalSettings / extension.json
		if ( $userObj->isAllowed( 'saintapediafeedback-view' ) ) {
			return true;
		}

		$effective = MediaWikiServices::getInstance()
			->getUserGroupManager()
			->getUserEffectiveGroups( $userObj );

		return self::groupsGrantAccess( self::getAllowedGroups(), $userObj, $effective );
	}

	/**
	 * Whether the access-page group list grants this identity.
	 *
	 * Ignores blocks and saintapediafeedback-view (applied in userCanManage).
	 * The `user` token matches named accounts only — not anons, not temps.
	 *
	 * @param string[] $groups
	 * @param object $user User / UserIdentity / test double
	 * @param string[] $effectiveGroups from UserGroupManager
	 */
	public static function groupsGrantAccess( array $groups, $user, array $effectiveGroups = [] ): bool {
		if ( !$groups ) {
			$groups = self::DEFAULT_GROUPS;
		}

		if ( in_array( '*', $groups, true ) ) {
			return true;
		}

		// Option C: any persistent registered account (temps are not "user")
		if ( in_array( 'user', $groups, true ) && self::isPersistentAccount( $user ) ) {
			return true;
		}

		foreach ( $groups as $g ) {
			if ( $g === 'user' || $g === '*' ) {
				continue;
			}
			if ( in_array( $g, $effectiveGroups, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param User $user
	 */
	private static function userIsBlocked( User $user ): bool {
		// Prefer Authority when available (MW 1.39+)
		try {
			if ( method_exists( $user, 'getBlock' ) ) {
				return (bool)$user->getBlock();
			}
		} catch ( \Throwable $e ) {
			// fall through
		}
		return false;
	}

	/**
	 * Groups currently allowed (from wiki page or PHP defaults).
	 *
	 * @return string[]
	 */
	public static function getAllowedGroups(): array {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$cache = $services->getMainWANObjectCache();

		$pageName = $config->get( 'SaintapediaFeedbackAccessPage' );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = 'SaintapediaFeedback-access';
		}

		$defaults = $config->get( 'SaintapediaFeedbackAccessGroups' );
		if ( !is_array( $defaults ) || !$defaults ) {
			$defaults = self::DEFAULT_GROUPS;
		}

		return $cache->getWithSetCallback(
			$cache->makeKey( self::CACHE_KEY, md5( $pageName ) ),
			$cache::TTL_HOUR,
			static function () use ( $pageName, $defaults ) {
				return self::loadGroupsFromPage( $pageName, $defaults );
			}
		);
	}

	/**
	 * @param string $pageName DB key under NS_MEDIAWIKI (no namespace prefix)
	 * @param string[] $defaults
	 * @return string[]
	 */
	public static function loadGroupsFromPage( string $pageName, array $defaults ): array {
		$title = Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
		if ( !$title || !$title->exists() ) {
			return array_values( $defaults );
		}

		$services = MediaWikiServices::getInstance();
		$wikipage = $services->getWikiPageFactory()->newFromTitle( $title );
		$content = $wikipage->getContent();
		if ( !$content ) {
			return array_values( $defaults );
		}

		$text = method_exists( $content, 'getText' )
			? $content->getText()
			: $content->getTextForSearchIndex();

		$groups = self::parseGroupList( (string)$text );
		if ( !$groups ) {
			return array_values( $defaults );
		}
		return $groups;
	}

	/**
	 * Parse wiki page body into group tokens (pure; unit-testable).
	 *
	 * @return string[]
	 */
	public static function parseGroupList( string $text ): array {
		$groups = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' || $line[0] === ';' ) {
				continue;
			}
			// Allow "* user" wiki-list markup. A line that is only "*" (the
			// documented everyone-including-anons token) must survive the strip.
			$raw = $line;
			$line = preg_replace( '/^\*+\s*/', '', $line );
			$line = trim( $line );
			if ( $line === '' && preg_match( '/^\*+$/', $raw ) ) {
				$line = '*';
			}
			if ( strpos( $line, '#' ) !== false ) {
				$line = trim( substr( $line, 0, strpos( $line, '#' ) ) );
			}
			if ( $line === '' ) {
				continue;
			}
			$groups[] = $line;
		}
		$out = [];
		foreach ( $groups as $g ) {
			if ( !in_array( $g, $out, true ) ) {
				$out[] = $g;
			}
		}
		return $out;
	}

	/** Drop WAN cache after the access page is edited. */
	public static function invalidateCache(): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$pageName = $config->get( 'SaintapediaFeedbackAccessPage' );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = 'SaintapediaFeedback-access';
		}
		$cache = $services->getMainWANObjectCache();
		$cache->delete( $cache->makeKey( self::CACHE_KEY, md5( $pageName ) ) );
	}

	/**
	 * Title of the configuration page (for help links).
	 */
	public static function getAccessPageTitle(): ?Title {
		$services = MediaWikiServices::getInstance();
		$pageName = $services->getMainConfig()->get( 'SaintapediaFeedbackAccessPage' );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = 'SaintapediaFeedback-access';
		}
		return Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
	}
}
