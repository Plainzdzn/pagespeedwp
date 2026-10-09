<?php
/**
 * Rückgängig und Originale löschen in Schritten.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Ein Job nach der Umwandlung: rollback (Briefing §4.6) oder purge (§4.7).
 *
 * Die Ziele (Log-Zeilen) stehen beim Start fest, der Stand liegt in der Option `akwu_job`.
 * Jeder Schritt arbeitet ein Stück ab. Bricht ein Request ab, macht der nächste dort weiter:
 * Zeilen, die schon zurückgesetzt oder gelöscht sind, werden dabei erkannt und übersprungen.
 */
final class Job {

	/**
	 * Name der Option.
	 */
	const OPTION = 'akwu_job';

	/**
	 * Gespeicherte Fehlermeldungen.
	 */
	const MAX_ERRORS = 50;

	/**
	 * Aktueller oder letzter Job.
	 *
	 * @return array|null
	 */
	public static function current() {
		wp_cache_delete( self::OPTION, 'options' );
		$job = get_option( self::OPTION );

		return ( is_array( $job ) && isset( $job['type'] ) ) ? $job : null;
	}

	/**
	 * Läuft gerade ein Job?
	 *
	 * @param array|null $job Job.
	 * @return bool
	 */
	public static function is_active( $job ) {
		return is_array( $job ) && 'running' === $job['status'];
	}

	/**
	 * Startet einen Job.
	 *
	 * @param string $type     rollback oder purge.
	 * @param int[]  $row_ids  Log-Zeilen.
	 * @return array|\WP_Error
	 */
	public static function start( $type, array $row_ids ) {
		if ( Conversion::is_active( Conversion::current() ) ) {
			return new \WP_Error( 'akwu_run_active', __( 'Es ist noch eine Umwandlung offen. Bitte erst fertig laufen lassen oder abbrechen.', 'akuma-webp-umwandler' ) );
		}
		if ( self::is_active( self::current() ) ) {
			return new \WP_Error( 'akwu_job_active', __( 'Es läuft noch ein Rückgängig oder Löschen. Bitte kurz warten.', 'akuma-webp-umwandler' ) );
		}
		if ( null !== Lock::holder() ) {
			return new \WP_Error( 'akwu_locked', __( 'Gerade läuft schon ein Scan oder eine Umwandlung. Bitte kurz warten.', 'akuma-webp-umwandler' ) );
		}
		if ( empty( $row_ids ) ) {
			return new \WP_Error( 'akwu_empty', 'purge' === $type ? __( 'Keine Originale zum Löschen.', 'akuma-webp-umwandler' ) : __( 'Keine Bilder zum Zurücksetzen.', 'akuma-webp-umwandler' ) );
		}

		$job = array(
			'type'         => 'purge' === $type ? 'purge' : 'rollback',
			'status'       => 'running',
			'targets'      => array_values( array_map( 'intval', $row_ids ) ),
			'cursor'       => 0,
			'done'         => 0,
			'failed'       => 0,
			'errors'       => array(),
			'bytes'        => 0,
			'replacements' => 0,
			'purged'       => array(),
			'started'      => time(),
			'finished'     => 0,
			'user'         => get_current_user_id(),
		);

		self::save( $job );

		return $job;
	}

	/**
	 * Log-Zeilen für einen Job, mit allen Prüfungen.
	 *
	 * @param string   $type           rollback oder purge.
	 * @param int[]    $attachment_ids Bei rollback: nur diese Bilder, leer für alle.
	 * @param int|null $confirm        Bei purge: eingetippte Anzahl, null ohne Abfrage (WP-CLI fragt selbst).
	 * @return int[]|\WP_Error Zeilen-IDs.
	 */
	public static function targets( $type, array $attachment_ids = array(), $confirm = null ) {
		$report = Report::load();
		if ( null === $report ) {
			return new \WP_Error( 'akwu_no_report', __( 'Es wurde noch nichts umgewandelt.', 'akuma-webp-umwandler' ) );
		}

		if ( 'purge' === $type ) {
			$purgeable = $report->purgeable();
			if ( null !== $confirm && (int) $confirm !== $purgeable['count'] ) {
				/* translators: %s: Anzahl Bilder. */
				return new \WP_Error( 'akwu_confirm', sprintf( __( 'Zum Bestätigen bitte die Zahl %s eintippen.', 'akuma-webp-umwandler' ), $purgeable['count'] ) );
			}
			return $purgeable['rows'];
		}

		$rollbackable = $report->rollbackable();
		if ( empty( $attachment_ids ) ) {
			return $rollbackable['rows'];
		}

		$rows = array();
		foreach ( array_unique( $attachment_ids ) as $attachment_id ) {
			if ( isset( $rollbackable['blocked'][ $attachment_id ] ) ) {
				return new \WP_Error( 'akwu_blocked', $rollbackable['blocked'][ $attachment_id ] );
			}
			$row = $report->row( $attachment_id );
			if ( null === $row || 'done' !== $row['status'] ) {
				/* translators: %d: Attachment-ID. */
				return new \WP_Error( 'akwu_not_converted', sprintf( __( 'Bild %d ist nicht umgewandelt.', 'akuma-webp-umwandler' ), $attachment_id ) );
			}
			$rows[] = (int) $row['id'];
		}

		return $rows;
	}

	/**
	 * Arbeitet bis zum Zeitlimit ab.
	 *
	 * @param float $budget Sekunden.
	 * @return array|\WP_Error
	 */
	public static function step( $budget ) {
		$job = self::current();

		if ( null === $job ) {
			return new \WP_Error( 'akwu_no_job', __( 'Es läuft kein Rückgängig oder Löschen.', 'akuma-webp-umwandler' ) );
		}
		if ( ! self::is_active( $job ) ) {
			return $job;
		}
		if ( ! Lock::acquire( 'job', (int) ceil( $budget ) + 150 ) ) {
			return new \WP_Error( 'akwu_locked', __( 'Ein anderes Fenster arbeitet gerade daran. Bitte kurz warten.', 'akuma-webp-umwandler' ) );
		}

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( (int) ceil( $budget ) + 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Auf manchen Hostings gesperrt.
			}

			$deadline = microtime( true ) + (float) $budget;
			$batch    = max( 1, (int) Settings::get( 'batch_size' ) );
			$total    = count( $job['targets'] );

			while ( $job['cursor'] < $total && microtime( true ) < $deadline ) {
				$ids  = array_slice( $job['targets'], $job['cursor'], 'purge' === $job['type'] ? $batch * 5 : $batch );
				$rows = Log_Table::by_ids( $ids );

				if ( 'purge' === $job['type'] ) {
					self::purge_rows( $job, $rows );
				} else {
					self::rollback_rows( $job, $rows );
				}

				$job['cursor'] += count( $ids );
				self::save( $job );
			}

			if ( $job['cursor'] >= $total ) {
				if ( 'rollback' === $job['type'] ) {
					Cache_Purger::elementor();
					$job['purged'] = Cache_Purger::purge_all();
				}
				$job['status']   = 'done';
				$job['finished'] = time();
				self::save( $job );
			}
		} finally {
			Lock::release();
		}

		return $job;
	}

	/**
	 * Fortschritt für Oberfläche und WP-CLI.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function progress( array $job ) {
		$total = count( $job['targets'] );

		return array(
			'type'     => $job['type'],
			'status'   => $job['status'],
			'total'    => $total,
			'cursor'   => min( $total, (int) $job['cursor'] ),
			'percent'  => $total > 0 ? (int) floor( 100 * min( $total, $job['cursor'] ) / $total ) : 100,
			'done'     => (int) $job['done'],
			'failed'   => (int) $job['failed'],
			'bytes'    => (int) $job['bytes'],
			'finished' => 'done' === $job['status'],
			'label'    => self::label( $job ),
		);
	}

	/**
	 * Zeile unter dem Fortschrittsbalken.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function label( array $job ) {
		$total = count( $job['targets'] );

		if ( 'done' === $job['status'] ) {
			if ( 'purge' === $job['type'] ) {
				/* translators: 1: Anzahl Bilder, 2: freigegebener Speicher. */
				return sprintf( _n( 'Originale von %1$s Bild gelöscht, %2$s frei.', 'Originale von %1$s Bildern gelöscht, %2$s frei.', $job['done'], 'akuma-webp-umwandler' ), Format::number( $job['done'] ), Format::bytes( $job['bytes'] ) );
			}
			/* translators: %s: Anzahl Bilder. */
			return sprintf( _n( '%s Bild zurückgesetzt.', '%s Bilder zurückgesetzt.', $job['done'], 'akuma-webp-umwandler' ), Format::number( $job['done'] ) );
		}

		return 'purge' === $job['type']
			/* translators: 1: bearbeitete Bilder, 2: alle Bilder. */
			? sprintf( __( 'Originale löschen: %1$s von %2$s', 'akuma-webp-umwandler' ), Format::number( min( $total, $job['cursor'] ) ), Format::number( $total ) )
			/* translators: 1: bearbeitete Bilder, 2: alle Bilder. */
			: sprintf( __( 'Zurücksetzen: %1$s von %2$s', 'akuma-webp-umwandler' ), Format::number( min( $total, $job['cursor'] ) ), Format::number( $total ) );
	}

	/**
	 * Ein Paket zurücksetzen. Schon zurückgesetzte Zeilen (nach einem Abbruch) zählen als erledigt.
	 *
	 * @param array   $job  Job.
	 * @param array[] $rows Zeilen.
	 * @return void
	 */
	private static function rollback_rows( array &$job, array $rows ) {
		$open = array();

		foreach ( $rows as $row ) {
			if ( 'rolled_back' === $row['status'] ) {
				++$job['done'];
			} else {
				$open[] = $row;
			}
		}

		if ( empty( $open ) ) {
			return;
		}

		$result = Rollback::rollback_rows( $open, new Url_Matcher( Attachment_Files::baseurl() ) );

		$job['done']         += count( $result['done'] );
		$job['replacements'] += $result['replacements'];
		foreach ( $result['errors'] as $attachment_id => $message ) {
			self::fail( $job, $attachment_id, $message );
		}
	}

	/**
	 * Originale eines Pakets löschen.
	 *
	 * @param array   $job  Job.
	 * @param array[] $rows Zeilen.
	 * @return void
	 */
	private static function purge_rows( array &$job, array $rows ) {
		foreach ( $rows as $row ) {
			if ( ! empty( $row['purged_at'] ) ) {
				++$job['done'];
				continue;
			}

			$result = Originals::purge( $row );
			if ( is_wp_error( $result ) ) {
				self::fail( $job, (int) $row['attachment_id'], $result->get_error_message() );
				continue;
			}

			++$job['done'];
			$job['bytes'] += $result;
		}
	}

	/**
	 * Fehler zählen und die ersten Meldungen merken.
	 *
	 * @param array  $job           Job.
	 * @param int    $attachment_id Anhang.
	 * @param string $message       Meldung.
	 * @return void
	 */
	private static function fail( array &$job, $attachment_id, $message ) {
		++$job['failed'];
		if ( count( $job['errors'] ) < self::MAX_ERRORS ) {
			$job['errors'][ (int) $attachment_id ] = (string) $message;
		}
	}

	/**
	 * Speichert den Job, ohne Autoload.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	private static function save( array $job ) {
		update_option( self::OPTION, $job, false );
	}
}
