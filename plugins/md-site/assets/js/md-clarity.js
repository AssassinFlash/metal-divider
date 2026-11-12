/**
 * Microsoft Clarity bootstrapper (project zccyz0dp2k).
 *
 * Served as a first-party external script so script-src keeps 'unsafe-inline'
 * closed under md-site-guards.php.
 */
( function ( c, l, a, r, i, t, y ) {
	c[ a ] =
		c[ a ] ||
		function () {
			( c[ a ].q = c[ a ].q || [] ).push( arguments );
		};
	t = l.createElement( r );
	t.async = true;
	t.src = 'https://www.clarity.ms/tag/' + i;
	y = l.getElementsByTagName( r )[ 0 ];
	if ( y && y.parentNode ) {
		y.parentNode.insertBefore( t, y );
	} else {
		l.head.appendChild( t );
	}
} )( window, document, 'clarity', 'script', 'zccyz0dp2k' );
