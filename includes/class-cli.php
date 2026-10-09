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
