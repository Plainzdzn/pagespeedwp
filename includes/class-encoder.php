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

		// Geschrieben wird nur in die vorgesehene, noch nicht vorhandene Zieldatei und nur als WebP.
		if ( file_exists( $target ) ) {
			/* translators: %s: Dateiname. */
			return new \WP_Error( 'akwu_target_exists', sprintf( __( 'Die Zieldatei %s gibt es schon. Bild bleibt unverändert.', 'akuma-webp-umwandler' ), wp_basename( $target ) ) );
		}

		if ( ! self::output_is_webp( $editor, $target ) ) {
			/* translators: %s: Dateiname. */
			return new \WP_Error( 'akwu_output_format', sprintf( __( 'Ein Filter verlangt für %s ein anderes Format als WebP. Bild bleibt unverändert.', 'akuma-webp-umwandler' ), wp_basename( $source ) ) );
		}

		$size  = $editor->get_size();
		$saved = self::save_webp( $editor, $target, $quality );

		if ( ! self::is_own_webp( $saved, $target, $size, $alpha, false ) ) {
			return self::reject_save( $saved, $target, $source );
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
	 * Präfix der eigenen Zwischendatei für die zweite Fassung.
	 */
	const TEMP_PREFIX = 'akwu-alpha-';

	/**
	 * Qualität, die während save_webp() gilt, sonst null.
	 *
	 * @var int|null
	 */
	private static $pinned_quality = null;

	/**
	 * Speichert als WebP mit genau der gewählten Qualität.
	 *
	 * Beim Speichern in ein anderes Format ruft der Core in get_output_format() set_quality() ohne Wert
	 * auf. Dann gilt sein Standard (WebP 86) statt des eben gesetzten Werts, bei Imagick und GD. Für die
	 * Dauer dieses einen Aufrufs liefert der Filter deshalb die gewählte Qualität.
	 *
	 * @param \WP_Image_Editor $editor  Editor.
	 * @param string           $path    Zieldatei.
	 * @param int              $quality Qualität.
	 * @return array|\WP_Error Ergebnis von save().
	 */
	private static function save_webp( $editor, $path, $quality ) {
		self::$pinned_quality = max( 1, min( 100, (int) $quality ) );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'pinned_quality' ), PHP_INT_MAX );

		try {
			return $editor->save( $path, 'image/webp' );
		} finally {
			remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'pinned_quality' ), PHP_INT_MAX );
			self::$pinned_quality = null;
		}
	}

	/**
	 * Filter `wp_editor_set_quality`, nur während save_webp() aktiv.
	 *
	 * @param int $quality Qualität aus dem Core oder anderen Filtern.
	 * @return int
	 */
	public static function pinned_quality( $quality ) {
		return null === self::$pinned_quality ? $quality : self::$pinned_quality;
	}

	/**
	 * Kodiert zusätzlich verlustbehaftet mit verlustfreiem Alphakanal und ersetzt die verlustfreie Datei,
	 * wenn die neue höchstens halb so groß ist. Sonst bleibt alles, wie es ist.
	 *
	 * Mit einem frischen Editor aus dem PNG: Ein schon gespeicherter Editor zeigt auf die verlustfreie
	 * WebP-Datei, und der Core stellt beim nächsten Speichern deshalb wieder auf verlustfrei.
	 *
	 * Geschrieben wird nur in eine eigene, vorher neu angelegte Zwischendatei. Übernommen wird sie nur,
	 * wenn save() genau diese Datei als WebP meldet und sie ein vollständiges, verlustbehaftetes WebP mit
	 * Alphakanal in den Maßen des PNG ist. In jedem anderen Fall bleibt die verlustfreie Datei, und nur
	 * die eigene Zwischendatei wird entfernt. Pfade, die save() abweichend meldet, bleiben unberührt.
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

		$size = $editor->get_size();
		$temp = self::reserve_temp( dirname( $path ) );

		if ( '' === $temp ) {
			return false;
		}

		// Würde ein Filter den Typ und damit die Endung ändern, gar nicht erst speichern.
		if ( ! $editor->akwu_output_is( $temp, 'image/webp' ) ) {
			self::drop_temp( $temp );
			return false;
		}

		$lossy = self::save_webp( $editor, $temp, $quality );

		if ( ! self::is_own_webp( $lossy, $temp, $size, true, true ) ) {
			self::drop_temp( $temp );
			return false;
		}

		clearstatcache( true, $temp );
		$bytes = (int) filesize( $temp );

		if ( $bytes <= 0 || $bytes > $lossless * self::LOSSY_ALPHA_MAX_RATIO ) {
			self::drop_temp( $temp );
			return false;
		}

		// Die verlustfreie Datei durch die kleinere ersetzen, Name bleibt gleich.
		if ( ! @rename( $temp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Gleiches Verzeichnis, Fehler wird behandelt.
			self::drop_temp( $temp );
			return false;
		}

		return true;
	}

	/**
	 * Ist das Ergebnis von save() genau die eigene Datei als vollständiges WebP in den Maßen der Quelle?
	 *
	 * @param array|\WP_Error $saved      Ergebnis von save().
	 * @param string          $path       Eigene Datei (Zieldatei oder Zwischendatei).
	 * @param array           $size       Maße der Quelle (width, height).
	 * @param bool            $need_alpha Die Quelle hat durchsichtige Pixel, das WebP muss einen Alphakanal haben.
	 * @param bool            $need_lossy Nur verlustbehaftet ist gültig (zweite PNG-Fassung).
	 * @return bool
	 */
	private static function is_own_webp( $saved, $path, $size, $need_alpha, $need_lossy ) {
		if ( is_wp_error( $saved ) || ! is_array( $saved ) || empty( $saved['path'] ) || empty( $saved['mime-type'] ) ) {
			return false;
		}

		if ( wp_normalize_path( $saved['path'] ) !== wp_normalize_path( $path ) || 'image/webp' !== $saved['mime-type'] ) {
			return false;
		}

		$info = Webp_Info::read( $path );

		return null !== $info
			&& ( ! $need_lossy || 'lossy' === $info['compression'] )
			&& ( ! $need_alpha || true === $info['alpha'] )
			&& false === $info['animated']
			&& isset( $size['width'], $size['height'] )
			&& (int) $size['width'] === $info['width']
			&& (int) $size['height'] === $info['height'];
	}

	/**
	 * Würde save() genau diese Datei als WebP schreiben? Fragt den Filter `image_editor_output_format`
	 * so wie der Core, ohne etwas zu schreiben.
	 *
	 * @param \WP_Image_Editor $editor Editor.
	 * @param string           $path   Zieldatei mit Endung .webp.
	 * @return bool
	 */
	private static function output_is_webp( $editor, $path ) {
		if ( $editor instanceof Imagick_Webp_Editor ) {
			return $editor->akwu_output_is( $path, 'image/webp' );
		}

		$formats = function_exists( 'wp_get_image_editor_output_format' )
			? wp_get_image_editor_output_format( $path, 'image/webp' )
			: apply_filters( 'image_editor_output_format', array(), $path, 'image/webp' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core-Filter, vor WordPress 6.1 ohne Wrapper.

		return ! ( is_array( $formats ) && isset( $formats['image/webp'] ) && 'image/webp' !== $formats['image/webp'] && $editor->supports_mime_type( $formats['image/webp'] ) );
	}

	/**
	 * Verwirft ein ungültiges erstes Speichern. Entfernt wird nur die eigene Zieldatei (sie gab es vorher
	 * nicht). Meldet save() eine andere Datei, bleibt sie unberührt und steht in der Meldung.
	 *
	 * @param array|\WP_Error $saved  Ergebnis von save().
	 * @param string          $target Eigene Zieldatei.
	 * @param string          $source Quelldatei.
	 * @return \WP_Error
	 */
	private static function reject_save( $saved, $target, $source ) {
		clearstatcache( true, $target );
		if ( is_file( $target ) ) {
			wp_delete_file( $target );
		}

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$other = ( is_array( $saved ) && ! empty( $saved['path'] ) && wp_normalize_path( $saved['path'] ) !== wp_normalize_path( $target ) && is_file( $saved['path'] ) ) ? $saved['path'] : '';

		if ( '' !== $other ) {
			return new \WP_Error(
				'akwu_save',
				/* translators: 1: Dateiname der Quelle, 2: Dateiname, den der Bildeditor gemeldet hat. */
				sprintf( __( 'WebP für %1$s konnte nicht gespeichert werden. Der Bildeditor hat stattdessen %2$s gemeldet, diese Datei bleibt unverändert. Bild bleibt im Original.', 'akuma-webp-umwandler' ), wp_basename( $source ), wp_basename( $other ) )
			);
		}

		/* translators: %s: Dateiname. */
		return new \WP_Error( 'akwu_save', sprintf( __( 'WebP für %s ist unvollständig oder passt nicht zum Original (Format, Maße oder Transparenz). Bild bleibt im Original.', 'akuma-webp-umwandler' ), wp_basename( $source ) ) );
	}

	/**
	 * Legt eine neue, leere Zwischendatei an. Gibt es den Namen schon, wird ein anderer gewählt.
	 *
	 * @param string $dir Verzeichnis der Zieldatei.
	 * @return string Pfad oder leer.
	 */
	private static function reserve_temp( $dir ) {
		for ( $i = 0; $i < 5; $i++ ) {
			$temp   = trailingslashit( $dir ) . self::TEMP_PREFIX . strtolower( wp_generate_password( 12, false, false ) ) . '.webp';
			$handle = @fopen( $temp, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Modus x legt nur neue Dateien an.

			if ( $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Gehört zu fopen() oben.
				return $temp;
			}
		}

		return '';
	}

	/**
	 * Entfernt die eigene Zwischendatei, falls es sie noch gibt.
	 *
	 * @param string $temp Pfad aus reserve_temp().
	 * @return void
	 */
	private static function drop_temp( $temp ) {
		clearstatcache( true, $temp );

		if ( '' !== $temp && 0 === strpos( wp_basename( $temp ), self::TEMP_PREFIX ) && is_file( $temp ) ) {
			wp_delete_file( $temp );
		}
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
