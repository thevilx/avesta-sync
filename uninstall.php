<?php
/**
 * Removes plugin options. Backups in uploads/avesta-sync-backups are kept on purpose.
 *
 * @package Avesta_Sync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'avesta_sync_key', 'avesta_sync_settings', 'avesta_sync_baseline', 'avesta_sync_hashcache' ) as $avesta_sync_opt ) {
	delete_option( $avesta_sync_opt );
}
