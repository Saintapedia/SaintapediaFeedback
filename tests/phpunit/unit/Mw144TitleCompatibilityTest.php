<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * MW 1.44 (T352725-adjacent core cleanup): the legacy global `Title` class
 * alias was removed. Extensions must import `MediaWiki\Title\Title` instead
 * of relying on the bare global name. This regression previously reached
 * production: FeedbackNotifier::notifyNew() declared a `Title` parameter
 * that resolved to the removed alias, so a real MediaWiki\Title\Title
 * passed in from ApiSubmitFeedback caused a TypeError *after* the feedback
 * row had already been inserted (2026-09-10 external code review).
 */
class Mw144TitleCompatibilityTest extends TestCase {

	public function testPhpSourcesDoNotImportTheRemovedGlobalTitleAlias(): void {
		$root = dirname( __DIR__, 3 ) . '/includes';
		$offenders = [];
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $files as $file ) {
			if ( !$file->isFile() || $file->getExtension() !== 'php' ) {
				continue;
			}
			$src = file_get_contents( $file->getPathname() );
			// A `use` import of the bare, unqualified global `Title` class,
			// not `MediaWiki\Title\Title` (nor any other `...\Title`).
			if ( preg_match( '/^use\s+Title\s*;/m', $src ) ) {
				$offenders[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
		$this->assertSame(
			[],
			$offenders,
			"Import MediaWiki\\Title\\Title, not the removed global Title alias (MW 1.44+)"
		);
	}
}
