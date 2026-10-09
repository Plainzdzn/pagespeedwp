<?php
/**
 * Platzhalter für Bereiche, die in einem späteren Meilenstein folgen.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type string $title     Überschrift der Karte.
 *     @type string $text      Was hier später erscheint.
 *     @type string $milestone Meilenstein, z. B. „M2“.
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="akwu-card akwu-placeholder">
	<div class="akwu-card__head">
		<h2 class="akwu-card__title"><?php echo esc_html( $data['title'] ); ?></h2>
		<span class="akwu-pill akwu-pill--info">
			<?php
			/* translators: %s: Meilenstein, z. B. „M2“. */
			echo esc_html( sprintf( __( 'Folgt mit %s', 'akuma-webp-umwandler' ), $data['milestone'] ) );
			?>
		</span>
	</div>
	<p class="akwu-empty"><?php echo esc_html( $data['text'] ); ?></p>
</div>
