<?php
/**
 * Alle Bilder: Liste mit Filter, Größe, Schätzung, Fundstellen und Status.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php, zusätzlich scan, scan_state, query.
 */

use Akuma\WebpUmwandler\Admin;
use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\Icons;
use Akuma\WebpUmwandler\Scan_Result;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$result        = $data['scan'];
$query         = $data['query'];
$rows_per_page = 50;

if ( null === $result ) {
	View::render(
		'part-page-head',
		array(
			'eyebrow' => __( 'Alle Bilder', 'akuma-webp-umwandler' ),
			'title'   => __( 'Noch keine Bildliste.', 'akuma-webp-umwandler' ),
			'accent'  => __( 'Sie entsteht beim Scan.', 'akuma-webp-umwandler' ),
		)
	);
	View::render( 'part-scan-progress', array( 'state' => $data['scan_state'] ) );
	?>
	<div class="akwu-cta">
		<div class="akwu-cta__text">
			<h2 class="akwu-cta__title"><?php esc_html_e( 'Bestand scannen', 'akuma-webp-umwandler' ); ?></h2>
			<p><?php esc_html_e( 'Der Scan erfasst alle Bilder, ihre Größen und wo sie verwendet werden. Er verändert nichts.', 'akuma-webp-umwandler' ); ?></p>
		</div>
		<div class="akwu-cta__actions">
			<button type="button" class="akwu-button" data-akwu-scan data-akwu-restart="1">
				<?php esc_html_e( 'Scan starten', 'akuma-webp-umwandler' ); ?>
				<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
			</button>
		</div>
	</div>
	<?php
	return;
}

$counts       = $result->counts();
$items        = $result->items( $query['filter'], $query['sort'] );
$total        = count( $items );
$page_count   = max( 1, (int) ceil( $total / $rows_per_page ) );
$current_page = min( $query['paged'], $page_count );
$visible      = array_slice( $items, ( $current_page - 1 ) * $rows_per_page, $rows_per_page );
$base         = Admin::page_url( 'akwu-bilder' );

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Alle Bilder', 'akuma-webp-umwandler' ),
		/* translators: %s: Anzahl Bilder. */
		'title'   => sprintf( _n( '%s Bild.', '%s Bilder.', $counts['all'], 'akuma-webp-umwandler' ), Format::number( $counts['all'] ) ),
		/* translators: %s: Anzahl Bilder. */
		'accent'  => sprintf( _n( '%s davon bereit zur Umwandlung.', '%s davon bereit zur Umwandlung.', $counts['ready'], 'akuma-webp-umwandler' ), Format::number( $counts['ready'] ) ),
		/* translators: %s: Zeitpunkt, z. B. „heute, 16:52“. */
		'meta'    => sprintf( __( 'Letzter Scan: %s', 'akuma-webp-umwandler' ), $result->finished_label() ),
	)
);
?>
<nav class="akwu-tabs" aria-label="<?php esc_attr_e( 'Bilder filtern', 'akuma-webp-umwandler' ); ?>">
	<?php foreach ( Scan_Result::filters() as $key => $label ) : ?>
		<?php $is_current = ( $key === $query['filter'] ); ?>
		<a class="akwu-tab<?php echo $is_current ? ' is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'filter', $key, $base ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>>
			<?php echo esc_html( $label ); ?>
			<span class="akwu-tab__count"><?php echo esc_html( Format::number( $counts[ $key ] ) ); ?></span>
		</a>
	<?php endforeach; ?>
</nav>

<div class="akwu-card akwu-card--table">
	<?php if ( empty( $visible ) ) : ?>
		<p class="akwu-empty akwu-card__body"><?php esc_html_e( 'Keine Bilder in dieser Auswahl.', 'akuma-webp-umwandler' ); ?></p>
	<?php else : ?>
		<div class="akwu-table-wrap">
			<table class="akwu-table">
				<thead>
					<tr>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Vorschau', 'akuma-webp-umwandler' ); ?></span></th>
						<th scope="col"><a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'filter' => $query['filter'],
									'sort'   => 'name',
								),
								$base
							)
						);
						?>
													"><?php esc_html_e( 'Datei', 'akuma-webp-umwandler' ); ?></a></th>
						<th scope="col"><?php esc_html_e( 'Format', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'filter' => $query['filter'],
									'sort'   => 'bytes',
								),
								$base
							)
						);
						?>
													"><?php esc_html_e( 'Heute', 'akuma-webp-umwandler' ); ?></a></th>
						<th scope="col"><?php esc_html_e( 'Als WebP', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Verwendet', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'akuma-webp-umwandler' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $visible as $item ) : ?>
						<?php
						View::render(
							'part-image-row',
							array(
								'item'    => $item,
								'details' => true,
							)
						);
						?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

<?php if ( $page_count > 1 ) : ?>
	<nav class="akwu-pagination" aria-label="<?php esc_attr_e( 'Seiten der Bildliste', 'akuma-webp-umwandler' ); ?>">
		<?php for ( $number = 1; $number <= $page_count; $number++ ) : ?>
			<?php if ( $number === $current_page ) : ?>
				<span class="akwu-pagination__item is-current" aria-current="page"><?php echo esc_html( (string) $number ); ?></span>
			<?php else : ?>
				<a class="akwu-pagination__item" href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'filter' => $query['filter'],
							'sort'   => $query['sort'],
							'paged'  => $number,
						),
						$base
					)
				);
				?>
														"><?php echo esc_html( (string) $number ); ?></a>
			<?php endif; ?>
		<?php endfor; ?>
	</nav>
<?php endif; ?>

<p class="akwu-small"><?php esc_html_e( '„Als WebP“ ist hochgerechnet aus einer Stichprobe. Fundstellen mit Hinweis werden nicht automatisch ersetzt und sind danach von Hand zu prüfen.', 'akuma-webp-umwandler' ); ?></p>
