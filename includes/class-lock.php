<?php
/**
 * Sperre gegen parallele Läufe.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Atomare Sperre in der Options-Tabelle (INSERT IGNORE), mit Ablaufzeit.
 *
 * Scan und Umwandlung teilen sich die Sperre, damit nie zwei Pakete gleichzeitig laufen,
 * auch nicht aus zwei Browserfenstern oder Admin und WP-CLI.
 */
final class Lock {

	/**
	 * Name der Option.
	 */
	const OPTION = 'akwu_lock';

	/**
	 * Kennung des aktuellen Halters in diesem Request.
	 *
	 * @var string|null
	 */
	private static $token = null;

	/**
	 * Versucht die Sperre zu bekommen.
	 *
	 * @param string $task Was läuft, z. B. scan oder convert.
	 * @param int    $ttl  Sekunden bis zum Ablauf.
	 * @return bool
	 */
	public static function acquire( $task, $ttl = 120 ) {
		global $wpdb;

		$token = wp_generate_uuid4();
		$value = maybe_serialize(
			array(
				'task'    => $task,
				'token'   => $token,
				'expires' => time() + (int) $ttl,
			)
		);

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomare Sperre, Cache darf nicht dazwischen.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION, $value ) );

			if ( 1 === (int) $wpdb->rows_affected ) {
				self::$token = $token;
				self::flush_cache();
				return true;
			}

			$current = self::read();
			if ( null === $current || $current['expires'] >= time() ) {
				return false;
			}

			// Abgelaufen: genau diesen Eintrag löschen und noch einmal versuchen.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomare Sperre.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, $current['raw'] ) );
		}

		return false;
	}

	/**
	 * Gibt die eigene Sperre frei.
	 *
	 * @return void
	 */
	public static function release() {
		global $wpdb;

		if ( null === self::$token ) {
			return;
		}

		$current = self::read();
		if ( null !== $current && $current['token'] === self::$token ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomare Sperre.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, $current['raw'] ) );
		}

		self::$token = null;
		self::flush_cache();
	}

	/**
	 * Wer hält die Sperre gerade?
	 *
	 * @return string|null Aufgabe (scan, convert …) oder null, wenn frei.
	 */
	public static function holder() {
		$current = self::read();

		return ( null !== $current && $current['expires'] >= time() ) ? $current['task'] : null;
	}

	/**
	 * Liest die Sperre direkt aus der Datenbank.
	 *
	 * @return array|null
	 */
	private static function read() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomare Sperre.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		if ( null === $raw ) {
			return null;
		}

		$data = maybe_unserialize( $raw );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return array(
			'raw'     => $raw,
			'task'    => isset( $data['task'] ) ? (string) $data['task'] : '',
			'token'   => isset( $data['token'] ) ? (string) $data['token'] : '',
			'expires' => isset( $data['expires'] ) ? (int) $data['expires'] : 0,
		);
	}

	/**
	 * Hält der Objekt-Cache eine alte Kopie, wird sie verworfen.
	 *
	 * @return void
	 */
	private static function flush_cache() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
