<?php
/**
 * Fix-Datei: kleines Must-use-Plugin mit den eingeschalteten Fixes, das ohne dieses Plugin weiterwirkt (ADR-040).
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Schreibt und entfernt genau eine eigene Datei im Ordner der Must-use-Plugins.
 *
 * Die Datei enthält nur eingeschaltete Fixes und deren Daten als feste Werte. Sie hängt nicht von diesem
 * Plugin ab und bleibt nach dessen Entfernung wirksam. Fremde Dateien gleichen Namens werden nie angefasst.
 */
class Fix_File {

	/**
	 * Dateiname im Ordner der Must-use-Plugins.
	 */
	const FILE = 'akuma-pagespeed-fixes.php';

	/**
	 * Kennung in der Datei. Nur Dateien mit dieser Zeile gelten als eigene.
	 */
	const MARKER = 'akwu-fix-file: 1';

	/**
	 * Pfad der Fix-Datei.
	 *
	 * @return string
	 */
	public static function path() {
		return trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) . self::FILE;
	}

	/**
	 * Zustand der Datei.
	 *
	 * @return string none (gibt es nicht), ours (eigene Datei), foreign (fremde Datei gleichen Namens).
	 */
	public static function status() {
		$path = self::path();
		if ( ! file_exists( $path ) ) {
			return 'none';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Datei.
		$content = (string) file_get_contents( $path );

		return false !== strpos( $content, self::MARKER ) ? 'ours' : 'foreign';
	}

	/**
	 * Ganzzahlen aufsteigend, ohne Doppelte, ohne Null.
	 *
	 * @param array $ids Werte.
	 * @return int[]
	 */
	public static function ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Quelltext der Fix-Datei für die eingeschalteten Fixes. Ohne Fixes leer.
	 *
	 * @param array $fixes Bekannt: header_images => Bild-IDs.
	 * @return string
	 */
	public static function render( array $fixes ) {
		$header = isset( $fixes['header_images'] ) ? self::ids( (array) $fixes['header_images'] ) : array();
		if ( empty( $header ) ) {
			return '';
		}

		$lines = array(
			'<?php',
			'/**',
			' * Plugin Name: Akuma PageSpeed-Fixes',
			' * Description: Vom WebP-Umwandler geschrieben. Enthält nur die dort eingeschalteten Fixes und wirkt auch ohne das Plugin weiter. Zurücknehmen: im Plugin unter „Ladeverhalten“ oder diese Datei löschen.',
			' * Version: 2',
			' * Author: Akuma Digital',
			' *',
			' * ' . self::MARKER,
			' *',
			' * @package Akuma\PagespeedFixes',
			' */',
			'',
			"defined( 'ABSPATH' ) || exit;",
			'',
			'// Fix header_images (2): Bilder der Kopfzeile sofort laden, nicht verzögert.',
			"if ( ! function_exists( 'akwu_fix_header_images' ) ) {",
			'	/**',
			'	 * Nimmt die Bilder der Kopfzeile vom verzögerten Laden aus.',
			'	 *',
			'	 * @param array  $attr       Attribute des Bild-Tags.',
			'	 * @param object $attachment Anhang.',
			'	 * @return array',
			'	 */',
			'	function akwu_fix_header_images( $attr, $attachment ) {',
			'		static $ids = array( ' . implode( ', ', $header ) . ' );',
			'',
			'		if ( ! is_array( $attr ) || ! is_object( $attachment ) || ! isset( $attachment->ID ) || ! in_array( (int) $attachment->ID, $ids, true ) ) {',
			'			return $attr;',
			'		}',
			'',
			'		// Kein loading="eager" setzen, wo keines verlangt ist: WordPress und Elementor zählen ein Bild mit gesetztem',
			'		// loading-Attribut nicht mit, die Schwelle „erste Bilder nicht verzögern“ würde sich um ein Bild verschieben.',
			"		if ( isset( \$attr['loading'] ) && 'lazy' === \$attr['loading'] ) {",
			"			\$attr['loading'] = 'eager';",
			'		}',
			"		\$attr['data-no-lazy'] = '1';",
			"		\$class                = isset( \$attr['class'] ) ? (string) \$attr['class'] : '';",
			"		if ( false === strpos( ' ' . \$class . ' ', ' skip-lazy ' ) ) {",
			"			\$attr['class'] = trim( \$class . ' skip-lazy' );",
			'		}',
			'',
			'		return $attr;',
			'	}',
			"	add_filter( 'wp_get_attachment_image_attributes', 'akwu_fix_header_images', 20, 2 );",
			'}',
			'',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Schreibt die Fix-Datei für die eingeschalteten Fixes oder entfernt sie, wenn keiner mehr übrig ist.
	 *
	 * @param array $fixes Siehe render().
	 * @return true|\WP_Error
	 */
	public static function write( array $fixes ) {
		$source = self::render( $fixes );
		if ( '' === $source ) {
			return self::remove();
		}

		if ( 'foreign' === self::status() ) {
			return new \WP_Error(
				'akwu_fix_foreign',
				/* translators: %s: Dateiname. */
				sprintf( __( 'Im Ordner der Must-use-Plugins liegt schon eine fremde Datei %s. Sie bleibt unverändert.', 'akuma-webp-umwandler' ), self::FILE )
			);
		}

		$path = self::path();
		$dir  = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return new \WP_Error( 'akwu_fix_dir', __( 'Der Ordner der Must-use-Plugins lässt sich nicht beschreiben. Nichts geändert.', 'akuma-webp-umwandler' ) );
		}

		// Erst in eine eigene Zwischendatei, dann umbenennen: nie eine halbe PHP-Datei im Ordner, der bei jedem Aufruf geladen wird.
		$temp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Eigene Datei, kein FTP-Fall.
		if ( strlen( $source ) !== file_put_contents( $temp, $source ) ) {
			wp_delete_file( $temp );

			return new \WP_Error( 'akwu_fix_write', __( 'Die Fix-Datei ließ sich nicht schreiben. Nichts geändert.', 'akuma-webp-umwandler' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Gleicher Ordner, atomarer Austausch.
		if ( ! rename( $temp, $path ) ) {
			wp_delete_file( $temp );

			return new \WP_Error( 'akwu_fix_write', __( 'Die Fix-Datei ließ sich nicht schreiben. Nichts geändert.', 'akuma-webp-umwandler' ) );
		}

		self::refresh( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Datei.
		if ( (string) file_get_contents( $path ) !== $source ) {
			return new \WP_Error( 'akwu_fix_write', __( 'Die Fix-Datei wurde nicht vollständig geschrieben.', 'akuma-webp-umwandler' ) );
		}

		return true;
	}

	/**
	 * Entfernt die eigene Fix-Datei. Fremde Dateien bleiben.
	 *
	 * @return true|\WP_Error
	 */
	public static function remove() {
		$status = self::status();
		if ( 'none' === $status ) {
			return true;
		}

		if ( 'foreign' === $status ) {
			return new \WP_Error(
				'akwu_fix_foreign',
				/* translators: %s: Dateiname. */
				sprintf( __( 'Die Datei %s stammt nicht von diesem Plugin und bleibt unverändert.', 'akuma-webp-umwandler' ), self::FILE )
			);
		}

		$path = self::path();
		wp_delete_file( $path );
		self::refresh( $path );

		if ( file_exists( $path ) ) {
			return new \WP_Error( 'akwu_fix_write', __( 'Die Fix-Datei ließ sich nicht entfernen.', 'akuma-webp-umwandler' ) );
		}

		return true;
	}

	/**
	 * Zwischenspeicher von PHP für die Datei verwerfen, damit der nächste Aufruf den neuen Stand lädt.
	 *
	 * @param string $path Datei.
	 * @return void
	 */
	private static function refresh( $path ) {
		clearstatcache( true, $path );
		if ( function_exists( 'opcache_invalidate' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Je nach Server gesperrt, dann greift die normale Prüfung der Dateizeit.
			@opcache_invalidate( $path, true );
		}
	}
}
