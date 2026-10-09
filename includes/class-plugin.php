<?php
/**
 * Einstiegspunkt des Plugins.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert die Teile des Plugins und behandelt die Aktivierung.
 */
final class Plugin {

	/**
	 * Startet das Plugin. Bei Multisite wird nur ein Hinweis registriert.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( is_multisite() ) {
			add_action( 'admin_notices', array( __CLASS__, 'multisite_notice' ) );
			add_action( 'network_admin_notices', array( __CLASS__, 'multisite_notice' ) );
			return;
		}

		( new Rest_Controller() )->register();

		// Wird ein umgewandeltes Bild aus der Mediathek gelöscht, die alten Originale mitnehmen.
		add_action( 'delete_attachment', array( Originals::class, 'on_delete_attachment' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register();
		}

		if ( is_admin() ) {
			$admin = new Admin( new System_Check() );
			$admin->register();
		}
	}

	/**
	 * Aktivierung. Bricht bei Multisite ab, damit das Plugin dort nicht aktiv wird.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( is_multisite() ) {
			wp_die(
				esc_html__( 'Der WebP-Umwandler unterstützt Multisite-Installationen noch nicht und wurde nicht aktiviert.', 'akuma-webp-umwandler' ),
				esc_html__( 'WebP-Umwandler', 'akuma-webp-umwandler' ),
				array( 'back_link' => true )
			);
		}

		Log_Table::install();
	}

	/**
	 * Hinweis, falls das Plugin in einer Multisite trotzdem aktiv ist.
	 *
	 * @return void
	 */
	public static function multisite_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Der WebP-Umwandler unterstützt Multisite-Installationen noch nicht. Er ist aktiv, tut aber nichts. Bitte deaktivieren.', 'akuma-webp-umwandler' )
		);
	}
}
