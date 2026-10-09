<?php
/**
 * Gegenprobe: Wo stehen noch alte Adressen umgewandelter Bilder?
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Durchsucht Datenbank und Theme in Schritten nach alten Adressen aller umgewandelten Bilder
 * (Briefing §4.4, Gegenprobe). Läuft am Ende jeder Umwandlung und vor dem Löschen der Originale.
 *
 * Die Fundstellen für die Anzeige sind gekappt, die Menge der betroffenen Bilder nicht:
 * Sie entscheidet, welche Originale nicht gelöscht werden dürfen.
 */
final class Verifier {

	/**
	 * Quellen in Reihenfolge. Verweise über die ID brauchen keinen Ersatz und zählen nicht.
	 */
	const SOURCES = array( 'posts', 'postmeta', 'options', 'termmeta', 'snippets', 'theme' );

	/**
	 * Gespeicherte Fundstellen für die Anzeige.
	 */
	const MAX_HITS = 500;

	/**
	 * Neuer Stand.
	 *
	 * @return array{source: int, cursor: int, ids: array<int, bool>, hits: array[]}
	 */
	public static function start() {
		return array(
			'source' => 0,
			'cursor' => 0,
			'ids'    => array(),
			'hits'   => array(),
		);
	}

	/**
	 * Fertig?
	 *
	 * @param array $state Stand.
	 * @return bool
	 */
	public static function finished( array $state ) {
		return $state['source'] >= count( self::SOURCES );
	}

	/**
	 * Anteil erledigt, 0 bis 1.
	 *
	 * @param array $state Stand.
	 * @return float
	 */
	public static function share( array $state ) {
		return min( 1, $state['source'] / count( self::SOURCES ) );
	}

	/**
	 * Sucht bis zum Zeitlimit weiter. Am Ende werden die Fundstellen für den Bericht gespeichert.
	 *
	 * @param array $state    Stand, wird fortgeschrieben.
	 * @param float $deadline Zeitpunkt, ab dem nichts Neues mehr begonnen wird.
	 * @return void
	 */
	public static function step( array &$state, $deadline ) {
		// Alle umgewandelten Bilder, auch aus früheren Läufen (z. B. „Erst 10 testen“).
		$index = array();
		foreach ( Report::existing( Log_Table::latest_by_attachment( array(), array( 'done' ) ) ) as $row ) {
			foreach ( array_keys( (array) $row['url_map'] ) as $old_path ) {
				$index[ $old_path ] = (int) $row['attachment_id'];
			}
		}

		if ( empty( $index ) ) {
			$state['source'] = count( self::SOURCES );
		}

		$converted = array_flip( $index );
		$finder    = new Usage_Finder( new Url_Matcher( Attachment_Files::baseurl() ), $index );

		while ( ! self::finished( $state ) && microtime( true ) < $deadline ) {
			$source = self::SOURCES[ $state['source'] ];

			if ( 'theme' === $source ) {
				$files = Usage_Finder::theme_files();
				$chunk = array_slice( $files, $state['cursor'], Scanner::THEME_BATCH );
				$hits  = $finder->scan_theme_files( $chunk );

				$state['cursor'] += count( $chunk );
				if ( $state['cursor'] >= count( $files ) ) {
					$state['source'] = count( self::SOURCES );
				}
			} else {
				$result = $finder->scan( $source, $state['cursor'], Scanner::USAGE_BATCH[ $source ] );
				$hits   = $result['hits'];

				$state['cursor'] = $result['cursor'];
				if ( $result['done'] ) {
					++$state['source'];
					$state['cursor'] = 0;
				}
			}

			foreach ( $hits as $hit ) {
				if ( 'url' !== $hit['by'] || ! isset( $converted[ $hit['attachment'] ] ) ) {
					continue;
				}
				$state['ids'][ (int) $hit['attachment'] ] = true;
				if ( count( $state['hits'] ) < self::MAX_HITS ) {
					$state['hits'][] = $hit;
				}
			}
		}

		if ( self::finished( $state ) ) {
			Report::save_leftovers( $state['hits'], array_keys( $state['ids'] ) );
		}
	}
}
