<?php
/**
 * History tab markup.
 *
 * @package Avesta_Sync
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="as-panel__head">
	<div>
		<h2 class="as-panel__title"><?php esc_html_e( 'History', 'avesta-sync' ); ?></h2>
		<p class="as-panel__lead"><?php esc_html_e( 'Every sync run, who started it and what happened to each item.', 'avesta-sync' ); ?></p>
	</div>
	<button type="button" class="as-btn as-btn--secondary" id="as-history-clear" hidden>
		<span class="dashicons dashicons-trash" aria-hidden="true"></span>
		<span class="as-btn__label"><?php esc_html_e( 'Clear history', 'avesta-sync' ); ?></span>
	</button>
</div>
<div id="as-history-list" class="as-acclist"></div>
