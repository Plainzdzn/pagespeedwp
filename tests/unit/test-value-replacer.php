<?php
/**
 * Tests für serialisierungs- und JSON-sicheres Ersetzen.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Url_Matcher;
use Akuma\WebpUmwandler\Value_Replacer;
use PHPUnit\Framework\TestCase;

/**
 * Serialisierte Daten, verschachtelt, Objekte, JSON wie in _elementor_data, geschützte Schlüssel.
 */
class Value_Replacer_Test extends TestCase {

	const OLD = 'https://example.de/wp-content/uploads/2019/05/bild.png';
	const NEW = 'https://example.de/wp-content/uploads/2019/05/bild.webp';

	/**
	 * Ersetzer: bild.png → bild.webp und büro.jpg → büro.webp.
	 *
	 * @return Value_Replacer
	 */
	private static function replacer() {
		return new Value_Replacer(
			new Url_Matcher( 'https://example.de/wp-content/uploads' ),
			array(
				'2019/05/bild.png' => '2019/05/bild.webp',
				'2019/05/büro.jpg' => '2019/05/büro.webp',
			)
		);
	}

	/**
	 * Serialisiertes Array: Längen stimmen danach, Daten sind gleich bis auf die URL.
	 */
	public function test_serialized_array() {
		$data     = array(
			'titel'  => 'Galerie',
			'bilder' => array( self::OLD, 'https://example.de/wp-content/uploads/2019/05/büro.jpg' ),
			'zahl'   => 3,
			'float'  => 1.5,
			'leer'   => null,
			'an'     => true,
		);
		$replacer = self::replacer();
		$result   = $replacer->replace( serialize( $data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Testdaten.

		$expected              = $data;
		$expected['bilder'][0] = self::NEW;
		$expected['bilder'][1] = 'https://example.de/wp-content/uploads/2019/05/büro.webp';

		$this->assertSame( $expected, unserialize( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Testdaten.
		$this->assertSame( 2, $replacer->count() );
	}

	/**
	 * Objekte unbekannter Klassen und private Eigenschaften bleiben intakt.
	 */
	public function test_serialized_unknown_object() {
		$raw      = 'O:12:"Fremde_Klass":2:{s:3:"url";s:' . strlen( self::OLD ) . ':"' . self::OLD . '";s:9:"' . "\0" . '*' . "\0" . 'privat";i:5;}';
		$result   = self::replacer()->replace( $raw );
		$expected = 'O:12:"Fremde_Klass":2:{s:3:"url";s:' . strlen( self::NEW ) . ':"' . self::NEW . '";s:9:"' . "\0" . '*' . "\0" . 'privat";i:5;}';

		$this->assertSame( $expected, $result );
	}

	/**
	 * Verschachtelt serialisiert (serialisierter Text in einer Zeichenkette).
	 */
	public function test_nested_serialized() {
		$inner = serialize( array( 'bild' => self::OLD ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Testdaten.
		$outer = serialize( array( 'daten' => $inner ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Testdaten.

		$result = unserialize( self::replacer()->replace( $outer ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Testdaten.

		$this->assertSame( array( 'bild' => self::NEW ), unserialize( $result['daten'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Testdaten.
	}

	/**
	 * Der Schlüssel custom_css in serialisierten Elementor-Seiteneinstellungen bleibt unverändert.
	 */
	public function test_serialized_custom_css_is_protected() {
		$data = array(
			'custom_css'            => 'selector { background: url(' . self::OLD . '); }',
			'background_background' => 'classic',
			'background_image'      => array(
				'url' => self::OLD,
				'id'  => 22,
			),
		);

		$result = unserialize( self::replacer()->replace( serialize( $data ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Testdaten.

		$this->assertSame( $data['custom_css'], $result['custom_css'] );
		$this->assertSame( self::NEW, $result['background_image']['url'] );
	}

	/**
	 * Kaputte serialisierte Daten bleiben unverändert.
	 */
	public function test_broken_serialized_untouched() {
		$broken = 'a:1:{s:3:"url";s:999:"' . self::OLD . '";}';

		$this->assertSame( $broken, self::replacer()->replace( $broken ) );
	}

	/**
	 * Elementor-Daten: JSON mit \/, Bild-Widget, Hintergrund, Text-Editor, custom_css geschützt.
	 */
	public function test_elementor_json() {
		$data = array(
			array(
				'id'       => 'a5e0001',
				'elType'   => 'section',
				'settings' => array(
					'background_image' => array(
						'url' => self::OLD,
						'id'  => 22,
					),
					'custom_css'       => 'selector{background:url(' . self::OLD . ')}',
				),
				'elements' => array(
					array(
						'id'         => 'a5e0002',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => '<p><img src="' . self::OLD . '"></p>' ),
						'elements'   => array(),
					),
				),
			),
		);
		$json = json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Wie wp_json_encode() ohne WordPress.

		$replacer = self::replacer();
		$result   = $replacer->replace_json( $json );
		$decoded  = json_decode( $result, true );

		$this->assertStringContainsString( '\\/', $result, 'Slashes maskiert wie bei Elementor' );
		$this->assertSame( self::NEW, $decoded[0]['settings']['background_image']['url'] );
		$this->assertSame( $data[0]['settings']['custom_css'], $decoded[0]['settings']['custom_css'] );
		$this->assertSame( '<p><img src="' . self::NEW . '"></p>', $decoded[0]['elements'][0]['settings']['editor'] );
		$this->assertSame( 2, $replacer->count() );
	}

	/**
	 * Nicht dekodierbares JSON: Rohtext mit \/ wird ersetzt.
	 */
	public function test_json_fallback_escaped() {
		$raw    = '[{"url":"https:\/\/example.de\/wp-content\/uploads\/2019\/05\/bild.png"},kaputt';
		$result = self::replacer()->replace_json( $raw );

		$this->assertSame( '[{"url":"https:\/\/example.de\/wp-content\/uploads\/2019\/05\/bild.webp"},kaputt', $result );
	}

	/**
	 * Ohne Treffer bleibt JSON Byte für Byte gleich (keine Neukodierung).
	 */
	public function test_json_without_match_unchanged() {
		$raw = '{"a":{},"b":1.0,"c":"ü"}';

		$this->assertSame( $raw, self::replacer()->replace_json( $raw ) );
	}

	/**
	 * Klartext und Erkennung serialisierter Werte.
	 */
	public function test_plain_text_and_detection() {
		$this->assertSame( 'Bild: ' . self::NEW, self::replacer()->replace( 'Bild: ' . self::OLD ) );
		$this->assertTrue( Value_Replacer::is_serialized( 'a:0:{}' ) );
		$this->assertTrue( Value_Replacer::is_serialized( 'N;' ) );
		$this->assertFalse( Value_Replacer::is_serialized( 'a:Text' ) );
		$this->assertFalse( Value_Replacer::is_serialized( '{"json":1}' ) );
	}
}
