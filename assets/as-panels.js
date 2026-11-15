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

	function connOf( d ) {
		return {
			connected: d.connected,
			error: d.error,
			remote: d.remote,
			wcInfo: { wc: d.wc, wc_block: d.wc_block, wc_warn: d.wc_warn, wc_message: d.wc_message }
		};
	}

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
		var conn = d.connected === null ? dash.conn : connOf( d );
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
			if ( d.scopes && d.scopes.indexOf( 'woocommerce' ) !== -1 && conn.wcInfo ) {
				var w = conn.wcInfo;
				var ver = function ( s ) {
					return s && s.active ? ( s.version || '-' ) : t.wcNotActive;
				};
				kv( dl, t.wcLabel, UI.msg( 'wcVersions', null, [ ver( w.wc && w.wc.local ), ver( w.wc && w.wc.remote ) ] ) );
			}
			c.appendChild( dl );
			if ( d.scopes && d.scopes.indexOf( 'woocommerce' ) !== -1 && conn.wcInfo && conn.wcInfo.wc_message && ( conn.wcInfo.wc_block || conn.wcInfo.wc_warn ) ) {
				var wn = el( 'div', 'as-alert as-alert--' + ( conn.wcInfo.wc_block ? 'warning' : 'info' ) );
				wn.setAttribute( 'role', 'status' );
				wn.appendChild( UI.icon( conn.wcInfo.wc_block ? 'warning' : 'info' ) );
				var wt = el( 'div', 'as-alert__text', conn.wcInfo.wc_message );
				wt.setAttribute( 'dir', 'auto' );
				wn.appendChild( wt );
				c.appendChild( wn );
			}
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
		} else if ( ! k.total && ! k.wc_skipped ) {
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
		if ( k.wc_skipped && ! k.error ) {
			var sk = el( 'p', 'as-card__meta as-muted' );
			sk.appendChild( UI.icon( 'info' ) );
			sk.appendChild( el( 'span', '', t.wcNotChecked ) );
			c.appendChild( sk );
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
				dash.conn = connOf( d );
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

	UI.kit = {
		disclosure: disclosure,
		headBtn: headBtn,
		sourceBadge: sourceBadge,
		resetDash: function () {
			dash.data = null;
		}
	};
}() );
