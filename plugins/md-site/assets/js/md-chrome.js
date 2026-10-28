/* MetalDivider chrome runtime.
 *
 * The converted LiveCanvas templates carry the old site's markup, including
 * custom elements (xo-modal, xo-tabs, xo-sticky, xo-lazyload) and Alpine
 * components. The old theme's xo-webcomponents bundle does not enter this
 * site (ticket #19 T3/T6, spec 08 DEBT-3/CHECK-5), so this file reimplements
 * the small behaviour surface those tags actually use, and registers the
 * Alpine components the templates call by name (the CSP-friendly Alpine
 * build cannot evaluate object literals with methods).
 *
 * Load order: this file, then the Alpine plugins, then Alpine itself.
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------------ *
   * Alpine components (registered before Alpine starts)
   * ------------------------------------------------------------------ */
  document.addEventListener('alpine:init', function () {
    var Alpine = window.Alpine;
    if (!Alpine) {
      return;
    }

    // The family pages' hover/tap tab strip.
    //
    // The initial tab is taken from the panels' own `x-show` keys, not from
    // the argument alone. The argument was a hardcoded string the caller had
    // to keep in sync with whatever that group's panels actually use, and one
    // page reused `mdHoverTabs('fluted')` for three different groups: the
    // group whose keys are `execution_step_*` matched none of them, so all
    // four of its panels rendered hidden and the section read as blank
    // (found 2026-10-27 on /glass-hybrid-screens/ and
    // /laser-cut-geometric-metal-screens/). Deriving the default removes the
    // class of error rather than fixing one instance: whatever the panels
    // use, the first of them is what opens.
    Alpine.data('mdHoverTabs', function (initial) {
      return {
        activeTab: initial,
        initTab: function () {
          var root = this.$root;
          var keys = [];
          root.querySelectorAll('[x-show]').forEach(function (panel) {
            var expr = panel.getAttribute('x-show') || '';
            var hit = expr.match(/'([^']+)'/);
            if (hit && keys.indexOf(hit[1]) === -1) {
              keys.push(hit[1]);
            }
          });
          // Only override when the given initial is not one of this group's
          // keys: a caller that names a real key keeps its choice.
          if (keys.length && keys.indexOf(this.activeTab) === -1) {
            this.activeTab = keys[0];
          }
        },
        isDesktopHover: false,
        hoverTimer: null,
        init: function () {
          var self = this;
          this.initTab();
          var mq = window.matchMedia('(hover: hover) and (pointer: fine)');
          this.isDesktopHover = mq.matches;
          var onChange = function (event) {
            self.isDesktopHover = event.matches;
            self.clearQueuedTab();
          };
          if (mq.addEventListener) {
            mq.addEventListener('change', onChange);
          } else {
            mq.addListener(onChange);
          }
        },
        setTab: function (tabId) {
          clearTimeout(this.hoverTimer);
          if (this.activeTab === tabId) {
            return;
          }
          this.activeTab = tabId;
        },
        queueTab: function (tabId) {
          if (!this.isDesktopHover) {
            return;
          }
          var self = this;
          clearTimeout(this.hoverTimer);
          this.hoverTimer = setTimeout(function () {
            self.setTab(tabId);
          }, 120);
        },
        clearQueuedTab: function () {
          clearTimeout(this.hoverTimer);
        }
      };
    });

    // An expandable step list whose panels hold videos: opening a step
    // starts its videos (the browser blocks autoplay for some sources).
    Alpine.data('mdVideoSteps', function (selector) {
      return {
        activeSteps: ['1'],
        isOpen: function (step) {
          return this.activeSteps.indexOf(String(step)) > -1;
        },
        toggle: function (step) {
          var key = String(step);
          this.activeSteps = this.isOpen(key)
            ? this.activeSteps.filter(function (item) {
                return item !== key;
              })
            : this.activeSteps.concat([key]);
          this.syncVideos();
        },
        syncVideos: function () {
          var root = this.$root;
          this.$nextTick(function () {
            root.querySelectorAll(selector).forEach(function (video) {
              video.muted = true;
              video.playsInline = true;
              var played = video.play();
              if (played && played.catch) {
                played.catch(function () {});
              }
            });
          });
        }
      };
    });

    // The outdoor page's tiered step list.
    Alpine.data('mdTierSteps', function () {
      return {
        activeStep: 1,
        isDesktopHover: false,
        timer: null,
        stepTiers: { 1: [3], 2: [2, 3], 3: [2, 3], 4: [1, 2, 3], 5: [1, 2, 3] },
        init: function () {
          var self = this;
          var mq = window.matchMedia('(hover: hover) and (pointer: fine)');
          this.isDesktopHover = mq.matches;
          var onChange = function (event) {
            self.isDesktopHover = event.matches;
            self.clearQueued();
          };
          if (mq.addEventListener) {
            mq.addEventListener('change', onChange);
          } else {
            mq.addListener(onChange);
          }
        },
        setStep: function (step) {
          clearTimeout(this.timer);
          step = Number(step);
          if (this.activeStep === step) {
            return;
          }
          this.activeStep = step;
        },
        queueStep: function (step) {
          if (!this.isDesktopHover) {
            return;
          }
          var self = this;
          clearTimeout(this.timer);
          this.timer = setTimeout(function () {
            self.setStep(step);
          }, 120);
        },
        clearQueued: function () {
          clearTimeout(this.timer);
        },
        stepHasTier: function (step, tier) {
          return (this.stepTiers[step] || []).indexOf(Number(tier)) > -1;
        }
      };
    });

    // The FAQ page: one open answer at a time, category filter resets it.
    // The FAQ's category filter.
    //
    // The default is every category, not one hand-picked name. Two reasons,
    // both observed 2026-10-27: a hardcoded category name silently hides the
    // other seven groups if the markup's label ever differs by a character
    // (seven of eight groups hidden, on a page whose whole job is answering
    // questions), and landing on one category makes the page read as
    // mostly-empty until the visitor discovers the tabs. The filter is an
    // optional narrowing, so it starts wide.
    Alpine.data('mdFaq', function () {
      return {
        openFaq: '',
        activeFaqCategory: 'all',
        selectCategory: function (name) {
          this.activeFaqCategory = name;
          this.openFaq = '';
        },
        toggle: function (id) {
          this.openFaq = this.openFaq === id ? '' : id;
        }
      };
    });

    // The quote dialog, opened by [data-quote-source] CTAs and by any
    // anchor that targets #open-quote. The footer ships one dialog on every
    // page; a page with its own #quote-modal (custom-screens) keeps the page
    // dialog as the single open target, so the footer copy stays inert there.
    //
    // A page that already renders the inquiry form itself (the inquiry face)
    // must not get a second copy inside the dialog: md-inquiry's markup
    // carries fixed ids (md-inquiry-name, md-inquiry-source-*) and its script
    // binds only the page's first .md-inquiry-app, so a dialog copy would both
    // duplicate nine ids and sit inert. On such a page the CTA scrolls to the
    // form that is already on screen instead of opening a dialog over it.
    Alpine.data('quoteModal', function () {
      return {
        isOpen: false,
        detail: null,
        opener: null,
        open: function (detail) {
          if (document.getElementById('quote-modal') && this.$root.id !== 'quote-modal') {
            return;
          }
          var page_form = pageInquiryForm();
          if (page_form) {
            focusInquiryForm(page_form);
            return;
          }
          if (this.isOpen) {
            return;
          }
          // The dialog joins the shared surface stack before it is shown.
          // Without this the stack only knows the drawer the CTA lives in,
          // and that drawer's inert sweep marks every other body child —
          // this dialog included, it lives in the footer — inert. Focus
          // cannot enter an inert subtree, so the form sat unreachable
          // behind its own overlay, and one Escape closed both surfaces
          // (the stack answered for the drawer, Alpine's window listener
          // for the dialog). On the stack, inert, Tab and Escape all
          // treat the dialog as the topmost surface.
          var root = this.$root;
          var self = this;
          this.opener = document.activeElement;
          root.__mdClose = function () {
            self.close();
          };
          pushSurface(root);
          this.detail = detail || null;
          this.isOpen = true;
        },
        close: function () {
          if (!this.isOpen) {
            return;
          }
          var root = this.$root;
          var opener = this.opener;
          this.isOpen = false;
          this.opener = null;
          this.detail = null;
          root.__mdClose = null;
          popSurface(root);
          restoreFocus(opener);
        }
      };
    });

    // The stats bands' factory-image marquee (the five old sections each
    // defined the same component; the converted markup keeps their names).
    var marquee = function () {
      return {
        frameId: null,
        lastTimestamp: null,
        paused: false,
        offset: 0,
        init: function () {
          if (!this.$refs.marquee || !this.$refs.track) {
            return;
          }
          if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
          }
          var self = this;
          this.frameId = window.setTimeout(function () {
            self.step(Date.now());
          }, 16);
        },
        step: function (timestamp) {
          if (this.lastTimestamp === null) {
            this.lastTimestamp = timestamp;
          }
          var delta = (timestamp - this.lastTimestamp) / 1000;
          this.lastTimestamp = timestamp;
          if (!this.paused) {
            var speed = window.matchMedia('(min-width: 768px)').matches ? 40 : 28;
            var resetPoint = this.$refs.track.scrollWidth / 2;
            this.offset -= speed * delta;
            if (resetPoint > 0 && Math.abs(this.offset) >= resetPoint) {
              this.offset = 0;
            }
          }
          var self = this;
          this.frameId = window.setTimeout(function () {
            self.step(Date.now());
          }, 16);
        },
        resume: function () {
          this.paused = false;
          this.lastTimestamp = null;
        }
      };
    };

    ['interiorMfgStatsMarquee', 'chineseMfgStatsMarquee', 'laserCutMfgStatsMarquee', 'outdoorMfgStatsMarquee', 'stainlessCapabilityProofMarquee'].forEach(function (name) {
      Alpine.data(name, marquee);
    });
  });

  /* ------------------------------------------------------------------ *
   * xo-* custom elements
   * ------------------------------------------------------------------ */
  function each(list, fn) {
    Array.prototype.forEach.call(list, fn);
  }

  // The inquiry form a page renders for itself, outside the footer's quote
  // dialog — the inquiry face is the only page that does. Null everywhere else.
  function pageInquiryForm() {
    var app = null;
    each(document.querySelectorAll('.md-inquiry-app'), function (candidate) {
      if (!app && !candidate.closest('#md-quote-modal')) {
        app = candidate;
      }
    });
    return app;
  }

  // Brings the page's own form into view and puts the caret in its first
  // field: the quote CTA's intent is "start an inquiry", and on a page whose
  // form is already on screen that is a scroll, not a dialog.
  function focusInquiryForm(app) {
    var target = app.querySelector('.md-inquiry-field__input') || app;
    if (target.scrollIntoView) {
      target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
    if (typeof target.focus === 'function') {
      target.focus({ preventScroll: true });
    }
  }

  // Drops the dialog's copy of the inquiry form on a page that already renders
  // one. Leaving it would duplicate nine ids and, because md-inquiry's script
  // binds only the first .md-inquiry-app on the page, also leave the dialog's
  // copy inert. Runs once at start, before anything can be opened.
  function dropDuplicateModalForm() {
    var pageForm = pageInquiryForm();
    if (!pageForm) {
      return;
    }
    each(document.querySelectorAll('#md-quote-modal .md-inquiry-app'), function (duplicate) {
      duplicate.parentNode.removeChild(duplicate);
    });
  }

  function findModal(name) {
    var found = null;
    each(document.querySelectorAll('xo-modal'), function (modal) {
      if (!found && modal.getAttribute('xo-name') === name) {
        found = modal;
      }
    });
    return found;
  }

  var openStack = [];

  // Every surface that overlays the page — xo-modal, the gallery lightbox —
  // joins one stack and one scroll lock. The lock is the
  // html.md-modal-open class (chrome.css), not body.style.overflow: the
  // inline property could not see the other surfaces, so a lightbox opened
  // over an open modal released the lock the modal still needed, and closing
  // the modal then left the page unscrollable when the lightbox was the one
  // still open. The stack also decides who answers Escape: the last surface
  // opened is the only one that closes.
  function syncScrollLock() {
    document.documentElement.classList.toggle('md-modal-open', openStack.length > 0);
  }

  /* ------------------------------------------------------------------ *
   * Background isolation (inert)
   * ------------------------------------------------------------------ */

  // The non-interactive page behind a surface is every sibling along the
  // surface's ancestor chain. That walk — not body's children — is what makes
  // this work for both surface families: the converted gallery keeps its
  // lightbox inside <section> inside <main>, and the header's drawers live
  // inside <header>, so a body-level sweep would miss the page in one case and
  // inert the dialog itself in the other.
  // HEAD is in the skip list because the walk reaches <html>'s children: an
  // inert <head> changes nothing and is a pointless DOM mutation.
  var INERT_SKIP = { SCRIPT: true, STYLE: true, TEMPLATE: true, LINK: true, META: true, HEAD: true };

  function surfaceShells(surface) {
    var shells = [];
    var node = surface;
    while (node && node !== document.documentElement) {
      var parent = node.parentElement;
      if (!parent) {
        break;
      }
      each(parent.children, function (sibling) {
        if (sibling !== node && !INERT_SKIP[sibling.localName.toUpperCase()] && shells.indexOf(sibling) < 0) {
          shells.push(sibling);
        }
      });
      node = parent;
    }
    return shells;
  }

  // What the manager currently holds inert, with each element's aria-hidden as
  // it was beforehand: cleanup restores that exact value instead of stripping
  // an attribute the markup owns (decorative sections carry aria-hidden="true"
  // of their own, and the old sweep would have erased them).
  var INERT_HELD = [];

  // One recomputation for the whole stack, run on every open and close. A
  // per-surface apply/release pair cannot be correct once surfaces nest: the
  // header's sub-drawers open INSIDE the main drawer, so both own the page
  // behind them, and releasing on the sub-drawer's close used to un-inert the
  // page while the main drawer was still open. Recomputing the union means a
  // shell is released only when no open surface still needs it.
  //
  // A surface's own ancestor chain is always live. The gallery lightbox sits
  // inside <main>, so an open header modal had marked <main> inert — and focus
  // cannot be moved into an inert subtree (measured: opening the lightbox over
  // the search sheet left focus on <body> and the dialog unreachable by Tab).
  // Excluding each surface's ancestors keeps every open surface usable while
  // everything around them stays inert.
  function syncInert() {
    var protectedNodes = [];
    var wanted = [];

    openStack.forEach(function (surface) {
      var node = surface;
      while (node && node !== document.documentElement) {
        if (protectedNodes.indexOf(node) < 0) {
          protectedNodes.push(node);
        }
        node = node.parentElement;
      }
      surfaceShells(surface).forEach(function (shell) {
        if (wanted.indexOf(shell) < 0) {
          wanted.push(shell);
        }
      });
    });

    // A shell that is also (or contains) an open surface is not inert-able.
    wanted = wanted.filter(function (shell) {
      if (protectedNodes.indexOf(shell) > -1) {
        return false;
      }
      var holdsSurface = false;
      openStack.forEach(function (surface) {
        if (shell.contains(surface)) {
          holdsSurface = true;
        }
      });
      return !holdsSurface;
    });

    INERT_HELD = INERT_HELD.filter(function (held) {
      if (wanted.indexOf(held.el) > -1) {
        return true;
      }
      held.el.removeAttribute('inert');
      if (null === held.aria) {
        held.el.removeAttribute('aria-hidden');
      } else {
        held.el.setAttribute('aria-hidden', held.aria);
      }
      return false;
    });

    wanted.forEach(function (shell) {
      var known = false;
      INERT_HELD.forEach(function (held) {
        if (held.el === shell) {
          known = true;
        }
      });
      if (known) {
        return;
      }
      var prior = shell.getAttribute('aria-hidden');
      shell.setAttribute('inert', '');
      shell.setAttribute('aria-hidden', 'true');
      INERT_HELD.push({ el: shell, aria: prior });
    });
  }

  function pushSurface(surface) {
    if (surface && openStack.indexOf(surface) < 0) {
      openStack.push(surface);
    }
    syncScrollLock();
    syncInert();
  }

  function popSurface(surface) {
    var index = openStack.indexOf(surface);
    if (index > -1) {
      openStack.splice(index, 1);
    }
    syncScrollLock();
    syncInert();
  }

  // Closing always hands focus back to whatever opened the surface: a dialog
  // that swallows focus on close strands a keyboard user at the top of the
  // document.
  //
  // The fallback matters when surfaces nest. If the lightbox was opened while
  // the search modal was open, its opener is a gallery card inside <main>, and
  // <main> is legitimately still inert for the search modal — focus cannot
  // enter an inert subtree. Rather than leave focus on <body>, the topmost
  // open surface receives it, which is where the user actually is.
  function restoreFocus(target) {
    if (target && typeof target.focus === 'function' && target !== document.body
      && document.contains(target) && !target.closest('[inert]') && target.getClientRects().length) {
      target.focus({ preventScroll: true });
      return;
    }
    if (openStack.length) {
      var top = openStack[openStack.length - 1];
      var next = focusablesIn(top)[0];
      if (!next) {
        if (top.tabIndex < 0) {
          top.tabIndex = -1;
        }
        next = top;
      }
      if (typeof next.focus === 'function') {
        next.focus({ preventScroll: true });
      }
    }
  }

  // The focusable descendants of a surface, in DOM order.
  //
  // getClientRects() is the render test, not visibility: an element inside a
  // display:none subtree — every closed sub-drawer in the header, for
  // instance — still reports visibility:visible and tabIndex 0 while having no
  // box at all. Counting those made a Tab wrap hand focus to an invisible
  // control, which reads as "Tab does nothing" at the end of the list.
  //
  // Media players are in the list because the browser puts them in the tab
  // order: a <video controls> is a tab stop with its own play, seek and
  // volume controls. Leaving it out made the trap treat a dialog's close
  // button as the last stop and wrap straight back to itself, so the player —
  // the one control the process dialog exists for — was reachable by pointer
  // only.
  function focusablesIn(root) {
    return [].slice
      .call(root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), video[controls], audio[controls], [tabindex]:not([tabindex="-1"])'))
      .filter(function (el) {
        return el.tabIndex >= 0 && el.getClientRects().length > 0;
      });
  }

  // Tab containment for whichever surface is on top. The background is inert,
  // so the only way out is the browser chrome at either end of the document;
  // wrapping there keeps the keyboard inside the dialog. One delegated
  // handler for the whole stack instead of one per surface: it therefore
  // follows nesting automatically — the topmost surface is the one wrapped in,
  // which is exactly the one the user is in.
  function trapTabInTopSurface(event) {
    if (!openStack.length) {
      return;
    }
    var top = openStack[openStack.length - 1];
    var focusables = focusablesIn(top);
    if (!focusables.length) {
      event.preventDefault();
      if (top.tabIndex < 0) {
        top.tabIndex = -1;
      }
      top.focus({ preventScroll: true });
      return;
    }
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    var active = document.activeElement;
    var outside = !top.contains(active);
    if (event.shiftKey && (active === first || outside)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (active === last || outside)) {
      event.preventDefault();
      first.focus();
    }
  }

  // A surface needs a name and a role before it can be announced as a dialog.
  // The converted markup ships bare <xo-modal> elements with neither, and the
  // label is derived from the control that opened it, which is the most
  // accurate name available at runtime.
  function labelModal(modal, trigger) {
    if (!modal.hasAttribute('role')) {
      modal.setAttribute('role', 'dialog');
    }
    if (!modal.hasAttribute('aria-modal')) {
      modal.setAttribute('aria-modal', 'true');
    }
    if (modal.hasAttribute('aria-label') || modal.hasAttribute('aria-labelledby')) {
      return;
    }
    var label = '';
    if (trigger) {
      label = (trigger.getAttribute('aria-label') || '').trim() || (trigger.textContent || '').trim();
    }
    if (!label) {
      var heading = modal.querySelector('h1, h2, h3');
      label = heading ? (heading.textContent || '').trim() : '';
    }
    if (!label) {
      var name = (modal.getAttribute('xo-name') || '').toLowerCase();
      if (name.indexOf('hamburger') > -1 || name.indexOf('menu') > -1) {
        label = 'Menu';
      } else if (name.indexOf('search') > -1) {
        label = 'Search';
      }
    }
    modal.setAttribute('aria-label', label || 'Dialog');
  }

  // The control a surface should focus first. An explicit autofocus wins, then
  // the surface's own text field (the search sheet is useless with focus on
  // its close button), then the first control in DOM order.
  function initialFocusTarget(surface) {
    var explicit = surface.querySelector('[autofocus]');
    if (explicit && explicit.getClientRects().length) {
      return explicit;
    }
    var field = surface.querySelector('input:not([type="hidden"]):not([disabled]), textarea:not([disabled])');
    if (field && field.getClientRects().length) {
      return field;
    }
    var focusables = focusablesIn(surface);
    if (focusables.length) {
      return focusables[0];
    }
    if (surface.tabIndex < 0) {
      surface.tabIndex = -1;
    }
    return surface;
  }

  // A trigger that opens a surface must announce the surface's state. The
  // drawer's triggers are the hamburger ("Open menu") and the sub-menu rows,
  // none of which ship aria-expanded in the converted markup; without this the
  // trigger still reads "Open menu" while the drawer is open (measured: the
  // attribute stayed null through open and close).
  function syncTriggerState(trigger, open) {
    // Programmatic opens record document.activeElement, which can be <body>;
    // only a real toggle control carries the state.
    if (!trigger || trigger === document.body || trigger === document.documentElement) {
      return;
    }
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      var label = trigger.getAttribute('aria-label') || '';
      if (/^Open\s/.test(label)) {
        trigger.__mdClosedLabel = label;
        trigger.setAttribute('aria-label', 'Close' + label.slice(4));
      }
    } else if (trigger.__mdClosedLabel) {
      trigger.setAttribute('aria-label', trigger.__mdClosedLabel);
      trigger.__mdClosedLabel = null;
    }
  }

  function openModal(modal, trigger) {
    if (!modal) {
      return;
    }
    // Record the opener before the modal takes focus: the drawer's triggers
    // are xo-modal-trigger elements, which are not natively focusable, so the
    // closest focusable ancestor is what the user was on. document.activeElement
    // is the honest answer for the click and keyboard paths alike.
    if (!modal.__mdOpener) {
      modal.__mdOpener = trigger || document.activeElement;
    }
    syncTriggerState(modal.__mdOpener, true);
    labelModal(modal, trigger);
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    pushSurface(modal);
    // Initial focus inside the surface. Without this, opening the mobile
    // drawer left focus on <body> and the first Tab continued from the top of
    // the page behind the overlay (measured on 390).
    initialFocusTarget(modal).focus({ preventScroll: true });
  }

  function closeModal(modal) {
    if (!modal) {
      return;
    }
    var opener = modal.__mdOpener;
    modal.__mdOpener = null;
    // Order, and why it is this one:
    // 1. popSurface releases the page's inert state, without which the opener
    //    is not focusable — focus cannot enter an inert subtree (measured: the
    //    restore silently no-opped and focus stayed on <body>).
    // 2. Focus then leaves the surface, so the aria-hidden below is not
    //    applied to a node that still holds focus (Chrome refuses that and
    //    warns).
    // 3. Only then does the surface hide.
    // All three run in one task, so no frame is painted between them: the page
    // is never visibly interactive behind a still-visible overlay.
    popSurface(modal);
    restoreFocus(opener);
    syncTriggerState(opener, false);
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  function setupModals() {
    each(document.querySelectorAll('xo-modal'), function (modal) {
      modal.setAttribute('aria-hidden', 'true');
    });

    // The converted markup uses <xo-modal-trigger> as a bare custom element
    // with a click handler and no button inside it (measured: 28 in the
    // header alone, including the hamburger). That element is not focusable
    // and does not respond to Enter or Space, so every one of these controls
    // was pointer-only. The role, the tab stop and the activation keys are
    // the whole of what a native button would have given them, applied here
    // instead of requiring 28 template edits.
    each(document.querySelectorAll('xo-modal-trigger'), function (trigger) {
      var opens = !!trigger.getAttribute('xo-name');
      if (!trigger.hasAttribute('role')) {
        trigger.setAttribute('role', 'button');
      }
      if (!trigger.hasAttribute('tabindex') && trigger.tabIndex < 0) {
        trigger.tabIndex = 0;
      }
      if (opens && !trigger.hasAttribute('aria-haspopup')) {
        trigger.setAttribute('aria-haspopup', 'dialog');
      }
      trigger.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
          // Space would scroll the page before it activates otherwise.
          event.preventDefault();
          trigger.click();
        }
      });
    });

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('xo-modal-trigger');
      if (trigger) {
        var name = trigger.getAttribute('xo-name');
        if (name) {
          event.preventDefault();
          // The trigger is what focus returns to and what names the dialog; a
          // click on a custom element does not move document.activeElement, so
          // both are passed explicitly.
          openModal(findModal(name), trigger);
        } else {
          var owner = trigger.closest('xo-modal');
          if (owner) {
            event.preventDefault();
            closeModal(owner);
          }
        }
        return;
      }

      // A [data-quote-source] CTA, or any anchor to #open-quote, opens the
      // quote dialog when one exists; elsewhere the anchor target handles it.
      var quote = event.target.closest('[data-quote-source], a[href="#open-quote"]');
      if (quote && document.querySelector('[x-data^="quoteModal"]')) {
        event.preventDefault();
        window.dispatchEvent(
          new CustomEvent('open-quote', {
            detail: { source: quote.getAttribute('data-quote-source') || 'open-quote' }
          })
        );
      }
    });

    document.addEventListener('click', function (event) {
      if (event.target.matches('xo-modal.is-open')) {
        closeModal(event.target);
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Tab') {
        trapTabInTopSurface(event);
        return;
      }
      if (event.key === 'Escape' && openStack.length) {
        // The topmost surface owns Escape; the surfaces below it stay open.
        // Their own close functions restore focus where it came from.
        // stopImmediatePropagation is the ownership: a later document listener
        // (the nav disclosure, the landing menu, the catalog sheet) would
        // otherwise close too and move focus off the opener this close just
        // restored. The catalog sheet checks md-modal-open, but closeModal
        // clears that class before those listeners run.
        event.preventDefault();
        event.stopImmediatePropagation();
        var top = openStack[openStack.length - 1];
        if (typeof top.__mdClose === 'function') {
          top.__mdClose();
        } else {
          closeModal(top);
        }
      }
    });
  }

  // One serial for the whole document: generated tab ids must be unique
  // across every tab set on the page. The previous scheme ("md-tab-" + index)
  // was unique only while a page carried a single strip, and the panels never
  // received an id at all, so aria-controls pointed at nothing.
  var tabSetSerial = 0;

  /**
   * Applies the WAI-ARIA tabs pattern to one strip and returns nothing.
   *
   * Both tab vocabularies on this site are the same behaviour over different
   * markup — the converted pages carry <xo-tabs>/<xo-tabs-trigger> custom
   * elements, and the pattern library carries plain buttons with
   * data-tab/data-panel attributes. This is the one implementation; the two
   * discovery passes below only tell it how to read a name and which class
   * marks the open tab, so there is no second tab engine to keep in step.
   *
   * @param {ArrayLike<Element>} triggers The strip's controls, in order.
   * @param {ArrayLike<Element>} panes    The panels, in the same order.
   * @param {Object}             options  list, activeClass, nameOf, paneNameOf, initial.
   */
  function bindTabSet(triggers, panes, options) {
    var activeClass = options.activeClass;
    var nameOf = options.nameOf;
    var paneNameOf = options.paneNameOf;
    var serial = (tabSetSerial += 1);
    var list = options.list;

    // The converted markup is a tab strip in behaviour only: a plain div
    // holding custom elements, so a screen reader announced four unrelated
    // "group"s and every one of them was a tab stop. The roles, the
    // selection state and the roving tabindex are applied here, from the
    // markup that is already in the page — the tab strip needs no new
    // server template for this.
    if (list && !list.hasAttribute('role')) {
      list.setAttribute('role', 'tablist');
    }

    var triggerId = function (trigger, index) {
      if (!trigger.id) {
        trigger.id = 'md-tab-' + serial + '-' + index;
      }
      return trigger.id;
    };
    var paneId = function (pane, index) {
      if (!pane.id) {
        pane.id = 'md-tab-' + serial + '-' + index + '-panel';
      }
      return pane.id;
    };
    var sameName = function (a, b) {
      return String(a === null || a === undefined ? '' : a).trim() ===
        String(b === null || b === undefined ? '' : b).trim();
    };

    var activate = function (name, moveFocus) {
      each(triggers, function (trigger, index) {
        var on = sameName(nameOf(trigger), name);
        trigger.classList.toggle(activeClass, on);
        trigger.setAttribute('role', 'tab');
        trigger.setAttribute('aria-selected', on ? 'true' : 'false');
        // One tab stop for the whole strip: Tab reaches the strip once and
        // leaves again, arrow keys move within it (WAI-ARIA tabs pattern).
        trigger.tabIndex = on ? 0 : -1;
        trigger.setAttribute('aria-controls', panes[index] ? paneId(panes[index], index) : '');
        if (on && moveFocus) {
          trigger.focus();
        }
      });
      each(panes, function (pane, index) {
        var on = sameName(paneNameOf(pane), name);
        pane.classList.toggle(activeClass, on);
        pane.hidden = !on;
        pane.setAttribute('role', 'tabpanel');
        pane.setAttribute('aria-labelledby', triggers[index] ? triggerId(triggers[index], index) : '');
        // A panel without focusable content is unreachable by keyboard
        // unless it is itself focusable.
        pane.tabIndex = on ? 0 : -1;
      });
    };

    var indexOf = function (trigger) {
      for (var i = 0; i < triggers.length; i += 1) {
        if (triggers[i] === trigger) {
          return i;
        }
      }
      return -1;
    };

    each(triggers, function (trigger) {
      trigger.addEventListener('click', function () {
        activate(nameOf(trigger), false);
      });
      trigger.addEventListener('keydown', function (event) {
        var current = indexOf(trigger);
        var next = null;
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
          next = (current + 1) % triggers.length;
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
          next = (current - 1 + triggers.length) % triggers.length;
        } else if (event.key === 'Home') {
          next = 0;
        } else if (event.key === 'End') {
          next = triggers.length - 1;
        }
        if (next === null) {
          return;
        }
        event.preventDefault();
        activate(nameOf(triggers[next]), true);
      });
    });

    if (options.initial) {
      activate(nameOf(options.initial), false);
    }
  }

  function setupTabs() {
    // The converted pages' <xo-tabs> strip.
    each(document.querySelectorAll('xo-tabs'), function (tabs) {
      var triggers = tabs.querySelectorAll('xo-tabs-trigger');
      var panes = tabs.querySelectorAll('xo-tabs-pane');
      if (!triggers.length || !panes.length) {
        return;
      }
      bindTabSet(triggers, panes, {
        list: tabs.querySelector(':scope > .hpl__tablist') || triggers[0].parentElement,
        activeClass: 'is-active',
        nameOf: function (trigger) {
          return trigger.getAttribute('xo-name');
        },
        paneNameOf: function (pane) {
          return pane.getAttribute('xo-name');
        },
        initial: tabs.querySelector('xo-tabs-trigger[xo-active]') || triggers[0]
      });
    });

    // The pattern library's strip (custom-screens): plain <button
    // data-tab> controls over <div data-panel> panels. It shipped as four
    // panels with no behaviour bound at all, so only the first — the one
    // carrying the `active` class in the markup — was ever visible and the
    // other three were unreachable. Same engine, second vocabulary.
    each(document.querySelectorAll('.patterns-tabs'), function (list) {
      var scope = list.parentElement;
      var content = scope ? scope.querySelector('.patterns-content') : null;
      if (!content) {
        return;
      }
      var triggers = list.querySelectorAll('.tab-button');
      var panes = content.querySelectorAll('.tab-panel');
      if (!triggers.length || !panes.length) {
        return;
      }
      bindTabSet(triggers, panes, {
        list: list,
        activeClass: 'active',
        nameOf: function (trigger) {
          return trigger.getAttribute('data-tab');
        },
        paneNameOf: function (pane) {
          return pane.getAttribute('data-panel');
        },
        initial: list.querySelector('.tab-button.active') || triggers[0]
      });
    });
  }

  function setupLazyload() {
    each(document.querySelectorAll('img[xo-src]'), function (image) {
      // A mega-menu panel's pictures are deferred to the panel's first open
      // (see setOpen): a closed panel still occupies layout behind
      // `opacity`/`visibility`, so `loading="lazy"` defers nothing and every
      // page paid for the whole menu's imagery.
      if (image.closest('.xo-menu-horizontal-v1__mega-menu')) {
        return;
      }
      image.src = image.getAttribute('xo-src');
      var srcset = image.getAttribute('xo-srcset');
      if (srcset) {
        image.srcset = srcset;
      }
    });
  }

  // The nav's mega menus. The header ships each panel as an empty
  // <xo-mega-menu xo-name="header-mega-menu-v1" xo-index="N"> and the panel's
  // content as a <template xo-mega-menu-name="header-mega-menu-v1"
  // xo-mega-menu-index="N">, the same split the old theme's runtime consumed:
  // it cloned the template into the empty tag, then set the item's own
  // position to static so a full-width panel lays out against the header
  // instead of a 76px-tall menu row. An unfilled tag stays display:none
  // (chrome.css .xo-menu-horizontal-v1__mega-menu:empty), so without this
  // pass the dropdown has no content at all.
  function fillMegaMenus() {
    var templates = {};
    each(document.querySelectorAll('template[xo-mega-menu-name]'), function (template) {
      var key = template.getAttribute('xo-mega-menu-name') + '|' + template.getAttribute('xo-mega-menu-index');
      if (!templates[key]) {
        templates[key] = template;
      }
    });

    each(document.querySelectorAll('xo-mega-menu'), function (panel) {
      // The drawer lists the same destinations as text links. Cloning the
      // desktop image grid into it hid those links (the sibling rule in
      // chrome.css) and left leaf items pointing at an empty modal.
      if (panel.classList.contains('xo-menu-hamburger-style1__mega-menu')) {
        return;
      }
      if (panel.children.length) {
        return;
      }
      var name = panel.getAttribute('xo-name');
      var template = name && templates[name + '|' + (panel.getAttribute('xo-index') || '0')];
      if (!template || !template.content) {
        return;
      }
      panel.appendChild(template.content.cloneNode(true));
      var section = panel.firstElementChild;
      if (section && section.getAttribute('data-id')) {
        panel.setAttribute('xo-section-id', section.getAttribute('data-id'));
      }
    });
  }

  // The desktop panel is the nav's own full-width dropdown: it hangs from the
  // header's bottom edge and stays hidden until the item is hovered or
  // focused. Its presentation is chrome.css's job now (position, fade,
  // visibility); this function owns only the state. A filled panel replaces
  // the item's narrow sub-menu, which still carries the same links.
  //
  // The open state belongs to the header, not the item: the pointer leaves
  // the 16px-tall item to reach a panel that starts at the header's bottom
  // edge, and closing on the item's own mouseleave would shut the panel
  // before the pointer arrives. Leaving the header (or moving over the logo
  // or the actions) is what closes it.
  //
  // Keyboard contract, which the CSS alone cannot express:
  // - The panel is a disclosure, not a dialog: it does not trap Tab. It opens
  //   on focus and closes when focus leaves it, so tabbing through the nav
  //   passes through the panel's own links in document order and then moves
  //   on — the panel never strands focus inside a hidden subtree.
  // - Escape closes the panel and returns focus to the item's link, so the
  //   user resumes where they started instead of at the top of the document.
  function setupDesktopMegaPanels() {
    var entries = [];
    var closeAll = function (except) {
      entries.forEach(function (entry) {
        if (entry.panel !== except) {
          entry.setOpen(false);
        }
      });
    };

    each(document.querySelectorAll('xo-mega-menu.xo-menu-horizontal-v1__mega-menu'), function (panel) {
      var item = panel.parentElement;
      if (!item || !panel.firstElementChild) {
        return;
      }
      // No inline geometry: the closed state must also remove the panel from
      // the tab order, which `inert` plus the stylesheet's visibility rule do
      // together (see setOpen). Starting state is the closed one.
      panel.setAttribute('aria-hidden', 'true');
      panel.setAttribute('inert', '');

      var link = item.querySelector('.xo-menu-horizontal-v1__link');
      if (link) {
        link.setAttribute('aria-expanded', 'false');
        // The one control that opens the panel says so, and the panel it
        // controls is named for a screen reader.
        if (!panel.id) {
          panel.id = 'md-mega-panel-' + (panel.getAttribute('xo-index') || entries.length);
        }
        link.setAttribute('aria-controls', panel.id);
      }

      var setOpen = function (open) {
        item.classList.toggle('md-mega-open', open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        // `inert` is what makes the closed state immediate. The stylesheet
        // hides the panel with a delayed `visibility` so the 300ms fade can
        // play, and during that fade the links would still be tabbable;
        // inert removes them from the tab order and the accessibility tree on
        // the same tick, and the fade is unaffected because inert changes no
        // computed style.
        if (open) {
          panel.removeAttribute('inert');
          // The panel's own images resolve here, on first open, rather than at
          // load. `setupLazyload()` writes every `xo-src` to `src`, and a
          // closed panel is hidden with `opacity`/`visibility` — it still
          // occupies layout, so `loading="lazy"` never defers anything and
          // every page downloaded the whole menu's imagery (measured
          // 2026-10-27: pages with no images of their own still fetched 382KB
          // of closed-menu pictures at 1440). Resolving on open is what the
          // dropdown actually needs.
          each(panel.querySelectorAll('img[xo-src]'), function (image) {
            image.src = image.getAttribute('xo-src');
            var srcset = image.getAttribute('xo-srcset');
            if (srcset) {
              image.srcset = srcset;
            }
            image.removeAttribute('xo-src');
          });
        } else {
          panel.setAttribute('inert', '');
        }
        if (link) {
          link.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
      };

      entries.push({ panel: panel, setOpen: setOpen });

      item.addEventListener('mouseenter', function () {
        closeAll(panel);
        setOpen(true);
      });
      item.addEventListener('focusin', function () {
        closeAll(panel);
        setOpen(true);
      });
      // A coarse pointer synthesises mouseenter and then still follows the
      // parent link. Toggle the panel instead of navigating; the hub link
      // inside the panel is how that pointer reaches the parent page.
      if (link && link.tagName === 'A') {
        link.addEventListener('click', function (event) {
          if (!window.matchMedia('(hover: none), (pointer: coarse)').matches) {
            return;
          }
          event.preventDefault();
          var open = !item.classList.contains('md-mega-open');
          closeAll(open ? panel : null);
          setOpen(open);
        });
      }
      item.addEventListener('focusout', function (event) {
        if (!item.contains(event.relatedTarget)) {
          setOpen(false);
        }
      });
      item.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && item.classList.contains('md-mega-open')) {
          event.preventDefault();
          // Order matters: focus the trigger FIRST, then close. Focusing is
          // what fires the item's own focusin handler, which opens the panel —
          // closing first meant Escape reopened the panel it had just shut
          // (measured: aria-expanded stayed true after Escape). With focus
          // already on the trigger, the focusin handler is a no-op for this
          // panel and the close below is the last word.
          if (link) {
            link.focus();
          }
          setOpen(false);
          closeAll(null);
        }
      });
    });

    // Every top-level item closes a panel it does not own. The loop above
    // only listens on items whose mega menu already has children, so moving
    // onto Patterns, About, or Inquiry left the previous panel open.
    // Resources keeps its own CSS :hover menu; this only clears the mega panel.
    each(document.querySelectorAll('nav.xo-header-nav > ul > li'), function (item) {
      item.addEventListener('mouseenter', function () {
        if (!item.classList.contains('md-mega-open')) {
          closeAll(null);
        }
      });
    });

    // A text submenu with no image panel (Patterns) is hover-only too. On a
    // coarse pointer the click toggles the same disclosure class Resources
    // uses, and the first row of the submenu is the hub link.
    var coarseNav = window.matchMedia('(hover: none), (pointer: coarse)');
    each(document.querySelectorAll('nav.xo-header-nav > ul > li'), function (item) {
      var parentLink = item.querySelector('.xo-menu-horizontal-v1__link');
      var submenu = item.querySelector('.xo-menu-horizontal-v1__sub-menu');
      var mega = item.querySelector('.xo-menu-horizontal-v1__mega-menu');
      if (!parentLink || parentLink.tagName !== 'A' || !submenu) {
        return;
      }
      if (mega && mega.firstElementChild) {
        return;
      }
      parentLink.addEventListener('click', function (event) {
        if (!coarseNav.matches) {
          return;
        }
        event.preventDefault();
        closeAll(null);
        var open = !item.classList.contains('is-disclosure-open');
        item.classList.toggle('is-disclosure-open', open);
        parentLink.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });

    if (!entries.length) {
      return;
    }

    var header = entries[0].panel.closest('header');
    if (header) {
      header.addEventListener('mouseleave', function () {
        closeAll(null);
      });
      each(header.querySelectorAll('.xo-header-logo, .xo-header-actions'), function (region) {
        region.addEventListener('mouseenter', function () {
          closeAll(null);
        });
      });
    }
  }

  // Inside the mobile drawer the panel is the accordion's content: it takes
  // the place of the row list the drawer prints beside it, because both list
  // the same destinations. chrome.css states that replacement as a sibling
  // rule now (`.xo-menu-hamburger-style1__mega-menu:not(:empty) ~ …`), so the
  // drawer has no presentation path of its own: filling the panel is enough.
  // Nothing to run here; the pass stays so the start order names every stage
  // the chrome has.

  // The header band sticks to the top. OWNER-APPROVED UPGRADE (2026-10-26):
  // the old site's header scrolls away with the page, and the owner ruled
  // that this defect is fixed — registered in docs/deploy/visual-parity-gate.md
  // § 升级台账, not claimed as parity.
  //
  // The sticking element is declared in chrome.css (`body > header#lc-header`):
  // LiveCanvas wraps the header partial in that <body> child, so the selector
  // states exactly which box owns the band. This function used to infer the
  // same box by walking up from <xo-sticky> until a parent measured taller
  // than the band (+40px slack) — a guess any wrapper of near-band height
  // could move. What is left is the fallback for a header partial rendered
  // outside the LiveCanvas wrapper: it sets the contract on the tag itself
  // and marks the page, so a missing host is inspectable rather than silent.
  // The pattern library's per-tile preview.
  //
  // Every tile on the custom-screens page shipped a `role="button"
  // data-pattern-preview` control and a complete lightbox — dialog, stage,
  // prev/next arrows, thumbnail strip — and nothing in the site bound either
  // of them: the preview control was a dead tab stop on all nineteen tiles,
  // and the dialog's 19 `src=""` images were empty requests waiting on a
  // click that could not happen. The markup and its CSS are unchanged; this
  // is the behaviour the strip was drawn for, using the same surface stack,
  // focus restore and Tab containment the gallery lightbox already uses.
  function setupPatternLightbox() {
    each(document.querySelectorAll('[data-pattern-preview]'), function (trigger) {
      var item = trigger.closest('.pattern-item');
      // The tile and its dialog are siblings, so the dialog is found through
      // the item's parent rather than by guessing an id.
      var host = item ? item.parentElement : null;
      var dialogId = item ? item.getAttribute('data-pattern-block-id') : null;
      var lightbox = null;
      if (host && dialogId) {
        each(host.querySelectorAll('[data-pattern-lightbox]'), function (candidate) {
          if (!lightbox && candidate.id === 'pattern-lightbox-' + dialogId) {
            lightbox = candidate;
          }
        });
      }
      if (!lightbox) {
        return;
      }

      var stageImage = lightbox.querySelector('[data-pattern-main-image]');
      var counter = lightbox.querySelector('[data-pattern-count]');
      var thumbs = lightbox.querySelectorAll('[data-pattern-thumb]');
      var opener = null;
      var index = 0;

      // A closed dialog is invisible but still laid out: the volume hides it
      // with opacity alone (`.pattern-lightbox { opacity: 0 }`), which leaves
      // its close button, arrows and thumbnails in the tab order. Tab then
      // walked into a surface marked aria-hidden, announced nothing, and the
      // only way out was to keep pressing Tab. `inert` is what takes a
      // subtree out of the tab order, the accessibility tree and hit testing
      // at once, and it is the mechanism this file already uses for the page
      // behind an open surface.
      var setClosed = function (closed) {
        if (closed) {
          lightbox.setAttribute('inert', '');
          lightbox.setAttribute('aria-hidden', 'true');
        } else {
          lightbox.removeAttribute('inert');
          lightbox.setAttribute('aria-hidden', 'false');
        }
      };
      setClosed(true);

      var show = function (next) {
        if (!thumbs.length) {
          return;
        }
        index = (next + thumbs.length) % thumbs.length;
        var active = thumbs[index];
        if (stageImage) {
          stageImage.src = active.getAttribute('data-src') || '';
          stageImage.alt = active.getAttribute('data-alt') || '';
          // The source set has to move with the source. A leftover `srcset`
          // from the previous image keeps describing *that* picture's widths,
          // so the browser may satisfy the new layout from a stale candidate
          // and show the wrong image at the right size. When the picture being
          // shown has no ladder of its own the attribute is removed rather
          // than blanked, after which `src` alone decides.
          //
          // Only the *ladder* travels: which picture is the button's business,
          // which box is the stage's. The button's `data-sizes` describes its
          // 64px thumb, and applying that to a 900px dialog would hand the
          // browser a candidate five times too small, so `sizes` stays the
          // stage's own declaration from the markup.
          var srcset = active.getAttribute('data-srcset') || '';
          if (srcset) {
            stageImage.setAttribute('srcset', srcset);
          } else {
            stageImage.removeAttribute('srcset');
          }
        }
        each(thumbs, function (thumb, i) {
          thumb.classList.toggle('active', i === index);
        });
        if (counter) {
          counter.textContent = index + 1 + ' / ' + thumbs.length;
        }
      };

      var close = function () {
        lightbox.classList.remove('active');
        setClosed(true);
        popSurface(lightbox);
        restoreFocus(opener);
        opener = null;
      };
      // The shared Escape handler closes whichever surface is on top.
      lightbox.__mdClose = close;

      var open = function () {
        opener = trigger;
        show(0);
        lightbox.classList.add('active');
        setClosed(false);
        pushSurface(lightbox);
        var first = focusablesIn(lightbox)[0];
        if (first) {
          first.focus({ preventScroll: true });
        }
      };

      trigger.addEventListener('click', open);
      trigger.addEventListener('keydown', function (event) {
        // role="button" carries no native activation, so the keys a real
        // button answers are dispatched here.
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
          event.preventDefault();
          open();
        }
      });

      lightbox.addEventListener('click', function (event) {
        if (event.target.closest('[data-pattern-close]')) {
          event.preventDefault();
          close();
          return;
        }
        if (event.target.closest('[data-pattern-prev]')) {
          event.preventDefault();
          show(index - 1);
          return;
        }
        if (event.target.closest('[data-pattern-next]')) {
          event.preventDefault();
          show(index + 1);
          return;
        }
        var thumb = event.target.closest('[data-pattern-thumb]');
        if (thumb) {
          event.preventDefault();
          for (var i = 0; i < thumbs.length; i += 1) {
            if (thumbs[i] === thumb) {
              show(i);
              break;
            }
          }
        }
      });

      lightbox.addEventListener('keydown', function (event) {
        if (!lightbox.classList.contains('active')) {
          return;
        }
        if (event.key === 'ArrowLeft') {
          event.preventDefault();
          show(index - 1);
        } else if (event.key === 'ArrowRight') {
          event.preventDefault();
          show(index + 1);
        }
      });
    });
  }

  // The process sections' "Watch 30s" tiles.
  //
  // Each family page draws a play tile over one step of its process and a
  // full-screen dialog holding that step's video, and nothing bound the two:
  // the conversion dropped the old section's inline script, and the CSP
  // blocks inline handlers anyway, so the tile was a button that only looked
  // pressable (measured: a click produced no class, no focus change and no
  // playback). The tile names its dialog with aria-controls, and this
  // function is the whole of the wiring.
  //
  // The dialog is a plain <div>, not an <xo-modal>, so it cannot ride
  // openModal/closeModal directly; it joins the same stack by hand —
  // pushSurface/popSurface for the scroll lock and the inert page behind it,
  // the __mdClose hook for Escape, the shared Tab trap and restoreFocus for
  // the keyboard. That is deliberately not a second modal engine: the same
  // functions the header drawers, the search sheet and the two lightboxes
  // use, driven by the same calls.
  //
  // The template's own <video> stays the player. Nothing is swapped for an
  // embed: opening calls play(), closing calls pause(), and poster, controls
  // and source are the markup's.
  function setupProcessVideos() {
    each(document.querySelectorAll('.video-trigger'), function (trigger) {
      // aria-controls is the pairing, so the button itself states which
      // dialog it opens. A tile without it is left alone rather than this
      // function guessing from an id convention the next template may not
      // keep.
      var id = trigger.getAttribute('aria-controls');
      var dialog = id ? document.getElementById(id) : null;
      if (!dialog) {
        return;
      }
      var video = dialog.querySelector('video');
      var opener = null;

      // Closed is a state the section volume cannot express for every
      // template: display:none hides the dialog, but the element ships
      // aria-modal="true" and a player with controls, so `inert` is what keeps
      // a dialog one property away from visible out of the tab order and the
      // accessibility tree in the meantime.
      var setDialogClosed = function (closed) {
        if (closed) {
          dialog.setAttribute('inert', '');
          dialog.setAttribute('aria-hidden', 'true');
        } else {
          dialog.removeAttribute('inert');
          dialog.setAttribute('aria-hidden', 'false');
        }
      };
      setDialogClosed(true);
      if (video) {
        video.pause();
      }

      var close = function () {
        // The player is the point of the surface: it stops when the dialog
        // goes, not whenever the video next reaches its end.
        if (video) {
          video.pause();
        }
        dialog.classList.remove('is-open');
        // Same order as closeModal, for the same reason: the tile sits in the
        // page that is inert until popSurface runs, so focus is handed back
        // only once it can land. One task, no intermediate paint.
        popSurface(dialog);
        restoreFocus(opener);
        opener = null;
        setDialogClosed(true);
      };
      // The shared Escape handler closes whichever surface is on top.
      dialog.__mdClose = close;

      var open = function () {
        opener = trigger;
        setDialogClosed(false);
        dialog.classList.add('is-open');
        pushSurface(dialog);
        initialFocusTarget(dialog).focus({ preventScroll: true });
        if (video && typeof video.play === 'function') {
          // A refused play() is the browser declining to start a media
          // element; the controls in the markup are the visitor's way out,
          // and an unhandled rejection would only be console noise.
          var started = video.play();
          if (started && typeof started.catch === 'function') {
            started.catch(function () {});
          }
        }
      };

      trigger.addEventListener('click', open);

      dialog.addEventListener('click', function (event) {
        // The dialog element is its own backdrop: the content sits in a
        // centred child, so a click landing on the dialog itself is a click
        // outside the video.
        if (event.target === dialog || event.target.closest('[data-close]')) {
          event.preventDefault();
          close();
        }
      });
    });
  }

  // The expanded-mesh sections: several scenes on one sticky stage.
  //
  // The outer element is a tall scroll track (1200vh on the home page, 800vh
  // on the expanded-metal page) and the stage inside it is sticky at 100vh, so
  // the section holds the viewport while the page scrolls through it. The
  // markup ships every scene — background, copy, FAQ group, nav item — and the
  // volumes already carry the state classes that show one of them
  // (.emt-bg-active / .emt-bg-prev with the .emt-rev variant for travelling
  // up, .emt-active on the copy and FAQ groups). Nothing ever set those
  // classes, so the track scrolled through the whole 1200vh with the stage
  // frozen on scene one: the page read as thousands of pixels of nothing.
  //
  // The scene is a function of scroll progress through the track, and nothing
  // else — one source of truth. A nav click is a scroll to that scene's
  // slice, not a separate state, so the two can never disagree.
  //
  // The scene count comes from the markup (six here, four there) rather than a
  // literal, which is what lets one function serve both sections.
  function setupMeshTypes() {
    each(document.querySelectorAll('[id^="emt-outer-"]'), function (root) {
      var stage = root.querySelector('[id^="emt-stage-"]');
      if (!stage) {
        return;
      }

      var groups = [
        { attr: 'data-emt-bg', items: [].slice.call(root.querySelectorAll('[data-emt-bg]')) },
        { attr: 'data-emt-content', items: [].slice.call(root.querySelectorAll('[data-emt-content]')) },
        { attr: 'data-emt-faq', items: [].slice.call(root.querySelectorAll('[data-emt-faq]')) },
        { attr: 'data-emt-nav', items: [].slice.call(root.querySelectorAll('[data-emt-nav]')) }
      ];

      var sceneOf = function (element, attr) {
        var value = parseInt(element.getAttribute(attr), 10);
        return isNaN(value) ? -1 : value;
      };

      var count = 0;
      groups.forEach(function (group) {
        group.items.forEach(function (item) {
          var scene = sceneOf(item, group.attr);
          if (scene + 1 > count) {
            count = scene + 1;
          }
        });
      });
      if (!count || !groups[0].items.length) {
        return;
      }

      var current = -1;

      // The inactive scenes' copy and FAQ buttons are only faded out
      // (opacity 0, pointer-events none), which leaves their buttons in the
      // tab order: without this, Tab walks into answers for a scene the
      // visitor cannot see. `inert` is the mechanism the rest of this file
      // uses for exactly that.
      var showGroup = function (group, index) {
        group.items.forEach(function (item) {
          var active = sceneOf(item, group.attr) === index;
          item.classList.toggle('emt-active', active);
          if (group.attr === 'data-emt-content' || group.attr === 'data-emt-faq') {
            if (active) {
              item.removeAttribute('inert');
              item.removeAttribute('aria-hidden');
            } else {
              item.setAttribute('inert', '');
              item.setAttribute('aria-hidden', 'true');
            }
          }
          if (group.attr === 'data-emt-nav') {
            // The active item is the current one in a set of six, which is
            // what aria-current states; it also tells a screen reader why the
            // item is styled differently.
            if (active) {
              item.setAttribute('aria-current', 'true');
            } else {
              item.removeAttribute('aria-current');
            }
          }
        });
      };

      var apply = function (index) {
        if (index === current) {
          return;
        }
        // Direction is what the volume's four keyframes are keyed on:
        // travelling up must bring the incoming scene in from above and send
        // the outgoing one down, or a reversed scroll plays the forward
        // animation and the section reads as broken.
        if (index < current) {
          root.classList.add('emt-rev');
        } else {
          root.classList.remove('emt-rev');
        }

        // The outgoing background keeps one class until the next change: it
        // animates away underneath the incoming scene.
        //
        // It has to lose the class when that animation ends, though. The
        // volume's `.emt-bg-prev` rule is `visibility: visible; z-index: 1`
        // with the slide-out animation on top, and nothing ever removed it:
        // the outgoing scene's image stayed visible under the incoming one
        // indefinitely (measured at 2.5s after the click, still
        // `visible`). Between two scenes with any transparency in the
        // incoming art the stale frame shows through, and after a few
        // swipes every scene the visitor has passed through is stacked
        // under the current one. Removing it at animationend — with a
        // timer as the floor, because a reduced-motion or backgrounded tab
        // may never fire that event — leaves the finished state as "the
        // current scene only", which is what the class name says.
        groups[0].items.forEach(function (bg) {
          var scene = sceneOf(bg, 'data-emt-bg');
          bg.classList.toggle('emt-bg-active', scene === index);
          bg.classList.toggle('emt-bg-prev', scene === current && current !== index);
          if (scene === current && current !== index) {
            clearTimeout(bg.__emtPrevTimer);
            var settle = function (event) {
              if (event && event.target !== bg) {
                return;
              }
              bg.removeEventListener('animationend', settle);
              clearTimeout(bg.__emtPrevTimer);
              bg.classList.remove('emt-bg-prev');
            };
            bg.addEventListener('animationend', settle);
            bg.__emtPrevTimer = setTimeout(settle, 900);
          } else {
            clearTimeout(bg.__emtPrevTimer);
            bg.classList.remove('emt-bg-prev');
          }
        });

        showGroup(groups[1], index);
        showGroup(groups[2], index);
        showGroup(groups[3], index);

        current = index;
      };

      var distance = function () {
        return root.offsetHeight - stage.offsetHeight;
      };

      var scrollTopFor = function (index) {
        var top = root.getBoundingClientRect().top + window.pageYOffset;
        var total = distance();
        if (total <= 0) {
          return top;
        }
        // Each scene owns [index/count, (index+1)/count). Aiming a click at the
        // leading edge lands on the previous scene: the browser stores an
        // integer scroll offset, so the edge rounds one pixel short and
        // floor() reports the slice before it. Measured on the home track at
        // 390: Wall Cladding (index 5 of 6) arrived on scene 4. The last slice
        // is therefore the end of the track, which sync already treats as the
        // last scene. Earlier slices are aimed at their midpoint, one pixel
        // inside the leading edge at minimum.
        if (index >= count - 1) {
          return top + total;
        }
        var start = (total * index) / count;
        var end = (total * (index + 1)) / count;
        var inset = Math.max(1, Math.min((end - start) / 2, end - start - 1));
        return top + start + inset;
      };

      var sync = function () {
        var total = distance();
        if (total <= 0) {
          apply(0);
          return;
        }
        var travelled = -root.getBoundingClientRect().top;
        var progress = travelled / total;
        if (progress < 0) {
          progress = 0;
        } else if (progress > 1) {
          progress = 1;
        }
        // The final pixel belongs to the last scene. A scroll that stops one
        // pixel short of the track end — the integer clamp of scrollTo — must
        // not fall back into the previous slice.
        var index = travelled >= total - 1 ? count - 1 : Math.floor(progress * count);
        if (index > count - 1) {
          index = count - 1;
        }
        if (index < 0) {
          index = 0;
        }
        apply(index);
      };

      // Throttled with setTimeout, NOT requestAnimationFrame: rAF does not
      // fire at all in some environments (HeadlessChrome has no compositor),
      // and it also pauses in a background tab. Wrapping sync in rAF meant
      // the scene never changed on scroll — the section read as dead. A
      // timeout runs in every environment, including a backgrounded tab.
      var frame = 0;
      var onScroll = function () {
        if (frame) {
          return;
        }
        frame = window.setTimeout(function () {
          frame = 0;
          sync();
        }, 16);
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      window.addEventListener('resize', onScroll);

      // Sync once at startup. The listener only fires on the next scroll, so
      // a visitor who scrolled while the script was still downloading (169KB
      // on a cold connection) would otherwise sit on the first scene with no
      // way to know the section is interactive until they scroll again —
      // which is exactly the "this whole block is dead" report. The initial
      // pass also lands the aria-current marker on whichever scene the page
      // actually opens on, not necessarily the first.
      sync();

      groups[3].items.forEach(function (button) {
        button.addEventListener('click', function () {
          var index = sceneOf(button, 'data-emt-nav');
          if (index < 0) {
            return;
          }
          // Apply the scene directly, and scroll to it. The scroll alone
          // cannot be relied on to switch the scene: programmatic
          // `window.scrollTo` does not fire a scroll event in every
          // environment (measured 2026-10-27 — the nav click landed on the
          // right offset and the scene still read as the first one, until a
          // manual scroll event was dispatched). Selecting the scene here is
          // what the click means; the scroll is just how the visitor gets
          // there. Reduced motion still jumps.
          apply(index);
          var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          smoothScrollTo(scrollTopFor(index), reduced);
        });
      });

      /* --- FAQ ------------------------------------------------------- *
       * The answer element is the one that animates: its volume rule is
       * max-height:0 with overflow hidden, so the open height has to be
       * measured for the transition to have somewhere to go. The button's
       * aria-expanded is the state a screen reader reads, and it is also what
       * the volume styles the joined corner on. */

      var answerOf = function (wrap) {
        return wrap ? wrap.querySelector('[data-emt-faq-ans]') : null;
      };
      var buttonOf = function (wrap) {
        return wrap ? wrap.querySelector('[data-emt-faq-btn]') : null;
      };

      var setAnswer = function (wrap, open) {
        var button = buttonOf(wrap);
        var answer = answerOf(wrap);
        if (button) {
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (!answer) {
          return;
        }
        if (open) {
          answer.removeAttribute('aria-hidden');
          // scrollHeight is the content's own height, measured while the box is
          // clipped to zero — that is what makes it the height to open to.
          answer.style.maxHeight = answer.scrollHeight + 'px';
        } else {
          answer.style.maxHeight = '';
          answer.setAttribute('aria-hidden', 'true');
        }
      };

      each(root.querySelectorAll('[data-emt-faq-wrap]'), function (wrap) {
        var button = buttonOf(wrap);
        if (!button) {
          return;
        }
        setAnswer(wrap, false);
        button.addEventListener('click', function () {
          var open = button.getAttribute('aria-expanded') === 'true';
          var group = button.closest('[data-emt-faq]') || root;
          // One answer at a time within the group: a second open answer pushes
          // the rest of the list past the bottom of the stage, where it is
          // unreachable.
          each(group.querySelectorAll('[data-emt-faq-wrap]'), function (other) {
            if (other !== wrap) {
              setAnswer(other, false);
            }
          });
          setAnswer(wrap, !open);
        });
      });

      // An open answer's measured height is a snapshot: re-measure on resize,
      // where the copy reflows and the old number would clip the text.
      window.addEventListener('resize', function () {
        each(root.querySelectorAll('[data-emt-faq-btn][aria-expanded="true"]'), function (button) {
          var wrap = button.closest('[data-emt-faq-wrap]');
          var answer = answerOf(wrap);
          if (answer) {
            answer.style.maxHeight = answer.scrollHeight + 'px';
          }
        });
      });

      // The markup marks scene one with the same classes apply() would set, so
      // this first call only records where the page already is.
      sync();
    });
  }

  /* ================================================================== *
   * Shared control primitives
   *
   * The converted pages carry seven vocabulary families for the same four
   * behaviours: a tab strip, an accordion, a horizontal carousel, and a
   * numbered stepper. Every one of them shipped as markup plus a stylesheet
   * state class that nothing ever set, so the controls were drawn and dead.
   *
   * Implementing them one family at a time would have produced seven copies
   * of the same logic, each with its own idea of what "open" means. These
   * three functions are the behaviours; the setup* passes below only say how
   * to read each family's attribute names. The tab strip already had this
   * shape (bindTabSet), so it is reused rather than duplicated.
   * ================================================================== */

  // A horizontal scroll-snap carousel: two arrows, optional dots and a
  // counter. Scrolling is the state, so dragging, wheel and keyboard scrolling
  // all stay in sync with the arrows for free — the same reason the expanded
  // mesh section reads scroll position instead of tracking an index.
  //
  // `step` is measured from the track's own children rather than assumed: the
  // cards differ per page (a 620px proof card, a 300px case card), and a
  // hard-coded distance scrolls to the wrong place on every other page.
  function bindCarousel(track, options) {
    if (!track) {
      return;
    }
    var settings = options || {};

    var step = function () {
      var first = track.firstElementChild;
      if (!first) {
        return track.clientWidth;
      }
      var gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap || '0') || 0;
      return first.getBoundingClientRect().width + gap;
    };

    var pages = function () {
      var card = step();
      return card > 0 ? Math.max(1, Math.round(track.scrollWidth / card)) : 1;
    };

    // The list of scroll stops the dots index into. Rounding drift across a
    // fractional card width would make the last dot unreachable, so the final
    // stop is pinned to the true maximum.
    var maxScroll = function () {
      return Math.max(0, track.scrollWidth - track.clientWidth);
    };

    var current = function () {
      var card = step();
      if (card <= 0) {
        return 0;
      }
      var index = Math.round(track.scrollLeft / card);
      var total = pages();
      return Math.min(Math.max(index, 0), total - 1);
    };

    // "At the start" and "at the end" are geometric facts, not scrollLeft == 0.
    // A snap container with `padding-left` settles at scrollLeft = padding on
    // load (measured: 25px on the home proof strip), so comparing against zero
    // left the previous arrow enabled on a strip that could not go back. The
    // honest test is where the first and last cards actually are relative to
    // the track's own box.
    var atStart = function () {
      var first = track.firstElementChild;
      if (!first || maxScroll() <= 0) {
        return true;
      }
      return first.getBoundingClientRect().left >= track.getBoundingClientRect().left - 2;
    };

    var atEnd = function () {
      var last = track.lastElementChild;
      if (!last || maxScroll() <= 0) {
        return true;
      }
      return last.getBoundingClientRect().right <= track.getBoundingClientRect().right + 2;
    };

    var announce = function () {
      var index = current();
      if (settings.counter) {
        settings.counter.textContent = String(index + 1);
      }
      if (settings.dots) {
        each(settings.dots, function (dot, i) {
          var active = i === index;
          dot.setAttribute('data-active', active ? 'true' : 'false');
          dot.setAttribute('aria-current', active ? 'true' : 'false');
        });
      }
      // An arrow at the end of its travel says so rather than looking
      // pressable and doing nothing.
      if (settings.prev) {
        settings.prev.disabled = atStart();
      }
      if (settings.next) {
        settings.next.disabled = atEnd();
      }
    };

    var goTo = function (index) {
      var card = step();
      var total = pages();
      var target = Math.min(Math.max(index, 0), total - 1);
      var left = target * card;
      if (target === total - 1) {
        left = maxScroll();
      }
      var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      // scrollTo with behaviour is the one API that moves a snap container to a
      // position the snap points actually agree with.
      if (typeof track.scrollTo === 'function') {
        track.scrollTo({ left: left, behavior: reduced ? 'auto' : 'smooth' });
      } else {
        track.scrollLeft = left;
      }
    };

    var step_by = function (direction) {
      goTo(current() + direction);
    };

    if (settings.prev) {
      settings.prev.addEventListener('click', function () {
        step_by(-1);
      });
    }
    if (settings.next) {
      settings.next.addEventListener('click', function () {
        step_by(1);
      });
    }
    if (settings.dots) {
      each(settings.dots, function (dot, i) {
        dot.addEventListener('click', function () {
          goTo(i);
        });
      });
    }

    // One recomputation per frame, for the same reason the mesh section uses
    // one: scroll fires continuously and announce() is cheap but not free.
    var frame = 0;
    track.addEventListener('scroll', function () {
      if (frame) {
        return;
      }
      frame = window.setTimeout(function () {
        frame = 0;
        announce();
      }, 16);
    }, { passive: true });
    window.addEventListener('resize', announce);

    // Keyboard: the track is a scroll container, so Left/Right already work
    // natively once it has focus. Focusability is what the markup is missing.
    if (!track.hasAttribute('tabindex') && track.tabIndex < 0) {
      track.tabIndex = 0;
    }
    if (settings.label && !track.hasAttribute('aria-label') && !track.hasAttribute('aria-labelledby')) {
      track.setAttribute('aria-label', settings.label);
    }

    announce();
  }

  // An accordion: one trigger, one body, and a class the stylesheet animates
  // on. `single` keeps one item open per group (used where a second open item
  // would push the list out of its box).
  //
  // The body height is measured, never fixed: the volume for one of these
  // groups caps the open body at a fixed 640px, which clips the longer answers.
  function bindAccordion(items, options) {
    var settings = options || {};
    var openClass = settings.openClass || 'is-open';
    var triggerSelector = settings.trigger || 'button';
    var bodySelector = settings.body || null;

    var bodyOf = function (item) {
      if (bodySelector) {
        return item.querySelector(bodySelector);
      }
      var trigger = item.querySelector(triggerSelector);
      return trigger ? trigger.nextElementSibling : null;
    };

    var setOpen = function (item, open, animate) {
      var trigger = item.querySelector(triggerSelector);
      var body = bodyOf(item);
      item.classList.toggle(openClass, open);
      if (trigger) {
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      if (!body) {
        return;
      }
      if (open) {
        body.removeAttribute('aria-hidden');
        body.removeAttribute('inert');
        if (animate === false) {
          body.style.maxHeight = 'none';
        } else {
          body.style.maxHeight = body.scrollHeight + 'px';
        }
      } else {
        // Collapsing from `none` needs one frame at the measured height first,
        // or the transition has no start value and the body snaps shut.
        if (body.style.maxHeight === 'none') {
          body.style.maxHeight = body.scrollHeight + 'px';
          // Force the start value to be read before the transition begins.
          void body.offsetHeight;
        }
        body.style.maxHeight = '';
        body.setAttribute('aria-hidden', 'true');
        body.setAttribute('inert', '');
      }
    };

    each(items, function (item) {
      var trigger = item.querySelector(triggerSelector);
      if (!trigger) {
        return;
      }
      // The markup's own open state wins on first paint.
      var initiallyOpen = item.classList.contains(openClass)
        || trigger.getAttribute('aria-expanded') === 'true';
      setOpen(item, initiallyOpen, false);

      trigger.addEventListener('click', function () {
        var willOpen = !item.classList.contains(openClass);
        if (willOpen && settings.single) {
          each(items, function (other) {
            if (other !== item && other.classList.contains(openClass)) {
              setOpen(other, false);
            }
          });
        }
        setOpen(item, willOpen);
      });
    });

    // Re-measure open bodies when the layout changes: the height recorded at
    // open time clips the text once the copy reflows.
    if (settings.remeasure !== false) {
      window.addEventListener('resize', function () {
        each(items, function (item) {
          if (!item.classList.contains(openClass)) {
            return;
          }
          var body = bodyOf(item);
          if (body && body.style.maxHeight !== 'none') {
            body.style.maxHeight = body.scrollHeight + 'px';
          }
        });
      });
    }
  }

  // Home: the "why leading projects choose us" proof carousel. The markup
  // ships four cards, two arrows, four dots and a "1 / 4" counter, all inert.
  function setupOutcomesCarousel() {
    var track = document.querySelector('[data-home-outcomes-track]');
    if (!track) {
      return;
    }
    var dotsWrap = document.querySelector('[data-home-outcomes-dots]');
    bindCarousel(track, {
      prev: document.querySelector('[data-home-outcomes-prev]'),
      next: document.querySelector('[data-home-outcomes-next]'),
      dots: dotsWrap ? dotsWrap.querySelectorAll('[data-home-outcomes-dot]') : null,
      counter: document.querySelector('[data-home-outcomes-current]'),
      label: 'Project proof'
    });
  }

  // The family pages' case showcase: one track, two arrows, no dots. Six pages
  // carry this section and none of them bound the arrows.
  function setupShowcaseCarousels() {
    each(document.querySelectorAll('[data-showcase-track]'), function (track) {
      // The arrows are the track's siblings inside the section, and the
      // page-level selector would otherwise pair the first pair with every
      // track.
      var section = track.closest('section') || document;
      var scope = section;
      bindCarousel(track, {
        prev: scope.querySelector('[data-showcase-direction="-1"]'),
        next: scope.querySelector('[data-showcase-direction="1"]'),
        label: track.getAttribute('aria-label') || 'Project showcase'
      });
    });
  }

  // The project-proof strip on the catalog pages (series-archive, shop): the
  // same shape with its own attribute names.
  function setupProjectProofCarousels() {
    each(document.querySelectorAll('[data-ppp-track]'), function (track) {
      var scope = track.closest('section') || document;
      bindCarousel(track, {
        prev: scope.querySelector('[data-ppp-direction="-1"]'),
        next: scope.querySelector('[data-ppp-direction="1"]'),
        label: 'Project proof'
      });
    });
  }

  // The expanded-metal material accordion. The volume caps the open body at a
  // fixed 640px, so these bodies are set to `none` while open — the content is
  // taller than that on a narrow screen and would be clipped.
  function setupMaterialAccordion() {
    each(document.querySelectorAll('[data-emf-acc]'), function (item) {
      var body = item.querySelector('.emf-acc-body');
      var trigger = item.querySelector('.emf-acc-trigger');
      if (!body || !trigger) {
        return;
      }
      // The image panel this material owns is paired by index, and the volume
      // already has an is-active state for it.
      var index = item.getAttribute('data-emf-acc');
      var panel = document.querySelector('[data-emf-img="' + index + '"]');

      var setOpen = function (open) {
        item.classList.toggle('emf-acc-open', open);
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
          body.removeAttribute('aria-hidden');
          // `none` rather than the measured height: the volume's 640px cap is
          // shorter than these bodies on a phone, and a measured px value goes
          // stale the moment the swatch row reflows.
          body.style.maxHeight = 'none';
        } else {
          body.style.maxHeight = '';
          body.setAttribute('aria-hidden', 'true');
        }
        if (panel) {
          panel.classList.toggle('emf-active', open);
        }
      };

      setOpen(item.classList.contains('emf-acc-open'));

      trigger.addEventListener('click', function () {
        var willOpen = !item.classList.contains('emf-acc-open');
        // One material open at a time: the image column shows the open one, and
        // two open bodies would disagree with it.
        each(document.querySelectorAll('[data-emf-acc]'), function (other) {
          if (other === item) {
            return;
          }
          var otherBody = other.querySelector('.emf-acc-body');
          var otherTrigger = other.querySelector('.emf-acc-trigger');
          other.classList.remove('emf-acc-open');
          if (otherTrigger) {
            otherTrigger.setAttribute('aria-expanded', 'false');
          }
          if (otherBody) {
            otherBody.style.maxHeight = '';
            otherBody.setAttribute('aria-hidden', 'true');
          }
          var otherPanel = document.querySelector('[data-emf-img="' + other.getAttribute('data-emf-acc') + '"]');
          if (otherPanel) {
            otherPanel.classList.remove('emf-active');
          }
        });
        setOpen(willOpen);
      });
    });
  }

  // The tab families. Each is the same WAI-ARIA strip over a different
  // vocabulary, so each one is a call to the engine setupTabs already uses —
  // not a new implementation.
  //
  // The common thread in every family: the volumes style an `active`/`data-active`
  // state and the markup ships either nothing or a single hard-coded first tab,
  // so the strip rendered and did not respond.
  function setupDeadTabFamilies() {
    // Expanded-metal "Side by Side / Method Detail" switch.
    bindSwitchable(document.querySelectorAll('[data-evp-switch]'), document.querySelectorAll('[data-evp-panel]'), 'data-evp-switch', 'data-evp-panel');

    // The method-detail strip inside that panel.
    bindSwitchable(document.querySelectorAll('[data-evp-tab]'), document.querySelectorAll('[data-evp-tab-content]'), 'data-evp-tab', 'data-evp-tab-content');

    // Manufacturing: the same two-level switch with its own names.
    bindSwitchable(document.querySelectorAll('[data-mfg-spec-switch]'), document.querySelectorAll('[data-mfg-spec-panel]'), 'data-mfg-spec-switch', 'data-mfg-spec-panel');
    bindSwitchable(document.querySelectorAll('[data-mfg-spec-tab]'), document.querySelectorAll('[data-mfg-spec-tab-content]'), 'data-mfg-spec-tab', 'data-mfg-spec-tab-content');

    // Manufacturing techniques: a nav rail, a copy block and a background per
    // item — three parallel groups keyed by the same 0-based index. Each group
    // names its own attribute and its own state class; nothing is inferred from
    // the element's other data attributes.
    bindIndexedGroups(
      document.querySelectorAll('[data-mfg-tech-nav]'),
      'data-mfg-tech-nav',
      'active',
      [
        { items: document.querySelectorAll('[data-mfg-tech-content]'), attr: 'data-mfg-tech-content', activeClass: 'mfg-tech-active' },
        { items: document.querySelectorAll('[data-mfg-tech-bg]'), attr: 'data-mfg-tech-bg', activeClass: 'mfg-tech-bg-active' }
      ]
    );

    // Stainless: the four project tabs (1-based) switch the four project panels
    // (1-based) — a plain pair.
    bindSwitchable(document.querySelectorAll('[data-sps-tab]'), document.querySelectorAll('[data-sps-panel]'), 'data-sps-tab', 'data-sps-panel');

    // Stainless: the story rows below the panel are their own accordion — the
    // trigger opens its row and the matching scene image, 0-based.
    bindIndexedGroups(
      document.querySelectorAll('[data-sps-trigger]'),
      'data-sps-trigger',
      'is-active',
      [
        { items: document.querySelectorAll('[data-sps-row]'), attr: 'data-sps-row', activeClass: 'is-active' },
        { items: document.querySelectorAll('[data-sps-scene]'), attr: 'data-sps-scene', activeClass: 'is-active' }
      ]
    );

    // Series archive: the pattern-guide pagination.
    bindSwitchable(document.querySelectorAll('[data-pag-tab]'), document.querySelectorAll('[data-pag-panel]'), 'data-pag-tab', 'data-pag-panel');

    // Laser-cut panels: the method tabs. Their container has no hook class of
    // its own (only a tablist role inside a rounded panel), so the pair is
    // found by looking for the strip that owns `data-target` buttons.
    each(document.querySelectorAll('[role="tablist"]'), function (list) {
      var triggers = list.querySelectorAll('.tab-btn[data-target]');
      if (!triggers.length) {
        return;
      }
      var scope = list.parentElement || document;
      var panes = scope.querySelectorAll('.panel[data-panel]');
      if (!panes.length) {
        return;
      }
      bindTabSet(triggers, panes, {
        list: list,
        activeClass: 'active',
        nameOf: function (trigger) {
          return trigger.getAttribute('data-target');
        },
        paneNameOf: function (pane) {
          return pane.getAttribute('data-panel');
        },
        initial: list.querySelector('.tab-btn.active') || triggers[0]
      });
    });
  }

  // Two control groups paired by a shared value: the clicked control's own
  // attribute value must equal the panel's. `hidden` is the state — the volumes
  // already style `[data-…-panel][hidden]` — so nothing else has to be invented.
  //
  // Panels are paired per region, not document-wide. Two unrelated blocks may
  // reuse the same attribute names with different value bases — the stainless
  // page's tab strip counts from 1 and its story accordion from 0 — and one
  // merged group then decides every panel by the clicked tab's value: the
  // accordion's open panel matches no tab and is hidden while its row still
  // says it is open, an accordion that opens onto nothing. A control therefore
  // owns only the panels inside its own nearest enclosing block.
  function bindSwitchable(controls, panels, controlAttr, panelAttr) {
    if (!controls.length || !panels.length) {
      return;
    }
    var pool = [];
    each(panels, function (panel) {
      pool.push(panel);
    });

    var regionOf = function (control) {
      var node = control.parentElement;
      while (node && node !== document.body) {
        for (var i = 0; i < pool.length; i++) {
          if (node.contains(pool[i])) {
            return node;
          }
        }
        node = node.parentElement;
      }
      return document.body;
    };

    var groups = [];
    each(controls, function (control) {
      var region = regionOf(control);
      var group = null;
      for (var i = 0; i < groups.length; i++) {
        if (groups[i].region === region) {
          group = groups[i];
          break;
        }
      }
      if (!group) {
        group = { region: region, controls: [] };
        groups.push(group);
      }
      group.controls.push(control);
    });

    each(groups, function (group) {
      var owned = [];
      each(pool, function (panel) {
        if (group.region.contains(panel)) {
          owned.push(panel);
        }
      });
      bindSwitchableGroup(group.controls, owned, controlAttr, panelAttr);
    });
  }

  function bindSwitchableGroup(controls, panels, controlAttr, panelAttr) {
    if (!controls.length || !panels.length) {
      return;
    }
    var apply = function (value) {
      each(controls, function (control) {
        var active = control.getAttribute(controlAttr) === value;
        if (active) {
          control.setAttribute('data-active', '');
        } else {
          control.removeAttribute('data-active');
        }
        control.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      each(panels, function (panel) {
        panel.hidden = panel.getAttribute(panelAttr) !== value;
      });
    };

    each(controls, function (control) {
      var label = control.getAttribute('role');
      if (!label) {
        control.setAttribute('role', 'tab');
      }
      if (!control.hasAttribute('tabindex')) {
        control.tabIndex = 0;
      }
      control.addEventListener('click', function () {
        apply(control.getAttribute(controlAttr));
      });
      control.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
          event.preventDefault();
          apply(control.getAttribute(controlAttr));
        }
      });
    });

    var initial = null;
    each(controls, function (control) {
      if (!initial && control.hasAttribute('data-active')) {
        initial = control;
      }
    });
    if (!initial) {
      initial = controls[0];
    }
    apply(initial.getAttribute(controlAttr));
  }

  // One control group and N display groups that all switch together on the
  // same index.
  //
  // Every group names its own attribute and its own state class: the three
  // families in this page reuse `active`, `is-active` and `mfg-tech-active`
  // for the same idea, and inferring which data attribute identifies an item
  // (by scanning for a numeric value) paired `data-sps-row` with
  // `data-sps-scene` on the wrong items. The caller states the contract.
  function bindIndexedGroups(controls, controlAttr, controlClass, groups) {
    if (!controls.length) {
      return;
    }

    var keyOf = function (element, attr) {
      return element ? element.getAttribute(attr) : null;
    };

    var apply = function (value, options) {
      each(controls, function (control) {
        var active = keyOf(control, controlAttr) === value;
        if (controlClass) {
          control.classList.toggle(controlClass, active);
        }
        if (active) {
          control.setAttribute('aria-current', 'true');
        } else {
          control.removeAttribute('aria-current');
        }
        if (control.hasAttribute('aria-expanded')) {
          control.setAttribute('aria-expanded', active ? 'true' : 'false');
        }
      });
      each(groups, function (group) {
        each(group.items, function (item) {
          var active = keyOf(item, group.attr) === value;
          if (group.activeClass) {
            item.classList.toggle(group.activeClass, active);
          }
          if (group.hide) {
            item.hidden = !active;
          }
          if (group.announce) {
            item.setAttribute('aria-hidden', active ? 'false' : 'true');
          }
        });
      });
    };

    var selected = null;
    each(controls, function (control) {
      if (selected !== null) {
        return;
      }
      if (control.getAttribute('aria-expanded') === 'true'
        || (controlClass && control.classList.contains(controlClass))) {
        selected = control.getAttribute(controlAttr);
      }
    });
    if (selected === null && controls[0]) {
      selected = controls[0].getAttribute(controlAttr);
    }
    apply(selected);

    each(controls, function (control) {
      if (!control.hasAttribute('tabindex') && control.tabIndex < 0) {
        control.tabIndex = 0;
      }
      var activate = function () {
        var value = control.getAttribute(controlAttr);
        var isOpen = control.getAttribute('aria-expanded') === 'true'
          || (controlClass && control.classList.contains(controlClass));
        // A control that carries aria-expanded is an accordion trigger: its
        // second click closes the row it opened.
        if (control.hasAttribute('aria-expanded') && isOpen) {
          return;
        }
        apply(value);
      };
      control.addEventListener('click', activate);
      control.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
          event.preventDefault();
          activate();
        }
      });
    });
  }

  // A spec table whose headers advertise sorting: `th[data-sort]` over rows
  // that carry `data-original-order` so the original sequence can be restored.
  // The volume ships the SVG arrows and a `.sort-ic` class but no behaviour, so
  // the arrows pointed at nothing.
  function setupSpecTableSort() {
    each(document.querySelectorAll('table.spec-table, table[data-spec-table]'), function (table) {
      var headers = [].slice.call(table.querySelectorAll('th[data-sort]'));
      var body = table.querySelector('tbody');
      if (!headers.length || !body) {
        return;
      }
      var rows = [].slice.call(body.querySelectorAll('tr'));
      var direction = 1;
      var current = null;

      var sortBy = function (header) {
        var key = header.getAttribute('data-sort');
        // Clicking the current header reverses it; a different header starts
        // ascending. There is no third state: the authored order is what the
        // section ships, and a visitor who wants it back reloads.
        direction = current === key && direction === 1 ? -1 : 1;
        var column = headers.indexOf(header);

        var sorted = rows.slice().sort(function (a, b) {
          var left = (a.children[column] || {}).textContent || '';
          var right = (b.children[column] || {}).textContent || '';
          var leftNumber = parseFloat(left.replace(/[^0-9.\-]/g, ''));
          var rightNumber = parseFloat(right.replace(/[^0-9.\-]/g, ''));
          // Numeric when both cells lead with a number, textual otherwise: a
          // "0.8 — 12.0 mm" range and a "Sheet or coil format" note live in the
          // same table.
          if (!isNaN(leftNumber) && !isNaN(rightNumber)) {
            return (leftNumber - rightNumber) * direction;
          }
          return left.trim().localeCompare(right.trim()) * direction;
        });

        sorted.forEach(function (row) {
          body.appendChild(row);
        });

        each(headers, function (other) {
          var sortedThis = other === header;
          other.setAttribute('aria-sort', sortedThis
            ? (direction === 1 ? 'ascending' : 'descending')
            : 'none');
          if (other !== header) {
            other.removeAttribute('data-sorted');
          }
        });
        header.setAttribute('data-sorted', direction === 1 ? 'asc' : 'desc');
        current = key;
      };

      each(headers, function (header, index) {
        header.setAttribute('tabindex', '0');
        header.setAttribute('role', 'button');
        header.setAttribute('aria-sort', 'none');
        var activate = function () {
          sortBy(header);
        };
        header.addEventListener('click', activate);
        header.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
            event.preventDefault();
            activate();
          }
        });
        if (index === 0) {
          // No default sort: the table is authored in the order the section
          // wants read.
        }
      });
    });
  }

  // The blog article's reading map: names the share links for screen
  // readers, opens the TOC disclosure at desktop widths, and builds the
  // section list from the article's own headings.
  //
  // This ran as an inline `<script>` inside the LiveCanvas template, which the
  // site's CSP blocks (`script-src` carries no `'unsafe-inline'`) — every
  // article paid for a console error and got none of the behaviour: zero
  // sections in the map, zero share links with a name (measured 2026-10-27 at
  // both 1440 and 390). The behaviour belongs in this file by the site's own
  // ruling, not in the template.
  function setupBlogArticleMap() {
    var root = document.getElementById('md-blog-single');
    if (!root) {
      return;
    }

    each(root.querySelectorAll('.reader-share-links a'), function (link) {
      var name = (link.textContent || '').trim();
      if (name) {
        link.setAttribute('aria-label', name);
        link.setAttribute('title', name);
      }
    });

    var disclosure = root.querySelector('.reader-toc');
    var desktop = window.matchMedia('(min-width:1000px)');
    if (disclosure) {
      var sizeDisclosure = function () {
        disclosure.open = desktop.matches;
      };
      sizeDisclosure();
      if (desktop.addEventListener) {
        desktop.addEventListener('change', sizeDisclosure);
      }
    }

    var content = root.querySelector('.md-blog-single-content');
    var map = root.querySelector('.md-blog-single-map-nav');
    var first = map && map.querySelector('a[href="#md-blog-single-body"]');
    var relatedLink = map && map.querySelector('a[href="#md-blog-single-related"]');
    if (relatedLink && !root.querySelector('.md-blog-single-related-card')) {
      relatedLink.remove();
    }
    if (!content || !first) {
      return;
    }

    var headings = [].filter.call(content.querySelectorAll('h2'), function (heading) {
      return !heading.closest('.md-inquiry-cta');
    });

    var previous = first;
    headings.forEach(function (heading, index) {
      if (!heading.id) {
        var base = 'md-article-section-' + (index + 1);
        var id = base;
        var suffix = 2;
        while (document.getElementById(id)) {
          id = base + '-' + suffix++;
        }
        heading.id = id;
      }

      var link = document.createElement('a');
      link.className = 'md-blog-single-map-section';
      link.href = '#' + heading.id;

      var line = document.createElement('span');
      line.className = 'md-blog-single-map-line';
      line.setAttribute('aria-hidden', 'true');

      var label = document.createElement('span');
      label.textContent = (heading.textContent || '').trim();

      link.appendChild(line);
      link.appendChild(label);
      if (previous.insertAdjacentElement) {
        previous.insertAdjacentElement('afterend', link);
      }
      previous = link;
    });

    var markCurrent = function () {
      each(map.querySelectorAll('a[href^="#"]'), function (link) {
        if (link.hash === window.location.hash && link.hash !== '') {
          link.setAttribute('aria-current', 'location');
        } else {
          link.removeAttribute('aria-current');
        }
      });
    };
    markCurrent();
    window.addEventListener('hashchange', markCurrent);
  }

  // Laser-cut panels' FAQ: category tabs, a text filter, collapsible groups and
  // per-question answers. All four were drawn and none of them worked.
  function setupFaqFilter() {
    each(document.querySelectorAll('#laser-cut-panels-faq-template--21844033995005__laser-cut-panels-faq, [data-md-faq-filter]'), function (root) {
      var tabs = [].slice.call(root.querySelectorAll('.cat-tab[data-cat]'));
      var groups = [].slice.call(root.querySelectorAll('.faq-group[data-cat]'));
      var search = root.querySelector('input[id$="-faq-search"]');
      var items = [].slice.call(root.querySelectorAll('.faq-item[data-q]'));
      if (!tabs.length && !search) {
        return;
      }

      var activeCat = 'all';
      var query = '';

      var apply = function () {
        var shown = 0;
        groups.forEach(function (group) {
          var groupCat = group.getAttribute('data-cat');
          var catMatch = activeCat === 'all' || groupCat === activeCat;
          var groupShown = 0;
          each(group.querySelectorAll('.faq-item[data-q]'), function (item) {
            var haystack = (item.getAttribute('data-q') || '').toLowerCase();
            // The visible question text matters too: data-q is a keyword list,
            // and a visitor searching what they can read must find it.
            var label = (item.textContent || '').toLowerCase();
            var textMatch = !query || haystack.indexOf(query) > -1 || label.indexOf(query) > -1;
            var visible = catMatch && textMatch;
            item.hidden = !visible;
            if (visible) {
              groupShown += 1;
            }
          });
          var groupVisible = groupShown > 0;
          group.hidden = !groupVisible;
          if (groupVisible) {
            shown += groupShown;
          }
        });

        var empty = root.querySelector('[data-md-faq-empty]');
        if (empty) {
          empty.hidden = shown > 0;
        }
        return shown;
      };

      // The tab highlight must tell the truth about what the list shows. The
      // visual state on faqs.html lives in the Alpine component
      // (activeFaqCategory drives the x-bind classes), while aria-pressed and
      // .active are this filter's own; a search narrows the groups without
      // touching any of them, so the old tab kept announcing a category the
      // list no longer showed. cat is the category to highlight, or null when
      // no single category owns the visible list — which is the explicit
      // "all" tab on the faces that ship one (laser-cut-panels), and no
      // highlight on the faces that do not (faqs).
      var syncTabs = function (cat) {
        if (cat === null) {
          each(tabs, function (other) {
            if (other.getAttribute('data-cat') === 'all') {
              cat = 'all';
            }
          });
        }
        each(tabs, function (other) {
          var on = cat !== null && other.getAttribute('data-cat') === cat;
          other.classList.toggle('active', on);
          other.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var scope = window.Alpine && window.Alpine.$data && tabs.length
          ? window.Alpine.$data(tabs[0])
          : null;
        if (scope && typeof scope.activeFaqCategory !== 'undefined') {
          scope.activeFaqCategory = cat || '';
        }
      };

      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          activeCat = tab.getAttribute('data-cat');
          syncTabs(activeCat);
          apply();
        });
        tab.setAttribute('aria-pressed', tab.classList.contains('active') ? 'true' : 'false');
      });

      // Run the filter once at startup. The groups ship *without* `hidden` so
      // a visitor without scripts can read all eight (before this, seven of
      // them were hidden in the markup itself and stayed hidden forever —
      // measured 2026-10-27: 2,919 characters with scripts, 31,656 without).
      // Start wide: every category shown, no tab filtered in. The markup's
      // first tab carries `active` for its own styling, and honouring it as a
      // *filter* hid seven of the eight groups on load — a page whose job is
      // answering questions opened showing one eighth of them (measured
      // 2026-10-27: 7 groups hidden, 1 visible). Narrowing is the visitor's
      // action, so it starts from "all".
      activeCat = 'all';
      apply();
      syncTabs(null);

      if (search) {
        search.addEventListener('input', function () {
          query = (search.value || '').trim().toLowerCase();
          apply();
          if (!query) {
            // Back to the tab the visitor chose (nothing when the list is
            // unfiltered, which shows every group at once).
            syncTabs(activeCat === 'all' ? null : activeCat);
            return;
          }
          var visible = [];
          groups.forEach(function (group) {
            if (!group.hidden && visible.indexOf(group.getAttribute('data-cat')) === -1) {
              visible.push(group.getAttribute('data-cat'));
            }
          });
          syncTabs(visible.length === 1 ? visible[0] : null);
        });
      }

      // The group headers (Drawing & Design, …) open and close their body.
      each(root.querySelectorAll('.faq-group'), function (group) {
        var bodyEl = group.querySelector('.group-body');
        var header = group.querySelector('.group-header')
          || (group.firstElementChild && group.firstElementChild.tagName === 'BUTTON' ? group.firstElementChild : null);
        if (!header || !bodyEl) {
          return;
        }
        var setOpen = function (open) {
          bodyEl.hidden = !open;
          header.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        // Groups ship open: the section reads as a list of answers, and a
        // collapsed group would hide every question behind a second click.
        setOpen(true);
        header.addEventListener('click', function () {
          setOpen(bodyEl.hidden);
        });
      });

      // Per-question answers.
      each(items, function (item) {
        var question = item.querySelector('.question');
        var answer = item.querySelector('.answer');
        if (!question || !answer) {
          return;
        }
        answer.hidden = true;
        question.setAttribute('aria-expanded', 'false');
        question.addEventListener('click', function () {
          var open = answer.hidden;
          // One answer at a time within a group: a long answer would push the
          // rest of the list out of view.
          each(item.parentElement.querySelectorAll('.faq-item'), function (other) {
            if (other === item) {
              return;
            }
            var otherAnswer = other.querySelector('.answer');
            var otherQuestion = other.querySelector('.question');
            if (otherAnswer) {
              otherAnswer.hidden = true;
            }
            if (otherQuestion) {
              otherQuestion.setAttribute('aria-expanded', 'false');
            }
          });
          answer.hidden = !open;
          question.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
      });
    });
  }

  // Perforated panels' application scope: four markers over an open-area rail,
  // each owning a background image, a copy panel and (on a phone) a detail
  // panel. The section also carries an open-area readout that follows the
  // marker's own `data-pa-position`.
  function setupPerforatedMarkers() {
    each(document.querySelectorAll('[data-pa-marker]'), function (marker) {
      var index = marker.getAttribute('data-pa-marker');
      var scope = marker.closest('section') || document;
      var bgs = [].slice.call(scope.querySelectorAll('[data-pa-bg-panel]'));
      var panels = [].slice.call(scope.querySelectorAll('[data-pa-panel]'));
      var mobile = [].slice.call(scope.querySelectorAll('[data-pa-mobile-panel]'));
      var railFill = scope.querySelector('[data-pa-rail-fill]');
      var readout = scope.querySelector('[data-pa-oa-value]');

      var apply = function () {
        each(scope.querySelectorAll('[data-pa-marker]'), function (other) {
          var active = other === marker;
          other.setAttribute('aria-pressed', active ? 'true' : 'false');
          other.classList.toggle('is-active', active);
        });
        each(bgs, function (bg) {
          var active = bg.getAttribute('data-pa-bg-panel') === index;
          bg.classList.toggle('opacity-100', active);
          bg.classList.toggle('opacity-0', !active);
        });
        each(panels, function (panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-pa-panel') === index);
          panel.hidden = panel.getAttribute('data-pa-panel') !== index;
        });
        each(mobile, function (panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-pa-mobile-panel') === index);
          panel.hidden = panel.getAttribute('data-pa-mobile-panel') !== index;
        });

        // The open-area readout and the rail fill both come from the marker's
        // own declared position, so they cannot drift from each other.
        var percent = marker.getAttribute('data-pa-position');
        if (railFill && percent) {
          railFill.style.width = percent + '%';
        }
        if (readout && percent) {
          readout.textContent = percent + '%';
        }
      };

      marker.addEventListener('click', apply);
    });

    // First marker is the authored default.
    var first = document.querySelector('[data-pa-marker]');
    if (first) {
      first.click();
    }
  }

  // The before/after split comparison. `data-pa-slider` is the handle, the
  // masked half's width is the reveal, and the drag is pointer-only because a
  // range input is not in this markup.
  //
  // The split is measured against the panel that actually holds the mask, not
  // the whole section: the section is full width but the comparison lives in
  // the left half of it, so a ratio taken from the section put the handle at
  // half the distance the pointer had travelled (measured: dragging to 80% of
  // the section produced a 0.5 split).
  function setupCompareDrag() {
    each(document.querySelectorAll('[data-pa-slider]'), function (slider) {
      var scope = slider.closest('[data-pa-left-panel]')
        || slider.closest('section')
        || slider.parentElement;
      if (!scope) {
        return;
      }
      var masked = scope.querySelector('[data-pa-masked-left]');
      var mask = scope.querySelector('[data-pa-mask]');
      if (!masked || !mask) {
        return;
      }

      var set = function (ratio) {
        var clamped = Math.min(1, Math.max(0, ratio));
        var percent = (clamped * 100).toFixed(2) + '%';
        masked.style.width = percent;
        // The handle is positioned by the same percentage. Its `left` is set
        // here rather than in the stylesheet because this is the value that
        // must match the mask exactly; the stylesheet owns the box, not the
        // position.
        slider.style.left = percent;
        slider.setAttribute('aria-valuenow', String(Math.round(clamped * 100)));
      };

      var ratioFromEvent = function (event) {
        var box = scope.getBoundingClientRect();
        if (!box.width) {
          return 0.5;
        }
        var x = (event.touches && event.touches[0] ? event.touches[0].clientX : event.clientX) - box.left;
        return x / box.width;
      };

      var dragging = false;
      var start = function (event) {
        dragging = true;
        set(ratioFromEvent(event));
        event.preventDefault();
      };
      var move = function (event) {
        if (!dragging) {
          return;
        }
        set(ratioFromEvent(event));
      };
      var end = function () {
        dragging = false;
      };

      slider.addEventListener('pointerdown', start);
      // The move and release listeners live on the window: the pointer leaves
      // the 4px handle immediately, and a listener on the handle would stop
      // receiving events at that moment.
      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', end);
      window.addEventListener('pointercancel', end);

      // Keyboard: the handle is a slider, so the arrow keys must move it.
      slider.setAttribute('tabindex', '0');
      slider.setAttribute('role', 'slider');
      slider.setAttribute('aria-valuemin', '0');
      slider.setAttribute('aria-valuemax', '100');
      slider.addEventListener('keydown', function (event) {
        var box = scope.getBoundingClientRect();
        var current = masked.getBoundingClientRect().width / (box.width || 1);
        if (event.key === 'ArrowLeft') {
          event.preventDefault();
          set(current - 0.05);
        } else if (event.key === 'ArrowRight') {
          event.preventDefault();
          set(current + 0.05);
        } else if (event.key === 'Home') {
          event.preventDefault();
          set(0);
        } else if (event.key === 'End') {
          event.preventDefault();
          set(1);
        }
      });

      set(0.5);
    });
  }

  // The manufacturing quality document stack: four cards dealt into
  // is-active / is-next / is-prev / is-hidden positions, with a caption, four
  // dots and two arrows.
  //
  // The card positions are the volume's vocabulary (a "deck" of documents), so
  // this is a stepper, not a scroller: the index is the state and each card
  // gets the class its offset from the current index implies.
  function setupQualityDeck() {
    var root = document.querySelector('[data-mfg-quality]');
    if (!root) {
      return;
    }
    var cards = [].slice.call(root.querySelectorAll('[data-mfg-quality-card]'));
    var dots = [].slice.call(root.querySelectorAll('[data-mfg-quality-dot]'));
    var caption = root.querySelector('[data-mfg-quality-caption]');
    var prev = root.querySelector('[data-mfg-quality-prev]');
    var next = root.querySelector('[data-mfg-quality-next]');
    if (!cards.length) {
      return;
    }

    var index = 0;
    // The caption content per card is the card's own title block; the caption
    // element is a summary of the current document.
    var captionFor = function (card) {
      var title = card.querySelector('.mfg-quality__doc-title');
      var type = card.querySelector('.mfg-quality__doc-label');
      return {
        title: title ? title.textContent.trim() : '',
        type: type ? type.textContent.trim() : ''
      };
    };

    var apply = function (next_index) {
      var total = cards.length;
      index = ((next_index % total) + total) % total;

      cards.forEach(function (card, i) {
        var offset = (i - index + total) % total;
        card.classList.toggle('is-active', offset === 0);
        card.classList.toggle('is-next', offset === 1);
        card.classList.toggle('is-prev', offset === total - 1);
        card.classList.toggle('is-hidden', offset !== 0 && offset !== 1 && offset !== total - 1);
        card.setAttribute('aria-hidden', offset === 0 ? 'false' : 'true');
        // Only the shown card is reachable: the others are stacked behind it.
        if (offset === 0) {
          card.removeAttribute('inert');
        } else {
          card.setAttribute('inert', '');
        }
      });

      dots.forEach(function (dot, i) {
        var active = i === index;
        dot.classList.toggle('is-active', active);
        dot.setAttribute('aria-current', active ? 'true' : 'false');
      });

      if (caption) {
        var info = captionFor(cards[index]);
        var textNode = null;
        for (var i = 0; i < caption.childNodes.length; i += 1) {
          if (caption.childNodes[i].nodeType === 3 && caption.childNodes[i].textContent.trim()) {
            textNode = caption.childNodes[i];
            break;
          }
        }
        if (textNode) {
          textNode.textContent = info.title;
        } else if (info.title) {
          caption.insertBefore(document.createTextNode(info.title), caption.firstChild);
        }
        var typeEl = caption.querySelector('.mfg-quality__type');
        if (typeEl && info.type) {
          typeEl.textContent = info.type;
        }
      }
    };

    if (prev) {
      prev.addEventListener('click', function () {
        apply(index - 1);
      });
    }
    if (next) {
      next.addEventListener('click', function () {
        apply(index + 1);
      });
    }
    dots.forEach(function (dot, i) {
      dot.addEventListener('click', function () {
        apply(i);
      });
    });

    apply(0);
  }

  // Smooth scroll driven by requestAnimationFrame, not by the browser's
  // `behavior: 'smooth'`: that option silently performs no scroll in some
  // environments (HeadlessChrome has no compositor, so the nav click left
  // scrollY at 0 and the section looked dead). This works everywhere, and it
  // also cancels itself if the visitor takes over the scroll mid-flight.
  function smoothScrollTo(target, reduced) {
    if (reduced || typeof window.requestAnimationFrame !== 'function') {
      window.scrollTo(0, target);
      return;
    }
    var startY = window.pageYOffset || document.documentElement.scrollTop || 0;
    var delta = target - startY;
    if (Math.abs(delta) < 2) {
      window.scrollTo(0, target);
      return;
    }
    var duration = Math.min(900, Math.max(300, Math.abs(delta) * 0.5));
    var cancelled = false;
    var cancel = function () {
      cancelled = true;
    };
    // If the visitor scrolls by hand, stop animating: their input wins.
    window.addEventListener('wheel', cancel, { passive: true, once: true });
    window.addEventListener('touchmove', cancel, { passive: true, once: true });
    var started = Date.now();
    var step = function () {
      if (cancelled) {
        return;
      }
      var progress = Math.min(1, (Date.now() - started) / duration);
      // easeInOutCubic: slow at both ends, so the scene change reads as a
      // transition rather than a jump cut.
      var eased = progress < 0.5
        ? 4 * progress * progress * progress
        : 1 - Math.pow(-2 * progress + 2, 3) / 2;
      window.scrollTo(0, Math.round(startY + delta * eased));
      if (progress < 1) {
        window.setTimeout(step, 16);
      }
    };
    // A timeout, not requestAnimationFrame: rAF does not fire in some
    // environments, which is precisely why the browser's own smooth scroll
    // could not be relied on either.
    window.setTimeout(step, 16);
  }

  // The header's predictive search. Empty state lists the nine product_cat
  // series from the Woo Store API. A query lists up to six pattern tiles from
  // the same API. Both faces clone a template already in the header — the
  // series tile is the search-series-tile partial — so this function fills
  // values and never assembles card markup.
  function setupPredictiveSearchReset() {
    each(document.querySelectorAll('[data-predictive-search-input]'), function (input) {
      var name = input.getAttribute('data-predictive-search-input');
      var reset = document.querySelector('[data-predictive-search-reset="' + name + '"]');
      var results = document.querySelector('[data-predictive-search-result="' + name + '"]');
      if (!reset) {
        return;
      }

      var series = document.querySelector('[data-md-search-series]');
      var seriesTemplate = document.getElementById('md-search-series-tile');
      var hitTemplate = document.getElementById('md-search-hit');
      var seeAll = document.querySelector('[data-md-search-all]');
      var emptyNote = document.querySelector('[data-md-search-empty]');
      var emptyQuery = document.querySelector('[data-md-search-empty-query]');
      var prompt = document.querySelector('[data-md-search-prompt]');
      var timer = null;
      var inflight = null;
      var seriesLoaded = false;

      var decodeText = function (value) {
        var box = document.createElement('textarea');
        box.innerHTML = value || '';
        return box.value;
      };

      var fillTile = function (node, href, src, alt, label) {
        var link = node.querySelector('a') || (node.tagName === 'A' ? node : null);
        var image = node.querySelector('img');
        var name = node.querySelector('.md-search-tile__name, .md-search-hit__name');
        if (link && href) {
          link.href = href;
        }
        if (image && src) {
          image.src = src;
          image.alt = alt || '';
        }
        if (name) {
          name.textContent = decodeText(label);
        }
      };

      // The series tiles carry nine thumbnails. Loading them at boot costs
      // every visitor nine image requests for a panel they may never open —
      // measured at +9 images and roughly 2.8 MB on the home page alone. Load
      // them the first time the search panel actually opens.
      var seriesModal = document.querySelector("xo-modal[xo-name='search']");
      var seriesRequested = false;
      var requestSeriesOnce = function () {
        if (seriesRequested) {
          return;
        }
        seriesRequested = true;
        loadSeries();
      };
      if (seriesModal) {
        var seriesObserver = new MutationObserver(function () {
          if (seriesModal.classList.contains('is-open')) {
            requestSeriesOnce();
          }
        });
        seriesObserver.observe(seriesModal, { attributes: true, attributeFilter: ['class'] });
        if (seriesModal.classList.contains('is-open')) {
          requestSeriesOnce();
        }
      }

      var sync = function () {
        var hasValue = !!(input.value || '').trim();
        reset.hidden = !hasValue;
        if (series) {
          // Nothing to show until the tiles are fetched, so an untouched panel
          // must not reserve an empty list box above the prompt.
          series.hidden = hasValue || !seriesRequested;
        }
        if (prompt) {
          prompt.hidden = hasValue;
        }
        if (results) {
          results.hidden = !hasValue;
        }
        if (!hasValue) {
          if (seeAll) {
            seeAll.hidden = true;
          }
          if (emptyNote) {
            emptyNote.hidden = true;
          }
        }
      };

      var settle = function (query) {
        if (inflight) {
          inflight.abort();
          inflight = null;
        }
        if (!query) {
          if (results) {
            results.textContent = '';
          }
          return;
        }

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        inflight = controller;
        var url = '/wp-json/wc/store/v1/products?per_page=6&search=' + encodeURIComponent(query);

        fetch(url, controller ? { signal: controller.signal } : undefined)
          .then(function (response) {
            if (!response.ok) {
              throw new Error('search ' + response.status);
            }
            return response.json();
          })
          .then(function (rows) {
            if (inflight !== controller) {
              return;
            }
            inflight = null;
            paint(rows, query);
          })
          .catch(function (error) {
            // An aborted request is the normal case: a newer query replaced
            // this one. Anything else stays silent — a search box that throws
            // a red banner because the network hiccuped is worse than one that
            // simply offers the submit path it already has.
            if (error && error.name === 'AbortError') {
              return;
            }
            if (inflight === controller) {
              inflight = null;
              if (results) {
                results.textContent = '';
              }
            }
          });
      };

      var paint = function (rows, query) {
        if (results) {
          results.textContent = '';
        }
        if (seeAll) {
          seeAll.href = '/shop/?pattern_search=' + encodeURIComponent(query);
          seeAll.hidden = false;
        }
        if (!rows || !rows.length || !hitTemplate || !hitTemplate.content) {
          if (emptyNote) {
            emptyNote.hidden = false;
          }
          if (emptyQuery) {
            emptyQuery.textContent = '"' + query + '"';
          }
          return;
        }
        if (emptyNote) {
          emptyNote.hidden = true;
        }

        // Store API already ranked "leaf" with Leaf Vines first. Keep a
        // name-first sort so a body mention cannot outrank a pattern named
        // for the query inside the six rows this panel shows.
        var needle = query.toLowerCase();
        var score = function (row) {
          var title = (row.name || row.slug || '').toLowerCase();
          if (title.indexOf(needle) === 0) {
            return 0;
          }
          return title.indexOf(needle) === -1 ? 2 : 1;
        };
        rows.slice().sort(function (a, b) {
          return score(a) - score(b);
        }).slice(0, 6).forEach(function (row) {
          var node = hitTemplate.content.firstElementChild.cloneNode(true);
          var image = row.images && row.images[0] ? row.images[0] : {};
          fillTile(node, row.permalink, image.thumbnail || image.src, image.alt || row.name, row.name || row.slug);
          results.appendChild(node);
        });
      };

      var loadSeries = function () {
        if (seriesLoaded || !series || !seriesTemplate || !seriesTemplate.content) {
          return;
        }
        seriesLoaded = true;
        fetch('/wp-json/wc/store/v1/products/categories?per_page=20')
          .then(function (response) {
            if (!response.ok) {
              throw new Error('series ' + response.status);
            }
            return response.json();
          })
          .then(function (rows) {
            var order = [
              'pattern-perforated',
              'pattern-geometric',
              'pattern-gradient',
              'pattern-abstract',
              'pattern-organic',
              'pattern-landscape',
              'pattern-vintage-retro',
              'pattern-asian',
              'pattern-moroccan-arabic'
            ];
            var tiles = (rows || []).filter(function (row) {
              return row && row.slug && row.slug.indexOf('pattern-') === 0 && row.permalink;
            });
            tiles.sort(function (a, b) {
              var ai = order.indexOf(a.slug);
              var bi = order.indexOf(b.slug);
              return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
            });
            var seed = seriesTemplate.content.firstElementChild;
            if (!seed) {
              return;
            }
            tiles.forEach(function (row) {
              var node = seed.cloneNode(true);
              var image = row.image || {};
              fillTile(node, row.permalink, image.thumbnail || image.src, image.alt || row.name, row.name);
              series.appendChild(node);
            });
            if (tiles.length) {
              series.hidden = !!(input.value || '').trim();
            }
          })
          .catch(function () {
            seriesLoaded = false;
          });
      };

      reset.addEventListener('click', function (event) {
        event.preventDefault();
        input.value = '';
        if (inflight) {
          inflight.abort();
          inflight = null;
        }
        if (results) {
          results.textContent = '';
        }
        sync();
        input.focus();
      });

      input.addEventListener('input', function () {
        sync();
        if (timer) {
          clearTimeout(timer);
        }
        // 250ms is the threshold where typing a word stops feeling like it
        // waits. Below it the box queries mid-word; above it the pause is
        // perceptible.
        timer = setTimeout(function () {
          timer = null;
          settle((input.value || '').trim());
        }, 250);
      });

      // Suggestions are a layer inside the dialog, not the dialog itself.
      // The first Escape dismisses them and stays in the field; the next one
      // belongs to the shared stack, which closes the sheet and restores the
      // opener. stopPropagation is the layer: the stack listener is on
      // document and would otherwise close the sheet on the same key. The
      // listener is on the modal, not the field — a result link and "See all
      // matches" are tab stops, and Escape from either has to dismiss the
      // list too. Focus returns to the field because hiding the link that
      // held it would otherwise leave it in a hidden subtree.
      var suggestionsOpen = function () {
        return (results && !results.hidden)
          || (seeAll && !seeAll.hidden)
          || (emptyNote && !emptyNote.hidden);
      };
      var dismissSuggestions = function () {
        if (timer) {
          clearTimeout(timer);
          timer = null;
        }
        if (inflight) {
          inflight.abort();
          inflight = null;
        }
        if (results) {
          results.hidden = true;
        }
        if (seeAll) {
          seeAll.hidden = true;
        }
        if (emptyNote) {
          emptyNote.hidden = true;
        }
      };
      if (seriesModal) {
        seriesModal.addEventListener('keydown', function (event) {
          if (event.key !== 'Escape' || !suggestionsOpen()) {
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          dismissSuggestions();
          input.focus({ preventScroll: true });
        });
      }

      sync();
    });
  }

  // The header's Resources disclosure. It carries a sub-menu (FAQs, the blog)
  // but no page of its own, and it shipped as `<a href="#">` — a control that
  // looks like a link, is announced as a link, and does nothing when clicked
  // (2026-10-27: every one of 21 page types, at 1440). Its markup is now a
  // button, which is what a pure disclosure should be; this opens and closes
  // it for pointer and keyboard alike, and Escape closes it like every other
  // layer on the site.
  function setupNavDisclosure() {
    each(document.querySelectorAll('[data-nav-disclosure]'), function (button) {
      var item = button.closest('li');
      var menu = item ? item.querySelector('.xo-menu-horizontal-v1__sub-menu') : null;
      if (!menu) {
        return;
      }

      var setOpen = function (open) {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        item.classList.toggle('is-disclosure-open', open);
      };

      var isOpen = function () {
        return button.getAttribute('aria-expanded') === 'true';
      };

      setOpen(false);

      button.addEventListener('click', function (event) {
        event.preventDefault();
        setOpen(!isOpen());
      });

      document.addEventListener('click', function (event) {
        if (isOpen() && !item.contains(event.target)) {
          setOpen(false);
        }
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen()) {
          setOpen(false);
          button.focus();
        }
      });

      item.addEventListener('focusout', function (event) {
        if (isOpen() && !item.contains(event.relatedTarget)) {
          setOpen(false);
        }
      });
    });
  }

  // The series archive's specification-decision cards: picking a card fills the
  // one detail panel beside them from that card's own data attributes. The
  // cards carry every value the panel needs, so nothing is duplicated in markup.
  function setupSpecDecisionCards() {
    each(document.querySelectorAll('[data-psd-card]'), function (card) {
      var scope = card.closest('section') || document;
      var detail = scope.querySelector('[data-psd-detail]');
      if (!detail) {
        return;
      }

      var set = function (field, value) {
        var el = detail.querySelector('[data-psd-detail-' + field + ']');
        if (!el) {
          return;
        }
        if (el.tagName === 'IMG') {
          el.src = value;
          // The ladder has to move with the source. The detail image ships a
          // srcset for the initial card; a leftover srcset keeps describing
          // *that* picture's widths, so the browser satisfies the new layout
          // from a stale candidate and the panel never changes. Cards without
          // a ladder of their own drop the attribute so `src` alone decides.
          var srcset = card.getAttribute('data-psd-image-srcset');
          if (srcset) {
            el.setAttribute('srcset', srcset);
          } else {
            el.removeAttribute('srcset');
          }
          return;
        }
        el.textContent = value;
      };

      // The chips and notes are spans with their own classes, not list items:
      // the volume styles `.psd__chip` and `.psd__note`, so the replacement has
      // to produce those elements rather than semantic list children.
      var fill = function (selector, values, tag, className) {
        var container = detail.querySelector(selector);
        if (!container) {
          return;
        }
        container.innerHTML = '';
        values.forEach(function (value) {
          var item = document.createElement(tag);
          item.className = className;
          item.textContent = value;
          container.appendChild(item);
        });
      };

      var apply = function () {
        each(scope.querySelectorAll('[data-psd-card]'), function (other) {
          var active = other === card;
          other.classList.toggle('is-active', active);
          other.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        set('img', card.getAttribute('data-psd-image') || '');
        var img = detail.querySelector('[data-psd-detail-img]');
        if (img) {
          img.alt = card.getAttribute('data-psd-image-alt') || '';
        }
        set('label', card.getAttribute('data-psd-label') || '');
        set('title', card.getAttribute('data-psd-title') || '');
        set('desc', card.getAttribute('data-psd-desc') || '');

        // The CTA is an anchor with two independent values: where it goes
        // (data-psd-url) and what it says (data-psd-cta). Routing it through
        // set() made every value an href, so the label never replaced the
        // initial card's "Browse geometric patterns" text.
        var link = detail.querySelector('[data-psd-detail-cta]');
        if (link) {
          link.href = card.getAttribute('data-psd-url') || '#';
          link.textContent = card.getAttribute('data-psd-cta') || '';
        }

        var best = (card.getAttribute('data-psd-best') || '').split(',').map(function (item) {
          return item.trim();
        }).filter(Boolean);
        fill('[data-psd-best-list]', best, 'span', 'psd__chip');

        var notes = (card.getAttribute('data-psd-notes') || '').split('\n').map(function (item) {
          return item.trim();
        }).filter(Boolean);
        fill('[data-psd-note-list]', notes, 'span', 'psd__note');
      };

      card.addEventListener('click', apply);
      if (card.classList.contains('is-active')) {
        apply();
      }
    });
  }

  // The perforated page's pattern-route cards: four `data-ppr-card` elements,
  // each carrying its whole detail row as `data-ppr-*` attributes, over a
  // single `data-ppr-detail` panel. The cards shipped `cursor-pointer` with no
  // runtime behind it, so the detail never followed a click.
  function setupPatternRouteCards() {
    each(document.querySelectorAll('[data-ppr-card]'), function (card) {
      var scope = card.closest('section') || document;
      var detail = scope.querySelector('[data-ppr-detail]');
      if (!detail) {
        return;
      }

      var set = function (field, value) {
        var el = detail.querySelector('[data-ppr-detail-' + field + ']');
        if (!el) {
          return;
        }
        if (el.tagName === 'IMG') {
          // The cards carry one image URL each; a stale srcset would keep
          // resolving to the previous card's variant.
          el.removeAttribute('srcset');
          el.src = value;
          return;
        }
        if (el.tagName === 'A') {
          el.href = value;
          return;
        }
        el.textContent = value;
      };

      var apply = function () {
        each(scope.querySelectorAll('[data-ppr-card]'), function (other) {
          var active = other === card;
          other.classList.toggle('is-active', active);
          other.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        var name = card.querySelector('h3');
        set('img', card.getAttribute('data-ppr-image') || '');
        var img = detail.querySelector('[data-ppr-detail-img]');
        if (img && name) {
          img.alt = name.textContent;
        }
        set('eyebrow', card.getAttribute('data-ppr-eyebrow') || '');
        set('title', card.getAttribute('data-ppr-title') || '');
        set('desc', card.getAttribute('data-ppr-desc') || '');

        // The chips and the constraint rows are elements with their own
        // classes, so the refill produces those rather than text nodes.
        var chips = detail.querySelector('[data-ppr-detail-spaces]');
        if (chips) {
          chips.innerHTML = '';
          (card.getAttribute('data-ppr-spaces') || '').split(',').forEach(function (value) {
            value = value.trim();
            if (!value) {
              return;
            }
            var chip = document.createElement('span');
            chip.className = 'text-sm border border-[#c9a962]/30 rounded-full px-4 py-2 text-white/80';
            chip.textContent = value;
            chips.appendChild(chip);
          });
        }
        var notes = detail.querySelector('[data-ppr-detail-constraints]');
        if (notes) {
          notes.innerHTML = '';
          (card.getAttribute('data-ppr-constraints') || '').split('\n').forEach(function (value) {
            value = value.trim();
            if (!value) {
              return;
            }
            var row = document.createElement('div');
            row.className = 'flex items-start gap-2.5 py-2.5 border-b border-white/[0.06]';
            var dot = document.createElement('span');
            dot.className = 'mt-[7px] w-1.5 h-1.5 rounded-full bg-[#c9a962] flex-shrink-0';
            dot.setAttribute('aria-hidden', 'true');
            var text = document.createElement('span');
            text.className = 'text-sm text-white/75 leading-relaxed';
            text.textContent = value;
            row.appendChild(dot);
            row.appendChild(text);
            notes.appendChild(row);
          });
        }

        set('cta-label', card.getAttribute('data-ppr-cta') || '');
        var cta = detail.querySelector('[data-ppr-detail-cta]');
        if (cta) {
          cta.href = card.getAttribute('data-ppr-cta-url') || '#open-quote';
        }
      };

      card.addEventListener('click', apply);
      card.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
          event.preventDefault();
          apply();
        }
      });
      if (card.classList.contains('is-active')) {
        apply();
      }
    });
  }

  /* ================================================================== *
   * The custom-screens landing face
   *
   * This page was converted from a theme that carried its whole interaction
   * layer as inline `onclick` attributes. The CSP baseline blocks inline
   * handlers, and the functions they named were never migrated, so all
   * twenty-three of them were dead: the pattern chips, the feature gallery
   * arrows, the three application lightboxes, the trust tabs, the mobile menu,
   * the mobile thumbnails, the Get Quote buttons and the success dialog.
   * ================================================================== */

  // The hero's pattern chips: four chips, one preview image, one label. Each
  // chip carries its image, alt and label, so the preview is a copy of the
  // chip's own data — the same shape as the catalog's spec-decision cards.
  function setupHeroChips() {
    var chips = [].slice.call(document.querySelectorAll('.hero-chip[data-pattern-image]'));
    if (!chips.length) {
      return;
    }
    var scope = chips[0].closest('section') || document;
    var image = scope.querySelector('.hero-preview img');
    var label = scope.querySelector('[data-hero-pattern-label]');
    if (!image) {
      return;
    }

    var apply = function (chip) {
      each(chips, function (other) {
        var active = other === chip;
        other.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      var src = chip.getAttribute('data-pattern-image');
      if (src) {
        image.src = src;
        // The srcset was describing the previous picture; left in place the
        // browser can satisfy the new layout from a stale candidate.
        image.removeAttribute('srcset');
      }
      image.alt = chip.getAttribute('data-pattern-alt') || '';
      if (label) {
        label.textContent = chip.getAttribute('data-pattern-label') || '';
      }
    };

    each(chips, function (chip) {
      chip.addEventListener('click', function () {
        apply(chip);
      });
    });

    var initial = chips.filter(function (chip) {
      return chip.getAttribute('aria-pressed') === 'true';
    })[0];
    if (initial) {
      apply(initial);
    }
  }

  // Why-us: the feature gallery's arrows. The panels are flexible siblings —
  // `.feature-panel.active { flex: 2.5 }` — so this is a stepper over
  // `.active`, not a scroller, and only one panel is active at a time.
  function setupFeatureGallery() {
    var gallery = document.querySelector('[class*="features-gallery"]')
      || document.querySelector('#gallery-template--22465876033789__why_us_a7TA6d');
    if (!gallery) {
      return;
    }
    var panels = [].slice.call(gallery.querySelectorAll('.feature-panel'));
    if (panels.length < 2) {
      return;
    }
    var section = gallery.closest('section') || document;
    var prev = section.querySelector('.gallery-nav--prev');
    var next = section.querySelector('.gallery-nav--next');
    var index = Math.max(0, panels.findIndex(function (panel) {
      return panel.classList.contains('active');
    }));

    var apply = function (next_index) {
      index = ((next_index % panels.length) + panels.length) % panels.length;
      panels.forEach(function (panel, i) {
        var active = i === index;
        panel.classList.toggle('active', active);
        // The inactive panels are collapsed by the volume's flex rules but
        // their text is still in the accessibility tree.
        panel.setAttribute('aria-hidden', active ? 'false' : 'true');
      });
      if (prev) {
        prev.disabled = false;
      }
      if (next) {
        next.disabled = false;
      }
    };

    if (prev) {
      prev.addEventListener('click', function () {
        apply(index - 1);
      });
    }
    if (next) {
      next.addEventListener('click', function () {
        apply(index + 1);
      });
    }
    apply(index);
  }

  // The three application case studies. Each trigger opens the dialog its own
  // id names, and every dialog is a surface on the shared stack — the same
  // scroll lock, inert page, Escape handling and focus restore the gallery
  // lightbox uses.
  function setupApplicationLightboxes() {
    each(document.querySelectorAll('[data-app-lightbox]'), function (trigger) {
      var dialog = document.getElementById(trigger.getAttribute('data-app-lightbox'));
      if (!dialog) {
        return;
      }
      var opener = null;

      var setClosed = function (closed) {
        if (closed) {
          dialog.classList.remove('active');
          dialog.setAttribute('inert', '');
          dialog.setAttribute('aria-hidden', 'true');
        } else {
          dialog.removeAttribute('inert');
          dialog.setAttribute('aria-hidden', 'false');
        }
      };
      setClosed(true);

      var close = function () {
        setClosed(true);
        popSurface(dialog);
        restoreFocus(opener);
        opener = null;
      };
      dialog.__mdClose = close;

      var open = function () {
        opener = trigger;
        setClosed(false);
        dialog.classList.add('active');
        pushSurface(dialog);
        initialFocusTarget(dialog).focus({ preventScroll: true });
      };

      trigger.addEventListener('click', open);
      trigger.addEventListener('keydown', function (event) {
        // role="button" on a div carries no native activation.
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
          event.preventDefault();
          open();
        }
      });

      each(dialog.querySelectorAll('[data-app-lightbox-close]'), function (control) {
        control.addEventListener('click', function (event) {
          event.preventDefault();
          close();
        });
      });
    });
  }

  // The landing page's own quote dialog. The face carries an Alpine
  // `quoteModal()` component, so the shared `[data-quote-source]` handler in
  // setupModals already dispatches `open-quote`; this only adds the mobile
  // menu's "close before opening" ordering, which the inline handler used to
  // do with a 200ms timeout.
  function setupLandingNav() {
    var nav = document.getElementById('landing-nav');
    if (!nav) {
      return;
    }
    var toggle = document.getElementById('mobile-toggle');
    var overlay = document.getElementById('mobile-menu-overlay');
    var drawer = document.getElementById('mobile-menu-drawer');

    // The band is `position: fixed` on this face, and the site header is
    // sticky above it. Without an offset the two occupy the same pixels and
    // the site header wins on z-index.
    var syncOffset = function () {
      var header = document.querySelector('body > header#lc-header');
      var height = header ? Math.round(header.getBoundingClientRect().height) : 0;
      nav.style.setProperty('--landing-nav-offset', height + 'px');
      nav.setAttribute('data-md-header-offset', String(height));
    };
    syncOffset();
    window.addEventListener('resize', syncOffset);

    // Once scrolled the nav locks to the top, so the offset must not keep
    // pushing it down then.
    var syncState = function () {
      nav.classList.toggle('scrolled', window.scrollY > 24);
    };
    window.addEventListener('scroll', syncState, { passive: true });
    syncState();

    if (!toggle || !drawer) {
      return;
    }

    var setMenu = function (open) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
      drawer.classList.toggle('open', open);
      nav.classList.toggle('mobile-view', open);
      if (overlay) {
        overlay.classList.toggle('open', open);
      }
      // A closed drawer is off-screen, not absent: its links stay in the tab
      // order and would strand a keyboard visitor.
      if (open) {
        drawer.removeAttribute('inert');
        drawer.setAttribute('aria-hidden', 'false');
      } else {
        drawer.setAttribute('inert', '');
        drawer.setAttribute('aria-hidden', 'true');
      }
    };
    setMenu(false);

    toggle.addEventListener('click', function () {
      setMenu(toggle.getAttribute('aria-expanded') !== 'true');
    });

    each(document.querySelectorAll('[data-mobile-menu-close]'), function (control) {
      control.addEventListener('click', function () {
        setMenu(false);
      });
    });

    // The drawer's Get Quote closes the menu first, then opens the dialog. The
    // shared handler reads data-quote-source off the same button, so this only
    // has to close the menu — no timeout, and no second open path.
    each(drawer.querySelectorAll('[data-quote-source]'), function (control) {
      control.addEventListener('click', function () {
        setMenu(false);
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        setMenu(false);
        toggle.focus();
      }
    });
  }

  // The trust section's four tabs. Same WAI-ARIA strip the rest of the site
  // uses, over this face's `.tab-btn` / `.tab-content-item` vocabulary.
  function setupTrustTabs() {
    each(document.querySelectorAll('.trust-tabs-section'), function (section) {
      var triggers = [].slice.call(section.querySelectorAll('.tab-btn[role="tab"]'));
      var panes = [].slice.call(section.querySelectorAll('.tab-content-item'));
      if (!triggers.length || !panes.length) {
        return;
      }
      bindTabSet(triggers, panes, {
        list: section.querySelector('[role="tablist"]'),
        activeClass: 'active',
        nameOf: function (trigger) {
          return trigger.id.replace(/^tab-btn-/, '');
        },
        paneNameOf: function (pane) {
          return pane.id.replace(/^trust-tab-/, '');
        },
        initial: triggers.filter(function (t) {
          return t.getAttribute('aria-selected') === 'true';
        })[0] || triggers[0]
      });

      // The volume draws a sliding underline whose width and position the old
      // inline handler computed. One delegated update per activation keeps it
      // honest without a second state tracker.
      var indicator = section.querySelector('.tab-indicator');
      var moveIndicator = function () {
        var active = section.querySelector('.tab-btn[aria-selected="true"]');
        if (!indicator || !active) {
          return;
        }
        indicator.style.width = active.offsetWidth + 'px';
        indicator.style.transform = 'translateX(' + active.offsetLeft + 'px)';
      };
      moveIndicator();
      each(triggers, function (trigger) {
        trigger.addEventListener('click', moveIndicator);
      });
      window.addEventListener('resize', moveIndicator);
    });
  }

  // The mobile product gallery: four thumbnails swap the main image and its
  // caption. The thumbnails carry the source, the label and the alt so the
  // main image is a copy of the chosen button.
  function setupMobileGallery() {
    each(document.querySelectorAll('[data-mobile-thumb]'), function (thumb) {
      // The scope is the mobile block that holds both the big image and these
      // buttons. It is found by walking up to the first ancestor that contains
      // a main image — the block's own class list is a Tailwind display
      // utility (`md:hidden`) and matching on an escaped class name is a
      // selector waiting to break.
      var scope = thumb.parentElement;
      while (scope && scope !== document.body && !scope.querySelector('img')) {
        scope = scope.parentElement;
      }
      if (!scope) {
        return;
      }
      // Wider than the thumbnail row, and the one that holds the caption.
      while (scope && scope !== document.body && !scope.querySelector('#mobile-main-label')) {
        scope = scope.parentElement;
      }
      var main = scope ? scope.querySelector('img') : null;
      var label = scope ? scope.querySelector('#mobile-main-label') : null;
      if (!main) {
        return;
      }

      var apply = function () {
        var src = thumb.getAttribute('data-src');
        if (src) {
          main.src = src;
          main.removeAttribute('srcset');
        }
        main.alt = thumb.getAttribute('data-alt') || main.alt;
        if (label) {
          label.textContent = thumb.getAttribute('data-label') || label.textContent;
        }
        each(scope.querySelectorAll('[data-mobile-thumb]'), function (other) {
          other.setAttribute('aria-pressed', other === thumb ? 'true' : 'false');
          other.classList.toggle('is-active', other === thumb);
        });
      };

      thumb.setAttribute('aria-pressed', 'false');
      thumb.addEventListener('click', apply);
    });
  }

  // The landing face's shared success dialog: the backdrop and its Close
  // button dismiss it. The dialog is shown by the inquiry flow, not here, so
  // this only owns the dismissal the inline handlers used to provide.
  function setupSectionModalClose() {
    each(document.querySelectorAll('[data-section-modal-close]'), function (control) {
      var modal = control.closest('[id$="section-success-modal"]') || document.querySelector('#section-success-modal');
      if (!modal) {
        return;
      }
      control.addEventListener('click', function (event) {
        event.preventDefault();
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.classList.remove('opacity-100');
        modal.setAttribute('aria-hidden', 'true');
        var content = modal.querySelector('.section-modal-content');
        if (content) {
          content.classList.add('scale-95');
          content.classList.remove('scale-100');
        }
      });
    });
  }

  function setupSticky() {
    each(document.querySelectorAll('xo-sticky'), function (element) {
      if (element.closest('header#lc-header')) {
        return;
      }
      element.style.position = 'sticky';
      element.style.top = '0';
      element.style.zIndex = '100';
      element.setAttribute('data-md-sticky-fallback', 'no-header-host');
    });
  }

  // The back-to-top control is part of the first-party floating chrome
  // (ticket #67): it appears after the first viewport and returns to the
  // top. The WhatsApp float needs no script. Overlap is not a reason to hide
  // either control — they stay visible and discoverable.
  // The footer's tab dock.
  //
  // Ported from de-linker's footer (its inline `footer-app.js`) at the
  // owner's direction on 2026-10-27. The dock is a phone shape — five icons
  // along the bottom of the footer — and on desktop the same nodes become an
  // inline tab strip; one panel shows at a time either way, so twenty
  // destinations cost one screen instead of two.
  //
  // The gate matters: `is-ready` is what un-hides the dock and turns the
  // panels into a single visible one. Without this function the block shows
  // every panel stacked and no dock at all, which is exactly what a visitor
  // without JavaScript should get — readable, complete, nothing hidden behind
  // a control that cannot work.
  //
  // Keyboard behaviour is de-linker's: arrows move between tabs, Home and End
  // jump to the ends, and selecting moves focus with the selection (the ARIA
  // tabs pattern's automatic activation).
  function setupFooterDock() {
    var root = document.getElementById('md-footer');
    if (!root) {
      return;
    }
    var dock = root.querySelector('[data-md-footer-nav]');
    if (!dock) {
      return;
    }
    var tabs = [].slice.call(dock.querySelectorAll('[data-md-footer-tab]'));
    if (!tabs.length) {
      return;
    }
    var panels = tabs.map(function (tab) {
      return root.querySelector('#' + tab.getAttribute('aria-controls'));
    });

    var select = function (index, focus) {
      tabs.forEach(function (tab, position) {
        var selected = position === index;
        tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        tab.tabIndex = selected ? 0 : -1;
        if (panels[position]) {
          panels[position].hidden = !selected;
        }
      });
      if (focus && tabs[index]) {
        tabs[index].focus();
      }
    };

    dock.setAttribute('role', 'tablist');
    tabs.forEach(function (tab, index) {
      tab.setAttribute('role', 'tab');
      if (panels[index]) {
        panels[index].setAttribute('role', 'tabpanel');
      }
      tab.addEventListener('click', function () {
        select(index);
      });
      tab.addEventListener('keydown', function (event) {
        var steps = { ArrowRight: 1, ArrowLeft: -1 };
        if (!(event.key in steps) && event.key !== 'Home' && event.key !== 'End') {
          return;
        }
        event.preventDefault();
        var next = event.key === 'Home' ? 0
          : event.key === 'End' ? tabs.length - 1
            : (index + steps[event.key] + tabs.length) % tabs.length;
        select(next, true);
      });
    });

    root.classList.add('is-ready');

    // The contact heading's arrow is a decorative suffix; de-linker appends it
    // to the link so the two travel together when the heading is copied or
    // read out. Kept because the markup ships the arrow as a sibling.
    var arrow = root.querySelector('[data-md-footer-contact-arrow]');
    if (arrow && arrow.previousElementSibling && arrow.previousElementSibling.append) {
      arrow.previousElementSibling.append(' ', arrow);
    }

    select(0);

    // Copy-to-clipboard on the enquiries address. The status element is the
    // accessible half (a live region); the button's own label flips so a
    // screen reader hears the result without the live region being read
    // twice. A refused clipboard (insecure context, permission) falls back to
    // telling the visitor to select the address, which is what they can do.
    var copy = root.querySelector('[data-md-footer-copy]');
    var status = root.querySelector('.md-footer__copy-status');
    if (copy && status) {
      copy.addEventListener('click', function () {
        var value = copy.getAttribute('data-md-footer-copy') || '';
        var done = function () {
          copy.setAttribute('data-copied', 'true');
          copy.setAttribute('aria-label', 'Email address copied');
          status.textContent = 'Email address copied.';
        };
        var failed = function () {
          status.textContent = 'Select the email address to copy it.';
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(value).then(done, failed);
        } else {
          failed();
        }
      });
    }
  }

  function setupFloats() {
    var top = document.querySelector('.md-back-to-top');
    if (!top) {
      return;
    }
    var sync = function () {
      top.hidden = window.scrollY < 400;
    };
    window.addEventListener('scroll', sync, { passive: true });
    sync();
    top.addEventListener('click', function (event) {
      event.preventDefault();
      var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
    });
  }

  // The project gallery: nine projects at a time, category filters, and the
  // lightbox. The old section shipped this as an inline <script> that the
  // conversion dropped, so every project rendered at once and the page was
  // twice the old height (old section 1911px, ours 4198px).
  function setupGallery() {
    var root = document.querySelector('section.project-gallery');
    if (!root) {
      return;
    }
    var filterBtns = [].slice.call(root.querySelectorAll('.filter-btn'));
    var cards = [].slice.call(root.querySelectorAll('.project-card'));
    if (!cards.length) {
      return;
    }
    var emptyState = root.querySelector('[id^="emptyState"]');
    var loadMoreWrap = root.querySelector('[id^="loadMoreWrap"]');
    var loadMoreBtn = root.querySelector('[id^="loadMoreBtn"]');
    var pageSize = 9;
    var activeFilter = 'all';
    var visibleCount = pageSize;

    var matchingCards = function () {
      return cards.filter(function (card) {
        var cat = (card.getAttribute('data-category') || '').toLowerCase();
        return activeFilter === 'all' || cat === activeFilter;
      });
    };

    var applyFilterAndPaging = function () {
      var matching = matchingCards();
      cards.forEach(function (card) {
        card.classList.add('is-filtered-out');
        card.classList.add('is-paged-out');
      });
      matching.forEach(function (card, index) {
        card.classList.remove('is-filtered-out');
        if (index < visibleCount) {
          card.classList.remove('is-paged-out');
        } else {
          card.classList.add('is-paged-out');
        }
      });
      if (emptyState) {
        emptyState.hidden = matching.length > 0;
      }
      if (loadMoreWrap) {
        loadMoreWrap.hidden = matching.length <= visibleCount;
      }
      if (loadMoreBtn) {
        loadMoreBtn.disabled = matching.length <= visibleCount;
      }
    };

    var setActiveFilter = function (value, resetVisible) {
      var normalized = (value || 'all').toLowerCase();
      var target =
        filterBtns.filter(function (btn) {
          return (btn.getAttribute('data-filter') || '').toLowerCase() === normalized;
        })[0] ||
        filterBtns.filter(function (btn) {
          return (btn.getAttribute('data-filter') || '').toLowerCase() === 'all';
        })[0];
      activeFilter = (target && target.getAttribute('data-filter')) || 'all';
      activeFilter = activeFilter.toLowerCase();
      if (resetVisible) {
        visibleCount = pageSize;
      }
      filterBtns.forEach(function (btn) {
        var on = (btn.getAttribute('data-filter') || '').toLowerCase() === activeFilter;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      applyFilterAndPaging();
    };

    filterBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        setActiveFilter(btn.getAttribute('data-filter') || 'all', true);
      });
    });
    if (loadMoreBtn) {
      loadMoreBtn.addEventListener('click', function () {
        visibleCount += pageSize;
        applyFilterAndPaging();
      });
    }

    var lightbox = root.querySelector('[id^="lightboxModal"]');
    if (!lightbox) {
      applyFilterAndPaging();
      return;
    }
    var backdrop = lightbox.querySelector('.lightbox-backdrop');
    var closeBtn = lightbox.querySelector('.lightbox-close');
    var prevBtn = lightbox.querySelector('.lightbox-nav--prev');
    var nextBtn = lightbox.querySelector('.lightbox-nav--next');
    var image = lightbox.querySelector('.lightbox-image');
    var fallback = lightbox.querySelector('.lightbox-fallback');
    var titleEl = lightbox.querySelector('.lightbox-title');
    var subtitleEl = lightbox.querySelector('.lightbox-subtitle');
    var counterEl = lightbox.querySelector('.lightbox-counter');
    var currentImages = [];
    var currentIndex = 0;
    var currentProject = {};

    var parseImages = function (value) {
      return (value || '')
        .split('|')
        .map(function (item) {
          return item.trim();
        })
        .filter(Boolean);
    };

    var updateLightbox = function () {
      if (!currentImages.length) {
        return;
      }
      if (fallback) {
        fallback.hidden = true;
      }
      if (image) {
        image.hidden = false;
      }
      var nextSrc = currentImages[currentIndex];
      if (image && image.getAttribute('src') !== nextSrc) {
        image.classList.add('is-loading');
        image.src = nextSrc;
      }
      if (image) {
        image.alt = (currentProject.title || 'Project image') + ' (' + (currentIndex + 1) + ')';
      }
      if (titleEl) {
        titleEl.textContent = currentProject.title || '';
      }
      if (subtitleEl) {
        subtitleEl.textContent = currentProject.subtitle || '';
      }
      if (counterEl) {
        counterEl.textContent = currentIndex + 1 + ' / ' + currentImages.length;
      }
      var showNav = currentImages.length > 1;
      if (prevBtn) {
        prevBtn.hidden = !showNav;
      }
      if (nextBtn) {
        nextBtn.hidden = !showNav;
      }
    };

    // Focus containment and the page's inert state are the shared surface
    // manager's job now (pushSurface/popSurface → syncInert): the dialog's
    // ancestor-chain walk, the nesting union and the restoration of each
    // shell's own aria-hidden live in one place. A second local apply/release
    // pair here was the bug it fixed — it stripped aria-hidden from shells
    // that own it, and it could release the page while another surface
    // (the header's drawer, an open modal) still needed it.

    // The visible focusable controls of the open dialog, in DOM order: the
    // close button, the two arrows, and the image itself when it is a link.
    var focusablesInLightbox = function () {
      return focusablesIn(lightbox);
    };

    var dialog = lightbox.querySelector('[role="dialog"]') || lightbox;
    var opener = null;

    var openLightbox = function (images, project, startIndex, trigger) {
      if (!images || !images.length) {
        return;
      }
      currentImages = images;
      currentProject = project || {};
      currentIndex = Math.max(0, Math.min(startIndex || 0, currentImages.length - 1));
      // The opener is the control that invoked the dialog — the standard
      // WAI-ARIA return target.
      opener = trigger || document.activeElement;
      currentImages.forEach(function (src) {
        var pre = new Image();
        pre.src = src;
      });
      updateLightbox();
      lightbox.hidden = false;
      // The dialog is focusable so it can receive focus when it holds no
      // control (an image that failed to load still keeps a labelled dialog).
      if (dialog !== lightbox) {
        dialog.tabIndex = -1;
      }
      pushSurface(lightbox);
      // Initial focus: the close button. Focusing the image first would
      // announce a filename and nothing else; the close control is the
      // dialog's first action in DOM order and stays reachable.
      var first = closeBtn || focusablesInLightbox()[0] || dialog;
      if (first && typeof first.focus === 'function') {
        first.focus({ preventScroll: true });
      }
    };

    var closeLightbox = function () {
      // Same order as closeModal, for the same reason: the opener sits inside
      // the page that is still inert until popSurface runs, so focus is
      // restored only after it can land. One task, no intermediate paint.
      var restoreTo = opener;
      opener = null;
      popSurface(lightbox);
      restoreFocus(restoreTo);
      lightbox.hidden = true;
    };
    // The shared Escape handler runs surfaces through this hook.
    lightbox.__mdClose = closeLightbox;

    var navigate = function (direction) {
      if (!currentImages.length) {
        return;
      }
      currentIndex += direction;
      if (currentIndex < 0) {
        currentIndex = currentImages.length - 1;
      }
      if (currentIndex >= currentImages.length) {
        currentIndex = 0;
      }
      updateLightbox();
    };

    // Tab containment is the shared trap's (trapTabInTopSurface): it wraps in
    // whichever surface is on top, so the gallery lightbox, the pattern
    // lightbox and the header drawers all get the same behaviour from one
    // handler. A second listener here would double-handle every keypress at
    // the boundaries.

    if (image) {
      image.addEventListener('load', function () {
        image.classList.remove('is-loading');
      });
      image.addEventListener('error', function () {
        image.classList.remove('is-loading');
        if (fallback) {
          fallback.hidden = false;
        }
        image.hidden = true;
      });
    }

    var projectOf = function (card) {
      return {
        title: card.getAttribute('data-title') || '',
        subtitle: card.getAttribute('data-subtitle') || '',
        location: card.getAttribute('data-location') || ''
      };
    };

    cards.forEach(function (card) {
      // The card is the click target for the whole tile, so it must also be
      // the keyboard target: a div with a click handler is invisible to Tab
      // and to a screen reader. Role and tabindex put it in both worlds, and
      // Enter/Space do what the pointer does.
      if (!card.hasAttribute('role')) {
        card.setAttribute('role', 'button');
      }
      if (!card.hasAttribute('tabindex')) {
        card.tabIndex = 0;
      }
      if (!card.hasAttribute('aria-label')) {
        var label = card.getAttribute('data-title') || '';
        card.setAttribute('aria-label', label ? 'View gallery: ' + label : 'View gallery');
      }
      var open = function (event) {
        if (event) {
          event.preventDefault();
        }
        if (card.classList.contains('is-filtered-out') || card.classList.contains('is-paged-out')) {
          return;
        }
        openLightbox(parseImages(card.getAttribute('data-images')), projectOf(card), 0, card);
      };
      card.addEventListener('click', open);
      card.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
          // Space would otherwise scroll the page before it activates.
          event.preventDefault();
          open(event);
        }
      });
      var btn = card.querySelector('.view-gallery-btn');
      if (btn) {
        btn.addEventListener('click', function (event) {
          event.stopPropagation();
          open(event);
        });
      }
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', closeLightbox);
    }
    if (backdrop) {
      backdrop.addEventListener('click', closeLightbox);
    }
    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        navigate(-1);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        navigate(1);
      });
    }
    // Escape is the shared surface stack's job (setupModals) — it closes the
    // topmost surface through the __mdClose hook set above, so a quote modal
    // opened from inside the lightbox closes first. Only the arrow keys are
    // this dialog's own.
    document.addEventListener('keydown', function (event) {
      if (lightbox.hidden) {
        return;
      }
      if (event.key === 'ArrowLeft') {
        navigate(-1);
      }
      if (event.key === 'ArrowRight') {
        navigate(1);
      }
    });

    // Deep links: ?category=…, ?project=…, ?image=N — the old section's entry
    // points from the home page and the series pages.
    var params = new URLSearchParams(window.location.search);
    var requestedCategory = (params.get('category') || '').toLowerCase();
    var requestedProject = (params.get('project') || '').toLowerCase();
    var requestedImage = Number(params.get('image') || '1');
    var requestedStartIndex = Number.isFinite(requestedImage) ? Math.max(0, requestedImage - 1) : 0;

    setActiveFilter(requestedCategory || 'all', true);

    if (requestedProject) {
      var targetCard = cards.filter(function (card) {
        return (card.getAttribute('data-project') || '').toLowerCase() === requestedProject;
      })[0];
      if (targetCard) {
        var targetCategory = (targetCard.getAttribute('data-category') || '').toLowerCase();
        if (activeFilter !== 'all' && targetCategory !== activeFilter) {
          setActiveFilter(targetCategory, true);
        }
        var matching = matchingCards();
        var targetIndex = matching.indexOf(targetCard);
        if (targetIndex >= 0 && targetIndex >= visibleCount) {
          visibleCount = targetIndex + 1;
          applyFilterAndPaging();
        }
        targetCard.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        var images = parseImages(targetCard.getAttribute('data-images'));
        if (images.length) {
          window.setTimeout(function () {
            openLightbox(images, projectOf(targetCard), requestedStartIndex);
          }, 220);
        }
      }
    }
  }

  // The catalog's category rail is a sidebar on wide screens and a bottom
  // sheet on narrow ones. The stylesheet owns the sheet; this only opens and
  // closes it, and steps aside when a quote dialog is already on the stack.
  function setupCatalogFilter() {
    var root = document.querySelector('.pattern-catalog');
    if (!root) {
      return;
    }
    var openButton = root.querySelector('[data-md-filter-open]');
    var panel = root.querySelector('#pattern-catalog-filters');
    if (!openButton || !panel) {
      return;
    }
    var backdrop = root.querySelector('.pattern-catalog__filter-backdrop');
    var mq = window.matchMedia('(max-width: 749px)');
    var current = root.querySelector('.pattern-catalog__filter-current');
    var activeName = root.querySelector('.pattern-catalog__nav-link.is-active span');
    if (current && activeName && !(current.textContent || '').trim()) {
      current.textContent = (activeName.textContent || '').trim();
    }

    var setOpen = function (open) {
      if (!mq.matches) {
        open = false;
      }
      panel.classList.toggle('is-open', open);
      document.documentElement.classList.toggle('md-catalog-filter-open', open);
      openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.removeAttribute('aria-hidden');
      } else {
        panel.removeAttribute('role');
        panel.removeAttribute('aria-modal');
        if (mq.matches) {
          panel.setAttribute('aria-hidden', 'true');
        } else {
          panel.removeAttribute('aria-hidden');
        }
      }
      if (backdrop) {
        backdrop.hidden = !open;
      }
    };

    var closeTo = function (restore) {
      setOpen(false);
      if (restore && typeof openButton.focus === 'function') {
        openButton.focus();
      }
    };

    openButton.addEventListener('click', function () {
      setOpen(true);
      var close = panel.querySelector('[data-md-filter-close]');
      if (close && typeof close.focus === 'function') {
        close.focus();
      }
    });
    each(root.querySelectorAll('[data-md-filter-close]'), function (button) {
      button.addEventListener('click', function () {
        closeTo(true);
      });
    });
    panel.addEventListener('click', function (event) {
      if (event.target.closest('a')) {
        closeTo(false);
      }
    });
    document.addEventListener('keydown', function (event) {
      if (!panel.classList.contains('is-open') || document.documentElement.classList.contains('md-modal-open')) {
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        closeTo(true);
        return;
      }
      if (event.key !== 'Tab') {
        return;
      }
      var focusables = focusablesIn(panel);
      if (!focusables.length) {
        event.preventDefault();
        return;
      }
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      var activeEl = document.activeElement;
      if (event.shiftKey && (activeEl === first || !panel.contains(activeEl))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (activeEl === last || !panel.contains(activeEl))) {
        event.preventDefault();
        first.focus();
      }
    });

    var onViewport = function () {
      if (!mq.matches) {
        setOpen(false);
      } else if (!panel.classList.contains('is-open')) {
        panel.setAttribute('aria-hidden', 'true');
      }
    };
    if (mq.addEventListener) {
      mq.addEventListener('change', onViewport);
    } else {
      mq.addListener(onViewport);
    }
    onViewport();
  }

  function start() {
    // `md-js` is what makes `x-cloak` mean anything: the CSS only hides a
    // cloaked block once this class is on the root, so a visitor without
    // scripts keeps every panel the cloak was meant to pre-hide (the family
    // faces keep 24–26 such blocks each, and cloaking them unconditionally
    // cost those pages a third of their words). Set first, before any
    // behaviour, so the hide lands in the same frame the markup arrives.
    document.documentElement.classList.add('md-js');

    // Before any dialog can open: a page that renders the inquiry form itself
    // must not also carry the dialog's copy (duplicate ids, inert controls).
    dropDuplicateModalForm();
    setupModals();
    setupTabs();
    // The cloned panels carry <img xo-src>, so the fill runs before the
    // lazy-load pass resolves those sources. The drawer's panels are filled
    // by the same pass and need no runtime of their own (chrome.css owns
    // their presentation).
    fillMegaMenus();
    setupDesktopMegaPanels();
    setupLazyload();
    setupSticky();
    setupFooterDock();
    setupFloats();
    setupGallery();
    setupPatternLightbox();
    setupProcessVideos();
    setupMeshTypes();

    // The control families. Each is markup plus a stylesheet state class that
    // nothing set, so every one of them was drawn and dead.
    setupDeadTabFamilies();
    setupOutcomesCarousel();
    setupMaterialAccordion();
    setupShowcaseCarousels();
    setupProjectProofCarousels();
    setupQualityDeck();
    setupSpecTableSort();
    setupFaqFilter();
    setupBlogArticleMap();
    setupPerforatedMarkers();
    setupCompareDrag();
    setupPatternRouteCards();
    setupPredictiveSearchReset();
    setupNavDisclosure();
    setupSpecDecisionCards();

    // The custom-screens landing face, converted from a theme whose whole
    // interaction layer was inline `onclick` — blocked by the CSP and naming
    // functions that were never migrated.
    setupHeroChips();
    setupFeatureGallery();
    setupApplicationLightboxes();
    setupTrustTabs();
    setupMobileGallery();
    setupSectionModalClose();
    setupLandingNav();

    setupCatalogFilter();

    // The catalog's sort select posts its form on change (the CSP blocks
    // inline handlers, so the behaviour lives here).
    document.addEventListener('change', function (event) {
      if (event.target.matches('[data-md-sort]') && event.target.form) {
        event.target.form.submit();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
