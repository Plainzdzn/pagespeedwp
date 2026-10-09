<?php
/**
 * Systemprüfung: alle Prüfungen mit Status, Wert und Hinweis.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\Format;
use Akuma\WebpUmwandler\System_Check;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$check       = $data['system_check'];
$error_count = $check->count( System_Check::ERROR );
$warn_count  = $check->count( System_Check::WARN );

if ( $error_count > 0 ) {
	$head_title = __( 'Start nicht möglich.', 'akuma-webp-umwandler' );
	/* translators: %s: Anzahl. */
	$head_accent = sprintf( _n( '%s Punkt verhindert die Umwandlung.', '%s Punkte verhindern die Umwandlung.', $error_count, 'akuma-webp-umwandler' ), Format::number( $error_count ) );
} elseif ( $warn_count > 0 ) {
	$head_title = __( 'Fast bereit.', 'akuma-webp-umwandler' );
	/* translators: %s: Anzahl. */
	$head_accent = sprintf( _n( '%s Punkt bitte prüfen.', '%s Punkte bitte prüfen.', $warn_count, 'akuma-webp-umwandler' ), Format::number( $warn_count ) );
} else {
	$head_title  = __( 'Alles bereit.', 'akuma-webp-umwandler' );
	$head_accent = __( 'Der Server kann umwandeln.', 'akuma-webp-umwandler' );
}

View::render(
	'part-page-head',
	array(
		'eyebrow' => __( 'Systemprüfung', 'akuma-webp-umwandler' ),
		'title'   => $head_title,
		'accent'  => $head_accent,
	)
);
?>
<div class="akwu-card akwu-card--table">
	<div class="akwu-table-wrap">
		<table class="akwu-table">
			<thead>
				<tr>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Status', 'akuma-webp-umwandler' ); ?></span></th>
					<th scope="col"><?php esc_html_e( 'Prüfung', 'akuma-webp-umwandler' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ergebnis', 'akuma-webp-umwandler' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Hinweis', 'akuma-webp-umwandler' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $check->results() as $row ) : ?>
					<tr class="akwu-table__row--<?php echo esc_attr( $row['status'] ); ?>">
						<td><?php View::status_pill( $row['status'] ); ?></td>
						<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
						<td><?php echo esc_html( $row['value'] ); ?></td>
						<td class="akwu-table__detail"><?php echo esc_html( $row['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<p class="akwu-small"><?php esc_html_e( 'Es wird nur geprüft und gewarnt. Bei anderen Plugins stellt der WebP-Umwandler nichts um.', 'akuma-webp-umwandler' ); ?></p>
