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

		// Note on what this test does and doesn't catch: if the Title-import
		// regression reappeared, this call would throw an *uncaught*
		// TypeError right here -- a parameter-type mismatch happens at the
		// call site, before notifyNew()'s body (and its internal try/catch)
		// ever runs, so PHPUnit would report this test as an error, not a
		// failed assertion below. That exact mechanism is what
		// testUnguardedCallThrowsOnAWrongTypeTitleArgument pins directly.
		// What the log assertion below covers is a different, narrower
		// class of failure: something *inside* notifyNew()'s body (once
		// execution has actually entered it) throwing and being silently
		// caught by its own internal try/catch -- "didn't throw" alone
		// wouldn't reveal that, since the internal catch's whole job is to
		// swallow it.
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

	/**
	 * Reproduces the actual production failure mode directly: a PHP
	 * TypeError from a wrong-typed argument at the notifyNew() call site
	 * is NOT caught by notifyNew()'s own internal try/catch, because the
	 * type check happens before the method body (and that try/catch)
	 * ever runs. This is why ApiSubmitFeedback needs its own try/catch
	 * around the call, not just FeedbackNotifier's internal one.
	 *
	 * @phpcs:disable -- deliberately wrong argument type
	 */
	public function testUnguardedCallThrowsOnAWrongTypeTitleArgument(): void {
		$this->expectException( \TypeError::class );
		// @phan-suppress-next-line PhanTypeMismatchArgument deliberate
		FeedbackNotifier::notifyNew(
			1,
			'not a Title object',
			[ 'inaccurate' ],
			null,
			$this->getTestUser()->getUser()
		);
	}

	/**
	 * The other half of the same scenario: wrapping the call the way
	 * ApiSubmitFeedback::execute() does turns that same TypeError into a
	 * caught, logged failure instead of a propagating exception.
	 */
	public function testGuardedCallCatchesTheSameWrongTypeTitleArgument(): void {
		try {
			// @phan-suppress-next-line PhanTypeMismatchArgument deliberate
			FeedbackNotifier::notifyNew(
				1,
				'not a Title object',
				[ 'inaccurate' ],
				null,
				$this->getTestUser()->getUser()
			);
			$this->fail( 'Expected a TypeError to reach this try block' );
		} catch ( \Throwable $e ) {
			$this->assertInstanceOf( \TypeError::class, $e );
		}
	}
}
