<?php
/**
 * Seitenzahlen für lange Listen.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type int    $pages   Anzahl Seiten.
 *     @type int    $current Aktuelle Seite.
 *     @type string $base    URL ohne paged.
 *     @type string $label   Beschriftung für Screenreader.
 * }
 */

defined( 'ABSPATH' ) || exit;

if ( $data['pages'] < 2 ) {
	return;
}
?>
<nav class="akwu-pagination akwu-card__body" aria-label="<?php echo esc_attr( $data['label'] ); ?>">
	<?php for ( $number = 1; $number <= $data['pages']; $number++ ) : ?>
		<?php if ( $number === $data['current'] ) : ?>
			<span class="akwu-pagination__item is-current" aria-current="page"><?php echo esc_html( (string) $number ); ?></span>
		<?php else : ?>
			<a class="akwu-pagination__item" href="<?php echo esc_url( add_query_arg( 'paged', $number, $data['base'] ) ); ?>"><?php echo esc_html( (string) $number ); ?></a>
		<?php endif; ?>
	<?php endfor; ?>
</nav>
