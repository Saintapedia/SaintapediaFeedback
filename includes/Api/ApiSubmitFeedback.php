<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Api;

use ApiBase;
use MediaWiki\Extension\SaintapediaFeedback\CaptchaGate;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackNotifier;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiSubmitFeedback extends ApiBase {

	private FeedbackStore $store;

	public function __construct( $main, $moduleName, FeedbackStore $store ) {
		parent::__construct( $main, $moduleName );
		$this->store = $store;
	}

	public function execute(): void {
		$params  = $this->extractRequestParams();
		$config  = $this->getConfig();
		$request = $this->getRequest();
		$mode    = $config->get( 'SaintapediaFeedbackMode' );
		$user    = $this->getUser();

		// Deny blocked users (includes partial blocks — deliberate, matches MW core write API convention)
		$block = $this->getAuthority()->getBlock();
		if ( $block ) {
			$this->dieBlocked( $block );
		}

		// Cheap validation first so invalid requests do not burn a one-time hCaptcha token
		// or an outbound siteverify round-trip (review: VeritasDei).
		$title = \Title::newFromID( $params['pageid'] );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError( 'apierror-invalidtitle' );
		}

		$allowedNamespaces = $config->get( 'SaintapediaFeedbackNamespaces' );
		if ( !in_array( $title->getNamespace(), $allowedNamespaces, true ) ) {
			$this->dieWithError( 'saintapediafeedback-error-namespace', 'spf-namespace' );
		}

		$allowedCategories = [
			'inaccurate', 'outdated', 'needs-detail',
			'confusing', 'missing-sources', 'broken-links', 'other',
		];
		$categories = $params['categories'];
		foreach ( $categories as $cat ) {
			if ( !in_array( $cat, $allowedCategories, true ) ) {
				$this->dieWithError( [ 'apierror-badparameter', 'categories' ] );
			}
		}
		if ( !$categories ) {
			$this->dieWithError( 'saintapediafeedback-error-nocategory' );
		}

		// hCaptcha / ConfirmEdit — open to anons, fail closed when required
		if ( !CaptchaGate::pass( $config, $request, $user ) ) {
			$reason = CaptchaGate::getLastFailReason();
			if ( $reason === 'unavailable' ) {
				$this->dieWithError( 'saintapediafeedback-error-captcha-unavailable', 'spf-captcha-unavailable' );
			}
			$this->dieWithError( 'saintapediafeedback-error-captcha', 'spf-captcha' );
		}

		// Rate limiting — hash the IP, never log the raw value
		$ip     = $request->getIP();
		$ipHash = hash(
			'sha256',
			$ip . MediaWikiServices::getInstance()->getMainConfig()->get( 'SecretKey' )
		);
		$limit = $mode === 'enterprise'
			? $config->get( 'SaintapediaFeedbackEnterpriseRateLimit' )
			: $config->get( 'SaintapediaFeedbackRateLimit' );

		if ( $this->store->countRecentByIpHash( $ipHash ) >= $limit ) {
			$this->dieWithError( 'saintapediafeedback-error-ratelimit' );
		}

		// Sanitize free text
		$comment = $params['comment'] ?? null;
		if ( $comment !== null ) {
			$comment = trim( $comment );
			$maxLen  = $mode === 'enterprise' ? 5000 : 500;
			if ( mb_strlen( $comment ) > $maxLen ) {
				$comment = mb_substr( $comment, 0, $maxLen );
			}
			if ( $comment === '' ) {
				$comment = null;
			}
		}

		// Contact email (enterprise or enabled explicitly)
		$enableEmail = $mode === 'enterprise' || $config->get( 'SaintapediaFeedbackEnableEmail' );
		$contactEmail = null;
		if ( $enableEmail && isset( $params['email'] ) ) {
			$email = trim( $params['email'] );
			if ( $email !== '' && filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$contactEmail = $email;
			}
		}

		$id = $this->store->insert( [
			'pageId'       => $title->getArticleID(),
			'namespace'    => $title->getNamespace(),
			'title'        => $title->getDBkey(),
			'userId'       => $user->isRegistered() ? $user->getId() : null,
			'ipHash'       => $ipHash,
			'categories'   => $categories,
			'comment'      => $comment,
			'contactEmail' => $contactEmail,
			'mode'         => $mode,
		] );

		FeedbackNotifier::notifyNew( $id, $title, $categories, $comment, $user );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'id'     => $id,
		] );
	}

	public function getAllowedParams(): array {
		return [
			'pageid' => [
				ParamValidator::PARAM_TYPE     => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'categories' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_ISMULTI  => true,
			],
			'comment' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'email' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			// hCaptcha token (ConfirmEdit HCaptcha also accepts h-captcha-response)
			'captchaWord' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'captchaword' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	public function needsToken(): string {
		return 'csrf';
	}

	public function isWriteMode(): bool {
		return true;
	}

	public function mustBePosted(): bool {
		return true;
	}
}
