<?php
/**
 * Anzeige eines Umwandlungslaufs.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Bereitet einen Lauf für Seite, REST-Antwort und Admin-Leiste auf.
 * Texte und HTML entstehen hier, damit sie übersetzbar und sauber maskiert sind.
 */
final class Run_Presenter {

	/**
	 * Daten für die Anzeige.
	 *
	 * @param array $run Lauf.
	 * @return array
	 */
	public static function payload( array $run ) {
		$progress = Conversion::progress( $run );

		return array_merge(
			$progress,
			array(
				'eyebrow'     => self::eyebrow( $run ),
				'headline'    => self::headline( $run, $progress ),
				'accent'      => self::accent( $run, $progress ),
				'batch_label' => self::batch_text( $run, $progress ),
				'tiles'       => self::tiles( $progress ),
				'steps_html'  => self::render( 'part-run-steps', array( 'steps' => self::steps( $run ) ) ),
				'log_html'    => self::render( 'part-run-log', array( 'entries' => Conversion::recent( $run ) ) ),
				/* translators: %s: Fortschritt in Prozent. */
				'bar_label'   => sprintf( __( 'WebP-Umwandler · %s %%', 'akuma-webp-umwandler' ), $progress['percent'] ),
			)
		);
	}

	/**
	 * Kleine Zeile über der Überschrift.
	 *
	 * @param array $run Lauf.
	 * @return string
	 */
	public static function eyebrow( array $run ) {
		$labels = array(
			'running'    => __( 'Umwandlung läuft', 'akuma-webp-umwandler' ),
			'paused'     => __( 'Umwandlung pausiert', 'akuma-webp-umwandler' ),
			'cancelling' => __( 'Umwandlung wird zurückgesetzt', 'akuma-webp-umwandler' ),
			'done'       => __( 'Umwandlung abgeschlossen', 'akuma-webp-umwandler' ),
			'cancelled'  => __( 'Umwandlung abgebrochen', 'akuma-webp-umwandler' ),
		);

		return isset( $labels[ $run['status'] ] ) ? $labels[ $run['status'] ] : __( 'Umwandlung', 'akuma-webp-umwandler' );
	}

	/**
	 * Überschrift, z. B. „201 von 347 Bildern.“
	 *
	 * @param array $run      Lauf.
	 * @param array $progress Fortschritt.
	 * @return string
	 */
	private static function headline( array $run, array $progress ) {
		if ( 'cancelled' === $run['status'] ) {
			return __( 'Abgebrochen.', 'akuma-webp-umwandler' );
		}
		if ( 'done' === $run['status'] ) {
			return __( 'Fertig.', 'akuma-webp-umwandler' );
		}
		if ( 'cancelling' === $run['status'] ) {
			return __( 'Wird zurückgesetzt.', 'akuma-webp-umwandler' );
		}

		/* translators: 1: bearbeitete Bilder, 2: alle Bilder des Laufs. */
		return sprintf( _n( '%1$s von %2$s Bild.', '%1$s von %2$s Bildern.', $progress['total'], 'akuma-webp-umwandler' ), Format::number( $progress['processed'] ), Format::number( $progress['total'] ) );
	}

	/**
	 * Zweiter Satz der Überschrift.
	 *
	 * @param array $run      Lauf.
	 * @param array $progress Fortschritt.
	 * @return string
	 */
	private static function accent( array $run, array $progress ) {
		switch ( $run['status'] ) {
			case 'done':
				/* translators: %s: eingesparte Größe. */
				return sprintf( __( '%s weniger Ladegewicht.', 'akuma-webp-umwandler' ), Format::bytes( $progress['saved'] ) );
			case 'cancelled':
				return __( 'Alle Bilder dieses Laufs sind wieder im Original.', 'akuma-webp-umwandler' );
			case 'paused':
				return __( 'Pausiert.', 'akuma-webp-umwandler' );
			case 'cancelling':
				return __( 'Die Bilder dieses Laufs gehen zurück ins Original.', 'akuma-webp-umwandler' );
		}

		if ( 'convert' !== $run['phase'] ) {
			return __( 'Fast fertig.', 'akuma-webp-umwandler' );
		}
		if ( $progress['eta'] <= 0 ) {
			return __( 'Läuft.', 'akuma-webp-umwandler' );
		}
		if ( $progress['eta'] < 60 ) {
			return __( 'Noch weniger als eine Minute.', 'akuma-webp-umwandler' );
		}

		$minutes = (int) ceil( $progress['eta'] / 60 );

		/* translators: %s: Minuten. */
		return sprintf( _n( 'Noch etwa %s Minute.', 'Noch etwa %s Minuten.', $minutes, 'akuma-webp-umwandler' ), Format::number( $minutes ) );
	}

	/**
	 * Zeile unter dem Fortschrittsbalken.
	 *
	 * @param array $run      Lauf.
	 * @param array $progress Fortschritt.
	 * @return string
	 */
	public static function batch_text( array $run, array $progress ) {
		$labels = array(
			'finalize' => __( 'Elementor-CSS und Cache werden erneuert', 'akuma-webp-umwandler' ),
			'verify'   => __( 'Gegenprobe läuft', 'akuma-webp-umwandler' ),
			'rollback' => __( 'Rückgängig läuft', 'akuma-webp-umwandler' ),
			'done'     => __( 'Abgeschlossen', 'akuma-webp-umwandler' ),
		);

		if ( isset( $labels[ $run['phase'] ] ) ) {
			return $labels[ $run['phase'] ];
		}

		/* translators: 1: aktuelles Paket, 2: Pakete gesamt. */
		return sprintf( __( 'Paket %1$s von %2$s', 'akuma-webp-umwandler' ), Format::number( min( $progress['batches'], $progress['batch'] + 1 ) ), Format::number( $progress['batches'] ) );
	}

	/**
	 * Kacheln: Eingespart, Umgewandelt, Übersprungen, Bild-IDs verändert.
	 *
	 * @param array $progress Fortschritt.
	 * @return array<string, string>
	 */
	private static function tiles( array $progress ) {
		return array(
			'saved'       => Format::bytes( $progress['saved'] ),
			'converted'   => Format::number( $progress['converted'] ),
			'skipped'     => Format::number( $progress['skipped'] + $progress['errors'] ),
			'ids_changed' => Format::number( $progress['ids_changed'] ),
		);
	}

	/**
	 * Schritte des Ablaufs mit Zustand.
	 *
	 * @param array $run Lauf.
	 * @return array[] Liste mit label und state (wait, run, with, done).
	 */
	public static function steps( array $run ) {
		if ( in_array( $run['status'], array( 'cancelling', 'cancelled' ), true ) ) {
			$done = 'cancelled' === $run['status'];

			return array(
				array(
					'label' => __( 'Bilder zurücksetzen', 'akuma-webp-umwandler' ),
					'state' => $done ? 'done' : 'run',
				),
				array(
					'label' => __( 'Verweise zurücksetzen', 'akuma-webp-umwandler' ),
					'state' => $done ? 'done' : 'with',
				),
				array(
					'label' => __( 'Elementor-CSS neu erzeugen', 'akuma-webp-umwandler' ),
					'state' => $done ? 'done' : 'wait',
				),
				array(
					'label' => __( 'Cache leeren', 'akuma-webp-umwandler' ),
					'state' => $done ? 'done' : 'wait',
				),
			);
		}

		$order = array( 'convert', 'finalize', 'verify', 'done' );
		$index = array_search( $run['phase'], $order, true );
		$index = false === $index ? 0 : $index;

		$state = static function ( $step_index ) use ( $index ) {
			if ( $step_index < $index ) {
				return 'done';
			}
			return $step_index === $index ? 'run' : 'wait';
		};

		return array(
			array(
				'label' => __( 'Bilder umwandeln', 'akuma-webp-umwandler' ),
				'state' => $state( 0 ),
			),
			array(
				'label' => __( 'Verweise ersetzen', 'akuma-webp-umwandler' ),
				'state' => 'run' === $state( 0 ) ? 'with' : $state( 0 ),
			),
			array(
				'label' => __( 'Elementor-CSS neu erzeugen', 'akuma-webp-umwandler' ),
				'state' => $state( 1 ),
			),
			array(
				'label' => __( 'Cache leeren', 'akuma-webp-umwandler' ),
				'state' => $state( 1 ),
			),
			array(
				'label' => __( 'Gegenprobe', 'akuma-webp-umwandler' ),
				'state' => $state( 2 ),
			),
		);
	}

	/**
	 * Rendert ein Template in einen String.
	 *
	 * @param string $view Template.
	 * @param array  $data Daten.
	 * @return string
	 */
	private static function render( $view, array $data ) {
		ob_start();
		View::render( $view, $data );

		return (string) ob_get_clean();
	}
}
