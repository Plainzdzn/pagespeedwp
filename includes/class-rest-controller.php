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

		$this->convert_routes();
		$this->job_routes();

		register_rest_route(
			self::NAMESPACE_V1,
			'/pagespeed',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pagespeed' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'which' => array(
						'type'     => 'string',
						'enum'     => array( 'before', 'after' ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Misst die Startseite mit PageSpeed Insights (optional, nur mit API-Schlüssel).
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function pagespeed( \WP_REST_Request $request ) {
		$result = PageSpeed::measure( $request['which'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Routen für Rückgängig und Originale löschen.
	 *
	 * @return void
	 */
	private function job_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/job',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'job_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/job/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'job_start' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'type'    => array(
						'type'     => 'string',
						'enum'     => array( 'rollback', 'purge' ),
						'required' => true,
					),
					'ids'     => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
					'confirm' => array(
						'type'    => 'integer',
						'default' => -1,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/job/step',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'job_step' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Stand des Jobs.
	 *
	 * @return \WP_REST_Response
	 */
	public function job_status() {
		$job = Job::current();

		return rest_ensure_response( null === $job ? array( 'status' => 'none' ) : Job::progress( $job ) );
	}

	/**
	 * Startet Rückgängig (alle oder einzelne Bilder) oder das Löschen der Originale.
	 *
	 * Löschen verlangt als Bestätigung die Anzahl der betroffenen Bilder (Briefing §4.7).
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function job_start( \WP_REST_Request $request ) {
		$rows = Job::targets( $request['type'], array_map( 'absint', (array) $request['ids'] ), 'purge' === $request['type'] ? (int) $request['confirm'] : null );
		if ( is_wp_error( $rows ) ) {
			$rows->add_data( array( 'status' => 400 ) );
			return $rows;
		}

		$job = Job::start( $request['type'], $rows );
		if ( is_wp_error( $job ) ) {
			$job->add_data( array( 'status' => 409 ) );
			return $job;
		}

		return rest_ensure_response( Job::progress( $job ) );
	}

	/**
	 * Nächster Schritt des Jobs.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function job_step() {
		$job = Job::step( self::budget() );
		if ( is_wp_error( $job ) ) {
			$job->add_data( array( 'status' => 409 ) );
			return $job;
		}

		return rest_ensure_response( Job::progress( $job ) );
	}

	/**
	 * Routen der Umwandlung.
	 *
	 * @return void
	 */
	private function convert_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/convert',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'convert_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/convert/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'convert_start' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'mode'   => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'test' ),
						'default' => 'all',
					),
					'backup' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		foreach ( array( 'step', 'pause', 'resume', 'cancel' ) as $action ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/convert/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'convert_' . $action ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * Stand der Umwandlung.
	 *
	 * @return \WP_REST_Response
	 */
	public function convert_status() {
		$run = Conversion::current();

		return rest_ensure_response( null === $run ? array( 'status' => 'none' ) : Run_Presenter::payload( $run ) );
	}

	/**
	 * Startet eine Umwandlung. Verlangt die Bestätigung des Backups und eine bestandene Systemprüfung.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function convert_start( \WP_REST_Request $request ) {
		if ( ! $request['backup'] ) {
			return new \WP_Error( 'akwu_backup', __( 'Bitte zuerst bestätigen, dass ein Backup von Datenbank und Uploads erstellt ist.', 'akuma-webp-umwandler' ), array( 'status' => 400 ) );
		}

		$check = new System_Check();
		if ( ! $check->can_start() ) {
			return new \WP_Error( 'akwu_system', __( 'Die Systemprüfung meldet ein Problem. Bitte zuerst dort nachsehen.', 'akuma-webp-umwandler' ), array( 'status' => 400 ) );
		}

		$run = Conversion::start( $request['mode'] );

		if ( is_wp_error( $run ) ) {
			$run->add_data( array( 'status' => 409 ) );
			return $run;
		}

		return rest_ensure_response( Run_Presenter::payload( $run ) );
	}

	/**
	 * Nächster Schritt der Umwandlung.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function convert_step() {
		return self::run_response( Conversion::step( self::budget() ) );
	}

	/**
	 * Pausieren.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function convert_pause() {
		return self::run_response( Conversion::pause() );
	}

	/**
	 * Fortsetzen.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function convert_resume() {
		return self::run_response( Conversion::resume() );
	}

	/**
	 * Abbrechen und zurücksetzen.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function convert_cancel() {
		return self::run_response( Conversion::cancel() );
	}

	/**
	 * Antwort für einen Lauf oder Fehler.
	 *
	 * @param array|\WP_Error $run Lauf.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function run_response( $run ) {
		if ( is_wp_error( $run ) ) {
			$run->add_data( array( 'status' => 409 ) );
			return $run;
		}

		return rest_ensure_response( Run_Presenter::payload( $run ) );
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

		if ( Conversion::is_active( Conversion::current() ) ) {
			return new \WP_Error( 'akwu_run_active', __( 'Während einer Umwandlung ist kein neuer Scan möglich.', 'akuma-webp-umwandler' ), array( 'status' => 409 ) );
		}

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
