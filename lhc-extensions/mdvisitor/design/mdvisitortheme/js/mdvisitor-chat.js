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
    block = document.getElementById('messagesBlock');
    if (!head || !block) {
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
