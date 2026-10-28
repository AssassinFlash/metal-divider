/* Metal Divider site launcher — the customer-service entry point.
 *
 * Loaded on site pages by mu-plugins/md-chat-launcher.php. It renders the
 * Ask Metal Divider launcher button and opens the visitor widget (the LHC
 * mdvisitor start page) in a same-origin iframe card. The card stays lazy:
 * nothing loads until the visitor opens it. Closing is driven by the widget
 * itself (lhc_chat_closed_explicit) or Escape while focus is on the page.
 *
 * Design and behaviour mirror preview/mdvisitor-preview.html, the approved
 * reference wiring from #162.
 */
( function () {
	'use strict';

	if ( window.top !== window.self ) {
		return;
	}
	if ( window.__mdvLauncherLoaded ) {
		return;
	}
	window.__mdvLauncherLoaded = true;

	var START_PATH = '/chat/index.php/chat/chatwidget/(department)/1';
	var CSS_PATH = '/chat/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor-launcher.css';
	var CSS_VERSION = '20261028a';

	if ( location.pathname.indexOf( '/chat/' ) === 0 ) {
		return;
	}

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		var link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = CSS_PATH + '?v=' + CSS_VERSION;
		document.head.appendChild( link );

		var launcher = document.createElement( 'button' );
		launcher.type = 'button';
		launcher.className = 'mdvl-launcher';
		launcher.id = 'mdvl-open';
		launcher.setAttribute( 'aria-controls', 'mdvl-card' );
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.setAttribute( 'aria-haspopup', 'dialog' );

		var mark = document.createElement( 'span' );
		mark.className = 'mdvl-mark';
		mark.setAttribute( 'aria-hidden', 'true' );
		mark.textContent = 'MD';

		var label = document.createElement( 'span' );
		label.className = 'mdvl-label';
		label.textContent = 'Ask Metal Divider';

		var dot = document.createElement( 'span' );
		dot.className = 'mdvl-dot';
		dot.setAttribute( 'aria-hidden', 'true' );

		launcher.appendChild( mark );
		launcher.appendChild( label );
		launcher.appendChild( dot );

		var card = document.createElement( 'div' );
		card.className = 'mdvl-card';
		card.id = 'mdvl-card';
		card.setAttribute( 'role', 'dialog' );
		card.setAttribute( 'aria-label', 'Metal Divider assistant' );
		card.hidden = true;

		var frame = null;

		function ensureFrame() {
			if ( frame ) {
				return;
			}
			frame = document.createElement( 'iframe' );
			frame.className = 'mdvl-frame';
			frame.title = 'Metal Divider assistant';
			frame.src = START_PATH + '?v=' + Date.now() + '&URLReferer=' + encodeURIComponent( window.location.href ) + '&r=' + encodeURIComponent( window.location.origin );
			card.appendChild( frame );
		}

		function openCard() {
			ensureFrame();
			card.hidden = false;
			launcher.setAttribute( 'aria-expanded', 'true' );
			launcher.hidden = true;
			if ( frame.contentWindow ) {
				frame.contentWindow.focus();
			}
		}

		function closeCard() {
			if ( card.hidden ) {
				return;
			}
			card.hidden = true;
			launcher.setAttribute( 'aria-expanded', 'false' );
			launcher.hidden = false;
			launcher.focus();
		}

		launcher.addEventListener( 'click', openCard );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closeCard();
			}
		} );

		window.addEventListener( 'message', function ( event ) {
			if ( event.origin !== window.location.origin ) {
				return;
			}
			if ( event.data === 'lhc_chat_closed_explicit' ) {
				closeCard();
			}
		} );

		document.body.appendChild( card );
		document.body.appendChild( launcher );
	} );
}() );
