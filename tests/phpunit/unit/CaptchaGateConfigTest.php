<?php

namespace MediaWiki\Extension\SaintapediaFeedback\Tests\Unit;

use MediaWiki\Config\Config;
use MediaWiki\Extension\SaintapediaFeedback\CaptchaGate;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight Config stub for unit tests.
 */
class ArrayConfig implements Config {
	/** @var array */
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	/** @inheritDoc */
	public function get( $name ) {
		if ( !array_key_exists( $name, $this->data ) ) {
			throw new \InvalidArgumentException( "Missing config $name" );
		}
		return $this->data[$name];
	}

	/** @inheritDoc */
	public function has( $name ): bool {
		return array_key_exists( $name, $this->data );
	}
}

/**
 * @covers \MediaWiki\Extension\SaintapediaFeedback\CaptchaGate::isCaptchaEnabled
 */
class CaptchaGateConfigTest extends TestCase {

	public function testPublicModeAutoEnablesCaptcha(): void {
		$config = new ArrayConfig( [
			'SaintapediaFeedbackMode' => 'public',
			'SaintapediaFeedbackRequireCaptcha' => null,
		] );
		$this->assertTrue( CaptchaGate::isCaptchaEnabled( $config ) );
	}

	public function testEnterpriseModeAutoDisablesCaptcha(): void {
		$config = new ArrayConfig( [
			'SaintapediaFeedbackMode' => 'enterprise',
			'SaintapediaFeedbackRequireCaptcha' => null,
		] );
		$this->assertFalse( CaptchaGate::isCaptchaEnabled( $config ) );
	}

	public function testExplicitOverride(): void {
		$publicOff = new ArrayConfig( [
			'SaintapediaFeedbackMode' => 'public',
			'SaintapediaFeedbackRequireCaptcha' => false,
		] );
		$this->assertFalse( CaptchaGate::isCaptchaEnabled( $publicOff ) );

		$enterpriseOn = new ArrayConfig( [
			'SaintapediaFeedbackMode' => 'enterprise',
			'SaintapediaFeedbackRequireCaptcha' => true,
		] );
		$this->assertTrue( CaptchaGate::isCaptchaEnabled( $enterpriseOn ) );
	}
}
