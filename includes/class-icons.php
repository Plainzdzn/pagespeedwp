<?php
/**
 * SVG-Icons der Oberfläche.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Linien-Icons aus den Design-Mockups (24er-Raster, Strichstärke über currentColor).
 */
final class Icons {

	/**
	 * Inhalt der Icons ohne umschließendes svg-Element.
	 */
	const SHAPES = array(
		'logo'    => '<rect x="3" y="3" width="18" height="18" rx="4"></rect><circle cx="9" cy="9" r="2"></circle><path d="M21 15l-5-5L5 21"></path>',
		'grid'    => '<rect x="3" y="3" width="7" height="7" rx="2"></rect><rect x="14" y="3" width="7" height="7" rx="2"></rect><rect x="3" y="14" width="7" height="7" rx="2"></rect><rect x="14" y="14" width="7" height="7" rx="2"></rect>',
		'cycle'   => '<path d="M4 12a8 8 0 0 1 14-5.3L20 9"></path><path d="M20 4v5h-5"></path><path d="M20 12a8 8 0 0 1-14 5.3L4 15"></path><path d="M4 20v-5h5"></path>',
		'chart'   => '<path d="M5 20V10M12 20V4M19 20v-7"></path>',
		'image'   => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="M21 16l-5-5-8 8"></path>',
		'sliders' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"></path><circle cx="16" cy="7" r="2"></circle><circle cx="10" cy="17" r="2"></circle>',
		'shield'  => '<path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"></path><path d="M9 12l2 2 4-4"></path>',
		'undo'    => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5"></path>',
		'arrow'   => '<path d="M7 17L17 7"></path><path d="M8 7h9v9"></path>',
		'pause'   => '<path d="M9 5v14"></path><path d="M15 5v14"></path>',
		'play'    => '<path d="M8 5l11 7-11 7z"></path>',
	);

	/**
	 * Gibt ein Icon aus. Icons sind dekorativ (aria-hidden).
	 *
	 * @param string $name   Schlüssel aus SHAPES.
	 * @param int    $size   Kantenlänge in Pixeln.
	 * @param string $stroke Strichstärke.
	 * @return void
	 */
	public static function render( $name, $size = 18, $stroke = '1.7' ) {
		echo wp_kses( self::svg( $name, $size, $stroke ), self::allowed_html() );
	}

	/**
	 * SVG-Markup eines Icons.
	 *
	 * @param string $name   Schlüssel aus SHAPES.
	 * @param int    $size   Kantenlänge in Pixeln.
	 * @param string $stroke Strichstärke.
	 * @return string Leer, wenn das Icon unbekannt ist.
	 */
	public static function svg( $name, $size = 18, $stroke = '1.7' ) {
		if ( ! isset( self::SHAPES[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="akwu-icon" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%2$s" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			(int) $size,
			preg_replace( '/[^0-9.]/', '', (string) $stroke ),
			self::SHAPES[ $name ]
		);
	}

	/**
	 * Erlaubte Elemente und Attribute für wp_kses().
	 *
	 * @return array
	 */
	private static function allowed_html() {
		return array(
			'svg'    => array(
				'class'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
				'focusable'       => true,
			),
			'path'   => array( 'd' => true ),
			'rect'   => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
			),
			'circle' => array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
		);
	}
}
