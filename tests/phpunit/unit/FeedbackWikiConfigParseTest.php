<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackWikiConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackWikiConfig::parseLines
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackWikiConfig::parseBoolToken
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackWikiConfig::resolveBool
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackWikiConfig::resolveInt
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackWikiConfig::overlayReadFailureMessage
 */
class FeedbackWikiConfigParseTest extends TestCase {

	public function testParseLinesIgnoresCommentsAndBlanks(): void {
		$text = <<<TEXT
# comment
5

; old style comment
10
TEXT;
		$this->assertSame( [ '5', '10' ], FeedbackWikiConfig::parseLines( $text ) );
	}

	public function testParseLinesStripsWikiListMarkup(): void {
		$this->assertSame( [ 'false' ], FeedbackWikiConfig::parseLines( '* false' ) );
		$this->assertSame( [ '10' ], FeedbackWikiConfig::parseLines( "* 10\n" ) );
	}

	public function testParseLinesStripsInlineComments(): void {
		$this->assertSame( [ 'true' ], FeedbackWikiConfig::parseLines( 'true # temporary override' ) );
		$this->assertSame( [ 'Admin' ], FeedbackWikiConfig::parseLines( 'Admin # notify lead editor' ) );
	}

	public function testParseLinesDeduplicates(): void {
		$this->assertSame( [ 'Admin', 'EditorBot' ], FeedbackWikiConfig::parseLines( "Admin\nEditorBot\nAdmin\n" ) );
	}

	public function testParseLinesEmptyReturnsEmpty(): void {
		$this->assertSame( [], FeedbackWikiConfig::parseLines( '' ) );
		$this->assertSame( [], FeedbackWikiConfig::parseLines( "# only comments\n" ) );
	}

	public function testParseBoolTokenRecognizesTrueValues(): void {
		foreach ( [ 'true', 'TRUE', 'yes', 'on', '1' ] as $value ) {
			$this->assertTrue( FeedbackWikiConfig::parseBoolToken( $value ), "Expected true for '$value'" );
		}
	}

	public function testParseBoolTokenRecognizesFalseValues(): void {
		foreach ( [ 'false', 'FALSE', 'no', 'off', '0' ] as $value ) {
			$this->assertFalse( FeedbackWikiConfig::parseBoolToken( $value ), "Expected false for '$value'" );
		}
	}

	public function testParseBoolTokenUnrecognizedReturnsNull(): void {
		$this->assertNull( FeedbackWikiConfig::parseBoolToken( 'maybe' ) );
		$this->assertNull( FeedbackWikiConfig::parseBoolToken( '' ) );
		$this->assertNull( FeedbackWikiConfig::parseBoolToken( '2' ) );
	}

	public function testResolveBoolEmptyUsesPhpValue(): void {
		$this->assertTrue( FeedbackWikiConfig::resolveBool( '', true ) );
		$this->assertFalse( FeedbackWikiConfig::resolveBool( '', false ) );
		$this->assertFalse( FeedbackWikiConfig::resolveBool( "# comment only\n", false ) );
	}

	public function testResolveBoolRecognizesOverlay(): void {
		$this->assertTrue( FeedbackWikiConfig::resolveBool( 'true', false ) );
		$this->assertFalse( FeedbackWikiConfig::resolveBool( '* false', true ) );
	}

	public function testResolveBoolReadFailureUsesOnReadErrorWhenSet(): void {
		// Captcha: overlay exception must not fall back to PHP=false.
		$this->assertTrue( FeedbackWikiConfig::resolveBool( '', false, true, true ) );
		// Without $onReadError, a failed read uses PHP (rate limit etc.).
		$this->assertFalse( FeedbackWikiConfig::resolveBool( '', false, true, null ) );
		$this->assertTrue( FeedbackWikiConfig::resolveBool( '', true, true, null ) );
	}

	public function testResolveIntEmptyUsesPhpValue(): void {
		$this->assertSame( 5, FeedbackWikiConfig::resolveInt( '', 5 ) );
		$this->assertSame( 5, FeedbackWikiConfig::resolveInt( "# comment\n", 5 ) );
		$this->assertSame( 5, FeedbackWikiConfig::resolveInt( 'nope', 5 ) );
	}

	public function testResolveIntAcceptsZeroAsRejectAll(): void {
		// 0 is a valid override (tryInsertUnderLimit treats limit < 1 as reject).
		$this->assertSame( 0, FeedbackWikiConfig::resolveInt( '0', 5 ) );
		$this->assertSame( 0, FeedbackWikiConfig::resolveInt( '* 0', 5 ) );
	}

	public function testResolveIntOverrideAndReadFailure(): void {
		$this->assertSame( 10, FeedbackWikiConfig::resolveInt( '10', 5 ) );
		$this->assertSame( 5, FeedbackWikiConfig::resolveInt( '10', 5, true ) );
	}

	public function testOverlayReadFailureMessageDistinguishesFailClosed(): void {
		$this->assertSame(
			'SaintapediaFeedback: wiki-config read failed for SaintapediaFeedbackRequireCaptchaPage; failing closed. boom',
			FeedbackWikiConfig::overlayReadFailureMessage(
				'SaintapediaFeedbackRequireCaptchaPage',
				true,
				'boom'
			)
		);
		$this->assertSame(
			'SaintapediaFeedback: wiki-config read failed for SaintapediaFeedbackShowPublicCountsPage; using PHP value.',
			FeedbackWikiConfig::overlayReadFailureMessage(
				'SaintapediaFeedbackShowPublicCountsPage',
				false
			)
		);
	}
}
