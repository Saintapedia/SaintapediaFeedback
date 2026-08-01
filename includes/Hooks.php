<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use DatabaseUpdater;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use Skin;

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

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
		$updater->addExtensionTable(
			'spf_feedback',
			dirname( __DIR__ ) . '/sql/tables.sql'
		);
	}
}
