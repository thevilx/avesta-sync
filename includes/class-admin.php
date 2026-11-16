<?php
/**
 * Admin UI: Tools > Avesta Sync.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page and settings handlers (AJAX lives in Ajax).
 */
class Admin {

	const SLUG = 'avesta-sync';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_avesta_sync_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_avesta_sync_regen', array( __CLASS__, 'regen' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_management_page( __( 'Avesta Sync', 'avesta-sync' ), __( 'Avesta Sync', 'avesta-sync' ), Settings::capability(), self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Script handles in load order (each depends on the previous one).
	 *
	 * @return string[] Handle suffix => file.
	 */
	private static function scripts() {
		return array(
			'core'     => 'as-core.js',
			'tabs'     => 'as-tabs.js',
			'diff'     => 'as-diff.js',
			'diffmodal' => 'as-diff-modal.js',
			'sync'     => 'as-sync.js',
			'syncrun'  => 'as-sync-run.js',
			'apply'    => 'as-apply.js',
			'panels'   => 'as-panels.js',
			'backups'  => 'as-backups.js',
			'history'  => 'as-history.js',
			'settings' => 'as-settings.js',
		);
	}

	/**
	 * Enqueue assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'avesta-sync-admin', AVESTA_SYNC_URL . 'assets/admin.css', array( 'dashicons' ), AVESTA_SYNC_VERSION );
		wp_enqueue_style( 'avesta-sync-sync', AVESTA_SYNC_URL . 'assets/as-sync.css', array( 'avesta-sync-admin' ), AVESTA_SYNC_VERSION );
		if ( 0 === strpos( (string) get_user_locale(), 'fa' ) ) {
			// Persian UI: Vazirmatn web font, loaded from Google Fonts on this screen only (disclosed in readme.txt).
			wp_enqueue_style( 'avesta-sync-vazirmatn', 'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- a ver query arg breaks the Google Fonts URL.
			wp_add_inline_style( 'avesta-sync-admin', '.avesta-sync{font-family:Vazirmatn,Tahoma,sans-serif}' );
		}
		$prev = array();
		foreach ( self::scripts() as $name => $file ) {
			$handle = 'avesta-sync-' . $name;
			wp_enqueue_script( $handle, AVESTA_SYNC_URL . 'assets/' . $file, $prev, AVESTA_SYNC_VERSION, true );
			$prev = array( $handle );
		}
		wp_localize_script(
			'avesta-sync-core',
			'AvestaSync',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'avesta_sync' ),
				'locale'  => str_replace( '_', '-', get_user_locale() ),
				'i18n'    => require trailingslashit( AVESTA_SYNC_DIR ) . 'includes/views/i18n.php',
			)
		);
	}

	/**
	 * Save settings.
	 */
	public static function save() {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'avesta-sync' ), 403 );
		}
		check_admin_referer( 'avesta_sync_save' );
		$raw = isset( $_POST['avesta_sync'] ) && is_array( $_POST['avesta_sync'] ) ? wp_unslash( $_POST['avesta_sync'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Settings::sanitize.
		$settings = Settings::sanitize( $raw, Settings::get() );
		update_option( Settings::OPTION, $settings, false );
		Schedule::sync( $settings );
		self::back( 'saved', 'settings' );
	}

	/**
	 * Regenerate this site's key.
	 */
	public static function regen() {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'avesta-sync' ), 403 );
		}
		check_admin_referer( 'avesta_sync_regen' );
		Auth::regenerate();
		self::back( 'regen', 'settings' );
	}

	/**
	 * Redirect back to the page.
	 *
	 * @param string $msg Notice code.
	 * @param string $tab Tab to open.
	 */
	private static function back( $msg, $tab = 'dashboard' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG,
					'as_msg' => $msg,
					'tab'    => $tab,
				),
				admin_url( 'tools.php' )
			) . '#' . rawurlencode( $tab )
		);
		exit;
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'avesta-sync' ), 403 );
		}
		$s    = Settings::get();
		$key  = Auth::key();
		$msg  = isset( $_GET['as_msg'] ) ? sanitize_key( wp_unslash( $_GET['as_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$dir  = trailingslashit( AVESTA_SYNC_DIR ) . 'includes/views/';
		$tabs = array(
			'dashboard' => array( __( 'Dashboard', 'avesta-sync' ), 'dashboard' ),
			'sync'      => array( __( 'Sync', 'avesta-sync' ), 'randomize' ),
			'backups'   => array( __( 'Backups', 'avesta-sync' ), 'backup' ),
			'history'   => array( __( 'History', 'avesta-sync' ), 'list-view' ),
			'settings'  => array( __( 'Settings', 'avesta-sync' ), 'admin-generic' ),
		);
		?>
		<div class="wrap avesta-sync">
			<header class="as-header">
				<div class="as-header__brand">
					<img class="as-logo" src="<?php echo esc_url( AVESTA_SYNC_URL . 'assets/logo.svg' ); ?>" width="36" height="36" alt="">
					<h1 class="as-header__title"><?php esc_html_e( 'Avesta Sync', 'avesta-sync' ); ?></h1>
					<span class="as-header__ver">v<?php echo esc_html( AVESTA_SYNC_VERSION ); ?></span>
				</div>
				<span class="as-pill is-idle" id="as-pill"><span class="as-pill__dot" aria-hidden="true"></span><span class="as-pill__text"><?php esc_html_e( 'Checking connection...', 'avesta-sync' ); ?></span></span>
			</header>
			<hr class="wp-header-end">
			<div class="as-tabs">
				<div class="as-tablist" id="as-tablist" role="tablist" aria-label="<?php esc_attr_e( 'Avesta Sync sections', 'avesta-sync' ); ?>">
					<?php foreach ( $tabs as $id => $tab ) : ?>
						<button type="button" class="as-tab" role="tab" id="as-tab-<?php echo esc_attr( $id ); ?>" aria-controls="as-panel-<?php echo esc_attr( $id ); ?>" aria-selected="false" tabindex="-1">
							<span class="dashicons dashicons-<?php echo esc_attr( $tab[1] ); ?>" aria-hidden="true"></span>
							<span class="as-tab__label"><?php echo esc_html( $tab[0] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
			<?php foreach ( array_keys( $tabs ) as $id ) : ?>
				<div class="as-tabpanel" role="tabpanel" id="as-panel-<?php echo esc_attr( $id ); ?>" aria-labelledby="as-tab-<?php echo esc_attr( $id ); ?>" tabindex="0" hidden>
					<?php include $dir . $id . '.php'; ?>
				</div>
			<?php endforeach; ?>
			<div id="as-announce" class="screen-reader-text" role="status" aria-live="polite"></div>
		</div>
		<?php
	}
}
