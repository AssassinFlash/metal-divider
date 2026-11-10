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

	if ( window.__mdvLauncherLoaded ) {
		return;
	}
	window.__mdvLauncherLoaded = true;
	if ( window.top !== window.self ) {
		return;
	}

	var START_PATH = '/chat/index.php/chat/chatwidget/(department)/1';
	var CSS_PATH = '/chat/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor-launcher.css';
	var CSS_VERSION = '20261111a';

	if ( location.pathname.indexOf( '/chat/' ) === 0 ) {
		return;
	}

	// WordPress product titles carry SEOPress's presentation suffix ("Agave |
	// Architectural Metal Screen Pattern"). The chat widget and a few PDPs still
	// hand that title over, so the quote dialog must receive the clean pattern
	// name rather than the SEO string.
	function cleanPatternName( value ) {
		return String( value || '' ).split( /[|｜]/ )[0].trim();
	}

	function getPageLocale() {
		var docLang = ( document.documentElement.getAttribute( 'lang' ) || '' ).toLowerCase().trim();
		if ( docLang.indexOf( 'zh' ) === 0 ) {
			return 'zh';
		}
		if ( docLang.indexOf( 'pt' ) === 0 ) {
			return 'pt-br';
		}
		if ( docLang.indexOf( 'es' ) === 0 ) {
			return 'es';
		}
		if ( docLang.indexOf( 'de' ) === 0 ) {
			return 'de';
		}
		if ( docLang.indexOf( 'fr' ) === 0 ) {
			return 'fr';
		}
		if ( docLang.indexOf( 'ar' ) === 0 ) {
			return 'ar';
		}
		if ( docLang.indexOf( 'id' ) === 0 ) {
			return 'id';
		}
		if ( docLang.indexOf( 'vi' ) === 0 ) {
			return 'vi';
		}
		if ( docLang.indexOf( 'en' ) === 0 ) {
			return 'en';
		}
		var pathMatch = window.location.pathname.match( /^\/(zh|es|pt-br|de|fr|ar|id|vi)(?:\/|$)/i );
		if ( pathMatch && pathMatch[1] ) {
			return pathMatch[1].toLowerCase();
		}
		return 'en';
	}

	function getPageDir( locale ) {
		var docDir = document.documentElement.getAttribute( 'dir' );
		if ( docDir ) {
			return docDir.toLowerCase();
		}
		return locale === 'ar' ? 'rtl' : 'ltr';
	}

	var LABELS = {
		en: { ask: 'Ask Metal Divider', assistant: 'Metal Divider assistant' },
		zh: { ask: '咨询 Metal Divider', assistant: 'Metal Divider 智能助手' },
		es: { ask: 'Preguntar a Metal Divider', assistant: 'Asistente de Metal Divider' },
		'pt-br': { ask: 'Pergunte à Metal Divider', assistant: 'Assistente Metal Divider' },
		de: { ask: 'Metal Divider fragen', assistant: 'Metal Divider Assistent' },
		fr: { ask: 'Demander à Metal Divider', assistant: 'Assistant Metal Divider' },
		ar: { ask: 'اسأل Metal Divider', assistant: 'مساعد Metal Divider' },
		id: { ask: 'Tanya Metal Divider', assistant: 'Asisten Metal Divider' },
		vi: { ask: 'Hỏi Metal Divider', assistant: 'Trợ lý Metal Divider' }
	};

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		var currentLocale = getPageLocale();
		var currentDir = getPageDir( currentLocale );
		var i18n = LABELS[ currentLocale ] || LABELS.en;

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
		launcher.setAttribute( 'aria-label', i18n.ask );

		var mark = document.createElement( 'span' );
		mark.className = 'mdvl-mark';
		mark.setAttribute( 'aria-hidden', 'true' );
		mark.textContent = 'MD';

		var label = document.createElement( 'span' );
		label.className = 'mdvl-label';
		label.textContent = i18n.ask;

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
		card.setAttribute( 'aria-label', i18n.assistant );
		card.hidden = true;

		var frame = null;

		function sendHostContext() {
			if ( ! frame || ! frame.contentWindow ) {
				return;
			}
			try {
				frame.contentWindow.postMessage(
					{
						type: 'mdv_host_context',
						pageUrl: window.location.href,
						pageTitle: document.title,
						lang: currentLocale,
						dir: currentDir
					},
					window.location.origin
				);
			} catch ( err ) {}
		}

		function ensureFrame() {
			if ( frame ) {
				return;
			}
			frame = document.createElement( 'iframe' );
			frame.className = 'mdvl-frame';
			frame.title = 'Metal Divider assistant';
			frame.addEventListener( 'load', sendHostContext );
			frame.src = START_PATH + '?v=' + Date.now() + '&URLReferer=' + encodeURIComponent( window.location.href ) + '&r=' + encodeURIComponent( window.location.origin );
			card.appendChild( frame );
		}

		function openCard() {
			ensureFrame();
			card.hidden = false;
			launcher.setAttribute( 'aria-expanded', 'true' );
			launcher.hidden = true;
			sendHostContext();
			if ( frame.contentWindow ) {
				frame.contentWindow.focus();
			}
		}

		function closeCard( restoreFocus ) {
			if ( card.hidden ) {
				return;
			}
			card.hidden = true;
			launcher.setAttribute( 'aria-expanded', 'false' );
			launcher.hidden = false;
			if ( restoreFocus !== false ) {
				launcher.focus();
			}
		}

		launcher.addEventListener( 'click', openCard );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closeCard( true );
			}
		} );

		window.addEventListener( 'message', function ( event ) {
			if ( event.origin !== window.location.origin ) {
				return;
			}
			if ( !frame || event.source !== frame.contentWindow ) {
				return;
			}
			if ( event.data === 'lhc_chat_closed_explicit' ) {
				closeCard( true );
				return;
			}
			if ( event.data && event.data.type === 'mdv_request_host_context' ) {
				sendHostContext();
				return;
			}
			if ( event.data === 'mdv_open_quote' || ( event.data && event.data.type === 'mdv_open_quote' ) ) {
				closeCard( false );
				var pdpTrigger = document.querySelector( '.pdp-quote-bar a[href="#open-quote"], .pdp-hero a[href="#open-quote"]' );
				var patternCode = pdpTrigger ? ( pdpTrigger.getAttribute( 'data-pattern-code' ) || '' ) : '';
				var patternSlug = pdpTrigger ? ( pdpTrigger.getAttribute( 'data-pattern-slug' ) || '' ) : '';
				var patternName = cleanPatternName( pdpTrigger ? ( pdpTrigger.getAttribute( 'data-pattern-name' ) || '' ) : '' );
				var patternThumb = pdpTrigger ? ( pdpTrigger.getAttribute( 'data-pattern-thumb' ) || pdpTrigger.getAttribute( 'data-quote-thumb' ) || '' ) : '';
				if ( !patternSlug && event.data && typeof event.data === 'object' ) {
					var chatSlug = typeof event.data.patternSlug === 'string' ? event.data.patternSlug.trim().toLowerCase() : '';
					var chatName = typeof event.data.patternName === 'string' ? cleanPatternName( event.data.patternName ) : '';
					if ( !chatSlug && typeof event.data.pageUrl === 'string' ) {
						var pageMatch = event.data.pageUrl.match( /(?:\/(?:zh|es|pt-br|de|fr|ar|id|vi))?\/pattern\/([a-z0-9-]+)\/?/i );
						if ( pageMatch && pageMatch[1] ) {
							chatSlug = pageMatch[1].toLowerCase();
						}
					}
					if ( chatSlug ) {
						patternSlug = chatSlug;
						patternName = chatName;
						var cardTrigger = document.querySelector( '[data-pattern-slug="' + chatSlug.replace( /["\\]/g, '' ) + '"]' );
						if ( cardTrigger ) {
							patternCode = cardTrigger.getAttribute( 'data-pattern-code' ) || '';
							patternThumb = cardTrigger.getAttribute( 'data-pattern-thumb' ) || cardTrigger.getAttribute( 'data-quote-thumb' ) || '';
						}
						if ( !patternCode && chatName ) {
							patternCode = chatName;
						}
					}
				}
				window.dispatchEvent(
					new CustomEvent( 'open-quote', {
						detail: {
							source: 'ai_chat_widget',
							sourceModule: 'ai_chat_widget',
							sourceButton: 'request_quote',
							patternCode: patternCode,
							patternSlug: patternSlug,
							patternName: patternName,
							patternThumb: patternThumb,
							quoteIntent: ''
						}
					} )
				);
			}
		} );

		document.body.appendChild( card );
		document.body.appendChild( launcher );
	} );
}() );
