<?php
/**
 * Settings tab markup.
 *
 * Expects $s (Settings::get()), $key (this site's key) and $msg (notice code).
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

$avesta_sync_scope_info = array(
	'theme'   => array( __( 'Theme files', 'avesta-sync' ), __( 'Templates, styles and assets of the themes you pick.', 'avesta-sync' ) ),
	'plugins' => array( __( 'Custom plugins', 'avesta-sync' ), __( 'Files of the plugin folders you pick. Activation state is never synced.', 'avesta-sync' ) ),
	'content' => array( __( 'Content', 'avesta-sync' ), __( 'Pages, posts, templates, menus and a few options.', 'avesta-sync' ) ),
	'uploads' => array( __( 'Media uploads', 'avesta-sync' ), __( 'Files in the uploads folder.', 'avesta-sync' ) ),
);
$avesta_sync_woo_on     = class_exists( 'WooCommerce' );
$avesta_sync_woo_opts   = array(
	'wc_products'   => array( __( 'Products', 'avesta-sync' ), __( 'Simple, variable, grouped and external products, including their variations.', 'avesta-sync' ) ),
	'wc_taxonomies' => array( __( 'Categories, tags and attributes', 'avesta-sync' ), __( 'Product categories, tags and global attributes with their terms.', 'avesta-sync' ) ),
	'wc_coupons'    => array( __( 'Coupons', 'avesta-sync' ), __( 'Discount codes and their restrictions.', 'avesta-sync' ) ),
	'wc_settings'   => array( __( 'Store settings', 'avesta-sync' ), __( 'General, tax and shipping settings. Payment gateways and API keys are never synced.', 'avesta-sync' ) ),
);
$avesta_sync_schedules  = array(
	'off'        => __( 'Off', 'avesta-sync' ),
	'hourly'     => __( 'Hourly', 'avesta-sync' ),
	'twicedaily' => __( 'Twice daily', 'avesta-sync' ),
	'daily'      => __( 'Daily', 'avesta-sync' ),
);
?>
<div class="as-panel__head">
	<div>
		<h2 class="as-panel__title"><?php esc_html_e( 'Settings', 'avesta-sync' ); ?></h2>
		<p class="as-panel__lead"><?php esc_html_e( 'Connect this site to the other one and choose what is synced.', 'avesta-sync' ); ?></p>
	</div>
</div>

<?php if ( 'saved' === $msg ) : ?>
	<div class="as-alert as-alert--success" role="status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div class="as-alert__text"><?php esc_html_e( 'Settings saved.', 'avesta-sync' ); ?></div></div>
<?php elseif ( 'regen' === $msg ) : ?>
	<div class="as-alert as-alert--warning" role="status"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div class="as-alert__text"><?php esc_html_e( 'A new key was generated. Update it on the other site.', 'avesta-sync' ); ?></div></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="as-settings" class="as-form">
	<input type="hidden" name="action" value="avesta_sync_save">
	<?php wp_nonce_field( 'avesta_sync_save' ); ?>

	<section class="as-card">
		<h3 class="as-card__title"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span><?php esc_html_e( 'Connection', 'avesta-sync' ); ?></span></h3>
		<div class="as-field">
			<label for="as-url"><?php esc_html_e( 'Remote site URL', 'avesta-sync' ); ?></label>
			<input type="url" id="as-url" name="avesta_sync[remote_url]" class="as-input" dir="ltr" placeholder="https://example.com" value="<?php echo esc_attr( $s['remote_url'] ); ?>" aria-describedby="as-url-help">
			<p class="as-help" id="as-url-help"><?php esc_html_e( 'HTTPS is required (localhost and *.test excepted).', 'avesta-sync' ); ?></p>
		</div>
		<div class="as-field">
			<label for="as-rkey"><?php esc_html_e( 'Remote secret key', 'avesta-sync' ); ?></label>
			<div class="as-inputrow">
				<input type="password" id="as-rkey" name="avesta_sync[remote_key]" class="as-input as-input--code" dir="ltr" autocomplete="off" value="" placeholder="<?php echo '' !== $s['remote_key'] ? esc_attr__( 'Saved - leave blank to keep', 'avesta-sync' ) : ''; ?>" aria-describedby="as-rkey-help">
				<button type="button" class="as-btn as-btn--secondary" id="as-rkey-toggle" aria-pressed="false"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="as-btn__label"><?php esc_html_e( 'Show', 'avesta-sync' ); ?></span></button>
			</div>
			<p class="as-help" id="as-rkey-help"><?php esc_html_e( 'Copy it from the Settings tab of the other site.', 'avesta-sync' ); ?></p>
		</div>
		<div class="as-actions">
			<button type="button" class="as-btn as-btn--secondary" id="as-test"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span><span class="as-btn__label"><?php esc_html_e( 'Test connection', 'avesta-sync' ); ?></span></button>
			<span id="as-test-result" class="as-inline-result" role="status" aria-live="polite"></span>
		</div>
	</section>

	<section class="as-card">
		<h3 class="as-card__title"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span><span><?php esc_html_e( 'This site\'s key', 'avesta-sync' ); ?></span></h3>
		<p class="as-help"><?php esc_html_e( 'Paste this key into the "Remote secret key" field of the other site so it can call this site.', 'avesta-sync' ); ?></p>
		<div class="as-field">
			<label for="as-own-key" class="screen-reader-text"><?php esc_html_e( 'Key to copy to the other site', 'avesta-sync' ); ?></label>
			<div class="as-inputrow">
				<input type="password" id="as-own-key" class="as-input as-input--code" dir="ltr" readonly autocomplete="off" value="<?php echo esc_attr( $key ); ?>">
				<button type="button" class="as-btn as-btn--secondary" id="as-key-toggle" aria-pressed="false"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="as-btn__label"><?php esc_html_e( 'Show', 'avesta-sync' ); ?></span></button>
				<button type="button" class="as-btn as-btn--secondary" id="as-copy"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="as-btn__label"><?php esc_html_e( 'Copy', 'avesta-sync' ); ?></span></button>
			</div>
		</div>
		<p class="as-alert as-alert--warning as-alert--inline"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span class="as-alert__text"><?php esc_html_e( 'Whoever holds this key can write files to this site. Share it only with the other site and regenerate it if it may have leaked.', 'avesta-sync' ); ?></span></p>
		<button type="button" class="as-btn as-btn--danger-link" id="as-regen"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span><span class="as-btn__label"><?php esc_html_e( 'Regenerate key', 'avesta-sync' ); ?></span></button>
	</section>

	<section class="as-card">
		<h3 class="as-card__title"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><span><?php esc_html_e( 'What to sync', 'avesta-sync' ); ?></span></h3>
		<fieldset class="as-fieldset">
			<legend class="screen-reader-text"><?php esc_html_e( 'What to sync', 'avesta-sync' ); ?></legend>
			<div class="as-scopes">
				<?php foreach ( $avesta_sync_scope_info as $avesta_sync_k => $avesta_sync_info ) : ?>
					<label class="as-scope">
						<input type="checkbox" name="avesta_sync[scopes][]" value="<?php echo esc_attr( $avesta_sync_k ); ?>" <?php checked( in_array( $avesta_sync_k, $s['scopes'], true ) ); ?>>
						<span class="as-scope__body">
							<span class="as-scope__title"><?php echo esc_html( $avesta_sync_info[0] ); ?></span>
							<span class="as-scope__desc"><?php echo esc_html( $avesta_sync_info[1] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
				<?php if ( $avesta_sync_woo_on ) : ?>
					<label class="as-scope">
						<input type="checkbox" name="avesta_sync[scopes][]" value="woocommerce" <?php checked( in_array( 'woocommerce', $s['scopes'], true ) ); ?>>
						<span class="as-scope__body">
							<span class="as-scope__title"><?php esc_html_e( 'WooCommerce', 'avesta-sync' ); ?></span>
							<span class="as-scope__desc"><?php esc_html_e( 'Products, categories and attributes, coupons and store settings. Orders and customers are never touched.', 'avesta-sync' ); ?></span>
						</span>
					</label>
				<?php else : ?>
					<div class="as-scope is-disabled">
						<input type="checkbox" disabled id="as-woo-off" aria-describedby="as-woo-off-why">
						<span class="as-scope__body">
							<label class="as-scope__title" for="as-woo-off"><?php esc_html_e( 'WooCommerce', 'avesta-sync' ); ?></label>
							<span class="as-scope__desc" id="as-woo-off-why"><?php esc_html_e( 'WooCommerce is not active on this site, so its products and store settings cannot be synced.', 'avesta-sync' ); ?></span>
						</span>
					</div>
					<?php if ( in_array( 'woocommerce', $s['scopes'], true ) ) : // Keep the saved choice while WooCommerce is inactive. ?>
						<input type="hidden" name="avesta_sync[scopes][]" value="woocommerce">
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</fieldset>

		<fieldset class="as-fieldset as-checklist" data-scope-box="theme">
			<legend><?php esc_html_e( 'Themes to sync', 'avesta-sync' ); ?></legend>
			<div class="as-checks">
				<?php foreach ( Settings::theme_slugs() as $avesta_sync_slug ) : ?>
					<label class="as-check"><input type="checkbox" name="avesta_sync[themes][]" value="<?php echo esc_attr( $avesta_sync_slug ); ?>" <?php checked( in_array( $avesta_sync_slug, $s['themes'], true ) ); ?>><span dir="ltr"><?php echo esc_html( $avesta_sync_slug ); ?></span></label>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<fieldset class="as-fieldset as-checklist" data-scope-box="plugins">
			<legend><?php esc_html_e( 'Plugin folders to sync', 'avesta-sync' ); ?></legend>
			<div class="as-checks">
				<?php foreach ( Settings::plugin_folders() as $avesta_sync_slug ) : ?>
					<label class="as-check"><input type="checkbox" name="avesta_sync[plugins][]" value="<?php echo esc_attr( $avesta_sync_slug ); ?>" <?php checked( in_array( $avesta_sync_slug, $s['plugins'], true ) ); ?>><span dir="ltr"><?php echo esc_html( $avesta_sync_slug ); ?></span></label>
				<?php endforeach; ?>
			</div>
			<p class="as-help"><?php esc_html_e( 'Only files are synced; plugin activation state never is. Avesta Sync itself is excluded.', 'avesta-sync' ); ?></p>
		</fieldset>

		<input type="hidden" name="avesta_sync[wc_opts_present]" value="1">
		<?php if ( $avesta_sync_woo_on ) : ?>
			<fieldset class="as-fieldset as-checklist" data-scope-box="woocommerce">
				<legend><?php esc_html_e( 'WooCommerce items to sync', 'avesta-sync' ); ?></legend>
				<div class="as-checks as-checks--stack">
					<?php foreach ( $avesta_sync_woo_opts as $avesta_sync_k => $avesta_sync_info ) : ?>
						<label class="as-scope as-scope--sub">
							<input type="checkbox" name="avesta_sync[<?php echo esc_attr( $avesta_sync_k ); ?>]" value="1" <?php checked( ! empty( $s[ $avesta_sync_k ] ) ); ?>>
							<span class="as-scope__body">
								<span class="as-scope__title"><?php echo esc_html( $avesta_sync_info[0] ); ?></span>
								<span class="as-scope__desc"><?php echo esc_html( $avesta_sync_info[1] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		<?php else : ?>
			<?php foreach ( array_keys( $avesta_sync_woo_opts ) as $avesta_sync_k ) : ?>
				<?php if ( ! empty( $s[ $avesta_sync_k ] ) ) : ?>
					<input type="hidden" name="avesta_sync[<?php echo esc_attr( $avesta_sync_k ); ?>]" value="1">
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endif; ?>

		<label class="as-check as-check--block">
			<input type="hidden" name="avesta_sync[media_library]" value="0">
			<input type="checkbox" name="avesta_sync[media_library]" value="1" <?php checked( ! empty( $s['media_library'] ) ); ?>>
			<span class="as-scope__body">
				<span class="as-scope__title"><?php esc_html_e( 'Include Media Library entries', 'avesta-sync' ); ?></span>
				<span class="as-scope__desc"><?php esc_html_e( 'Also sync the attachment records (titles, captions, alt text) of synced uploads.', 'avesta-sync' ); ?></span>
			</span>
		</label>
	</section>

	<section class="as-card">
		<h3 class="as-card__title"><span class="dashicons dashicons-hidden" aria-hidden="true"></span><span><?php esc_html_e( 'Exclude patterns', 'avesta-sync' ); ?></span></h3>
		<div class="as-field">
			<label for="as-excludes"><?php esc_html_e( 'Paths to leave out', 'avesta-sync' ); ?></label>
			<textarea id="as-excludes" name="avesta_sync[excludes]" class="as-input as-input--code" dir="ltr" rows="5" aria-describedby="as-excludes-help"><?php echo esc_textarea( implode( "\n", (array) $s['excludes'] ) ); ?></textarea>
			<p class="as-help" id="as-excludes-help"><?php esc_html_e( 'One pattern per line. Examples:', 'avesta-sync' ); ?> <code>*.map</code> <code>themes/my-theme/src/*</code></p>
		</div>
	</section>

	<section class="as-card">
		<h3 class="as-card__title"><span class="dashicons dashicons-clock" aria-hidden="true"></span><span><?php esc_html_e( 'Scheduled check', 'avesta-sync' ); ?></span></h3>
		<p class="as-help"><?php esc_html_e( 'A scheduled check only compares both sites and reports differences. It never changes anything.', 'avesta-sync' ); ?></p>
		<div class="as-grid2">
			<div class="as-field">
				<label for="as-schedule"><?php esc_html_e( 'Check how often', 'avesta-sync' ); ?></label>
				<select id="as-schedule" name="avesta_sync[schedule]" class="as-input">
					<?php foreach ( $avesta_sync_schedules as $avesta_sync_val => $avesta_sync_lbl ) : ?>
						<option value="<?php echo esc_attr( $avesta_sync_val ); ?>" <?php selected( $s['schedule'], $avesta_sync_val ); ?>><?php echo esc_html( $avesta_sync_lbl ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="as-field">
				<label for="as-email"><?php esc_html_e( 'Email the result to', 'avesta-sync' ); ?></label>
				<input type="email" id="as-email" name="avesta_sync[notify_email]" class="as-input" dir="ltr" value="<?php echo esc_attr( $s['notify_email'] ); ?>" placeholder="name@example.com">
			</div>
		</div>
	</section>

	<section class="as-card">
		<h3 class="as-card__title"><span class="dashicons dashicons-backup" aria-hidden="true"></span><span><?php esc_html_e( 'Backups', 'avesta-sync' ); ?></span></h3>
		<div class="as-field as-field--narrow">
			<label for="as-keep"><?php esc_html_e( 'Backup runs to keep', 'avesta-sync' ); ?></label>
			<input type="number" id="as-keep" name="avesta_sync[backup_keep]" class="as-input" min="0" max="200" step="1" value="<?php echo esc_attr( (int) $s['backup_keep'] ); ?>" aria-describedby="as-keep-help">
			<p class="as-help" id="as-keep-help"><?php esc_html_e( '0 keeps all backups. Older runs are deleted automatically.', 'avesta-sync' ); ?></p>
		</div>
	</section>

	<div class="as-savebar">
		<button type="submit" class="as-btn as-btn--primary" id="as-save"><span class="dashicons dashicons-saved" aria-hidden="true"></span><span class="as-btn__label"><?php esc_html_e( 'Save settings', 'avesta-sync' ); ?></span></button>
		<span class="as-dirty" id="as-dirty" hidden><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e( 'You have unsaved changes.', 'avesta-sync' ); ?></span>
	</div>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="as-regen-form" class="as-hidden-form">
	<input type="hidden" name="action" value="avesta_sync_regen">
	<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'avesta_sync_regen' ) ); ?>">
</form>
