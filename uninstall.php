<?php
/**
 * Deinstallation: entfernt nur Optionen und das Protokoll des Plugins.
 *
 * Umgewandelte Bilder und ersetzte Verweise bleiben unverändert.
 * Danach ist kein Rückgängig mehr möglich, darauf weist die Seite „Rückgängig“ hin.
 *
 * @package Akuma\WebpUmwandler
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Optionen und Transients mit dem Prefix akwu_.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Einmalige Bereinigung bei der Deinstallation.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'akwu_' ) . '%',
		$wpdb->esc_like( '_transient_akwu_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_akwu_' ) . '%'
	)
);

// Protokoll-Tabelle (ab M3).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Einmalige Bereinigung bei der Deinstallation.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}akwu_log" );

wp_cache_delete( 'alloptions', 'options' );
