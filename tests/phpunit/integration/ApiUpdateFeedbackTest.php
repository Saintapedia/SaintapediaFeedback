<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Integration;

use ApiTestCase;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use MediaWiki\MediaWikiServices;

/**
 * Write module action=saintapediafeedbackupdate: permission gate
 * (view + process), validation, all-or-nothing id check, audit actor,
 * private-by-default resolutions, and read-back via spfincluderesolution.
 *
 *   php vendor/bin/phpunit --group SaintapediaFeedback
 *
 * @group API
 * @group Database
 * @group SaintapediaFeedback
 * @covers \MediaWiki\Extension\SaintapediaFeedback\Api\ApiUpdateFeedback
 */
class ApiUpdateFeedbackTest extends ApiTestCase {

	private FeedbackStore $store;

	protected function setUp(): void {
		parent::setUp();
		if ( property_exists( $this, 'tablesUsed' ) ) {
			$this->tablesUsed[] = 'spf_feedback';
			$this->tablesUsed[] = 'spf_feedback_log';
		}
		$this->overrideConfigValues( [
			'SaintapediaFeedbackMode' => 'public',
			'SaintapediaFeedbackAccessGroups' => [ 'sysop' ],
			'SaintapediaFeedbackExportAccessGroups' => [ 'sysop' ],
		] );
		$this->setGroupPermissions( [
			'feedbackbot' => [
				'saintapediafeedback-view' => true,
				'saintapediafeedback-export' => true,
				'saintapediafeedback-process' => true,
			],
			'viewonly' => [ 'saintapediafeedback-view' => true ],
		] );
		$this->store = MediaWikiServices::getInstance()->getService( 'SaintapediaFeedback.FeedbackStore' );
	}

	private function insert(): int {
		return $this->store->insert( [
			'pageId' => 4242, 'namespace' => NS_MAIN, 'title' => 'Test_Page',
			'userId' => null, 'ipHash' => str_repeat( 'a', 64 ),
			'categories' => [ 'inaccurate' ], 'comment' => 'Date looks wrong.',
			'contactEmail' => 'reader@example.org', 'mode' => 'public',
		] );
	}

	private function update( array $params, $user ): array {
		[ $result ] = $this->doApiRequestWithToken(
			$params + [ 'action' => 'saintapediafeedbackupdate' ],
			null, $user
		);
		return $result['saintapediafeedbackupdate'];
	}

	private function bot() {
		return $this->getTestUser( [ 'feedbackbot' ] )->getUser();
	}

	public function testViewOnlyUserIsRejected(): void {
		$id = $this->insert();
		$this->expectApiErrorCode( 'permissiondenied' );
		$this->update( [ 'ids' => $id, 'status' => 'reviewed' ],
			$this->getTestUser( [ 'viewonly' ] )->getUser() );
	}

	public function testNewIsNotASettableStatus(): void {
		$id = $this->insert();
		$this->expectApiErrorCode( 'badstatus' );
		$this->update( [ 'ids' => $id, 'status' => 'new' ], $this->bot() );
	}

	public function testUnknownIdWritesNothing(): void {
		$id = $this->insert();
		try {
			$this->update( [ 'ids' => $id . '|999999', 'status' => 'reviewed' ], $this->bot() );
			$this->fail( 'Expected nosuchfeedback' );
		} catch ( \ApiUsageException $e ) {
			$this->assertTrue( $e->getStatusValue()->hasMessage( 'apierror-saintapediafeedback-nosuchfeedback' ) );
		}
		$this->assertSame( 'new', $this->store->getById( $id )->fb_status );
	}

	public function testSummaryOnlyWithActioned(): void {
		$id = $this->insert();
		$this->expectApiErrorCode( 'actionedonly' );
		$this->update( [ 'ids' => $id, 'status' => 'reviewed', 'summary' => 'x' ], $this->bot() );
	}

	public function testSummaryTooLong(): void {
		$id = $this->insert();
		$this->expectApiErrorCode( 'toolong' );
		$this->update( [ 'ids' => $id, 'status' => 'actioned', 'summary' => str_repeat( 'x', 1001 ) ],
			$this->bot() );
	}

	public function testReviewedRecordsApiUserAndNote(): void {
		$id = $this->insert();
		$bot = $this->bot();
		$out = $this->update( [ 'ids' => $id, 'status' => 'reviewed', 'note' => 'Checked.' ], $bot );
		$this->assertSame( 'success', $out['result'] );
		$this->assertSame( 1, $out['updated'] );
		$this->assertSame( 'new', $out['items'][0]['oldstatus'] );

		$row = $this->store->getById( $id );
		$this->assertSame( 'reviewed', $row->fb_status );
		$this->assertSame( $bot->getId(), (int)$row->fb_status_user_id );
		$this->assertSame( 'Checked.', $row->fb_work_note );

		$log = $this->store->getStatusLog( $id );
		$this->assertCount( 1, $log );
		$this->assertSame( $bot->getId(), (int)$log[0]->log_user_id );
		$this->assertSame( 'Checked.', $log[0]->log_note );
	}

	public function testActionedIsPrivateByDefault(): void {
		$id = $this->insert();
		$out = $this->update( [ 'ids' => $id, 'status' => 'actioned', 'summary' => 'Fixed.' ], $this->bot() );
		$this->assertFalse( $out['items'][0]['public'] );
		$row = $this->store->getById( $id );
		$this->assertSame( 0, (int)$row->fb_resolution_public );
		$this->assertSame( 'Fixed.', $row->fb_resolution_summary );
	}

	public function testActionedPrivateReadBackIsFalse(): void {
		$id = $this->insert();
		$bot = $this->bot();
		$this->update( [ 'ids' => $id, 'status' => 'actioned', 'summary' => 'Fixed.' ], $bot );
		[ $result ] = $this->doApiRequest( [
			'action' => 'query', 'list' => 'saintapediafeedback',
			'spfstatus' => 'actioned', 'spfincluderesolution' => 1,
		], null, false, $bot );
		$this->assertFalse( $result['query']['saintapediafeedback'][0]['resolutionPublic'] );
	}

	public function testZeroIdIsRejectedNotDropped(): void {
		$id = $this->insert();
		$this->expectException( \ApiUsageException::class );
		$this->update( [ 'ids' => $id . '|0', 'status' => 'reviewed' ], $this->bot() );
	}

	public function testUnconfiguredRateLimitStillUpdates(): void {
		$id = $this->insert();
		$out = $this->update( [ 'ids' => $id, 'status' => 'reviewed' ], $this->bot() );
		$this->assertSame( 'success', $out['result'] );
	}

	public function testActionedPublicAndReadBack(): void {
		$id = $this->insert();
		$bot = $this->bot();
		$this->update( [ 'ids' => $id, 'status' => 'actioned', 'summary' => 'Fixed.', 'public' => 1 ], $bot );
		[ $result ] = $this->doApiRequest( [
			'action' => 'query', 'list' => 'saintapediafeedback',
			'spfstatus' => 'actioned', 'spfincluderesolution' => 1,
		], null, false, $bot );
		$item = $result['query']['saintapediafeedback'][0];
		$this->assertSame( 'Fixed.', $item['resolutionSummary'] );
		$this->assertTrue( $item['resolutionPublic'] );
		$this->assertSame( $bot->getName(), $item['statusUser'] );
		$this->assertArrayNotHasKey( 'contactEmail', $item );
	}

	public function testBulkUpdate(): void {
		$a = $this->insert();
		$b = $this->insert();
		$out = $this->update( [ 'ids' => "$a|$b", 'status' => 'dismissed' ], $this->bot() );
		$this->assertSame( 2, $out['updated'] );
		$this->assertSame( 'dismissed', $this->store->getById( $b )->fb_status );
	}

	public function testMoreThanFiftyIdsRejected(): void {
		$this->expectApiErrorCode( 'toomanyvalues' );
		$this->update( [ 'ids' => implode( '|', range( 1, 51 ) ), 'status' => 'reviewed' ], $this->bot() );
	}

	public function testDefaultListOutputUnchanged(): void {
		$id = $this->insert();
		$bot = $this->bot();
		$this->update( [ 'ids' => $id, 'status' => 'reviewed', 'note' => 'secret' ], $bot );
		[ $result ] = $this->doApiRequest(
			[ 'action' => 'query', 'list' => 'saintapediafeedback', 'spfstatus' => '*' ],
			null, false, $bot
		);
		$this->assertArrayNotHasKey( 'workNote', $result['query']['saintapediafeedback'][0] );
	}
}
