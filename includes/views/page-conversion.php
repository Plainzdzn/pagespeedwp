<?php
/**
 * Umwandlung: Fortschritt, Ablauf, Live-Protokoll und nach dem Lauf die Gegenprobe.
 *
 * Drei Zustände: noch kein Lauf, Lauf offen (läuft, pausiert, wird zurückgesetzt), Lauf beendet.
 * Während eines offenen Laufs fordert admin.js die Pakete an und tauscht die Inhalte aus.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\Admin;
use Akuma\WebpUmwandler\Cache_Purger;
use Akuma\WebpUmwandler\Conversion;
use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\Icons;
use Akuma\WebpUmwandler\Log_Table;
use Akuma\WebpUmwandler\Run_Presenter;
use Akuma\WebpUmwandler\Scan_Result;
use Akuma\WebpUmwandler\System_Check;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$run = $data['run'];

if ( null === $run ) {
	View::render(
		'part-page-head',
		array(
			'eyebrow' => __( 'Umwandlung', 'akuma-webp-umwandler' ),
			'title'   => __( 'Noch keine Umwandlung.', 'akuma-webp-umwandler' ),
			'accent'  => null === $data['scan'] ? __( 'Zuerst den Bestand scannen.', 'akuma-webp-umwandler' ) : __( 'Start auf der Übersicht.', 'akuma-webp-umwandler' ),
		)
	);
	?>
	<div class="akwu-cta">
		<div class="akwu-cta__text">
			<h2 class="akwu-cta__title"><?php esc_html_e( 'So läuft die Umwandlung', 'akuma-webp-umwandler' ); ?></h2>
			<p><?php esc_html_e( 'Die Bilder werden in Paketen umgewandelt, jedes behält seine ID. Verweise in Seiten, Elementor und Einstellungen werden je Paket mitgezogen, danach Elementor-CSS und Cache erneuert. Zum Schluss sucht eine Gegenprobe nach übrig gebliebenen alten Adressen.', 'akuma-webp-umwandler' ); ?></p>
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

$payload  = Run_Presenter::payload( $run );
$finished = $payload['finished'];
$tiles    = array(
	'saved'       => __( 'Eingespart', 'akuma-webp-umwandler' ),
	'converted'   => __( 'Umgewandelt', 'akuma-webp-umwandler' ),
	'skipped'     => __( 'Übersprungen', 'akuma-webp-umwandler' ),
	'ids_changed' => __( 'Bild-IDs verändert', 'akuma-webp-umwandler' ),
);
?>
<div class="akwu-run" data-akwu-run data-akwu-run-status="<?php echo esc_attr( $run['status'] ); ?>">
	<div class="akwu-page-head">
		<div class="akwu-page-head__text">
			<p class="akwu-eyebrow" data-akwu-run-field="eyebrow"><?php echo esc_html( $payload['eyebrow'] ); ?></p>
			<h1 id="akwu-title" class="akwu-title">
				<span data-akwu-run-field="headline"><?php echo esc_html( $payload['headline'] ); ?></span>
				<span class="akwu-title__accent" data-akwu-run-field="accent"><?php echo esc_html( $payload['accent'] ); ?></span>
			</h1>
		</div>
		<?php if ( $finished && $run['finished'] > 0 ) : ?>
			<p class="akwu-page-head__meta">
				<?php
				/* translators: %s: Datum und Uhrzeit. */
				echo esc_html( sprintf( __( 'Beendet: %s', 'akuma-webp-umwandler' ), wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $run['finished'] ) ) );
				?>
			</p>
		<?php endif; ?>
	</div>

	<div class="akwu-progress">
		<div class="akwu-progress__track" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $payload['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Fortschritt der Umwandlung', 'akuma-webp-umwandler' ); ?>">
			<div class="akwu-progress__fill" style="width: <?php echo esc_attr( (string) $payload['percent'] ); ?>%"></div>
		</div>
		<div class="akwu-progress__meta">
			<span data-akwu-run-field="batch_label" aria-live="polite"><?php echo esc_html( $payload['batch_label'] ); ?></span>
			<span class="akwu-num" data-akwu-run-field="percent"><?php echo esc_html( $payload['percent'] . ' %' ); ?></span>
		</div>
		<p class="akwu-progress__error" data-akwu-run-error role="alert" hidden></p>
	</div>

	<div class="akwu-tiles">
		<?php foreach ( $tiles as $key => $label ) : ?>
			<div class="akwu-tile<?php echo 'saved' === $key ? ' akwu-tile--highlight' : ''; ?>">
				<?php echo esc_html( $label ); ?>
				<span class="akwu-num akwu-tile__value" data-akwu-run-tile="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $payload['tiles'][ $key ] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="akwu-columns">
		<div class="akwu-card akwu-columns__side">
			<h2 class="akwu-card__title"><?php esc_html_e( 'Ablauf', 'akuma-webp-umwandler' ); ?></h2>
			<div data-akwu-run-html="steps_html">
				<?php View::render( 'part-run-steps', array( 'steps' => Run_Presenter::steps( $run ) ) ); ?>
			</div>
		</div>

		<div class="akwu-card akwu-columns__main">
			<div class="akwu-card__head">
				<h2 class="akwu-card__title"><?php esc_html_e( 'Live-Protokoll', 'akuma-webp-umwandler' ); ?></h2>
				<span class="akwu-small"><?php esc_html_e( 'Neueste zuerst', 'akuma-webp-umwandler' ); ?></span>
			</div>
			<div data-akwu-run-html="log_html">
				<?php View::render( 'part-run-log', array( 'entries' => Conversion::recent( $run ) ) ); ?>
			</div>
		</div>
	</div>
</div>

<?php
if ( ! $finished ) {
	return;
}

// Nach dem Lauf: was erneuert wurde und was die Gegenprobe gefunden hat.
// Die Gegenprobe deckt alle umgewandelten Bilder ab, auch aus früheren Läufen.
$old_files = array();
$left_ids  = array_map( 'intval', wp_list_pluck( $run['leftovers'], 'attachment' ) );
foreach ( $left_ids ? Log_Table::latest_by_attachment( $left_ids ) : array() as $attachment_id => $row ) {
	$old_files[ $attachment_id ] = wp_basename( (string) $row['old_file'] );
}

$unexpected = array();
$to_check   = array();
foreach ( $run['leftovers'] as $hit ) {
	if ( null === $hit['warning'] ) {
		$unexpected[] = $hit;
	} else {
		$to_check[] = $hit;
	}
}
$leftovers = array_merge( $unexpected, $to_check );
?>
<div class="akwu-columns">
	<div class="akwu-card akwu-columns__side akwu-checklist">
		<h2 class="akwu-card__title"><?php esc_html_e( 'Erneuert', 'akuma-webp-umwandler' ); ?></h2>
		<div class="akwu-check">
			<?php View::status_pill( $run['elementor'] ? System_Check::OK : System_Check::INFO ); ?>
			<div>
				<?php echo esc_html( $run['elementor'] ? __( 'Elementor-CSS neu erzeugt', 'akuma-webp-umwandler' ) : __( 'Elementor nicht aktiv', 'akuma-webp-umwandler' ) ); ?>
				<span class="akwu-meta"><?php esc_html_e( 'Elementor baut die CSS-Dateien beim nächsten Seitenaufruf neu.', 'akuma-webp-umwandler' ); ?></span>
			</div>
		</div>
		<div class="akwu-check">
			<?php View::status_pill( System_Check::OK ); ?>
			<div>
				<?php esc_html_e( 'Cache geleert', 'akuma-webp-umwandler' ); ?>
				<span class="akwu-meta"><?php echo esc_html( implode( ', ', (array) $run['purged'] ) ); ?></span>
			</div>
		</div>
		<?php if ( Cache_Purger::needs_raidboxes_hint() ) : ?>
			<div class="akwu-check">
				<?php View::status_pill( System_Check::WARN ); ?>
				<div>
					<?php esc_html_e( 'Raidboxes-Cache von Hand leeren', 'akuma-webp-umwandler' ); ?>
					<span class="akwu-meta"><?php esc_html_e( 'Im Raidboxes-Dashboard unter Cache. Das Plugin kann ihn ohne FastPixel nicht selbst leeren.', 'akuma-webp-umwandler' ); ?></span>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<div class="akwu-card akwu-columns__main akwu-card--table">
		<div class="akwu-card__head akwu-card__head--padded">
			<h2 class="akwu-card__title"><?php esc_html_e( 'Gegenprobe', 'akuma-webp-umwandler' ); ?></h2>
			<?php if ( 'done' === $run['status'] ) : ?>
				<span class="akwu-small">
					<?php
					if ( empty( $leftovers ) ) {
						esc_html_e( 'Keine alten Adressen mehr gefunden', 'akuma-webp-umwandler' );
					} else {
						/* translators: %s: Anzahl Fundstellen. */
						echo esc_html( sprintf( _n( '%s Fundstelle', '%s Fundstellen', count( $leftovers ), 'akuma-webp-umwandler' ), Format::number( count( $leftovers ) ) ) );
					}
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php if ( 'done' !== $run['status'] ) : ?>
			<p class="akwu-empty akwu-card__body"><?php esc_html_e( 'Der Lauf wurde abgebrochen, alle Bilder dieses Laufs sind wieder im Original. Keine Gegenprobe nötig.', 'akuma-webp-umwandler' ); ?></p>
		<?php elseif ( empty( $leftovers ) ) : ?>
			<p class="akwu-empty akwu-card__body"><?php esc_html_e( 'Alle Verweise auf die umgewandelten Bilder zeigen jetzt auf WebP. Die Originale bleiben liegen, bis sie im Bericht gelöscht werden.', 'akuma-webp-umwandler' ); ?></p>
		<?php else : ?>
			<div class="akwu-table-wrap">
				<table class="akwu-table akwu-table--compact akwu-table--fluid">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Bild', 'akuma-webp-umwandler' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Fundstelle', 'akuma-webp-umwandler' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'akuma-webp-umwandler' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $leftovers as $hit ) : ?>
							<?php $place_link = Scan_Result::place_link( $hit ); ?>
							<tr>
								<td>
									<span class="akwu-file"><?php echo esc_html( isset( $old_files[ $hit['attachment'] ] ) ? $old_files[ $hit['attachment'] ] : '#' . $hit['attachment'] ); ?></span>
									<span class="akwu-meta">
										<?php
										/* translators: %d: Attachment-ID. */
										echo esc_html( sprintf( __( 'ID %d', 'akuma-webp-umwandler' ), $hit['attachment'] ) );
										?>
									</span>
								</td>
								<td>
									<?php if ( '' !== $place_link ) : ?>
										<a href="<?php echo esc_url( $place_link ); ?>"><?php echo esc_html( $hit['label'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $hit['label'] ); ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( null === $hit['warning'] ) : ?>
										<span class="akwu-pill akwu-pill--error"><?php esc_html_e( 'Nicht ersetzt', 'akuma-webp-umwandler' ); ?></span>
										<span class="akwu-meta"><?php esc_html_e( 'Alte Adresse noch da, bitte die Stelle prüfen', 'akuma-webp-umwandler' ); ?></span>
									<?php else : ?>
										<span class="akwu-pill akwu-pill--warn"><?php echo esc_html( Scan_Result::warning_label( $hit['warning'] ) ); ?></span>
										<span class="akwu-meta"><?php esc_html_e( 'Von Hand prüfen, wird nie automatisch geändert', 'akuma-webp-umwandler' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( count( $leftovers ) >= Conversion::MAX_LEFTOVERS ) : ?>
				<p class="akwu-small akwu-card__body">
					<?php
					/* translators: %s: Höchstzahl. */
					echo esc_html( sprintf( __( 'Es werden höchstens %s Fundstellen gespeichert.', 'akuma-webp-umwandler' ), Format::number( Conversion::MAX_LEFTOVERS ) ) );
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>

<div class="akwu-cta">
	<div class="akwu-cta__text">
		<h2 class="akwu-cta__title"><?php echo esc_html( 'done' === $run['status'] ? __( 'Seiten ansehen und Bericht prüfen', 'akuma-webp-umwandler' ) : __( 'Neu starten', 'akuma-webp-umwandler' ) ); ?></h2>
		<p>
			<?php
			echo esc_html(
				'done' === $run['status']
					? __( 'Am besten ein paar Seiten im Frontend öffnen. Im Bericht stehen alle Bilder mit vorher und nachher, dort lässt sich auch alles zurücknehmen.', 'akuma-webp-umwandler' )
					: __( 'Auf der Übersicht lässt sich eine neue Umwandlung starten.', 'akuma-webp-umwandler' )
			);
			?>
		</p>
	</div>
	<div class="akwu-cta__actions">
		<?php if ( 'done' === $run['status'] ) : ?>
			<a class="akwu-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Website öffnen', 'akuma-webp-umwandler' ); ?></a>
			<a class="akwu-button" href="<?php echo esc_url( Admin::page_url( 'akwu-bericht' ) ); ?>">
				<?php esc_html_e( 'Zum Bericht', 'akuma-webp-umwandler' ); ?>
				<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
			</a>
		<?php else : ?>
			<a class="akwu-button" href="<?php echo esc_url( Admin::page_url( Admin::MENU_SLUG ) ); ?>">
				<?php esc_html_e( 'Zur Übersicht', 'akuma-webp-umwandler' ); ?>
				<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
			</a>
		<?php endif; ?>
	</div>
</div>
