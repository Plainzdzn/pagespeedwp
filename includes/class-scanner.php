<?php
/**
 * Scan: Bestandsaufnahme, Verwendung, Warnungen, Hochrechnung.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Führt den Scan in Schritten aus und speichert Zwischenstand und Ergebnis
 * in der Option `akwu_scan` (nicht automatisch geladen). Ändert nichts an Bildern oder Inhalten.
 *
 * Phasen: inventory → usage → theme → estimate → finalize → done.
 * Admin-UI (REST) und WP-CLI rufen dieselben Schritte auf.
 */
final class Scanner {

	/**
	 * Name der Option.
	 */
	const OPTION = 'akwu_scan';

	/**
	 * Version des gespeicherten Formats. Ältere Ergebnisse werden verworfen.
	 */
	const FORMAT = 1;

	/**
	 * Anhänge pro Bestandsaufnahme-Runde.
	 */
	const INVENTORY_BATCH = 50;

	/**
	 * Zeilen pro Runde je Quelle.
	 */
	const USAGE_BATCH = array(
		'posts'    => 100,
		'postmeta' => 50,
		'options'  => 100,
		'termmeta' => 200,
		'snippets' => 100,
		'featured' => 500,
		'special'  => 1,
	);

	/**
	 * Gespeicherte Fundstellen je Bild (die Gesamtzahl wird immer gezählt).
	 */
	const MAX_PLACES = 25;

	/**
	 * Größe der Stichprobe für die Hochrechnung.
	 */
	const SAMPLES = 20;

	/**
	 * Phasen mit Anzeigenamen, in Reihenfolge.
	 *
	 * @return array<string, string>
	 */
	public static function phases() {
		return array(
			'inventory' => __( 'Bilder erfassen', 'akuma-webp-umwandler' ),
			'usage'     => __( 'Verwendung suchen', 'akuma-webp-umwandler' ),
			'theme'     => __( 'Theme-Dateien prüfen', 'akuma-webp-umwandler' ),
			'estimate'  => __( 'Stichprobe umwandeln', 'akuma-webp-umwandler' ),
			'finalize'  => __( 'Ergebnis berechnen', 'akuma-webp-umwandler' ),
			'done'      => __( 'Fertig', 'akuma-webp-umwandler' ),
		);
	}

	/**
	 * Gespeicherter Stand oder null.
	 *
	 * @return array|null
	 */
	public static function state() {
		$state = get_option( self::OPTION );

		return ( is_array( $state ) && isset( $state['format'] ) && self::FORMAT === $state['format'] ) ? $state : null;
	}

	/**
	 * Liegt ein fertiges Ergebnis vor?
	 *
	 * @return bool
	 */
	public static function is_done() {
		$state = self::state();

		return null !== $state && 'done' === $state['status'];
	}

	/**
	 * Beginnt einen neuen Scan. Ein vorheriges Ergebnis wird ersetzt.
	 *
	 * @return array Stand.
	 */
	public static function start() {
		$state = array(
			'format'   => self::FORMAT,
			'status'   => 'running',
			'started'  => time(),
			'finished' => 0,
			'phase'    => 'inventory',
			'cursor'   => 0,
			'source'   => 0,
			'total'    => Inventory::count(),
			'baseurl'  => Attachment_Files::baseurl(),
			'thumbs'   => Inventory::elementor_thumbs(),
			'items'    => array(),
			'queue'    => array(),
			'samples'  => array(),
			'warnings' => array(),
			'totals'   => array(),
			'settings' => Settings::all(),
		);

		self::save( $state );

		return $state;
	}

	/**
	 * Führt Schritte aus, bis die Zeit um ist oder der Scan fertig.
	 *
	 * @param float $budget Sekunden.
	 * @return array|\WP_Error Stand oder Fehler, wenn gerade ein anderer Lauf aktiv ist.
	 */
	public static function step( $budget ) {
		$state = self::state();

		if ( null === $state || 'running' !== $state['status'] ) {
			return null === $state ? new \WP_Error( 'akwu_no_scan', __( 'Es läuft kein Scan.', 'akuma-webp-umwandler' ) ) : $state;
		}

		if ( ! Lock::acquire( 'scan', (int) ceil( $budget ) + 120 ) ) {
			return new \WP_Error( 'akwu_locked', __( 'Gerade läuft schon ein Scan oder eine Umwandlung. Bitte kurz warten.', 'akuma-webp-umwandler' ) );
		}

		try {
			$deadline = microtime( true ) + (float) $budget;
			do {
				self::advance( $state );
			} while ( 'done' !== $state['phase'] && microtime( true ) < $deadline );

			self::save( $state );
		} finally {
			Lock::release();
		}

		return $state;
	}

	/**
	 * Fortschritt für die Anzeige.
	 *
	 * @param array $state Stand.
	 * @return array{phase: string, label: string, percent: int, finished: bool}
	 */
	public static function progress( array $state ) {
		$phases  = self::phases();
		$weights = array(
			'inventory' => array( 0, 30 ),
			'usage'     => array( 30, 70 ),
			'theme'     => array( 70, 75 ),
			'estimate'  => array( 75, 98 ),
			'finalize'  => array( 98, 100 ),
			'done'      => array( 100, 100 ),
		);
		$phase   = $state['phase'];
		$range   = $weights[ $phase ];
		$share   = 0.0;

		if ( 'inventory' === $phase && $state['total'] > 0 ) {
			$share = count( $state['items'] ) / $state['total'];
		} elseif ( 'usage' === $phase ) {
			$share = $state['source'] / count( Usage_Finder::SOURCES );
		} elseif ( 'estimate' === $phase ) {
			$planned = count( $state['samples'] ) + count( $state['queue'] );
			$share   = $planned > 0 ? count( $state['samples'] ) / $planned : 1;
		}

		return array(
			'phase'    => $phase,
			'label'    => $phases[ $phase ],
			'percent'  => (int) floor( $range[0] + ( $range[1] - $range[0] ) * min( 1, $share ) ),
			'finished' => 'done' === $phase,
		);
	}

	/**
	 * Ein Schritt der aktuellen Phase.
	 *
	 * @param array $state Stand, wird geändert.
	 * @return void
	 */
	private static function advance( array &$state ) {
		switch ( $state['phase'] ) {
			case 'inventory':
				self::step_inventory( $state );
				break;
			case 'usage':
				self::step_usage( $state );
				break;
			case 'theme':
				self::step_theme( $state );
				break;
			case 'estimate':
				self::step_estimate( $state );
				break;
			case 'finalize':
				self::finalize( $state );
				break;
		}
	}

	/**
	 * Bestandsaufnahme: nächstes Paket Anhänge.
	 *
	 * @param array $state Stand.
	 * @return void
	 */
	private static function step_inventory( array &$state ) {
		$ids = Inventory::ids_after( $state['cursor'], self::INVENTORY_BATCH );

		foreach ( $ids as $attachment_id ) {
			$record = Inventory::record( $attachment_id );

			$record['uses']       = 0;
			$record['posts']      = array();
			$record['places']     = array();
			$record['background'] = false;
			$record['unknown']    = 0;
			$record['warnings']   = array();

			$state['items'][ $attachment_id ] = $record;
			$state['cursor']                  = $attachment_id;
		}

		if ( count( $ids ) < self::INVENTORY_BATCH ) {
			$state['phase']  = 'usage';
			$state['cursor'] = 0;
			$state['source'] = 0;
		}
	}

	/**
	 * Verwendung: nächstes Paket der aktuellen Quelle.
	 *
	 * @param array $state Stand.
	 * @return void
	 */
	private static function step_usage( array &$state ) {
		$source = Usage_Finder::SOURCES[ $state['source'] ];
		$result = self::finder( $state )->scan( $source, $state['cursor'], self::USAGE_BATCH[ $source ] );

		self::merge_hits( $state, $result['hits'] );
		$state['cursor'] = $result['cursor'];

		if ( $result['done'] ) {
			++$state['source'];
			$state['cursor'] = 0;
			if ( $state['source'] >= count( Usage_Finder::SOURCES ) ) {
				$state['phase'] = 'theme';
			}
		}
	}

	/**
	 * Theme-Dateien: in einem Schritt.
	 *
	 * @param array $state Stand.
	 * @return void
	 */
	private static function step_theme( array &$state ) {
		self::merge_hits( $state, self::finder( $state )->scan_theme_files() );

		$state['phase'] = 'estimate';
		$state['queue'] = Estimator::pick_samples( $state['items'], self::SAMPLES );
	}

	/**
	 * Hochrechnung: ein Bild der Stichprobe umwandeln, in ein temporäres Verzeichnis.
	 *
	 * @param array $state Stand.
	 * @return void
	 */
	private static function step_estimate( array &$state ) {
		$attachment_id = array_shift( $state['queue'] );

		if ( null === $attachment_id ) {
			$state['phase'] = 'finalize';
			return;
		}

		$item   = $state['items'][ $attachment_id ];
		$source = Attachment_Files::path( Attachment_Files::source( $item['files'] ) );
		$target = trailingslashit( get_temp_dir() ) . 'akwu-sample-' . wp_generate_password( 12, false ) . '.webp';
		$result = Encoder::encode( $source, $target, $state['settings'] );

		if ( ! is_wp_error( $result ) ) {
			$state['samples'][ $attachment_id ] = array(
				'class'  => Estimator::class_of( $item ),
				'source' => (int) filesize( $source ),
				'webp'   => $result['bytes'],
			);
			wp_delete_file( $result['path'] );
		}

		if ( is_file( $target ) ) {
			wp_delete_file( $target );
		}
	}

	/**
	 * Ergebnis berechnen: Elementor-Vorschaubilder zuordnen, Status, Hochrechnung, Summen.
	 *
	 * @param array $state Stand.
	 * @return void
	 */
	private static function finalize( array &$state ) {
		self::assign_thumbs( $state );

		$ratios      = Estimator::ratios( $state['samples'] );
		$min_savings = (int) $state['settings']['min_savings'];

		$totals = array(
			'images'      => 0,
			'sizes'       => 0,
			'bytes'       => 0,
			'by_kind'     => array(),
			'convertible' => array(
				'jpg' => 0,
				'png' => 0,
			),
			'ready'       => 0,
			'skip'        => 0,
			'missing'     => 0,
			'unused'      => 0,
			'source'      => 0,
			'after'       => 0,
			'webp'        => 0,
			'needed'      => 0,
			'ratios'      => $ratios,
			'samples'     => count( $state['samples'] ),
			'warnings'    => array(),
		);

		foreach ( $state['items'] as $attachment_id => &$item ) {
			$kind = $item['kind'];

			$item['estimate'] = $item['bytes'];
			$item['ratio']    = 1.0;
			$item['measured'] = false;

			if ( isset( Inventory::CONVERTIBLE[ $item['mime'] ] ) ) {
				if ( empty( $item['source'] ) ) {
					$item['status'] = 'missing';
					++$totals['missing'];
				} else {
					$measured = isset( $state['samples'][ $attachment_id ] ) && $state['samples'][ $attachment_id ]['source'] > 0
						? $state['samples'][ $attachment_id ]['webp'] / $state['samples'][ $attachment_id ]['source']
						: null;
					$estimate = Estimator::estimate( $item, $ratios, $measured );

					$item['ratio']    = $estimate['ratio'];
					$item['measured'] = $estimate['measured'];

					if ( Estimator::would_skip( $estimate['ratio'], $min_savings ) ) {
						$item['status'] = 'skip';
						++$totals['skip'];
					} else {
						$item['status']   = 'ready';
						$item['estimate'] = $estimate['bytes'];
						$totals['webp']  += $estimate['bytes'];
						++$totals['ready'];
					}

					++$totals['convertible'][ $kind ];
					$totals['source'] += $item['bytes'];
					if ( 0 === $item['uses'] ) {
						++$totals['unused'];
					}
				}
			} else {
				$item['status'] = isset( Inventory::MODERN[ $item['mime'] ] ) ? 'modern' : 'ignored';
			}

			++$totals['images'];
			$totals['sizes'] += max( 0, count( array_unique( $item['files'] ) ) - 1 );
			$totals['bytes'] += $item['bytes'];
			$totals['after'] += $item['estimate'];

			if ( ! isset( $totals['by_kind'][ $kind ] ) ) {
				$totals['by_kind'][ $kind ] = array(
					'count' => 0,
					'bytes' => 0,
				);
			}
			++$totals['by_kind'][ $kind ]['count'];
			$totals['by_kind'][ $kind ]['bytes'] += $item['bytes'];
		}
		unset( $item );

		foreach ( $state['warnings'] as $warning ) {
			$type                        = $warning['warning'];
			$totals['warnings'][ $type ] = isset( $totals['warnings'][ $type ] ) ? $totals['warnings'][ $type ] + 1 : 1;
		}

		// Laut Briefing: freier Speicher mindestens das 1,5-Fache der umzuwandelnden Bilder.
		$totals['needed'] = (int) ceil( $totals['source'] * 1.5 );

		$state['totals']   = $totals;
		$state['phase']    = 'done';
		$state['status']   = 'done';
		$state['finished'] = time();
		$state['queue']    = array();
	}

	/**
	 * Elementor-Vorschaubilder den Anhängen zuordnen, sofern eindeutig.
	 *
	 * @param array $state Stand.
	 * @return void
	 */
	private static function assign_thumbs( array &$state ) {
		$owners = array();

		foreach ( $state['items'] as $attachment_id => $item ) {
			$keys = array();
			foreach ( array( 'full', 'original_image' ) as $which ) {
				if ( isset( $item['files'][ $which ] ) ) {
					$keys[ Inventory::file_key( $item['files'][ $which ] ) ] = true;
				}
			}
			foreach ( array_keys( $keys ) as $key ) {
				$owners[ $key ][] = $attachment_id;
			}
		}

		foreach ( $state['thumbs'] as $key => $group ) {
			if ( isset( $owners[ $key ] ) && 1 === count( $owners[ $key ] ) ) {
				$attachment_id                               = $owners[ $key ][0];
				$state['items'][ $attachment_id ]['thumbs'] += $group['count'];
				$state['items'][ $attachment_id ]['bytes']  += $group['bytes'];
			}
		}
	}

	/**
	 * Übernimmt Fundstellen in die Datensätze.
	 *
	 * @param array   $state Stand.
	 * @param array[] $hits  Fundstellen aus Usage_Finder.
	 * @return void
	 */
	private static function merge_hits( array &$state, array $hits ) {
		foreach ( $hits as $hit ) {
			$attachment_id = $hit['attachment'];
			if ( ! isset( $state['items'][ $attachment_id ] ) ) {
				continue;
			}

			$item  = &$state['items'][ $attachment_id ];
			$place = array(
				'where'     => $hit['where'],
				'object'    => $hit['object'],
				'key'       => $hit['key'],
				'label'     => $hit['label'],
				'post_type' => $hit['post_type'],
				'context'   => $hit['context'],
				'count'     => $hit['count'],
			);

			if ( null !== $hit['warning'] ) {
				$place['warning']    = $hit['warning'];
				$item['warnings'][]  = $place;
				$state['warnings'][] = $place + array( 'attachment' => $attachment_id );
				unset( $item );
				continue;
			}

			$item['uses'] += $hit['count'];
			if ( count( $item['places'] ) < self::MAX_PLACES ) {
				$item['places'][] = $place;
			}
			if ( $hit['object'] > 0 && in_array( $hit['where'], array( 'post', 'meta', 'featured' ), true ) && 'elementor_library' !== $hit['post_type'] ) {
				$item['posts'][ $hit['object'] ] = true;
			}
			if ( 'background' === $hit['context'] ) {
				$item['background'] = true;
			}
			if ( 'unknown_size' === $hit['context'] ) {
				$item['unknown'] += $hit['count'];
			}
			unset( $item );
		}
	}

	/**
	 * Verwendungssuche mit Index aller Dateien. Der Index wird pro Request einmal gebaut.
	 *
	 * @param array $state Stand.
	 * @return Usage_Finder
	 */
	private static function finder( array $state ) {
		static $cache = array();

		$key = count( $state['items'] ) . ':' . $state['started'];
		if ( ! isset( $cache[ $key ] ) ) {
			$index = array();
			foreach ( $state['items'] as $attachment_id => $item ) {
				foreach ( $item['files'] as $relative ) {
					$index[ $relative ] = (int) $attachment_id;
				}
			}
			$cache = array( $key => new Usage_Finder( new Url_Matcher( $state['baseurl'] ), $index ) );
		}

		return $cache[ $key ];
	}

	/**
	 * Speichert den Stand, ohne Autoload.
	 *
	 * @param array $state Stand.
	 * @return void
	 */
	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}
}
