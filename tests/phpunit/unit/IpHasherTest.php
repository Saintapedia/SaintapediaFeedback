<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use MediaWiki\Extension\SaintapediaFeedback\IpHasher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaFeedback\IpHasher
 */
class IpHasherTest extends TestCase {

	public function testSameInputsProduceTheSameHash(): void {
		$a = IpHasher::hash( '203.0.113.5', 'secret', '2026-09-10' );
		$b = IpHasher::hash( '203.0.113.5', 'secret', '2026-09-10' );
		$this->assertSame( $a, $b );
	}

	public function testDifferentAddressesProduceDifferentHashes(): void {
		$a = IpHasher::hash( '203.0.113.5', 'secret', '2026-09-10' );
		$b = IpHasher::hash( '203.0.113.6', 'secret', '2026-09-10' );
		$this->assertNotSame( $a, $b );
	}

	/**
	 * F-07: the same address must not hash to the same value on two
	 * different days, so it cannot be linked across days by comparing
	 * stored hashes.
	 */
	public function testSameAddressOnDifferentDaysProducesDifferentHashes(): void {
		$day1 = IpHasher::hash( '203.0.113.5', 'secret', '2026-09-10' );
		$day2 = IpHasher::hash( '203.0.113.5', 'secret', '2026-09-11' );
		$this->assertNotSame( $day1, $day2 );
	}

	/**
	 * F-07: a dedicated secret must be independently rotatable from
	 * $wgSecretKey — changing only the secret must change the hash.
	 */
	public function testDifferentSecretsProduceDifferentHashes(): void {
		$a = IpHasher::hash( '203.0.113.5', 'secret-one', '2026-09-10' );
		$b = IpHasher::hash( '203.0.113.5', 'secret-two', '2026-09-10' );
		$this->assertNotSame( $a, $b );
	}

	public function testHashIsA64CharacterHexString(): void {
		$hash = IpHasher::hash( '203.0.113.5', 'secret', '2026-09-10' );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $hash ) );
	}

	public function testDefaultDateBucketUsesToday(): void {
		$withExplicitToday = IpHasher::hash( '203.0.113.5', 'secret', gmdate( 'Y-m-d' ) );
		$withDefault = IpHasher::hash( '203.0.113.5', 'secret' );
		$this->assertSame( $withExplicitToday, $withDefault );
	}
}
