<?php
/**
 * Übersicht: Kennzahlen nach dem Scan, größte Dateien, „Vor dem Start“, Start.
 *
 * In M1 ohne Scan-Daten, mit echter Systemprüfung.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\Admin;
use Akuma\WebpUmwandler\Icons;
use Akuma\WebpUmwandler\System_Check;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$check     = $data['system_check'];
$webp      = $check->result( 'webp' );
$conflicts = $check->conflict_results();

// Schlechtester Status unter den Bildoptimierern und ihre Namen.
$conflict_status = System_Check::OK;
$conflict_names  = array();
foreach ( $conflicts as $row ) {
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

$stats = array(
	__( 'Bilder', 'akuma-webp-umwandler' ),
	__( 'Noch nicht WebP', 'akuma-webp-umwandler' ),
	__( 'Größe heute', 'akuma-webp-umwandler' ),
);

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Übersicht', 'akuma-webp-umwandler' ),
		'title'   => __( 'Noch kein Scan.', 'akuma-webp-umwandler' ),
		'accent'  => __( 'Er liest nur und ändert nichts.', 'akuma-webp-umwandler' ),
	)
);
?>
<div class="akwu-stats">
	<?php foreach ( $stats as $caption ) : ?>
		<div class="akwu-card akwu-stat">
			<span class="akwu-caption"><?php echo esc_html( $caption ); ?></span>
			<span class="akwu-num akwu-stat__value">–</span>
			<span class="akwu-small"><?php esc_html_e( 'nach dem Scan', 'akuma-webp-umwandler' ); ?></span>
		</div>
	<?php endforeach; ?>
	<div class="akwu-card akwu-stat akwu-stat--highlight">
		<span class="akwu-caption"><?php esc_html_e( 'Erwartet danach', 'akuma-webp-umwandler' ); ?></span>
		<span class="akwu-num akwu-stat__value">–</span>
		<span class="akwu-small"><?php esc_html_e( 'hochgerechnet', 'akuma-webp-umwandler' ); ?></span>
	</div>
</div>

<div class="akwu-columns">
	<div class="akwu-card akwu-columns__main">
		<div class="akwu-card__head">
			<h2 class="akwu-card__title"><?php esc_html_e( 'Größte Dateien', 'akuma-webp-umwandler' ); ?></h2>
		</div>
		<p class="akwu-empty"><?php esc_html_e( 'Erscheint nach dem Scan, mit geschätzter WebP-Größe und den Seiten, auf denen das Bild verwendet wird.', 'akuma-webp-umwandler' ); ?></p>
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

		<label class="akwu-check akwu-check--input" for="akwu-backup">
			<input type="checkbox" id="akwu-backup" name="akwu_backup">
			<span>
				<?php esc_html_e( 'Backup ist erstellt', 'akuma-webp-umwandler' ); ?>
				<span class="akwu-meta"><?php esc_html_e( 'Datenbank und Uploads, Pflicht vor dem Start', 'akuma-webp-umwandler' ); ?></span>
			</span>
		</label>

		<a class="akwu-link" href="<?php echo esc_url( Admin::page_url( 'akwu-systempruefung' ) ); ?>"><?php esc_html_e( 'Alle Prüfungen ansehen', 'akuma-webp-umwandler' ); ?></a>
	</div>
</div>

<div class="akwu-cta">
	<div class="akwu-cta__text">
		<h2 class="akwu-cta__title"><?php esc_html_e( 'Bestand scannen', 'akuma-webp-umwandler' ); ?></h2>
		<p><?php esc_html_e( 'Der Scan erfasst alle Bilder, ihre Größen und wo sie verwendet werden. Danach steht hier, was die Umwandlung bringt.', 'akuma-webp-umwandler' ); ?></p>
	</div>
	<div class="akwu-cta__actions">
		<span class="akwu-small">
			<?php
			/* translators: %s: Meilenstein, z. B. „M2“. */
			echo esc_html( sprintf( __( 'Folgt mit %s', 'akuma-webp-umwandler' ), 'M2' ) );
			?>
		</span>
		<button type="button" class="akwu-button" disabled>
			<?php esc_html_e( 'Scan starten', 'akuma-webp-umwandler' ); ?>
			<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
		</button>
	</div>
</div>
