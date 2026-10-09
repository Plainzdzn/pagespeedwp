<?php
/**
 * Elementor-CSS neu erzeugen und Caches leeren.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Leert nach einer Umwandlung oder einem Rückgängig die Caches (Briefing §4.5).
 *
 * Jede Schnittstelle wird nur aufgerufen, wenn sie vorhanden ist. Alle Aufrufe sind am
 * Quellcode der jeweiligen Plugins geprüft (Stand 2026-10-09):
 *
 * - Elementor 4.3.4: `\Elementor\Plugin::$instance->files_manager->clear_cache()`
 * - FastPixel 2.0.0: Hook `fastpixel/purge/all` (öffentlich für Entwickler, leert auch Hosting-Caches wie Raidboxes)
 * - WP Rocket (GitHub wp-media/wp-rocket, trunk): `rocket_clean_domain()`
 * - LiteSpeed Cache: Hook `litespeed_purge_all`
 * - W3 Total Cache: `w3tc_flush_all()`
 * - WP Super Cache: `wp_cache_clear_cache()`
 * - Autoptimize: `autoptimizeCache::clearall()`
 * - WordPress-Objekt-Cache: `wp_cache_flush()`
 *
 * Raidboxes ohne FastPixel: keine dokumentierte Schnittstelle, die Oberfläche zeigt einen Hinweis.
 */
final class Cache_Purger {

	/**
	 * Elementor-CSS und Element-Cache neu erzeugen lassen.
	 *
	 * @return bool Ob Elementor aktiv war.
	 */
	public static function elementor() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) || ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
			return false;
		}

		\Elementor\Plugin::$instance->files_manager->clear_cache();

		return true;
	}

	/**
	 * Leert alle erkannten Caches.
	 *
	 * @return string[] Namen der geleerten Caches.
	 */
	public static function purge_all() {
		$purged = array();

		foreach ( self::available() as $name => $purge ) {
			$purge();
			$purged[] = $name;
		}

		wp_cache_flush();
		$purged[] = __( 'WordPress-Objekt-Cache', 'akuma-webp-umwandler' );

		return $purged;
	}

	/**
	 * Namen der erkannten Cache-Plugins, ohne etwas zu leeren. Für die Systemprüfung.
	 *
	 * @return string[]
	 */
	public static function detect() {
		return array_keys( self::available() );
	}

	/**
	 * Vorhandene Caches und wie sie geleert werden.
	 *
	 * @return array<string, callable> Name => Aufruf.
	 */
	private static function available() {
		$caches = array();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Hooks anderer Plugins.
		if ( has_action( 'fastpixel/purge/all' ) ) {
			$caches['FastPixel'] = static function () {
				do_action( 'fastpixel/purge/all' );
			};
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			$caches['WP Rocket'] = static function () {
				rocket_clean_domain();
			};
		}

		if ( has_action( 'litespeed_purge_all' ) ) {
			$caches['LiteSpeed Cache'] = static function () {
				do_action( 'litespeed_purge_all', 'WebP-Umwandler' );
			};
		}
		// phpcs:enable

		if ( function_exists( 'w3tc_flush_all' ) ) {
			$caches['W3 Total Cache'] = static function () {
				w3tc_flush_all();
			};
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$caches['WP Super Cache'] = static function () {
				wp_cache_clear_cache();
			};
		}

		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			$caches['Autoptimize'] = static function () {
				\autoptimizeCache::clearall();
			};
		}

		return $caches;
	}

	/**
	 * Läuft die Seite wahrscheinlich bei Raidboxes, ohne FastPixel?
	 *
	 * Gleiche Erkennung wie in FastPixel 2.0 (Hostname beginnt mit „box-“ und Ordner rb-plugins).
	 * Dann muss der Server-Cache im Raidboxes-Dashboard geleert werden.
	 *
	 * @return bool
	 */
	public static function needs_raidboxes_hint() {
		if ( has_action( 'fastpixel/purge/all' ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook von FastPixel.
			return false;
		}

		$hostname = function_exists( 'gethostname' ) ? (string) gethostname() : '';

		return 0 === strpos( $hostname, 'box-' ) && file_exists( trailingslashit( ABSPATH ) . 'rb-plugins' );
	}
}
