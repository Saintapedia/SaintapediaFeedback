<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ConfirmEdit\Hooks as ConfirmEditHooks;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\User;

/**
 * Multi-wiki captcha policy for open (mostly anonymous) feedback submit.
 *
 * Public mode: captcha required by default (almost all submitters have no account).
 * Enterprise mode: captcha off by default (more logged-in staff).
 * Per-wiki override via $wgSaintapediaFeedbackRequireCaptcha.
 *
 * Uses ConfirmEdit + hCaptcha when available; fails closed when captcha is required
 * but ConfirmEdit/hCaptcha is not configured.
 */
class CaptchaGate {

	/** @var string Result of last decision for diagnostics in API errors */
	private static string $lastFailReason = '';

	public static function getLastFailReason(): string {
		return self::$lastFailReason;
	}

	/**
	 * Effective "require captcha for this request" policy (before skip-rights).
	 * null config value = auto from mode.
	 */
	public static function isCaptchaEnabled( Config $config ): bool {
		$flag = $config->get( 'SaintapediaFeedbackRequireCaptcha' );
		$phpValue = $flag === null
			? $config->get( 'SaintapediaFeedbackMode' ) !== 'enterprise'
			: (bool)$flag;

		return FeedbackWikiConfig::effectiveBool(
			'SaintapediaFeedbackRequireCaptchaPage',
			'SaintapediaFeedback-require-captcha',
			$phpValue
		);
	}

	/**
	 * Whether this user must solve captcha for this submit.
	 */
	public static function mustSolveCaptcha( Config $config, User $user ): bool {
		if ( !self::isCaptchaEnabled( $config ) ) {
			return false;
		}
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'ConfirmEdit' ) ) {
			// Still "must" — API will fail closed.
			return true;
		}
		$captcha = ConfirmEditHooks::getInstance();
		if ( $captcha->canSkipCaptcha( $user, $config ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Verify captcha for a submit request. Returns true if allowed to proceed.
	 */
	public static function pass( Config $config, WebRequest $request, User $user ): bool {
		self::$lastFailReason = '';

		if ( !self::mustSolveCaptcha( $config, $user ) ) {
			return true;
		}

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'ConfirmEdit' ) ) {
			self::$lastFailReason = 'unavailable';
			return false;
		}

		// Prefer hCaptcha site key presence so misconfigured wikis fail closed clearly.
		if ( self::getHCaptchaSiteKey() === '' ) {
			self::$lastFailReason = 'unavailable';
			return false;
		}

		$captcha = ConfirmEditHooks::getInstance();
		$captcha->setAction( 'saintapediafeedback' );
		$captcha->setTrigger( 'saintapediafeedback-submit' );

		if ( !$captcha->passCaptchaLimitedFromRequest( $request, $user ) ) {
			self::$lastFailReason = 'failed';
			return false;
		}

		return true;
	}

	public static function getHCaptchaSiteKey(): string {
		$services = MediaWikiServices::getInstance();

		try {
			$key = $services->getConfigFactory()
				->makeConfig( 'hcaptcha' )
				->get( 'HCaptchaSiteKey' );
			if ( is_string( $key ) && $key !== '' ) {
				return $key;
			}
		} catch ( \Throwable $e ) {
			// hCaptcha submodule may not be loaded
		}

		try {
			$key = $services->getMainConfig()->get( 'HCaptchaSiteKey' );
			if ( is_string( $key ) && $key !== '' ) {
				return $key;
			}
		} catch ( \Throwable $e ) {
			// not set
		}

		return '';
	}

	/**
	 * Expose captcha UI config and CSP when the widget may need hCaptcha.
	 */
	public static function prepareOutput( OutputPage $out, Config $config ): array {
		$user = $out->getUser();
		$require = self::mustSolveCaptcha( $config, $user );
		$siteKey = $require ? self::getHCaptchaSiteKey() : '';

		if ( $require && $siteKey !== '' && class_exists( HCaptcha::class ) ) {
			HCaptcha::addCSPSources( $out->getCSP() );
		}

		return [
			'requireCaptcha' => $require && $siteKey !== '',
			// If policy requires captcha but key missing, widget should show config error.
			'captchaMisconfigured' => $require && $siteKey === '',
			'hCaptchaSiteKey' => $siteKey,
		];
	}
}
