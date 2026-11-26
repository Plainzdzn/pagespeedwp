<?php
/**
 * Tests für den Quelltext der Fix-Datei und die Bildsuche in Kopfzeilen-Vorlagen.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Fix_File;
use Akuma\WebpUmwandler\Header_Images;
use PHPUnit\Framework\TestCase;

/**
 * Die Fix-Datei ist gültiges PHP, enthält nur die genannten Bild-IDs und ändert nur deren Bild-Tags.
 */
class Fix_File_Test extends TestCase {

	/**
	 * Ohne Fixes gibt es keinen Quelltext, also keine Datei.
	 */
	public function test_no_fixes_no_source() {
		$this->assertSame( '', Fix_File::render( array() ) );
		$this->assertSame( '', Fix_File::render( array( 'header_images' => array() ) ) );
		$this->assertSame( '', Fix_File::render( array( 'header_images' => array( 0, '0', 'abc' ) ) ) );
		$this->assertSame( '', Fix_File::render( array( 'unbekannt' => array( 5 ) ) ) );
	}

	/**
	 * IDs werden zu Ganzzahlen, sortiert, ohne Doppelte. Fremder Text kommt nie in den Quelltext.
	 */
	public function test_ids_are_sanitized_and_sorted() {
		$this->assertSame( array( 3, 12, 40 ), Fix_File::ids( array( '40', 3, 12, 3, 0, '12abc' ) ) );

		$source = Fix_File::render( array( 'header_images' => array( 40, '3', "12 ); system( 'x' ); //", 3 ) ) );
		$this->assertStringContainsString( 'static $ids = array( 3, 12, 40 );', $source );
		$this->assertStringNotContainsString( 'system', $source );
	}

	/**
	 * Gleiche Eingabe, gleicher Quelltext, unabhängig von der Reihenfolge.
	 */
	public function test_source_is_deterministic() {
		$this->assertSame(
			Fix_File::render( array( 'header_images' => array( 7, 5 ) ) ),
			Fix_File::render( array( 'header_images' => array( 5, 7, 7 ) ) )
		);
	}

	/**
	 * Der Quelltext ist gültiges PHP, trägt die Kennung und bricht ohne WordPress ab.
	 */
	public function test_source_is_valid_php_with_marker() {
		$source = Fix_File::render( array( 'header_images' => array( 6378 ) ) );

		$this->assertStringStartsWith( "<?php\n", $source );
		$this->assertStringContainsString( Fix_File::MARKER, $source );
		$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $source );
		$this->assertNotEmpty( token_get_all( $source, TOKEN_PARSE ) );
	}

	/**
	 * Die Funktion aus dem Quelltext ändert nur die Bild-Tags der genannten IDs.
	 */
	public function test_filter_marks_only_listed_images() {
		$calls = array();
		if ( ! function_exists( 'add_filter' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Squiz.Commenting.FunctionComment.Missing -- Ersatz für WordPress im Unit-Test.
			function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
				$GLOBALS['akwu_test_filters'][] = array( $hook, $callback, $priority, $args );
			}
		}

		$file = tempnam( sys_get_temp_dir(), 'akwu-fix' );
		file_put_contents( $file, Fix_File::render( array( 'header_images' => array( 11, 22 ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei.
		require $file;
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Testdatei.

		$calls = $GLOBALS['akwu_test_filters'];
		$this->assertSame( array( 'wp_get_attachment_image_attributes', 'akwu_fix_header_images', 20, 2 ), end( $calls ) );

		$logo = akwu_fix_header_images( array( 'class' => 'attachment-full size-full' ), (object) array( 'ID' => 22 ) );
		$this->assertSame( 'eager', $logo['loading'] );
		$this->assertSame( '1', $logo['data-no-lazy'] );
		$this->assertSame( 'attachment-full size-full skip-lazy', $logo['class'] );

		// Zweimal angewendet bleibt die Klasse einmal.
		$again = akwu_fix_header_images( $logo, (object) array( 'ID' => 22 ) );
		$this->assertSame( 'attachment-full size-full skip-lazy', $again['class'] );

		// Ein lazy gesetztes Attribut wird für Kopfbilder überschrieben, andere Bilder bleiben unberührt.
		$lazy = akwu_fix_header_images( array( 'loading' => 'lazy' ), (object) array( 'ID' => '11' ) );
		$this->assertSame( 'eager', $lazy['loading'] );
		$this->assertSame( 'skip-lazy', $lazy['class'] );

		$other = array(
			'class'   => 'x',
			'loading' => 'lazy',
		);
		$this->assertSame( $other, akwu_fix_header_images( $other, (object) array( 'ID' => 33 ) ) );
		$this->assertSame( $other, akwu_fix_header_images( $other, null ) );
		$this->assertSame( 'kein Array', akwu_fix_header_images( 'kein Array', (object) array( 'ID' => 22 ) ) );
	}

	/**
	 * Bilder in verschachtelten Containern: Bild, Bild-Box und Website-Logo. Andere Widgets und leere Bilder nicht.
	 */
	public function test_finds_images_in_header_tree() {
		$tree = array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array( 'background_image' => array( 'id' => 99 ) ),
				'elements' => array(
					array(
						'id'         => 'w1',
						'elType'     => 'widget',
						'widgetType' => 'image',
						'settings'   => array(
							'image' => array(
								'id'  => '6378',
								'url' => 'https://example.org/logo.webp',
							),
						),
					),
					array(
						'id'       => 'c2',
						'elType'   => 'container',
						'elements' => array(
							array(
								'id'         => 'w2',
								'elType'     => 'widget',
								'widgetType' => 'theme-site-logo',
								'settings'   => array(),
							),
							array(
								'id'         => 'w3',
								'elType'     => 'widget',
								'widgetType' => 'image-box',
								'settings'   => array( 'image' => array( 'id' => 12 ) ),
							),
							array(
								'id'         => 'w4',
								'elType'     => 'widget',
								'widgetType' => 'image',
								'settings'   => array(
									'image' => array(
										'id'  => '',
										'url' => 'https://example.org/extern.png',
									),
								),
							),
							array(
								'id'         => 'w5',
								'elType'     => 'widget',
								'widgetType' => 'nav-menu',
								'settings'   => array( 'image' => array( 'id' => 77 ) ),
							),
							'kaputt',
						),
					),
				),
			),
		);

		$found = Header_Images::find_images( $tree );

		$this->assertSame( array( 'w1', 'w2', 'w3' ), array_column( $found, 'widget' ) );
		$this->assertSame( array( 6378, 0, 12 ), array_column( $found, 'id' ) );
		$this->assertSame( array( false, true, false ), array_column( $found, 'logo' ) );
	}
}
