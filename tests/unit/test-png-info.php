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

	/**
	 * Erzeugt ein RGBA-Bild mit Imagick, wahlweise mit einem durchsichtigen Pixel.
	 *
	 * @param bool   $one_clear Ein Pixel mit Alpha 0.
	 * @param string $format    PNG32 oder PNG8 (Palette).
	 * @return string PNG-Daten.
	 */
	private static function rgba( $one_clear, $format = 'PNG32' ) {
		$image = new Imagick();
		$image->newImage( 40, 30, new ImagickPixel( 'rgba(28,128,93,1)' ) );
		$image->setImageAlphaChannel( defined( 'Imagick::ALPHACHANNEL_SET' ) ? Imagick::ALPHACHANNEL_SET : Imagick::ALPHACHANNEL_ACTIVATE );
		if ( $one_clear ) {
			$draw = new ImagickDraw();
			$draw->setFillColor( new ImagickPixel( 'transparent' ) );
			$draw->color( 5, 5, Imagick::PAINT_REPLACE );
			$image->drawImage( $draw );
		}
		$image->setImageFormat( $format );

		return $image->getImageBlob();
	}

	/**
	 * Liest PNG-Daten wie eine Datei ein.
	 *
	 * @param string $blob PNG-Daten.
	 * @return Imagick
	 */
	private static function load( $blob ) {
		$image = new Imagick();
		$image->readImageBlob( $blob );

		return $image;
	}

	/**
	 * Alphakanal ohne durchsichtiges Pixel gilt als deckend, schon ein Pixel macht ihn durchsichtig (1.1.3).
	 */
	public function test_imagick_opaque_checks_pixels_not_header() {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick fehlt.' );
		}

		$opaque = self::rgba( false );
		$this->assertTrue( Png_Info::has_alpha_in( $opaque ), 'Kopf meldet Alphakanal' );
		$this->assertTrue( Png_Info::imagick_opaque( self::load( $opaque ) ), 'Pixel sind alle deckend' );

		$clear = self::rgba( true );
		$this->assertTrue( Png_Info::has_alpha_in( $clear ) );
		$this->assertFalse( Png_Info::imagick_opaque( self::load( $clear ) ), 'Ein durchsichtiges Pixel reicht' );
	}

	/**
	 * Bild ohne Alphakanal ist deckend, kein Imagick-Objekt ergibt null.
	 */
	public function test_imagick_opaque_without_alpha_and_invalid_input() {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick fehlt.' );
		}

		$rgb = new Imagick();
		$rgb->newImage( 10, 10, new ImagickPixel( 'white' ) );
		$rgb->setImageFormat( 'PNG24' );

		$this->assertTrue( Png_Info::imagick_opaque( $rgb ) );
		$this->assertNull( Png_Info::imagick_opaque( 'kein Bild' ) );
	}
}
