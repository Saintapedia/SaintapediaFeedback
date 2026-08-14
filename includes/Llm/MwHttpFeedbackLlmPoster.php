<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Llm;

use MediaWiki\Http\HttpRequestFactory;

/**
 * POSTs a JSON batch via MediaWiki's HTTP client.
 */
class MwHttpFeedbackLlmPoster implements FeedbackLlmPoster {

	private HttpRequestFactory $http;

	public function __construct( HttpRequestFactory $http ) {
		$this->http = $http;
	}

	public function postJson( string $url, array $payload, string $token = '' ): int {
		$headers = [ 'Content-Type' => 'application/json' ];
		if ( $token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$req = $this->http->create( $url, [
			'method'  => 'POST',
			'postData' => json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'timeout' => 30,
		], __METHOD__ );
		foreach ( $headers as $name => $value ) {
			$req->setHeader( $name, $value );
		}
		$status = $req->execute();
		if ( !$status->isOK() ) {
			return 0;
		}
		return (int)$req->getStatus();
	}
}
