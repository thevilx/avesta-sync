<?php
/**
 * Admin UI: Tools > Avesta Sync.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page and AJAX handlers.
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
		add_action( 'wp_ajax_avesta_sync_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_avesta_sync_compare', array( __CLASS__, 'ajax_compare' ) );
		add_action( 'wp_ajax_avesta_sync_apply', array( __CLASS__, 'ajax_apply' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_management_page( __( 'Avesta Sync', 'avesta-sync' ), __( 'Avesta Sync', 'avesta-sync' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
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
		wp_enqueue_style( 'avesta-sync-admin', AVESTA_SYNC_URL . 'assets/admin.css', array(), AVESTA_SYNC_VERSION );
		wp_enqueue_script( 'avesta-sync-admin', AVESTA_SYNC_URL . 'assets/admin.js', array(), AVESTA_SYNC_VERSION, true );
		wp_localize_script(
			'avesta-sync-admin',
			'AvestaSync',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'avesta_sync' ),
				'i18n'    => array(
					'working'     => __( 'Working...', 'avesta-sync' ),
					'copied'      => __( 'Copied', 'avesta-sync' ),
					'nothing'     => __( 'Everything in the selected scopes is identical.', 'avesta-sync' ),
					'noneSel'     => __( 'Nothing selected to apply.', 'avesta-sync' ),
					'confirmDel'  => __( 'Some selected items DELETE files or content on the receiving side (a backup is kept). Continue?', 'avesta-sync' ),
					'confirmRegen' => __( 'Regenerate this site\'s key? The other site must be updated with the new key.', 'avesta-sync' ),
					'done'        => __( 'Finished', 'avesta-sync' ),
					'failed'      => __( 'failed', 'avesta-sync' ),
					'ok'          => __( 'ok', 'avesta-sync' ),
					'mediaNote'   => __( 'Note: media files were copied, but attachment records are not synced. Regenerate thumbnails / import media in the Media Library if new files do not show up.', 'avesta-sync' ),
					'local'       => __( 'Local', 'avesta-sync' ),
					'remote'      => __( 'Remote', 'avesta-sync' ),
					'skip'        => __( 'Skip', 'avesta-sync' ),
					'push'        => __( 'Push →', 'avesta-sync' ),
					'pull'        => __( '← Pull', 'avesta-sync' ),
					'pushDel'     => __( 'Push → (delete on remote)', 'avesta-sync' ),
					'pullDel'     => __( '← Pull (delete locally)', 'avesta-sync' ),
					'pushRestore' => __( 'Push → (restore on remote)', 'avesta-sync' ),
					'pullRestore' => __( '← Pull (restore locally)', 'avesta-sync' ),
				),
			)
		);
	}

	/**
	 * Save settings.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'avesta-sync' ), 403 );
		}
		check_admin_referer( 'avesta_sync_save' );
		$raw = isset( $_POST['avesta_sync'] ) && is_array( $_POST['avesta_sync'] ) ? wp_unslash( $_POST['avesta_sync'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Settings::sanitize.
		update_option( Settings::OPTION, Settings::sanitize( $raw, Settings::get() ), false );
		self::back( 'saved' );
	}

	/**
	 * Regenerate this site's key.
	 */
	public static function regen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'avesta-sync' ), 403 );
		}
		check_admin_referer( 'avesta_sync_regen' );
		Auth::regenerate();
		self::back( 'regen' );
	}

	/**
	 * Redirect back to the page.
	 *
	 * @param string $msg Notice code.
	 */
	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'as_msg' => $msg ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Common AJAX guard.
	 */
	private static function guard() {
		check_ajax_referer( 'avesta_sync', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'avesta-sync' ) ), 403 );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		@set_time_limit( 0 );
		ignore_user_abort( true );
	}

	/**
	 * Send a WP_Error as JSON error.
	 *
	 * @param \WP_Error $e Error.
	 */
	private static function fail( $e ) {
		wp_send_json_error( array( 'message' => $e->get_error_message(), 'code' => $e->get_error_code() ) );
	}

	/**
	 * AJAX: test connection (uses posted, unsaved values if given).
	 */
	public static function ajax_test() {
		self::guard();
		$s   = Settings::get();
		$url = isset( $_POST['remote_url'] ) ? untrailingslashit( esc_url_raw( trim( wp_unslash( $_POST['remote_url'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$key = isset( $_POST['remote_key'] ) ? preg_replace( '/[^\x21-\x7E]/', '', wp_unslash( $_POST['remote_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $url ) {
			$s['remote_url'] = $url;
		}
		if ( '' !== $key ) {
			$s['remote_key'] = $key;
		}
		$c = Client::from_settings( $s );
		if ( is_wp_error( $c ) ) {
			self::fail( $c );
		}
		$info = $c->info();
		if ( is_wp_error( $info ) ) {
			self::fail( $info );
		}
		wp_send_json_success(
			array(
				'site'          => $info['site_url'],
				'version'       => $info['version'],
				'php'           => $info['php_version'],
				'post_max_size' => size_format( (int) $info['post_max_size'] ),
				'chunk'         => size_format( $c->chunk_size() ),
				'themes'        => wp_list_pluck( (array) $info['themes'], 'slug' ),
			)
		);
	}

	/**
	 * AJAX: compare.
	 */
	public static function ajax_compare() {
		self::guard();
		$s = Settings::get();
		$c = Client::from_settings( $s );
		if ( is_wp_error( $c ) ) {
			self::fail( $c );
		}
		if ( ! $s['scopes'] ) {
			wp_send_json_error( array( 'message' => __( 'No scope selected. Tick at least one scope and save settings.', 'avesta-sync' ) ) );
		}
		$res = Sync::compare( $c, $s, $s['scopes'] );
		if ( is_wp_error( $res ) ) {
			self::fail( $res );
		}
		Backup::purge_tmp();
		Diff::flush();
		Files::flush();
		$res['run'] = Backup::new_run();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: apply one item.
	 */
	public static function ajax_apply() {
		self::guard();
		$raw  = isset( $_POST['item'] ) ? json_decode( wp_unslash( $_POST['item'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$run  = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( $_POST['run'] ) ) : '';
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Bad request.', 'avesta-sync' ) ) );
		}
		$item = array(
			'scope'  => isset( $raw['scope'] ) ? sanitize_key( $raw['scope'] ) : '',
			'id'     => isset( $raw['id'] ) && is_string( $raw['id'] ) ? $raw['id'] : '',
			'action' => isset( $raw['action'] ) ? sanitize_key( $raw['action'] ) : '',
			'del'    => ! empty( $raw['del'] ),
		);
		$s = Settings::get();
		$c = Client::from_settings( $s );
		if ( is_wp_error( $c ) ) {
			self::fail( $c );
		}
		$msg = Sync::apply( $c, $s, $item, $run );
		Diff::flush();
		Files::flush();
		if ( is_wp_error( $msg ) ) {
			self::fail( $msg );
		}
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'avesta-sync' ), 403 );
		}
		$s   = Settings::get();
		$key = Auth::key();
		$msg = isset( $_GET['as_msg'] ) ? sanitize_key( wp_unslash( $_GET['as_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap avesta-sync">
			<h1><?php esc_html_e( 'Avesta Sync', 'avesta-sync' ); ?></h1>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'avesta-sync' ); ?></p></div>
			<?php elseif ( 'regen' === $msg ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'A new key was generated. Update it on the other site.', 'avesta-sync' ); ?></p></div>
			<?php endif; ?>

			<div class="as-card">
				<h2><?php esc_html_e( 'This site\'s secret key', 'avesta-sync' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste this key into the other site\'s "Remote secret key" field so it can call this site.', 'avesta-sync' ); ?></p>
				<p class="as-keyrow">
					<label class="screen-reader-text" for="as-own-key"><?php esc_html_e( 'This site\'s key', 'avesta-sync' ); ?></label>
					<input type="text" id="as-own-key" class="large-text code" readonly value="<?php echo esc_attr( $key ); ?>">
					<button type="button" class="button" id="as-copy"><?php esc_html_e( 'Copy', 'avesta-sync' ); ?></button>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="as-regen-form">
					<input type="hidden" name="action" value="avesta_sync_regen">
					<?php wp_nonce_field( 'avesta_sync_regen' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Regenerate key', 'avesta-sync' ); ?></button>
				</form>
			</div>

			<div class="as-card">
				<h2><?php esc_html_e( 'Settings', 'avesta-sync' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="as-settings">
					<input type="hidden" name="action" value="avesta_sync_save">
					<?php wp_nonce_field( 'avesta_sync_save' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="as-url"><?php esc_html_e( 'Remote site URL', 'avesta-sync' ); ?></label></th>
							<td><input type="url" id="as-url" name="avesta_sync[remote_url]" class="regular-text" placeholder="https://example.com" value="<?php echo esc_attr( $s['remote_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'HTTPS is required (localhost / *.test excepted).', 'avesta-sync' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="as-rkey"><?php esc_html_e( 'Remote secret key', 'avesta-sync' ); ?></label></th>
							<td><input type="password" id="as-rkey" name="avesta_sync[remote_key]" class="regular-text code" autocomplete="off" value="" placeholder="<?php echo '' !== $s['remote_key'] ? esc_attr__( 'Saved - leave blank to keep', 'avesta-sync' ) : ''; ?>">
							<p><button type="button" class="button" id="as-test"><?php esc_html_e( 'Test connection', 'avesta-sync' ); ?></button> <span id="as-test-result" role="status" aria-live="polite"></span></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'What to sync', 'avesta-sync' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Scopes', 'avesta-sync' ); ?></legend>
									<?php
									$labels = array(
										'theme'   => __( 'Theme files', 'avesta-sync' ),
										'plugins' => __( 'Custom plugins', 'avesta-sync' ),
										'content' => __( 'Content (pages, posts, templates, menus, a few options)', 'avesta-sync' ),
										'uploads' => __( 'Media uploads', 'avesta-sync' ),
									);
									foreach ( $labels as $k => $label ) {
										printf( '<label><input type="checkbox" name="avesta_sync[scopes][]" value="%1$s" %2$s> %3$s</label><br>', esc_attr( $k ), checked( in_array( $k, $s['scopes'], true ), true, false ), esc_html( $label ) );
									}
									?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Themes', 'avesta-sync' ); ?></th>
							<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Themes to sync', 'avesta-sync' ); ?></legend>
								<?php
								foreach ( Settings::theme_slugs() as $slug ) {
									printf( '<label class="as-inline"><input type="checkbox" name="avesta_sync[themes][]" value="%1$s" %2$s> %1$s</label> ', esc_attr( $slug ), checked( in_array( $slug, $s['themes'], true ), true, false ) );
								}
								?>
							</fieldset></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Plugins', 'avesta-sync' ); ?></th>
							<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Plugin folders to sync', 'avesta-sync' ); ?></legend>
								<?php
								foreach ( Settings::plugin_folders() as $slug ) {
									printf( '<label class="as-inline"><input type="checkbox" name="avesta_sync[plugins][]" value="%1$s" %2$s> %1$s</label> ', esc_attr( $slug ), checked( in_array( $slug, $s['plugins'], true ), true, false ) );
								}
								?>
								<p class="description"><?php esc_html_e( 'Only files are synced; plugin activation state never is. Avesta Sync itself is excluded.', 'avesta-sync' ); ?></p>
							</fieldset></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save settings', 'avesta-sync' ) ); ?>
				</form>
			</div>

			<div class="as-card" id="as-sync">
				<h2><?php esc_html_e( 'Sync', 'avesta-sync' ); ?></h2>
				<p><button type="button" class="button button-primary" id="as-compare"><?php esc_html_e( 'Compare', 'avesta-sync' ); ?></button>
				<span id="as-status" role="status" aria-live="polite"></span></p>
				<div id="as-results" hidden>
					<p class="as-toolbar">
						<button type="button" class="button" data-bulk="push"><?php esc_html_e( 'Select all pushes', 'avesta-sync' ); ?></button>
						<button type="button" class="button" data-bulk="pull"><?php esc_html_e( 'Select all pulls', 'avesta-sync' ); ?></button>
						<button type="button" class="button" data-bulk="conflict-push"><?php esc_html_e( 'Conflicts: push', 'avesta-sync' ); ?></button>
						<button type="button" class="button" data-bulk="conflict-pull"><?php esc_html_e( 'Conflicts: pull', 'avesta-sync' ); ?></button>
						<button type="button" class="button" data-bulk="none"><?php esc_html_e( 'Clear selection', 'avesta-sync' ); ?></button>
					</p>
					<div class="as-table-wrap"><table class="widefat striped" id="as-table">
						<thead><tr>
							<td class="check-column"><input type="checkbox" id="as-all" aria-label="<?php esc_attr_e( 'Select all rows', 'avesta-sync' ); ?>"></td>
							<th scope="col"><?php esc_html_e( 'Item', 'avesta-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'avesta-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Local', 'avesta-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Remote', 'avesta-sync' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Direction', 'avesta-sync' ); ?></th>
						</tr></thead>
						<tbody></tbody>
					</table></div>
					<p><button type="button" class="button button-primary" id="as-apply"><?php esc_html_e( 'Apply selected', 'avesta-sync' ); ?></button></p>
				</div>
				<progress id="as-progress" value="0" max="1" hidden></progress>
				<div id="as-log" class="as-log" role="log" aria-live="polite" hidden></div>
				<p id="as-summary" class="as-summary" role="status"></p>
			</div>
		</div>
		<?php
	}
}
