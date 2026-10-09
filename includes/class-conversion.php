<?php
/**
 * Ablauf einer Umwandlung in Paketen.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Steuert einen Lauf: Bilder in Paketen umwandeln, Verweise je Paket ersetzen,
 * danach Elementor-CSS und Caches erneuern und eine Gegenprobe machen (Briefing §4.3 bis §4.8).
 *
 * Der Lauf steht in der Option `akwu_run`, der Stand je Bild in der Log-Tabelle.
 * Phasen: convert → finalize → verify → done. Beim Abbrechen: rollback → done.
 * Ein abgebrochener Request (Fenster zu, Zeitlimit) wird beim nächsten Schritt fortgesetzt.
 */
final class Conversion {

	/**
	 * Name der Option.
	 */
	const OPTION = 'akwu_run';

	/**
	 * Größe des Testlaufs „Erst 10 testen“.
	 */
	const TEST_SIZE = 10;

	/**
	 * Gespeicherte Restfundstellen der Gegenprobe.
	 */
	const MAX_LEFTOVERS = Verifier::MAX_HITS;

	/**
	 * Versuche je Bild, bevor es nach Abbrüchen übersprungen wird.
	 */
	const MAX_ATTEMPTS = 2;

	/**
	 * Aktueller oder letzter Lauf.
	 *
	 * @return array|null
	 */
	public static function current() {
		$run = get_option( self::OPTION );

		return ( is_array( $run ) && ! empty( $run['id'] ) ) ? $run : null;
	}

	/**
	 * Läuft, pausiert oder wird gerade abgebrochen?
	 *
	 * @param array|null $run Lauf.
	 * @return bool
	 */
	public static function is_active( $run ) {
		return is_array( $run ) && in_array( $run['status'], array( 'running', 'paused', 'cancelling' ), true );
	}

	/**
	 * Startet einen Lauf.
	 *
	 * @param string $mode  all (alle bereiten Bilder), test (die 10 meistgenutzten) oder ids.
	 * @param int[]  $ids   Bei Modus ids: diese Anhänge.
	 * @param int    $limit Höchstzahl Bilder, 0 für alle.
	 * @return array|\WP_Error Lauf.
	 */
	public static function start( $mode, array $ids = array(), $limit = 0 ) {
		if ( self::is_active( self::current() ) ) {
			return new \WP_Error( 'akwu_active', __( 'Es läuft bereits eine Umwandlung. Bitte erst fortsetzen oder abbrechen.', 'akuma-webp-umwandler' ) );
		}

		if ( Job::is_active( Job::current() ) ) {
			return new \WP_Error( 'akwu_job_active', __( 'Es läuft noch ein Rückgängig oder Löschen. Bitte kurz warten.', 'akuma-webp-umwandler' ) );
		}

		if ( null !== Lock::holder() ) {
			return new \WP_Error( 'akwu_locked', __( 'Gerade läuft schon ein Scan oder eine Umwandlung. Bitte kurz warten.', 'akuma-webp-umwandler' ) );
		}

		$queue = self::queue( $mode, $ids, $limit );
		if ( is_wp_error( $queue ) ) {
			return $queue;
		}

		Log_Table::maybe_install();

		$run = array(
			'id'        => wp_generate_uuid4(),
			'mode'      => $mode,
			'status'    => 'running',
			'phase'     => 'convert',
			'created'   => time(),
			'finished'  => 0,
			'total'     => count( $queue ),
			'settings'  => Settings::all(),
			'batches'   => 0,
			'seconds'   => 0.0,
			'elementor' => false,
			'purged'    => array(),
			'verify'    => Verifier::start(),
			'leftovers' => array(),
			'user'      => get_current_user_id(),
		);

		Log_Table::add_pending( $run['id'], $queue );
		self::save( $run );

		return $run;
	}

	/**
	 * Welche Bilder ein Lauf umwandeln würde. Ändert nichts, auch für `--dry-run`.
	 *
	 * @param string $mode  all, test oder ids.
	 * @param int[]  $ids   Bei Modus ids: diese Anhänge.
	 * @param int    $limit Höchstzahl Bilder, 0 für alle.
	 * @return int[]|\WP_Error Anhang-IDs in Reihenfolge der Umwandlung.
	 */
	public static function queue( $mode, array $ids = array(), $limit = 0 ) {
		$queue = 'ids' === $mode ? self::queue_from_ids( $ids ) : self::queue_from_scan( $mode );
		if ( is_wp_error( $queue ) ) {
			return $queue;
		}

		if ( 'test' === $mode ) {
			$limit = self::TEST_SIZE;
		}
		if ( $limit > 0 ) {
			$queue = array_slice( $queue, 0, $limit );
		}

		if ( empty( $queue ) ) {
			return new \WP_Error(
				'akwu_empty',
				'ids' === $mode
					? __( 'Keine Bilder zum Umwandeln. Es zählen nur Anhänge im Format JPG oder PNG.', 'akuma-webp-umwandler' )
					: __( 'Keine Bilder zum Umwandeln. Falls seitdem Bilder hochgeladen wurden, bitte neu scannen.', 'akuma-webp-umwandler' )
			);
		}

		return $queue;
	}

	/**
	 * Warteschlange aus dem Scan: bereite Bilder, die jetzt noch JPG oder PNG sind.
	 *
	 * @param string $mode all oder test.
	 * @return int[]|\WP_Error
	 */
	private static function queue_from_scan( $mode ) {
		$result = Scan_Result::load();

		if ( null === $result ) {
			return new \WP_Error( 'akwu_no_scan', __( 'Bitte zuerst den Bestand scannen.', 'akuma-webp-umwandler' ) );
		}

		// Beim Test die meistgenutzten zuerst, damit das Ergebnis auf den Seiten sichtbar ist.
		$ids = array_map( 'intval', wp_list_pluck( $result->open_items( 'test' === $mode ? 'uses' : 'id' ), 'id' ) );

		return $ids;
	}

	/**
	 * Warteschlange aus vorgegebenen IDs, nur JPG und PNG.
	 *
	 * @param int[] $ids Anhänge.
	 * @return int[]
	 */
	private static function queue_from_ids( array $ids ) {
		$queue = array();

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $attachment_id ) {
			if ( 'attachment' === get_post_type( $attachment_id ) && isset( Inventory::CONVERTIBLE[ (string) get_post_mime_type( $attachment_id ) ] ) ) {
				$queue[] = $attachment_id;
			}
		}

		return $queue;
	}

	/**
	 * Führt Schritte aus, bis die Zeit um ist, ein Paket fertig ist oder der Lauf endet.
	 *
	 * @param float $budget Sekunden.
	 * @return array|\WP_Error Lauf.
	 */
	public static function step( $budget ) {
		$run = self::fresh();

		if ( null === $run ) {
			return new \WP_Error( 'akwu_no_run', __( 'Es läuft keine Umwandlung.', 'akuma-webp-umwandler' ) );
		}

		if ( ! in_array( $run['status'], array( 'running', 'cancelling' ), true ) ) {
			return $run;
		}

		// Etwas länger als das Zeitlimit des Schritts, damit eine abgestürzte Anfrage nicht lange blockiert.
		if ( ! Lock::acquire( 'convert', (int) ceil( $budget ) + 150 ) ) {
			return new \WP_Error( 'akwu_locked', __( 'Ein anderes Fenster arbeitet gerade an diesem Lauf. Bitte kurz warten.', 'akuma-webp-umwandler' ) );
		}

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( (int) ceil( $budget ) + 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Auf manchen Hostings gesperrt.
			}
			wp_raise_memory_limit( 'image' );

			$deadline = microtime( true ) + (float) $budget;

			switch ( $run['phase'] ) {
				case 'convert':
					self::step_convert( $run, $deadline );
					break;
				case 'finalize':
					self::step_finalize( $run );
					break;
				case 'verify':
					self::step_verify( $run, $deadline );
					break;
				case 'rollback':
					self::step_rollback( $run, $deadline );
					break;
			}

			self::save( self::merge_status( $run ) );
		} finally {
			Lock::release();
		}

		return self::current();
	}

	/**
	 * Übernimmt Pausieren oder Abbrechen, die während des Schritts aus einem anderen Request kamen.
	 *
	 * @param array $run Lauf nach dem Schritt.
	 * @return array
	 */
	private static function merge_status( array $run ) {
		$latest = self::fresh();

		if ( null === $latest || $latest['id'] !== $run['id'] ) {
			return $run;
		}

		if ( 'cancelling' === $latest['status'] && ! in_array( $run['status'], array( 'cancelling', 'cancelled' ), true ) ) {
			$run['status'] = 'cancelling';
			$run['phase']  = 'rollback';
		} elseif ( 'paused' === $latest['status'] && 'running' === $run['status'] ) {
			$run['status'] = 'paused';
		}

		return $run;
	}

	/**
	 * Lauf direkt aus der Datenbank, am Objekt-Cache vorbei. Ein anderer Request kann ihn geändert haben.
	 *
	 * @return array|null
	 */
	private static function fresh() {
		wp_cache_delete( self::OPTION, 'options' );

		return self::current();
	}

	/**
	 * Ein Paket umwandeln und die Verweise für das Paket ersetzen.
	 *
	 * @param array $run      Lauf.
	 * @param float $deadline Zeitpunkt, ab dem kein neues Bild mehr begonnen wird.
	 * @return void
	 */
	private static function step_convert( array &$run, $deadline ) {
		$started = microtime( true );

		// Nach einem Abbruch mitten in einer Umwandlung: Anhang wiederherstellen, einmal neu versuchen.
		// Bricht dasselbe Bild wieder ab (Speicher, Zeitlimit), wird es übersprungen, damit der Lauf weitergeht.
		foreach ( Log_Table::rows( $run['id'], array( 'working' ) ) as $row ) {
			Rollback::restore_attachment( $row );
			Log_Table::update(
				$row['id'],
				(int) $row['attempts'] >= self::MAX_ATTEMPTS
					? array(
						'status'  => 'error',
						'message' => __( 'Die Umwandlung brach wiederholt ab, vermutlich zu wenig Arbeitsspeicher oder Zeit. Bild bleibt im Original.', 'akuma-webp-umwandler' ),
					)
					: array(
						'status'  => 'pending',
						'message' => __( 'Nach Unterbrechung zurückgesetzt, neuer Versuch.', 'akuma-webp-umwandler' ),
					)
			);
		}

		// Bereits umgestellte Bilder, deren Verweise noch offen sind (Abbruch nach dem Umstellen).
		$converted = Log_Table::rows( $run['id'], array( 'converted' ) );
		$batch     = max( 1, (int) $run['settings']['batch_size'] );

		$ready = count( $converted );
		while ( $ready < $batch && microtime( true ) < $deadline ) {
			$next = Log_Table::next_pending( $run['id'], 1 );
			if ( empty( $next ) ) {
				break;
			}

			$row = Converter::convert( $next[0], $run['settings'] );
			if ( 'converted' === $row['status'] ) {
				$converted[] = $row;
				++$ready;
			}
		}

		if ( $converted ) {
			self::replace_references( $converted );
			++$run['batches'];
		}

		$run['seconds'] += microtime( true ) - $started;

		$counts = Log_Table::counts( $run['id'] );
		if ( 0 === $counts['pending'] && 0 === $counts['working'] && 0 === $counts['converted'] ) {
			$run['phase'] = 'finalize';
		}
	}

	/**
	 * Ersetzt die Verweise für umgestellte Bilder und schließt deren Zeilen ab.
	 *
	 * @param array[] $rows Zeilen mit Status converted.
	 * @return void
	 */
	private static function replace_references( array $rows ) {
		$map    = array();
		$owners = array();

		foreach ( $rows as $row ) {
			foreach ( $row['url_map'] as $from => $to ) {
				$map[ $from ]    = $to;
				$owners[ $from ] = (int) $row['attachment_id'];
			}
		}

		$result = ( new Replacer( new Url_Matcher( Attachment_Files::baseurl() ) ) )->run( $map, $owners );

		foreach ( $rows as $row ) {
			$attachment_id = (int) $row['attachment_id'];
			Log_Table::update(
				$row['id'],
				array(
					'status'       => 'done',
					'replacements' => isset( $result['per_attachment'][ $attachment_id ] ) ? $result['per_attachment'][ $attachment_id ] : 0,
					'places'       => isset( $result['places'][ $attachment_id ] ) ? $result['places'][ $attachment_id ] : array(),
				)
			);
		}
	}

	/**
	 * Elementor-CSS neu erzeugen, Caches leeren, dann Gegenprobe.
	 *
	 * @param array $run Lauf.
	 * @return void
	 */
	private static function step_finalize( array &$run ) {
		$run['elementor'] = Cache_Purger::elementor();
		$run['purged']    = Cache_Purger::purge_all();
		$run['phase']     = 'verify';
		$run['verify']    = Verifier::start();
	}

	/**
	 * Gegenprobe: Gibt es noch Verweise auf die alten Dateien?
	 *
	 * Stellen mit Warnung (CSS, Snippets, Theme) sind erwartet, alle anderen sind unerwartet
	 * und landen unter „Bitte prüfen“.
	 *
	 * @param array $run      Lauf.
	 * @param float $deadline Zeitpunkt.
	 * @return void
	 */
	private static function step_verify( array &$run, $deadline ) {
		Verifier::step( $run['verify'], $deadline );

		if ( Verifier::finished( $run['verify'] ) ) {
			$run['leftovers']      = $run['verify']['hits'];
			$run['verify']['hits'] = array();
			$run['verify']['ids']  = array();
			$run['phase']          = 'done';
			$run['status']         = 'done';
			$run['finished']       = time();
		}
	}

	/**
	 * Abbrechen: umgewandelte Bilder dieses Laufs paketweise zurücknehmen.
	 *
	 * @param array $run      Lauf.
	 * @param float $deadline Zeitpunkt.
	 * @return void
	 */
	private static function step_rollback( array &$run, $deadline ) {
		foreach ( Log_Table::rows( $run['id'], array( 'working' ) ) as $row ) {
			Rollback::restore_attachment( $row );
			Log_Table::update( $row['id'], array( 'status' => 'pending' ) );
		}

		$matcher = new Url_Matcher( Attachment_Files::baseurl() );
		$batch   = max( 1, (int) $run['settings']['batch_size'] );

		while ( microtime( true ) < $deadline ) {
			$rows = Log_Table::rows( $run['id'], array( 'done', 'converted' ), $batch );
			if ( empty( $rows ) ) {
				break;
			}

			$result = Rollback::rollback_rows( $rows, $matcher );
			foreach ( $result['errors'] as $attachment_id => $message ) {
				foreach ( $rows as $row ) {
					if ( (int) $row['attachment_id'] === (int) $attachment_id ) {
						Log_Table::update(
							$row['id'],
							array(
								'status'  => 'error',
								'message' => $message,
							)
						);
					}
				}
			}
		}

		$left = Log_Table::rows( $run['id'], array( 'done', 'converted' ), 1 );
		if ( empty( $left ) ) {
			foreach ( Log_Table::rows( $run['id'], array( 'pending' ) ) as $row ) {
				Log_Table::update(
					$row['id'],
					array(
						'status'  => 'cancelled',
						'message' => __( 'Lauf abgebrochen, nicht umgewandelt.', 'akuma-webp-umwandler' ),
					)
				);
			}
			$run['elementor'] = Cache_Purger::elementor();
			$run['purged']    = Cache_Purger::purge_all();
			$run['phase']     = 'done';
			$run['status']    = 'cancelled';
			$run['finished']  = time();
		}
	}

	/**
	 * Pausiert den Lauf. Der nächste Schritt wartet, bis er fortgesetzt wird.
	 *
	 * @return array|\WP_Error
	 */
	public static function pause() {
		return self::set_status( array( 'running' ), 'paused' );
	}

	/**
	 * Setzt einen pausierten Lauf fort.
	 *
	 * @return array|\WP_Error
	 */
	public static function resume() {
		return self::set_status( array( 'paused' ), 'running' );
	}

	/**
	 * Bricht den Lauf ab und nimmt die Umwandlungen dieses Laufs zurück.
	 *
	 * @return array|\WP_Error
	 */
	public static function cancel() {
		$run = self::set_status( array( 'running', 'paused' ), 'cancelling' );

		if ( ! is_wp_error( $run ) ) {
			$run['phase'] = 'rollback';
			self::save( $run );
		}

		return $run;
	}

	/**
	 * Ändert den Status, wenn der aktuelle passt.
	 *
	 * @param string[] $from Erlaubte Ausgangszustände.
	 * @param string   $to   Neuer Status.
	 * @return array|\WP_Error
	 */
	private static function set_status( array $from, $to ) {
		$run = self::fresh();

		if ( null === $run || ! in_array( $run['status'], $from, true ) ) {
			return new \WP_Error( 'akwu_status', __( 'Das geht im aktuellen Zustand der Umwandlung nicht.', 'akuma-webp-umwandler' ) );
		}

		$run['status'] = $to;
		self::save( $run );

		return $run;
	}

	/**
	 * Fortschritt und Zahlen für Oberfläche und WP-CLI.
	 *
	 * @param array $run Lauf.
	 * @return array
	 */
	public static function progress( array $run ) {
		$counts    = Log_Table::counts( $run['id'] );
		$processed = $counts['done'] + $counts['skipped'] + $counts['error'] + $counts['rolled_back'];
		$total     = max( 1, (int) $run['total'] );
		$batch     = max( 1, (int) $run['settings']['batch_size'] );

		switch ( $run['phase'] ) {
			case 'convert':
				$percent = (int) floor( 90 * $processed / $total );
				break;
			case 'finalize':
				$percent = 92;
				break;
			case 'verify':
				$percent = 93 + (int) floor( 6 * Verifier::share( $run['verify'] ) );
				break;
			case 'rollback':
				$percent = (int) floor( 100 * $counts['rolled_back'] / max( 1, $counts['rolled_back'] + $counts['done'] + $counts['converted'] ) );
				break;
			default:
				$percent = 100;
		}

		$remaining = $counts['pending'] + $counts['working'] + $counts['converted'];
		$eta       = ( $processed > 0 && $remaining > 0 ) ? (int) ceil( $run['seconds'] / $processed * $remaining ) : 0;

		return array(
			'status'       => $run['status'],
			'phase'        => $run['phase'],
			'percent'      => min( 100, $percent ),
			'total'        => (int) $run['total'],
			'processed'    => $processed,
			'converted'    => $counts['done'],
			'skipped'      => $counts['skipped'],
			'errors'       => $counts['error'],
			'rolled_back'  => $counts['rolled_back'],
			'pending'      => $remaining,
			'saved'        => max( 0, $counts['bytes_before'] - $counts['bytes_after'] ),
			'replacements' => $counts['replacements'],
			'batch'        => (int) $run['batches'],
			'batches'      => (int) ceil( $total / $batch ),
			'eta'          => $eta,
			'ids_changed'  => self::ids_changed( $run ),
			'finished'     => in_array( $run['status'], array( 'done', 'cancelled' ), true ),
		);
	}

	/**
	 * Kontrollwert: Bei wie vielen umgewandelten Bildern stimmt die ID nicht mehr?
	 *
	 * Geprüft wird, ob der Anhang mit derselben ID noch existiert und auf die WebP-Datei zeigt.
	 *
	 * @param array $run Lauf.
	 * @return int Sollte immer 0 sein.
	 */
	public static function ids_changed( array $run ) {
		global $wpdb;

		$expected = Log_Table::new_files( $run['id'], 'done' );
		$changed  = 0;

		// Wenige Abfragen statt einer je Bild, auch bei tausenden Bildern.
		foreach ( array_chunk( array_keys( $expected ), 500 ) as $chunk ) {
			$list = implode( ',', array_map( 'intval', $chunk ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs als int.
			$mimes = $wpdb->get_results( "SELECT ID, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN ({$list})", OBJECT_K );
			$files = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id IN ({$list})", OBJECT_K );
			// phpcs:enable

			foreach ( $chunk as $attachment_id ) {
				if ( ! isset( $mimes[ $attachment_id ], $files[ $attachment_id ] )
					|| 'image/webp' !== $mimes[ $attachment_id ]->post_mime_type
					|| (string) $files[ $attachment_id ]->meta_value !== $expected[ $attachment_id ] ) {
					++$changed;
				}
			}
		}

		return $changed;
	}

	/**
	 * Letzte abgeschlossene Zeilen für das Live-Protokoll, neueste zuerst.
	 *
	 * @param array $run   Lauf.
	 * @param int   $limit Anzahl.
	 * @return array[]
	 */
	public static function recent( array $run, $limit = 8 ) {
		$entries = array();

		foreach ( Log_Table::rows( $run['id'], array( 'done', 'skipped', 'error', 'rolled_back', 'cancelled' ), $limit, 'DESC' ) as $row ) {
			$old_name = '' !== (string) $row['old_file'] ? wp_basename( $row['old_file'] ) : wp_basename( (string) get_post_meta( $row['attachment_id'], '_wp_attached_file', true ) );

			$entries[] = array(
				'id'           => (int) $row['attachment_id'],
				'status'       => $row['status'],
				'file'         => $old_name,
				'new_file'     => '' !== (string) $row['new_file'] ? wp_basename( $row['new_file'] ) : '',
				'before'       => (int) $row['bytes_before'],
				'after'        => (int) $row['bytes_after'],
				'replacements' => (int) $row['replacements'],
				'message'      => (string) $row['message'],
			);
		}

		return $entries;
	}

	/**
	 * Speichert den Lauf, ohne Autoload.
	 *
	 * @param array $run Lauf.
	 * @return void
	 */
	private static function save( array $run ) {
		update_option( self::OPTION, $run, false );
	}
}
