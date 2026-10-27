/* md-inquiry's behavior file: the Turnstile wiring, the live WhatsApp link,
 * and the two client-side validations. It carries no style and no markup of
 * its own — the classes it toggles (md-inquiry-*) are styled by the theme's
 * WindPress volume, and its configuration arrives in the app element's
 * data-md-inquiry attribute, because the site's CSP allows no inline script.
 *
 * One page carries several of these forms: the shared CTA band, the footer's
 * quote dialog on pages that have no form of their own, and a section that
 * asks for a quote in place. This file used to bind `document.querySelector`
 * — the first one only — so every other form on the page was inert: no
 * validation, no WhatsApp text, no Turnstile status. Each app is now bound on
 * its own, and its lookups stay inside its own form.
 *
 * The Turnstile callbacks must exist on window before Turnstile's api.js
 * loads: the script is registered with this file as its dependency, so the
 * order holds. They are page-level, because the challenge is page-level:
 * every bound form is enabled when it passes.
 */
(function () {
	'use strict';

	var apps = document.querySelectorAll('.md-inquiry-app');
	if (!apps.length) {
		return;
	}

	var bound = [];

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
		var waLink = form.querySelector('.md-inquiry-whatsapp');
		var statusEl = form.querySelector('.md-inquiry-turnstile-status');

		function field(name) {
			return fieldIn(form, name);
		}

		function source(part) {
			var input = form.querySelector('[name="md_inquiry_source_' + part + '"]');
			return input ? input.value : '';
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
				control.removeAttribute('aria-describedby');
				return;
			}
			wrapper.classList.add('md-inquiry-field--invalid');
			var error = wrapper.querySelector('.md-inquiry-field__error');
			if (!error) {
				error = document.createElement('span');
				error.className = 'md-inquiry-field__error';
				error.id = control.id ? control.id + '-error' : 'md-inquiry-' + key + '-error';
				wrapper.appendChild(error);
			}
			error.textContent = message;
			control.setAttribute('aria-invalid', 'true');
			control.setAttribute('aria-describedby', error.id);
		}

		function emailLooksValid(email) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
		}

		function waText() {
			var name = value('name');
			var company = value('company');
			var message = value('message');
			var lines = [];
			if (name) {
				lines.push("Hi, I'm " + name + (company ? ' (' + company + ')' : '') + '.');
			}
			if (cfg.pattern) {
				lines.push("I'd like a quote for this pattern:");
				lines.push(cfg.pattern.name + ' (' + cfg.pattern.code + ')');
			}
			if (message) {
				lines.push(message);
			}
			lines.push('Ref: ' + source('page') + '/' + source('module') + '/' + source('button'));
			return lines.join('\n');
		}

		function updateWhatsApp() {
			if (!waLink || !cfg.waNumber) {
				return;
			}
			waLink.href = 'https://wa.me/' + cfg.waNumber + '?text=' + encodeURIComponent(waText());
		}

		['name', 'company', 'message'].forEach(function (key) {
			var input = field(key);
			if (input) {
				input.addEventListener('input', updateWhatsApp);
			}
		});

		form.addEventListener('submit', function (event) {
			var badName = '' === value('name');
			var badEmail = !emailLooksValid(value('email'));
			fieldError('name', badName ? ((cfg.i18n && cfg.i18n.errorName) || 'Please enter your name.') : '');
			fieldError('email', badEmail ? ((cfg.i18n && cfg.i18n.errorEmail) || 'Please enter a valid email address.') : '');
			if (badName || badEmail) {
				event.preventDefault();
				var first = form.querySelector('.md-inquiry-field--invalid .md-inquiry-field__input');
				if (first) {
					first.focus();
				}
			}
		});

		updateWhatsApp();

		return {
			cfg: cfg,
			form: form,
			submit: submit,
			setStatus: setStatus
		};
	}

	Array.prototype.forEach.call(apps, function (app) {
		var instance = bindApp(app);
		if (instance) {
			bound.push(instance);
		}
	});

	if (!bound.length) {
		return;
	}

	/* ---------- Turnstile ---------- */

	// The challenge is one per page, so its outcome is page-level: a pass
	// releases every bound form.
	function eachBound(fn) {
		bound.forEach(fn);
	}

	function enableSubmit() {
		eachBound(function (b) {
			if (b.submit) {
				b.submit.disabled = false;
			}
		});
	}

	function disableSubmit() {
		eachBound(function (b) {
			if (b.submit && b.cfg.turnstile) {
				b.submit.disabled = true;
			}
		});
	}

	function resetWidget() {
		if (window.turnstile && typeof window.turnstile.reset === 'function') {
			window.turnstile.reset();
		}
	}

	var seenTurnstileErrors = {};

	window.mdInquiryTurnstileCallback = function () {
		enableSubmit();
		eachBound(function (b) {
			b.setStatus('');
		});
	};

	window.mdInquiryTurnstileExpired = function () {
		disableSubmit();
		eachBound(function (b) {
			b.setStatus((b.cfg.i18n && b.cfg.i18n.turnstileExpired) || 'Verification expired. Please try again.');
		});
		resetWidget();
	};

	window.mdInquiryTurnstileError = function (code) {
		disableSubmit();
		/* Cloudflare's own warning: the same error may report itself more than
		 * once — the message shows for its first report only. */
		if (seenTurnstileErrors[code]) {
			return;
		}
		seenTurnstileErrors[code] = true;
		eachBound(function (b) {
			b.setStatus((b.cfg.i18n && b.cfg.i18n.turnstileError) || 'Verification could not run. Please refresh the page and try again.');
		});
	};

	window.mdInquiryTurnstileTimeout = function () {
		disableSubmit();
		resetWidget();
	};
})();
