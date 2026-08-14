<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Special;

use ErrorPageError;
use Html;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackAccess;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackFilters;
use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use MediaWiki\Extension\SaintapediaFeedback\TalkLinkPoster;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\TitleFactory;
use PermissionsError;
use SpecialPage;
use Title;
use User;

/**
 * Editor dashboard + per-page feedback review.
 *
 * - Special:SaintapediaFeedback — all feedback, filter/sort/search/bulk process
 * - Special:SaintapediaFeedback/<pageid> — one article
 * - Special:SaintapediaFeedback/export — JSON of current filters
 * - Special:SaintapediaFeedback/export/<pageid> — JSON for one page
 */
class SpecialFeedback extends SpecialPage {

	private const PAGE_SIZE = 50;

	private FeedbackStore $store;
	private TitleFactory $titleFactory;

	/** @var string Subpage from execute() (page id, export/…, resolutions/…) */
	private string $subpage = '';

	public function __construct( FeedbackStore $store, TitleFactory $titleFactory ) {
		// Restriction right kept for Special:ListGroupRights / LocalSettings;
		// actual access is FeedbackAccess (wiki page + defaults + explicit right).
		parent::__construct( 'SaintapediaFeedback', 'saintapediafeedback-view' );
		$this->store = $store;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * Allow access via MediaWiki:SaintapediaFeedback-access groups (default: named accounts, not temp),
	 * or the saintapediafeedback-view right.
	 *
	 * @param User $user
	 * @return bool
	 */
	public function userCanExecute( $user ) {
		return FeedbackAccess::userCanManage( $user );
	}

	/**
	 * @throws PermissionsError
	 */
	public function checkPermissions() {
		if ( !$this->userCanExecute( $this->getUser() ) ) {
			throw new PermissionsError( 'saintapediafeedback-view' );
		}
	}

	public function doesWrites(): bool {
		return true;
	}

	public function execute( $par ): void {
		$this->setHeaders();
		$out = $this->getOutput();
		// mediawiki.special is styles-only; our module is loaded once via addModules (scripts+styles)
		$out->addModuleStyles( [ 'mediawiki.special' ] );
		$out->addModules( 'ext.saintapediafeedback.special' );

		$par = (string)( $par ?? '' );
		$this->subpage = $par;
		$request = $this->getRequest();

		// Public resolutions list — no login (only actioned items marked public)
		if ( $par === 'resolutions' || strpos( $par, 'resolutions/' ) === 0 ) {
			$this->showPublicResolutions( $par );
			return;
		}

		$this->checkPermissions();

		// JSON export routes (editors only — same right as this page)
		if ( $par === 'export' || strpos( $par, 'export/' ) === 0 ) {
			$this->handleExport( $par );
			return;
		}

		if ( $request->wasPosted() ) {
			$this->checkReadOnly();
		}

		// Status mutations (POST + CSRF) before rendering
		if ( $this->handleStatusUpdate() || $this->handleBulkStatusUpdate() ) {
			return;
		}
		$this->showMutationFlash();

		// Subpage form Special:SaintapediaFeedback/<pageid> → per-article view.
		// Query ?pageid= is reserved for dashboard filtering (not page view).
		if ( $par !== '' && ctype_digit( $par ) ) {
			$this->showPageFeedback( (int)$par );
			return;
		}

		// Title lookup: jump to per-article view by name (restores pre-dashboard UX).
		// Uses dedicated submit name so filter "Apply" does not redirect.
		if ( $request->getCheck( 'spf_goto' ) ) {
			$pagename = trim( (string)$request->getVal( 'pagename', '' ) );
			if ( $pagename !== '' ) {
				$title = Title::newFromText( $pagename );
				if ( $title && $title->exists() ) {
					$this->getOutput()->redirect(
						$this->getPageTitle( (string)$title->getArticleID() )->getLocalURL()
					);
					return;
				}
				// Fall through to dashboard with a not-found notice
				$this->getOutput()->addHTML(
					'<div class="errorbox">' .
					$this->msg( 'saintapediafeedback-special-notfound' )->escaped() .
					'</div>'
				);
			}
		}

		$this->showDashboard();
	}

	private function showDashboard(): void {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'saintapediafeedback-dashboard-title' ) );

		// Help editors find the access config page
		$accessTitle = FeedbackAccess::getAccessPageTitle();
		if ( $accessTitle ) {
			$out->addSubtitle(
				$this->msg( 'saintapediafeedback-access-help' )
					->rawParams(
						Html::element( 'a', [ 'href' => $accessTitle->getLocalURL() ],
							$accessTitle->getPrefixedText() )
					)
					->parse()
			);
		}

		$filters = $this->getFiltersFromRequest();
		$limit = self::PAGE_SIZE;

		// Title typed into filter form → resolve to pageId for dashboard filter
		if ( !empty( $filters['pageNotFound'] ) ) {
			$out->addHTML(
				'<div class="errorbox">' .
				$this->msg( 'saintapediafeedback-special-notfound' )->escaped() .
				'</div>'
			);
		}

		$counts = $this->store->countByStatus( $filters );
		$total = $this->store->countDashboard( $filters );
		$offset = FeedbackFilters::clampOffset(
			$this->getRequest()->getInt( 'offset' ),
			$total,
			$limit
		);
		$rows = $this->store->getDashboard( $filters, $limit, $offset );

		$out->addHTML( $this->renderPageLookupForm() );
		$out->addHTML( $this->renderSummaryChips( $counts, $filters ) );
		$out->addHTML( $this->renderFilterForm( $filters ) );

		if ( !empty( $filters['pageId'] ) && !empty( $filters['pageLabel'] ) ) {
			// Pass clear overrides via $extra so filtersToQuery's null-page branch runs
			$clearUrl = $this->getPageTitle()->getLocalURL(
				$this->filtersToQuery( $filters, [ 'pageId' => null, 'pagename' => null ] )
			);
			$out->addHTML(
				Html::rawElement( 'p', [ 'class' => 'spf-dashboard-page-filter' ],
					$this->msg( 'saintapediafeedback-dashboard-filtered-page' )
						->rawParams( htmlspecialchars( $filters['pageLabel'] ) )
						->parse() .
					' · ' .
					Html::element( 'a', [ 'href' => $clearUrl ],
						$this->msg( 'saintapediafeedback-dashboard-clear-page' )->text() )
				)
			);
		}

		if ( !$rows ) {
			$out->addWikiMsg( 'saintapediafeedback-dashboard-empty' );
			return;
		}

		$out->addHTML(
			Html::rawElement( 'p', [ 'class' => 'spf-dashboard-meta' ],
				$this->msg( 'saintapediafeedback-dashboard-showing' )
					->numParams( $offset + 1, min( $offset + count( $rows ), $total ), $total )
					->escaped()
			)
		);

		// Bulk form is only the toolbar; row checkboxes associate via form="spf-bulk-form"
		// so single-item status forms are not nested (invalid HTML).
		$bulkAction = $this->getPageTitle()->getLocalURL(
			FeedbackFilters::withOffset( $this->filtersToQuery( $filters ), $offset )
		);
		$out->addHTML( Html::openElement( 'form', [
			'method' => 'post',
			'action' => $bulkAction,
			'class'  => 'spf-bulk-form',
			'id'     => 'spf-bulk-form',
		] ) );
		$out->addHTML( Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) );
		$out->addHTML( $this->renderBulkToolbar() );
		$out->addHTML( Html::closeElement( 'form' ) );

		$out->addHTML( Html::openElement( 'div', [ 'class' => 'spf-feedback-list spf-dashboard-list' ] ) );
		foreach ( $rows as $row ) {
			$this->renderFeedbackRow( $row, true );
		}
		$out->addHTML( Html::closeElement( 'div' ) );

		$out->addHTML( $this->renderPagination( $filters, $offset, $limit, $total ) );

		$exportUrl = $this->getPageTitle( 'export' )->getLocalURL( $this->filtersToQuery( $filters ) );
		$out->addHTML( '<p class="spf-export-link">' .
			$out->msg( 'saintapediafeedback-export-link' )->rawParams(
				'<a href="' . htmlspecialchars( $exportUrl ) . '">' .
				$out->msg( 'saintapediafeedback-export-link-text' )->escaped() . '</a>'
			)->parse() .
		'</p>' );
	}

	private function showPageFeedback( int $pageId ): void {
		$out  = $this->getOutput();
		$title = $this->titleFromId( $pageId );

		if ( !$title ) {
			$out->setPageTitle( $this->msg( 'saintapediafeedback-special-title' ) );
			$out->addWikiMsg( 'saintapediafeedback-special-notfound' );
			return;
		}

		$out->setPageTitle( $this->msg( 'saintapediafeedback-special-page-title', $title->getPrefixedText() ) );
		$out->addBacklinkSubtitle( $this->getPageTitle() );
		$out->addSubtitle(
			Html::element( 'a', [ 'href' => $title->getLocalURL() ], $title->getPrefixedText() )
		);

		$limit = self::PAGE_SIZE;
		$total = (int)( $this->store->getPageCounts( $pageId )['total'] ?? 0 );
		$offset = FeedbackFilters::clampOffset(
			$this->getRequest()->getInt( 'offset' ),
			$total,
			$limit
		);
		$rows = $this->store->getForPage( $pageId, $limit, $offset );

		if ( !$rows ) {
			$out->addWikiMsg( 'saintapediafeedback-special-nofeedback' );
			return;
		}

		if ( $total > 0 ) {
			$out->addHTML(
				Html::rawElement( 'p', [ 'class' => 'spf-dashboard-meta' ],
					$this->msg( 'saintapediafeedback-dashboard-showing' )
						->numParams( $offset + 1, min( $offset + count( $rows ), $total ), $total )
						->escaped()
				)
			);
		}

		$out->addHTML( '<div class="spf-feedback-list">' );
		foreach ( $rows as $row ) {
			$this->renderFeedbackRow( $row, false );
		}
		$out->addHTML( '</div>' );

		$out->addHTML( $this->renderPagination( [], $offset, $limit, $total, (string)$pageId ) );

		$exportUrl = $this->getPageTitle( 'export/' . $pageId )->getLocalURL();
		$out->addHTML( '<p class="spf-export-link">' .
			$out->msg( 'saintapediafeedback-export-link' )->rawParams(
				'<a href="' . htmlspecialchars( $exportUrl ) . '">' .
				$out->msg( 'saintapediafeedback-export-link-text' )->escaped() . '</a>'
			)->parse() .
		'</p>' );
	}

	/**
	 * Process status change only on POST with a valid edit token.
	 *
	 * @return bool True when a redirect was issued
	 */
	private function handleStatusUpdate(): bool {
		$request = $this->getRequest();
		// Bulk form uses spfbulkaction — ignore here
		if ( $request->getVal( 'spfbulkaction' ) ) {
			return false;
		}
		$action = $request->getVal( 'spfaction' );
		$fbId   = (int)$request->getVal( 'spfid' );
		$pageId = (int)$request->getVal( 'spfpageid' );
		$validActions = FeedbackFilters::processActions();

		if ( !$action || !$fbId || !in_array( $action, $validActions, true ) ) {
			return false;
		}

		if ( !$request->wasPosted() ) {
			return false;
		}

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			throw new ErrorPageError( 'sessionfailure-title', 'sessionfailure' );
		}

		// Prefer scoped update when page id is known
		$actorId = FeedbackAccess::isPersistentAccount( $this->getUser() )
			? $this->getUser()->getId()
			: null;
		// Actioned → public by default. Checkbox is checked by default; spfpublic_present
		// means the form included the control (unchecked = not in POST).
		if ( $action === 'actioned' ) {
			$makePublic = $request->getCheck( 'spfpublic_present' )
				? $request->getCheck( 'spfpublic' )
				: true;
		} else {
			$makePublic = false;
		}
		$opts = FeedbackFilters::statusUpdateOpts(
			$request->getVal( 'spfworknote' ),
			$request->getVal( 'spfressummary' ),
			$makePublic
		);
		$updated = $this->store->updateStatus(
			$fbId,
			$action,
			$pageId > 0 ? $pageId : null,
			$actorId,
			$opts
		);

		if ( !$updated ) {
			return false;
		}

		// Optional Talk note: short link only (never dumps work notes / raw feedback)
		$talkFlash = null;
		if (
			$action === 'actioned'
			&& $request->getCheck( 'spftalk' )
			&& MediaWikiServices::getInstance()->getMainConfig()->get( 'SaintapediaFeedbackEnableTalkLink' )
		) {
			$article = $this->titleFromId( $pageId );
			if ( !$article ) {
				$article = $this->resolveArticleForFeedback( $fbId );
			}
			$talkOk = false;
			if ( $article ) {
				$talkOk = TalkLinkPoster::postResolutionLink(
					$article,
					$fbId,
					$this->getUser(),
					$makePublic
				);
			}
			$talkFlash = $talkOk ? 'ok' : 'fail';
		}

		$flash = [ 'spfok' => '1' ];
		if ( $talkFlash !== null ) {
			$flash['spftalk'] = $talkFlash;
		}
		$this->redirectAfterMutation( $flash );
		return true;
	}

	/**
	 * Look up the article Title for a feedback row by id (from fb_page_id).
	 *
	 * @return Title|null
	 */
	private function resolveArticleForFeedback( int $fbId ) {
		try {
			$services = MediaWikiServices::getInstance();
			$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
			$pageId = (int)$dbr->selectField(
				'spf_feedback',
				'fb_page_id',
				[ 'fb_id' => $fbId ],
				__METHOD__
			);
			return $this->titleFromId( $pageId );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Bulk status change for selected dashboard rows (POST + CSRF).
	 *
	 * @return bool True when a redirect was issued
	 */
	private function handleBulkStatusUpdate(): bool {
		$request = $this->getRequest();
		$action = $request->getVal( 'spfbulkaction' );
		if ( !$action || !$request->wasPosted() ) {
			return false;
		}
		if ( !in_array( $action, FeedbackFilters::processActions(), true ) ) {
			return false;
		}
		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			throw new ErrorPageError( 'sessionfailure-title', 'sessionfailure' );
		}

		$ids = $request->getArray( 'spfids' ) ?? [];
		if ( !is_array( $ids ) ) {
			$ids = [];
		}
		$actorId = FeedbackAccess::isPersistentAccount( $this->getUser() )
			? $this->getUser()->getId()
			: null;
		$workNote = $request->getText( 'spfworknote' );
		// Same as single-item: actioned → public by default; checkbox opt-out when present
		$resolutionPublic = null;
		if ( $action === 'actioned' ) {
			$resolutionPublic = $request->getCheck( 'spfpublic_present' )
				? $request->getCheck( 'spfpublic' )
				: true;
		}
		$n = $this->store->updateStatusBulk(
			$ids,
			$action,
			$actorId,
			$workNote !== '' ? $workNote : null,
			$resolutionPublic
		);
		$this->redirectAfterMutation( [
			'spfok' => '1',
			'spfn' => (string)$n,
		] );
		return true;
	}

	/**
	 * PRG: after a successful POST, redirect so refresh does not re-submit.
	 */
	private function redirectAfterMutation( array $flash ): void {
		$offset = $this->getRequest()->getInt( 'offset' );
		if ( $this->subpage !== '' && ctype_digit( $this->subpage ) ) {
			$query = FeedbackFilters::withOffset( $flash, $offset );
			$url = $this->getPageTitle( $this->subpage )->getLocalURL( $query );
		} else {
			$query = FeedbackFilters::withOffset(
				$this->filtersToQuery( $this->getFiltersFromRequest() ),
				$offset
			);
			$url = $this->getPageTitle()->getLocalURL( array_merge( $query, $flash ) );
		}
		$this->getOutput()->redirect( $url );
	}

	/** Show success/Talk banners after a PRG redirect (query flags only). */
	private function showMutationFlash(): void {
		$request = $this->getRequest();
		if ( $request->getCheck( 'spfok' ) ) {
			if ( $request->getVal( 'spfn' ) !== null ) {
				$this->getOutput()->addHTML(
					'<div class="successbox">' .
					$this->msg( 'saintapediafeedback-bulk-updated' )
						->numParams( $request->getInt( 'spfn' ) )->escaped() .
					'</div>'
				);
			} else {
				$this->getOutput()->addHTML(
					'<div class="successbox">' .
					$this->msg( 'saintapediafeedback-status-updated' )->escaped() .
					'</div>'
				);
			}
		}
		$talk = $request->getVal( 'spftalk' );
		if ( $talk === 'ok' ) {
			$this->getOutput()->addHTML(
				'<div class="successbox">' .
				$this->msg( 'saintapediafeedback-talk-posted' )->escaped() .
				'</div>'
			);
		} elseif ( $talk === 'fail' ) {
			$this->getOutput()->addHTML(
				'<div class="warningbox">' .
				$this->msg( 'saintapediafeedback-talk-failed' )->escaped() .
				'</div>'
			);
		}
	}

	/**
	 * Anyone can view public resolutions (actioned + published). No raw reader comments.
	 */
	private function showPublicResolutions( string $par ): void {
		$out = $this->getOutput();
		$pageId = 0;
		if ( preg_match( '#^resolutions/(\d+)$#', $par, $m ) ) {
			$pageId = (int)$m[1];
		} else {
			$pageId = $this->getRequest()->getInt( 'pageid' );
		}

		if ( !$pageId ) {
			$out->setPageTitle( $this->msg( 'saintapediafeedback-resolutions-title' ) );
			$out->addWikiMsg( 'saintapediafeedback-resolutions-need-page' );
			return;
		}

		$title = $this->titleFromId( $pageId );
		if ( !$title ) {
			$out->setPageTitle( $this->msg( 'saintapediafeedback-resolutions-title' ) );
			$out->addWikiMsg( 'saintapediafeedback-special-notfound' );
			return;
		}

		$out->setPageTitle(
			$this->msg( 'saintapediafeedback-resolutions-page-title', $title->getPrefixedText() )
		);
		$out->addSubtitle( Html::element( 'a', [ 'href' => $title->getLocalURL() ],
			$title->getPrefixedText() ) );

		$rows = $this->store->getPublicResolutions( $pageId );
		if ( !$rows ) {
			$out->addWikiMsg( 'saintapediafeedback-resolutions-empty' );
			return;
		}

		$out->addHTML( Html::openElement( 'div', [ 'class' => 'spf-feedback-list spf-public-resolutions' ] ) );
		foreach ( $rows as $row ) {
			$categories = json_decode( $row->fb_categories, true ) ?? [];
			$catLabels = implode( ', ', array_map( function ( $cat ) {
				return $this->msg( 'saintapediafeedback-category-' . $cat )->text();
			}, $categories ) );
			$when = $row->fb_status_timestamp
				? $this->getLanguage()->userDate( $row->fb_status_timestamp, $this->getUser() )
				: '';
			$summary = $row->fb_resolution_summary
				?: $this->msg( 'saintapediafeedback-resolutions-default-summary' )->text();

			$out->addHTML( '<div class="spf-feedback-item spf-status-actioned">' );
			$out->addHTML( '<div class="spf-feedback-meta">'
				. '<span class="spf-id">#' . (int)$row->fb_id . '</span> '
				. ( $when !== '' ? '<span class="spf-time">' . htmlspecialchars( $when ) . '</span>' : '' )
				. '</div>' );
			if ( $catLabels !== '' ) {
				$out->addHTML( '<div class="spf-feedback-categories">'
					. $this->msg( 'saintapediafeedback-special-categories' )->escaped()
					. ' <strong>' . htmlspecialchars( $catLabels ) . '</strong></div>' );
			}
			$out->addHTML( '<div class="spf-feedback-comment">'
				. nl2br( htmlspecialchars( $summary ) ) . '</div>' );
			$out->addHTML( '</div>' );
		}
		$out->addHTML( Html::closeElement( 'div' ) );
	}

	private function handleExport( string $par ): void {
		$out = $this->getOutput();
		$out->disable();

		$filters = $this->getFiltersFromRequest();
		if ( preg_match( '#^export/(\d+)$#', $par, $m ) ) {
			$data = $this->store->exportForPage( (int)$m[1] );
		} else {
			$data = $this->store->exportDashboard( $filters, 500 );
		}

		$response = $this->getRequest()->response();
		$response->header( 'Content-Type: application/json; charset=utf-8' );
		$response->header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.echo
		echo json_encode( [
			'count' => count( $data ),
			'items' => $data,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @return array{
	 *   status:string,category:string,sort:string,pageId:?int,
	 *   pagename:?string,pageLabel:?string,pageNotFound:bool,search:string
	 * }
	 */
	private function getFiltersFromRequest(): array {
		$req = $this->getRequest();
		$status = FeedbackFilters::normalizeStatus( $req->getVal( 'status', 'new' ) );
		$category = FeedbackFilters::normalizeCategory( $req->getVal( 'category', 'all' ) );
		$sort = FeedbackFilters::normalizeSort( $req->getVal( 'sort', 'newest' ) );
		$search = FeedbackFilters::sanitizeSearch( $req->getVal( 'q', '' ) );

		$pageId = $req->getInt( 'pageid' ) ?: null;
		$pagename = trim( (string)$req->getVal( 'pagename', '' ) );
		$pageLabel = null;
		$pageNotFound = false;

		// Resolve article title → pageId for dashboard filtering (and redisplay label)
		if ( $pagename !== '' && !$req->getCheck( 'spf_goto' ) ) {
			$title = Title::newFromText( $pagename );
			if ( $title && $title->exists() ) {
				$pageId = $title->getArticleID();
				$pageLabel = $title->getPrefixedText();
				$pagename = $pageLabel;
			} else {
				$pageNotFound = true;
				$pageId = null;
			}
		} elseif ( $pageId ) {
			$title = $this->titleFromId( $pageId );
			if ( $title ) {
				$pageLabel = $title->getPrefixedText();
				$pagename = $pageLabel;
			}
		}

		return [
			'status'       => $status,
			'category'     => $category,
			'sort'         => $sort,
			'pageId'       => $pageId,
			'pagename'     => $pagename !== '' ? $pagename : null,
			'pageLabel'    => $pageLabel,
			'pageNotFound' => $pageNotFound,
			'search'       => $search,
		];
	}

	/**
	 * Build query params for dashboard links.
	 * To clear the page filter, pass null pageId/pagename via $extra (or $filters);
	 * !empty() omits them so they do not appear in the URL.
	 */
	private function filtersToQuery( array $filters, array $extra = [] ): array {
		$merged = array_merge( $filters, $extra );
		$q = [
			'status'   => $merged['status'] ?? 'new',
			'category' => $merged['category'] ?? 'all',
			'sort'     => $merged['sort'] ?? 'newest',
		];
		// null/empty pageId or pagename (clear filter) is intentionally omitted
		if ( !empty( $merged['pageId'] ) ) {
			$q['pageid'] = (int)$merged['pageId'];
		}
		if ( !empty( $merged['pagename'] ) ) {
			$q['pagename'] = $merged['pagename'];
		}
		if ( !empty( $merged['search'] ) ) {
			$q['q'] = $merged['search'];
		}
		if ( isset( $merged['offset'] ) && $merged['offset'] !== null && $merged['offset'] !== '' ) {
			$q['offset'] = (int)$merged['offset'];
		}
		return $q;
	}

	private function renderBulkToolbar(): string {
		$html = Html::openElement( 'div', [ 'class' => 'spf-bulk-toolbar' ] );
		$html .= Html::rawElement( 'label', [ 'class' => 'spf-bulk-select-all' ],
			Html::input( 'spf-select-all', '1', 'checkbox', [ 'id' => 'spf-select-all' ] ) .
			' ' . $this->msg( 'saintapediafeedback-bulk-select-all' )->escaped()
		);
		$html .= Html::openElement( 'select', [
			'name' => 'spfbulkaction',
			'id'   => 'spf-bulk-action',
			'required' => 'required',
		] );
		$html .= Html::element( 'option', [ 'value' => '' ],
			$this->msg( 'saintapediafeedback-bulk-action' )->text() );
		foreach ( FeedbackFilters::processActions() as $status ) {
			$html .= Html::element( 'option', [ 'value' => $status ],
				$this->msg( 'saintapediafeedback-action-' . $status )->text() );
		}
		$html .= Html::closeElement( 'select' );
		$html .= Html::element( 'label', [
			'for' => 'spf-bulk-worknote',
			'class' => 'spf-filter-label',
		], $this->msg( 'saintapediafeedback-work-note' )->text() );
		$html .= Html::textarea( 'spfworknote', '', [
			'id' => 'spf-bulk-worknote',
			'class' => 'spf-work-note',
			'rows' => 2,
			'placeholder' => $this->msg( 'saintapediafeedback-work-note-placeholder' )->text(),
		] );
		$html .= Html::rawElement( 'p', [ 'class' => 'spf-action-hint' ],
			$this->msg( 'saintapediafeedback-work-note-visibility' )->escaped() );
		// Same control as single-item actioned form: default on, uncheck to keep private.
		// Shown only when bulk action is "actioned" (see special.js); server ignores otherwise.
		$html .= Html::hidden( 'spfpublic_present', '1' );
		$html .= Html::openElement( 'span', [
			'id' => 'spf-bulk-public-wrap',
			'class' => 'spf-bulk-public-wrap',
		] );
		$html .= Html::rawElement( 'label', [ 'class' => 'spf-public-check' ],
			Html::input( 'spfpublic', '1', 'checkbox', [
				'checked' => 'checked',
				'id' => 'spf-bulk-public',
			] ) . ' '
			. $this->msg( 'saintapediafeedback-make-public' )->escaped()
		);
		$html .= Html::closeElement( 'span' );
		$html .= Html::submitButton(
			$this->msg( 'saintapediafeedback-bulk-apply' )->text(),
			[ 'class' => 'spf-filter-submit' ]
		);
		$html .= Html::closeElement( 'div' );
		return $html;
	}

	/**
	 * Jump-to-article form: type a title, open that page's feedback view.
	 * Restores the pre-dashboard title lookup for "does article X have feedback?"
	 */
	private function renderPageLookupForm(): string {
		$html = Html::openElement( 'form', [
			'method' => 'get',
			'action' => $this->getPageTitle()->getLocalURL(),
			'class'  => 'spf-page-lookup-form',
		] );
		$html .= Html::openElement( 'div', [ 'class' => 'spf-filter-row' ] );
		$html .= Html::label(
			$this->msg( 'saintapediafeedback-special-pagename' )->text(),
			'spf-lookup-pagename',
			[ 'class' => 'spf-filter-label' ]
		);
		$html .= Html::input( 'pagename', '', 'text', [
			'id'          => 'spf-lookup-pagename',
			'class'       => 'spf-lookup-input',
			'placeholder' => $this->msg( 'saintapediafeedback-special-pagename' )->text(),
			'size'        => 40,
		] );
		// Distinct submit name so this does not collide with filter Apply
		$html .= Html::submitButton(
			$this->msg( 'saintapediafeedback-special-submit' )->text(),
			[ 'name' => 'spf_goto', 'value' => '1', 'class' => 'spf-filter-submit' ]
		);
		$html .= Html::closeElement( 'div' );
		$html .= Html::rawElement( 'p', [ 'class' => 'spf-lookup-help' ],
			$this->msg( 'saintapediafeedback-lookup-help' )->escaped()
		);
		$html .= Html::closeElement( 'form' );
		return $html;
	}

	private function renderSummaryChips( array $counts, array $filters ): string {
		$html = Html::openElement( 'div', [ 'class' => 'spf-summary-chips' ] );
		foreach ( [ 'new', 'reviewed', 'actioned', 'dismissed', 'all' ] as $status ) {
			$url = $this->getPageTitle()->getLocalURL(
				$this->filtersToQuery( array_merge( $filters, [ 'status' => $status ] ) )
			);
			$active = ( ( $filters['status'] ?? 'new' ) === $status ) ? ' spf-chip-filter--active' : '';
			$label = $this->msg( 'saintapediafeedback-status-' . $status )->text();
			$count = (int)( $counts[$status] ?? 0 );
			$html .= Html::rawElement(
				'a',
				[
					'href'  => $url,
					'class' => 'spf-chip-filter spf-chip-filter-' . $status . $active,
				],
				Html::element( 'span', [ 'class' => 'spf-chip-label' ], $label ) .
				Html::element( 'span', [ 'class' => 'spf-chip-count' ], (string)$count )
			);
		}
		$html .= Html::closeElement( 'div' );
		return $html;
	}

	private function renderFilterForm( array $filters ): string {
		$html = Html::openElement( 'form', [
			'method' => 'get',
			'action' => $this->getPageTitle()->getLocalURL(),
			'class'  => 'spf-filter-form',
		] );

		// Article filter (dashboard): filter list by page without leaving the dashboard
		$html .= Html::openElement( 'div', [ 'class' => 'spf-filter-row' ] );
		$html .= Html::label(
			$this->msg( 'saintapediafeedback-filter-page' )->text(),
			'spf-filter-pagename',
			[ 'class' => 'spf-filter-label' ]
		);
		$html .= Html::input( 'pagename', (string)( $filters['pagename'] ?? '' ), 'text', [
			'id'    => 'spf-filter-pagename',
			'class' => 'spf-lookup-input',
			'size'  => 40,
		] );
		$html .= Html::closeElement( 'div' );

		// Free-text search (comment + page title)
		$html .= Html::openElement( 'div', [ 'class' => 'spf-filter-row' ] );
		$html .= Html::label(
			$this->msg( 'saintapediafeedback-filter-search' )->text(),
			'spf-filter-q',
			[ 'class' => 'spf-filter-label' ]
		);
		$html .= Html::input( 'q', (string)( $filters['search'] ?? '' ), 'search', [
			'id'          => 'spf-filter-q',
			'class'       => 'spf-lookup-input',
			'placeholder' => $this->msg( 'saintapediafeedback-filter-search-placeholder' )->text(),
			'size'        => 40,
		] );
		$html .= Html::closeElement( 'div' );

		$html .= Html::openElement( 'div', [ 'class' => 'spf-filter-row' ] );

		// Status
		$html .= Html::label(
			$this->msg( 'saintapediafeedback-filter-status' )->text(),
			'spf-filter-status',
			[ 'class' => 'spf-filter-label' ]
		);
		$html .= Html::openElement( 'select', [ 'name' => 'status', 'id' => 'spf-filter-status' ] );
		foreach ( array_merge( [ 'all' ], FeedbackFilters::VALID_STATUSES ) as $status ) {
			$opt = [ 'value' => $status ];
			if ( ( $filters['status'] ?? '' ) === $status ) {
				$opt['selected'] = 'selected';
			}
			$html .= Html::element( 'option', $opt,
				$this->msg( 'saintapediafeedback-status-' . $status )->text() );
		}
		$html .= Html::closeElement( 'select' );

		// Category
		$html .= Html::label(
			$this->msg( 'saintapediafeedback-filter-category' )->text(),
			'spf-filter-category',
			[ 'class' => 'spf-filter-label' ]
		);
		$html .= Html::openElement( 'select', [ 'name' => 'category', 'id' => 'spf-filter-category' ] );
		$allCat = [ 'value' => 'all' ];
		if ( ( $filters['category'] ?? 'all' ) === 'all' ) {
			$allCat['selected'] = 'selected';
		}
		$html .= Html::element( 'option', $allCat,
			$this->msg( 'saintapediafeedback-filter-category-all' )->text() );
		foreach ( FeedbackFilters::VALID_CATEGORIES as $cat ) {
			$opt = [ 'value' => $cat ];
			if ( ( $filters['category'] ?? '' ) === $cat ) {
				$opt['selected'] = 'selected';
			}
			$html .= Html::element( 'option', $opt,
				$this->msg( 'saintapediafeedback-category-' . $cat )->text() );
		}
		$html .= Html::closeElement( 'select' );

		// Sort
		$html .= Html::label(
			$this->msg( 'saintapediafeedback-filter-sort' )->text(),
			'spf-filter-sort',
			[ 'class' => 'spf-filter-label' ]
		);
		$html .= Html::openElement( 'select', [ 'name' => 'sort', 'id' => 'spf-filter-sort' ] );
		foreach ( FeedbackFilters::VALID_SORTS as $sort ) {
			$opt = [ 'value' => $sort ];
			if ( ( $filters['sort'] ?? '' ) === $sort ) {
				$opt['selected'] = 'selected';
			}
			$html .= Html::element( 'option', $opt,
				$this->msg( 'saintapediafeedback-sort-' . $sort )->text() );
		}
		$html .= Html::closeElement( 'select' );

		$html .= Html::submitButton(
			$this->msg( 'saintapediafeedback-filter-apply' )->text(),
			[ 'class' => 'spf-filter-submit' ]
		);

		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'form' );
		return $html;
	}

	/**
	 * @param array $filters Dashboard filters (ignored when $subpage is a page id)
	 * @param string|null $subpage Per-article view page id, or null for dashboard
	 */
	private function renderPagination(
		array $filters,
		int $offset,
		int $limit,
		int $total,
		?string $subpage = null
	): string {
		if ( $total <= $limit ) {
			return '';
		}
		$html = Html::openElement( 'nav', [
			'class'      => 'spf-pagination',
			'aria-label' => $this->msg( 'saintapediafeedback-pagination' )->text(),
		] );
		$title = $subpage !== null ? $this->getPageTitle( $subpage ) : $this->getPageTitle();
		if ( $offset > 0 ) {
			$prev = max( 0, $offset - $limit );
			$query = $subpage !== null
				? [ 'offset' => $prev ]
				: $this->filtersToQuery( $filters, [ 'offset' => $prev ] );
			$html .= Html::element( 'a', [ 'href' => $title->getLocalURL( $query ), 'class' => 'spf-page-prev' ],
				$this->msg( 'saintapediafeedback-pagination-prev' )->text() );
		}
		if ( $offset + $limit < $total ) {
			$next = $offset + $limit;
			$query = $subpage !== null
				? [ 'offset' => $next ]
				: $this->filtersToQuery( $filters, [ 'offset' => $next ] );
			$html .= Html::element( 'a', [ 'href' => $title->getLocalURL( $query ), 'class' => 'spf-page-next' ],
				$this->msg( 'saintapediafeedback-pagination-next' )->text() );
		}
		$html .= Html::closeElement( 'nav' );
		return $html;
	}

	/**
	 * @param object $row DB row
	 * @param bool $showPage Whether to show article title (dashboard mode)
	 */
	private function renderFeedbackRow( object $row, bool $showPage ): void {
		$out        = $this->getOutput();
		$categories = json_decode( $row->fb_categories, true ) ?? [];
		$catLabels  = implode( ', ', array_map( function ( $cat ) {
			return $this->msg( 'saintapediafeedback-category-' . $cat )->text();
		}, $categories ) );

		$statusClass = 'spf-status-' . htmlspecialchars( $row->fb_status );
		$time = $this->getLanguage()->userTimeAndDate( $row->fb_timestamp, $this->getUser() );

		$out->addHTML( '<div class="spf-feedback-item ' . $statusClass . '">' );
		$out->addHTML( '<div class="spf-feedback-meta">' );
		if ( $showPage ) {
			$out->addHTML(
				Html::input( 'spfids[]', (string)(int)$row->fb_id, 'checkbox', [
					'class' => 'spf-row-check',
					'form'  => 'spf-bulk-form',
				] ) . ' '
			);
		}
		$out->addHTML( '<span class="spf-id">#' . (int)$row->fb_id . '</span> ' );
		$out->addHTML( '<span class="spf-time">' . htmlspecialchars( $time ) . '</span> ' );
		$out->addHTML( '<span class="spf-mode spf-mode-' . htmlspecialchars( $row->fb_mode ) . '">'
			. htmlspecialchars( $row->fb_mode ) . '</span> ' );
		$out->addHTML( '<span class="spf-status spf-status-badge">'
			. htmlspecialchars( $this->msg( 'saintapediafeedback-status-' . $row->fb_status )->text() )
			. '</span>' );
		if ( !empty( $row->fb_priority ) ) {
			$out->addHTML( ' <span class="spf-priority-badge">'
				. $this->msg( 'saintapediafeedback-priority-sme' )->escaped()
				. '</span>' );
		}
		$out->addHTML( '</div>' );

		// Audit: last status change
		if ( !empty( $row->fb_status_timestamp ) ) {
			$actor = '';
			if ( !empty( $row->fb_status_user_id ) ) {
				$u = \MediaWiki\MediaWikiServices::getInstance()
					->getUserFactory()
					->newFromId( (int)$row->fb_status_user_id );
				if ( $u && $u->isRegistered() ) {
					$actor = $u->getName();
				}
			}
			$statusTime = $this->getLanguage()->userTimeAndDate(
				$row->fb_status_timestamp,
				$this->getUser()
			);
			if ( $actor !== '' ) {
				$auditHtml = $this->msg( 'saintapediafeedback-audit-by' )
					->params( $actor, $statusTime )->parse();
			} else {
				$auditHtml = $this->msg( 'saintapediafeedback-audit-unknown' )
					->params( $statusTime )->parse();
			}
			$out->addHTML( '<div class="spf-audit">' . $auditHtml . '</div>' );
		}

		if ( $showPage ) {
			$pageTitle = $this->titleFromId( (int)$row->fb_page_id );
			$pageLabel = $pageTitle
				? $pageTitle->getPrefixedText()
				: str_replace( '_', ' ', $row->fb_page_title );
			$pageUrl = $pageTitle ? $pageTitle->getLocalURL() : '#';
			$filterUrl = $this->getPageTitle( (string)(int)$row->fb_page_id )->getLocalURL();
			$out->addHTML( '<div class="spf-feedback-page">'
				. $this->msg( 'saintapediafeedback-dashboard-page' )->escaped() . ' '
				. Html::element( 'a', [ 'href' => $pageUrl ], $pageLabel )
				. ' · '
				. Html::element( 'a', [ 'href' => $filterUrl ],
					$this->msg( 'saintapediafeedback-dashboard-page-only' )->text() )
				. '</div>' );
		}

		$out->addHTML( '<div class="spf-feedback-categories">'
			. $this->msg( 'saintapediafeedback-special-categories' )->escaped()
			. ' <strong>' . htmlspecialchars( $catLabels ) . '</strong></div>' );

		if ( $row->fb_comment ) {
			$out->addHTML( '<div class="spf-feedback-comment">'
				. nl2br( htmlspecialchars( $row->fb_comment ) ) . '</div>' );
		}

		// Work note (managers only — not on Talk; visible to anyone who can open this dashboard)
		if ( !empty( $row->fb_work_note ) ) {
			$out->addHTML( '<div class="spf-work-note-display">'
				. '<strong>' . $this->msg( 'saintapediafeedback-work-note' )->escaped() . ':</strong> '
				. nl2br( htmlspecialchars( $row->fb_work_note ) )
				. '</div>' );
		}
		if ( !empty( $row->fb_resolution_public ) && $row->fb_status === 'actioned' ) {
			$out->addHTML( '<div class="spf-public-flag">'
				. $this->msg( 'saintapediafeedback-resolution-is-public' )->escaped()
				. '</div>' );
		}

		// Status action forms: POST + CSRF; work note encouraged (especially for actioned).
		// Include offset so PRG after submit stays on the page the editor was triaging.
		$offset = max( 0, $this->getRequest()->getInt( 'offset' ) );
		$actionUrl = $showPage
			? $this->getPageTitle()->getLocalURL(
				FeedbackFilters::withOffset( $this->filtersToQuery( $this->getFiltersFromRequest() ), $offset )
			)
			: $this->getPageTitle( (string)$row->fb_page_id )->getLocalURL(
				FeedbackFilters::withOffset( [], $offset )
			);

		$out->addHTML( '<div class="spf-feedback-actions">' );
		foreach ( [ 'reviewed', 'actioned', 'dismissed' ] as $status ) {
			if ( $row->fb_status === $status ) {
				continue;
			}
			$formClass = 'spf-status-form spf-status-form-' . $status;
			$html = Html::openElement( 'form', [
				'method' => 'post',
				'action' => $actionUrl,
				'class'  => $formClass,
			] );
			$html .= Html::hidden( 'spfaction', $status );
			$html .= Html::hidden( 'spfid', (string)(int)$row->fb_id );
			$html .= Html::hidden( 'spfpageid', (string)(int)$row->fb_page_id );
			$html .= Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() );

			if ( $status === 'actioned' ) {
				$html .= Html::rawElement( 'p', [ 'class' => 'spf-action-hint' ],
					$this->msg( 'saintapediafeedback-actioned-hint' )->escaped() );
				$html .= Html::textarea( 'spfworknote', '', [
					'class' => 'spf-work-note',
					'rows' => 2,
					'placeholder' => $this->msg( 'saintapediafeedback-work-note-placeholder' )->text(),
				] );
				$html .= Html::rawElement( 'p', [ 'class' => 'spf-action-hint' ],
					$this->msg( 'saintapediafeedback-work-note-visibility' )->escaped() );
				// Default checked: actioned → public resolutions list
				$html .= Html::hidden( 'spfpublic_present', '1' );
				$html .= Html::rawElement( 'label', [ 'class' => 'spf-public-check' ],
					Html::input( 'spfpublic', '1', 'checkbox', [ 'checked' => 'checked' ] ) . ' '
					. $this->msg( 'saintapediafeedback-make-public' )->escaped()
				);
				$html .= Html::input( 'spfressummary', '', 'text', [
					'class' => 'spf-res-summary',
					'placeholder' => $this->msg( 'saintapediafeedback-resolution-summary-placeholder' )->text(),
					'size' => 40,
				] );
				$talkEnabled = \MediaWiki\MediaWikiServices::getInstance()
					->getMainConfig()
					->get( 'SaintapediaFeedbackEnableTalkLink' );
				if ( $talkEnabled ) {
					$html .= Html::rawElement( 'label', [ 'class' => 'spf-public-check' ],
						Html::input( 'spftalk', '1', 'checkbox' ) . ' '
						. $this->msg( 'saintapediafeedback-post-talk-link' )->escaped()
					);
					$html .= Html::rawElement( 'p', [ 'class' => 'spf-action-hint' ],
						$this->msg( 'saintapediafeedback-post-talk-hint' )->escaped()
					);
				}
			}

			$html .= Html::submitButton(
				$this->msg( 'saintapediafeedback-action-' . $status )->text(),
				[ 'class' => 'spf-action-btn spf-action-' . $status ]
			);
			$html .= Html::closeElement( 'form' );
			$out->addHTML( $html );
		}

		// Link to public resolutions for this page
		$pubUrl = $this->getPageTitle( 'resolutions/' . (int)$row->fb_page_id )->getLocalURL();
		$out->addHTML( '<p class="spf-public-link">'
			. Html::element( 'a', [ 'href' => $pubUrl ],
				$this->msg( 'saintapediafeedback-resolutions-link' )->text() )
			. '</p>' );

		$out->addHTML( '</div>' );
		$out->addHTML( '</div>' );
	}

	/** @return Title|null */
	private function titleFromId( int $pageId ) {
		return $pageId > 0 ? $this->titleFactory->newFromID( $pageId ) : null;
	}

	protected function getGroupName(): string {
		return 'wiki';
	}
}
