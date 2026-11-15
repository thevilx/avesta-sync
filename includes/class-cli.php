<?php
/**
 * WP-CLI commands.
 *
 * @package Avesta_Sync
 */

namespace Avesta_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Sync from the command line.
 */
class Cli {

	/**
	 * Show differences between this site and the remote.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scopes>]
	 * : Comma list of theme,plugins,content,uploads,woocommerce. Defaults to saved settings.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function status( $args, $assoc ) {
		$ctx = $this->compare( $assoc );
		$this->print_rows( $ctx['rows'] );
		\WP_CLI::success( sprintf( '%d differing, %d identical.', count( $ctx['rows'] ), $ctx['identical'] ) );
	}

	/**
	 * Pull remote changes (remote-new / remote-changed) to this site.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scopes>]
	 * : Comma list of theme,plugins,content,uploads,woocommerce.
	 *
	 * [--stock]
	 * : Also overwrite WooCommerce stock quantity and status of existing products (default: stock is left alone).
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * [--dry-run]
	 * : Only print what would be applied.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function pull( $args, $assoc ) {
		$this->run( 'pull', $assoc );
	}

	/**
	 * Push local changes (local-new / local-changed) to the remote.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scopes>]
	 * : Comma list of theme,plugins,content,uploads,woocommerce.
	 *
	 * [--stock]
	 * : Also overwrite WooCommerce stock quantity and status of existing products (default: stock is left alone).
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * [--dry-run]
	 * : Only print what would be applied.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function push( $args, $assoc ) {
		$this->run( 'push', $assoc );
	}

	/**
	 * List backup runs.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function backups( $args, $assoc ) {
		$out = array();
		foreach ( Backup::runs() as $r ) {
			$out[] = array(
				'run'   => $r['run'],
				'time'  => gmdate( 'Y-m-d H:i:s', $r['time'] ) . ' UTC',
				'files' => $r['files'],
				'size'  => size_format( $r['size'] ),
			);
		}
		if ( ! $out ) {
			\WP_CLI::success( 'No backups.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $out, array( 'run', 'time', 'files', 'size' ) );
	}

	/**
	 * Restore one backed-up item (the current version is backed up first).
	 *
	 * ## OPTIONS
	 *
	 * <run>
	 * : Run id, e.g. 20260101-120000 (see `backups`).
	 *
	 * <path>
	 * : Item path, e.g. themes/my-theme/style.css or content/page_about.json.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function restore( $args, $assoc ) {
		\WP_CLI::confirm( sprintf( 'Restore %s from backup %s?', $args[1], $args[0] ), $assoc );
		$msg = Backup::restore( $args[0], $args[1] );
		Diff::flush();
		if ( is_wp_error( $msg ) ) {
			\WP_CLI::error( $msg->get_error_message() );
		}
		\WP_CLI::success( $msg );
	}

	/**
	 * Compare via the shared engine.
	 *
	 * @param array $assoc Flags.
	 * @return array
	 */
	private function compare( array $assoc ) {
		$s = Settings::get();
		if ( ! empty( $assoc['scope'] ) ) {
			$s['scopes'] = array_values( array_intersect( Settings::scopes(), array_map( 'trim', explode( ',', $assoc['scope'] ) ) ) );
		}
		if ( ! $s['scopes'] ) {
			\WP_CLI::error( 'No valid scope selected.' );
		}
		$c = Client::from_settings( $s );
		if ( is_wp_error( $c ) ) {
			\WP_CLI::error( $c->get_error_message() );
		}
		$res = Sync::compare( $c, $s, $s['scopes'] );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		Diff::flush();
		Files::flush();
		Schedule::store( $res );
		if ( ! empty( $res['wc_block'] ) && in_array( 'woocommerce', $s['scopes'], true ) ) {
			\WP_CLI::warning( $res['wc_message'] );
		} elseif ( ! empty( $res['wc_warn'] ) && in_array( 'woocommerce', $s['scopes'], true ) ) {
			\WP_CLI::warning( $res['wc_message'] );
		}
		foreach ( (array) $res['wc_notes'] as $note ) {
			\WP_CLI::warning( $note );
		}
		$res['settings'] = $s;
		$res['client']   = $c;
		return $res;
	}

	/**
	 * Print a table.
	 *
	 * @param array $rows Rows.
	 */
	private function print_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'scope'   => $r['scope'],
				'status'  => $r['status'],
				'default' => $r['action'],
				'item'    => $r['id'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $out, array( 'scope', 'status', 'default', 'item' ) );
	}

	/**
	 * Apply all non-conflicting, non-deleting items for a direction.
	 *
	 * @param string $dir   push|pull.
	 * @param array  $assoc Flags.
	 */
	private function run( $dir, array $assoc ) {
		$ctx   = $this->compare( $assoc );
		$todo  = array_values(
			array_filter(
				$ctx['rows'],
				static function ( $r ) use ( $dir ) {
					return $r['action'] === $dir;
				}
			)
		);
		$skipped = count( $ctx['rows'] ) - count( $todo );
		usort(
			$todo,
			static function ( $a, $b ) {
				return $a['ord'] <=> $b['ord'];
			}
		);
		if ( ! $todo ) {
			\WP_CLI::success( sprintf( 'Nothing to %s. (%d items need manual attention: conflicts, deletions or opposite direction; use the admin UI.)', $dir, $skipped ) );
			return;
		}
		$this->print_rows( $todo );
		if ( ! empty( $assoc['dry-run'] ) ) {
			\WP_CLI::success( sprintf( 'Dry run: %d item(s) would be applied (%s), %d left for manual review. Nothing was changed.', count( $todo ), $dir, $skipped ) );
			return;
		}
		\WP_CLI::confirm( sprintf( 'Apply %d item(s) (%s)?', count( $todo ), $dir ), $assoc );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running sync step.
		$run  = Backup::new_run();
		$fail = 0;
		$bar  = \WP_CLI\Utils\make_progress_bar( ucfirst( $dir ), count( $todo ) );
		foreach ( $todo as $r ) {
			$msg = Sync::apply(
				$ctx['client'],
				$ctx['settings'],
				array(
					'scope'  => $r['scope'],
					'id'     => $r['id'],
					'action' => $dir,
					'del'    => false,
					'stock'  => ! empty( $assoc['stock'] ),
				),
				$run
			);
			History::log_item(
				$run,
				'cli',
				$ctx['client']->url(),
				array(
					'scope'  => $r['scope'],
					'id'     => $r['id'],
					'label'  => $r['label'],
					'action' => $dir,
					'del'    => false,
				),
				! is_wp_error( $msg ),
				$msg
			);
			if ( is_wp_error( $msg ) ) {
				++$fail;
				\WP_CLI::warning( $r['id'] . ': ' . $msg->get_error_message() );
			}
			$bar->tick();
		}
		$bar->finish();
		Diff::flush();
		Files::flush();
		if ( $fail ) {
			\WP_CLI::error( sprintf( '%d of %d failed (run %s). Overwritten items are backed up; see the Backups tab or `wp avesta-sync backups`.', $fail, count( $todo ), $run ) );
		}
		\WP_CLI::success( sprintf( '%d item(s) synced. %d left for manual review.', count( $todo ), $skipped ) );
	}
}
