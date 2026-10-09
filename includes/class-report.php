<?php
/**
 * Bericht über alle Umwandlungen.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Fasst den aktuellen Stand aller Bilder zusammen, über alle Läufe (Briefing §4.9).
 *
 * Maßgeblich ist je Anhang die letzte Zeile im Protokoll. Ein Testlauf und ein späterer
 * Gesamtlauf ergeben so einen gemeinsamen Bericht. Für Seite, CSV und WP-CLI.
 */
final class Report {

	/**
	 * Option mit den Restfundstellen der letzten vollständigen Gegenprobe.
	 */
	const LEFTOVERS_OPTION = 'akwu_leftovers';

	/**
	 * Letzte Zeile je Anhang.
	 *
	 * @var array<int, array>
	 */
	private $rows;

	/**
	 * Restfundstellen.
	 *
	 * @var array[]
	 */
	private $leftovers;

	/**
	 * Restfundstellen je Anhang.
	 *
	 * @var array<int, array[]>
	 */
	private $by_attachment = array();

	/**
	 * Konstruktor.
	 *
	 * @param array<int, array> $rows      Letzte Zeile je Anhang.
	 * @param array[]           $leftovers Restfundstellen.
	 */
	public function __construct( array $rows, array $leftovers ) {
		$this->rows      = $rows;
		$this->leftovers = $leftovers;

		foreach ( $leftovers as $hit ) {
			$this->by_attachment[ (int) $hit['attachment'] ][] = $hit;
		}
	}

	/**
	 * Bericht aus dem Protokoll.
	 *
	 * @return self|null Null, solange noch nichts umgewandelt wurde.
	 */
	public static function load() {
		if ( ! Log_Table::exists() ) {
			return null;
		}

		$rows = self::existing( Log_Table::latest_by_attachment( array(), array( 'done', 'skipped', 'error', 'rolled_back' ) ) );
		if ( empty( $rows ) ) {
			return null;
		}

		$saved = get_option( self::LEFTOVERS_OPTION );
		$hits  = ( is_array( $saved ) && isset( $saved['hits'] ) ) ? (array) $saved['hits'] : array();

		return new self( $rows, $hits );
	}

	/**
	 * Nur Anhänge, die es noch gibt. In der Mediathek gelöschte Bilder fallen aus dem Bericht.
	 *
	 * @param array<int, array> $rows Zeilen je Anhang.
	 * @return array<int, array>
	 */
	private static function existing( array $rows ) {
		global $wpdb;

		$found = array();
		foreach ( array_chunk( array_keys( $rows ), 500 ) as $chunk ) {
			$list = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs als int.
			foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN ({$list})" ) as $attachment_id ) {
				$found[ (int) $attachment_id ] = true;
			}
		}

		return array_intersect_key( $rows, $found );
	}

	/**
	 * Speichert die Restfundstellen einer vollständigen Gegenprobe.
	 *
	 * @param array[] $hits Fundstellen.
	 * @return void
	 */
	public static function save_leftovers( array $hits ) {
		update_option(
			self::LEFTOVERS_OPTION,
			array(
				'time' => time(),
				'hits' => array_values( $hits ),
			),
			false
		);
	}

	/**
	 * Summen für Kopf und Kacheln.
	 *
	 * @return array{before: int, after: int, saved: int, percent: int, converted: int, skipped: int, errors: int, rolled_back: int, replacements: int, places: int, purged: int}
	 */
	public function totals() {
		$totals = array(
			'before'       => 0,
			'after'        => 0,
			'saved'        => 0,
			'percent'      => 0,
			'converted'    => 0,
			'skipped'      => 0,
			'errors'       => 0,
			'rolled_back'  => 0,
			'replacements' => 0,
			'places'       => 0,
			'purged'       => 0,
		);
		$places = array();

		foreach ( $this->rows as $row ) {
			switch ( $row['status'] ) {
				case 'done':
					++$totals['converted'];
					$totals['before']       += (int) $row['bytes_before'];
					$totals['after']        += (int) $row['bytes_after'];
					$totals['replacements'] += (int) $row['replacements'];
					if ( ! empty( $row['purged_at'] ) ) {
						++$totals['purged'];
					}
					foreach ( (array) $row['places'] as $place ) {
						if ( in_array( $place['where'], array( 'post', 'meta' ), true ) && $place['object'] > 0 ) {
							$places[ (int) $place['object'] ] = true;
						}
					}
					break;
				case 'skipped':
					++$totals['skipped'];
					break;
				case 'error':
					++$totals['errors'];
					break;
				case 'rolled_back':
					++$totals['rolled_back'];
					break;
			}
		}

		$totals['saved']   = max( 0, $totals['before'] - $totals['after'] );
		$totals['percent'] = $totals['before'] > 0 ? (int) round( 100 * $totals['saved'] / $totals['before'] ) : 0;
		$totals['places']  = count( $places );

		return $totals;
	}

	/**
	 * Einträge je Bild für Tabelle und CSV.
	 *
	 * @param string $sort saved (größte Ersparnis zuerst) oder id.
	 * @return array[]
	 */
	public function entries( $sort = 'saved' ) {
		$entries = array();

		foreach ( $this->rows as $attachment_id => $row ) {
			$done   = 'done' === $row['status'];
			$before = (int) $row['bytes_before'];
			$after  = $done ? (int) $row['bytes_after'] : $before;

			$entries[] = array(
				'id'           => (int) $attachment_id,
				'row_id'       => (int) $row['id'],
				'status'       => $row['status'],
				'old_file'     => (string) $row['old_file'],
				'new_file'     => $done ? (string) $row['new_file'] : '',
				'file'         => self::display_file( $row ),
				'before'       => $before,
				'after'        => $after,
				'percent'      => ( $done && $before > 0 ) ? (int) round( 100 * ( $before - $after ) / $before ) : 0,
				'replacements' => $done ? (int) $row['replacements'] : 0,
				'message'      => (string) $row['message'],
				'purged'       => ! empty( $row['purged_at'] ),
				'updated'      => (string) $row['updated_at'],
				'leftovers'    => $this->leftovers_for( $attachment_id ),
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) use ( $sort ) {
				if ( 'id' === $sort ) {
					return $a['id'] <=> $b['id'];
				}
				return array( 'done' === $b['status'], $b['before'] - $b['after'], $b['before'] ) <=> array( 'done' === $a['status'], $a['before'] - $a['after'], $a['before'] );
			}
		);

		return $entries;
	}

	/**
	 * Restfundstellen von Bildern, die noch umgewandelt sind.
	 *
	 * @return array[] Fundstellen aus Usage_Finder, ergänzt um file (alter Dateiname).
	 */
	public function leftovers() {
		$hits = array();

		foreach ( $this->leftovers as $hit ) {
			$attachment_id = (int) $hit['attachment'];
			if ( ! isset( $this->rows[ $attachment_id ] ) || 'done' !== $this->rows[ $attachment_id ]['status'] ) {
				continue;
			}
			$hit['file'] = wp_basename( (string) $this->rows[ $attachment_id ]['old_file'] );
			$hits[]      = $hit;
		}

		return $hits;
	}

	/**
	 * Anhänge, deren alte Adresse noch irgendwo steht. Ihre Originale werden nicht gelöscht.
	 *
	 * @return int[]
	 */
	public function referenced_ids() {
		return array_values( array_unique( wp_list_pluck( $this->leftovers(), 'attachment' ) ) );
	}

	/**
	 * Was „Originale löschen“ jetzt freigeben würde.
	 *
	 * @return array{rows: int[], count: int, bytes: int, kept: int} Zeilen-IDs, Anzahl, Bytes, behaltene Bilder.
	 */
	public function purgeable() {
		$keep   = array_flip( $this->referenced_ids() );
		$result = array(
			'rows'  => array(),
			'count' => 0,
			'bytes' => 0,
			'kept'  => 0,
		);

		foreach ( $this->rows as $attachment_id => $row ) {
			if ( 'done' !== $row['status'] || ! empty( $row['purged_at'] ) ) {
				continue;
			}
			if ( isset( $keep[ $attachment_id ] ) ) {
				++$result['kept'];
				continue;
			}
			$result['rows'][] = (int) $row['id'];
			$result['bytes'] += (int) $row['bytes_before'];
		}

		$result['count'] = count( $result['rows'] );

		return $result;
	}

	/**
	 * Zeilen, die sich zurücknehmen lassen, und die übrigen mit Grund.
	 *
	 * @return array{rows: int[], blocked: array<int, string>} Zeilen-IDs und Anhang-ID => Grund.
	 */
	public function rollbackable() {
		$result = array(
			'rows'    => array(),
			'blocked' => array(),
		);

		foreach ( $this->rows as $attachment_id => $row ) {
			if ( 'done' !== $row['status'] ) {
				continue;
			}
			$check = Rollback::check( $row );
			if ( true === $check ) {
				$result['rows'][] = (int) $row['id'];
			} else {
				$result['blocked'][ $attachment_id ] = $check->get_error_message();
			}
		}

		return $result;
	}

	/**
	 * Letzte Zeile eines Anhangs.
	 *
	 * @param int $attachment_id Anhang.
	 * @return array|null
	 */
	public function row( $attachment_id ) {
		return isset( $this->rows[ $attachment_id ] ) ? $this->rows[ $attachment_id ] : null;
	}

	/**
	 * Zeitpunkt der letzten Änderung im Protokoll.
	 *
	 * @return int Unix-Zeit, 0 wenn unbekannt.
	 */
	public function last_change() {
		$latest = '';
		foreach ( $this->rows as $row ) {
			if ( (string) $row['updated_at'] > $latest ) {
				$latest = (string) $row['updated_at'];
			}
		}

		return '' === $latest ? 0 : (int) strtotime( $latest . ' UTC' );
	}

	/**
	 * CSV für Excel: Semikolon, UTF-8 mit BOM (Briefing §4.9).
	 *
	 * @return string
	 */
	public function csv() {
		$lines = array(
			array( 'ID', 'Datei vorher', 'Datei nachher', 'Status', 'Bytes vorher', 'Bytes nachher', 'Ersparnis %', 'Verweise ersetzt', 'Bitte prüfen', 'Originale gelöscht', 'Meldung', 'Zeitpunkt (UTC)' ),
		);

		foreach ( $this->entries( 'id' ) as $entry ) {
			$checks = array();
			foreach ( $entry['leftovers'] as $hit ) {
				$checks[] = $hit['label'] . ' (' . ( null === $hit['warning'] ? __( 'nicht ersetzt', 'akuma-webp-umwandler' ) : Scan_Result::warning_label( $hit['warning'] ) ) . ')';
			}

			$lines[] = array(
				$entry['id'],
				$entry['old_file'],
				$entry['new_file'],
				self::status_label( $entry['status'] ),
				$entry['before'],
				$entry['after'],
				$entry['percent'],
				$entry['replacements'],
				implode( ', ', $checks ),
				$entry['purged'] ? __( 'ja', 'akuma-webp-umwandler' ) : __( 'nein', 'akuma-webp-umwandler' ),
				$entry['message'],
				$entry['updated'],
			);
		}

		$csv = "\xEF\xBB\xBF";
		foreach ( $lines as $line ) {
			$csv .= implode( ';', array_map( array( __CLASS__, 'csv_value' ), $line ) ) . "\r\n";
		}

		return $csv;
	}

	/**
	 * Ein Feld für die CSV: in Anführungszeichen, wenn nötig, und ohne Formel-Einschleusung.
	 *
	 * @param mixed $value Wert.
	 * @return string
	 */
	public static function csv_value( $value ) {
		if ( is_int( $value ) ) {
			return (string) $value;
		}

		$value = (string) $value;

		// Excel würde =, +, -, @ am Anfang als Formel ausführen.
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			$value = "'" . $value;
		}

		if ( preg_match( '/[;"\r\n]/', $value ) ) {
			$value = '"' . str_replace( '"', '""', $value ) . '"';
		}

		return $value;
	}

	/**
	 * Lesbarer Status.
	 *
	 * @param string $status Status aus dem Protokoll.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'done'        => __( 'Umgewandelt', 'akuma-webp-umwandler' ),
			'skipped'     => __( 'Übersprungen', 'akuma-webp-umwandler' ),
			'error'       => __( 'Fehler', 'akuma-webp-umwandler' ),
			'rolled_back' => __( 'Zurückgesetzt', 'akuma-webp-umwandler' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Restfundstellen eines Anhangs.
	 *
	 * @param int $attachment_id Anhang.
	 * @return array[]
	 */
	private function leftovers_for( $attachment_id ) {
		if ( ! isset( $this->rows[ $attachment_id ], $this->by_attachment[ $attachment_id ] ) || 'done' !== $this->rows[ $attachment_id ]['status'] ) {
			return array();
		}

		return $this->by_attachment[ $attachment_id ];
	}

	/**
	 * Dateiname für die Anzeige: nach der Umwandlung der neue, sonst der alte.
	 *
	 * @param array $row Zeile.
	 * @return string
	 */
	private static function display_file( array $row ) {
		if ( 'done' === $row['status'] && '' !== (string) $row['new_file'] ) {
			return wp_basename( (string) $row['new_file'] );
		}
		if ( '' !== (string) $row['old_file'] ) {
			return wp_basename( (string) $row['old_file'] );
		}

		return wp_basename( (string) get_post_meta( (int) $row['attachment_id'], '_wp_attached_file', true ) );
	}
}
