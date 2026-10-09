<?php
/**
 * Schritte des Ablaufs mit Zustand.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type array[] $steps Liste mit label und state (wait, run, with, done).
 * }
 */

defined( 'ABSPATH' ) || exit;

$labels = array(
	'wait' => array( __( 'wartet', 'akuma-webp-umwandler' ), 'info' ),
	'run'  => array( __( 'läuft', 'akuma-webp-umwandler' ), 'ok' ),
	'with' => array( __( 'läuft mit', 'akuma-webp-umwandler' ), 'ok' ),
	'done' => array( __( 'fertig', 'akuma-webp-umwandler' ), 'ok' ),
);
?>
<ol class="akwu-steps">
	<?php foreach ( $data['steps'] as $index => $step ) : ?>
		<?php $state = isset( $labels[ $step['state'] ] ) ? $labels[ $step['state'] ] : $labels['wait']; ?>
		<li class="akwu-step akwu-step--<?php echo esc_attr( $step['state'] ); ?>">
			<span class="akwu-step__number" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $index + 1 ) ); ?></span>
			<span class="akwu-step__label"><?php echo esc_html( $step['label'] ); ?></span>
			<span class="akwu-pill akwu-pill--<?php echo esc_attr( $state[1] ); ?>"><?php echo esc_html( $state[0] ); ?></span>
		</li>
	<?php endforeach; ?>
</ol>
