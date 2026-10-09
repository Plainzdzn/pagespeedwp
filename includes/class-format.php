<?php
/**
 * Zahlenformate für die Oberfläche.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Formatiert Zahlen und Dateigrößen deutsch, unabhängig von der Sprache der Website.
 *
 * Die Oberfläche ist nur deutsch, deshalb gilt das deutsche Zahlenformat immer.
 */
final class Format {

	/**
	 * Geschütztes Leerzeichen zwischen Zahl und Einheit.
	 */
	const NBSP = "\u{00A0}";

	/**
	 * Zahl mit Tausenderpunkt und Dezimalkomma, z. B. „1.284“ oder „38,4“.
	 *
	 * @param int|float $number   Zahl.
	 * @param int       $decimals Nachkommastellen.
	 * @return string
	 */
	public static function number( $number, $decimals = 0 ) {
		return number_format( (float) $number, $decimals, ',', '.' );
	}

	/**
	 * Dateigröße, z. B. „390 KB“ oder „38,4 MB“ (Basis 1024 wie in WordPress).
	 *
	 * Bytes und Kilobytes ohne Nachkommastelle, ab Megabyte mit einer.
	 *
	 * @param int|float $bytes Größe in Bytes.
	 * @return string
	 */
	public static function bytes( $bytes ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$last  = count( $units ) - 1;
		$value = max( 0, (float) $bytes );
		$index = 0;

		while ( $value >= 1024 && $index < $last ) {
			$value /= 1024;
			++$index;
		}

		// Rundung auf 1.024 KB o. Ä. vermeiden: dann die nächste Einheit nehmen.
		$decimals = $index < 2 ? 0 : 1;
		if ( round( $value, $decimals ) >= 1024 && $index < $last ) {
			$value /= 1024;
			++$index;
			$decimals = 1;
		}

		return self::number( $value, $decimals ) . self::NBSP . $units[ $index ];
	}

	/**
	 * Zeitpunkt kurz: „heute, 16:52“ oder „09.10.2026, 16:52“, in der Zeitzone der Website.
	 *
	 * @param int $time Unix-Zeit.
	 * @return string
	 */
	public static function time_label( $time ) {
		if ( wp_date( 'Y-m-d', $time ) === wp_date( 'Y-m-d' ) ) {
			/* translators: %s: Uhrzeit. */
			return sprintf( __( 'heute, %s', 'akuma-webp-umwandler' ), wp_date( 'H:i', $time ) );
		}

		return wp_date( 'd.m.Y, H:i', $time );
	}
}
