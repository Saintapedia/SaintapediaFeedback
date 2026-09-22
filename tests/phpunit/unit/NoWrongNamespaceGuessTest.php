<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A real-world incident (not from this repo's git history -- from a
 * manually hand-patched deployment) imported `MediaWiki\Permissions\
 * PermissionsError`, which does not exist and fataled every request to
 * Special:SaintapediaFeedback. The real class, as of the MediaWiki version
 * that moved it out of the global namespace, is `MediaWiki\Exception\
 * PermissionsError` -- easy to guess wrong because the *unrelated*
 * PermissionStatus class genuinely does live under `MediaWiki\Permissions`.
 *
 * This extension's declared floor is MediaWiki >= 1.39, where PermissionsError
 * and ErrorPageError only ever existed as bare global classes (the modern
 * `MediaWiki\Exception\...` namespace and a compatibility class_alias for the
 * old global name both arrived later) -- so, unlike Title (safely
 * `MediaWiki\Title\Title` since 1.39, see Mw144TitleCompatibilityTest), the
 * *correct* fix for this whole declared range is to keep using the bare
 * global `PermissionsError` / `ErrorPageError`, not to adopt either
 * namespaced form. This test guards specifically against the wrong-namespace
 * guess reaching a real commit; it is not a general ban on the correct
 * `MediaWiki\Exception\...` form, which would be the right choice if this
 * extension ever raises its floor past the version that removed the global
 * alias.
 */
class NoWrongNamespaceGuessTest extends TestCase {

	public function testPhpSourcesDoNotImportPermissionsErrorFromTheWrongNamespace(): void {
		$offenders = $this->scanFor( '/^use\s+MediaWiki\\\\Permissions\\\\PermissionsError\s*;/m' );
		$this->assertSame(
			[],
			$offenders,
			'MediaWiki\\Permissions\\PermissionsError does not exist -- the real class ' .
				'(if this extension\'s MW floor is ever raised past the point where the ' .
				'global alias was removed) is MediaWiki\\Exception\\PermissionsError. ' .
				'For now, on this extension\'s declared >= 1.39 floor, keep the bare ' .
				'global `use PermissionsError;`.'
		);
	}

	public function testPhpSourcesDoNotImportErrorPageErrorFromTheWrongNamespace(): void {
		$offenders = $this->scanFor( '/^use\s+MediaWiki\\\\Permissions\\\\ErrorPageError\s*;/m' );
		$this->assertSame(
			[],
			$offenders,
			'MediaWiki\\Permissions\\ErrorPageError does not exist -- the real class ' .
				'(if this extension\'s MW floor is ever raised) is ' .
				'MediaWiki\\Exception\\ErrorPageError. For now, keep the bare global ' .
				'`use ErrorPageError;`.'
		);
	}

	/** @return string[] */
	private function scanFor( string $pattern ): array {
		$root = dirname( __DIR__, 3 ) . '/includes';
		$offenders = [];
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $files as $file ) {
			if ( !$file->isFile() || $file->getExtension() !== 'php' ) {
				continue;
			}
			$src = file_get_contents( $file->getPathname() );
			if ( preg_match( $pattern, $src ) ) {
				$offenders[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
		return $offenders;
	}
}
