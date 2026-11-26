<?php
/**
 * Ladeverhalten: Bilder der Kopfzeile sofort laden, damit die Seite beim Nachladen nicht verrutscht.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\Fix_File;
use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\Header_Images;
use Akuma\WebpUmwandler\Icons;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$images   = Header_Images::scan();
$enabled  = Header_Images::is_active();
$outdated = $enabled && Header_Images::is_outdated();
$foreign  = 'foreign' === Fix_File::status();

if ( empty( $images ) ) {
	$headline = __( 'Kein Bild in einer Kopfzeilen-Vorlage gefunden.', 'akuma-webp-umwandler' );
	$accent   = '';
} else {
	/* translators: %s: Anzahl. */
	$headline = sprintf( _n( '%s Bild in der Kopfzeile.', '%s Bilder in der Kopfzeile.', count( $images ), 'akuma-webp-umwandler' ), Format::number( count( $images ) ) );
	$accent   = $enabled ? __( 'Laden sofort.', 'akuma-webp-umwandler' ) : __( 'Nichts eingeschaltet.', 'akuma-webp-umwandler' );
}

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Ladeverhalten', 'akuma-webp-umwandler' ),
		'title'   => $headline,
		'accent'  => $accent,
	)
);
?>
<div class="akwu-callout">
	<p class="akwu-callout__title"><?php esc_html_e( 'Was hier passiert', 'akuma-webp-umwandler' ); ?></p>
	<p><?php esc_html_e( 'Manche Cache- und Optimierungs-Plugins laden alle Bilder verzögert, auch das Logo in der Kopfzeile. Bis es nachgeladen ist, steht dort ein Platzhalter in anderer Größe. Danach rutscht die ganze Seite ein Stück nach unten. Das stört beim Lesen und kostet in PageSpeed Punkte (Layout-Verschiebung, CLS).', 'akuma-webp-umwandler' ); ?></p>
	<p><?php esc_html_e( 'Der Fix kennzeichnet nur die Bilder der Kopfzeilen-Vorlagen als „sofort laden“ (loading="eager", data-no-lazy, Klasse skip-lazy). Am gespeicherten Inhalt ändert sich nichts. Er steht in einer kleinen eigenen Datei im Ordner der Must-use-Plugins und wirkt deshalb auch weiter, wenn dieses Plugin entfernt ist. Zurücknehmen entfernt die Datei wieder.', 'akuma-webp-umwandler' ); ?></p>
</div>

<?php if ( $foreign ) : ?>
	<div class="akwu-callout">
		<p class="akwu-callout__title"><?php esc_html_e( 'Fremde Datei gefunden', 'akuma-webp-umwandler' ); ?></p>
		<p>
			<?php
			/* translators: %s: Dateiname. */
			echo esc_html( sprintf( __( 'Im Ordner der Must-use-Plugins liegt eine Datei %s, die nicht von diesem Plugin stammt. Sie wird nicht angefasst, der Fix lässt sich deshalb nicht einschalten.', 'akuma-webp-umwandler' ), Fix_File::FILE ) );
			?>
		</p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $images ) ) : ?>
	<div class="akwu-card akwu-card--table">
		<div class="akwu-table-wrap">
			<table class="akwu-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Bild', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kopfzeilen-Vorlage', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Lädt', 'akuma-webp-umwandler' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $images as $entry ) : ?>
						<tr>
							<td>
								<span class="akwu-file"><?php echo esc_html( '' !== $entry['file'] ? $entry['file'] : '–' ); ?></span>
								<span class="akwu-meta">
									<?php
									/* translators: 1: Bild-ID, 2: Widget-Typ von Elementor. */
									echo esc_html( sprintf( __( 'Bild-ID %1$d · Widget-Typ %2$s', 'akuma-webp-umwandler' ), $entry['id'], $entry['type'] ) );
									?>
								</span>
							</td>
							<td>
								<?php echo esc_html( '' !== $entry['title'] ? $entry['title'] : '–' ); ?>
								<span class="akwu-meta">
									<?php
									/* translators: %d: Beitrags-ID. */
									echo esc_html( sprintf( __( 'ID %d', 'akuma-webp-umwandler' ), $entry['template'] ) );
									?>
								</span>
							</td>
							<td>
								<?php
								if ( $enabled && $entry['active'] ) {
									esc_html_e( 'sofort (hier eingeschaltet)', 'akuma-webp-umwandler' );
								} elseif ( $enabled ) {
									esc_html_e( 'noch nicht erfasst, bitte neu einschalten', 'akuma-webp-umwandler' );
								} else {
									esc_html_e( 'wie Theme und andere Plugins es vorgeben', 'akuma-webp-umwandler' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ( ! $foreign ) : ?>
		<div class="akwu-cta">
			<div class="akwu-cta__text">
				<h2 class="akwu-cta__title">
					<?php
					if ( $outdated ) {
						esc_html_e( 'Die Kopfzeile hat sich geändert', 'akuma-webp-umwandler' );
					} elseif ( $enabled ) {
						esc_html_e( 'Der Fix ist eingeschaltet', 'akuma-webp-umwandler' );
					} else {
						esc_html_e( 'Bilder der Kopfzeile sofort laden', 'akuma-webp-umwandler' );
					}
					?>
				</h2>
				<p>
					<?php
					if ( $outdated ) {
						esc_html_e( 'In der Kopfzeile stehen heute andere Bilder als beim Einschalten. Neu einschalten übernimmt den heutigen Stand.', 'akuma-webp-umwandler' );
					} elseif ( $enabled ) {
						/* translators: %s: Dateiname. */
						echo esc_html( sprintf( __( 'Er steht in %s im Ordner der Must-use-Plugins und wirkt auch ohne dieses Plugin weiter.', 'akuma-webp-umwandler' ), Fix_File::FILE ) );
					} else {
						esc_html_e( 'Schreibt eine kleine Fix-Datei. Der gespeicherte Inhalt der Website bleibt unverändert, Zurücknehmen entfernt die Datei wieder.', 'akuma-webp-umwandler' );
					}
					?>
				</p>
			</div>
			<div class="akwu-cta__actions">
				<?php if ( $enabled ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'akwu_loading' ); ?>
						<input type="hidden" name="action" value="akwu_loading">
						<input type="hidden" name="akwu_do" value="revert">
						<button type="submit" class="akwu-link" data-akwu-loading="revert"><?php esc_html_e( 'Zurücknehmen', 'akuma-webp-umwandler' ); ?></button>
					</form>
				<?php endif; ?>
				<?php if ( ! $enabled || $outdated ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'akwu_loading' ); ?>
						<input type="hidden" name="action" value="akwu_loading">
						<input type="hidden" name="akwu_do" value="apply">
						<button type="submit" class="akwu-button" data-akwu-loading="apply">
							<?php echo esc_html( $outdated ? __( 'Neu einschalten', 'akuma-webp-umwandler' ) : __( 'Einschalten', 'akuma-webp-umwandler' ) ); ?>
							<span class="akwu-button__medal"><?php Icons::render( 'arrow', 17, '1.8' ); ?></span>
						</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
<?php endif; ?>

<p class="akwu-small"><?php esc_html_e( 'Gesucht wird nur in veröffentlichten Kopfzeilen-Vorlagen des Elementor Theme Builders (Bild, Bild-Box, Website-Logo). Bilder im Seiteninhalt bleiben unberührt. Geprüft mit WP Rocket. Andere Lazy-Loader beachten die Kennzeichnung meist ebenfalls, das ist aber nicht getestet. Nach dem Einschalten den Server-Cache leeren (Raidboxes: im Dashboard).', 'akuma-webp-umwandler' ); ?></p>
