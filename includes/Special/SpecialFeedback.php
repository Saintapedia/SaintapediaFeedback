<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Special;

use ErrorPageError;
use HTMLForm;
use Html;
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

		// Status updates: POST + edit token only (no CSRF via GET/img traps)
		$this->handleStatusUpdate( $pageId );

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
		$exportUrl = SpecialPage::getTitleFor( 'SaintapediaFeedback', 'export/' . $pageId )->getLocalURL();
		$out->addHTML( '<p class="spf-export-link">' .
			$out->msg( 'saintapediafeedback-export-link' )->rawParams(
				'<a href="' . htmlspecialchars( $exportUrl ) . '">' .
				$out->msg( 'saintapediafeedback-export-link-text' )->escaped() . '</a>'
			)->parse() .
		'</p>' );
	}

	/**
	 * Process status change only on POST with a valid edit token.
	 * The feedback row must belong to the page being viewed.
	 */
	private function handleStatusUpdate( int $pageId ): void {
		$request = $this->getRequest();
		$action = $request->getVal( 'spfaction' );
		$fbId   = (int)$request->getVal( 'spfid' );
		$validStatuses = [ 'reviewed', 'actioned', 'dismissed' ];

		if ( !$action || !$fbId || !in_array( $action, $validStatuses, true ) ) {
			return;
		}

		// Reject CSRF-via-GET (e.g. <img src="...?spfaction=dismissed&spfid=42">)
		if ( !$request->wasPosted() ) {
			return;
		}

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			throw new ErrorPageError( 'sessionfailure-title', 'sessionfailure' );
		}

		$updated = $this->store->updateStatus( $fbId, $action, $pageId );
		if ( $updated ) {
			$this->getOutput()->addHTML(
				'<div class="successbox">' .
				$this->msg( 'saintapediafeedback-status-updated' )->escaped() .
				'</div>'
			);
		}
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

		// Status action buttons: POST forms with CSRF token (not GET links)
		$actionUrl = $this->getPageTitle( (string)$row->fb_page_id )->getLocalURL();
		$out->addHTML( '<div class="spf-feedback-actions">' );
		foreach ( [ 'reviewed', 'actioned', 'dismissed' ] as $status ) {
			if ( $row->fb_status !== $status ) {
				$out->addHTML(
					Html::openElement( 'form', [
						'method' => 'post',
						'action' => $actionUrl,
						'class'  => 'spf-status-form',
					] ) .
					Html::hidden( 'spfaction', $status ) .
					Html::hidden( 'spfid', (string)(int)$row->fb_id ) .
					Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) .
					Html::submitButton(
						$this->msg( 'saintapediafeedback-action-' . $status )->text(),
						[ 'class' => 'spf-action-btn spf-action-' . $status ]
					) .
					Html::closeElement( 'form' )
				);
			}
		}
		$out->addHTML( '</div>' );

		$out->addHTML( '</div>' );
	}

	protected function getGroupName(): string {
		return 'wiki';
	}
}
