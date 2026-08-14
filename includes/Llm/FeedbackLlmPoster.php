<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Llm;

/**
 * HTTP POST of a JSON batch. Implementation lives outside this interface
 * (MediaWiki HttpRequestFactory, or a test fake).
 */
interface FeedbackLlmPoster {

	/**
	 * @return int HTTP status (0 = transport failure)
	 */
	public function postJson( string $url, array $payload, string $token = '' ): int;
}
