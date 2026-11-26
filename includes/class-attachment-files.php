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
	 * Tatsächlicher Bildtyp einer Datei anhand der ersten Bytes, unabhängig von Endung und MIME-Typ.
	 *
	 * WordPress wandelt HEIC-Uploads in JPG um, lässt den MIME-Typ des Anhangs aber auf image/heic.
	 * Umgekehrt darf eine echte HEIC-Datei nie als JPG behandelt werden, nur weil sie so heißt.
	 *
	 * @param string $path Absoluter Pfad.
	 * @return string|null jpg, png, webp, gif, heic, avif oder null, wenn unbekannt oder nicht lesbar.
	 */
	public static function real_kind( $path ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Nur die ersten 16 Bytes einer lokalen Datei.
		$head = file_get_contents( $path, false, null, 0, 16 );
		if ( ! is_string( $head ) || strlen( $head ) < 12 ) {
			return null;
		}

		if ( 0 === strncmp( $head, "\xFF\xD8\xFF", 3 ) ) {
			return 'jpg';
		}
		if ( 0 === strncmp( $head, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return 'png';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'webp';
		}
		if ( 'GIF8' === substr( $head, 0, 4 ) ) {
			return 'gif';
		}
		if ( 'ftyp' === substr( $head, 4, 4 ) ) {
			$brand = substr( $head, 8, 4 );
			if ( in_array( $brand, array( 'avif', 'avis' ), true ) ) {
				return 'avif';
			}
			if ( in_array( $brand, array( 'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'msf1' ), true ) ) {
				return 'heic';
			}
		}

		return null;
	}

	/**
	 * Die Datei, aus der umgewandelt wird: das Original in voller Auflösung, sonst die Hauptdatei.
	 *
	 * Ein Original, das nachweislich kein JPG oder PNG ist (HEIC-Master eines iPhone-Uploads), scheidet aus.
	 * Dann ist die von WordPress erzeugte Hauptdatei die Quelle, der Master bleibt unangetastet.
	 *
	 * @param array<string, string> $files Dateien aus of().
	 * @return string Relativer Pfad.
	 */
	public static function source( array $files ) {
		if ( isset( $files['original_image'] ) && file_exists( self::path( $files['original_image'] ) ) && ! self::is_foreign_master( $files['original_image'] ) ) {
			return $files['original_image'];
		}

		return $files['full'];
	}

	/**
	 * Ist die Datei nachweislich weder JPG noch PNG (z. B. ein HEIC-Master)?
	 *
	 * Unbekannter Inhalt gilt nicht als fremd, damit sich für gewöhnliche Anhänge nichts ändert.
	 *
	 * @param string $relative Relativer Pfad.
	 * @return bool
	 */
	public static function is_foreign_master( $relative ) {
		$kind = self::real_kind( self::path( $relative ) );

		return null !== $kind && ! in_array( $kind, array( 'jpg', 'png' ), true );
	}

	/**
	 * Andere Anhänge, die auf dieselbe Hauptdatei zeigen (WPML-Duplikate, doppelte Einträge).
	 *
	 * @param int    $attachment_id Attachment-ID.
	 * @param string $attached      Wert von _wp_attached_file.
	 * @return int[] Attachment-IDs, aufsteigend, ohne die eigene.
	 */
	public static function aliases( $attachment_id, $attached ) {
		global $wpdb;

		if ( '' === (string) $attached ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sicherheitsprüfung vor dem Schreiben, ohne Cache.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wp_attached_file' AND m.meta_value = %s AND m.post_id <> %d AND p.post_type = 'attachment' ORDER BY m.post_id ASC",
				(string) $attached,
				(int) $attachment_id
			)
		);

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
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
