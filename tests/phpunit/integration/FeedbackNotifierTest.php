<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Integration;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackNotifier;
use MediaWiki\MediaWikiServices;
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
 * @group Database Needed for real DB access: notifyNew() unconditionally
 *   goes through FeedbackWikiConfig's on-wiki-override lookup (a
 *   WANObjectCache-backed MediaWiki:-namespace page existence check),
 *   regardless of the SaintapediaFeedbackNotifyUsers config value passed
 *   in setUp() -- discovered on MW 1.45, which disables DB access outside
 *   @group Database tests and surfaced this as a caught-and-logged
 *   "Database backend disabled" failure that looked identical to a real
 *   regression until traced down.
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

	/**
	 * None of these tests need a real, persisted user account -- they're
	 * about the Title parameter, not about who submitted. getTestUser()
	 * persists to the test database and, as of MW 1.45, throws a
	 * LogicException outside a @group Database test; an anonymous User
	 * is real (satisfies notifyNew()'s `User $agent` type) without either
	 * requirement, and is the fix MW 1.45's own error message recommends.
	 */
	private function anonymousUser(): \User {
		return MediaWikiServices::getInstance()->getUserFactory()->newAnonymous();
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
			$this->anonymousUser()
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
			$this->anonymousUser()
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
				$this->anonymousUser()
			);
			$this->fail( 'Expected a TypeError to reach this try block' );
		} catch ( \Throwable $e ) {
			$this->assertInstanceOf( \TypeError::class, $e );
		}
	}
}
