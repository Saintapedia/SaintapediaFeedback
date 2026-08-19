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

	public const DEFAULT_EMAIL_GROUPS = [ 'sysop' ];

	public const DEFAULT_EXPORT_GROUPS = [ 'sysop' ];

	public const CACHE_KEY = 'saintapediafeedback-access-groups';

	public const EMAIL_CACHE_KEY = 'saintapediafeedback-email-access-groups';

	public const EXPORT_CACHE_KEY = 'saintapediafeedback-export-access-groups';

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
	 * Whether this user may see the optional contact-email field.
	 *
	 * Separate from userCanManage() so email can be locked to a smaller set
	 * (e.g. sysop only) even when the dashboard itself is opened up to a
	 * broader group like "user" or a custom editor group. Callers must still
	 * gate on userCanManage() first — this only decides email visibility for
	 * someone who can already open the dashboard.
	 */
	public static function userCanViewEmail( UserIdentity $user ): bool {
		$userObj = $user instanceof User
			? $user
			: MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user );

		if ( self::userIsBlocked( $userObj ) ) {
			return false;
		}

		if ( $userObj->isAllowed( 'saintapediafeedback-viewemail' ) ) {
			return true;
		}

		$effective = MediaWikiServices::getInstance()
			->getUserGroupManager()
			->getUserEffectiveGroups( $userObj );

		return self::groupsGrantAccess( self::getAllowedEmailGroups(), $userObj, $effective );
	}

	/**
	 * Whether this user may download the JSON export (bulk raw feedback data).
	 *
	 * Separate from userCanManage() so a broader dashboard-triage group does
	 * not automatically get bulk offline export. Callers must still gate on
	 * userCanManage() first — export routes require both.
	 */
	public static function userCanExport( UserIdentity $user ): bool {
		$userObj = $user instanceof User
			? $user
			: MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user );

		if ( self::userIsBlocked( $userObj ) ) {
			return false;
		}

		if ( $userObj->isAllowed( 'saintapediafeedback-export' ) ) {
			return true;
		}

		$effective = MediaWikiServices::getInstance()
			->getUserGroupManager()
			->getUserEffectiveGroups( $userObj );

		return self::groupsGrantAccess( self::getAllowedExportGroups(), $userObj, $effective );
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
		return self::getAllowedGroupsFor(
			'SaintapediaFeedbackAccessPage',
			'SaintapediaFeedback-access',
			'SaintapediaFeedbackAccessGroups',
			self::DEFAULT_GROUPS,
			self::CACHE_KEY
		);
	}

	/**
	 * Groups currently allowed to see the contact-email field (from wiki
	 * page or PHP defaults). Independent of getAllowedGroups().
	 *
	 * @return string[]
	 */
	public static function getAllowedEmailGroups(): array {
		return self::getAllowedGroupsFor(
			'SaintapediaFeedbackEmailAccessPage',
			'SaintapediaFeedback-email-access',
			'SaintapediaFeedbackEmailAccessGroups',
			self::DEFAULT_EMAIL_GROUPS,
			self::EMAIL_CACHE_KEY
		);
	}

	/**
	 * Groups currently allowed to export (from wiki page or PHP defaults).
	 * Independent of getAllowedGroups().
	 *
	 * @return string[]
	 */
	public static function getAllowedExportGroups(): array {
		return self::getAllowedGroupsFor(
			'SaintapediaFeedbackExportAccessPage',
			'SaintapediaFeedback-export-access',
			'SaintapediaFeedbackExportAccessGroups',
			self::DEFAULT_EXPORT_GROUPS,
			self::EXPORT_CACHE_KEY
		);
	}

	/**
	 * @param string $pageConfigKey Config var naming the MediaWiki-namespace page
	 * @param string $pageDefault Fallback DB key when that config var is unset
	 * @param string $groupsConfigKey Config var with the PHP-default group list
	 * @param string[] $groupsDefault Fallback when that config var is unset
	 * @param string $cacheKeyPrefix
	 * @return string[]
	 */
	private static function getAllowedGroupsFor(
		string $pageConfigKey,
		string $pageDefault,
		string $groupsConfigKey,
		array $groupsDefault,
		string $cacheKeyPrefix
	): array {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$cache = $services->getMainWANObjectCache();

		$pageName = $config->get( $pageConfigKey );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = $pageDefault;
		}

		$defaults = $config->get( $groupsConfigKey );
		if ( !is_array( $defaults ) || !$defaults ) {
			$defaults = $groupsDefault;
		}

		return $cache->getWithSetCallback(
			$cache->makeKey( $cacheKeyPrefix, md5( $pageName ) ),
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
	 * Normalize one wiki-page line: skip blank/comment-only lines, strip a
	 * leading wiki-list "*" marker (keeping a lone "*" as the everyone
	 * token) and an inline "#" comment. Returns null when the line has
	 * nothing left after normalization.
	 *
	 * Pure; shared by parseGroupList() and FeedbackWikiConfig's line
	 * parsing so both accept the same on-wiki page conventions.
	 */
	public static function normalizeLine( string $line ): ?string {
		$line = trim( $line );
		if ( $line === '' || $line[0] === '#' || $line[0] === ';' ) {
			return null;
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
		return $line === '' ? null : $line;
	}

	/**
	 * Parse wiki page body into group tokens (pure; unit-testable).
	 *
	 * @return string[]
	 */
	public static function parseGroupList( string $text ): array {
		$groups = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$normalized = self::normalizeLine( $line );
			if ( $normalized !== null ) {
				$groups[] = $normalized;
			}
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
		self::invalidateCacheFor( 'SaintapediaFeedbackAccessPage', 'SaintapediaFeedback-access', self::CACHE_KEY );
	}

	/** Drop WAN cache after the email-access page is edited. */
	public static function invalidateEmailCache(): void {
		self::invalidateCacheFor(
			'SaintapediaFeedbackEmailAccessPage',
			'SaintapediaFeedback-email-access',
			self::EMAIL_CACHE_KEY
		);
	}

	/** Drop WAN cache after the export-access page is edited. */
	public static function invalidateExportCache(): void {
		self::invalidateCacheFor(
			'SaintapediaFeedbackExportAccessPage',
			'SaintapediaFeedback-export-access',
			self::EXPORT_CACHE_KEY
		);
	}

	private static function invalidateCacheFor( string $pageConfigKey, string $pageDefault, string $cacheKeyPrefix ): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$pageName = $config->get( $pageConfigKey );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = $pageDefault;
		}
		$cache = $services->getMainWANObjectCache();
		$cache->delete( $cache->makeKey( $cacheKeyPrefix, md5( $pageName ) ) );
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

	/**
	 * Title of the email-access configuration page (for help links).
	 */
	public static function getEmailAccessPageTitle(): ?Title {
		$services = MediaWikiServices::getInstance();
		$pageName = $services->getMainConfig()->get( 'SaintapediaFeedbackEmailAccessPage' );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = 'SaintapediaFeedback-email-access';
		}
		return Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
	}

	/**
	 * Title of the export-access configuration page (for help links).
	 */
	public static function getExportAccessPageTitle(): ?Title {
		$services = MediaWikiServices::getInstance();
		$pageName = $services->getMainConfig()->get( 'SaintapediaFeedbackExportAccessPage' );
		if ( !is_string( $pageName ) || $pageName === '' ) {
			$pageName = 'SaintapediaFeedback-export-access';
		}
		return Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
	}
}
