<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Llm;

/**
 * Pull pending feedback, POST to a webhook, mark processed on HTTP 2xx.
 *
 * Does not change fb_status — editors still own the workflow.
 */
class FeedbackLlmBatchRunner {

	private FeedbackLlmBatchSource $source;
	private FeedbackLlmPoster $poster;

	public function __construct( FeedbackLlmBatchSource $source, FeedbackLlmPoster $poster ) {
		$this->source = $source;
		$this->poster = $poster;
	}

	/**
	 * @return array{
	 *   count:int,ids:int[],items:array,status:?int,marked:bool,error:?string
	 * }
	 */
	public function run(
		string $webhook,
		int $limit,
		bool $dryRun,
		string $token = '',
		bool $enabled = false
	): array {
		$limit = max( 1, min( $limit, 500 ) );
		$empty = [
			'count'  => 0,
			'ids'    => [],
			'items'  => [],
			'status' => null,
			'marked' => false,
			'error'  => null,
		];

		// $enabled is the policy gate: false means LLM processing is disabled
		// regardless of what webhook value was supplied (LocalSettings or
		// --webhook). Checked before the webhook-unconfigured case so a
		// command-line override can never bypass the disabled state.
		if ( !$dryRun && !$enabled ) {
			$empty['error'] = 'llm-disabled';
			return $empty;
		}

		if ( !$dryRun && $webhook === '' ) {
			$empty['error'] = 'webhook-unconfigured';
			return $empty;
		}

		$rows = $this->source->getPendingLlmBatch( $limit );
		$items = [];
		$ids = [];
		foreach ( $rows as $row ) {
			$item = FeedbackLlmPayload::fromRow( $row );
			$items[] = $item;
			$ids[] = $item['id'];
		}

		$result = $empty;
		$result['count'] = count( $items );
		$result['ids'] = $ids;
		$result['items'] = $items;

		if ( !$items || $dryRun ) {
			return $result;
		}

		$payload = [
			'count' => count( $items ),
			'items' => $items,
		];
		$status = $this->poster->postJson( $webhook, $payload, $token );
		$result['status'] = $status;
		if ( $status >= 200 && $status < 300 ) {
			$this->source->markLlmProcessed( $ids );
			$result['marked'] = true;
		} else {
			$result['error'] = 'http-' . $status;
		}
		return $result;
	}
}
