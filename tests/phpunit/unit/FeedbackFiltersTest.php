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

	public function testSanitizeSearchTrimsAndPreservesLiterals(): void {
		$this->assertSame( '', FeedbackFilters::sanitizeSearch( null ) );
		$this->assertSame( '', FeedbackFilters::sanitizeSearch( '   ' ) );
		$this->assertSame( 'hello', FeedbackFilters::sanitizeSearch( '  hello  ' ) );
		// LIKE metacharacters are escaped by buildLike(); keep them so users can find "50%" etc.
		$this->assertSame( '50%', FeedbackFilters::sanitizeSearch( '50%' ) );
		$this->assertSame( 'under_score', FeedbackFilters::sanitizeSearch( 'under_score' ) );
		$this->assertSame( '%a_b\\c%', FeedbackFilters::sanitizeSearch( '%a_b\\c%' ) );
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

	public function testStatusUpdateOptsOmitsEmptyWorkNote(): void {
		$opts = FeedbackFilters::statusUpdateOpts( '', 'Fixed it', true );
		$this->assertArrayNotHasKey( 'workNote', $opts );
		$this->assertTrue( $opts['resolutionPublic'] );
		$this->assertSame( 'Fixed it', $opts['resolutionSummary'] );
	}

	public function testStatusUpdateOptsOmitsMissingWorkNote(): void {
		$opts = FeedbackFilters::statusUpdateOpts( null, null, false );
		$this->assertArrayNotHasKey( 'workNote', $opts );
		$this->assertFalse( $opts['resolutionPublic'] );
		$this->assertSame( '', $opts['resolutionSummary'] );
	}

	public function testStatusUpdateOptsIncludesNonEmptyWorkNote(): void {
		$opts = FeedbackFilters::statusUpdateOpts( "  updated sources  ", '', true );
		$this->assertSame( '  updated sources  ', $opts['workNote'] );
	}

	public function testWithOffsetOmitsZeroAndNegative(): void {
		$base = [ 'status' => 'new' ];
		$this->assertSame( $base, FeedbackFilters::withOffset( $base, 0 ) );
		$this->assertSame( $base, FeedbackFilters::withOffset( $base, -10 ) );
	}

	public function testWithOffsetKeepsPositivePager(): void {
		$this->assertSame(
			[ 'status' => 'new', 'offset' => 50 ],
			FeedbackFilters::withOffset( [ 'status' => 'new' ], 50 )
		);
	}

	public function testClampOffsetPastEndGoesToLastPage(): void {
		$this->assertSame( 0, FeedbackFilters::clampOffset( 100, 50, 50 ) );
		$this->assertSame( 50, FeedbackFilters::clampOffset( 100, 75, 50 ) );
		// offset == total is already past the last row (0-based)
		$this->assertSame( 0, FeedbackFilters::clampOffset( 50, 50, 50 ) );
	}

	public function testClampOffsetInRangeUnchanged(): void {
		$this->assertSame( 0, FeedbackFilters::clampOffset( 0, 50, 50 ) );
		$this->assertSame( 50, FeedbackFilters::clampOffset( 50, 75, 50 ) );
		$this->assertSame( 0, FeedbackFilters::clampOffset( 0, 0, 50 ) );
		$this->assertSame( 0, FeedbackFilters::clampOffset( -5, 50, 50 ) );
	}
}
