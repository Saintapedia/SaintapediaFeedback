<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Integration;

use ApiTestCase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Post-insert contract for the `saintapediafeedback` API module: once
 * FeedbackStore::tryInsertUnderLimit() has returned an id, the submission
 * has already succeeded, and nothing in the best-effort notification step
 * may turn that into an API error.
 *
 * Run through MediaWiki's own runner, which needs core's require-dev packages:
 *
 *   php vendor/bin/phpunit --group SaintapediaFeedback
 *
 * @group Database
 * @group SaintapediaFeedback
 * @covers \MediaWiki\Extension\SaintapediaFeedback\Api\ApiSubmitFeedback
 */
class ApiSubmitFeedbackTest extends ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		// See FeedbackStoreTest for why this is guarded: $tablesUsed was
		// removed from MediaWikiIntegrationTestCase in MW 1.45 (replaced by
		// automatic table-usage tracking) and setting it there is now a
		// deprecated dynamic property under PHP 8.4.
		if ( property_exists( $this, 'tablesUsed' ) ) {
			$this->tablesUsed[] = 'spf_feedback';
			$this->tablesUsed[] = 'spf_feedback_log';
		}
		$this->overrideConfigValues( [
			'SaintapediaFeedbackMode' => 'enterprise',
			'SaintapediaFeedbackRequireCaptcha' => false,
			'SaintapediaFeedbackNamespaces' => [ NS_MAIN ],
			'SaintapediaFeedbackEnterpriseRateLimit' => 1000,
			'SaintapediaFeedbackNotifyUsers' => [],
			'SaintapediaFeedbackNotifyWatchers' => false,
			// Email path deliberately ON, so the mailer hook below (which
			// throws) is actually reached -- this is what turns notification
			// into a real failure for this test, rather than a no-op.
			'SaintapediaFeedbackEnableEmail' => true,
			'SaintapediaFeedbackNotifyEmail' => 'curator@example.org',
		] );
	}

	private function existingMainPageTitle(): Title {
		$title = Title::newMainPage();
		if ( !$title->exists() ) {
			$this->editPage( $title, 'Test content for feedback API tests.' );
		}
		return $title;
	}

	public function testSubmissionSucceedsWhenNotificationIsHealthy(): void {
		$title = $this->existingMainPageTitle();

		[ $result ] = $this->doApiRequestWithToken( [
			'action' => 'saintapediafeedback',
			'pageid' => $title->getArticleID(),
			'categories' => 'inaccurate',
			'comment' => 'The date looks wrong.',
		] );

		$this->assertSame( 'success', $result['saintapediafeedback']['result'] );
		$this->assertGreaterThan( 0, $result['saintapediafeedback']['id'] );
	}

	/**
	 * The literal regression: a notification-step failure must not surface
	 * as an API error, and must not prevent the id from a real, already-
	 * committed row from coming back to the caller.
	 *
	 * Fault injection note: with FeedbackNotifier as a static class (not an
	 * injected service), the specific failure mode that shipped in
	 * production -- a PHP TypeError thrown at the notifyNew() call site,
	 * before its own body/try-catch ever runs -- cannot be reproduced here
	 * without reintroducing the bug itself. This test instead forces a
	 * real throw further inside the notification path (the email step, via
	 * the AlternateUserMailer hook) and asserts the same externally-visible
	 * contract: a stored submission still comes back as 'success'. Direct
	 * coverage of the outer call-site guard specifically would need
	 * FeedbackNotifier registered as an injectable service with a
	 * fault-injectable double -- noted as desirable, not required for 1.9.1.
	 */
	public function testSubmissionSucceedsWhenNotificationThrows(): void {
		$title = $this->existingMainPageTitle();

		$this->setTemporaryHook( 'AlternateUserMailer',
			static function () {
				throw new \RuntimeException( 'Simulated mailer outage' );
			}
		);

		[ $result ] = $this->doApiRequestWithToken( [
			'action' => 'saintapediafeedback',
			'pageid' => $title->getArticleID(),
			'categories' => 'inaccurate',
			'comment' => 'The date looks wrong.',
		] );

		$this->assertSame( 'success', $result['saintapediafeedback']['result'] );
		$id = $result['saintapediafeedback']['id'];
		$this->assertGreaterThan( 0, $id );

		// ...and the row really was persisted, not just an API response
		// that happens to look right.
		$store = MediaWikiServices::getInstance()->getService( 'SaintapediaFeedback.FeedbackStore' );
		$this->assertNotNull( $store->getById( $id ) );
	}
}
