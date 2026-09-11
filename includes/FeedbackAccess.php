<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use User;

/**
 * Who may view/process the feedback dashboard.
 *
 * Configured from LocalSettings.php only (F-08, 2026-09-10 review): dashboard,
 * email, and export access used to also be overridable from MediaWiki-namespace
 * pages, editable by anyone holding editinterface with no deploy or code review.
 * That on-wiki override has been removed entirely for these three; only
 * $wgSaintapediaFeedbackAccessGroups / EmailAccessGroups / ExportAccessGroups
 * (below) are consulted now. (Some lower-stakes operational settings — notify
 * list, show-public-counts, enable-talklink — remain wiki-overridable; see
 * FeedbackWikiConfig.)
 *
 * Special tokens:
 * - sysop — administrators [default in public mode; matches saintapediafeedback-view]
 * - user  — any persistent named account (not temp / IP); default in enterprise mode (option C)
 * - *     — everyone including anons (rarely appropriate; never honored for
 *           email access — see getAllowedEmailGroups())
 * - autoconfirmed, editor, … — normal MediaWiki groups
 *
 * Default for dashboard access (getAllowedGroups()) when
 * $wgSaintapediaFeedbackAccessGroups is unset or empty is mode-dependent —
 * see defaultGroupsForMode(): [ 'sysop' ] for public mode, [ 'user' ] for
 * enterprise mode. Email and export access (getAllowedEmailGroups() /
 * getAllowedExportGroups()) do NOT follow mode; their unset/empty default
 * is always [ 'sysop' ], regardless of $wgSaintapediaFeedbackMode.
 * Users who hold saintapediafeedback-view via LocalSettings always pass.
 */
class FeedbackAccess {

	public const DEFAULT_GROUPS = [ 'sysop' ];

	public const DEFAULT_EMAIL_GROUPS = [ 'sysop' ];

	public const DEFAULT_EXPORT_GROUPS = [ 'sysop' ];

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
	 * someone who can already open the dashboard. getAllowedEmailGroups()
	 * never honors a "*" token (F-08): contact email can never be made
	 * visible to anonymous/everyone, regardless of how it's configured.
	 */
	public static function userCanViewEmail( UserIdentity $user ): bool {
		try {
			return self::userHasSecondaryAccess(
				$user,
				'saintapediafeedback-viewemail',
				[ self::class, 'getAllowedEmailGroups' ]
			);
		} catch ( \Throwable $e ) {
			self::logClosedFailure( 'userCanViewEmail', $e );
			return false;
		}
	}

	/**
	 * Whether this user may download the JSON export (bulk raw feedback data).
	 *
	 * Separate from userCanManage() so a broader dashboard-triage group does
	 * not automatically get bulk offline export. Callers must still gate on
	 * userCanManage() first — export routes require both.
	 */
	public static function userCanExport( UserIdentity $user ): bool {
		try {
			return self::userHasSecondaryAccess(
				$user,
				'saintapediafeedback-export',
				[ self::class, 'getAllowedExportGroups' ]
			);
		} catch ( \Throwable $e ) {
			self::logClosedFailure( 'userCanExport', $e );
			return false;
		}
	}

	private static function logClosedFailure( string $context, \Throwable $e ): void {
		if ( function_exists( 'wfLogWarning' ) ) {
			wfLogWarning( "SaintapediaFeedback: {$context} overlay read failed; denying. {$e->getMessage()}" );
		}
	}

	/**
	 * Email/export check without the fail-closed wrapper. $groupsFn reads
	 * plain LocalSettings.php config now (no wiki-page IO), so this should
	 * not throw in practice; the try/catch in the two callers is kept as
	 * defense-in-depth rather than removed.
	 *
	 * @param callable(): string[] $groupsFn
	 */
	private static function userHasSecondaryAccess(
		UserIdentity $user,
		string $right,
		callable $groupsFn
	): bool {
		$userObj = $user instanceof User
			? $user
			: MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user );

		if ( self::userIsBlocked( $userObj ) ) {
			return false;
		}

		if ( $userObj->isAllowed( $right ) ) {
			return true;
		}

		$effective = MediaWikiServices::getInstance()
			->getUserGroupManager()
			->getUserEffectiveGroups( $userObj );

		return self::groupsGrantAccess( $groupsFn(), $userObj, $effective );
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
	 * Groups currently allowed to open the dashboard (from
	 * $wgSaintapediaFeedbackAccessGroups; when unset, defaultGroupsForMode()
	 * picks sysop for public mode or user for enterprise mode).
	 *
	 * @return string[]
	 */
	public static function getAllowedGroups(): array {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$groups = $config->get( 'SaintapediaFeedbackAccessGroups' );
		if ( is_array( $groups ) && $groups ) {
			return array_values( $groups );
		}
		return self::defaultGroupsForMode( (string)$config->get( 'SaintapediaFeedbackMode' ) );
	}

	/**
	 * Mode-aware fallback for getAllowedGroups() when
	 * $wgSaintapediaFeedbackAccessGroups is unset/empty. Enterprise wikis
	 * are intranets with many more trusted logged-in staff than a public
	 * wiki's sysop-only default fits, so they default to any named account
	 * (option C) instead. Pure; unit-testable.
	 *
	 * Deliberately does NOT apply to getAllowedEmailGroups()/
	 * getAllowedExportGroups() — those stay sysop-only regardless of mode,
	 * matching the existing "separate, more restrictive by default" design
	 * for email visibility and bulk export (see their own doc comments).
	 *
	 * @return string[]
	 */
	public static function defaultGroupsForMode( string $mode ): array {
		return $mode === 'enterprise' ? [ 'user' ] : self::DEFAULT_GROUPS;
	}

	/**
	 * Groups currently allowed to see the contact-email field (from
	 * $wgSaintapediaFeedbackEmailAccessGroups, or DEFAULT_EMAIL_GROUPS when
	 * unset). Independent of getAllowedGroups(). A "*" token is never
	 * honored here (F-08): contact email must never be visible to
	 * anonymous/everyone, so it is dropped before the list reaches
	 * groupsGrantAccess() — even if it came from LocalSettings.php.
	 *
	 * @return string[]
	 */
	public static function getAllowedEmailGroups(): array {
		return self::withoutPublicWildcard(
			self::configuredGroups( 'SaintapediaFeedbackEmailAccessGroups', self::DEFAULT_EMAIL_GROUPS ),
			'SaintapediaFeedbackEmailAccessGroups'
		);
	}

	/**
	 * Groups currently allowed to export (from
	 * $wgSaintapediaFeedbackExportAccessGroups, or DEFAULT_EXPORT_GROUPS when
	 * unset). Independent of getAllowedGroups().
	 *
	 * @return string[]
	 */
	public static function getAllowedExportGroups(): array {
		return self::configuredGroups( 'SaintapediaFeedbackExportAccessGroups', self::DEFAULT_EXPORT_GROUPS );
	}

	/**
	 * @param string $configKey
	 * @param string[] $default
	 * @return string[]
	 */
	private static function configuredGroups( string $configKey, array $default ): array {
		$groups = MediaWikiServices::getInstance()->getMainConfig()->get( $configKey );
		return ( is_array( $groups ) && $groups ) ? array_values( $groups ) : $default;
	}

	/**
	 * Drops a "*" (everyone including anonymous) token from a group list,
	 * logging when it does so. Groups that pass through
	 * groupsGrantAccess() unfiltered otherwise; this is the one place "*"
	 * is refused outright rather than just discouraged in documentation.
	 * Pure aside from the log call; unit-testable.
	 *
	 * @param string[] $groups
	 * @return string[]
	 */
	public static function withoutPublicWildcard( array $groups, string $configKey ): array {
		if ( !in_array( '*', $groups, true ) ) {
			return $groups;
		}
		if ( function_exists( 'wfLogWarning' ) ) {
			wfLogWarning(
				"SaintapediaFeedback: {$configKey} included '*' (everyone, including anonymous "
					. "readers). Contact-email visibility can never be made public; ignoring '*' "
					. 'for this setting.'
			);
		}
		return array_values( array_filter( $groups, static fn ( $g ) => $g !== '*' ) );
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
}
