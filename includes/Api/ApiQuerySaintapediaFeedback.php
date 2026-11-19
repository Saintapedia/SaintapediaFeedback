<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Api;

use ApiBase;
use ApiQuery;
use ApiQueryBase;
use ApiResult;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackFilters;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use MediaWiki\User\UserIdentityLookup;
use TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * action=query&list=saintapediafeedback — read-only feedback listing for
 * bots / scripts (api.php instead of the Cloudflare-challenged Special page).
 *
 * Same gate as Special:SaintapediaFeedback/export: userCanManage() AND
 * userCanExport(). Same row shape as FeedbackStore::exportDashboard()
 * (via FeedbackStore::exportRow()). Never returns fb_ip_hash; contact email
 * only on explicit request with userCanViewEmail().
 *
 * spfincluderesolution=1 (1.11.0) adds the resolution / work-note fields that
 * the dashboard already shows to anyone with saintapediafeedback-view, so a
 * bot can read back what action=saintapediafeedbackupdate wrote. Off by
 * default so the default output stays identical to the JSON export.
 *
 * Read-only: GET is fine, no token, not a write module (ApiQueryBase
 * defaults; restated below so a reviewer does not have to look them up).
 */
class ApiQuerySaintapediaFeedback extends ApiQueryBase {

	private FeedbackStore $store;
	private TitleFactory $titleFactory;
	private UserIdentityLookup $userIdentityLookup;
	/** @var array<int,string|null> user id => name cache for statusUser */
	private array $userNames = [];

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		FeedbackStore $store,
		TitleFactory $titleFactory,
		UserIdentityLookup $userIdentityLookup
	) {
		parent::__construct( $query, $moduleName, 'spf' );
		$this->store = $store;
		$this->titleFactory = $titleFactory;
		$this->userIdentityLookup = $userIdentityLookup;
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$this->requireMaxOneParameter( $params, 'page', 'pageid' );

		$user = $this->getUser();
		if ( !FeedbackAccess::isPersistentAccount( $user ) ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}
		$block = $this->getAuthority()->getBlock();
		if ( $block ) {
			$this->dieBlocked( $block );
		}
		if ( !FeedbackAccess::userCanManage( $user ) || !$this->sessionAllows( 'saintapediafeedback-view' ) ) {
			$this->dieWithError(
				[ 'apierror-permissiondenied', $this->msg( 'action-saintapediafeedback-view' ) ],
				'permissiondenied'
			);
		}
		if ( !FeedbackAccess::userCanExport( $user ) || !$this->sessionAllows( 'saintapediafeedback-export' ) ) {
			$this->dieWithError(
				[ 'apierror-permissiondenied', $this->msg( 'action-saintapediafeedback-export' ) ],
				'permissiondenied'
			);
		}
		$wantEmail = (bool)$params['includeemail'];
		if ( $wantEmail && ( !FeedbackAccess::userCanViewEmail( $user )
			|| !$this->sessionAllows( 'saintapediafeedback-viewemail' ) )
		) {
			$this->dieWithError(
				[ 'apierror-permissiondenied', $this->msg( 'action-saintapediafeedback-viewemail' ) ],
				'permissiondenied'
			);
		}

		$filters = [
			'status'   => $params['status'],
			'category' => $params['category'] ?? 'all',
			'sort'     => $params['sort'],
			'search'   => $params['search'] ?? null,
			'since'    => $params['since'] ?? null,
		];

		if ( $params['page'] !== null ) {
			$title = $this->titleFactory->newFromText( $params['page'] );
			if ( !$title || !$title->exists() ) {
				$this->dieWithError( [ 'apierror-missingtitle-byname', wfEscapeWikiText( $params['page'] ) ], 'missingtitle' );
			}
			$filters['pageId'] = $title->getArticleID();
		} elseif ( $params['pageid'] !== null ) {
			// No existence check: feedback for a since-deleted page stays readable.
			$filters['pageId'] = (int)$params['pageid'];
		}

		if ( $params['continue'] !== null ) {
			$cont = explode( '|', $params['continue'] );
			$this->dieContinueUsageIf( count( $cont ) !== 2
				|| !preg_match( '/^\d{14}$/', $cont[0] )
				|| !ctype_digit( $cont[1] ) );
			$filters['continue'] = [ $cont[0], (int)$cont[1] ];
		}

		$limit = (int)$params['limit'];
		// One extra row tells us whether to emit continuation.
		$rows = $this->store->getDashboard( $filters, $limit + 1, 0 );

		$emails = [];
		if ( $wantEmail && $this->emailFieldEnabled() ) {
			$emails = $this->store->getContactEmailsById(
				array_map( static fn ( $r ) => (int)$r->fb_id, array_slice( $rows, 0, $limit ) )
			);
		}

		$result = $this->getResult();
		$path = [ 'query', $this->getModuleName() ];
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ++$count > $limit ) {
				$this->setContinueEnumParameter( 'continue', $row->fb_timestamp . '|' . (int)$row->fb_id );
				break;
			}
			$item = FeedbackStore::exportRow( $row );
			$item['ns'] = (int)$row->fb_page_namespace;
			$item['timestamp'] = wfTimestamp( TS_ISO_8601, $row->fb_timestamp );
			ApiResult::setIndexedTagName( $item['categories'], 'category' );
			if ( $wantEmail ) {
				$item['contactEmail'] = $emails[(int)$row->fb_id] ?? null;
			}
			if ( $params['includeresolution'] ) {
				$item += $this->resolutionFields( $row );
			}
			if ( !$result->addValue( $path, null, $item ) ) {
				$this->setContinueEnumParameter( 'continue', $row->fb_timestamp . '|' . (int)$row->fb_id );
				break;
			}
		}
		$result->addIndexedTagName( $path, 'feedback' );
	}

	/**
	 * Fields the dashboard shows on an item but the export leaves out.
	 * @param object $row MANAGER_LIST_FIELDS row
	 * @return array<string,mixed>
	 */
	private function resolutionFields( $row ): array {
		$uid = $row->fb_status_user_id !== null ? (int)$row->fb_status_user_id : 0;
		if ( $uid > 0 && !array_key_exists( $uid, $this->userNames ) ) {
			$identity = $this->userIdentityLookup->getUserIdentityByUserId( $uid );
			$this->userNames[$uid] = $identity ? $identity->getName() : null;
		}
		return [
			'workNote'          => $row->fb_work_note ?? null,
			'resolutionSummary' => $row->fb_resolution_summary ?? null,
			'resolutionPublic'  => (bool)$row->fb_resolution_public,
			'statusUser'        => $uid > 0 ? $this->userNames[$uid] : null,
			'statusTimestamp'   => $row->fb_status_timestamp
				? wfTimestamp( TS_ISO_8601, $row->fb_status_timestamp )
				: null,
		];
	}

	/**
	 * Bot passwords / OAuth restrict rights by grant, but FeedbackAccess's
	 * group-list fallback (getUserEffectiveGroups) does not see grants. On a
	 * grant-restricted session, require the right itself so a bot password
	 * without the viewfeedback grant cannot ride on a group list.
	 */
	private function sessionAllows( string $right ): bool {
		$allowed = $this->getRequest()->getSession()->getAllowedUserRights();
		if ( $allowed === null ) {
			return true;
		}
		return $this->getAuthority()->isAllowed( $right );
	}

	/** Mirrors SpecialFeedback::contactEmailsForRows(). */
	private function emailFieldEnabled(): bool {
		$config = $this->getConfig();
		return $config->get( 'SaintapediaFeedbackMode' ) === 'enterprise'
			|| $config->get( 'SaintapediaFeedbackEnableEmail' );
	}

	public function getCacheMode( $params ): string {
		return 'private';
	}

	public function isWriteMode(): bool {
		return false;
	}

	public function mustBePosted(): bool {
		return false;
	}

	/** @return false */
	public function needsToken() {
		return false;
	}

	public function getAllowedParams(): array {
		return [
			'status' => [
				ParamValidator::PARAM_TYPE => FeedbackFilters::VALID_STATUSES,
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ALL => true,
				ParamValidator::PARAM_DEFAULT => implode( '|', FeedbackFilters::VALID_STATUSES ),
			],
			'page' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				IntegerDef::PARAM_MIN => 1,
			],
			'category' => [
				ParamValidator::PARAM_TYPE => FeedbackFilters::VALID_CATEGORIES,
			],
			'search' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'since' => [
				ParamValidator::PARAM_TYPE => 'timestamp',
			],
			'sort' => [
				ParamValidator::PARAM_TYPE => FeedbackFilters::VALID_SORTS,
				ParamValidator::PARAM_DEFAULT => 'newest',
			],
			'limit' => [
				ParamValidator::PARAM_TYPE => 'limit',
				ParamValidator::PARAM_DEFAULT => 50,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => ApiBase::LIMIT_BIG1,
				IntegerDef::PARAM_MAX2 => ApiBase::LIMIT_BIG1,
			],
			'includeemail' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'includeresolution' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
		];
	}

	protected function getExamplesMessages(): array {
		return [
			'action=query&list=saintapediafeedback&spfstatus=new&spflimit=20'
				=> 'apihelp-query+saintapediafeedback-example-new',
			'action=query&list=saintapediafeedback&spfstatus=new|reviewed'
				. '&spfsince=2026-10-01T00:00:00Z&spfsort=oldest'
				=> 'apihelp-query+saintapediafeedback-example-since',
			'action=query&list=saintapediafeedback&spfpage=Main_Page&spfstatus=*'
				=> 'apihelp-query+saintapediafeedback-example-page',
			'action=query&list=saintapediafeedback&spfstatus=actioned&spfincluderesolution=1'
				=> 'apihelp-query+saintapediafeedback-example-resolution',
		];
	}
}
