<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaFeedback\FeedbackStore
 */
class FeedbackStoreFieldsTest extends TestCase {

	public function testManagerListFieldsOmitContactAndIpHash(): void {
		$fields = FeedbackStore::MANAGER_LIST_FIELDS;
		$this->assertNotContains( 'fb_contact_email', $fields );
		$this->assertNotContains( 'fb_ip_hash', $fields );
		$this->assertContains( 'fb_id', $fields );
		$this->assertContains( 'fb_comment', $fields );
		$this->assertContains( 'fb_work_note', $fields );
		$this->assertContains( 'fb_resolution_summary', $fields );
	}
}
