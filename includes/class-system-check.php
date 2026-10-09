<?php
/**
 * Systemprüfung vor der Umwandlung.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Prüft, ob der Server die Umwandlung kann, und warnt vor Konflikten.
 *
 * Liest nur, ändert nichts. Jede Prüfung liefert eine Zeile mit
 * id, label, status (ok|warn|error|info), value und detail.
 */
final class System_Check {

	const OK    = 'ok';
	const WARN  = 'warn';
	const ERROR = 'error';
	const INFO  = 'info';

	/**
	 * Arbeitsspeicher für Bildbearbeitung, ab dem nicht gewarnt wird (256 MB).
	 */
	const MIN_IMAGE_MEMORY = 268435456;

	/**
	 * Laufzeit pro Anfrage in Sekunden, ab der nicht gewarnt wird.
	 */
	const MIN_EXECUTION_TIME = 30;

	/**
	 * Zwischenspeicher der Ergebnisse für die laufende Anfrage.
	 *
	 * @var array[]|null
	 */
	private $results = null;

	/**
	 * Alle Prüfungen in Anzeigereihenfolge.
	 *
	 * @return array[]
	 */
	public function results() {
		if ( null === $this->results ) {
			$this->results = array_merge(
				array(
					$this->check_webp(),
					$this->check_uploads(),
					$this->check_disk(),
					$this->check_execution_time(),
					$this->check_memory(),
					$this->check_environment(),
					$this->check_elementor(),
					$this->check_cache(),
				),
				$this->check_conflicts()
			);
		}

		return $this->results;
	}

	/**
	 * Eine einzelne Prüfung.
	 *
	 * @param string $id Kennung der Prüfung.
	 * @return array|null
	 */
	public function result( $id ) {
		foreach ( $this->results() as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Zeilen zu anderen Bildoptimierern.
	 *
	 * @return array[]
	 */
	public function conflict_results() {
		return array_values(
			array_filter(
				$this->results(),
				static function ( $row ) {
					return 0 === strpos( $row['id'], 'conflict' );
				}
			)
		);
	}

	/**
	 * Anzahl der Prüfungen mit einem Status.
	 *
	 * @param string $status ok, warn, error oder info.
	 * @return int
	 */
	public function count( $status ) {
		$count = 0;

		foreach ( $this->results() as $row ) {
			if ( $status === $row['status'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Ob eine Umwandlung technisch möglich ist (keine Prüfung mit Status error).
	 *
	 * @return bool
	 */
	public function can_start() {
		return 0 === $this->count( self::ERROR );
	}

	/**
	 * Status für den Arbeitsspeicher, der für Bildbearbeitung zur Verfügung steht.
	 *
	 * @param int $bytes Bytes, -1 für unbegrenzt.
	 * @return string
	 */
	public static function memory_status( $bytes ) {
		return ( -1 === (int) $bytes || $bytes >= self::MIN_IMAGE_MEMORY ) ? self::OK : self::WARN;
	}

	/**
	 * Status für die maximale Laufzeit einer Anfrage.
	 *
	 * @param int $seconds Sekunden, 0 für unbegrenzt.
	 * @return string
	 */
	public static function execution_time_status( $seconds ) {
		return ( 0 === (int) $seconds || $seconds >= self::MIN_EXECUTION_TIME ) ? self::OK : self::WARN;
	}

	/**
	 * Kann der Server WebP erzeugen, und mit welchem Editor?
	 *
	 * @return array
	 */
	private function check_webp() {
		$label   = __( 'WebP-Unterstützung', 'akuma-webp-umwandler' );
		$support = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$editor  = $this->webp_editor();

		if ( ! $support || null === $editor ) {
			return self::row(
				'webp',
				$label,
				self::ERROR,
				__( 'Nicht verfügbar', 'akuma-webp-umwandler' ),
				__( 'Der Server kann kein WebP erzeugen. Bitte beim Hoster Imagick oder GD mit WebP-Unterstützung aktivieren lassen.', 'akuma-webp-umwandler' )
			);
		}

		if ( 'WP_Image_Editor_Imagick' === $editor ) {
			return self::row(
				'webp',
				$label,
				self::OK,
				$this->imagick_label(),
				__( 'PNG mit Transparenz wird verlustfrei gespeichert.', 'akuma-webp-umwandler' )
			);
		}

		return self::row(
			'webp',
			$label,
			self::OK,
			$this->gd_label(),
			__( 'Imagick fehlt. PNG mit Transparenz wird deshalb mit Qualität 90 statt verlustfrei gespeichert.', 'akuma-webp-umwandler' )
		);
	}

	/**
	 * Der Bildeditor, den WordPress für WebP wählen würde.
	 *
	 * Gleiche Reihenfolge und Tests wie im WordPress-Core (`_wp_image_editor_choose()`).
	 *
	 * @return string|null Klassenname oder null.
	 */
	private function webp_editor() {
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

		/** This filter is documented in wp-includes/media.php */
		$editors = apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core-Filter.
		$args    = array( 'mime_type' => 'image/webp' );

		foreach ( (array) $editors as $editor ) {
			if ( ! is_string( $editor ) || ! class_exists( $editor ) ) {
				continue;
			}
			if ( call_user_func( array( $editor, 'test' ), $args ) && call_user_func( array( $editor, 'supports_mime_type' ), 'image/webp' ) ) {
				return $editor;
			}
		}

		return null;
	}

	/**
	 * Anzeige „Imagick (ImageMagick 7.1.1-15)“.
	 *
	 * @return string
	 */
	private function imagick_label() {
		$version = '';

		if ( class_exists( 'Imagick' ) ) {
			$info = \Imagick::getVersion();
			if ( isset( $info['versionString'] ) && preg_match( '/ImageMagick\s+([0-9][0-9.\-]*)/', $info['versionString'], $match ) ) {
				$version = 'ImageMagick ' . $match[1];
			}
		}

		return '' === $version ? 'Imagick' : sprintf( 'Imagick (%s)', $version );
	}

	/**
	 * Anzeige „GD (2.3.3)“.
	 *
	 * @return string
	 */
	private function gd_label() {
		$info = function_exists( 'gd_info' ) ? gd_info() : array();

		return empty( $info['GD Version'] ) ? 'GD' : sprintf( 'GD (%s)', $info['GD Version'] );
	}

	/**
	 * Upload-Ordner vorhanden und beschreibbar?
	 *
	 * @return array
	 */
	private function check_uploads() {
		$label  = __( 'Upload-Ordner', 'akuma-webp-umwandler' );
		$upload = wp_upload_dir( null, false );

		if ( ! empty( $upload['error'] ) ) {
			return self::row( 'uploads', $label, self::ERROR, __( 'Nicht verfügbar', 'akuma-webp-umwandler' ), wp_strip_all_tags( $upload['error'] ) );
		}

		$path = self::relative_path( $upload['basedir'] );

		if ( ! wp_is_writable( $upload['basedir'] ) ) {
			return self::row(
				'uploads',
				$label,
				self::ERROR,
				$path,
				__( 'WordPress kann hier nicht schreiben. Ohne Schreibrechte ist keine Umwandlung möglich.', 'akuma-webp-umwandler' )
			);
		}

		return self::row( 'uploads', $label, self::OK, $path, __( 'Beschreibbar.', 'akuma-webp-umwandler' ) );
	}

	/**
	 * Freier Speicher im Upload-Ordner.
	 *
	 * Der Vergleich mit der Größe der Originale (Faktor 1,5) braucht die Scan-Daten und folgt mit dem Scan.
	 *
	 * @return array
	 */
	private function check_disk() {
		$label  = __( 'Freier Speicher', 'akuma-webp-umwandler' );
		$upload = wp_upload_dir( null, false );
		$dir    = is_dir( $upload['basedir'] ) ? $upload['basedir'] : WP_CONTENT_DIR;
		$free   = self::free_space( $dir );

		if ( null === $free ) {
			return self::row(
				'disk',
				$label,
				self::WARN,
				__( 'Nicht ermittelbar', 'akuma-webp-umwandler' ),
				__( 'Der Server gibt den freien Speicher nicht an. Bitte beim Hoster nachsehen, ob mindestens das 1,5-Fache der Bildgröße frei ist.', 'akuma-webp-umwandler' )
			);
		}

		/* translators: %s: Speicherplatz, z. B. „12,3 GB“. */
		$value  = sprintf( __( '%s frei', 'akuma-webp-umwandler' ), Format::bytes( $free ) );
		$result = Scan_Result::load();

		if ( null === $result ) {
			return self::row( 'disk', $label, self::INFO, $value, __( 'Nötig ist etwa das 1,5-Fache der umzuwandelnden Bilder. Der Abgleich folgt nach dem Scan.', 'akuma-webp-umwandler' ) );
		}

		$totals = $result->totals();
		$needed = (int) $totals['needed'];
		$webp   = isset( $totals['webp'] ) ? (int) $totals['webp'] : 0;

		if ( $free < $webp ) {
			/* translators: %s: Speicherplatz. */
			return self::row( 'disk', $label, self::ERROR, $value, sprintf( __( 'Zu wenig Speicher. Allein die WebP-Dateien brauchen etwa %s.', 'akuma-webp-umwandler' ), Format::bytes( $webp ) ) );
		}

		if ( $free < $needed ) {
			/* translators: %s: Speicherplatz. */
			return self::row( 'disk', $label, self::WARN, $value, sprintf( __( 'Knapp. Empfohlen ist das 1,5-Fache der umzuwandelnden Bilder, etwa %s.', 'akuma-webp-umwandler' ), Format::bytes( $needed ) ) );
		}

		/* translators: %s: Speicherplatz. */
		return self::row( 'disk', $label, self::OK, $value, sprintf( __( 'Reicht. Nötig sind etwa %s (1,5-Fache der umzuwandelnden Bilder).', 'akuma-webp-umwandler' ), Format::bytes( $needed ) ) );
	}

	/**
	 * Maximale Laufzeit einer Anfrage.
	 *
	 * @return array
	 */
	private function check_execution_time() {
		$seconds = (int) ini_get( 'max_execution_time' );
		$status  = self::execution_time_status( $seconds );

		if ( 0 === $seconds ) {
			$value = __( 'Unbegrenzt', 'akuma-webp-umwandler' );
		} else {
			/* translators: %s: Anzahl Sekunden. */
			$value = sprintf( _n( '%s Sekunde', '%s Sekunden', $seconds, 'akuma-webp-umwandler' ), Format::number( $seconds ) );
		}

		$detail = self::OK === $status
			? __( 'Die Umwandlung läuft in Paketen, jedes Paket ist eine eigene Anfrage.', 'akuma-webp-umwandler' )
			: __( 'Kurz. Bei großen Bildern kann ein Paket abbrechen, dann die Paketgröße verkleinern.', 'akuma-webp-umwandler' );

		return self::row( 'time', __( 'Maximale Laufzeit', 'akuma-webp-umwandler' ), $status, $value, $detail );
	}

	/**
	 * Arbeitsspeicher, den WordPress für Bildbearbeitung bereitstellt.
	 *
	 * WordPress hebt das Limit beim Bearbeiten von Bildern auf WP_MAX_MEMORY_LIMIT an
	 * (`wp_raise_memory_limit( 'image' )`), sofern der Server das erlaubt.
	 *
	 * @return array
	 */
	private function check_memory() {
		$current = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$bytes   = $current;

		if ( -1 !== $current && wp_is_ini_value_changeable( 'memory_limit' ) ) {
			/** This filter is documented in wp-includes/functions.php */
			$image_limit = wp_convert_hr_to_bytes( (string) apply_filters( 'image_memory_limit', WP_MAX_MEMORY_LIMIT ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core-Filter.
			$bytes       = ( -1 === $image_limit ) ? -1 : max( $current, $image_limit );
		}

		$status = self::memory_status( $bytes );
		$value  = ( -1 === $bytes ) ? __( 'Unbegrenzt', 'akuma-webp-umwandler' ) : Format::bytes( $bytes );
		$detail = self::OK === $status
			? __( 'Steht WordPress für die Bildbearbeitung zur Verfügung.', 'akuma-webp-umwandler' )
			: __( 'Knapp für große PNG-Dateien. Empfohlen sind mindestens 256 MB (WP_MAX_MEMORY_LIMIT).', 'akuma-webp-umwandler' );

		return self::row( 'memory', __( 'Arbeitsspeicher', 'akuma-webp-umwandler' ), $status, $value, $detail );
	}

	/**
	 * PHP- und WordPress-Version.
	 *
	 * @return array
	 */
	private function check_environment() {
		return self::row(
			'environment',
			__( 'PHP und WordPress', 'akuma-webp-umwandler' ),
			self::OK,
			sprintf( 'PHP %1$s · WordPress %2$s', PHP_VERSION, get_bloginfo( 'version' ) ),
			''
		);
	}

	/**
	 * Elementor aktiv? Dann wird nach der Umwandlung das CSS neu erzeugt.
	 *
	 * @return array
	 */
	private function check_elementor() {
		$label = __( 'Elementor', 'akuma-webp-umwandler' );

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return self::row( 'elementor', $label, self::INFO, __( 'Nicht aktiv', 'akuma-webp-umwandler' ), __( 'Das Neuerzeugen des Elementor-CSS entfällt.', 'akuma-webp-umwandler' ) );
		}

		return self::row(
			'elementor',
			$label,
			self::OK,
			/* translators: %s: Elementor-Version. */
			sprintf( __( 'Version %s', 'akuma-webp-umwandler' ), ELEMENTOR_VERSION ),
			__( 'Das Elementor-CSS wird nach der Umwandlung neu erzeugt.', 'akuma-webp-umwandler' )
		);
	}

	/**
	 * Welche Caches nach der Umwandlung geleert werden. Auf Raidboxes ohne FastPixel ein Hinweis,
	 * den Server-Cache im Dashboard zu leeren.
	 *
	 * @return array
	 */
	private function check_cache() {
		$label  = __( 'Cache', 'akuma-webp-umwandler' );
		$caches = Cache_Purger::detect();
		$value  = $caches ? implode( ', ', $caches ) : __( 'Kein Cache-Plugin erkannt', 'akuma-webp-umwandler' );

		if ( Cache_Purger::needs_raidboxes_hint() ) {
			return self::row( 'cache', $label, self::INFO, $value, __( 'Raidboxes erkannt. Den Server-Cache nach der Umwandlung bitte im Raidboxes-Dashboard leeren, das Plugin kann ihn ohne FastPixel nicht selbst leeren.', 'akuma-webp-umwandler' ) );
		}

		return self::row(
			'cache',
			$label,
			self::OK,
			$value,
			$caches
				? __( 'Diese Caches und der WordPress-Objekt-Cache werden nach der Umwandlung geleert.', 'akuma-webp-umwandler' )
				: __( 'Nach der Umwandlung wird der WordPress-Objekt-Cache geleert.', 'akuma-webp-umwandler' )
		);
	}

	/**
	 * Andere Bildoptimierer. Nur Hinweise, es wird nichts umgestellt.
	 *
	 * @return array[]
	 */
	private function check_conflicts() {
		$found = Conflict_Detector::detect( (array) get_option( 'active_plugins', array() ) );

		if ( empty( $found ) ) {
			return array(
				self::row(
					'conflicts',
					__( 'Andere Bildoptimierer', 'akuma-webp-umwandler' ),
					self::OK,
					__( 'Keine aktiv', 'akuma-webp-umwandler' ),
					__( 'Kein bekanntes Plugin mit eigener Bildkomprimierung oder WebP-Auslieferung gefunden.', 'akuma-webp-umwandler' )
				),
			);
		}

		$rows = array();

		foreach ( $found as $basename => $name ) {
			$rows[] = Conflict_Detector::FASTPIXEL === $basename
				? $this->fastpixel_row( $basename, $name )
				: self::row(
					'conflict:' . $basename,
					$name,
					self::WARN,
					__( 'Aktiv', 'akuma-webp-umwandler' ),
					__( 'Hat eigene Bildkomprimierung oder WebP-Auslieferung. Bitte vor dem Start prüfen, ob sich das mit der Umwandlung überschneidet. Der WebP-Umwandler stellt dort nichts um.', 'akuma-webp-umwandler' )
				);
		}

		return $rows;
	}

	/**
	 * FastPixel: Stufe der Bildkomprimierung anzeigen.
	 *
	 * FastPixel 2.0 kennt Lossy, Glossy und Lossless, aber keine Stufe „aus“.
	 * Lossless verändert Pixel nicht weiter und ist deshalb nur ein Hinweis.
	 *
	 * @param string $basename Plugin-Basename.
	 * @param string $name     Anzeigename.
	 * @return array
	 */
	private function fastpixel_row( $basename, $name ) {
		$level   = Conflict_Detector::fastpixel_level( get_option( 'fastpixel_images_optimization', 1 ) );
		$label   = Conflict_Detector::FASTPIXEL_LEVELS[ $level ];
		$version = defined( 'FASTPIXEL_VERSION' ) ? FASTPIXEL_VERSION : '';

		$value = '' === $version
			/* translators: %s: Stufe, z. B. „Lossy“. */
			? sprintf( __( 'Bildkomprimierung: %s', 'akuma-webp-umwandler' ), $label )
			/* translators: 1: FastPixel-Version, 2: Stufe, z. B. „Lossy“. */
			: sprintf( __( 'Version %1$s · Bildkomprimierung: %2$s', 'akuma-webp-umwandler' ), $version, $label );

		if ( 3 === $level ) {
			return self::row(
				'conflict:' . $basename,
				$name,
				self::INFO,
				$value,
				__( 'FastPixel liefert Bilder zusätzlich über sein CDN aus, verlustfrei. Die Qualität leidet dadurch nicht weiter.', 'akuma-webp-umwandler' )
			);
		}

		return self::row(
			'conflict:' . $basename,
			$name,
			self::WARN,
			$value,
			__( 'FastPixel komprimiert Bilder zusätzlich verlustbehaftet und liefert sie über sein CDN aus. Eine Stufe „aus“ gibt es in FastPixel 2.0 nicht, am schonendsten ist „Lossless“. Bitte vor dem Start prüfen.', 'akuma-webp-umwandler' )
		);
	}

	/**
	 * Freier Speicher eines Verzeichnisses.
	 *
	 * @param string $dir Verzeichnis.
	 * @return float|null Bytes oder null, wenn der Server es nicht verrät.
	 */
	private static function free_space( $dir ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}

		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Je nach Hosting (open_basedir, disable_functions) kommt sonst eine Warnung.

		return is_numeric( $free ) ? (float) $free : null;
	}

	/**
	 * Pfad relativ zur WordPress-Installation, wenn er darin liegt.
	 *
	 * @param string $path Absoluter Pfad.
	 * @return string
	 */
	private static function relative_path( $path ) {
		$root = wp_normalize_path( ABSPATH );
		$path = wp_normalize_path( $path );

		return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
	}

	/**
	 * Baut eine Ergebniszeile.
	 *
	 * @param string $id     Kennung.
	 * @param string $label  Bezeichnung.
	 * @param string $status ok, warn, error oder info.
	 * @param string $value  Kurzer Wert.
	 * @param string $detail Erklärung.
	 * @return array
	 */
	private static function row( $id, $label, $status, $value, $detail ) {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'value'  => $value,
			'detail' => $detail,
		);
	}
}
