<?php
/**
 * Imagick-Editor mit verlustfreiem WebP und ohne Metadaten.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Erweitert den Imagick-Editor von WordPress um zwei Schalter, die der Core nicht anbietet.
 *
 * Wird nur von Encoder::encode() über den Filter `wp_image_editors` eingesetzt.
 * Die Core-Klasse muss vorher geladen sein (Encoder::load_editors()).
 */
class Imagick_Webp_Editor extends \WP_Image_Editor_Imagick {

	/**
	 * Speichert WebP verlustfrei (für PNG mit Transparenz).
	 *
	 * @return bool
	 */
	public function akwu_set_lossless() {
		try {
			$this->image->setImageCompressionQuality( 100 );
			$this->image->setCompressionQuality( 100 );
			return $this->image->setOption( 'webp:lossless', 'true' );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Entfernt EXIF- und andere Metadaten, behält aber Farbprofile.
	 *
	 * @return bool
	 */
	public function akwu_strip_meta() {
		return true === $this->strip_meta();
	}
}
