<?php
/**
 * Alte Originale nach der Umwandlung löschen.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Löscht die alten JPG- und PNG-Dateien eines umgewandelten Bilds (Briefing §4.7):
 * Original, `-scaled`, alle alten Größen und die eindeutig zugehörigen Elementor-Thumbs.
 *
 * Gelöscht wird nur, was das Bild selbst nicht mehr nutzt und kein anderer Anhang als Datei führt.
 * Danach ist für dieses Bild kein Rückgängig mehr möglich.
 */
final class Originals {

	/**
	 * Löscht die alten Dateien einer Log-Zeile.
	 *
	 * @param array $row Log-Zeile mit Status done.
	 * @return int|\WP_Error Freigegebene Bytes.
	 */
	public static function purge( array $row ) {
		$attachment_id = (int) $row['attachment_id'];

		if ( 'done' !== $row['status'] ) {
			return new \WP_Error( 'akwu_purge_status', __( 'Dieses Bild ist nicht umgewandelt.', 'akuma-webp-umwandler' ) );
		}

		// Nur, wenn der Anhang noch auf das WebP zeigt. Sonst wurde er seitdem verändert.
		if ( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) !== (string) $row['new_file'] || 'image/webp' !== get_post_mime_type( $attachment_id ) ) {
			return new \WP_Error( 'akwu_purge_changed', __( 'Das Bild wurde seit der Umwandlung verändert. Die alten Dateien bleiben.', 'akuma-webp-umwandler' ) );
		}

		$current = Attachment_Files::of( $attachment_id );
		$current = null === $current ? array() : array_values( $current );
		$files   = array_diff( array_unique( array_values( (array) $row['old_files'] ) ), $current );
		$files   = array_merge( $files, self::elementor_thumbs( $row ) );
		$bytes   = 0;
		$deleted = 0;

		foreach ( array_unique( $files ) as $relative ) {
			$path = Attachment_Files::path( $relative );
			if ( ! is_file( $path ) || self::used_elsewhere( $relative, $attachment_id ) ) {
				continue;
			}

			$size = (int) filesize( $path );
			wp_delete_file( $path );

			if ( ! is_file( $path ) ) {
				$bytes += $size;
				++$deleted;
			}
		}

		Log_Table::update(
			$row['id'],
			array(
				'purged_at' => current_time( 'mysql', true ),
				/* translators: %s: Anzahl Dateien. */
				'message'   => sprintf( _n( 'Original gelöscht (%s Datei).', 'Originale gelöscht (%s Dateien).', $deleted, 'akuma-webp-umwandler' ), Format::number( $deleted ) ),
			)
		);

		return $bytes;
	}

	/**
	 * Ein umgewandeltes Bild wird aus der Mediathek gelöscht. WordPress löscht dann nur die
	 * WebP-Dateien, die alten Originale stehen nicht mehr in den Metadaten. Sie werden hier
	 * mit gelöscht, damit keine verwaisten Dateien bleiben.
	 *
	 * Läuft vor dem Löschen der Metadaten (Hook `delete_attachment`).
	 *
	 * @param int $attachment_id Anhang.
	 * @return void
	 */
	public static function on_delete_attachment( $attachment_id ) {
		if ( ! Log_Table::exists() ) {
			return;
		}

		$rows = Log_Table::latest_by_attachment( array( (int) $attachment_id ), array( 'done' ) );
		if ( isset( $rows[ $attachment_id ] ) && empty( $rows[ $attachment_id ]['purged_at'] ) ) {
			self::purge( $rows[ $attachment_id ] );
		}
	}

	/**
	 * Elementor-Thumbs der alten Datei, nur wenn der Dateiname keinem anderen Anhang gehört.
	 *
	 * @param array $row Log-Zeile.
	 * @return string[] Pfade relativ zum Upload-Ordner.
	 */
	private static function elementor_thumbs( array $row ) {
		$dir    = Attachment_Files::basedir() . Inventory::ELEMENTOR_THUMBS . '/';
		$thumbs = array();

		if ( ! is_dir( $dir ) ) {
			return $thumbs;
		}

		$sources = array( (string) $row['old_files']['full'] );
		if ( isset( $row['old_files']['original_image'] ) ) {
			$sources[] = (string) $row['old_files']['original_image'];
		}

		foreach ( array_unique( $sources ) as $source ) {
			$name = wp_basename( $source );
			if ( self::basename_shared( $name, (int) $row['attachment_id'] ) ) {
				continue;
			}

			$key = Inventory::file_key( $source );
			$dot = strrpos( $key, '.' );
			foreach ( (array) glob( $dir . self::glob_escape( substr( $key, 0, (int) $dot ) ) . '-*' ) as $path ) {
				if ( is_string( $path ) && Inventory::thumb_key( wp_basename( $path ) ) === $key ) {
					$thumbs[] = Inventory::ELEMENTOR_THUMBS . '/' . wp_basename( $path );
				}
			}
		}

		return $thumbs;
	}

	/**
	 * Führt ein anderer Anhang dieselbe Datei?
	 *
	 * @param string $relative      Pfad relativ zum Upload-Ordner.
	 * @param int    $attachment_id Eigener Anhang.
	 * @return bool
	 */
	private static function used_elsewhere( $relative, $attachment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sicherheitsprüfung vor dem Löschen, ohne Cache.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1", $relative, $attachment_id ) );
	}

	/**
	 * Hat ein anderer Anhang eine Datei mit demselben Namen (auch in einem anderen Ordner)?
	 * Dann lassen sich Elementor-Thumbs nicht eindeutig zuordnen.
	 *
	 * @param string $name          Dateiname.
	 * @param int    $attachment_id Eigener Anhang.
	 * @return bool
	 */
	private static function basename_shared( $name, $attachment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sicherheitsprüfung vor dem Löschen, ohne Cache.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value = %s OR meta_value LIKE %s ) AND post_id <> %d LIMIT 1", $name, '%/' . $wpdb->esc_like( $name ), $attachment_id ) );
	}

	/**
	 * Maskiert Zeichen, die glob() als Muster liest.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function glob_escape( $text ) {
		return preg_replace( '/([*?\[\]])/', '[$1]', $text );
	}
}
