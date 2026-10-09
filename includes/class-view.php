<?php
/**
 * Einbinden der Seiten-Templates.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Rendert Templates aus `includes/views/` und kleine wiederkehrende Bausteine.
 */
final class View {

	/**
	 * Bindet ein Template ein. Im Template steht `$data` zur Verfügung.
	 *
	 * @param string $name Dateiname ohne `.php`, nur a–z, 0–9 und Bindestrich.
	 * @param array  $data Daten für das Template.
	 * @return void
	 */
	public static function render( $name, array $data = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data wird im Template verwendet.
		if ( ! preg_match( '/^[a-z0-9-]+$/', $name ) ) {
			return;
		}

		$file = AKWU_DIR . 'includes/views/' . $name . '.php';

		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	/**
	 * Statuspille mit Symbol und Text für Screenreader.
	 *
	 * @param string $status ok, warn, error oder info.
	 * @return void
	 */
	public static function status_pill( $status ) {
		$map = array(
			System_Check::OK    => array( '✓', __( 'In Ordnung', 'akuma-webp-umwandler' ) ),
			System_Check::WARN  => array( '!', __( 'Bitte prüfen', 'akuma-webp-umwandler' ) ),
			System_Check::ERROR => array( '×', __( 'Problem', 'akuma-webp-umwandler' ) ),
			System_Check::INFO  => array( '–', __( 'Hinweis', 'akuma-webp-umwandler' ) ),
		);

		if ( ! isset( $map[ $status ] ) ) {
			$status = System_Check::INFO;
		}

		printf(
			'<span class="akwu-pill akwu-pill--%1$s"><span aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			esc_attr( $status ),
			esc_html( $map[ $status ][0] ),
			esc_html( $map[ $status ][1] )
		);
	}
}
