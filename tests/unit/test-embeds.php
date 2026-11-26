<?php
/**
 * Tests für die verzögert geladenen Touren: iframes finden, Attribut setzen, im JSON ersetzen.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Embeds;
use PHPUnit\Framework\TestCase;

/**
 * Nur der eine Anbieter, nur das eine Tag, zeichengenau umkehrbar.
 */
class Embeds_Test extends TestCase {

	const HOSTS = array( 'my.matterport.com' );

	/**
	 * Das Tag der JBZ-Startseite: Quelle ohne Anführungszeichen.
	 */
	const TOUR = '<iframe width="850" height="675" src=https://my.matterport.com/show/?m=ABC123 frameborder="0" allowfullscreen allow="autoplay; fullscreen; web-share; xr-spatial-tracking;">';

	/**
	 * Quelle mit und ohne Anführungszeichen wird erkannt.
	 */
	public function test_finds_tour_with_quoted_and_unquoted_source() {
		$html  = '<p>Text</p>' . self::TOUR . '</iframe><iframe src="https://my.matterport.com/show/?m=X" title="Tour"></iframe>';
		$found = Embeds::find_iframes( $html, self::HOSTS );

		$this->assertCount( 2, $found );
		$this->assertSame( self::TOUR, $found[0]['tag'] );
		$this->assertSame( 'my.matterport.com', $found[0]['host'] );
		$this->assertNull( $found[0]['loading'] );
	}

	/**
	 * Andere Einbettungen bleiben außen vor: Videos, Karten, ähnlich klingende Hosts, relative Quellen.
	 */
	public function test_ignores_other_embeds() {
		$html = '<iframe src="https://www.youtube.com/embed/x"></iframe>'
			. '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>'
			. '<iframe src="https://my.matterport.com.example.org/show/"></iframe>'
			. '<iframe src="https://evil.example/?u=https://my.matterport.com/show/"></iframe>'
			. '<iframe src="/show/?m=1"></iframe>'
			. '<iframe data-src="https://my.matterport.com/show/?m=1"></iframe>';

		$this->assertSame( array(), Embeds::find_iframes( $html, self::HOSTS ) );
	}

	/**
	 * Ein vorhandenes loading-Attribut wird gemeldet und nicht überschrieben.
	 */
	public function test_existing_loading_attribute_is_kept() {
		$tag   = '<iframe loading="eager" src="https://my.matterport.com/show/?m=1">';
		$found = Embeds::find_iframes( $tag, self::HOSTS );

		$this->assertSame( 'eager', $found[0]['loading'] );
		$this->assertSame( $tag, Embeds::lazy_tag( $tag ) );
	}

	/**
	 * Es kommt genau ein Attribut dazu, alles andere bleibt Zeichen für Zeichen gleich.
	 */
	public function test_lazy_tag_only_adds_the_attribute() {
		$lazy = Embeds::lazy_tag( self::TOUR );

		$this->assertSame( '<iframe loading="lazy"' . substr( self::TOUR, strlen( '<iframe' ) ), $lazy );
		$this->assertSame( 'lazy', Embeds::find_iframes( $lazy, self::HOSTS )[0]['loading'] );
		$this->assertStringContainsString( 'allowfullscreen', $lazy );
	}

	/**
	 * Im gespeicherten Elementor-JSON ändert sich nur das Tag, und zurück ergibt wieder den alten Text.
	 */
	public function test_json_replacement_is_exactly_reversible() {
		$data = array(
			array(
				'id'       => 'a1',
				'elType'   => 'container',
				'settings' => array( 'titel' => 'Größe & „Zitat“ / Pfad' ),
				'elements' => array(
					array(
						'id'         => 'w1',
						'elType'     => 'widget',
						'widgetType' => 'html',
						'settings'   => array( 'html' => self::TOUR . '</iframe>' ),
					),
				),
			),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test ohne WordPress.
		$raw  = json_encode( $data );
		$lazy = Embeds::lazy_tag( self::TOUR );
		$new  = Embeds::replace_in_json( $raw, self::TOUR, $lazy );

		$this->assertNotNull( $new );
		$this->assertSame( strlen( $raw ) + strlen( ' loading=\"lazy\"' ), strlen( $new ) );
		$this->assertSame( $lazy . '</iframe>', json_decode( $new, true )[0]['elements'][0]['settings']['html'] );
		$this->assertSame( $data[0]['settings'], json_decode( $new, true )[0]['settings'] );
		$this->assertSame( $raw, Embeds::replace_in_json( $new, $lazy, self::TOUR ) );
	}

	/**
	 * Steht dasselbe Tag zweimal im Inhalt, wird nichts geändert.
	 */
	public function test_json_replacement_refuses_ambiguous_tag() {
		$twice = array(
			'a' => self::TOUR,
			'b' => self::TOUR,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test ohne WordPress.
		$raw = json_encode( $twice );

		$this->assertNull( Embeds::replace_in_json( $raw, self::TOUR, Embeds::lazy_tag( self::TOUR ) ) );
		$this->assertNull( Embeds::replace_in_json( '{"a":"nichts"}', self::TOUR, Embeds::lazy_tag( self::TOUR ) ) );
	}

	/**
	 * Abschnitt, Widget und ausgeblendete Geräte kommen aus dem Elementor-Baum.
	 */
	public function test_tree_reports_section_and_hidden_devices() {
		$widget = static function ( $id, $html ) {
			return array(
				'id'         => $id,
				'elType'     => 'widget',
				'widgetType' => 'html',
				'settings'   => array( 'html' => $html ),
			);
		};
		$data   = array(
			array(
				'id'       => 's1',
				'elements' => array( $widget( 'w0', '<p>Kein iframe</p>' ) ),
			),
			array(
				'id'       => 's2',
				'elements' => array(),
			),
			array(
				'id'       => 's3',
				'settings' => array( 'hide_mobile' => 'hidden-mobile' ),
				'elements' => array(
					array(
						'id'       => 'c1',
						'elements' => array( $widget( 'w1', self::TOUR . '</iframe>' ) ),
					),
				),
			),
			array(
				'id'       => 's4',
				'settings' => array(
					'hide_desktop' => 'hidden-desktop',
					'hide_tablet'  => 'hidden-tablet',
				),
				'elements' => array( $widget( 'w2', str_replace( '675', '375', self::TOUR ) . '</iframe>' ) ),
			),
		);

		$found = Embeds::in_elements( $data, self::HOSTS );

		$this->assertCount( 2, $found );
		$this->assertSame( array( 'w1', 3, 4, array( 'mobile' ) ), array( $found[0]['widget'], $found[0]['section'], $found[0]['sections'], $found[0]['hidden'] ) );
		$this->assertSame( array( 'w2', 4, array( 'desktop', 'tablet' ) ), array( $found[1]['widget'], $found[1]['section'], $found[1]['hidden'] ) );
	}
}
