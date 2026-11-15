/* Avesta Sync admin UI: Backups tab. */
( function () {
	'use strict';

	var UI = window.AvestaSyncUI;
	if ( ! UI || ! UI.kit ) {
		return;
	}
	var t = UI.t;
	var el = UI.el;
	var $ = UI.$;
	var kit = UI.kit;
	var disclosure = kit.disclosure;
	var headBtn = kit.headBtn;
	var flash = null;

	function backupItems( run, panel, resetLoaded ) {
		UI.clear( panel ).appendChild( UI.loading() );
		UI.post( 'avesta_sync_backup_items', { run: run.run } ).then( function ( d ) {
			UI.clear( panel );
			if ( ! d.items.length ) {
				panel.appendChild( el( 'p', 'as-muted', t.noItems ) );
				return;
			}
			var ul = el( 'ul', 'as-items' );
			d.items.forEach( function ( it ) {
				var li = el( 'li', 'as-item' );
				var main = el( 'div', 'as-item__main' );
				var what = it.label || it.path;
				if ( it.label ) {
					/* Readable label first, raw path as quiet secondary text. */
					var wrapL = el( 'span', 'as-item__name' );
					wrapL.appendChild( UI.proseLabel( it.label ) );
					var sec = el( 'span', 'as-path as-muted as-item__path', it.path );
					sec.setAttribute( 'dir', 'ltr' );
					wrapL.appendChild( sec );
					main.appendChild( wrapL );
				} else {
					var path = el( 'span', it.type === 'content' ? 'as-label' : 'as-path', it.path );
					path.setAttribute( 'dir', it.type === 'content' ? 'auto' : 'ltr' );
					main.appendChild( path );
					main.appendChild( el( 'span', 'as-tag', it.type === 'content' ? t.typeContent : t.typeFile ) );
				}
				if ( it.size ) {
					main.appendChild( el( 'bdi', 'as-muted as-item__size', UI.fmtSize( it.size ) ) );
				}
				li.appendChild( main );
				var res = el( 'span', 'as-inline-result' );
				res.setAttribute( 'role', 'status' );
				var rb = UI.btn( t.restore, 'as-btn--secondary as-btn--sm', function () {
					UI.confirm( {
						title: t.restoreTitle,
						message: UI.fmt( t.restoreConfirm, what ),
						confirmLabel: t.restore,
						onConfirm: function () {
							rb.disabled = true;
							res.className = 'as-inline-result';
							res.textContent = t.working;
							UI.post( 'avesta_sync_restore', { run: run.run, path: it.path } ).then( function ( r ) {
								res.className = 'as-inline-result is-ok';
								res.textContent = r.message;
								rb.disabled = false;
								/* A restore changes local files and adds a pre-restore backup run. */
								flash = { run: run.run, path: it.path, msg: r.message };
								kit.resetDash();
								if ( UI.sync && UI.sync.markStale ) {
									UI.sync.markStale();
								}
								loadBackups( function () {
									var tg = document.querySelector( '#as-backups-list [data-run="' + run.run + '"] .as-acc__toggle' );
									if ( tg ) {
										tg.click();
										tg.focus();
									}
								} );
							} ).catch( function ( e ) {
								res.className = 'as-inline-result is-err';
								res.textContent = e.message;
								rb.disabled = false;
							} );
						}
					} );
				}, 'undo' );
				rb.setAttribute( 'aria-label', UI.fmt( t.restoreFor, what ) );
				li.appendChild( rb );
				if ( flash && flash.run === run.run && flash.path === it.path ) {
					res.className = 'as-inline-result is-ok';
					res.textContent = flash.msg;
					flash = null;
				}
				li.appendChild( res );
				ul.appendChild( li );
			} );
			panel.appendChild( ul );
		} ).catch( function ( e ) {
			UI.clear( panel ).appendChild( UI.errorBox( e.message, function () {
				backupItems( run, panel, resetLoaded );
			} ) );
		} );
	}

	function drawBackups( runs ) {
		var host = UI.clear( $( 'as-backups-list' ) );
		if ( ! runs.length ) {
			host.appendChild( UI.empty( 'backup', t.noBackups, t.noBackupsText ) );
			return;
		}
		runs.forEach( function ( run ) {
			var item = el( 'div', 'as-acc' );
			item.setAttribute( 'data-run', run.run );
			var head = el( 'div', 'as-acc__head' );
			var info = el( 'span', 'as-acc__info' );
			info.appendChild( UI.timeNode( run.time ) );
			var sub = el( 'span', 'as-muted as-acc__sub' );
			sub.appendChild( UI.msg( 'nFilesSize', run.files, [ UI.num( run.files ), UI.fmtSize( run.size ) ] ) );
			info.appendChild( sub );
			var tb = headBtn( [ info ] );
			head.appendChild( tb );
			var del = UI.btn( t.delete, 'as-btn--danger-link as-btn--sm', function () {
				UI.confirm( {
					title: t.deleteBackupTitle,
					message: t.deleteBackupText,
					confirmLabel: t.delete,
					danger: true,
					onConfirm: function () {
						UI.post( 'avesta_sync_backup_delete', { run: run.run } ).then( function () {
							UI.announce( t.backupDeleted );
							loadBackups();
							kit.resetDash();
						} ).catch( function ( e ) {
							item.appendChild( UI.errorBox( e.message ) );
						} );
					}
				} );
			}, 'trash' );
			del.setAttribute( 'aria-label', UI.fmt( t.deleteBackupFor, UI.fmtDate( run.time ) ) );
			head.appendChild( del );
			var panel = el( 'div', 'as-acc__panel' );
			panel.hidden = true;
			disclosure( tb, panel, function ( reset ) {
				backupItems( run, panel, reset );
			} );
			item.appendChild( head );
			item.appendChild( panel );
			host.appendChild( item );
		} );
	}

	function loadBackups( after ) {
		var host = UI.clear( $( 'as-backups-list' ) );
		host.appendChild( UI.loading() );
		UI.post( 'avesta_sync_backups', {} ).then( function ( d ) {
			drawBackups( d.runs );
			if ( typeof after === 'function' ) {
				after();
			}
		} ).catch( function ( e ) {
			UI.clear( host ).appendChild( UI.errorBox( e.message, function () {
				loadBackups();
			} ) );
		} );
	}

	UI.modules.backups = {
		init: function () {
			loadBackups();
		},
		show: function () {
			loadBackups();
		}
	};
}() );
