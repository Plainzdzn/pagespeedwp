<?php
/**
 * WP-CLI-Befehle.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Wandelt PNG und JPG der Mediathek in WebP um, mit derselben Attachment-ID.
 *
 * Nutzt dieselbe Kernlogik wie die Admin-Oberfläche.
 */
final class Cli {

	/**
	 * Registriert den Befehl `wp akwu`.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'akwu', self::class );
	}

	/**
	 * Bestandsaufnahme: Größen, Verwendung, Warnungen und Hochrechnung. Ändert nichts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Ausgabe der Bildliste.
	 * ---
	 * default: summary
	 * options:
	 *   - summary
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp akwu scan
	 *     wp akwu scan --format=table
	 *
	 * @param array $args       Positionsargumente.
	 * @param array $assoc_args Optionen.
	 * @return void
	 */
	public function scan( $args, $assoc_args ) {
		if ( Conversion::is_active( Conversion::current() ) ) {
			WP_CLI::error( __( 'Während einer Umwandlung ist kein neuer Scan möglich.', 'akuma-webp-umwandler' ) );
		}
		if ( null !== Lock::holder() ) {
			WP_CLI::error( __( 'Gerade läuft schon ein Scan oder eine Umwandlung.', 'akuma-webp-umwandler' ) );
		}

		Scanner::start();
		$last = '';

		do {
			$state = Scanner::step( 30 );
			if ( is_wp_error( $state ) ) {
				WP_CLI::error( $state->get_error_message() );
			}
			$progress = Scanner::progress( $state );
			if ( $progress['label'] !== $last ) {
				WP_CLI::log( sprintf( '%3d %%  %s', $progress['percent'], $progress['label'] ) );
				$last = $progress['label'];
			}
		} while ( ! $progress['finished'] );

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'summary' );

		if ( 'summary' === $format ) {
			$this->print_summary( new Scan_Result( $state ) );
			return;
		}

		$rows = array();
		foreach ( ( new Scan_Result( $state ) )->items( 'all', 'bytes' ) as $item ) {
			$rows[] = array(
				'ID'        => $item['id'],
				'Datei'     => $item['file'],
				'Format'    => $item['kind'],
				'Status'    => $item['status'],
				'Bytes'     => $item['bytes'],
				'Geschätzt' => $item['estimate'],
				'Verweise'  => $item['uses'],
				'Warnungen' => count( $item['warnings'] ),
			);
		}
		WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows ? $rows[0] : array( 'ID' => 0 ) ) );
	}

	/**
	 * Wandelt die bereiten Bilder in WebP um, mit derselben Attachment-ID, und zieht alle Verweise mit.
	 *
	 * Ohne `--ids` gilt die Liste aus dem letzten Scan (`wp akwu scan`). Ein offener Lauf aus der
	 * Oberfläche lässt sich mit `--resume` hier zu Ende führen.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Nur anzeigen, welche Bilder umgewandelt würden. Ändert nichts.
	 *
	 * [--limit=<anzahl>]
	 * : Höchstens so viele Bilder.
	 *
	 * [--ids=<ids>]
	 * : Nur diese Anhänge, durch Komma getrennt.
	 *
	 * [--test]
	 * : Testlauf mit den 10 meistgenutzten Bildern, wie „Erst 10 testen“.
	 *
	 * [--resume]
	 * : Einen offenen oder pausierten Lauf fortsetzen.
	 *
	 * [--yes]
	 * : Die Frage nach dem Backup überspringen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp akwu convert --dry-run
	 *     wp akwu convert --test --yes
	 *     wp akwu convert --ids=12,34,56
	 *     wp akwu convert --limit=50
	 *
	 * @param array $args       Positionsargumente.
	 * @param array $assoc_args Optionen.
	 * @return void
	 */
	public function convert( $args, $assoc_args ) {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$resume  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'resume', false );
		$limit   = absint( WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 ) );
		$ids     = array_filter( array_map( 'absint', explode( ',', (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'ids', '' ) ) ) );
		$mode    = $ids ? 'ids' : ( WP_CLI\Utils\get_flag_value( $assoc_args, 'test', false ) ? 'test' : 'all' );
		$run     = Conversion::current();

		if ( Conversion::is_active( $run ) ) {
			if ( ! $resume ) {
				WP_CLI::error( __( 'Es ist noch eine Umwandlung offen. Mit --resume fortsetzen oder in der Oberfläche abbrechen.', 'akuma-webp-umwandler' ) );
			}
			if ( 'paused' === $run['status'] ) {
				Conversion::resume();
			}
			$this->run_until_done();
			return;
		}

		if ( $resume ) {
			WP_CLI::error( __( 'Es ist keine Umwandlung offen.', 'akuma-webp-umwandler' ) );
		}

		$queue = Conversion::queue( $mode, $ids, $limit );
		if ( is_wp_error( $queue ) ) {
			WP_CLI::error( $queue->get_error_message() );
		}

		if ( $dry_run ) {
			$this->print_queue( $queue );
			return;
		}

		$check = new System_Check();
		if ( ! $check->can_start() ) {
			WP_CLI::error( __( 'Die Systemprüfung meldet ein Problem. Bitte zuerst in der Oberfläche unter Systemprüfung nachsehen.', 'akuma-webp-umwandler' ) );
		}

		/* translators: %s: Anzahl Bilder. */
		WP_CLI::log( sprintf( _n( '%s Bild wird umgewandelt.', '%s Bilder werden umgewandelt.', count( $queue ), 'akuma-webp-umwandler' ), Format::number( count( $queue ) ) ) );
		WP_CLI::confirm( __( 'Ist ein Backup von Datenbank und Uploads erstellt?', 'akuma-webp-umwandler' ), $assoc_args );

		$run = Conversion::start( $mode, $ids, $limit );
		if ( is_wp_error( $run ) ) {
			WP_CLI::error( $run->get_error_message() );
		}

		$this->run_until_done();
	}

	/**
	 * Fordert Schritte an, bis der Lauf endet, und zeigt den Fortschritt je Paket.
	 *
	 * @return void
	 */
	private function run_until_done() {
		$last = '';

		do {
			$run = Conversion::step( 30 );
			if ( is_wp_error( $run ) ) {
				WP_CLI::error( $run->get_error_message() );
			}

			$progress = Conversion::progress( $run );
			$line     = sprintf( '%3d %%  %s · %s/%s', $progress['percent'], Run_Presenter::batch_text( $run, $progress ), Format::number( $progress['processed'] ), Format::number( $progress['total'] ) );
			if ( $line !== $last ) {
				WP_CLI::log( $line );
				$last = $line;
			}

			if ( 'paused' === $run['status'] ) {
				WP_CLI::error( __( 'Der Lauf wurde in der Oberfläche pausiert. Mit --resume fortsetzen.', 'akuma-webp-umwandler' ) );
			}
		} while ( ! $progress['finished'] );

		$this->print_run( $run, $progress );
	}

	/**
	 * Liste für `--dry-run`.
	 *
	 * @param int[] $queue Anhang-IDs.
	 * @return void
	 */
	private function print_queue( array $queue ) {
		$result = Scan_Result::load();
		$rows   = array();
		$bytes  = 0;
		$after  = 0;

		foreach ( $queue as $attachment_id ) {
			$item = null === $result ? null : $result->item( $attachment_id );

			$rows[] = array(
				'ID'        => $attachment_id,
				'Datei'     => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
				'Heute'     => null === $item ? '–' : Format::bytes( $item['bytes'] ),
				'Geschätzt' => null === $item ? '–' : Format::bytes( $item['estimate'] ),
				'Verweise'  => null === $item ? '–' : $item['uses'],
			);

			if ( null !== $item ) {
				$bytes += $item['bytes'];
				$after += $item['estimate'];
			}
		}

		WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );

		if ( $bytes > 0 ) {
			/* translators: 1: Größe heute, 2: geschätzte Größe danach. */
			WP_CLI::log( sprintf( __( 'Heute %1$s, danach etwa %2$s.', 'akuma-webp-umwandler' ), Format::bytes( $bytes ), Format::bytes( $after ) ) );
		}

		/* translators: %s: Anzahl Bilder. */
		WP_CLI::success( sprintf( _n( 'Probelauf: %s Bild würde umgewandelt. Es wurde nichts verändert.', 'Probelauf: %s Bilder würden umgewandelt. Es wurde nichts verändert.', count( $queue ), 'akuma-webp-umwandler' ), Format::number( count( $queue ) ) ) );
	}

	/**
	 * Zusammenfassung nach dem Lauf.
	 *
	 * @param array $run      Lauf.
	 * @param array $progress Fortschritt.
	 * @return void
	 */
	private function print_run( array $run, array $progress ) {
		WP_CLI::log( '' );

		if ( 'cancelled' === $run['status'] ) {
			WP_CLI::warning( __( 'Der Lauf wurde abgebrochen. Alle Bilder dieses Laufs sind wieder im Original.', 'akuma-webp-umwandler' ) );
			return;
		}

		WP_CLI::log( sprintf( 'Umgewandelt:        %s', Format::number( $progress['converted'] ) ) );
		WP_CLI::log( sprintf( 'Übersprungen:       %s', Format::number( $progress['skipped'] ) ) );
		WP_CLI::log( sprintf( 'Fehler:             %s', Format::number( $progress['errors'] ) ) );
		WP_CLI::log( sprintf( 'Eingespart:         %s', Format::bytes( $progress['saved'] ) ) );
		WP_CLI::log( sprintf( 'Verweise ersetzt:   %s', Format::number( $progress['replacements'] ) ) );
		WP_CLI::log( sprintf( 'Bild-IDs verändert: %s', Format::number( $progress['ids_changed'] ) ) );
		WP_CLI::log( sprintf( 'Cache geleert:      %s', implode( ', ', (array) $run['purged'] ) ) );

		foreach ( Log_Table::rows( $run['id'], array( 'error' ) ) as $row ) {
			WP_CLI::warning( sprintf( 'ID %d: %s', $row['attachment_id'], $row['message'] ) );
		}

		if ( $run['leftovers'] ) {
			WP_CLI::log( '' );
			WP_CLI::log( __( 'Gegenprobe, alte Adressen noch gefunden:', 'akuma-webp-umwandler' ) );
			foreach ( $run['leftovers'] as $hit ) {
				WP_CLI::log( sprintf( '- ID %d: %s (%s)', $hit['attachment'], $hit['label'], null === $hit['warning'] ? __( 'nicht ersetzt', 'akuma-webp-umwandler' ) : Scan_Result::warning_label( $hit['warning'] ) . ', ' . __( 'von Hand prüfen', 'akuma-webp-umwandler' ) ) );
			}
		}

		if ( Cache_Purger::needs_raidboxes_hint() ) {
			WP_CLI::warning( __( 'Raidboxes-Cache bitte im Raidboxes-Dashboard leeren.', 'akuma-webp-umwandler' ) );
		}

		WP_CLI::success( __( 'Umwandlung abgeschlossen.', 'akuma-webp-umwandler' ) );
	}

	/**
	 * Gibt die Zusammenfassung eines Scans aus.
	 *
	 * @param Scan_Result $result Ergebnis.
	 * @return void
	 */
	private function print_summary( Scan_Result $result ) {
		$totals = $result->totals();

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Bilder:           %s (+ %s Vorschaugrößen)', Format::number( $totals['images'] ), Format::number( $totals['sizes'] ) ) );
		WP_CLI::log( sprintf( 'Noch nicht WebP:  %s (%s JPG, %s PNG)', Format::number( $totals['convertible']['jpg'] + $totals['convertible']['png'] ), Format::number( $totals['convertible']['jpg'] ), Format::number( $totals['convertible']['png'] ) ) );
		WP_CLI::log( sprintf( 'Bereit:           %s, voraussichtlich übersprungen: %s, Datei fehlt: %s', Format::number( $totals['ready'] ), Format::number( $totals['skip'] ), Format::number( $totals['missing'] ) ) );
		WP_CLI::log( sprintf( 'Größe heute:      %s', Format::bytes( $totals['bytes'] ) ) );
		WP_CLI::log( sprintf( 'Erwartet danach:  %s (%s)', Format::bytes( $totals['after'] ), $result->savings_percent_label() ) );
		WP_CLI::log( sprintf( 'Stichprobe:       %s Bilder', Format::number( $totals['samples'] ) ) );

		$warnings = $result->warnings();
		if ( $warnings ) {
			WP_CLI::log( '' );
			WP_CLI::log( __( 'Bitte prüfen (wird nicht automatisch ersetzt):', 'akuma-webp-umwandler' ) );
			foreach ( $warnings as $warning ) {
				WP_CLI::log( sprintf( '- %s: %s (%s)', $warning['file'], $warning['label'], Scan_Result::warning_label( $warning['warning'] ) ) );
			}
		}

		WP_CLI::success( __( 'Scan abgeschlossen. Es wurde nichts verändert.', 'akuma-webp-umwandler' ) );
	}
}
