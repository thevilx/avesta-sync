<?php
/**
 * Backups tab markup.
 *
 * @package Avesta_Sync
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="as-panel__head">
	<div>
		<h2 class="as-panel__title"><?php esc_html_e( 'Backups', 'avesta-sync' ); ?></h2>
		<p class="as-panel__lead"><?php esc_html_e( 'Before a sync overwrites or deletes something on this site, the previous version is saved here. Restoring an item is itself backed up first.', 'avesta-sync' ); ?></p>
	</div>
</div>
<div id="as-backups-list" class="as-acclist"></div>
