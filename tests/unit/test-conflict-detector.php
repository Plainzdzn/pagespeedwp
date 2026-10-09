<?php
/**
 * Tests für die Erkennung anderer Bildoptimierer.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Conflict_Detector;
use PHPUnit\Framework\TestCase;

/**
 * Erkennung über Plugin-Basenames und FastPixel-Stufen.
 */
class Conflict_Detector_Test extends TestCase {

	/**
	 * Ohne bekannte Plugins kein Treffer.
	 */
	public function test_no_conflicts() {
		$this->assertSame( array(), Conflict_Detector::detect( array( 'elementor/elementor.php', 'wp-rocket/wp-rocket.php' ) ) );
	}

	/**
	 * Treffer in der Reihenfolge der bekannten Liste.
	 */
	public function test_detects_known_plugins() {
		$found = Conflict_Detector::detect(
			array(
				'wp-smushit/wp-smush.php',
				'elementor/elementor.php',
				'fastpixel-website-accelerator/fastpixel.php',
			)
		);

		$this->assertSame(
			array(
				'fastpixel-website-accelerator/fastpixel.php' => 'FastPixel',
				'wp-smushit/wp-smush.php' => 'Smush',
			),
			$found
		);
	}

	/**
	 * Nur exakte Basenames zählen.
	 */
	public function test_requires_exact_basename() {
		$this->assertSame( array(), Conflict_Detector::detect( array( 'imagify-pro/imagify.php', 'imagify/imagify-old.php' ) ) );
	}

	/**
	 * FastPixel-Stufen wie in FastPixel 2.0, Unbekanntes gilt als Lossy.
	 */
	public function test_fastpixel_level() {
		$this->assertSame( 1, Conflict_Detector::fastpixel_level( 1 ) );
		$this->assertSame( 2, Conflict_Detector::fastpixel_level( '2' ) );
		$this->assertSame( 3, Conflict_Detector::fastpixel_level( 3 ) );
		$this->assertSame( 1, Conflict_Detector::fastpixel_level( 0 ) );
		$this->assertSame( 1, Conflict_Detector::fastpixel_level( false ) );
		$this->assertSame( 1, Conflict_Detector::fastpixel_level( array( 3 ) ) );
		$this->assertSame( 1, Conflict_Detector::fastpixel_level( 7 ) );
	}
}
