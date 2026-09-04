<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * MW 1.45 (T343994): OutputPage::setPageTitle() no longer accepts Message.
 * Localised titles must go through setPageTitleMsg().
 */
class Mw145PageTitleCompatibilityTest extends TestCase {

	public function testPhpSourcesDoNotPassMessageToSetPageTitle(): void {
		$root = dirname( __DIR__, 3 ) . '/includes';
		$offenders = [];
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $files as $file ) {
			if ( !$file->isFile() || $file->getExtension() !== 'php' ) {
				continue;
			}
			$src = file_get_contents( $file->getPathname() );
			if ( preg_match(
				'/->setPageTitle\s*\(\s*(?:\$this->msg|wfMessage|\$\w+->msg)\s*\(/s',
				$src
			) ) {
				$offenders[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
		$this->assertSame(
			[],
			$offenders,
			'Pass Message objects to setPageTitleMsg(), not setPageTitle() (MW 1.45 T343994)'
		);
	}
}
