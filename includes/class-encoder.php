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
 *   Ab 1.1.3 zählt auch ein PNG mit Alphakanal, aber ohne ein einziges durchsichtiges Pixel, als
 *   „ohne Transparenz“ (Imagick prüft die Pixel). Der leere Alphakanal entfällt im WebP.
 * - PNG mit Transparenz: verlustfrei, wenn Imagick verfügbar ist, sonst mit der PNG-Qualität (Standard 90).
 *   Mit der Einstellung „automatisch“ (Opt-in) wird zusätzlich verlustbehaftet mit verlustfreiem
 *   Alphakanal kodiert und diese Fassung nur genommen, wenn sie höchstens halb so groß ist.
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
	 *     @type string $path           Erzeugte Datei.
	 *     @type int    $bytes          Dateigröße.
	 *     @type string $mode           lossy, lossless oder lossy-alpha.
	 *     @type int    $quality        Verwendete Qualität.
	 *     @type bool   $alpha          Quelle hat durchsichtige Pixel.
	 *     @type bool   $opaque         PNG mit Alphakanal, aber ohne durchsichtige Pixel.
	 *     @type int    $lossless_bytes Größe der verlustfreien Fassung, wenn beide erzeugt wurden, sonst 0.
	 *     @type string $editor         imagick oder gd.
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

		$imagick = $editor instanceof Imagick_Webp_Editor;
		$opaque  = false;

		// Alphakanal im Kopf heißt noch nicht Transparenz: die Pixel entscheiden (nur mit Imagick prüfbar).
		if ( $alpha && $imagick && true === $editor->akwu_is_opaque() ) {
			$alpha  = false;
			$opaque = true;
			$editor->akwu_drop_alpha();
		}

		$lossless = $alpha && $imagick;
		$auto     = $lossless && isset( $settings['png_transparent'] ) && 'auto' === $settings['png_transparent'];

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

		$mode           = $lossless ? 'lossless' : 'lossy';
		$lossless_bytes = 0;

		if ( $auto ) {
			clearstatcache( true, $saved['path'] );
			$lossless_bytes = (int) filesize( $saved['path'] );
			$quality_lossy  = (int) $settings['quality_jpg'];
			$candidate      = self::lossy_alpha( $source, $saved['path'], $quality_lossy, $lossless_bytes );
			if ( $candidate ) {
				$mode    = 'lossy-alpha';
				$quality = $quality_lossy;
			}
		}

		clearstatcache( true, $saved['path'] );

		return array(
			'path'           => $saved['path'],
			'bytes'          => (int) filesize( $saved['path'] ),
			'mode'           => $mode,
			'quality'        => $quality,
			'alpha'          => $alpha,
			'opaque'         => $opaque,
			'lossless_bytes' => $lossless_bytes,
			'editor'         => $editor instanceof \WP_Image_Editor_Imagick ? 'imagick' : 'gd',
		);
	}

	/**
	 * Höchstens dieser Anteil der verlustfreien Größe, damit die verlustbehaftete Fassung genommen wird.
	 * Bei Logos und flachen Grafiken ist verlustbehaftet kaum kleiner oder sogar größer, sie bleiben verlustfrei.
	 */
	const LOSSY_ALPHA_MAX_RATIO = 0.5;

	/**
	 * Kodiert zusätzlich verlustbehaftet mit verlustfreiem Alphakanal und ersetzt die verlustfreie Datei,
	 * wenn die neue höchstens halb so groß ist. Sonst bleibt alles, wie es ist.
	 *
	 * Mit einem frischen Editor aus dem PNG: Ein schon gespeicherter Editor zeigt auf die verlustfreie
	 * WebP-Datei, und der Core stellt beim nächsten Speichern deshalb wieder auf verlustfrei.
	 *
	 * @param string $source   Quell-PNG.
	 * @param string $path     Verlustfrei gespeicherte Datei.
	 * @param int    $quality  Qualität für die Farbwerte.
	 * @param int    $lossless Größe der verlustfreien Datei.
	 * @return bool Ob die verlustbehaftete Fassung übernommen wurde.
	 */
	private static function lossy_alpha( $source, $path, $quality, $lossless ) {
		add_filter( 'wp_image_editors', array( __CLASS__, 'prefer_own_imagick' ) );
		$editor = wp_get_image_editor(
			$source,
			array(
				'mime_type'        => 'image/png',
				'output_mime_type' => 'image/webp',
			)
		);
		remove_filter( 'wp_image_editors', array( __CLASS__, 'prefer_own_imagick' ) );

		if ( is_wp_error( $editor ) || ! $editor instanceof Imagick_Webp_Editor || ! $editor->akwu_set_lossy_alpha( $quality ) ) {
			return false;
		}
		$editor->akwu_strip_meta();

		$temp  = trailingslashit( dirname( $path ) ) . wp_unique_filename( dirname( $path ), 'akwu-alpha-' . wp_basename( $path ) );
		$lossy = $editor->save( $temp, 'image/webp' );

		if ( is_wp_error( $lossy ) || empty( $lossy['path'] ) || ! is_file( $lossy['path'] ) ) {
			return false;
		}

		clearstatcache( true, $lossy['path'] );
		$bytes = (int) filesize( $lossy['path'] );

		if ( $bytes <= 0 || $bytes > $lossless * self::LOSSY_ALPHA_MAX_RATIO ) {
			wp_delete_file( $lossy['path'] );
			return false;
		}

		// Die verlustfreie Datei durch die kleinere ersetzen, Name bleibt gleich.
		if ( ! @rename( $lossy['path'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Gleiches Verzeichnis, Fehler wird behandelt.
			wp_delete_file( $lossy['path'] );
			return false;
		}

		return true;
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
