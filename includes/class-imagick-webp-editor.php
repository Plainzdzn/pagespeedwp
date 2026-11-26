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
	 * Hat das Bild einen Alphakanal, aber kein einziges durchsichtiges Pixel?
	 *
	 * @return bool|null Siehe Png_Info::imagick_opaque().
	 */
	public function akwu_is_opaque() {
		return Png_Info::imagick_opaque( $this->image );
	}

	/**
	 * Schaltet einen leeren Alphakanal ab, damit das WebP keinen Alphakanal mitschleppt.
	 *
	 * @return bool
	 */
	public function akwu_drop_alpha() {
		try {
			$mode = defined( '\Imagick::ALPHACHANNEL_DEACTIVATE' ) ? \Imagick::ALPHACHANNEL_DEACTIVATE : \Imagick::ALPHACHANNEL_OFF;
			return (bool) $this->image->setImageAlphaChannel( $mode );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Verlustbehaftet mit verlustfreiem Alphakanal (für Freisteller, Einstellung „automatisch“).
	 *
	 * @param int $quality Qualität für die Farbwerte.
	 * @return bool
	 */
	public function akwu_set_lossy_alpha( $quality ) {
		try {
			// Zuerst die Qualität im Editor setzen: Nach dem ersten Speichern zeigt der Editor auf die
			// verlustfreie WebP-Datei, und set_quality() des Core schaltet dann selbst auf verlustfrei.
			// Die Optionen danach überschreiben das wieder.
			$this->set_quality( (int) $quality );
			$this->image->setOption( 'webp:lossless', 'false' );
			$this->image->setOption( 'webp:alpha-quality', '100' );
			$this->image->setOption( 'webp:alpha-compression', '1' );
			$this->image->setImageCompressionQuality( (int) $quality );
			$this->image->setCompressionQuality( (int) $quality );
			return 'false' === $this->image->getOption( 'webp:lossless' );
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
