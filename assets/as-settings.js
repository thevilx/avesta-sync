/* Avesta Sync admin UI: Settings tab behaviour and app boot. */
( function () {
	'use strict';

	var UI = window.AvestaSyncUI;
	if ( ! UI ) {
		return;
	}
	var t = UI.t;
	var el = UI.el;
	var $ = UI.$;
	var initialSnap = '';
	var dirty = false;

	/* Serialised form state: dirty means "differs from what was loaded", so reverting a field clears it. */
	function snapshot( form ) {
		var parts = [];
		new FormData( form ).forEach( function ( v, k ) {
			if ( k !== '_wpnonce' && k !== '_wp_http_referer' && k !== 'action' ) {
				parts.push( k + '=' + ( typeof v === 'string' ? v : '' ) );
			}
		} );
		return parts.join( '&' );
	}

	function toggleSecret( input, btn ) {
		var show = input.type === 'password';
		input.type = show ? 'text' : 'password';
		btn.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
		btn.querySelector( '.as-btn__label' ).textContent = show ? t.hide : t.show;
	}

	function scopeLists() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-scope-box]' ), function ( box ) {
			var cb = document.querySelector( 'input[name="avesta_sync[scopes][]"][value="' + box.getAttribute( 'data-scope-box' ) + '"]' );
			box.hidden = ! ( cb && cb.checked );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.as-scope' ), function ( c ) {
			var cb = c.querySelector( 'input' );
			c.classList.toggle( 'is-on', cb.checked );
		} );
	}

	function testConnection() {
		var out = $( 'as-test-result' );
		var btn = $( 'as-test' );
		out.className = 'as-inline-result';
		UI.clear( out );
		out.appendChild( UI.el( 'span', 'as-spinner' ) );
		out.appendChild( document.createTextNode( ' ' + t.working ) );
		btn.disabled = true;
		UI.post( 'avesta_sync_test', { remote_url: $( 'as-url' ).value, remote_key: $( 'as-rkey' ).value } ).then( function ( d ) {
			btn.disabled = false;
			UI.clear( out );
			out.className = 'as-inline-result is-ok';
			out.appendChild( UI.icon( 'yes-alt' ) );
			out.appendChild( UI.msg( 'testOk', null, [ d.site, d.version, d.php, d.post_max_size, d.chunk ] ) );
			UI.setPill( 'ok' );
			UI.modules.dashboard.invalidate();
		} ).catch( function ( e ) {
			btn.disabled = false;
			UI.clear( out );
			out.className = 'as-inline-result is-err';
			out.appendChild( UI.icon( 'warning' ) );
			out.appendChild( el( 'span', '', e.message ) );
			UI.setPill( 'bad' );
			UI.modules.dashboard.invalidate();
		} );
	}

	UI.modules.settings = {
		init: function () {
			var form = $( 'as-settings' );
			initialSnap = snapshot( form );
			var mark = function () {
				dirty = snapshot( form ) !== initialSnap;
				$( 'as-dirty' ).hidden = ! dirty;
			};
			form.addEventListener( 'input', mark );
			form.addEventListener( 'change', mark );
			form.addEventListener( 'submit', function () {
				dirty = false;
			} );
			window.addEventListener( 'beforeunload', function ( e ) {
				if ( dirty ) {
					e.preventDefault();
					e.returnValue = '';
				}
			} );
			UI.leaveGuards.settings = function ( proceed ) {
				if ( ! dirty ) {
					return false;
				}
				UI.confirm( {
					title: t.unsavedTitle,
					message: t.unsavedText,
					confirmLabel: t.leave,
					cancelLabel: t.stay,
					danger: true,
					onConfirm: proceed
				} );
				return true;
			};

			$( 'as-rkey-toggle' ).addEventListener( 'click', function () {
				toggleSecret( $( 'as-rkey' ), $( 'as-rkey-toggle' ) );
			} );
			$( 'as-key-toggle' ).addEventListener( 'click', function () {
				toggleSecret( $( 'as-own-key' ), $( 'as-key-toggle' ) );
			} );
			$( 'as-copy' ).addEventListener( 'click', function () {
				var lbl = $( 'as-copy' ).querySelector( '.as-btn__label' );
				UI.copy( $( 'as-own-key' ).value, function () {
					lbl.textContent = t.copied;
					UI.announce( t.copied );
					window.setTimeout( function () {
						lbl.textContent = t.copy;
					}, 1800 );
				} );
			} );
			$( 'as-test' ).addEventListener( 'click', testConnection );

			var regenForm = $( 'as-regen-form' );
			var regenBtn = $( 'as-regen' );
			regenBtn.addEventListener( 'click', function () {
				UI.confirm( {
					title: t.regenTitle,
					message: t.confirmRegen,
					confirmLabel: t.regenerate,
					danger: true,
					onConfirm: function () {
						regenForm.submit();
					}
				} );
			} );

			Array.prototype.forEach.call( document.querySelectorAll( 'input[name="avesta_sync[scopes][]"]' ), function ( cb ) {
				cb.addEventListener( 'change', scopeLists );
			} );
			scopeLists();
		},
		show: function () {}
	};

	/* ---------- boot ---------- */
	function boot() {
		UI.initTabs();
		UI.ensureTab( 'dashboard' );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
