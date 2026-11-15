/* Avesta Sync admin UI: History tab. */
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
	var sourceBadge = kit.sourceBadge;

	/* ---------- History ---------- */
	function actionText( it ) {
		if ( it.del ) {
			return it.action === 'push' ? t.actDelRemote : t.actDelLocal;
		}
		return it.action === 'push' ? t.actPush : t.actPull;
	}

	function drawHistory( entries ) {
		var host = UI.clear( $( 'as-history-list' ) );
		$( 'as-history-clear' ).hidden = ! entries.length;
		if ( ! entries.length ) {
			host.appendChild( UI.empty( 'backup', t.noHistory, t.noHistoryText ) );
			return;
		}
		entries.forEach( function ( en ) {
			var item = el( 'div', 'as-acc' );
			var head = el( 'div', 'as-acc__head' );
			var info = el( 'span', 'as-acc__info' );
			info.appendChild( UI.timeNode( en.time ) );
			var sub = el( 'span', 'as-acc__sub' );
			var srcLabel = en.source === 'cli' ? t.srcCli : t.srcAdmin;
			var who = String( en.user || '' ).trim();
			/* Runs started from WP-CLI carry the user name "WP-CLI": show it only once. */
			if ( who && who.toLowerCase() !== srcLabel.toLowerCase() && who.toLowerCase() !== 'wp-cli' ) {
				var wb = el( 'bdi', 'as-muted', who );
				wb.setAttribute( 'dir', 'auto' );
				sub.appendChild( wb );
				sub.appendChild( document.createTextNode( ' · ' ) );
			}
			sub.appendChild( sourceBadge( en.source ) );
			sub.appendChild( document.createTextNode( ' · ' ) );
			var rh = el( 'bdi', 'as-muted as-path', UI.host( en.remote ) );
			rh.setAttribute( 'dir', 'ltr' );
			sub.appendChild( rh );
			info.appendChild( sub );
			var tb = headBtn( [ info ] );
			head.appendChild( tb );
			var res = el( 'span', 'as-acc__result' );
			res.appendChild( el( 'span', 'as-status st-ok' ) ).appendChild( UI.msg( 'nOk', null, [ UI.num( en.ok ) ] ) );
			res.appendChild( el( 'span', 'as-status ' + ( en.failed ? 'st-conflict' : 'st-none' ) ) ).appendChild( UI.msg( 'nFailed', null, [ UI.num( en.failed ) ] ) );
			head.appendChild( res );
			var panel = el( 'div', 'as-acc__panel' );
			panel.hidden = true;
			var ul = el( 'ul', 'as-items' );
			( en.items || [] ).forEach( function ( it ) {
				var li = el( 'li', 'as-item as-item--hist' );
				var main = el( 'div', 'as-item__main' );
				main.appendChild( el( 'span', 'as-status ' + ( it.ok ? 'st-ok' : 'st-conflict' ), it.ok ? t.ok : t.failed ) );
				main.appendChild( el( 'span', 'as-tag', actionText( it ) ) );
				var isContent = it.scope === 'content';
				var p = el( 'span', isContent ? 'as-label' : 'as-path', isContent ? it.label : it.id );
				p.setAttribute( 'dir', isContent ? 'auto' : 'ltr' );
				main.appendChild( p );
				li.appendChild( main );
				if ( it.message ) {
					var mp = el( 'p', 'as-item__msg as-muted', it.message );
					mp.setAttribute( 'dir', 'auto' );
					li.appendChild( mp );
				}
				ul.appendChild( li );
			} );
			if ( ! ul.firstChild ) {
				panel.appendChild( el( 'p', 'as-muted', t.noItems ) );
			} else {
				panel.appendChild( ul );
			}
			disclosure( tb, panel, function () {} );
			item.appendChild( head );
			item.appendChild( panel );
			host.appendChild( item );
		} );
	}

	function loadHistory() {
		var host = UI.clear( $( 'as-history-list' ) );
		host.appendChild( UI.loading() );
		UI.post( 'avesta_sync_history', {} ).then( function ( d ) {
			drawHistory( d.entries );
		} ).catch( function ( e ) {
			UI.clear( host ).appendChild( UI.errorBox( e.message, loadHistory ) );
		} );
	}

	UI.modules.history = {
		init: function () {
			$( 'as-history-clear' ).addEventListener( 'click', function () {
				UI.confirm( {
					title: t.clearHistoryTitle,
					message: t.clearHistoryText,
					confirmLabel: t.clearHistory,
					danger: true,
					onConfirm: function () {
						UI.post( 'avesta_sync_history_clear', {} ).then( function () {
							UI.announce( t.historyCleared );
							kit.resetDash();
							drawHistory( [] );
						} ).catch( function ( e ) {
							UI.clear( $( 'as-history-list' ) ).appendChild( UI.errorBox( e.message, loadHistory ) );
						} );
					}
				} );
			} );
			loadHistory();
		},
		show: loadHistory
	};
}() );
