<?php
/**
 * Bericht: vorher/nachher, pro Bild, Restfundstellen, CSV-Export.
 *
 * In M1 nur Platzhalter, der Bericht folgt mit M4.
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
		'eyebrow' => __( 'Bericht', 'akuma-webp-umwandler' ),
		'title'   => __( 'Noch kein Bericht.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Er entsteht nach der ersten Umwandlung.', 'akuma-webp-umwandler' ),
	)
);

View::render(
	'part-placeholder',
	array(
		'title'     => __( 'Vorher und nachher', 'akuma-webp-umwandler' ),
		'text'      => __( 'Gesamtgröße vorher und nachher, umgewandelte und übersprungene Bilder, ersetzte Verweise, eine Tabelle pro Bild, Stellen zum Prüfen und der CSV-Export.', 'akuma-webp-umwandler' ),
		'milestone' => 'M4',
	)
);
