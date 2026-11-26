<?php
/**
 * Bilder der Kopfzeile sofort laden: nimmt sie vom verzögerten Laden aus (ADR-040).
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Findet die Bilder in den Kopfzeilen-Vorlagen von Elementor und schaltet für genau diese Bild-IDs einen Fix ein.
 *
 * Anlass: Ein Lazy-Loader (auf der Testkopie WP Rocket) lud auch das Logo der Kopfzeile verzögert. Der Platzhalter
 * war kleiner als das Bild, beim Nachladen rutschte die ganze Seite nach unten. Der Fix setzt an den Bild-Tags
 * dieser IDs data-no-lazy="1" und die Klasse skip-lazy. Ein vorhandenes loading="lazy" wird zu "eager", sonst bleibt
 * das loading-Attribut unberührt: WordPress und Elementor zählen ein Bild mit gesetztem loading-Attribut nicht zu den
 * ersten Bildern, die nicht verzögert werden, ein gesetztes "eager" würde diese Schwelle um ein Bild verschieben
 * (ADR-041). Nichts am gespeicherten Inhalt ändert sich.
 */
class Header_Images {

	/**
	 * Option mit dem eingeschalteten Stand: ids, time, templates.
	 */
	const OPTION = 'akwu_header_images';

	/**
	 * Widgets, deren Einstellung „image“ ein Bild der Mediathek ist.
	 */
	const WIDGETS = array( 'image', 'image-box' );

	/**
	 * Widgets, die das Website-Logo aus dem Customizer zeigen.
	 */
	const LOGO_WIDGETS = array( 'theme-site-logo' );

	/**
	 * Bild-IDs in einem Elementor-Baum. Ein Logo-Widget steht als 'logo' in der Liste.
	 *
	 * @param array $elements Elemente aus `_elementor_data`.
	 * @return array[] Je Fund: widget (ID des Widgets), type (Widget-Typ), id (Bild-ID oder 0), logo (bool).
	 */
	public static function find_images( array $elements ) {
		$found = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				$widget   = isset( $element['id'] ) ? (string) $element['id'] : '';

				if ( in_array( $type, self::WIDGETS, true ) && isset( $settings['image']['id'] ) && (int) $settings['image']['id'] > 0 ) {
					$found[] = array(
						'widget' => $widget,
						'type'   => $type,
						'id'     => (int) $settings['image']['id'],
						'logo'   => false,
					);
				} elseif ( in_array( $type, self::LOGO_WIDGETS, true ) ) {
					$found[] = array(
						'widget' => $widget,
						'type'   => $type,
						'id'     => 0,
						'logo'   => true,
					);
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = array_merge( $found, self::find_images( $element['elements'] ) );
			}
		}

		return $found;
	}

	/**
	 * Veröffentlichte Kopfzeilen-Vorlagen von Elementor (Theme Builder).
	 *
	 * @return int[] Beitrags-IDs.
	 */
	public static function templates() {
		$ids = get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'publish',
				'numberposts'      => 20,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Einmalige Suche auf der Admin-Seite.
				'meta_key'         => '_elementor_template_type',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Siehe oben.
				'meta_value'       => 'header',
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Bilder aller Kopfzeilen-Vorlagen mit Dateiname und Zustand.
	 *
	 * @return array[] Je Bild: id, file, template, title, widget, type, active (im Fix enthalten).
	 */
	public static function scan() {
		$state  = self::state();
		$active = $state ? $state['ids'] : array();
		$list   = array();
		$seen   = array();

		foreach ( self::templates() as $template ) {
			$data = json_decode( (string) get_post_meta( $template, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			foreach ( self::find_images( $data ) as $entry ) {
				$id = $entry['logo'] ? (int) get_theme_mod( 'custom_logo' ) : $entry['id'];
				if ( $id <= 0 || isset( $seen[ $id ] ) || ! wp_attachment_is_image( $id ) ) {
					continue;
				}

				$seen[ $id ] = true;
				$file        = (string) get_post_meta( $id, '_wp_attached_file', true );
				$list[]      = array(
					'id'       => $id,
					'file'     => '' !== $file ? wp_basename( $file ) : '',
					'template' => $template,
					'title'    => get_the_title( $template ),
					'widget'   => $entry['widget'],
					'type'     => $entry['type'],
					'active'   => in_array( $id, $active, true ),
				);
			}
		}

		return $list;
	}

	/**
	 * Eingeschalteter Stand.
	 *
	 * @return array|null ids (int[]), time (UTC), oder null.
	 */
	public static function state() {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || empty( $state['ids'] ) || ! is_array( $state['ids'] ) ) {
			return null;
		}

		$state['ids'] = Fix_File::ids( $state['ids'] );

		return empty( $state['ids'] ) ? null : $state;
	}

	/**
	 * Ob der Fix wirkt: Stand gespeichert und die Fix-Datei enthält genau diese Bild-IDs.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$state = self::state();
		if ( null === $state || 'ours' !== Fix_File::status() ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Datei.
		return (string) file_get_contents( Fix_File::path() ) === Fix_File::render( array( 'header_images' => $state['ids'] ) );
	}

	/**
	 * Eigene Fix-Datei vorhanden, aber nicht in der heutigen Form (ältere Plugin-Version oder von Hand geändert).
	 * Sie wirkt weiter, bis sie neu geschrieben oder zurückgenommen wird.
	 *
	 * @return bool
	 */
	public static function is_stale() {
		return null !== self::state() && 'ours' === Fix_File::status() && ! self::is_active();
	}

	/**
	 * Bild-IDs, die heute in den Kopfzeilen stehen, aber nicht im eingeschalteten Stand (oder umgekehrt).
	 *
	 * @return bool
	 */
	public static function is_outdated() {
		$state = self::state();
		if ( null === $state ) {
			return false;
		}

		return Fix_File::ids( wp_list_pluck( self::scan(), 'id' ) ) !== $state['ids'];
	}

	/**
	 * Schaltet den Fix für die Bilder ein, die jetzt in den Kopfzeilen stehen.
	 *
	 * @return int|\WP_Error Anzahl der Bilder.
	 */
	public static function apply() {
		$ids = Fix_File::ids( wp_list_pluck( self::scan(), 'id' ) );
		if ( empty( $ids ) ) {
			return new \WP_Error( 'akwu_header_none', __( 'In den Kopfzeilen-Vorlagen steht kein Bild aus der Mediathek.', 'akuma-webp-umwandler' ) );
		}

		$written = Fix_File::write( array( 'header_images' => $ids ) );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		update_option(
			self::OPTION,
			array(
				'ids'  => $ids,
				'time' => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);

		Cache_Purger::elementor();
		Cache_Purger::purge_all();

		return count( $ids );
	}

	/**
	 * Nimmt den Fix zurück: Fix-Datei entfernen, Stand löschen.
	 *
	 * @return true|\WP_Error
	 */
	public static function revert() {
		$removed = Fix_File::write( array() );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		delete_option( self::OPTION );

		Cache_Purger::elementor();
		Cache_Purger::purge_all();

		return true;
	}
}
