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
}
