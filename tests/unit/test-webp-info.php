<?php
/**
 * Tests für das Lesen von WebP-Köpfen.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Webp_Info;
use PHPUnit\Framework\TestCase;

/**
 * Container, Bitströme, Alphakanal, abgeschnittene und fremde Dateien.
 */
class Webp_Info_Test extends TestCase {

	/**
	 * Angelegte Testdateien.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Testdateien entfernen.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Testdatei, ohne WordPress.
			}
		}
		$this->files = array();
	}

	/**
	 * Schreibt Bytes in eine Testdatei.
	 *
	 * @param string $data Inhalt.
	 * @return string Pfad.
	 */
	private function file( $data ) {
		$path          = tempnam( sys_get_temp_dir(), 'akwu-webp-' );
		$this->files[] = $path;
		file_put_contents( $path, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei, ohne WordPress.

		return $path;
	}

	/**
	 * RIFF-Chunk mit Füllbyte bei ungerader Länge.
	 *
	 * @param string $type FourCC.
	 * @param string $data Inhalt.
	 * @return string
	 */
	private static function chunk( $type, $data ) {
		return $type . pack( 'V', strlen( $data ) ) . $data . ( strlen( $data ) % 2 ? "\0" : '' );
	}

	/**
	 * Vollständiger Container.
	 *
	 * @param string $chunks Chunks.
	 * @return string
	 */
	private static function riff( $chunks ) {
		return 'RIFF' . pack( 'V', 4 + strlen( $chunks ) ) . 'WEBP' . $chunks;
	}

	/**
	 * VP8-Bitstrom (Schlüsselbild) mit Maßen.
	 *
	 * @param int $width  Breite.
	 * @param int $height Höhe.
	 * @return string
	 */
	private static function vp8( $width, $height ) {
		return self::chunk( 'VP8 ', "\x10\x02\x00\x9d\x01\x2a" . pack( 'vv', $width, $height ) . str_repeat( "\x55", 11 ) );
	}

	/**
	 * VP8L-Bitstrom mit Maßen und Alpha-Bit.
	 *
	 * @param int  $width  Breite.
	 * @param int  $height Höhe.
	 * @param bool $alpha  Alpha-Bit.
	 * @return string
	 */
	private static function vp8l( $width, $height, $alpha ) {
		$bits = ( $width - 1 ) | ( ( $height - 1 ) << 14 ) | ( ( $alpha ? 1 : 0 ) << 28 );

		return self::chunk( 'VP8L', "\x2f" . pack( 'V', $bits ) . str_repeat( "\x00", 6 ) );
	}

	/**
	 * VP8X-Kopf.
	 *
	 * @param int $flags  Flags.
	 * @param int $width  Leinwandbreite.
	 * @param int $height Leinwandhöhe.
	 * @return string
	 */
	private static function vp8x( $flags, $width, $height ) {
		$w = $width - 1;
		$h = $height - 1;

		return self::chunk( 'VP8X', chr( $flags ) . "\0\0\0" . chr( $w & 0xff ) . chr( ( $w >> 8 ) & 0xff ) . chr( ( $w >> 16 ) & 0xff ) . chr( $h & 0xff ) . chr( ( $h >> 8 ) & 0xff ) . chr( ( $h >> 16 ) & 0xff ) );
	}

	/**
	 * Einfaches verlustbehaftetes WebP.
	 */
	public function test_simple_lossy() {
		$info = Webp_Info::read( $this->file( self::riff( self::vp8( 640, 420 ) ) ) );

		$this->assertSame(
			array(
				'width'       => 640,
				'height'      => 420,
				'compression' => 'lossy',
				'alpha'       => false,
				'animated'    => false,
			),
			$info
		);
	}

	/**
	 * Verlustbehaftet mit Alphakanal: VP8X mit Alpha-Flag und ALPH-Chunk.
	 */
	public function test_lossy_with_alpha() {
		$info = Webp_Info::read( $this->file( self::riff( self::vp8x( 0x10, 640, 420 ) . self::chunk( 'ALPH', "\x00abc" ) . self::vp8( 640, 420 ) ) ) );

		$this->assertSame( 'lossy', $info['compression'] );
		$this->assertTrue( $info['alpha'] );
		$this->assertSame( 640, $info['width'] );
	}

	/**
	 * Alpha-Flag ohne ALPH-Chunk ist kein Alphakanal.
	 */
	public function test_alpha_flag_without_alph_chunk() {
		$info = Webp_Info::read( $this->file( self::riff( self::vp8x( 0x10, 640, 420 ) . self::vp8( 640, 420 ) ) ) );

		$this->assertFalse( $info['alpha'] );
	}

	/**
	 * Verlustfrei mit und ohne Alpha-Bit.
	 */
	public function test_lossless() {
		$with    = Webp_Info::read( $this->file( self::riff( self::vp8l( 300, 200, true ) ) ) );
		$without = Webp_Info::read( $this->file( self::riff( self::vp8l( 300, 200, false ) ) ) );

		$this->assertSame( 'lossless', $with['compression'] );
		$this->assertTrue( $with['alpha'] );
		$this->assertSame( 300, $with['width'] );
		$this->assertSame( 200, $with['height'] );
		$this->assertFalse( $without['alpha'] );
	}

	/**
	 * Leinwand und Bild verschieden groß: kein gültiges Standbild.
	 */
	public function test_canvas_mismatch() {
		$this->assertNull( Webp_Info::read( $this->file( self::riff( self::vp8x( 0x10, 640, 420 ) . self::chunk( 'ALPH', "\x00abc" ) . self::vp8( 320, 210 ) ) ) ) );
	}

	/**
	 * Abgeschnittene Datei: RIFF-Länge passt nicht zur Dateigröße.
	 */
	public function test_truncated() {
		$data = self::riff( self::vp8x( 0x10, 640, 420 ) . self::chunk( 'ALPH', str_repeat( 'a', 40 ) ) . self::vp8( 640, 420 ) );

		$this->assertNull( Webp_Info::read( $this->file( substr( $data, 0, 40 ) ) ) );
	}

	/**
	 * JPEG, PNG, leere und fehlende Dateien sind kein WebP.
	 */
	public function test_not_webp() {
		$this->assertNull( Webp_Info::read( $this->file( "\xff\xd8\xff\xe0" . str_repeat( "\0", 40 ) ) ) );
		$this->assertNull( Webp_Info::read( $this->file( "\x89PNG\r\n\x1a\n" . str_repeat( "\0", 40 ) ) ) );
		$this->assertNull( Webp_Info::read( $this->file( '' ) ) );
		$this->assertNull( Webp_Info::read( sys_get_temp_dir() . '/akwu-gibt-es-nicht.webp' ) );
		$this->assertNull( Webp_Info::read( '' ) );
	}

	/**
	 * Container ohne Bild.
	 */
	public function test_no_bitstream() {
		$this->assertNull( Webp_Info::read( $this->file( self::riff( self::vp8x( 0, 10, 10 ) ) ) ) );
	}

	/**
	 * Animation wird erkannt.
	 */
	public function test_animated() {
		$info = Webp_Info::read( $this->file( self::riff( self::vp8x( 0x12, 64, 64 ) . self::chunk( 'ANIM', str_repeat( "\0", 6 ) ) . self::chunk( 'ANMF', str_repeat( "\0", 16 ) ) ) ) );

		$this->assertTrue( $info['animated'] );
		$this->assertSame( 64, $info['width'] );
	}

	/**
	 * Echte Dateien aus Imagick, falls vorhanden.
	 */
	public function test_real_imagick_files() {
		if ( ! extension_loaded( 'imagick' ) || ! in_array( 'WEBP', \Imagick::queryFormats( 'WEBP' ), true ) ) {
			$this->markTestSkipped( 'Imagick mit WebP fehlt.' );
		}

		$image = new \Imagick();
		$image->newImage( 120, 80, new \ImagickPixel( 'rgba(28,128,93,0.5)' ) );
		$image->setImageFormat( 'WEBP' );

		$image->setOption( 'webp:lossless', 'true' );
		$lossless = Webp_Info::read( $this->file( $image->getImageBlob() ) );

		$image->setOption( 'webp:lossless', 'false' );
		$image->setOption( 'webp:alpha-quality', '100' );
		$lossy = Webp_Info::read( $this->file( $image->getImageBlob() ) );

		$this->assertSame( 'lossless', $lossless['compression'] );
		$this->assertTrue( $lossless['alpha'] );
		$this->assertSame( 'lossy', $lossy['compression'] );
		$this->assertTrue( $lossy['alpha'] );
		$this->assertSame( 120, $lossy['width'] );
		$this->assertSame( 80, $lossy['height'] );
	}
}
