/* Avesta Sync admin UI: Sync tab (compare, filters, grouped list, selection). */
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
	var STATUS_ICON = {
		'local-new': 'plus-alt2',
		'local-changed': 'edit',
		'remote-new': 'plus-alt2',
		'remote-changed': 'edit',
		'local-deleted': 'trash',
		'remote-deleted': 'trash',
		conflict: 'warning'
	};
	var SCOPE_ORDER = { theme: 1, plugins: 2, content: 3, uploads: 4 };
	var PAGE = 100;

	var S = {
		rows: [],
		groups: [],
		run: '',
		identical: 0,
		busy: false,
		q: '',
		scope: 'all',
		statuses: {},
		loaded: false
	};
	var sync = UI.sync = {};

	/* ---------- model helpers ---------- */
	function isDeletion( r, dir ) {
		return ( dir === 'push' && ! r.local ) || ( dir === 'pull' && ! r.remote );
	}

	function defaultDir( r ) {
		return r.action !== 'skip' ? r.action : ( r.local ? 'push' : 'pull' );
	}

	function dirLabel( r, dir ) {
		if ( dir === 'skip' ) {
			return t.skip;
		}
		var delRemote = ! r.local && r.remote;
		var delLocal = r.local && ! r.remote;
		var isDel = r.status === 'local-deleted' || r.status === 'remote-deleted';
		if ( dir === 'push' ) {
			return delRemote ? t.pushDel : ( isDel ? t.pushRestore : t.push );
		}
		return delLocal ? t.pullDel : ( isDel ? t.pullRestore : t.pull );
	}

	function isSel( r ) {
		return ! r.done && r.dir !== 'skip';
	}

	function setDir( r, dir ) {
		if ( r.done ) {
			return;
		}
		if ( dir === 'skip' && r.dir !== 'skip' ) {
			r.last = r.dir;
		}
		r.dir = dir;
		refreshRow( r );
		refreshGroupOf( r );
		refreshBar();
	}

	function toggleRow( r, on ) {
		setDir( r, on ? ( r.last || defaultDir( r ) ) : 'skip' );
	}

	function label( r ) {
		return r.scope === 'content' ? r.label : r.id;
	}

	function visible( r ) {
		if ( S.scope !== 'all' && r.scope !== S.scope ) {
			return false;
		}
		var any = Object.keys( S.statuses ).length > 0;
		if ( any && ! S.statuses[ r.status ] ) {
			return false;
		}
		return ! S.q || r._s.indexOf( S.q ) !== -1;
	}

	/* ---------- row & group DOM ---------- */
	function sideText( s, lbl ) {
		var span = el( 'span', 'as-size' );
		span.appendChild( el( 'span', 'as-size__k', lbl ) );
		var v = el( 'span', 'as-size__v' );
		v.appendChild( el( 'bdi', '', s ? ( s.size !== null && s.size !== undefined ? UI.fmtSize( s.size ) : t.present ) : t.absent ) );
		span.appendChild( v );
		return span;
	}

	function rowEl( r ) {
		var li = el( 'li', 'as-row st-' + r.status );
		var cbl = el( 'label', 'as-row__check' );
		var cb = el( 'input' );
		cb.type = 'checkbox';
		cb.setAttribute( 'aria-label', UI.fmt( t.selectRow, label( r ) ) );
		cb.addEventListener( 'change', function () {
			toggleRow( r, cb.checked );
		} );
		cbl.appendChild( cb );
		li.appendChild( cbl );

		var main = el( 'div', 'as-row__main' );
		var isContent = r.scope === 'content';
		var path = el( 'span', isContent ? 'as-label' : 'as-path', label( r ) );
		path.setAttribute( 'dir', isContent ? 'auto' : 'ltr' );
		main.appendChild( path );
		var st = el( 'span', 'as-status st-' + r.status );
		st.appendChild( UI.icon( STATUS_ICON[ r.status ] || 'marker' ) );
		st.appendChild( el( 'span', '', t.status[ r.status ] || r.status ) );
		main.appendChild( st );
		var note = el( 'span', 'as-row__note' );
		main.appendChild( note );
		li.appendChild( main );

		var sizes = el( 'div', 'as-row__sizes' );
		sizes.appendChild( sideText( r.local, t.local ) );
		sizes.appendChild( sideText( r.remote, t.remote ) );
		li.appendChild( sizes );

		var dirWrap = el( 'div', 'as-row__dir' );
		var sel = el( 'select', 'as-dirsel' );
		sel.setAttribute( 'aria-label', UI.fmt( t.directionFor, label( r ) ) );
		[ 'skip', 'push', 'pull' ].forEach( function ( d ) {
			var o = el( 'option', '', dirLabel( r, d ) );
			o.value = d;
			sel.appendChild( o );
		} );
		sel.addEventListener( 'change', function () {
			setDir( r, sel.value );
		} );
		dirWrap.appendChild( sel );
		/* Visual axis Local <-> Remote. Arrow direction is flipped by CSS for RTL, so it always points at the receiving side. */
		var axis = el( 'span', 'as-axis' );
		axis.setAttribute( 'aria-hidden', 'true' );
		axis.title = t.pushHint + ' / ' + t.pullHint;
		axis.appendChild( el( 'span', 'as-axis__end as-axis__end--local', t.local ) );
		var arrow = el( 'span', 'dashicons as-axis__arrow' );
		axis.appendChild( arrow );
		axis.appendChild( el( 'span', 'as-axis__end as-axis__end--remote', t.remote ) );
		dirWrap.appendChild( axis );
		li.appendChild( dirWrap );

		var act = el( 'div', 'as-row__act' );
		if ( r.previewable ) {
			var vb = UI.btn( t.viewDiff, 'as-btn--secondary as-btn--sm', function () {
				UI.openDiff( r, { dirLabel: dirLabel, setDir: setDir, isLocked: function ( x ) {
					return x.done || S.busy;
				} } );
			}, 'visibility' );
			vb.setAttribute( 'aria-label', UI.fmt( t.viewDiffFor, label( r ) ) );
			act.appendChild( vb );
		}
		li.appendChild( act );

		r.el = { li: li, cb: cb, sel: sel, note: note, st: st, arrow: arrow, axis: axis };
		refreshRow( r );
		return li;
	}

	function refreshRow( r ) {
		var e = r.el;
		if ( ! e ) {
			return;
		}
		e.cb.checked = r.dir !== 'skip';
		e.cb.disabled = r.done;
		e.sel.value = r.dir;
		e.sel.disabled = r.done || S.busy;
		e.sel.setAttribute( 'data-dir', r.dir );
		e.sel.title = r.dir === 'push' ? t.pushHint : ( r.dir === 'pull' ? t.pullHint : '' );
		e.arrow.className = 'dashicons as-axis__arrow is-' + r.dir + ( r.dir === 'skip' ? ' dashicons-minus' : ' dashicons-arrow-right-alt' );
		e.axis.setAttribute( 'data-dir', r.dir );
		e.li.classList.toggle( 'is-done', r.done );
		e.li.classList.toggle( 'is-selected', isSel( r ) );
		e.li.classList.toggle( 'is-failed', !! r.err );
		e.li.classList.toggle( 'is-delete', isSel( r ) && isDeletion( r, r.dir ) );
		e.note.textContent = r.done ? t.rowDone : ( r.err ? UI.fmt( t.rowFailed, r.err ) : '' );
		e.note.className = 'as-row__note' + ( r.done ? ' is-ok' : ( r.err ? ' is-err' : '' ) );
	}

	function groupEl( g ) {
		var li = el( 'li', 'as-group' );
		var head = el( 'div', 'as-group__head' );
		var cbl = el( 'label', 'as-group__check' );
		var cb = el( 'input' );
		cb.type = 'checkbox';
		cb.setAttribute( 'aria-label', UI.fmt( t.selectGroup, g.name ) );
		cb.addEventListener( 'change', function () {
			g.vis.forEach( function ( r ) {
				if ( ! r.done ) {
					toggleRowSilent( r, cb.checked );
				}
			} );
			refreshAll();
		} );
		cbl.appendChild( cb );
		head.appendChild( cbl );
		var tg = el( 'button', 'as-group__toggle' );
		tg.type = 'button';
		tg.appendChild( UI.icon( 'arrow-down-alt2' ) );
		var nm = el( 'span', 'as-group__name', g.name );
		nm.setAttribute( 'dir', 'ltr' );
		tg.appendChild( el( 'span', 'as-tag as-tag--' + g.scope, t.scopes[ g.scope ] || g.scope ) );
		tg.appendChild( nm );
		var cnt = el( 'span', 'as-group__count as-muted' );
		tg.appendChild( cnt );
		tg.addEventListener( 'click', function () {
			g.expanded = ! g.expanded;
			g.shown = PAGE;
			fillGroup( g );
		} );
		head.appendChild( tg );
		var selc = el( 'span', 'as-group__sel' );
		head.appendChild( selc );
		var list = el( 'ul', 'as-rows' );
		li.appendChild( head );
		li.appendChild( list );
		g.el = { li: li, cb: cb, tg: tg, cnt: cnt, sel: selc, list: list };
		return li;
	}

	function toggleRowSilent( r, on ) {
		if ( on ) {
			r.dir = r.dir !== 'skip' ? r.dir : ( r.last || defaultDir( r ) );
		} else if ( r.dir !== 'skip' ) {
			r.last = r.dir;
			r.dir = 'skip';
		}
	}

	function fillGroup( g ) {
		var e = g.el;
		UI.clear( e.list );
		e.tg.setAttribute( 'aria-expanded', g.expanded ? 'true' : 'false' );
		e.li.classList.toggle( 'is-open', g.expanded );
		if ( g.expanded ) {
			var frag = document.createDocumentFragment();
			g.vis.slice( 0, g.shown ).forEach( function ( r ) {
				frag.appendChild( rowEl( r ) );
			} );
			e.list.appendChild( frag );
			if ( g.vis.length > g.shown ) {
				var more = el( 'li', 'as-rows__more' );
				var mb = UI.btn( '', 'as-btn--secondary as-btn--sm', function () {
					g.shown += PAGE;
					fillGroup( g );
				} );
				UI.setMsg( mb.querySelector( '.as-btn__label' ), 'showMore', null, [ UI.num( Math.min( PAGE, g.vis.length - g.shown ) ), UI.num( g.vis.length - g.shown ) ] );
				more.appendChild( mb );
				e.list.appendChild( more );
			}
		}
		refreshGroup( g );
	}

	function refreshGroup( g ) {
		var e = g.el;
		if ( ! e ) {
			return;
		}
		var open = g.vis.filter( function ( r ) {
			return ! r.done;
		} );
		var n = open.filter( isSel ).length;
		e.cb.disabled = open.length === 0;
		e.cb.checked = open.length > 0 && n === open.length;
		e.cb.indeterminate = n > 0 && n < open.length;
		UI.setMsg( e.cnt, 'nItems', g.vis.length, [ UI.num( g.vis.length ) ] );
		UI.clear( e.sel );
		if ( n ) {
			UI.setMsg( e.sel, 'nSelected', n, [ UI.num( n ) ] );
		}
	}

	function refreshGroupOf( r ) {
		S.groups.forEach( function ( g ) {
			if ( g.key === r._g ) {
				refreshGroup( g );
			}
		} );
	}

	function refreshAll() {
		S.groups.forEach( function ( g ) {
			if ( g.el && g.el.li.parentNode ) {
				g.vis.slice( 0, g.shown ).forEach( refreshRow );
				refreshGroup( g );
			}
		} );
		refreshBar();
	}

	/* ---------- bar ---------- */
	function counts() {
		var c = { total: 0, push: 0, pull: 0, del: 0, hidden: 0 };
		S.rows.forEach( function ( r ) {
			if ( ! isSel( r ) ) {
				return;
			}
			c.total++;
			c[ r.dir ]++;
			if ( isDeletion( r, r.dir ) ) {
				c.del++;
			}
			if ( ! visible( r ) ) {
				c.hidden++;
			}
		} );
		return c;
	}

	function refreshBar() {
		var c = counts();
		var txt = $( 'as-bar-text' );
		if ( ! S.busy ) {
			UI.setMsg( txt, 'barSelected', c.total, [ UI.num( c.total ), UI.num( c.push ), UI.num( c.pull ), UI.num( c.del ) ] );
			UI.clear( $( 'as-bar-hidden' ) );
			if ( c.hidden ) {
				UI.setMsg( $( 'as-bar-hidden' ), 'barHidden', null, [ UI.num( c.hidden ) ] );
			}
		}
		var blocked = S.busy || c.total === 0 || !! S.stale;
		$( 'as-apply' ).disabled = blocked;
		$( 'as-apply' ).setAttribute( 'aria-disabled', blocked ? 'true' : 'false' );
		$( 'as-bar' ).classList.toggle( 'has-delete', c.del > 0 && ! S.busy );
	}

	/* ---------- filters / render ---------- */
	function buildGroups( rows ) {
		var map = {};
		var groups = [];
		rows.forEach( function ( r ) {
			var key = r.scope + '\u0000' + r.group;
			r._g = key;
			if ( ! map[ key ] ) {
				map[ key ] = { key: key, scope: r.scope, name: r.group, rows: [], vis: [], expanded: false, shown: PAGE, el: null };
				groups.push( map[ key ] );
			}
			map[ key ].rows.push( r );
		} );
		groups.sort( function ( a, b ) {
			return ( ( SCOPE_ORDER[ a.scope ] || 9 ) - ( SCOPE_ORDER[ b.scope ] || 9 ) ) || a.name.localeCompare( b.name );
		} );
		groups.forEach( function ( g ) {
			g.rows.sort( function ( a, b ) {
				return a.id.localeCompare( b.id );
			} );
		} );
		return groups;
	}

	function renderList() {
		var host = $( 'as-groups' );
		UI.clear( host );
		var total = 0;
		S.groups.forEach( function ( g ) {
			g.vis = g.rows.filter( visible );
			total += g.vis.length;
		} );
		var expandAll = ( S.q || Object.keys( S.statuses ).length ) && total <= 300;
		var frag = document.createDocumentFragment();
		S.groups.forEach( function ( g ) {
			if ( ! g.vis.length ) {
				return;
			}
			if ( expandAll ) {
				g.expanded = true;
			}
			if ( ! g.el ) {
				groupEl( g );
			}
			frag.appendChild( g.el.li );
			fillGroup( g );
		} );
		host.appendChild( frag );
		var none = $( 'as-nomatch' );
		none.hidden = total !== 0;
		$( 'as-bulk' ).hidden = false;
		var cnt = $( 'as-filter-count' );
		UI.clear( cnt );
		if ( total !== S.rows.length ) {
			UI.setMsg( cnt, 'showingOf', null, [ UI.num( total ), UI.num( S.rows.length ) ] );
		}
		refreshBar();
	}

	function renderChips() {
		var host = UI.clear( $( 'as-chips' ) );
		var cnt = {};
		S.rows.forEach( function ( r ) {
			cnt[ r.status ] = ( cnt[ r.status ] || 0 ) + 1;
		} );
		STATUS_ORDER.forEach( function ( s ) {
			if ( ! cnt[ s ] ) {
				return;
			}
			var b = el( 'button', 'as-chip st-' + s );
			b.type = 'button';
			b.setAttribute( 'aria-pressed', S.statuses[ s ] ? 'true' : 'false' );
			b.appendChild( UI.icon( STATUS_ICON[ s ] ) );
			b.appendChild( el( 'span', 'as-chip__n', UI.num( cnt[ s ] ) ) );
			b.appendChild( el( 'span', '', t.status[ s ] ) );
			b.addEventListener( 'click', function () {
				if ( S.statuses[ s ] ) {
					delete S.statuses[ s ];
				} else {
					S.statuses[ s ] = true;
				}
				b.setAttribute( 'aria-pressed', S.statuses[ s ] ? 'true' : 'false' );
				renderList();
			} );
			host.appendChild( b );
		} );
		var idn = el( 'span', 'as-chip as-chip--static' );
		idn.appendChild( UI.icon( 'yes' ) );
		idn.appendChild( el( 'span', 'as-chip__n', UI.num( S.identical ) ) );
		idn.appendChild( el( 'span', '', t.identical ) );
		host.appendChild( idn );
	}

	/* Shared with as-sync-run.js */
	sync.state = S;
	sync.core = {
		renderList: renderList,
		renderChips: renderChips,
		buildGroups: buildGroups,
		refreshAll: refreshAll,
		refreshBar: refreshBar,
		refreshRow: refreshRow,
		refreshGroupOf: refreshGroupOf,
		isSel: isSel,
		toggleRowSilent: toggleRowSilent,
		isDeletion: isDeletion,
		label: label,
		counts: counts,
		PAGE: PAGE
	};
}() );
