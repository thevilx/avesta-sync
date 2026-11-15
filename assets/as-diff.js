/* Avesta Sync admin UI: line diff (Myers) and the diff modal. */
( function () {
	'use strict';

	var UI = window.AvestaSyncUI;
	if ( ! UI ) {
		return;
	}
	var t = UI.t;
	var el = UI.el;
	var MAX_LINES = 4000;
	var MAX_D = 2500;
	var CONTEXT = 3;
	var FOLD_MIN = 10;

	function splitLines( s ) {
		if ( s === '' ) {
			return [];
		}
		var a = s.replace( /\r\n?/g, '\n' ).split( '\n' );
		if ( a.length && a[ a.length - 1 ] === '' ) {
			a.pop();
		}
		return a;
	}

	/* Myers O(ND). Returns ops [{t:'=','-','+', text}] or null when too different. */
	function myers( a, b ) {
		var n = a.length;
		var m = b.length;
		var max = Math.min( n + m, MAX_D );
		var off = max + 1;
		var v = new Int32Array( 2 * max + 4 );
		var trace = [];
		var found = -1;
		var d, k, x, y;
		for ( d = 0; d <= max && found < 0; d++ ) {
			trace.push( { base: off - d - 1, w: v.slice( off - d - 1, off + d + 2 ) } );
			for ( k = -d; k <= d; k += 2 ) {
				if ( k === -d || ( k !== d && v[ off + k - 1 ] < v[ off + k + 1 ] ) ) {
					x = v[ off + k + 1 ];
				} else {
					x = v[ off + k - 1 ] + 1;
				}
				y = x - k;
				while ( x < n && y < m && a[ x ] === b[ y ] ) {
					x++;
					y++;
				}
				v[ off + k ] = x;
				if ( x >= n && y >= m ) {
					found = d;
					break;
				}
			}
		}
		if ( found < 0 ) {
			return null;
		}
		var ops = [];
		x = n;
		y = m;
		var px, py, pk, tr, kk;
		for ( d = found; d > 0; d-- ) {
			tr = trace[ d ];
			kk = x - y;
			if ( kk === -d || ( kk !== d && tr.w[ kk - 1 + off - tr.base ] < tr.w[ kk + 1 + off - tr.base ] ) ) {
				pk = kk + 1;
			} else {
				pk = kk - 1;
			}
			px = tr.w[ pk + off - tr.base ];
			py = px - pk;
			while ( x > px && y > py ) {
				ops.push( { t: '=', text: a[ x - 1 ] } );
				x--;
				y--;
			}
			if ( x === px ) {
				ops.push( { t: '+', text: b[ py ] } );
			} else {
				ops.push( { t: '-', text: a[ px ] } );
			}
			x = px;
			y = py;
		}
		while ( x > 0 && y > 0 ) {
			ops.push( { t: '=', text: a[ x - 1 ] } );
			x--;
			y--;
		}
		ops.reverse();
		return ops;
	}

	function diffLines( a, b ) {
		var s = 0;
		while ( s < a.length && s < b.length && a[ s ] === b[ s ] ) {
			s++;
		}
		var ea = a.length;
		var eb = b.length;
		while ( ea > s && eb > s && a[ ea - 1 ] === b[ eb - 1 ] ) {
			ea--;
			eb--;
		}
		var mid = myers( a.slice( s, ea ), b.slice( s, eb ) );
		if ( ! mid ) {
			return null;
		}
		var ops = [];
		var i;
		for ( i = 0; i < s; i++ ) {
			ops.push( { t: '=', text: a[ i ] } );
		}
		ops = ops.concat( mid );
		for ( i = ea; i < a.length; i++ ) {
			ops.push( { t: '=', text: a[ i ] } );
		}
		return ops;
	}

	/* Number the ops and build rows for side-by-side: {l:{n,text,t}|null, r:{...}|null, eq:bool}. */
	function sideRows( ops ) {
		var rows = [];
		var ln = 0;
		var rn = 0;
		var i = 0;
		var dels, adds, j;
		while ( i < ops.length ) {
			if ( ops[ i ].t === '=' ) {
				ln++;
				rn++;
				rows.push( { eq: true, l: { n: ln, text: ops[ i ].text }, r: { n: rn, text: ops[ i ].text } } );
				i++;
				continue;
			}
			dels = [];
			adds = [];
			while ( i < ops.length && ops[ i ].t !== '=' ) {
				( ops[ i ].t === '-' ? dels : adds ).push( ops[ i ].text );
				i++;
			}
			for ( j = 0; j < Math.max( dels.length, adds.length ); j++ ) {
				rows.push( {
					eq: false,
					l: j < dels.length ? { n: ++ln, text: dels[ j ], t: '-' } : null,
					r: j < adds.length ? { n: ++rn, text: adds[ j ], t: '+' } : null
				} );
			}
		}
		return rows;
	}

	function unifiedRows( ops ) {
		var ln = 0;
		var rn = 0;
		return ops.map( function ( o ) {
			if ( o.t === '=' ) {
				ln++;
				rn++;
				return { eq: true, a: ln, b: rn, text: o.text, t: '=' };
			}
			if ( o.t === '-' ) {
				return { eq: false, a: ++ln, b: null, text: o.text, t: '-' };
			}
			return { eq: false, a: null, b: ++rn, text: o.text, t: '+' };
		} );
	}

	/* Mark equal runs longer than FOLD_MIN as foldable (keeping CONTEXT lines around changes). */
	function plan( rows ) {
		var out = [];
		var i = 0;
		var j, start, end, run;
		while ( i < rows.length ) {
			if ( ! rows[ i ].eq ) {
				out.push( rows[ i++ ] );
				continue;
			}
			j = i;
			while ( j < rows.length && rows[ j ].eq ) {
				j++;
			}
			run = j - i;
			start = i === 0 ? 0 : CONTEXT;
			end = j === rows.length ? 0 : CONTEXT;
			if ( run > FOLD_MIN && run - start - end > 3 ) {
				out = out.concat( rows.slice( i, i + start ) );
				out.push( { fold: rows.slice( i + start, j - end ) } );
				out = out.concat( rows.slice( j - end, j ) );
			} else {
				out = out.concat( rows.slice( i, j ) );
			}
			i = j;
		}
		return out;
	}

	function cell( cls, text ) {
		var c = el( 'td', cls );
		if ( text !== undefined ) {
			c.textContent = text;
		}
		return c;
	}

	function mark( ty ) {
		return ty === '+' ? '+' : ( ty === '-' ? '-' : '' );
	}

	function foldRow( item, colspan, build, tbody ) {
		var tr = el( 'tr', 'as-diff__fold' );
		var td = el( 'td' );
		td.colSpan = colspan;
		var b = UI.btn( UI.msgText( 'diffUnchanged', item.fold.length, [ UI.num( item.fold.length ) ] ), 'as-btn--link', function () {
			var frag = document.createDocumentFragment();
			item.fold.forEach( function ( r ) {
				frag.appendChild( build( r ) );
			} );
			tbody.insertBefore( frag, tr );
			tbody.removeChild( tr );
		}, 'arrow-down-alt2' );
		td.appendChild( b );
		tr.appendChild( td );
		return tr;
	}

	function renderSide( ops ) {
		var table = el( 'table', 'as-diff__table as-diff__table--side' );
		var tbody = el( 'tbody' );
		function build( r ) {
			var tr = el( 'tr', r.eq ? '' : 'as-diff__row' );
			tr.appendChild( cell( 'as-diff__n', r.l ? String( r.l.n ) : '' ) );
			var lt = cell( 'as-diff__c' + ( r.l && r.l.t ? ' is-del' : ( r.l ? '' : ' is-empty' ) ) );
			if ( r.l ) {
				lt.appendChild( el( 'span', 'as-diff__m', mark( r.l.t ) ) );
				lt.appendChild( document.createTextNode( r.l.text ) );
			}
			tr.appendChild( lt );
			tr.appendChild( cell( 'as-diff__n', r.r ? String( r.r.n ) : '' ) );
			var rt = cell( 'as-diff__c' + ( r.r && r.r.t ? ' is-add' : ( r.r ? '' : ' is-empty' ) ) );
			if ( r.r ) {
				rt.appendChild( el( 'span', 'as-diff__m', mark( r.r.t ) ) );
				rt.appendChild( document.createTextNode( r.r.text ) );
			}
			tr.appendChild( rt );
			return tr;
		}
		var frag = document.createDocumentFragment();
		plan( sideRows( ops ) ).forEach( function ( item ) {
			frag.appendChild( item.fold ? foldRow( item, 4, build, tbody ) : build( item ) );
		} );
		tbody.appendChild( frag );
		table.appendChild( tbody );
		return table;
	}

	function renderUnified( ops ) {
		var table = el( 'table', 'as-diff__table as-diff__table--uni' );
		var tbody = el( 'tbody' );
		function build( r ) {
			var tr = el( 'tr', r.eq ? '' : 'as-diff__row' );
			tr.appendChild( cell( 'as-diff__n', r.a ? String( r.a ) : '' ) );
			tr.appendChild( cell( 'as-diff__n', r.b ? String( r.b ) : '' ) );
			var c = cell( 'as-diff__c' + ( r.t === '+' ? ' is-add' : ( r.t === '-' ? ' is-del' : '' ) ) );
			c.appendChild( el( 'span', 'as-diff__m', mark( r.t ) ) );
			c.appendChild( document.createTextNode( r.text ) );
			tr.appendChild( c );
			return tr;
		}
		var frag = document.createDocumentFragment();
		plan( unifiedRows( ops ) ).forEach( function ( item ) {
			frag.appendChild( item.fold ? foldRow( item, 3, build, tbody ) : build( item ) );
		} );
		tbody.appendChild( frag );
		table.appendChild( tbody );
		return table;
	}

	function pane( title, cls, text, emptyMsg ) {
		var p = el( 'div', 'as-plain__pane ' + cls );
		p.appendChild( el( 'div', 'as-plain__title', title ) );
		if ( text === null ) {
			p.appendChild( el( 'div', 'as-plain__missing as-muted', emptyMsg ) );
		} else {
			var pre = el( 'pre', 'as-plain__pre', text );
			pre.tabIndex = 0;
			pre.setAttribute( 'aria-label', title );
			p.appendChild( pre );
		}
		return p;
	}

	function renderPlain( d, note ) {
		var wrap = el( 'div', 'as-plain' );
		if ( note ) {
			wrap.appendChild( el( 'p', 'as-alert as-alert--info as-plain__note', note ) );
		}
		var grid = el( 'div', 'as-plain__grid' );
		grid.appendChild( pane( t.local, 'is-local', d.local, t.missingLocal ) );
		grid.appendChild( pane( t.remote, 'is-remote', d.remote, t.missingRemote ) );
		wrap.appendChild( grid );
		return wrap;
	}

	function statLine( ops ) {
		var add = 0;
		var del = 0;
		ops.forEach( function ( o ) {
			if ( o.t === '+' ) {
				add++;
			} else if ( o.t === '-' ) {
				del++;
			}
		} );
		var s = el( 'p', 'as-diff__stat' );
		var d = el( 'span', 'as-diff__stat-del' );
		d.appendChild( UI.msg( 'diffRemoved', del, [ UI.num( del ) ] ) );
		s.appendChild( d );
		s.appendChild( document.createTextNode( ' ' ) );
		var a = el( 'span', 'as-diff__stat-add' );
		a.appendChild( UI.msg( 'diffAdded', add, [ UI.num( add ) ] ) );
		s.appendChild( a );
		return s;
	}

	function headers() {
		var h = el( 'div', 'as-diff__heads' );
		h.appendChild( el( 'span', 'as-diff__head is-local', t.local ) );
		h.appendChild( el( 'span', 'as-diff__head is-remote', t.remote ) );
		return h;
	}

	/* Fill the modal body from a preview response. */
	function fill( body, d ) {
		UI.clear( body );
		if ( d.binary ) {
			body.appendChild( UI.empty( 'media-default', t.diffBinary ) );
			return;
		}
		if ( d.too_large ) {
			body.appendChild( UI.empty( 'media-text', t.diffTooLarge ) );
			return;
		}
		if ( d.local === null && d.remote === null ) {
			body.appendChild( UI.empty( 'media-text', t.diffNone ) );
			return;
		}
		if ( d.local === null || d.remote === null ) {
			body.appendChild( renderPlain( d, d.local === null ? t.missingLocal : t.missingRemote ) );
			return;
		}
		if ( d.local === d.remote ) {
			body.appendChild( UI.empty( 'yes-alt', t.diffIdentical ) );
			return;
		}
		var a = splitLines( d.local );
		var b = splitLines( d.remote );
		if ( a.length > MAX_LINES || b.length > MAX_LINES ) {
			body.appendChild( renderPlain( d, UI.msgText( 'diffCap', null, [ UI.num( MAX_LINES ) ] ) ) );
			return;
		}
		var ops = diffLines( a, b );
		if ( ! ops ) {
			body.appendChild( renderPlain( d, t.diffComplex ) );
			return;
		}
		if ( ! ops.some( function ( o ) {
			return o.t !== '=';
		} ) ) {
			/* Same lines after normalising line endings / trailing newline: only whitespace at line ends differs. */
			body.appendChild( UI.empty( 'editor-code', t.diffEolOnly ) );
			return;
		}
		var narrow = window.matchMedia && window.matchMedia( '(max-width: 782px)' ).matches;
		var mode = narrow ? 'uni' : 'side';
		var bar = el( 'div', 'as-diff__bar' );
		bar.appendChild( statLine( ops ) );
		var seg = el( 'div', 'as-seg' );
		seg.setAttribute( 'role', 'group' );
		seg.setAttribute( 'aria-label', t.diffView );
		var holder = el( 'div', 'as-diff' );
		var btns = {};
		function draw() {
			UI.clear( holder );
			if ( mode === 'side' ) {
				holder.appendChild( headers() );
			}
			var scroller = el( 'div', 'as-diff__scroll' );
			scroller.tabIndex = 0;
			scroller.setAttribute( 'aria-label', t.diffRegion );
			scroller.setAttribute( 'dir', 'ltr' );
			scroller.appendChild( mode === 'side' ? renderSide( ops ) : renderUnified( ops ) );
			holder.appendChild( scroller );
			Object.keys( btns ).forEach( function ( k ) {
				btns[ k ].setAttribute( 'aria-pressed', k === mode ? 'true' : 'false' );
			} );
		}
		[ [ 'side', t.diffSide ], [ 'uni', t.diffUnified ] ].forEach( function ( o ) {
			var bt = UI.btn( o[ 1 ], 'as-seg__btn', function () {
				mode = o[ 0 ];
				draw();
			} );
			btns[ o[ 0 ] ] = bt;
			seg.appendChild( bt );
		} );
		bar.appendChild( seg );
		body.appendChild( bar );
		body.appendChild( holder );
		draw();
	}

	/*
	 * Open the diff modal. row: sync row, api: {dirLabel(row,dir), setDir(row,dir), isLocked(row)}.
	 */
	UI.openDiff = function ( row, api ) {
		var body = el( 'div', 'as-diffwrap' );
		body.appendChild( UI.loading() );
		var m;
		var foot = [];
		if ( ! api.isLocked( row ) ) {
			foot.push( UI.btn( api.dirLabel( row, 'skip' ), 'as-btn--secondary', function () {
				api.setDir( row, 'skip' );
				m.close();
			} ) );
			var del = UI.sync && UI.sync.isDeletion;
			foot.push( UI.btn( api.dirLabel( row, 'pull' ), del && del( row, 'pull' ) ? 'as-btn--danger' : 'as-btn--remote', function () {
				api.setDir( row, 'pull' );
				m.close();
			}, 'arrow-left-alt' ) );
			foot.push( UI.btn( api.dirLabel( row, 'push' ), del( row, 'push' ) ? 'as-btn--danger' : 'as-btn--primary', function () {
				api.setDir( row, 'push' );
				m.close();
			}, 'arrow-right-alt' ) );
		}
		foot.unshift( UI.btn( t.close, 'as-btn--link', function () {
			m.close();
		} ) );
		var path = el( 'p', 'as-diff__path', row.scope === 'content' ? row.label : row.id );
		path.setAttribute( 'dir', row.scope === 'content' ? 'auto' : 'ltr' );
		var wrap = el( 'div', 'as-diffouter' );
		wrap.appendChild( path );
		wrap.appendChild( body );
		m = UI.modal( { title: t.diffTitle, body: wrap, footer: foot, wide: true } );
		if ( ! m ) {
			return;
		}
		UI.post( 'avesta_sync_preview', { scope: row.scope, id: row.id } ).then( function ( d ) {
			fill( body, d );
		} ).catch( function ( e ) {
			UI.clear( body );
			body.appendChild( UI.errorBox( e.message ) );
		} );
	};
}() );
