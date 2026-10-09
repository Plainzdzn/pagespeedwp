<?php
/**
 * Einstellungen des Plugins.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Liest und speichert die Einstellungen in der Option `akwu_settings`.
 */
final class Settings {

	/**
	 * Name der Option.
	 */
	const OPTION = 'akwu_settings';

	/**
	 * Standardwerte laut Briefing.
	 */
	const DEFAULTS = array(
		'quality_jpg' => 82,
		'quality_png' => 90,
		'min_savings' => 10,
		'batch_size'  => 10,
		'psi_api_key' => '',
	);

	/**
	 * Erlaubte Bereiche für Zahlenwerte: Schlüssel => array( min, max ).
	 */
	const RANGES = array(
		'quality_jpg' => array( 40, 100 ),
		'quality_png' => array( 40, 100 ),
		'min_savings' => array( 0, 90 ),
		'batch_size'  => array( 1, 50 ),
	);

	/**
	 * Alle Einstellungen, ergänzt um Standardwerte.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Eine Einstellung.
	 *
	 * @param string $key Schlüssel aus DEFAULTS.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Bereinigt Eingaben. Unbekannte Schlüssel fallen weg, Zahlen werden in ihren Bereich geholt.
	 *
	 * @param mixed $input Rohwerte, z. B. aus dem Formular.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = self::DEFAULTS;

		foreach ( self::RANGES as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$output[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}

		if ( isset( $input['psi_api_key'] ) && is_string( $input['psi_api_key'] ) ) {
			$output['psi_api_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $input['psi_api_key'] );
		}

		return $output;
	}

	/**
	 * Registriert die Option für das Einstellungsformular.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'akwu_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::DEFAULTS,
				'show_in_rest'      => false,
			)
		);
	}
}
