<?php
/**
 * Tabellenzeile für ein Bild (Übersicht und „Alle Bilder“).
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type array $item    Datensatz aus dem Scan.
 *     @type bool  $details Fundstellen ausklappbar anzeigen und Format-Spalte zeigen.
 * }
 */

use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\Inventory;
use Akuma\WebpUmwandler\Scan_Result;

defined( 'ABSPATH' ) || exit;

$item         = $data['item'];
$details      = ! empty( $data['details'] );
$thumb        = 'missing' === $item['status'] ? false : wp_get_attachment_image_url( $item['id'], 'thumbnail' );
$status_label = Scan_Result::status_label( $item );
$convertible  = isset( Inventory::CONVERTIBLE[ $item['mime'] ] );
$places       = array_merge( $item['places'], $item['warnings'] );
?>
<tr>
	<td class="akwu-table__thumb">
		<?php if ( $thumb ) : ?>
			<img class="akwu-thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" width="40" height="28" loading="lazy">
		<?php else : ?>
			<span class="akwu-thumb akwu-thumb--empty" aria-hidden="true"></span>
		<?php endif; ?>
	</td>
	<td>
		<span class="akwu-file" title="<?php echo esc_attr( wp_basename( $item['file'] ) ); ?>"><?php echo esc_html( wp_basename( $item['file'] ) ); ?></span>
		<span class="akwu-meta">
			<?php
			/* translators: %d: Attachment-ID. */
			echo esc_html( sprintf( __( 'ID %d', 'akuma-webp-umwandler' ), $item['id'] ) );
			if ( $item['thumbs'] > 0 ) {
				/* translators: %s: Anzahl Elementor-Vorschaubilder. */
				echo esc_html( ' · ' . sprintf( _n( '%s Elementor-Vorschaubild', '%s Elementor-Vorschaubilder', $item['thumbs'], 'akuma-webp-umwandler' ), Format::number( $item['thumbs'] ) ) );
			}
			?>
		</span>
	</td>
	<?php if ( $details ) : ?>
		<td><?php echo esc_html( Scan_Result::kind_label( $item['kind'] ) ); ?></td>
	<?php endif; ?>
	<td class="akwu-nowrap"><?php echo esc_html( Format::bytes( $item['bytes'] ) ); ?></td>
	<td class="akwu-nowrap">
		<?php if ( $convertible && 'ready' === $item['status'] ) : ?>
			<span class="akwu-estimate"><?php echo esc_html( '≈ ' . Format::bytes( $item['estimate'] ) ); ?></span>
		<?php elseif ( $convertible && 'skip' === $item['status'] ) : ?>
			<span class="akwu-meta-inline">
				<?php
				$saving = (int) round( ( 1 - $item['ratio'] ) * 100 );
				echo esc_html(
					$saving > 0
						/* translators: %s: Ersparnis in Prozent. */
						? sprintf( __( 'nur %s kleiner', 'akuma-webp-umwandler' ), Format::number( $saving ) . Format::NBSP . '%' )
						: __( 'nicht kleiner', 'akuma-webp-umwandler' )
				);
				?>
			</span>
		<?php else : ?>
			<span class="akwu-meta-inline">–</span>
		<?php endif; ?>
	</td>
	<td>
		<?php if ( $details && ! empty( $places ) ) : ?>
			<details class="akwu-places">
				<summary><?php echo esc_html( Scan_Result::usage_label( $item ) ); ?></summary>
				<ul>
					<?php foreach ( $places as $place ) : ?>
						<?php $place_link = Scan_Result::place_link( $place ); ?>
						<li>
							<?php if ( '' !== $place_link ) : ?>
								<a href="<?php echo esc_url( $place_link ); ?>"><?php echo esc_html( $place['label'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $place['label'] ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $place['warning'] ) ) : ?>
								<span class="akwu-pill akwu-pill--warn"><?php echo esc_html( Scan_Result::warning_label( $place['warning'] ) ); ?></span>
							<?php elseif ( 'background' === $place['context'] ) : ?>
								<span class="akwu-meta-inline"><?php esc_html_e( 'Hintergrund', 'akuma-webp-umwandler' ); ?></span>
							<?php elseif ( 'featured' === $place['context'] ) : ?>
								<span class="akwu-meta-inline"><?php esc_html_e( 'Beitragsbild', 'akuma-webp-umwandler' ); ?></span>
							<?php elseif ( 'unknown_size' === $place['context'] ) : ?>
								<span class="akwu-meta-inline"><?php esc_html_e( 'unbekannte Größe', 'akuma-webp-umwandler' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
					<?php if ( count( $item['places'] ) < $item['uses'] && count( $item['places'] ) >= 25 ) : ?>
						<li class="akwu-meta-inline"><?php esc_html_e( 'und weitere', 'akuma-webp-umwandler' ); ?></li>
					<?php endif; ?>
				</ul>
			</details>
		<?php else : ?>
			<?php echo esc_html( Scan_Result::usage_label( $item ) ); ?>
		<?php endif; ?>
	</td>
	<td><span class="akwu-pill akwu-pill--<?php echo esc_attr( $status_label[1] ); ?>"><?php echo esc_html( $status_label[0] ); ?></span></td>
</tr>
