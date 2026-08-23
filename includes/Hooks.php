<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use Config;
use DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Skin;
use SpecialPage;
use Title;

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

		$showPublicCounts = FeedbackWikiConfig::effectiveBool(
			'SaintapediaFeedbackShowPublicCountsPage',
			'SaintapediaFeedback-show-public-counts',
			(bool)$config->get( 'SaintapediaFeedbackShowPublicCounts' )
		);

		$publicCounts = [ 'open' => 0, 'resolved' => 0, 'total' => 0 ];
		if ( $showPublicCounts ) {
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
			'spfShowPublicCounts'     => $showPublicCounts,
			'spfCountOpen'            => (int)$publicCounts['open'],
			'spfCountResolved'        => (int)$publicCounts['resolved'],
			'spfCountTotal'           => (int)$publicCounts['total'],
		] );

		$out->addModules( 'ext.saintapediafeedback.widget' );
	}

	/**
	 * Toolbox: reader entry to open/restore the widget; managers also get the dashboard.
	 *
	 * @param Skin $skin
	 * @param array &$sidebar
	 */
	public static function onSidebarBeforeOutput( Skin $skin, &$sidebar ): void {
		$title = $skin->getTitle();
		if ( !$title || !$title->exists() || $title->isSpecialPage() ) {
			return;
		}
		$config = self::getConfig();
		$allowedNamespaces = $config->get( 'SaintapediaFeedbackNamespaces' );
		if ( !in_array( $title->getNamespace(), $allowedNamespaces, true ) ) {
			return;
		}

		$action = $skin->getRequest()->getVal( 'action', 'view' );
		if ( $action === 'view' ) {
			// Restore path if the reader hid the FAB (session). Also a second open entry.
			$sidebar['TOOLBOX']['saintapediafeedback-widget'] = [
				'id'   => 't-saintapediafeedback-widget',
				'href' => '#spf-feedback',
				'text' => $skin->msg( 'saintapediafeedback-button-label' )->text(),
			];
		}

		if ( !FeedbackAccess::userCanManage( $skin->getUser() ) ) {
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
		$updater->addExtensionField(
			'spf_feedback',
			'fb_work_note',
			$dir . '/patch-work-notes-public.sql'
		);
		$updater->addExtensionField(
			'spf_feedback_log',
			'log_note',
			$dir . '/patch-log-note.sql'
		);
		$updater->addExtensionIndex(
			'spf_feedback',
			'spf_ip_time',
			$dir . '/patch-ip-hash-index.sql'
		);
		// Indexes are registered one per patch rather than bundled into the
		// ALTERs above. A bare CREATE INDEX aborts the remainder of its patch
		// file when the name already exists, and MediaWiki cannot resume a
		// half-applied patch — so the guard column would already be present,
		// the patch would never re-run, and the index would be missing for
		// good. Registered individually, each is guarded by its own check.
		$updater->addExtensionIndex(
			'spf_feedback',
			'spf_priority',
			$dir . '/patch-index-priority.sql'
		);
		$updater->addExtensionIndex(
			'spf_feedback',
			'spf_public_res',
			$dir . '/patch-index-public-res.sql'
		);
	}

	/**
	 * Invalidate the relevant cache when any access-config or on-wiki
	 * operational-setting page is edited.
	 */
	public static function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		self::maybeInvalidateConfigCaches( $wikiPage->getTitle() );
	}

	/**
	 * Deleting a config page must reset to PHP defaults immediately (not wait TTL).
	 */
	public static function onPageDeleteComplete(
		$page,
		$deleter,
		$reason,
		$pageID,
		$deletedRev,
		$logEntry,
		$archivedRevisionCount
	): void {
		try {
			$title = Title::castFromPageIdentity( $page );
		} catch ( \Throwable $e ) {
			$title = null;
		}
		if ( !$title && is_object( $page ) && method_exists( $page, 'getDBkey' ) ) {
			// Older signatures sometimes pass Title-like objects
			$title = Title::makeTitleSafe( $page->getNamespace(), $page->getDBkey() );
		}
		self::maybeInvalidateConfigCaches( $title );
	}

	/**
	 * Moving/renaming any access-config or on-wiki operational-setting page
	 * must not leave a stale cache entry.
	 */
	public static function onPageMoveComplete(
		$old,
		$new,
		$user,
		$pageid,
		$redirid,
		$reason,
		$revision
	): void {
		$targets = [
			[ FeedbackAccess::getAccessPageTitle(), [ FeedbackAccess::class, 'invalidateCache' ] ],
			[ FeedbackAccess::getEmailAccessPageTitle(), [ FeedbackAccess::class, 'invalidateEmailCache' ] ],
			[ FeedbackAccess::getExportAccessPageTitle(), [ FeedbackAccess::class, 'invalidateExportCache' ] ],
		];
		foreach ( [ $old, $new ] as $lt ) {
			try {
				$t = Title::newFromLinkTarget( $lt );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( !$t ) {
				continue;
			}
			foreach ( $targets as [ $page, $invalidate ] ) {
				if ( $page && ( $t->equals( $page ) || $t->getPrefixedText() === $page->getPrefixedText() ) ) {
					$invalidate();
				}
			}
			FeedbackWikiConfig::maybeInvalidate( $t );
		}
	}

	/**
	 * @param \MediaWiki\Title\Title|Title|null $title
	 */
	private static function maybeInvalidateConfigCaches( $title ): void {
		if ( !$title ) {
			return;
		}
		$access = FeedbackAccess::getAccessPageTitle();
		if ( $access && $title->equals( $access ) ) {
			FeedbackAccess::invalidateCache();
		}
		$emailAccess = FeedbackAccess::getEmailAccessPageTitle();
		if ( $emailAccess && $title->equals( $emailAccess ) ) {
			FeedbackAccess::invalidateEmailCache();
		}
		$exportAccess = FeedbackAccess::getExportAccessPageTitle();
		if ( $exportAccess && $title->equals( $exportAccess ) ) {
			FeedbackAccess::invalidateExportCache();
		}
		FeedbackWikiConfig::maybeInvalidate( $title );
	}
}
