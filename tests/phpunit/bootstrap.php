<?php

/**
 * Minimal bootstrap for pure unit tests (no MediaWiki core required).
 */

// Stub MW Config interface so CaptchaGate typehints resolve outside a full MW tree.
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
}

namespace Wikimedia\Rdbms {
	if ( !interface_exists( ILoadBalancer::class ) ) {
		interface ILoadBalancer {
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
