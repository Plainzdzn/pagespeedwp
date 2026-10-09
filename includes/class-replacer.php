<?php
/**
 * Verweise in der Datenbank ersetzen.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Ersetzt Upload-Pfade laut Zuordnung in posts (Inhalt, Auszug), postmeta, options und termmeta (Briefing §4.4).
 *
 * - Vorfilter per SQL-LIKE auf Dateiname und Endung, damit nicht die ganze Datenbank geladen wird.
 * - Serialisierte Werte und JSON (`_elementor_data`) werden sicher ersetzt (Value_Replacer).
 * - Nie angefasst: guid, Customizer-CSS (Post-Typ custom_css), `custom_css` in Elementor-Daten,
 *   Daten des Anhangs selbst, Elementor-Caches (werden danach neu erzeugt), Transients und eigene Optionen.
 * - Geschrieben wird direkt per $wpdb (wie WP-CLI search-replace), danach wird der Cache des Objekts geleert.
 */
final class Replacer {

	/**
	 * Zeilen pro Abfrage.
	 */
	const BATCH = 100;

	/**
	 * Postmeta, die nie ersetzt werden.
	 */
	const SKIP_META = Usage_Finder::SKIP_META;

	/**
	 * URL-Erkennung.
	 *
	 * @var Url_Matcher
	 */
	private $matcher;

	/**
	 * Konstruktor.
	 *
	 * @param Url_Matcher $matcher URL-Erkennung.
	 */
	public function __construct( Url_Matcher $matcher ) {
		$this->matcher = $matcher;
	}

	/**
	 * Ersetzt alle Verweise laut Zuordnung.
	 *
	 * @param array<string, string> $map    Alter Pfad => neuer Pfad.
	 * @param array<string, int>    $owners Alter Pfad => Attachment-ID, für die Zählung je Bild.
	 * @return array{total: int, per_attachment: array<int, int>, places: array<int, array[]>}
	 */
	public function run( array $map, array $owners ) {
		$result = array(
			'total'          => 0,
			'per_attachment' => array(),
			'places'         => array(),
		);

		if ( empty( $map ) ) {
			return $result;
		}

		$replacer = new Value_Replacer( $this->matcher, $map );
		$likes    = self::like_patterns( array_keys( $map ) );

		$this->replace_posts( $replacer, $likes, $owners, $result );
		$this->replace_meta( 'post', $replacer, $likes, $owners, $result );
		$this->replace_meta( 'term', $replacer, $likes, $owners, $result );
		$this->replace_options( $replacer, $likes, $owners, $result );

		return $result;
	}

	/**
	 * LIKE-Muster aus den alten Pfaden: Dateiname ohne Größenzusatz plus Endung,
	 * z. B. `%bild%.png%` für bild.png und alle seine Größen. Auch URL-kodiert.
	 *
	 * @param string[] $paths Alte Pfade.
	 * @return string[] Muster für LIKE, bereits maskiert.
	 */
	public static function like_patterns( array $paths ) {
		global $wpdb;

		$patterns = array();

		foreach ( $paths as $path ) {
			$name = wp_basename( $path );
			$dot  = strrpos( $name, '.' );
			if ( false === $dot ) {
				continue;
			}

			$stem = preg_replace( '/-(\d+x\d+|scaled|rotated)$/', '', substr( $name, 0, $dot ) );
			$ext  = substr( $name, $dot );

			foreach ( array_unique( array( $stem, rawurlencode( $stem ) ) ) as $variant ) {
				$patterns[] = '%' . $wpdb->esc_like( $variant ) . '%' . $wpdb->esc_like( $ext ) . '%';
			}
		}

		return array_values( array_unique( $patterns ) );
	}

	/**
	 * SQL-Bedingung „Spalte LIKE eines der Muster“.
	 *
	 * @param string   $column Spalte.
	 * @param string[] $likes  Muster.
	 * @return string
	 */
	private static function like_clause( $column, array $likes ) {
		global $wpdb;

		$parts = array();
		foreach ( $likes as $like ) {
			$parts[] = $wpdb->prepare( "{$column} LIKE %s", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Spaltenname aus Konstanten.
		}

		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * Beiträge: Inhalt und Auszug.
	 *
	 * @param Value_Replacer $replacer Ersetzer.
	 * @param string[]       $likes    LIKE-Muster.
	 * @param array          $owners   Pfad => Anhang.
	 * @param array          $result   Ergebnis, wird ergänzt.
	 * @return void
	 */
	private function replace_posts( Value_Replacer $replacer, array $likes, array $owners, array &$result ) {
		global $wpdb;

		$cursor = 0;
		$where  = '(' . self::like_clause( 'post_content', $likes ) . ' OR ' . self::like_clause( 'post_excerpt', $likes ) . ')';

		// Customizer-CSS bleibt unangetastet, auch in seinen Revisionen. Andere Revisionen werden mit ersetzt,
		// damit eine wiederhergestellte Revision keine alten Adressen zurückbringt.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wenige IDs, einmal je Durchgang.
		$css_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'custom_css'" ) );
		if ( $css_ids ) {
			$where .= " AND NOT ( post_type = 'revision' AND post_parent IN (" . implode( ',', $css_ids ) . ') )';
		}

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where aus vorbereiteten Teilen.
			$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'custom_css' AND {$where} ORDER BY ID ASC LIMIT %d", $cursor, self::BATCH ) );
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$cursor  = (int) $row->ID;
				$changes = array();

				foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
					$new = $replacer->replace( $row->$field );
					if ( $replacer->count() > 0 && $new !== $row->$field ) {
						$changes[ $field ] = $new;
						self::record( $result, $replacer, $owners, 'post', (int) $row->ID, $field );
					}
				}

				if ( $changes ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Gezielte Ersetzung, Cache wird geleert.
					$wpdb->update( $wpdb->posts, $changes, array( 'ID' => (int) $row->ID ) );
					clean_post_cache( (int) $row->ID );
				}
			}
		} while ( self::BATCH === $fetched );
	}

	/**
	 * Postmeta oder Termmeta.
	 *
	 * @param string         $type     post oder term.
	 * @param Value_Replacer $replacer Ersetzer.
	 * @param string[]       $likes    LIKE-Muster.
	 * @param array          $owners   Pfad => Anhang.
	 * @param array          $result   Ergebnis, wird ergänzt.
	 * @return void
	 */
	private function replace_meta( $type, Value_Replacer $replacer, array $likes, array $owners, array &$result ) {
		global $wpdb;

		$table  = 'post' === $type ? $wpdb->postmeta : $wpdb->termmeta;
		$column = 'post' === $type ? 'post_id' : 'term_id';
		$skip   = "'" . implode( "','", array_map( 'esc_sql', self::SKIP_META ) ) . "'";
		$where  = self::like_clause( 'meta_value', $likes );
		$cursor = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Tabellen und Bedingungen aus vorbereiteten Teilen.
			$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, {$column} AS object_id, meta_key, meta_value FROM {$table} WHERE meta_id > %d AND meta_key NOT IN ({$skip}) AND {$where} ORDER BY meta_id ASC LIMIT %d", $cursor, self::BATCH ) );
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row->meta_id;
				$new    = '_elementor_data' === $row->meta_key ? $replacer->replace_json( $row->meta_value ) : $replacer->replace( $row->meta_value );

				if ( 0 === $replacer->count() || $new === $row->meta_value ) {
					continue;
				}

				self::record( $result, $replacer, $owners, 'post' === $type ? 'meta' : 'term', (int) $row->object_id, $row->meta_key );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Gezielte Ersetzung über meta_id, Cache wird geleert.
				$wpdb->update( $table, array( 'meta_value' => $new ), array( 'meta_id' => (int) $row->meta_id ) );
				wp_cache_delete( (int) $row->object_id, $type . '_meta' );
			}
		} while ( self::BATCH === $fetched );
	}

	/**
	 * Optionen, ohne Transients und eigene Optionen.
	 *
	 * @param Value_Replacer $replacer Ersetzer.
	 * @param string[]       $likes    LIKE-Muster.
	 * @param array          $owners   Pfad => Anhang.
	 * @param array          $result   Ergebnis, wird ergänzt.
	 * @return void
	 */
	private function replace_options( Value_Replacer $replacer, array $likes, array $owners, array &$result ) {
		global $wpdb;

		$where   = self::like_clause( 'option_value', $likes );
		$cursor  = 0;
		$exclude = $wpdb->prepare(
			'option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s',
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
			$wpdb->esc_like( 'akwu_' ) . '%'
		);

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bedingungen aus vorbereiteten Teilen.
			$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND {$exclude} AND {$where} ORDER BY option_id ASC LIMIT %d", $cursor, self::BATCH ) );
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row->option_id;
				$new    = $replacer->replace( $row->option_value );

				if ( 0 === $replacer->count() || $new === $row->option_value ) {
					continue;
				}

				self::record( $result, $replacer, $owners, 'option', 0, $row->option_name );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Gezielte Ersetzung, Cache wird geleert.
				$wpdb->update( $wpdb->options, array( 'option_value' => $new ), array( 'option_id' => (int) $row->option_id ) );
				wp_cache_delete( $row->option_name, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
		} while ( self::BATCH === $fetched );
	}

	/**
	 * Zählt Ersetzungen je Anhang und merkt sich die Stelle.
	 *
	 * @param array          $result   Ergebnis.
	 * @param Value_Replacer $replacer Ersetzer nach dem letzten Aufruf.
	 * @param array          $owners   Pfad => Anhang.
	 * @param string         $where    post, meta, term oder option.
	 * @param int            $object_id ID des Objekts.
	 * @param string         $key      Feld, Meta-Schlüssel oder Optionsname.
	 * @return void
	 */
	private static function record( array &$result, Value_Replacer $replacer, array $owners, $where, $object_id, $key ) {
		$per_attachment = array();

		foreach ( $replacer->counts_by_path() as $path => $count ) {
			if ( isset( $owners[ $path ] ) ) {
				$attachment_id                    = $owners[ $path ];
				$per_attachment[ $attachment_id ] = ( isset( $per_attachment[ $attachment_id ] ) ? $per_attachment[ $attachment_id ] : 0 ) + $count;
			}
		}

		foreach ( $per_attachment as $attachment_id => $count ) {
			$result['total']                           += $count;
			$result['per_attachment'][ $attachment_id ] = ( isset( $result['per_attachment'][ $attachment_id ] ) ? $result['per_attachment'][ $attachment_id ] : 0 ) + $count;

			if ( ! isset( $result['places'][ $attachment_id ] ) || count( $result['places'][ $attachment_id ] ) < 50 ) {
				$result['places'][ $attachment_id ][] = array(
					'where'  => $where,
					'object' => $object_id,
					'key'    => (string) $key,
					'count'  => $count,
				);
			}
		}
	}
}
