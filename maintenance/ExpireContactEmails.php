<?php

use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Clear fb_contact_email on rows older than the configured retention
 * window (F-09). Only the email field is cleared — status, categories,
 * comment, and audit history are kept, so this is safe to run repeatedly
 * and does not affect the dashboard, exports, or LLM payloads, none of
 * which include contact email regardless of this job's schedule.
 *
 *   php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ExpireContactEmails.php --dry-run
 *   php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ExpireContactEmails.php --days 90
 */
class ExpireContactEmails extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Clear fb_contact_email on feedback rows older than the retention window.'
		);
		$this->addOption( 'dry-run', 'Print how many rows would be cleared; do not write' );
		$this->addOption(
			'days',
			'Override $wgSaintapediaFeedbackContactEmailRetentionDays',
			false,
			true
		);
		$this->addOption(
			'limit',
			'Max rows cleared per batch (default 500); repeat the job to continue a large backlog',
			false,
			true
		);
		$this->requireExtension( 'SaintapediaFeedback' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$dryRun = $this->hasOption( 'dry-run' );
		$days = (int)$this->getOption(
			'days',
			(int)$config->get( 'SaintapediaFeedbackContactEmailRetentionDays' )
		);
		$limit = max( 1, (int)$this->getOption( 'limit', 500 ) );

		/** @var \MediaWiki\Extension\SaintapediaFeedback\FeedbackStore $store */
		$store = $services->getService( 'SaintapediaFeedback.FeedbackStore' );

		// --dry-run always works, even with retention disabled (days < 1) —
		// it never writes, so there's nothing for the disabled-by-default
		// gate below to protect. Matches the documented invocation example
		// and lets an operator preview what a --days value would do before
		// touching $wgSaintapediaFeedbackContactEmailRetentionDays.
		if ( $dryRun ) {
			if ( $days < 1 ) {
				$this->output(
					"Dry run: retention is disabled (\$wgSaintapediaFeedbackContactEmailRetentionDays "
						. "is 0 and no --days was given). Nothing would be cleared. Pass --days N to "
						. "preview a specific window, e.g. --dry-run --days 90.\n"
				);
				return;
			}
			$count = $store->countExpirableContactEmails( $days );
			$this->output(
				"Dry run: {$count} row(s) have a contact email older than {$days} day(s) and "
					. "would be cleared. No changes made.\n"
			);
			return;
		}

		if ( $days < 1 ) {
			$this->fatalError(
				'Retention is disabled: $wgSaintapediaFeedbackContactEmailRetentionDays is 0 '
					. 'and no --days was given. Set one of those to a positive number of days, '
					. 'or use --dry-run to preview without changing the config.'
			);
		}

		$total = 0;
		do {
			$cleared = $store->expireContactEmails( $days, $limit );
			$total += $cleared;
			if ( $cleared > 0 ) {
				$this->output( "Cleared {$cleared} row(s)...\n" );
			}
		} while ( $cleared === $limit );

		$this->output( "Done. Cleared contact email on {$total} row(s) older than {$days} day(s).\n" );
	}
}

$maintClass = ExpireContactEmails::class;
require_once RUN_MAINTENANCE_IF_MAIN;
