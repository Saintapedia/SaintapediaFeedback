<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Api;

use ApiBase;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use Wikimedia\ParamValidator\ParamValidator;

class ApiSubmitFeedback extends ApiBase {

	private FeedbackStore $store;

	public function __construct( $query, $moduleName, FeedbackStore $store ) {
		parent::__construct( $query, $moduleName );
		$this->store = $store;
	}

	public function execute(): void {
		$params   = $this->extractRequestParams();
		$config   = $this->getConfig();
		$request  = $this->getRequest();
		$mode     = $config->get( 'SaintapediaFeedbackMode' );

		// Deny blocked users
		$block = $this->getUser()->getBlock();
		if ( $block ) {
			$this->dieBlocked( $block );
		}

		// Validate page
		$title = \Title::newFromID( $params['pageid'] );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError( 'apierror-invalidtitle' );
		}

		// Validate categories
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

		// Rate limiting — hash the IP, never log the raw value
		$ip     = $request->getIP();
		$ipHash = hash( 'sha256', $ip . \MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( 'SecretKey' ) );
		$limit  = $mode === 'enterprise'
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

		$user = $this->getUser();
		$id   = $this->store->insert( [
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
