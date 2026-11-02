/* Avesta Sync admin UI. Vanilla JS, no innerHTML with data. */
( function () {
	'use strict';

	var cfg = window.AvestaSync;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;
	var state = { rows: [], run: '' };
	var busy = false;

	function $( id ) {
		return document.getElementById( id );
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined ) {
			n.textContent = text;
		}
		return n;
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.text();
			} )
			.then( function ( txt ) {
				var j;
				try {
					j = JSON.parse( txt );
				} catch ( e ) {
					throw new Error( 'Unexpected server response: ' + txt.slice( 0, 200 ) );
				}
				if ( ! j.success ) {
					throw new Error( ( j.data && j.data.message ) || 'Request failed' );
				}
				return j.data;
			} );
	}

	function fmtSize( n ) {
		if ( n === null || n === undefined ) {
			return '';
		}
		if ( n < 1024 ) {
			return n + ' B';
		}
		if ( n < 1048576 ) {
			return ( n / 1024 ).toFixed( 1 ) + ' KB';
		}
		return ( n / 1048576 ).toFixed( 1 ) + ' MB';
	}

	function log( msg, isErr ) {
		var box = $( 'as-log' );
		box.hidden = false;
		box.appendChild( el( 'div', isErr ? 'err' : '', msg ) );
		box.scrollTop = box.scrollHeight;
	}

	/* ---- copy key ---- */
	var copyBtn = $( 'as-copy' );
	if ( copyBtn ) {
		copyBtn.addEventListener( 'click', function () {
			var inp = $( 'as-own-key' );
			inp.select();
			var done = function () {
				var old = copyBtn.textContent;
				copyBtn.textContent = t.copied;
				setTimeout( function () {
					copyBtn.textContent = old;
				}, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( inp.value ).then( done, function () {
					document.execCommand( 'copy' );
					done();
				} );
			} else {
				document.execCommand( 'copy' );
				done();
			}
		} );
	}

	var regen = $( 'as-regen-form' );
	if ( regen ) {
		regen.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( t.confirmRegen ) ) {
				e.preventDefault();
			}
		} );
	}

	/* ---- test connection ---- */
	$( 'as-test' ).addEventListener( 'click', function () {
		var out = $( 'as-test-result' );
		out.className = '';
		out.textContent = t.working;
		post( 'avesta_sync_test', { remote_url: $( 'as-url' ).value, remote_key: $( 'as-rkey' ).value } )
			.then( function ( d ) {
				out.className = 'ok-text';
				out.textContent = 'OK: ' + d.site + ' (Avesta Sync ' + d.version + ', PHP ' + d.php + ', post_max_size ' + d.post_max_size + ', chunk ' + d.chunk + ')';
			} )
			.catch( function ( e ) {
				out.className = 'err-text';
				out.textContent = e.message;
			} );
	} );

	/* ---- compare ---- */
	function badge( status ) {
		return el( 'span', 'as-badge st-' + status, status );
	}

	function side( s ) {
		if ( ! s ) {
			return el( 'span', 'as-meta', '-' );
		}
		var txt = ( s.size !== null ? fmtSize( s.size ) + ' · ' : '' ) + s.sha.slice( 0, 7 );
		return el( 'span', 'as-meta', txt );
	}

	function dirSelect( row ) {
		var sel = el( 'select' );
		sel.setAttribute( 'aria-label', row.id );
		var delRemote = ! row.local && row.remote; // push means delete remote
		var delLocal = row.local && ! row.remote; // pull means delete local
		var isDeletion = row.status === 'local-deleted' || row.status === 'remote-deleted';
		var opts = [
			[ 'skip', t.skip ],
			[ 'push', delRemote ? t.pushDel : ( isDeletion ? t.pushRestore : t.push ) ],
			[ 'pull', delLocal ? t.pullDel : ( isDeletion ? t.pullRestore : t.pull ) ]
		];
		opts.forEach( function ( o ) {
			var op = el( 'option', '', o[ 1 ] );
			op.value = o[ 0 ];
			sel.appendChild( op );
		} );
		sel.value = row.action;
		return sel;
	}

	function render( data ) {
		state.rows = data.rows;
		state.run = data.run;
		var tbody = $( 'as-table' ).querySelector( 'tbody' );
		while ( tbody.firstChild ) {
			tbody.removeChild( tbody.firstChild );
		}
		var order = { theme: 1, plugins: 2, content: 3, uploads: 4 };
		var rows = data.rows.slice().sort( function ( a, b ) {
			return ( order[ a.scope ] - order[ b.scope ] ) || a.group.localeCompare( b.group ) || a.id.localeCompare( b.id );
		} );
		var group = null;
		rows.forEach( function ( row ) {
			if ( row.group !== group ) {
				group = row.group;
				var gr = el( 'tr', 'as-group' );
				var th = el( 'th', '', group );
				th.colSpan = 6;
				gr.appendChild( th );
				tbody.appendChild( gr );
			}
			var tr = el( 'tr' );
			var c0 = el( 'th', 'check-column' );
			c0.scope = 'row';
			var cb = el( 'input' );
			cb.type = 'checkbox';
			cb.checked = row.action !== 'skip';
			cb.setAttribute( 'aria-label', row.id );
			c0.appendChild( cb );
			var c1 = el( 'td' );
			c1.appendChild( el( 'span', 'as-path', row.scope === 'content' ? row.label : row.id ) );
			var c2 = el( 'td' );
			c2.appendChild( badge( row.status ) );
			var c3 = el( 'td' );
			c3.appendChild( side( row.local ) );
			var c4 = el( 'td' );
			c4.appendChild( side( row.remote ) );
			var c5 = el( 'td' );
			var sel = dirSelect( row );
			c5.appendChild( sel );
			sel.addEventListener( 'change', function () {
				cb.checked = sel.value !== 'skip';
			} );
			cb.addEventListener( 'change', function () {
				if ( cb.checked && sel.value === 'skip' ) {
					sel.value = row.action !== 'skip' ? row.action : ( row.local ? 'push' : 'pull' );
				}
			} );
			[ c0, c1, c2, c3, c4, c5 ].forEach( function ( c ) {
				tr.appendChild( c );
			} );
			tr._row = row;
			tr._cb = cb;
			tr._sel = sel;
			tbody.appendChild( tr );
		} );
	}

	function setStatus( msg, cls ) {
		var s = $( 'as-status' );
		s.className = cls || '';
		s.textContent = msg;
	}

	$( 'as-compare' ).addEventListener( 'click', function () {
		if ( busy ) {
			return;
		}
		busy = true;
		$( 'as-summary' ).textContent = '';
		setStatus( t.working );
		post( 'avesta_sync_compare', {} )
			.then( function ( d ) {
				busy = false;
				var counts = {};
				d.rows.forEach( function ( r ) {
					counts[ r.status ] = ( counts[ r.status ] || 0 ) + 1;
				} );
				var parts = Object.keys( counts ).map( function ( k ) {
					return counts[ k ] + ' ' + k;
				} );
				parts.push( d.identical + ' identical' );
				setStatus( parts.join( ', ' ) );
				$( 'as-results' ).hidden = d.rows.length === 0;
				if ( d.rows.length === 0 ) {
					setStatus( t.nothing + ' (' + d.identical + ')', 'ok-text' );
					return;
				}
				render( d );
			} )
			.catch( function ( e ) {
				busy = false;
				setStatus( e.message, 'err-text' );
			} );
	} );

	/* ---- bulk selectors ---- */
	function rowsEls() {
		return Array.prototype.slice.call( $( 'as-table' ).querySelectorAll( 'tbody tr' ) ).filter( function ( r ) {
			return r._row;
		} );
	}

	function setRow( tr, dir ) {
		tr._sel.value = dir;
		tr._cb.checked = dir !== 'skip';
	}

	document.querySelectorAll( '[data-bulk]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var mode = b.getAttribute( 'data-bulk' );
			rowsEls().forEach( function ( tr ) {
				var r = tr._row;
				if ( mode === 'none' ) {
					setRow( tr, 'skip' );
				} else if ( mode === 'push' && r.action === 'push' ) {
					setRow( tr, 'push' );
				} else if ( mode === 'pull' && r.action === 'pull' ) {
					setRow( tr, 'pull' );
				} else if ( mode === 'conflict-push' && r.status === 'conflict' ) {
					setRow( tr, 'push' );
				} else if ( mode === 'conflict-pull' && r.status === 'conflict' ) {
					setRow( tr, 'pull' );
				}
			} );
		} );
	} );

	$( 'as-all' ).addEventListener( 'change', function ( e ) {
		rowsEls().forEach( function ( tr ) {
			var r = tr._row;
			setRow( tr, e.target.checked ? ( r.action !== 'skip' ? r.action : ( r.local ? 'push' : 'pull' ) ) : 'skip' );
		} );
	} );

	/* ---- apply ---- */
	function isDeletion( r, dir ) {
		return ( dir === 'push' && ! r.local ) || ( dir === 'pull' && ! r.remote );
	}

	$( 'as-apply' ).addEventListener( 'click', function () {
		if ( busy ) {
			return;
		}
		var queue = rowsEls().filter( function ( tr ) {
			return tr._cb.checked && tr._sel.value !== 'skip';
		} ).map( function ( tr ) {
			return { row: tr._row, dir: tr._sel.value, tr: tr };
		} );
		if ( ! queue.length ) {
			setStatus( t.noneSel, 'err-text' );
			return;
		}
		if ( queue.some( function ( q ) {
			return isDeletion( q.row, q.dir );
		} ) && ! window.confirm( t.confirmDel ) ) {
			return;
		}
		queue.sort( function ( a, b ) {
			return a.row.ord - b.row.ord;
		} );
		busy = true;
		var prog = $( 'as-progress' );
		prog.max = queue.length;
		prog.value = 0;
		prog.hidden = false;
		$( 'as-log' ).textContent = '';
		var okN = 0;
		var failN = 0;
		var media = false;

		function next( i ) {
			if ( i >= queue.length ) {
				busy = false;
				var cls = failN ? 'err-text' : 'ok-text';
				var sum = $( 'as-summary' );
				sum.className = 'as-summary ' + cls;
				sum.textContent = t.done + ': ' + okN + ' ' + t.ok + ', ' + failN + ' ' + t.failed + '.' + ( media ? ' ' + t.mediaNote : '' );
				return;
			}
			var q = queue[ i ];
			var label = q.row.scope === 'content' ? q.row.label : q.row.id;
			var size = q.row.local && q.dir === 'push' ? q.row.local.size : ( q.row.remote ? q.row.remote.size : null );
			log( '[' + ( i + 1 ) + '/' + queue.length + '] ' + q.dir + ' ' + label + ( size ? ' (' + fmtSize( size ) + ')' : '' ) + ' ...' );
			post( 'avesta_sync_apply', {
				run: state.run,
				item: JSON.stringify( { scope: q.row.scope, id: q.row.id, action: q.dir, del: isDeletion( q.row, q.dir ) } )
			} ).then( function ( d ) {
				okN++;
				if ( q.row.scope === 'uploads' && ! isDeletion( q.row, q.dir ) ) {
					media = true;
				}
				log( '    ' + t.ok + ': ' + d.message );
				q.tr._cb.checked = false;
				q.tr._cb.disabled = true;
				q.tr._sel.disabled = true;
			} ).catch( function ( e ) {
				failN++;
				log( '    ' + t.failed + ': ' + e.message, true );
			} ).then( function () {
				prog.value = i + 1;
				next( i + 1 );
			} );
		}
		next( 0 );
	} );
}() );
