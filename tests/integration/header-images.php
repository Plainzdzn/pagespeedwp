<?php
/**
 * Integrationstest: Bilder der Kopfzeile sofort laden (Fix-Datei schreiben, wirken lassen, zurücknehmen).
 *
 * Aufruf auf einer Testinstallation:
 *   wp eval-file tests/integration/header-images.php
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration\HeaderImages;

use Akuma\WebpUmwandler\Fix_File;
use Akuma\WebpUmwandler\Header_Images;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Abbruch: nur auf Testinstallationen.' );
}

const META_KEY = '_akwu_header_test';

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
 * Kleines Testbild in der Mediathek.
 *
 * @param string $name Dateiname ohne Endung.
 * @return int Bild-ID.
 */
function image( $name ) {
	$upload = wp_upload_dir();
	$file   = trailingslashit( $upload['path'] ) . 'akwu-kopf-' . $name . '.png';
	$img    = imagecreatetruecolor( 240, 80 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 28, 128, 93 ) );
	imagepng( $img, $file );

	$id = wp_insert_attachment(
		array(
			'post_title'     => 'akwu-kopf-' . $name,
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
		),
		$file
	);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	update_post_meta( $id, META_KEY, 1 );

	return (int) $id;
}

/**
 * Bild-Widget wie in Elementor.
 *
 * @param string $id    Widget-ID.
 * @param int    $image Bild-ID.
 * @param string $type  Widget-Typ.
 * @return array
 */
function widget( $id, $image, $type = 'image' ) {
	return array(
		'id'         => $id,
		'elType'     => 'widget',
		'widgetType' => $type,
		'settings'   => $image ? array(
			'image' => array(
				'id'  => $image,
				'url' => wp_get_attachment_url( $image ),
			),
		) : array(),
		'elements'   => array(),
	);
}

/**
 * Vorlage oder Seite mit Elementor-Daten.
 *
 * @param string $type     Beitragstyp.
 * @param string $status   Status.
 * @param array  $widgets  Widgets.
 * @param string $template Vorlagentyp von Elementor oder leer.
 * @return int
 */
function document( $type, $status, array $widgets, $template = '' ) {
	$id = wp_insert_post(
		array(
			'post_type'   => $type,
			'post_status' => $status,
			'post_title'  => 'Kopf-Test ' . $type . ' ' . $status,
		)
	);
	update_post_meta( $id, META_KEY, 1 );
	if ( '' !== $template ) {
		update_post_meta( $id, '_elementor_template_type', $template );
	}
	update_post_meta(
		$id,
		'_elementor_data',
		wp_slash(
			wp_json_encode(
				array(
					array(
						'id'       => 'c' . $id,
						'elType'   => 'container',
						'settings' => array(),
						'elements' => $widgets,
					),
				)
			)
		)
	);

	return (int) $id;
}

/**
 * Räumt Testdaten ab.
 *
 * @return void
 */
function cleanup() {
	foreach ( get_posts( array( 'post_type' => array( 'page', 'attachment', 'elementor_library' ), 'post_status' => 'any', 'meta_key' => META_KEY, 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $old ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Nur Test.
		if ( 'attachment' === get_post_type( $old ) ) {
			wp_delete_attachment( $old, true );
		} else {
			wp_delete_post( $old, true );
		}
	}
	delete_option( Header_Images::OPTION );
	remove_theme_mod( 'custom_logo' );
	if ( 'ours' === Fix_File::status() ) {
		Fix_File::remove();
	}
}

/**
 * Ablauf.
 *
 * @return void
 */
function main() {
	$GLOBALS['akwu_failures'] = 0;

	if ( ! post_type_exists( 'elementor_library' ) ) {
		// Ohne Elementor in der Testinstallation: Beitragstyp für die Vorlagen bereitstellen.
		register_post_type( 'elementor_library', array( 'public' => false ) );
	}

	cleanup();
	if ( 'none' !== Fix_File::status() ) {
		WP_CLI::error( 'Abbruch: Im Ordner der Must-use-Plugins liegt schon eine Datei ' . Fix_File::FILE . '.' );
	}

	$logo    = image( 'logo' );
	$badge   = image( 'siegel' );
	$content = image( 'inhalt' );
	$custom  = image( 'customizer-logo' );
	$draft   = image( 'entwurf' );

	$header = document( 'elementor_library', 'publish', array( widget( 'w1', $logo ), widget( 'w2', 0, 'theme-site-logo' ), widget( 'w3', $logo ) ), 'header' );
	document( 'elementor_library', 'draft', array( widget( 'w4', $draft ) ), 'header' );
	document( 'elementor_library', 'publish', array( widget( 'w5', $content ) ), 'footer' );
	document( 'page', 'publish', array( widget( 'w6', $content ) ) );

	WP_CLI::log( 'Suche:' );
	$scan = Header_Images::scan();
	check( array( $logo ) === wp_list_pluck( $scan, 'id' ), 'Nur das Bild der veröffentlichten Kopfzeile, einmal, ohne Entwurf, Fußzeile und Seiteninhalt' );
	check( $header === $scan[0]['template'] && 'image' === $scan[0]['type'] && 'akwu-kopf-logo.png' === $scan[0]['file'] && false === $scan[0]['active'], 'Vorlage, Widget und Datei stimmen, noch nichts eingeschaltet' );

	set_theme_mod( 'custom_logo', $custom );
	check( array( $logo, $custom ) === wp_list_pluck( Header_Images::scan(), 'id' ), 'Das Website-Logo aus dem Customizer wird über das Logo-Widget erkannt' );
	check( ! Header_Images::is_active() && null === Header_Images::state() && 'none' === Fix_File::status(), 'Vor dem Einschalten: keine Datei, kein Stand' );

	$before = wp_get_attachment_image( $logo, 'full' );
	check( false === strpos( $before, 'skip-lazy' ) && false === strpos( $before, 'data-no-lazy' ), 'Bild-Tag vorher ohne Kennzeichnung' );

	WP_CLI::log( 'Einschalten:' );
	$raw = get_post_meta( $header, '_elementor_data', true );
	check( 2 === Header_Images::apply(), 'Zwei Bilder eingeschaltet' );
	check( 'ours' === Fix_File::status() && Header_Images::is_active() && ! Header_Images::is_outdated(), 'Fix-Datei geschrieben, Stand passt zur Datei' );
	$state = Header_Images::state();
	check( Fix_File::ids( array( $logo, $custom ) ) === $state['ids'], 'Gespeicherter Stand enthält genau die beiden Bild-IDs' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Datei.
	$source = (string) file_get_contents( Fix_File::path() );
	check( Fix_File::render( array( 'header_images' => array( $logo, $custom ) ) ) === $source, 'Inhalt der Datei entspricht dem erwarteten Quelltext' );
	check( array() === glob( dirname( Fix_File::path() ) . '/*.tmp' ), 'Keine Zwischendatei zurückgeblieben' );

	WP_CLI::log( 'Wirkung (Fix-Datei geladen wie ein Must-use-Plugin):' );
	require Fix_File::path();
	$tag = wp_get_attachment_image( $logo, 'full', false, array( 'class' => 'attachment-full size-full' ) );
	check( false !== strpos( $tag, 'loading="eager"' ) && false !== strpos( $tag, 'data-no-lazy="1"' ) && false !== strpos( $tag, 'class="attachment-full size-full skip-lazy"' ), 'Logo der Kopfzeile: loading="eager", data-no-lazy und Klasse skip-lazy' );
	check( false === strpos( $tag, 'loading="lazy"' ), 'Logo der Kopfzeile trägt kein loading="lazy" mehr' );
	$tag = wp_get_attachment_image( $custom, 'thumbnail' );
	check( false !== strpos( $tag, 'skip-lazy' ) && false !== strpos( $tag, 'loading="eager"' ), 'Website-Logo aus dem Customizer ebenfalls, auch in anderer Größe' );
	$other = wp_get_attachment_image( $content, 'full' );
	check( false === strpos( $other, 'skip-lazy' ) && false === strpos( $other, 'data-no-lazy' ) && false === strpos( $other, 'eager' ), 'Bild im Seiteninhalt bleibt unberührt' );
	check( get_post_meta( $header, '_elementor_data', true ) === $raw, 'Gespeicherter Inhalt der Vorlage Zeichen für Zeichen unverändert' );

	WP_CLI::log( 'Ladeverhalten der übrigen Bilder (Schwelle „erste Bilder nicht verzögern“):' );
	$plain = wp_get_attachment_image( $logo, 'full', false, array( 'loading' => false ) );
	check( false === strpos( $plain, ' loading=' ) && false !== strpos( $plain, 'data-no-lazy="1"' ) && false !== strpos( $plain, 'skip-lazy' ), 'Kopfbild ohne loading-Attribut bekommt keines dazu, nur die Kennzeichnung' );
	// Wie Elementors „Optimized Image Loading“: Der Kern vergibt kein fetchpriority, die Tags der Seite laufen nacheinander
	// durch die Zählung des Kerns. Ein Bild mit gesetztem loading-Attribut zählt dort nicht mit.
	wp_high_priority_element_flag( false );
	$passes = static function () use ( $logo ) {
		$tags = array( wp_get_attachment_image( $logo, 'full', false, array( 'loading' => false ) ) );
		for ( $i = 1; $i <= 5; $i++ ) {
			$tags[] = '<img width="1200" height="800" src="https://example.org/akwu-inhalt-' . $i . '.jpg" alt="">';
		}
		wp_increase_content_media_count( -1 * wp_increase_content_media_count( 0 ) );
		$GLOBALS['wp_query']->in_the_loop = true;
		$out                              = array();
		foreach ( $tags as $tag ) {
			$out[] = preg_match( '/ loading="([a-z]+)"/', wp_img_tag_add_loading_optimization_attrs( $tag, 'the_content' ), $m ) ? $m[1] : '';
		}
		$GLOBALS['wp_query']->in_the_loop = false;
		wp_increase_content_media_count( -1 * wp_increase_content_media_count( 0 ) );
		return $out;
	};
	$with    = $passes();
	$removed = remove_filter( 'wp_get_attachment_image_attributes', 'akwu_fix_header_images', 20 );
	$without = $passes();
	add_filter( 'wp_get_attachment_image_attributes', 'akwu_fix_header_images', 20, 2 );
	check( true === $removed && array( '', '', '', 'lazy', 'lazy', 'lazy' ) === $without, 'Ohne Fix: Kopfbild und zwei Inhaltsbilder ohne lazy, ab dem dritten Inhaltsbild loading="lazy" (' . implode( ',', $without ) . ')' );
	check( $with === $without, 'Mit Fix behält jedes andere Bild sein loading="lazy", die Schwelle verschiebt sich nicht (' . implode( ',', $with ) . ')' );

	WP_CLI::log( 'Kopfzeile ändert sich:' );
	$data                  = json_decode( get_post_meta( $header, '_elementor_data', true ), true );
	$data[0]['elements'][] = widget( 'w7', $badge, 'image-box' );
	update_post_meta( $header, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	check( Header_Images::is_outdated(), 'Neues Bild in der Kopfzeile wird als Abweichung erkannt' );
	$marked = wp_list_pluck( Header_Images::scan(), 'active', 'id' );
	check( true === $marked[ $logo ] && false === $marked[ $badge ], 'Liste zeigt, welches Bild schon erfasst ist' );
	check( 3 === Header_Images::apply() && ! Header_Images::is_outdated() && Header_Images::is_active(), 'Neu einschalten übernimmt den heutigen Stand' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Datei.
	check( false !== strpos( (string) file_get_contents( Fix_File::path() ), 'array( ' . implode( ', ', Fix_File::ids( array( $logo, $custom, $badge ) ) ) . ' )' ), 'Fix-Datei enthält jetzt drei Bild-IDs' );

	WP_CLI::log( 'Fremde Datei gleichen Namens:' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Datei.
	$ours    = (string) file_get_contents( Fix_File::path() );
	$foreign = "<?php\n// Fremde Datei eines anderen Entwicklers.\n";
	file_put_contents( Fix_File::path(), $foreign ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei.
	check( 'foreign' === Fix_File::status() && ! Header_Images::is_active(), 'Datei ohne Kennung gilt als fremd' );
	check( is_wp_error( Header_Images::apply() ) && is_wp_error( Header_Images::revert() ), 'Einschalten und Zurücknehmen werden abgelehnt' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Datei.
	check( (string) file_get_contents( Fix_File::path() ) === $foreign, 'Fremde Datei Byte für Byte unverändert' );
	check( null !== Header_Images::state(), 'Der gespeicherte Stand bleibt, solange nicht zurückgenommen werden konnte' );
	file_put_contents( Fix_File::path(), $ours ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei.

	WP_CLI::log( 'Von Hand veränderte Fix-Datei:' );
	file_put_contents( Fix_File::path(), $ours . "\n// von Hand ergänzt\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei.
	check( 'ours' === Fix_File::status() && ! Header_Images::is_active(), 'Eigene Datei mit abweichendem Inhalt zählt nicht als eingeschaltet' );
	check( Header_Images::is_stale(), 'Sie wird als eigene Datei in älterer oder geänderter Form erkannt (Neu einschalten und Zurücknehmen bleiben möglich)' );
	check( 3 === Header_Images::apply() && Header_Images::is_active(), 'Neu einschalten stellt den erwarteten Inhalt wieder her' );

	WP_CLI::log( 'Zurücknehmen:' );
	check( true === Header_Images::revert(), 'Zurückgenommen' );
	check( 'none' === Fix_File::status() && ! file_exists( Fix_File::path() ) && null === Header_Images::state() && ! Header_Images::is_active() && ! Header_Images::is_stale(), 'Fix-Datei entfernt, Stand gelöscht' );
	check( true === Header_Images::revert(), 'Nochmal zurücknehmen ändert nichts und meldet keinen Fehler' );

	WP_CLI::log( 'Kopfzeile ohne Bild:' );
	update_post_meta( $header, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 'c0', 'elType' => 'container', 'settings' => array(), 'elements' => array() ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Nur Test.
	remove_theme_mod( 'custom_logo' );
	check( array() === Header_Images::scan() && is_wp_error( Header_Images::apply() ) && 'none' === Fix_File::status(), 'Ohne Bild: abgelehnt, keine Datei' );

	cleanup();

	if ( $GLOBALS['akwu_failures'] > 0 ) {
		WP_CLI::error( sprintf( '%d Prüfungen fehlgeschlagen.', $GLOBALS['akwu_failures'] ) );
	}
	WP_CLI::success( 'Bilder der Kopfzeile bestanden.' );
}

main();
