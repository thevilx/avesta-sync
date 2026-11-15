<?php
/**
 * Sync tab markup (results are filled by JS).
 *
 * @package Avesta_Sync
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="as-sync">
	<div class="as-panel__head">
		<div>
			<h2 class="as-panel__title"><?php esc_html_e( 'Sync', 'avesta-sync' ); ?></h2>
			<p class="as-panel__lead"><?php esc_html_e( 'Compare both sites, review every difference, then choose what to do with each item. Push sends the version on this site to the remote site; pull brings the version on the remote site to this site. Nothing changes until you apply.', 'avesta-sync' ); ?></p>
		</div>
		<button type="button" class="as-btn as-btn--primary" id="as-compare">
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<span class="as-btn__label"><?php esc_html_e( 'Compare', 'avesta-sync' ); ?></span>
		</button>
	</div>

	<div id="as-wc-notices"></div>
	<div id="as-state"></div>
	<div id="as-stale" class="as-alert as-alert--warning" role="status" hidden><span class="dashicons dashicons-warning" aria-hidden="true"></span><div class="as-alert__text"><?php esc_html_e( 'Results may be out of date. Compare again.', 'avesta-sync' ); ?></div></div>
	<div id="as-chips" class="as-chips" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'avesta-sync' ); ?>" hidden></div>

	<div id="as-results" hidden>
		<div class="as-filters">
			<div class="as-field">
				<label for="as-q"><?php esc_html_e( 'Search', 'avesta-sync' ); ?></label>
				<input type="search" id="as-q" class="as-input" placeholder="<?php esc_attr_e( 'Filter by path or name', 'avesta-sync' ); ?>" autocomplete="off">
			</div>
			<div class="as-field">
				<label for="as-scope"><?php esc_html_e( 'Section', 'avesta-sync' ); ?></label>
				<select id="as-scope" class="as-input">
					<option value="all"><?php esc_html_e( 'All', 'avesta-sync' ); ?></option>
					<option value="theme"><?php esc_html_e( 'Theme', 'avesta-sync' ); ?></option>
					<option value="plugins"><?php esc_html_e( 'Plugins', 'avesta-sync' ); ?></option>
					<option value="content"><?php esc_html_e( 'Content', 'avesta-sync' ); ?></option>
					<option value="uploads"><?php esc_html_e( 'Media', 'avesta-sync' ); ?></option>
					<option value="woocommerce"><?php esc_html_e( 'WooCommerce', 'avesta-sync' ); ?></option>
				</select>
			</div>
			<p class="as-filter-count as-muted" id="as-filter-count" role="status"></p>
		</div>
		<div id="as-bulk" class="as-bulk" role="group" aria-label="<?php esc_attr_e( 'Bulk actions for the rows shown', 'avesta-sync' ); ?>">
			<button type="button" class="as-btn as-btn--secondary as-btn--sm" data-bulk="push"><?php esc_html_e( 'Select all pushes', 'avesta-sync' ); ?></button>
			<button type="button" class="as-btn as-btn--secondary as-btn--sm" data-bulk="pull"><?php esc_html_e( 'Select all pulls', 'avesta-sync' ); ?></button>
			<button type="button" class="as-btn as-btn--secondary as-btn--sm" data-bulk="conflict-push"><?php esc_html_e( 'Conflicts: push', 'avesta-sync' ); ?></button>
			<button type="button" class="as-btn as-btn--secondary as-btn--sm" data-bulk="conflict-pull"><?php esc_html_e( 'Conflicts: pull', 'avesta-sync' ); ?></button>
			<button type="button" class="as-btn as-btn--link as-btn--sm" data-bulk="none"><?php esc_html_e( 'Clear selection', 'avesta-sync' ); ?></button>
		</div>
		<ul id="as-groups" class="as-groups"></ul>
		<div id="as-nomatch" hidden>
			<div class="as-empty">
				<span class="dashicons dashicons-filter" aria-hidden="true"></span>
				<p class="as-empty__title"><?php esc_html_e( 'No items match your filters.', 'avesta-sync' ); ?></p>
				<button type="button" class="as-btn as-btn--secondary" id="as-clear-filters"><?php esc_html_e( 'Clear filters', 'avesta-sync' ); ?></button>
			</div>
		</div>
	</div>

	<div id="as-run" class="as-run" hidden>
		<div class="as-run__top">
			<progress id="as-progress" value="0" max="1" aria-label="<?php esc_attr_e( 'Sync progress', 'avesta-sync' ); ?>"></progress>
			<span id="as-progress-text" class="as-run__text" role="status"></span>
		</div>
		<div id="as-log" class="as-log" role="log" aria-live="polite" tabindex="0" aria-label="<?php esc_attr_e( 'Sync log', 'avesta-sync' ); ?>" dir="ltr" hidden></div>
		<div id="as-summary" class="as-summary" role="status" hidden></div>
	</div>

	<div id="as-bar" class="as-bar" hidden>
		<div class="as-bar__info">
			<span id="as-bar-text" class="as-bar__text"></span>
			<span id="as-bar-hidden" class="as-bar__hidden as-muted"></span>
		</div>
		<button type="button" class="as-btn as-btn--secondary" id="as-stop" hidden>
			<span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
			<span class="as-btn__label"><?php esc_html_e( 'Stop after this item', 'avesta-sync' ); ?></span>
		</button>
		<button type="button" class="as-btn as-btn--primary" id="as-apply" disabled>
			<span class="dashicons dashicons-yes" aria-hidden="true"></span>
			<span class="as-btn__label"><?php esc_html_e( 'Apply selected', 'avesta-sync' ); ?></span>
		</button>
	</div>
</div>
