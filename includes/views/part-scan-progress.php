<?php
/**
 * Fortschrittsanzeige des Scans. Wird von admin.js gefüllt.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type array|null $state Gespeicherter Stand des Scans.
 * }
 */

use Akuma\WebpUmwandler\Scanner;

defined( 'ABSPATH' ) || exit;

$running  = ( null !== $data['state'] && 'running' === $data['state']['status'] );
$progress = $running ? Scanner::progress( $data['state'] ) : null;
$percent  = null === $progress ? 0 : $progress['percent'];
?>
<div class="akwu-card akwu-scan-progress" data-akwu-progress="scan"<?php echo $running ? '' : ' hidden'; ?>>
	<div class="akwu-progress">
		<div class="akwu-progress__track" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Fortschritt des Scans', 'akuma-webp-umwandler' ); ?>">
			<div class="akwu-progress__fill" style="width: <?php echo esc_attr( (string) $percent ); ?>%"></div>
		</div>
		<div class="akwu-progress__meta">
			<span data-akwu-progress-label aria-live="polite">
				<?php
				echo esc_html(
					null === $progress
						? __( 'Scan startet …', 'akuma-webp-umwandler' )
						/* translators: %s: Phase, z. B. „Verwendung suchen“. */
						: sprintf( __( 'Unterbrochen bei: %s', 'akuma-webp-umwandler' ), $progress['label'] )
				);
				?>
			</span>
			<span class="akwu-num" data-akwu-progress-percent><?php echo esc_html( $percent . ' %' ); ?></span>
		</div>
	</div>
	<p class="akwu-progress__error" data-akwu-progress-error role="alert" hidden></p>
</div>
