<?php
/**
 * The value shortcodes the card partials call, and the render scope.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * The partials' data arm: every shortcode here prints a value and nothing
 * else — no element, no class, no attribute pair. The element around it, and
 * the class list that positions it, are the partial's (`Partials`).
 *
 * The split is the AGENTS §5 one, made mechanical: a value shortcode returns
 * escaped text, so a template can put it inside a heading, an attribute or a
 * URL without the caller deciding how to escape. Each one names the position
 * it is safe in:
 *
 * - `[md_pattern_id]`, `[md_pattern_image_width]`,
 *   `[md_pattern_image_height]`, `[md_media_width]`, `[md_media_height]` —
 *   integers, cast;
 * - `[md_pattern_permalink]`, `[md_pattern_image_url]`, `[md_media_url]` —
 *   URLs (`esc_url()`);
 * - `[md_pattern_code]`, `[md_pattern_family]`, `[md_pattern_series]`,
 *   `[md_pattern_best_fit]`, `[md_pattern_open_area]`, `[md_pattern_badge]`,
 *   `[md_pattern_image_alt]`, `[md_media_alt]`, `[md_pattern_context name="…"]`
 *   — HTML text (`esc_html()`, which escapes the quotes an attribute also
 *   needs, so a template may put one inside an attribute value);
 * - `[md_pattern_image_srcset sizes="…"]` — an attribute *pair*, empty when
 *   the image has no alternatives, so the attribute list stays in the
 *   template and only whether it exists travels.
 *
 * No value shortcode is ever written inside a `class` attribute: a class
 * token is the template's (AGENTS §5) and a data value in one is both a
 * design decision in PHP and, mechanically, a class the WindPress compiler
 * tries to build (`pattern-lightbox-thumb[md_media_state]` — measured, and the
 * reason the strip's selection state moved to the interaction layer, which
 * already sets it on open).
 *
 * A partial is rendered for one product (`Partials::render_post()`), so the
 * current post is the pattern being drawn and these need no arguments for
 * it. The values a *call site* supplies (the home card's eyebrow, the
 * panel's label, the thumbnail an image fragment draws) arrive through the
 * scope that render pushed — never through WooCommerce, because they are the
 * caller's wording and the caller's item, not the product's.
 *
 * The scope is instance state, so the class is testable without globals; the
 * site's one instance is `shared()`, because a shortcode can only reach the
 * object its global name was registered on. `Partials::shared()` builds
 * itself on that same instance, which is what makes a pushed frame visible to
 * the value shortcodes inside it.
 */
final class PartialFields {

	/**
	 * The current pattern's id.
	 */
	public const ID = 'md_pattern_id';

	/**
	 * The current pattern's short code.
	 */
	public const CODE = 'md_pattern_code';

	/**
	 * The current pattern's canonical URL.
	 */
	public const PERMALINK = 'md_pattern_permalink';

	/**
	 * The current pattern's family (its series term's name).
	 */
	public const FAMILY = 'md_pattern_family';

	/**
	 * The current pattern's series word.
	 */
	public const SERIES = 'md_pattern_series';

	/**
	 * The current pattern's best-fit display word.
	 */
	public const BEST_FIT = 'md_pattern_best_fit';

	/**
	 * The current pattern's open-area wording.
	 */
	public const OPEN_AREA = 'md_pattern_open_area';

	/**
	 * The card's second badge (open area, or the fit word as "Effect").
	 */
	public const BADGE = 'md_pattern_badge';

	/**
	 * The current pattern's featured image URL.
	 */
	public const IMAGE_URL = 'md_pattern_image_url';

	/**
	 * The current pattern's featured image width.
	 */
	public const IMAGE_WIDTH = 'md_pattern_image_width';

	/**
	 * The current pattern's featured image height.
	 */
	public const IMAGE_HEIGHT = 'md_pattern_image_height';

	/**
	 * The current pattern's featured image alt text.
	 */
	public const IMAGE_ALT = 'md_pattern_image_alt';

	/**
	 * The current pattern's featured image `srcset`/`sizes` attributes.
	 */
	public const IMAGE_SRCSET = 'md_pattern_image_srcset';

	/**
	 * A value the current render's call site supplied.
	 */
	public const CONTEXT = 'md_pattern_context';

	/**
	 * The current item's image URL.
	 */
	public const MEDIA_URL = 'md_media_url';

	/**
	 * The current item's image width.
	 */
	public const MEDIA_WIDTH = 'md_media_width';

	/**
	 * The current item's image height.
	 */
	public const MEDIA_HEIGHT = 'md_media_height';

	/**
	 * The current item's image alt text.
	 */
	public const MEDIA_ALT = 'md_media_alt';

	/**
	 * The current item's image dimensions, as an attribute pair or nothing.
	 */
	public const MEDIA_DIMENSIONS = 'md_media_dimensions';

	/**
	 * The current item's optional image attributes, as one suffix: the
	 * loading tier and, when the item declares them, the dimensions.
	 */
	public const MEDIA_ATTRIBUTES = 'md_media_attributes';

	/**
	 * The current item's own link (a card that is not the current post).
	 */
	public const ITEM_LINK = 'md_item_link';

	/**
	 * The current item's display name (a related pattern's code, a series'
	 * term name) — the card's heading.
	 */
	public const MEDIA_NAME = 'md_media_name';

	/**
	 * The current item's body copy (a series' description); empty when the
	 * item carries none.
	 */
	public const MEDIA_TEXT = 'md_media_text';

	/**
	 * The render scope: one frame per render in progress, innermost last.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $scope = array();

	/**
	 * The request's instance, created on first use.
	 *
	 * @var self|null
	 */
	private static ?self $shared = null;

	/**
	 * The site's instance: the one the shortcodes are registered on, and the
	 * one a renderer must push its scope onto.
	 */
	public static function shared(): self {
		return self::$shared ??= new self();
	}

	/**
	 * Forgets the request's instance (tests only: the scope must not leak
	 * between cases).
	 */
	public static function forget(): void {
		self::$shared = null;
	}

	/**
	 * Adds the shortcodes.
	 */
	public function register(): void {
		\add_shortcode( self::ID, array( $this, 'id' ) );
		\add_shortcode( self::CODE, array( $this, 'code' ) );
		\add_shortcode( self::PERMALINK, array( $this, 'permalink' ) );
		\add_shortcode( self::FAMILY, array( $this, 'series_name' ) );
		\add_shortcode( self::SERIES, array( $this, 'series' ) );
		\add_shortcode( self::BEST_FIT, array( $this, 'best_fit' ) );
		\add_shortcode( self::OPEN_AREA, array( $this, 'open_area' ) );
		\add_shortcode( self::BADGE, array( $this, 'badge' ) );
		\add_shortcode( self::IMAGE_URL, array( $this, 'image_url' ) );
		\add_shortcode( self::IMAGE_WIDTH, array( $this, 'image_width' ) );
		\add_shortcode( self::IMAGE_HEIGHT, array( $this, 'image_height' ) );
		\add_shortcode( self::IMAGE_ALT, array( $this, 'image_alt' ) );
		\add_shortcode( self::IMAGE_SRCSET, array( $this, 'image_srcset' ) );
		\add_shortcode( self::CONTEXT, array( $this, 'context' ) );
		\add_shortcode( self::MEDIA_URL, array( $this, 'media_url' ) );
		\add_shortcode( self::MEDIA_WIDTH, array( $this, 'media_width' ) );
		\add_shortcode( self::MEDIA_HEIGHT, array( $this, 'media_height' ) );
		\add_shortcode( self::MEDIA_ALT, array( $this, 'media_alt' ) );
		\add_shortcode( self::MEDIA_DIMENSIONS, array( $this, 'media_dimensions' ) );
		\add_shortcode( self::MEDIA_ATTRIBUTES, array( $this, 'media_attributes' ) );
		\add_shortcode( self::ITEM_LINK, array( $this, 'item_link' ) );
		\add_shortcode( self::MEDIA_NAME, array( $this, 'media_name' ) );
		\add_shortcode( self::MEDIA_TEXT, array( $this, 'media_text' ) );
	}

	/**
	 * Pushes a scope frame; every `pop()` must match one.
	 *
	 * @param array<string, mixed> $frame The values this render supplies.
	 */
	public function push( array $frame ): void {
		$this->scope[] = $frame;
	}

	/**
	 * Pops the newest scope frame.
	 */
	public function pop(): void {
		array_pop( $this->scope );
	}

	/**
	 * A value from the current render's scope, '' when the key is absent or
	 * no render is in progress. The newest frame wins, so a fragment
	 * rendered from inside a partial sees its own values.
	 *
	 * @param string $key The scope key.
	 * @return string The value, '' for a non-scalar (use `list()` for those).
	 */
	public function value( string $key ): string {
		$found = $this->lookup( $key );
		return is_scalar( $found ) ? (string) $found : '';
	}

	/**
	 * A list from the current render's scope — the images a fragment renders
	 * one at a time, which `Partials::each()` walks.
	 *
	 * @param string $key The scope key.
	 * @return list<array<string, mixed>> The items, empty when the key holds no list.
	 */
	public function items( string $key ): array {
		$found = $this->lookup( $key );
		if ( ! is_array( $found ) ) {
			return array();
		}
		$items = array();
		foreach ( $found as $item ) {
			if ( is_array( $item ) ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * The newest frame's value for a key.
	 *
	 * @param string $key The scope key.
	 * @return mixed The raw value.
	 */
	private function lookup( string $key ): mixed {
		for ( $i = count( $this->scope ) - 1; $i >= 0; $i-- ) {
			if ( array_key_exists( $key, $this->scope[ $i ] ) ) {
				return $this->scope[ $i ][ $key ];
			}
		}
		return null;
	}

	/**
	 * The current pattern's id.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function id( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return (string) $this->current();
	}

	/**
	 * The current pattern's code.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function code( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( PatternFacts::code( $this->current() ) );
	}

	/**
	 * The current pattern's canonical URL.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function permalink( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_url( (string) \get_permalink( $this->current() ) );
	}

	/**
	 * The current pattern's family: its series term's name, the word the
	 * card's Family row carries.
	 *
	 * The tag is `md_pattern_family` (the row's own label) and the method is
	 * `series_name()`; the name is not `family()` because PHPCompatibility's
	 * removed-extension sniff reads any call whose name starts with `fam` as
	 * the long-deleted FAM extension, static calls included.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function series_name( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( PatternFacts::series_name( $this->current() ) );
	}

	/**
	 * The current pattern's series word.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function series( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( PatternFacts::series( $this->current() ) );
	}

	/**
	 * The current pattern's best-fit display word.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function best_fit( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( PatternFacts::best_fit( $this->current() ) );
	}

	/**
	 * The current pattern's open-area wording.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function open_area( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( PatternFacts::open_area( $this->current() ) );
	}

	/**
	 * The card's second badge.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function badge( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( PatternFacts::badge( $this->current() ) );
	}

	/**
	 * The featured image URL.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function image_url( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_url( PatternFacts::image( $this->current() )['url'] );
	}

	/**
	 * The featured image width.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function image_width( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return (string) PatternFacts::image( $this->current() )['width'];
	}

	/**
	 * The featured image height.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function image_height( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return (string) PatternFacts::image( $this->current() )['height'];
	}

	/**
	 * The featured image's alt text: the pattern code, which is what the
	 * card's image announces (the lightbox and the gallery carry their own).
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function image_alt( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( PatternFacts::code( $this->current() ) );
	}

	/**
	 * `[md_pattern_image_srcset sizes="…"]`: the `srcset`/`sizes` attribute
	 * pair, or nothing. The attributes are written here rather than in the
	 * partial because whether they exist is a fact about the file
	 * (`PatternFacts::srcset()`), and an empty `srcset=""` would make the
	 * browser fetch nothing.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes: sizes.
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function image_srcset( $atts = array(), ?string $content = '' ): string {
		unset( $content );
		$atts = \shortcode_atts( array( 'sizes' => '' ), (array) $atts, self::IMAGE_SRCSET );
		$pair = PatternFacts::srcset( $this->current(), (string) $atts['sizes'] );
		if ( '' === $pair['srcset'] ) {
			return '';
		}
		$attrs = ' srcset="' . \esc_attr( $pair['srcset'] ) . '"';
		if ( '' !== $pair['sizes'] ) {
			$attrs .= ' sizes="' . \esc_attr( $pair['sizes'] ) . '"';
		}
		return $attrs;
	}

	/**
	 * `[md_pattern_context name="eyebrow"]`: a value the call site of the
	 * current render supplied. Empty outside a render that set the key.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes: name (required).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function context( $atts = array(), ?string $content = '' ): string {
		unset( $content );
		$atts = \shortcode_atts( array( 'name' => '' ), (array) $atts, self::CONTEXT );
		$name = (string) $atts['name'];
		return '' === $name ? '' : \esc_html( $this->value( $name ) );
	}

	/**
	 * The current item's image URL.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function media_url( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_url( $this->value( 'url' ) );
	}

	/**
	 * The current item's image width.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function media_width( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return (string) (int) $this->value( 'width' );
	}

	/**
	 * The current item's image height.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function media_height( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return (string) (int) $this->value( 'height' );
	}

	/**
	 * The current item's alt text.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function media_alt( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( $this->value( 'alt' ) );
	}

	/**
	 * The current item's image dimensions as a `width`/`height` attribute
	 * pair, or nothing when the item declares none.
	 *
	 * A suffix like `[md_media_state]`'s rule: the attributes are emitted here
	 * rather than in the partial because whether they exist is a fact about
	 * the item, and `width="0" height="0"` describes no image.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function media_dimensions( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return $this->dimension_suffix();
	}

	/**
	 * The `width`/`height` suffix, or '' when the item declares no size.
	 */
	private function dimension_suffix(): string {
		$width  = (int) $this->value( 'width' );
		$height = (int) $this->value( 'height' );
		if ( $width <= 0 || $height <= 0 ) {
			return '';
		}
		return sprintf( ' width="%d" height="%d"', $width, $height );
	}

	/**
	 * The current item's optional image attributes, as one suffix: the
	 * loading tier, then the dimensions when the item declares them.
	 *
	 * **One shortcode, not two, and that is a WordPress constraint, not a
	 * preference.** Two *adjacent* shortcodes in an HTML tag —
	 * `alt="[a]"[b][c]` — make `wp_kses_attr_parse()` fail on the element,
	 * which makes `do_shortcodes_in_html_tags()` entity-encode every bracket
	 * in it: nothing expands and the visitor gets the raw tags. Measured on
	 * the dev instance, 2026-10-26; a single suffix, or two separated by
	 * whitespace, expand normally. Whitespace is not an option here because
	 * it would land in the markup as a stray space or newline and the output
	 * has to stay byte-identical to the element this replaced.
	 *
	 * The two facts stay distinct — the loading tier is always emitted (every
	 * image is eager or lazy) and the dimensions only when the attachment
	 * metadata carries them — so the composition is a concatenation of two
	 * independent answers, not a merged decision.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function media_attributes( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		$loading = 'eager' === $this->value( 'priority' )
			? ' loading="eager" fetchpriority="high"'
			: ' loading="lazy"';
		return $loading . $this->dimension_suffix();
	}

	/**
	 * The current item's own link.
	 *
	 * The cards this serves list *other* things than the post being rendered
	 * — the PDP's related patterns, the hub's series — so their href is the
	 * item's, not `[md_pattern_permalink]`'s (which is always the current
	 * post).
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function item_link( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_url( $this->value( 'link' ) );
	}

	/**
	 * The current item's display name: the card's heading.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function media_name( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( $this->value( 'name' ) );
	}

	/**
	 * The current item's body copy; the paragraph is rendered only when the
	 * item carries text, so a series without a description draws no empty
	 * line (the caller wraps it in `[md_if items=text]`).
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 */
	public function media_text( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return \esc_html( $this->value( 'text' ) );
	}


	/**
	 * The product the current render is for: the id the renderer put in the
	 * scope, else the loop's own post (a partial rendered straight from a
	 * LiveCanvas loop rather than through `Partials::render_post()`).
	 *
	 * Reading the scope first is what makes a render deterministic: the
	 * renderer states which product it is drawing instead of inferring it
	 * from a global another plugin can move between two cards.
	 */
	private function current(): int {
		$scoped = $this->value( 'post_id' );
		return '' === $scoped ? (int) \get_the_ID() : (int) $scoped;
	}
}
