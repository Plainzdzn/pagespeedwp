<?php
/**
 * Erkennung anderer Bildoptimierer.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Findet aktive Plugins mit eigener Bildkomprimierung oder WebP-Auslieferung.
 *
 * Erkennt nur, stellt nichts um.
 */
final class Conflict_Detector {

	/**
	 * Plugin-Basename von FastPixel.
	 */
	const FASTPIXEL = 'fastpixel-website-accelerator/fastpixel.php';

	/**
	 * Bekannte Bildoptimierer: Basename => Name.
	 *
	 * Basenames geprüft an den aktuellen Versionen auf wordpress.org (2026-10-09).
	 * Umbenannte Ordner oder Pro-Varianten mit anderem Basename werden nicht erkannt.
	 */
	const KNOWN = array(
		self::FASTPIXEL                                 => 'FastPixel',
		'webp-express/webp-express.php'                 => 'WebP Express',
		'webp-converter-for-media/webp-converter-for-media.php' => 'Converter for Media',
		'imagify/imagify.php'                           => 'Imagify',
		'shortpixel-image-optimiser/wp-shortpixel.php'  => 'ShortPixel Image Optimizer',
		'ewww-image-optimizer/ewww-image-optimizer.php' => 'EWWW Image Optimizer',
		'wp-smushit/wp-smush.php'                       => 'Smush',
		'optimole-wp/optimole-wp.php'                   => 'Optimole',
	);

	/**
	 * Stufen der FastPixel-Bildkomprimierung (Option `fastpixel_images_optimization`, FastPixel 2.0).
	 */
	const FASTPIXEL_LEVELS = array(
		1 => 'Lossy',
		2 => 'Glossy',
		3 => 'Lossless',
	);

	/**
	 * Findet bekannte Bildoptimierer in einer Liste aktiver Plugins.
	 *
	 * @param string[] $active_plugins Basenames wie in der Option `active_plugins`.
	 * @return array<string, string> Basename => Name, in der Reihenfolge von KNOWN.
	 */
	public static function detect( array $active_plugins ) {
		$found = array();

		foreach ( self::KNOWN as $basename => $name ) {
			if ( in_array( $basename, $active_plugins, true ) ) {
				$found[ $basename ] = $name;
			}
		}

		return $found;
	}

	/**
	 * Normalisiert die FastPixel-Stufe so, wie FastPixel 2.0 sie liest.
	 *
	 * FastPixel nimmt bei fehlenden oder unbekannten Werten „Lossy“ (1).
	 *
	 * @param mixed $raw Wert der Option `fastpixel_images_optimization`.
	 * @return int 1, 2 oder 3.
	 */
	public static function fastpixel_level( $raw ) {
		$level = is_scalar( $raw ) ? (int) $raw : 0;

		return isset( self::FASTPIXEL_LEVELS[ $level ] ) ? $level : 1;
	}
}
