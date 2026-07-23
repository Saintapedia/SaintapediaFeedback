<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use DatabaseUpdater;
use MediaWiki\Config\Config;
use MediaWiki\Output\OutputPage;
use Skin;

class Hooks {

	private static function getConfig(): Config {
		return \MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
	}

	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		$config = self::getConfig();

		// Only show in configured namespaces
		$allowedNamespaces = $config->get( 'SaintapediaFeedbackNamespaces' );
		if ( !in_array( $out->getTitle()->getNamespace(), $allowedNamespaces, true ) ) {
			return;
		}

		// Don't show on special pages or action pages (edit, history, etc.)
		if ( $out->getTitle()->isSpecialPage() ) {
			return;
		}
		$action = $out->getRequest()->getVal( 'action', 'view' );
		if ( $action !== 'view' ) {
			return;
		}

		$mode = $config->get( 'SaintapediaFeedbackMode' );
		$enableEmail = $mode === 'enterprise' || $config->get( 'SaintapediaFeedbackEnableEmail' );

		$out->addJsConfigVars( [
			'spfMode'          => $mode,
			'spfPageId'        => $out->getTitle()->getArticleID(),
			'spfPageTitle'     => $out->getTitle()->getPrefixedText(),
			'spfEnableEmail'   => $enableEmail,
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
