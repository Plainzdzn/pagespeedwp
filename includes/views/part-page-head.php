<?php
/**
 * Seitenkopf im Inhaltsbereich: Eyebrow und Überschrift mit Akzent.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type string $eyebrow Kleine Zeile über der Überschrift.
 *     @type string $title   Überschrift.
 *     @type string $accent  Zweiter Satz der Überschrift in Akzentfarbe.
 *     @type string $meta    Optional, rechtsbündige Zusatzinfo.
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="akwu-page-head">
	<div class="akwu-page-head__text">
		<p class="akwu-eyebrow"><?php echo esc_html( $data['eyebrow'] ); ?></p>
		<h1 id="akwu-title" class="akwu-title">
			<?php echo esc_html( $data['title'] ); ?>
			<?php if ( ! empty( $data['accent'] ) ) : ?>
				<span class="akwu-title__accent"><?php echo esc_html( $data['accent'] ); ?></span>
			<?php endif; ?>
		</h1>
	</div>
	<?php if ( ! empty( $data['meta'] ) ) : ?>
		<p class="akwu-page-head__meta"><?php echo esc_html( $data['meta'] ); ?></p>
	<?php endif; ?>
</div>
