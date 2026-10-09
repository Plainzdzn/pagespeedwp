<?php
/**
 * Rückgängig: komplett oder pro Bild, solange die Originale existieren.
 *
 * In M1 nur Platzhalter und der Hinweis zur Deinstallation. Rückgängig folgt mit M4.
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
		'eyebrow' => __( 'Rückgängig', 'akuma-webp-umwandler' ),
		'title'   => __( 'Noch nichts umgewandelt.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Rückgängig geht, solange die Originale existieren.', 'akuma-webp-umwandler' ),
	)
);

View::render(
	'part-placeholder',
	array(
		'title'     => __( 'Umwandlung zurücknehmen', 'akuma-webp-umwandler' ),
		'text'      => __( 'Stellt Datei, Metadaten und Verweise wieder her, für alle Bilder oder einzeln. Danach werden die WebP-Dateien entfernt.', 'akuma-webp-umwandler' ),
		'milestone' => 'M4',
	)
);
?>
<div class="akwu-callout akwu-callout--warn">
	<p class="akwu-callout__title"><?php esc_html_e( 'Plugin löschen beendet die Möglichkeit zum Rückgängigmachen', 'akuma-webp-umwandler' ); ?></p>
	<p><?php esc_html_e( 'Beim Löschen des Plugins wird das Protokoll entfernt. Die umgewandelten Bilder bleiben, ein Rückgängig ist danach aber nicht mehr möglich.', 'akuma-webp-umwandler' ); ?></p>
</div>
