<?php
/**
 * Tests für den Vergleich zweier Mediathek-Einträge mit gemeinsamer Datei.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Aliases;
use PHPUnit\Framework\TestCase;

/**
 * Gemeinsam umgestellt wird nur, wenn Dateien, Größennamen und Pixelmaße übereinstimmen.
 */
class Aliases_Test extends TestCase {

	/**
	 * Gleiche Zuordnung in anderer Reihenfolge passt.
	 */
	public function test_same_mapping_in_any_order() {
		$a = array(
			'full'      => '2026/02/foto.jpg',
			'medium'    => '2026/02/foto-300x200.jpg',
			'thumbnail' => '2026/02/foto-150x150.jpg',
		);
		$b = array(
			'thumbnail' => '2026/02/foto-150x150.jpg',
			'full'      => '2026/02/foto.jpg',
			'medium'    => '2026/02/foto-300x200.jpg',
		);

		$this->assertTrue( Aliases::same_files( $a, $b ) );
	}

	/**
	 * Dieselbe Datei unter anderem Größennamen passt nicht: Der zweite Eintrag verlöre sonst seine Größe.
	 */
	public function test_same_paths_under_other_size_names_do_not_match() {
		$a = array(
			'full'   => '2026/02/foto.jpg',
			'medium' => '2026/02/foto-300x200.jpg',
		);
		$b = array(
			'full'   => '2026/02/foto.jpg',
			'poster' => '2026/02/foto-300x200.jpg',
		);

		$this->assertFalse( Aliases::same_files( $a, $b ) );
	}

	/**
	 * Fehlt einem Eintrag eine Größe oder das Original, passt es nicht.
	 */
	public function test_missing_size_or_original_does_not_match() {
		$a = array(
			'full'           => '2026/02/foto-scaled.jpg',
			'original_image' => '2026/02/foto.heic',
			'medium'         => '2026/02/foto-300x200.jpg',
		);

		$this->assertFalse( Aliases::same_files( $a, array_diff_key( $a, array( 'medium' => 1 ) ) ) );
		$this->assertFalse( Aliases::same_files( $a, array_diff_key( $a, array( 'original_image' => 1 ) ) ) );
	}

	/**
	 * Gleiche Namen, aber andere Pixelmaße in den Metadaten passen nicht.
	 */
	public function test_dimensions_must_match() {
		$meta = array(
			'width'  => 1920,
			'height' => 2560,
			'sizes'  => array(
				'medium' => array(
					'file'   => 'foto-225x300.jpg',
					'width'  => 225,
					'height' => 300,
				),
			),
		);

		$other                              = $meta;
		$other['sizes']['medium']['height'] = 301;
		$wide                               = $meta;
		$wide['width']                      = 2560;

		$this->assertTrue( Aliases::same_sizes( $meta, $meta ) );
		$this->assertFalse( Aliases::same_sizes( $meta, $other ) );
		$this->assertFalse( Aliases::same_sizes( $meta, $wide ) );
	}
}
