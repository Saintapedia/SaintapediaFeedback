<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use SpecialPage;

/**
 * Optional notifications when feedback is submitted.
 *
 * - Echo (if loaded): events to configured usernames
 * - Email (optional): single address from config
 *
 * Soft dependencies — never fails the submit path.
 */
class FeedbackNotifier {

	/**
	 * @param int $feedbackId
	 * @param Title $title Page the feedback is about
	 * @param string[] $categories
	 * @param string|null $comment
	 * @param User $agent Submitting user (may be anon)
	 */
	public static function notifyNew(
		int $feedbackId,
		Title $title,
		array $categories,
		?string $comment,
		User $agent
	): void {
		try {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			self::notifyEcho( $config, $feedbackId, $title, $categories, $comment, $agent );
			self::notifyEmail( $config, $feedbackId, $title, $categories, $comment );
		} catch ( \Throwable $e ) {
			wfDebugLog( 'SaintapediaFeedback', 'notifyNew failed: ' . $e->getMessage() );
		}
	}

	private static function notifyEcho(
		Config $config,
		int $feedbackId,
		Title $title,
		array $categories,
		?string $comment,
		User $agent
	): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			return;
		}
		$names = $config->get( 'SaintapediaFeedbackNotifyUsers' );
		if ( !is_array( $names ) || !$names ) {
			return;
		}

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$recipients = [];
		foreach ( $names as $name ) {
			if ( !is_string( $name ) || $name === '' ) {
				continue;
			}
			$user = $userFactory->newFromName( $name );
			if ( $user && $user->isRegistered() && FeedbackAccess::userCanManage( $user ) ) {
				$recipients[] = $user->getId();
			}
		}
		if ( !$recipients ) {
			return;
		}

		// Prefer modern Echo namespace; fall back to legacy if needed
		$eventClass = class_exists( \MediaWiki\Extension\Notifications\Model\Event::class )
			? \MediaWiki\Extension\Notifications\Model\Event::class
			: ( class_exists( \EchoEvent::class ) ? \EchoEvent::class : null );
		if ( !$eventClass ) {
			return;
		}

		$eventClass::create( [
			'type' => 'saintapediafeedback-new',
			'title' => $title,
			'agent' => $agent,
			'extra' => [
				'feedback-id' => $feedbackId,
				'categories'  => $categories,
				'comment'     => $comment !== null ? mb_substr( $comment, 0, 200 ) : null,
				'notify-user-ids' => $recipients,
			],
		] );
	}

	private static function notifyEmail(
		Config $config,
		int $feedbackId,
		Title $title,
		array $categories,
		?string $comment
	): void {
		$to = $config->get( 'SaintapediaFeedbackNotifyEmail' );
		if ( !is_string( $to ) || $to === '' || !filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			return;
		}

		$dashboard = SpecialPage::getTitleFor( 'SaintapediaFeedback' )->getFullURL();
		$pageUrl = $title->getFullURL();
		$subject = '[SaintapediaFeedback] New feedback on ' . $title->getPrefixedText();
		$body = "New article feedback (#{$feedbackId})\n\n"
			. "Page: {$title->getPrefixedText()}\n"
			. "URL: {$pageUrl}\n"
			. 'Categories: ' . implode( ', ', $categories ) . "\n"
			. 'Comment: ' . ( $comment ?? '(none)' ) . "\n\n"
			. "Dashboard: {$dashboard}\n";

		$from = $config->get( 'PasswordSender' );
		if ( !is_string( $from ) || $from === '' ) {
			$from = 'wiki@localhost';
		}

		// UserMailer::send expects MailAddress objects in modern MW
		if ( class_exists( \MailAddress::class ) ) {
			\UserMailer::send(
				new \MailAddress( $to ),
				new \MailAddress( $from ),
				$subject,
				$body
			);
		}
	}
}
