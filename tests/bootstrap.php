<?php
/**
 * PHPUnit-Bootstrap für Unit-Tests ohne WordPress.
 *
 * Getestet werden nur Klassen, die keine WordPress-Funktionen aufrufen.
 *
 * @package Akuma\WebpUmwandler
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Ersatz für WordPress in Unit-Tests.
}

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
Akuma\WebpUmwandler\Autoloader::register( dirname( __DIR__ ) . '/includes/' );
