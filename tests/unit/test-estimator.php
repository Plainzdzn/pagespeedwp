<?php
/**
 * Tests für Stichprobe und Hochrechnung.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\Estimator;
use PHPUnit\Framework\TestCase;

/**
 * Klassen, Auswahl der Stichprobe, Verhältnisse, Überspringen.
 */
class Estimator_Test extends TestCase {

	/**
	 * Baut einen Datensatz.
	 *
	 * @param int       $id     ID.
	 * @param string    $kind   jpg oder png.
	 * @param int       $source Bytes der Quelle.
	 * @param bool|null $alpha  Transparenz.
	 * @return array
	 */
	private static function item( $id, $kind, $source, $alpha = null ) {
		return array(
			'id'     => $id,
			'kind'   => $kind,
			'source' => $source,
			'bytes'  => $source * 2,
			'alpha'  => $alpha,
		);
	}

	/**
	 * Klassen nach Format und Transparenz, ohne Quelle keine Klasse.
	 */
	public function test_class_of() {
		$this->assertSame( 'jpg', Estimator::class_of( self::item( 1, 'jpg', 10 ) ) );
		$this->assertSame( 'png', Estimator::class_of( self::item( 2, 'png', 10, false ) ) );
		$this->assertSame( 'png_alpha', Estimator::class_of( self::item( 3, 'png', 10, true ) ) );
		$this->assertNull( Estimator::class_of( self::item( 4, 'jpg', 0 ) ) );
		$this->assertNull( Estimator::class_of( self::item( 5, 'webp', 10 ) ) );
	}

	/**
	 * Höchstens $max Bilder, jede Klasse vertreten, verteilt über die Größen.
	 */
	public function test_pick_samples() {
		$items = array();
		for ( $i = 1; $i <= 100; $i++ ) {
			$items[] = self::item( $i, 'jpg', $i * 1000 );
		}
		$items[] = self::item( 200, 'png', 5000, true );

		$ids = Estimator::pick_samples( $items, 20 );

		$this->assertCount( 20, $ids );
		$this->assertContains( 200, $ids );
		$this->assertContains( 1, $ids, 'kleinstes JPG fehlt' );
		$this->assertGreaterThan( 90, max( array_diff( $ids, array( 200 ) ) ), 'großes JPG fehlt' );
	}

	/**
	 * Weniger Bilder als Stichprobe: alle.
	 */
	public function test_pick_samples_small_library() {
		$items = array( self::item( 1, 'jpg', 10 ), self::item( 2, 'png', 10, false ), self::item( 3, 'gif', 10 ) );

		$this->assertEqualsCanonicalizing( array( 1, 2 ), Estimator::pick_samples( $items, 20 ) );
		$this->assertSame( array(), Estimator::pick_samples( array(), 20 ) );
	}

	/**
	 * Verhältnis nach Bytes gewichtet, fehlende Klassen mit Standardwert.
	 */
	public function test_ratios() {
		$ratios = Estimator::ratios(
			array(
				array(
					'class'  => 'jpg',
					'source' => 1000,
					'webp'   => 300,
				),
				array(
					'class'  => 'jpg',
					'source' => 3000,
					'webp'   => 900,
				),
			)
		);

		$this->assertEqualsWithDelta( 0.3, $ratios['jpg'], 0.0001 );
		$this->assertSame( Estimator::DEFAULT_RATIOS['png'], $ratios['png'] );
	}

	/**
	 * Gemessenes Verhältnis geht vor, sonst das der Klasse.
	 */
	public function test_estimate() {
		$item   = self::item( 1, 'jpg', 1000 );
		$ratios = array( 'jpg' => 0.25 );

		$this->assertSame( 500, Estimator::estimate( $item, $ratios )['bytes'] );
		$this->assertSame( 1600, Estimator::estimate( $item, $ratios, 0.8 )['bytes'] );
		$this->assertTrue( Estimator::estimate( $item, $ratios, 0.8 )['measured'] );
	}

	/**
	 * Mindestersparnis 10 %: 0,91 überspringen, 0,9 umwandeln.
	 */
	public function test_would_skip() {
		$this->assertTrue( Estimator::would_skip( 0.91, 10 ) );
		$this->assertTrue( Estimator::would_skip( 1.02, 10 ) );
		$this->assertFalse( Estimator::would_skip( 0.9, 10 ) );
		$this->assertFalse( Estimator::would_skip( 0.3, 10 ) );
	}
}
