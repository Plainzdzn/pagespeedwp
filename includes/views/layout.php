<?php
/**
 * Gemeinsames Layout aller Plugin-Seiten: Kopfzeile, WordPress-Hinweise, Panel mit Navigation.
 *
 * @package Akuma\WebpUmwandler
 *
 * @var array $data {
 *     @type array        $pages        Seiten aus Admin::pages().
 *     @type string       $current      Slug der aktuellen Seite.
 *     @type System_Check $system_check   Systemprüfung.
 *     @type array|null   $run            Aktueller oder letzter Lauf.
 *     @type array[]      $notices        Hinweise.
 *     @type array[]      $header_actions Knöpfe in der Kopfzeile.
 * }
 */

use Akuma\WebpUmwandler\Admin;
use Akuma\WebpUmwandler\Icons;
use Akuma\WebpUmwandler\View;

defined( 'ABSPATH' ) || exit;

$nav_pages = $data['pages'];
$current   = $data['current'];
$groups    = array(
	'main'  => '',
	'setup' => __( 'Einrichtung', 'akuma-webp-umwandler' ),
);
?>
<div class="wrap akwu">
	<header class="akwu-header">
		<div class="akwu-brand">
			<span class="akwu-logo"><?php Icons::render( 'logo', 20, '1.8' ); ?></span>
			<div>
				<p class="akwu-brand__name">
					<?php esc_html_e( 'WebP-Umwandler', 'akuma-webp-umwandler' ); ?>
					<span class="akwu-version"><?php echo esc_html( 'v' . AKWU_VERSION ); ?></span>
				</p>
				<p class="akwu-brand__by"><?php esc_html_e( 'von Akuma Digital', 'akuma-webp-umwandler' ); ?></p>
			</div>
		</div>
		<?php if ( ! empty( $data['header_actions'] ) ) : ?>
			<div class="akwu-header__actions">
				<?php foreach ( $data['header_actions'] as $header_action ) : ?>
					<?php $header_class = 'akwu-header-button' . ( 'danger' === $header_action['style'] ? ' akwu-header-button--danger' : '' ); ?>
					<?php if ( isset( $header_action['url'] ) ) : ?>
						<a class="<?php echo esc_attr( $header_class ); ?>" href="<?php echo esc_url( $header_action['url'] ); ?>">
					<?php else : ?>
						<button type="button" class="<?php echo esc_attr( $header_class ); ?>" data-akwu-run-action="<?php echo esc_attr( $header_action['action'] ); ?>">
					<?php endif; ?>
						<?php
						if ( '' !== $header_action['icon'] ) {
							Icons::render( $header_action['icon'], 15, '1.8' );
						}
						echo esc_html( $header_action['label'] );
						?>
					<?php echo isset( $header_action['url'] ) ? '</a>' : '</button>'; ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</header>

	<hr class="wp-header-end">

	<?php foreach ( $data['notices'] as $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> akwu-notice">
			<p>
				<strong><?php echo esc_html( $notice['title'] ); ?></strong>
				<?php echo esc_html( $notice['message'] ); ?>
			</p>
			<?php if ( isset( $notice['action'] ) && 'rescan' === $notice['action'] ) : ?>
				<button type="button" class="akwu-link akwu-notice__action" data-akwu-scan data-akwu-restart="1"><?php esc_html_e( 'Erneut scannen', 'akuma-webp-umwandler' ); ?></button>
			<?php endif; ?>
			<?php if ( isset( $notice['link'] ) ) : ?>
				<a class="akwu-link akwu-notice__action" href="<?php echo esc_url( $notice['link']['url'] ); ?>"><?php echo esc_html( $notice['link']['label'] ); ?></a>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php
	if ( 'akwu-einstellungen' === $current ) {
		settings_errors();
	}
	?>

	<div class="akwu-panel">
		<nav class="akwu-nav" aria-label="<?php esc_attr_e( 'Bereiche des WebP-Umwandlers', 'akuma-webp-umwandler' ); ?>">
			<?php foreach ( $groups as $group => $heading ) : ?>
				<?php if ( '' !== $heading ) : ?>
					<p class="akwu-nav__heading"><?php echo esc_html( $heading ); ?></p>
				<?php endif; ?>
				<ul class="akwu-nav__list">
					<?php foreach ( $nav_pages as $slug => $nav_page ) : ?>
						<?php
						if ( $group !== $nav_page['group'] ) {
							continue;
						}
						$is_current = ( $slug === $current );
						?>
						<li>
							<a class="akwu-nav__item<?php echo $is_current ? ' is-current' : ''; ?>" href="<?php echo esc_url( Admin::page_url( $slug ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>>
								<?php Icons::render( $nav_page['icon'] ); ?>
								<?php echo esc_html( $nav_page['menu'] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<p class="akwu-nav__note"><?php esc_html_e( 'Nach Abschluss kann das Plugin entfernt werden. Die Änderungen bleiben bestehen.', 'akuma-webp-umwandler' ); ?></p>
		</nav>

		<section class="akwu-content" aria-labelledby="akwu-title">
			<?php View::render( $nav_pages[ $current ]['view'], $data ); ?>
		</section>
	</div>
</div>
