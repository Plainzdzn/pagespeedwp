<?php
/**
 * Protokoll-Tabelle der Umwandlung.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Tabelle `{prefix}akwu_log`: eine Zeile pro Anhang und Lauf.
 *
 * Status: pending → working → converted → done, sonst skipped, error, rolled_back oder cancelled (nie begonnen, Lauf abgebrochen).
 * `working` heißt: Zustand gesichert, Umwandlung begonnen. Bricht der Request dort ab,
 * stellt der nächste Schritt den Anhang aus dem gesicherten Zustand wieder her.
 * `converted` heißt: Anhang umgestellt, Verweise noch nicht ersetzt.
 */
final class Log_Table {

	/**
	 * Version des Tabellenschemas.
	 */
	const DB_VERSION = 1;

	/**
	 * Option mit der installierten Schema-Version.
	 */
	const VERSION_OPTION = 'akwu_db_version';

	/**
	 * Spalten mit JSON-Inhalt.
	 */
	const JSON_COLUMNS = array( 'old_meta', 'old_files', 'new_files', 'url_map', 'places' );

	/**
	 * Tabellenname mit Prefix.
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;

		return $wpdb->prefix . 'akwu_log';
	}

	/**
	 * Legt die Tabelle an oder aktualisiert sie (dbDelta).
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(36) NOT NULL DEFAULT '',
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			old_file text NULL,
			new_file text NULL,
			old_mime varchar(100) NOT NULL DEFAULT '',
			old_meta longtext NULL,
			old_files longtext NULL,
			new_files longtext NULL,
			url_map longtext NULL,
			bytes_before bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes_after bigint(20) unsigned NOT NULL DEFAULT 0,
			replacements int(10) unsigned NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			places longtext NULL,
			message text NULL,
			created_at datetime NULL,
			updated_at datetime NULL,
			purged_at datetime NULL,
			PRIMARY KEY  (id),
			KEY run_status (run_id,status),
			KEY attachment_id (attachment_id)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Legt die Tabelle an, falls sie fehlt oder veraltet ist.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::VERSION_OPTION ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Legt Zeilen mit Status pending an.
	 *
	 * @param string $run_id Lauf.
	 * @param int[]  $ids    Anhänge.
	 * @return void
	 */
	public static function add_pending( $run_id, array $ids ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $attachment_id ) {
				$values[] = $wpdb->prepare( '(%s, %d, %s, %s, %s)', $run_id, $attachment_id, 'pending', $now, $now );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Werte oben einzeln vorbereitet.
			$wpdb->query( 'INSERT INTO ' . self::name() . ' (run_id, attachment_id, status, created_at, updated_at) VALUES ' . implode( ',', $values ) );
		}
	}

	/**
	 * Eine Zeile.
	 *
	 * @param int $id Zeilen-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Eigene Tabelle.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return null === $row ? null : self::decode( $row );
	}

	/**
	 * Zeilen eines Laufs nach Status.
	 *
	 * @param string   $run_id   Lauf.
	 * @param string[] $statuses Status, leer für alle.
	 * @param int      $limit    Höchstzahl, 0 für alle.
	 * @param string   $order    ASC oder DESC (nach updated_at, dann id).
	 * @return array[]
	 */
	public static function rows( $run_id, array $statuses = array(), $limit = 0, $order = 'ASC' ) {
		global $wpdb;

		$order = 'DESC' === $order ? 'DESC' : 'ASC';
		$where = $wpdb->prepare( 'run_id = %s', $run_id );

		if ( $statuses ) {
			$where .= " AND status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
		}

		$sql = 'SELECT * FROM ' . self::name() . " WHERE {$where} ORDER BY updated_at {$order}, id {$order}";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bestandteile oben vorbereitet bzw. maskiert.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * Neue Datei je Anhang für einen Status, ohne die großen JSON-Spalten.
	 *
	 * @param string $run_id Lauf.
	 * @param string $status Status.
	 * @return array<int, string> Anhang-ID => neue Datei (relativ).
	 */
	public static function new_files( $run_id, $status ) {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Eigene Tabelle.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id, new_file FROM {$table} WHERE run_id = %s AND status = %s", $run_id, $status ), ARRAY_A );

		$files = array();
		foreach ( (array) $rows as $row ) {
			$files[ (int) $row['attachment_id'] ] = (string) $row['new_file'];
		}

		return $files;
	}

	/**
	 * Nächste Zeilen mit Status pending, in Reihenfolge der Anlage.
	 *
	 * @param string $run_id Lauf.
	 * @param int    $limit  Höchstzahl.
	 * @return array[]
	 */
	public static function next_pending( $run_id, $limit ) {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Eigene Tabelle.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s AND status = %s ORDER BY id ASC LIMIT %d", $run_id, 'pending', $limit ), ARRAY_A );

		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * Anzahl je Status in einem Lauf.
	 *
	 * @param string $run_id Lauf.
	 * @return array<string, int>
	 */
	public static function counts( $run_id ) {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Eigene Tabelle.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n, SUM(bytes_before) AS before_bytes, SUM(bytes_after) AS after_bytes, SUM(replacements) AS repl FROM {$table} WHERE run_id = %s GROUP BY status", $run_id ), ARRAY_A );

		$counts = array(
			'pending'      => 0,
			'working'      => 0,
			'converted'    => 0,
			'done'         => 0,
			'skipped'      => 0,
			'error'        => 0,
			'rolled_back'  => 0,
			'cancelled'    => 0,
			'bytes_before' => 0,
			'bytes_after'  => 0,
			'replacements' => 0,
		);

		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
			if ( 'done' === $row['status'] ) {
				$counts['bytes_before'] = (int) $row['before_bytes'];
				$counts['bytes_after']  = (int) $row['after_bytes'];
				$counts['replacements'] = (int) $row['repl'];
			}
		}

		return $counts;
	}

	/**
	 * Letzte Zeile je Anhang, über alle Läufe.
	 *
	 * Zeilen mit Status pending oder cancelled zählen nicht, sie haben am Anhang nichts geändert.
	 *
	 * @param int[]    $ids      Nur diese Anhänge, leer für alle.
	 * @param string[] $statuses Nur Anhänge, deren letzte Zeile diesen Status hat, leer für alle.
	 * @return array<int, array> Anhang-ID => Zeile, aufsteigend nach Zeilen-ID.
	 */
	public static function latest_by_attachment( array $ids = array(), array $statuses = array() ) {
		global $wpdb;

		$table = self::name();
		$where = "WHERE status NOT IN ('pending', 'cancelled')";
		if ( $ids ) {
			$where .= ' AND attachment_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		$outer = '';
		if ( $statuses ) {
			$outer = " WHERE l.status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Eigene Tabelle, IDs als int, Status maskiert.
		$rows = $wpdb->get_results( "SELECT l.* FROM {$table} l INNER JOIN ( SELECT MAX(id) AS id FROM {$table} {$where} GROUP BY attachment_id ) m ON m.id = l.id{$outer} ORDER BY l.id ASC", ARRAY_A );

		$latest = array();
		foreach ( (array) $rows as $row ) {
			$latest[ (int) $row['attachment_id'] ] = self::decode( $row );
		}

		return $latest;
	}

	/**
	 * Zeilen nach ID.
	 *
	 * @param int[] $ids Zeilen-IDs.
	 * @return array<int, array> Zeilen-ID => Zeile.
	 */
	public static function by_ids( array $ids ) {
		global $wpdb;

		$rows = array();
		if ( empty( $ids ) ) {
			return $rows;
		}

		$table = self::name();
		$list  = implode( ',', array_map( 'intval', $ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Eigene Tabelle, IDs als int.
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN ({$list})", ARRAY_A ) as $row ) {
			$rows[ (int) $row['id'] ] = self::decode( $row );
		}

		return $rows;
	}

	/**
	 * Aktualisiert eine Zeile. JSON-Spalten nehmen Arrays.
	 *
	 * @param int   $id   Zeilen-ID.
	 * @param array $data Spalte => Wert.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		foreach ( self::JSON_COLUMNS as $column ) {
			if ( array_key_exists( $column, $data ) && ! is_string( $data[ $column ] ) && null !== $data[ $column ] ) {
				$data[ $column ] = wp_json_encode( $data[ $column ] );
			}
		}

		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Eigene Tabelle.
		$wpdb->update( self::name(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Existiert die Tabelle?
	 *
	 * @return bool
	 */
	public static function exists() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prüfung auf eigene Tabelle.
		return self::name() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::name() ) ) );
	}

	/**
	 * Dekodiert JSON-Spalten und Zahlen.
	 *
	 * @param array $row Zeile aus der Datenbank.
	 * @return array
	 */
	public static function decode( array $row ) {
		foreach ( self::JSON_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) && '' !== $row[ $column ] ) {
				$decoded        = json_decode( $row[ $column ], true );
				$row[ $column ] = is_array( $decoded ) ? $decoded : array();
			} else {
				$row[ $column ] = array();
			}
		}

		foreach ( array( 'id', 'attachment_id', 'bytes_before', 'bytes_after', 'replacements' ) as $column ) {
			$row[ $column ] = isset( $row[ $column ] ) ? (int) $row[ $column ] : 0;
		}

		return $row;
	}
}
