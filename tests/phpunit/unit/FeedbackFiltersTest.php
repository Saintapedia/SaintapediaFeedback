<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackFilters;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackFilters
 */
class FeedbackFiltersTest extends TestCase {

	public function testNormalizeStatusDefaultsAndAllows(): void {
		$this->assertSame( 'new', FeedbackFilters::normalizeStatus( null ) );
		$this->assertSame( 'new', FeedbackFilters::normalizeStatus( 'nope' ) );
		$this->assertSame( 'all', FeedbackFilters::normalizeStatus( 'all' ) );
		$this->assertSame( 'reviewed', FeedbackFilters::normalizeStatus( 'reviewed' ) );
		$this->assertSame( 'actioned', FeedbackFilters::normalizeStatus( 'actioned' ) );
		$this->assertSame( 'dismissed', FeedbackFilters::normalizeStatus( 'dismissed' ) );
	}

	public function testNormalizeCategory(): void {
		$this->assertSame( 'all', FeedbackFilters::normalizeCategory( null ) );
		$this->assertSame( 'all', FeedbackFilters::normalizeCategory( 'evil' ) );
		$this->assertSame( 'broken-links', FeedbackFilters::normalizeCategory( 'broken-links' ) );
	}

	public function testNormalizeSort(): void {
		$this->assertSame( 'newest', FeedbackFilters::normalizeSort( null ) );
		$this->assertSame( 'newest', FeedbackFilters::normalizeSort( 'random' ) );
		$this->assertSame( 'oldest', FeedbackFilters::normalizeSort( 'oldest' ) );
	}

	public function testSanitizeSearchStripsWildcardsAndTrims(): void {
		$this->assertSame( '', FeedbackFilters::sanitizeSearch( null ) );
		$this->assertSame( '', FeedbackFilters::sanitizeSearch( '   ' ) );
		$this->assertSame( 'hello', FeedbackFilters::sanitizeSearch( '  hello  ' ) );
		$this->assertSame( 'abc', FeedbackFilters::sanitizeSearch( '%a_b\\c%' ) );
	}

	public function testSanitizeSearchMaxLength(): void {
		$long = str_repeat( 'x', FeedbackFilters::MAX_SEARCH_LENGTH + 50 );
		$out = FeedbackFilters::sanitizeSearch( $long );
		$this->assertSame( FeedbackFilters::MAX_SEARCH_LENGTH, mb_strlen( $out ) );
	}

	public function testProcessActions(): void {
		$this->assertSame(
			[ 'reviewed', 'actioned', 'dismissed' ],
			FeedbackFilters::processActions()
		);
	}
}
