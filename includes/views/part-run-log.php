<?php
/**
 * Live-Protokoll: die letzten bearbeiteten Bilder, neueste zuerst.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type array[] $entries Einträge aus Conversion::recent().
 * }
 */

use Akuma\WebpUmwandler\Format;

defined( 'ABSPATH' ) || exit;

$symbols = array(
	'done'        => array( '✓', 'ok', __( 'Umgewandelt', 'akuma-webp-umwandler' ) ),
	'skipped'     => array( '–', 'info', __( 'Übersprungen', 'akuma-webp-umwandler' ) ),
	'error'       => array( '!', 'warn', __( 'Fehler', 'akuma-webp-umwandler' ) ),
	'rolled_back' => array( '↺', 'info', __( 'Zurückgesetzt', 'akuma-webp-umwandler' ) ),
	'cancelled'   => array( '–', 'info', __( 'Nicht umgewandelt', 'akuma-webp-umwandler' ) ),
);
?>
<?php if ( empty( $data['entries'] ) ) : ?>
	<p class="akwu-empty"><?php esc_html_e( 'Die ersten Bilder erscheinen hier, sobald das erste Paket fertig ist.', 'akuma-webp-umwandler' ); ?></p>
<?php else : ?>
	<ul class="akwu-log">
		<?php foreach ( $data['entries'] as $entry ) : ?>
			<?php $symbol = isset( $symbols[ $entry['status'] ] ) ? $symbols[ $entry['status'] ] : $symbols['skipped']; ?>
			<li class="akwu-log__entry">
				<span class="akwu-pill akwu-pill--<?php echo esc_attr( $symbol[1] ); ?>"><span aria-hidden="true"><?php echo esc_html( $symbol[0] ); ?></span><span class="screen-reader-text"><?php echo esc_html( $symbol[2] ); ?></span></span>
				<span class="akwu-log__text">
					<?php
					echo esc_html( 'done' === $entry['status'] && '' !== $entry['new_file'] ? $entry['file'] . ' → ' . $entry['new_file'] : $entry['file'] );
					?>
					<span class="akwu-meta">
						<?php
						/* translators: %d: Attachment-ID. */
						$details = sprintf( __( 'ID %d', 'akuma-webp-umwandler' ), $entry['id'] );
						if ( 'done' === $entry['status'] ) {
							/* translators: %s: Anzahl Verweise. */
							$details .= ' · ' . sprintf( _n( '%s Verweis', '%s Verweise', $entry['replacements'], 'akuma-webp-umwandler' ), Format::number( $entry['replacements'] ) );
						} elseif ( '' !== $entry['message'] ) {
							$details .= ' · ' . $entry['message'];
						}
						echo esc_html( $details );
						?>
					</span>
				</span>
				<span class="akwu-log__size">
					<?php if ( 'done' === $entry['status'] ) : ?>
						<?php echo esc_html( Format::bytes( $entry['before'] ) . ' → ' ); ?><strong><?php echo esc_html( Format::bytes( $entry['after'] ) ); ?></strong>
					<?php elseif ( $entry['before'] > 0 ) : ?>
						<?php echo esc_html( Format::bytes( $entry['before'] ) ); ?>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
