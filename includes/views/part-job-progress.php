<?php
/**
 * Fortschritt für Rückgängig oder Originale löschen. Wird von admin.js gefüllt.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type array|null $job  Aktueller oder letzter Job.
 *     @type string     $type rollback oder purge: Job, der auf dieser Seite läuft.
 * }
 */

use Akuma\WebpUmwandler\Job;

defined( 'ABSPATH' ) || exit;

$job      = $data['job'];
$running  = Job::is_active( $job ) && $data['type'] === $job['type'];
$progress = $running ? Job::progress( $job ) : null;
$percent  = null === $progress ? 0 : $progress['percent'];
?>
<div class="akwu-card akwu-scan-progress" data-akwu-progress="job" data-akwu-job-type="<?php echo esc_attr( $data['type'] ); ?>" data-akwu-job-status="<?php echo $running ? 'running' : 'none'; ?>"<?php echo $running ? '' : ' hidden'; ?>>
	<div class="akwu-progress">
		<div class="akwu-progress__track" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo esc_attr( 'purge' === $data['type'] ? __( 'Fortschritt beim Löschen der Originale', 'akuma-webp-umwandler' ) : __( 'Fortschritt beim Zurücksetzen', 'akuma-webp-umwandler' ) ); ?>">
			<div class="akwu-progress__fill" style="width: <?php echo esc_attr( (string) $percent ); ?>%"></div>
		</div>
		<div class="akwu-progress__meta">
			<span data-akwu-progress-label aria-live="polite"><?php echo esc_html( null === $progress ? __( 'Startet …', 'akuma-webp-umwandler' ) : $progress['label'] ); ?></span>
			<span class="akwu-num" data-akwu-progress-percent><?php echo esc_html( $percent . ' %' ); ?></span>
		</div>
	</div>
	<p class="akwu-progress__error" data-akwu-progress-error role="alert" hidden></p>
</div>
