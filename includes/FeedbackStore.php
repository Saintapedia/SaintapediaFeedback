<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

use MediaWiki\Config\Config;
use Wikimedia\Rdbms\ILoadBalancer;

class FeedbackStore {

	private ILoadBalancer $loadBalancer;
	private Config $config;

	public function __construct( ILoadBalancer $loadBalancer, Config $config ) {
		$this->loadBalancer = $loadBalancer;
		$this->config = $config;
	}

	public function insert( array $data ): int {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$db->insert(
			'spf_feedback',
			[
				'fb_page_id'        => $data['pageId'],
				'fb_page_namespace' => $data['namespace'],
				'fb_page_title'     => $data['title'],
				'fb_user_id'        => $data['userId'] ?? null,
				'fb_ip_hash'        => $data['ipHash'],
				'fb_categories'     => json_encode( $data['categories'] ),
				'fb_comment'        => $data['comment'] ?? null,
				'fb_contact_email'  => $data['contactEmail'] ?? null,
				'fb_mode'           => $data['mode'],
				'fb_status'         => 'new',
				'fb_priority'       => (int)( $data['priority'] ?? 0 ),
				'fb_timestamp'      => $db->timestamp(),
			],
			__METHOD__
		);
		return $db->insertId();
	}

	/**
	 * Counts for a page: open (new+reviewed), resolved (actioned), total, new-only.
	 *
	 * @return array{open:int,resolved:int,total:int,new:int}
	 */
	public function getPageCounts( int $pageId ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $db->select(
			'spf_feedback',
			[ 'fb_status', 'cnt' => 'COUNT(*)' ],
			[ 'fb_page_id' => $pageId ],
			__METHOD__,
			[ 'GROUP BY' => 'fb_status' ]
		);
		$by = [ 'new' => 0, 'reviewed' => 0, 'actioned' => 0, 'dismissed' => 0 ];
		foreach ( $res as $row ) {
			$by[(string)$row->fb_status] = (int)$row->cnt;
		}
		$open = $by['new'] + $by['reviewed'];
		$resolved = $by['actioned'];
		$total = array_sum( $by );
		return [
			'open'     => $open,
			'resolved' => $resolved,
			'total'    => $total,
			'new'      => $by['new'],
		];
	}

	/** Count submissions from a given IP hash within the past 24 hours. */
	public function countRecentByIpHash( string $ipHash ): int {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$cutoff = $db->timestamp( time() - 86400 );
		return (int)$db->selectField(
			'spf_feedback',
			'COUNT(*)',
			[
				'fb_ip_hash'    => $ipHash,
				'fb_timestamp > ' . $db->addQuotes( $cutoff ),
			],
			__METHOD__
		);
	}

	/** Fetch feedback rows for a page, newest first. */
	public function getForPage( int $pageId, int $limit = 50, int $offset = 0 ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$rows = $db->select(
			'spf_feedback',
			'*',
			[ 'fb_page_id' => $pageId ],
			__METHOD__,
			[
				'ORDER BY' => 'fb_timestamp DESC',
				'LIMIT'    => $limit,
				'OFFSET'   => $offset,
			]
		);
		return iterator_to_array( $rows );
	}

	/**
	 * Dashboard listing with filters and sort.
	 *
	 * @param array $filters Keys: status, category, pageId, sort, search
	 * @return object[]
	 */
	public function getDashboard( array $filters, int $limit = 50, int $offset = 0 ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		[ $conds, $options ] = $this->buildDashboardQuery( $db, $filters, $limit, $offset );
		$rows = $db->select( 'spf_feedback', '*', $conds, __METHOD__, $options );
		return iterator_to_array( $rows );
	}

	/** Total rows matching dashboard filters (for pagination). */
	public function countDashboard( array $filters ): int {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		[ $conds ] = $this->buildDashboardQuery( $db, $filters, null, null );
		return (int)$db->selectField( 'spf_feedback', 'COUNT(*)', $conds, __METHOD__ );
	}

	/**
	 * Counts keyed by status for summary chips (respects page/category/search filters).
	 *
	 * @return array<string,int>
	 */
	public function countByStatus( array $filters = [] ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		// Ignore status filter for summary counts
		$filtersForSummary = $filters;
		unset( $filtersForSummary['status'] );
		[ $conds ] = $this->buildDashboardQuery( $db, $filtersForSummary, null, null );

		$res = $db->select(
			'spf_feedback',
			[ 'fb_status', 'cnt' => 'COUNT(*)' ],
			$conds,
			__METHOD__,
			[ 'GROUP BY' => 'fb_status' ]
		);

		$counts = [
			'new'       => 0,
			'reviewed'  => 0,
			'actioned'  => 0,
			'dismissed' => 0,
		];
		foreach ( $res as $row ) {
			$status = (string)$row->fb_status;
			$counts[$status] = (int)$row->cnt;
		}
		$counts['all'] = array_sum( $counts );
		return $counts;
	}

	/**
	 * @param \Wikimedia\Rdbms\IDatabase $db
	 * @param array $filters
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return array{0:array,1:array}
	 */
	private function buildDashboardQuery( $db, array $filters, ?int $limit, ?int $offset ): array {
		$conds = [];

		$status = $filters['status'] ?? null;
		if ( is_string( $status ) && $status !== '' && $status !== 'all' ) {
			$conds['fb_status'] = $status;
		}

		$pageId = $filters['pageId'] ?? null;
		if ( $pageId ) {
			$conds['fb_page_id'] = (int)$pageId;
		}

		$category = $filters['category'] ?? null;
		if ( is_string( $category ) && $category !== '' && $category !== 'all' ) {
			// Categories stored as JSON array strings; LIKE is good enough for allowlisted chips
			$conds[] = 'fb_categories ' . $db->buildLike(
				$db->anyString(),
				'"' . $category . '"',
				$db->anyString()
			);
		}

		$search = FeedbackFilters::sanitizeSearch( $filters['search'] ?? null );
		if ( $search !== '' ) {
			$like = $db->buildLike( $db->anyString(), $search, $db->anyString() );
			$conds[] = $db->makeList( [
				'fb_comment ' . $like,
				'fb_page_title ' . $like,
			], LIST_OR );
		}

		$sort = ( $filters['sort'] ?? 'newest' ) === 'oldest' ? 'ASC' : 'DESC';
		$options = [ 'ORDER BY' => "fb_timestamp $sort, fb_id $sort" ];
		if ( $limit !== null ) {
			$options['LIMIT'] = $limit;
		}
		if ( $offset !== null ) {
			$options['OFFSET'] = $offset;
		}

		return [ $conds, $options ];
	}

	/** Fetch unprocessed feedback rows for LLM batch processing. */
	public function getPendingLlmBatch( int $limit = 100 ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$rows = $db->select(
			'spf_feedback',
			'*',
			[
				'fb_llm_processed' => 0,
				'fb_status'        => [ 'new', 'reviewed' ],
			],
			__METHOD__,
			[
				'ORDER BY' => 'fb_timestamp ASC',
				'LIMIT'    => $limit,
			]
		);
		return iterator_to_array( $rows );
	}

	public function markLlmProcessed( array $ids ): void {
		if ( !$ids ) {
			return;
		}
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$db->update(
			'spf_feedback',
			[
				'fb_llm_processed'           => 1,
				'fb_llm_processed_timestamp' => $db->timestamp(),
			],
			[ 'fb_id' => $ids ],
			__METHOD__
		);
	}

	/**
	 * Update workflow status for a feedback row and append an audit log entry.
	 *
	 * When $pageId is provided, the row must belong to that page (prevents
	 * cross-page status mutation from the special-page UI).
	 *
	 * @param int|null $actorUserId User who performed the change (null = system)
	 * @return bool True if a matching row was updated
	 */
	/**
	 * Update workflow status for a feedback row and append an audit log entry.
	 *
	 * @param array $opts Optional keys:
	 *   - workNote (string|null) private editor note
	 *   - resolutionPublic (bool) expose on public resolutions list (actioned only)
	 *   - resolutionSummary (string|null) short public summary
	 * @return bool True if a matching row was updated
	 */
	public function updateStatus(
		int $id,
		string $status,
		?int $pageId = null,
		?int $actorUserId = null,
		array $opts = []
	): bool {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$conds = [ 'fb_id' => $id ];
		if ( $pageId !== null ) {
			$conds['fb_page_id'] = $pageId;
		}

		$row = $db->selectRow( 'spf_feedback', '*', $conds, __METHOD__ );
		if ( !$row ) {
			return false;
		}
		$old = (string)$row->fb_status;

		$workNote = isset( $opts['workNote'] ) ? $this->clampNote( $opts['workNote'] ) : null;
		// Default: actioned items are public (product: resolutions list should fill when work is done).
		// Pass resolutionPublic=false to keep an actioned item private.
		$resPublic = $status === 'actioned'
			&& ( !array_key_exists( 'resolutionPublic', $opts ) || !empty( $opts['resolutionPublic'] ) );
		$resSummary = array_key_exists( 'resolutionSummary', $opts )
			? $this->clampNote( $opts['resolutionSummary'], 1000 )
			: null;

		$set = [
			'fb_status'           => $status,
			'fb_status_user_id'   => $actorUserId,
			'fb_status_timestamp' => $db->timestamp(),
		];
		// Always allow updating notes when provided (including re-action)
		if ( array_key_exists( 'workNote', $opts ) ) {
			$set['fb_work_note'] = $workNote;
		}
		if ( $status === 'actioned' ) {
			$set['fb_resolution_public'] = $resPublic ? 1 : 0;
			if ( $resSummary !== null ) {
				$set['fb_resolution_summary'] = $resSummary;
			} elseif ( $resPublic && empty( $row->fb_resolution_summary ) ) {
				// Leave null — UI uses default "Addressed." message for empty summary
				$set['fb_resolution_summary'] = null;
			}
		} elseif ( $status === 'dismissed' ) {
			// Dismissed items should not stay on the public resolution list
			$set['fb_resolution_public'] = 0;
		}

		$ts = $set['fb_status_timestamp'];
		$db->update( 'spf_feedback', $set, $conds, __METHOD__ );
		if ( !$db->affectedRows() && $old === $status ) {
			// Status unchanged but notes may have updated
			return true;
		}
		if ( $old !== $status || $workNote !== null ) {
			$this->insertStatusLog(
				$db, $id, $actorUserId, $old, $status, $ts, $workNote
			);
		}
		return true;
	}

	/**
	 * Bulk-update workflow status for many feedback ids (with audit).
	 * Bulk "actioned" defaults to public resolution (same as single-item);
	 * pass $resolutionPublic=false to keep private.
	 *
	 * @param int[] $ids
	 * @param bool|null $resolutionPublic When actioned: true/false publish flag; null = default public
	 * @return int Number of rows updated
	 */
	public function updateStatusBulk(
		array $ids,
		string $status,
		?int $actorUserId = null,
		?string $workNote = null,
		?bool $resolutionPublic = null
	): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( !$ids || !in_array( $status, FeedbackFilters::processActions(), true ) ) {
			return 0;
		}
		$opts = [];
		if ( $workNote !== null && $workNote !== '' ) {
			$opts['workNote'] = $workNote;
		}
		// Same product default as single-item: actioned → public unless explicitly false
		if ( $status === 'actioned' ) {
			$opts['resolutionPublic'] = $resolutionPublic !== false;
		}
		$n = 0;
		foreach ( $ids as $id ) {
			if ( $this->updateStatus( $id, $status, null, $actorUserId, $opts ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Public resolutions for a page (actioned + flagged public). No auth required.
	 *
	 * @return object[]
	 */
	public function getPublicResolutions( int $pageId, int $limit = 50 ): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		if ( !$db->fieldExists( 'spf_feedback', 'fb_resolution_public', __METHOD__ ) ) {
			return [];
		}
		$rows = $db->select(
			'spf_feedback',
			[
				'fb_id',
				'fb_categories',
				'fb_resolution_summary',
				'fb_status_timestamp',
				'fb_timestamp',
			],
			[
				'fb_page_id' => $pageId,
				'fb_status' => 'actioned',
				'fb_resolution_public' => 1,
			],
			__METHOD__,
			[
				'ORDER BY' => 'fb_status_timestamp DESC',
				'LIMIT' => $limit,
			]
		);
		return iterator_to_array( $rows );
	}

	private function clampNote( $note, int $max = 2000 ): ?string {
		if ( $note === null ) {
			return null;
		}
		$note = trim( (string)$note );
		if ( $note === '' ) {
			return null;
		}
		if ( mb_strlen( $note ) > $max ) {
			$note = mb_substr( $note, 0, $max );
		}
		return $note;
	}

	/**
	 * Append a status-change row to spf_feedback_log.
	 *
	 * Trade-off (intentional): failures are swallowed so a missing table during
	 * migration or a transient DB error does not roll back the status change
	 * itself. The denormalized last-actor fields on spf_feedback still update.
	 * Consequence: the append-only history can have silent gaps; operators
	 * should ensure update.php has run and watch the SaintapediaFeedback log.
	 *
	 * @param \Wikimedia\Rdbms\IDatabase $db
	 */
	private function insertStatusLog(
		$db,
		int $fbId,
		?int $actorUserId,
		?string $oldStatus,
		string $newStatus,
		string $ts,
		?string $note = null
	): void {
		try {
			if ( !$db->tableExists( 'spf_feedback_log', __METHOD__ ) ) {
				return;
			}
			$row = [
				'log_fb_id'       => $fbId,
				'log_user_id'     => $actorUserId,
				'log_old_status'  => $oldStatus,
				'log_new_status'  => $newStatus,
				'log_timestamp'   => $ts,
			];
			if ( $db->fieldExists( 'spf_feedback_log', 'log_note', __METHOD__ ) ) {
				$row['log_note'] = $note;
			}
			$db->insert( 'spf_feedback_log', $row, __METHOD__ );
		} catch ( \Throwable $e ) {
			wfDebugLog( 'SaintapediaFeedback', 'audit log insert failed: ' . $e->getMessage() );
		}
	}

	/** Export feedback for a page as structured data for LLM consumption. */
	public function exportForPage( int $pageId ): array {
		$rows = $this->getForPage( $pageId, 500 );
		return array_map( static function ( $row ) {
			return [
				'id'         => (int)$row->fb_id,
				'timestamp'  => $row->fb_timestamp,
				'categories' => json_decode( $row->fb_categories, true ) ?? [],
				'comment'    => $row->fb_comment,
				'status'     => $row->fb_status,
			];
		}, $rows );
	}

	/** Export filtered dashboard rows as structured data for LLM consumption. */
	public function exportDashboard( array $filters, int $limit = 500 ): array {
		$rows = $this->getDashboard( $filters, $limit, 0 );
		return array_map( static function ( $row ) {
			return [
				'id'         => (int)$row->fb_id,
				'pageId'     => (int)$row->fb_page_id,
				'pageTitle'  => $row->fb_page_title,
				'timestamp'  => $row->fb_timestamp,
				'categories' => json_decode( $row->fb_categories, true ) ?? [],
				'comment'    => $row->fb_comment,
				'status'     => $row->fb_status,
				'mode'       => $row->fb_mode,
			];
		}, $rows );
	}
}
