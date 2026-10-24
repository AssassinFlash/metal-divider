/* md-inquiry's behavior file: the Turnstile wiring, the live WhatsApp link,
 * and the two client-side validations. It carries no style and no markup of
 * its own — the classes it toggles (md-inquiry-*) are styled by the theme's
 * WindPress volume, and its configuration arrives in the app element's
 * data-md-inquiry attribute, because the site's CSP allows no inline script.
 *
 * The Turnstile callbacks must exist on window before Turnstile's api.js
 * loads: the script is registered with this file as its dependency, so the
 * order holds.
 */
(function () {
	'use strict';

	var app = document.querySelector('.md-inquiry-app');
	if (!app) {
		return;
	}

	var cfg;
	try {
		cfg = JSON.parse(app.getAttribute('data-md-inquiry') || '{}');
	} catch (error) {
		cfg = {};
	}
	var form = app.querySelector('.md-inquiry-form');
	if (!form) {
		return;
	}

	var submit = form.querySelector('.md-inquiry-submit');
	var waLink = form.querySelector('.md-inquiry-whatsapp');
	var statusEl = form.querySelector('.md-inquiry-turnstile-status');
	var seenTurnstileErrors = {};

	function source(part) {
		var input = form.querySelector('#md-inquiry-source-' + part);
		return input ? input.value : '';
	}

	function value(key) {
		var input = form.querySelector('#md-inquiry-' + key);
		return input ? input.value.trim() : '';
	}

	/* ---------- Turnstile ---------- */

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

	function enableSubmit() {
		if (submit) {
			submit.disabled = false;
		}
	}

	function disableSubmit() {
		if (submit && cfg.turnstile) {
			submit.disabled = true;
		}
	}

	function resetWidget() {
		if (window.turnstile && typeof window.turnstile.reset === 'function') {
			window.turnstile.reset();
		}
	}

	window.mdInquiryTurnstileCallback = function () {
		enableSubmit();
		setStatus('');
	};

	window.mdInquiryTurnstileExpired = function () {
		disableSubmit();
		setStatus((cfg.i18n && cfg.i18n.turnstileExpired) || 'Verification expired. Please try again.');
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
		setStatus((cfg.i18n && cfg.i18n.turnstileError) || 'Verification could not run. Please refresh the page and try again.');
	};

	window.mdInquiryTurnstileTimeout = function () {
		disableSubmit();
		resetWidget();
	};

	/* ---------- Field validation ---------- */

	function fieldError(key, message) {
		var field = form.querySelector('#md-inquiry-' + key);
		var wrapper = field ? field.closest('.md-inquiry-field') : null;
		if (!wrapper) {
			return;
		}
		if (!message) {
			wrapper.classList.remove('md-inquiry-field--invalid');
			var existing = wrapper.querySelector('.md-inquiry-field__error');
			if (existing) {
				existing.remove();
			}
			if (field) {
				field.removeAttribute('aria-invalid');
			}
			return;
		}
		wrapper.classList.add('md-inquiry-field--invalid');
		var error = wrapper.querySelector('.md-inquiry-field__error');
		if (!error) {
			error = document.createElement('span');
			error.className = 'md-inquiry-field__error';
			error.id = 'md-inquiry-' + key + '-error';
			wrapper.appendChild(error);
		}
		error.textContent = message;
		if (field) {
			field.setAttribute('aria-invalid', 'true');
			field.setAttribute('aria-describedby', error.id);
		}
	}

	function emailLooksValid(email) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
	}

	/* ---------- The live WhatsApp link ---------- */

	function waText() {
		var name = value('name');
		var company = value('company');
		var message = form.querySelector('#md-inquiry-message');
		message = message ? message.value.trim() : '';
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
		var input = form.querySelector('#md-inquiry-' + key);
		if (input) {
			input.addEventListener('input', updateWhatsApp);
		}
	});

	/* ---------- Submit ---------- */

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
})();
