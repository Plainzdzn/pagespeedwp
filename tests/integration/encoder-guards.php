<?php
/**
 * Integrationstest: Qualität am Kodierpunkt und Schutz der zweiten PNG-Fassung (1.1.4).
 *
 * Aufruf auf einer Testinstallation:
 *   wp eval-file tests/integration/encoder-guards.php
 *
 * Arbeitet nur mit eigenen Testbildern in einem eigenen temporären Ordner und räumt ihn ab.
 *
 * 1. Qualität: Der Core setzt beim Speichern in ein anderes Format in get_output_format() die Qualität
 *    auf seinen Standard (WebP 86) zurück. Geprüft wird die am Imagick-Objekt direkt vor dem Schreiben
 *    gesetzte Qualität für JPG, deckendes PNG mit Alphakanal, Freisteller verlustfrei und Freisteller
 *    verlustbehaftet (automatisch), mit 35, 82 und 95. Mit GD zusätzlich über die Dateigröße, mit
 *    Imagick über die Dateigröße nur, wenn die installierte ImageMagick-Version die Qualität umsetzt.
 * 2. Zweite Fassung bei Freistellern: Filter image_editor_output_format, abweichender Pfad, Fehler nach
 *    dem Schreiben, kein WebP, Alphakanal verloren, falsche Maße, abgeschnittene Datei. Jedes Mal muss
 *    die gute verlustfreie Datei bleiben, nur die eigene Zwischendatei verschwinden und kein fremder
 *    Pfad verändert werden.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration\EncoderGuards;

use Akuma\WebpUmwandler\Encoder;
use Akuma\WebpUmwandler\Settings;
use Akuma\WebpUmwandler\Webp_Info;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Abbruch: nur auf Testinstallationen.' );
}

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
 * Einstellungen mit Qualität und PNG-Modus.
 *
 * @param int    $quality Qualität für JPG und PNG.
 * @param string $png     lossless oder auto.
 * @return array
 */
function settings( $quality, $png = 'lossless' ) {
	$settings                    = Settings::all();
	$settings['quality_jpg']     = $quality;
	$settings['quality_png']     = $quality;
	$settings['png_transparent'] = $png;

	return $settings;
}

/**
 * Fotoähnliches Bild mit Imagick. Ohne Zufall, damit alle Läufe dieselben Pixel haben.
 *
 * @param string $kind jpg, deckend oder freisteller.
 * @param string $path Ziel.
 * @return void
 */
function write_imagick( $kind, $path ) {
	$image = new \Imagick();
	$image->newPseudoImage( 640, 420, 'gradient:#1c805d-#f4c04a' );
	$noise = new \Imagick();
	$noise->newPseudoImage( 640, 420, 'pattern:checkerboard' );
	$image->compositeImage( $noise, \Imagick::COMPOSITE_OVERLAY, 0, 0 );
	$draw = new \ImagickDraw();
	for ( $i = 0; $i < 60; $i++ ) {
		$draw->setFillColor( new \ImagickPixel( sprintf( '#%02x%02x%02x', ( $i * 37 ) % 256, ( $i * 91 ) % 256, ( $i * 53 ) % 256 ) ) );
		$draw->circle( ( $i * 53 ) % 640, ( $i * 29 ) % 420, ( ( $i * 53 ) % 640 ) + 8 + ( $i % 23 ), ( $i * 29 ) % 420 );
	}
	$image->drawImage( $draw );
	$image->blurImage( 0, 0.6 );

	if ( 'jpg' === $kind ) {
		$image->setImageFormat( 'JPEG' );
		$image->setImageCompressionQuality( 95 );
		$image->writeImage( $path );
		return;
	}

	$image->setImageAlphaChannel( defined( '\Imagick::ALPHACHANNEL_SET' ) ? \Imagick::ALPHACHANNEL_SET : \Imagick::ALPHACHANNEL_ACTIVATE );

	if ( 'freisteller' === $kind ) {
		$mask = new \Imagick();
		$mask->newImage( 640, 420, new \ImagickPixel( 'black' ) );
		$ellipse = new \ImagickDraw();
		$ellipse->setFillColor( new \ImagickPixel( 'white' ) );
		$ellipse->ellipse( 320, 210, 240, 170, 0, 360 );
		$mask->drawImage( $ellipse );
		$mask->setImageAlphaChannel( defined( '\Imagick::ALPHACHANNEL_COPY' ) ? \Imagick::ALPHACHANNEL_COPY : \Imagick::ALPHACHANNEL_SET );
		$image->compositeImage( $mask, \Imagick::COMPOSITE_COPYOPACITY, 0, 0 );
	}

	$image->setImageFormat( 'PNG32' );
	$image->writeImage( 'png32:' . $path );
}

/**
 * Fotoähnliches Bild mit GD (ohne Alphakanal).
 *
 * @param string $kind jpg oder png.
 * @param string $path Ziel.
 * @return void
 */
function write_gd( $kind, $path ) {
	$image = imagecreatetruecolor( 640, 420 );
	for ( $y = 0; $y < 420; $y++ ) {
		for ( $x = 0; $x < 640; $x += 2 ) {
			$color = imagecolorallocate( $image, ( $x * 3 + $y ) % 256, ( $y * 5 + ( $x ^ $y ) ) % 256, ( $x * $y ) % 256 );
			imagefilledrectangle( $image, $x, $y, $x + 1, $y, $color );
		}
	}
	imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );

	if ( 'jpg' === $kind ) {
		imagejpeg( $image, $path, 95 );
	} else {
		imagepng( $image, $path );
	}
	imagedestroy( $image );
}

/**
 * Freisteller mit fotoähnlichem Rauschen (GD, fester Startwert): verlustfrei groß, verlustbehaftet und
 * als JPEG sehr klein. So gewinnt ein falsches JPEG den Größenvergleich, wie im gemeldeten Fall.
 *
 * @param string $path Ziel.
 * @return void
 */
function write_noise_cutout( $path ) {
	mt_srand( 4711 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Fester Startwert für gleiche Testbilder.
	$image = imagecreatetruecolor( 640, 420 );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	for ( $y = 0; $y < 420; $y++ ) {
		for ( $x = 0; $x < 640; $x++ ) {
			imagesetpixel( $image, $x, $y, imagecolorallocatealpha( $image, mt_rand( 60, 200 ), mt_rand( 80, 180 ), mt_rand( 40, 160 ), 0 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Fester Startwert für gleiche Testbilder.
		}
	}
	imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
	$clear = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
	for ( $y = 0; $y < 420; $y++ ) {
		for ( $x = 0; $x < 640; $x++ ) {
			if ( pow( ( $x - 320 ) / 250, 2 ) + pow( ( $y - 210 ) / 180, 2 ) > 1 ) {
				imagesetpixel( $image, $x, $y, $clear );
			}
		}
	}
	imagepng( $image, $path );
	imagedestroy( $image );
	mt_srand(); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Zufall wieder freigeben.
}

/**
 * Setzt den Test-Editor an die erste Stelle (nach prefer_own_imagick des Plugins).
 *
 * @param string[] $editors Editor-Klassen.
 * @return string[]
 */
function guard_first( $editors ) {
	$editors = is_array( $editors ) ? $editors : array();
	array_unshift( $editors, Guard_Editor::class );

	return array_values( array_unique( $editors ) );
}

/**
 * Nur GD.
 *
 * @return string[]
 */
function gd_only() {
	return array( 'WP_Image_Editor_GD' );
}

/**
 * Letzter Protokolleintrag für eine Datei.
 *
 * @param string $basename Dateiname.
 * @return array|null
 */
function last_log( $basename ) {
	$found = null;
	foreach ( Guard_Editor::$log as $entry ) {
		if ( $entry['file'] === $basename ) {
			$found = $entry;
		}
	}

	return $found;
}

/**
 * Letzter Protokolleintrag für die Zwischendatei.
 *
 * @return array|null
 */
function last_temp_log() {
	$found = null;
	foreach ( Guard_Editor::$log as $entry ) {
		if ( 0 === strpos( $entry['file'], 'akwu-alpha-' ) ) {
			$found = $entry;
		}
	}

	return $found;
}

/**
 * Dateien eines Ordners mit SHA-256.
 *
 * @param string $dir Ordner.
 * @return array<string,string>
 */
function listing( $dir ) {
	$files = array();
	foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
		if ( is_file( $file ) ) {
			$files[ wp_basename( $file ) ] = hash_file( 'sha256', $file );
		}
	}
	ksort( $files );

	return $files;
}

/**
 * Teil 1: Qualität am Kodierpunkt mit Imagick.
 *
 * @param string $dir Arbeitsordner.
 * @return void
 */
function quality_imagick( $dir ) {
	WP_CLI::log( '== Qualität am Kodierpunkt, Imagick (' . \Imagick::getVersion()['versionString'] . ')' );

	$sources = array(
		'jpg'         => $dir . '/q-foto.jpg',
		'deckend'     => $dir . '/q-deckend.png',
		'freisteller' => $dir . '/q-freisteller.png',
	);
	foreach ( $sources as $kind => $path ) {
		write_imagick( $kind, $path );
	}

	// Setzt diese ImageMagick-Version die WebP-Qualität überhaupt um? Sonst kein Größenvergleich.
	$probe = new \Imagick( $sources['jpg'] );
	$probe->setImageFormat( 'WEBP' );
	$probe->setImageCompressionQuality( 35 );
	$low = strlen( $probe->getImageBlob() );
	$probe->setImageCompressionQuality( 95 );
	$high           = strlen( $probe->getImageBlob() );
	$bytes_reliable = $low < $high;
	WP_CLI::log( $bytes_reliable ? '  Info  ImageMagick setzt WebP-Qualität um, Dateigrößen werden mit verglichen.' : '  Info  Diese ImageMagick-Version ignoriert die WebP-Qualität (35 und 95 gleich groß), geprüft wird die gesetzte Qualität.' );

	add_filter( 'wp_image_editors', __NAMESPACE__ . '\guard_first', 20 );
	Guard_Editor::$mode = 'none';

	$cases = array(
		array( 'jpg', 'lossless', 'lossy' ),
		array( 'deckend', 'lossless', 'lossy' ),
		array( 'freisteller', 'lossless', 'lossless' ),
		array( 'freisteller', 'auto', 'lossy-alpha' ),
	);

	foreach ( $cases as $case ) {
		list( $kind, $png, $expected_mode ) = $case;
		$sizes                              = array();

		foreach ( array( 35, 82, 95 ) as $quality ) {
			Guard_Editor::$log = array();
			$target            = $dir . "/out-{$kind}-{$png}-{$quality}.webp";
			$result            = Encoder::encode( $sources[ $kind ], $target, settings( $quality, $png ) );

			if ( is_wp_error( $result ) ) {
				check( false, "{$kind}/{$png} q{$quality}: " . $result->get_error_message() );
				continue;
			}

			$expected = 'lossless' === $expected_mode ? 100 : $quality;
			$entry    = 'lossy-alpha' === $expected_mode ? last_temp_log() : last_log( wp_basename( $target ) );

			check( $expected_mode === $result['mode'], "{$kind}/{$png} q{$quality}: Modus {$result['mode']}" );
			check( $expected === (int) $result['quality'], "{$kind}/{$png} q{$quality}: gemeldete Qualität {$result['quality']}" );
			check( null !== $entry && $expected === $entry['image'] && $expected === $entry['wand'] && $expected === $entry['editor'], "{$kind}/{$png} q{$quality}: Qualität am Kodierpunkt " . ( $entry ? "{$entry['image']}/{$entry['wand']}/{$entry['editor']}" : 'fehlt' ) . ", erwartet {$expected}" );

			$sizes[ $quality ] = (int) $result['bytes'];
		}

		if ( $bytes_reliable && 'lossless' !== $expected_mode && 3 === count( $sizes ) ) {
			check( $sizes[35] < $sizes[82] && $sizes[82] < $sizes[95], "{$kind}/{$png}: Dateigröße steigt mit der Qualität (" . implode( ' < ', $sizes ) . ' B)' );
		}
	}

	remove_filter( 'wp_image_editors', __NAMESPACE__ . '\guard_first', 20 );

	check( false === has_filter( 'wp_editor_set_quality', array( Encoder::class, 'pinned_quality' ) ), 'Qualitätsfilter ist nach dem Speichern wieder entfernt' );

	$editor = wp_get_image_editor( $sources['jpg'] );
	check( ! is_wp_error( $editor ) && 82 === (int) $editor->get_quality(), 'Andere Editoren behalten den Core-Standard (JPG 82)' );
}

/**
 * Teil 1b: Qualität mit GD, belegt über die Dateigröße.
 *
 * @param string $dir Arbeitsordner.
 * @return void
 */
function quality_gd( $dir ) {
	WP_CLI::log( '== Qualität am Kodierpunkt, GD' );

	if ( ! function_exists( 'imagewebp' ) ) {
		WP_CLI::log( '  übersprungen: GD ohne WebP' );
		return;
	}

	$sources = array(
		'jpg' => $dir . '/g-foto.jpg',
		'png' => $dir . '/g-deckend.png',
	);
	write_gd( 'jpg', $sources['jpg'] );
	write_gd( 'png', $sources['png'] );

	add_filter( 'wp_image_editors', __NAMESPACE__ . '\gd_only', 30 );

	foreach ( $sources as $kind => $source ) {
		$sizes  = array();
		$hashes = array();

		foreach ( array( 35, 82, 86, 95 ) as $quality ) {
			$target = $dir . "/gd-{$kind}-{$quality}.webp";
			$result = Encoder::encode( $source, $target, settings( $quality ) );

			if ( is_wp_error( $result ) ) {
				check( false, "GD {$kind} q{$quality}: " . $result->get_error_message() );
				continue;
			}
			check( 'gd' === $result['editor'] && $quality === (int) $result['quality'], "GD {$kind} q{$quality}: Editor {$result['editor']}, gemeldet {$result['quality']}" );
			$sizes[ $quality ]  = (int) $result['bytes'];
			$hashes[ $quality ] = hash_file( 'sha256', $result['path'] );
		}

		if ( 4 === count( $sizes ) ) {
			check( $sizes[35] < $sizes[82] && $sizes[82] < $sizes[95], "GD {$kind}: Dateigröße steigt mit der Qualität ({$sizes[35]} < {$sizes[82]} < {$sizes[95]} B)" );
			check( $hashes[82] !== $hashes[86], "GD {$kind}: 82 ist nicht der Core-Standard 86 ({$sizes[82]} gegen {$sizes[86]} B)" );
		}
	}

	remove_filter( 'wp_image_editors', __NAMESPACE__ . '\gd_only', 30 );
}

/**
 * Teil 2: Eine Störung der zweiten Fassung prüfen.
 *
 * @param string   $dir     Arbeitsordner.
 * @param string   $label   Bezeichnung.
 * @param string   $mode    Störung im Test-Editor.
 * @param callable $arrange Optional: setzt Filter oder Dateien vor dem Lauf, gibt Aufräum-Funktion zurück.
 * @return void
 */
function guard_case( $dir, $label, $mode, $arrange = null ) {
	$work = $dir . '/' . sanitize_title( $label );
	wp_mkdir_p( $work );
	$source = $work . '/freisteller.png';
	write_noise_cutout( $source );

	$cleanup            = $arrange ? call_user_func( $arrange, $work ) : null;
	$before             = listing( $work );
	Guard_Editor::$mode = $mode;
	Guard_Editor::$log  = array();

	$target = $work . '/freisteller-1.webp';
	$result = Encoder::encode( $source, $target, settings( 82, 'auto' ) );

	Guard_Editor::$mode = 'none';

	if ( is_wp_error( $result ) ) {
		check( false, "{$label}: " . $result->get_error_message() );
		return;
	}

	$info  = Webp_Info::read( $target );
	$after = listing( $work );
	$left  = array_filter(
		array_keys( $after ),
		function ( $name ) {
			return 0 === strpos( $name, 'akwu-alpha-' ) && '.webp' === substr( $name, -5 );
		}
	);

	if ( 'none' === $mode && null === $arrange ) {
		check( 'lossy-alpha' === $result['mode'], "{$label}: Modus {$result['mode']}" );
		check( null !== $info && 'lossy' === $info['compression'] && $info['alpha'], "{$label}: Ergebnis ist WebP verlustbehaftet mit Alphakanal" );
	} else {
		check( 'lossless' === $result['mode'] && 100 === (int) $result['quality'], "{$label}: verlustfreie Fassung bleibt (Modus {$result['mode']}, Qualität {$result['quality']})" );
		check( null !== $info && 'lossless' === $info['compression'] && $info['alpha'], "{$label}: Zieldatei ist echtes verlustfreies WebP mit Alphakanal" );
	}

	check( null !== $info && 640 === $info['width'] && 420 === $info['height'], "{$label}: Maße " . ( $info ? "{$info['width']}×{$info['height']}" : 'unlesbar' ) );
	check( empty( $left ), "{$label}: keine eigene Zwischendatei übrig" . ( $left ? ' (' . implode( ', ', $left ) . ')' : '' ) );

	// Alles, was vorher da war, ist unverändert. Neu ist nur die Zieldatei und was der Filter selbst benannt hat.
	foreach ( $before as $name => $hash ) {
		check( isset( $after[ $name ] ) && $after[ $name ] === $hash, "{$label}: {$name} unverändert" );
	}

	if ( is_callable( $cleanup ) ) {
		call_user_func( $cleanup, $work, $after );
	}
}

/**
 * Teil 2: alle Störungen.
 *
 * @param string $dir Arbeitsordner.
 * @return void
 */
function guards( $dir ) {
	WP_CLI::log( '== Zweite Fassung bei Freistellern (automatisch)' );

	add_filter( 'wp_image_editors', __NAMESPACE__ . '\guard_first', 20 );

	guard_case( $dir, 'ohne Störung', 'none' );

	// Filter verlangt für die Zwischendatei JPEG: Der Core würde die Endung ändern und ein JPEG schreiben.
	guard_case(
		$dir,
		'Filter JPEG fuer Zwischendatei',
		'none',
		function () {
			$filter = function ( $formats, $filename ) {
				if ( $filename && 0 === strpos( wp_basename( $filename ), 'akwu-alpha-' ) ) {
					$formats['image/webp'] = 'image/jpeg';
				}
				return $formats;
			};
			add_filter( 'image_editor_output_format', $filter, 10, 2 );

			return function ( $work ) use ( $filter ) {
				remove_filter( 'image_editor_output_format', $filter, 10 );
				$jpegs = glob( $work . '/akwu-alpha-*.jpg' );
				check( empty( $jpegs ), 'Filter JPEG fuer Zwischendatei: kein JPEG geschrieben' );
			};
		}
	);

	// Filter, der erst beim zweiten Aufruf je Datei umschaltet: save() schreibt dann ein JPEG unter dem
	// vom Filter bestimmten Namen. Das Plugin darf diese Datei weder übernehmen noch löschen.
	guard_case(
		$dir,
		'Filter schaltet waehrend save um',
		'none',
		function () {
			$seen   = array();
			$filter = function ( $formats, $filename ) use ( &$seen ) {
				$name = $filename ? wp_basename( $filename ) : '';
				if ( 0 === strpos( $name, 'akwu-alpha-' ) ) {
					$seen[ $name ] = isset( $seen[ $name ] ) ? $seen[ $name ] + 1 : 1;
					if ( $seen[ $name ] >= 2 ) {
						$formats['image/webp'] = 'image/jpeg';
					}
				}
				return $formats;
			};
			add_filter( 'image_editor_output_format', $filter, 10, 2 );

			return function ( $work ) use ( $filter ) {
				remove_filter( 'image_editor_output_format', $filter, 10 );
				$jpegs = (array) glob( $work . '/akwu-alpha-*.jpg' );
				check( 1 === count( $jpegs ) && 'image/jpeg' === wp_get_image_mime( $jpegs[0] ), 'Filter schaltet waehrend save um: vom Filter benanntes JPEG bleibt unberührt liegen' );
				foreach ( $jpegs as $jpeg ) {
					wp_delete_file( $jpeg );
				}
			};
		}
	);

	// save() meldet einen fremden Pfad. Der darf weder umbenannt noch gelöscht werden.
	guard_case(
		$dir,
		'fremder Pfad',
		'foreign-path',
		function ( $work ) {
			Guard_Editor::$foreign = $work . '/fremd.webp';
			copy( __FILE__, Guard_Editor::$foreign );
			return function () {
				Guard_Editor::$foreign = '';
			};
		}
	);

	guard_case( $dir, 'Fehler nach dem Schreiben', 'error-after-write' );
	guard_case( $dir, 'kein WebP', 'not-webp' );
	guard_case( $dir, 'Alphakanal verloren', 'no-alpha' );
	guard_case( $dir, 'falsche Masse', 'wrong-size' );
	guard_case( $dir, 'abgeschnittene Datei', 'truncated' );

	remove_filter( 'wp_image_editors', __NAMESPACE__ . '\guard_first', 20 );
}

/**
 * Ablauf.
 *
 * @return void
 */
function main() {
	global $akwu_failures;
	$akwu_failures = 0;

	Encoder::load_editors();
	require_once __DIR__ . '/fixtures/class-guard-editor.php';

	$dir = untrailingslashit( get_temp_dir() ) . '/akwu-encoder-guards-' . wp_generate_password( 8, false );
	wp_mkdir_p( $dir );

	if ( extension_loaded( 'imagick' ) && Guard_Editor::test( array( 'mime_type' => 'image/webp' ) ) ) {
		quality_imagick( $dir );
		guards( $dir );
	} else {
		WP_CLI::log( '== Imagick fehlt: Qualität mit Imagick und zweite Fassung übersprungen' );
	}

	quality_gd( $dir );

	// Arbeitsordner abräumen (nur eigene Testdateien).
	$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Eigener Testordner.
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Eigener Testordner.

	if ( $akwu_failures ) {
		WP_CLI::error( "{$akwu_failures} Prüfungen fehlgeschlagen." );
	}
	WP_CLI::success( 'Qualität und zweite PNG-Fassung wie erwartet.' );
}

main();
