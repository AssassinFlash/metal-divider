(function () {
  'use strict';

  var head = null;
  var block = null;
  var card = null;
  var chatId = 0;
  var chatHash = '';
  var chatStatus = 0;
  var emailSaved = false;
  var skipKey = '';
  var started = false;
  var lastStoreKey = 'mdv:lastChat';
  var currentPageUrl = '';
  var currentLocale = '';
  var syncInFlightUrl = '';

  var COPY = {
    en: {
      wa: 'Hi Metal Divider team, I have an inquiry from: ',
      waBase: 'Hi Metal Divider team, I have an inquiry about your stainless steel screens.',
      emailTitle: 'Get a reply by email',
      emailDesc: 'Optional. We only use it to reply to this inquiry. No account needed.',
      emailPlaceholder: 'you@company.com',
      emailAria: 'Email address',
      save: 'Save',
      notNow: 'Not now',
      enterEmail: 'Enter your email address.',
      saving: 'Saving...',
      saved: 'Saved. The team can follow up by email.',
      checkEmail: 'Please check the email and try again.',
      saveError: 'Could not save right now. Please try again.',
      deleting: 'Deleting…',
      deleteError: 'Could not delete this conversation right now. Please try again.'
    },
    zh: {
      wa: '您好 Metal Divider 团队，我想咨询关于此页面的屏风图案：',
      waBase: '您好 Metal Divider 团队，我想咨询定制不锈钢屏风图案。',
      emailTitle: '通过邮件接收回复',
      emailDesc: '选填。仅用于回复本次询盘，无需注册账户。',
      emailPlaceholder: 'you@company.com',
      emailAria: '电子邮箱地址',
      save: '保存',
      notNow: '暂不需要',
      enterEmail: '请输入您的电子邮箱地址。',
      saving: '正在保存…',
      saved: '已保存，我们的团队将通过邮件与您跟进。',
      checkEmail: '请检查邮箱格式后重试。',
      saveError: '暂时无法保存，请稍后重试。',
      deleting: '正在删除…',
      deleteError: '暂时无法删除此对话，请稍后重试。'
    },
    es: {
      wa: 'Hola equipo de Metal Divider, tengo una consulta desde: ',
      waBase: 'Hola equipo de Metal Divider, tengo una consulta sobre sus paneles metálicos.',
      emailTitle: 'Recibir respuesta por correo',
      emailDesc: 'Opcional. Solo lo usamos para responder a esta consulta. Sin crear cuenta.',
      emailPlaceholder: 'usted@empresa.com',
      emailAria: 'Dirección de correo electrónico',
      save: 'Guardar',
      notNow: 'Ahora no',
      enterEmail: 'Introduzca su dirección de correo electrónico.',
      saving: 'Guardando...',
      saved: 'Guardado. Nuestro equipo le responderá por correo.',
      checkEmail: 'Verifique el correo e inténtelo de nuevo.',
      saveError: 'No se pudo guardar ahora. Inténtelo de nuevo.',
      deleting: 'Eliminando…',
      deleteError: 'No se pudo eliminar esta conversación ahora. Inténtelo de nuevo.'
    },
    'pt-br': {
      wa: 'Olá equipe Metal Divider, tenho uma consulta a partir de: ',
      waBase: 'Olá equipe Metal Divider, gostaria de informações sobre seus painéis metálicos.',
      emailTitle: 'Receber resposta por e-mail',
      emailDesc: 'Opcional. Usamos apenas para responder a esta consulta. Sem cadastro.',
      emailPlaceholder: 'voce@empresa.com',
      emailAria: 'Endereço de e-mail',
      save: 'Salvar',
      notNow: 'Agora não',
      enterEmail: 'Digite seu endereço de e-mail.',
      saving: 'Salvando...',
      saved: 'Salvo. Nossa equipe dará retorno por e-mail.',
      checkEmail: 'Verifique o e-mail e tente novamente.',
      saveError: 'Não foi possível salvar agora. Tente novamente.',
      deleting: 'Excluindo…',
      deleteError: 'Não foi possível excluir esta conversa agora. Tente novamente.'
    },
    de: {
      wa: 'Hallo Metal Divider Team, ich habe eine Anfrage zu dieser Seite: ',
      waBase: 'Hallo Metal Divider Team, ich habe eine Anfrage zu Ihren Edelstahlpaneelen.',
      emailTitle: 'Antwort per E-Mail erhalten',
      emailDesc: 'Optional. Wir nutzen sie nur für diese Anfrage. Kein Konto erforderlich.',
      emailPlaceholder: 'name@unternehmen.de',
      emailAria: 'E-Mail-Adresse',
      save: 'Speichern',
      notNow: 'Jetzt nicht',
      enterEmail: 'Bitte geben Sie Ihre E-Mail-Adresse ein.',
      saving: 'Wird gespeichert...',
      saved: 'Gespeichert. Unser Team meldet sich per E-Mail.',
      checkEmail: 'Bitte prüfen Sie die E-Mail-Adresse und versuchen Sie es erneut.',
      saveError: 'Speichern fehlgeschlagen. Bitte erneut versuchen.',
      deleting: 'Wird gelöscht…',
      deleteError: 'Gespräch konnte gerade nicht gelöscht werden. Bitte erneut versuchen.'
    },
    fr: {
      wa: 'Bonjour l’équipe Metal Divider, j’ai une question depuis : ',
      waBase: 'Bonjour l’équipe Metal Divider, j’ai une question concernant vos panneaux métalliques.',
      emailTitle: 'Recevoir une réponse par e-mail',
      emailDesc: 'Facultatif. Utilisé uniquement pour répondre à cette demande. Sans compte.',
      emailPlaceholder: 'vous@entreprise.fr',
      emailAria: 'Adresse e-mail',
      save: 'Enregistrer',
      notNow: 'Pas maintenant',
      enterEmail: 'Veuillez saisir votre adresse e-mail.',
      saving: 'Enregistrement...',
      saved: 'Enregistré. Notre équipe pourra vous répondre par e-mail.',
      checkEmail: 'Veuillez vérifier l’adresse e-mail et réessayer.',
      saveError: 'Impossible d’enregistrer pour le moment. Veuillez réessayer.',
      deleting: 'Suppression…',
      deleteError: 'Impossible de supprimer cette conversation pour le moment. Veuillez réessayer.'
    },
    'ar': {
      wa: 'مرحباً فريق Metal Divider، لدي استفسار بخصوص هذه الصفحة: ',
      waBase: 'مرحباً فريق Metal Divider، لدي استفسار حول القواطع المعدنية المعمارية.',
      emailTitle: 'استلام الرد عبر البريد الإلكتروني',
      emailDesc: 'اختياري. نستخدمه فقط للرد على هذا الاستفسار دون الحاجة لإنشاء حساب.',
      emailPlaceholder: 'you@company.com',
      emailAria: 'عنوان البريد الإلكتروني',
      save: 'حفظ',
      notNow: 'ليس الآن',
      enterEmail: 'يرجى إدخال عنوان بريدك الإلكتروني.',
      saving: 'جارٍ الحفظ...',
      saved: 'تم الحفظ. سيتابع فريقنا معك عبر البريد الإلكتروني.',
      checkEmail: 'يرجى التحقق من البريد الإلكتروني والمحاولة مرة أخرى.',
      saveError: 'تعذر الحفظ حالياً. يرجى المحاولة مرة أخرى.',
      deleting: 'جارٍ الحذف…',
      deleteError: 'تعذر حذف هذه المحادثة حالياً. يرجى المحاولة مرة أخرى.'
    },
    id: {
      wa: 'Halo tim Metal Divider, saya memiliki pertanyaan dari halaman: ',
      waBase: 'Halo tim Metal Divider, saya ingin bertanya mengenai panel baja tahan karat Anda.',
      emailTitle: 'Terima balasan melalui email',
      emailDesc: 'Opsional. Hanya digunakan untuk membalas permintaan ini. Tanpa akun.',
      emailPlaceholder: 'anda@perusahaan.com',
      emailAria: 'Alamat email',
      save: 'Simpan',
      notNow: 'Nanti saja',
      enterEmail: 'Masukkan alamat email Anda.',
      saving: 'Menyimpan...',
      saved: 'Tersimpan. Tim kami dapat menindaklanjuti melalui email.',
      checkEmail: 'Periksa kembali alamat email dan coba lagi.',
      saveError: 'Tidak dapat menyimpan saat ini. Silakan coba lagi.',
      deleting: 'Menghapus…',
      deleteError: 'Tidak dapat menghapus percakapan ini sekarang. Silakan coba lagi.'
    },
    vi: {
      wa: 'Xin chào đội ngũ Metal Divider, tôi có câu hỏi từ trang: ',
      waBase: 'Xin chào đội ngũ Metal Divider, tôi muốn tư vấn về tấm vách ngăn inox của bên mình.',
      emailTitle: 'Nhận phản hồi qua email',
      emailDesc: 'Tùy chọn. Chỉ dùng để phản hồi yêu cầu này, không cần tạo tài khoản.',
      emailPlaceholder: 'ban@congty.com',
      emailAria: 'Địa chỉ email',
      save: 'Lưu',
      notNow: 'Để sau',
      enterEmail: 'Vui lòng nhập địa chỉ email của bạn.',
      saving: 'Đang lưu...',
      saved: 'Đã lưu. Đội ngũ của chúng tôi sẽ phản hồi qua email.',
      checkEmail: 'Vui lòng kiểm tra lại email và thử lại.',
      saveError: 'Hiện chưa thể lưu. Vui lòng thử lại.',
      deleting: 'Đang xóa…',
      deleteError: 'Hiện chưa thể xóa cuộc trò chuyện này. Vui lòng thử lại.'
    }
  };

  function normalizeLocale(raw) {
    if (!raw || typeof raw !== 'string') {
      return '';
    }
    var clean = raw.toLowerCase().trim();
    if (clean.indexOf('zh') === 0) {
      return 'zh';
    }
    if (clean.indexOf('pt') === 0) {
      return 'pt-br';
    }
    if (/^(?:en|es|pt-br|de|fr|ar|id|vi)$/.test(clean)) {
      return clean;
    }
    return '';
  }

  function localeFromUrl(raw) {
    if (!raw || typeof raw !== 'string') {
      return '';
    }
    try {
      var u = new URL(raw, window.location.origin);
      var match = u.pathname.match(/^\/(zh|es|pt-br|de|fr|ar|id|vi)(?:\/|$)/i);
      if (match && match[1]) {
        return match[1].toLowerCase();
      }
    } catch (e) {}
    return '';
  }

  function getEffectiveLocale() {
    if (currentLocale) {
      return currentLocale;
    }
    var fromUrl = localeFromUrl(currentPageUrl || window.location.href);
    if (fromUrl) {
      return fromUrl;
    }
    return 'en';
  }

  function getCopy() {
    return COPY[getEffectiveLocale()] || COPY.en;
  }

  function refreshEmailCardCopy() {
    if (!card) {
      return;
    }
    var c = getCopy();
    var dir = getEffectiveLocale() === 'ar' ? 'rtl' : 'ltr';
    card.setAttribute('dir', dir);
    var h2 = card.querySelector('#mdv-email-title');
    var p = card.querySelector('p');
    var inp = card.querySelector('input[name="email"]');
    var btn = card.querySelector('form button[type="submit"]');
    var skip = card.querySelector('.mdv-email-skip');
    if (h2) { h2.textContent = c.emailTitle; }
    if (p) { p.textContent = c.emailDesc; }
    if (inp) {
      inp.placeholder = c.emailPlaceholder;
      inp.setAttribute('aria-label', c.emailAria);
    }
    if (btn) { btn.textContent = c.save; }
    if (skip) { skip.textContent = c.notNow; }
  }

  function setLocale(candidate, dirCandidate) {
    var norm = normalizeLocale(candidate);
    if (!norm) {
      return;
    }
    var changed = currentLocale !== norm;
    currentLocale = norm;
    var dir = (dirCandidate === 'rtl' || currentLocale === 'ar') ? 'rtl' : 'ltr';
    document.documentElement.setAttribute('lang', currentLocale);
    document.documentElement.setAttribute('dir', dir);
    try {
      window.sessionStorage.setItem('mdv_lang', currentLocale);
      window.sessionStorage.setItem('mdv_dir', dir);
    } catch (e) {}
    refreshEmailCardCopy();
    updateWhatsAppLinks();
    if (changed) {
      syncInFlightUrl = '';
      try {
        if (chatId) {
          window.sessionStorage.removeItem('mdv:syncedUrl:' + chatId);
        }
      } catch (e) {}
      if (typeof window.__mdvApplyChatLocale === 'function') {
        window.__mdvApplyChatLocale(currentLocale, dir);
      }
      if (currentPageUrl && chatId && chatHash) {
        syncChatContext(currentPageUrl);
      }
    }
  }

  function normalizePageUrl(raw) {
    if (!raw || typeof raw !== 'string') {
      return '';
    }
    try {
      var u = new URL(raw, window.location.origin);
      if (u.origin !== window.location.origin) {
        return '';
      }
      if (u.pathname.indexOf('/chat/') === 0) {
        return '';
      }
      return u.href;
    } catch (e) {
      return '';
    }
  }

  function updateWhatsAppLinks() {
    var c = getCopy();
    var base = 'https://wa.me/85264731687';
    var href = currentPageUrl
      ? base + '?text=' + encodeURIComponent(c.wa + currentPageUrl)
      : base + (c.waBase ? '?text=' + encodeURIComponent(c.waBase) : '');
    Array.prototype.forEach.call(document.querySelectorAll('[data-mdv-wa]'), function (el) {
      el.setAttribute('href', href);
    });
  }

  function syncChatContext(pageUrl) {
    var clean = normalizePageUrl(pageUrl);
    if (!clean || !chatId || !chatHash) {
      return;
    }
    var syncKey = 'mdv:syncedUrl:' + chatId;
    var lastSynced = '';
    try {
      lastSynced = window.sessionStorage.getItem(syncKey) || '';
    } catch (e) {}
    if (lastSynced === clean || syncInFlightUrl === clean) {
      return;
    }
    syncInFlightUrl = clean;
    var locale = getEffectiveLocale();
    var payload = { chat_id: chatId, hash: chatHash, page_url: clean };
    if (locale && locale !== 'en') {
      payload.lang = locale;
    }
    fetch(window.location.origin + '/wp-json/md/v1/chat/context', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      if (res.ok) {
        try {
          window.sessionStorage.setItem(syncKey, clean);
        } catch (e) {}
      }
      if (syncInFlightUrl === clean) {
        syncInFlightUrl = '';
      }
    }).catch(function () {
      if (syncInFlightUrl === clean) {
        syncInFlightUrl = '';
      }
    });
  }

  function setPageUrl(candidate) {
    var clean = normalizePageUrl(candidate);
    if (!clean) {
      return;
    }
    currentPageUrl = clean;
    try {
      window.sessionStorage.setItem('mdv:pageUrl', currentPageUrl);
    } catch (e) {}
    if (!currentLocale || currentLocale === 'en') {
      var urlLocale = localeFromUrl(clean);
      if (urlLocale) {
        setLocale(urlLocale);
      }
    }
    updateWhatsAppLinks();
    if (chatId && chatHash) {
      syncChatContext(currentPageUrl);
    }
  }

  (function initPageContext() {
    var fromQuery = '';
    var queryLang = '';
    var queryDir = '';
    try {
      var params = new URLSearchParams(window.location.search);
      fromQuery = params.get('URLReferer') || '';
      queryLang = params.get('mdvlang') || '';
      queryDir = params.get('mdvdir') || '';
    } catch (e) {}
    var fromSession = '';
    var sessionLang = '';
    var sessionDir = '';
    try {
      fromSession = window.sessionStorage.getItem('mdv:pageUrl') || '';
      sessionLang = window.sessionStorage.getItem('mdv_lang') || '';
      sessionDir = window.sessionStorage.getItem('mdv_dir') || '';
    } catch (e) {}
    var headEl = document.querySelector('.mdv-chat-head');
    var headLang = headEl ? (headEl.getAttribute('data-mdv-lang') || '') : '';
    var headDir = headEl ? (headEl.getAttribute('dir') || '') : '';
    var fromRef = document.referrer || '';
    var candidateUrl = fromQuery || fromSession || fromRef;
    var initLang = normalizeLocale(queryLang)
      || normalizeLocale(sessionLang)
      || (headLang && headLang !== 'en' ? normalizeLocale(headLang) : '')
      || localeFromUrl(candidateUrl)
      || normalizeLocale(headLang)
      || 'en';
    setLocale(initLang, queryDir || sessionDir || headDir);
    setPageUrl(candidateUrl);
  }());

  function readStore(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function writeStore(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (e) {
      /* private mode: no persistence, the page still works */
    }
  }

  function hasBody(row) {
    var body = row.querySelector('.msg-body');
    return !!(body && (body.textContent || '').trim() !== '');
  }

  var deleteWired = false;

  function wireDelete() {
    if (deleteWired) {
      return;
    }
    var wrap = document.querySelector('.mdv-danger-actions');
    if (!wrap) {
      return;
    }
    var btn = wrap.querySelector('.mdv-delete-btn');
    var box = wrap.querySelector('.mdv-confirm');
    var yes = wrap.querySelector('.mdv-confirm-yes');
    var no = wrap.querySelector('.mdv-confirm-no');
    var status = wrap.querySelector('.mdv-delete-status');
    if (!btn || !box || !yes || !no || !status) {
      return;
    }
    deleteWired = true;
    var lastFocus = null;

    function setStatus(text, tone) {
      status.textContent = text;
      status.setAttribute('data-tone', tone || '');
      status.hidden = text === '';
    }

    function openBox() {
      lastFocus = document.activeElement;
      box.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      no.focus();
    }

    function closeBox() {
      box.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      if (lastFocus && typeof lastFocus.focus === 'function') {
        lastFocus.focus();
      }
    }

    btn.addEventListener('click', function () {
      setStatus('', '');
      if (box.hidden) {
        openBox();
      } else {
        closeBox();
      }
    });

    no.addEventListener('click', closeBox);

    document.addEventListener('keydown', function (event) {
      if (box.hidden) {
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        closeBox();
        return;
      }
      if (event.key === 'Tab') {
        /* keep focus inside the modal confirm: Tab and shift+Tab cycle its
         two buttons in order, wrapping at both ends */
        event.preventDefault();
        if (document.activeElement === yes) {
          no.focus();
        } else {
          yes.focus();
        }
      }
    }, true);

    yes.addEventListener('click', function () {
      var c = getCopy();
      yes.disabled = true;
      no.disabled = true;
      setStatus(c.deleting, '');
      fetch(window.location.origin + '/wp-json/md/v1/chat/delete', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ chat_id: chatId, hash: chatHash })
      }).then(function (res) {
        if (res.status === 200 || res.status === 404) {
          return { gone: true };
        }
        return { gone: false };
      }).then(function (result) {
        if (result.gone) {
          try {
            window.localStorage.removeItem(lastStoreKey);
            window.localStorage.removeItem('mdv:emailSaved:' + chatId);
            window.localStorage.removeItem('mdv:emailSkip:' + chatId);
            window.sessionStorage.removeItem('mdv:syncedUrl:' + chatId);
          } catch (e) {
            /* private mode: nothing persisted, nothing to clear */
          }
          var nextStart = wrap.getAttribute('data-mdv-start-url') || '/chat/';
          var eff = getEffectiveLocale();
          if (eff && eff !== 'en' && nextStart.indexOf('mdvlang=') === -1) {
            nextStart += (nextStart.indexOf('?') === -1 ? '?' : '&')
              + 'mdvlang=' + encodeURIComponent(eff)
              + '&mdvdir=' + encodeURIComponent(eff === 'ar' ? 'rtl' : 'ltr');
          }
          window.location.assign(nextStart);
          return;
        }
        yes.disabled = false;
        no.disabled = false;
        setStatus(getCopy().deleteError, 'error');
      }).catch(function () {
        yes.disabled = false;
        no.disabled = false;
        setStatus(getCopy().deleteError, 'error');
      });
    });
  }

  function rememberLastChat() {
    if (!block) {
      return;
    }
    var rows = block.querySelectorAll('.message-row');
    var seenVisitor = false;
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].classList.contains('response') && hasBody(rows[i])) {
        seenVisitor = true;
        break;
      }
    }
    if (!seenVisitor) {
      return;
    }
    var resumeUrl = window.location.pathname + window.location.search;
    var eff = getEffectiveLocale();
    if (eff && eff !== 'en' && resumeUrl.indexOf('mdvlang=') === -1) {
      resumeUrl += (resumeUrl.indexOf('?') === -1 ? '?' : '&')
        + 'mdvlang=' + encodeURIComponent(eff)
        + '&mdvdir=' + encodeURIComponent(eff === 'ar' ? 'rtl' : 'ltr');
    }
    writeStore(lastStoreKey, JSON.stringify({
      url: resumeUrl,
      at: Date.now()
    }));
  }

  function isGreeting(row) {
    var body = row.querySelector('.msg-body');
    var text = body ? (body.textContent || '').replace(/\s+/g, ' ').trim() : '';
    return /^Hi\s*[—–-]\s*I['’]?m\s+Metal Divider['’]?s AI assistant\b/i.test(text);
  }

  function visitorHasAnswer() {
    var rows = block.querySelectorAll('.message-row');
    var seenVisitor = false;
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      if (row.classList.contains('message-row-typing')) {
        continue;
      }
      if (row.classList.contains('response')) {
        if (hasBody(row)) {
          seenVisitor = true;
        }
        continue;
      }
      if (seenVisitor && row.classList.contains('message-admin') && hasBody(row) && !isGreeting(row)) {
        return true;
      }
    }
    return false;
  }

  function setStatus(text, tone) {
    var node = card && card.querySelector('.mdv-email-status');
    if (!node) {
      return;
    }
    node.textContent = text;
    if (tone) {
      node.setAttribute('data-tone', tone);
    } else {
      node.removeAttribute('data-tone');
    }
  }

  function hideCard() {
    if (card) {
      card.hidden = true;
    }
  }

  function buildCard() {
    var c = getCopy();
    var section = document.createElement('section');
    section.className = 'mdv-email';
    section.hidden = true;
    section.setAttribute('dir', getEffectiveLocale() === 'ar' ? 'rtl' : 'ltr');
    section.setAttribute('aria-labelledby', 'mdv-email-title');

    var h2 = document.createElement('h2');
    h2.id = 'mdv-email-title';
    h2.textContent = c.emailTitle;
    section.appendChild(h2);

    var desc = document.createElement('p');
    desc.textContent = c.emailDesc;
    section.appendChild(desc);

    var form = document.createElement('form');
    form.noValidate = true;

    var input = document.createElement('input');
    input.type = 'email';
    input.name = 'email';
    input.setAttribute('inputmode', 'email');
    input.setAttribute('autocomplete', 'email');
    input.maxLength = 100;
    input.placeholder = c.emailPlaceholder;
    input.setAttribute('aria-label', c.emailAria);
    input.required = true;
    form.appendChild(input);

    var submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.textContent = c.save;
    form.appendChild(submitBtn);
    section.appendChild(form);

    var skip = document.createElement('button');
    skip.type = 'button';
    skip.className = 'mdv-email-skip';
    skip.textContent = c.notNow;
    section.appendChild(skip);

    var statusDiv = document.createElement('div');
    statusDiv.className = 'mdv-email-status';
    statusDiv.setAttribute('role', 'status');
    statusDiv.setAttribute('aria-live', 'polite');
    section.appendChild(statusDiv);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var copy = getCopy();
      var value = (input.value || '').trim();
      if (value === '') {
        setStatus(copy.enterEmail, 'error');
        input.focus();
        return;
      }
      setStatus(copy.saving, '');
      submitBtn.disabled = true;
      var locale = getEffectiveLocale();
      var payload = { chat_id: chatId, hash: chatHash, email: value, page_url: currentPageUrl };
      if (locale && locale !== 'en') {
        payload.lang = locale;
      }
      fetch(window.location.origin + '/wp-json/md/v1/chat/email', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (response) {
        return response.json().catch(function () {
          return {};
        }).then(function (data) {
          return { ok: response.ok, data: data };
        });
      }).then(function (result) {
        var latestCopy = getCopy();
        if (!result.ok) {
          var message = result.data && result.data.message ? result.data.message : latestCopy.checkEmail;
          setStatus(message, 'error');
          submitBtn.disabled = false;
          input.focus();
          return;
        }
        emailSaved = true;
        writeStore('mdv:emailSaved:' + chatId, '1');
        setStatus(latestCopy.saved, 'ok');
        setTimeout(hideCard, 900);
      }).catch(function () {
        setStatus(getCopy().saveError, 'error');
        submitBtn.disabled = false;
      });
    });

    skip.addEventListener('click', function () {
      writeStore(skipKey, '1');
      hideCard();
    });

    return section;
  }

  function maybeShow() {
    if (!block || !card || emailSaved || chatStatus === 2 || readStore(skipKey) === '1' || !visitorHasAnswer()) {
      if (card) {
        hideCard();
      }
      return;
    }
    if (card.hidden) {
      card.hidden = false;
    }
  }

  function latestRecommendedPattern() {
    var bodies = document.querySelectorAll('#messages .message-admin .msg-body');
    for (var b = bodies.length - 1; b >= 0; b -= 1) {
      var links = bodies[b].querySelectorAll('a[href*="/pattern/"]');
      for (var i = 0; i < links.length; i += 1) {
        var href = links[i].getAttribute('href') || '';
        var m = href.match(/\/pattern\/([a-z0-9-]+)\/?/i);
        if (m && m[1]) {
          var label = (links[i].textContent || '').replace(/\*\*/g, '').trim();
          var isUrlLabel = /^(?:https?:\/\/|\/)/i.test(label);
          var cleanName = isUrlLabel
            ? ''
            : label.replace(/\s*(?:\|.*|Laser\s+Cut\s+Screens?|Architectural\s+Metal\s+Screen\s+Patterns?)\s*$/i, '').trim();
          return {
            patternSlug: m[1].toLowerCase(),
            patternName: cleanName || (isUrlLabel ? '' : label),
            patternUrl: href
          };
        }
      }
    }
    return { patternSlug: '', patternName: '', patternUrl: '' };
  }

  document.addEventListener('click', function (event) {
    var quoteBtn = event.target && event.target.closest ? event.target.closest('[data-mdv-open-quote]') : null;
    if (!quoteBtn) {
      return;
    }
    event.preventDefault();
    if (window.parent && window.parent !== window) {
      var rec = latestRecommendedPattern();
      window.parent.postMessage({
        type: 'mdv_open_quote',
        pageUrl: currentPageUrl,
        patternSlug: rec.patternSlug,
        patternName: rec.patternName,
        patternUrl: rec.patternUrl
      }, window.location.origin);
    } else {
      var locale = getEffectiveLocale();
      var prefix = locale && locale !== 'en' ? '/' + locale : '';
      window.open(prefix + '/inquiry/', '_blank', 'noopener');
    }
  });

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin) {
      return;
    }
    if (event.data && event.data.type === 'mdv_host_context') {
      if (event.data.lang) {
        setLocale(String(event.data.lang), event.data.dir ? String(event.data.dir) : '');
      }
      if (event.data.pageUrl) {
        setPageUrl(event.data.pageUrl);
      }
    }
  });

  function start() {
    if (started) {
      return;
    }
    head = document.querySelector('.mdv-chat-head');
    if (head && (!currentLocale || currentLocale === 'en')) {
      var headLang = head.getAttribute('data-mdv-lang') || '';
      if (headLang && headLang !== 'en') {
        setLocale(headLang, head.getAttribute('dir') || '');
      }
    }
    updateWhatsAppLinks();
    if (!head) {
      return;
    }
    chatId = parseInt(head.getAttribute('data-chat-id') || '0', 10);
    chatHash = head.getAttribute('data-chat-hash') || '';
    chatStatus = parseInt(head.getAttribute('data-chat-status') || '0', 10);
    emailSaved = head.getAttribute('data-email-saved') === '1';
    skipKey = 'mdv:emailSkip:' + chatId;
    if (!chatId || !chatHash) {
      return;
    }
    wireDelete();
    if (currentPageUrl) {
      syncChatContext(currentPageUrl);
    }
    if (window.parent && window.parent !== window) {
      try {
        window.parent.postMessage({ type: 'mdv_request_host_context' }, window.location.origin);
      } catch (e) {}
    }

    block = document.getElementById('messagesBlock');
    if (!block) {
      return;
    }
    started = true;

    card = buildCard();
    var messages = document.getElementById('messages');
    if (messages && messages.parentNode) {
      messages.parentNode.insertBefore(card, messages);
    } else {
      block.parentNode.insertBefore(card, block);
    }

    rememberLastChat();
    maybeShow();
    if (window.MutationObserver) {
      new MutationObserver(function () {
        rememberLastChat();
        maybeShow();
      }).observe(block, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
