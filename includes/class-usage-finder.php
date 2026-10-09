<?php
/**
 * Suche nach Verwendungen von Bildern in der Datenbank und im Theme.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Findet, wo Bilder per URL oder ID verwendet werden. Liest nur.
 *
 * Jede Fundstelle ist ein Array mit den Schlüsseln attachment, by (url oder id), where, object,
 * key, label, context, count und warning. `warning` ist null für Stellen, die bei der
 * Umwandlung automatisch ersetzt werden, sonst der Typ der Warnung:
 *
 * - customizer_css: Zusätzliches CSS des Customizers
 * - elementor_css:  Custom CSS in Elementor (Element, Seite oder Kit)
 * - snippet:        Code-Snippets-Tabelle
 * - theme_file:     Datei im Theme
 *
 * Verweise nur über die ID (Beitragsbild, Logo, Elementor-Atomic-Elemente) brauchen
 * keinen Ersatz, weil die ID gleich bleibt. Sie zählen nur als Verwendung.
 */
final class Usage_Finder {

	/**
	 * Quellen in Scan-Reihenfolge.
	 */
	const SOURCES = array( 'posts', 'postmeta', 'options', 'termmeta', 'snippets', 'featured', 'special' );

	/**
	 * Postmeta, die nicht als Verwendung zählen: Daten des Anhangs selbst und Elementor-Caches,
	 * die nach der Umwandlung ohnehin neu erzeugt werden.
	 */
	const SKIP_META = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_elementor_css',
		'_elementor_element_cache',
		'_elementor_page_assets',
	);

	/**
	 * Postmeta mit Elementor-Struktur, die strukturiert durchsucht werden.
	 */
	const ELEMENTOR_META = array( '_elementor_data', '_elementor_page_settings' );

	/**
	 * Post-Typen ohne eigene Verwendung.
	 */
	const SKIP_POST_TYPES = array( 'revision', 'attachment' );

	/**
	 * Dateiendungen, die im Theme durchsucht werden.
	 */
	const THEME_EXTENSIONS = array( 'php', 'css', 'scss', 'less', 'js', 'json', 'html', 'htm', 'twig', 'txt' );

	/**
	 * URL-Erkennung.
	 *
	 * @var Url_Matcher
	 */
	private $matcher;

	/**
	 * Pfad im Upload-Ordner => Attachment-ID.
	 *
	 * @var array<string, int>
	 */
	private $index;

	/**
	 * Konstruktor.
	 *
	 * @param Url_Matcher        $matcher URL-Erkennung.
	 * @param array<string, int> $index   Pfad im Upload-Ordner => Attachment-ID.
	 */
	public function __construct( Url_Matcher $matcher, array $index ) {
		$this->matcher = $matcher;
		$this->index   = $index;
	}

	/**
	 * Durchsucht eine Quelle ab einem Cursor.
	 *
	 * @param string $source Eine der SOURCES.
	 * @param int    $cursor Letzte verarbeitete ID der Quelle.
	 * @param int    $limit  Höchstzahl Zeilen.
	 * @return array{hits: array[], cursor: int, done: bool, rows: int}
	 */
	public function scan( $source, $cursor, $limit ) {
		switch ( $source ) {
			case 'posts':
				return $this->scan_posts( $cursor, $limit );
			case 'postmeta':
				return $this->scan_postmeta( $cursor, $limit );
			case 'options':
				return $this->scan_options( $cursor, $limit );
			case 'termmeta':
				return $this->scan_termmeta( $cursor, $limit );
			case 'snippets':
				return $this->scan_snippets( $cursor, $limit );
			case 'featured':
				return $this->scan_featured( $cursor, $limit );
			default:
				return array(
					'hits'   => $this->scan_special(),
					'cursor' => 0,
					'done'   => true,
					'rows'   => 1,
				);
		}
	}

	/**
	 * Inhalt und Auszug von Beiträgen, Seiten und eigenen Post-Typen.
	 *
	 * @param int $cursor Letzte Post-ID.
	 * @param int $limit  Höchstzahl.
	 * @return array
	 */
	private function scan_posts( $cursor, $limit ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $this->matcher->like_needle() ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scan in Paketen mit LIKE-Vorfilter.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_status, post_title, post_content, post_excerpt FROM {$wpdb->posts}
				WHERE ID > %d AND post_type NOT IN ('revision', 'attachment') AND post_status NOT IN ('trash', 'auto-draft')
				AND ( post_content LIKE %s OR post_excerpt LIKE %s ) ORDER BY ID ASC LIMIT %d",
				$cursor,
				$like,
				$like,
				$limit
			)
		);

		$hits = array();
		foreach ( $rows as $row ) {
			$cursor  = (int) $row->ID;
			$warning = 'custom_css' === $row->post_type ? 'customizer_css' : null;
			$label   = 'custom_css' === $row->post_type
				/* translators: %s: Theme-Name. */
				? sprintf( __( 'Zusätzliches CSS (%s)', 'akuma-webp-umwandler' ), $row->post_title )
				: self::post_label( $row->post_title );

			foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
				foreach ( $this->matcher->find( $row->$field ) as $path => $count ) {
					$this->add_path_hit( $hits, $path, $count, 'post', (int) $row->ID, $field, $label, $row->post_type, $warning );
				}
			}
		}

		return self::result( $hits, $cursor, count( $rows ) < $limit, count( $rows ) );
	}

	/**
	 * Postmeta inklusive Elementor-Daten.
	 *
	 * @param int $cursor Letzte meta_id.
	 * @param int $limit  Höchstzahl.
	 * @return array
	 */
	private function scan_postmeta( $cursor, $limit ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $this->matcher->like_needle() ) . '%';
		$skip = "'" . implode( "','", array_map( 'esc_sql', self::SKIP_META ) ) . "'";

		// Elementor-4-Atomic-Bilder stehen oft nur mit ID drin, ohne URL. Deshalb der zweite Vorfilter.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $skip besteht aus Konstanten.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scan in Paketen mit LIKE-Vorfilter.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_title
				FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_id > %d AND pm.meta_key NOT IN ($skip)
				AND ( pm.meta_value LIKE %s OR ( pm.meta_key = '_elementor_data' AND pm.meta_value LIKE %s ) )
				AND ( p.post_type IS NULL OR p.post_type <> 'revision' ) ORDER BY pm.meta_id ASC LIMIT %d",
				$cursor,
				$like,
				'%' . $wpdb->esc_like( 'image-attachment-id' ) . '%',
				$limit
			)
		);
		// phpcs:enable

		$hits = array();
		foreach ( $rows as $row ) {
			$cursor = (int) $row->meta_id;
			$label  = self::post_label( (string) $row->post_title );

			if ( in_array( $row->meta_key, self::ELEMENTOR_META, true ) ) {
				$this->scan_elementor_value( $hits, $row );
				continue;
			}

			foreach ( $this->matcher->find( $row->meta_value ) as $path => $count ) {
				$this->add_path_hit( $hits, $path, $count, 'meta', (int) $row->post_id, $row->meta_key, $label, (string) $row->post_type, null );
			}
		}

		return self::result( $hits, $cursor, count( $rows ) < $limit, count( $rows ) );
	}

	/**
	 * `_elementor_data` (JSON) und `_elementor_page_settings` (serialisiert) strukturiert durchsuchen,
	 * damit Hintergrundbilder und Custom CSS erkannt werden.
	 *
	 * @param array  $hits Fundstellen, wird ergänzt.
	 * @param object $row  Zeile mit meta_value, meta_key, post_id, post_type, post_title.
	 * @return void
	 */
	private function scan_elementor_value( array &$hits, $row ) {
		$data = '_elementor_data' === $row->meta_key
			? json_decode( (string) $row->meta_value, true )
			: maybe_unserialize( $row->meta_value );

		$label = self::post_label( (string) $row->post_title );
		if ( '_elementor_page_settings' === $row->meta_key && 'elementor_library' === $row->post_type ) {
			$label = __( 'Elementor-Kit oder -Vorlage', 'akuma-webp-umwandler' );
		}

		if ( ! is_array( $data ) ) {
			foreach ( $this->matcher->find( (string) $row->meta_value ) as $path => $count ) {
				$this->add_path_hit( $hits, $path, $count, 'meta', (int) $row->post_id, $row->meta_key, $label, (string) $row->post_type, null, 'elementor' );
			}
			return;
		}

		$found = array();
		$ids   = array();
		self::walk( $data, false, false, $this->matcher, $found, $ids );

		foreach ( $found as $entry ) {
			$warning = $entry['custom_css'] ? 'elementor_css' : null;
			$context = $entry['background'] ? 'background' : 'elementor';
			$this->add_path_hit( $hits, $entry['path'], $entry['count'], 'meta', (int) $row->post_id, $row->meta_key, $label, (string) $row->post_type, $warning, $context );
		}

		foreach ( $ids as $attachment_id => $count ) {
			$hits[] = self::hit( $attachment_id, 'meta', (int) $row->post_id, $row->meta_key, $label, 'elementor', $count, null, (string) $row->post_type, 'id' );
		}
	}

	/**
	 * Geht verschachtelte Elementor-Daten durch.
	 *
	 * @param mixed       $value         Wert.
	 * @param bool        $in_custom_css Liegt unter einem Schlüssel custom_css.
	 * @param bool        $in_background Liegt unter einem Schlüssel mit „background“.
	 * @param Url_Matcher $matcher       URL-Erkennung.
	 * @param array       $found         Treffer per URL, wird ergänzt.
	 * @param array       $ids           Treffer per ID (Atomic-Elemente), wird ergänzt.
	 * @return void
	 */
	private static function walk( $value, $in_custom_css, $in_background, Url_Matcher $matcher, array &$found, array &$ids ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			if ( isset( $value['$$type'], $value['value'] ) && 'image-attachment-id' === $value['$$type'] && is_numeric( $value['value'] ) ) {
				$attachment_id         = (int) $value['value'];
				$ids[ $attachment_id ] = isset( $ids[ $attachment_id ] ) ? $ids[ $attachment_id ] + 1 : 1;
			}

			foreach ( $value as $key => $child ) {
				$key = strtolower( (string) $key );
				self::walk( $child, $in_custom_css || 'custom_css' === $key, $in_background || false !== strpos( $key, 'background' ), $matcher, $found, $ids );
			}
			return;
		}

		if ( ! is_string( $value ) ) {
			return;
		}

		foreach ( $matcher->find( $value ) as $path => $count ) {
			$found[] = array(
				'path'       => $path,
				'count'      => $count,
				'custom_css' => $in_custom_css,
				'background' => $in_background,
			);
		}
	}

	/**
	 * Optionen (Theme-Einstellungen, Widgets, Plugin-Optionen). Transients und eigene Optionen nicht.
	 *
	 * @param int $cursor Letzte option_id.
	 * @param int $limit  Höchstzahl.
	 * @return array
	 */
	private function scan_options( $cursor, $limit ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $this->matcher->like_needle() ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scan in Paketen mit LIKE-Vorfilter.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options}
				WHERE option_id > %d AND option_value LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
				ORDER BY option_id ASC LIMIT %d",
				$cursor,
				$like,
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%',
				$wpdb->esc_like( 'akwu_' ) . '%',
				$limit
			)
		);

		$hits = array();
		foreach ( $rows as $row ) {
			$cursor = (int) $row->option_id;
			$label  = self::option_label( $row->option_name );
			foreach ( $this->matcher->find( $row->option_value ) as $path => $count ) {
				$this->add_path_hit( $hits, $path, $count, 'option', 0, $row->option_name, $label, '', null );
			}
		}

		return self::result( $hits, $cursor, count( $rows ) < $limit, count( $rows ) );
	}

	/**
	 * Termmeta (z. B. Bilder an Kategorien).
	 *
	 * @param int $cursor Letzte meta_id.
	 * @param int $limit  Höchstzahl.
	 * @return array
	 */
	private function scan_termmeta( $cursor, $limit ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $this->matcher->like_needle() ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scan in Paketen mit LIKE-Vorfilter.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta}
				WHERE meta_id > %d AND meta_value LIKE %s ORDER BY meta_id ASC LIMIT %d",
				$cursor,
				$like,
				$limit
			)
		);

		$hits = array();
		foreach ( $rows as $row ) {
			$cursor = (int) $row->meta_id;
			$term   = get_term( (int) $row->term_id );
			/* translators: %s: Name des Begriffs, z. B. einer Kategorie. */
			$label = sprintf( __( 'Begriff „%s“', 'akuma-webp-umwandler' ), ( $term && ! is_wp_error( $term ) ) ? $term->name : $row->term_id );
			foreach ( $this->matcher->find( $row->meta_value ) as $path => $count ) {
				$this->add_path_hit( $hits, $path, $count, 'term', (int) $row->term_id, $row->meta_key, $label, '', null );
			}
		}

		return self::result( $hits, $cursor, count( $rows ) < $limit, count( $rows ) );
	}

	/**
	 * Tabelle des Plugins Code Snippets (`{prefix}snippets`), falls vorhanden. Nur Warnungen.
	 *
	 * @param int $cursor Letzte Snippet-ID.
	 * @param int $limit  Höchstzahl.
	 * @return array
	 */
	private function scan_snippets( $cursor, $limit ) {
		global $wpdb;

		$table = self::snippets_table();
		if ( null === $table ) {
			return self::result( array(), 0, true, 0 );
		}

		$like = '%' . $wpdb->esc_like( $this->matcher->like_needle() ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus $wpdb->prefix.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, code FROM `{$table}` WHERE id > %d AND code LIKE %s ORDER BY id ASC LIMIT %d", $cursor, $like, $limit ) );

		$hits = array();
		foreach ( (array) $rows as $row ) {
			$cursor = (int) $row->id;
			/* translators: %s: Name des Snippets. */
			$label = sprintf( __( 'Code Snippet „%s“', 'akuma-webp-umwandler' ), $row->name );
			foreach ( $this->matcher->find( $row->code ) as $path => $count ) {
				$this->add_path_hit( $hits, $path, $count, 'snippet', (int) $row->id, 'code', $label, '', 'snippet' );
			}
		}

		return self::result( $hits, $cursor, count( (array) $rows ) < $limit, count( (array) $rows ) );
	}

	/**
	 * Name der Code-Snippets-Tabelle, wenn sie existiert.
	 *
	 * @return string|null
	 */
	public static function snippets_table() {
		global $wpdb;

		$table = $wpdb->prefix . 'snippets';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prüfung, ob die Tabelle existiert.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $exists === $table ? $table : null;
	}

	/**
	 * Beitragsbilder (`_thumbnail_id`). Verweis über die ID, kein Ersatz nötig.
	 *
	 * @param int $cursor Letzte meta_id.
	 * @param int $limit  Höchstzahl.
	 * @return array
	 */
	private function scan_featured( $cursor, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scan in Paketen.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_id, pm.post_id, pm.meta_value, p.post_type, p.post_title
				FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_id > %d AND pm.meta_key = '_thumbnail_id' AND p.post_type <> 'revision' AND p.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY pm.meta_id ASC LIMIT %d",
				$cursor,
				$limit
			)
		);

		$hits = array();
		foreach ( $rows as $row ) {
			$cursor = (int) $row->meta_id;
			$hits[] = self::hit( (int) $row->meta_value, 'featured', (int) $row->post_id, '_thumbnail_id', self::post_label( $row->post_title ), 'featured', 1, null, $row->post_type, 'id' );
		}

		return self::result( $hits, $cursor, count( $rows ) < $limit, count( $rows ) );
	}

	/**
	 * Logo und Website-Icon (Verweis über die ID).
	 *
	 * @return array[]
	 */
	private function scan_special() {
		$hits = array();
		$logo = (int) get_theme_mod( 'custom_logo' );
		$icon = (int) get_option( 'site_icon' );

		if ( $logo > 0 ) {
			$hits[] = self::hit( $logo, 'option', 0, 'custom_logo', __( 'Logo', 'akuma-webp-umwandler' ), 'logo', 1, null, '', 'id' );
		}
		if ( $icon > 0 ) {
			$hits[] = self::hit( $icon, 'option', 0, 'site_icon', __( 'Website-Icon', 'akuma-webp-umwandler' ), 'logo', 1, null, '', 'id' );
		}

		return $hits;
	}

	/**
	 * Durchsuchbare Dateien des aktiven Themes (und Eltern-Themes), sortiert.
	 *
	 * Nur das Auflisten, ohne Lesen. Ordner node_modules, vendor und .git werden übersprungen,
	 * ebenso Dateien über 2 MB.
	 *
	 * @param int $max_files Höchstzahl Dateien.
	 * @return string[] Absolute Pfade.
	 */
	public static function theme_files( $max_files = 3000 ) {
		$files = array();
		$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					static function ( $file ) {
						return ! ( $file->isDir() && in_array( $file->getFilename(), array( 'node_modules', 'vendor', '.git' ), true ) );
					}
				)
			);

			foreach ( $iterator as $file ) {
				if ( count( $files ) >= $max_files ) {
					break 2;
				}
				if ( $file->isFile() && $file->getSize() <= 2 * MB_IN_BYTES && in_array( strtolower( $file->getExtension() ), self::THEME_EXTENSIONS, true ) ) {
					$files[] = wp_normalize_path( $file->getPathname() );
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Durchsucht Theme-Dateien. Nur Warnungen.
	 *
	 * @param string[] $files Absolute Pfade aus theme_files().
	 * @return array[]
	 */
	public function scan_theme_files( array $files ) {
		$hits = array();
		$root = trailingslashit( wp_normalize_path( get_theme_root() ) );

		foreach ( $files as $path ) {
			$text     = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale Theme-Datei, nur lesen.
			$relative = str_replace( $root, '', $path );

			foreach ( $this->matcher->find( (string) $text ) as $found => $matches ) {
				$this->add_path_hit( $hits, $found, $matches, 'file', 0, $relative, $relative, '', 'theme_file' );
			}
		}

		return $hits;
	}

	/**
	 * Fügt eine Fundstelle per Pfad hinzu, wenn der Pfad zu einem Anhang gehört.
	 *
	 * Pfade zu Größen, die nicht in den Metadaten stehen (z. B. `bild-640x480.png` nach
	 * Theme-Wechsel), werden dem Anhang zugeordnet und mit Kontext `unknown_size` markiert.
	 *
	 * @param array       $hits      Fundstellen, wird ergänzt.
	 * @param string      $path      Pfad im Upload-Ordner.
	 * @param int         $count     Anzahl.
	 * @param string      $where     post, meta, option, term, snippet oder file.
	 * @param int         $object_id ID des Objekts.
	 * @param string      $key       Feld, Meta-Schlüssel oder Optionsname.
	 * @param string      $label     Anzeigename.
	 * @param string      $post_type Post-Typ, falls vorhanden.
	 * @param string|null $warning   Warnungstyp oder null.
	 * @param string      $context   Kontext, Standard content.
	 * @return void
	 */
	private function add_path_hit( array &$hits, $path, $count, $where, $object_id, $key, $label, $post_type, $warning, $context = 'content' ) {
		$attachment_id = $this->attachment_for( $path );

		if ( null === $attachment_id ) {
			return;
		}

		if ( ! isset( $this->index[ $path ] ) ) {
			$context = 'unknown_size';
		}

		$hits[] = self::hit( $attachment_id, $where, $object_id, $key, $label, $context, $count, $warning, $post_type );
	}

	/**
	 * Attachment-ID zu einem Pfad, auch für nicht registrierte Größen wie `bild-640x480.png`.
	 *
	 * @param string $path Pfad im Upload-Ordner.
	 * @return int|null
	 */
	public function attachment_for( $path ) {
		if ( isset( $this->index[ $path ] ) ) {
			return $this->index[ $path ];
		}

		if ( preg_match( '/^(.+)-\d+x\d+(\.[A-Za-z]+)$/', $path, $match ) && isset( $this->index[ $match[1] . $match[2] ] ) ) {
			return $this->index[ $match[1] . $match[2] ];
		}

		return null;
	}

	/**
	 * Baut eine Fundstelle.
	 *
	 * @param int         $attachment_id Anhang.
	 * @param string      $where         Art der Quelle.
	 * @param int         $object_id     ID des Objekts.
	 * @param string      $key           Feld oder Schlüssel.
	 * @param string      $label         Anzeigename.
	 * @param string      $context       Kontext.
	 * @param int         $count         Anzahl.
	 * @param string|null $warning       Warnungstyp oder null.
	 * @param string      $post_type     Post-Typ.
	 * @param string      $by            url (Verweis über die Adresse) oder id (über die Attachment-ID).
	 * @return array
	 */
	private static function hit( $attachment_id, $where, $object_id, $key, $label, $context, $count, $warning, $post_type = '', $by = 'url' ) {
		return array(
			'by'         => $by,
			'attachment' => (int) $attachment_id,
			'where'      => $where,
			'object'     => $object_id,
			'key'        => (string) $key,
			'label'      => (string) $label,
			'post_type'  => (string) $post_type,
			'context'    => $context,
			'count'      => (int) $count,
			'warning'    => $warning,
		);
	}

	/**
	 * Ergebnis einer Scan-Runde.
	 *
	 * @param array[] $hits   Fundstellen.
	 * @param int     $cursor Neuer Cursor.
	 * @param bool    $done   Quelle fertig.
	 * @param int     $rows   Gelesene Zeilen.
	 * @return array
	 */
	private static function result( array $hits, $cursor, $done, $rows ) {
		return array(
			'hits'   => $hits,
			'cursor' => (int) $cursor,
			'done'   => (bool) $done,
			'rows'   => (int) $rows,
		);
	}

	/**
	 * Anzeigename eines Beitrags.
	 *
	 * @param string $title Titel.
	 * @return string
	 */
	private static function post_label( $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );

		return '' === $title ? __( '(ohne Titel)', 'akuma-webp-umwandler' ) : $title;
	}

	/**
	 * Anzeigename einer Option.
	 *
	 * @param string $name Optionsname.
	 * @return string
	 */
	private static function option_label( $name ) {
		if ( 0 === strpos( $name, 'theme_mods_' ) ) {
			return __( 'Theme-Einstellungen', 'akuma-webp-umwandler' );
		}
		if ( 0 === strpos( $name, 'widget_' ) ) {
			return __( 'Widgets', 'akuma-webp-umwandler' );
		}

		/* translators: %s: Optionsname. */
		return sprintf( __( 'Option %s', 'akuma-webp-umwandler' ), $name );
	}
}
