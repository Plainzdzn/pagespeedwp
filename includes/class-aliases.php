<?php
/**
 * Anhänge, die sich dieselbe Datei teilen (WPML-Duplikate, doppelte Mediathek-Einträge).
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Hält eine Gruppe von Anhängen mit gemeinsamer Datei zusammen: ein Bild wird umgewandelt,
 * alle Einträge zeigen danach auf dieselben WebP-Dateien und gehen gemeinsam zurück.
 *
 * Jeder Eintrag bekommt seine eigene Protokollzeile mit seinem eigenen alten Zustand.
 * Die Gruppe ergibt sich aus Lauf und neuer Datei, die Tabelle braucht dafür keine eigene Spalte.
 */
final class Aliases {

	/**
	 * Dateien umgewandelter Gruppen, die beim Löschen eines Eintrags gefährdet sind: absoluter Pfad → gemeinsame Hauptdatei.
	 *
	 * Die Liste wächst nur. Ob eine Datei liegen bleibt, entscheidet keep_shared() jedes Mal neu am Stand der Datenbank.
	 * So stören sich verschachtelte Löschvorgänge nicht (ein anderes Plugin löscht im Hook von A ein weiteres Bild C),
	 * und es braucht kein Zeitfenster, das jemand zu früh schließen könnte.
	 *
	 * @var array<string, string>
	 */
	private static $shared = array();

	/**
	 * Vor dem Löschen eines Anhangs: Dateien merken, die ein anderer Eintrag derselben umgewandelten Datei noch führt.
	 *
	 * WordPress merkt sich die Dateien des Anhangs, bevor ein Plugin gefragt wird, und löscht sie danach ohne Rücksicht
	 * auf andere Einträge. Der einzige Punkt zum Eingreifen ist der Filter `wp_delete_file`, siehe keep_shared().
	 * Gilt nur für Dateien, die aus einer Umwandlung dieses Plugins stammen. Alles andere bleibt Sache von WordPress.
	 *
	 * @param int $attachment_id Attachment-ID, die gelöscht wird.
	 * @return void
	 */
	public static function before_delete( $attachment_id ) {
		if ( ! Log_Table::exists() ) {
			return;
		}

		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$others   = Attachment_Files::aliases( $attachment_id, $attached );
		if ( empty( $others ) ) {
			return;
		}

		$converted = false;
		foreach ( Log_Table::latest_by_attachment( array_merge( array( (int) $attachment_id ), $others ), array( 'done' ) ) as $row ) {
			if ( (string) $row['new_file'] === $attached ) {
				$converted = true;
				break;
			}
		}
		if ( ! $converted ) {
			return;
		}

		foreach ( $others as $other ) {
			foreach ( (array) Attachment_Files::of( $other ) as $relative ) {
				self::$shared[ wp_normalize_path( Attachment_Files::path( $relative ) ) ] = $attached;
			}
		}
	}

	/**
	 * Filter `wp_delete_file`: Eine Datei bleibt liegen, solange noch ein Mediathek-Eintrag auf ihre Hauptdatei zeigt.
	 *
	 * Gefragt wird die Datenbank direkt, ohne Zwischenspeicher: Der Eintrag, der gerade gelöscht wird, steht dort schon
	 * nicht mehr. Wird später auch der letzte Eintrag gelöscht oder zurückgesetzt, zeigt niemand mehr auf die Hauptdatei
	 * und die Dateien werden wie gewohnt entfernt.
	 *
	 * @param string $file Absoluter Pfad, den WordPress löschen will.
	 * @return string Derselbe Pfad oder leer, dann löscht WordPress nicht.
	 */
	public static function keep_shared( $file ) {
		if ( ! is_string( $file ) || '' === $file ) {
			return $file;
		}

		$path = wp_normalize_path( $file );
		if ( isset( self::$shared[ $path ] ) && Attachment_Files::aliases( 0, self::$shared[ $path ] ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * Prüft vor dem Schreiben, ob alle Einträge mit derselben Datei gemeinsam umgestellt werden können.
	 *
	 * @param int                   $attachment_id Attachment-ID des Bildes, das umgewandelt wird.
	 * @param string                $attached      Sein Wert von _wp_attached_file.
	 * @param array<string, string> $old_files     Seine Dateien aus Attachment_Files::of().
	 * @param string                $kind          Sein tatsächliches Format, jpg oder png.
	 * @return array{states: array<int, array>, reason: string} Alter Zustand je weiterem Eintrag, oder der Grund, warum nichts geschrieben wird.
	 */
	public static function plan( $attachment_id, $attached, array $old_files, $kind ) {
		$states = array();

		foreach ( Attachment_Files::aliases( $attachment_id, $attached ) as $alias_id ) {
			$mime  = (string) get_post_mime_type( $alias_id );
			$meta  = wp_get_attachment_metadata( $alias_id, true );
			$files = is_array( $meta ) && ! empty( $meta['file'] ) ? Attachment_Files::of( $alias_id, $meta ) : null;

			if ( null === $files || Inventory::real( $mime, $files['full'] ) !== $kind || ! self::same_files( $old_files, $files ) || ! self::same_sizes( (array) wp_get_attachment_metadata( $attachment_id, true ), $meta ) ) {
				return array(
					'states' => array(),
					'reason' => sprintf(
						/* translators: %d: Attachment-ID. */
						__( 'Bild-ID %d nutzt dieselbe Datei, hat aber andere Metadaten oder Größen. Damit beide Einträge zusammenpassen, bleibt das Bild unverändert.', 'akuma-webp-umwandler' ),
						$alias_id
					),
				);
			}

			$states[ $alias_id ] = array(
				'old_file'  => (string) get_post_meta( $alias_id, '_wp_attached_file', true ),
				'old_mime'  => $mime,
				'old_meta'  => $meta,
				'old_files' => $files,
			);
		}

		return array(
			'states' => $states,
			'reason' => '',
		);
	}

	/**
	 * Dieselben Dateien unter denselben Größennamen, unabhängig von der Reihenfolge?
	 *
	 * Der Name gehört dazu: Führt ein Eintrag eine Datei als „medium“ und der andere als eigene Größe „poster“,
	 * würde eine gemeinsame Umstellung dem zweiten seine Größe nehmen und WordPress fiele dort auf das ganze Bild zurück.
	 *
	 * @param array<string, string> $a Dateien eines Anhangs aus Attachment_Files::of().
	 * @param array<string, string> $b Dateien eines anderen Anhangs.
	 * @return bool
	 */
	public static function same_files( array $a, array $b ) {
		ksort( $a );
		ksort( $b );

		return $a === $b;
	}

	/**
	 * Dieselben Pixelmaße für das ganze Bild und jede Größe?
	 *
	 * @param array $a Metadaten eines Anhangs.
	 * @param array $b Metadaten eines anderen Anhangs.
	 * @return bool
	 */
	public static function same_sizes( array $a, array $b ) {
		return self::dimensions( $a ) === self::dimensions( $b );
	}

	/**
	 * Pixelmaße aus den Metadaten, nach Größenname sortiert.
	 *
	 * @param array $meta Metadaten.
	 * @return array<string, string> Größenname → „Breite x Höhe“.
	 */
	private static function dimensions( array $meta ) {
		$out = array(
			'full' => ( isset( $meta['width'] ) ? (int) $meta['width'] : 0 ) . 'x' . ( isset( $meta['height'] ) ? (int) $meta['height'] : 0 ),
		);

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( is_array( $size ) ) {
					$out[ 'size:' . $name ] = ( isset( $size['width'] ) ? (int) $size['width'] : 0 ) . 'x' . ( isset( $size['height'] ) ? (int) $size['height'] : 0 );
				}
			}
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Sichert den alten Zustand aller weiteren Einträge, bevor irgendetwas geschrieben wird.
	 *
	 * @param array             $leader   Protokollzeile des Bildes, das umgewandelt wird (mit run_id und new_file).
	 * @param array<int, array> $states   Alter Zustand je Eintrag aus plan().
	 * @return array[] Protokollzeilen der weiteren Einträge.
	 */
	public static function begin( array $leader, array $states ) {
		$rows = array();

		foreach ( $states as $alias_id => $state ) {
			$row = Log_Table::open_row( (string) $leader['run_id'], (int) $alias_id );
			if ( null === $row ) {
				continue;
			}

			$state = array_merge(
				$state,
				array(
					'status'       => 'working',
					'attempts'     => (int) $row['attempts'] + 1,
					'new_file'     => (string) $leader['new_file'],
					// Die Bytes zählen beim umgewandelten Bild, sonst stünde die Ersparnis doppelt im Bericht.
					'bytes_before' => 0,
					'bytes_after'  => 0,
					'message'      => '',
				)
			);
			Log_Table::update( $row['id'], $state );

			$rows[] = array_merge( $row, $state );
		}

		return $rows;
	}

	/**
	 * Stellt alle weiteren Einträge auf die WebP-Dateien des umgewandelten Bildes um.
	 *
	 * @param array   $leader Protokollzeile des umgewandelten Bildes (Status converted).
	 * @param array[] $rows   Protokollzeilen aus begin().
	 * @return void
	 */
	public static function follow( array $leader, array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}

		$leader_id = (int) $leader['attachment_id'];
		$meta      = wp_get_attachment_metadata( $leader_id, true );

		foreach ( $rows as $row ) {
			$alias_id = (int) $row['attachment_id'];
			$own      = is_array( $meta ) ? $meta : array();

			if ( ! empty( $row['old_meta']['image_meta'] ) && is_array( $row['old_meta']['image_meta'] ) ) {
				$own['image_meta'] = $row['old_meta']['image_meta'];
			}

			// Direkt schreiben: Filter anderer Plugins (WPML gleicht Duplikate selbst ab) sollen hier nichts zurückspiegeln.
			update_post_meta( $alias_id, '_wp_attached_file', wp_slash( (string) $leader['new_file'] ) );
			update_post_meta( $alias_id, '_wp_attachment_metadata', wp_slash( $own ) );
			Converter::set_mime( $alias_id, 'image/webp' );

			Log_Table::update(
				$row['id'],
				array(
					'status'     => 'converted',
					'new_file'   => (string) $leader['new_file'],
					'new_files'  => $leader['new_files'],
					'new_hashes' => isset( $leader['new_hashes'] ) ? $leader['new_hashes'] : array(),
					'url_map'    => $leader['url_map'],
					/* translators: %d: Attachment-ID. */
					'message'    => sprintf( __( 'Gleiche Datei wie Bild-ID %d, zusammen umgestellt.', 'akuma-webp-umwandler' ), $leader_id ),
				)
			);
		}
	}

	/**
	 * Setzt die weiteren Einträge zurück, wenn die Umwandlung des Bildes scheitert, und vermerkt den Grund.
	 *
	 * @param array[] $rows    Protokollzeilen aus begin().
	 * @param string  $status  skipped oder error.
	 * @param string  $message Grund.
	 * @param bool    $restore Anhang zurückstellen (nötig, sobald am Bild etwas umgestellt wurde).
	 * @return void
	 */
	public static function abort( array $rows, $status, $message, $restore ) {
		foreach ( $rows as $row ) {
			if ( $restore ) {
				Rollback::restore_attachment( $row );
			}
			Log_Table::update(
				$row['id'],
				array(
					'status'  => $status,
					'message' => $message,
				)
			);
		}
	}

	/**
	 * Alle Protokollzeilen einer Gruppe: gleicher Lauf, gleiche neue Datei, noch umgestellt.
	 *
	 * @param array $row Protokollzeile eines Eintrags.
	 * @return array[] Zeilen der übrigen Einträge, nach Attachment-ID.
	 */
	public static function members( array $row ) {
		if ( '' === (string) $row['new_file'] || '' === (string) $row['run_id'] ) {
			return array();
		}

		return Log_Table::siblings( (string) $row['run_id'], (string) $row['new_file'], (int) $row['attachment_id'] );
	}

	/**
	 * Zeigt ein anderer Anhang auf diese Hauptdatei?
	 *
	 * @param string $relative      Relativer Pfad.
	 * @param int    $attachment_id Attachment-ID, die nicht zählt.
	 * @return int Attachment-ID des anderen Anhangs oder 0.
	 */
	public static function attached_elsewhere( $relative, $attachment_id ) {
		$others = Attachment_Files::aliases( $attachment_id, (string) $relative );

		return empty( $others ) ? 0 : (int) $others[0];
	}
}
