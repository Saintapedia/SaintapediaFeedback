<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use SpecialPage;

/**
 * Optional short Talk-page note linking to public resolutions.
 * Does not dump work notes or raw reader comments.
 */
class TalkLinkPoster {

	/**
	 * Append a short section to the article's talk page.
	 *
	 * @return bool True if an edit was saved
	 */
	public static function postResolutionLink(
		Title $articleTitle,
		int $feedbackId,
		User $editor,
		bool $resolutionWasPublished
	): bool {
		try {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			if ( !$config->get( 'SaintapediaFeedbackEnableTalkLink' ) ) {
				return false;
			}

			$talk = $articleTitle->getTalkPage();
			if ( !$talk || !$talk->canExist() ) {
				return false;
			}

			$services = MediaWikiServices::getInstance();
			if ( !$services->getPermissionManager()->userCan( 'edit', $editor, $talk ) ) {
				wfDebugLog( 'SaintapediaFeedback', 'Talk link skipped: no edit right on talk' );
				return false;
			}

			$page = $services->getWikiPageFactory()->newFromTitle( $talk );

			$resolutionsTitle = SpecialPage::getTitleFor(
				'SaintapediaFeedback',
				'resolutions/' . $articleTitle->getArticleID()
			);
			$resolutionsLink = '[[' . $resolutionsTitle->getPrefixedText() . ']]';
			$articleLink = '[[' . $articleTitle->getPrefixedText() . ']]';

			$msgKey = $resolutionWasPublished
				? 'saintapediafeedback-talk-section-public'
				: 'saintapediafeedback-talk-section';
			$section = wfMessage( $msgKey )
				->params( $feedbackId, $articleLink, $resolutionsLink )
				->inContentLanguage()
				->plain();

			$heading = wfMessage( 'saintapediafeedback-talk-heading' )
				->inContentLanguage()
				->plain();

			$existing = '';
			$content = $page->getContent();
			if ( $content instanceof WikitextContent ) {
				$existing = $content->getText();
			} elseif ( $content && method_exists( $content, 'getText' ) ) {
				$existing = $content->getText();
			}

			// Avoid duplicate spam for same feedback id
			$marker = '<!-- spf-feedback-' . $feedbackId . ' -->';
			if ( strpos( $existing, $marker ) !== false ) {
				return false;
			}

			$block = "\n\n== " . $heading . " ==\n"
				. $marker . "\n"
				. trim( $section ) . "\n";

			$newText = rtrim( $existing ) . $block;
			$newContent = new WikitextContent( $newText );

			$summary = wfMessage( 'saintapediafeedback-talk-edit-summary' )
				->numParams( $feedbackId )
				->inContentLanguage()
				->text();

			$updater = $page->newPageUpdater( $editor );
			$updater->setContent( SlotRecord::MAIN, $newContent );
			$comment = CommentStoreComment::newUnsavedComment( $summary );
			$flags = defined( 'EDIT_MINOR' ) ? EDIT_MINOR : 0;
			$rev = $updater->saveRevision( $comment, $flags );
			return $rev !== null;
		} catch ( \Throwable $e ) {
			wfDebugLog( 'SaintapediaFeedback', 'Talk link post failed: ' . $e->getMessage() );
			return false;
		}
	}
}
