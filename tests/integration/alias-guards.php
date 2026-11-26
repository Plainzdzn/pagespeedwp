<?php
/**
 * Integrationstest: Schutz für Mediathek-Einträge mit gemeinsamer umgewandelter Datei.
 *
 * Aufruf auf einer Testinstallation:
 *   wp eval-file tests/integration/alias-guards.php
 *
 * Arbeitet nur mit eigens erzeugten, entbehrlichen Testbildern (Meta `_akwu_guard_test`, Dateien `akwu-guard-*`).
 * Geprüft wird:
 * - Einen von zwei Einträgen endgültig löschen: Der andere behält Datei, Größen und HEIC-Master und lässt sich zurücksetzen.
 * - Ohne die Hooks des Plugins (deaktiviert) verhält sich WordPress wie vor der Umwandlung, der Rest lässt sich reparieren.
 * - Ein Eintrag wurde nach der Umwandlung ersetzt oder bearbeitet: Rückgängig bricht für die ganze Gruppe ab, nichts ändert sich.
 * - Wiederaufnahme nach Abbruch lässt ein zwischenzeitlich ersetztes Bild in Ruhe.
 * - Einträge mit anderen Größennamen werden nicht gemeinsam umgestellt.
 *
 * Für den Test mit Protokollzeilen einer älteren Version: erst mit altem Plugin-Code `AKWU_GUARD_STAGE=prepare`,
 * dann mit neuem Code `AKWU_GUARD_STAGE=verify`.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration\AliasGuards;

use Akuma\WebpUmwandler\Aliases;
use Akuma\WebpUmwandler\Attachment_Files;
use Akuma\WebpUmwandler\Conversion;
use Akuma\WebpUmwandler\Converter;
use Akuma\WebpUmwandler\Job;
use Akuma\WebpUmwandler\Log_Table;
use Akuma\WebpUmwandler\Originals;
use Akuma\WebpUmwandler\Report;
use Akuma\WebpUmwandler\Rollback;
use Akuma\WebpUmwandler\Url_Matcher;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Abbruch: nur auf Testinstallationen.' );
}

const META_KEY = '_akwu_guard_test';
const OPTION   = 'akwu_guard_upgrade';

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
 * Schreibt ein echtes JPG mit Struktur.
 *
 * @param string $path   Ziel.
 * @param int    $width  Breite.
 * @param int    $height Höhe.
 * @return void
 */
function write_jpeg( $path, $width, $height ) {
	$image = imagecreatetruecolor( $width, $height );
	for ( $i = 0; $i < 40; $i++ ) {
		imagefilledellipse( $image, wp_rand( 0, $width ), wp_rand( 0, $height ), wp_rand( 60, 400 ), wp_rand( 60, 400 ), imagecolorallocate( $image, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
	}
	imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
	imagejpeg( $image, $path, 92 );
	imagedestroy( $image );
}

/**
 * Legt einen Anhang auf eine vorhandene JPG-Datei.
 *
 * @param string $path  Absoluter Pfad.
 * @param string $title Titel.
 * @param string $mime  MIME-Typ am Anhang.
 * @param array  $extra Zusätzliche Metadaten.
 * @return int
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
	wp_update_attachment_metadata( $attachment_id, array_merge( wp_generate_attachment_metadata( $attachment_id, $path ), $extra ) );
	Converter::set_mime( $attachment_id, $mime );

	return (int) $attachment_id;
}

/**
 * Zweiter Eintrag auf dieselbe Datei, wie WPML ihn für eine Übersetzung anlegt.
 *
 * @param int    $source_id Vorhandener Anhang.
 * @param string $title     Titel.
 * @return int
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
 * Zwei Einträge A und B auf dieselbe Datei, dazu eine Seite, die das Bild verwendet.
 *
 * @param string $tag  Kurzname der Gruppe, Teil der Dateinamen.
 * @param bool   $heic Als HEIC hochgeladen (JPG auf dem Server, HEIC-Master daneben).
 * @return array{tag: string, a: int, b: int, page: int}
 */
function group( $tag, $heic = true ) {
	$upload = wp_upload_dir();
	$base   = $upload['path'] . '/akwu-guard-' . $tag;

	if ( $heic ) {
		file_put_contents( $base . '.heic', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . random_bytes( 20000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Nur Test.
		write_jpeg( $base . '-scaled.jpg', 900, 1200 );
		$a = attach( $base . '-scaled.jpg', 'Guard ' . $tag . ' A', 'image/heic', array( 'original_image' => 'akwu-guard-' . $tag . '.heic' ) );
	} else {
		write_jpeg( $base . '.jpg', 1200, 900 );
		$a = attach( $base . '.jpg', 'Guard ' . $tag . ' A', 'image/jpeg' );
	}
	$b = duplicate( $a, 'Guard ' . $tag . ' B' );

	$page = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Guard ' . $tag,
			'post_content' => '<img class="wp-image-' . $a . '" src="' . $upload['url'] . '/' . wp_basename( get_attached_file( $a ) ) . '" alt="">',
		)
	);
	update_post_meta( $page, META_KEY, 1 );

	return array(
		'tag'  => $tag,
		'a'    => $a,
		'b'    => $b,
		'page' => (int) $page,
	);
}

/**
 * Zustand einer Gruppe: Einträge, Seite und alle ihre Dateien mit Prüfsumme.
 *
 * @param array $group Gruppe.
 * @param int[] $ids   Einträge, deren Zustand zählt (Standard: A und B).
 * @return array
 */
function state( array $group, $ids = null ) {
	wp_cache_flush();
	clearstatcache();

	$upload = wp_upload_dir();
	$state  = array(
		'posts' => array(),
		'files' => array(),
		'page'  => get_post_field( 'post_content', $group['page'] ),
	);

	foreach ( null === $ids ? array( $group['a'], $group['b'] ) : $ids as $attachment_id ) {
		$state['posts'][ $attachment_id ] = array(
			get_post_mime_type( $attachment_id ),
			get_post_meta( $attachment_id, '_wp_attached_file', true ),
			wp_get_attachment_metadata( $attachment_id, true ),
		);
	}
	foreach ( (array) glob( $upload['path'] . '/akwu-guard-' . $group['tag'] . '*' ) as $file ) {
		$state['files'][ wp_basename( $file ) ] = hash_file( 'sha256', $file );
	}
	ksort( $state['files'] );

	return $state;
}

/**
 * Dateien eines Eintrags mit Prüfsumme, fehlende als „fehlt“.
 *
 * @param int $attachment_id Attachment-ID.
 * @return array<string, string>
 */
function files_of( $attachment_id ) {
	clearstatcache();

	$out = array();
	foreach ( (array) Attachment_Files::of( $attachment_id ) as $name => $relative ) {
		$path         = Attachment_Files::path( $relative );
		$out[ $name ] = is_file( $path ) ? hash_file( 'sha256', $path ) : 'fehlt';
	}

	return $out;
}

/**
 * Wandelt die genannten Bilder um und wartet auf das Ende des Laufs.
 *
 * @param int[] $ids Attachment-IDs.
 * @return array Lauf.
 */
function convert( array $ids ) {
	$run = Conversion::start( 'ids', $ids );
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
 * Letzte Protokollzeile eines Bildes.
 *
 * @param int $attachment_id Attachment-ID.
 * @return array|null
 */
function row( $attachment_id ) {
	$rows = Log_Table::latest_by_attachment( array( (int) $attachment_id ) );

	return isset( $rows[ $attachment_id ] ) ? $rows[ $attachment_id ] : null;
}

/**
 * Rückgängig für einzelne Bilder, direkt über die Kernklasse, mit Ergebnis.
 *
 * @param int[] $attachment_ids Attachment-IDs.
 * @return array{done: int[], errors: array<int, string>, replacements: int}
 */
function rollback( array $attachment_ids ) {
	$rows = array();
	foreach ( $attachment_ids as $attachment_id ) {
		$rows[] = row( $attachment_id );
	}

	return Rollback::rollback_rows( array_filter( $rows ), new Url_Matcher( Attachment_Files::baseurl() ) );
}

/**
 * Ein Redakteur ersetzt das Medium: neue Datei, neue Metadaten, anderer MIME-Typ.
 *
 * @param int    $attachment_id Attachment-ID.
 * @param string $tag           Gruppe.
 * @return string Dateiname der neuen Datei.
 */
function replace_medium( $attachment_id, $tag ) {
	$upload = wp_upload_dir();
	$path   = $upload['path'] . '/akwu-guard-' . $tag . '-ersatz-' . $attachment_id . '.jpg';

	write_jpeg( $path, 640, 480 );
	update_attached_file( $attachment_id, $path );
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
	Converter::set_mime( $attachment_id, 'image/jpeg' );

	return wp_basename( $path );
}

/**
 * Stellt nach, dass ein Lauf mitten in der Umwandlung abgebrochen ist.
 *
 * @param array   $run    Lauf.
 * @param array[] $rows   Zeilen, die als „in Arbeit“ gelten sollen.
 * @param array   $change Zusätzliche Felder für die Zeilen.
 * @return void
 */
function interrupt( array $run, array $rows, array $change = array() ) {
	foreach ( $rows as $row ) {
		Log_Table::update( $row['id'], array_merge( array( 'status' => 'working' ), $change ) );
	}
	$stored           = get_option( 'akwu_run' );
	$stored['status'] = 'running';
	$stored['phase']  = 'convert';
	update_option( 'akwu_run', $stored, false );
}

/**
 * Hooks des Plugins an- oder abschalten (abgeschaltet entspricht einem deaktivierten Plugin).
 *
 * @param bool $on Anschalten.
 * @return void
 */
function hooks( $on ) {
	if ( $on ) {
		add_action( 'delete_attachment', array( Originals::class, 'on_delete_attachment' ) );
		add_action( 'delete_attachment', array( Aliases::class, 'before_delete' ), 5 );
		add_action( 'deleted_post', array( Aliases::class, 'mark_deleted' ) );
		add_action( 'clean_post_cache', array( Aliases::class, 'after_delete' ) );
		add_filter( 'wp_delete_file', array( Aliases::class, 'keep_shared' ), PHP_INT_MAX );
	} else {
		remove_action( 'delete_attachment', array( Originals::class, 'on_delete_attachment' ) );
		remove_action( 'delete_attachment', array( Aliases::class, 'before_delete' ), 5 );
		remove_action( 'deleted_post', array( Aliases::class, 'mark_deleted' ) );
		remove_action( 'clean_post_cache', array( Aliases::class, 'after_delete' ) );
		remove_filter( 'wp_delete_file', array( Aliases::class, 'keep_shared' ), PHP_INT_MAX );
	}
}

/**
 * Räumt Testdaten ab, auch die eines früheren Laufs.
 *
 * @return void
 */
function cleanup() {
	$upload = wp_upload_dir();
	$posts  = get_posts(
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
	foreach ( (array) glob( $upload['path'] . '/akwu-guard-*' ) as $file ) {
		wp_delete_file( $file );
	}
	delete_option( OPTION );
}

/**
 * Einen von zwei Einträgen endgültig löschen, der andere bleibt vollständig und lässt sich zurücksetzen.
 *
 * @param array  $group  Umgewandelte Gruppe.
 * @param array  $before Zustand vor der Umwandlung.
 * @param string $delete a oder b: welcher Eintrag gelöscht wird.
 * @return void
 */
function delete_one_keep_other( array $group, array $before, $delete ) {
	$gone   = $group[ $delete ];
	$stays  = $group[ 'a' === $delete ? 'b' : 'a' ];
	$files  = files_of( $stays );
	$meta   = wp_get_attachment_metadata( $stays, true );
	$master = isset( $files['original_image'] ) ? $files['original_image'] : '';

	wp_delete_attachment( $gone, true );

	check( null === get_post( $gone ) && files_of( $stays ) === $files && ! in_array( 'fehlt', $files, true ), 'Der andere Eintrag behält Hauptdatei, alle Größen und Original bytegleich' );
	check( wp_get_attachment_metadata( $stays, true ) === $meta && 'image/webp' === get_post_mime_type( $stays ), 'Seine Metadaten sind unverändert' );
	$src = wp_get_attachment_image_src( $stays, 'medium' );
	check( is_array( $src ) && is_file( Attachment_Files::path( Attachment_Files::relative( str_replace( Attachment_Files::baseurl() . '/', '', $src[0] ) ) ) ), 'Die Größe „medium“ ist weiter abrufbar' );
	check( '' === $master || ( 'fehlt' !== $master && $before['files'][ 'akwu-guard-' . $group['tag'] . '.heic' ] === $master ), 'HEIC-Master unverändert vorhanden' );
	check( null === row( $gone ) && 'done' === row( $stays )['status'] && (int) row( $stays )['bytes_after'] > 0, 'Protokoll: gelöschter Eintrag entfernt, der andere führt die Größenangaben' );

	$result = rollback( array( $stays ) );
	$after  = state( $group, array( $stays ) );
	check( array( $stays ) === $result['done'] && empty( $result['errors'] ), 'Der verbliebene Eintrag lässt sich zurücksetzen' );
	check( $before['posts'][ $stays ] === $after['posts'][ $stays ], 'Er steht wieder wie vor der Umwandlung (Datei, Metadaten, MIME-Typ)' );
	check( $before['files'] === $after['files'] && $before['page'] === $after['page'], 'Alle Originaldateien bytegleich, WebP entfernt, Seiteninhalt wie vorher' );
}

/**
 * Nach einer Abweichung in der Gruppe: Rückgängig bricht ab und ändert nichts.
 *
 * @param array  $group Gruppe.
 * @param int[]  $ask   Einträge, für die Rückgängig verlangt wird.
 * @param string $label Beschreibung des Falls.
 * @param int[]  $ids   Einträge, deren Zustand zählt.
 * @return void
 */
function expect_abort( array $group, array $ask, $label, $ids = null ) {
	$before = state( $group, $ids );
	$result = rollback( $ask );
	$after  = state( $group, $ids );
	$count  = null === $ids ? 2 : count( $ids );

	check( empty( $result['done'] ) && count( $result['errors'] ) === $count && 0 === (int) $result['replacements'], $label . ': Rückgängig bricht für die ganze Gruppe ab' );
	check( $before === $after, $label . ': Einträge, Metadaten, Seite und alle Dateien unverändert' );
}

/**
 * Prüfungen mit Protokollzeilen, die eine ältere Version geschrieben hat.
 *
 * @return void
 */
function verify_upgrade() {
	$stored = get_option( OPTION );
	if ( ! is_array( $stored ) ) {
		WP_CLI::error( 'Keine vorbereiteten Gruppen. Erst mit AKWU_GUARD_STAGE=prepare unter der alten Version laufen lassen.' );
	}

	WP_CLI::log( 'Zeilen der Version ' . $stored['version'] . ', geprüft mit ' . AKWU_VERSION . ':' );

	$report = Report::load()->rollbackable();
	$ids    = array( $stored['g1']['a'], $stored['g1']['b'], $stored['g2']['a'], $stored['g2']['b'] );
	check( empty( array_intersect_key( $report['blocked'], array_flip( $ids ) ) ), 'Unveränderte Gruppen aus der alten Version gelten weiter als zurücksetzbar' );

	replace_medium( $stored['g1']['b'], $stored['g1']['tag'] );
	expect_abort( $stored['g1'], array( $stored['g1']['a'] ), 'Alte Zeilen, Zweiteintrag ersetzt' );

	delete_one_keep_other( $stored['g2'], $stored['before2'], 'a' );
}

/**
 * Ablauf.
 *
 * @return void
 */
function main() {
	$GLOBALS['akwu_failures'] = 0;

	$stage  = (string) getenv( 'AKWU_GUARD_STAGE' );
	$upload = wp_upload_dir();
	wp_mkdir_p( $upload['path'] );

	if ( 'verify' === $stage ) {
		verify_upgrade();
		cleanup();
		finish();
		return;
	}

	cleanup();

	if ( 'prepare' === $stage ) {
		$g1      = group( 'alt1' );
		$g2      = group( 'alt2' );
		$before2 = state( $g2 );
		convert( array( $g1['a'], $g2['a'] ) );
		check( 'image/webp' === get_post_mime_type( $g1['b'] ) && 'image/webp' === get_post_mime_type( $g2['b'] ), 'Zwei Gruppen mit Version ' . AKWU_VERSION . ' umgewandelt' );
		update_option(
			OPTION,
			array(
				'version' => AKWU_VERSION,
				'g1'      => $g1,
				'g2'      => $g2,
				'before2' => $before2,
			),
			false
		);
		finish();
		return;
	}

	WP_CLI::log( 'Ohne die Hooks des Plugins (wie deaktiviert), gewöhnliches JPG:' );
	$g      = group( 'aus', false );
	$before = state( $g );
	convert( array( $g['a'] ) );
	$files = files_of( $g['b'] );
	hooks( false );
	wp_delete_attachment( $g['a'], true );
	hooks( true );
	check( in_array( 'fehlt', files_of( $g['b'] ), true ) && ! in_array( 'fehlt', $files, true ), 'WordPress löscht dem verbliebenen Eintrag die gemeinsamen Dateien (Verhalten des Kerns, wie vor der Umwandlung)' );
	check( is_array( row( $g['a'] ) ) && null === get_post( $g['a'] ), 'Die Protokollzeile des gelöschten Eintrags bleibt verwaist zurück' );
	$result = rollback( array( $g['b'] ) );
	$after  = state( $g, array( $g['b'] ) );
	check( array( $g['b'] ) === $result['done'] && $before['posts'][ $g['b'] ] === $after['posts'][ $g['b'] ] && $before['files'] === $after['files'], 'Mit wieder aktivem Plugin stellt Rückgängig den verbliebenen Eintrag auf die erhaltenen Originale zurück' );
	$orphan = rollback( array( $g['a'] ) );
	check( empty( $orphan['done'] ) && isset( $orphan['errors'][ $g['a'] ] ), 'Rückgängig für den gelöschten Eintrag wird abgelehnt' );

	WP_CLI::log( 'Mit Schutz: zuerst umgewandelten Eintrag A löschen (HEIC-Upload):' );
	$g      = group( 'la' );
	$before = state( $g );
	convert( array( $g['a'] ) );
	delete_one_keep_other( $g, $before, 'a' );

	WP_CLI::log( 'Mit Schutz: mit umgestellten Eintrag B löschen (HEIC-Upload):' );
	$g      = group( 'lb' );
	$before = state( $g );
	convert( array( $g['a'] ) );
	delete_one_keep_other( $g, $before, 'b' );

	WP_CLI::log( 'Mit Schutz: andere Reihenfolge, B wurde umgewandelt und A zog mit, A wird gelöscht:' );
	$g      = group( 'lc', false );
	$before = state( $g );
	convert( array( $g['b'] ) );
	delete_one_keep_other( $g, $before, 'a' );

	WP_CLI::log( 'Beide Einträge im selben Aufruf löschen:' );
	$g = group( 'beide' );
	convert( array( $g['a'] ) );
	wp_delete_attachment( $g['a'], true );
	wp_delete_attachment( $g['b'], true );
	clearstatcache();
	check( array() === glob( $upload['path'] . '/akwu-guard-beide*' ), 'Nach dem letzten Eintrag bleibt keine Datei zurück, auch keine alten Originale' );

	WP_CLI::log( 'Medium ersetzt, danach Rückgängig:' );
	$g = group( 'ersatz-b' );
	convert( array( $g['a'] ) );
	$name = replace_medium( $g['b'], $g['tag'] );
	expect_abort( $g, array( $g['a'] ), 'B ersetzt, Rückgängig für A' );
	expect_abort( $g, array( $g['b'] ), 'B ersetzt, Rückgängig für B' );
	check( is_file( $upload['path'] . '/' . $name ) && wp_basename( get_attached_file( $g['b'] ) ) === $name, 'Die Ersatzdatei und ihr Eintrag bestehen weiter' );
	$blocked = Report::load()->rollbackable()['blocked'];
	check( isset( $blocked[ $g['a'] ], $blocked[ $g['b'] ] ) && false !== strpos( $blocked[ $g['a'] ], (string) $g['b'] ), 'In der Liste sind beide gesperrt, bei A mit Verweis auf B' );

	$g = group( 'ersatz-a', false );
	convert( array( $g['a'] ) );
	replace_medium( $g['a'], $g['tag'] );
	expect_abort( $g, array( $g['b'] ), 'A ersetzt, Rückgängig für B' );
	expect_abort( $g, array( $g['a'], $g['b'] ), 'A ersetzt, Rückgängig für beide' );

	WP_CLI::log( 'Nur Metadaten geändert, zusätzliche Größe:' );
	$g = group( 'meta' );
	convert( array( $g['a'] ) );
	$meta = wp_get_attachment_metadata( $g['b'], true );
	copy( get_attached_file( $g['b'] ), $upload['path'] . '/akwu-guard-meta-poster.webp' );
	$meta['sizes']['poster'] = array(
		'file'      => 'akwu-guard-meta-poster.webp',
		'width'     => 900,
		'height'    => 1200,
		'mime-type' => 'image/webp',
	);
	update_post_meta( $g['b'], '_wp_attachment_metadata', wp_slash( $meta ) );
	expect_abort( $g, array( $g['a'] ), 'Zusätzliche Größe bei B' );

	WP_CLI::log( 'Inhalt der gemeinsamen Datei unter gleichem Namen ersetzt:' );
	$g = group( 'inhalt', false );
	convert( array( $g['a'] ) );
	write_jpeg( $upload['path'] . '/akwu-guard-inhalt-tmp.jpg', 300, 200 );
	copy( $upload['path'] . '/akwu-guard-inhalt-tmp.jpg', get_attached_file( $g['a'] ) );
	expect_abort( $g, array( $g['b'] ), 'Datei überschrieben' );

	WP_CLI::log( 'Ein Eintrag gelöscht, der andere danach ersetzt:' );
	$g = group( 'rest' );
	convert( array( $g['a'] ) );
	wp_delete_attachment( $g['a'], true );
	replace_medium( $g['b'], $g['tag'] );
	expect_abort( $g, array( $g['b'] ), 'A gelöscht, B ersetzt', array( $g['b'] ) );

	WP_CLI::log( 'Wiederaufnahme nach Abbruch, B wurde inzwischen ersetzt:' );
	$g   = group( 'abbruch' );
	$run = convert( array( $g['a'] ) );
	interrupt( $run, array( row( $g['a'] ), row( $g['b'] ) ) );
	$name   = replace_medium( $g['b'], $g['tag'] );
	$b_then = state( $g, array( $g['b'] ) );
	do {
		$run      = Conversion::step( 30 );
		$progress = Conversion::progress( $run );
	} while ( ! $progress['finished'] );
	$b_now = state( $g, array( $g['b'] ) );
	check( $b_then['posts'] === $b_now['posts'] && $b_then['files'][ $name ] === $b_now['files'][ $name ], 'B und seine Ersatzdatei bleiben unverändert' );
	check( 'error' === row( $g['b'] )['status'] && false !== strpos( row( $g['b'] )['message'], 'Unterbrechung' ), 'B steht mit Grund im Protokoll' );
	check( 'done' === row( $g['a'] )['status'] && 'image/webp' === get_post_mime_type( $g['a'] ) && is_file( get_attached_file( $g['a'] ) ), 'A wurde zurückgestellt und allein neu umgewandelt' );

	WP_CLI::log( 'Wiederaufnahme nach Abbruch bei einem großen Bild (WordPress hat schon auf -scaled umgestellt):' );
	write_jpeg( $upload['path'] . '/akwu-guard-gross.jpg', 3000, 2000 );
	$h      = attach( $upload['path'] . '/akwu-guard-gross.jpg', 'Guard gross', 'image/jpeg' );
	$h_then = array( get_post_meta( $h, '_wp_attached_file', true ), wp_get_attachment_metadata( $h, true ) );
	$run    = convert( array( $h ) );
	$h_row  = row( $h );
	check( false !== strpos( (string) $h_row['new_file'], '-scaled.webp' ) && ! empty( $h_row['new_files']['original_image'] ), 'Großes Bild: Hauptdatei ist die verkleinerte WebP-Fassung' );
	interrupt(
		$run,
		array( $h_row ),
		array(
			'new_file'  => $h_row['new_files']['original_image'],
			'new_files' => array(),
		)
	);
	do {
		$run      = Conversion::step( 30 );
		$progress = Conversion::progress( $run );
	} while ( ! $progress['finished'] );
	check( 'done' === row( $h )['status'] && 2 === (int) row( $h )['attempts'] && 'image/webp' === get_post_mime_type( $h ), 'Es wird zurückgestellt und im zweiten Versuch umgewandelt, nicht als fremd liegen gelassen' );
	rollback( array( $h ) );
	check( array( get_post_meta( $h, '_wp_attached_file', true ), wp_get_attachment_metadata( $h, true ) ) === $h_then, 'Danach lässt es sich wie gewohnt zurücksetzen' );

	WP_CLI::log( 'Gleiche Datei, aber anderer Größenname:' );
	$g    = group( 'name', false );
	$meta = wp_get_attachment_metadata( $g['b'], true );
	check( isset( $meta['sizes']['medium'] ), 'Testbild hat die Größe „medium“' );
	$meta['sizes']['poster'] = $meta['sizes']['medium'];
	unset( $meta['sizes']['medium'] );
	update_post_meta( $g['b'], '_wp_attachment_metadata', wp_slash( $meta ) );
	$before = state( $g );
	convert( array( $g['a'] ) );
	check( 'skipped' === row( $g['a'] )['status'] && false !== strpos( row( $g['a'] )['message'], (string) $g['b'] ), 'Die Gruppe wird mit Grund ausgelassen' );
	check( state( $g ) === $before, 'Nichts geschrieben: B behält „poster“, A behält „medium“' );
	$poster = wp_get_attachment_image_src( $g['b'], 'poster' );
	check( is_array( $poster ) && false !== strpos( $poster[0], '-300x' ), 'Die eigene Größe von B liefert weiter die kleine Datei, nicht das ganze Bild' );

	cleanup();
	finish();
}

/**
 * Abschlussmeldung.
 *
 * @return void
 */
function finish() {
	if ( $GLOBALS['akwu_failures'] > 0 ) {
		WP_CLI::error( sprintf( '%d Prüfungen fehlgeschlagen.', $GLOBALS['akwu_failures'] ) );
	}
	WP_CLI::success( 'Schutz für gemeinsame Dateien bestanden.' );
}

main();
