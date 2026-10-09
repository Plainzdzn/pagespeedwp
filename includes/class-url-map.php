<?php
/**
 * Zuordnung alte Datei → neue Datei für ein umgewandeltes Bild.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Baut aus den Dateien vor und nach der Umwandlung die Ersetzungsliste.
 *
 * Regeln (Briefing §4.3, Punkt 7):
 * - Volle Auflösung (Original vor dem Verkleinern, sonst die angehängte Datei) → volle Auflösung.
 * - `-scaled`-Datei → neue `-scaled`-Datei, sonst volle Auflösung.
 * - Jede Größe über ihren Namen, nicht über Pixelmaße. Fehlt eine Größe danach,
 *   zeigt sie auf die neue angehängte Datei und wird gemeldet. Der Converter erzeugt
 *   alte Größen deshalb vorher nach und bricht ab, wenn trotzdem eine fehlt.
 *
 * Kommt ohne WordPress aus und ist dadurch direkt testbar.
 */
final class Url_Map {

	/**
	 * Baut die Zuordnung.
	 *
	 * @param array<string, string> $before Dateien vorher, Schlüssel wie Attachment_Files::from_meta().
	 * @param array<string, string> $after  Dateien nachher, gleiche Schlüssel.
	 * @return array{map: array<string, string>, missing: string[]} Alter Pfad => neuer Pfad, fehlende Größennamen.
	 */
	public static function build( array $before, array $after ) {
		$map     = array();
		$missing = array();

		$old_fullres = isset( $before['original_image'] ) ? $before['original_image'] : $before['full'];
		$new_fullres = isset( $after['original_image'] ) ? $after['original_image'] : $after['full'];

		$map[ $old_fullres ] = $new_fullres;

		if ( isset( $before['original_image'] ) ) {
			$map[ $before['full'] ] = isset( $after['original_image'] ) ? $after['full'] : $new_fullres;
		}

		foreach ( $before as $name => $path ) {
			if ( 'full' === $name || 'original_image' === $name || isset( $map[ $path ] ) ) {
				continue;
			}

			if ( isset( $after[ $name ] ) ) {
				$map[ $path ] = $after[ $name ];
			} else {
				$map[ $path ] = $after['full'];
				$missing[]    = (string) $name;
			}
		}

		// Nichts auf sich selbst abbilden.
		foreach ( $map as $from => $to ) {
			if ( $from === $to ) {
				unset( $map[ $from ] );
			}
		}

		return array(
			'map'     => $map,
			'missing' => $missing,
		);
	}

	/**
	 * Umkehrung für das Rückgängigmachen. Mehrere alte Pfade auf denselben neuen
	 * Pfad (fehlende Größen) gehen auf die volle Auflösung zurück.
	 *
	 * @param array<string, string> $map         Alter Pfad => neuer Pfad.
	 * @param string                $old_fullres Alter Pfad der vollen Auflösung.
	 * @return array<string, string> Neuer Pfad => alter Pfad.
	 */
	public static function reverse( array $map, $old_fullres ) {
		$reverse = array();

		foreach ( $map as $from => $to ) {
			if ( ! isset( $reverse[ $to ] ) || $from === $old_fullres ) {
				$reverse[ $to ] = $from;
			}
		}

		return $reverse;
	}
}
