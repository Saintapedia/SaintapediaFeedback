<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Integration;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackNotifier;
use MediaWikiIntegrationTestCase;

/**
 * Regression coverage for the 2026-09-10 external code review: notifyNew()
 * declared a `Title` parameter that (via a stale `use Title;` import)
 * resolved to the global alias MediaWiki removed in 1.44. A genuine
 * MediaWiki\Title\Title produced by core's own TitleFactory -- exactly what
 * ApiSubmitFeedback passes in production -- then failed with a TypeError,
 * *after* the feedback row had already been committed to the database.
 *
 * Run through MediaWiki's own runner, which needs core's require-dev packages:
 *
 *   php vendor/bin/phpunit --group SaintapediaFeedback
 *
 * @group SaintapediaFeedback
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackNotifier
 */
class FeedbackNotifierTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Notification features off: this test is about the Title parameter
		// type, not about Echo/email delivery, which have no coverage here.
		$this->overrideConfigValues( [
			'SaintapediaFeedbackNotifyUsers' => [],
			'SaintapediaFeedbackNotifyWatchers' => false,
			'SaintapediaFeedbackNotifyEmail' => '',
		] );
	}

	public function testNotifyNewAcceptsARealTitleFromTitleFactory(): void {
		$title = $this->getServiceContainer()
			->getTitleFactory()
			->newFromText( 'Main Page' );
		$this->assertInstanceOf( \MediaWiki\Title\Title::class, $title );

		// The assertion is that this does not throw a TypeError. notifyNew()
		// has its own internal try/catch, so a regression here would not
		// surface as a thrown exception in this test -- it would instead log
		// to the SaintapediaFeedback channel and silently swallow the error,
		// which is precisely the failure mode this test exists to catch.
		// Assert on the debug log instead of assuming "didn't throw" is
		// enough.
		$loggedError = null;
		$this->setLogger( 'SaintapediaFeedback', new class( $loggedError ) extends \Psr\Log\AbstractLogger {
			private $sink;
			public function __construct( &$sink ) {
				$this->sink = &$sink;
			}
			public function log( $level, $message, array $context = [] ): void {
				$this->sink = $this->sink ?? $message;
			}
		} );

		FeedbackNotifier::notifyNew(
			1,
			$title,
			[ 'inaccurate' ],
			'Test comment',
			$this->getTestUser()->getUser()
		);

		$this->assertNull(
			$loggedError,
			'notifyNew() logged an internal failure -- likely a Title type mismatch: ' . (string)$loggedError
		);
	}
}
