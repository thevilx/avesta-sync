<?php
/**
 * Removes plugin options and the cron event. Backups in uploads/avesta-sync-backups-<token>
 * (and the avesta_sync_backup_token option that locates them) are kept on purpose.
 *
 * @package Avesta_Sync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'avesta_sync_key', 'avesta_sync_settings', 'avesta_sync_baseline', 'avesta_sync_hashcache', 'avesta_sync_history', 'avesta_sync_last_check', 'avesta_sync_last_notified', 'avesta_sync_wc_pending' ) as $avesta_sync_opt ) {
	delete_option( $avesta_sync_opt );
}
wp_clear_scheduled_hook( 'avesta_sync_scheduled_check' );
