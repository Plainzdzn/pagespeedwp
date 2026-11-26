<?php
/**
 * Einbettungen: 3D-Touren, die weit unten auf einer Seite stehen, erst bei Bedarf laden.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\Embeds;
use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$embeds = Embeds::scan();
$lazy   = count(
	array_filter(
		$embeds,
		static function ( $entry ) {
			return 'eager' !== $entry['state'];
		}
	)
);

$devices = array(
	'desktop' => __( 'Desktop', 'akuma-webp-umwandler' ),
	'tablet'  => __( 'Tablet', 'akuma-webp-umwandler' ),
	'mobile'  => __( 'Handy', 'akuma-webp-umwandler' ),
);

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Einbettungen', 'akuma-webp-umwandler' ),
		'title'   => empty( $embeds )
			? __( 'Keine 3D-Tour gefunden.', 'akuma-webp-umwandler' )
			/* translators: %s: Anzahl. */
			: sprintf( _n( '%s eingebettete Tour gefunden.', '%s eingebettete Touren gefunden.', count( $embeds ), 'akuma-webp-umwandler' ), Format::number( count( $embeds ) ) ),
		'accent'  => empty( $embeds )
			? ''
			/* translators: %s: Anzahl. */
			: sprintf( _n( '%s lädt erst bei Bedarf.', '%s laden erst bei Bedarf.', $lazy, 'akuma-webp-umwandler' ), Format::number( $lazy ) ),
	)
);
?>
<div class="akwu-callout">
	<p class="akwu-callout__title"><?php esc_html_e( 'Was hier passiert', 'akuma-webp-umwandler' ); ?></p>
	<p><?php esc_html_e( 'Eine 3D-Tour lädt beim Seitenaufruf mehrere Megabyte, auch wenn sie weit unten steht und niemand hinscrollt. Hier lässt sich je Tour festlegen, dass der Browser sie erst lädt, wenn man in ihre Nähe kommt. Die Tour selbst bleibt unverändert auf der Seite: gleiche Bedienung mit Maus, Tastatur und Touch, keine Vorschaukachel, kein zusätzlicher Klick.', 'akuma-webp-umwandler' ); ?></p>
	<p><?php esc_html_e( 'Geändert wird nur das eine iframe im HTML-Widget (Attribut loading="lazy"). Das lässt sich hier jederzeit zurücknehmen und bleibt auch ohne dieses Plugin wirksam.', 'akuma-webp-umwandler' ); ?></p>
</div>

<?php if ( ! empty( $embeds ) ) : ?>
	<div class="akwu-card akwu-card--table">
		<div class="akwu-table-wrap">
			<table class="akwu-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Seite', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Position', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Anbieter', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Lädt', 'akuma-webp-umwandler' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Aktion', 'akuma-webp-umwandler' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $embeds as $entry ) : ?>
						<?php
						$visible = array_diff_key( $devices, array_flip( $entry['hidden'] ) );
						?>
						<tr>
							<td>
								<a class="akwu-file" href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $entry['title'] ? $entry['title'] : $entry['url'] ); ?></a>
								<span class="akwu-meta">
									<?php
									/* translators: 1: Beitrags-ID, 2: Widget-ID. */
									echo esc_html( sprintf( __( 'ID %1$d · Widget %2$s', 'akuma-webp-umwandler' ), $entry['post'], $entry['widget'] ) );
									?>
								</span>
							</td>
							<td>
								<?php
								/* translators: 1: Nummer des Abschnitts, 2: Anzahl Abschnitte. */
								echo esc_html( sprintf( __( 'Abschnitt %1$d von %2$d', 'akuma-webp-umwandler' ), $entry['section'], $entry['sections'] ) );
								?>
								<span class="akwu-meta">
									<?php
									echo esc_html(
										count( $visible ) === count( $devices )
											? __( 'auf allen Geräten sichtbar', 'akuma-webp-umwandler' )
											/* translators: %s: Gerätenamen. */
											: sprintf( __( 'sichtbar auf: %s', 'akuma-webp-umwandler' ), empty( $visible ) ? '–' : implode( ', ', $visible ) )
									);
									?>
								</span>
							</td>
							<td><?php echo esc_html( $entry['host'] ); ?></td>
							<td>
								<?php
								if ( 'eager' === $entry['state'] ) {
									esc_html_e( 'sofort beim Seitenaufruf', 'akuma-webp-umwandler' );
								} elseif ( 'ours' === $entry['state'] ) {
									esc_html_e( 'erst bei Bedarf (hier eingestellt)', 'akuma-webp-umwandler' );
								} else {
									/* translators: %s: Wert des Attributs loading. */
									echo esc_html( sprintf( __( 'im Widget festgelegt: %s', 'akuma-webp-umwandler' ), $entry['state'] ) );
								}
								?>
							</td>
							<td class="akwu-table__action">
								<?php if ( 'ours' === $entry['state'] || ( $entry['eligible'] && 'eager' === $entry['state'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'akwu_embed' ); ?>
										<input type="hidden" name="action" value="akwu_embed">
										<input type="hidden" name="akwu_key" value="<?php echo esc_attr( $entry['key'] ); ?>">
										<input type="hidden" name="akwu_do" value="<?php echo esc_attr( 'ours' === $entry['state'] ? 'revert' : 'lazy' ); ?>">
										<button type="submit" class="akwu-link">
											<?php
											if ( 'ours' === $entry['state'] ) {
												esc_html_e( 'Zurücknehmen', 'akuma-webp-umwandler' );
											} else {
												esc_html_e( 'Erst bei Bedarf laden', 'akuma-webp-umwandler' );
											}
											?>
										</button>
									</form>
								<?php else : ?>
									<span class="akwu-meta"><?php echo esc_html( $entry['reason'] ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>

<p class="akwu-small"><?php esc_html_e( 'Gesucht wird nur nach Matterport-Touren in Elementor-HTML-Widgets veröffentlichter Seiten. Andere Einbettungen, Videos und Karten bleiben unberührt. Touren in den ersten beiden Abschnitten einer Seite werden nicht angeboten, sie sollen sofort laden.', 'akuma-webp-umwandler' ); ?></p>
