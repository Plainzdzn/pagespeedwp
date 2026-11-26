<?php
/**
 * Test-Editor für tests/integration/encoder-guards.php.
 *
 * Erst nach Encoder::load_editors() einbinden, die Elternklassen müssen geladen sein.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Integration\EncoderGuards;

use Akuma\WebpUmwandler\Imagick_Webp_Editor;

/**
 * Wie der Editor des Plugins, mit zwei Zusätzen nur für Tests:
 *
 * - Protokoll: Nach jedem get_output_format() (das ist der letzte Schritt vor dem Schreiben) wird die
 *   Qualität festgehalten, die am Imagick-Objekt tatsächlich gesetzt ist.
 * - Störungen: Für die eigene Zwischendatei des Plugins (Name beginnt mit akwu-alpha-) liefert save()
 *   je nach $mode ein falsches Ergebnis, wie es ein anderer Editor oder ein Fehler täte.
 */
class Guard_Editor extends Imagick_Webp_Editor {

	/**
	 * Störung für die Zwischendatei: none, foreign-path, error-after-write, not-webp, no-alpha,
	 * wrong-size, truncated.
	 *
	 * @var string
	 */
	public static $mode = 'none';

	/**
	 * Fremde Datei, die save() bei foreign-path meldet.
	 *
	 * @var string
	 */
	public static $foreign = '';

	/**
	 * Protokoll: je Eintrag Dateiname, Bildqualität, Qualität im Editor.
	 *
	 * @var array[]
	 */
	public static $log = array();

	/**
	 * Hält nach dem Core-Aufruf die tatsächlich gesetzte Qualität fest.
	 *
	 * @param string $filename  Pfad.
	 * @param string $mime_type Typ.
	 * @return array
	 */
	protected function get_output_format( $filename = null, $mime_type = null ) {
		$format = parent::get_output_format( $filename, $mime_type );

		self::$log[] = array(
			'file'    => $filename ? wp_basename( $filename ) : '',
			'image'   => (int) $this->image->getImageCompressionQuality(),
			'wand'    => (int) $this->image->getCompressionQuality(),
			'editor'  => (int) $this->get_quality(),
			'mime'    => isset( $format[2] ) ? $format[2] : '',
			'outfile' => isset( $format[0] ) && $format[0] ? wp_basename( $format[0] ) : '',
		);

		return $format;
	}

	/**
	 * Speichert, bei der Zwischendatei gestört nach $mode.
	 *
	 * @param string $destfilename Ziel.
	 * @param string $mime_type    Typ.
	 * @return array|\WP_Error
	 */
	public function save( $destfilename = null, $mime_type = null ) {
		if ( 'none' === self::$mode || ! $destfilename || 0 !== strpos( wp_basename( $destfilename ), 'akwu-alpha-' ) ) {
			return parent::save( $destfilename, $mime_type );
		}

		switch ( self::$mode ) {
			case 'foreign-path':
				$saved = parent::save( $destfilename, $mime_type );
				if ( ! is_wp_error( $saved ) ) {
					$saved['path'] = self::$foreign;
					$saved['file'] = wp_basename( self::$foreign );
				}
				return $saved;

			case 'error-after-write':
				parent::save( $destfilename, $mime_type );
				return new \WP_Error( 'akwu_test', 'Test: Fehler nach dem Schreiben.' );

			case 'not-webp':
				$jpeg = clone $this->image;
				$jpeg->setImageFormat( 'JPEG' );
				file_put_contents( $destfilename, $jpeg->getImageBlob() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei.
				return array(
					'path'      => $destfilename,
					'file'      => wp_basename( $destfilename ),
					'width'     => $this->size['width'],
					'height'    => $this->size['height'],
					'mime-type' => 'image/webp',
				);

			case 'no-alpha':
				$this->image->setImageAlphaChannel( defined( '\Imagick::ALPHACHANNEL_REMOVE' ) ? \Imagick::ALPHACHANNEL_REMOVE : \Imagick::ALPHACHANNEL_OFF );
				return parent::save( $destfilename, $mime_type );

			case 'wrong-size':
				$this->image->scaleImage( (int) ( $this->size['width'] / 2 ), (int) ( $this->size['height'] / 2 ) );
				return parent::save( $destfilename, $mime_type );

			case 'truncated':
				$saved = parent::save( $destfilename, $mime_type );
				if ( ! is_wp_error( $saved ) ) {
					$data = (string) file_get_contents( $destfilename ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Testdatei.
					file_put_contents( $destfilename, substr( $data, 0, (int) ( strlen( $data ) / 2 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei.
				}
				return $saved;
		}

		return parent::save( $destfilename, $mime_type );
	}
}
