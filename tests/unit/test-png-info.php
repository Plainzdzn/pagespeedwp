<?php
/**
 * Tests für die PNG-Transparenz-Erkennung.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Png_Info;
use PHPUnit\Framework\TestCase;

/**
 * Farbtypen und tRNS-Block.
 */
class Png_Info_Test extends TestCase {

	/**
	 * Baut einen PNG-Block.
	 *
	 * @param string $type Blocktyp.
	 * @param string $data Inhalt.
	 * @return string
	 */
	private static function chunk( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * Minimaler PNG-Kopf mit Farbtyp und optionalen Blöcken vor IDAT.
	 *
	 * @param int    $color_type Farbtyp laut PNG-Spezifikation.
	 * @param string $extra      Weitere Blöcke vor IDAT.
	 * @return string
	 */
	private static function png( $color_type, $extra = '' ) {
		$ihdr = pack( 'NNCCCCC', 10, 10, 8, $color_type, 0, 0, 0 );

		return Png_Info::SIGNATURE . self::chunk( 'IHDR', $ihdr ) . $extra . self::chunk( 'IDAT', 'x' ) . self::chunk( 'IEND', '' );
	}

	/**
	 * RGBA und Graustufen mit Alpha haben Transparenz.
	 */
	public function test_alpha_color_types() {
		$this->assertTrue( Png_Info::has_alpha_in( self::png( 6 ) ) );
		$this->assertTrue( Png_Info::has_alpha_in( self::png( 4 ) ) );
	}

	/**
	 * RGB, Graustufen und Palette ohne tRNS haben keine.
	 */
	public function test_opaque_color_types() {
		$this->assertFalse( Png_Info::has_alpha_in( self::png( 2 ) ) );
		$this->assertFalse( Png_Info::has_alpha_in( self::png( 0 ) ) );
		$this->assertFalse( Png_Info::has_alpha_in( self::png( 3, self::chunk( 'PLTE', str_repeat( "\0", 3 ) ) ) ) );
	}

	/**
	 * Palette mit tRNS-Block hat Transparenz.
	 */
	public function test_palette_with_trns() {
		$extra = self::chunk( 'PLTE', str_repeat( "\0", 3 ) ) . self::chunk( 'tRNS', "\0" );

		$this->assertTrue( Png_Info::has_alpha_in( self::png( 3, $extra ) ) );
	}

	/**
	 * Kein PNG ergibt null.
	 */
	public function test_not_a_png() {
		$this->assertNull( Png_Info::has_alpha_in( "\xFF\xD8\xFF\xE0 JFIF" ) );
		$this->assertNull( Png_Info::has_alpha_in( '' ) );
	}
}
