<?php
/**
 * Testdaten für den WebP-Umwandler. Nur für Testinstallationen.
 *
 * Aufruf: wp eval-file tests/seed/seed.php
 *
 * Wiederholbar: Testdaten aus einem früheren Lauf (Meta `_akwu_seed`) werden vorher gelöscht.
 * Die Bilder liegen bewusst in uploads/2019/05, wie bei einer älteren Website.
 *
 * Legt an:
 * - Bilder per GD: PNG mit und ohne Transparenz, große JPG, ein Bild über 2560 px (ergibt -scaled),
 *   Namenskollision bild.png + bild.jpg, eine bereits stark komprimierte JPG, ein GIF und ein WebP.
 * - Elementor-Seite: Image-Widget, Section- und Container-Hintergrundbild, Galerie,
 *   Bild im Text-Editor, Elementor-4-Atomic-Bild (nur ID) und eine Bild-URL im Custom CSS
 *   der Seiteneinstellungen.
 * - Beitrag mit Bildblock und Postmeta (URL und serialisiertes Array).
 * - Theme-Mod mit Bild-URL, Hintergrundbild im Elementor-Kit, Bild-URL im Customizer-CSS.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler\Tests\Seed;

use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

const META_KEY = '_akwu_seed';
const SUBDIR   = '2019/05';

/**
 * Führt alle Schritte aus.
 *
 * @return void
 */
function run() {
	if ( 'production' === wp_get_environment_type() ) {
		WP_CLI::error( 'Abbruch: WP_ENVIRONMENT_TYPE ist „production“. Testdaten nur auf Testinstallationen anlegen.' );
	}

	if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagewebp' ) ) {
		WP_CLI::error( 'GD mit WebP-Unterstützung fehlt, Testbilder können nicht erzeugt werden.' );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	if ( $admins ) {
		wp_set_current_user( (int) $admins[0] );
	}

	reset_previous();

	$ids  = create_images();
	$urls = array();
	foreach ( $ids as $key => $id ) {
		$urls[ $key ] = wp_get_attachment_url( $id );
	}

	$page_id = create_elementor_page( $ids, $urls );
	$post_id = create_block_post( $ids, $urls );
	$kit     = set_kit_background( $ids, $urls );

	set_theme_mod( 'background_image', $urls['transparent'] );
	wp_update_custom_css_post( sprintf( "body {\n\tbackground-image: url('%s');\n}\n", $urls['bild_png'] ) );

	WP_CLI::log( '' );
	WP_CLI::log( 'Bilder:' );
	$rows = array();
	foreach ( $ids as $key => $id ) {
		$meta   = wp_get_attachment_metadata( $id );
		$rows[] = array(
			'Schlüssel' => $key,
			'ID'        => $id,
			'Datei'     => get_post_meta( $id, '_wp_attached_file', true ),
			'Typ'       => get_post_mime_type( $id ),
			'Größen'    => isset( $meta['sizes'] ) ? count( $meta['sizes'] ) : 0,
			'Original'  => isset( $meta['original_image'] ) ? $meta['original_image'] : '',
		);
	}
	WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );

	WP_CLI::log( '' );
	WP_CLI::log( sprintf( 'Elementor-Seite: %s', get_permalink( $page_id ) ) );
	WP_CLI::log( sprintf( 'Beitrag mit Bildblock: %s', get_permalink( $post_id ) ) );
	WP_CLI::log( $kit ? sprintf( 'Hintergrundbild im Elementor-Kit (ID %d) gesetzt.', $kit ) : 'Kein aktives Elementor-Kit gefunden, Kit übersprungen.' );
	WP_CLI::log( 'Customizer-CSS und Theme-Mod „background_image“ gesetzt.' );
	WP_CLI::success( 'Testdaten angelegt.' );
}

/**
 * Löscht Testdaten eines früheren Laufs samt Dateien.
 *
 * @return void
 */
function reset_previous() {
	$previous = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Nur in Testdaten.
		)
	);

	foreach ( $previous as $id ) {
		if ( 'attachment' === get_post_type( $id ) ) {
			wp_delete_attachment( $id, true );
		} else {
			wp_delete_post( $id, true );
		}
	}

	if ( $previous ) {
		WP_CLI::log( sprintf( '%d Einträge aus einem früheren Lauf gelöscht.', count( $previous ) ) );
	}

	// Reste im Testordner, z. B. nach einem abgebrochenen Lauf.
	$upload = wp_upload_dir( SUBDIR );
	foreach ( (array) glob( trailingslashit( $upload['path'] ) . '*' ) as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
}

/**
 * Erzeugt die Testbilder und legt sie als Anhänge an.
 *
 * @return array<string, int> Schlüssel => Attachment-ID.
 */
function create_images() {
	$upload = wp_upload_dir( SUBDIR );
	wp_mkdir_p( $upload['path'] );
	$dir = trailingslashit( $upload['path'] );

	$specs = array(
		// Schlüssel => Datei, Breite, Höhe, Art, Titel.
		'transparent' => array( 'logo-transparent.png', 1600, 1000, 'png-alpha', 'PNG mit Transparenz' ),
		'team'        => array( 'team-header.png', 1800, 900, 'png', 'Große PNG ohne Transparenz' ),
		'praxis'      => array( 'praxis-empfang.jpg', 2000, 1333, 'jpg', 'Große JPG' ),
		'panorama'    => array( 'panorama.jpg', 3200, 1800, 'jpg', 'JPG über 2560 px (ergibt -scaled)' ),
		'bild_png'    => array( 'bild.png', 1200, 800, 'png', 'Namenskollision PNG' ),
		'bild_jpg'    => array( 'bild.jpg', 1200, 800, 'jpg', 'Namenskollision JPG' ),
		'optimiert'   => array( 'bereits-optimiert.jpg', 800, 600, 'jpg-low', 'Bereits stark komprimierte JPG' ),
		'gif'         => array( 'animation.gif', 300, 200, 'gif', 'GIF (wird ignoriert)' ),
		'webp'        => array( 'schon-modern.webp', 800, 600, 'webp', 'WebP (schon modern)' ),
	);

	$mimes = array(
		'png-alpha' => 'image/png',
		'png'       => 'image/png',
		'jpg'       => 'image/jpeg',
		'jpg-low'   => 'image/jpeg',
		'gif'       => 'image/gif',
		'webp'      => 'image/webp',
	);

	$ids = array();
	foreach ( $specs as $key => $spec ) {
		list( $name, $width, $height, $kind, $title ) = $spec;

		$file = $dir . $name;
		write_image( $file, $width, $height, $kind );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mimes[ $kind ],
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'guid'           => trailingslashit( $upload['url'] ) . $name,
			),
			$file,
			0,
			true
		);

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( sprintf( '%s: %s', $name, $id->get_error_message() ) );
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $title );
		update_post_meta( $id, META_KEY, 1 );

		$ids[ $key ] = $id;
	}

	return $ids;
}

/**
 * Schreibt ein Testbild. Fotoähnlicher Inhalt (Verlauf, Flächen, Weichzeichner), damit die
 * Kompression realistisch ausfällt.
 *
 * @param string $file   Zielpfad.
 * @param int    $width  Breite.
 * @param int    $height Höhe.
 * @param string $kind   png-alpha, png, jpg, jpg-low, gif oder webp.
 * @return void
 */
function write_image( $file, $width, $height, $kind ) {
	$image = imagecreatetruecolor( $width, $height );

	if ( 'png-alpha' === $kind ) {
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		imagefilledrectangle( $image, 0, 0, $width, $height, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
		imagealphablending( $image, true );
		for ( $i = 0; $i < 60; $i++ ) {
			$color = imagecolorallocatealpha( $image, wp_rand( 20, 60 ), wp_rand( 100, 160 ), wp_rand( 80, 120 ), wp_rand( 0, 90 ) );
			$size  = wp_rand( 60, 320 );
			imagefilledellipse( $image, wp_rand( 0, $width ), wp_rand( 0, $height ), $size, $size, $color );
		}
	} else {
		// Vertikaler Verlauf.
		for ( $y = 0; $y < $height; $y++ ) {
			$t     = $y / max( 1, $height - 1 );
			$color = imagecolorallocate( $image, (int) ( 40 + 160 * $t ), (int) ( 110 + 90 * ( 1 - $t ) ), (int) ( 90 + 120 * $t ) );
			imageline( $image, 0, $y, $width, $y, $color );
		}
		// Flächen in vielen Farben, danach weichzeichnen.
		$count = (int) ( $width * $height / 4000 );
		for ( $i = 0; $i < $count; $i++ ) {
			$color = imagecolorallocatealpha( $image, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 30, 100 ) );
			imagefilledellipse( $image, wp_rand( 0, $width ), wp_rand( 0, $height ), wp_rand( 4, 90 ), wp_rand( 4, 90 ), $color );
		}
		imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
	}

	switch ( $kind ) {
		case 'png-alpha':
		case 'png':
			imagepng( $image, $file, 6 );
			break;
		case 'jpg':
			imagejpeg( $image, $file, 92 );
			break;
		case 'jpg-low':
			imagejpeg( $image, $file, 45 );
			break;
		case 'gif':
			imagetruecolortopalette( $image, true, 128 );
			imagegif( $image, $file );
			break;
		case 'webp':
			imagewebp( $image, $file, 80 );
			break;
	}
}

/**
 * Elementor-Seite mit den typischen Bildstellen.
 *
 * Gespeichert wie von Elementor selbst: `_elementor_data` als JSON mit wp_slash().
 *
 * @param array $ids  Attachment-IDs.
 * @param array $urls Original-URLs.
 * @return int Seiten-ID.
 */
function create_elementor_page( array $ids, array $urls ) {
	$medium = wp_get_attachment_image_src( $ids['praxis'], 'medium' );

	$image_setting = static function ( $key, $alt = '' ) use ( $ids, $urls ) {
		return array(
			'url'    => $urls[ $key ],
			'id'     => $ids[ $key ],
			'size'   => '',
			'alt'    => $alt,
			'source' => 'library',
		);
	};

	$data = array(
		array(
			'id'       => 'a5e0001',
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array(
				'background_background' => 'classic',
				'background_image'      => $image_setting( 'team' ),
			),
			'elements' => array(
				array(
					'id'       => 'a5e0002',
					'elType'   => 'column',
					'isInner'  => false,
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => 'a5e0003',
							'elType'     => 'widget',
							'widgetType' => 'image',
							'isInner'    => false,
							'settings'   => array(
								'image'      => $image_setting( 'praxis', 'Empfang' ),
								'image_size' => 'large',
							),
							'elements'   => array(),
						),
						array(
							'id'         => 'a5e0004',
							'elType'     => 'widget',
							'widgetType' => 'image-gallery',
							'isInner'    => false,
							'settings'   => array(
								'wp_gallery' => array(
									array(
										'id'  => $ids['bild_png'],
										'url' => $urls['bild_png'],
									),
									array(
										'id'  => $ids['bild_jpg'],
										'url' => $urls['bild_jpg'],
									),
									array(
										'id'  => $ids['panorama'],
										'url' => $urls['panorama'],
									),
								),
							),
							'elements'   => array(),
						),
						array(
							'id'         => 'a5e0005',
							'elType'     => 'widget',
							'widgetType' => 'text-editor',
							'isInner'    => false,
							'settings'   => array(
								'editor' => sprintf(
									'<p>Text mit eingebettetem Bild.</p><p><img class="alignnone size-medium wp-image-%1$d" src="%2$s" alt="" width="%3$d" height="%4$d" /></p>',
									$ids['praxis'],
									$medium[0],
									$medium[1],
									$medium[2]
								),
							),
							'elements'   => array(),
						),
					),
				),
			),
		),
		array(
			'id'       => 'a5e0006',
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => array(
				'content_width'         => 'boxed',
				'background_background' => 'classic',
				'background_image'      => $image_setting( 'transparent' ),
			),
			'elements' => array(
				array(
					'id'         => 'a5e0007',
					'elType'     => 'widget',
					'widgetType' => 'image',
					'isInner'    => false,
					'settings'   => array( 'image' => $image_setting( 'optimiert' ) ),
					'elements'   => array(),
				),
				// Elementor-4-Atomic-Bild: nur die ID, keine URL.
				array(
					'id'         => 'a5e0008',
					'elType'     => 'widget',
					'widgetType' => 'e-image',
					'isInner'    => false,
					'settings'   => array(
						'image' => array(
							'$$type' => 'image',
							'value'  => array(
								'src' => array(
									'$$type' => 'image-src',
									'value'  => array(
										'id'  => array(
											'$$type' => 'image-attachment-id',
											'value'  => $ids['webp'],
										),
										'url' => null,
									),
								),
							),
						),
					),
					'elements'   => array(),
				),
			),
		),
	);

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Testseite Elementor',
			'post_content' => '',
		),
		true
	);

	if ( is_wp_error( $page_id ) ) {
		WP_CLI::error( $page_id->get_error_message() );
	}

	update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
	update_post_meta( $page_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
	update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	update_post_meta(
		$page_id,
		'_elementor_page_settings',
		array( 'custom_css' => sprintf( "selector {\n\tbackground-image: url(%s);\n}", $urls['bild_jpg'] ) )
	);
	update_post_meta( $page_id, META_KEY, 1 );

	return $page_id;
}

/**
 * Beitrag mit Bildblock, Link auf das Original und Postmeta wie von ACF oder JetEngine.
 *
 * @param array $ids  Attachment-IDs.
 * @param array $urls Original-URLs.
 * @return int Beitrags-ID.
 */
function create_block_post( array $ids, array $urls ) {
	$large = wp_get_attachment_image_src( $ids['team'], 'large' );

	$content = sprintf(
		'<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"media"} -->' . "\n"
		. '<figure class="wp-block-image size-large"><a href="%3$s"><img src="%2$s" alt="" class="wp-image-%1$d"/></a></figure>' . "\n"
		. '<!-- /wp:image -->' . "\n\n"
		. '<!-- wp:paragraph -->' . "\n" . '<p>Ein Beitrag mit Bild aus dem Block-Editor.</p>' . "\n" . '<!-- /wp:paragraph -->',
		$ids['team'],
		$large[0],
		$urls['team']
	);

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Testbeitrag Block-Editor',
			'post_content' => $content,
			'post_excerpt' => sprintf( 'Auszug mit Bild-URL: %s', $urls['praxis'] ),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $post_id->get_error_message() );
	}

	update_post_meta( $post_id, 'hero_bild_url', $urls['team'] );
	update_post_meta(
		$post_id,
		'galerie_daten',
		array(
			'titel'  => 'Galerie',
			'bilder' => array( $urls['bild_png'], $urls['bild_jpg'] ),
		)
	);
	update_post_meta( $post_id, META_KEY, 1 );

	return $post_id;
}

/**
 * Hintergrundbild im aktiven Elementor-Kit (globale Seiteneinstellungen).
 *
 * @param array $ids  Attachment-IDs.
 * @param array $urls Original-URLs.
 * @return int Kit-ID oder 0.
 */
function set_kit_background( array $ids, array $urls ) {
	$kit_id = (int) get_option( 'elementor_active_kit' );

	if ( $kit_id <= 0 || 'elementor_library' !== get_post_type( $kit_id ) ) {
		return 0;
	}

	$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
	$settings = is_array( $settings ) ? $settings : array();

	$settings['body_background_background'] = 'classic';
	$settings['body_background_image']      = array(
		'url'    => $urls['panorama'],
		'id'     => $ids['panorama'],
		'size'   => '',
		'alt'    => '',
		'source' => 'library',
	);

	update_post_meta( $kit_id, '_elementor_page_settings', $settings );

	return $kit_id;
}

run();
