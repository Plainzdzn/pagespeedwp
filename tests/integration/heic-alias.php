<?php
/**
 * Integrationstest: HEIC-Einträge mit echter JPG-Datei und Anhänge mit gemeinsamer Datei (WPML-Duplikate).
 *
 * Aufruf auf einer Testinstallation:
 *   wp eval-file tests/integration/heic-alias.php
 *
 * Legt eigene Testbilder an (Meta `_akwu_heic_test`) und räumt sie am Ende wieder ab.
 * Geprüft wird: Erkennung im Scan, gemeinsame Umstellung, Verweise, Rückgängig mit Datei-Hashes,
 * Wiederholung nach einem Abbruch, Löschen der Originale mit erhaltenem HEIC-Master.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration\HeicAlias;

use Akuma\WebpUmwandler\Attachment_Files;
use Akuma\WebpUmwandler\Conversion;
use Akuma\WebpUmwandler\Converter;
use Akuma\WebpUmwandler\Inventory;
use Akuma\WebpUmwandler\Job;
use Akuma\WebpUmwandler\Log_Table;
use Akuma\WebpUmwandler\Originals;
use Akuma\WebpUmwandler\Scan_Result;
use Akuma\WebpUmwandler\Scanner;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Abbruch: nur auf Testinstallationen.' );
}

const META_KEY = '_akwu_heic_test';

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
 * Kopf einer echten HEIC-Datei mit Füllbytes. Kein Bildinhalt, aber eindeutig kein JPG.
 *
 * @param int $bytes Größe.
 * @return string
 */
function heic_bytes( $bytes ) {
	return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . random_bytes( $bytes );
}

/**
 * Schreibt ein echtes JPG mit Struktur, damit WebP deutlich kleiner wird.
 *
 * @param string $path   Ziel.
 * @param int    $width  Breite.
 * @param int    $height Höhe.
 * @return void
 */
function write_jpeg( $path, $width, $height ) {
	$image = imagecreatetruecolor( $width, $height );
	for ( $i = 0; $i < 60; $i++ ) {
		imagefilledellipse( $image, wp_rand( 0, $width ), wp_rand( 0, $height ), wp_rand( 80, 600 ), wp_rand( 80, 600 ), imagecolorallocate( $image, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
	}
	imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
	imagejpeg( $image, $path, 92 );
	imagedestroy( $image );
}

/**
 * Legt einen Anhang auf eine vorhandene Datei, mit Metadaten und Größen.
 *
 * @param string $path  Absoluter Pfad der Hauptdatei.
 * @param string $title Titel.
 * @param string $mime  MIME-Typ, der am Ende am Anhang steht.
 * @param array  $extra Zusätzliche Metadaten (z. B. original_image).
 * @return int Attachment-ID.
 */
function attach( $path, $title, $mime, array $extra = array() ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = wp_insert_attachment(
		array(
			'post_title'     => $title,
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		),
		$path
	);
	update_post_meta( $attachment_id, META_KEY, 1 );

	if ( 'jpg' === Attachment_Files::real_kind( $path ) ) {
		$meta = wp_generate_attachment_metadata( $attachment_id, $path );
	} else {
		$meta = array( 'file' => Attachment_Files::relative( $path ) );
	}
	wp_update_attachment_metadata( $attachment_id, array_merge( $meta, $extra ) );
	Converter::set_mime( $attachment_id, $mime );

	return (int) $attachment_id;
}

/**
 * Zweiter Eintrag auf dieselbe Datei, wie WPML ihn für eine Übersetzung anlegt.
 *
 * @param int    $source_id Vorhandener Anhang.
 * @param string $title     Titel.
 * @return int Attachment-ID.
 */
function duplicate( $source_id, $title ) {
	$attachment_id = wp_insert_post(
		array(
			'post_type'      => 'attachment',
			'post_title'     => $title,
			'post_mime_type' => get_post_mime_type( $source_id ),
			'post_status'    => 'inherit',
		)
	);
	update_post_meta( $attachment_id, META_KEY, 1 );
	update_post_meta( $attachment_id, '_wp_attached_file', wp_slash( get_post_meta( $source_id, '_wp_attached_file', true ) ) );
	update_post_meta( $attachment_id, '_wp_attachment_metadata', wp_slash( wp_get_attachment_metadata( $source_id, true ) ) );

	return (int) $attachment_id;
}

/**
 * Zustand aller Testanhänge und der Testseite, zum Vergleich vorher und nachher.
 *
 * @param int[]  $ids     Attachment-IDs.
 * @param int    $page_id Testseite.
 * @param string $dir    Ordner mit den Testdateien.
 * @return array
 */
function snapshot( array $ids, $page_id, $dir ) {
	wp_cache_flush();

	$state = array(
		'posts' => array(),
		'files' => array(),
		'page'  => array( get_post_field( 'post_content', $page_id ), get_post_meta( $page_id, '_elementor_data', true ) ),
	);

	foreach ( $ids as $attachment_id ) {
		$state['posts'][ $attachment_id ] = array(
			get_post_mime_type( $attachment_id ),
			get_post_meta( $attachment_id, '_wp_attached_file', true ),
			wp_get_attachment_metadata( $attachment_id, true ),
		);
	}

	foreach ( (array) glob( $dir . '/akwu-heic-*' ) as $file ) {
		$state['files'][ wp_basename( $file ) ] = hash_file( 'sha256', $file );
	}
	ksort( $state['files'] );

	return $state;
}

/**
 * Scan bis zum Ende.
 *
 * @return Scan_Result
 */
function scan() {
	Scanner::start();
	do {
		$state = Scanner::step( 30 );
	} while ( 'done' !== $state['phase'] );

	return Scan_Result::load();
}

/**
 * Umwandlung aller offenen Bilder bis zum Ende.
 *
 * @return array Lauf.
 */
function convert_all() {
	$run = Conversion::start( 'all' );
	if ( is_wp_error( $run ) ) {
		WP_CLI::error( $run->get_error_message() );
	}
	do {
		$run      = Conversion::step( 30 );
		$progress = Conversion::progress( $run );
	} while ( ! $progress['finished'] );

	return $run;
}

/**
 * Räumt Testdaten ab, auch die eines früheren Laufs.
 *
 * @param string $dir Ordner mit den Testdateien.
 * @return void
 */
function cleanup( $dir ) {
	$posts = get_posts(
		array(
			'post_type'      => array( 'attachment', 'page' ),
			'post_status'    => 'any',
			'meta_key'       => META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Nur Test.
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	// Protokollzeilen der Testbilder entfernt das Plugin beim Löschen der Anhänge selbst.
	foreach ( (array) glob( $dir . '/akwu-heic-*' ) as $file ) {
		wp_delete_file( $file );
	}
}

/**
 * Ablauf.
 *
 * @return void
 */
function main() {
	$GLOBALS['akwu_failures'] = 0;

	$upload = wp_upload_dir();
	$dir    = $upload['path'];
	$url    = $upload['url'];
	wp_mkdir_p( $dir );
	cleanup( $dir );

	WP_CLI::log( 'Testbilder anlegen …' );

	// A: als HEIC hochgeladen, WordPress hat ein JPG daraus gemacht. B: zweiter Eintrag auf dieselbe Datei.
	$master = heic_bytes( 60000 );
	file_put_contents( $dir . '/akwu-heic-foto.heic', $master ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Nur Test.
	write_jpeg( $dir . '/akwu-heic-foto-scaled.jpg', 1920, 2560 );
	$a = attach( $dir . '/akwu-heic-foto-scaled.jpg', 'HEIC-Upload mit JPG', 'image/heic', array( 'original_image' => 'akwu-heic-foto.heic' ) );
	$b = duplicate( $a, 'HEIC-Upload mit JPG (Übersetzung)' );

	// C: echte HEIC ohne JPG. D: echte HEIC, die nur wie ein JPG heißt.
	file_put_contents( $dir . '/akwu-heic-echt.heic', heic_bytes( 30000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Nur Test.
	$c = attach( $dir . '/akwu-heic-echt.heic', 'Echte HEIC', 'image/heic' );
	file_put_contents( $dir . '/akwu-heic-getarnt-scaled.jpg', heic_bytes( 30000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Nur Test.
	$d = attach( $dir . '/akwu-heic-getarnt-scaled.jpg', 'HEIC mit JPG-Endung', 'image/heic' );

	// E und F: gleiche Datei, aber F kennt eine Größe nicht. Das passt nicht zusammen und bleibt unverändert.
	write_jpeg( $dir . '/akwu-heic-ungleich.jpg', 1200, 900 );
	$e      = attach( $dir . '/akwu-heic-ungleich.jpg', 'Gleiche Datei, andere Größen', 'image/jpeg' );
	$f      = duplicate( $e, 'Gleiche Datei, andere Größen (Übersetzung)' );
	$f_meta = wp_get_attachment_metadata( $f, true );
	unset( $f_meta['sizes']['medium'] );
	update_post_meta( $f, '_wp_attachment_metadata', wp_slash( $f_meta ) );

	// H: gewöhnliches großes JPG, von WordPress auf 2560 Pixel verkleinert, Original daneben.
	write_jpeg( $dir . '/akwu-heic-gross.jpg', 3000, 2000 );
	$h      = attach( $dir . '/akwu-heic-gross.jpg', 'Großes JPG mit Original', 'image/jpeg' );
	$h_meta = wp_get_attachment_metadata( $h, true );

	// Ab hier gilt auf der Website eine kleinere Obergrenze für große Bilder als beim Hochladen.
	add_filter(
		'big_image_size_threshold',
		static function () {
			return 1600;
		}
	);

	$ids      = array( $a, $b, $c, $d, $e, $f, $h );
	$a_meta   = wp_get_attachment_metadata( $a, true );
	$a_medium = $url . '/' . $a_meta['sizes']['medium']['file'];
	$a_full   = $url . '/akwu-heic-foto-scaled.jpg';

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'HEIC-Test',
			'post_content' => '<img class="wp-image-' . $a . '" src="' . $a_medium . '" alt=""><a href="' . $url . '/akwu-heic-foto.heic">Original</a>',
		)
	);
	update_post_meta( $page_id, META_KEY, 1 );
	update_post_meta(
		$page_id,
		'_elementor_data',
		wp_slash(
			wp_json_encode(
				array(
					array(
						'id'       => 'aaaaaaa',
						'elType'   => 'container',
						'settings' => array(
							'background_image' => array(
								'url' => $a_full,
								'id'  => $b,
							),
						),
						'elements' => array(),
					),
				)
			)
		)
	);

	$before = snapshot( $ids, $page_id, $dir );

	WP_CLI::log( 'Scan:' );
	$result = scan();
	$item_a = $result->item( $a );
	$item_b = $result->item( $b );
	check( 'jpg' === $item_a['kind'] && 'ready' === $item_a['status'] && 'heic' === $item_a['container'], 'HEIC-Eintrag mit echter JPG-Datei ist bereit' );
	check( 'ready' === $item_b['status'] && array( $a ) === $item_b['shared'] && ! empty( $item_b['duplicate'] ), 'Zweiter Eintrag auf dieselbe Datei ist erkannt und zählt nicht doppelt' );
	check( 'other' === $result->item( $c )['kind'] && 'ignored' === $result->item( $c )['status'], 'Echte HEIC bleibt außen vor' );
	check( 'other' === $result->item( $d )['kind'] && 'ignored' === $result->item( $d )['status'], 'Echte HEIC mit JPG-Endung bleibt außen vor' );
	check( null === Inventory::real_of( $c ) && null === Inventory::real_of( $d ) && 'jpg' === Inventory::real_of( $a ), 'Direkte Prüfung am Anhang stimmt mit dem Scan überein' );
	check( $item_a['uses'] + $item_b['uses'] >= 2, 'Verweise über Datei und Bild-ID gefunden' );

	WP_CLI::log( 'Umwandlung:' );
	$run  = convert_all();
	$rows = array();
	foreach ( Log_Table::rows( $run['id'] ) as $row ) {
		$rows[ $row['attachment_id'] ] = $row;
	}
	$after = snapshot( $ids, $page_id, $dir );

	check( 'done' === $rows[ $a ]['status'] && 'done' === $rows[ $b ]['status'], 'Beide Einträge sind umgestellt' );
	check( 'image/webp' === $after['posts'][ $a ][0] && 'image/webp' === $after['posts'][ $b ][0], 'Beide Einträge sind WebP' );
	check( $after['posts'][ $a ][1] === $after['posts'][ $b ][1] && '.webp' === substr( $after['posts'][ $a ][1], -5 ), 'Beide zeigen auf dieselbe WebP-Datei' );
	check( $after['posts'][ $a ][2]['sizes'] === $after['posts'][ $b ][2]['sizes'] && ! empty( $after['posts'][ $a ][2]['sizes'] ), 'Beide haben dieselben WebP-Größen' );
	check( 'akwu-heic-foto.heic' === $after['posts'][ $a ][2]['original_image'] && 'akwu-heic-foto.heic' === $after['posts'][ $b ][2]['original_image'], 'HEIC-Master bleibt als Original eingetragen' );
	check( hash( 'sha256', $master ) === $after['files']['akwu-heic-foto.heic'], 'HEIC-Master unverändert auf dem Server' );
	check( $before['files']['akwu-heic-foto-scaled.jpg'] === $after['files']['akwu-heic-foto-scaled.jpg'], 'JPG-Original liegt unverändert daneben' );
	check( 0 === min( $rows[ $a ]['bytes_before'], $rows[ $b ]['bytes_before'] ) && max( $rows[ $a ]['bytes_before'], $rows[ $b ]['bytes_before'] ) > 0, 'Bytes stehen nur bei einem der beiden Einträge' );
	check( $rows[ $a ]['bytes_after'] + $rows[ $b ]['bytes_after'] < $rows[ $a ]['bytes_before'] + $rows[ $b ]['bytes_before'], 'WebP ist kleiner als das JPG' );
	check( false === strpos( $after['page'][0], '.jpg' ) && false !== strpos( $after['page'][0], '.webp' ), 'Verweis im Seiteninhalt zeigt auf WebP' );
	check( false !== strpos( $after['page'][0], 'akwu-heic-foto.heic' ), 'Link auf den HEIC-Master bleibt' );
	$elementor = json_decode( $after['page'][1], true );
	check( is_array( $elementor ) && '.webp' === substr( $elementor[0]['settings']['background_image']['url'], -5 ) && $b === $elementor[0]['settings']['background_image']['id'], 'Elementor-Hintergrund zeigt auf WebP, Bild-ID unverändert' );
	check( $before['posts'][ $c ] === $after['posts'][ $c ] && $before['posts'][ $d ] === $after['posts'][ $d ], 'Echte HEIC-Einträge unverändert' );
	check( ! isset( $rows[ $c ] ) && ! isset( $rows[ $d ] ), 'Echte HEIC-Einträge standen nicht in der Warteschlange' );
	check( 'skipped' === $rows[ $e ]['status'] && 'skipped' === $rows[ $f ]['status'] && false !== strpos( $rows[ $e ]['message'] . $rows[ $f ]['message'], 'andere Metadaten' ), 'Einträge mit ungleichen Größen werden mit Grund ausgelassen' );
	check( $before['posts'][ $e ] === $after['posts'][ $e ] && $before['posts'][ $f ] === $after['posts'][ $f ], 'Ausgelassene Einträge sind unverändert' );
	check( null === Inventory::real_of( $a ), 'Umgewandelter Eintrag gilt nicht mehr als offen' );

	WP_CLI::log( 'Pixelmaße bei kleinerer Obergrenze der Website:' );
	$a_size = getimagesize( Attachment_Files::path( $after['posts'][ $a ][1] ) );
	check( array( 1920, 2560 ) === array( $a_size[0], $a_size[1] ) && 1920 === (int) $after['posts'][ $a ][2]['width'] && 2560 === (int) $after['posts'][ $a ][2]['height'], 'HEIC-Upload: Hauptdatei bleibt 1920 × 2560, wird nicht noch einmal verkleinert' );
	$h_size = getimagesize( Attachment_Files::path( $after['posts'][ $h ][1] ) );
	check( 'akwu-heic-gross.jpg' === $h_meta['original_image'] && 2560 === (int) $h_meta['width'], 'Großes JPG lag vorher als verkleinerte Fassung mit 2560 Pixeln vor' );
	check( array( (int) $h_meta['width'], (int) $h_meta['height'] ) === array( $h_size[0], $h_size[1] ) && '.webp' === substr( (string) $after['posts'][ $h ][2]['original_image'], -5 ), 'Großes JPG: verkleinerte Fassung behält ihre Maße, Original ist WebP' );

	WP_CLI::log( 'Rückgängig nur für einen der beiden Einträge (und das große JPG):' );
	$job = Job::start( 'rollback', array( (int) $rows[ $a ]['id'], (int) $rows[ $h ]['id'] ) );
	if ( is_wp_error( $job ) ) {
		WP_CLI::error( $job->get_error_message() );
	}
	do {
		$job = Job::step( 30 );
	} while ( Job::is_active( $job ) );
	$restored = snapshot( $ids, $page_id, $dir );
	check( $before['posts'] === $restored['posts'], 'Alle Einträge wie vorher (Datei, Metadaten, MIME-Typ), auch der zweite' );
	check( $before['files'] === $restored['files'], 'Dateien und Hashes wie vorher, WebP gelöscht' );
	check( $before['page'] === $restored['page'], 'Seiteninhalt und Elementor-Daten wie vorher' );

	WP_CLI::log( 'Wiederholung nach einem Abbruch mitten in der Umwandlung:' );
	scan();
	$run = convert_all();
	foreach ( Log_Table::rows( $run['id'], array( 'done' ) ) as $row ) {
		Log_Table::update( $row['id'], array( 'status' => 'working' ) );
	}
	$stored           = get_option( 'akwu_run' );
	$stored['status'] = 'running';
	$stored['phase']  = 'convert';
	update_option( 'akwu_run', $stored, false );
	do {
		$run      = Conversion::step( 30 );
		$progress = Conversion::progress( $run );
	} while ( ! $progress['finished'] );
	$retried = snapshot( $ids, $page_id, $dir );
	$rows    = array();
	foreach ( Log_Table::rows( $run['id'], array( 'done' ) ) as $row ) {
		$rows[ $row['attachment_id'] ] = $row;
	}
	check( isset( $rows[ $a ], $rows[ $b ] ) && 2 === (int) $rows[ $a ]['attempts'], 'Beide Einträge nach dem zweiten Versuch umgestellt' );
	check( $retried['posts'][ $a ][1] === $retried['posts'][ $b ][1] && $after['posts'][ $a ][1] === $retried['posts'][ $a ][1], 'Wieder dieselbe WebP-Datei für beide, kein zweiter Satz Dateien' );
	check( array_keys( $after['files'] ) === array_keys( $retried['files'] ), 'Dateiliste wie nach der ersten Umwandlung' );

	WP_CLI::log( 'Originale löschen:' );
	$first = Originals::purge( Log_Table::get( $rows[ $a ]['id'] ) );
	$other = Originals::purge( Log_Table::get( $rows[ $b ]['id'] ) );
	$final = snapshot( $ids, $page_id, $dir );
	check( ! is_wp_error( $first ) && ! is_wp_error( $other ), 'Löschen für beide Einträge ohne Fehler' );
	check( ! isset( $final['files']['akwu-heic-foto-scaled.jpg'] ), 'JPG-Dateien gelöscht' );
	check( isset( $final['files']['akwu-heic-foto.heic'] ) && hash( 'sha256', $master ) === $final['files']['akwu-heic-foto.heic'], 'HEIC-Master bleibt erhalten' );
	check( is_file( Attachment_Files::path( $final['posts'][ $b ][1] ) ) && $retried['posts'] === $final['posts'], 'Beide Einträge zeigen weiter auf vorhandene WebP-Dateien' );

	WP_CLI::log( 'Schutz bei geteilter alter Datei:' );
	// Jetzt passen die Metadaten von E und F zusammen, beide gehen gemeinsam.
	update_post_meta( $f, '_wp_attachment_metadata', wp_slash( wp_get_attachment_metadata( $e, true ) ) );
	scan();
	$run  = convert_all();
	$rows = array();
	foreach ( Log_Table::rows( $run['id'] ) as $row ) {
		$rows[ $row['attachment_id'] ] = $row;
	}
	check( isset( $rows[ $e ], $rows[ $f ] ) && 'done' === $rows[ $e ]['status'] && 'done' === $rows[ $f ]['status'], 'Mit passenden Metadaten gehen beide Einträge zusammen' );
	check( get_post_meta( $e, '_wp_attached_file', true ) === get_post_meta( $f, '_wp_attached_file', true ) && 'image/webp' === get_post_mime_type( $f ), 'Beide zeigen auf dieselbe WebP-Datei' );
	// Ein Eintrag wird von Hand zurück auf die alte Datei gestellt: die alten Dateien werden noch gebraucht.
	update_post_meta( $f, '_wp_attached_file', wp_slash( $rows[ $f ]['old_file'] ) );
	$guard = Originals::purge( Log_Table::get( $rows[ $e ]['id'] ) );
	check( is_wp_error( $guard ) && 'akwu_purge_shared' === $guard->get_error_code() && is_file( $dir . '/akwu-heic-ungleich.jpg' ), 'Alte Dateien bleiben, solange ein anderer Eintrag darauf zeigt' );

	cleanup( $dir );

	if ( $GLOBALS['akwu_failures'] > 0 ) {
		WP_CLI::error( sprintf( '%d Prüfungen fehlgeschlagen.', $GLOBALS['akwu_failures'] ) );
	}
	WP_CLI::success( 'HEIC und gemeinsame Dateien bestanden.' );
}

main();
