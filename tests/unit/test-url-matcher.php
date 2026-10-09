<?php
/**
 * Tests für das Erkennen und Ersetzen von Upload-URLs.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Url_Matcher;
use PHPUnit\Framework\TestCase;

/**
 * URL-Varianten, fremde Hosts, Schreibweisen.
 */
class Url_Matcher_Test extends TestCase {

	/**
	 * Matcher für https://example.de.
	 *
	 * @var Url_Matcher
	 */
	private $matcher;

	/**
	 * Vorbereitung.
	 */
	protected function setUp(): void {
		$this->matcher = new Url_Matcher( 'https://example.de/wp-content/uploads' );
	}

	/**
	 * Absolute, protokollrelative und relative URLs werden erkannt.
	 */
	public function test_finds_all_url_variants() {
		$text = implode(
			' ',
			array(
				'<img src="https://example.de/wp-content/uploads/2019/05/bild.png">',
				'<img src="http://example.de/wp-content/uploads/2019/05/bild.png">',
				'<img src="//example.de/wp-content/uploads/2019/05/bild-300x200.png">',
				'url(/wp-content/uploads/2019/05/bild.png)',
				'https://www.example.de/wp-content/uploads/2019/05/foto.JPG',
			)
		);

		$this->assertSame(
			array(
				'2019/05/bild.png'         => 3,
				'2019/05/bild-300x200.png' => 1,
				'2019/05/foto.JPG'         => 1,
			),
			$this->matcher->find( $text )
		);
	}

	/**
	 * JSON-Schreibweise mit \/ wird erkannt und normalisiert.
	 */
	public function test_finds_json_escaped() {
		$json = json_encode( array( 'url' => 'https://example.de/wp-content/uploads/2019/05/bild.png' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Ohne WordPress im Test, gleiche Ausgabe wie wp_json_encode().

		$this->assertStringContainsString( '\\/', $json );
		$this->assertSame( array( '2019/05/bild.png' => 1 ), $this->matcher->find( $json ) );
	}

	/**
	 * Fremde Hosts und Pfade außerhalb des Upload-Ordners zählen nicht.
	 */
	public function test_ignores_foreign_hosts() {
		$text = 'https://other.de/wp-content/uploads/2019/05/bild.png '
			. 'https://example.de/wp-content/themes/x/bild.png '
			. 'https://cdn.example.de/wp-content/uploads/2019/05/bild.png';

		$this->assertSame( array(), $this->matcher->find( $text ) );
	}

	/**
	 * Längere Dateinamen und angehängte Endungen sind keine Treffer für den kürzeren Namen.
	 */
	public function test_respects_file_boundaries() {
		$found = $this->matcher->find( '/wp-content/uploads/2019/05/bild.png.webp /wp-content/uploads/2019/05/bild.pngx' );

		$this->assertArrayNotHasKey( '2019/05/bild.png', $found );
		$this->assertSame( array( '2019/05/bild.png.webp' => 1 ), $found );
	}

	/**
	 * Query-Strings und srcset-Listen trennen sauber.
	 */
	public function test_query_string_and_srcset() {
		$text = 'srcset="https://example.de/wp-content/uploads/2019/05/a-300x200.jpg 300w, '
			. 'https://example.de/wp-content/uploads/2019/05/a.jpg?ver=2 1200w"';

		$this->assertSame(
			array(
				'2019/05/a-300x200.jpg' => 1,
				'2019/05/a.jpg'         => 1,
			),
			$this->matcher->find( $text )
		);
	}

	/**
	 * URL-kodierte Umlaute werden zum gleichen Pfad normalisiert.
	 */
	public function test_url_encoded() {
		$found = $this->matcher->find( 'https://example.de/wp-content/uploads/2019/05/b%C3%BCro.jpg und /wp-content/uploads/2019/05/büro.jpg' );

		$this->assertSame( array( '2019/05/büro.jpg' => 2 ), $found );
	}

	/**
	 * Ersetzen tauscht nur den Pfad, Host und Schreibweise bleiben.
	 */
	public function test_replace_keeps_prefix_and_style() {
		$map   = array(
			'2019/05/bild.png'         => '2019/05/bild.webp',
			'2019/05/bild-300x200.png' => '2019/05/bild-300x200.webp',
			'2019/05/büro.jpg'         => '2019/05/büro.webp',
		);
		$count = 0;
		$text  = '<img src="http://example.de/wp-content/uploads/2019/05/bild.png" srcset="//www.example.de/wp-content/uploads/2019/05/bild-300x200.png 300w">'
			. ' {"url":"https:\/\/example.de\/wp-content\/uploads\/2019\/05\/bild.png"}'
			. ' url(/wp-content/uploads/2019/05/b%C3%BCro.jpg)'
			. ' https://other.de/wp-content/uploads/2019/05/bild.png';

		$result = $this->matcher->replace( $text, $map, $count );

		$this->assertSame(
			'<img src="http://example.de/wp-content/uploads/2019/05/bild.webp" srcset="//www.example.de/wp-content/uploads/2019/05/bild-300x200.webp 300w">'
			. ' {"url":"https:\/\/example.de\/wp-content\/uploads\/2019\/05\/bild.webp"}'
			. ' url(/wp-content/uploads/2019/05/b%C3%BCro.webp)'
			. ' https://other.de/wp-content/uploads/2019/05/bild.png',
			$result
		);
		$this->assertSame( 4, $count );
	}

	/**
	 * Pfade ohne Zuordnung bleiben unverändert.
	 */
	public function test_replace_ignores_unmapped() {
		$text  = 'https://example.de/wp-content/uploads/2019/05/andere.png';
		$count = 0;

		$this->assertSame( $text, $this->matcher->replace( $text, array( '2019/05/bild.png' => '2019/05/bild.webp' ), $count ) );
		$this->assertSame( 0, $count );
	}

	/**
	 * Host mit Port (lokale Testumgebung) ohne www-Variante.
	 */
	public function test_host_with_port() {
		$matcher = new Url_Matcher( 'http://localhost:8080/wp-content/uploads' );

		$this->assertSame(
			array( '2019/05/a.png' => 1 ),
			$matcher->find( 'http://localhost:8080/wp-content/uploads/2019/05/a.png http://localhost/wp-content/uploads/2019/05/a.png' )
		);
		$this->assertSame( 'uploads', $matcher->like_needle() );
	}
}
