<?php
/**
 * Kopf und Chunks einer WebP-Datei lesen.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Prüft, ob eine Datei wirklich ein vollständiges WebP ist, und liest Abmessungen, Kodierung und Alphakanal.
 *
 * Nur Kopf und Chunk-Liste werden gelesen (RIFF-Container, Google-Spezifikation „WebP Container“).
 * Kein WordPress nötig, damit es ohne Testinstallation geprüft werden kann.
 */
final class Webp_Info {

	/**
	 * Höchstens so viele Chunks werden gelesen. Ein Standbild hat zwei bis fünf.
	 */
	const MAX_CHUNKS = 64;

	/**
	 * Liest eine WebP-Datei.
	 *
	 * @param string $file Pfad.
	 * @return array|null Null, wenn die Datei kein vollständiges WebP ist. Sonst {
	 *     @type int    $width       Breite in Pixeln.
	 *     @type int    $height      Höhe in Pixeln.
	 *     @type string $compression lossy oder lossless.
	 *     @type bool   $alpha       Das Bild trägt einen Alphakanal (VP8X mit Alpha-Flag und ALPH-Chunk, oder VP8L mit Alpha-Bit).
	 *     @type bool   $animated    Animation.
	 * }
	 */
	public static function read( $file ) {
		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		clearstatcache( true, $file );
		$size   = (int) filesize( $file );
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Lokale Datei, nur lesen.

		if ( ! $handle ) {
			return null;
		}

		$info = self::parse( $handle, $size );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Gehört zu fopen() oben.

		return $info;
	}

	/**
	 * Wertet den Container aus.
	 *
	 * @param resource $handle Geöffnete Datei.
	 * @param int      $size   Dateigröße.
	 * @return array|null
	 */
	private static function parse( $handle, $size ) {
		$head = self::bytes( $handle, 12 );

		if ( null === $head || 'RIFF' !== substr( $head, 0, 4 ) || 'WEBP' !== substr( $head, 8, 4 ) ) {
			return null;
		}

		// Die RIFF-Länge muss genau zur Datei passen. Eine abgebrochene Ausgabe ist kürzer.
		$riff = unpack( 'V', substr( $head, 4, 4 ) );
		if ( (int) $riff[1] + 8 !== $size ) {
			return null;
		}

		$canvas   = null;
		$flags    = 0;
		$stream   = null;
		$has_alph = false;
		$animated = false;
		$offset   = 12;

		for ( $i = 0; $i < self::MAX_CHUNKS && $offset + 8 <= $size; $i++ ) {
			if ( 0 !== fseek( $handle, $offset ) ) {
				return null;
			}

			$chunk = self::bytes( $handle, 8 );
			if ( null === $chunk ) {
				return null;
			}

			$type   = substr( $chunk, 0, 4 );
			$length = unpack( 'V', substr( $chunk, 4, 4 ) );
			$length = (int) $length[1];
			$next   = $offset + 8 + $length + ( $length % 2 );

			if ( $offset + 8 + $length > $size ) {
				return null;
			}

			if ( 'VP8X' === $type ) {
				$payload = self::bytes( $handle, 10 );
				if ( null === $payload || $length < 10 ) {
					return null;
				}
				$flags  = ord( $payload[0] );
				$canvas = array(
					1 + self::uint24( substr( $payload, 4, 3 ) ),
					1 + self::uint24( substr( $payload, 7, 3 ) ),
				);
			} elseif ( 'ALPH' === $type ) {
				$has_alph = true;
			} elseif ( 'ANIM' === $type || 'ANMF' === $type ) {
				$animated = true;
			} elseif ( ( 'VP8 ' === $type || 'VP8L' === $type ) && null === $stream ) {
				$stream = self::bitstream( $handle, $type, $length );
				if ( null === $stream ) {
					return null;
				}
				if ( 'VP8L' === $type ) {
					// Ein ALPH-Chunk vor einem VP8L-Bild ist nicht erlaubt.
					$has_alph = false;
				}
			}

			$offset = $next;
		}

		if ( null === $stream && ! $animated ) {
			return null;
		}

		$animated = $animated || (bool) ( $flags & 0x02 );

		if ( $animated ) {
			$width  = $canvas ? $canvas[0] : 0;
			$height = $canvas ? $canvas[1] : 0;
			$alpha  = (bool) ( $flags & 0x10 );
			$mode   = $stream ? $stream['compression'] : 'lossy';
		} else {
			// Leinwand und Bild müssen bei einem Standbild gleich groß sein.
			if ( $canvas && ( $canvas[0] !== $stream['width'] || $canvas[1] !== $stream['height'] ) ) {
				return null;
			}
			$width  = $stream['width'];
			$height = $stream['height'];
			$mode   = $stream['compression'];

			if ( 'lossless' === $mode ) {
				$alpha = $canvas ? (bool) ( $flags & 0x10 ) : $stream['alpha'];
			} else {
				$alpha = $canvas && ( $flags & 0x10 ) && $has_alph;
			}
		}

		return array(
			'width'       => (int) $width,
			'height'      => (int) $height,
			'compression' => $mode,
			'alpha'       => (bool) $alpha,
			'animated'    => $animated,
		);
	}

	/**
	 * Liest Abmessungen aus dem VP8- oder VP8L-Bitstrom.
	 *
	 * @param resource $handle Datei, steht am Anfang der Nutzdaten.
	 * @param string   $type   VP8 (mit Leerzeichen) oder VP8L.
	 * @param int      $length Länge der Nutzdaten.
	 * @return array|null
	 */
	private static function bitstream( $handle, $type, $length ) {
		if ( 'VP8L' === $type ) {
			$data = $length >= 5 ? self::bytes( $handle, 5 ) : null;
			if ( null === $data || "\x2f" !== $data[0] ) {
				return null;
			}
			$bits = unpack( 'V', substr( $data, 1, 4 ) );
			$bits = (int) $bits[1];

			return array(
				'width'       => 1 + ( $bits & 0x3fff ),
				'height'      => 1 + ( ( $bits >> 14 ) & 0x3fff ),
				'alpha'       => (bool) ( ( $bits >> 28 ) & 1 ),
				'compression' => 'lossless',
			);
		}

		$data = $length >= 10 ? self::bytes( $handle, 10 ) : null;
		if ( null === $data || "\x9d\x01\x2a" !== substr( $data, 3, 3 ) || ( ord( $data[0] ) & 1 ) ) {
			return null;
		}
		$dims = unpack( 'v2', substr( $data, 6, 4 ) );

		return array(
			'width'       => $dims[1] & 0x3fff,
			'height'      => $dims[2] & 0x3fff,
			'alpha'       => false,
			'compression' => 'lossy',
		);
	}

	/**
	 * Liest genau so viele Bytes.
	 *
	 * @param resource $handle Datei.
	 * @param int      $count  Anzahl.
	 * @return string|null
	 */
	private static function bytes( $handle, $count ) {
		$data = fread( $handle, $count ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Lokale Datei, nur der Kopf.

		return ( is_string( $data ) && strlen( $data ) === $count ) ? $data : null;
	}

	/**
	 * 24-Bit-Zahl, Little Endian.
	 *
	 * @param string $bytes Drei Bytes.
	 * @return int
	 */
	private static function uint24( $bytes ) {
		return ord( $bytes[0] ) | ( ord( $bytes[1] ) << 8 ) | ( ord( $bytes[2] ) << 16 );
	}
}
