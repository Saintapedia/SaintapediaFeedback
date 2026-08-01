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

	public function testParseEmptyReturnsEmpty(): void {
		$this->assertSame( [], FeedbackAccess::parseGroupList( '' ) );
		$this->assertSame( [], FeedbackAccess::parseGroupList( "# only comments\n" ) );
	}

	public function testDefaultGroupsIsUser(): void {
		$this->assertSame( [ 'user' ], FeedbackAccess::DEFAULT_GROUPS );
	}
}
