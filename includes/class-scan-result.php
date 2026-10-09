<?php
/**
 * Lesezugriff auf ein Scan-Ergebnis für Oberfläche und WP-CLI.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Bereitet das gespeicherte Scan-Ergebnis für die Anzeige auf.
 */
final class Scan_Result {

	/**
	 * Filter der Bildliste: Schlüssel => Anzeigename.
	 *
	 * @return array<string, string>
	 */
	public static function filters() {
		return array(
			'all'    => __( 'Alle', 'akuma-webp-umwandler' ),
			'ready'  => __( 'Bereit', 'akuma-webp-umwandler' ),
			'skip'   => __( 'Überspringen', 'akuma-webp-umwandler' ),
			'modern' => __( 'Schon modern', 'akuma-webp-umwandler' ),
			'check'  => __( 'Bitte prüfen', 'akuma-webp-umwandler' ),
			'unused' => __( 'Nicht gefunden', 'akuma-webp-umwandler' ),
		);
	}

	/**
	 * Gespeicherter Stand.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Konstruktor.
	 *
	 * @param array $state Stand aus Scanner::state().
	 */
	public function __construct( array $state ) {
		$this->state = $state;
	}

	/**
	 * Fertiges Ergebnis oder null.
	 *
	 * @return self|null
	 */
	public static function load() {
		$state = Scanner::state();

		return ( null !== $state && 'done' === $state['status'] ) ? new self( $state ) : null;
	}

	/**
	 * Summen.
	 *
	 * @return array
	 */
	public function totals() {
		return $this->state['totals'];
	}

	/**
	 * Zeitpunkt des Abschlusses.
	 *
	 * @return int
	 */
	public function finished() {
		return (int) $this->state['finished'];
	}

	/**
	 * Abschlusszeit lesbar: „heute, 16:52“ oder „09.10.2026, 16:52“.
	 *
	 * @return string
	 */
	public function finished_label() {
		return Format::time_label( $this->finished() );
	}

	/**
	 * Erwartete Ersparnis in Bytes.
	 *
	 * @return int
	 */
	public function savings() {
		return max( 0, (int) $this->state['totals']['bytes'] - (int) $this->state['totals']['after'] );
	}

	/**
	 * Erwartete Ersparnis in Prozent der heutigen Größe.
	 *
	 * @return int
	 */
	public function savings_percent() {
		$bytes = (int) $this->state['totals']['bytes'];

		return $bytes > 0 ? (int) round( 100 * $this->savings() / $bytes ) : 0;
	}

	/**
	 * Ersparnis als „−76 %“.
	 *
	 * @return string
	 */
	public function savings_percent_label() {
		return '−' . Format::number( $this->savings_percent() ) . Format::NBSP . '%';
	}

	/**
	 * Ein Datensatz.
	 *
	 * @param int $attachment_id Anhang.
	 * @return array|null
	 */
	public function item( $attachment_id ) {
		return isset( $this->state['items'][ $attachment_id ] ) ? $this->state['items'][ $attachment_id ] : null;
	}

	/**
	 * Datensätze nach Filter, sortiert.
	 *
	 * @param string $filter Schlüssel aus filters().
	 * @param string $sort   bytes (absteigend), name oder id.
	 * @return array[]
	 */
	public function items( $filter = 'all', $sort = 'bytes' ) {
		$items = array_values(
			array_filter(
				$this->state['items'],
				static function ( $item ) use ( $filter ) {
					return self::matches( $item, $filter );
				}
			)
		);

		usort(
			$items,
			static function ( $a, $b ) use ( $sort ) {
				if ( 'name' === $sort ) {
					return strcasecmp( wp_basename( $a['file'] ), wp_basename( $b['file'] ) );
				}
				if ( 'id' === $sort ) {
					return $a['id'] <=> $b['id'];
				}
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return $items;
	}

	/**
	 * Bereite Bilder, die jetzt noch JPG oder PNG sind. Umgewandelte fallen heraus, auch ohne neuen Scan.
	 *
	 * @param string $sort bytes, name, id oder uses (meistgenutzte zuerst).
	 * @return array[] Einträge wie items().
	 */
	public function open_items( $sort = 'id' ) {
		global $wpdb;

		$items = $this->items( 'ready', 'uses' === $sort ? 'id' : $sort );
		$open  = array();

		$mimes = "'" . implode( "','", array_map( 'esc_sql', array_keys( Inventory::CONVERTIBLE ) ) ) . "'";
		foreach ( array_chunk( wp_list_pluck( $items, 'id' ), 500 ) as $chunk ) {
			$list = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs als int, MIME-Typen maskiert.
			foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ({$mimes}) AND ID IN ({$list})" ) as $attachment_id ) {
				$open[ (int) $attachment_id ] = true;
			}
		}

		$items = array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $open ) {
					return isset( $open[ (int) $item['id'] ] );
				}
			)
		);

		if ( 'uses' === $sort ) {
			usort(
				$items,
				static function ( $a, $b ) {
					return array( $b['uses'], $b['bytes'] ) <=> array( $a['uses'], $a['bytes'] );
				}
			);
		}

		return $items;
	}

	/**
	 * Anzahl je Filter.
	 *
	 * @return array<string, int>
	 */
	public function counts() {
		$counts = array_fill_keys( array_keys( self::filters() ), 0 );

		foreach ( $this->state['items'] as $item ) {
			foreach ( array_keys( $counts ) as $filter ) {
				if ( self::matches( $item, $filter ) ) {
					++$counts[ $filter ];
				}
			}
		}

		return $counts;
	}

	/**
	 * Die größten umwandelbaren Bilder.
	 *
	 * @param int $limit Anzahl.
	 * @return array[]
	 */
	public function largest( $limit = 5 ) {
		$items = array_filter(
			$this->items( 'all', 'bytes' ),
			static function ( $item ) {
				return isset( Inventory::CONVERTIBLE[ $item['mime'] ] );
			}
		);

		return array_slice( array_values( $items ), 0, $limit );
	}

	/**
	 * Fundstellen, die nicht automatisch ersetzt werden, mit Dateiname.
	 *
	 * @return array[]
	 */
	public function warnings() {
		$warnings = array();

		foreach ( $this->state['warnings'] as $warning ) {
			$item = $this->item( $warning['attachment'] );

			$warnings[] = $warning + array( 'file' => null === $item ? '' : wp_basename( $item['file'] ) );
		}

		return $warnings;
	}

	/**
	 * Passt ein Datensatz zum Filter?
	 *
	 * @param array  $item   Datensatz.
	 * @param string $filter Filter.
	 * @return bool
	 */
	private static function matches( array $item, $filter ) {
		switch ( $filter ) {
			case 'ready':
				return 'ready' === $item['status'];
			case 'skip':
				return 'skip' === $item['status'];
			case 'modern':
				return in_array( $item['status'], array( 'modern', 'ignored' ), true );
			case 'check':
				return ! empty( $item['warnings'] ) || in_array( $item['status'], array( 'missing', 'nometa' ), true ) || ! empty( $item['unknown'] ) || ! empty( $item['missing'] );
			case 'unused':
				// Bilder, die nur in CSS, Snippets oder Theme-Dateien vorkommen, gelten als gefunden.
				return 0 === (int) $item['uses'] && empty( $item['warnings'] ) && isset( Inventory::CONVERTIBLE[ $item['mime'] ] );
			default:
				return true;
		}
	}

	/**
	 * Wo wird das Bild verwendet, kurz: „Startseite“, „3 Seiten“, „Theme-Einstellungen“ oder „Nicht gefunden“.
	 *
	 * @param array $item Datensatz.
	 * @return string
	 */
	public static function usage_label( array $item ) {
		$posts = array_keys( $item['posts'] );

		if ( 1 === count( $posts ) ) {
			if ( (int) get_option( 'page_on_front' ) === (int) $posts[0] ) {
				return __( 'Startseite', 'akuma-webp-umwandler' );
			}
			foreach ( $item['places'] as $place ) {
				if ( (int) $place['object'] === (int) $posts[0] ) {
					return $place['label'];
				}
			}
		}

		if ( count( $posts ) > 1 ) {
			/* translators: %s: Anzahl Seiten. */
			return sprintf( _n( '%s Seite', '%s Seiten', count( $posts ), 'akuma-webp-umwandler' ), Format::number( count( $posts ) ) );
		}

		if ( ! empty( $item['places'] ) ) {
			return $item['places'][0]['label'];
		}

		if ( ! empty( $item['warnings'] ) ) {
			return $item['warnings'][0]['label'];
		}

		return __( 'Nicht gefunden', 'akuma-webp-umwandler' );
	}

	/**
	 * Status als Text und Stil der Pille.
	 *
	 * @param array $item Datensatz.
	 * @return array{0: string, 1: string} Text und Stil (ok, warn, error, info).
	 */
	public static function status_label( array $item ) {
		switch ( $item['status'] ) {
			case 'ready':
				if ( ! empty( $item['warnings'] ) || ! empty( $item['unknown'] ) ) {
					return array( __( 'Bitte prüfen', 'akuma-webp-umwandler' ), 'warn' );
				}
				return $item['background']
					? array( __( 'Hintergrundbild', 'akuma-webp-umwandler' ), 'warn' )
					: array( __( 'Bereit', 'akuma-webp-umwandler' ), 'info' );
			case 'skip':
				return array( __( 'Überspringen', 'akuma-webp-umwandler' ), 'info' );
			case 'missing':
				return array( __( 'Datei fehlt', 'akuma-webp-umwandler' ), 'error' );
			case 'nometa':
				return array( __( 'Metadaten fehlen', 'akuma-webp-umwandler' ), 'warn' );
			case 'modern':
				return array( __( 'Schon modern', 'akuma-webp-umwandler' ), 'ok' );
			default:
				return array( __( 'Bleibt', 'akuma-webp-umwandler' ), 'info' );
		}
	}

	/**
	 * Anzeigename eines Warnungstyps.
	 *
	 * @param string $type Typ.
	 * @return string
	 */
	public static function warning_label( $type ) {
		$labels = array(
			'customizer_css' => __( 'Zusätzliches CSS', 'akuma-webp-umwandler' ),
			'elementor_css'  => __( 'Custom CSS in Elementor', 'akuma-webp-umwandler' ),
			'snippet'        => __( 'Code Snippet', 'akuma-webp-umwandler' ),
			'theme_file'     => __( 'Datei im Theme', 'akuma-webp-umwandler' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Link zum Bearbeiten einer Fundstelle, falls es einen gibt.
	 *
	 * @param array $place Fundstelle.
	 * @return string URL oder leer.
	 */
	public static function place_link( array $place ) {
		if ( in_array( $place['where'], array( 'post', 'meta', 'featured' ), true ) && $place['object'] > 0 ) {
			if ( 'custom_css' === $place['post_type'] ) {
				return admin_url( 'customize.php' );
			}
			$link = get_edit_post_link( (int) $place['object'], 'raw' );
			return null === $link ? '' : $link;
		}
		if ( 'option' === $place['where'] && 0 === strpos( $place['key'], 'theme_mods_' ) ) {
			return admin_url( 'customize.php' );
		}
		if ( 'snippet' === $place['where'] && $place['object'] > 0 ) {
			// Wie im Plugin Code Snippets selbst (Admin_Bar): Basis-URL der Bearbeiten-Seite plus id.
			$base = function_exists( 'code_snippets' ) ? code_snippets()->get_menu_url( 'edit' ) : admin_url( 'admin.php?page=edit-snippet' );
			return add_query_arg( 'id', (int) $place['object'], $base );
		}

		return '';
	}

	/**
	 * Formatkürzel für die Anzeige.
	 *
	 * @param string $kind Kürzel aus Inventory::kind().
	 * @return string
	 */
	public static function kind_label( $kind ) {
		return 'other' === $kind ? __( 'Sonstige', 'akuma-webp-umwandler' ) : strtoupper( $kind );
	}
}
