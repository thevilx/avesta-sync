<?php
/**
 * Plugin Name:       Avesta Sync
 * Plugin URI:        https://avestatech.ir
 * Description:       Hash-based two-way sync (push/pull) of theme files, custom plugins, content and media between a local site and production over a signed REST API. Only changed items are transferred.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            AvestaTech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       avesta-sync
 *
 * @package Avesta_Sync
 */

defined( 'ABSPATH' ) || exit;

define( 'AVESTA_SYNC_VERSION', '1.0.0' );
define( 'AVESTA_SYNC_FILE', __FILE__ );
define( 'AVESTA_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AVESTA_SYNC_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'auth', 'paths', 'backup', 'files', 'content', 'importer', 'menus', 'client', 'diff', 'sync', 'rest', 'admin' ) as $avesta_sync_file ) {
	require_once AVESTA_SYNC_DIR . 'includes/class-' . $avesta_sync_file . '.php';
}
unset( $avesta_sync_file );

register_activation_hook( __FILE__, array( 'Avesta_Sync\Auth', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Avesta_Sync\Rest::init();
		if ( is_admin() ) {
			Avesta_Sync\Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once AVESTA_SYNC_DIR . 'includes/class-cli.php';
			WP_CLI::add_command( 'avesta-sync', 'Avesta_Sync\Cli' );
		}
	}
);
