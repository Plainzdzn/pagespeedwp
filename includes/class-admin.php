<?php
/**
 * Admin-Menü und Seiten.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Registriert den Menüpunkt mit seinen Unterseiten und lädt die Assets nur dort.
 */
final class Admin {

	/**
	 * Recht für alle Seiten und Aktionen des Plugins.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Slug des Hauptmenüs, zugleich die Übersicht.
	 */
	const MENU_SLUG = 'akwu';

	/**
	 * Systemprüfung.
	 *
	 * @var System_Check
	 */
	private $system_check;

	/**
	 * Hook-Suffixe der Plugin-Seiten.
	 *
	 * @var string[]
	 */
	private $hook_suffixes = array();

	/**
	 * Konstruktor.
	 *
	 * @param System_Check $system_check Systemprüfung.
	 */
	public function __construct( System_Check $system_check ) {
		$this->system_check = $system_check;
	}

	/**
	 * Hängt sich in WordPress ein.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Alle Seiten des Plugins in Menü-Reihenfolge.
	 *
	 * @return array<string, array{menu: string, view: string, icon: string, group: string}>
	 */
	public static function pages() {
		return array(
			self::MENU_SLUG       => array(
				'menu'  => __( 'Übersicht', 'akuma-webp-umwandler' ),
				'view'  => 'page-overview',
				'icon'  => 'grid',
				'group' => 'main',
			),
			'akwu-umwandlung'     => array(
				'menu'  => __( 'Umwandlung', 'akuma-webp-umwandler' ),
				'view'  => 'page-conversion',
				'icon'  => 'cycle',
				'group' => 'main',
			),
			'akwu-bericht'        => array(
				'menu'  => __( 'Bericht', 'akuma-webp-umwandler' ),
				'view'  => 'page-report',
				'icon'  => 'chart',
				'group' => 'main',
			),
			'akwu-bilder'         => array(
				'menu'  => __( 'Alle Bilder', 'akuma-webp-umwandler' ),
				'view'  => 'page-images',
				'icon'  => 'image',
				'group' => 'main',
			),
			'akwu-einstellungen'  => array(
				'menu'  => __( 'Einstellungen', 'akuma-webp-umwandler' ),
				'view'  => 'page-settings',
				'icon'  => 'sliders',
				'group' => 'setup',
			),
			'akwu-systempruefung' => array(
				'menu'  => __( 'Systemprüfung', 'akuma-webp-umwandler' ),
				'view'  => 'page-system',
				'icon'  => 'shield',
				'group' => 'setup',
			),
			'akwu-rueckgaengig'   => array(
				'menu'  => __( 'Rückgängig', 'akuma-webp-umwandler' ),
				'view'  => 'page-rollback',
				'icon'  => 'undo',
				'group' => 'setup',
			),
		);
	}

	/**
	 * Menüpunkt und Unterseiten. WordPress zeigt die Unterseiten selbst im Menü an.
	 *
	 * Alle Seiten nutzen denselben Callback, damit WordPress ihn für die Übersicht
	 * (gleicher Slug wie das Hauptmenü) nicht doppelt registriert.
	 *
	 * @return void
	 */
	public function add_menu() {
		$plugin_name = __( 'WebP-Umwandler', 'akuma-webp-umwandler' );

		$this->hook_suffixes[] = add_menu_page(
			$plugin_name,
			$plugin_name,
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-format-image',
			81
		);

		foreach ( self::pages() as $slug => $page ) {
			$this->hook_suffixes[] = add_submenu_page(
				self::MENU_SLUG,
				/* translators: 1: Seitenname, 2: Plugin-Name. */
				sprintf( __( '%1$s – %2$s', 'akuma-webp-umwandler' ), $page['menu'], $plugin_name ),
				$page['menu'],
				self::CAPABILITY,
				$slug,
				array( $this, 'render' )
			);
		}

		$this->hook_suffixes = array_values( array_unique( array_filter( $this->hook_suffixes ) ) );
	}

	/**
	 * Lädt CSS nur auf den Seiten des Plugins.
	 *
	 * @param string $hook_suffix Aktuelle Admin-Seite.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, $this->hook_suffixes, true ) ) {
			return;
		}

		wp_enqueue_style( 'akwu-admin', AKWU_URL . 'assets/admin.css', array(), AKWU_VERSION );
	}

	/**
	 * Rendert die aktuelle Plugin-Seite im gemeinsamen Layout.
	 *
	 * @return void
	 */
	public function render() {
		global $plugin_page;

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Dafür fehlen die Rechte.', 'akuma-webp-umwandler' ) );
		}

		$pages   = self::pages();
		$current = ( is_string( $plugin_page ) && isset( $pages[ $plugin_page ] ) ) ? $plugin_page : self::MENU_SLUG;

		View::render(
			'layout',
			array(
				'pages'        => $pages,
				'current'      => $current,
				'system_check' => $this->system_check,
			)
		);
	}

	/**
	 * URL einer Plugin-Seite.
	 *
	 * @param string $slug Seiten-Slug.
	 * @return string
	 */
	public static function page_url( $slug ) {
		return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
	}
}
