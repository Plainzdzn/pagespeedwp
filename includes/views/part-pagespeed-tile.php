<?php
/**
 * Kachel „PageSpeed mobil“ im Bericht. Messung folgt mit der optionalen PageSpeed-API.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data Siehe layout.php.
 */

use Akuma\WebpUmwandler\Admin;

defined( 'ABSPATH' ) || exit;
?>
<div>
	<span class="akwu-caption"><?php esc_html_e( 'PageSpeed mobil', 'akuma-webp-umwandler' ); ?></span>
	<span class="akwu-num akwu-report-stats__value">–</span>
	<a class="akwu-small" href="<?php echo esc_url( Admin::page_url( 'akwu-einstellungen' ) ); ?>"><?php esc_html_e( 'Optional, mit API-Schlüssel', 'akuma-webp-umwandler' ); ?></a>
</div>
