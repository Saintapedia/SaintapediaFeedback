<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Special;

use HTMLForm;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use SpecialPage;
use Title;

class SpecialFeedback extends SpecialPage {

	private FeedbackStore $store;

	public function __construct( FeedbackStore $store ) {
		parent::__construct( 'SaintapediaFeedback', 'saintapediafeedback-view' );
		$this->store = $store;
	}

	public function execute( $par ): void {
		$this->setHeaders();
		$this->checkPermissions();
		$out = $this->getOutput();
		$out->addModuleStyles( 'mediawiki.special' );

		$pageId = (int)( $par ?: $this->getRequest()->getVal( 'pageid', 0 ) );

		if ( $pageId ) {
			$this->showPageFeedback( $pageId );
		} else {
			$this->showPageSelector();
		}
	}

	private function showPageSelector(): void {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'saintapediafeedback-special-title' ) );

		$form = HTMLForm::factory( 'ooui', [
			'pagename' => [
				'type'    => 'title',
				'label-message' => 'saintapediafeedback-special-pagename',
				'required' => true,
			],
		], $this->getContext() );

		$form->setMethod( 'get' )
			->setSubmitTextMsg( 'saintapediafeedback-special-submit' )
			->setFormIdentifier( 'spf-page-selector' )
			->setSubmitCallback( function ( $data ) {
				$title = Title::newFromText( $data['pagename'] );
				if ( $title && $title->exists() ) {
					$this->getOutput()->redirect(
						$this->getPageTitle( $title->getArticleID() )->getLocalURL()
					);
				}
				return true;
			} )
			->show();
	}

	private function showPageFeedback( int $pageId ): void {
		$out  = $this->getOutput();
		$title = Title::newFromID( $pageId );

		if ( !$title ) {
			$out->setPageTitle( $this->msg( 'saintapediafeedback-special-title' ) );
			$out->addWikiMsg( 'saintapediafeedback-special-notfound' );
			return;
		}

		$out->setPageTitle( $this->msg( 'saintapediafeedback-special-page-title', $title->getPrefixedText() ) );
		$out->addBacklinkSubtitle( $title );

		// Handle status update actions
		$action = $this->getRequest()->getVal( 'spfaction' );
		$fbId   = (int)$this->getRequest()->getVal( 'spfid' );
		$validStatuses = [ 'reviewed', 'actioned', 'dismissed' ];
		if ( $action && $fbId && in_array( $action, $validStatuses, true ) ) {
			$this->store->updateStatus( $fbId, $action );
			$out->addHTML( '<div class="successbox">' . $this->msg( 'saintapediafeedback-status-updated' )->escaped() . '</div>' );
		}

		$rows = $this->store->getForPage( $pageId );

		if ( !$rows ) {
			$out->addWikiMsg( 'saintapediafeedback-special-nofeedback' );
			return;
		}

		$out->addHTML( '<div class="spf-feedback-list">' );
		foreach ( $rows as $row ) {
			$this->renderFeedbackRow( $row );
		}
		$out->addHTML( '</div>' );

		// LLM export link for editors
		$exportUrl = \SpecialPage::getTitleFor( 'SaintapediaFeedback', 'export/' . $pageId )->getLocalURL();
		$out->addHTML( '<p class="spf-export-link">' .
			$out->msg( 'saintapediafeedback-export-link' )->rawParams(
				'<a href="' . htmlspecialchars( $exportUrl ) . '">' .
				$out->msg( 'saintapediafeedback-export-link-text' )->escaped() . '</a>'
			)->parse() .
		'</p>' );
	}

	private function renderFeedbackRow( object $row ): void {
		$out        = $this->getOutput();
		$categories = json_decode( $row->fb_categories, true ) ?? [];
		$catLabels  = implode( ', ', array_map( function ( $cat ) {
			return $this->msg( 'saintapediafeedback-category-' . $cat )->text();
		}, $categories ) );

		$statusClass = 'spf-status-' . htmlspecialchars( $row->fb_status );
		$time = $this->getLanguage()->userTimeAndDate( $row->fb_timestamp, $this->getUser() );

		$out->addHTML( '<div class="spf-feedback-item ' . $statusClass . '">' );
		$out->addHTML( '<div class="spf-feedback-meta">' );
		$out->addHTML( '<span class="spf-time">' . htmlspecialchars( $time ) . '</span> ' );
		$out->addHTML( '<span class="spf-mode spf-mode-' . htmlspecialchars( $row->fb_mode ) . '">'
			. htmlspecialchars( $row->fb_mode ) . '</span> ' );
		$out->addHTML( '<span class="spf-status">' . htmlspecialchars( $row->fb_status ) . '</span>' );
		$out->addHTML( '</div>' );

		$out->addHTML( '<div class="spf-feedback-categories">'
			. $this->msg( 'saintapediafeedback-special-categories' )->escaped()
			. ' <strong>' . htmlspecialchars( $catLabels ) . '</strong></div>' );

		if ( $row->fb_comment ) {
			$out->addHTML( '<div class="spf-feedback-comment">'
				. nl2br( htmlspecialchars( $row->fb_comment ) ) . '</div>' );
		}

		// Status action buttons
		$baseUrl = $this->getPageTitle( $row->fb_page_id )->getLocalURL();
		$out->addHTML( '<div class="spf-feedback-actions">' );
		foreach ( [ 'reviewed', 'actioned', 'dismissed' ] as $status ) {
			if ( $row->fb_status !== $status ) {
				$url = $baseUrl . '&spfaction=' . $status . '&spfid=' . (int)$row->fb_id;
				$out->addHTML( '<a class="spf-action-btn spf-action-' . $status . '" href="'
					. htmlspecialchars( $url ) . '">'
					. $this->msg( 'saintapediafeedback-action-' . $status )->escaped()
					. '</a> ' );
			}
		}
		$out->addHTML( '</div>' );

		$out->addHTML( '</div>' );
	}

	protected function getGroupName(): string {
		return 'wiki';
	}
}
