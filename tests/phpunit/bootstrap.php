<?php

/**
 * Minimal bootstrap for pure unit tests (no MediaWiki core required).
 */

// Stub MW Config interface so CaptchaGate typehints resolve outside a full MW tree.
//
// Defined under MediaWiki\Config and aliased to the global name, mirroring what
// MediaWiki itself does: the interface lives in the namespace from 1.42 and
// carries a class_alias so the pre-namespace global `Config` keeps working. The
// extension type-hints the global name for 1.39 compatibility, and the alias
// makes both spellings the same type, so a stub implementing either satisfies it.
namespace MediaWiki\Config {
	if ( !interface_exists( Config::class ) ) {
		interface Config {
			/**
			 * @param string $name
			 * @return mixed
			 */
			public function get( $name );

			/**
			 * @param string $name
			 * @return bool
			 */
			public function has( $name );
		}
	}

	if ( !interface_exists( \Config::class, false ) ) {
		class_alias( Config::class, 'Config' );
	}
}

namespace Wikimedia\Rdbms {
	if ( !interface_exists( ILoadBalancer::class ) ) {
		interface ILoadBalancer {
		}
	}
	if ( !interface_exists( IDatabase::class ) ) {
		interface IDatabase {
		}
	}
}

namespace {
	$root = dirname( __DIR__, 2 );
	spl_autoload_register( static function ( $class ) use ( $root ) {
		$prefix = 'MediaWiki\\Extension\\SaintapediaFeedback\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file = $root . '/includes/' . $relative . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	} );
}
