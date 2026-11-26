<?php
/**
 * Tests für das Bereinigen der Einstellungen.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Standardwerte, Bereiche, unbekannte Schlüssel.
 */
class Settings_Test extends TestCase {

	/**
	 * Leere Eingabe ergibt die Standardwerte.
	 */
	public function test_defaults() {
		$this->assertSame( Settings::DEFAULTS, Settings::sanitize( array() ) );
		$this->assertSame( Settings::DEFAULTS, Settings::sanitize( 'kaputt' ) );
	}

	/**
	 * Zahlen werden in ihren Bereich geholt, Unbekanntes fällt weg.
	 */
	public function test_ranges_and_unknown_keys() {
		$result = Settings::sanitize(
			array(
				'quality_jpg' => '150',
				'quality_png' => '10',
				'min_savings' => '15',
				'batch_size'  => 'abc',
				'fremd'       => 'x',
			)
		);

		$this->assertSame( 100, $result['quality_jpg'] );
		$this->assertSame( 40, $result['quality_png'] );
		$this->assertSame( 15, $result['min_savings'] );
		$this->assertSame( 10, $result['batch_size'] );
		$this->assertArrayNotHasKey( 'fremd', $result );
	}

	/**
	 * Der API-Schlüssel behält nur erlaubte Zeichen.
	 */
	public function test_api_key() {
		$result = Settings::sanitize( array( 'psi_api_key' => ' AIza_Sy-12<script> ' ) );

		$this->assertSame( 'AIza_Sy-12script', $result['psi_api_key'] );
	}

	/**
	 * PNG mit Transparenz: nur „lossless“ (Standard) und „auto“ sind erlaubt.
	 */
	public function test_png_transparent() {
		$this->assertSame( 'lossless', Settings::sanitize( array() )['png_transparent'] );
		$this->assertSame( 'auto', Settings::sanitize( array( 'png_transparent' => 'auto' ) )['png_transparent'] );
		$this->assertSame( 'lossless', Settings::sanitize( array( 'png_transparent' => 'verlustig' ) )['png_transparent'] );
		$this->assertSame( 'lossless', Settings::sanitize( array( 'png_transparent' => array( 'auto' ) ) )['png_transparent'] );
	}
}
