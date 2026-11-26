<?php
/**
 * Tests für die Erkennung des tatsächlichen Bildtyps anhand der ersten Bytes.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Attachment_Files;
use PHPUnit\Framework\TestCase;

/**
 * Die Endung entscheidet nichts: JPG hinter HEIC-Einträgen erkennen, echte HEIC nie als JPG lesen.
 */
class Real_Kind_Test extends TestCase {

	/**
	 * Angelegte Dateien, werden nach jedem Test gelöscht.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Räumt auf.
	 */
	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test ohne WordPress.
			}
		}
		$this->files = array();
	}

	/**
	 * Legt eine Datei mit Inhalt und beliebiger Endung an.
	 *
	 * @param string $name  Dateiname.
	 * @param string $bytes Inhalt.
	 * @return string Pfad.
	 */
	private function file( $name, $bytes ) {
		$path = sys_get_temp_dir() . '/akwu-' . uniqid() . '-' . $name;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test ohne WordPress.
		$this->files[] = $path;

		return $path;
	}

	/**
	 * Kopf einer ISO-Medien-Datei mit Marke.
	 *
	 * @param string $brand Vier Zeichen.
	 * @return string
	 */
	private static function ftyp( $brand ) {
		return "\x00\x00\x00\x18ftyp" . $brand . "\x00\x00\x00\x00mif1heic" . str_repeat( "\x00", 32 );
	}

	/**
	 * Jeder Typ wird am Inhalt erkannt.
	 */
	public function test_detects_types_by_content() {
		$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
		$png  = "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01";
		$webp = "RIFF\x24\x00\x00\x00WEBPVP8 \x18\x00\x00\x00";

		$this->assertSame( 'jpg', Attachment_Files::real_kind( $this->file( 'a.jpg', $jpeg ) ) );
		$this->assertSame( 'png', Attachment_Files::real_kind( $this->file( 'a.png', $png ) ) );
		$this->assertSame( 'webp', Attachment_Files::real_kind( $this->file( 'a.webp', $webp ) ) );
		$this->assertSame( 'gif', Attachment_Files::real_kind( $this->file( 'a.gif', 'GIF89a' . str_repeat( "\x00", 12 ) ) ) );
		$this->assertSame( 'heic', Attachment_Files::real_kind( $this->file( 'a.heic', self::ftyp( 'heic' ) ) ) );
		$this->assertSame( 'heic', Attachment_Files::real_kind( $this->file( 'b.heic', self::ftyp( 'mif1' ) ) ) );
		$this->assertSame( 'avif', Attachment_Files::real_kind( $this->file( 'a.avif', self::ftyp( 'avif' ) ) ) );
	}

	/**
	 * WordPress-Fall: Datei heißt -scaled.jpg und ist JPG, obwohl der Anhang als HEIC geführt wird.
	 */
	public function test_jpeg_behind_any_name() {
		$jpeg = "\xFF\xD8\xFF\xE1\x00\x10Exif\x00\x00II*\x00\x08\x00\x00\x00";

		$this->assertSame( 'jpg', Attachment_Files::real_kind( $this->file( 'IMG_0053-scaled.jpg', $jpeg ) ) );
		$this->assertSame( 'jpg', Attachment_Files::real_kind( $this->file( 'IMG_0053.heic', $jpeg ) ) );
	}

	/**
	 * Eine echte HEIC, die nur wie ein JPG heißt, ist kein JPG.
	 */
	public function test_real_heic_named_jpg_is_not_jpeg() {
		$this->assertSame( 'heic', Attachment_Files::real_kind( $this->file( 'foto.jpg', self::ftyp( 'heic' ) ) ) );
		$this->assertSame( 'heic', Attachment_Files::real_kind( $this->file( 'foto-scaled.jpg', self::ftyp( 'heix' ) ) ) );
	}

	/**
	 * Unbekannter, zu kurzer oder fehlender Inhalt ergibt null statt einer Vermutung.
	 */
	public function test_unknown_content_is_null() {
		$this->assertNull( Attachment_Files::real_kind( $this->file( 'a.jpg', 'kein Bild, nur Text mit genug Zeichen' ) ) );
		$this->assertNull( Attachment_Files::real_kind( $this->file( 'b.jpg', "\xFF\xD8" ) ) );
		$this->assertNull( Attachment_Files::real_kind( sys_get_temp_dir() . '/akwu-gibt-es-nicht.jpg' ) );
		$this->assertNull( Attachment_Files::real_kind( $this->file( 'c.mp4', "\x00\x00\x00\x18ftypisom" . str_repeat( "\x00", 16 ) ) ) );
	}
}
