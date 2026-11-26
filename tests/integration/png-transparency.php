<?php
/**
 * Integrationstest: PNG nach echter Transparenz kodieren (1.1.3).
 *
 * Aufruf auf einer Testinstallation mit Imagick:
 *   wp eval-file tests/integration/png-transparency.php
 *
 * Legt drei eigene Testbilder an (Meta `_akwu_png_test`) und räumt sie am Ende wieder ab:
 * - deckend: RGBA-PNG, in dem kein Pixel durchsichtig ist (typischer Canva-Export),
 * - freisteller: Foto mit durchsichtigem Rand,
 * - logo: flache Grafik mit durchsichtigem Hintergrund.
 *
 * Geprüft wird mit der Standardeinstellung und mit „automatisch“: Kodierung (WebP-Chunk), Meldung,
 * Pixelmaße, Alphakanal im Ergebnis, danach Rückgängig mit Datei-Hashes wie vorher.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration\PngTransparency;

use Akuma\WebpUmwandler\Conversion;
use Akuma\WebpUmwandler\Job;
use Akuma\WebpUmwandler\Log_Table;
use Akuma\WebpUmwandler\Png_Info;
use Akuma\WebpUmwandler\Scan_Result;
use Akuma\WebpUmwandler\Scanner;
use Akuma\WebpUmwandler\Settings;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Abbruch: nur auf Testinstallationen.' );
}

const META_KEY = '_akwu_png_test';

/**
 * Prüft eine Bedingung und zählt Fehler.
 *
 * @param bool   $condition Erwartung.
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
 * Art der WebP-Datei aus den RIFF-Chunks: lossy, lossless oder lossy-alpha.
 *
 * @param string $path Datei.
 * @return string
 */
function webp_kind( $path ) {
	$data = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Testdatei.
	if ( 'RIFF' !== substr( $data, 0, 4 ) || 'WEBP' !== substr( $data, 8, 4 ) ) {
		return 'kein-webp';
	}
	$chunk = substr( $data, 12, 4 );
	if ( 'VP8 ' === $chunk ) {
		return 'lossy';
	}
	if ( 'VP8L' === $chunk ) {
		return 'lossless';
	}
	if ( 'VP8X' === $chunk ) {
		if ( false !== strpos( $data, 'VP8L' ) ) {
			return 'lossless';
		}
		return false !== strpos( $data, 'ALPH' ) ? 'lossy-alpha' : 'lossy';
	}
	return 'unbekannt';
}

/**
 * Schreibt ein Test-PNG mit Imagick.
 *
 * @param string $path Ziel.
 * @param string $kind deckend, freisteller oder logo.
 * @return void
 */
function write_png( $path, $kind ) {
	if ( 'logo' === $kind ) {
		$image = new \Imagick();
		$image->newImage( 900, 300, new \ImagickPixel( 'transparent' ) );
		$draw = new \ImagickDraw();
		$draw->setFillColor( new \ImagickPixel( '#1c805d' ) );
		$draw->rectangle( 40, 60, 340, 240 );
		$draw->setFillColor( new \ImagickPixel( '#263730' ) );
		$draw->circle( 600, 150, 700, 150 );
		$image->drawImage( $draw );
	} else {
		// Fotoähnlicher Inhalt: Plasma mit Weichzeichner, viele Farben wie bei einem Foto.
		$image = new \Imagick();
		$image->newPseudoImage( 900, 600, 'plasma:fractal' );
		$image->blurImage( 0, 1.2 );
		$image->setImageAlphaChannel( defined( '\Imagick::ALPHACHANNEL_SET' ) ? \Imagick::ALPHACHANNEL_SET : \Imagick::ALPHACHANNEL_ACTIVATE );

		if ( 'freisteller' === $kind ) {
			$mask = new \Imagick();
			$mask->newImage( 900, 600, new \ImagickPixel( 'black' ) );
			$draw = new \ImagickDraw();
			$draw->setFillColor( new \ImagickPixel( 'white' ) );
			$draw->ellipse( 450, 300, 330, 250, 0, 360 );
			$mask->drawImage( $draw );
			$mask->setImageAlphaChannel( defined( '\Imagick::ALPHACHANNEL_COPY' ) ? \Imagick::ALPHACHANNEL_COPY : \Imagick::ALPHACHANNEL_SET );
			$image->compositeImage( $mask, \Imagick::COMPOSITE_COPYOPACITY, 0, 0 );
		}
	}

	$image->setImageFormat( 'PNG32' );
	$image->writeImage( 'png32:' . $path );
}

/**
 * Hängt ein Bild an.
 *
 * @param string $path  Datei.
 * @param string $title Titel.
 * @return int
 */
function attach( $path, $title ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = wp_insert_attachment(
		array(
			'post_title'     => $title,
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
		),
		$path
	);
	update_post_meta( $attachment_id, META_KEY, 1 );
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

	return (int) $attachment_id;
}

/**
 * Hashes aller Testdateien.
 *
 * @param string $dir Ordner.
 * @return array
 */
function hashes( $dir ) {
	$out = array();
	foreach ( (array) glob( $dir . '/akwu-png-*' ) as $file ) {
		$out[ wp_basename( $file ) ] = hash_file( 'sha256', $file );
	}
	ksort( $out );
	return $out;
}

/**
 * Scan und Umwandlung aller offenen Bilder.
 *
 * @return void
 */
function scan_and_convert() {
	Scanner::start();
	do {
		$state = Scanner::step( 30 );
	} while ( 'done' !== $state['phase'] );
	Scan_Result::load();

	$run = Conversion::start( 'all' );
	if ( is_wp_error( $run ) ) {
		WP_CLI::error( $run->get_error_message() );
	}
	do {
		$run      = Conversion::step( 30 );
		$progress = Conversion::progress( $run );
	} while ( ! $progress['finished'] );
}

/**
 * Alles zurücknehmen.
 *
 * @return void
 */
function rollback_all() {
	$job = Job::start( 'rollback', Job::targets( 'rollback' ) );
	if ( is_wp_error( $job ) ) {
		WP_CLI::error( $job->get_error_message() );
	}
	do {
		$job = Job::step( 30 );
	} while ( 'done' !== $job['status'] );
	check( 0 === (int) $job['failed'], sprintf( 'Rückgängig ohne Fehler (%d Bilder)', (int) $job['done'] ) );
}

/**
 * Letzte Protokollzeile eines Anhangs.
 *
 * @param int $attachment_id Anhang.
 * @return array|null
 */
function latest( $attachment_id ) {
	$rows = Log_Table::latest_by_attachment( array( (int) $attachment_id ) );
	return isset( $rows[ (int) $attachment_id ] ) ? $rows[ (int) $attachment_id ] : null;
}

/**
 * Räumt Testdaten ab.
 *
 * @param string $dir Ordner.
 * @return void
 */
function cleanup( $dir ) {
	foreach ( get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_key'       => META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Nur Testdaten.
			'fields'         => 'ids',
		)
	) as $post_id ) {
		wp_delete_attachment( $post_id, true );
	}
	foreach ( (array) glob( $dir . '/akwu-png-*' ) as $file ) {
		wp_delete_file( $file );
	}
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Log_Table::name() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Testinstallation.
	delete_option( 'akwu_scan' );
	delete_option( 'akwu_run' );
	delete_option( 'akwu_job' );
}

/**
 * Prüft die drei Bilder nach einer Umwandlung.
 *
 * @param int[]  $ids      Name => ID.
 * @param array  $expected Name => erwartete Art.
 * @param string $label    Durchlauf.
 * @return void
 */
function verify( array $ids, array $expected, $label ) {
	foreach ( $ids as $name => $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		$row  = latest( $attachment_id );
		check( 'image/webp' === get_post_mime_type( $attachment_id ), "$label: $name ist jetzt WebP" );
		$kind = webp_kind( $file );
		check( $kind === $expected[ $name ], "$label: $name als " . $expected[ $name ] . ' kodiert (ist ' . $kind . ')' );
		$size = wp_getimagesize( $file );
		check( $size && 900 === (int) $size[0], "$label: $name behält die Pixelbreite" );
		$image  = new \Imagick( $file );
		$opaque = Png_Info::imagick_opaque( $image );
		check( ( true === $opaque ) === ( 'deckend' === $name ), "$label: $name ist im WebP " . ( 'deckend' === $name ? 'deckend' : 'weiter durchsichtig' ) );
		check( $row && 'done' === $row['status'], "$label: Protokollzeile für $name ist abgeschlossen (Verweise ersetzt)" );
		WP_CLI::log( '        Meldung ' . $name . ': ' . ( $row ? $row['message'] : '-' ) );
	}
}

/**
 * Ablauf des Tests.
 *
 * @return void
 */
function main() {
	global $akwu_failures;
	$akwu_failures = 0;

	if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) || ! \Imagick::queryFormats( 'WEBP' ) ) {
		WP_CLI::warning( 'Übersprungen: Imagick mit WebP fehlt. Dieser Test braucht Imagick.' );
		return;
	}

	$uploads = wp_upload_dir();
	$dir     = $uploads['basedir'] . '/akwu-png-test';
	wp_mkdir_p( $dir );
	cleanup( $dir );
	delete_option( Settings::OPTION );

	WP_CLI::log( 'PNG-Transparenz: Testbilder anlegen' );
	$ids = array();
	foreach ( array( 'deckend', 'freisteller', 'logo' ) as $name ) {
		$path = $dir . '/akwu-png-' . $name . '.png';
		write_png( $path, $name );
		$ids[ $name ] = attach( $path, 'akwu png ' . $name );
	}
	check( true === Png_Info::has_alpha( $dir . '/akwu-png-deckend.png' ), 'Kopf des deckenden PNG meldet einen Alphakanal (Ausgangslage des Fehlers)' );
	check( true === Png_Info::imagick_opaque( new \Imagick( $dir . '/akwu-png-deckend.png' ) ), 'Pixelprüfung: deckendes PNG hat kein durchsichtiges Pixel' );
	check( false === Png_Info::imagick_opaque( new \Imagick( $dir . '/akwu-png-freisteller.png' ) ), 'Pixelprüfung: Freisteller hat durchsichtige Pixel' );
	check( false === Png_Info::imagick_opaque( new \Imagick( $dir . '/akwu-png-logo.png' ) ), 'Pixelprüfung: Logo hat durchsichtige Pixel' );
	$before = hashes( $dir );

	WP_CLI::log( 'Lauf 1: Standard (PNG mit Transparenz verlustfrei)' );
	scan_and_convert();
	verify(
		$ids,
		array(
			'deckend'     => 'lossy',
			'freisteller' => 'lossless',
			'logo'        => 'lossless',
		),
		'Standard'
	);
	$row = latest( $ids['deckend'] );
	check( $row && false !== strpos( $row['message'], 'verlustbehaftet, Qualität 82' ) && false !== strpos( $row['message'], 'ohne durchsichtige Pixel' ), 'Standard: Meldung nennt verlustbehaftet und den leeren Alphakanal' );

	rollback_all();
	check( hashes( $dir ) === $before, 'Rückgängig nach Lauf 1: alle Testdateien bytegleich wie vorher, keine WebP übrig' );
	foreach ( $ids as $name => $attachment_id ) {
		check( 'image/png' === get_post_mime_type( $attachment_id ), "Rückgängig nach Lauf 1: $name ist wieder PNG" );
	}

	WP_CLI::log( 'Lauf 2: Einstellung „automatisch“' );
	update_option( Settings::OPTION, array_merge( Settings::DEFAULTS, array( 'png_transparent' => 'auto' ) ) );
	scan_and_convert();
	verify(
		$ids,
		array(
			'deckend'     => 'lossy',
			'freisteller' => 'lossy-alpha',
			'logo'        => 'lossless',
		),
		'Automatisch'
	);
	$row = latest( $ids['freisteller'] );
	check( $row && false !== strpos( $row['message'], 'verlustfreiem Alphakanal' ), 'Automatisch: Meldung beim Freisteller nennt den verlustfreien Alphakanal' );
	$row = latest( $ids['logo'] );
	check( $row && false !== strpos( $row['message'], 'nicht mindestens halb so groß' ), 'Automatisch: Logo bleibt verlustfrei, Meldung sagt warum' );
	check( array() === glob( $dir . '/akwu-alpha-*' ), 'Automatisch: keine Zwischendatei übrig' );

	rollback_all();
	check( hashes( $dir ) === $before, 'Rückgängig nach Lauf 2: alle Testdateien bytegleich wie vorher, keine WebP übrig' );

	delete_option( Settings::OPTION );
	cleanup( $dir );
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Leerer Testordner.

	if ( $akwu_failures ) {
		WP_CLI::error( $akwu_failures . ' Prüfungen fehlgeschlagen.' );
	}

	WP_CLI::success( 'PNG-Transparenz bestanden.' );
}

main();
