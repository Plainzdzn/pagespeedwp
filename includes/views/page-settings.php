<?php
/**
 * Einstellungen: Qualität, Mindestersparnis, Paketgröße, PageSpeed-API-Schlüssel.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\Icons;
use Akuma\WebpUmwandler\Settings;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$values = Settings::all();
$fields = array(
	'quality_jpg' => array(
		__( 'JPG-Qualität', 'akuma-webp-umwandler' ),
		__( 'Für JPG und PNG ohne Transparenz. Standard 82.', 'akuma-webp-umwandler' ),
	),
	'quality_png' => array(
		__( 'PNG mit Transparenz', 'akuma-webp-umwandler' ),
		__( 'Mit Imagick verlustfrei, dieser Wert gilt nur ohne Imagick. Standard 90.', 'akuma-webp-umwandler' ),
	),
	'min_savings' => array(
		__( 'Mindestersparnis in %', 'akuma-webp-umwandler' ),
		__( 'Ist das WebP nicht mindestens so viel kleiner, bleibt das Bild unverändert. Schützt bereits optimierte Bilder. Standard 10.', 'akuma-webp-umwandler' ),
	),
	'batch_size'  => array(
		__( 'Paketgröße', 'akuma-webp-umwandler' ),
		__( 'Bilder pro Anfrage. Kleiner wählen, wenn der Server Zeitlimits meldet. Standard 10.', 'akuma-webp-umwandler' ),
	),
);

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Einstellungen', 'akuma-webp-umwandler' ),
		'title'   => __( 'Einstellungen.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Die Standardwerte passen meist.', 'akuma-webp-umwandler' ),
	)
);
?>
<form class="akwu-card akwu-form" method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
	<?php settings_fields( 'akwu_settings' ); ?>

	<?php foreach ( $fields as $key => $field ) : ?>
		<?php $range = Settings::RANGES[ $key ]; ?>
		<div class="akwu-field">
			<label class="akwu-field__label" for="akwu-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label>
			<input class="akwu-field__input" type="number" id="akwu-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $values[ $key ] ); ?>" min="<?php echo esc_attr( (string) $range[0] ); ?>" max="<?php echo esc_attr( (string) $range[1] ); ?>" step="1" aria-describedby="akwu-<?php echo esc_attr( $key ); ?>-help" required>
			<p class="akwu-field__help" id="akwu-<?php echo esc_attr( $key ); ?>-help"><?php echo esc_html( $field[1] ); ?></p>
		</div>
	<?php endforeach; ?>

	<div class="akwu-field">
		<label class="akwu-field__label" for="akwu-psi_api_key"><?php esc_html_e( 'PageSpeed-API-Schlüssel', 'akuma-webp-umwandler' ); ?></label>
		<input class="akwu-field__input akwu-field__input--wide" type="password" id="akwu-psi_api_key" name="<?php echo esc_attr( Settings::OPTION . '[psi_api_key]' ); ?>" value="<?php echo esc_attr( $values['psi_api_key'] ); ?>" autocomplete="off" spellcheck="false" aria-describedby="akwu-psi_api_key-help">
		<p class="akwu-field__help" id="akwu-psi_api_key-help"><?php esc_html_e( 'Optional. Damit misst der Bericht die Startseite mobil vorher und nachher. Ohne Schlüssel sendet das Plugin keine Anfragen nach außen.', 'akuma-webp-umwandler' ); ?></p>
	</div>

	<div class="akwu-form__actions">
		<button type="submit" class="akwu-button">
			<?php esc_html_e( 'Speichern', 'akuma-webp-umwandler' ); ?>
			<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
		</button>
	</div>
</form>

<p class="akwu-small"><?php esc_html_e( 'Änderungen gelten für die nächste Umwandlung. Für eine neue Hochrechnung bitte erneut scannen.', 'akuma-webp-umwandler' ); ?></p>
