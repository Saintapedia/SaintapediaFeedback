<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Api;

use ApiBase;
use ApiResult;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackFilters;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * action=saintapediafeedbackupdate — set workflow status (and optional
 * private work note / public resolution summary) on one or more feedback
 * items. The API twin of the status buttons on Special:SaintapediaFeedback.
 *
 * Gate: logged-in, not blocked, FeedbackAccess::userCanManage() (same as the
 * dashboard) AND the saintapediafeedback-process right. On a bot password /
 * OAuth session the processfeedback grant supplies that right.
 *
 * Writes go through FeedbackStore::updateStatus() — the exact call the
 * dashboard makes — so the audit history (spf_feedback_log.log_user_id),
 * fb_status_user_id, the optimistic concurrency guard (F-05) and the
 * "dismissed leaves the public list" rule all behave identically. The actor
 * recorded is the API user (for a bot password, the main account, e.g.
 * FeedbackBot). Contact emails are never read or written. No notifications
 * or webhooks fire, exactly like the dashboard.
 *
 * Deliberate difference from the dashboard: "actioned" is PRIVATE unless the
 * caller sends public=1 (the dashboard defaults to public). Bot-written text
 * should not reach readers without someone opting in.
 */
class ApiUpdateFeedback extends ApiBase {

	public const MAX_IDS = 50;
	public const MAX_NOTE = 2000;
	public const MAX_SUMMARY = 1000;
	/** $wgRateLimits key; a no-op unless the wiki configures it. */
	public const RATE_LIMIT_ACTION = 'saintapediafeedback-update';

	private FeedbackStore $store;

	public function __construct( $main, $moduleName, FeedbackStore $store ) {
		parent::__construct( $main, $moduleName );
		$this->store = $store;
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$user = $this->getUser();

		// 1. Who is asking?
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
		// isAllowed() already respects bot-password / OAuth grants.
		if ( !$this->getAuthority()->isAllowed( 'saintapediafeedback-process' ) ) {
			$this->dieWithError(
				[ 'apierror-permissiondenied', $this->msg( 'action-saintapediafeedback-process' ) ],
				'permissiondenied'
			);
		}

		// 2. Is the request itself valid?
		$status = (string)$params['status'];
		$allowed = FeedbackFilters::processActions();
		if ( !in_array( $status, $allowed, true ) ) {
			$this->dieWithError(
				[
					'apierror-saintapediafeedback-badstatus',
					wfEscapeWikiText( $status ),
					implode( ', ', $allowed ),
				],
				'badstatus'
			);
		}
		$note = $params['note'];
		$summary = $params['summary'];
		$public = (bool)$params['public'];
		if ( $note !== null && mb_strlen( trim( $note ) ) > self::MAX_NOTE ) {
			$this->dieWithError( [ 'apierror-saintapediafeedback-toolong', 'note', self::MAX_NOTE ], 'toolong' );
		}
		if ( $summary !== null && mb_strlen( trim( $summary ) ) > self::MAX_SUMMARY ) {
			$this->dieWithError( [ 'apierror-saintapediafeedback-toolong', 'summary', self::MAX_SUMMARY ], 'toolong' );
		}
		if ( $status !== 'actioned' && ( ( $summary !== null && trim( $summary ) !== '' ) || $public ) ) {
			$this->dieWithError( 'apierror-saintapediafeedback-actionedonly', 'actionedonly' );
		}

		$ids = array_values( array_unique( array_filter(
			array_map( 'intval', $params['ids'] ),
			static fn ( int $id ) => $id > 0
		) ) );
		if ( !$ids ) {
			$this->dieWithError( [ 'apierror-saintapediafeedback-nosuchfeedback', '0', 1 ], 'nosuchfeedback' );
		}

		// 3. Rate limit (each id counts as one action).
		if ( $this->hitRateLimit( count( $ids ) ) ) {
			$this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
		}

		// 4. All-or-nothing existence check: if any id is unknown, write nothing.
		$rows = [];
		$missing = [];
		foreach ( $ids as $id ) {
			$row = $this->store->getById( $id );
			if ( $row ) {
				$rows[$id] = $row;
			} else {
				$missing[] = $id;
			}
		}
		if ( $missing ) {
			$this->dieWithError(
				[
					'apierror-saintapediafeedback-nosuchfeedback',
					implode( ', ', $missing ),
					count( $missing ),
				],
				'nosuchfeedback'
			);
		}

		// 5. Write, one item at a time, through the dashboard's own code path.
		$opts = FeedbackFilters::statusUpdateOpts( $note, $summary, $status === 'actioned' && $public );
		$actorId = $user->getId();
		$items = [];
		$updated = 0;
		foreach ( $ids as $id ) {
			$item = [ 'id' => $id, 'oldstatus' => (string)$rows[$id]->fb_status ];
			if ( $this->store->updateStatus( $id, $status, null, $actorId, $opts ) ) {
				$item['result'] = 'success';
				$item['status'] = $status;
				if ( $status === 'actioned' ) {
					$item['public'] = (bool)$opts['resolutionPublic'];
				}
				$updated++;
			} else {
				// F-05 guard: someone else changed it between our read and write.
				$item['result'] = 'conflict';
			}
			$items[] = $item;
		}

		if ( $updated === count( $ids ) ) {
			$overall = 'success';
		} elseif ( $updated === 0 ) {
			$overall = 'failure';
		} else {
			$overall = 'partial';
		}
		ApiResult::setIndexedTagName( $items, 'feedback' );
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'    => $overall,
			'status'    => $status,
			'updated'   => $updated,
			'conflicts' => count( $ids ) - $updated,
			'items'     => $items,
		] );
	}

	/**
	 * Authority::limit() exists from MW 1.41; fall back to User::pingLimiter()
	 * on older cores. Returns true when the caller is over the limit.
	 */
	private function hitRateLimit( int $incrBy ): bool {
		$authority = $this->getAuthority();
		if ( method_exists( $authority, 'limit' ) ) {
			return !$authority->limit( self::RATE_LIMIT_ACTION, $incrBy, null );
		}
		return $this->getUser()->pingLimiter( self::RATE_LIMIT_ACTION, $incrBy );
	}

	/**
	 * Same hardening as ApiQuerySaintapediaFeedback: FeedbackAccess's
	 * group-list fallback does not see bot-password grants, so on a
	 * grant-restricted session require the right itself.
	 */
	private function sessionAllows( string $right ): bool {
		$allowed = $this->getRequest()->getSession()->getAllowedUserRights();
		if ( $allowed === null ) {
			return true;
		}
		return $this->getAuthority()->isAllowed( $right );
	}

	public function mustBePosted(): bool {
		return true;
	}

	public function isWriteMode(): bool {
		return true;
	}

	/** @return string */
	public function needsToken() {
		return 'csrf';
	}

	public function getAllowedParams(): array {
		return [
			'ids' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => self::MAX_IDS,
				ParamValidator::PARAM_ISMULTI_LIMIT2 => self::MAX_IDS,
			],
			'status' => [
				// Validated in execute() so a bad value gives "badstatus".
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG_APPEND => [
					[ 'apihelp-saintapediafeedbackupdate-param-status-values' ],
				],
			],
			'note' => [
				ParamValidator::PARAM_TYPE => 'text',
			],
			'summary' => [
				ParamValidator::PARAM_TYPE => 'text',
			],
			'public' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
		];
	}

	protected function getExamplesMessages(): array {
		return [
			'action=saintapediafeedbackupdate&ids=23&status=reviewed'
				. '&note=Checked+the+source;+date+is+wrong&token=123ABC'
				=> 'apihelp-saintapediafeedbackupdate-example-reviewed',
			'action=saintapediafeedbackupdate&ids=23&status=actioned'
				. '&summary=Corrected+the+feast+date.&public=1&token=123ABC'
				=> 'apihelp-saintapediafeedbackupdate-example-actioned',
			'action=saintapediafeedbackupdate&ids=24|25|26&status=dismissed'
				. '&note=Spam&token=123ABC'
				=> 'apihelp-saintapediafeedbackupdate-example-bulk',
		];
	}
}
