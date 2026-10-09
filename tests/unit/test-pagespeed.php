<?php
/**
 * Tests für das Auslesen der PageSpeed-Antwort.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\PageSpeed;
use PHPUnit\Framework\TestCase;

/**
 * Antwort der PageSpeed Insights API v5 (gekürzt auf die genutzten Felder).
 */
class PageSpeed_Test extends TestCase {

	/**
	 * Punktzahl, LCP und Seitengewicht.
	 */
	public function test_parse_result() {
		$body = self::json(
			array(
				'lighthouseResult' => array(
					'categories' => array( 'performance' => array( 'score' => 0.81 ) ),
					'audits'     => array(
						'largest-contentful-paint' => array( 'numericValue' => 2534.7 ),
						'total-byte-weight'        => array( 'numericValue' => 1843200 ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				'score'  => 81,
				'lcp'    => '2,5 s',
				'weight' => 1843200,
			),
			PageSpeed::parse( $body )
		);
	}

	/**
	 * Fehlermeldung der API wird durchgereicht.
	 */
	public function test_parse_api_error() {
		$body = self::json( array( 'error' => array( 'message' => 'API key not valid.' ) ) );

		$this->assertSame( array( 'error' => 'API key not valid.' ), PageSpeed::parse( $body ) );
	}

	/**
	 * Unlesbares oder unvollständiges JSON ergibt einen Fehler.
	 */
	public function test_parse_invalid() {
		$this->assertArrayHasKey( 'error', PageSpeed::parse( 'kein json' ) );
		$this->assertArrayHasKey( 'error', PageSpeed::parse( '{"lighthouseResult":{}}' ) );
	}

	/**
	 * Ohne Audits: Punktzahl ja, LCP und Gewicht leer.
	 */
	public function test_parse_without_audits() {
		$body = self::json( array( 'lighthouseResult' => array( 'categories' => array( 'performance' => array( 'score' => 0.5 ) ) ) ) );

		$this->assertSame(
			array(
				'score'  => 50,
				'lcp'    => '',
				'weight' => 0,
			),
			PageSpeed::parse( $body )
		);
	}

	/**
	 * JSON wie wp_json_encode(), ohne WordPress.
	 *
	 * @param mixed $data Daten.
	 * @return string
	 */
	private static function json( $data ) {
		return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit-Test ohne WordPress.
	}
}
