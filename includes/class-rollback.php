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
	 * @param array $row  Log-Zeile.
	 * @param bool  $deep Inhalt jeder vorhandenen Datei per Prüfsumme vergleichen (vor dem Schreiben), sonst nur die Größe (für die Anzeige).
	 * @return true|\WP_Error
	 */
	public static function check( array $row, $deep = false ) {
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

		$drift = self::drift( $row, $deep );
		if ( '' !== $drift ) {
			return new \WP_Error( 'akwu_rollback_changed', $drift );
		}

		return true;
	}

	/**
	 * Gibt es den Mediathek-Eintrag einer Zeile noch?
	 *
	 * @param array $row Log-Zeile.
	 * @return bool
	 */
	public static function exists( array $row ) {
		return 'attachment' === get_post_type( (int) $row['attachment_id'] );
	}

	/**
	 * Weicht der Anhang vom Zustand ab, den die Umwandlung hinterlassen hat?
	 *
	 * Rückgängig darf nur zurücknehmen, was die Umwandlung selbst angelegt hat. Zeigt der Anhang inzwischen auf eine
	 * andere Datei, führt er Dateien, die nicht aus der Umwandlung stammen, oder haben die WebP-Dateien einen anderen
	 * Inhalt, bleibt alles unverändert. Fehlende WebP-Dateien sind kein Hindernis: dann gibt es nichts zu verlieren.
	 *
	 * @param array $row  Log-Zeile mit Status done oder converted.
	 * @param bool  $deep Inhalt per Prüfsumme vergleichen, sonst nur Größen.
	 * @return string Grund oder leer, wenn der Zustand passt.
	 */
	public static function drift( array $row, $deep = false ) {
		$attachment_id = (int) $row['attachment_id'];

		if ( ! self::exists( $row ) ) {
			return __( 'Der Mediathek-Eintrag existiert nicht mehr.', 'akuma-webp-umwandler' );
		}

		$changed = __( 'Das Bild wurde seit der Umwandlung ersetzt oder bearbeitet. Rückgängig würde die neue Fassung überschreiben, deshalb bleibt alles unverändert.', 'akuma-webp-umwandler' );

		if ( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) !== (string) $row['new_file'] || 'image/webp' !== get_post_mime_type( $attachment_id ) ) {
			return $changed;
		}

		$recorded = array_values( (array) $row['new_files'] );
		$current  = Attachment_Files::of( $attachment_id );
		$foreign  = array_diff( null === $current ? array() : array_values( $current ), $recorded );
		if ( $foreign ) {
			/* translators: %s: Dateinamen. */
			return sprintf( __( 'Zum Bild gehören inzwischen Dateien, die nicht aus der Umwandlung stammen (%s). Rückgängig würde sie löschen, deshalb bleibt alles unverändert.', 'akuma-webp-umwandler' ), implode( ', ', array_map( 'wp_basename', array_slice( array_values( $foreign ), 0, 3 ) ) ) );
		}

		return self::content_drift( $row, $deep );
	}

	/**
	 * Haben die Dateien der Umwandlung noch den Inhalt von damals?
	 *
	 * Geprüft wird jede Datei, die noch vorhanden ist, auch wenn andere fehlen. Dateien, die schon vor der Umwandlung
	 * zum Bild gehörten (HEIC-Master), zählen nicht, sie werden beim Rückgängig nie gelöscht.
	 * - Mit Fingerabdruck (ab 1.1.2): Größe und SHA-256 je Datei.
	 * - Ohne Fingerabdruck (ältere Läufe) ist nur die Summe der Größen bekannt. Was sich daran widerlegen lässt, führt
	 *   zur Ablehnung. Beweisen lässt sich der unveränderte Inhalt nicht, deshalb werden solche Dateien beim Rückgängig
	 *   nicht gelöscht, sondern beiseitegelegt, siehe restore_attachment().
	 *
	 * @param array $row  Log-Zeile.
	 * @param bool  $deep Prüfsummen vergleichen, sonst nur Größen.
	 * @return string Grund oder leer.
	 */
	private static function content_drift( array $row, $deep ) {
		/* translators: %s: Dateiname oder kurze Erklärung. */
		$changed = __( 'Die WebP-Dateien haben inzwischen einen anderen Inhalt als direkt nach der Umwandlung (%s). Rückgängig würde sie löschen, deshalb bleibt alles unverändert.', 'akuma-webp-umwandler' );
		$old     = array_values( (array) $row['old_files'] );
		$hashes  = isset( $row['new_hashes'] ) ? (array) $row['new_hashes'] : array();

		clearstatcache();

		if ( $hashes ) {
			foreach ( array_unique( array_values( (array) $row['new_files'] ) ) as $relative ) {
				$path = Attachment_Files::path( $relative );
				if ( in_array( $relative, $old, true ) || ! is_file( $path ) ) {
					continue;
				}

				$expected = isset( $hashes[ $relative ] ) ? explode( ':', (string) $hashes[ $relative ], 2 ) : array( '', '' );
				if ( 2 !== count( $expected ) || (int) filesize( $path ) !== (int) $expected[0] || ( $deep && ! hash_equals( $expected[1], (string) hash_file( 'sha256', $path ) ) ) ) {
					/* translators: %s: Dateiname. */
					return sprintf( $changed, wp_basename( $relative ) );
				}
			}

			return '';
		}

		// Älterer Lauf: nur die Summe aller Dateigrößen ist bekannt, bei Zweiteinträgen steht sie beim umgewandelten Bild.
		$expected = (int) $row['bytes_after'];
		if ( 0 === $expected ) {
			foreach ( Aliases::members( $row ) as $member ) {
				$expected = max( $expected, (int) $member['bytes_after'] );
			}
		}
		if ( $expected <= 0 ) {
			return '';
		}

		$measure = Attachment_Files::measure( (array) $row['new_files'] );
		$present = (int) $measure['bytes'];
		$nothing = count( array_unique( array_values( (array) $row['new_files'] ) ) ) === count( $measure['missing'] );

		// Alles da: Die Summe muss stimmen. Etwas fehlt: Der Rest muss kleiner sein als das Ganze. Nichts mehr da: nichts zu verlieren.
		if ( ! $nothing && ( empty( $measure['missing'] ) ? $present !== $expected : $present >= $expected ) ) {
			/* translators: %s: Erklärung. */
			return sprintf( $changed, __( 'andere Gesamtgröße', 'akuma-webp-umwandler' ) );
		}

		return '';
	}

	/**
	 * Steht der Anhang in einem Zustand, den diese Zeile nicht kennt (weder alte noch neue Datei)?
	 *
	 * Dann wurde er zwischendurch von anderer Seite geändert und darf nicht zurückgestellt werden.
	 * Bekannt sind auch die Fassungen, die WordPress während der Umstellung selbst aus der neuen Datei ableitet
	 * (`-scaled` bei großen Bildern, `-rotated` nach dem Drehen), solange die Zeile noch die Zieldatei nennt.
	 *
	 * @param array $row Log-Zeile.
	 * @return bool
	 */
	public static function foreign( array $row ) {
		if ( ! self::exists( $row ) ) {
			return true;
		}

		$current = (string) get_post_meta( (int) $row['attachment_id'], '_wp_attached_file', true );
		$known   = array( (string) $row['old_file'], (string) $row['new_file'] );

		if ( '' !== (string) $row['new_file'] ) {
			$info = pathinfo( (string) $row['new_file'] );
			$base = ( ( isset( $info['dirname'] ) && '.' !== $info['dirname'] ) ? $info['dirname'] . '/' : '' ) . $info['filename'];
			foreach ( array( '-scaled', '-rotated' ) as $suffix ) {
				$known[] = $base . $suffix . ( isset( $info['extension'] ) ? '.' . $info['extension'] : '' );
			}
		}

		return ! in_array( $current, $known, true );
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

		// Zeilen, deren Mediathek-Eintrag es nicht mehr gibt (gelöscht, während das Plugin aus war), zählen nicht zur Gruppe.
		foreach ( $rows as $index => $row ) {
			if ( ! self::exists( $row ) ) {
				$result['errors'][ $row['attachment_id'] ] = __( 'Der Mediathek-Eintrag existiert nicht mehr.', 'akuma-webp-umwandler' );
				unset( $rows[ $index ] );
			}
		}

		// Einträge mit derselben Datei gehen nur gemeinsam zurück. Die ganze Gruppe wird geprüft, bevor irgendetwas
		// geschrieben wird. Scheitert die Prüfung bei einem Eintrag, bleibt die ganze Gruppe unverändert.
		$rows    = self::with_members( array_values( $rows ) );
		$blocked = array();
		foreach ( $rows as $row ) {
			$check = self::check( $row, true );
			if ( is_wp_error( $check ) ) {
				$blocked[ (string) $row['run_id'] . '|' . (string) $row['new_file'] ] = array( (int) $row['attachment_id'], $check->get_error_message() );
			}
		}

		foreach ( $rows as $row ) {
			$group = (string) $row['run_id'] . '|' . (string) $row['new_file'];
			if ( isset( $blocked[ $group ] ) ) {
				$result['errors'][ $row['attachment_id'] ] = (int) $row['attachment_id'] === $blocked[ $group ][0]
					? $blocked[ $group ][1]
					/* translators: 1: Attachment-ID, 2: Grund. */
					: sprintf( __( 'Gleiche Datei wie Bild-ID %1$d: %2$s', 'akuma-webp-umwandler' ), $blocked[ $group ][0], $blocked[ $group ][1] );
				continue;
			}

			$fullres = isset( $row['old_files']['original_image'] ) ? $row['old_files']['original_image'] : $row['old_files']['full'];
			foreach ( Url_Map::reverse( $row['url_map'], $fullres ) as $from => $to ) {
				$map[ $from ] = $to;
				if ( ! isset( $owners[ $from ] ) ) {
					$owners[ $from ] = (int) $row['attachment_id'];
				}
			}
			$ready[] = $row;
		}

		// 1. Verweise zurück auf die alten Dateien.
		$replaced               = ( new Replacer( $matcher ) )->run( $map, $owners );
		$result['replacements'] = $replaced['total'];

		// 2. Anhang zurückstellen, WebP-Dateien löschen.
		foreach ( $ready as $row ) {
			// Ohne Fingerabdruck (Läufe vor 1.1.2) lässt sich nicht beweisen, dass die WebP-Dateien unverändert sind.
			// Sie werden dann nicht gelöscht, sondern beiseitegelegt.
			$aside = empty( $row['new_hashes'] );
			self::restore_attachment( $row, $aside );

			$message = sprintf(
				/* translators: %s: Anzahl. */
				__( 'Rückgängig gemacht, %s Verweise zurückgesetzt.', 'akuma-webp-umwandler' ),
				isset( $replaced['per_attachment'][ $row['attachment_id'] ] ) ? $replaced['per_attachment'][ $row['attachment_id'] ] : 0
			);
			if ( $aside && is_dir( Attachment_Files::path( self::aside_dir( $row ) ) ) ) {
				/* translators: %s: Ordner im Upload-Verzeichnis. */
				$message .= ' ' . sprintf( __( 'Die WebP-Dateien dieses älteren Laufs wurden nicht gelöscht, sie liegen in %s.', 'akuma-webp-umwandler' ), 'uploads/' . untrailingslashit( self::aside_dir( $row ) ) );
			}

			Log_Table::update(
				$row['id'],
				array(
					'status'  => 'rolled_back',
					'message' => $message,
				)
			);
			$result['done'][] = (int) $row['attachment_id'];
		}

		return $result;
	}

	/**
	 * Ergänzt Protokollzeilen um die übrigen Einträge ihrer Gruppe (gleiche Datei, gleicher Lauf).
	 *
	 * @param array[] $rows Protokollzeilen.
	 * @return array[] Zeilen ohne Doppelte.
	 */
	public static function with_members( array $rows ) {
		$all = array();
		foreach ( $rows as $row ) {
			$all[ (int) $row['id'] ] = $row;
		}
		foreach ( $rows as $row ) {
			foreach ( Aliases::members( $row ) as $member ) {
				if ( ! isset( $all[ (int) $member['id'] ] ) && self::exists( $member ) ) {
					$all[ (int) $member['id'] ] = $member;
				}
			}
		}

		return array_values( $all );
	}

	/**
	 * Stellt Datei-Pointer, Metadaten und MIME-Typ wieder her und löscht die neuen Dateien.
	 *
	 * Auch für abgebrochene Umwandlungen (Status working), deshalb werden die neuen
	 * Dateien zusätzlich aus dem aktuellen Zustand des Anhangs ermittelt.
	 *
	 * @param array $row Log-Zeile mit gesichertem Zustand.
	 * @param bool  $aside Neue Dateien nicht löschen, sondern in den Sicherungsordner verschieben.
	 * @return bool false, wenn kein Zustand gesichert war (dann wurde nichts geändert).
	 */
	public static function restore_attachment( array $row, $aside = false ) {
		$attachment_id = (int) $row['attachment_id'];

		if ( '' === (string) $row['old_file'] || empty( $row['old_meta'] ) || '' === (string) $row['old_mime'] ) {
			return false;
		}

		// Zeigt der Anhang auf eine Datei, die diese Zeile nicht kennt, hat ihn jemand anders geändert. Dann nichts anfassen.
		if ( self::foreign( $row ) ) {
			return false;
		}

		$new_files = is_array( $row['new_files'] ) ? array_values( $row['new_files'] ) : array();
		$current   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		// Nach einem Abbruch mitten in der Umwandlung sind die neuen Dateien noch nicht protokolliert. Dann zählt, was der Anhang gerade führt.
		if ( $current !== $row['old_file'] && empty( $row['new_files'] ) ) {
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

		// Zeigt noch ein anderer Eintrag auf die WebP-Datei, bleiben die Dateien liegen. Der letzte Eintrag der Gruppe räumt auf.
		if ( ! empty( $row['new_file'] ) && (string) $row['new_file'] !== (string) $row['old_file'] && Aliases::attached_elsewhere( (string) $row['new_file'], $attachment_id ) > 0 ) {
			return true;
		}

		$old_files = array_values( (array) $row['old_files'] );
		foreach ( array_unique( $new_files ) as $relative ) {
			if ( in_array( $relative, $old_files, true ) ) {
				continue;
			}
			$path = Attachment_Files::path( $relative );
			if ( ! is_file( $path ) ) {
				continue;
			}
			if ( $aside ) {
				self::set_aside( $relative, self::aside_dir( $row ) );
			} else {
				wp_delete_file( $path );
			}
		}

		return true;
	}

	/**
	 * Ordner (relativ zum Upload-Ordner), in dem die WebP-Dateien eines älteren Laufs beim Rückgängig aufgehoben werden.
	 *
	 * @param array $row Log-Zeile.
	 * @return string Mit Schrägstrich am Ende.
	 */
	public static function aside_dir( array $row ) {
		return 'akwu-sicherung/' . substr( preg_replace( '/[^a-z0-9]/i', '', (string) $row['run_id'] ), 0, 8 ) . '-' . (int) $row['attachment_id'] . '/';
	}

	/**
	 * Verschiebt eine Datei in den Sicherungsordner. Klappt das nicht, bleibt sie an ihrem Platz. Gelöscht wird nichts.
	 *
	 * @param string $relative Relativer Pfad der Datei.
	 * @param string $dir      Zielordner relativ zum Upload-Ordner, mit Schrägstrich am Ende.
	 * @return void
	 */
	private static function set_aside( $relative, $dir ) {
		$target = Attachment_Files::path( $dir . wp_basename( $relative ) );

		if ( wp_mkdir_p( dirname( $target ) ) && ! file_exists( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Verschieben innerhalb des Upload-Ordners, ohne Kopie.
			rename( Attachment_Files::path( $relative ), $target );
		}
	}
}
