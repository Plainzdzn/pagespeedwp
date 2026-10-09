<?php
/**
 * Autoloader für die Klassen des Plugins.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Lädt Klassen aus dem Namespace des Plugins nach WPCS-Dateinamen.
 *
 * Beispiele: `Cache_Purger` → `class-cache-purger.php`,
 * `Cli\Command` → `cli/class-command.php`.
 */
final class Autoloader {

	/**
	 * Namespace-Prefix der Plugin-Klassen.
	 */
	const PREFIX = 'Akuma\\WebpUmwandler\\';

	/**
	 * Registriert den Autoloader.
	 *
	 * @param string $base_dir Verzeichnis der Klassendateien, mit abschließendem Slash.
	 * @return void
	 */
	public static function register( $base_dir ) {
		spl_autoload_register(
			static function ( $class_name ) use ( $base_dir ) {
				$file = self::file_for( $class_name );
				if ( null !== $file && is_readable( $base_dir . $file ) ) {
					require_once $base_dir . $file;
				}
			}
		);
	}

	/**
	 * Ermittelt den relativen Dateipfad zu einem Klassennamen.
	 *
	 * @param string $class_name Vollqualifizierter Klassenname.
	 * @return string|null Relativer Pfad oder null, wenn die Klasse nicht zum Plugin gehört.
	 */
	public static function file_for( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return null;
		}

		$parts = explode( '\\', substr( $class_name, strlen( self::PREFIX ) ) );
		$class = array_pop( $parts );
		$path  = '';

		foreach ( $parts as $part ) {
			$path .= self::slug( $part ) . '/';
		}

		return $path . 'class-' . self::slug( $class ) . '.php';
	}

	/**
	 * Wandelt einen Namensteil in einen Dateinamensteil um.
	 *
	 * @param string $name Klassen- oder Namespace-Teil.
	 * @return string
	 */
	private static function slug( $name ) {
		return strtolower( str_replace( '_', '-', $name ) );
	}
}
