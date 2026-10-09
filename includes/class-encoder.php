<?php
/**
 * Erzeugen von WebP-Dateien.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Wandelt eine JPG- oder PNG-Datei in WebP um, mit den Regeln aus dem Briefing:
 *
 * - JPG und PNG ohne Transparenz: verlustbehaftet mit der JPG-Qualität (Standard 82).
 * - PNG mit Transparenz: verlustfrei, wenn Imagick verfügbar ist, sonst mit der PNG-Qualität (Standard 90).
 * - EXIF-Ausrichtung wird angewendet, Metadaten außer Farbprofilen entfernt (Imagick).
 *
 * Wird vom Scan (Stichprobe) und von der Umwandlung genutzt.
 */
final class Encoder {

	/**
	 * Erzeugt eine WebP-Datei.
	 *
	 * @param string $source   Quelldatei (absoluter Pfad).
	 * @param string $target   Zieldatei (absoluter Pfad, Endung .webp).
	 * @param array  $settings Einstellungen, siehe Settings::all().
	 * @return array|\WP_Error {
	 *     @type string $path    Erzeugte Datei.
	 *     @type int    $bytes   Dateigröße.
	 *     @type string $mode    lossy oder lossless.
	 *     @type int    $quality Verwendete Qualität.
	 *     @type bool   $alpha   Quelle hatte Transparenz.
	 *     @type string $editor  imagick oder gd.
	 * }
	 */
	public static function encode( $source, $target, array $settings ) {
		self::load_editors();

		$mime = wp_get_image_mime( $source );

		if ( 'image/jpeg' !== $mime && 'image/png' !== $mime ) {
			/* translators: %s: Dateiname. */
			return new \WP_Error( 'akwu_mime', sprintf( __( '%s ist kein JPG oder PNG.', 'akuma-webp-umwandler' ), wp_basename( $source ) ) );
		}

		$alpha = ( 'image/png' === $mime && true === Png_Info::has_alpha( $source ) );

		add_filter( 'wp_image_editors', array( __CLASS__, 'prefer_own_imagick' ) );
		$editor = wp_get_image_editor(
			$source,
			array(
				'mime_type'        => $mime,
				'output_mime_type' => 'image/webp',
			)
		);
		remove_filter( 'wp_image_editors', array( __CLASS__, 'prefer_own_imagick' ) );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$imagick  = $editor instanceof Imagick_Webp_Editor;
		$lossless = $alpha && $imagick;

		if ( $lossless ) {
			$quality = 100;
			$editor->set_quality( 100 );
			$editor->akwu_set_lossless();
		} else {
			$quality = (int) ( $alpha ? $settings['quality_png'] : $settings['quality_jpg'] );
			$editor->set_quality( $quality );
		}

		if ( 'image/jpeg' === $mime ) {
			$editor->maybe_exif_rotate();
		}

		if ( $imagick ) {
			$editor->akwu_strip_meta();
		}

		$saved = $editor->save( $target, 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( empty( $saved['path'] ) || ! is_file( $saved['path'] ) || 'image/webp' !== $saved['mime-type'] ) {
			if ( ! empty( $saved['path'] ) && is_file( $saved['path'] ) && $saved['path'] !== $source ) {
				wp_delete_file( $saved['path'] );
			}
			/* translators: %s: Dateiname. */
			return new \WP_Error( 'akwu_save', sprintf( __( 'WebP für %s konnte nicht gespeichert werden.', 'akuma-webp-umwandler' ), wp_basename( $source ) ) );
		}

		clearstatcache( true, $saved['path'] );

		return array(
			'path'    => $saved['path'],
			'bytes'   => (int) filesize( $saved['path'] ),
			'mode'    => $lossless ? 'lossless' : 'lossy',
			'quality' => $quality,
			'alpha'   => $alpha,
			'editor'  => $editor instanceof \WP_Image_Editor_Imagick ? 'imagick' : 'gd',
		);
	}

	/**
	 * Setzt den eigenen Imagick-Editor an die erste Stelle. Ohne Imagick greift weiter GD.
	 *
	 * @param string[] $editors Editor-Klassen.
	 * @return string[]
	 */
	public static function prefer_own_imagick( $editors ) {
		$editors = is_array( $editors ) ? $editors : array();
		array_unshift( $editors, Imagick_Webp_Editor::class );

		return array_values( array_unique( $editors ) );
	}

	/**
	 * Lädt die Editor-Klassen des Core, damit die eigene Unterklasse geladen werden kann.
	 *
	 * @return void
	 */
	public static function load_editors() {
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
	}
}
