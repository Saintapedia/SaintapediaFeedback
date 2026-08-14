<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Llm;

/**
 * Store surface used by the LLM pull job (testable without a live DB).
 */
interface FeedbackLlmBatchSource {

	/**
	 * Unprocessed new/reviewed rows, oldest first.
	 *
	 * @return object[]
	 */
	public function getPendingLlmBatch( int $limit ): array;

	/**
	 * @param int[] $ids
	 */
	public function markLlmProcessed( array $ids ): void;
}
