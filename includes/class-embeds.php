<?php
/**
 * Eingebettete 3D-Touren, die weit unten auf der Seite stehen, erst bei Bedarf laden.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Findet iframes bekannter Tour-Anbieter in Elementor-HTML-Widgets und setzt auf Wunsch `loading="lazy"`.
 *
 * Wie bei den Bildern: einmalige Änderung am gespeicherten Inhalt, keine Logik bei der Auslieferung.
 * Das echte iframe bleibt stehen. Der Browser lädt es von selbst, sobald man in seine Nähe scrollt,
 * Tastatur, Touch und Einwilligungs-Werkzeuge verhalten sich wie vorher.
 * Jede Änderung wird je Einbettung gespeichert und lässt sich zeichengenau zurücknehmen.
 */
final class Embeds {

	/**
	 * Option mit den gesetzten Änderungen, Schlüssel „Beitrag:Widget:Nummer“.
	 */
	const OPTION = 'akwu_embeds';

	/**
	 * So viele Abschnitte müssen vor der Einbettung stehen, damit sie als „weit unten“ gilt.
	 */
	const MIN_SECTIONS_BEFORE = 2;

	/**
	 * Hosts, deren iframes gemeint sind. Bewusst eng: kein allgemeiner Eingriff in Einbettungen.
	 *
	 * @return string[]
	 */
	public static function hosts() {
		$hosts = apply_filters( 'akwu_embed_hosts', array( 'my.matterport.com' ) );

		return array_values( array_filter( array_map( 'strval', (array) $hosts ) ) );
	}

	/**
	 * Öffnende iframe-Tags eines HTML-Schnipsels, deren Quelle auf einem der Hosts liegt.
	 *
	 * @param string   $html  HTML aus einem Widget.
	 * @param string[] $hosts Erlaubte Hosts.
	 * @return array[] Je Fund: tag (öffnendes Tag, unverändert), host, loading (Wert des Attributs oder null).
	 */
	public static function find_iframes( $html, array $hosts ) {
		$found = array();

		if ( ! is_string( $html ) || false === stripos( $html, '<iframe' ) || ! preg_match_all( '/<iframe\b[^>]*>/i', $html, $matches ) ) {
			return $found;
		}

		foreach ( $matches[0] as $tag ) {
			// Quelle mit oder ohne Anführungszeichen.
			if ( ! preg_match( '/\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $src ) ) {
				continue;
			}
			$url = html_entity_decode( (string) end( $src ), ENT_QUOTES, 'UTF-8' );

			// Nur absolute http(s)-Adressen, der Host muss genau passen (keine Subdomains, kein Teiltreffer).
			if ( ! preg_match( '#^(?:https?:)?//([^/?\#:@]+)(?:[:/?\#]|$)#i', $url, $match ) ) {
				continue;
			}
			$host = strtolower( $match[1] );

			if ( ! in_array( $host, $hosts, true ) ) {
				continue;
			}

			$loading = null;
			if ( preg_match( '/\sloading\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $attr ) ) {
				$loading = strtolower( (string) end( $attr ) );
			}

			$found[] = array(
				'tag'     => $tag,
				'host'    => $host,
				'loading' => $loading,
			);
		}

		return $found;
	}

	/**
	 * Dasselbe Tag mit `loading="lazy"`. Ein vorhandenes loading-Attribut bleibt, wie es ist.
	 *
	 * @param string $tag Öffnendes iframe-Tag.
	 * @return string
	 */
	public static function lazy_tag( $tag ) {
		if ( preg_match( '/\sloading\s*=/i', $tag ) ) {
			return $tag;
		}

		return (string) preg_replace( '/^<iframe\b/i', '$0 loading="lazy"', $tag, 1 );
	}

	/**
	 * Ersetzt ein Tag im gespeicherten Elementor-JSON, ohne den Rest neu zu kodieren.
	 *
	 * Nur wenn das Tag genau einmal vorkommt, sonst wäre nicht eindeutig, welche Einbettung gemeint ist.
	 *
	 * @param string $raw  JSON-Text aus _elementor_data.
	 * @param string $from Tag, wie es im Widget steht.
	 * @param string $to   Neues Tag.
	 * @return string|null Neuer JSON-Text oder null, wenn nicht genau ein Treffer.
	 */
	public static function replace_in_json( $raw, $from, $to ) {
		// Gleiche Kodierung wie beim Speichern durch Elementor (Schrägstriche und Umlaute maskiert).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reine Zeichenkette, soll auch ohne WordPress prüfbar bleiben.
		$needle = substr( (string) json_encode( (string) $from ), 1, -1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Wie oben.
		$with = substr( (string) json_encode( (string) $to ), 1, -1 );

		if ( '' === $needle || 1 !== substr_count( $raw, $needle ) ) {
			return null;
		}

		$new = str_replace( $needle, $with, $raw );

		return is_array( json_decode( $new, true ) ) ? $new : null;
	}

	/**
	 * HTML-Widgets mit passenden iframes im Elementor-Baum, mit Abschnitt und Sichtbarkeit.
	 *
	 * @param array    $elements Dekodierte Elementor-Daten.
	 * @param string[] $hosts    Erlaubte Hosts.
	 * @return array[] Je Fund: widget, number, tag, host, loading, section (ab 1), sections, hidden (Geräte).
	 */
	public static function in_elements( array $elements, array $hosts ) {
		$found    = array();
		$sections = count( $elements );

		foreach ( array_values( $elements ) as $index => $element ) {
			if ( is_array( $element ) ) {
				self::walk( $element, $hosts, $index + 1, $sections, array(), $found );
			}
		}

		return $found;
	}

	/**
	 * Geht einen Zweig des Elementor-Baums durch.
	 *
	 * @param array    $element  Element.
	 * @param string[] $hosts    Erlaubte Hosts.
	 * @param int      $section  Nummer des Abschnitts auf oberster Ebene.
	 * @param int      $sections Anzahl Abschnitte.
	 * @param string[] $hidden   Geräte, auf denen ein Elternteil ausgeblendet ist.
	 * @param array[]  $found    Funde, wird ergänzt.
	 * @return void
	 */
	private static function walk( array $element, array $hosts, $section, $sections, array $hidden, array &$found ) {
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			if ( ! empty( $settings[ 'hide_' . $device ] ) ) {
				$hidden[ $device ] = $device;
			}
		}

		if ( isset( $element['widgetType'], $settings['html'], $element['id'] ) && 'html' === $element['widgetType'] && is_string( $settings['html'] ) ) {
			foreach ( self::find_iframes( $settings['html'], $hosts ) as $number => $iframe ) {
				$found[] = $iframe + array(
					'widget'   => (string) $element['id'],
					'number'   => (int) $number,
					'section'  => (int) $section,
					'sections' => (int) $sections,
					'hidden'   => array_values( $hidden ),
				);
			}
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			foreach ( $element['elements'] as $child ) {
				if ( is_array( $child ) ) {
					self::walk( $child, $hosts, $section, $sections, $hidden, $found );
				}
			}
		}
	}

	/**
	 * Schlüssel einer Einbettung in der Option.
	 *
	 * @param int    $post_id Beitrag.
	 * @param string $widget  Widget-ID.
	 * @param int    $number  Nummer des iframes im Widget.
	 * @return string
	 */
	public static function key( $post_id, $widget, $number ) {
		return (int) $post_id . ':' . $widget . ':' . (int) $number;
	}

	/**
	 * Gespeicherte Änderungen.
	 *
	 * @return array<string, array>
	 */
	public static function changes() {
		$changes = get_option( self::OPTION, array() );

		return is_array( $changes ) ? $changes : array();
	}

	/**
	 * Alle Einbettungen der Website mit Status und Empfehlung.
	 *
	 * @return array[] Je Fund zusätzlich: post, title, url, type, key, state (default, ours, fixed), eligible, reason.
	 */
	public static function scan() {
		global $wpdb;

		$hosts   = self::hosts();
		$changes = self::changes();
		$list    = array();

		if ( empty( $hosts ) ) {
			return $list;
		}

		$likes = array();
		foreach ( $hosts as $host ) {
			$likes[] = $wpdb->prepare( 'm.meta_value LIKE %s', '%' . $wpdb->esc_like( $host ) . '%' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Einmalige Suche auf der Admin-Seite, Bedingungen oben einzeln vorbereitet.
		$ids = $wpdb->get_col( "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_status = 'publish' AND p.post_type <> 'revision' AND ( " . implode( ' OR ', $likes ) . ' ) ORDER BY m.post_id ASC LIMIT 200' );

		foreach ( array_map( 'intval', (array) $ids ) as $post_id ) {
			$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$type = (string) get_post_type( $post_id );
			foreach ( self::in_elements( $data, $hosts ) as $entry ) {
				$key = self::key( $post_id, $entry['widget'], $entry['number'] );

				// default: kein Attribut, der Browser lädt sofort. ours: hier umgestellt. fixed: im Widget ausdrücklich gesetzt.
				$state = 'default';
				if ( null !== $entry['loading'] ) {
					$state = ( isset( $changes[ $key ] ) && $changes[ $key ]['new'] === $entry['tag'] ) ? 'ours' : 'fixed';
				}

				$reason = '';
				if ( 'elementor_library' === $type ) {
					$reason = __( 'Vorlage: Die Position auf den Seiten ist hier nicht bekannt.', 'akuma-webp-umwandler' );
				} elseif ( $entry['section'] <= self::MIN_SECTIONS_BEFORE ) {
					$reason = __( 'Steht weit oben auf der Seite und soll sofort laden.', 'akuma-webp-umwandler' );
				} elseif ( 'fixed' === $state ) {
					$reason = __( 'Das Ladeverhalten ist im Widget ausdrücklich festgelegt und bleibt so.', 'akuma-webp-umwandler' );
				}

				$list[] = $entry + array(
					'post'     => $post_id,
					'title'    => get_the_title( $post_id ),
					'url'      => (string) get_permalink( $post_id ),
					'type'     => $type,
					'key'      => $key,
					'state'    => $state,
					'eligible' => '' === $reason,
					'reason'   => $reason,
				);
			}
		}

		return $list;
	}

	/**
	 * Eine Einbettung aus scan() nach Schlüssel.
	 *
	 * @param string $key Schlüssel.
	 * @return array|null
	 */
	private static function entry( $key ) {
		foreach ( self::scan() as $entry ) {
			if ( $entry['key'] === $key ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Setzt `loading="lazy"` an einer Einbettung.
	 *
	 * @param string $key Schlüssel aus scan().
	 * @return true|\WP_Error
	 */
	public static function apply( $key ) {
		$entry = self::entry( $key );

		if ( null === $entry ) {
			return new \WP_Error( 'akwu_embed_missing', __( 'Die Einbettung wurde nicht mehr gefunden. Wurde die Seite inzwischen geändert?', 'akuma-webp-umwandler' ) );
		}
		if ( ! $entry['eligible'] || 'default' !== $entry['state'] ) {
			return new \WP_Error( 'akwu_embed_skip', '' !== $entry['reason'] ? $entry['reason'] : __( 'Diese Einbettung lädt bereits verzögert.', 'akuma-webp-umwandler' ) );
		}

		$raw = (string) get_post_meta( $entry['post'], '_elementor_data', true );
		$new = self::lazy_tag( $entry['tag'] );

		// Ohne tatsächliche Änderung wird nichts geschrieben und nichts als Erfolg gemeldet.
		if ( $new === $entry['tag'] ) {
			return new \WP_Error( 'akwu_embed_same', __( 'An diesem iframe gibt es nichts zu ändern.', 'akuma-webp-umwandler' ) );
		}

		$out = self::replace_in_json( $raw, $entry['tag'], $new );

		if ( null === $out ) {
			return new \WP_Error( 'akwu_embed_ambiguous', __( 'Das iframe steht mehrfach gleich auf der Seite oder ließ sich nicht eindeutig finden. Es wurde nichts geändert.', 'akuma-webp-umwandler' ) );
		}

		// Zustand sichern, bevor geschrieben wird.
		$changes         = self::changes();
		$changes[ $key ] = array(
			'post'   => (int) $entry['post'],
			'widget' => (string) $entry['widget'],
			'host'   => (string) $entry['host'],
			'old'    => (string) $entry['tag'],
			'new'    => $new,
			'hash'   => hash( 'sha256', $raw ),
			'time'   => time(),
		);
		update_option( self::OPTION, $changes, false );

		if ( ! self::write( $entry['post'], $out ) ) {
			self::write( $entry['post'], $raw );
			unset( $changes[ $key ] );
			update_option( self::OPTION, $changes, false );

			return new \WP_Error( 'akwu_embed_write', __( 'Die Seite ließ sich nicht speichern. Es wurde nichts geändert.', 'akuma-webp-umwandler' ) );
		}

		self::flush( $entry['post'] );

		return true;
	}

	/**
	 * Nimmt die Änderung an einer Einbettung zurück.
	 *
	 * @param string $key Schlüssel.
	 * @return true|\WP_Error true, wenn der gespeicherte Inhalt wieder zeichengenau dem alten entspricht oder inhaltlich zurückgestellt ist.
	 */
	public static function revert( $key ) {
		$changes = self::changes();

		if ( ! isset( $changes[ $key ] ) ) {
			return new \WP_Error( 'akwu_embed_unknown', __( 'Zu dieser Einbettung ist keine Änderung gespeichert.', 'akuma-webp-umwandler' ) );
		}

		$change = $changes[ $key ];
		$raw    = (string) get_post_meta( $change['post'], '_elementor_data', true );
		$out    = self::replace_in_json( $raw, $change['new'], $change['old'] );

		if ( null === $out ) {
			return new \WP_Error( 'akwu_embed_changed', __( 'Das iframe wurde seit der Änderung bearbeitet. Bitte das Attribut loading im Widget von Hand entfernen.', 'akuma-webp-umwandler' ) );
		}
		if ( ! self::write( $change['post'], $out ) ) {
			return new \WP_Error( 'akwu_embed_write', __( 'Die Seite ließ sich nicht speichern. Es wurde nichts geändert.', 'akuma-webp-umwandler' ) );
		}

		unset( $changes[ $key ] );
		update_option( self::OPTION, $changes, false );
		self::flush( $change['post'] );

		return true;
	}

	/**
	 * Entspricht der gespeicherte Inhalt nach dem Zurücknehmen zeichengenau dem Stand vor der Änderung?
	 *
	 * @param int    $post_id Beitrag.
	 * @param string $hash    SHA-256 des alten Inhalts.
	 * @return bool
	 */
	public static function matches( $post_id, $hash ) {
		return hash_equals( (string) $hash, hash( 'sha256', (string) get_post_meta( $post_id, '_elementor_data', true ) ) );
	}

	/**
	 * Schreibt den JSON-Text und prüft, dass er genau so ankam.
	 *
	 * @param int    $post_id Beitrag.
	 * @param string $raw     JSON-Text.
	 * @return bool
	 */
	private static function write( $post_id, $raw ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( $raw ) );
		wp_cache_delete( $post_id, 'post_meta' );

		return (string) get_post_meta( $post_id, '_elementor_data', true ) === $raw;
	}

	/**
	 * Erneuert die Zwischenspeicher, damit die Änderung ausgeliefert wird.
	 *
	 * @param int $post_id Beitrag.
	 * @return void
	 */
	private static function flush( $post_id ) {
		// Elementor merkt sich fertiges Widget-HTML je Beitrag.
		delete_post_meta( $post_id, '_elementor_element_cache' );
		clean_post_cache( $post_id );
		Cache_Purger::elementor();
		Cache_Purger::purge_all();
	}
}
