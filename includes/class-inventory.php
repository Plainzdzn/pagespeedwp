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
		);

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
