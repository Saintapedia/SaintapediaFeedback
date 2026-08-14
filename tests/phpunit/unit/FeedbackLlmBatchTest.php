<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use MediaWiki\Extension\SaintapediaFeedback\Llm\FeedbackLlmBatchRunner;
use MediaWiki\Extension\SaintapediaFeedback\Llm\FeedbackLlmBatchSource;
use MediaWiki\Extension\SaintapediaFeedback\Llm\FeedbackLlmPayload;
use MediaWiki\Extension\SaintapediaFeedback\Llm\FeedbackLlmPoster;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaFeedback\Llm\FeedbackLlmPayload
 * @covers \MediaWiki\Extension\SaintapediaFeedback\Llm\FeedbackLlmBatchRunner
 */
class FeedbackLlmBatchTest extends TestCase {

	public function testPayloadOmitsPiiAndWorkNotes(): void {
		$row = (object)[
			'fb_id' => 7,
			'fb_page_id' => 42,
			'fb_page_title' => 'Example',
			'fb_timestamp' => '20260814010101',
			'fb_categories' => '["outdated"]',
			'fb_comment' => 'Fix the date',
			'fb_status' => 'new',
			'fb_mode' => 'public',
			'fb_work_note' => 'secret editor note',
			'fb_contact_email' => 'reader@example.org',
			'fb_ip_hash' => 'abc123',
		];
		$item = FeedbackLlmPayload::fromRow( $row );
		$this->assertSame( 7, $item['id'] );
		$this->assertSame( 42, $item['pageId'] );
		$this->assertSame( 'Example', $item['pageTitle'] );
		$this->assertSame( [ 'outdated' ], $item['categories'] );
		$this->assertSame( 'Fix the date', $item['comment'] );
		$this->assertArrayNotHasKey( 'workNote', $item );
		$this->assertArrayNotHasKey( 'email', $item );
		$this->assertArrayNotHasKey( 'ipHash', $item );
		$this->assertSame( [], array_intersect(
			[ 'fb_work_note', 'fb_contact_email', 'fb_ip_hash', 'work_note', 'contact_email' ],
			array_keys( $item )
		) );
	}

	public function testDryRunDoesNotPostOrMark(): void {
		$source = new FakeLlmSource( [ $this->sampleRow( 1 ) ] );
		$poster = new FakeLlmPoster( 200 );
		$runner = new FeedbackLlmBatchRunner( $source, $poster );
		$result = $runner->run( 'https://hooks.example/llm', 50, true );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( [ 1 ], $result['ids'] );
		$this->assertFalse( $result['marked'] );
		$this->assertSame( 0, $poster->calls );
		$this->assertSame( [], $source->marked );
	}

	public function testEmptyWebhookWithoutDryRunFails(): void {
		$source = new FakeLlmSource( [ $this->sampleRow( 1 ) ] );
		$poster = new FakeLlmPoster( 200 );
		$runner = new FeedbackLlmBatchRunner( $source, $poster );
		$result = $runner->run( '', 50, false );
		$this->assertNotNull( $result['error'] );
		$this->assertFalse( $result['marked'] );
		$this->assertSame( 0, $poster->calls );
		$this->assertSame( [], $source->marked );
	}

	public function testHttp2xxMarksProcessed(): void {
		$source = new FakeLlmSource( [ $this->sampleRow( 3 ), $this->sampleRow( 4 ) ] );
		$poster = new FakeLlmPoster( 204 );
		$runner = new FeedbackLlmBatchRunner( $source, $poster );
		$result = $runner->run( 'https://hooks.example/llm', 50, false );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( [ 3, 4 ], $result['ids'] );
		$this->assertTrue( $result['marked'] );
		$this->assertSame( 204, $result['status'] );
		$this->assertSame( [ 3, 4 ], $source->marked );
		$this->assertSame( 1, $poster->calls );
		$this->assertSame( 'https://hooks.example/llm', $poster->lastUrl );
		$this->assertCount( 2, $poster->lastPayload['items'] );
	}

	public function testHttpErrorDoesNotMark(): void {
		$source = new FakeLlmSource( [ $this->sampleRow( 9 ) ] );
		$poster = new FakeLlmPoster( 503 );
		$runner = new FeedbackLlmBatchRunner( $source, $poster );
		$result = $runner->run( 'https://hooks.example/llm', 50, false );
		$this->assertFalse( $result['marked'] );
		$this->assertSame( 503, $result['status'] );
		$this->assertSame( [], $source->marked );
	}

	public function testEmptyBatchIsNoop(): void {
		$source = new FakeLlmSource( [] );
		$poster = new FakeLlmPoster( 200 );
		$runner = new FeedbackLlmBatchRunner( $source, $poster );
		$result = $runner->run( 'https://hooks.example/llm', 50, false );
		$this->assertSame( 0, $result['count'] );
		$this->assertFalse( $result['marked'] );
		$this->assertSame( 0, $poster->calls );
	}

	private function sampleRow( int $id ): object {
		return (object)[
			'fb_id' => $id,
			'fb_page_id' => 10,
			'fb_page_title' => 'Page',
			'fb_timestamp' => '20260814000000',
			'fb_categories' => '["other"]',
			'fb_comment' => null,
			'fb_status' => 'new',
		];
	}
}

class FakeLlmSource implements FeedbackLlmBatchSource {
	/** @var object[] */
	public array $rows;
	/** @var int[] */
	public array $marked = [];

	/** @param object[] $rows */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function getPendingLlmBatch( int $limit ): array {
		return array_slice( $this->rows, 0, $limit );
	}

	public function markLlmProcessed( array $ids ): void {
		$this->marked = $ids;
	}
}

class FakeLlmPoster implements FeedbackLlmPoster {
	public int $status;
	public int $calls = 0;
	public ?string $lastUrl = null;
	public ?array $lastPayload = null;
	public ?string $lastToken = null;

	public function __construct( int $status ) {
		$this->status = $status;
	}

	public function postJson( string $url, array $payload, string $token = '' ): int {
		$this->calls++;
		$this->lastUrl = $url;
		$this->lastPayload = $payload;
		$this->lastToken = $token;
		return $this->status;
	}
}
