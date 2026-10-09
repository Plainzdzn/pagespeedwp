<?php
/**
 * Alle Bilder: Liste aller Bilder mit Größe, Status und Fundstellen.
 *
 * In M1 nur Platzhalter, die Liste entsteht mit dem Scan in M2.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Alle Bilder', 'akuma-webp-umwandler' ),
		'title'   => __( 'Noch keine Bildliste.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Sie entsteht beim Scan.', 'akuma-webp-umwandler' ),
	)
);

View::render(
	'part-placeholder',
	array(
		'title'     => __( 'Bilder der Mediathek', 'akuma-webp-umwandler' ),
		'text'      => __( 'Alle PNG- und JPG-Bilder mit Größe heute, geschätzter WebP-Größe, Fundstellen und Status. Später auch mit Rückgängig pro Bild.', 'akuma-webp-umwandler' ),
		'milestone' => 'M2',
	)
);
