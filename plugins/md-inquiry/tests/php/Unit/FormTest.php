<?php
/**
 * Tests for the inquiry form's output.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Form;
use MetalDivider\Inquiry\FormState;
use MetalDivider\Inquiry\Pattern;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * The form as the templates receive it: the pattern row, the fields with
 * their limits, the hidden plumbing, the Turnstile widget's presence and
 * absence — semantic markup with the md-inquiry-* class names, and zero
 * inline styles (AGENTS.md §5: any visual decision belongs to the WindPress
 * volume, never to this output).
 *
 * @covers \MetalDivider\Inquiry\Form
 */
final class FormTest extends TestCase {

	/**
	 * Script registrations captured while a test calls register_assets().
	 *
	 * @var array<int, array{0: string, 1: string, 2: mixed, 3: mixed}>
	 */
	private array $registered_scripts = array();

	/**
	 * Sets the shared mocks up: escaping and sanitization pass through, the
	 * pattern resolves to Apiary, the assets enqueue quietly.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->mock_passthroughs();

		$product            = new \stdClass();
		$product->ID        = 42;
		$product->post_type = 'product';
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( $product ) );
		WP_Mock::userFunction( 'get_the_title' )->andReturn( 'Apiary' );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( int $id, string $key ): string {
				return '_sku' === $key ? 'APIARY' : '';
			}
		);
		WP_Mock::userFunction( 'get_permalink' )->andReturn( 'https://metal-divider.com/pattern/apiary' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturn( '' );
		WP_Mock::userFunction( 'get_post_field' )->andReturn( 'apiary-architectural-metal-screen-pattern' );
		WP_Mock::userFunction( 'get_post' )->andReturn( null );
		// wp_nonce_field() emits BOTH name and id, and the id is what collided
		// when a page carried two forms. The mock used to emit only the name,
		// which is exactly why no test ever saw the duplicate.
		WP_Mock::userFunction( 'wp_nonce_field' )->andReturnUsing(
			static function ( string $action, string $name ): string {
				return '<input type="hidden" name="' . $name . '" id="' . $name . '" value="nonce-for-' . $action . '">';
			}
		);
		$this->registered_scripts = array();
		$scripts                  = &$this->registered_scripts;
		WP_Mock::userFunction( 'wp_register_script' )->andReturnUsing(
			static function ( string $handle, string $src, $deps = array(), $version = false ) use ( &$scripts ): void {
				$scripts[] = array( $handle, $src, $deps, $version );
			}
		);
		WP_Mock::userFunction( 'wp_enqueue_script' )->andReturn( null );
		WP_Mock::userFunction( 'plugin_dir_url' )->andReturn( 'https://metal-divider.com/wp-content/plugins/md-inquiry/' );
		// The Turnstile widget states its language instead of letting Cloudflare
		// infer one from the browser; the site's locale is where that comes from.
		WP_Mock::userFunction( 'get_locale' )->andReturn( 'en_US' );
	}

	/**
	 * The form with a pattern: the context row, the repopulatable fields with
	 * their limits, the hidden source triple and pattern slug, the nonce, the
	 * honeypot — and not one inline style.
	 */
	public function test_the_form_assembles_the_patterned_shape(): void {
		$html = $this->render( 'apiary-architectural-metal-screen-pattern', array() );

		$this->assertStringContainsString( 'class="md-inquiry-app" data-md-inquiry="', $html, 'the behavior file reads its configuration from the data attribute — the CSP allows no inline script' );
		$this->assertStringContainsString( 'class="md-inquiry-pattern__name">Apiary<', $html );
		$this->assertStringContainsString( 'class="md-inquiry-pattern__code">APIARY<', $html );
		$this->assertStringContainsString( 'This pattern is attached to your inquiry.', $html );
		$this->assertStringContainsString( '<form class="md-inquiry-form" method="post">', $html, 'no action attribute: the form posts to the page it sits on, the CSP\'s form-action honored' );
		// Asserted as three separate facts, not one adjacency: a real
		// wp_nonce_field() writes id between name and value, and an assertion
		// pinned to attribute order fails on markup that is correct.
		$this->assertStringContainsString( 'name="md_inquiry_nonce"', $html, 'the nonce travels as md_inquiry_nonce' );
		$this->assertStringContainsString( 'value="nonce-for-md_inquiry_submit"', $html, 'the nonce value is the action\'s nonce' );
		$this->assertMatchesRegularExpression(
			'/id="md_inquiry_nonce(-\d+)?"/',
			$html,
			'the nonce field carries an id, suffixed per instance when a page has more than one form'
		);
		$this->assertStringContainsString( 'name="md_inquiry_pattern_slug" value="apiary-architectural-metal-screen-pattern"', $html );
		$this->assertStringContainsString( 'id="md-inquiry-source-page" name="md_inquiry_source_page" value="pattern-pdp"', $html );
		$this->assertStringContainsString( 'id="md-inquiry-source-module" name="md_inquiry_source_module" value="pattern-actions"', $html );
		$this->assertStringContainsString( 'id="md-inquiry-source-button" name="md_inquiry_source_button" value="request_quote"', $html );
		$this->assertStringContainsString( 'name="md_inquiry_name"', $html );
		$this->assertStringContainsString( 'maxlength="120"', $html );
		$this->assertStringContainsString( 'name="md_inquiry_phone"', $html );
		$this->assertStringContainsString( 'maxlength="40"', $html, 'phone is capped at PHONE_MAX, not NAME_MAX' );
		$this->assertStringContainsString( 'name="md_inquiry_confirm"', $html );
		$this->assertStringContainsString( 'I confirm this inquiry is for an architectural or commercial project.', $html );
		$this->assertStringContainsString( '<div class="md-inquiry-notice" role="alert" aria-live="polite"></div>', $html );
		$this->assertStringContainsString( 'id="md-inquiry-message-hint"', $html );
		$this->assertStringContainsString( 'aria-describedby="md-inquiry-message-hint"', $html );
		$this->assertStringContainsString( 'name="website"', $html, 'the honeypot' );
		$this->assertStringContainsString( 'class="md-inquiry-submit"', $html );
		$this->assertStringContainsString( 'class="md-inquiry-fields"', $html, 'fields scroll independently of the sticky actions' );
		// Owner ruling 2026-10-30: the WhatsApp channel is gone site-wide. The
		// form offers one path — submit — and no second, off-site one.
		$this->assertStringNotContainsString( 'wa.me', $html, 'no WhatsApp bypass (owner ruling 2026-10-30)' );
		$this->assertStringNotContainsString( 'md-inquiry-whatsapp', $html, 'no WhatsApp bypass (owner ruling 2026-10-30)' );
		$this->assertStringNotContainsString( 'WhatsApp', $html, 'no WhatsApp copy (owner ruling 2026-10-30)' );
		$this->assertStringNotContainsString( ' style=', $html, 'zero inline styles, always' );
		$this->assertStringNotContainsString( '<script', $html, 'no inline script: the CSP closes that door' );

		// The reply promise. Every old page told the visitor when to expect an
		// answer; the rebuilt form told them nothing, so the highest-intent
		// moment on the site — a filled-in brief — ended without a commitment.
		$this->assertStringContainsString( 'class="md-inquiry-promise"', $html, 'the form states its response window' );
		$this->assertStringContainsString( 'We reply within 24 hours on business days.', $html );
		$this->assertStringContainsString( 'Your details are used only to answer this inquiry', $html, 'and says what it does with them' );

		// Six pages ask the visitor to send a drawing by name. The form has no
		// file input and the plugin has no upload handler, so the only honest
		// instruction is that a drawing reaches the engineer by reply.
		$this->assertStringContainsString( 'Send it in reply to the confirmation email', $html, 'the form says how a drawing reaches us' );
		$this->assertStringNotContainsString( 'type="file"', $html, 'and does not pretend to take one' );
	}

	/**
	 * Several forms on one page never share an element id.
	 *
	 * A page renders the shared CTA band, the footer dialog and a section that
	 * asks in place. Before the suffix, thirteen ids repeated per instance and
	 * every `<label for>` on the page pointed at the first form's input.
	 */
	public function test_two_renders_on_one_page_never_share_an_id(): void {
		$this->render( 'apiary-architectural-metal-screen-pattern', array() );
		$first  = $this->render( 'apiary-architectural-metal-screen-pattern', array() );
		$second = $this->render( 'apiary-architectural-metal-screen-pattern', array() );

		preg_match_all( '/\sid="([^"]+)"/', $first, $a );
		preg_match_all( '/\sid="([^"]+)"/', $second, $b );

		$this->assertNotEmpty( $a[1], 'the form prints ids' );
		$this->assertNotEmpty( $b[1], 'and so does the next one' );
		$this->assertSame(
			array(),
			array_values( array_intersect( $a[1], $b[1] ) ),
			'two renders on one page never repeat an id'
		);
	}

	/**
	 * With Turnstile configured the slot is an explicit mount point, not a
	 * cf-turnstile element, and the submit button starts disabled. The
	 * behavior file's own callback is what unlocks it.
	 */
	public function test_turnstile_configured_renders_the_widget_and_a_locked_submit(): void {
		$config = new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => 'the-sitekey',
				'MD_INQUIRY_TURNSTILE_SECRET'  => 'the-secret',
			)
		);
		$html   = $this->render( 'apiary-architectural-metal-screen-pattern', array(), $config );

		// Asserted as the facts the widget needs, not as one attribute run: an
		// assertion pinned to attribute order fails on markup that is correct,
		// and this one did when data-language joined the list.
		$this->assertStringContainsString( 'class="md-inquiry-turnstile"', $html );
		$this->assertStringContainsString( 'data-sitekey="the-sitekey"', $html );
		$this->assertStringContainsString( 'data-appearance="interaction-only"', $html );
		$this->assertStringContainsString( 'data-theme="auto"', $html );
		$this->assertStringContainsString( 'data-retry="auto"', $html );
		// The widget's language is stated, not inferred from the browser: without
		// it Cloudflare served a Chinese challenge inside an English page.
		$this->assertMatchesRegularExpression(
			'/data-language="[a-z]{2}"/',
			$html,
			'the Turnstile widget is told which language to speak'
		);
		$this->assertStringNotContainsString( 'cf-turnstile', $html, 'implicit rendering would also challenge the hidden quote dialog' );
		$this->assertStringNotContainsString( 'data-callback=', $html, 'a data-callback name is one function for every form' );
		$this->assertStringNotContainsString( 'mdInquiryTurnstileCallback', $html );
		$this->assertStringContainsString( 'class="md-inquiry-submit" disabled', $html );
	}

	/**
	 * Without Turnstile keys — the early deployment, the local and staging
	 * instances — no widget and no lock: the form submits, and the record
	 * carries the mark instead.
	 */
	public function test_turnstile_unconfigured_renders_no_widget_and_an_open_submit(): void {
		$html = $this->render( 'apiary-architectural-metal-screen-pattern', array() );

		// Positives first: an empty render would satisfy both negatives below
		// while shipping no form at all, which is the state this test is meant
		// to rule out.
		$this->assertStringContainsString( '<form class="md-inquiry-form"', $html, 'the form is there' );
		$this->assertStringContainsString( 'class="md-inquiry-submit"', $html, 'the submit button is there' );
		$this->assertStringNotContainsString( 'cf-turnstile', $html );
		$this->assertStringNotContainsString( 'md-inquiry-turnstile', $html, 'an unconfigured form does not ship an empty widget slot' );
		$this->assertDoesNotMatchRegularExpression(
			'/class="md-inquiry-submit"[^>]*\sdisabled/',
			$html,
			'the submit is not locked when no challenge is configured'
		);
	}

	/**
	 * A failed submission re-renders with its values and its notice: the
	 * customer's typed words survive the retry.
	 */
	public function test_a_failed_submission_repopulates_and_notifies(): void {
		$state = new FormState();
		$state->fail(
			array(
				'name'    => 'Jane Chan',
				'email'   => 'jane@studio.com',
				'message' => '40 panels',
			),
			array( 'email' => 'Please enter a valid email address.' ),
			'validation'
		);
		$html = $this->render( 'apiary-architectural-metal-screen-pattern', array(), null, $state );

		$this->assertStringContainsString( 'class="md-inquiry-notice md-inquiry-notice--error" role="alert"', $html );
		$this->assertStringContainsString( 'Please complete the highlighted fields.', $html );
		$this->assertStringContainsString( 'value="Jane Chan"', $html );
		$this->assertStringContainsString( 'md-inquiry-field--invalid', $html );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html );
	}

	/**
	 * The explicit pattern attribute decides the context — the template face's
	 * way of placing the form with a named pattern.
	 */
	public function test_the_pattern_attribute_decides_the_context(): void {
		$html = $this->render( 'apiary', array() );

		$this->assertStringContainsString( 'class="md-inquiry-pattern__name">Apiary<', $html );
		$this->assertStringContainsString( 'name="md_inquiry_pattern_slug" value="apiary"', $html );
		$this->assertStringContainsString( '"module":"pattern-actions"', $html, 'the config JSON carries the source for the behavior file' );
	}


	/**
	 * Without a pattern the form must not claim one is attached, and the
	 * inquiry page's source triple is the contact hub, not a pattern action.
	 */
	public function test_a_form_without_a_pattern_does_not_claim_one(): void {
		$config = new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
				'MD_INQUIRY_TURNSTILE_SECRET'  => '',
			)
		);
		$html   = ( new Form( $config, new Pattern(), new FormState() ) )->render( '', 'inquiry', 'contact-hub', 'send-inquiry' );

		$this->assertStringNotContainsString( 'this pattern', $html );
		$this->assertStringNotContainsString( 'md-inquiry-pattern', $html );
		$this->assertStringContainsString( 'value="inquiry"', $html );
		$this->assertStringContainsString( 'value="contact-hub"', $html );
		$this->assertStringContainsString( 'value="send-inquiry"', $html );
	}

	/**
	 * The Turnstile api.js is explicit, and its onload name is the behavior file's function.
	 *
	 * The dependency is this plugin's script, printed first; defer keeps
	 * api.js from calling that function before the file has defined it.
	 */
	public function test_turnstile_script_is_explicit_and_deferred(): void {
		$config = new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => 'the-sitekey',
				'MD_INQUIRY_TURNSTILE_SECRET'  => 'the-secret',
			)
		);
		$form   = new Form( $config, new Pattern(), new FormState() );
		$form->register_assets();

		$src              = '';
		$deps             = array();
		$behavior_version = '';
		$api_version      = false;
		foreach ( $this->registered_scripts as $call ) {
			if ( Form::TURNSTILE_SCRIPT === $call[0] ) {
				$src         = $call[1];
				$deps        = $call[2];
				$api_version = $call[3];
			}
			if ( Form::SCRIPT === $call[0] ) {
				$behavior_version = (string) $call[3];
			}
		}

		$this->assertNotSame( '', $src, 'the Turnstile script was not registered' );
		$this->assertStringContainsString( 'render=explicit', $src );
		$this->assertStringContainsString( 'onload=' . Form::TURNSTILE_ONLOAD, $src );
		$this->assertStringNotContainsString( 'cf-turnstile', $src );
		$this->assertContains( Form::SCRIPT, $deps );
		$this->assertNull( $api_version, 'Cloudflare must not receive WordPress\'s unrelated ver parameter' );
		$expected = \MetalDivider\Inquiry\Plugin::VERSION . '.' . substr( (string) md5_file( dirname( __DIR__, 3 ) . '/assets/inquiry.js' ), 0, 10 );
		$this->assertSame( $expected, $behavior_version, 'the behavior URL changes whenever its bytes change' );

		$tag      = '<script src="' . $src . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- a tag passed to defer_turnstile(), not a script this test enqueues.
		$deferred = $form->defer_turnstile( $tag, Form::TURNSTILE_SCRIPT, $src );
		$this->assertStringContainsString( 'defer src', $deferred );
		$this->assertSame( $tag, $form->defer_turnstile( $tag, Form::SCRIPT, $src ) );

		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/inquiry.js' );
		$this->assertStringContainsString( 'window.' . Form::TURNSTILE_ONLOAD, $js );
		$this->assertStringNotContainsString( 'window.mdInquiryTurnstileCallback', $js );
		$this->assertDoesNotMatchRegularExpression( '/turnstile\\.reset\\(\\s*\\)/', $js );
	}

	/**
	 * Each form renders its own widget. A cloaked dialog slot does not, until
	 * it has a box and intersects the viewport; one form's pass, error, expiry
	 * or timeout does not touch the other form's submit.
	 *
	 * This executes inquiry.js. The same program fails on the previous file,
	 * which never defined mdInquiryTurnstileReady and reset every widget at once.
	 */
	public function test_each_form_renders_its_own_turnstile_and_a_hidden_dialog_waits(): void {
		$js = dirname( __DIR__, 3 ) . '/assets/inquiry.js';
		$this->assertFileExists( $js );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- inquiry.js only runs in a browser; node is how this test can fail the viewport gate.
		$process = proc_open( array( 'node', '-', $js ), $descriptors, $pipes );
		$this->assertIsResource( $process );

		fwrite( $pipes[0], $this->turnstile_behavior_program() );
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );

		$this->assertSame( 0, $code, $stderr . $stdout );
		$this->assertStringContainsString( 'ok renders=2', (string) $stdout );
	}

	/**
	 * Renders the form: directly with the given pattern slug, or through the
	 * shortcode when the test says so.
	 *
	 * @param string                $pattern_slug The pattern to attach, or '' for the current post's.
	 * @param array<string, string> $atts         The shortcode attributes, when the path is the shortcode.
	 * @param Config|null           $config       The configuration, or the unconfigured default.
	 * @param FormState|null        $state        The form state, or a fresh one.
	 * @return string
	 */
	private function render( string $pattern_slug, array $atts, ?Config $config = null, ?FormState $state = null ): string {
		$config = $config ?? new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
				'MD_INQUIRY_TURNSTILE_SECRET'  => '',
			)
		);
		$state  = $state ?? new FormState();
		$form   = new Form( $config, new Pattern(), $state );

		if ( array() !== $atts ) {
			WP_Mock::userFunction( 'shortcode_atts' )->once()->andReturnUsing(
				static function ( array $defaults, array $given ): array {
					return \array_merge( $defaults, $given );
				}
			);
			return $form->render_shortcode( $atts );
		}
		return $form->render( $pattern_slug, '', '', '' );
	}
	/**
	 * The node program that fails unless each form owns its Turnstile widget.
	 *
	 * @return string
	 */
	private function turnstile_behavior_program(): string {
		return <<<'TURNSTILE_BEHAVIOR'
'use strict';

var fs = require('fs');
var vm = require('vm');

var jsPath = process.argv[2];
if (!jsPath) {
	console.error('missing inquiry.js path');
	process.exit(2);
}

function fail(message) {
	console.error('FAIL: ' + message);
	process.exit(1);
}

function El(tag) {
	this.tag = tag;
	this.attrs = {};
	this.children = [];
	this.parentElement = null;
	this.textContent = '';
	this.value = '';
	this.checked = false;
	this.required = false;
	this.id = '';
	this.disabled = false;
	this.rect = null;
	this.classList = {
		add: function () {},
		remove: function () {}
	};
}
El.prototype.getAttribute = function (name) {
	return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
};
El.prototype.setAttribute = function (name, value) {
	this.attrs[name] = String(value);
	if (name === 'id') {
		this.id = String(value);
	}
};
El.prototype.removeAttribute = function (name) {
	delete this.attrs[name];
};
El.prototype.hasAttribute = function (name) {
	return Object.prototype.hasOwnProperty.call(this.attrs, name);
};
El.prototype.addEventListener = function () {};
El.prototype.appendChild = function (child) {
	child.parentElement = this;
	this.children.push(child);
	return child;
};
El.prototype.getClientRects = function () {
	return this.rect ? [this.rect] : [];
};
El.prototype.getBoundingClientRect = function () {
	return this.rect || { top: 0, left: 0, bottom: 0, right: 0, width: 0, height: 0 };
};
El.prototype.focus = function () {};
El.prototype.closest = function (selector) {
	var node = this;
	while (node) {
		if (matches(node, selector)) {
			return node;
		}
		node = node.parentElement;
	}
	return null;
};
El.prototype.querySelector = function (selector) {
	var found = this.querySelectorAll(selector);
	return found.length ? found[0] : null;
};
El.prototype.querySelectorAll = function (selector) {
	var out = [];
	walk(this, function (node) {
		if (node !== this && matches(node, selector)) {
			out.push(node);
		}
	}.bind(this));
	return out;
};

function walk(node, fn) {
	fn(node);
	node.children.forEach(function (child) {
		walk(child, fn);
	});
}

function matches(node, selector) {
	if (!node || !node.attrs) {
		return false;
	}
	if (selector.charAt(0) === '.') {
		var className = node.attrs.class || '';
		return (' ' + className + ' ').indexOf(' ' + selector.slice(1) + ' ') !== -1;
	}
	var nameMatch = selector.match(/^\[name="([^"]+)"\]$/);
	if (nameMatch) {
		return node.attrs.name === nameMatch[1];
	}
	var idPrefix = selector.match(/^\[id\^="([^"]+)"\]$/);
	if (idPrefix) {
		return typeof node.attrs.id === 'string' && node.attrs.id.indexOf(idPrefix[1]) === 0;
	}
	return false;
}

function el(tag, className) {
	var node = new El(tag);
	if (className) {
		node.setAttribute('class', className);
	}
	return node;
}

var root = el('html');
var body = el('body');
root.appendChild(body);

function app(sitekey) {
	var appEl = el('div', 'md-inquiry-app');
	appEl.setAttribute('data-md-inquiry', JSON.stringify({
		turnstile: true,
		i18n: {
			turnstileExpired: 'expired-for-this-form',
			turnstileError: 'error-for-this-form'
		}
	}));
	var form = el('form', 'md-inquiry-form');
	var submit = el('button', 'md-inquiry-submit');
	submit.disabled = true;
	var status = el('p', 'md-inquiry-turnstile-status');
	status.setAttribute('hidden', '');
	var slot = el('div', 'md-inquiry-turnstile');
	slot.setAttribute('data-sitekey', sitekey);
	slot.setAttribute('data-appearance', 'interaction-only');
	slot.setAttribute('data-theme', 'auto');
	slot.setAttribute('data-retry', 'auto');
	form.appendChild(submit);
	form.appendChild(status);
	form.appendChild(slot);
	appEl.appendChild(form);
	return { app: appEl, form: form, submit: submit, status: status, slot: slot };
}

var visible = app('visible-key');
visible.slot.rect = { top: 4000, left: 0, width: 300, height: 65, bottom: 4065, right: 300 };
body.appendChild(visible.app);

var modal = el('div');
modal.setAttribute('x-cloak', '');
var hidden = app('hidden-key');
hidden.slot.rect = { top: 120, left: 16, width: 280, height: 65, bottom: 185, right: 296 };
modal.appendChild(hidden.app);
body.appendChild(modal);

var renders = [];
var resets = [];
var nextId = 0;
var ios = [];
var mos = [];

function IntersectionObserver(callback) {
	this.callback = callback;
	this.el = null;
	this.dead = false;
	ios.push(this);
}
IntersectionObserver.prototype.observe = function (element) {
	this.el = element;
};
IntersectionObserver.prototype.disconnect = function () {
	this.dead = true;
};

function MutationObserver(callback) {
	this.callback = callback;
	this.dead = false;
	mos.push(this);
}
MutationObserver.prototype.observe = function () {};
MutationObserver.prototype.disconnect = function () {
	this.dead = true;
};

function liveIOs(element) {
	return ios.filter(function (io) {
		return !io.dead && io.el === element;
	});
}

function fireIO(element, intersecting) {
	liveIOs(element).forEach(function (io) {
		io.callback([{ target: element, isIntersecting: intersecting }]);
	});
}

function fireMutations() {
	mos.slice().forEach(function (mo) {
		if (!mo.dead) {
			mo.callback([]);
		}
	});
}

global.window = global;
global.document = {
	documentElement: root,
	querySelectorAll: function (selector) {
		return root.querySelectorAll(selector);
	},
	createElement: function (tag) {
		return el(tag);
	}
};
global.IntersectionObserver = IntersectionObserver;
global.MutationObserver = MutationObserver;
global.innerWidth = 800;
global.innerHeight = 800;
root.clientWidth = 800;
root.clientHeight = 800;
global.turnstile = {
	render: function (element, options) {
		nextId += 1;
		var id = 'widget-' + nextId;
		renders.push({ id: id, element: element, options: options });
		return id;
	},
	reset: function () {
		resets.push(Array.prototype.slice.call(arguments));
	}
};

vm.runInThisContext(fs.readFileSync(jsPath, 'utf8'), { filename: jsPath });

if (typeof global.mdInquiryTurnstileReady !== 'function') {
	fail('mdInquiryTurnstileReady was not defined; api.js onload has nothing to call');
}
global.mdInquiryTurnstileReady();

if (typeof global.mdInquiryTurnstileCallback === 'function' || typeof global.mdInquiryTurnstileError === 'function') {
	fail('page-level Turnstile callbacks are still installed');
}

if (renders.length !== 1 || renders[0].element !== visible.slot) {
	fail('a laid-out page form should render on ready, and a cloaked dialog slot should not; got ' + renders.length);
}
if (renders[0].options.sitekey !== 'visible-key' || renders[0].options.appearance !== 'interaction-only') {
	fail('explicit render did not receive this form\'s sitekey and appearance');
}

renders[0].options.callback('token-a');
if (visible.submit.disabled !== false || hidden.submit.disabled !== true) {
	fail('a pass on one form enabled or left the other form\'s submit');
}

fireIO(hidden.slot, true);
if (renders.length !== 1) {
	fail('an intersecting cloaked slot was rendered before the dialog was open');
}

hidden.slot.rect = null;
fireIO(hidden.slot, true);
if (renders.length !== 1) {
	fail('a zero-size slot was rendered');
}

hidden.slot.rect = { top: 120, left: 16, width: 280, height: 65, bottom: 185, right: 296 };
fireIO(hidden.slot, false);
modal.removeAttribute('x-cloak');
fireMutations();
if (renders.length !== 1) {
	fail('a laid-out dialog slot rendered before it entered the viewport');
}

fireIO(hidden.slot, true);
if (renders.length !== 2 || renders[1].element !== hidden.slot) {
	fail('the dialog slot should render once it has layout and intersects the viewport; got ' + renders.length);
}

renders[1].options.callback('token-b');
if (hidden.submit.disabled !== false || visible.submit.disabled !== false) {
	fail('the dialog pass changed the page form\'s submit');
}

var resetsBefore = resets.length;
renders[0].options['expired-callback']();
if (visible.submit.disabled !== true || hidden.submit.disabled !== false) {
	fail('expiry locked a form that did not expire');
}
if (resets.length !== resetsBefore + 1 || resets[resets.length - 1].length !== 1 || resets[resets.length - 1][0] !== renders[0].id) {
	fail('expiry must reset only that form\'s widget id, got ' + JSON.stringify(resets));
}

renders[0].options.callback('token-a-again');
renders[1].options['error-callback']('110200');
if (hidden.submit.disabled !== true || visible.submit.disabled !== false) {
	fail('one form\'s error locked the other form');
}
if (hidden.status.textContent !== 'error-for-this-form') {
	fail('the error status was not written on the form that failed');
}
if (resets.length !== resetsBefore + 1) {
	fail('an error reset a widget; only expiry and timeout should');
}

renders[1].options['timeout-callback']();
if (resets[resets.length - 1][0] !== renders[1].id || visible.submit.disabled !== false) {
	fail('timeout reset the wrong widget or locked the other form');
}

resets.forEach(function (args) {
	if (args.length !== 1) {
		fail('turnstile.reset was called without a widget id: ' + JSON.stringify(resets));
	}
});

console.log('ok renders=' + renders.length + ' resets=' + JSON.stringify(resets));
TURNSTILE_BEHAVIOR;
	}
	/**
	 * Two forms on one page must not share an element id.
	 *
	 * The production home page carries two (the closing card and the footer's
	 * quote dialog) and, before this guard, both nonce fields were
	 * `id="md_inquiry_nonce"` — 91 ids on the page with exactly one duplicated.
	 * Duplicate ids are invalid HTML, and getElementById plus any `for=` label
	 * resolve to whichever form rendered first, so the second form's nonce
	 * became unreachable to anything that addresses it by id.
	 *
	 * The mock in setUp omits the id attribute entirely, so this test supplies
	 * its own wp_nonce_field that emits the id WordPress really emits.
	 */
	public function test_two_forms_on_a_page_do_not_share_an_element_id(): void {
		$first  = $this->render( '', array() );
		$second = $this->render( '', array() );

		$ids = static function ( string $html ): array {
			preg_match_all( '/\bid="([^"]+)"/', $html, $matches );
			return $matches[1];
		};

		$first_ids  = $ids( $first );
		$second_ids = $ids( $second );

		// The render counter is static and is not reset between tests, so this
		// does not assert which suffix a given call gets — only that the two
		// calls differ, which is the property that matters.
		$this->assertNotSame( $first_ids, $second_ids, 'two renders produce different ids' );
		$this->assertNotEmpty(
			array_filter( $first_ids, static fn ( string $id ): bool => 0 === strpos( $id, 'md_inquiry_nonce' ) ),
			'the nonce field carries an id at all'
		);
		$this->assertNotEmpty(
			array_filter( $second_ids, static fn ( string $id ): bool => 0 === strpos( $id, 'md_inquiry_nonce' ) ),
			'the second form\'s nonce field carries an id too'
		);

		$shared = array_intersect( $first_ids, $second_ids );
		$this->assertSame(
			array(),
			array_values( $shared ),
			'two forms on one page share element ids: ' . implode( ', ', $shared )
		);
	}
}
