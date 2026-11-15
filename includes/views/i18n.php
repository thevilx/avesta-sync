<?php
/**
 * Translatable strings handed to the admin JavaScript (AvestaSync.i18n).
 *
 * Placeholders (%s, %d, %1$s) are replaced client-side.
 *
 * @package Avesta_Sync
 */

defined( 'ABSPATH' ) || exit;

return array(
	// Generic.
	'working'         => __( 'Working...', 'avesta-sync' ),
	'loading'         => __( 'Loading...', 'avesta-sync' ),
	'close'           => __( 'Close', 'avesta-sync' ),
	'cancel'          => __( 'Cancel', 'avesta-sync' ),
	'confirm'         => __( 'Confirm', 'avesta-sync' ),
	'delete'          => __( 'Delete', 'avesta-sync' ),
	'retry'           => __( 'Try again', 'avesta-sync' ),
	'show'            => __( 'Show', 'avesta-sync' ),
	'hide'            => __( 'Hide', 'avesta-sync' ),
	'copy'            => __( 'Copy', 'avesta-sync' ),
	'copied'          => __( 'Copied', 'avesta-sync' ),
	'ok'              => __( 'OK', 'avesta-sync' ),
	'failed'          => __( 'Failed', 'avesta-sync' ),
	'local'           => __( 'Local', 'avesta-sync' ),
	'remote'          => __( 'Remote', 'avesta-sync' ),
	'present'         => __( 'present', 'avesta-sync' ),
	'absent'          => __( 'missing', 'avesta-sync' ),
	'errTitle'        => __( 'Something went wrong', 'avesta-sync' ),
	'errGeneric'      => __( 'The request failed.', 'avesta-sync' ),
	/* translators: %s: start of the unexpected server response. */
	'errUnexpected'   => __( 'Unexpected server response: %s', 'avesta-sync' ),
	'units'           => array(
		_x( 'B', 'bytes', 'avesta-sync' ),
		_x( 'KB', 'kilobytes', 'avesta-sync' ),
		_x( 'MB', 'megabytes', 'avesta-sync' ),
		_x( 'GB', 'gigabytes', 'avesta-sync' ),
	),
	'pill'            => array(
		'idle'  => __( 'Checking connection...', 'avesta-sync' ),
		'ok'    => __( 'Connected', 'avesta-sync' ),
		'bad'   => __( 'Not connected', 'avesta-sync' ),
		'unset' => __( 'Not configured', 'avesta-sync' ),
	),
	'scopes'          => array(
		'theme'   => __( 'Theme', 'avesta-sync' ),
		'plugins' => __( 'Plugins', 'avesta-sync' ),
		'content' => __( 'Content', 'avesta-sync' ),
		'uploads' => __( 'Media', 'avesta-sync' ),
	),
	'schedules'       => array(
		'off'        => __( 'Off', 'avesta-sync' ),
		'hourly'     => __( 'Hourly', 'avesta-sync' ),
		'twicedaily' => __( 'Twice daily', 'avesta-sync' ),
		'daily'      => __( 'Daily', 'avesta-sync' ),
	),
	'status'          => array(
		'local-new'      => __( 'New on this site', 'avesta-sync' ),
		'local-changed'  => __( 'Changed locally', 'avesta-sync' ),
		'remote-new'     => __( 'New on remote', 'avesta-sync' ),
		'remote-changed' => __( 'Changed on remote', 'avesta-sync' ),
		'local-deleted'  => __( 'Deleted locally', 'avesta-sync' ),
		'remote-deleted' => __( 'Deleted on remote', 'avesta-sync' ),
		'conflict'       => __( 'Conflict', 'avesta-sync' ),
	),
	'srcAdmin'        => __( 'Admin', 'avesta-sync' ),
	'srcCli'          => __( 'WP-CLI', 'avesta-sync' ),
	'typeFile'        => __( 'File', 'avesta-sync' ),
	'typeContent'     => __( 'Content', 'avesta-sync' ),

	// Dashboard.
	'cardConnection'  => __( 'Connection', 'avesta-sync' ),
	'cardLastSync'    => __( 'Last sync', 'avesta-sync' ),
	'cardLastCheck'   => __( 'Last check', 'avesta-sync' ),
	'cardBackups'     => __( 'Backups', 'avesta-sync' ),
	'cardSchedule'    => __( 'Scheduled check', 'avesta-sync' ),
	'checking'        => __( 'Contacting the remote site...', 'avesta-sync' ),
	'notChecked'      => __( 'Connection not checked yet.', 'avesta-sync' ),
	'notConfigured'   => __( 'Get started in three steps:', 'avesta-sync' ),
	'step1'           => __( 'Install Avesta Sync on both sites.', 'avesta-sync' ),
	'step2'           => __( 'Copy this site\'s key into the other site\'s settings, and the other site\'s key and URL into this one.', 'avesta-sync' ),
	'step3'           => __( 'Click Compare, review the differences and apply what you want.', 'avesta-sync' ),
	'goSettings'      => __( 'Go to settings', 'avesta-sync' ),
	'cantReach'       => __( 'Cannot reach the remote site', 'avesta-sync' ),
	'remoteSite'      => __( 'Remote site', 'avesta-sync' ),
	'pluginVersion'   => __( 'Avesta Sync', 'avesta-sync' ),
	'wpVersion'       => __( 'WordPress', 'avesta-sync' ),
	'phpVersion'      => __( 'PHP', 'avesta-sync' ),
	'noSyncYet'       => __( 'No sync has been run yet.', 'avesta-sync' ),
	/* translators: %s: user display name. */
	'byUser'          => __( 'by %s', 'avesta-sync' ),
	/* translators: %s: number of items. */
	'nOk'             => __( '%s ok', 'avesta-sync' ),
	/* translators: %s: number of items. */
	'nFailed'         => __( '%s failed', 'avesta-sync' ),
	'viewHistory'     => __( 'View history', 'avesta-sync' ),
	'noCheckYet'      => __( 'No comparison has been run yet.', 'avesta-sync' ),
	/* translators: %s: number of identical items. */
	'inSync'          => __( 'In sync (%s identical items)', 'avesta-sync' ),
	'reviewSync'      => __( 'Review in Sync', 'avesta-sync' ),
	/* translators: %s: number of backup runs. */
	'nRuns' => array(
		/* translators: %s: number of backup runs. */
		'one'   => _n( '%s backup run', '%s backup runs', 1, 'avesta-sync' ),
		/* translators: %s: number of backup runs. */
		'other' => _n( '%s backup run', '%s backup runs', 2, 'avesta-sync' ),
	),
	/* translators: %s: size such as 1.2 MB. */
	'totalSize'       => __( '%s in total', 'avesta-sync' ),
	'noBackupsShort'  => __( 'Nothing backed up yet.', 'avesta-sync' ),
	'manageBackups'   => __( 'Manage backups', 'avesta-sync' ),
	'nextRun'         => __( 'Next run:', 'avesta-sync' ),
	'changeSchedule'  => __( 'Change schedule', 'avesta-sync' ),

	// Sync.
	'skip'            => __( 'Skip', 'avesta-sync' ),
	'push'            => __( 'Push', 'avesta-sync' ),
	'pull'            => __( 'Pull', 'avesta-sync' ),
	'pushHint'        => __( 'From this site to the remote site', 'avesta-sync' ),
	'pullHint'        => __( 'From the remote site to this site', 'avesta-sync' ),
	'pushDel'         => __( 'Push (delete on remote)', 'avesta-sync' ),
	'pullDel'         => __( 'Pull (delete locally)', 'avesta-sync' ),
	'pushRestore'     => __( 'Push (restore on remote)', 'avesta-sync' ),
	'pullRestore'     => __( 'Pull (restore locally)', 'avesta-sync' ),
	'comparing'       => __( 'Comparing both sites. This can take a while on large sites...', 'avesta-sync' ),
	'nothingTitle'    => __( 'Everything in the selected scopes is identical.', 'avesta-sync' ),
	/* translators: %s: number of identical items. */
	'nothingText'     => __( '%s items checked, nothing to sync.', 'avesta-sync' ),
	/* translators: 1: number of differences, 2: number of identical items. */
	'compared'        => __( 'Comparison finished: %1$s differences, %2$s identical.', 'avesta-sync' ),
	'identical'       => __( 'identical', 'avesta-sync' ),
	/* translators: %s: file path. */
	'selectRow'       => __( 'Select %s', 'avesta-sync' ),
	/* translators: %s: group name. */
	'selectGroup'     => __( 'Select all shown items in %s', 'avesta-sync' ),
	/* translators: %s: file path. */
	'directionFor'    => __( 'Direction for %s', 'avesta-sync' ),
	'viewDiff'        => __( 'View diff', 'avesta-sync' ),
	/* translators: %s: file path. */
	'viewDiffFor'     => __( 'View diff for %s', 'avesta-sync' ),
	/* translators: %s: number of items. */
	'nItems' => array(
		/* translators: %s: number of items. */
		'one'   => _n( '%s item', '%s items', 1, 'avesta-sync' ),
		/* translators: %s: number of items. */
		'other' => _n( '%s item', '%s items', 2, 'avesta-sync' ),
	),
	/* translators: %s: number of selected items. */
	'nSelected' => array(
		/* translators: %s: number of selected items. */
		'one'   => _n( '%s selected', '%s selected', 1, 'avesta-sync' ),
		/* translators: %s: number of selected items. */
		'other' => _n( '%s selected', '%s selected', 2, 'avesta-sync' ),
	),
	/* translators: 1: number of rows to load now, 2: number of rows remaining. */
	'showMore'        => __( 'Show %1$s more (%2$s left)', 'avesta-sync' ),
	/* translators: 1: rows shown, 2: total rows. */
	'showingOf'       => __( 'Showing %1$s of %2$s items', 'avesta-sync' ),
	/* translators: 1: selected, 2: pushes, 3: pulls, 4: deletions. */
	'barSelected' => array(
		/* translators: 1: selected, 2: pushes, 3: pulls, 4: deletions. */
		'one'   => _n( '%1$s item selected (%2$s push, %3$s pull, %4$s delete)', '%1$s items selected (%2$s push, %3$s pull, %4$s delete)', 1, 'avesta-sync' ),
		/* translators: 1: selected, 2: pushes, 3: pulls, 4: deletions. */
		'other' => _n( '%1$s item selected (%2$s push, %3$s pull, %4$s delete)', '%1$s items selected (%2$s push, %3$s pull, %4$s delete)', 2, 'avesta-sync' ),
	),
	/* translators: %s: number of selected items hidden by filters. */
	'barHidden'       => __( '%s of them are hidden by your filters.', 'avesta-sync' ),
	'noneSel'         => __( 'Nothing selected to apply.', 'avesta-sync' ),
	'rowDone'         => __( 'Done', 'avesta-sync' ),
	/* translators: %s: error message. */
	'rowFailed'       => __( 'Failed: %s', 'avesta-sync' ),

	// Apply.
	'applyTitle'      => __( 'Apply selected changes?', 'avesta-sync' ),
	/* translators: %s: number of items. */
	'applyIntro' => array(
		/* translators: %s: number of items. */
		'one'   => _n( 'You are about to apply %s item:', 'You are about to apply %s items:', 1, 'avesta-sync' ),
		/* translators: %s: number of items. */
		'other' => _n( 'You are about to apply %s item:', 'You are about to apply %s items:', 2, 'avesta-sync' ),
	),
	'sumPush'         => __( 'will be pushed to the remote site', 'avesta-sync' ),
	'sumPull'         => __( 'will be pulled to this site', 'avesta-sync' ),
	'sumDelete'       => __( 'will be deleted on the receiving side', 'avesta-sync' ),
	/* translators: %s: number of deletions. */
	'deleteWarn' => array(
		/* translators: %s: number of deletions. */
		'one'   => _n( '%s item will be DELETED. A backup is kept on the site that receives the change.', '%s items will be DELETED. A backup is kept on the site that receives the change.', 1, 'avesta-sync' ),
		/* translators: %s: number of deletions. */
		'other' => _n( '%s item will be DELETED. A backup is kept on the site that receives the change.', '%s items will be DELETED. A backup is kept on the site that receives the change.', 2, 'avesta-sync' ),
	),
	'backupNote'      => __( 'Anything overwritten or deleted on this site is saved under Backups first.', 'avesta-sync' ),
	/* translators: %s: number of items. */
	'applyN' => array(
		/* translators: %s: number of items. */
		'one'   => _n( 'Apply %s item', 'Apply %s items', 1, 'avesta-sync' ),
		/* translators: %s: number of items. */
		'other' => _n( 'Apply %s item', 'Apply %s items', 2, 'avesta-sync' ),
	),
	/* translators: 1: current item, 2: total items. */
	'progress'        => __( 'Applied %1$s of %2$s', 'avesta-sync' ),
	'stopping'        => __( 'Stopping after the current item...', 'avesta-sync' ),
	/* translators: 1: number ok, 2: number failed. */
	'finished'        => __( 'Finished: %1$s ok, %2$s failed.', 'avesta-sync' ),
	/* translators: %s: number of items not processed. */
	'stopped' => array(
		/* translators: %s: number of items not processed. */
		'one'   => _n( 'Stopped. %s item was not applied and is still selected.', 'Stopped. %s items were not applied and are still selected.', 1, 'avesta-sync' ),
		/* translators: %s: number of items not processed. */
		'other' => _n( 'Stopped. %s item was not applied and is still selected.', 'Stopped. %s items were not applied and are still selected.', 2, 'avesta-sync' ),
	),
	/* translators: %s: number of failed items. */
	'retryFailed'     => __( 'Retry %s failed', 'avesta-sync' ),
	'mediaNote'       => __( 'Media files were copied. If new files do not show up in the Media Library, enable "Include Media Library entries" in Settings or regenerate thumbnails.', 'avesta-sync' ),
	'logPush'         => __( 'push', 'avesta-sync' ),
	'logPull'         => __( 'pull', 'avesta-sync' ),
	'logDelRemote'    => __( 'delete on remote', 'avesta-sync' ),
	'logDelLocal'     => __( 'delete locally', 'avesta-sync' ),
	/* translators: 1: item number, 2: total, 3: action, 4: path. */
	'logItem'         => __( '[%1$s/%2$s] %3$s %4$s', 'avesta-sync' ),

	// Diff modal.
	'diffTitle'       => __( 'Compare versions', 'avesta-sync' ),
	'diffBinary'      => __( 'Binary file - no preview', 'avesta-sync' ),
	'diffTooLarge'    => __( 'This file is too large to preview.', 'avesta-sync' ),
	'diffNone'        => __( 'Nothing to show for this item.', 'avesta-sync' ),
	'diffIdentical'   => __( 'Both versions are identical.', 'avesta-sync' ),
	'missingLocal'    => __( 'Does not exist locally', 'avesta-sync' ),
	'missingRemote'   => __( 'Does not exist on remote', 'avesta-sync' ),
	/* translators: %s: maximum number of lines. */
	'diffCap'         => __( 'The file has more than %s lines, so the versions are shown side by side without alignment.', 'avesta-sync' ),
	'diffComplex'     => __( 'The versions differ too much to align line by line, so they are shown side by side.', 'avesta-sync' ),
	'diffView'        => __( 'Diff layout', 'avesta-sync' ),
	'diffSide'        => __( 'Side by side', 'avesta-sync' ),
	'diffUnified'     => __( 'Unified', 'avesta-sync' ),
	'diffEolOnly'     => __( 'Only line endings or a trailing newline differ.', 'avesta-sync' ),
	'diffRegion'      => __( 'Diff', 'avesta-sync' ),
	/* translators: %s: number of unchanged lines. */
	'diffUnchanged' => array(
		/* translators: %s: number of unchanged lines. */
		'one'   => _n( 'Show %s unchanged line', 'Show %s unchanged lines', 1, 'avesta-sync' ),
		/* translators: %s: number of unchanged lines. */
		'other' => _n( 'Show %s unchanged line', 'Show %s unchanged lines', 2, 'avesta-sync' ),
	),
	/* translators: %s: number of lines. */
	'diffAdded' => array(
		/* translators: %s: number of lines. */
		'one'   => _n( '%s line only on remote', '%s lines only on remote', 1, 'avesta-sync' ),
		/* translators: %s: number of lines. */
		'other' => _n( '%s line only on remote', '%s lines only on remote', 2, 'avesta-sync' ),
	),
	/* translators: %s: number of lines. */
	'diffRemoved' => array(
		/* translators: %s: number of lines. */
		'one'   => _n( '%s line only on this site', '%s lines only on this site', 1, 'avesta-sync' ),
		/* translators: %s: number of lines. */
		'other' => _n( '%s line only on this site', '%s lines only on this site', 2, 'avesta-sync' ),
	),

	// Backups / history.
	'noBackups'       => __( 'No backups yet', 'avesta-sync' ),
	'noBackupsText'   => __( 'Backups appear here after a sync overwrites or deletes something on this site.', 'avesta-sync' ),
	/* translators: 1: number of files, 2: size such as 1.2 MB. */
	'nFilesSize' => array(
		/* translators: 1: number of files, 2: size such as 1.2 MB. */
		'one'   => _n( '%1$s file · %2$s', '%1$s files · %2$s', 1, 'avesta-sync' ),
		/* translators: 1: number of files, 2: size such as 1.2 MB. */
		'other' => _n( '%1$s file · %2$s', '%1$s files · %2$s', 2, 'avesta-sync' ),
	),
	'actPush'         => __( 'Push', 'avesta-sync' ),
	'actPull'         => __( 'Pull', 'avesta-sync' ),
	'actDelRemote'    => __( 'Deleted on remote', 'avesta-sync' ),
	'actDelLocal'     => __( 'Deleted locally', 'avesta-sync' ),
	'noItems'         => __( 'No items.', 'avesta-sync' ),
	'restore'         => __( 'Restore', 'avesta-sync' ),
	/* translators: %s: file path. */
	'restoreFor'      => __( 'Restore %s', 'avesta-sync' ),
	'restoreTitle'    => __( 'Restore this item?', 'avesta-sync' ),
	/* translators: %s: file path. */
	'restoreConfirm'  => __( 'The backed-up version of %s will replace the current one. The current version is backed up first.', 'avesta-sync' ),
	/* translators: %s: date. */
	'deleteBackupFor' => __( 'Delete backup from %s', 'avesta-sync' ),
	'deleteBackupTitle' => __( 'Delete this backup?', 'avesta-sync' ),
	'deleteBackupText' => __( 'This permanently deletes every file in this backup run. This cannot be undone.', 'avesta-sync' ),
	'backupDeleted'   => __( 'Backup deleted.', 'avesta-sync' ),
	'noHistory'       => __( 'No history yet', 'avesta-sync' ),
	'noHistoryText'   => __( 'Applied sync items are recorded here.', 'avesta-sync' ),
	'clearHistory'    => __( 'Clear history', 'avesta-sync' ),
	'clearHistoryTitle' => __( 'Clear the history?', 'avesta-sync' ),
	'clearHistoryText' => __( 'All recorded sync runs will be removed. Backups are not affected.', 'avesta-sync' ),
	'historyCleared'  => __( 'History cleared.', 'avesta-sync' ),

	// Settings.
	/* translators: 1: site URL, 2: plugin version, 3: PHP version, 4: post_max_size, 5: chunk size. */
	'testOk'          => __( 'Connected to %1$s (Avesta Sync %2$s, PHP %3$s, post_max_size %4$s, chunk %5$s)', 'avesta-sync' ),
	'regenTitle'      => __( 'Regenerate this site\'s key?', 'avesta-sync' ),
	'confirmRegen'    => __( 'The other site will stop working until you paste the new key into its settings.', 'avesta-sync' ),
	'regenerate'      => __( 'Regenerate key', 'avesta-sync' ),
	'unsavedTitle'    => __( 'Discard unsaved changes?', 'avesta-sync' ),
	'unsavedText'     => __( 'You changed settings but did not save them. Your edits stay in the form until you reload the page.', 'avesta-sync' ),
	'leave'           => __( 'Leave without saving', 'avesta-sync' ),
	'stay'            => __( 'Stay here', 'avesta-sync' ),
);
