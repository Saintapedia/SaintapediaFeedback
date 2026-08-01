<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use DatabaseUpdater;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use Skin;
use SpecialPage;

class Hooks {

	private static function getConfig(): Config {
		return MediaWikiServices::getInstance()->getMainConfig();
	}

	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		$config = self::getConfig();

		// Only show in configured namespaces
		$allowedNamespaces = $config->get( 'SaintapediaFeedbackNamespaces' );
		$title = $out->getTitle();
		if ( !$title || !in_array( $title->getNamespace(), $allowedNamespaces, true ) ) {
			return;
		}

		// Don't show on special pages or action pages (edit, history, etc.)
		if ( $title->isSpecialPage() ) {
			return;
		}
		$action = $out->getRequest()->getVal( 'action', 'view' );
		if ( $action !== 'view' ) {
			return;
		}

		// No feedback UI on non-existing pages
		if ( !$title->exists() ) {
			return;
		}

		$mode = $config->get( 'SaintapediaFeedbackMode' );
		$enableEmail = $mode === 'enterprise' || $config->get( 'SaintapediaFeedbackEnableEmail' );
		$captcha = CaptchaGate::prepareOutput( $out, $config );

		$publicCounts = [ 'open' => 0, 'resolved' => 0, 'total' => 0 ];
		if ( $config->get( 'SaintapediaFeedbackShowPublicCounts' ) ) {
			try {
				$store = MediaWikiServices::getInstance()->getService( 'SaintapediaFeedback.FeedbackStore' );
				$publicCounts = $store->getPageCounts( $title->getArticleID() );
			} catch ( \Throwable $e ) {
				// ignore
			}
		}

		$out->addJsConfigVars( [
			'spfMode'                 => $mode,
			'spfPageId'               => $title->getArticleID(),
			'spfPageTitle'            => $title->getPrefixedText(),
			'spfEnableEmail'          => $enableEmail,
			'spfRequireCaptcha'       => $captcha['requireCaptcha'],
			'spfCaptchaMisconfigured' => $captcha['captchaMisconfigured'],
			'spfHCaptchaSiteKey'      => $captcha['hCaptchaSiteKey'],
			'spfShowPublicCounts'     => (bool)$config->get( 'SaintapediaFeedbackShowPublicCounts' ),
			'spfCountOpen'            => (int)$publicCounts['open'],
			'spfCountResolved'        => (int)$publicCounts['resolved'],
			'spfCountTotal'           => (int)$publicCounts['total'],
		] );

		$out->addModules( 'ext.saintapediafeedback.widget' );
	}

	/**
	 * Toolbox link for editors: jump to this page's feedback on the special page.
	 *
	 * @param Skin $skin
	 * @param array &$sidebar
	 */
	public static function onSidebarBeforeOutput( Skin $skin, &$sidebar ): void {
		$user = $skin->getUser();
		if ( !FeedbackAccess::userCanManage( $user ) ) {
			return;
		}
		$title = $skin->getTitle();
		if ( !$title || !$title->exists() || $title->isSpecialPage() ) {
			return;
		}
		$config = self::getConfig();
		$allowedNamespaces = $config->get( 'SaintapediaFeedbackNamespaces' );
		if ( !in_array( $title->getNamespace(), $allowedNamespaces, true ) ) {
			return;
		}

		$url = SpecialPage::getTitleFor(
			'SaintapediaFeedback',
			(string)$title->getArticleID()
		)->getLocalURL();

		$counts = [ 'new' => 0, 'open' => 0 ];
		try {
			$store = MediaWikiServices::getInstance()->getService( 'SaintapediaFeedback.FeedbackStore' );
			$counts = $store->getPageCounts( $title->getArticleID() );
		} catch ( \Throwable $e ) {
			// store/table may not exist yet
		}

		$text = $skin->msg( 'saintapediafeedback-toolbox' )->text();
		if ( !empty( $counts['new'] ) ) {
			$text = $skin->msg( 'saintapediafeedback-toolbox-count' )
				->numParams( (int)$counts['new'] )
				->text();
		} elseif ( !empty( $counts['open'] ) ) {
			$text = $skin->msg( 'saintapediafeedback-toolbox-open' )
				->numParams( (int)$counts['open'] )
				->text();
		}

		$sidebar['TOOLBOX']['saintapediafeedback'] = [
			'id'   => 't-saintapediafeedback',
			'href' => $url,
			'text' => $text,
		];
	}

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
		$dir = dirname( __DIR__ ) . '/sql';
		$updater->addExtensionTable(
			'spf_feedback',
			$dir . '/tables.sql'
		);
		$updater->addExtensionTable(
			'spf_feedback_log',
			$dir . '/feedback_log.sql'
		);
		// Incremental columns for installs that already had spf_feedback
		$updater->addExtensionField(
			'spf_feedback',
			'fb_status_user_id',
			$dir . '/patch-audit-priority.sql'
		);
	}

	/**
	 * Invalidate access-group cache when MediaWiki:SaintapediaFeedback-access is edited.
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param EditResult $editResult
	 */
	public static function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		$title = $wikiPage->getTitle();
		$access = FeedbackAccess::getAccessPageTitle();
		if ( $access && $title->equals( $access ) ) {
			FeedbackAccess::invalidateCache();
		}
	}
}
