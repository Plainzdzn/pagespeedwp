<?php
/**
 * Integrationstest: Originale löschen nach der Umwandlung.
 *
 * Nur in der Testinstallation ausführen (legt die Seed-Daten neu an):
 *   wp eval-file tests/integration/purge.php
 *
 * Prüft:
 * - Gelöscht werden Original, -scaled-Quelle, alte Größen (auch die aus einem alten Theme).
 * - Originale, deren alte Adresse noch in CSS steht, bleiben samt Größen.
 * - Wird eine alte Adresse nach der Umwandlung wieder eingefügt, findet die Gegenprobe vor dem
 *   Löschen sie, und das Original bleibt.
 * - Die WebP-Dateien bleiben, alle Bilder der Testseiten sind abrufbar.
 * - Danach ist Rückgängig für die gelöschten Bilder gesperrt, der Bericht zählt sie.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration;

use Akuma\WebpUmwandler\Attachment_Files;
use Akuma\WebpUmwandler\Conversion;
use Akuma\WebpUmwandler\Job;
use Akuma\WebpUmwandler\Report;
use Akuma\WebpUmwandler\Scanner;
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
 * Der Test.
 *
 * @return void
 */
function main() {
	$GLOBALS['akwu_failures'] = 0;
	$dir                      = Attachment_Files::basedir() . '2019/05/';

	WP_CLI::log( 'Testdaten, Scan, Umwandlung …' );
	WP_CLI::runcommand( 'eval-file ' . escapeshellarg( dirname( __DIR__ ) . '/seed/seed.php' ), array( 'return' => true ) );
	wp_cache_flush();

	Scanner::start();
	do {
		$state = Scanner::step( 30 );
	} while ( 'done' !== $state['phase'] );

	Conversion::start( 'all' );
	do {
		$progress = Conversion::progress( Conversion::step( 30 ) );
	} while ( ! $progress['finished'] );

	$report    = Report::load();
	$purgeable = $report->purgeable();
	check( 2 === $purgeable['kept'], 'Zwei Originale bleiben (in Customizer-CSS und Elementor-CSS verwendet)' );

	// Nach der Gegenprobe wird die alte Adresse des Logos wieder eingefügt, z. B. aus einer alten Vorlage kopiert.
	$late = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Später eingefügt',
			'post_content' => '<img src="' . trailingslashit( Attachment_Files::baseurl() ) . '2019/05/logo-transparent.png" alt="">',
		)
	);
	update_post_meta( $late, '_akwu_seed', 1 );

	WP_CLI::log( 'Originale löschen …' );
	check( is_wp_error( Job::targets( 'purge', array(), $purgeable['count'] + 1 ) ), 'Falsche Bestätigungszahl wird abgelehnt' );
	$job = Job::start( 'purge', Job::targets( 'purge', array(), $purgeable['count'] ) );
	do {
		$job = Job::step( 30 );
	} while ( 'done' !== $job['status'] );

	check( 0 === $job['failed'] && $purgeable['count'] - 1 === $job['done'], sprintf( 'Originale von %d Bildern gelöscht', $job['done'] ) );
	check( 1 === $job['kept'], 'Gegenprobe vor dem Löschen hat die später eingefügte Adresse gefunden' );
	check( $job['bytes'] > 0, 'Speicher freigegeben: ' . size_format( $job['bytes'] ) );

	foreach ( array( 'praxis-empfang.jpg', 'praxis-empfang-640x427.jpg', 'praxis-empfang-300x200.jpg', 'panorama.jpg', 'panorama-scaled.jpg', 'team-header.png' ) as $name ) {
		check( ! is_file( $dir . $name ), 'gelöscht: ' . $name );
	}
	foreach ( array( 'logo-transparent.png', 'bild.png', 'bild-300x200.png', 'bild.jpg', 'bild-300x200.jpg', 'bereits-optimiert.jpg', 'ohne-metadaten.png' ) as $name ) {
		check( is_file( $dir . $name ), 'bleibt: ' . $name );
	}
	foreach ( array( 'praxis-empfang.webp', 'praxis-empfang-640x427.webp', 'panorama-scaled.webp', 'panorama.webp', 'team-header.webp', 'bild.webp', 'bild-1.webp' ) as $name ) {
		check( is_file( $dir . $name ), 'WebP vorhanden: ' . $name );
	}

	$report = Report::load();
	check( 3 === $report->totals()['purged'], 'Bericht zählt 3 Bilder ohne Original' );
	check( in_array( 'logo-transparent.png', wp_list_pluck( $report->leftovers(), 'file' ), true ), 'Bericht listet die später eingefügte Adresse unter „Bitte prüfen“' );
	check(
		is_wp_error(
			Job::targets(
				'rollback',
				array(
					(int) get_posts(
						array(
							'post_type'   => 'attachment',
							'title'       => 'Große JPG',
							'numberposts' => 1,
							'fields'      => 'ids',
						)
					)[0],
				)
			)
		),
		'Rückgängig für gelöschte Originale gesperrt'
	);
	check( 3 === count( Job::targets( 'rollback' ) ), 'Rückgängig für die drei behaltenen Originale möglich' );

	$posts = get_posts(
		array(
			'post_type'   => array( 'page', 'post' ),
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_key'    => '_akwu_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Nur Test.
		)
	);
	foreach ( $posts as $post ) {
		$response = wp_remote_get( get_permalink( $post ), array( 'timeout' => 30 ) );
		$html     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			WP_CLI::log( '  –     Seite nicht abrufbar (Server läuft nicht?), Bildprüfung übersprungen' );
			continue;
		}
		preg_match_all( '~/wp-content/uploads/((?:2019/05|elementor/thumbs)/[^"\'\s)]+\.(?:webp|png|jpe?g))~', $html, $matches );
		$missing = array_filter(
			array_unique( $matches[1] ),
			static function ( $relative ) {
				return ! is_file( Attachment_Files::path( $relative ) );
			}
		);
		check( empty( $missing ), sprintf( '%s: alle %d Bilder abrufbar', $post->post_title, count( array_unique( $matches[1] ) ) ) . ( $missing ? ' – fehlt: ' . implode( ', ', $missing ) : '' ) );
	}

	if ( $GLOBALS['akwu_failures'] > 0 ) {
		WP_CLI::error( sprintf( '%d Prüfungen fehlgeschlagen.', $GLOBALS['akwu_failures'] ) );
	}

	WP_CLI::success( 'Originale löschen bestanden.' );
}

main();
