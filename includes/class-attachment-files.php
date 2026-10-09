<?php
/**
 * Dateien eines Bild-Anhangs.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Ermittelt alle Dateien eines Anhangs relativ zum Upload-Ordner.
 *
 * Schlüssel: `full` (angehängte Datei, bei großen Bildern die `-scaled`-Version),
 * `original_image` (Original vor dem Verkleinern, falls vorhanden) und die
 * registrierten Größen aus `_wp_attachment_metadata` mit ihrem Namen.
 */
final class Attachment_Files {

	/**
	 * Basisverzeichnis des Upload-Ordners mit Slash am Ende.
	 *
	 * @return string
	 */
	public static function basedir() {
		$upload = wp_upload_dir( null, false );

		return trailingslashit( wp_normalize_path( $upload['basedir'] ) );
	}

	/**
	 * Basis-URL des Upload-Ordners ohne Slash am Ende.
	 *
	 * @return string
	 */
	public static function baseurl() {
		$upload = wp_upload_dir( null, false );

		return untrailingslashit( $upload['baseurl'] );
	}

	/**
	 * Dateien eines Anhangs.
	 *
	 * @param int        $attachment_id Anhang.
	 * @param array|null $meta          Metadaten, sonst aus der Datenbank (ungefiltert).
	 * @return array<string, string>|null Schlüssel => Pfad relativ zum Upload-Ordner, null ohne angehängte Datei.
	 */
	public static function of( $attachment_id, $meta = null ) {
		$attached = self::relative( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );

		if ( null === $attached ) {
			return null;
		}

		if ( null === $meta ) {
			$meta = wp_get_attachment_metadata( $attachment_id, true );
		}

		return self::from_meta( $attached, is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Dateien aus angehängter Datei und Metadaten.
	 *
	 * @param string $attached Angehängte Datei relativ zum Upload-Ordner.
	 * @param array  $meta     Metadaten.
	 * @return array<string, string>
	 */
	public static function from_meta( $attached, array $meta ) {
		$dir   = dirname( $attached );
		$dir   = ( '.' === $dir || '' === $dir ) ? '' : $dir . '/';
		$files = array( 'full' => $attached );

		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$files['original_image'] = $dir . wp_basename( $meta['original_image'] );
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && 'full' !== $name && 'original_image' !== $name ) {
					$files[ (string) $name ] = $dir . wp_basename( $size['file'] );
				}
			}
		}

		return $files;
	}

	/**
	 * Absoluter Pfad zu einer Datei relativ zum Upload-Ordner.
	 *
	 * @param string $relative Pfad relativ zum Upload-Ordner.
	 * @return string
	 */
	public static function path( $relative ) {
		return self::basedir() . ltrim( $relative, '/' );
	}

	/**
	 * Pfad relativ zum Upload-Ordner. Absolute Pfade außerhalb davon ergeben null.
	 *
	 * @param string $file Wert aus `_wp_attached_file`.
	 * @return string|null
	 */
	public static function relative( $file ) {
		if ( '' === $file ) {
			return null;
		}

		$file = wp_normalize_path( $file );

		if ( path_is_absolute( $file ) ) {
			$base = self::basedir();
			return 0 === strpos( $file, $base ) ? substr( $file, strlen( $base ) ) : null;
		}

		return ltrim( $file, '/' );
	}

	/**
	 * Quelle für die Umwandlung: das Original vor dem Verkleinern, sonst die angehängte Datei.
	 *
	 * @param array<string, string> $files Ergebnis von of().
	 * @return string Pfad relativ zum Upload-Ordner.
	 */
	public static function source( array $files ) {
		if ( isset( $files['original_image'] ) && file_exists( self::path( $files['original_image'] ) ) ) {
			return $files['original_image'];
		}

		return $files['full'];
	}

	/**
	 * Gesamtgröße und fehlende Dateien. Doppelte Pfade (gleiche Datei für zwei Größen) zählen einmal.
	 *
	 * @param array<string, string> $files Ergebnis von of().
	 * @return array{bytes: int, missing: string[]}
	 */
	public static function measure( array $files ) {
		$bytes   = 0;
		$missing = array();

		foreach ( array_unique( array_values( $files ) ) as $relative ) {
			$path = self::path( $relative );
			if ( is_file( $path ) ) {
				$bytes += (int) filesize( $path );
			} else {
				$missing[] = $relative;
			}
		}

		return array(
			'bytes'   => $bytes,
			'missing' => $missing,
		);
	}
}
