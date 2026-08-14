<?php

use MediaWiki\Extension\SaintapediaFeedback\Llm\FeedbackLlmBatchRunner;
use MediaWiki\Extension\SaintapediaFeedback\Llm\MwHttpFeedbackLlmPoster;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Pull unprocessed feedback and POST it to the configured LLM webhook.
 *
 * Does not change workflow status. On HTTP 2xx, sets fb_llm_processed.
 *
 *   php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php
 *   php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php --dry-run
 */
class ProcessFeedbackLlm extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'POST pending article feedback to the LLM webhook and mark those rows processed.'
		);
		$this->addOption( 'dry-run', 'Print the batch JSON; do not POST or mark processed' );
		$this->addOption( 'limit', 'Max rows this run (default: $wgSaintapediaFeedbackLlmBatchSize)', false, true );
		$this->addOption( 'webhook', 'Override $wgSaintapediaFeedbackLlmWebhook', false, true );
		$this->requireExtension( 'SaintapediaFeedback' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$webhook = (string)$this->getOption(
			'webhook',
			(string)$config->get( 'SaintapediaFeedbackLlmWebhook' )
		);
		$limit = (int)$this->getOption(
			'limit',
			(int)$config->get( 'SaintapediaFeedbackLlmBatchSize' )
		);
		$token = (string)$config->get( 'SaintapediaFeedbackLlmWebhookToken' );
		$dryRun = $this->hasOption( 'dry-run' );

		/** @var \MediaWiki\Extension\SaintapediaFeedback\FeedbackStore $store */
		$store = $services->getService( 'SaintapediaFeedback.FeedbackStore' );
		$poster = new MwHttpFeedbackLlmPoster( $services->getHttpRequestFactory() );
		$runner = new FeedbackLlmBatchRunner( $store, $poster );
		$result = $runner->run( $webhook, $limit, $dryRun, $token );

		if ( $result['error'] === 'webhook-unconfigured' ) {
			$this->fatalError(
				'Set $wgSaintapediaFeedbackLlmWebhook or pass --webhook (or use --dry-run).'
			);
		}

		$this->output( json_encode( [
			'count'  => $result['count'],
			'ids'    => $result['ids'],
			'status' => $result['status'],
			'marked' => $result['marked'],
			'error'  => $result['error'],
			'dryRun' => $dryRun,
			'items'  => $dryRun ? $result['items'] : null,
		], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n" );

		if ( $result['error'] && $result['error'] !== 'webhook-unconfigured' ) {
			$this->fatalError( 'Webhook failed: ' . $result['error'] );
		}
	}
}

$maintClass = ProcessFeedbackLlm::class;
require_once RUN_MAINTENANCE_IF_MAIN;
