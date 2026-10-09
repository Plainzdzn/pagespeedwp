<?php
/**
 * Rückgängig: alle oder einzelne Bilder zurück ins Original, solange die Originale existieren.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php, zusätzlich report und job.
 */

use Akuma\WebpUmwandler\Admin;
use Akuma\WebpUmwandler\Conversion;
use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\Icons;
use Akuma\WebpUmwandler\Job;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$report        = $data['report'];
$report_totals = null === $report ? null : $report->totals();
$converted     = null === $report_totals ? 0 : $report_totals['converted'];

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Rückgängig', 'akuma-webp-umwandler' ),
		'title'   => 0 === $converted
			? __( 'Nichts zurückzusetzen.', 'akuma-webp-umwandler' )
			/* translators: %s: Anzahl Bilder. */
			: sprintf( _n( '%s Bild umgewandelt.', '%s Bilder umgewandelt.', $converted, 'akuma-webp-umwandler' ), Format::number( $converted ) ),
		'accent'  => __( 'Rückgängig geht, solange die Originale existieren.', 'akuma-webp-umwandler' ),
	)
);

View::render( 'part-job-progress', array_merge( $data, array( 'type' => 'rollback' ) ) );

if ( $converted > 0 ) :
	$rollbackable = $report->rollbackable();
	$possible     = count( $rollbackable['rows'] );
	$blocked      = $rollbackable['blocked'];
	$job_open     = Job::is_active( $data['job'] ) || Conversion::is_active( $data['run'] );
	$entries      = array_values(
		array_filter(
			$report->entries( 'id' ),
			static function ( $entry ) {
				return 'done' === $entry['status'];
			}
		)
	);
	$list_page    = $data['query']['paged'];
	$list_size    = 50;
	$shown        = array_slice( $entries, ( $list_page - 1 ) * $list_size, $list_size );
	?>
	<div class="akwu-cta akwu-cta--plain">
		<div class="akwu-cta__text">
			<h2 class="akwu-cta__title"><?php esc_html_e( 'Alles zurücksetzen', 'akuma-webp-umwandler' ); ?></h2>
			<p>
				<?php
				esc_html_e( 'Stellt bei allen umgewandelten Bildern Datei, Metadaten und Verweise wieder her und löscht danach die WebP-Dateien. Die Bild-IDs bleiben dieselben. Danach werden Elementor-CSS und Cache erneuert.', 'akuma-webp-umwandler' );
				if ( $blocked ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %s: Anzahl Bilder. */
							_n( '%s Bild lässt sich nicht mehr zurücksetzen, der Grund steht in der Liste.', '%s Bilder lassen sich nicht mehr zurücksetzen, die Gründe stehen in der Liste.', count( $blocked ), 'akuma-webp-umwandler' ),
							Format::number( count( $blocked ) )
						)
					);
				}
				?>
			</p>
		</div>
		<div class="akwu-cta__actions">
			<button type="button" class="akwu-danger-button" data-akwu-job="rollback" data-akwu-confirm="<?php esc_attr_e( 'Alle umgewandelten Bilder zurück ins Original setzen?', 'akuma-webp-umwandler' ); ?>"<?php disabled( $job_open || 0 === $possible ); ?>>
				<?php Icons::render( 'undo', 15, '1.8' ); ?>
				<?php
				/* translators: %s: Anzahl Bilder. */
				echo esc_html( sprintf( _n( '%s Bild zurücksetzen', 'Alle %s Bilder zurücksetzen', $possible, 'akuma-webp-umwandler' ), Format::number( $possible ) ) );
				?>
			</button>
		</div>
	</div>

	<div class="akwu-card akwu-card--table">
		<div class="akwu-card__head akwu-card__head--padded">
			<h2 class="akwu-card__title"><?php esc_html_e( 'Einzeln zurücksetzen', 'akuma-webp-umwandler' ); ?></h2>
			<span class="akwu-small"><?php esc_html_e( 'Nach ID sortiert', 'akuma-webp-umwandler' ); ?></span>
		</div>
		<div class="akwu-table-wrap">
			<table class="akwu-table akwu-table--compact">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Datei', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ID', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Vorher', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Nachher', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Aktion', 'akuma-webp-umwandler' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shown as $entry ) : ?>
						<tr>
							<td>
								<span class="akwu-file"><?php echo esc_html( $entry['file'] ); ?></span>
								<span class="akwu-meta">
									<?php
									/* translators: %s: alter Dateiname. */
									echo esc_html( sprintf( __( 'vorher %s', 'akuma-webp-umwandler' ), wp_basename( $entry['old_file'] ) ) );
									?>
								</span>
							</td>
							<td><?php echo esc_html( (string) $entry['id'] ); ?></td>
							<td><?php echo esc_html( Format::bytes( $entry['before'] ) ); ?></td>
							<td><?php echo esc_html( Format::bytes( $entry['after'] ) ); ?></td>
							<td class="akwu-table__action">
								<?php if ( isset( $blocked[ $entry['id'] ] ) ) : ?>
									<span class="akwu-meta"><?php echo esc_html( $blocked[ $entry['id'] ] ); ?></span>
								<?php else : ?>
									<button type="button" class="akwu-link" data-akwu-job="rollback" data-akwu-ids="<?php echo esc_attr( (string) $entry['id'] ); ?>"<?php disabled( $job_open ); ?>><?php esc_html_e( 'Zurücksetzen', 'akuma-webp-umwandler' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( count( $entries ) > $list_size ) : ?>
			<?php
			View::render(
				'part-pagination',
				array(
					'pages'   => (int) ceil( count( $entries ) / $list_size ),
					'current' => $list_page,
					'base'    => Admin::page_url( 'akwu-rueckgaengig' ),
					'label'   => __( 'Seiten der Bildliste', 'akuma-webp-umwandler' ),
				)
			);
			?>
		<?php endif; ?>
	</div>
<?php endif; ?>

<div class="akwu-callout akwu-callout--warn">
	<p class="akwu-callout__title"><?php esc_html_e( 'Plugin löschen beendet die Möglichkeit zum Rückgängigmachen', 'akuma-webp-umwandler' ); ?></p>
	<p><?php esc_html_e( 'Beim Löschen des Plugins wird das Protokoll entfernt. Die umgewandelten Bilder bleiben, ein Rückgängig ist danach aber nicht mehr möglich.', 'akuma-webp-umwandler' ); ?></p>
</div>
