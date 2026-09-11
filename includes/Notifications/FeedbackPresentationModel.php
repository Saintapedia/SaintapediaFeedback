<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use SpecialPage;

/**
 * Echo presentation for new feedback alerts.
 * Only loaded when Echo is present (class_exists checked by registration).
 */
class FeedbackPresentationModel extends EchoEventPresentationModel {

	public function getIconType() {
		return 'placeholder';
	}

	public function getHeaderMessage() {
		$msg = $this->msg( 'notification-header-saintapediafeedback-new' );
		if ( $this->event->getTitle() ) {
			$msg->params( $this->event->getTitle()->getPrefixedText() );
		} else {
			$msg->params( '' );
		}
		return $msg;
	}

	/**
	 * Never renders the raw reader comment (F-04): Echo's event extra data
	 * deliberately does not carry it, so a recipient must open the
	 * authorized dashboard to read what was actually submitted.
	 */
	public function getBodyMessage() {
		$extra = $this->event->getExtra();
		$cats = $extra['categories'] ?? [];
		if ( $cats ) {
			return $this->msg( 'notification-body-saintapediafeedback-new-cats' )
				->params( implode( ', ', $cats ) );
		}
		return $this->msg( 'notification-body-saintapediafeedback-new-generic' );
	}

	public function getPrimaryLink() {
		$id = (int)( $this->event->getExtra()['feedback-id'] ?? 0 );
		$title = $this->event->getTitle();
		if ( $title ) {
			$url = SpecialPage::getTitleFor(
				'SaintapediaFeedback',
				(string)$title->getArticleID()
			)->getFullURL();
		} else {
			$url = SpecialPage::getTitleFor( 'SaintapediaFeedback' )->getFullURL();
		}
		return [
			'url' => $url,
			'label' => $this->msg( 'notification-link-saintapediafeedback-new' )->text(),
		];
	}
}
