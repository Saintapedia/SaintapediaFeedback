<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Llm;

/**
 * Map a store row to the LLM-safe export shape.
 *
 * Never includes work notes, contact email, or IP hash.
 */
class FeedbackLlmPayload {

	/**
	 * @param object $row DB row (stdClass)
	 * @return array{
	 *   id:int,pageId:int,pageTitle:string,timestamp:string,
	 *   categories:array,comment:?string,status:string
	 * }
	 */
	public static function fromRow( object $row ): array {
		$categories = json_decode( (string)( $row->fb_categories ?? '[]' ), true );
		if ( !is_array( $categories ) ) {
			$categories = [];
		}
		return [
			'id'         => (int)$row->fb_id,
			'pageId'     => (int)( $row->fb_page_id ?? 0 ),
			'pageTitle'  => (string)( $row->fb_page_title ?? '' ),
			'timestamp'  => (string)( $row->fb_timestamp ?? '' ),
			'categories' => $categories,
			'comment'    => $row->fb_comment ?? null,
			'status'     => (string)( $row->fb_status ?? '' ),
		];
	}
}
