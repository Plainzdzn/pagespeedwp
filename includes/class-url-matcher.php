<?php
/**
 * Erkennen und Ersetzen von Upload-URLs in beliebigem Text.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Findet Bild-URLs des eigenen Upload-Ordners und ersetzt deren Pfad.
 *
 * Erkannt werden absolute URLs mit http und https, protokollrelative (`//host/…`) und
 * relative (`/wp-content/uploads/…`), jeweils auch in der JSON-Schreibweise mit `\/`
 * und mit URL-kodierten Zeichen. Neben dem eigenen Host gilt die Variante mit oder
 * ohne `www.`. URLs anderer Hosts werden nie angefasst.
 *
 * Beim Ersetzen bleibt alles vor dem Pfad unverändert (Protokoll, Host, Schreibweise),
 * nur der Pfad im Upload-Ordner wird getauscht.
 *
 * Kommt ohne WordPress aus und ist dadurch direkt testbar.
 */
final class Url_Matcher {

	/**
	 * Dateiendungen, die als Bild-URL gelten.
	 */
	const EXTENSIONS = 'jpe?g|png|gif|webp|avif';

	/**
	 * Regulärer Ausdruck. Gruppe 1: alles bis einschließlich Upload-Pfad, Gruppe 2: Pfad im Upload-Ordner.
	 *
	 * @var string
	 */
	private $pattern;

	/**
	 * Letzter Pfadteil des Upload-Ordners, z. B. „uploads“. Für SQL-Vorfilter.
	 *
	 * @var string
	 */
	private $needle;

	/**
	 * Konstruktor.
	 *
	 * @param string $uploads_url Basis-URL des Upload-Ordners, z. B. https://example.de/wp-content/uploads.
	 */
	public function __construct( $uploads_url ) {
		$parts = parse_url( $uploads_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Ohne WordPress testbar.
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

		$hosts = array();
		if ( '' !== $host ) {
			$port    = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
			$hosts[] = $host . $port;
			if ( false !== strpos( $host, '.' ) && false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
				$hosts[] = ( 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host ) . $port;
			}
		}

		$slash    = '(?:/|\\\\/)';
		$path_re  = '';
		$segments = '' === $path ? array() : explode( '/', $path );
		foreach ( $segments as $segment ) {
			$path_re .= $slash . preg_quote( $segment, '~' );
		}

		$quoted_hosts = array();
		foreach ( $hosts as $candidate ) {
			$quoted_hosts[] = preg_quote( $candidate, '~' );
		}

		// Absolut oder protokollrelativ mit eigenem Host, oder relativ ohne Host davor.
		$prefix = '' === $host
			? '(?<![A-Za-z0-9._\\~%:\\-\\\\])'
			: '(?:(?:https?:)?' . $slash . $slash . '(?i:' . implode( '|', $quoted_hosts ) . ')|(?<![A-Za-z0-9._\\~%:\\-\\\\]))';

		$this->pattern = '~(' . $prefix . $path_re . $slash . ')'
			. '((?:[^\s"\'()<>\\\\?#,;|]|\\\\/)+?\.(?i:' . self::EXTENSIONS . '))'
			. '(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])~';

		$this->needle = '' === $path ? $host : $segments[ count( $segments ) - 1 ];
	}

	/**
	 * Text für einen SQL-LIKE-Vorfilter (kommt in jeder erkannten URL vor).
	 *
	 * @return string
	 */
	public function like_needle() {
		return $this->needle;
	}

	/**
	 * Alle Upload-Pfade im Text mit Anzahl.
	 *
	 * @param string $text Beliebiger Text.
	 * @return array<string, int> Pfad im Upload-Ordner (normalisiert, z. B. „2019/05/bild.png“) => Anzahl.
	 */
	public function find( $text ) {
		$found = array();

		if ( ! is_string( $text ) || '' === $text || ! preg_match_all( $this->pattern, $text, $matches ) ) {
			return $found;
		}

		foreach ( $matches[2] as $raw ) {
			$path           = self::normalize( $raw );
			$found[ $path ] = isset( $found[ $path ] ) ? $found[ $path ] + 1 : 1;
		}

		return $found;
	}

	/**
	 * Ersetzt Upload-Pfade laut Zuordnung. Schreibweise (JSON-Slashes, URL-Kodierung) bleibt erhalten.
	 *
	 * @param string                $text  Beliebiger Text.
	 * @param array<string, string> $map   Alter Pfad => neuer Pfad, normalisiert.
	 * @param int                   $count Wird um die Zahl der Ersetzungen erhöht.
	 * @return string
	 */
	public function replace( $text, array $map, &$count = 0 ) {
		if ( ! is_string( $text ) || '' === $text || empty( $map ) ) {
			return $text;
		}

		$result = preg_replace_callback(
			$this->pattern,
			static function ( $found ) use ( $map, &$count ) {
				$path = self::normalize( $found[2] );
				if ( ! isset( $map[ $path ] ) ) {
					return $found[0];
				}

				$new = $map[ $path ];
				if ( rawurldecode( $found[2] ) !== $found[2] ) {
					$new = implode( '/', array_map( 'rawurlencode', explode( '/', $new ) ) );
				}
				if ( false !== strpos( $found[2], '\\/' ) || false !== strpos( $found[1], '\\/' ) ) {
					$new = str_replace( '/', '\\/', $new );
				}

				++$count;

				return $found[1] . $new;
			},
			$text
		);

		return null === $result ? $text : $result;
	}

	/**
	 * Vereinheitlicht einen gefundenen Pfad: JSON-Slashes auflösen, URL-Kodierung entfernen.
	 *
	 * @param string $raw Pfad wie im Text.
	 * @return string
	 */
	private static function normalize( $raw ) {
		return rawurldecode( str_replace( '\\/', '/', $raw ) );
	}
}
