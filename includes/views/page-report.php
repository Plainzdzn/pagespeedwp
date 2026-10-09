<?php
/**
 * Bericht: vorher/nachher, Kennzahlen, Bilder mit der größten Ersparnis, Stellen zum Prüfen,
 * Originale löschen. Gilt für alle Läufe zusammen.
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
use Akuma\WebpUmwandler\Report;
use Akuma\WebpUmwandler\Scan_Result;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$report = $data['report'];

if ( null === $report ) {
	View::render(
		'part-page-head',
		array(
			'eyebrow' => __( 'Bericht', 'akuma-webp-umwandler' ),
			'title'   => __( 'Noch kein Bericht.', 'akuma-webp-umwandler' ),
			'accent'  => __( 'Er entsteht nach der ersten Umwandlung.', 'akuma-webp-umwandler' ),
		)
	);
	?>
	<div class="akwu-cta">
		<div class="akwu-cta__text">
			<h2 class="akwu-cta__title"><?php esc_html_e( 'Erst scannen, dann umwandeln', 'akuma-webp-umwandler' ); ?></h2>
			<p><?php esc_html_e( 'Der Bericht zeigt danach vorher und nachher, jedes Bild, die Stellen zum Prüfen und den CSV-Export.', 'akuma-webp-umwandler' ); ?></p>
		</div>
		<div class="akwu-cta__actions">
			<a class="akwu-button" href="<?php echo esc_url( Admin::page_url( Admin::MENU_SLUG ) ); ?>">
				<?php esc_html_e( 'Zur Übersicht', 'akuma-webp-umwandler' ); ?>
				<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
			</a>
		</div>
	</div>
	<?php
	return;
}

$report_totals = $report->totals();
$show_all      = isset( $_GET['alle'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Anzeige.
$entries       = $report->entries( 'saved' );
$leftovers     = $report->leftovers();
$purgeable     = $report->purgeable();
$changed       = $report->last_change();
$job           = $data['job'];
$job_open      = Job::is_active( $job ) || Conversion::is_active( $data['run'] );

$list_page   = $data['query']['paged'];
$list_size   = 100;
$entry_count = count( $entries );
$shown       = $show_all ? array_slice( $entries, ( $list_page - 1 ) * $list_size, $list_size ) : array_slice( $entries, 0, 5 );

View::render(
	'part-page-head',
	array(
		'eyebrow' => $changed > 0
			/* translators: %s: Datum und Uhrzeit. */
			? sprintf( __( 'Bericht · %s', 'akuma-webp-umwandler' ), Format::time_label( $changed ) )
			: __( 'Bericht', 'akuma-webp-umwandler' ),
		'title'   => $report_totals['converted'] > 0 ? __( 'Fertig.', 'akuma-webp-umwandler' ) : __( 'Nichts umgewandelt.', 'akuma-webp-umwandler' ),
		'accent'  => $report_totals['converted'] > 0
			/* translators: %s: eingesparte Größe. */
			? sprintf( __( '%s weniger Ladegewicht.', 'akuma-webp-umwandler' ), Format::bytes( $report_totals['saved'] ) )
			: __( 'Alle Bilder sind wieder im Original.', 'akuma-webp-umwandler' ),
	)
);

$after_width = $report_totals['before'] > 0 ? max( 1, (int) round( 100 * $report_totals['after'] / $report_totals['before'] ) ) : 0;
?>
<div class="akwu-card akwu-report-summary">
	<div class="akwu-compare">
		<span class="akwu-caption"><?php esc_html_e( 'Vorher', 'akuma-webp-umwandler' ); ?></span>
		<div class="akwu-compare__bar akwu-compare__bar--before"><?php echo esc_html( Format::bytes( $report_totals['before'] ) ); ?></div>
		<span class="akwu-caption akwu-compare__after-label"><?php esc_html_e( 'Nachher', 'akuma-webp-umwandler' ); ?></span>
		<div class="akwu-compare__row">
			<div class="akwu-compare__bar akwu-compare__bar--after" style="width: <?php echo esc_attr( (string) $after_width ); ?>%"><?php echo esc_html( Format::bytes( $report_totals['after'] ) ); ?></div>
			<?php if ( $report_totals['percent'] > 0 ) : ?>
				<span class="akwu-pill akwu-pill--ok"><?php echo esc_html( '−' . Format::number( $report_totals['percent'] ) . ' %' ); ?></span>
			<?php endif; ?>
		</div>
	</div>
	<div class="akwu-report-stats">
		<div>
			<span class="akwu-caption"><?php esc_html_e( 'Umgewandelt', 'akuma-webp-umwandler' ); ?></span>
			<span class="akwu-num akwu-report-stats__value"><?php echo esc_html( Format::number( $report_totals['converted'] ) ); ?></span>
			<?php if ( $report_totals['rolled_back'] > 0 ) : ?>
				<span class="akwu-small">
					<?php
					/* translators: %s: Anzahl. */
					echo esc_html( sprintf( __( '%s zurückgesetzt', 'akuma-webp-umwandler' ), Format::number( $report_totals['rolled_back'] ) ) );
					?>
				</span>
			<?php endif; ?>
		</div>
		<div>
			<span class="akwu-caption"><?php esc_html_e( 'Übersprungen', 'akuma-webp-umwandler' ); ?></span>
			<span class="akwu-num akwu-report-stats__value"><?php echo esc_html( Format::number( $report_totals['skipped'] + $report_totals['errors'] ) ); ?></span>
			<?php if ( $report_totals['errors'] > 0 ) : ?>
				<span class="akwu-small">
					<?php
					/* translators: %s: Anzahl. */
					echo esc_html( sprintf( _n( 'davon %s mit Fehler', 'davon %s mit Fehler', $report_totals['errors'], 'akuma-webp-umwandler' ), Format::number( $report_totals['errors'] ) ) );
					?>
				</span>
			<?php endif; ?>
		</div>
		<div>
			<span class="akwu-caption"><?php esc_html_e( 'Verweise ersetzt', 'akuma-webp-umwandler' ); ?></span>
			<span class="akwu-num akwu-report-stats__value"><?php echo esc_html( Format::number( $report_totals['replacements'] ) ); ?></span>
			<span class="akwu-small">
				<?php
				/* translators: %s: Anzahl Seiten und Beiträge. */
				echo esc_html( sprintf( _n( 'in %s Seite oder Beitrag', 'in %s Seiten und Beiträgen', $report_totals['places'], 'akuma-webp-umwandler' ), Format::number( $report_totals['places'] ) ) );
				?>
			</span>
		</div>
		<?php View::render( 'part-pagespeed-tile', $data ); ?>
	</div>
</div>

<div class="akwu-columns akwu-columns--wide-main">
	<div class="akwu-card akwu-columns__main akwu-card--table">
		<div class="akwu-card__head akwu-card__head--padded">
			<h2 class="akwu-card__title"><?php echo esc_html( $show_all ? __( 'Alle Bilder', 'akuma-webp-umwandler' ) : __( 'Größte Ersparnis', 'akuma-webp-umwandler' ) ); ?></h2>
			<?php if ( $show_all ) : ?>
				<a class="akwu-link" href="<?php echo esc_url( Admin::page_url( 'akwu-bericht' ) ); ?>"><?php esc_html_e( 'Weniger anzeigen', 'akuma-webp-umwandler' ); ?></a>
			<?php elseif ( $entry_count > count( $shown ) ) : ?>
				<a class="akwu-link" href="<?php echo esc_url( add_query_arg( 'alle', '1', Admin::page_url( 'akwu-bericht' ) ) ); ?>">
					<?php
					/* translators: %s: Anzahl Bilder. */
					echo esc_html( sprintf( __( 'Alle %s anzeigen', 'akuma-webp-umwandler' ), Format::number( $entry_count ) ) );
					?>
				</a>
			<?php endif; ?>
		</div>
		<div class="akwu-table-wrap">
			<table class="akwu-table akwu-table--compact">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Datei', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ID', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Vorher', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Nachher', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Ergebnis', 'akuma-webp-umwandler' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shown as $entry ) : ?>
						<?php $is_done = 'done' === $entry['status']; ?>
						<tr<?php echo $is_done ? '' : ' class="is-muted"'; ?>>
							<td>
								<span class="akwu-file"><?php echo esc_html( $entry['file'] ); ?></span>
								<?php if ( ! $is_done && '' !== $entry['message'] ) : ?>
									<span class="akwu-meta"><?php echo esc_html( $entry['message'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $entry['id'] ); ?></td>
							<td><?php echo esc_html( Format::bytes( $entry['before'] ) ); ?></td>
							<td><?php echo esc_html( $is_done ? Format::bytes( $entry['after'] ) : __( 'unverändert', 'akuma-webp-umwandler' ) ); ?></td>
							<td>
								<?php if ( $is_done ) : ?>
									<span class="akwu-pill akwu-pill--ok"><?php echo esc_html( '−' . Format::number( $entry['percent'] ) . ' %' ); ?></span>
								<?php else : ?>
									<span class="akwu-pill akwu-pill--<?php echo 'error' === $entry['status'] ? 'warn' : 'info'; ?>"><?php echo esc_html( mb_strtolower( Report::status_label( $entry['status'] ) ) ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $show_all && $entry_count > $list_size ) : ?>
			<?php
			View::render(
				'part-pagination',
				array(
					'pages'   => (int) ceil( $entry_count / $list_size ),
					'current' => $list_page,
					'base'    => add_query_arg( 'alle', '1', Admin::page_url( 'akwu-bericht' ) ),
					'label'   => __( 'Seiten der Bildliste', 'akuma-webp-umwandler' ),
				)
			);
			?>
		<?php endif; ?>
	</div>

	<div class="akwu-card akwu-columns__side">
		<h2 class="akwu-card__title"><?php esc_html_e( 'Bitte prüfen', 'akuma-webp-umwandler' ); ?></h2>
		<?php if ( empty( $leftovers ) ) : ?>
			<p class="akwu-small"><?php esc_html_e( 'Keine alten Adressen mehr gefunden. Alles zeigt auf WebP.', 'akuma-webp-umwandler' ); ?></p>
		<?php else : ?>
			<p class="akwu-small"><?php esc_html_e( 'Hier lädt vorerst noch das Original. Das Plugin ändert diese Stellen nicht, bitte von Hand anpassen.', 'akuma-webp-umwandler' ); ?></p>
			<ul class="akwu-rest">
				<?php foreach ( $leftovers as $hit ) : ?>
					<?php $place_link = Scan_Result::place_link( $hit ); ?>
					<li class="akwu-rest__item">
						<span class="akwu-pill akwu-pill--<?php echo null === $hit['warning'] ? 'error' : 'warn'; ?>" aria-hidden="true">!</span>
						<span class="akwu-rest__text">
							<?php echo esc_html( $hit['file'] ); ?>
							<span class="akwu-meta"><?php echo esc_html( null === $hit['warning'] ? $hit['label'] . ' · ' . __( 'nicht ersetzt', 'akuma-webp-umwandler' ) : Scan_Result::warning_label( $hit['warning'] ) . ' · ' . $hit['label'] ); ?></span>
						</span>
						<?php if ( '' !== $place_link ) : ?>
							<a class="akwu-link" href="<?php echo esc_url( $place_link ); ?>"><?php esc_html_e( 'Öffnen', 'akuma-webp-umwandler' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>

<?php View::render( 'part-job-progress', array_merge( $data, array( 'type' => 'purge' ) ) ); ?>

<?php if ( $report_totals['converted'] > 0 ) : ?>
	<div class="akwu-callout akwu-callout--warn akwu-purge">
		<div class="akwu-purge__text">
			<p class="akwu-callout__title"><?php esc_html_e( 'Originale vom Server löschen', 'akuma-webp-umwandler' ); ?></p>
			<p class="akwu-small">
				<?php
				if ( $purgeable['count'] > 0 ) {
					/* translators: %s: Speicher, z. B. „38,4 MB“. */
					echo esc_html( sprintf( __( 'Gibt %s Speicher frei. Erst nach einigen Tagen ohne Auffälligkeiten, danach ist kein Rückgängig mehr möglich.', 'akuma-webp-umwandler' ), Format::bytes( $purgeable['bytes'] ) ) );
				} elseif ( $report_totals['purged'] > 0 ) {
					esc_html_e( 'Die Originale der umgewandelten Bilder sind gelöscht.', 'akuma-webp-umwandler' );
				} else {
					esc_html_e( 'Gerade gibt es keine Originale, die gelöscht werden können.', 'akuma-webp-umwandler' );
				}
				if ( $purgeable['kept'] > 0 ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %s: Anzahl Bilder. */
							_n( '%s Original bleibt, weil seine alte Adresse noch unter „Bitte prüfen“ steht.', '%s Originale bleiben, weil ihre alte Adresse noch unter „Bitte prüfen“ steht.', $purgeable['kept'], 'akuma-webp-umwandler' ),
							Format::number( $purgeable['kept'] )
						)
					);
				}
				?>
			</p>
		</div>
		<?php if ( $purgeable['count'] > 0 ) : ?>
			<div class="akwu-purge__actions">
				<button type="button" class="akwu-danger-button" data-akwu-purge-open<?php disabled( $job_open ); ?>>
					<?php Icons::render( 'trash', 15, '1.8' ); ?>
					<?php
					/* translators: %s: Anzahl Bilder. */
					echo esc_html( sprintf( _n( 'Original von %s Bild löschen', 'Originale von %s Bildern löschen', $purgeable['count'], 'akuma-webp-umwandler' ), Format::number( $purgeable['count'] ) ) );
					?>
				</button>
			</div>
			<form class="akwu-purge__confirm" data-akwu-purge-form hidden>
				<label for="akwu-purge-confirm">
					<?php
					/* translators: %s: Anzahl Bilder. */
					echo esc_html( sprintf( __( 'Zum Bestätigen die Zahl %s eintippen', 'akuma-webp-umwandler' ), $purgeable['count'] ) );
					?>
				</label>
				<input type="text" id="akwu-purge-confirm" inputmode="numeric" autocomplete="off" class="akwu-input" data-akwu-purge-input>
				<button type="submit" class="akwu-danger-button"><?php esc_html_e( 'Endgültig löschen', 'akuma-webp-umwandler' ); ?></button>
				<button type="button" class="akwu-link" data-akwu-purge-close><?php esc_html_e( 'Abbrechen', 'akuma-webp-umwandler' ); ?></button>
				<p class="akwu-progress__error akwu-purge__error" data-akwu-purge-error role="alert" hidden></p>
			</form>
		<?php endif; ?>
	</div>
<?php endif; ?>
