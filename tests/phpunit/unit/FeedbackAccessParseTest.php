<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess::parseGroupList
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

	public function testDefaultGroupsIsUser(): void {
		$this->assertSame( [ 'user' ], FeedbackAccess::DEFAULT_GROUPS );
	}

	public function testPersistentAccountExcludesTempAndAnon(): void {
		$this->assertFalse( FeedbackAccess::isPersistentAccount( new FakeIdentityUser( false, false ) ) );
		$this->assertFalse( FeedbackAccess::isPersistentAccount( new FakeIdentityUser( true, true ) ) );
		$this->assertTrue( FeedbackAccess::isPersistentAccount( new FakeIdentityUser( true, false ) ) );
		$this->assertTrue( FeedbackAccess::isPersistentAccount( new FakeNamedUserNoTempMethod() ) );
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
