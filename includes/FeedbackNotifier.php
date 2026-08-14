<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use SpecialPage;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Optional notifications when feedback is submitted.
 *
 * - Echo named users (config list)
 * - Echo page watchers (config flag — encourages enterprise editors)
 * - Email (optional single address)
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
			$recipients = self::collectRecipientIds( $config, $title, $agent );
			if ( $recipients ) {
				self::createEchoEvent( $feedbackId, $title, $categories, $comment, $agent, $recipients );
			}
			self::notifyEmail( $config, $feedbackId, $title, $categories, $comment );
		} catch ( \Throwable $e ) {
			wfDebugLog( 'SaintapediaFeedback', 'notifyNew failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @return int[] user ids
	 */
	private static function collectRecipientIds( Config $config, Title $title, User $agent ): array {
		$ids = [];

		$names = $config->get( 'SaintapediaFeedbackNotifyUsers' );
		if ( is_array( $names ) ) {
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			foreach ( $names as $name ) {
				if ( !is_string( $name ) || $name === '' ) {
					continue;
				}
				$user = $userFactory->newFromName( $name );
				if ( $user && FeedbackAccess::isPersistentAccount( $user ) && FeedbackAccess::userCanManage( $user ) ) {
					$ids[] = $user->getId();
				}
			}
		}

		// Watchers only if they may manage feedback — otherwise Echo would leak
		// raw comments (extra.comment) to users who cannot open the dashboard.
		if ( $config->get( 'SaintapediaFeedbackNotifyWatchers' ) ) {
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			foreach ( self::getWatcherUserIds( $title ) as $wid ) {
				$user = $userFactory->newFromId( (int)$wid );
				if ( FeedbackAccess::isPersistentAccount( $user ) && FeedbackAccess::userCanManage( $user ) ) {
					$ids[] = (int)$wid;
				}
			}
		}

		// De-dupe; never notify the submitter if they are registered
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( FeedbackAccess::isPersistentAccount( $agent ) ) {
			$ids = array_values( array_filter(
				$ids,
				static function ( $id ) use ( $agent ) {
					return (int)$id !== $agent->getId();
				}
			) );
		}
		return $ids;
	}

	/**
	 * Users watching this title (watchlist table). Callers must still filter by
	 * FeedbackAccess::userCanManage() before sending protected content via Echo.
	 *
	 * @return int[]
	 */
	private static function getWatcherUserIds( Title $title ): array {
		$services = MediaWikiServices::getInstance();
		/** @var ILoadBalancer $lb */
		$lb = $services->getDBLoadBalancer();
		$dbr = $lb->getConnection( DB_REPLICA );
		$res = $dbr->select(
			'watchlist',
			[ 'wl_user' ],
			[
				'wl_namespace' => $title->getNamespace(),
				'wl_title'     => $title->getDBkey(),
			],
			__METHOD__,
			[ 'DISTINCT' ]
		);
		$ids = [];
		foreach ( $res as $row ) {
			$ids[] = (int)$row->wl_user;
		}
		return $ids;
	}

	/**
	 * @param int[] $recipients
	 */
	private static function createEchoEvent(
		int $feedbackId,
		Title $title,
		array $categories,
		?string $comment,
		User $agent,
		array $recipients
	): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) || !$recipients ) {
			return;
		}
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
