<?php
/**
 * Kachel „PageSpeed mobil“ im Bericht: vorher → nachher, optional mit API-Schlüssel.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\Admin;
use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\PageSpeed;

defined( 'ABSPATH' ) || exit;

$speed_before = PageSpeed::result( 'before' );
$speed_after  = PageSpeed::result( 'after' );
?>
<div>
	<span class="akwu-caption"><?php esc_html_e( 'PageSpeed mobil', 'akuma-webp-umwandler' ); ?></span>
	<?php if ( ! PageSpeed::enabled() && null === $speed_after ) : ?>
		<span class="akwu-num akwu-report-stats__value">–</span>
		<a class="akwu-small" href="<?php echo esc_url( Admin::page_url( 'akwu-einstellungen' ) ); ?>"><?php esc_html_e( 'Optional, mit API-Schlüssel', 'akuma-webp-umwandler' ); ?></a>
	<?php else : ?>
		<span class="akwu-num akwu-report-stats__value">
			<?php echo esc_html( ( null === $speed_before ? '–' : $speed_before['score'] ) . ' → ' ); ?><span class="akwu-accent"><?php echo esc_html( null === $speed_after ? '–' : (string) $speed_after['score'] ); ?></span>
		</span>
		<span class="akwu-small">
			<?php
			if ( null !== $speed_after && '' !== $speed_after['lcp'] ) {
				/* translators: 1: LCP vorher, 2: LCP nachher. */
				echo esc_html( sprintf( __( 'Startseite · LCP %1$s → %2$s', 'akuma-webp-umwandler' ), null === $speed_before || '' === $speed_before['lcp'] ? '–' : $speed_before['lcp'], $speed_after['lcp'] ) );
			} else {
				esc_html_e( 'Startseite', 'akuma-webp-umwandler' );
			}
			?>
		</span>
		<?php if ( PageSpeed::enabled() ) : ?>
			<button type="button" class="akwu-link akwu-link--inline" data-akwu-pagespeed="after">
				<?php echo esc_html( null === $speed_after ? __( 'Nachher messen', 'akuma-webp-umwandler' ) : __( 'Neu messen', 'akuma-webp-umwandler' ) ); ?>
			</button>
		<?php endif; ?>
		<?php if ( null !== $speed_after && $speed_after['weight'] > 0 ) : ?>
			<span class="screen-reader-text">
				<?php
				/* translators: %s: Seitengewicht. */
				echo esc_html( sprintf( __( 'Seitengewicht nachher %s', 'akuma-webp-umwandler' ), Format::bytes( $speed_after['weight'] ) ) );
				?>
			</span>
		<?php endif; ?>
	<?php endif; ?>
</div>
