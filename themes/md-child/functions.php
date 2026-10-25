<?php
/**
 * MetalDivider's child theme: the theme's own behaviours, and nothing else.
 *
 * The one boundary (spec 17 §6.2, branch research/spec-theme-base): a
 * behaviour belongs here only when swapping the parent theme would rob it of
 * its meaning — marks on the document root, and changes to the parent theme's
 * asset queue. Everything else has its own owner: pages are LiveCanvas
 * dynamic templates, styles are the WindPress volume md-site installs, the
 * inquiry chain is md-inquiry, the local isolation is mu-plugins. This file
 * stays under about sixty lines; more is a boundary breach a review stops.
 *
 * @package MetalDivider\ChildTheme
 */

declare( strict_types = 1 );

/**
 * The parent theme's front-end assets, measured on the dev instance
 * (ticket #27; spec 17 §2.4): picostrap5 enqueues its compiled CSS bundle
 * (`picostrap-styles` — a second external stylesheet beside the WindPress
 * cache, against CHECK-3, and on this child theme a link into a css-output/
 * directory it does not carry) and the Bootstrap bundle script
 * (`bootstrap5` — a script outside the CHECK-4 whitelist, which is the
 * LiveCanvas runtime, Turnstile, the inquiry plugin and WindPress's none).
 * The handles go, on the front end only; the site's styles are the WindPress
 * volume, its scripts the whitelist's own. picostrap's own opt-out is the
 * `disable_bootstrap` theme mod (how the de-linker production site did it);
 * removing the handles here keeps the behaviour in the repository, where an
 * instance reset cannot lose it.
 */
function md_child_retire_parent_frontend_assets(): void {
	wp_dequeue_style( 'picostrap-styles' );
	wp_dequeue_script( 'bootstrap5' );
	wp_dequeue_script( 'bootstrap5-childtheme' );
	wp_dequeue_script( 'dark-mode-switch' );
}
add_action( 'wp_enqueue_scripts', 'md_child_retire_parent_frontend_assets', 310 );

/**
 * The picostrap_hints() preload of the CSS bundle removed above goes too: a
 * browser told to preload a stylesheet the page never loads is a wasted
 * fetch. WordPress loads the child's functions.php before the parent's, so
 * picostrap hooks picostrap_hints after this file has run — the removal
 * waits for after_setup_theme, the first hook past both themes' files and
 * still ahead of send_headers, where picostrap_hints fires.
 */
function md_child_retire_parent_preload_hint(): void {
	remove_action( 'send_headers', 'picostrap_hints' );
}
add_action( 'after_setup_theme', 'md_child_retire_parent_preload_hint' );

/*
 * The html root `js` class (the de-linker child theme carries it) is NOT
 * added: the picostrap bundle this theme measures carries no `.js`-rooted
 * selector, the WindPress vocabulary has none, and the parent markup that
 * might have needed one went with the dequeued bundle. A flag nothing
 * consumes is dead code (spec 17 §6.3: measure, then only add what is used).
 */

/**
 * The favicon: the brand mark, served from the plugin's assets (the site
 * had none; the browser's automatic /favicon.ico request was a 404).
 */
function md_child_favicon(): void {
	$base = content_url( 'plugins/md-site/assets/brand' );
	echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url( $base . '/metal-divider-icon-32.png' ) . '">' . "\n";
	echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( $base . '/metal-divider-icon-180.png' ) . '">' . "\n";
}
add_action( 'wp_head', 'md_child_favicon' );
