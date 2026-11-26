<?php
/**
 * Bestandsaufnahme der Bild-Anhänge.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Erfasst Bild-Anhänge mit Dateien, Größen und Format. Liest nur.
 */
final class Inventory {

	/**
	 * Formate, die umgewandelt werden: MIME-Typ => Kürzel.
	 */
	const CONVERTIBLE = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
	);

	/**
	 * Schon moderne Formate.
	 */
	/**
	 * MIME-Typen, unter denen WordPress eine in JPG umgewandelte Datei führen kann (HEIC-Uploads ab WP 6.7).
	 * Umwandelbar sind sie nur, wenn die Hauptdatei nachweislich JPG oder PNG ist, siehe real().
	 */
	const CONTAINERS = array(
		'image/heic'          => 'heic',
		'image/heif'          => 'heic',
		'image/heic-sequence' => 'heic',
		'image/heif-sequence' => 'heic',
	);

	const MODERN = array(
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	/**
	 * Unterordner der Elementor-Vorschaubilder im Upload-Ordner.
	 */
	const ELEMENTOR_THUMBS = 'elementor/thumbs';

	/**
	 * IDs der Bild-Anhänge nach einer ID, aufsteigend.
	 *
	 * @param int $after_id Letzte bereits erfasste ID.
	 * @param int $limit    Höchstzahl.
	 * @return int[]
	 */
	public static function ids_after( $after_id, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bestandsaufnahme in Paketen, Ergebnis wird nicht wiederverwendet.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				'image/%',
				$after_id,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Anzahl der Bild-Anhänge.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fortschrittsanzeige des Scans.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s", 'image/%' )
		);
	}

	/**
	 * Kürzel für einen MIME-Typ: jpg, png, webp, avif, gif, svg oder other.
	 *
	 * @param string $mime MIME-Typ.
	 * @return string
	 */
	public static function kind( $mime ) {
		if ( isset( self::CONVERTIBLE[ $mime ] ) ) {
			return self::CONVERTIBLE[ $mime ];
		}
		if ( isset( self::MODERN[ $mime ] ) ) {
			return self::MODERN[ $mime ];
		}
		if ( 'image/gif' === $mime ) {
			return 'gif';
		}
		if ( 'image/svg+xml' === $mime ) {
			return 'svg';
		}

		return 'other';
	}

	/**
	 * Tatsächliches umwandelbares Format eines Anhangs: nach MIME-Typ, bei HEIC-Einträgen nach Dateiinhalt.
	 *
	 * @param string      $mime     MIME-Typ des Anhangs.
	 * @param string|null $attached Relativer Pfad der Hauptdatei.
	 * @return string|null jpg, png oder null, wenn nicht umwandelbar (auch echte HEIC-Dateien).
	 */
	public static function real( $mime, $attached ) {
		if ( isset( self::CONVERTIBLE[ $mime ] ) ) {
			return self::CONVERTIBLE[ $mime ];
		}
		if ( ! isset( self::CONTAINERS[ $mime ] ) || null === $attached || '' === (string) $attached ) {
			return null;
		}

		$kind = Attachment_Files::real_kind( Attachment_Files::path( $attached ) );

		return in_array( $kind, array( 'jpg', 'png' ), true ) ? $kind : null;
	}

	/**
	 * Lässt sich der Anhang jetzt umwandeln? Liest MIME-Typ und Hauptdatei frisch aus der Datenbank.
	 *
	 * @param int $attachment_id Attachment-ID.
	 * @return string|null jpg, png oder null.
	 */
	public static function real_of( $attachment_id ) {
		return self::real(
			(string) get_post_mime_type( $attachment_id ),
			Attachment_Files::relative( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) )
		);
	}

	/**
	 * Ist ein Datensatz aus record() umwandelbar (JPG oder PNG, auch hinter einem HEIC-Eintrag)?
	 *
	 * @param array $item Datensatz.
	 * @return bool
	 */
	public static function convertible( array $item ) {
		return isset( $item['kind'] ) && in_array( $item['kind'], self::CONVERTIBLE, true );
	}

	/**
	 * MIME-Typen, unter denen umwandelbare Anhänge in der Datenbank stehen können, für SQL maskiert.
	 *
	 * @return string Liste wie 'image/jpeg','image/png',…
	 */
	public static function source_mimes_sql() {
		return "'" . implode( "','", array_map( 'esc_sql', array_merge( array_keys( self::CONVERTIBLE ), array_keys( self::CONTAINERS ) ) ) ) . "'";
	}

	/**
	 * Datensatz eines Anhangs für den Scan.
	 *
	 * @param int $attachment_id Anhang.
	 * @return array
	 */
	public static function record( $attachment_id ) {
		$mime  = (string) get_post_mime_type( $attachment_id );
		$kind  = self::kind( $mime );
		$meta  = wp_get_attachment_metadata( $attachment_id, true );
		$meta  = is_array( $meta ) ? $meta : array();
		$files = Attachment_Files::of( $attachment_id, $meta );

		// HEIC-Eintrag mit echter JPG-Datei: WordPress hat beim Upload umgewandelt, der MIME-Typ blieb stehen.
		$container = '';
		if ( isset( self::CONTAINERS[ $mime ] ) && null !== $files ) {
			$real = self::real( $mime, $files['full'] );
			if ( null !== $real ) {
				$kind      = $real;
				$container = self::CONTAINERS[ $mime ];
			}
		}

		$record = array(
			'id'      => (int) $attachment_id,
			'title'   => get_the_title( $attachment_id ),
			'mime'    => $mime,
			'kind'    => $kind,
			'file'    => null === $files ? '' : $files['full'],
			'files'   => null === $files ? array() : $files,
			'width'   => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'  => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'bytes'   => 0,
			'source'  => 0,
			'missing' => array(),
			'alpha'   => null,
			'thumbs'  => 0,
			'no_meta' => empty( $meta['file'] ),
		);
		if ( '' !== $container ) {
			$record['container'] = $container;
		}

		if ( null === $files ) {
			return $record;
		}

		$measure           = Attachment_Files::measure( $files );
		$record['bytes']   = $measure['bytes'];
		$record['missing'] = $measure['missing'];

		$source = Attachment_Files::path( Attachment_Files::source( $files ) );
		if ( is_file( $source ) ) {
			$record['source'] = (int) filesize( $source );
			if ( 'png' === $kind ) {
				$record['alpha'] = Png_Info::has_alpha( $source );
			}
		}

		return $record;
	}

	/**
	 * Elementor-Vorschaubilder, gruppiert nach Name und Endung des Ausgangsbilds.
	 *
	 * Elementor legt sie als `elementor/thumbs/{name}-{kennung}.{endung}` ab.
	 *
	 * @return array<string, array{count: int, bytes: int}> „name.endung“ => Anzahl und Bytes.
	 */
	public static function elementor_thumbs() {
		$dir    = Attachment_Files::basedir() . self::ELEMENTOR_THUMBS . '/';
		$groups = array();

		if ( ! is_dir( $dir ) ) {
			return $groups;
		}

		foreach ( (array) glob( $dir . '*' ) as $path ) {
			if ( ! is_string( $path ) || ! is_file( $path ) ) {
				continue;
			}
			$key = self::thumb_key( wp_basename( $path ) );
			if ( null === $key ) {
				continue;
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'count' => 0,
					'bytes' => 0,
				);
			}
			++$groups[ $key ]['count'];
			$groups[ $key ]['bytes'] += (int) filesize( $path );
		}

		return $groups;
	}

	/**
	 * Schlüssel „name.endung“ eines Elementor-Vorschaubilds, null wenn das Muster nicht passt.
	 *
	 * @param string $filename Dateiname.
	 * @return string|null
	 */
	public static function thumb_key( $filename ) {
		if ( ! preg_match( '/^(.+)-([0-9a-z]{16,})\.(jpe?g|png|gif|webp|avif)$/i', $filename, $match ) ) {
			return null;
		}

		return $match[1] . '.' . strtolower( $match[3] );
	}

	/**
	 * Schlüssel „name.endung“ einer Datei, wie sie Elementor-Vorschaubilder tragen.
	 *
	 * @param string $relative Pfad relativ zum Upload-Ordner.
	 * @return string
	 */
	public static function file_key( $relative ) {
		$name = wp_basename( $relative );
		$dot  = strrpos( $name, '.' );

		return false === $dot ? $name : substr( $name, 0, $dot ) . '.' . strtolower( substr( $name, $dot + 1 ) );
	}
}
