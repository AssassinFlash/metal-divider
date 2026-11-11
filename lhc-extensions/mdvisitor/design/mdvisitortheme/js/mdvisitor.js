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
  var currentPageUrl = '';
  var currentLocale = '';

  var COPY = {
    en: {
      wa: 'Hi Metal Divider team, I have an inquiry from: ',
      waBase: 'Hi Metal Divider team, I have an inquiry about your stainless steel screens.',
      send: 'Send message',
      filled: 'Question filled in. Not sent yet.',
      noMatch: 'No matching questions.'
    },
    zh: {
      wa: '您好 Metal Divider 团队，我想咨询关于此页面的屏风图案：',
      waBase: '您好 Metal Divider 团队，我想咨询定制不锈钢屏风图案。',
      send: '发送消息',
      filled: '问题已填入输入框，尚未发送。',
      noMatch: '未找到匹配的问题。'
    },
    es: {
      wa: 'Hola equipo de Metal Divider, tengo una consulta desde: ',
      waBase: 'Hola equipo de Metal Divider, tengo una consulta sobre sus paneles metálicos.',
      send: 'Enviar mensaje',
      filled: 'Pregunta completada. Aún no enviada.',
      noMatch: 'No hay preguntas coincidentes.'
    },
    'pt-br': {
      wa: 'Olá equipe Metal Divider, tenho uma consulta a partir de: ',
      waBase: 'Olá equipe Metal Divider, gostaria de informações sobre seus painéis metálicos.',
      send: 'Enviar mensagem',
      filled: 'Pergunta preenchida. Ainda não enviada.',
      noMatch: 'Nenhuma pergunta correspondente.'
    },
    de: {
      wa: 'Hallo Metal Divider Team, ich habe eine Anfrage zu dieser Seite: ',
      waBase: 'Hallo Metal Divider Team, ich habe eine Anfrage zu Ihren Edelstahlpaneelen.',
      send: 'Nachricht senden',
      filled: 'Frage eingefügt. Noch nicht gesendet.',
      noMatch: 'Keine passenden Fragen gefunden.'
    },
    fr: {
      wa: 'Bonjour l’équipe Metal Divider, j’ai une question depuis : ',
      waBase: 'Bonjour l’équipe Metal Divider, j’ai une question concernant vos panneaux métalliques.',
      send: 'Envoyer le message',
      filled: 'Question préremplie. Pas encore envoyée.',
      noMatch: 'Aucune question correspondante.'
    },
    ar: {
      wa: 'مرحباً فريق Metal Divider، لدي استفسار بخصوص هذه الصفحة: ',
      waBase: 'مرحباً فريق Metal Divider، لدي استفسار حول القواطع المعدنية المعمارية.',
      send: 'إرسال الرسالة',
      filled: 'تم إدراج السؤال في الحقل. لم يتم الإرسال بعد.',
      noMatch: 'لا توجد أسئلة مطابقة.'
    },
    id: {
      wa: 'Halo tim Metal Divider, saya memiliki pertanyaan dari halaman: ',
      waBase: 'Halo tim Metal Divider, saya ingin bertanya mengenai panel baja tahan karat Anda.',
      send: 'Kirim pesan',
      filled: 'Pertanyaan telah diisi. Belum dikirim.',
      noMatch: 'Tidak ada pertanyaan yang cocok.'
    },
    vi: {
      wa: 'Xin chào đội ngũ Metal Divider, tôi có câu hỏi từ trang: ',
      waBase: 'Xin chào đội ngũ Metal Divider, tôi muốn tư vấn về tấm vách ngăn inox của bên mình.',
      send: 'Gửi tin nhắn',
      filled: 'Đã điền câu hỏi. Chưa gửi đi.',
      noMatch: 'Không có câu hỏi phù hợp.'
    }
  };

  function normalizeLocale(raw) {
    if (!raw || typeof raw !== 'string') { return ''; }
    var clean = raw.toLowerCase().trim();
    if (clean.indexOf('zh') === 0) { return 'zh'; }
    if (clean.indexOf('pt') === 0) { return 'pt-br'; }
    if (/^(?:en|es|pt-br|de|fr|ar|id|vi)$/.test(clean)) { return clean; }
    return '';
  }

  function localeFromUrl(raw) {
    if (!raw || typeof raw !== 'string') { return ''; }
    try {
      var u = new URL(raw, window.location.origin);
      var m = u.pathname.match(/^\/(zh|es|pt-br|de|fr|ar|id|vi)(?:\/|$)/i);
      return m && m[1] ? m[1].toLowerCase() : '';
    } catch (err) {
      return '';
    }
  }

  function getCopy() {
    return COPY[currentLocale] || COPY.en;
  }

  function applySendButtonLabel() {
    var sendAnchor = doc.querySelector('#ChatSendButtonContainer a');
    if (sendAnchor) {
      var label = getCopy().send;
      sendAnchor.setAttribute('aria-label', label);
      sendAnchor.setAttribute('title', label);
    }
  }

  function setLocale(candidate, dirCandidate) {
    var norm = normalizeLocale(candidate);
    if (!norm) { return; }
    currentLocale = norm;
    var dir = (dirCandidate === 'rtl' || currentLocale === 'ar') ? 'rtl' : 'ltr';
    doc.documentElement.setAttribute('lang', currentLocale);
    doc.documentElement.setAttribute('dir', dir);
    card.setAttribute('dir', dir);
    card.setAttribute('data-mdv-lang', currentLocale);
    try {
      window.sessionStorage.setItem('mdv_lang', currentLocale);
      window.sessionStorage.setItem('mdv_dir', dir);
    } catch (err) {}
    applySendButtonLabel();
    updateWhatsAppLinks();
  }

  function normalizePageUrl(raw) {
    if (!raw || typeof raw !== 'string') { return ''; }
    try {
      var u = new URL(raw, window.location.origin);
      if (u.origin !== window.location.origin) { return ''; }
      if (u.pathname.indexOf('/chat/') === 0) { return ''; }
      return u.href;
    } catch (err) {
      return '';
    }
  }

  function updateWhatsAppLinks() {
    var base = 'https://wa.me/85264731687';
    var copy = getCopy();
    var text = currentPageUrl ? (copy.wa + currentPageUrl) : copy.waBase;
    var href = base + '?text=' + encodeURIComponent(text);
    Array.prototype.forEach.call(doc.querySelectorAll('[data-mdv-wa]'), function (el) {
      el.setAttribute('href', href);
    });
  }

  function setPageUrl(candidate) {
    var clean = normalizePageUrl(candidate);
    if (!clean) { return; }
    currentPageUrl = clean;
    try {
      window.sessionStorage.setItem('mdv:pageUrl', currentPageUrl);
    } catch (err) {}
    if (!currentLocale || currentLocale === 'en') {
      var urlLoc = localeFromUrl(currentPageUrl);
      if (urlLoc) {
        setLocale(urlLoc);
      }
    }
    if (form) {
      var refInput = form.querySelector('input[name="URLRefer"]');
      if (refInput) { refInput.value = currentPageUrl; }
    }
    updateWhatsAppLinks();
  }

  (function initPageContext() {
    var queryLang = '';
    var queryDir = '';
    var fromQuery = '';
    try {
      var params = new URLSearchParams(window.location.search);
      queryLang = params.get('mdvlang') || '';
      queryDir = params.get('mdvdir') || '';
      fromQuery = params.get('URLReferer') || '';
    } catch (err) {}
    var attrLang = card.getAttribute('data-mdv-lang') || '';
    var storedLang = '';
    var fromSession = '';
    try {
      storedLang = window.sessionStorage.getItem('mdv_lang') || '';
      fromSession = window.sessionStorage.getItem('mdv:pageUrl') || '';
    } catch (err) {}
    var fromRef = doc.referrer || '';
    var resolvedUrl = fromQuery || fromSession || fromRef;
    var initialLang = normalizeLocale(queryLang) ||
      (attrLang && attrLang !== 'en' ? normalizeLocale(attrLang) : '') ||
      localeFromUrl(resolvedUrl) ||
      normalizeLocale(storedLang) ||
      normalizeLocale(attrLang) ||
      'en';
    setLocale(initialLang, queryDir);
    setPageUrl(resolvedUrl);
    applySendButtonLabel();
    updateWhatsAppLinks();
  }());

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
    if (live) { live.textContent = getCopy().filled; }
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
    var quoteBtn = event.target.closest('[data-mdv-open-quote]');
    if (quoteBtn) {
      event.preventDefault();
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'mdv_open_quote', pageUrl: currentPageUrl }, window.location.origin);
      } else {
        var loc = currentLocale && currentLocale !== 'en' ? currentLocale : localeFromUrl(currentPageUrl);
        var prefix = loc ? '/' + loc : '';
        window.open(prefix + '/inquiry/', '_blank', 'noopener');
      }
      return;
    }

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
      if (live) { live.textContent = (needle && visible === 0) ? getCopy().noMatch : ''; }
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
    if (currentPageUrl) {
      try { window.sessionStorage.setItem('mdv:pageUrl', currentPageUrl); } catch (err) {}
    }
    Array.prototype.forEach.call(card.querySelectorAll('.md-resume'), function (link) {
      var resumeUrl = saved.url;
      if (currentLocale && resumeUrl.indexOf('mdvlang=') === -1) {
        resumeUrl += (resumeUrl.indexOf('?') === -1 ? '?' : '&') + 'mdvlang=' + encodeURIComponent(currentLocale);
      }
      link.href = resumeUrl;
      link.hidden = false;
      link.addEventListener('click', function () {
        try {
          if (currentPageUrl) { window.sessionStorage.setItem('mdv:pageUrl', currentPageUrl); }
          if (currentLocale) { window.sessionStorage.setItem('mdv_lang', currentLocale); }
        } catch (err) {}
      });
    });
    Array.prototype.forEach.call(card.querySelectorAll('.md-resume-reset'), function (button) {
      button.hidden = false;
    });
  }

  function resetResume() {
    try { window.localStorage.removeItem('mdv:lastChat'); } catch (err) {}
    Array.prototype.forEach.call(card.querySelectorAll('.md-resume'), function (link) {
      link.hidden = true;
      link.removeAttribute('href');
    });
    Array.prototype.forEach.call(card.querySelectorAll('.md-resume-reset'), function (button) {
      button.hidden = true;
    });
    var first = card.querySelector('.md-action');
    if (first) { first.focus(); }
  }

  Array.prototype.forEach.call(card.querySelectorAll('.md-resume-reset'), function (button) {
    button.addEventListener('click', resetResume);
  });

  initResume();

  try {
    var initial = new URLSearchParams(window.location.search).get('view');
    if (initial === 'faq' || initial === 'home') { view(initial); }
  } catch (err) {}

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin) { return; }
    if (event.data && event.data.type === 'mdv_host_context') {
      if (event.data.lang) {
        setLocale(String(event.data.lang), event.data.dir ? String(event.data.dir) : '');
      }
      if (event.data.pageUrl) {
        setPageUrl(event.data.pageUrl);
      }
    }
  });

  if (window.parent && window.parent !== window) {
    try {
      window.parent.postMessage({ type: 'mdv_request_host_context' }, window.location.origin);
    } catch (err) {}
  }

  doc.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') { return; }
    if (closeOpenFaq()) { event.preventDefault(); return; }
    event.preventDefault();
    closeCard();
  });
}());
