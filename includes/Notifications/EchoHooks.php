<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Notifications;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess;
use MediaWiki\User\UserIdentity;

/**
 * Registers the saintapediafeedback-new Echo event type and locates recipients.
 */
class EchoHooks {

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public static function onBeforeCreateEchoEvent(
		&$notifications,
		&$notificationCategories,
		&$icons
	) {
		$notificationCategories['saintapediafeedback'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-saintapediafeedback',
		];

		$notifications['saintapediafeedback-new'] = [
			'category' => 'saintapediafeedback',
			'group' => 'neutral',
			'section' => 'alert',
			'presentation-model' => FeedbackPresentationModel::class,
			'bundle' => [ 'web' => true, 'expand' => true ],
			'user-locators' => [
				[ [ self::class, 'locateNotifiedUsers' ] ],
			],
		];
	}

	/**
	 * @param \EchoEvent|\MediaWiki\Extension\Notifications\Model\Event $event
	 * @return UserIdentity[]
	 */
	public static function locateNotifiedUsers( $event ): array {
		$extra = $event->getExtra();
		$ids = $extra['notify-user-ids'] ?? [];
		if ( !is_array( $ids ) || !$ids ) {
			return [];
		}
		$users = [];
		$userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
		foreach ( $ids as $id ) {
			$user = $userFactory->newFromId( (int)$id );
			if ( FeedbackAccess::isPersistentAccount( $user ) ) {
				$users[] = $user;
			}
		}
		return $users;
	}
}
