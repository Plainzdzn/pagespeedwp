<?php
/**
 * Integrationstest: eingebettete Tour erst bei Bedarf laden und die Änderung zeichengenau zurücknehmen.
 *
 * Aufruf auf einer Testinstallation:
 *   wp eval-file tests/integration/embeds.php
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration\Embeds;

use Akuma\WebpUmwandler\Embeds;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Abbruch: nur auf Testinstallationen.' );
}

const META_KEY = '_akwu_embed_test';

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
 * HTML-Widget wie in Elementor.
 *
 * @param string $id   Widget-ID.
 * @param string $html Inhalt.
 * @return array
 */
function widget( $id, $html ) {
	return array(
		'id'         => $id,
		'elType'     => 'widget',
		'widgetType' => 'html',
		'settings'   => array( 'html' => $html ),
		'elements'   => array(),
	);
}

/**
 * Abschnitt auf oberster Ebene.
 *
 * @param string $id       ID.
 * @param array  $elements Inhalt.
 * @param array  $settings Einstellungen.
 * @return array
 */
function section( $id, array $elements, array $settings = array() ) {
	return array(
		'id'       => $id,
		'elType'   => 'container',
		'settings' => $settings,
		'elements' => $elements,
	);
}

/**
 * Ablauf.
 *
 * @return void
 */
function main() {
	$GLOBALS['akwu_failures'] = 0;

	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => META_KEY, 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $old ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Nur Test.
		wp_delete_post( $old, true );
	}
	delete_option( Embeds::OPTION );

	// Wie auf der JBZ-Startseite: dieselbe Tour zweimal, einmal für Desktop und Tablet, einmal fürs Handy. Quelle ohne Anführungszeichen.
	$desktop = '<iframe width="850" height="675" src=https://my.matterport.com/show/?m=TEST frameborder="0" allowfullscreen allow="autoplay; fullscreen; web-share; xr-spatial-tracking;"></iframe>';
	$mobile  = str_replace( '675', '375', $desktop );
	$data    = array(
		section( 's1', array( widget( 'w0', '<iframe src="https://my.matterport.com/show/?m=OBEN"></iframe>' ) ) ),
		section( 's2', array( widget( 'w9', '<iframe src="https://www.youtube.com/embed/abc"></iframe><p>Größe „ä“ / Pfad</p>' ) ) ),
		section( 's3', array() ),
		section( 's4', array( widget( 'w1', $desktop ) ), array( 'hide_mobile' => 'hidden-mobile' ) ),
		section(
			's5',
			array( widget( 'w2', $mobile ) ),
			array(
				'hide_desktop' => 'hidden-desktop',
				'hide_tablet'  => 'hidden-tablet',
			)
		),
	);

	$page_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Tour-Test',
		)
	);
	update_post_meta( $page_id, META_KEY, 1 );
	update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	update_post_meta( $page_id, '_elementor_element_cache', 'alt' );

	$raw = get_post_meta( $page_id, '_elementor_data', true );

	WP_CLI::log( 'Suche:' );
	$found = array();
	foreach ( Embeds::scan() as $entry ) {
		if ( $entry['post'] === $page_id ) {
			$found[ $entry['widget'] ] = $entry;
		}
	}
	check( array( 'w0', 'w1', 'w2' ) === array_keys( $found ), 'Drei Touren gefunden, das Video nicht' );
	check( ! $found['w0']['eligible'] && $found['w1']['eligible'] && $found['w2']['eligible'], 'Die Tour im ersten Abschnitt wird nicht angeboten, die beiden weit unten schon' );
	check( 4 === $found['w1']['section'] && 5 === $found['w1']['sections'] && array( 'mobile' ) === $found['w1']['hidden'], 'Abschnitt und ausgeblendete Geräte stimmen' );

	WP_CLI::log( 'Verzögert laden:' );
	check( is_wp_error( Embeds::apply( $found['w0']['key'] ) ), 'Tour weit oben wird abgelehnt' );
	check( get_post_meta( $page_id, '_elementor_data', true ) === $raw, 'Abgelehnt heißt: nichts geschrieben' );
	check( true === Embeds::apply( $found['w1']['key'] ), 'Tour für Desktop umgestellt' );

	$changed = get_post_meta( $page_id, '_elementor_data', true );
	$decoded = json_decode( $changed, true );
	check( is_array( $decoded ) && strlen( $changed ) === strlen( $raw ) + strlen( ' loading=\"lazy\"' ), 'Gespeicherter Inhalt ist gültiges JSON und genau um das Attribut länger' );
	check( 0 === strpos( $decoded[3]['elements'][0]['settings']['html'], '<iframe loading="lazy" width="850" height="675" src=https://my.matterport.com/show/?m=TEST ' ), 'Nur dieses iframe hat das Attribut' );
	check( $data[4] === $decoded[4] && $data[0] === $decoded[0] && $data[1] === $decoded[1], 'Alle anderen Abschnitte unverändert, auch Umlaute und das Video' );
	check( '' === (string) get_post_meta( $page_id, '_elementor_element_cache', true ), 'Elementor-Zwischenspeicher der Seite geleert' );

	$state = array();
	foreach ( Embeds::scan() as $entry ) {
		$state[ $entry['widget'] ] = $entry['state'];
	}
	check( 'ours' === $state['w1'] && 'eager' === $state['w2'], 'Status: eine Tour umgestellt, die andere noch nicht' );
	check( is_wp_error( Embeds::apply( $found['w1']['key'] ) ), 'Zweites Umstellen derselben Tour wird abgelehnt' );
	check( true === Embeds::apply( $found['w2']['key'] ), 'Tour fürs Handy umgestellt' );

	WP_CLI::log( 'Zurücknehmen:' );
	$changes = Embeds::changes();
	check( true === Embeds::revert( $found['w2']['key'] ) && true === Embeds::revert( $found['w1']['key'] ), 'Beide Änderungen zurückgenommen' );
	check( get_post_meta( $page_id, '_elementor_data', true ) === $raw, 'Gespeicherter Inhalt wieder Zeichen für Zeichen wie vorher' );
	check( Embeds::matches( $page_id, $changes[ $found['w1']['key'] ]['hash'] ), 'Prüfsumme stimmt mit dem Stand vor der Änderung überein' );
	check( array() === Embeds::changes(), 'Keine gespeicherten Änderungen mehr' );
	check( is_wp_error( Embeds::revert( $found['w1']['key'] ) ), 'Nochmal zurücknehmen wird abgelehnt' );

	WP_CLI::log( 'Seite wurde zwischendurch bearbeitet:' );
	Embeds::apply( $found['w1']['key'] );
	$edited = str_replace( 'loading=\"lazy\" width=\"850\" height=\"675\"', 'loading=\"lazy\" width=\"900\" height=\"675\"', get_post_meta( $page_id, '_elementor_data', true ) );
	update_post_meta( $page_id, '_elementor_data', wp_slash( $edited ) );
	check( is_wp_error( Embeds::revert( $found['w1']['key'] ) ) && get_post_meta( $page_id, '_elementor_data', true ) === $edited, 'Zurücknehmen lässt ein inzwischen bearbeitetes iframe in Ruhe' );

	wp_delete_post( $page_id, true );
	delete_option( Embeds::OPTION );

	if ( $GLOBALS['akwu_failures'] > 0 ) {
		WP_CLI::error( sprintf( '%d Prüfungen fehlgeschlagen.', $GLOBALS['akwu_failures'] ) );
	}
	WP_CLI::success( 'Einbettungen bestanden.' );
}

main();
