<?php
/**
 * Tests für die deutschen Zahlenformate.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Format;
use PHPUnit\Framework\TestCase;

/**
 * Zahlen und Dateigrößen im deutschen Format.
 */
class Format_Test extends TestCase {

	/**
	 * Tausenderpunkt und Dezimalkomma.
	 */
	public function test_number() {
		$this->assertSame( '1.284', Format::number( 1284 ) );
		$this->assertSame( '38,4', Format::number( 38.42, 1 ) );
		$this->assertSame( '0', Format::number( 0 ) );
	}

	/**
	 * Bytes und Kilobytes ohne, ab Megabyte mit Nachkommastelle.
	 *
	 * @dataProvider bytes_provider
	 *
	 * @param int|float $bytes    Eingabe.
	 * @param string    $expected Erwartete Ausgabe mit normalem Leerzeichen.
	 */
	public function test_bytes( $bytes, $expected ) {
		$this->assertSame( $expected, str_replace( Format::NBSP, ' ', Format::bytes( $bytes ) ) );
	}

	/**
	 * Testfälle für bytes().
	 *
	 * @return array
	 */
	public function bytes_provider() {
		return array(
			'null'             => array( 0, '0 B' ),
			'negativ'          => array( -5, '0 B' ),
			'bytes'            => array( 512, '512 B' ),
			'kilobytes'        => array( 96 * 1024, '96 KB' ),
			'kilobytes rund'   => array( 390.4 * 1024, '390 KB' ),
			'megabytes'        => array( 38.4 * 1024 * 1024, '38,4 MB' ),
			'gigabytes'        => array( 12.3 * 1024 * 1024 * 1024, '12,3 GB' ),
			'grenze kilobytes' => array( 1023.7 * 1024, '1,0 MB' ),
			'grenze megabytes' => array( 1023.97 * 1024 * 1024, '1,0 GB' ),
		);
	}

	/**
	 * Zwischen Zahl und Einheit steht ein geschütztes Leerzeichen.
	 */
	public function test_bytes_uses_nbsp() {
		$this->assertSame( '4,8' . Format::NBSP . 'MB', Format::bytes( 4.8 * 1024 * 1024 ) );
	}
}
