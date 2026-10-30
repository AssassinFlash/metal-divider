/* md-inquiry's behavior file: the Turnstile wiring
 * and the two client-side validations. It carries no style and no markup of
 * its own — the classes it toggles (md-inquiry-*) are styled by the theme's
 * WindPress volume, and its configuration arrives in the app element's
 * data-md-inquiry attribute, because the site's CSP allows no inline script.
 *
 * One page carries several of these forms: the shared CTA band, the footer's
 * quote dialog on pages that have no form of their own, and a section that
 * asks for a quote in place. This file used to bind `document.querySelector`
 * — the first one only — so every other form on the page was inert: no
 * validation, no Turnstile status. Each app is now bound on
 * its own, and its lookups stay inside its own form.
 *
 * Turnstile is explicit and per form. api.js loads with render=explicit and
 * onload=mdInquiryTurnstileReady, so nothing renders until this file asks.
 * That function lives here, and this file is api.js's dependency, so it
 * exists before the deferred script calls it. Each form passes its own
 * callbacks and the widget id turnstile.reset needs. A page-level callback
 * used to disable every submit when the widget inside the closed quote
 * dialog failed. That dialog has no box until it is open, so its widget is
 * not rendered until the slot has layout and has entered the viewport. A
 * form already on the page renders when the API is ready, even below the fold.
 */
(function () {
	'use strict';

	var bound = [];

	// api.js?onload= names this. It has to exist before that script runs, and
	// it has to be a no-op until forms are bound. Each form renders itself.
	window.mdInquiryTurnstileReady = function () {
		bound.forEach(function (instance) {
			if (instance.mountTurnstile) {
				instance.mountTurnstile();
			}
		});
	};

	var apps = document.querySelectorAll('.md-inquiry-app');
	if (!apps.length) {
		return;
	}

	// Every element id in these forms is suffixed per instance, so a lookup by
	// id would need to know which instance it is. The field's `name` is stable
	// across instances and unique inside its own form, so lookups stay local.
	function fieldIn(form, name) {
		var el = form.querySelector('[name="' + name + '"]');
		if (el) {
			return el;
		}
		return form.querySelector('[id^="md-inquiry-' + name + '"]');
	}

	function readConfig(app) {
		try {
			return JSON.parse(app.getAttribute('data-md-inquiry') || '{}');
		} catch (error) {
			return {};
		}
	}

	function bindApp(app) {
		var cfg = readConfig(app);
		var form = app.querySelector('.md-inquiry-form');
		if (!form) {
			return null;
		}

		var submit = form.querySelector('.md-inquiry-submit');
		var statusEl = form.querySelector('.md-inquiry-turnstile-status');
		var container = form.querySelector('.md-inquiry-turnstile');

		function field(name) {
			return fieldIn(form, name);
		}

		function value(key) {
			var input = field(key);
			return input ? input.value.trim() : '';
		}

		function setStatus(message) {
			if (!statusEl) {
				return;
			}
			if (message) {
				statusEl.textContent = message;
				statusEl.removeAttribute('hidden');
			} else {
				statusEl.setAttribute('hidden', '');
				statusEl.textContent = '';
			}
		}

		function describedBy(control, errorId) {
			var wrapper = control.closest('.md-inquiry-field');
			var ids = [];
			var hint = wrapper ? wrapper.querySelector('.md-inquiry-field__hint') : null;
			if (hint && hint.id) {
				ids.push(hint.id);
			}
			if (errorId) {
				ids.push(errorId);
			}
			if (ids.length) {
				control.setAttribute('aria-describedby', ids.join(' '));
			} else {
				control.removeAttribute('aria-describedby');
			}
		}

		function fieldError(key, message) {
			var control = field(key);
			var wrapper = control ? control.closest('.md-inquiry-field') : null;
			if (!wrapper) {
				return;
			}
			if (!message) {
				wrapper.classList.remove('md-inquiry-field--invalid');
				var existing = wrapper.querySelector('.md-inquiry-field__error');
				if (existing) {
					existing.remove();
				}
				control.removeAttribute('aria-invalid');
				describedBy(control, '');
				return;
			}
			wrapper.classList.add('md-inquiry-field--invalid');
			var error = wrapper.querySelector('.md-inquiry-field__error');
			if (!error) {
				error = document.createElement('span');
				error.className = 'md-inquiry-field__error';
				error.id = control.id ? control.id + '-error' : 'md-inquiry-' + key + '-error';
				error.setAttribute('role', 'alert');
				wrapper.appendChild(error);
			}
			error.setAttribute('role', 'alert');
			error.textContent = message;
			control.setAttribute('aria-invalid', 'true');
			describedBy(control, error.id);
		}

		var requiredKeys = ['name', 'email', 'country', 'scope', 'route', 'quantity', 'message', 'confirm'];

		function messageFor(key) {
			var i18n = cfg.i18n || {};
			var map = {
				name: i18n.errorName || 'Please enter your name.',
				email: i18n.errorEmail || 'Please enter a valid email address.',
				country: i18n.errorCountry || 'Please choose your country.',
				scope: i18n.errorScope || 'Please choose the project scope.',
				route: i18n.errorRoute || 'Please choose an inquiry route.',
				quantity: i18n.errorQuantity || 'Please choose a quantity range.',
				message: i18n.errorMessage || 'Please add a short message.',
				confirm: i18n.errorConfirm || 'Please confirm this inquiry is for an architectural or commercial project.'
			};
			return map[key] || 'Please complete this field.';
		}

		function isMissing(key) {
			var control = field(key);
			if (!control) {
				return false;
			}
			if ('confirm' === key) {
				return !control.checked;
			}
			if ('email' === key) {
				return !emailLooksValid(value('email'));
			}
			if ('message' === key && !control.required) {
				return false;
			}
			return '' === value(key);
		}

		function setNotice(message) {
			var notice = form.querySelector('.md-inquiry-notice');
			if (!notice) {
				return;
			}
			if (message) {
				notice.textContent = message;
				notice.classList.add('md-inquiry-notice--error');
			} else {
				notice.textContent = '';
				notice.classList.remove('md-inquiry-notice--error');
			}
		}

		function emailLooksValid(email) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
		}

		function keyFrom(control) {
			var name = control.getAttribute('name') || '';
			return name.indexOf('md_inquiry_') === 0 ? name.slice('md_inquiry_'.length) : '';
		}

		function clearIfValid(event) {
			var key = keyFrom(event.target);
			if (key && requiredKeys.indexOf(key) !== -1 && !isMissing(key)) {
				fieldError(key, '');
			}
		}

		form.addEventListener('input', clearIfValid);
		form.addEventListener('change', clearIfValid);
		form.addEventListener('invalid', function (event) {
			var key = keyFrom(event.target);
			if (requiredKeys.indexOf(key) === -1) {
				return;
			}
			fieldError(key, messageFor(key));
			setNotice((cfg.i18n && cfg.i18n.errorSummary) || 'Please complete the highlighted fields.');
		}, true);

		form.addEventListener('submit', function (event) {
			var first = null;
			requiredKeys.forEach(function (key) {
				var bad = isMissing(key);
				fieldError(key, bad ? messageFor(key) : '');
				if (bad && !first) {
					first = field(key);
				}
			});
			if (first) {
				event.preventDefault();
				setNotice((cfg.i18n && cfg.i18n.errorSummary) || 'Please complete the highlighted fields.');
				first.focus();
			}
		});

		var widgetId = null;
		var rendering = false;
		var watching = false;
		var seenErrors = {};
		var unwatch = null;

		function lockSubmit() {
			if (submit && cfg.turnstile) {
				submit.disabled = true;
			}
		}

		function resetThis() {
			if (widgetId && window.turnstile && typeof window.turnstile.reset === 'function') {
				window.turnstile.reset(widgetId);
			}
		}

		function stopWatching() {
			if (!unwatch) {
				return;
			}
			var stop = unwatch;
			unwatch = null;
			stop();
		}

		function renderWidget() {
			if (widgetId || rendering || !container) {
				return;
			}
			if (!window.turnstile || typeof window.turnstile.render !== 'function') {
				return;
			}
			var sitekey = container.getAttribute('data-sitekey') || '';
			if (!sitekey) {
				return;
			}
			rendering = true;
			var id = window.turnstile.render(container, {
				sitekey: sitekey,
				// Stated, never inferred: without this Cloudflare follows the
				// browser's locale, which put a Chinese challenge inside an English
				// page on a Chinese-locale machine (measured on production
				// 2026-10-29). The markup carries the site's language.
				language: container.getAttribute('data-language') || 'en',
				appearance: container.getAttribute('data-appearance') || 'interaction-only',
				theme: container.getAttribute('data-theme') || 'auto',
				retry: container.getAttribute('data-retry') || 'auto',
				callback: function () {
					if (submit) {
						submit.disabled = false;
					}
					setStatus('');
				},
				'expired-callback': function () {
					lockSubmit();
					setStatus((cfg.i18n && cfg.i18n.turnstileExpired) || 'Verification expired. Please try again.');
					resetThis();
				},
				'error-callback': function (code) {
					lockSubmit();
					if (seenErrors[code]) {
						return;
					}
					seenErrors[code] = true;
					setStatus((cfg.i18n && cfg.i18n.turnstileError) || 'Verification could not run. Please refresh the page and try again.');
				},
				'timeout-callback': function () {
					lockSubmit();
					resetThis();
				}
			});
			rendering = false;
			if (!id) {
				return;
			}
			widgetId = id;
			stopWatching();
		}

		function watchHidden() {
			var io = null;
			var mo = null;
			var intersecting = false;
			var hadLayout = hasLayout(container);
			var hadCloak = cloaked(container);

			function tryRender() {
				if (widgetId) {
					stopWatching();
					return;
				}
				if (cloaked(container) || !hasLayout(container) || !intersecting) {
					return;
				}
				renderWidget();
			}

			function arm() {
				intersecting = false;
				if (io) {
					io.disconnect();
					io = null;
				}
				if (typeof IntersectionObserver !== 'function') {
					intersecting = geometryInViewport(container);
					tryRender();
					return;
				}
				io = new IntersectionObserver(function (entries) {
					var i;
					for (i = 0; i < entries.length; i++) {
						if (entries[i].target === container) {
							intersecting = !!entries[i].isIntersecting;
						}
					}
					tryRender();
				});
				io.observe(container);
			}

			function stop() {
				if (io) {
					io.disconnect();
					io = null;
				}
				if (mo) {
					mo.disconnect();
					mo = null;
				}
			}

			if (typeof MutationObserver === 'function') {
				mo = new MutationObserver(function () {
					var laid = hasLayout(container);
					var covered = cloaked(container);
					if ((laid && !hadLayout) || (hadCloak && !covered)) {
						arm();
					}
					hadLayout = laid;
					hadCloak = covered;
					tryRender();
				});
				var node = container;
				while (node && node !== document.documentElement) {
					mo.observe(node, {
						attributes: true,
						attributeFilter: ['style', 'class', 'hidden', 'open', 'x-cloak', 'aria-hidden']
					});
					node = node.parentElement;
				}
			}

			unwatch = stop;
			arm();
		}

		function mountTurnstile() {
			if (widgetId || watching || !container || !cfg.turnstile) {
				return;
			}
			if (!container.getAttribute('data-sitekey')) {
				return;
			}
			if (pageVisible(container)) {
				renderWidget();
				return;
			}
			watching = true;
			watchHidden();
		}

		return {
			cfg: cfg,
			form: form,
			submit: submit,
			setStatus: setStatus,
			mountTurnstile: mountTurnstile
		};
	}

	Array.prototype.forEach.call(apps, function (app) {
		var instance = bindApp(app);
		if (instance) {
			bound.push(instance);
		}
	});


	function hasLayout(el) {
		if (!el || typeof el.getClientRects !== 'function') {
			return false;
		}
		var rects = el.getClientRects();
		if (!rects || !rects.length) {
			return false;
		}
		var box = el.getBoundingClientRect();
		return box.width > 0 && box.height > 0;
	}

	// A closed quote dialog can still have a box for a frame: x-cloak hides it
	// only after md-js is on the root, and x-show has not run yet. Treat that
	// as hidden. A page form below the fold has a box and no cloak, so it is
	// not this case.
	function cloaked(el) {
		var node = el;
		while (node && node !== document.documentElement) {
			if (node.hasAttribute && (node.hasAttribute('hidden') || node.hasAttribute('x-cloak'))) {
				return true;
			}
			if (node.getAttribute && node.getAttribute('aria-hidden') === 'true') {
				return true;
			}
			node = node.parentElement;
		}
		return false;
	}

	function pageVisible(el) {
		return hasLayout(el) && !cloaked(el);
	}

	function geometryInViewport(el) {
		var box = el.getBoundingClientRect();
		var width = window.innerWidth || (document.documentElement && document.documentElement.clientWidth) || 0;
		var height = window.innerHeight || (document.documentElement && document.documentElement.clientHeight) || 0;
		return box.width > 0 && box.height > 0 && box.bottom > 0 && box.right > 0 && box.top < height && box.left < width;
	}

	if (window.turnstile && typeof window.turnstile.render === 'function') {
		window.mdInquiryTurnstileReady();
	}
})();
