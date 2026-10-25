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

    // The custom-screens quote dialog, opened by [data-quote-source] CTAs.
    Alpine.data('quoteModal', function () {
      return {
        isOpen: false,
        detail: null,
        open: function (detail) {
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

  function openModal(modal) {
    if (!modal) {
      return;
    }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('md-modal-open');
    if (openStack.indexOf(modal) < 0) {
      openStack.push(modal);
    }
  }

  function closeModal(modal) {
    if (!modal) {
      return;
    }
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    var index = openStack.indexOf(modal);
    if (index > -1) {
      openStack.splice(index, 1);
    }
    if (!openStack.length) {
      document.documentElement.classList.remove('md-modal-open');
    }
  }

  function setupModals() {
    each(document.querySelectorAll('xo-modal'), function (modal) {
      modal.setAttribute('aria-hidden', 'true');
    });

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('xo-modal-trigger');
      if (trigger) {
        var name = trigger.getAttribute('xo-name');
        if (name) {
          event.preventDefault();
          openModal(findModal(name));
        } else {
          var owner = trigger.closest('xo-modal');
          if (owner) {
            event.preventDefault();
            closeModal(owner);
          }
        }
        return;
      }

      // A [data-quote-source] CTA opens the page's quote dialog when one
      // exists; elsewhere the anchor's #open-quote target handles the jump.
      var quote = event.target.closest('[data-quote-source]');
      if (quote && document.querySelector('[x-data^="quoteModal"]')) {
        event.preventDefault();
        window.dispatchEvent(
          new CustomEvent('open-quote', {
            detail: { source: quote.getAttribute('data-quote-source') }
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
      if (event.key === 'Escape') {
        openStack.slice().reverse().forEach(closeModal);
      }
    });
  }

  function setupTabs() {
    each(document.querySelectorAll('xo-tabs'), function (tabs) {
      var triggers = tabs.querySelectorAll('xo-tabs-trigger');
      var panes = tabs.querySelectorAll('xo-tabs-pane');
      if (!triggers.length || !panes.length) {
        return;
      }
      var activate = function (name) {
        each(triggers, function (trigger) {
          var on = trigger.getAttribute('xo-name') === name;
          trigger.classList.toggle('is-active', on);
          trigger.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        each(panes, function (pane) {
          var on = pane.getAttribute('xo-name') === name;
          pane.classList.toggle('is-active', on);
          pane.hidden = !on;
        });
      };
      each(triggers, function (trigger) {
        trigger.addEventListener('click', function () {
          activate(trigger.getAttribute('xo-name'));
        });
      });
      var initial = tabs.querySelector('xo-tabs-trigger[xo-active]') || triggers[0];
      activate(initial.getAttribute('xo-name'));
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

  function setupSticky() {
    each(document.querySelectorAll('xo-sticky'), function (element) {
      element.style.position = 'sticky';
      element.style.top = '0';
      element.style.zIndex = '50';
    });
  }

  function start() {
    setupModals();
    setupTabs();
    setupLazyload();
    setupSticky();

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
