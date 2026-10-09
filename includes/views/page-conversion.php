<?php
/**
 * Umwandlung: Fortschritt, Ablauf und Live-Protokoll.
 *
 * In M1 nur das Gerüst, der Start folgt mit M3.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$steps = array(
	__( 'Bilder umwandeln', 'akuma-webp-umwandler' ),
	__( 'Verweise ersetzen', 'akuma-webp-umwandler' ),
	__( 'Elementor-CSS neu erzeugen', 'akuma-webp-umwandler' ),
	__( 'Cache leeren', 'akuma-webp-umwandler' ),
);

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Umwandlung', 'akuma-webp-umwandler' ),
		'title'   => __( 'Noch keine Umwandlung.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Zuerst den Bestand scannen.', 'akuma-webp-umwandler' ),
	)
);
?>
<div class="akwu-progress">
	<div class="akwu-progress__track" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Fortschritt der Umwandlung', 'akuma-webp-umwandler' ); ?>">
		<div class="akwu-progress__fill"></div>
	</div>
	<div class="akwu-progress__meta">
		<span><?php esc_html_e( 'Noch nicht gestartet', 'akuma-webp-umwandler' ); ?></span>
		<span class="akwu-num">0 %</span>
	</div>
</div>

<div class="akwu-columns">
	<div class="akwu-card akwu-columns__side">
		<h2 class="akwu-card__title"><?php esc_html_e( 'Ablauf', 'akuma-webp-umwandler' ); ?></h2>
		<ol class="akwu-steps">
			<?php foreach ( $steps as $index => $step ) : ?>
				<li class="akwu-step">
					<span class="akwu-step__number" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $index + 1 ) ); ?></span>
					<span class="akwu-step__label"><?php echo esc_html( $step ); ?></span>
					<span class="akwu-pill akwu-pill--info"><?php esc_html_e( 'wartet', 'akuma-webp-umwandler' ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>

	<div class="akwu-columns__main">
		<?php
		View::render(
			'part-placeholder',
			array(
				'title'     => __( 'Live-Protokoll', 'akuma-webp-umwandler' ),
				'text'      => __( 'Erscheint während der Umwandlung, neueste Einträge zuerst. Start, Pausieren und Abbrechen kommen mit der Umwandlung.', 'akuma-webp-umwandler' ),
				'milestone' => 'M3',
			)
		);
		?>
	</div>
</div>
