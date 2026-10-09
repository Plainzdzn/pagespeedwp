<?php
/**
 * Tests für die CSV-Aufbereitung des Berichts.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Report;
use PHPUnit\Framework\TestCase;

/**
 * Felder für Excel mit Semikolon: Zahlen, Text, Anführungszeichen, Formeln.
 */
class Report_Test extends TestCase {

	/**
	 * Zahlen bleiben Zahlen.
	 */
	public function test_numbers_unchanged() {
		$this->assertSame( '437870', Report::csv_value( 437870 ) );
		$this->assertSame( '0', Report::csv_value( 0 ) );
	}

	/**
	 * Einfacher Text ohne Sonderzeichen bleibt ohne Anführungszeichen.
	 */
	public function test_plain_text() {
		$this->assertSame( '2019/05/bild.png', Report::csv_value( '2019/05/bild.png' ) );
		$this->assertSame( 'Umgewandelt', Report::csv_value( 'Umgewandelt' ) );
	}

	/**
	 * Semikolon, Anführungszeichen und Zeilenumbruch werden eingeschlossen und verdoppelt.
	 */
	public function test_quoting() {
		$this->assertSame( '"a;b"', Report::csv_value( 'a;b' ) );
		$this->assertSame( '"Sagt ""Hallo"""', Report::csv_value( 'Sagt "Hallo"' ) );
		$this->assertSame( "\"Zeile 1\nZeile 2\"", Report::csv_value( "Zeile 1\nZeile 2" ) );
	}

	/**
	 * Was Excel als Formel lesen würde, bekommt ein Hochkomma davor.
	 *
	 * @dataProvider formula_provider
	 *
	 * @param string $input    Eingabe.
	 * @param string $expected Ergebnis.
	 */
	public function test_formula_injection( $input, $expected ) {
		$this->assertSame( $expected, Report::csv_value( $input ) );
	}

	/**
	 * Formel-Anfänge.
	 *
	 * @return array[]
	 */
	public function formula_provider() {
		return array(
			array( '=HYPERLINK("x")', '"\'=HYPERLINK(""x"")"' ),
			array( '+1', "'+1" ),
			array( '-bild.png', "'-bild.png" ),
			array( '@SUMME(A1)', "'@SUMME(A1)" ),
		);
	}

	/**
	 * Leerer Text bleibt leer.
	 */
	public function test_empty() {
		$this->assertSame( '', Report::csv_value( '' ) );
	}
}
