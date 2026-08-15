<?php

use MediaWiki\Extension\SaintapediaFeedback\FeedbackStore;
use MediaWiki\MediaWikiServices;

return [
	'SaintapediaFeedback.FeedbackStore' => static function ( MediaWikiServices $services ): FeedbackStore {
		return new FeedbackStore(
			$services->getDBLoadBalancer()
		);
	},
];
