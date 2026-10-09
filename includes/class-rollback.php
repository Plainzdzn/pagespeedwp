<?php
/**
 * Rückgängig machen einer Umwandlung.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Stellt Anhänge aus dem gesicherten Zustand wieder her (Briefing §4.6).
 *
 * Reihenfolge: erst die Verweise zurück auf die alten Dateien, dann Datei-Pointer,
 * Metadaten und MIME-Typ, zuletzt die WebP-Dateien löschen. So zeigt die Seite in
 * jedem Moment auf vorhandene Dateien.
 */
final class Rollback {

	/**
	 * Lässt sich diese Zeile zurücknehmen? Nur, solange die Originale existieren.
	 *
	 * @param array $row Log-Zeile.
	 * @return true|\WP_Error
	 */
	public static function check( array $row ) {
		if ( ! in_array( $row['status'], array( 'done', 'converted' ), true ) ) {
			return new \WP_Error( 'akwu_rollback_status', __( 'Dieses Bild wurde nicht umgewandelt.', 'akuma-webp-umwandler' ) );
		}

		if ( ! empty( $row['purged_at'] ) ) {
			return new \WP_Error( 'akwu_rollback_purged', __( 'Die Originale wurden gelöscht. Rückgängig ist nicht mehr möglich.', 'akuma-webp-umwandler' ) );
		}

		if ( empty( $row['old_files'] ) || empty( $row['old_meta'] ) || '' === (string) $row['old_file'] ) {
			return new \WP_Error( 'akwu_rollback_state', __( 'Der gesicherte Zustand fehlt.', 'akuma-webp-umwandler' ) );
		}

		$fullres = isset( $row['old_files']['original_image'] ) ? $row['old_files']['original_image'] : $row['old_files']['full'];
		foreach ( array_unique( array( $fullres, $row['old_files']['full'] ) ) as $relative ) {
			if ( ! is_file( Attachment_Files::path( $relative ) ) ) {
				/* translators: %s: Dateiname. */
				return new \WP_Error( 'akwu_rollback_missing', sprintf( __( 'Das Original %s fehlt. Rückgängig ist nicht mehr möglich.', 'akuma-webp-umwandler' ), wp_basename( $relative ) ) );
			}
		}

		return true;
	}

	/**
	 * Nimmt mehrere Umwandlungen zurück. Verweise werden gemeinsam in einem Durchgang ersetzt.
	 *
	 * @param array[]     $rows    Log-Zeilen mit Status done oder converted.
	 * @param Url_Matcher $matcher URL-Erkennung.
	 * @return array{done: int[], errors: array<int, string>, replacements: int}
	 */
	public static function rollback_rows( array $rows, Url_Matcher $matcher ) {
		$result = array(
			'done'         => array(),
			'errors'       => array(),
			'replacements' => 0,
		);
		$map    = array();
		$owners = array();
		$ready  = array();

		foreach ( $rows as $row ) {
			$check = self::check( $row );
			if ( is_wp_error( $check ) ) {
				$result['errors'][ $row['attachment_id'] ] = $check->get_error_message();
				continue;
			}

			$fullres = isset( $row['old_files']['original_image'] ) ? $row['old_files']['original_image'] : $row['old_files']['full'];
			foreach ( Url_Map::reverse( $row['url_map'], $fullres ) as $from => $to ) {
				$map[ $from ]    = $to;
				$owners[ $from ] = (int) $row['attachment_id'];
			}
			$ready[] = $row;
		}

		// 1. Verweise zurück auf die alten Dateien.
		$replaced               = ( new Replacer( $matcher ) )->run( $map, $owners );
		$result['replacements'] = $replaced['total'];

		// 2. Anhang zurückstellen, WebP-Dateien löschen.
		foreach ( $ready as $row ) {
			self::restore_attachment( $row );
			Log_Table::update(
				$row['id'],
				array(
					'status'  => 'rolled_back',
					'message' => sprintf(
						/* translators: %s: Anzahl. */
						__( 'Rückgängig gemacht, %s Verweise zurückgesetzt.', 'akuma-webp-umwandler' ),
						isset( $replaced['per_attachment'][ $row['attachment_id'] ] ) ? $replaced['per_attachment'][ $row['attachment_id'] ] : 0
					),
				)
			);
			$result['done'][] = (int) $row['attachment_id'];
		}

		return $result;
	}

	/**
	 * Stellt Datei-Pointer, Metadaten und MIME-Typ wieder her und löscht die neuen Dateien.
	 *
	 * Auch für abgebrochene Umwandlungen (Status working), deshalb werden die neuen
	 * Dateien zusätzlich aus dem aktuellen Zustand des Anhangs ermittelt.
	 *
	 * @param array $row Log-Zeile mit gesichertem Zustand.
	 * @return bool false, wenn kein Zustand gesichert war (dann wurde nichts geändert).
	 */
	public static function restore_attachment( array $row ) {
		$attachment_id = (int) $row['attachment_id'];

		if ( '' === (string) $row['old_file'] || empty( $row['old_meta'] ) || '' === (string) $row['old_mime'] ) {
			return false;
		}

		$new_files = is_array( $row['new_files'] ) ? array_values( $row['new_files'] ) : array();
		$current   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( $current !== $row['old_file'] ) {
			$files = Attachment_Files::of( $attachment_id );
			if ( null !== $files ) {
				$new_files = array_merge( $new_files, array_values( $files ) );
			}
		}
		if ( ! empty( $row['new_file'] ) ) {
			$new_files[] = (string) $row['new_file'];
		}

		update_post_meta( $attachment_id, '_wp_attached_file', $row['old_file'] );
		wp_update_attachment_metadata( $attachment_id, $row['old_meta'] );
		Converter::set_mime( $attachment_id, $row['old_mime'] );

		$old_files = array_values( (array) $row['old_files'] );
		foreach ( array_unique( $new_files ) as $relative ) {
			if ( in_array( $relative, $old_files, true ) ) {
				continue;
			}
			$path = Attachment_Files::path( $relative );
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		return true;
	}
}
