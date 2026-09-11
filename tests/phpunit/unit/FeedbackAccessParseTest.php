<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess::parseGroupList
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess::isPersistentAccount
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess::groupsGrantAccess
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess::withoutPublicWildcard
 */
class FeedbackAccessParseTest extends TestCase {

	public function testParseIgnoresCommentsAndBlanks(): void {
		$text = <<<TEXT
# Who may manage feedback
user

; old style comment
sysop
TEXT;
		$this->assertSame( [ 'user', 'sysop' ], FeedbackAccess::parseGroupList( $text ) );
	}

	public function testParseWikiListMarkup(): void {
		$text = "* user\n* editor\n* user\n";
		$this->assertSame( [ 'user', 'editor' ], FeedbackAccess::parseGroupList( $text ) );
	}

	public function testParseLoneStarIsEveryoneToken(): void {
		$this->assertSame( [ '*' ], FeedbackAccess::parseGroupList( '*' ) );
		$this->assertSame( [ '*' ], FeedbackAccess::parseGroupList( "*\n" ) );
		$this->assertSame( [ '*' ], FeedbackAccess::parseGroupList( "  *  \n" ) );
	}

	public function testParseStarWithListMarkupIsEveryoneToken(): void {
		$this->assertSame( [ '*' ], FeedbackAccess::parseGroupList( '* *' ) );
		$this->assertSame( [ '*', 'sysop' ], FeedbackAccess::parseGroupList( "* *\n* sysop\n" ) );
	}

	public function testParseEmptyReturnsEmpty(): void {
		$this->assertSame( [], FeedbackAccess::parseGroupList( '' ) );
		$this->assertSame( [], FeedbackAccess::parseGroupList( "# only comments\n" ) );
	}

	public function testDefaultGroupsIsSysop(): void {
		$this->assertSame( [ 'sysop' ], FeedbackAccess::DEFAULT_GROUPS );
		$this->assertSame( [ 'sysop' ], FeedbackAccess::DEFAULT_EMAIL_GROUPS );
		$this->assertSame( [ 'sysop' ], FeedbackAccess::DEFAULT_EXPORT_GROUPS );
	}

	public function testPersistentAccountExcludesTempAndAnon(): void {
		$this->assertFalse( FeedbackAccess::isPersistentAccount( new FakeIdentityUser( false, false ) ) );
		$this->assertFalse( FeedbackAccess::isPersistentAccount( new FakeIdentityUser( true, true ) ) );
		$this->assertTrue( FeedbackAccess::isPersistentAccount( new FakeIdentityUser( true, false ) ) );
		$this->assertTrue( FeedbackAccess::isPersistentAccount( new FakeNamedUserNoTempMethod() ) );
	}

	public function testUserTokenDeniesTempAndAnon(): void {
		$temp = new FakeIdentityUser( true, true );
		$anon = new FakeIdentityUser( false, false );
		$named = new FakeIdentityUser( true, false );
		$preIsTemp = new FakeNamedUserNoTempMethod();

		$this->assertFalse( FeedbackAccess::groupsGrantAccess( [ 'user' ], $temp ) );
		$this->assertFalse( FeedbackAccess::groupsGrantAccess( [ 'user' ], $anon ) );
		$this->assertTrue( FeedbackAccess::groupsGrantAccess( [ 'user' ], $named ) );
		$this->assertTrue( FeedbackAccess::groupsGrantAccess( [ 'user' ], $preIsTemp ) );
	}

	public function testEmptyListUsesDefaultSysopNotUserToken(): void {
		$temp = new FakeIdentityUser( true, true );
		$named = new FakeIdentityUser( true, false );
		$this->assertFalse( FeedbackAccess::groupsGrantAccess( [], $temp ) );
		$this->assertFalse( FeedbackAccess::groupsGrantAccess( [], $named ) );
		// Empty is not "nobody": it substitutes DEFAULT_GROUPS (sysop).
		$this->assertTrue( FeedbackAccess::groupsGrantAccess( [], $named, [ 'sysop' ] ) );
	}

	public function testDummyNobodyTokenDoesNotGrantAccess(): void {
		$named = new FakeIdentityUser( true, false );
		// A documented dummy token on the email/export page is not *, user,
		// or a real group, so it grants nobody via the group-list check.
		// (Revoking the sysop *right* is the other half of locking to nobody.)
		$this->assertFalse( FeedbackAccess::groupsGrantAccess( [ 'no-one' ], $named ) );
		$this->assertFalse( FeedbackAccess::groupsGrantAccess( [ 'no-one' ], $named, [ 'sysop' ] ) );
		$this->assertFalse( FeedbackAccess::groupsGrantAccess( [ 'no-one' ], $named, [ 'user' ] ) );
	}

	public function testStarTokenAllowsTemp(): void {
		$this->assertTrue( FeedbackAccess::groupsGrantAccess(
			[ '*' ],
			new FakeIdentityUser( true, true )
		) );
	}

	public function testNamedGroupRequiresEffectiveMembership(): void {
		$temp = new FakeIdentityUser( true, true );
		$this->assertFalse( FeedbackAccess::groupsGrantAccess( [ 'sysop' ], $temp ) );
		$this->assertTrue( FeedbackAccess::groupsGrantAccess( [ 'sysop' ], $temp, [ 'sysop' ] ) );
	}

	/**
	 * F-08: contact-email visibility must never resolve to "everyone,
	 * including anonymous readers" — withoutPublicWildcard() is the one
	 * place that's enforced, regardless of where the group list came from.
	 */
	public function testWithoutPublicWildcardDropsStarOnly(): void {
		$this->assertSame(
			[ 'sysop', 'editor' ],
			FeedbackAccess::withoutPublicWildcard( [ 'sysop', '*', 'editor' ], 'x' )
		);
		$this->assertSame(
			[],
			FeedbackAccess::withoutPublicWildcard( [ '*' ], 'x' )
		);
	}

	public function testWithoutPublicWildcardLeavesListsWithoutStarUnchanged(): void {
		$this->assertSame(
			[ 'sysop', 'user' ],
			FeedbackAccess::withoutPublicWildcard( [ 'sysop', 'user' ], 'x' )
		);
		$this->assertSame( [], FeedbackAccess::withoutPublicWildcard( [], 'x' ) );
	}

	/**
	 * The resulting empty list is not itself "nobody": groupsGrantAccess()
	 * substitutes DEFAULT_GROUPS for an empty array, so stripping the only
	 * configured token ('*') falls back to the safe sysop default rather
	 * than granting no one.
	 */
	public function testEmptyAfterWildcardStripFallsBackToDefaultInGroupsGrantAccess(): void {
		$stripped = FeedbackAccess::withoutPublicWildcard( [ '*' ], 'x' );
		$named = new FakeIdentityUser( true, false );
		$this->assertTrue( FeedbackAccess::groupsGrantAccess( $stripped, $named, [ 'sysop' ] ) );
	}
}

class FakeIdentityUser {
	private bool $registered;
	private bool $temp;

	public function __construct( bool $registered, bool $temp ) {
		$this->registered = $registered;
		$this->temp = $temp;
	}

	public function isRegistered(): bool {
		return $this->registered;
	}

	public function isTemp(): bool {
		return $this->temp;
	}
}

/** Pre-1.42 User with no isTemp() — treat as a named account if registered. */
class FakeNamedUserNoTempMethod {
	public function isRegistered(): bool {
		return true;
	}
}
