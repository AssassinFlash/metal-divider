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
      if (event.key === 'Escape' && !box.hidden) {
        event.preventDefault();
        event.stopPropagation();
        closeBox();
      }
    }, true);

    yes.addEventListener('click', function () {
      yes.disabled = true;
      no.disabled = true;
      setStatus('Deleting…', '');
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
          } catch (e) {
            /* private mode: nothing persisted, nothing to clear */
          }
          window.location.assign(wrap.getAttribute('data-mdv-start-url') || '/chat/');
          return;
        }
        yes.disabled = false;
        no.disabled = false;
        setStatus('Could not delete this conversation right now. Please try again.', 'error');
      }).catch(function () {
        yes.disabled = false;
        no.disabled = false;
        setStatus('Could not delete this conversation right now. Please try again.', 'error');
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
    writeStore(lastStoreKey, JSON.stringify({
      url: window.location.pathname + window.location.search,
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
    var section = document.createElement('section');
    section.className = 'mdv-email';
    section.hidden = true;
    section.setAttribute('aria-labelledby', 'mdv-email-title');
    section.innerHTML = '<h2 id="mdv-email-title">Get a reply by email</h2>' +
      '<p>Optional. We only use it to reply to this inquiry. No account needed.</p>' +
      '<form novalidate>' +
        '<input type="email" name="email" inputmode="email" autocomplete="email" maxlength="100" placeholder="you@company.com" aria-label="Email address" required>' +
        '<button type="submit">Save</button>' +
      '</form>' +
      '<button type="button" class="mdv-email-skip">Not now</button>' +
      '<div class="mdv-email-status" role="status" aria-live="polite"></div>';

    var form = section.querySelector('form');
    var input = section.querySelector('input');
    var skip = section.querySelector('.mdv-email-skip');

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var value = (input.value || '').trim();
      if (value === '') {
        setStatus('Enter your email address.', 'error');
        input.focus();
        return;
      }
      setStatus('Saving...', '');
      var button = form.querySelector('button');
      button.disabled = true;
      fetch(window.location.origin + '/wp-json/md/v1/chat/email', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ chat_id: chatId, hash: chatHash, email: value })
      }).then(function (response) {
        return response.json().catch(function () {
          return {};
        }).then(function (data) {
          return { ok: response.ok, data: data };
        });
      }).then(function (result) {
        if (!result.ok) {
          var message = result.data && result.data.message ? result.data.message : 'Please check the email and try again.';
          setStatus(message, 'error');
          button.disabled = false;
          input.focus();
          return;
        }
        emailSaved = true;
        writeStore('mdv:emailSaved:' + chatId, '1');
        setStatus('Saved. The team can follow up by email.', 'ok');
        setTimeout(hideCard, 900);
      }).catch(function () {
        setStatus('Could not save right now. Please try again.', 'error');
        button.disabled = false;
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

  function start() {
    if (started) {
      return;
    }
    head = document.querySelector('.mdv-chat-head');
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
