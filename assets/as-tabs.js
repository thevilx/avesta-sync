/* Avesta Sync admin UI: tabs (ARIA tablist, hash + ?tab= state, lazy init). */
( function () {
	'use strict';

	var UI = window.AvestaSyncUI;
	if ( ! UI ) {
		return;
	}
	var NAMES = [ 'dashboard', 'sync', 'backups', 'history', 'settings' ];
	var inited = {};
	var current = '';
	var list;

	function tabBtn( name ) {
		return UI.$( 'as-tab-' + name );
	}

	function valid( name ) {
		return NAMES.indexOf( name ) !== -1;
	}

	function activate( name, opts ) {
		opts = opts || {};
		NAMES.forEach( function ( n ) {
			var on = n === name;
			var b = tabBtn( n );
			var p = UI.$( 'as-panel-' + n );
			b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			b.tabIndex = on ? 0 : -1;
			p.hidden = ! on;
		} );
		var b0 = tabBtn( name );
		var first = ! inited[ name ];
		inited[ name ] = true;
		current = name;
		if ( ! opts.noHash && window.history && history.replaceState ) {
			history.replaceState( null, '', '#' + name );
		}
		var mod = UI.modules[ name ];
		if ( mod ) {
			if ( first && mod.init ) {
				mod.init();
			} else if ( mod.show ) {
				mod.show();
			}
		}
		if ( b0 && b0.scrollIntoView ) {
			b0.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
		}
		if ( UI.updateTabFades ) {
			window.setTimeout( UI.updateTabFades, 50 );
		}
		if ( opts.focus ) {
			tabBtn( name ).focus();
		}
	}

	/* Public: switch tab, honouring leave guards (e.g. unsaved settings). */
	UI.showTab = function ( name, opts ) {
		if ( ! valid( name ) || name === current || UI.modalOpen() ) {
			return;
		}
		var guard = UI.leaveGuards[ current ];
		if ( guard && guard( function () {
			activate( name, opts );
		} ) ) {
			return;
		}
		activate( name, opts );
	};

	/* ?tab= always wins over a leftover #hash on load; the URL is normalised afterwards. */
	function initial() {
		var q = /[?&]tab=([a-z]+)/.exec( window.location.search );
		if ( q && valid( q[ 1 ] ) ) {
			return q[ 1 ];
		}
		var hash = ( window.location.hash || '' ).replace( '#', '' );
		return valid( hash ) ? hash : 'dashboard';
	}

	function normaliseUrl( tab ) {
		if ( ! window.history || ! history.replaceState ) {
			return;
		}
		var params = new URLSearchParams( window.location.search );
		params.delete( 'tab' );
		params.delete( 'as_msg' );
		var qs = params.toString();
		history.replaceState( null, '', window.location.pathname + ( qs ? '?' + qs : '' ) + '#' + tab );
	}

	function init() {
		list = UI.$( 'as-tablist' );
		if ( ! list ) {
			return;
		}
		NAMES.forEach( function ( n ) {
			tabBtn( n ).addEventListener( 'click', function () {
				UI.showTab( n );
			} );
		} );
		list.addEventListener( 'keydown', function ( e ) {
			if ( UI.modalOpen() ) {
				return;
			}
			var idx = NAMES.indexOf( current );
			var rtl = window.getComputedStyle( list ).direction === 'rtl';
			var next = -1;
			if ( e.key === 'ArrowRight' ) {
				next = idx + ( rtl ? -1 : 1 );
			} else if ( e.key === 'ArrowLeft' ) {
				next = idx + ( rtl ? 1 : -1 );
			} else if ( e.key === 'Home' ) {
				next = 0;
			} else if ( e.key === 'End' ) {
				next = NAMES.length - 1;
			} else {
				return;
			}
			e.preventDefault();
			next = ( next + NAMES.length ) % NAMES.length;
			UI.showTab( NAMES[ next ], { focus: true } );
		} );
		window.addEventListener( 'hashchange', function () {
			var h = ( window.location.hash || '' ).replace( '#', '' );
			if ( valid( h ) ) {
				UI.showTab( h, { noHash: true } );
			}
		} );
		var fades = function () {
			var max = list.scrollWidth - list.clientWidth;
			var sl = list.scrollLeft;
			var rtl = window.getComputedStyle( list ).direction === 'rtl';
			var left = rtl ? ( max + sl ) > 2 : sl > 2;
			var right = rtl ? -sl > 2 : ( max - sl ) > 2;
			list.classList.toggle( 'fade-l', max > 2 && left );
			list.classList.toggle( 'fade-r', max > 2 && right );
		};
		list.addEventListener( 'scroll', fades );
		window.addEventListener( 'resize', fades );
		UI.updateTabFades = fades;
		var start = initial();
		activate( start, { noHash: true } );
		normaliseUrl( start );
	}

	/* Run a tab's init once without showing it (e.g. to fill the header pill). */
	UI.ensureTab = function ( name ) {
		if ( ! inited[ name ] && UI.modules[ name ] && UI.modules[ name ].init ) {
			inited[ name ] = true;
			UI.modules[ name ].init();
		}
	};

	UI.initTabs = init;
}() );
