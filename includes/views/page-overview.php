<?php
/**
 * Übersicht: Kennzahlen, Verteilung, größte Dateien, „Vor dem Start“, Start.
 *
 * Drei Zustände: noch kein Scan, Scan unterbrochen, Scan fertig.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php, zusätzlich scan, scan_state.
 */

use Akuma\WebpUmwandler\Admin;
use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\Icons;
use Akuma\WebpUmwandler\Scan_Result;
use Akuma\WebpUmwandler\Settings;
use Akuma\WebpUmwandler\System_Check;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$check       = $data['system_check'];
$result      = $data['scan'];
$state       = $data['scan_state'];
$running     = ( null !== $state && 'running' === $state['status'] );
$scan_totals = null === $result ? null : $result->totals();
$webp        = $check->result( 'webp' );
$disk        = $check->result( 'disk' );

// Schlechtester Status unter den Bildoptimierern und ihre Namen.
$conflict_status = System_Check::OK;
$conflict_names  = array();
foreach ( $check->conflict_results() as $row ) {
	if ( System_Check::OK === $row['status'] ) {
		continue;
	}
	$conflict_names[] = $row['label'];
	if ( System_Check::WARN === $row['status'] ) {
		$conflict_status = System_Check::WARN;
	} elseif ( System_Check::OK === $conflict_status ) {
		$conflict_status = System_Check::INFO;
	}
}

// Seitenkopf.
if ( null !== $scan_totals ) {
	$head = array(
		'eyebrow' => __( 'Übersicht', 'akuma-webp-umwandler' ),
		/* translators: %s: Gesamtgröße, z. B. „38,4 MB“. */
		'title'   => sprintf( __( '%s Bilder.', 'akuma-webp-umwandler' ), Format::bytes( $scan_totals['bytes'] ) ),
		'accent'  => $result->savings() > 0
			/* translators: %s: erwartete Ersparnis, z. B. „29,3 MB“. */
			? sprintf( __( 'Rund %s davon unnötig.', 'akuma-webp-umwandler' ), Format::bytes( $result->savings() ) )
			: __( 'Kaum etwas zu holen.', 'akuma-webp-umwandler' ),
		/* translators: %s: Zeitpunkt, z. B. „heute, 16:52“. */
		'meta'    => sprintf( __( 'Letzter Scan: %s', 'akuma-webp-umwandler' ), $result->finished_label() ),
	);
} elseif ( $running ) {
	$head = array(
		'eyebrow' => __( 'Übersicht', 'akuma-webp-umwandler' ),
		'title'   => __( 'Scan unterbrochen.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Er macht dort weiter, wo er aufgehört hat.', 'akuma-webp-umwandler' ),
	);
} else {
	$head = array(
		'eyebrow' => __( 'Übersicht', 'akuma-webp-umwandler' ),
		'title'   => __( 'Noch kein Scan.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Er liest nur und ändert nichts.', 'akuma-webp-umwandler' ),
	);
}

View::render( 'part-page-head', $head );

// Kennzahlen.
if ( null !== $scan_totals ) {
	$convertible = $scan_totals['convertible']['jpg'] + $scan_totals['convertible']['png'];
	$tiles       = array(
		array(
			__( 'Bilder', 'akuma-webp-umwandler' ),
			Format::number( $scan_totals['images'] ),
			/* translators: %s: Anzahl Vorschaugrößen. */
			sprintf( __( '+ %s Vorschaugrößen', 'akuma-webp-umwandler' ), Format::number( $scan_totals['sizes'] ) ),
		),
		array(
			__( 'Noch nicht WebP', 'akuma-webp-umwandler' ),
			Format::number( $convertible ),
			/* translators: 1: Anzahl JPG, 2: Anzahl PNG. */
			sprintf( __( '%1$s JPG · %2$s PNG', 'akuma-webp-umwandler' ), Format::number( $scan_totals['convertible']['jpg'] ), Format::number( $scan_totals['convertible']['png'] ) ),
		),
		array(
			__( 'Größe heute', 'akuma-webp-umwandler' ),
			Format::bytes( $scan_totals['bytes'] ),
			__( 'alle Bilddateien', 'akuma-webp-umwandler' ),
		),
	);
	$after_tile  = array(
		__( 'Erwartet danach', 'akuma-webp-umwandler' ),
		'≈ ' . Format::bytes( $scan_totals['after'] ),
		/* translators: %s: Ersparnis in Prozent, z. B. „−76 %“. */
		sprintf( __( '%s, hochgerechnet', 'akuma-webp-umwandler' ), $result->savings_percent_label() ),
	);
} else {
	$placeholder = __( 'nach dem Scan', 'akuma-webp-umwandler' );
	$tiles       = array(
		array( __( 'Bilder', 'akuma-webp-umwandler' ), '–', $placeholder ),
		array( __( 'Noch nicht WebP', 'akuma-webp-umwandler' ), '–', $placeholder ),
		array( __( 'Größe heute', 'akuma-webp-umwandler' ), '–', $placeholder ),
	);
	$after_tile  = array( __( 'Erwartet danach', 'akuma-webp-umwandler' ), '–', __( 'hochgerechnet', 'akuma-webp-umwandler' ) );
}
?>
<div class="akwu-stats">
	<?php foreach ( $tiles as $tile ) : ?>
		<div class="akwu-card akwu-stat">
			<span class="akwu-caption"><?php echo esc_html( $tile[0] ); ?></span>
			<span class="akwu-num akwu-stat__value"><?php echo esc_html( $tile[1] ); ?></span>
			<span class="akwu-small"><?php echo esc_html( $tile[2] ); ?></span>
		</div>
	<?php endforeach; ?>
	<div class="akwu-card akwu-stat akwu-stat--highlight">
		<span class="akwu-caption"><?php echo esc_html( $after_tile[0] ); ?></span>
		<span class="akwu-num akwu-stat__value"><?php echo esc_html( $after_tile[1] ); ?></span>
		<span class="akwu-small akwu-stat__note"><?php echo esc_html( $after_tile[2] ); ?></span>
	</div>
</div>

<?php
// Verteilung nach Format.
if ( null !== $scan_totals && $scan_totals['bytes'] > 0 ) :
	$segments = array(
		'png'   => array( 'PNG', 0, '#2e5b4b' ),
		'jpg'   => array( 'JPG', 0, '#62a183' ),
		'webp'  => array( 'WebP', 0, '#b9d6c3' ),
		'other' => array( __( 'Sonstige', 'akuma-webp-umwandler' ), 0, '#cbd0cd' ),
	);
	foreach ( $scan_totals['by_kind'] as $kind => $sum ) {
		$segments[ isset( $segments[ $kind ] ) ? $kind : 'other' ][1] += $sum['bytes'];
	}
	$aria = array();
	foreach ( $segments as $segment ) {
		$aria[] = $segment[0] . ' ' . Format::number( round( 100 * $segment[1] / $scan_totals['bytes'] ) ) . ' %';
	}
	?>
	<div class="akwu-card akwu-distribution">
		<div class="akwu-card__head">
			<h2 class="akwu-card__title"><?php esc_html_e( 'Verteilung nach Format', 'akuma-webp-umwandler' ); ?></h2>
			<span class="akwu-small"><?php esc_html_e( 'SVG und GIF bleiben unverändert', 'akuma-webp-umwandler' ); ?></span>
		</div>
		<div class="akwu-bar" role="img" aria-label="<?php echo esc_attr( implode( ', ', $aria ) ); ?>">
			<?php foreach ( $segments as $segment ) : ?>
				<?php if ( $segment[1] > 0 ) : ?>
					<span style="width: <?php echo esc_attr( (string) round( 100 * $segment[1] / $scan_totals['bytes'], 2 ) ); ?>%; background: <?php echo esc_attr( $segment[2] ); ?>"></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<ul class="akwu-legend">
			<?php foreach ( $segments as $segment ) : ?>
				<li><span class="akwu-dot" style="background: <?php echo esc_attr( $segment[2] ); ?>"></span><?php echo esc_html( $segment[0] . ' · ' . Format::bytes( $segment[1] ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<div class="akwu-columns akwu-columns--wide-main">
	<div class="akwu-card akwu-columns__main akwu-card--table">
		<div class="akwu-card__head akwu-card__head--padded">
			<h2 class="akwu-card__title"><?php esc_html_e( 'Größte Dateien', 'akuma-webp-umwandler' ); ?></h2>
			<?php if ( null !== $scan_totals ) : ?>
				<a class="akwu-link" href="<?php echo esc_url( Admin::page_url( 'akwu-bilder' ) ); ?>">
					<?php
					/* translators: %s: Anzahl Bilder. */
					echo esc_html( sprintf( __( 'Alle %s anzeigen', 'akuma-webp-umwandler' ), Format::number( $scan_totals['images'] ) ) );
					?>
				</a>
			<?php endif; ?>
		</div>
		<?php if ( null === $result ) : ?>
			<p class="akwu-empty akwu-card__body"><?php esc_html_e( 'Erscheint nach dem Scan, mit geschätzter WebP-Größe und den Seiten, auf denen das Bild verwendet wird.', 'akuma-webp-umwandler' ); ?></p>
		<?php else : ?>
			<div class="akwu-table-wrap">
				<table class="akwu-table akwu-table--compact">
					<thead>
						<tr>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Vorschau', 'akuma-webp-umwandler' ); ?></span></th>
							<th scope="col"><?php esc_html_e( 'Datei', 'akuma-webp-umwandler' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Heute', 'akuma-webp-umwandler' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Als WebP', 'akuma-webp-umwandler' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Verwendet', 'akuma-webp-umwandler' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'akuma-webp-umwandler' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result->largest( 5 ) as $item ) : ?>
							<?php
							View::render(
								'part-image-row',
								array(
									'item'    => $item,
									'details' => false,
								)
							);
							?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<div class="akwu-card akwu-columns__side akwu-checklist">
		<h2 class="akwu-card__title"><?php esc_html_e( 'Vor dem Start', 'akuma-webp-umwandler' ); ?></h2>

		<div class="akwu-check">
			<?php View::status_pill( $webp['status'] ); ?>
			<div>
				<?php
				echo esc_html(
					System_Check::ERROR === $webp['status']
						? __( 'Server kann kein WebP erzeugen', 'akuma-webp-umwandler' )
						: __( 'Server kann WebP erzeugen', 'akuma-webp-umwandler' )
				);
				?>
				<span class="akwu-meta"><?php echo esc_html( $webp['value'] ); ?></span>
			</div>
		</div>

		<div class="akwu-check">
			<?php View::status_pill( $conflict_status ); ?>
			<div>
				<?php if ( empty( $conflict_names ) ) : ?>
					<?php esc_html_e( 'Keine doppelte Komprimierung', 'akuma-webp-umwandler' ); ?>
					<span class="akwu-meta"><?php esc_html_e( 'Kein anderer Bildoptimierer aktiv', 'akuma-webp-umwandler' ); ?></span>
				<?php else : ?>
					<?php esc_html_e( 'Andere Bildoptimierer aktiv', 'akuma-webp-umwandler' ); ?>
					<span class="akwu-meta"><?php echo esc_html( implode( ', ', $conflict_names ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( null !== $result ) : ?>
			<?php $warnings = $result->warnings(); ?>
			<div class="akwu-check">
				<?php View::status_pill( empty( $warnings ) ? System_Check::OK : System_Check::WARN ); ?>
				<div>
					<?php if ( empty( $warnings ) ) : ?>
						<?php esc_html_e( 'Keine Stellen zum Nacharbeiten', 'akuma-webp-umwandler' ); ?>
						<span class="akwu-meta"><?php esc_html_e( 'Alle Verweise liegen in der Datenbank', 'akuma-webp-umwandler' ); ?></span>
					<?php else : ?>
						<?php
						/* translators: %s: Anzahl. */
						echo esc_html( sprintf( _n( '%s Bildadresse in CSS, Snippets oder Theme', '%s Bildadressen in CSS, Snippets oder Theme', count( $warnings ), 'akuma-webp-umwandler' ), Format::number( count( $warnings ) ) ) );
						?>
						<a class="akwu-meta" href="<?php echo esc_url( add_query_arg( 'filter', 'check', Admin::page_url( 'akwu-bilder' ) ) ); ?>"><?php esc_html_e( 'Danach von Hand prüfen', 'akuma-webp-umwandler' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<div class="akwu-check">
				<?php View::status_pill( $disk['status'] ); ?>
				<div>
					<?php echo esc_html( $disk['value'] ); ?>
					<span class="akwu-meta"><?php echo esc_html( $disk['detail'] ); ?></span>
				</div>
			</div>
		<?php endif; ?>

		<label class="akwu-check akwu-check--input" for="akwu-backup">
			<input type="checkbox" id="akwu-backup" name="akwu_backup" data-akwu-backup>
			<span>
				<?php esc_html_e( 'Backup ist erstellt', 'akuma-webp-umwandler' ); ?>
				<span class="akwu-meta"><?php esc_html_e( 'Datenbank und Uploads, Pflicht vor dem Start', 'akuma-webp-umwandler' ); ?></span>
			</span>
		</label>

		<a class="akwu-link" href="<?php echo esc_url( Admin::page_url( 'akwu-systempruefung' ) ); ?>"><?php esc_html_e( 'Alle Prüfungen ansehen', 'akuma-webp-umwandler' ); ?></a>
	</div>
</div>

<?php View::render( 'part-scan-progress', array( 'state' => $state ) ); ?>

<div class="akwu-cta">
	<?php if ( null === $scan_totals ) : ?>
		<div class="akwu-cta__text">
			<h2 class="akwu-cta__title"><?php echo esc_html( $running ? __( 'Scan fortsetzen', 'akuma-webp-umwandler' ) : __( 'Bestand scannen', 'akuma-webp-umwandler' ) ); ?></h2>
			<p><?php esc_html_e( 'Der Scan erfasst alle Bilder, ihre Größen und wo sie verwendet werden. Er verändert nichts.', 'akuma-webp-umwandler' ); ?></p>
		</div>
		<div class="akwu-cta__actions">
			<?php if ( $running ) : ?>
				<button type="button" class="akwu-link" data-akwu-scan data-akwu-restart="1"><?php esc_html_e( 'Neu beginnen', 'akuma-webp-umwandler' ); ?></button>
			<?php endif; ?>
			<button type="button" class="akwu-button" data-akwu-scan data-akwu-restart="<?php echo $running ? '0' : '1'; ?>">
				<?php echo esc_html( $running ? __( 'Weiter scannen', 'akuma-webp-umwandler' ) : __( 'Scan starten', 'akuma-webp-umwandler' ) ); ?>
				<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
			</button>
		</div>
	<?php else : ?>
		<div class="akwu-cta__text">
			<h2 class="akwu-cta__title">
				<?php
				/* translators: %s: Anzahl Bilder. */
				echo esc_html( sprintf( _n( '%s Bild umwandeln', '%s Bilder umwandeln', $scan_totals['ready'], 'akuma-webp-umwandler' ), Format::number( $scan_totals['ready'] ) ) );
				?>
			</h2>
			<p>
				<?php
				/* translators: %s: Paketgröße. */
				echo esc_html( sprintf( __( 'In Paketen zu %s Bildern. Verweise in Seiten und Elementor werden mitgezogen, danach CSS und Cache erneuert.', 'akuma-webp-umwandler' ), Format::number( Settings::get( 'batch_size' ) ) ) );
				?>
			</p>
		</div>
		<div class="akwu-cta__actions">
			<span class="akwu-small">
				<?php
				/* translators: %s: Meilenstein, z. B. „M3“. */
				echo esc_html( sprintf( __( 'Folgt mit %s', 'akuma-webp-umwandler' ), 'M3' ) );
				?>
			</span>
			<button type="button" class="akwu-button" disabled>
				<?php esc_html_e( 'Umwandlung starten', 'akuma-webp-umwandler' ); ?>
				<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
			</button>
		</div>
	<?php endif; ?>
</div>
