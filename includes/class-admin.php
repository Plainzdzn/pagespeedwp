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
		add_action( 'admin_init', array( Settings::class, 'register' ) );
		add_action( 'admin_init', array( Log_Table::class, 'maybe_install' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
	}

	/**
	 * Fortschritt in der Admin-Leiste, solange eine Umwandlung offen ist.
	 *
	 * @param \WP_Admin_Bar $bar Admin-Leiste.
	 * @return void
	 */
	public function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$run = Conversion::current();
		if ( ! Conversion::is_active( $run ) ) {
			return;
		}

		$progress = Conversion::progress( $run );

		$bar->add_node(
			array(
				'id'    => 'akwu-progress',
				/* translators: %s: Fortschritt in Prozent. */
				'title' => '<span class="akwu-bar-label">' . esc_html( sprintf( __( 'WebP-Umwandler · %s %%', 'akuma-webp-umwandler' ), $progress['percent'] ) ) . '</span>',
				'href'  => self::page_url( 'akwu-umwandlung' ),
			)
		);
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
		wp_enqueue_script( 'akwu-admin', AKWU_URL . 'assets/admin.js', array(), AKWU_VERSION, true );
		wp_add_inline_script(
			'akwu-admin',
			'window.akwuAdmin = ' . wp_json_encode(
				array(
					'restUrl'    => esc_url_raw( rest_url( Rest_Controller::NAMESPACE_V1 . '/' ) ),
					'convertUrl' => esc_url_raw( self::page_url( 'akwu-umwandlung' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'i18n'       => array(
						'error'     => __( 'Das hat nicht geklappt. Bitte die Seite neu laden und noch einmal versuchen.', 'akuma-webp-umwandler' ),
						'scanDone'  => __( 'Scan abgeschlossen. Seite wird neu geladen …', 'akuma-webp-umwandler' ),
						'scanStart' => __( 'Scan startet …', 'akuma-webp-umwandler' ),
						'backup'    => __( 'Bitte zuerst bestätigen, dass ein Backup erstellt ist.', 'akuma-webp-umwandler' ),
						'cancel'    => __( 'Umwandlung abbrechen und alle Bilder dieses Laufs zurück ins Original setzen?', 'akuma-webp-umwandler' ),
						'retry'     => __( 'Verbindung unterbrochen. Neuer Versuch in wenigen Sekunden …', 'akuma-webp-umwandler' ),
					),
				)
			) . ';',
			'before'
		);
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
		$result  = Scan_Result::load();
		$run     = Conversion::current();

		View::render(
			'layout',
			array(
				'pages'          => $pages,
				'current'        => $current,
				'system_check'   => $this->system_check,
				'scan'           => $result,
				'scan_state'     => Scanner::state(),
				'run'            => $run,
				'query'          => self::query_args(),
				'notices'        => $this->notices( $current, $result, $run ),
				'header_actions' => self::header_actions( $current, $run ),
			)
		);
	}

	/**
	 * Hinweise im WordPress-Stil für die aktuelle Seite.
	 *
	 * @param string           $current Slug der Seite.
	 * @param Scan_Result|null $result  Scan-Ergebnis.
	 * @param array|null       $run     Aktueller oder letzter Lauf.
	 * @return array[] Liste mit type (success, info, warning, error), title, message, optional action und link.
	 */
	private function notices( $current, $result, $run ) {
		$notices = array();

		if ( Conversion::is_active( $run ) ) {
			if ( 'akwu-umwandlung' === $current ) {
				$notices[] = array(
					'type'    => 'warning',
					'title'   => 'paused' === $run['status'] ? __( 'Umwandlung pausiert.', 'akuma-webp-umwandler' ) : __( 'Umwandlung läuft.', 'akuma-webp-umwandler' ),
					'message' => 'paused' === $run['status']
						? __( 'Mit „Fortsetzen“ geht es beim nächsten Paket weiter.', 'akuma-webp-umwandler' )
						: __( 'Bitte dieses Fenster geöffnet lassen. Bei einer Unterbrechung geht es beim nächsten Paket weiter.', 'akuma-webp-umwandler' ),
				);
			} else {
				$notices[] = array(
					'type'    => 'warning',
					'title'   => __( 'Eine Umwandlung ist offen.', 'akuma-webp-umwandler' ),
					'message' => __( 'Sie läuft nur weiter, solange die Seite der Umwandlung geöffnet ist.', 'akuma-webp-umwandler' ),
					'link'    => array(
						'url'   => self::page_url( 'akwu-umwandlung' ),
						'label' => __( 'Zur Umwandlung', 'akuma-webp-umwandler' ),
					),
				);
			}

			return $notices;
		}

		if ( self::MENU_SLUG === $current && null !== $result && null !== $run && $run['finished'] > $result->finished() ) {
			$notices[] = array(
				'type'    => 'info',
				'title'   => __( 'Zahlen vom Scan vor der Umwandlung.', 'akuma-webp-umwandler' ),
				'message' => __( 'Ein neuer Scan zeigt den aktuellen Stand.', 'akuma-webp-umwandler' ),
				'action'  => 'rescan',
			);
		} elseif ( self::MENU_SLUG === $current && null !== $result ) {
			$notices[] = array(
				'type'    => 'success',
				'title'   => __( 'Scan abgeschlossen.', 'akuma-webp-umwandler' ),
				'message' => __( 'Es wurde nur gelesen, nichts verändert. Bild-IDs bleiben bei der Umwandlung erhalten.', 'akuma-webp-umwandler' ),
				'action'  => 'rescan',
			);
		}

		return $notices;
	}

	/**
	 * Knöpfe in der Kopfzeile. Auf der Umwandlung: Pausieren oder Fortsetzen und Abbrechen.
	 *
	 * @param string     $current Slug der Seite.
	 * @param array|null $run     Aktueller oder letzter Lauf.
	 * @return array[] Liste mit action, label und style (default, danger).
	 */
	private static function header_actions( $current, $run ) {
		if ( 'akwu-umwandlung' !== $current || ! Conversion::is_active( $run ) || 'cancelling' === $run['status'] ) {
			return array();
		}

		return array(
			'paused' === $run['status']
				? array(
					'action' => 'resume',
					'label'  => __( 'Fortsetzen', 'akuma-webp-umwandler' ),
					'icon'   => 'play',
					'style'  => 'default',
				)
				: array(
					'action' => 'pause',
					'label'  => __( 'Pausieren', 'akuma-webp-umwandler' ),
					'icon'   => 'pause',
					'style'  => 'default',
				),
			array(
				'action' => 'cancel',
				'label'  => __( 'Abbrechen und zurücksetzen', 'akuma-webp-umwandler' ),
				'icon'   => '',
				'style'  => 'danger',
			),
		);
	}

	/**
	 * Filter, Sortierung und Seite der Bildliste aus der URL. Nur lesend, deshalb ohne Nonce.
	 *
	 * @return array{filter: string, sort: string, paged: int}
	 */
	public static function query_args() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nur Anzeige-Filter, keine Aktion.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'bytes';
		$paged  = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		return array(
			'filter' => isset( Scan_Result::filters()[ $filter ] ) ? $filter : 'all',
			'sort'   => in_array( $sort, array( 'bytes', 'name', 'id' ), true ) ? $sort : 'bytes',
			'paged'  => max( 1, $paged ),
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
