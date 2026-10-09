<?php
/**
 * REST-Endpunkte für die Admin-Oberfläche.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace `akwu/v1`. Nur für Admins (`manage_options`). Die Cookie-Anmeldung der REST-API
 * verlangt den Nonce `wp_rest` (Header X-WP-Nonce), ohne ihn gilt der Aufruf als nicht angemeldet.
 */
final class Rest_Controller {

	/**
	 * Namespace der Routen.
	 */
	const NAMESPACE_V1 = 'akwu/v1';

	/**
	 * Hängt sich in WordPress ein.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registriert die Routen.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/scan',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'scan_status' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'scan_step' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'restart' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Nur Admins.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( Admin::CAPABILITY );
	}

	/**
	 * Stand des Scans.
	 *
	 * @return \WP_REST_Response
	 */
	public function scan_status() {
		$state = Scanner::state();

		return rest_ensure_response( null === $state ? array( 'status' => 'none' ) : self::scan_payload( $state ) );
	}

	/**
	 * Startet den Scan oder führt den nächsten Schritt aus.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_step( \WP_REST_Request $request ) {
		$state = Scanner::state();

		if ( $request['restart'] || null === $state || 'running' !== $state['status'] ) {
			if ( null !== Lock::holder() ) {
				return new \WP_Error( 'akwu_locked', __( 'Gerade läuft schon ein Scan oder eine Umwandlung. Bitte kurz warten.', 'akuma-webp-umwandler' ), array( 'status' => 409 ) );
			}
			Scanner::start();
		}

		$state = Scanner::step( self::budget() );

		if ( is_wp_error( $state ) ) {
			$state->add_data( array( 'status' => 409 ) );
			return $state;
		}

		return rest_ensure_response( self::scan_payload( $state ) );
	}

	/**
	 * Antwort mit Fortschritt.
	 *
	 * @param array $state Stand.
	 * @return array
	 */
	private static function scan_payload( array $state ) {
		$progress = Scanner::progress( $state );

		return array(
			'status'   => $state['status'],
			'phase'    => $progress['phase'],
			'label'    => $progress['label'],
			'percent'  => $progress['percent'],
			'finished' => $progress['finished'],
		);
	}

	/**
	 * Zeit pro Schritt: kurz genug für knappe Server-Limits, lang genug für Fortschritt.
	 *
	 * @return float Sekunden.
	 */
	public static function budget() {
		$limit = (int) ini_get( 'max_execution_time' );

		return ( $limit > 0 ) ? max( 2.0, min( 8.0, $limit / 3 ) ) : 8.0;
	}
}
