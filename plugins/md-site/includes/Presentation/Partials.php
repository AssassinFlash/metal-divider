<?php
/**
 * The card partials: the markup LiveCanvas owns, rendered with data.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * The reusable partials of the pattern faces, and the one way to render
 * them.
 *
 * The rule this class exists for (AGENTS.md §5): a Tailwind utility, a
 * gradient stop or an SVG path is a design decision, and design decisions
 * belong to the template layer — the LiveCanvas partial and the WindPress
 * compiler — never to a PHP string. The data arm keeps the opposite half: it
 * reads WooCommerce and hands the partial plain values.
 *
 * **Who owns a partial.** Presentation is repository-owned and WooCommerce is
 * data-owned; that is the same split the rest of the presentation layer
 * already runs on:
 *
 * - the repository file `content/livecanvas/partials/<name>.html` is the
 *   source of truth for the markup, exactly as the other templates under
 *   `content/livecanvas/` are;
 * - `wp md-site present` deploys it into an `lc_partial` post
 *   (`Templates::sync_partials()`), overwriting that post's content when the
 *   file changed — the same push every other face gets;
 * - WindPress's LiveCanvas integration compiles the classes it finds in
 *   `lc_partial` posts, so the deployed copy is what puts the card utilities
 *   into the compiled cache;
 * - `Templates::drift()` and `wp md-site present --check` compare the two, so
 *   a divergence is reported rather than silently resolved.
 *
 * A partial edited only in the database therefore does **not** survive the
 * next `present` — the repository wins, by design, and the drift check says so
 * before it happens. That is not a dual authority: there is one source of
 * truth and one deployed copy, the same relationship the page templates have.
 *
 * **Why the database copy is read first at render time.** The reader takes the
 * published `lc_partial` when the site has one and falls back to the file when
 * it does not. That is not a claim that database edits are durable — they are
 * not — it is what makes the two states agree: the copy LiveCanvas renders
 * (and the copy WindPress compiled the card's utilities from) is the copy the
 * visitor sees, so a site whose `present` is current renders identical markup
 * either way, and a site whose `present` has not run yet still renders cards
 * instead of blanks. The fallback covers exactly two situations: a partial that
 * is missing from the database, and one whose post is not published. In both
 * the file answers, because a face with no markup at all is worse than a face
 * drawn from the repository copy that `present` would have deployed.
 *
 * The mechanism is the framework's own, three pieces and nothing else:
 *
 * - a partial is an HTML file under `content/livecanvas/partials/`, deployed as
 *   an `lc_partial` post by `wp md-site present` and scanned by WindPress like
 *   every other template;
 * - its values are **shortcodes** (`[md_pattern_code]`, `[md_media_url]`,
 *   …), so rendering is `do_shortcode()` — no expression language, no
 *   conditionals, no loops, no template engine to learn or to keep in
 *   step with LiveCanvas;
 * - a partial composes another partial with `[md_partial name="…"]`, which
 *   is this class's only shortcode, and the values a *call site* supplies
 *   (the home card's eyebrow, the tile's panel) reach the markup through
 *   `[md_pattern_context name="…"]` — the scope pushed around that one
 *   render, popped when it ends.
 */
final class Partials {

	/**
	 * The nested-partial shortcode.
	 */
	public const PARTIAL = 'md_partial';

	/**
	 * The per-image fragment of a partial (`[md_pattern_fragment]`), the
	 * same renderer under the name a fragment reads better by.
	 */
	public const FRAGMENT = 'md_pattern_fragment';

	/**
	 * The scope-list loop (`[md_each]`).
	 */
	public const EACH = 'md_each';

	/**
	 * The enclosing conditional (`[md_if items="…"] … [/md_if]`).
	 */
	public const IF = 'md_if';

	/**
	 * The partial files, relative to this plugin.
	 */
	private const DIR = 'content/livecanvas/partials';

	/**
	 * The installed partial post type.
	 */
	private const POST_TYPE = 'lc_partial';

	/**
	 * The globals `WP_Query::setup_postdata()` writes, and therefore the ones
	 * a render has to put back (see `render_post()`).
	 */
	private const LOOP_GLOBALS = array(
		'post',
		'id',
		'authordata',
		'currentday',
		'currentmonth',
		'page',
		'pages',
		'multipage',
		'more',
		'numpages',
	);

	/**
	 * Markup already resolved this request, keyed by partial name.
	 *
	 * @var array<string, string>
	 */
	private array $contents = array();

	/**
	 * The render scope the value shortcodes read. It is `PartialFields`'s
	 * own instance because a shortcode can only reach the object its global
	 * name was registered on: pushing the scope anywhere else would give the
	 * template a frame its value shortcodes cannot see.
	 *
	 * @var PartialFields
	 */
	private readonly PartialFields $fields;

	/**
	 * The request's instance, created on first use.
	 *
	 * @var self|null
	 */
	private static ?self $shared = null;

	/**
	 * Takes the scope owner the value shortcodes read.
	 *
	 * @param PartialFields|null $fields The scope owner, `PartialFields::shared()` by default.
	 */
	public function __construct( ?PartialFields $fields = null ) {
		$this->fields = $fields ?? PartialFields::shared();
	}

	/**
	 * The site's instance: what `register()` hooks the shortcode on, and
	 * what a renderer calls.
	 */
	public static function shared(): self {
		return self::$shared ??= new self();
	}

	/**
	 * Forgets the request's instance and its cached markup (tests only).
	 */
	public static function forget(): void {
		self::$shared = null;
		PartialFields::forget();
	}

	/**
	 * Adds the nested-partial, fragment, item-loop and conditional shortcodes.
	 */
	public function register(): void {
		\add_shortcode( self::PARTIAL, array( $this, 'nested' ) );
		\add_shortcode( self::FRAGMENT, array( $this, 'nested' ) );
		\add_shortcode( self::EACH, array( $this, 'each' ) );
		\add_shortcode( self::IF, array( $this, 'if_items' ) );
	}

	/**
	 * `[md_partial name="…"]`: the named partial, rendered in the current
	 * scope — how one partial embeds another without either of them carrying
	 * the other's markup.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes: name (required).
	 * @return string The rendered partial, empty for an unknown name.
	 */
	public function nested( $atts = array() ): string {
		$atts = \shortcode_atts( array( 'name' => '' ), (array) $atts, self::PARTIAL );
		return $this->render( (string) $atts['name'] );
	}

	/**
	 * `[md_each items="gallery" partial="…" limit="2"]`: the named partial
	 * once per item of a scope list, with that item's own values in scope.
	 *
	 * The one loop the cards need, and the reason it is a shortcode rather
	 * than a template construct: LiveCanvas's own loop tags iterate *posts*,
	 * and the gallery strip (and the lightbox strip) iterate the images of
	 * one product — an array WooCommerce hands over, not a query. The partial
	 * never sees the loop: it is written for one image and rendered n times,
	 * so its markup stays a single item's markup.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes: items, partial, limit.
	 * @return string The concatenated renders, empty without items.
	 */
	public function each( $atts = array() ): string {
		$atts = \shortcode_atts(
			array(
				'items'   => '',
				'partial' => '',
				'limit'   => '0',
			),
			(array) $atts,
			self::EACH
		);
		$name = (string) $atts['partial'];
		if ( '' === $name ) {
			return '';
		}
		$limit = (int) $atts['limit'];
		$out   = '';
		foreach ( $this->fields->items( (string) $atts['items'] ) as $index => $item ) {
			if ( $limit > 0 && $index >= $limit ) {
				break;
			}
			$frame      = $item;
			$frame['i'] = $index;
			$frame['n'] = $index + 1;
			$out       .= $this->render( $name, $frame );
		}
		return $out;
	}

	/**
	 * `[md_if items="gallery"] … [/md_if]`: the enclosed markup only when the
	 * scope list is non-empty.
	 *
	 * The wrapper a loop needs and a partial cannot express: the tile's thumb
	 * strip is a two-slot frame, and the converted markup printed no frame at
	 * all for a pattern with one image — `[md_each]` alone would print an
	 * empty grid with its gap. Asking the same list the loop walks means the
	 * frame and its items can never disagree.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes: items (required).
	 * @param string|null                 $content The enclosed markup.
	 * @return string The content, or '' when the list is empty.
	 */
	public function if_items( $atts = array(), ?string $content = null ): string {
		$atts  = \shortcode_atts( array( 'items' => '' ), (array) $atts, self::IF );
		$items = $this->fields->items( (string) $atts['items'] );
		if ( array() === $items ) {
			return '';
		}
		return \do_shortcode( (string) $content );
	}

	/**
	 * Renders one partial with the values its call site supplies.
	 *
	 * @param string               $name    The partial's name (its file name, without .html).
	 * @param array<string, mixed> $context Values `[md_pattern_context]` reads, valid for this render only.
	 * @return string The markup, empty when the partial is not installed and has no file.
	 */
	public function render( string $name, array $context = array() ): string {
		$markup = $this->markup( $name );
		if ( '' === $markup ) {
			return '';
		}
		$this->fields->push( $context );
		try {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_shortcode() returns the markup every value shortcode escaped on emit.
			return \do_shortcode( $markup );
		} finally {
			$this->fields->pop();
		}
	}

	/**
	 * Renders one partial for one post: the post is set up the way
	 * LiveCanvas's own `[lc_get_posts]` loop sets it up, so the partial's
	 * value shortcodes read *that* product, and the surrounding request gets
	 * every global the loop moved put back afterwards.
	 *
	 * **Why all of them.** `setup_postdata()` writes more than the post:
	 * `WP_Query::setup_postdata()` declares `$id`, `$authordata`,
	 * `$currentday`, `$currentmonth`, `$page`, `$pages`, `$multipage`, `$more`
	 * and `$numpages` as globals and assigns all nine, while
	 * `wp_reset_postdata()` restores only what `$wp_query`'s own post derives.
	 * The two agree only when the post being rendered *is* the main query's
	 * post. Rendering editorial cards from inside a page whose main query is a
	 * `page` — every one of these faces — is exactly the case where they do
	 * not: the card's `$pages`, `$numpages` and `$more` would survive the
	 * render and leak into whatever the template asks next (a `the_content()`
	 * on the page after the cards would paginate the product's body).
	 *
	 * So the render saves the ten affected globals, sets up its post, and puts
	 * every one back in a `finally` — the caller's loop state is exactly what
	 * it was, whether the render succeeded or threw.
	 *
	 * @param string               $name    The partial's name.
	 * @param int                  $post_id The product to render.
	 * @param array<string, mixed> $context Values the call site supplies.
	 * @return string The markup, empty when the post cannot be read.
	 */
	public function render_post( string $name, int $post_id, array $context = array() ): string {
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$saved = self::save_loop_globals();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the loop idiom LiveCanvas's own [lc_get_posts] uses in this stack: the globals are saved above and restored in the finally below.
		$GLOBALS['post'] = $post;
		\setup_postdata( $post );
		// The product travels in the scope as well as in the loop: the scope
		// is what the value shortcodes read, so a partial always draws the
		// post it was rendered for even where a plugin has moved the loop.
		$context['post_id'] = $post_id;
		try {
			return $this->render( $name, $context );
		} finally {
			\wp_reset_postdata();
			self::restore_loop_globals( $saved );
		}
	}

	/**
	 * The globals `setup_postdata()` writes, with their current values.
	 *
	 * `array_key_exists` rather than `??` on purpose: a global that is unset
	 * must come back unset, not as a null the next `isset()` reads as present.
	 *
	 * @return array<string, mixed> The saved values, keyed by global name.
	 */
	private static function save_loop_globals(): array {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- these are WordPress's own loop globals ($post, $id, $pages, …), not variables this plugin introduces; renaming them would stop the restore from restoring anything.
		$saved = array();
		foreach ( self::LOOP_GLOBALS as $name ) {
			$saved[ $name ] = array_key_exists( $name, $GLOBALS )
				? array(
					'set'   => true,
					'value' => $GLOBALS[ $name ],
				)
				: array( 'set' => false );
		}
		return $saved;
		// phpcs:enable
	}

	/**
	 * Puts the globals back exactly as the caller had them.
	 *
	 * @param array<string, mixed> $saved The shape `save_loop_globals()` returns.
	 */
	private static function restore_loop_globals( array $saved ): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own loop globals, restored by name.
		foreach ( $saved as $name => $state ) {
			if ( ! is_array( $state ) || ! ( $state['set'] ?? false ) ) {
				unset( $GLOBALS[ $name ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring a global the render found unset.
				continue;
			}
			$GLOBALS[ $name ] = $state['value']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the value saved above.
		}
		// phpcs:enable
	}

	/**
	 * A value the current render's call site supplied, '' when the key is
	 * absent or no render is in progress — the scope's own reader, so a
	 * test of a renderer can ask without going through a shortcode.
	 *
	 * @param string $key The context key.
	 * @return string The value.
	 */
	public function context( string $key ): string {
		return $this->fields->value( $key );
	}

	/**
	 * The names of the partial files in the repository, sorted — the
	 * inventory the tests and the drift check compare `Templates` against.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		$files = \glob( self::directory() . '/*.html' );
		if ( false === $files ) {
			return array();
		}
		$names = array_map( static fn( string $path ): string => basename( $path, '.html' ), $files );
		sort( $names );
		return $names;
	}

	/**
	 * One partial file's absolute path.
	 *
	 * @param string $name The partial's name.
	 */
	public static function path( string $name ): string {
		return self::directory() . '/' . $name . '.html';
	}

	/**
	 * The partial directory, absolute.
	 */
	public static function directory(): string {
		return dirname( __DIR__, 2 ) . '/' . self::DIR;
	}

	/**
	 * The partial's markup: the deployed `lc_partial` when the site has a
	 * published one, the repository file otherwise.
	 *
	 * Cached per request: a page renders the same partial many times (once per
	 * card) and the markup cannot change mid-request.
	 *
	 * The order is what keeps the rendered card and the compiled stylesheet in
	 * step — WindPress compiles the utilities of the deployed `lc_partial`, so
	 * that copy is the one the visitor must receive. The file answers only when
	 * there is no deployed copy to read (not installed, or not published); see
	 * the class docblock for why that is a fallback and not a second source of
	 * truth.
	 *
	 * @param string $name The partial's name.
	 * @return string The markup, '' for an unknown name.
	 */
	private function markup( string $name ): string {
		if ( isset( $this->contents[ $name ] ) ) {
			return $this->contents[ $name ];
		}
		$markup = $this->installed( $name );
		if ( '' === $markup ) {
			$markup = self::file( $name );
		}
		$this->contents[ $name ] = $markup;
		return $markup;
	}

	/**
	 * The published `lc_partial` post's content, '' when there is none to read
	 * — the partial is not installed (a site before `wp md-site present`, or
	 * the tests), or its post is a draft.
	 *
	 * An unpublished post is deliberately not read: LiveCanvas renders a
	 * published partial, so a draft's content is not the markup the site is
	 * meant to show, and taking it would let an unpublished edit reach
	 * visitors. The file answers in that case.
	 *
	 * @param string $name The partial's name, which is its post name.
	 */
	private function installed( string $name ): string {
		$posts = \get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'name'           => $name,
				'posts_per_page' => 1,
			)
		);
		if ( array() === $posts ) {
			return '';
		}
		$content = (string) $posts[0]->post_content;
		return '' === trim( $content ) ? '' : rtrim( $content, "\n" );
	}

	/**
	 * The repository file's markup, refusing the empty (an empty partial
	 * would blank the card rather than fall back to anything).
	 *
	 * @param string $name The partial's name.
	 */
	private static function file( string $name ): string {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $name ) ) {
			return '';
		}
		$path = self::path( $name );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file, read per request and cached.
		$markup = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		return '' === trim( $markup ) ? '' : rtrim( $markup, "\n" );
	}
}
