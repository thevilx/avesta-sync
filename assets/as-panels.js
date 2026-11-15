/* Avesta Sync admin UI: Dashboard, Backups and History tabs. */
( function () {
	'use strict';

	var UI = window.AvestaSyncUI;
	if ( ! UI ) {
		return;
	}
	var t = UI.t;
	var el = UI.el;
	var $ = UI.$;

	var STATUS_ORDER = [ 'conflict', 'local-new', 'local-changed', 'remote-new', 'remote-changed', 'local-deleted', 'remote-deleted' ];

	/* ---------- header pill ---------- */
	UI.setPill = function ( state ) {
		var p = $( 'as-pill' );
		if ( ! p ) {
			return;
		}
		p.className = 'as-pill is-' + state;
		p.querySelector( '.as-pill__text' ).textContent = t.pill[ state ] || '';
	};

	/* ---------- shared bits ---------- */
	function card( title, icon, cls ) {
		var c = el( 'section', 'as-card ' + ( cls || '' ) );
		var h = el( 'h3', 'as-card__title' );
		h.appendChild( UI.icon( icon ) );
		h.appendChild( el( 'span', '', title ) );
		c.appendChild( h );
		return c;
	}

	function link( label, onClick ) {
		return UI.btn( label, 'as-btn--link', onClick );
	}

	function kv( dl, k, v ) {
		dl.appendChild( el( 'dt', '', k ) );
		var dd = el( 'dd' );
		if ( typeof v === 'string' ) {
			dd.textContent = v;
		} else {
			dd.appendChild( v );
		}
		dl.appendChild( dd );
	}

	function sourceBadge( src ) {
		return el( 'span', 'as-tag as-tag--src-' + src, src === 'cli' ? t.srcCli : t.srcAdmin );
	}

	/* ---------- Dashboard ---------- */
	var dash = { data: null, conn: null, grid: null };

	function connectionCard( d, loading ) {
		var c = card( t.cardConnection, 'admin-links', 'as-card--wide' );
		if ( loading ) {
			c.appendChild( UI.loading( t.checking ) );
			return c;
		}
		if ( ! d.configured ) {
			UI.setPill( 'unset' );
			c.appendChild( el( 'p', 'as-card__lead', t.notConfigured ) );
			var ol = el( 'ol', 'as-steps' );
			[ t.step1, t.step2, t.step3 ].forEach( function ( s ) {
				ol.appendChild( el( 'li', '', s ) );
			} );
			c.appendChild( ol );
			c.appendChild( UI.btn( t.goSettings, 'as-btn--primary', function () {
				UI.showTab( 'settings' );
			}, 'admin-generic' ) );
			return c;
		}
		var conn = d.connected === null ? dash.conn : { connected: d.connected, error: d.error, remote: d.remote };
		if ( ! conn ) {
			c.appendChild( el( 'p', 'as-muted', t.notChecked ) );
			UI.setPill( 'idle' );
			return c;
		}
		if ( conn.connected ) {
			UI.setPill( 'ok' );
			var dl = el( 'dl', 'as-kv' );
			var site = el( 'span', 'as-path', conn.remote.site || conn.remote.url );
			site.setAttribute( 'dir', 'ltr' );
			kv( dl, t.remoteSite, site );
			kv( dl, t.pluginVersion, conn.remote.version || '-' );
			kv( dl, t.wpVersion, conn.remote.wp || '-' );
			kv( dl, t.phpVersion, conn.remote.php || '-' );
			c.appendChild( dl );
		} else {
			UI.setPill( 'bad' );
			var box = el( 'div', 'as-alert as-alert--error' );
			box.setAttribute( 'role', 'alert' );
			box.appendChild( UI.icon( 'warning' ) );
			var tx = el( 'div', 'as-alert__text' );
			tx.appendChild( el( 'strong', '', t.cantReach ) );
			tx.appendChild( el( 'span', 'as-alert__msg', conn.error ) );
			box.appendChild( tx );
			c.appendChild( box );
			var row = el( 'div', 'as-actions' );
			row.appendChild( UI.btn( t.goSettings, 'as-btn--primary', function () {
				UI.showTab( 'settings' );
			}, 'admin-generic' ) );
			row.appendChild( UI.btn( t.retry, 'as-btn--secondary', function () {
				load( true );
			}, 'update' ) );
			c.appendChild( row );
		}
		return c;
	}

	function lastSyncCard( d ) {
		var c = card( t.cardLastSync, 'update' );
		var s = d.last_sync;
		if ( ! s ) {
			c.appendChild( el( 'p', 'as-muted', t.noSyncYet ) );
			return c;
		}
		var when = el( 'p', 'as-card__big' );
		when.appendChild( UI.timeNode( s.time ) );
		c.appendChild( when );
		var meta = el( 'p', 'as-card__meta' );
		var srcName = s.source === 'cli' ? t.srcCli : t.srcAdmin;
		var u = String( s.user || '' ).trim();
		if ( u && u.toLowerCase() !== srcName.toLowerCase() && u.toLowerCase() !== 'wp-cli' ) {
			meta.appendChild( UI.msg( 'byUser', null, [ u ] ) );
			meta.appendChild( document.createTextNode( ' ' ) );
		}
		meta.appendChild( sourceBadge( s.source ) );
		c.appendChild( meta );
		var res = el( 'p', 'as-card__meta' );
		res.appendChild( el( 'span', 'as-status st-ok' ) ).appendChild( UI.msg( 'nOk', null, [ UI.num( s.ok ) ] ) );
		res.appendChild( document.createTextNode( ' ' ) );
		res.appendChild( el( 'span', 'as-status ' + ( s.failed ? 'st-conflict' : 'st-none' ) ) ).appendChild( UI.msg( 'nFailed', null, [ UI.num( s.failed ) ] ) );
		c.appendChild( res );
		c.appendChild( link( t.viewHistory, function () {
			UI.showTab( 'history' );
		} ) );
		return c;
	}

	function lastCheckCard( d ) {
		var c = card( t.cardLastCheck, 'search' );
		var k = d.last_check;
		if ( ! k ) {
			c.appendChild( el( 'p', 'as-muted', t.noCheckYet ) );
			return c;
		}
		var p = el( 'p', 'as-card__big' );
		p.appendChild( UI.timeNode( k.time ) );
		c.appendChild( p );
		if ( k.error ) {
			var e = el( 'p', 'as-card__meta is-err' );
			e.appendChild( UI.icon( 'warning' ) );
			e.appendChild( el( 'span', '', k.error ) );
			c.appendChild( e );
		} else if ( ! k.total ) {
			var ok = el( 'p', 'as-card__meta is-ok' );
			ok.appendChild( UI.icon( 'yes-alt' ) );
			ok.appendChild( UI.msg( 'inSync', null, [ UI.num( k.identical ) ] ) );
			c.appendChild( ok );
		} else {
			var ul = el( 'ul', 'as-statlist' );
			STATUS_ORDER.forEach( function ( s ) {
				if ( k.counts[ s ] ) {
					var li = el( 'li' );
					li.appendChild( el( 'span', 'as-status st-' + s, t.status[ s ] ) );
					li.appendChild( el( 'strong', '', UI.num( k.counts[ s ] ) ) );
					ul.appendChild( li );
				}
			} );
			c.appendChild( ul );
		}
		c.appendChild( link( t.reviewSync, function () {
			UI.showTab( 'sync' );
		} ) );
		return c;
	}

	function backupsCard( d ) {
		var c = card( t.cardBackups, 'backup' );
		var p = el( 'p', 'as-card__big' );
		p.appendChild( UI.msg( 'nRuns', d.backups.runs, [ UI.num( d.backups.runs ) ] ) );
		c.appendChild( p );
		var tot = el( 'p', 'as-card__meta as-muted' );
		if ( d.backups.runs ) {
			tot.appendChild( UI.msg( 'totalSize', null, [ UI.fmtSize( d.backups.size ) ] ) );
		} else {
			tot.textContent = t.noBackupsShort;
		}
		c.appendChild( tot );
		c.appendChild( link( t.manageBackups, function () {
			UI.showTab( 'backups' );
		} ) );
		return c;
	}

	function scheduleCard( d ) {
		var c = card( t.cardSchedule, 'clock' );
		var mode = d.schedule.mode;
		c.appendChild( el( 'p', 'as-card__big', t.schedules[ mode ] || mode ) );
		if ( mode !== 'off' ) {
			var m = el( 'p', 'as-card__meta' );
			m.appendChild( document.createTextNode( t.nextRun + ' ' ) );
			if ( d.schedule.next ) {
				m.appendChild( UI.timeNode( d.schedule.next ) );
			} else {
				m.appendChild( document.createTextNode( '-' ) );
			}
			c.appendChild( m );
		}
		c.appendChild( link( t.changeSchedule, function () {
			UI.showTab( 'settings' );
		} ) );
		return c;
	}

	function drawDash( d, loading ) {
		var g = UI.clear( dash.grid );
		g.appendChild( connectionCard( d || { configured: true, connected: null }, loading ) );
		if ( d ) {
			g.appendChild( lastSyncCard( d ) );
			g.appendChild( lastCheckCard( d ) );
			g.appendChild( backupsCard( d ) );
			g.appendChild( scheduleCard( d ) );
		}
	}

	function load( ping ) {
		var pre = dash.data;
		if ( ping || ! pre ) {
			drawDash( pre, true );
			if ( ! pre ) {
				UI.clear( dash.grid ).appendChild( UI.loading( t.loading ) );
			}
		}
		UI.post( 'avesta_sync_dashboard', { ping: ping ? '1' : '0' } ).then( function ( d ) {
			if ( d.connected !== null ) {
				dash.conn = { connected: d.connected, error: d.error, remote: d.remote };
			}
			dash.data = d;
			drawDash( d, false );
		} ).catch( function ( e ) {
			UI.clear( dash.grid ).appendChild( UI.errorBox( e.message, function () {
				load( true );
			} ) );
		} );
	}

	UI.modules.dashboard = {
		init: function () {
			dash.grid = $( 'as-dash-grid' );
			$( 'as-dash-compare' ).addEventListener( 'click', function () {
				UI.sync.start();
			} );
			load( true );
		},
		show: function () {
			var ping = dash.stale;
			dash.stale = false;
			load( ping );
		},
		/* Connection settings were tested or changed: re-ping the remote next time. */
		invalidate: function () {
			dash.stale = true;
		}
	};

	/* ---------- generic disclosure ---------- */
	var accSeq = 0;

	function disclosure( btn, panel, onOpen ) {
		var loaded = false;
		panel.id = panel.id || 'as-accp-' + ( ++accSeq );
		btn.setAttribute( 'aria-controls', panel.id );
		btn.setAttribute( 'aria-expanded', 'false' );
		btn.addEventListener( 'click', function () {
			var open = btn.getAttribute( 'aria-expanded' ) !== 'true';
			btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			panel.hidden = ! open;
			if ( open && ! loaded ) {
				loaded = true;
				onOpen( function () {
					loaded = false;
				} );
			}
		} );
	}

	function headBtn( parts ) {
		var b = el( 'button', 'as-acc__toggle' );
		b.type = 'button';
		b.appendChild( UI.icon( 'arrow-down-alt2' ) );
		parts.forEach( function ( p ) {
			b.appendChild( p );
		} );
		return b;
	}

	/* ---------- Backups ---------- */
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
				var path = el( 'span', it.type === 'content' ? 'as-label' : 'as-path', it.path );
				path.setAttribute( 'dir', it.type === 'content' ? 'auto' : 'ltr' );
				main.appendChild( path );
				main.appendChild( el( 'span', 'as-tag', it.type === 'content' ? t.typeContent : t.typeFile ) );
				if ( it.size ) {
					main.appendChild( el( 'bdi', 'as-muted as-item__size', UI.fmtSize( it.size ) ) );
				}
				li.appendChild( main );
				var res = el( 'span', 'as-inline-result' );
				res.setAttribute( 'role', 'status' );
				var rb = UI.btn( t.restore, 'as-btn--secondary as-btn--sm', function () {
					UI.confirm( {
						title: t.restoreTitle,
						message: UI.fmt( t.restoreConfirm, it.path ),
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
								dash.data = null;
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
				rb.setAttribute( 'aria-label', UI.fmt( t.restoreFor, it.path ) );
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
							dash.data = null;
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

	UI.kit = {
		disclosure: disclosure,
		headBtn: headBtn,
		sourceBadge: sourceBadge,
		resetDash: function () {
			dash.data = null;
		}
	};
}() );
