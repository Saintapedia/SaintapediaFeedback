<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Integration;

use ApiTestCase;
use ApiUsageException;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use MediaWiki\MediaWikiServices;

/**
 * Read-only list=saintapediafeedback: permission gate (view + export),
 * PII exclusion, filters, and keyset continuation.
 *
 *   php vendor/bin/phpunit --group SaintapediaFeedback
 *
 * @group API
 * @group Database
 * @group SaintapediaFeedback
 * @covers \MediaWiki\Extension\SaintapediaFeedback\Api\ApiQuerySaintapediaFeedback
 */
class ApiQuerySaintapediaFeedbackTest extends ApiTestCase {

	private FeedbackStore $store;

	protected function setUp(): void {
		parent::setUp();
		if ( property_exists( $this, 'tablesUsed' ) ) {
			$this->tablesUsed[] = 'spf_feedback';
			$this->tablesUsed[] = 'spf_feedback_log';
		}
		$this->overrideConfigValues( [
			'SaintapediaFeedbackMode' => 'public',
			'SaintapediaFeedbackEnableEmail' => true,
			'SaintapediaFeedbackAccessGroups' => [ 'sysop' ],
			'SaintapediaFeedbackEmailAccessGroups' => [ 'sysop' ],
			'SaintapediaFeedbackExportAccessGroups' => [ 'sysop' ],
		] );
		$this->setGroupPermissions( [
			'feedbackbot' => [
				'saintapediafeedback-view' => true,
				'saintapediafeedback-export' => true,
			],
			'viewonly' => [ 'saintapediafeedback-view' => true ],
		] );
		$this->store = MediaWikiServices::getInstance()->getService( 'SaintapediaFeedback.FeedbackStore' );
	}

	private function insert( array $o = [] ): int {
		return $this->store->insert( $o + [
			'pageId' => 4242, 'namespace' => NS_MAIN, 'title' => 'Test_Page',
			'userId' => null, 'ipHash' => str_repeat( 'a', 64 ),
			'categories' => [ 'inaccurate' ], 'comment' => 'Date looks wrong.',
			'contactEmail' => 'reader@example.org', 'mode' => 'public',
		] );
	}

	private function list( array $params, $user ): array {
		[ $result ] = $this->doApiRequest(
			$params + [ 'action' => 'query', 'list' => 'saintapediafeedback' ],
			null, false, $user
		);
		return $result;
	}

	public function testAnonIsRejected(): void {
		$this->expectApiErrorCode( 'notloggedin' );
		$this->list( [], $this->getServiceContainer()->getUserFactory()->newAnonymous() );
	}

	public function testViewWithoutExportIsRejected(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$this->list( [], $this->getTestUser( [ 'viewonly' ] )->getUser() );
	}

	public function testBotGroupListsWithoutPii(): void {
		$id = $this->insert();
		$result = $this->list( [], $this->getTestUser( [ 'feedbackbot' ] )->getUser() );
		$items = $result['query']['saintapediafeedback'];
		$this->assertCount( 1, $items );
		$this->assertSame( $id, $items[0]['id'] );
		foreach ( [ 'id', 'pageId', 'pageTitle', 'ns', 'timestamp', 'categories', 'comment', 'status', 'mode' ] as $k ) {
			$this->assertArrayHasKey( $k, $items[0] );
		}
		$this->assertArrayNotHasKey( 'contactEmail', $items[0] );
		$this->assertStringNotContainsString( 'reader@example.org', json_encode( $result ) );
		$this->assertStringNotContainsString( str_repeat( 'a', 64 ), json_encode( $result ) );
	}

	public function testEmailNeedsViewEmail(): void {
		$this->insert();
		try {
			$this->list( [ 'spfincludeemail' => 1 ], $this->getTestUser( [ 'feedbackbot' ] )->getUser() );
			$this->fail( 'expected permissiondenied' );
		} catch ( ApiUsageException $e ) {
			$this->assertTrue( $e->getStatusValue()->hasMessage( 'apierror-permissiondenied' ) );
		}
		$result = $this->list( [ 'spfincludeemail' => 1 ], $this->getTestSysop()->getUser() );
		$this->assertSame( 'reader@example.org', $result['query']['saintapediafeedback'][0]['contactEmail'] );
	}

	public function testStatusCategoryAndPageFilters(): void {
		$a = $this->insert();
		$this->insert( [ 'pageId' => 7, 'categories' => [ 'outdated' ] ] );
		$this->store->updateStatus( $a, 'reviewed' );
		$bot = $this->getTestUser( [ 'feedbackbot' ] )->getUser();

		$this->assertCount( 1, $this->list( [ 'spfstatus' => 'reviewed' ], $bot )['query']['saintapediafeedback'] );
		$this->assertCount( 1, $this->list( [ 'spfcategory' => 'outdated' ], $bot )['query']['saintapediafeedback'] );
		$this->assertCount( 1, $this->list( [ 'spfpageid' => 7 ], $bot )['query']['saintapediafeedback'] );
		$this->assertCount( 2, $this->list( [ 'spfstatus' => 'new|reviewed' ], $bot )['query']['saintapediafeedback'] );
	}

	public function testSinceFilter(): void {
		$this->insert();
		$bot = $this->getTestUser( [ 'feedbackbot' ] )->getUser();
		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 );
		$this->assertSame( [], $this->list( [ 'spfsince' => $future ], $bot )['query']['saintapediafeedback'] ?? [] );
	}

	public function testContinuationWalksEveryRowOnce(): void {
		$ids = [ $this->insert(), $this->insert(), $this->insert() ];
		$bot = $this->getTestUser( [ 'feedbackbot' ] )->getUser();

		$seen = [];
		$params = [ 'spflimit' => 2 ];
		for ( $i = 0; $i < 5; $i++ ) {
			$result = $this->list( $params, $bot );
			foreach ( $result['query']['saintapediafeedback'] ?? [] as $item ) {
				$seen[] = $item['id'];
			}
			if ( !isset( $result['continue'] ) ) {
				break;
			}
			$params = [ 'spflimit' => 2 ] + $result['continue'];
		}
		sort( $seen );
		sort( $ids );
		$this->assertSame( $ids, $seen );
	}

	public function testBadContinueIsRejected(): void {
		$this->expectApiErrorCode( 'badcontinue' );
		$this->list( [ 'spfcontinue' => 'nope' ], $this->getTestUser( [ 'feedbackbot' ] )->getUser() );
	}

	public function testModuleIsReadOnly(): void {
		$main = new \ApiMain( new \FauxRequest( [] ) );
		$query = $main->getModuleManager()->getModule( 'query' );
		$module = $query->getModuleManager()->getModule( 'saintapediafeedback', 'list' );
		$this->assertFalse( $module->isWriteMode() );
		$this->assertFalse( $module->mustBePosted() );
		$this->assertFalse( $module->needsToken() );
	}
}
