<?php
/**
 * Tests für den Autoloader.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Autoloader;
use PHPUnit\Framework\TestCase;

/**
 * Klassennamen werden auf WPCS-Dateinamen abgebildet.
 */
class Autoloader_Test extends TestCase {

	/**
	 * Einfacher Klassenname.
	 */
	public function test_simple_class() {
		$this->assertSame( 'class-scanner.php', Autoloader::file_for( 'Akuma\\WebpUmwandler\\Scanner' ) );
	}

	/**
	 * Unterstriche werden zu Bindestrichen, alles klein.
	 */
	public function test_underscore_class() {
		$this->assertSame( 'class-cache-purger.php', Autoloader::file_for( 'Akuma\\WebpUmwandler\\Cache_Purger' ) );
	}

	/**
	 * Unter-Namespaces werden zu Unterordnern.
	 */
	public function test_sub_namespace() {
		$this->assertSame( 'cli/class-command.php', Autoloader::file_for( 'Akuma\\WebpUmwandler\\Cli\\Command' ) );
	}

	/**
	 * Fremde Klassen werden ignoriert.
	 */
	public function test_foreign_class() {
		$this->assertNull( Autoloader::file_for( 'WP_Image_Editor' ) );
		$this->assertNull( Autoloader::file_for( 'Akuma\\Other\\Scanner' ) );
	}

	/**
	 * Alle Klassen des Plugins sind über den Autoloader ladbar.
	 */
	public function test_plugin_classes_load() {
		foreach ( array( 'Admin', 'Conflict_Detector', 'Format', 'Icons', 'Plugin', 'System_Check', 'View' ) as $name ) {
			$this->assertTrue( class_exists( 'Akuma\\WebpUmwandler\\' . $name ), $name );
		}
	}
}
