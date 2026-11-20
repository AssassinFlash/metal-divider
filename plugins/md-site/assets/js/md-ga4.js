/**
 * Google Analytics 4 bootstrapper (G-CNCN4C4XMP) & conversion events.
 *
 * Served as a first-party external script so script-src keeps 'unsafe-inline'
 * closed under md-site-guards.php.
 */
( function ( w, d, measurementId ) {
	w.dataLayer = w.dataLayer || [];
	w.gtag =
		w.gtag ||
		function () {
			w.dataLayer.push( arguments );
		};
	w.gtag( 'js', new Date() );
	w.gtag( 'config', measurementId );

	var s = d.createElement( 'script' );
	s.async = true;
	s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( measurementId );
	var first = d.getElementsByTagName( 'script' )[ 0 ];
	if ( first && first.parentNode ) {
		first.parentNode.insertBefore( s, first );
	} else if ( d.head ) {
		d.head.appendChild( s );
	}

	function currentLang() {
		var meta = d.querySelector( 'meta[name="md-i18n-config"]' );
		return ( meta && meta.getAttribute( 'content' ) ) || d.documentElement.getAttribute( 'lang' ) || 'en';
	}

	function emitVerifiedLead() {
		var leadMeta = d.querySelector( 'meta[name="md-inquiry-verified"][content="1"]' );
		if ( ! leadMeta ) {
			return;
		}
		var inquiryId = leadMeta.getAttribute( 'data-inquiry-id' ) || '';
		var patternName = leadMeta.getAttribute( 'data-pattern-name' ) || '';
		var patternCode = leadMeta.getAttribute( 'data-pattern-code' ) || '';
		var dedupeKey = 'md_ga4_lead_' + inquiryId;
		if ( inquiryId ) {
			try {
				if ( w.sessionStorage && w.sessionStorage.getItem( dedupeKey ) ) {
					return;
				}
				if ( w.sessionStorage ) {
					w.sessionStorage.setItem( dedupeKey, '1' );
				}
			} catch ( err ) {
				// Private mode or disabled storage: fall through and emit once.
			}
		}
		w.gtag( 'event', 'lead_submit_success', {
			inquiry_id: inquiryId,
			pattern_name: patternName,
			pattern_code: patternCode,
			language: currentLang()
		} );
	}

	function setupWhatsAppTracking() {
		d.addEventListener(
			'click',
			function ( event ) {
				var target = event && event.target;
				if ( target && typeof target.closest !== 'function' ) {
					target = target.parentElement || null;
				}
				if ( ! target || typeof target.closest !== 'function' ) {
					return;
				}
				var link = target.closest(
					'.md-inquiry-whatsapp, .md-inquiry-whatsapp-link, .inquiry-wa, a[href^="https://wa.me/"], a[href^="https://api.whatsapp.com/"]'
				);
				if ( ! link ) {
					return;
				}
				var href = link.getAttribute( 'href' ) || '';
				var isInquiryWa =
					link.classList.contains( 'md-inquiry-whatsapp' ) ||
					link.classList.contains( 'md-inquiry-whatsapp-link' ) ||
					link.classList.contains( 'inquiry-wa' );
				if ( ! isInquiryWa && ! /^https:\/\/(?:wa\.me|api\.whatsapp\.com)\//i.test( href ) ) {
					return;
				}
				var source = isInquiryWa ? 'inquiry_form' : 'site_link';
				w.gtag( 'event', 'whatsapp_click', {
					link_url: href,
					link_source: source,
					language: currentLang(),
					page_path: w.location ? w.location.pathname : ''
				} );
			},
			true
		);
	}

	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', emitVerifiedLead );
	} else {
		emitVerifiedLead();
	}
	setupWhatsAppTracking();
} )( window, document, 'G-CNCN4C4XMP' );
