/* Avesta Sync admin UI: apply queue (confirm modal, sequential requests, log, stop, retry). */
( function () {
	'use strict';

	var UI = window.AvestaSyncUI;
	if ( ! UI || ! UI.sync || ! UI.modules.sync ) {
		return;
	}
	var t = UI.t;
	var el = UI.el;
	var $ = UI.$;
	var sync = UI.sync;
	var S = sync.state;
	var stopRequested = false;

	function logLine( msg, cls ) {
		var box = $( 'as-log' );
		box.hidden = false;
		box.appendChild( el( 'div', 'as-log__line ' + ( cls || '' ), msg ) );
		box.scrollTop = box.scrollHeight;
	}

	function summaryRow( label, n, cls ) {
		var li = el( 'li', 'as-sum__row ' + ( cls || '' ) );
		li.appendChild( el( 'strong', 'as-sum__n', UI.num( n ) ) );
		li.appendChild( el( 'span', '', label ) );
		return li;
	}

	function isProduct( r ) {
		return r.scope === 'woocommerce' && r.group === 'wc_products';
	}

	function confirmModal( items ) {
		var c = sync.counts();
		var body = el( 'div', 'as-confirm' );
		var intro = el( 'p', 'as-modal__text' );
		intro.appendChild( UI.msg( 'applyIntro', c.total, [ UI.num( c.total ) ] ) );
		body.appendChild( intro );
		var ul = el( 'ul', 'as-sum' );
		ul.appendChild( summaryRow( t.sumPush, c.push - items.pushDel ) );
		ul.appendChild( summaryRow( t.sumPull, c.pull - items.pullDel ) );
		ul.appendChild( summaryRow( t.sumDelete, c.del, c.del ? 'is-danger' : '' ) );
		body.appendChild( ul );
		if ( c.del ) {
			var w = el( 'p', 'as-alert as-alert--danger' );
			w.appendChild( UI.icon( 'warning' ) );
			w.appendChild( UI.msg( 'deleteWarn', c.del, [ UI.num( c.del ) ] ) );
			body.appendChild( w );
		}
		var stockCb = null;
		if ( sync.selected().some( isProduct ) ) {
			/* Always starts unticked: live stock is only overwritten by an explicit choice per run. */
			var box = el( 'label', 'as-stockopt' );
			stockCb = el( 'input' );
			stockCb.type = 'checkbox';
			stockCb.checked = false;
			var sb = el( 'span', 'as-stockopt__body' );
			sb.appendChild( el( 'span', 'as-stockopt__title', t.stockLabel ) );
			sb.appendChild( el( 'span', 'as-stockopt__hint as-muted', t.stockHint ) );
			box.appendChild( stockCb );
			box.appendChild( sb );
			body.appendChild( box );
			var warn = el( 'p', 'as-summary__stock' );
			warn.appendChild( UI.icon( 'warning' ) );
			warn.appendChild( el( 'span', '', t.stockWarn ) );
			warn.hidden = true;
			body.appendChild( warn );
			stockCb.addEventListener( 'change', function () {
				warn.hidden = ! stockCb.checked;
			} );
		}
		body.appendChild( el( 'p', 'as-muted as-modal__text', t.backupNote ) );
		UI.confirm( {
			title: t.applyTitle,
			body: body,
			confirmLabel: UI.msgText( 'applyN', c.total, [ UI.num( c.total ) ] ),
			danger: c.del > 0,
			onConfirm: function () {
				run( sync.selected(), stockCb ? stockCb.checked : false );
			}
		} );
	}

	function onBeforeUnload( e ) {
		e.preventDefault();
		e.returnValue = '';
	}

	function start() {
		if ( S.busy || S.stale ) {
			return;
		}
		var sel = sync.selected();
		if ( ! sel.length ) {
			UI.announce( t.noneSel );
			$( 'as-bar-text' ).textContent = t.noneSel;
			return;
		}
		var pd = 0;
		var ld = 0;
		sel.forEach( function ( r ) {
			if ( sync.isDeletion( r, r.dir ) ) {
				if ( r.dir === 'push' ) {
					pd++;
				} else {
					ld++;
				}
			}
		} );
		confirmModal( { pushDel: pd, pullDel: ld } );
	}

	function finish( okN, failN, left, failed, media, stockUsed ) {
		window.removeEventListener( 'beforeunload', onBeforeUnload );
		sync.setBusy( false );
		$( 'as-stop' ).hidden = true;
		$( 'as-apply' ).hidden = false;
		var sum = UI.clear( $( 'as-summary' ) );
		sum.hidden = false;
		sum.className = 'as-summary ' + ( failN ? 'has-fail' : 'is-ok' );
		var head = el( 'p', 'as-summary__head' );
		head.appendChild( UI.icon( failN ? 'warning' : 'yes-alt' ) );
		head.appendChild( UI.msg( 'finished', null, [ UI.num( okN ), UI.num( failN ) ] ) );
		sum.appendChild( head );
		if ( left > 0 ) {
			var sp = el( 'p', 'as-summary__line' );
			sp.appendChild( UI.msg( 'stopped', left, [ UI.num( left ) ] ) );
			sum.appendChild( sp );
		}
		if ( stockUsed ) {
			var so = el( 'p', 'as-summary__line as-summary__stock' );
			so.appendChild( UI.icon( 'warning' ) );
			so.appendChild( el( 'span', '', t.stockSummary ) );
			sum.appendChild( so );
		}
		if ( media ) {
			sum.appendChild( el( 'p', 'as-summary__line as-muted', t.mediaNote ) );
		}
		if ( failed.length ) {
			var rb = UI.btn( UI.fmt( t.retryFailed, UI.num( failed.length ) ), 'as-btn--secondary as-retry', function () {
				if ( S.busy || S.stale ) {
					return;
				}
				/* Only rows still selected with push/pull (the user may have set one to Skip meanwhile). */
				var again = failed.filter( function ( r ) {
					return ! r.done && r.dir !== 'skip';
				} );
				if ( again.length ) {
					run( again, stockUsed );
				}
			}, 'update' );
			if ( S.stale ) {
				rb.disabled = true;
				rb.setAttribute( 'aria-disabled', 'true' );
			}
			sum.appendChild( rb );
		}
		UI.announce( UI.fmt( t.finished, UI.num( okN ), UI.num( failN ) ) );
		sync.refreshBar();
	}

	function run( rows, stock ) {
		var queue = rows.slice().sort( function ( a, b ) {
			return a.ord - b.ord;
		} );
		var total = queue.length;
		var okN = 0;
		var failN = 0;
		var failed = [];
		var media = false;
		var stockUsed = !! stock && rows.some( isProduct );
		stopRequested = false;
		window.addEventListener( 'beforeunload', onBeforeUnload );
		queue.forEach( function ( r ) {
			r.err = '';
		} );
		sync.setBusy( true );
		var prog = $( 'as-progress' );
		prog.max = total;
		prog.value = 0;
		$( 'as-run' ).hidden = false;
		UI.clear( $( 'as-log' ) ).hidden = true;
		$( 'as-summary' ).hidden = true;
		$( 'as-stop' ).hidden = false;
		$( 'as-stop' ).disabled = false;
		$( 'as-apply' ).hidden = true;
		$( 'as-run' ).scrollIntoView( { block: 'nearest' } );
		$( 'as-stop' ).focus();

		function progressText( i ) {
			var args = [ UI.num( i ), UI.num( total ) ];
			UI.setMsg( $( 'as-progress-text' ), 'progress', null, args );
			UI.setMsg( $( 'as-bar-text' ), 'progress', null, args );
			UI.clear( $( 'as-bar-hidden' ) );
		}

		function next( i ) {
			progressText( i );
			if ( stopRequested || i >= total ) {
				finish( okN, failN, total - i, failed, media, stockUsed );
				return;
			}
			var r = queue[ i ];
			var dir = r.dir;
			var del = sync.isDeletion( r, dir );
			var size = r.local && dir === 'push' ? r.local.size : ( r.remote ? r.remote.size : null );
			var verb = del ? ( dir === 'push' ? t.logDelRemote : t.logDelLocal ) : ( dir === 'push' ? t.logPush : t.logPull );
			logLine( UI.fmt( t.logItem, UI.num( i + 1 ), UI.num( total ), verb, sync.label( r ) ) + ( size ? ' (' + UI.fmtSize( size ) + ')' : '' ) + ' ...' );
			UI.post( 'avesta_sync_apply', {
				run: S.run,
				item: JSON.stringify( { scope: r.scope, id: r.id, action: dir, del: del, label: r.label, stock: stockUsed && isProduct( r ) } )
			} ).then( function ( d ) {
				okN++;
				if ( r.scope === 'uploads' && ! del ) {
					media = true;
				}
				logLine( '    ' + t.ok + ': ' + d.message, 'is-ok' );
				r.done = true;
				sync.refreshRow( r );
			} ).catch( function ( e ) {
				failN++;
				failed.push( r );
				r.err = e.message;
				logLine( '    ' + t.failed + ': ' + e.message, 'is-err' );
				sync.refreshRow( r );
			} ).then( function () {
				prog.value = i + 1;
				next( i + 1 );
			} );
		}
		next( 0 );
	}

	var prevInit = UI.modules.sync.init;
	UI.modules.sync.init = function () {
		prevInit();
		$( 'as-apply' ).addEventListener( 'click', start );
		$( 'as-stop' ).addEventListener( 'click', function () {
			stopRequested = true;
			$( 'as-stop' ).disabled = true;
			$( 'as-progress-text' ).textContent = t.stopping;
			UI.announce( t.stopping );
		} );
	};
}() );
