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
				'fb_timestamp'      => $db->timestamp(),
			],
			__METHOD__
		);
		return $db->insertId();
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

	/**
	 * Bulk-update workflow status for many feedback ids.
	 *
	 * @param int[] $ids
	 * @return int Number of rows updated
	 */
	public function updateStatusBulk( array $ids, string $status ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( !$ids || !in_array( $status, FeedbackFilters::processActions(), true ) ) {
			return 0;
		}
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$db->update(
			'spf_feedback',
			[ 'fb_status' => $status ],
			[ 'fb_id' => $ids ],
			__METHOD__
		);
		return $db->affectedRows();
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
	 * Update workflow status for a feedback row.
	 *
	 * When $pageId is provided, the row must belong to that page (prevents
	 * cross-page status mutation from the special-page UI).
	 *
	 * @return bool True if a matching row was updated
	 */
	public function updateStatus( int $id, string $status, ?int $pageId = null ): bool {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$conds = [ 'fb_id' => $id ];
		if ( $pageId !== null ) {
			$conds['fb_page_id'] = $pageId;
		}
		$db->update(
			'spf_feedback',
			[ 'fb_status' => $status ],
			$conds,
			__METHOD__
		);
		return $db->affectedRows() > 0;
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
