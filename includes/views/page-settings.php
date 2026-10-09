<?php
/**
 * Einstellungen: Qualität, Mindestersparnis, Paketgröße, PageSpeed-API.
 *
 * In M1 nur Anzeige der geplanten Standardwerte. Bearbeitbar ab M3.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$defaults = array(
	array( __( 'JPG-Qualität', 'akuma-webp-umwandler' ), '82' ),
	array( __( 'PNG ohne Transparenz', 'akuma-webp-umwandler' ), __( 'wie JPG, 82', 'akuma-webp-umwandler' ) ),
	array( __( 'PNG mit Transparenz', 'akuma-webp-umwandler' ), __( 'verlustfrei mit Imagick, sonst Qualität 90', 'akuma-webp-umwandler' ) ),
	array( __( 'Mindestersparnis', 'akuma-webp-umwandler' ), __( '10 %, sonst bleibt das Bild unverändert', 'akuma-webp-umwandler' ) ),
	array( __( 'Paketgröße', 'akuma-webp-umwandler' ), __( '10 Bilder pro Anfrage', 'akuma-webp-umwandler' ) ),
	array( __( 'PageSpeed-API-Schlüssel', 'akuma-webp-umwandler' ), __( 'optional, für die Messung vorher und nachher', 'akuma-webp-umwandler' ) ),
);

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Einstellungen', 'akuma-webp-umwandler' ),
		'title'   => __( 'Einstellungen.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Vorerst gelten die Standardwerte.', 'akuma-webp-umwandler' ),
	)
);
?>
<div class="akwu-card">
	<div class="akwu-card__head">
		<h2 class="akwu-card__title"><?php esc_html_e( 'Standardwerte', 'akuma-webp-umwandler' ); ?></h2>
		<span class="akwu-pill akwu-pill--info">
			<?php
			/* translators: %s: Meilenstein, z. B. „M3“. */
			echo esc_html( sprintf( __( 'Bearbeitbar ab %s', 'akuma-webp-umwandler' ), 'M3' ) );
			?>
		</span>
	</div>
	<dl class="akwu-defaults">
		<?php foreach ( $defaults as $item ) : ?>
			<div class="akwu-defaults__row">
				<dt><?php echo esc_html( $item[0] ); ?></dt>
				<dd><?php echo esc_html( $item[1] ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
</div>
