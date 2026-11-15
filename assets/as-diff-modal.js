/* Avesta Sync admin UI: diff modal. */
( function () {
	'use strict';

	var UI = window.AvestaSyncUI;
	if ( ! UI || ! UI.diffFill ) {
		return;
	}
	var t = UI.t;
	var el = UI.el;
	var fill = UI.diffFill;

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
		var prose = row.scope === 'content' || row.scope === 'woocommerce';
		var path = el( 'p', 'as-diff__path' + ( prose ? ' is-prose' : '' ) );
		if ( prose ) {
			path.appendChild( UI.proseLabel( row.label, row.meta && row.meta.sku, row.meta && row.meta.name ) );
		} else {
			path.textContent = row.id;
			path.setAttribute( 'dir', 'ltr' );
		}
		var wrap = el( 'div', 'as-diffouter' );
		wrap.appendChild( path );
		if ( row.scope === 'woocommerce' && row.group === 'wc_products' ) {
			var note = el( 'p', 'as-alert as-alert--info as-diff__note' );
			note.appendChild( UI.icon( 'info' ) );
			note.appendChild( el( 'span', 'as-alert__text', t.diffStockNote ) );
			wrap.appendChild( note );
		}
		wrap.appendChild( body );
		m = UI.modal( { title: t.diffTitle, body: wrap, footer: foot, wide: true } );
		if ( ! m ) {
			return;
		}
		UI.post( 'avesta_sync_preview', { scope: row.scope, id: row.id } ).then( function ( d ) {
			fill( body, d, { json: prose } );
		} ).catch( function ( e ) {
			UI.clear( body );
			body.appendChild( UI.errorBox( e.message ) );
		} );
	};
}() );
