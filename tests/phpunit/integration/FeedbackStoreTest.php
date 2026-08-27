<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Integration;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/**
 * Database-backed behaviour of FeedbackStore.
 *
 * The unit suite covers the pure helpers; everything that only holds true
 * against a real database lives here. Two of these are load-bearing privacy
 * guarantees that were previously asserted only by reading the code: that list
 * and export queries never materialize the contact email or the IP hash.
 *
 * Run through MediaWiki's own runner, which needs core's require-dev packages:
 *
 *   php vendor/bin/phpunit --group SaintapediaFeedback
 *
 * @group Database
 * @group SaintapediaFeedback
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackStore
 */
class FeedbackStoreTest extends MediaWikiIntegrationTestCase {

	private FeedbackStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed[] = 'spf_feedback';
		$this->tablesUsed[] = 'spf_feedback_log';
		$this->store = MediaWikiServices::getInstance()
			->getService( 'SaintapediaFeedback.FeedbackStore' );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = [] ): array {
		return $overrides + [
			'pageId'       => 4242,
			'namespace'    => NS_MAIN,
			'title'        => 'Test_Page',
			'userId'       => null,
			'ipHash'       => str_repeat( 'a', 64 ),
			'categories'   => [ 'inaccurate' ],
			'comment'      => 'The date looks wrong.',
			'contactEmail' => null,
			'mode'         => 'public',
		];
	}

	/* ------------------------------------------------------------- insert */

	public function testInsertAndFetch(): void {
		$id = $this->store->insert( $this->row() );
		$this->assertGreaterThan( 0, $id );

		$row = $this->store->getById( $id );
		$this->assertNotNull( $row );
		$this->assertSame( 'new', (string)$row->fb_status );
		$this->assertSame( 'The date looks wrong.', (string)$row->fb_comment );
		$this->assertSame( [ 'inaccurate' ], json_decode( $row->fb_categories, true ) );
	}

	public function testGetByIdReturnsNullForUnknownId(): void {
		$this->assertNull( $this->store->getById( 999999 ) );
		$this->assertNull( $this->store->getById( 0 ) );
		$this->assertNull( $this->store->getById( -1 ) );
	}

	/* -------------------------------------------------------- rate limits */

	public function testRateLimitBlocksAtTheCap(): void {
		$data = $this->row( [ 'ipHash' => str_repeat( 'b', 64 ) ] );
		$this->assertNotNull( $this->store->tryInsertUnderLimit( $data, 2 ) );
		$this->assertNotNull( $this->store->tryInsertUnderLimit( $data, 2 ) );
		$this->assertNull(
			$this->store->tryInsertUnderLimit( $data, 2 ),
			'The third submission from one IP must be refused at a limit of 2'
		);
	}

	public function testRateLimitOfZeroRefusesEverything(): void {
		$this->assertNull( $this->store->tryInsertUnderLimit( $this->row(), 0 ) );
	}

	public function testRateLimitWithoutAnIpHashIsRefused(): void {
		$this->assertNull(
			$this->store->tryInsertUnderLimit( $this->row( [ 'ipHash' => '' ] ), 10 ),
			'An empty IP hash cannot be rate limited, so it must not be accepted'
		);
	}

	public function testCountRecentByIpHashIsPerAddress(): void {
		$this->store->insert( $this->row( [ 'ipHash' => str_repeat( 'c', 64 ) ] ) );
		$this->store->insert( $this->row( [ 'ipHash' => str_repeat( 'c', 64 ) ] ) );
		$this->assertSame( 2, $this->store->countRecentByIpHash( str_repeat( 'c', 64 ) ) );
		$this->assertSame( 0, $this->store->countRecentByIpHash( str_repeat( 'd', 64 ) ) );
	}

	/* ------------------------------------------------------------ privacy */

	/**
	 * The contact email must never be materialized by a list query. This is
	 * the guarantee the separate saintapediafeedback-viewemail right rests on:
	 * if the column came back in the row, every caller would have it.
	 */
	public function testListQueriesNeverCarryEmailOrIpHash(): void {
		$id = $this->store->insert( $this->row( [
			'contactEmail' => 'reader@example.org',
			'ipHash'       => str_repeat( 'e', 64 ),
		] ) );

		foreach ( [
			'getDashboard' => $this->store->getDashboard( [ 'status' => 'all' ] ),
			'getForPage'   => $this->store->getForPage( 4242 ),
		] as $label => $rows ) {
			$this->assertNotEmpty( $rows, $label );
			$this->assertFalse(
				property_exists( $rows[0], 'fb_contact_email' ),
				"$label must not select the contact email column"
			);
			$this->assertFalse(
				property_exists( $rows[0], 'fb_ip_hash' ),
				"$label must not select the IP hash column"
			);
		}

		// …and the dedicated accessor still works, for holders of the right.
		$this->assertSame(
			[ $id => 'reader@example.org' ],
			$this->store->getContactEmailsById( [ $id ] )
		);
	}

	public function testExportsCarryNoEmailOrIpHash(): void {
		$this->store->insert( $this->row( [
			'contactEmail' => 'reader@example.org',
			'ipHash'       => str_repeat( 'f', 64 ),
		] ) );

		foreach ( [
			'exportDashboard' => $this->store->exportDashboard( [ 'status' => 'all' ] ),
			'exportForPage'   => $this->store->exportForPage( 4242 ),
		] as $label => $items ) {
			$this->assertNotEmpty( $items, $label );
			$encoded = json_encode( $items );
			$this->assertStringNotContainsString( 'reader@example.org', $encoded, $label );
			$this->assertStringNotContainsString( str_repeat( 'f', 64 ), $encoded, $label );
			foreach ( array_keys( $items[0] ) as $key ) {
				$this->assertStringNotContainsStringIgnoringCase( 'mail', $key, $label );
			}
		}
	}

	public function testGetContactEmailsIgnoresEmptyAndUnknownIds(): void {
		$withEmail = $this->store->insert( $this->row( [ 'contactEmail' => 'a@example.org' ] ) );
		$without   = $this->store->insert( $this->row() );

		$emails = $this->store->getContactEmailsById( [ $withEmail, $without, 999999 ] );
		$this->assertSame( [ $withEmail => 'a@example.org' ], $emails );
		$this->assertSame( [], $this->store->getContactEmailsById( [] ) );
	}

	/* ------------------------------------------------------------- status */

	public function testUpdateStatusWritesAnAuditEntry(): void {
		$id = $this->store->insert( $this->row() );

		$this->assertTrue( $this->store->updateStatus(
			$id, 'reviewed', null, 7, [ 'workNote' => 'Checked the source.' ] ) );

		$row = $this->store->getById( $id );
		$this->assertSame( 'reviewed', (string)$row->fb_status );
		$this->assertSame( 7, (int)$row->fb_status_user_id );

		$log = $this->store->getStatusLog( $id );
		$this->assertCount( 1, $log );
		$this->assertSame( 'new', (string)$log[0]->log_old_status );
		$this->assertSame( 'reviewed', (string)$log[0]->log_new_status );
		$this->assertSame( 7, (int)$log[0]->log_user_id );
		$this->assertSame( 'Checked the source.', (string)$log[0]->log_note );
	}

	public function testStatusLogAccumulatesInOrder(): void {
		$id = $this->store->insert( $this->row() );
		$this->store->updateStatus( $id, 'reviewed', null, 1 );
		$this->store->updateStatus( $id, 'actioned', null, 2 );

		$log = $this->store->getStatusLog( $id );
		$this->assertCount( 2, $log );
		$this->assertSame( 'new', (string)$log[0]->log_old_status );
		$this->assertSame( 'reviewed', (string)$log[0]->log_new_status );
		$this->assertSame( 'reviewed', (string)$log[1]->log_old_status );
		$this->assertSame( 'actioned', (string)$log[1]->log_new_status );
	}

	public function testStatusLogIsEmptyForUnknownIds(): void {
		$this->assertSame( [], $this->store->getStatusLog( 999999 ) );
		$this->assertSame( [], $this->store->getStatusLog( 0 ) );
	}

	/**
	 * The per-page view passes its page id so a forged feedback id belonging
	 * to another article cannot be mutated through it.
	 */
	public function testUpdateStatusHonoursThePageGuard(): void {
		$id = $this->store->insert( $this->row( [ 'pageId' => 100 ] ) );

		$this->assertFalse( $this->store->updateStatus( $id, 'dismissed', 999, 1 ) );
		$this->assertSame( 'new', (string)$this->store->getById( $id )->fb_status );

		$this->assertTrue( $this->store->updateStatus( $id, 'dismissed', 100, 1 ) );
		$this->assertSame( 'dismissed', (string)$this->store->getById( $id )->fb_status );
	}

	public function testBulkUpdateLogsEachRealTransition(): void {
		$a = $this->store->insert( $this->row( [ 'comment' => 'A' ] ) );
		$b = $this->store->insert( $this->row( [ 'comment' => 'B' ] ) );
		$this->store->updateStatus( $b, 'reviewed', null, 1 );

		$this->assertSame( 2, $this->store->updateStatusBulk( [ $a, $b ], 'actioned', 5 ) );

		// Each row records where it actually came from, not a shared placeholder.
		$logA = $this->store->getStatusLog( $a );
		$logB = $this->store->getStatusLog( $b );
		$this->assertSame( 'new', (string)end( $logA )->log_old_status );
		$this->assertSame( 'reviewed', (string)end( $logB )->log_old_status );
		$this->assertSame( 'actioned', (string)end( $logA )->log_new_status );
		$this->assertSame( 'actioned', (string)end( $logB )->log_new_status );
	}

	public function testBulkUpdateRejectsStatusesOutsideTheProcessActions(): void {
		$id = $this->store->insert( $this->row() );
		$this->assertSame( 0, $this->store->updateStatusBulk( [ $id ], 'new', 1 ) );
		$this->assertSame( 0, $this->store->updateStatusBulk( [], 'actioned', 1 ) );
	}

	/* ------------------------------------------------------------- counts */

	public function testCountsByStatusAndPage(): void {
		$a = $this->store->insert( $this->row( [ 'pageId' => 55, 'comment' => 'A' ] ) );
		$this->store->insert( $this->row( [ 'pageId' => 55, 'comment' => 'B' ] ) );
		$this->store->insert( $this->row( [ 'pageId' => 66, 'comment' => 'C' ] ) );
		$this->store->updateStatus( $a, 'actioned', null, 1 );

		$counts = $this->store->countByStatus( [] );
		$this->assertSame( 2, $counts['new'] );
		$this->assertSame( 1, $counts['actioned'] );
		$this->assertSame( 3, $counts['all'] );

		$page = $this->store->getPageCounts( 55 );
		$this->assertSame( 1, $page['new'] );
		$this->assertSame( 1, $page['open'] );
		$this->assertSame( 1, $page['resolved'] );
		$this->assertSame( 2, $page['total'] );
	}

	/* ------------------------------------------------------------ filters */

	public function testSearchMatchesCommentAndPageTitle(): void {
		$this->store->insert( $this->row( [ 'comment' => 'The founding date is wrong' ] ) );
		$this->store->insert( $this->row( [ 'comment' => 'Broken link in refs' ] ) );

		$this->assertCount( 1, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => 'founding' ] ) );
		// Both rows are on Test_Page, so a title match returns both — which is
		// the point: search covers the page title, not just the comment.
		$this->assertCount( 2, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => 'Test_Page' ] ) );
		$this->assertCount( 0, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => 'nonexistent' ] ) );
	}

	/**
	 * A literal % must be searched for, not treated as a LIKE wildcard.
	 */
	public function testSearchTreatsWildcardsAsLiterals(): void {
		$this->store->insert( $this->row( [ 'comment' => 'only 50% complete' ] ) );
		$this->store->insert( $this->row( [ 'comment' => 'no percentage here' ] ) );
		$this->store->insert( $this->row( [ 'comment' => 'an under_score here' ] ) );

		// A literal % must match the one comment containing it, not act as a
		// LIKE wildcard and match everything.
		$this->assertCount( 1, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => '50%' ] ) );
		// Likewise _ must not match any single character.
		$this->assertCount( 1, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => 'under_score' ] ) );
		$this->assertCount( 0, $this->store->getDashboard(
			[ 'status' => 'all', 'search' => 'underXscore' ] ) );
	}

	public function testCategoryFilter(): void {
		$this->store->insert( $this->row( [ 'categories' => [ 'inaccurate' ] ] ) );
		$this->store->insert( $this->row( [ 'categories' => [ 'broken-links' ] ] ) );

		$this->assertCount( 1, $this->store->getDashboard(
			[ 'status' => 'all', 'category' => 'inaccurate' ] ) );
		$this->assertCount( 2, $this->store->getDashboard(
			[ 'status' => 'all', 'category' => 'all' ] ) );
	}

	public function testSortOrder(): void {
		$first  = $this->store->insert( $this->row( [ 'comment' => 'first' ] ) );
		$second = $this->store->insert( $this->row( [ 'comment' => 'second' ] ) );

		$newest = $this->store->getDashboard( [ 'status' => 'all', 'sort' => 'newest' ] );
		$oldest = $this->store->getDashboard( [ 'status' => 'all', 'sort' => 'oldest' ] );
		$this->assertSame( $second, (int)$newest[0]->fb_id );
		$this->assertSame( $first, (int)$oldest[0]->fb_id );
	}

	public function testPagination(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->store->insert( $this->row( [ 'comment' => "item $i" ] ) );
		}
		$this->assertSame( 5, $this->store->countDashboard( [ 'status' => 'all' ] ) );
		$this->assertCount( 2, $this->store->getDashboard( [ 'status' => 'all' ], 2, 0 ) );
		$this->assertCount( 1, $this->store->getDashboard( [ 'status' => 'all' ], 2, 4 ) );
	}

	/* ------------------------------------------- public resolutions + LLM */

	/**
	 * Only actioned items explicitly marked public appear on the reader-facing
	 * resolutions list — a dismissed item must never leak onto it.
	 */
	public function testPublicResolutionsOnlyListsPublicActionedItems(): void {
		$public   = $this->store->insert( $this->row( [ 'pageId' => 77, 'comment' => 'public' ] ) );
		$private  = $this->store->insert( $this->row( [ 'pageId' => 77, 'comment' => 'private' ] ) );
		$rejected = $this->store->insert( $this->row( [ 'pageId' => 77, 'comment' => 'rejected' ] ) );

		$this->store->updateStatus( $public, 'actioned', null, 1,
			[ 'resolutionPublic' => true, 'resolutionSummary' => 'Fixed the date.' ] );
		$this->store->updateStatus( $private, 'actioned', null, 1,
			[ 'resolutionPublic' => false ] );
		$this->store->updateStatus( $rejected, 'dismissed', null, 1 );

		$rows = $this->store->getPublicResolutions( 77 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $public, (int)$rows[0]->fb_id );
		$this->assertSame( 'Fixed the date.', (string)$rows[0]->fb_resolution_summary );
	}

	/**
	 * Dismissing an item that was previously public must take it off the list.
	 */
	public function testDismissingRemovesAnItemFromThePublicList(): void {
		$id = $this->store->insert( $this->row( [ 'pageId' => 88 ] ) );
		$this->store->updateStatus( $id, 'actioned', null, 1, [ 'resolutionPublic' => true ] );
		$this->assertCount( 1, $this->store->getPublicResolutions( 88 ) );

		$this->store->updateStatus( $id, 'dismissed', null, 1 );
		$this->assertCount( 0, $this->store->getPublicResolutions( 88 ) );
	}

	public function testLlmBatchMarksRowsOnce(): void {
		$a = $this->store->insert( $this->row( [ 'comment' => 'A' ] ) );
		$b = $this->store->insert( $this->row( [ 'comment' => 'B' ] ) );
		$closed = $this->store->insert( $this->row( [ 'comment' => 'C' ] ) );
		$this->store->updateStatus( $closed, 'dismissed', null, 1 );

		$pending = $this->store->getPendingLlmBatch( 10 );
		$ids = array_map( static fn ( $r ) => (int)$r->fb_id, $pending );
		sort( $ids );
		$this->assertSame( [ $a, $b ], $ids, 'Dismissed items are not sent for processing' );

		$this->store->markLlmProcessed( $ids );
		$this->assertSame( [], $this->store->getPendingLlmBatch( 10 ) );
	}
}
