(function () {
  'use strict';

  var doc = document;
  var form = doc.getElementById('form-start-chat');
  var question = doc.getElementById('id_Question') || doc.querySelector('textarea[name="Question"]');
  var card = doc.querySelector('.md-card.md-start');
  if (!card) { return; }

  var views = Array.prototype.slice.call(card.querySelectorAll('[data-mdv-view]'));
  var tabs = Array.prototype.slice.call(card.querySelectorAll('.md-tabs [data-mdv-go]'));
  var live = doc.getElementById('mdv-live');

  if (form && question && !question.classList.contains('hide')) {
    form.classList.add('mdv-has-question');
  }

  function view(name) {
    views.forEach(function (node) {
      var on = node.getAttribute('data-mdv-view') === name;
      node.classList.toggle('is-active', on);
      node.hidden = !on;
    });
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-mdv-go') === name;
      tab.classList.toggle('is-active', on);
      if (on) {
        tab.setAttribute('aria-current', 'page');
      } else {
        tab.removeAttribute('aria-current');
      }
    });
    if (name === 'faq') {
      var search = doc.getElementById('mdv-faq-search');
      if (search) { setTimeout(function () { search.focus(); }, 40); }
    }
  }

  function prefill(text) {
    if (!question) { return; }
    question.value = text;
    question.dispatchEvent(new Event('input', { bubbles: true }));
    view('home');
    question.focus();
    if (typeof question.setSelectionRange === 'function') {
      var end = question.value.length;
      question.setSelectionRange(end, end);
    }
    if (live) { live.textContent = 'Question filled in. Not sent yet.'; }
  }

  function closeOpenFaq() {
    var faq = card.querySelector('[data-mdv-view="faq"]');
    if (faq && faq.classList.contains('is-active')) {
      view('home');
      var homeTab = card.querySelector('.md-tabs [data-mdv-go="home"]');
      if (homeTab) { homeTab.focus(); }
      return true;
    }
    return false;
  }

  function notifyParentClosed() {
    if (!window.parent || window.parent === window) { return false; }
    var target = '';
    try {
      if (window.location.ancestorOrigins && window.location.ancestorOrigins.length) {
        target = window.location.ancestorOrigins[0];
      } else if (document.referrer) {
        target = new URL(document.referrer).origin;
      }
    } catch (err) {}
    if (!target) { return false; }
    window.parent.postMessage('lhc_chat_closed_explicit', target);
    return true;
  }

  function closeCard() {
    if (!notifyParentClosed()) { window.close(); }
  }

  card.addEventListener('click', function (event) {
    var go = event.target.closest('[data-mdv-go]');
    if (go) {
      event.preventDefault();
      var name = go.getAttribute('data-mdv-go');
      if (name === 'chat') {
        view('home');
        if (question) { question.focus(); }
      } else {
        view(name);
      }
      return;
    }

    var close = event.target.closest('[data-mdv-close]');
    if (close) {
      event.preventDefault();
      closeCard();
      return;
    }

    var fill = event.target.closest('[data-mdv-fill]');
    if (fill) {
      event.preventDefault();
      prefill(fill.getAttribute('data-mdv-fill') || '');
    }
  });

  var search = doc.getElementById('mdv-faq-search');
  if (search) {
    search.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); }
    });
    search.addEventListener('input', function () {
      var needle = search.value.trim().toLowerCase();
      var visible = 0;
      Array.prototype.forEach.call(card.querySelectorAll('.md-faq-list .md-faq-q'), function (row) {
        row.hidden = !!(needle && row.textContent.toLowerCase().indexOf(needle) === -1);
        if (!row.hidden) { visible += 1; }
      });
      if (live) { live.textContent = (needle && visible === 0) ? 'No matching questions.' : ''; }
    });
  }

  function initResume() {
    var raw = null;
    try { raw = window.localStorage.getItem('mdv:lastChat'); } catch (err) { return; }
    if (!raw) { return; }
    var saved = null;
    try { saved = JSON.parse(raw); } catch (err) { return; }
    if (!saved || typeof saved.url !== 'string') { return; }
    if (saved.url.indexOf('/chat/') !== 0 || saved.url.indexOf('/chatwidgetchat/') === -1) { return; }
    Array.prototype.forEach.call(card.querySelectorAll('.md-resume'), function (link) {
      link.href = saved.url;
      link.hidden = false;
    });
  }

  initResume();

  try {
    var initial = new URLSearchParams(window.location.search).get('view');
    if (initial === 'faq' || initial === 'home') { view(initial); }
  } catch (err) {}

  doc.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') { return; }
    if (closeOpenFaq()) { event.preventDefault(); return; }
    event.preventDefault();
    closeCard();
  });
}());
