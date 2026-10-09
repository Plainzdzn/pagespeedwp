<?php
/**
 * Optionale Messung mit der PageSpeed-Insights-API.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Misst die Startseite mobil vorher und nachher (Briefing §4.9).
 *
 * Die einzige Anfrage nach außen, die das Plugin stellt, und nur mit API-Schlüssel in den
 * Einstellungen und auf Knopfdruck (oder beim Start der Umwandlung, wenn noch kein Vorher-Wert da ist).
 * Schnittstelle: PageSpeed Insights API v5, `runPagespeed` mit `strategy=mobile`.
 */
final class PageSpeed {

	/**
	 * Option mit den Messungen.
	 */
	const OPTION = 'akwu_pagespeed';

	/**
	 * Endpunkt der API.
	 */
	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Ist ein API-Schlüssel eingetragen?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== trim( (string) Settings::get( 'psi_api_key' ) );
	}

	/**
	 * Gespeicherte Messungen.
	 *
	 * @return array{before?: array, after?: array}
	 */
	public static function results() {
		$saved = get_option( self::OPTION );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Eine Messung, falls vorhanden.
	 *
	 * @param string $which before oder after.
	 * @return array|null score, lcp, weight, time, url.
	 */
	public static function result( $which ) {
		$results = self::results();

		return isset( $results[ $which ] ) ? $results[ $which ] : null;
	}

	/**
	 * Misst die Startseite und speichert das Ergebnis.
	 *
	 * @param string $which before oder after.
	 * @return array|\WP_Error Messung.
	 */
	public static function measure( $which ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'akwu_psi_key', __( 'Für die Messung fehlt der PageSpeed-API-Schlüssel in den Einstellungen.', 'akuma-webp-umwandler' ) );
		}

		$which = 'after' === $which ? 'after' : 'before';
		$page  = home_url( '/' );
		$url   = self::ENDPOINT . '?' . http_build_query(
			array(
				'url'      => $page,
				'strategy' => 'mobile',
				'category' => 'performance',
				'key'      => trim( (string) Settings::get( 'psi_api_key' ) ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$response = wp_remote_get( $url, array( 'timeout' => 90 ) );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: Fehlermeldung. */
			return new \WP_Error( 'akwu_psi_request', sprintf( __( 'PageSpeed ist nicht erreichbar: %s', 'akuma-webp-umwandler' ), $response->get_error_message() ) );
		}

		$parsed = self::parse( (string) wp_remote_retrieve_body( $response ) );
		if ( isset( $parsed['error'] ) ) {
			/* translators: %s: Fehlermeldung der API. */
			return new \WP_Error( 'akwu_psi_api', sprintf( __( 'PageSpeed meldet: %s', 'akuma-webp-umwandler' ), $parsed['error'] ) );
		}

		$parsed['time'] = time();
		$parsed['url']  = $page;

		$results           = self::results();
		$results[ $which ] = $parsed;
		update_option( self::OPTION, $results, false );

		return $parsed;
	}

	/**
	 * Liest die Antwort der API.
	 *
	 * @param string $body JSON.
	 * @return array score (0–100), lcp (Text, z. B. „2,5 s“), weight (Bytes) oder error.
	 */
	public static function parse( $body ) {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return array( 'error' => 'Unlesbare Antwort.' );
		}
		if ( isset( $data['error']['message'] ) ) {
			return array( 'error' => (string) $data['error']['message'] );
		}
		if ( ! isset( $data['lighthouseResult']['categories']['performance']['score'] ) ) {
			return array( 'error' => 'Kein Leistungswert in der Antwort.' );
		}

		$audits = isset( $data['lighthouseResult']['audits'] ) ? (array) $data['lighthouseResult']['audits'] : array();
		$lcp    = isset( $audits['largest-contentful-paint']['numericValue'] ) ? (float) $audits['largest-contentful-paint']['numericValue'] : 0.0;

		return array(
			'score'  => (int) round( 100 * (float) $data['lighthouseResult']['categories']['performance']['score'] ),
			'lcp'    => $lcp > 0 ? Format::number( $lcp / 1000, 1 ) . ' s' : '',
			'weight' => isset( $audits['total-byte-weight']['numericValue'] ) ? (int) $audits['total-byte-weight']['numericValue'] : 0,
		);
	}
}
