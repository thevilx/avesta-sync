<?php
/**
 * Dashboard tab markup (cards are filled by JS).
 *
 * @package Avesta_Sync
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="as-panel__head">
	<div>
		<h2 class="as-panel__title"><?php esc_html_e( 'Dashboard', 'avesta-sync' ); ?></h2>
		<p class="as-panel__lead"><?php esc_html_e( 'The state of your connection and your latest activity at a glance.', 'avesta-sync' ); ?></p>
	</div>
	<button type="button" class="as-btn as-btn--primary" id="as-dash-compare">
		<span class="dashicons dashicons-update" aria-hidden="true"></span>
		<span class="as-btn__label"><?php esc_html_e( 'Compare now', 'avesta-sync' ); ?></span>
	</button>
</div>
<div class="as-dash" id="as-dash-grid" aria-live="polite"></div>
