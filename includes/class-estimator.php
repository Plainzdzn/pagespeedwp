<?php
/**
 * Hochrechnung der Ersparnis aus einer Stichprobe.
 *
 * @package Akuma\WebpUmwandler
 */

namespace Akuma\WebpUmwandler;

defined( 'ABSPATH' ) || exit;

/**
 * Wählt eine Stichprobe und rechnet ihr Ergebnis auf alle Bilder hoch.
 *
 * Klassen: jpg, png (ohne Transparenz) und png_alpha. Je Klasse gilt das
 * Verhältnis WebP-Bytes zu Original-Bytes der Stichprobe (nach Bytes gewichtet).
 * Gemessene Bilder behalten ihr eigenes Verhältnis.
 *
 * Kommt ohne WordPress aus und ist dadurch direkt testbar.
 */
final class Estimator {

	/**
	 * Verhältnis, wenn eine Klasse keine Stichprobe hat. Bewusst vorsichtig.
	 */
	const DEFAULT_RATIOS = array(
		'jpg'       => 0.7,
		'png'       => 0.4,
		'png_alpha' => 0.8,
	);

	/**
	 * Klasse eines Datensatzes oder null, wenn er nicht umgewandelt wird.
	 *
	 * @param array $item Datensatz aus Inventory::record().
	 * @return string|null
	 */
	public static function class_of( array $item ) {
		if ( empty( $item['source'] ) ) {
			return null;
		}
		if ( 'jpg' === $item['kind'] ) {
			return 'jpg';
		}
		if ( 'png' === $item['kind'] ) {
			return empty( $item['alpha'] ) ? 'png' : 'png_alpha';
		}

		return null;
	}

	/**
	 * Wählt eine Stichprobe, gemischt nach Klasse und Größe.
	 *
	 * Jede vorhandene Klasse bekommt mindestens ein Bild, der Rest verteilt sich nach Anteil.
	 * Innerhalb einer Klasse werden die Bilder gleichmäßig vom kleinsten bis zum größten gewählt.
	 *
	 * @param array[] $items Datensätze.
	 * @param int     $max   Größe der Stichprobe.
	 * @return int[] Attachment-IDs.
	 */
	public static function pick_samples( array $items, $max = 20 ) {
		$classes = array();
		foreach ( $items as $item ) {
			$class = self::class_of( $item );
			if ( null !== $class ) {
				$classes[ $class ][] = $item;
			}
		}

		if ( empty( $classes ) || $max < 1 ) {
			return array();
		}

		ksort( $classes );
		$total = 0;
		foreach ( $classes as $members ) {
			$total += count( $members );
		}

		$quota = array();
		foreach ( $classes as $class => $members ) {
			$quota[ $class ] = min( count( $members ), max( 1, (int) round( $max * count( $members ) / $total ) ) );
		}

		// Auf $max kürzen, bei der größten Klasse zuerst.
		while ( array_sum( $quota ) > $max ) {
			arsort( $quota );
			$largest = key( $quota );
			if ( $quota[ $largest ] <= 1 ) {
				break;
			}
			--$quota[ $largest ];
		}

		$ids = array();
		foreach ( $classes as $class => $members ) {
			usort(
				$members,
				static function ( $a, $b ) {
					return $a['source'] <=> $b['source'];
				}
			);
			$count = count( $members );
			$take  = $quota[ $class ];
			for ( $k = 0; $k < $take; $k++ ) {
				// Gleichmäßig vom kleinsten bis zum größten Bild, bei nur einem Bild die Mitte.
				$index = 1 === $take ? (int) floor( $count / 2 ) : (int) round( $k * ( $count - 1 ) / ( $take - 1 ) );
				$ids[] = (int) $members[ $index ]['id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Verhältnis je Klasse aus den Messungen.
	 *
	 * @param array[] $samples Messungen mit class, source und webp (Bytes).
	 * @return array<string, float> Klasse => Verhältnis. Klassen ohne Messung mit Standardwert.
	 */
	public static function ratios( array $samples ) {
		$sums = array();
		foreach ( $samples as $sample ) {
			if ( empty( $sample['source'] ) || ! isset( $sample['webp'] ) ) {
				continue;
			}
			$class = $sample['class'];
			if ( ! isset( $sums[ $class ] ) ) {
				$sums[ $class ] = array( 0, 0 );
			}
			$sums[ $class ][0] += (int) $sample['source'];
			$sums[ $class ][1] += (int) $sample['webp'];
		}

		$ratios = self::DEFAULT_RATIOS;
		foreach ( $sums as $class => $sum ) {
			if ( $sum[0] > 0 ) {
				$ratios[ $class ] = $sum[1] / $sum[0];
			}
		}

		return $ratios;
	}

	/**
	 * Geschätzte Größe nach der Umwandlung.
	 *
	 * @param array      $item     Datensatz mit bytes, source und Klasse.
	 * @param array      $ratios   Verhältnis je Klasse.
	 * @param float|null $measured Gemessenes Verhältnis dieses Bildes, falls in der Stichprobe.
	 * @return array{bytes: int, ratio: float, measured: bool}
	 */
	public static function estimate( array $item, array $ratios, $measured = null ) {
		$class = self::class_of( $item );
		$ratio = null !== $measured ? (float) $measured : ( null !== $class && isset( $ratios[ $class ] ) ? (float) $ratios[ $class ] : 1.0 );

		return array(
			'bytes'    => (int) round( (int) $item['bytes'] * $ratio ),
			'ratio'    => $ratio,
			'measured' => null !== $measured,
		);
	}

	/**
	 * Würde das Bild übersprungen, weil die Ersparnis unter der Mindestersparnis liegt?
	 *
	 * @param float $ratio       WebP-Bytes durch Original-Bytes.
	 * @param int   $min_savings Mindestersparnis in Prozent.
	 * @return bool
	 */
	public static function would_skip( $ratio, $min_savings ) {
		return $ratio > ( 1 - $min_savings / 100 );
	}
}
