<?php
/**
 * Tests für die Erkennung absoluter Pfade im Upload-Ordner, auch auf Windows.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Attachment_Files;
use PHPUnit\Framework\TestCase;

/**
 * Ein normalisierter Windows-Pfad (C:/…) ist absolut und darf nicht noch einmal an den Upload-Ordner gehängt werden.
 */
class Upload_Paths_Test extends TestCase {

	const WINDOWS = 'C:/xampp/htdocs/wp/wp-content/uploads/';
	const UNIX    = '/var/www/html/wp-content/uploads/';

	/**
	 * Absolut sind Unix-Pfade, Laufwerkspfade und Netzwerkpfade, relativ ist der Wert aus der Datenbank.
	 */
	public function test_recognizes_absolute_paths_on_both_platforms() {
		$this->assertTrue( Attachment_Files::looks_absolute( '/var/www/html/wp-content/uploads/2026/11/foto.webp' ) );
		$this->assertTrue( Attachment_Files::looks_absolute( 'C:/xampp/htdocs/wp/wp-content/uploads/2026/11/foto.webp' ) );
		$this->assertTrue( Attachment_Files::looks_absolute( 'd:/daten/foto.webp' ) );
		$this->assertTrue( Attachment_Files::looks_absolute( '//server/freigabe/uploads/foto.webp' ) );
		$this->assertFalse( Attachment_Files::looks_absolute( '2026/11/foto.webp' ) );
		$this->assertFalse( Attachment_Files::looks_absolute( 'foto.webp' ) );
		$this->assertFalse( Attachment_Files::looks_absolute( '' ) );
	}

	/**
	 * Windows: Pfad im Upload-Ordner wird relativ, auch bei anderer Schreibweise des Laufwerks.
	 */
	public function test_windows_path_inside_uploads_becomes_relative() {
		$this->assertSame( '2026/11/foto-scaled-1.webp', Attachment_Files::strip_base( 'C:/xampp/htdocs/wp/wp-content/uploads/2026/11/foto-scaled-1.webp', self::WINDOWS ) );
		$this->assertSame( '2026/11/foto.heic', Attachment_Files::strip_base( 'c:/XAMPP/htdocs/wp/wp-content/uploads/2026/11/foto.heic', self::WINDOWS ) );
	}

	/**
	 * Anderes Laufwerk, anderer Ordner oder ein Ordner mit ähnlichem Namen liegen außerhalb.
	 */
	public function test_paths_outside_uploads_are_excluded() {
		$this->assertNull( Attachment_Files::strip_base( 'D:/xampp/htdocs/wp/wp-content/uploads/2026/11/foto.webp', self::WINDOWS ) );
		$this->assertNull( Attachment_Files::strip_base( 'C:/xampp/htdocs/wp/wp-content/uploads-alt/foto.webp', self::WINDOWS ) );
		$this->assertNull( Attachment_Files::strip_base( 'C:/Windows/System32/foto.webp', self::WINDOWS ) );
		$this->assertNull( Attachment_Files::strip_base( '//server/freigabe/uploads/foto.webp', self::WINDOWS ) );
		$this->assertNull( Attachment_Files::strip_base( '/var/www/html/wp-content/uploads', self::UNIX ) );
		$this->assertNull( Attachment_Files::strip_base( '/var/www/andere/wp-content/uploads/foto.webp', self::UNIX ) );
	}

	/**
	 * Unix bleibt wie bisher, Groß- und Kleinschreibung zählt dort.
	 */
	public function test_unix_paths_stay_case_sensitive() {
		$this->assertSame( '2026/11/foto.webp', Attachment_Files::strip_base( '/var/www/html/wp-content/uploads/2026/11/foto.webp', self::UNIX ) );
		$this->assertNull( Attachment_Files::strip_base( '/var/www/html/WP-CONTENT/uploads/2026/11/foto.webp', self::UNIX ) );
	}
}
