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

		$out->addJsConfigVars( [
			'spfMode'                 => $mode,
			'spfPageId'               => $title->getArticleID(),
			'spfPageTitle'            => $title->getPrefixedText(),
			'spfEnableEmail'          => $enableEmail,
			'spfRequireCaptcha'       => $captcha['requireCaptcha'],
			'spfCaptchaMisconfigured' => $captcha['captchaMisconfigured'],
			'spfHCaptchaSiteKey'      => $captcha['hCaptchaSiteKey'],
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
		if ( !$user->isAllowed( 'saintapediafeedback-view' ) ) {
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

		$sidebar['TOOLBOX']['saintapediafeedback'] = [
			'id'   => 't-saintapediafeedback',
			'href' => $url,
			'text' => $skin->msg( 'saintapediafeedback-toolbox' )->text(),
		];
	}

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
		$updater->addExtensionTable(
			'spf_feedback',
			dirname( __DIR__ ) . '/sql/tables.sql'
		);
	}
}
