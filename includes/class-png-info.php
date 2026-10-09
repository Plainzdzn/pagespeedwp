<?php
/**
 * Transparenz-Erkennung für PNG-Dateien.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Liest den PNG-Kopf, ohne das Bild zu laden.
 *
 * Transparenz liegt vor bei Farbtyp 4 oder 6 (mit Alphakanal) oder bei einem
 * tRNS-Block vor den Bilddaten (Palette oder Farbschlüssel).
 */
final class Png_Info {

	/**
	 * PNG-Signatur.
	 */
	const SIGNATURE = "\x89PNG\r\n\x1a\n";

	/**
	 * So viele Bytes reichen in der Praxis bis zum ersten IDAT-Block.
	 */
	const HEAD_BYTES = 262144;

	/**
	 * Hat die PNG-Datei Transparenz?
	 *
	 * @param string $file Pfad.
	 * @return bool|null null, wenn die Datei kein lesbares PNG ist.
	 */
	public static function has_alpha( $file ) {
		if ( ! is_readable( $file ) ) {
			return null;
		}

		$head = file_get_contents( $file, false, null, 0, self::HEAD_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Datei, nur der Kopf.

		return false === $head ? null : self::has_alpha_in( $head );
	}

	/**
	 * Hat das PNG mit diesem Dateianfang Transparenz?
	 *
	 * @param string $data Anfang der Datei.
	 * @return bool|null null, wenn es kein PNG ist.
	 */
	public static function has_alpha_in( $data ) {
		if ( 0 !== strpos( $data, self::SIGNATURE ) ) {
			return null;
		}

		$offset = strlen( self::SIGNATURE );
		$length = strlen( $data );

		while ( $offset + 8 <= $length ) {
			$chunk = unpack( 'Nlength/a4type', substr( $data, $offset, 8 ) );
			$type  = $chunk['type'];

			if ( 'IHDR' === $type ) {
				if ( $offset + 8 + 10 > $length ) {
					return null;
				}
				$color_type = ord( $data[ $offset + 8 + 9 ] );
				if ( 4 === $color_type || 6 === $color_type ) {
					return true;
				}
			} elseif ( 'tRNS' === $type ) {
				return true;
			} elseif ( 'IDAT' === $type || 'IEND' === $type ) {
				return false;
			}

			$offset += 12 + $chunk['length'];
		}

		return false;
	}
}
