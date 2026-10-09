<?php
/**
 * Umwandlung eines einzelnen Anhangs.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Wandelt einen JPG- oder PNG-Anhang in WebP um, mit derselben Attachment-ID (Briefing §4.3).
 *
 * Ablauf:
 * 1. Quelle: Original vor dem Verkleinern, sonst die angehängte Datei.
 * 2. WebP erzeugen (Encoder), Dateiname eindeutig im Ordner (wp_unique_filename).
 * 3. Mindestersparnis prüfen, sonst überspringen.
 * 4. Zustand sichern (Log), erst dann den Anhang ändern.
 * 5. Datei-Pointer, MIME-Typ und Metadaten umstellen, alle Größen neu als WebP.
 * 6. Zuordnung alte → neue Dateien über die Größennamen.
 *
 * Alte Dateien bleiben liegen, bis die Originale bewusst gelöscht werden. Die guid bleibt unverändert.
 */
final class Converter {

	/**
	 * Wandelt den Anhang einer Log-Zeile um.
	 *
	 * @param array $row      Log-Zeile mit Status pending.
	 * @param array $settings Einstellungen des Laufs.
	 * @return array Log-Zeile nach der Umwandlung (Status converted, skipped oder error).
	 */
	public static function convert( array $row, array $settings ) {
		$attachment_id = (int) $row['attachment_id'];
		$mime          = (string) get_post_mime_type( $attachment_id );

		if ( ! isset( Inventory::CONVERTIBLE[ $mime ] ) ) {
			return self::finish( $row, 'skipped', __( 'Kein JPG oder PNG mehr, nichts zu tun.', 'akuma-webp-umwandler' ) );
		}

		$meta = wp_get_attachment_metadata( $attachment_id, true );

		// Ohne Metadaten gäbe es keinen Zustand, auf den Rückgängig zurückstellen könnte.
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return self::finish( $row, 'error', __( 'Für dieses Bild fehlen die Metadaten. Bitte zuerst die Vorschaubilder neu erzeugen lassen, dann erneut scannen.', 'akuma-webp-umwandler' ) );
		}

		$old_files = Attachment_Files::of( $attachment_id, $meta );

		if ( null === $old_files ) {
			return self::finish( $row, 'error', __( 'Keine Datei angehängt oder Datei außerhalb des Upload-Ordners.', 'akuma-webp-umwandler' ) );
		}

		$source = Attachment_Files::path( Attachment_Files::source( $old_files ) );
		if ( ! is_file( $source ) ) {
			return self::finish( $row, 'error', __( 'Die Originaldatei fehlt auf dem Server.', 'akuma-webp-umwandler' ) );
		}

		$dir    = dirname( $source );
		$target = trailingslashit( $dir ) . wp_unique_filename( $dir, pathinfo( $source, PATHINFO_FILENAME ) . '.webp' );

		// Zustand sichern, bevor irgendetwas geschrieben wird.
		$state = array(
			'status'       => 'working',
			'attempts'     => (int) $row['attempts'] + 1,
			'old_file'     => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'old_mime'     => $mime,
			'old_meta'     => $meta,
			'old_files'    => $old_files,
			'new_file'     => Attachment_Files::relative( $target ),
			'bytes_before' => Attachment_Files::measure( $old_files )['bytes'],
			'message'      => '',
		);
		Log_Table::update( $row['id'], $state );
		$row = array_merge( $row, $state );

		$encoded = Encoder::encode( $source, $target, $settings );

		if ( is_wp_error( $encoded ) ) {
			self::delete_new_file( $target, $old_files );
			return self::finish( $row, 'error', $encoded->get_error_message() );
		}

		$ratio = $encoded['bytes'] / max( 1, (int) filesize( $source ) );
		if ( Estimator::would_skip( $ratio, (int) $settings['min_savings'] ) ) {
			self::delete_new_file( $encoded['path'], $old_files );
			$saving = (int) round( ( 1 - $ratio ) * 100 );
			return self::finish(
				$row,
				'skipped',
				$saving > 0
					/* translators: 1: Ersparnis in Prozent, 2: Mindestersparnis in Prozent. */
					? sprintf( __( 'Nur %1$s %% kleiner, Mindestersparnis %2$s %%. Bild bleibt unverändert.', 'akuma-webp-umwandler' ), $saving, (int) $settings['min_savings'] )
					: __( 'Als WebP nicht kleiner. Bild bleibt unverändert.', 'akuma-webp-umwandler' )
			);
		}

		try {
			$result = self::switch_attachment( $attachment_id, $encoded['path'], $meta );
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'akwu_switch', $error->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			Rollback::restore_attachment( $row );
			return self::finish( $row, 'error', $result->get_error_message() );
		}

		$new_files = Attachment_Files::of( $attachment_id );
		$mapping   = Url_Map::build( $old_files, $new_files );

		// Jede alte Größe braucht ihre eigene neue Datei, sonst ließe sich Rückgängig nicht genau umkehren.
		if ( $mapping['missing'] ) {
			Rollback::restore_attachment( array_merge( $row, array( 'new_files' => $new_files ) ) );
			/* translators: %s: Liste von Größennamen. */
			return self::finish( $row, 'error', sprintf( __( 'Diese Größen ließen sich nicht als WebP erzeugen: %s. Bild bleibt im Original.', 'akuma-webp-umwandler' ), implode( ', ', $mapping['missing'] ) ) );
		}

		$message = sprintf(
			/* translators: 1: lossy oder lossless, 2: Qualität, 3: imagick oder gd. */
			__( 'WebP %1$s, Qualität %2$d, %3$s.', 'akuma-webp-umwandler' ),
			'lossless' === $encoded['mode'] ? __( 'verlustfrei', 'akuma-webp-umwandler' ) : __( 'verlustbehaftet', 'akuma-webp-umwandler' ),
			$encoded['quality'],
			'imagick' === $encoded['editor'] ? 'Imagick' : 'GD'
		);
		$update = array(
			'status'      => 'converted',
			'new_file'    => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'new_files'   => $new_files,
			'url_map'     => $mapping['map'],
			'bytes_after' => Attachment_Files::measure( $new_files )['bytes'],
			'message'     => $message,
		);
		Log_Table::update( $row['id'], $update );

		return array_merge( $row, $update );
	}

	/**
	 * Stellt den Anhang auf die WebP-Datei um. Die ID bleibt.
	 *
	 * @param int    $attachment_id Anhang.
	 * @param string $file          Neue Datei (absoluter Pfad).
	 * @param array  $old_meta      Metadaten vorher.
	 * @return true|\WP_Error
	 */
	private static function switch_attachment( $attachment_id, $file, array $old_meta ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( ! update_attached_file( $attachment_id, $file ) && get_attached_file( $attachment_id, true ) !== $file ) {
			return new \WP_Error( 'akwu_attached_file', __( 'Der Datei-Pointer konnte nicht umgestellt werden.', 'akuma-webp-umwandler' ) );
		}

		self::set_mime( $attachment_id, 'image/webp' );

		$meta = wp_generate_attachment_metadata( $attachment_id, $file );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return new \WP_Error( 'akwu_metadata', __( 'Die Metadaten des WebP-Bildes konnten nicht erzeugt werden.', 'akuma-webp-umwandler' ) );
		}

		// Bildinformationen (Copyright, Kamera, Bildunterschrift) aus dem Original behalten, WebP hat keine.
		if ( ! empty( $old_meta['image_meta'] ) && is_array( $old_meta['image_meta'] ) ) {
			$meta['image_meta'] = $old_meta['image_meta'];
		}

		$meta = self::add_old_sizes( $file, $meta, $old_meta );

		wp_update_attachment_metadata( $attachment_id, $meta );

		return true;
	}

	/**
	 * Erzeugt Größen, die das Bild vorher hatte, WordPress aber nicht mehr registriert
	 * (z. B. nach einem Theme-Wechsel), in denselben Maßen als WebP.
	 *
	 * So bekommt jede alte Datei genau eine neue, und Verweise wie `bild-640x480.jpg`
	 * zeigen danach auf `bild-640x480.webp` statt auf das ganze Bild.
	 * Was sich nicht erzeugen lässt, fehlt in den Metadaten und bricht die Umwandlung ab.
	 *
	 * @param string $file     Neue Datei (absoluter Pfad).
	 * @param array  $meta     Neue Metadaten.
	 * @param array  $old_meta Metadaten vorher.
	 * @return array Neue Metadaten, ergänzt.
	 */
	private static function add_old_sizes( $file, array $meta, array $old_meta ) {
		if ( empty( $old_meta['sizes'] ) || ! is_array( $old_meta['sizes'] ) ) {
			return $meta;
		}

		foreach ( $old_meta['sizes'] as $name => $size ) {
			if ( isset( $meta['sizes'][ $name ] ) || ! is_array( $size ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
				continue;
			}

			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				continue;
			}

			// Gleiche Maße wie das ganze Bild: WordPress verkleinert nicht, dann wird es eine Kopie.
			$current = $editor->get_size();
			$same    = (int) $current['width'] === (int) $size['width'] && (int) $current['height'] === (int) $size['height'];
			if ( ! $same && is_wp_error( $editor->resize( (int) $size['width'], (int) $size['height'], true ) ) ) {
				continue;
			}

			$saved = $editor->save( $editor->generate_filename(), 'image/webp' );
			if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
				continue;
			}

			$meta['sizes'][ $name ] = array(
				'file'      => wp_basename( $saved['file'] ),
				'width'     => (int) $saved['width'],
				'height'    => (int) $saved['height'],
				'mime-type' => 'image/webp',
				'filesize'  => isset( $saved['filesize'] ) ? (int) $saved['filesize'] : (int) filesize( $saved['path'] ),
			);
		}

		return $meta;
	}

	/**
	 * Setzt den MIME-Typ direkt in der Datenbank.
	 *
	 * Direkt statt über wp_update_post(): So laufen keine Speicher-Hooks anderer Plugins
	 * (z. B. Bildoptimierer, die bei jeder Aktualisierung neu komprimieren), und kein
	 * anderes Feld des Anhangs wird angefasst, auch nicht die guid.
	 *
	 * @param int    $attachment_id Anhang.
	 * @param string $mime          MIME-Typ.
	 * @return void
	 */
	public static function set_mime( $attachment_id, $mime ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Gezielte Änderung eines Felds, Cache wird danach geleert.
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime ), array( 'ID' => (int) $attachment_id ) );
		clean_post_cache( (int) $attachment_id );
	}

	/**
	 * Löscht eine neu erzeugte Datei, sofern sie nicht zu den alten Dateien gehört.
	 *
	 * @param string                $path      Absoluter Pfad.
	 * @param array<string, string> $old_files Alte Dateien relativ zum Upload-Ordner.
	 * @return void
	 */
	private static function delete_new_file( $path, array $old_files ) {
		$relative = Attachment_Files::relative( $path );

		if ( null !== $relative && ! in_array( $relative, $old_files, true ) && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Schließt eine Zeile mit Status und Nachricht ab.
	 *
	 * @param array  $row     Log-Zeile.
	 * @param string $status  Status.
	 * @param string $message Nachricht.
	 * @return array
	 */
	private static function finish( array $row, $status, $message ) {
		$update = array(
			'status'  => $status,
			'message' => $message,
		);
		Log_Table::update( $row['id'], $update );

		return array_merge( $row, $update );
	}
}
