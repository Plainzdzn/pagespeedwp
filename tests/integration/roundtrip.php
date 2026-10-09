<?php
/**
 * Integrationstest: Umwandeln und Rückgängig ergeben wieder genau den Ausgangszustand.
 *
 * Nur in der Testinstallation ausführen (legt die Seed-Daten neu an):
 *   wp eval-file tests/integration/roundtrip.php
 *
 * Prüft:
 * - Nach der Umwandlung: IDs gleich, MIME-Typ WebP, Verweise ersetzt, geschützte Stellen unverändert,
 *   alle Bild-URLs der Testseiten liefern eine Datei.
 * - Nach dem Rückgängig: Inhalte, Postmeta, Optionen und Anhänge Byte für Byte wie vorher,
 *   WebP-Dateien gelöscht, Originale noch da.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration;

use Akuma\WebpUmwandler\Attachment_Files;
use Akuma\WebpUmwandler\Conversion;
use Akuma\WebpUmwandler\Log_Table;
use Akuma\WebpUmwandler\Rollback;
use Akuma\WebpUmwandler\Scanner;
use Akuma\WebpUmwandler\Url_Matcher;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Abbruch: nur auf Testinstallationen.' );
}

/**
 * Prüft eine Bedingung und zählt Fehler.
 *
 * @param bool   $condition Bedingung.
 * @param string $message   Beschreibung.
 * @return void
 */
function check( $condition, $message ) {
	global $akwu_failures;

	if ( $condition ) {
		WP_CLI::log( '  ok    ' . $message );
	} else {
		++$akwu_failures;
		WP_CLI::warning( 'FEHLER ' . $message );
	}
}

/**
 * Momentaufnahme aller Daten, die die Umwandlung berührt.
 *
 * @return array
 */
function snapshot() {
	global $wpdb;

	$ids = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_akwu_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Nur Test.
		)
	);
	$kit = (int) get_option( 'elementor_active_kit' );
	if ( $kit > 0 ) {
		$ids[] = $kit;
	}
	sort( $ids );
	$list = implode( ',', array_map( 'intval', $ids ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nur Test, IDs als int.
	$posts = $wpdb->get_results( "SELECT ID, post_content, post_excerpt, post_mime_type, guid FROM {$wpdb->posts} WHERE ID IN ({$list}) ORDER BY ID", ARRAY_A );
	// Elementor-Caches und den Migrationsstand legt Elementor selbst beim Aufruf der Seite an.
	$meta = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$list}) AND meta_key NOT IN ('_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_edit_lock') AND meta_key NOT LIKE '\\_elementor\\_migrations\\_state%' ORDER BY post_id, meta_key, meta_id", ARRAY_A );
	// phpcs:enable

	$files = glob( Attachment_Files::basedir() . '2019/05/*' );
	sort( $files );

	return array(
		'posts'      => $posts,
		'meta'       => $meta,
		'theme_mods' => get_option( 'theme_mods_' . get_stylesheet() ),
		'custom_css' => wp_get_custom_css(),
		'files'      => array_map( 'wp_basename', $files ),
	);
}

/**
 * Der Rundlauf.
 *
 * @return void
 */
function main() {
	$GLOBALS['akwu_failures'] = 0;

	WP_CLI::log( 'Testdaten neu anlegen …' );
	WP_CLI::runcommand( 'eval-file ' . escapeshellarg( dirname( __DIR__ ) . '/seed/seed.php' ), array( 'return' => true ) );
	wp_cache_flush();

	$before = snapshot();

	WP_CLI::log( 'Scan …' );
	Scanner::start();
	do {
		$state = Scanner::step( 30 );
	} while ( 'done' !== $state['phase'] );

	WP_CLI::log( 'Umwandlung …' );
	$run = Conversion::start( 'all' );
	if ( is_wp_error( $run ) ) {
		WP_CLI::error( $run->get_error_message() );
	}
	do {
		$run      = Conversion::step( 30 );
		$progress = Conversion::progress( $run );
	} while ( ! $progress['finished'] );

	$rows = Log_Table::rows( $run['id'], array( 'done' ) );

	WP_CLI::log( 'Nach der Umwandlung:' );
	check( count( $rows ) >= 5, sprintf( '%d Bilder umgewandelt', count( $rows ) ) );
	check( 0 === $progress['ids_changed'], 'Bild-IDs unverändert' );
	check( 0 === $progress['errors'], 'keine Fehler' );

	foreach ( $rows as $row ) {
		check( 'image/webp' === get_post_mime_type( $row['attachment_id'] ) && is_file( Attachment_Files::path( $row['new_file'] ) ), sprintf( 'Anhang %d ist WebP, Datei vorhanden', $row['attachment_id'] ) );
	}

	$page = get_posts(
		array(
			'post_type'   => 'page',
			'title'       => 'Testseite Elementor',
			'numberposts' => 1,
		)
	)[0];
	$data = (string) get_post_meta( $page->ID, '_elementor_data', true );
	check( is_array( json_decode( $data, true ) ), '_elementor_data ist gültiges JSON' );
	check( false === strpos( $data, 'team-header.png' ) && false !== strpos( $data, 'team-header.webp' ), 'Hintergrundbild in _elementor_data ersetzt' );
	$settings = get_post_meta( $page->ID, '_elementor_page_settings', true );
	check( false !== strpos( $settings['custom_css'], 'bild.jpg' ), 'Custom CSS in Elementor unverändert' );
	check( false !== strpos( wp_get_custom_css(), 'bild.png' ), 'Customizer-CSS unverändert' );
	check( false !== strpos( (string) get_theme_mod( 'background_image' ), '.webp' ), 'Theme-Mod ersetzt' );

	$leftovers = array_filter(
		$run['leftovers'],
		static function ( $hit ) {
			return null === $hit['warning'];
		}
	);
	check( empty( $leftovers ), 'Gegenprobe: keine unerwarteten Reste' );

	foreach ( array( $page->ID ) as $post_id ) {
		$response = wp_remote_get( get_permalink( $post_id ) );
		$html     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			WP_CLI::log( '  –     Seite nicht abrufbar (Server läuft nicht?), Bildprüfung übersprungen' );
			continue;
		}
		preg_match_all( '~/wp-content/uploads/(2019/05/[^"\'\s)]+\.(?:webp|png|jpe?g))~', $html, $matches );
		foreach ( array_unique( $matches[1] ) as $relative ) {
			check( is_file( Attachment_Files::path( $relative ) ), 'Bild der Testseite vorhanden: ' . $relative );
		}
	}

	WP_CLI::log( 'Rückgängig …' );
	$result = Rollback::rollback_rows( $rows, new Url_Matcher( Attachment_Files::baseurl() ) );
	check( empty( $result['errors'] ), 'Rückgängig ohne Fehler' );
	wp_cache_flush();

	$after = snapshot();

	WP_CLI::log( 'Nach dem Rückgängig:' );
	check( $before['posts'] === $after['posts'], 'Beiträge (Inhalt, Auszug, MIME, guid) wie vorher' );
	check( $before['theme_mods'] === $after['theme_mods'], 'Theme-Einstellungen wie vorher' );
	check( $before['custom_css'] === $after['custom_css'], 'Customizer-CSS wie vorher' );
	check( $before['files'] === $after['files'], 'Dateien im Upload-Ordner wie vorher (WebP gelöscht)' );

	$meta_before = array();
	foreach ( $before['meta'] as $entry ) {
		$meta_before[ $entry['post_id'] . '|' . $entry['meta_key'] ] = $entry['meta_value'];
	}
	$meta_after = array();
	foreach ( $after['meta'] as $entry ) {
		$meta_after[ $entry['post_id'] . '|' . $entry['meta_key'] ] = $entry['meta_value'];
	}
	$differences = array_keys( array_diff_assoc( $meta_before, $meta_after ) + array_diff_assoc( $meta_after, $meta_before ) );
	check( empty( $differences ), 'Postmeta wie vorher' . ( $differences ? ': ' . implode( ', ', $differences ) : '' ) );

	if ( $GLOBALS['akwu_failures'] > 0 ) {
		WP_CLI::error( sprintf( '%d Prüfungen fehlgeschlagen.', $GLOBALS['akwu_failures'] ) );
	}

	WP_CLI::success( 'Rundlauf bestanden.' );
}

main();
