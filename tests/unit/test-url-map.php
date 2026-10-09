<?php
/**
 * Tests für die Zuordnung alte → neue Dateien.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Url_Map;
use PHPUnit\Framework\TestCase;

/**
 * Original, -scaled, Größen über den Namen, fehlende Größen, Umkehrung.
 */
class Url_Map_Test extends TestCase {

	/**
	 * Normales Bild: volle Auflösung und jede Größe über den Namen.
	 */
	public function test_simple_image() {
		$result = Url_Map::build(
			array(
				'full'      => '2019/05/bild.png',
				'thumbnail' => '2019/05/bild-150x150.png',
				'medium'    => '2019/05/bild-300x200.png',
			),
			array(
				'full'      => '2019/05/bild.webp',
				'thumbnail' => '2019/05/bild-150x150.webp',
				'medium'    => '2019/05/bild-300x200.webp',
			)
		);

		$this->assertSame(
			array(
				'2019/05/bild.png'         => '2019/05/bild.webp',
				'2019/05/bild-150x150.png' => '2019/05/bild-150x150.webp',
				'2019/05/bild-300x200.png' => '2019/05/bild-300x200.webp',
			),
			$result['map']
		);
		$this->assertSame( array(), $result['missing'] );
	}

	/**
	 * Großes Bild: Original → Original, -scaled → -scaled.
	 */
	public function test_scaled_image() {
		$result = Url_Map::build(
			array(
				'full'           => '2019/05/panorama-scaled.jpg',
				'original_image' => '2019/05/panorama.jpg',
				'large'          => '2019/05/panorama-1024x576.jpg',
			),
			array(
				'full'           => '2019/05/panorama-scaled.webp',
				'original_image' => '2019/05/panorama.webp',
				'large'          => '2019/05/panorama-1024x576.webp',
			)
		);

		$this->assertSame( '2019/05/panorama.webp', $result['map']['2019/05/panorama.jpg'] );
		$this->assertSame( '2019/05/panorama-scaled.webp', $result['map']['2019/05/panorama-scaled.jpg'] );
		$this->assertSame( '2019/05/panorama-1024x576.webp', $result['map']['2019/05/panorama-1024x576.jpg'] );
	}

	/**
	 * Namenskollision: neuer Name mit -1, Zuordnung trotzdem über die Größennamen.
	 */
	public function test_renamed_target() {
		$result = Url_Map::build(
			array(
				'full'      => '2019/05/bild.jpg',
				'thumbnail' => '2019/05/bild-150x150.jpg',
			),
			array(
				'full'      => '2019/05/bild-1.webp',
				'thumbnail' => '2019/05/bild-1-150x150.webp',
			)
		);

		$this->assertSame( '2019/05/bild-1.webp', $result['map']['2019/05/bild.jpg'] );
		$this->assertSame( '2019/05/bild-1-150x150.webp', $result['map']['2019/05/bild-150x150.jpg'] );
	}

	/**
	 * Größe fehlt danach: zeigt auf die neue Datei und wird gemeldet.
	 */
	public function test_missing_size() {
		$result = Url_Map::build(
			array(
				'full'       => '2019/05/a.png',
				'alte_größe' => '2019/05/a-640x480.png',
			),
			array( 'full' => '2019/05/a.webp' )
		);

		$this->assertSame( '2019/05/a.webp', $result['map']['2019/05/a-640x480.png'] );
		$this->assertSame( array( 'alte_größe' ), $result['missing'] );
	}

	/**
	 * Vorher verkleinert, danach nicht (oder umgekehrt): volle Auflösung bleibt volle Auflösung.
	 */
	public function test_threshold_changed() {
		$result = Url_Map::build(
			array(
				'full'           => '2019/05/p-scaled.jpg',
				'original_image' => '2019/05/p.jpg',
			),
			array( 'full' => '2019/05/p.webp' )
		);

		$this->assertSame( '2019/05/p.webp', $result['map']['2019/05/p.jpg'] );
		$this->assertSame( '2019/05/p.webp', $result['map']['2019/05/p-scaled.jpg'] );
	}

	/**
	 * Umkehrung: mehrere alte Pfade auf einen neuen gehen auf die volle Auflösung zurück.
	 */
	public function test_reverse() {
		$map = array(
			'2019/05/p.jpg'         => '2019/05/p.webp',
			'2019/05/p-scaled.jpg'  => '2019/05/p.webp',
			'2019/05/p-150x150.jpg' => '2019/05/p-150x150.webp',
		);

		$this->assertSame(
			array(
				'2019/05/p.webp'         => '2019/05/p.jpg',
				'2019/05/p-150x150.webp' => '2019/05/p-150x150.jpg',
			),
			Url_Map::reverse( $map, '2019/05/p.jpg' )
		);
	}
}
