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

    // The family pages' hover/tap tab strip. Initial tab is the argument.
    Alpine.data('mdHoverTabs', function (initial) {
      return {
        activeTab: initial,
        isDesktopHover: false,
        hoverTimer: null,
        init: function () {
          var self = this;
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
    Alpine.data('mdFaq', function () {
      return {
        openFaq: '',
        activeFaqCategory: 'Project Start',
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
        open: function (detail) {
          if (document.getElementById('quote-modal') && this.$root.id !== 'quote-modal') {
            return;
          }
          var page_form = pageInquiryForm();
          if (page_form) {
            focusInquiryForm(page_form);
            return;
          }
          this.detail = detail || null;
          this.isOpen = true;
        },
        close: function () {
          this.isOpen = false;
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
          this.frameId = window.requestAnimationFrame(function (ts) {
            self.step(ts);
          });
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
          this.frameId = window.requestAnimationFrame(function (ts) {
            self.step(ts);
          });
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
        var top = openStack[openStack.length - 1];
        if (typeof top.__mdClose === 'function') {
          event.preventDefault();
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
  // top. The WhatsApp float needs no script.
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

  function start() {
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
    setupFloats();
    setupGallery();
    setupPatternLightbox();
    setupProcessVideos();

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
