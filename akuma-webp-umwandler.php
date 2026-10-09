<?php
/**
 * Plugin Name:       WebP-Umwandler
 * Description:       Ersetzt PNG- und JPG-Bilder der Mediathek durch WebP, mit derselben Attachment-ID. Verweise in der Datenbank werden mitgezogen, danach kann das Plugin entfernt werden.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Akuma Digital
 * Text Domain:       akuma-webp-umwandler
 *
 * @package Akuma\WebpUmwandler
 */

defined( 'ABSPATH' ) || exit;

define( 'AKWU_VERSION', '0.1.0' );
define( 'AKWU_FILE', __FILE__ );
define( 'AKWU_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKWU_URL', plugin_dir_url( __FILE__ ) );

require_once AKWU_DIR . 'includes/class-autoloader.php';
Akuma\WebpUmwandler\Autoloader::register( AKWU_DIR . 'includes/' );

register_activation_hook( __FILE__, array( Akuma\WebpUmwandler\Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( Akuma\WebpUmwandler\Plugin::class, 'boot' ) );
