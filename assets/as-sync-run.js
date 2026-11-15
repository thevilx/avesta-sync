/* Avesta Sync admin UI: Sync tab compare flow, bulk actions and tab wiring. */
( function () {
	'use strict';

	var UI = window.AvestaSyncUI;
	if ( ! UI || ! UI.sync || ! UI.sync.core ) {
		return;
	}
	var t = UI.t;
	var el = UI.el;
	var $ = UI.$;
	var sync = UI.sync;
	var S = sync.state;
	var c = sync.core;
	var renderList = c.renderList;
	var renderChips = c.renderChips;
	var buildGroups = c.buildGroups;
	var refreshAll = c.refreshAll;
	var refreshBar = c.refreshBar;
	var isSel = c.isSel;
	var isDeletion = c.isDeletion;
	var label = c.label;
	var counts = c.counts;
	var toggleRowSilent = c.toggleRowSilent;
	var searchTimer = null;

	/* ---------- compare ---------- */
	function setState( node ) {
		var st = UI.clear( $( 'as-state' ) );
		if ( node ) {
			st.appendChild( node );
		}
	}

	function showResults( on ) {
		$( 'as-results' ).hidden = ! on;
		$( 'as-bar' ).hidden = ! on;
		$( 'as-chips' ).hidden = ! on;
	}

	function compare() {
		if ( S.busy ) {
			return;
		}
		var btn = $( 'as-compare' );
		S.busy = true;
		btn.disabled = true;
		btn.classList.add( 'is-loading' );
		showResults( false );
		$( 'as-stale' ).hidden = true;
		S.stale = false;
		$( 'as-run' ).hidden = true;
		setState( UI.loading( t.comparing ) );
		UI.post( 'avesta_sync_compare', {} ).then( function ( d ) {
			S.busy = false;
			btn.disabled = false;
			btn.classList.remove( 'is-loading' );
			S.run = d.run;
			S.identical = d.identical;
			S.loaded = true;
			S.statuses = {};
			S.rows = d.rows.map( function ( r ) {
				r.dir = r.action;
				r.last = null;
				r.done = false;
				r.err = '';
				r.el = null;
				r._s = ( r.id + ' ' + r.label + ' ' + r.group ).toLowerCase();
				return r;
			} );
			S.groups = buildGroups( S.rows );
			S.groups.forEach( function ( g, i ) {
				g.expanded = S.rows.length <= 300 || i < 3;
			} );
			if ( ! S.rows.length ) {
				setState( UI.empty( 'yes-alt', t.nothingTitle, UI.msgText( 'nothingText', null, [ UI.num( d.identical ) ] ) ) );
				UI.announce( t.nothingTitle );
				return;
			}
			setState( null );
			showResults( true );
			renderChips();
			renderList();
			UI.announce( UI.fmt( t.compared, UI.num( S.rows.length ), UI.num( d.identical ) ) );
		} ).catch( function ( e ) {
			S.busy = false;
			btn.disabled = false;
			btn.classList.remove( 'is-loading' );
			setState( UI.errorBox( e.message, compare ) );
		} );
	}

	/* ---------- bulk ---------- */
	function bulk( mode ) {
		S.groups.forEach( function ( g ) {
			g.vis.forEach( function ( r ) {
				if ( r.done ) {
					return;
				}
				if ( mode === 'none' ) {
					toggleRowSilent( r, false );
				} else if ( mode === 'push' && r.action === 'push' ) {
					r.dir = 'push';
				} else if ( mode === 'pull' && r.action === 'pull' ) {
					r.dir = 'pull';
				} else if ( mode === 'conflict-push' && r.status === 'conflict' ) {
					r.dir = 'push';
				} else if ( mode === 'conflict-pull' && r.status === 'conflict' ) {
					r.dir = 'pull';
				}
			} );
		} );
		refreshAll();
	}

	function clearFilters() {
		S.q = '';
		S.scope = 'all';
		S.statuses = {};
		$( 'as-q' ).value = '';
		$( 'as-scope' ).value = 'all';
		renderChips();
		renderList();
	}

	/* ---------- API for the apply module ---------- */
	sync.isDeletion = isDeletion;
	sync.label = label;
	sync.refreshRow = function ( r ) {
		c.refreshRow( r );
		c.refreshGroupOf( r );
	};
	sync.refreshBar = refreshBar;
	sync.refreshAll = refreshAll;
	sync.selected = function () {
		return S.rows.filter( isSel );
	};
	sync.counts = counts;
	sync.setBusy = function ( on ) {
		S.busy = on;
		var list = $( 'as-results' );
		if ( on ) {
			list.setAttribute( 'inert', '' );
		} else {
			list.removeAttribute( 'inert' );
		}
		$( 'as-compare' ).disabled = on;
		refreshAll();
	};
	sync.compare = compare;
	/* Called after something changed local files outside a sync (e.g. a backup restore). */
	sync.markStale = function () {
		if ( S.loaded ) {
			S.stale = true;
			$( 'as-stale' ).hidden = false;
			Array.prototype.forEach.call( document.querySelectorAll( '.as-retry' ), function ( b ) {
				b.disabled = true;
				b.setAttribute( 'aria-disabled', 'true' );
			} );
			refreshBar();
		}
	};

	UI.modules.sync = {
		init: function () {
			$( 'as-compare' ).addEventListener( 'click', compare );
			$( 'as-q' ).addEventListener( 'input', function ( e ) {
				window.clearTimeout( searchTimer );
				var v = e.target.value;
				searchTimer = window.setTimeout( function () {
					S.q = v.trim().toLowerCase();
					if ( S.loaded ) {
						renderList();
					}
				}, 200 );
			} );
			$( 'as-scope' ).addEventListener( 'change', function ( e ) {
				S.scope = e.target.value;
				if ( S.loaded ) {
					renderList();
				}
			} );
			Array.prototype.forEach.call( document.querySelectorAll( '[data-bulk]' ), function ( b ) {
				b.addEventListener( 'click', function () {
					bulk( b.getAttribute( 'data-bulk' ) );
				} );
			} );
			$( 'as-clear-filters' ).addEventListener( 'click', clearFilters );
			showResults( false );
		},
		show: function () {}
	};
	sync.start = function () {
		UI.showTab( 'sync' );
		if ( ! S.busy ) {
			compare();
		}
	};
}() );
