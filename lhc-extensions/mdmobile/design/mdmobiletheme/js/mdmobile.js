(function (global) {
    'use strict';

    // LHC 4.97 settings.ini.default.php: chat_message_sinterval => 3.5
    var POLL_MS = 3500;
    var POLL_MAX_MS = 15000;
    var NEAR_BOTTOM_PX = 64;
    var KINDS = { visitor: true, operator: true, system: true, bot: true };
    var KIND_LABEL = { visitor: 'Visitor', operator: 'Operator', system: 'System', bot: 'Bot' };

    function parseAfter(value) {
        var parsed = parseInt(value, 10);
        if (isNaN(parsed) || parsed < 0) {
            return 0;
        }
        return parsed;
    }

    function kindOf(value) {
        var kind = String(value || '').toLowerCase();
        if (KINDS[kind]) {
            return kind;
        }
        return 'system';
    }

    function appendQuery(url, afterId) {
        if (/[?&]after_id=/.test(url)) {
            return url.replace(/([?&])after_id=[^&]*/, '$1after_id=' + encodeURIComponent(afterId));
        }
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'after_id=' + encodeURIComponent(afterId);
    }

    function withAfterId(url, afterId, base) {
        var value = String(parseAfter(afterId));
        var source = String(url || '');
        if (source === '') {
            return '';
        }
        if (source.charAt(0) !== '/' && !/^[a-z][a-z0-9+.-]*:/i.test(source)) {
            return appendQuery(source, value);
        }
        try {
            var parsed = new URL(source, base || 'http://local.invalid/');
            parsed.searchParams.set('after_id', value);
            if (/^[a-z][a-z0-9+.-]*:/i.test(source)) {
                return parsed.toString();
            }
            return parsed.pathname + parsed.search + parsed.hash;
        } catch (error) {
            return appendQuery(source, value);
        }
    }

    function buildSendRequest(text, csrf, sender) {
        var who = typeof sender === 'string' && sender !== '' ? sender : 'operator';
        return {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRFToken': String(csrf || ''),
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: 'msg=' + encodeURIComponent(text) + '&sender=' + encodeURIComponent(who)
        };
    }

    function insertTextAtCaret(field, text) {
        var value = text === null || text === undefined ? '' : String(text);
        if (!field || typeof field.value !== 'string') {
            return value;
        }
        if (value === '') {
            return field.value;
        }
        var start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
        var end = typeof field.selectionEnd === 'number' ? field.selectionEnd : start;
        if (start < 0) {
            start = 0;
        }
        if (start > field.value.length) {
            start = field.value.length;
        }
        if (end < start) {
            end = start;
        }
        if (end > field.value.length) {
            end = field.value.length;
        }
        field.value = field.value.slice(0, start) + value + field.value.slice(end);
        if (typeof field.setSelectionRange === 'function') {
            try {
                field.setSelectionRange(start + value.length, start + value.length);
            } catch (error) {
                return field.value;
            }
        }
        return field.value;
    }

    function buildRemarksRequest(value, csrf) {
        return {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'X-CSRFToken': String(csrf || ''),
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: 'data=' + encodeURIComponent(value === null || value === undefined ? '' : String(value))
        };
    }

    function buildCloseRequest(csrf) {
        return {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRFToken': String(csrf || ''),
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: 'ChatStatus=2'
        };
    }

    function sendSucceeded(data) {
        return !!data && typeof data === 'object' && (data.error === 'false' || data.error === false);
    }

    function plainError(text) {
        var cleaned = String(text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        if (cleaned.length > 180) {
            cleaned = cleaned.slice(0, 180);
        }
        return cleaned;
    }

    function momentFrom(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        if (typeof value === 'number' || /^\d+$/.test(String(value))) {
            var stamp = parseInt(value, 10);
            if (isNaN(stamp)) {
                return null;
            }
            if (stamp > 100000000000) {
                stamp = Math.floor(stamp / 1000);
            }
            if (stamp < 1) {
                return null;
            }
            var numeric = new Date(stamp * 1000);
            return isNaN(numeric.getTime()) ? null : numeric;
        }
        var text = String(value).trim();
        if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/i.test(text)) {
            return null;
        }
        var parsed = new Date(text);
        return isNaN(parsed.getTime()) ? null : parsed;
    }

    function formatMoment(date, style) {
        var hours = date.getHours();
        var minutes = date.getMinutes();
        var clock = (hours < 10 ? '0' : '') + hours + ':' + (minutes < 10 ? '0' : '') + minutes;
        if (style === 'datetime') {
            var month = date.getMonth() + 1;
            var day = date.getDate();
            return date.getFullYear() + '-' + (month < 10 ? '0' : '') + month + '-' + (day < 10 ? '0' : '') + day + ' ' + clock;
        }
        return clock;
    }

    function formatTime(value, style) {
        var moment = momentFrom(value);
        if (!moment) {
            if (value === null || value === undefined || value === '' || typeof value === 'number' || /^\d+$/.test(String(value))) {
                return '';
            }
            return String(value);
        }
        return formatMoment(moment, style === 'datetime' ? 'datetime' : 'time');
    }

    function timeNodes(scope) {
        var nodes = [];
        var seen = [];
        function add(node) {
            if (!node || seen.indexOf(node) !== -1) {
                return;
            }
            seen.push(node);
            nodes.push(node);
        }
        if (scope && scope.getAttribute && (scope.hasAttribute('data-timestamp') || (String(scope.tagName || '').toLowerCase() === 'time' && scope.hasAttribute('datetime')))) {
            add(scope);
        }
        if (scope && scope.querySelectorAll) {
            var stamped = scope.querySelectorAll('[data-timestamp], time[datetime]');
            var index;
            for (index = 0; index < stamped.length; index += 1) {
                add(stamped[index]);
            }
        }
        return nodes;
    }

    function renderLocalTimes(scope) {
        var nodes = timeNodes(scope);
        var index;
        for (index = 0; index < nodes.length; index += 1) {
            var node = nodes[index];
            var stamp = node.getAttribute('data-timestamp');
            if (!stamp) {
                var fromIso = momentFrom(node.getAttribute('datetime'));
                if (!fromIso) {
                    continue;
                }
                stamp = String(Math.floor(fromIso.getTime() / 1000));
                node.setAttribute('data-timestamp', stamp);
                if (!node.getAttribute('data-time-style')) {
                    node.setAttribute('data-time-style', 'time');
                }
            }
            var style = node.getAttribute('data-time-style') === 'datetime' ? 'datetime' : 'time';
            var label = formatTime(stamp, style);
            var moment = momentFrom(stamp);
            if (label === '' || !moment) {
                continue;
            }
            node.textContent = label;
            node.setAttribute('datetime', moment.toISOString());
            node.setAttribute('data-timestamp', String(Math.floor(moment.getTime() / 1000)));
            node.setAttribute('data-time-style', style);
        }
    }

    function isNearBottom(element, threshold) {
        if (!element) {
            return true;
        }
        var gap = element.scrollHeight - element.scrollTop - element.clientHeight;
        if (element.scrollHeight <= element.clientHeight + 1) {
            return true;
        }
        return gap <= threshold;
    }

    function createController(options) {
        var root = options.root;
        var list = root.querySelector('#md-messages');
        var draft = root.querySelector('#md-draft');
        var sendButton = root.querySelector('#md-send');
        var statusEl = root.querySelector('#md-status');
        var fetchImpl = options.fetch;
        var storage = options.storage;
        var doc = options.document || global.document;
        var view = options.window || global;
        var setTimeoutImpl = options.setTimeout || global.setTimeout;
        var clearTimeoutImpl = options.clearTimeout || global.clearTimeout;
        var timer = null;
        var stopped = false;
        var started = false;
        var sending = false;
        var polling = null;
        var pollWait = POLL_MS;
        var stickyError = false;
        var statusToken = 0;
        var closeBlocks = [];
        var closing = false;
        var closed = root.getAttribute('data-state') === 'closed';

        function chatClosed() {
            return closed;
        }

        function closeRemoteAllowed() {
            return root.getAttribute('data-allow-close-remote') === '1';
        }

        function openClosedAllowed() {
            return root.getAttribute('data-allow-open-closed') === '1';
        }

        function writeAllowed() {
            return root.getAttribute('data-can-write') === '1';
        }

        function chatId() {
            return root.getAttribute('data-chat-id') || '';
        }

        function draftKey() {
            return 'mdmobile-draft-' + chatId();
        }

        function cursor() {
            return parseAfter(root.getAttribute('data-after-id'));
        }

        function setCursor(value) {
            var next = parseAfter(value);
            if (next >= cursor()) {
                root.setAttribute('data-after-id', String(next));
            }
        }

        function pageHidden() {
            return !!(doc && doc.visibilityState === 'hidden');
        }

        function setStatus(text, isError, sticky) {
            statusEl.textContent = text || '';
            if (isError) {
                statusEl.classList.add('is-error');
            } else {
                statusEl.classList.remove('is-error');
            }
            stickyError = !!sticky;
            statusToken += 1;
        }

        function setStatusIfIdle(text, isError) {
            if (sending || stickyError) {
                return;
            }
            setStatus(text, isError, false);
        }

        function flash(text) {
            var token = statusToken + 1;
            stickyError = false;
            statusEl.textContent = text;
            statusEl.classList.remove('is-error');
            statusToken = token;
            setTimeoutImpl(function () {
                if (statusToken === token && !stickyError && !sending) {
                    statusEl.textContent = '';
                }
            }, 2000);
        }

        function persistDraft() {
            if (!storage) {
                return;
            }
            try {
                if (draft.value === '') {
                    storage.removeItem(draftKey());
                } else {
                    storage.setItem(draftKey(), draft.value);
                }
            } catch (error) {
                return;
            }
        }

        function restoreDraft() {
            if (!storage || draft.value !== '' || chatClosed()) {
                return;
            }
            try {
                var saved = storage.getItem(draftKey());
                if (typeof saved === 'string' && saved !== '') {
                    draft.value = saved;
                }
            } catch (error) {
                return;
            }
        }

        function grow() {
            draft.style.height = 'auto';
            var next = draft.scrollHeight;
            if (next > 120) {
                next = 120;
            }
            if (next < 44) {
                next = 44;
            }
            draft.style.height = next + 'px';
        }

        function syncButton() {
            var empty = draft.value.replace(/^\s+|\s+$/g, '') === '';
            sendButton.disabled = sending || empty || chatClosed();
        }

        function scrollToEnd() {
            list.scrollTop = list.scrollHeight;
        }

        function hasId(id) {
            return !!list.querySelector('[data-id="' + String(id) + '"]');
        }

        function removeEmpty() {
            var empty = list.querySelector('#md-empty');
            if (empty && empty.parentNode) {
                empty.parentNode.removeChild(empty);
            }
        }

        function appendMessage(message) {
            var id = parseInt(message && message.id, 10);
            var text = message && message.text !== undefined && message.text !== null ? String(message.text) : '';
            if (isNaN(id) || id < 1 || text.replace(/^\s+|\s+$/g, '') === '' || hasId(id)) {
                return false;
            }
            var kind = kindOf(message.kind);
            var row = doc.createElement('div');
            row.className = 'md-row md-row-' + kind;
            row.setAttribute('data-id', String(id));
            row.setAttribute('data-kind', kind);
            var bubble = doc.createElement('div');
            bubble.className = 'md-bubble';
            var label = doc.createElement('span');
            label.textContent = KIND_LABEL[kind];
            label.className = kind === 'bot' ? 'md-kicker' : 'md-sr';
            var body = doc.createElement('p');
            body.className = 'md-text';
            body.textContent = text;
            bubble.appendChild(label);
            bubble.appendChild(body);
            var rawTime = message.time;
            if ((rawTime === undefined || rawTime === null || rawTime === '') && message.timestamp !== undefined) {
                rawTime = message.timestamp;
            }
            var moment = momentFrom(rawTime);
            if (moment || formatTime(rawTime) !== '') {
                var time = doc.createElement('time');
                time.className = 'md-time';
                time.setAttribute('data-time-style', 'time');
                if (moment) {
                    time.setAttribute('data-timestamp', String(Math.floor(moment.getTime() / 1000)));
                    time.setAttribute('datetime', moment.toISOString());
                }
                time.textContent = formatTime(rawTime, 'time');
                bubble.appendChild(time);
                renderLocalTimes(time);
            }
            row.appendChild(bubble);
            list.appendChild(row);
            return true;
        }

        function appendMessages(messages, forceScroll) {
            var near = forceScroll || isNearBottom(list, NEAR_BOTTOM_PX);
            var added = false;
            var index;
            for (index = 0; index < messages.length; index += 1) {
                if (appendMessage(messages[index])) {
                    added = true;
                    var id = parseInt(messages[index].id, 10);
                    if (!isNaN(id)) {
                        setCursor(id);
                    }
                }
            }
            if (added) {
                removeEmpty();
                if (near) {
                    scrollToEnd();
                }
            }
            return added;
        }

        function readPayload(response) {
            return response.text().then(function (text) {
                var data = null;
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    data = null;
                }
                return { ok: response.ok, status: response.status, text: text, data: data };
            });
        }

        function poll(force) {
            if (polling && !force) {
                return polling;
            }
            if (pageHidden()) {
                return Promise.resolve(false);
            }
            var url = withAfterId(root.getAttribute('data-messages-url') || '', cursor(), view.location ? view.location.href : undefined);
            if (url === '') {
                setStatusIfIdle('This page is missing the message address', true);
                return Promise.resolve(false);
            }
            if (typeof fetchImpl !== 'function') {
                setStatusIfIdle('This browser cannot refresh messages', true);
                return Promise.resolve(false);
            }
            var request = fetchImpl(url, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            }).then(readPayload).then(function (result) {
                var data = result.data;
                if (!result.ok || !data || typeof data !== 'object' || !Array.isArray(data.messages)) {
                    pollWait = Math.min(POLL_MAX_MS, pollWait * 2);
                    if (!chatClosed()) {
                        setStatusIfIdle('Messages could not be refreshed', true);
                    }
                    return false;
                }
                if (data.latest_id !== undefined && data.latest_id !== null && String(data.latest_id) !== '') {
                    setCursor(data.latest_id);
                }
                appendMessages(data.messages, false);
                pollWait = POLL_MS;
                if (!sending && !stickyError && statusEl.classList.contains('is-error')) {
                    setStatus('', false, false);
                }
                return true;
            }).catch(function () {
                pollWait = Math.min(POLL_MAX_MS, pollWait * 2);
                if (!chatClosed()) {
                    setStatusIfIdle('Messages could not be refreshed', true);
                }
                return false;
            });
            var tracked;
            tracked = request.then(function (ok) {
                if (polling === tracked) {
                    polling = null;
                }
                return ok;
            });
            polling = tracked;
            return tracked;
        }

        function clearTimer() {
            if (timer) {
                clearTimeoutImpl(timer);
                timer = null;
            }
        }

        function schedule(delay) {
            if (stopped) {
                return;
            }
            clearTimer();
            if (pageHidden()) {
                return;
            }
            timer = setTimeoutImpl(function () {
                timer = null;
                poll().then(function () {
                    schedule(pollWait);
                });
            }, delay);
        }

        function releaseSentText(sent) {
            if (draft.value === sent) {
                draft.value = '';
            } else if (sent !== '' && draft.value.indexOf(sent) === 0) {
                draft.value = draft.value.slice(sent.length);
            }
            persistDraft();
            grow();
            syncButton();
        }

        function send() {
            var text = draft.value.replace(/^\s+|\s+$/g, '');
            if (chatClosed()) {
                setStatus('This conversation is closed', true, true);
                return Promise.resolve(false);
            }
            if (closing) {
                setStatus('Wait for the close request to finish', true, true);
                return Promise.resolve(false);
            }
            if (sending) {
                return Promise.resolve(false);
            }
            if (text === '') {
                setStatus('Type a message', true, true);
                return Promise.resolve(false);
            }
            var url = root.getAttribute('data-send-url') || '';
            if (url === '') {
                setStatus('This page is missing the send address', true, true);
                return Promise.resolve(false);
            }
            if (typeof fetchImpl !== 'function') {
                setStatus('This browser cannot send messages', true, true);
                return Promise.resolve(false);
            }
            var sent = draft.value;
            var request = buildSendRequest(text, root.getAttribute('data-csrf') || '', root.getAttribute('data-sender') || '');
            sending = true;
            stickyError = false;
            syncButton();
            setStatus('Sending', false, false);
            return fetchImpl(url, request).then(readPayload).then(function (result) {
                var data = result.data;
                if (data && typeof data.token === 'string' && data.token !== '') {
                    root.setAttribute('data-csrf', data.token);
                }
                if (result.ok && sendSucceeded(data)) {
                    releaseSentText(sent);
                    markChatStatusAccepted();
                    flash('Sent');
                    return poll(true).then(function () {
                        scrollToEnd();
                        return true;
                    });
                }
                var reason = data && typeof data.r === 'string' ? plainError(data.r) : plainError(result.text);
                setStatus(reason || 'Could not send. Your draft is still here.', true, true);
                persistDraft();
                return false;
            }).catch(function () {
                setStatus('Could not send. Your draft is still here.', true, true);
                persistDraft();
                return false;
            }).then(function (ok) {
                sending = false;
                syncButton();
                schedule(pollWait);
                return ok;
            });
        }

        function viewportBox(viewport) {
            return {
                top: viewport.offsetTop || 0,
                left: viewport.offsetLeft || 0,
                width: viewport.width,
                height: viewport.height
            };
        }

        function keyboardOpen(viewport) {
            if (!viewport || !viewport.height) {
                return false;
            }
            var box = viewportBox(viewport);
            if (box.top > 1 || box.left > 1) {
                return true;
            }
            var layoutHeight = view.innerHeight || 0;
            return !!(layoutHeight && box.height < layoutHeight - 40);
        }

        function clearStyle(element, name) {
            if (!element || !element.style) {
                return;
            }
            if (element.style.removeProperty) {
                element.style.removeProperty(name);
            }
            element.style[name] = '';
        }

        function pinBox(element, box) {
            if (!element || !element.style) {
                return;
            }
            element.style.position = 'fixed';
            element.style.top = box.top + 'px';
            element.style.left = box.left + 'px';
            element.style.right = 'auto';
            element.style.bottom = 'auto';
            element.style.width = box.width + 'px';
            element.style.height = box.height + 'px';
            element.style.transform = 'none';
        }

        function clearKeyboardPin() {
            if (root.classList) {
                root.classList.remove('is-keyboard');
            }
            clearStyle(root, '--md-vv-top');
            clearStyle(root, '--md-vv-left');
            clearStyle(root, '--md-vv-width');
            clearStyle(root, '--md-vv-height');
            clearStyle(root, 'position');
            clearStyle(root, 'top');
            clearStyle(root, 'left');
            clearStyle(root, 'right');
            clearStyle(root, 'bottom');
            clearStyle(root, 'width');
            clearStyle(root, 'height');
            clearStyle(root, 'transform');
            clearStyle(root, 'maxHeight');
            clearStyle(sheet, 'position');
            clearStyle(sheet, 'top');
            clearStyle(sheet, 'left');
            clearStyle(sheet, 'right');
            clearStyle(sheet, 'bottom');
            clearStyle(sheet, 'width');
            clearStyle(sheet, 'height');
            clearStyle(sheet, 'transform');
        }

        function pinKeyboard() {
            var viewport = view.visualViewport;
            if (!viewport) {
                return;
            }
            if (!keyboardOpen(viewport)) {
                clearKeyboardPin();
                if (viewport.height) {
                    root.style.height = viewport.height + 'px';
                }
                return;
            }
            var box = viewportBox(viewport);
            if (root.classList) {
                root.classList.add('is-keyboard');
            }
            if (root.style && root.style.setProperty) {
                root.style.setProperty('--md-vv-top', box.top + 'px');
                root.style.setProperty('--md-vv-left', box.left + 'px');
                root.style.setProperty('--md-vv-width', box.width + 'px');
                root.style.setProperty('--md-vv-height', box.height + 'px');
            }
            root.style['--md-vv-top'] = box.top + 'px';
            root.style['--md-vv-left'] = box.left + 'px';
            root.style['--md-vv-width'] = box.width + 'px';
            root.style['--md-vv-height'] = box.height + 'px';
            root.style.maxHeight = 'none';
            pinBox(root, box);
            pinBox(sheet, box);
        }

        function onVisible() {
            if (!pageHidden()) {
                schedule(0);
            }
        }

        function onKeyDown(event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                send();
            }
        }

        var detailsButton = root.querySelector('#md-details');
        var sheet = root.querySelector('#md-sheet');
        var drawer = root.querySelector('#md-drawer');
        var mask = root.querySelector('#md-mask');
        var closeButton = root.querySelector('#md-drawer-close');
        var lastFocus = null;
        var drawerListening = false;

        function sheetClosed() {
            return !sheet || sheet.hasAttribute('hidden');
        }

        function setSheetClosed(closed) {
            if (!sheet) {
                return;
            }
            if (closed) {
                sheet.setAttribute('hidden', '');
                sheet.hidden = true;
            } else {
                sheet.removeAttribute('hidden');
                sheet.hidden = false;
            }
        }

        function setBackdropHidden(hidden) {
            var nodes = root.querySelectorAll('[data-md-backdrop]');
            var index;
            for (index = 0; index < nodes.length; index += 1) {
                if (hidden) {
                    nodes[index].setAttribute('aria-hidden', 'true');
                    nodes[index].setAttribute('inert', '');
                } else {
                    nodes[index].removeAttribute('aria-hidden');
                    nodes[index].removeAttribute('inert');
                }
            }
        }

        function focusableIn(container) {
            if (!container || !container.querySelectorAll) {
                return [];
            }
            var nodes = container.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled])');
            var out = [];
            var index;
            for (index = 0; index < nodes.length; index += 1) {
                if (!nodes[index].hasAttribute('disabled')) {
                    out.push(nodes[index]);
                }
            }
            return out;
        }

        function onDrawerKey(event) {
            if (sheetClosed()) {
                return;
            }
            if (event.key === 'Escape' || event.key === 'Esc') {
                event.preventDefault();
                closeDrawer();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            var items = focusableIn(drawer);
            if (items.length === 0) {
                event.preventDefault();
                if (drawer && drawer.focus) {
                    drawer.focus();
                }
                return;
            }
            var first = items[0];
            var last = items[items.length - 1];
            var active = doc ? doc.activeElement : null;
            var inside = active === drawer || items.indexOf(active) !== -1;
            if (!inside) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
                return;
            }
            if (event.shiftKey && (active === first || active === drawer)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (active === last || active === drawer)) {
                event.preventDefault();
                first.focus();
            }
        }

        function listenDrawer(on) {
            if (!doc || !doc.addEventListener || !doc.removeEventListener) {
                return;
            }
            if (on && !drawerListening) {
                doc.addEventListener('keydown', onDrawerKey, true);
                drawerListening = true;
            } else if (!on && drawerListening) {
                doc.removeEventListener('keydown', onDrawerKey, true);
                drawerListening = false;
            }
        }

        function openDrawer() {
            if (!detailsButton || !sheet || !drawer || !sheetClosed()) {
                return false;
            }
            lastFocus = doc ? doc.activeElement : null;
            setSheetClosed(false);
            pinKeyboard();
            detailsButton.setAttribute('aria-expanded', 'true');
            setBackdropHidden(true);
            listenDrawer(true);
            if (drawer.focus) {
                drawer.focus();
            }
            if ((!doc || doc.activeElement !== drawer) && closeButton && closeButton.focus) {
                closeButton.focus();
            }
            return true;
        }

        function closeDrawer() {
            if (!sheet || sheetClosed()) {
                return false;
            }
            setSheetClosed(true);
            if (detailsButton) {
                detailsButton.setAttribute('aria-expanded', 'false');
            }
            setBackdropHidden(false);
            listenDrawer(false);
            var back = detailsButton;
            if (lastFocus && lastFocus.focus && root.contains && root.contains(lastFocus)) {
                back = lastFocus;
            }
            if (back && back.focus) {
                back.focus();
            }
            return true;
        }

        function bindDetails() {
            if (!detailsButton || !sheet || !drawer || detailsButton.getAttribute('data-md-bound') === '1') {
                return;
            }
            detailsButton.setAttribute('data-md-bound', '1');
            detailsButton.addEventListener('click', function () {
                if (sheetClosed()) {
                    openDrawer();
                } else {
                    closeDrawer();
                }
            });
            if (closeButton) {
                closeButton.addEventListener('click', closeDrawer);
            }
            if (mask) {
                mask.addEventListener('click', closeDrawer);
            }
        }

        var quickButton = root.querySelector('#md-quick');
        var cannedSheet = root.querySelector('#md-canned-sheet');
        var cannedDialog = root.querySelector('#md-canned-dialog');
        var cannedMask = root.querySelector('#md-canned-mask');
        var cannedClose = root.querySelector('#md-canned-close');
        var cannedListening = false;
        var cannedLastFocus = null;

        function cannedClosed() {
            return !cannedSheet || cannedSheet.hasAttribute('hidden');
        }

        function setCannedClosed(closed) {
            if (!cannedSheet) {
                return;
            }
            if (closed) {
                cannedSheet.setAttribute('hidden', '');
                cannedSheet.hidden = true;
            } else {
                cannedSheet.removeAttribute('hidden');
                cannedSheet.hidden = false;
            }
        }

        function onCannedKey(event) {
            if (cannedClosed()) {
                return;
            }
            if (event.key === 'Escape' || event.key === 'Esc') {
                event.preventDefault();
                closeCanned();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            var items = focusableIn(cannedDialog);
            if (items.length === 0) {
                event.preventDefault();
                if (cannedDialog && cannedDialog.focus) {
                    cannedDialog.focus();
                }
                return;
            }
            var first = items[0];
            var last = items[items.length - 1];
            var active = doc ? doc.activeElement : null;
            var inside = active === cannedDialog || items.indexOf(active) !== -1;
            if (!inside) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
                return;
            }
            if (event.shiftKey && (active === first || active === cannedDialog)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (active === last || active === cannedDialog)) {
                event.preventDefault();
                first.focus();
            }
        }

        function listenCanned(on) {
            if (!doc || !doc.addEventListener || !doc.removeEventListener) {
                return;
            }
            if (on && !cannedListening) {
                doc.addEventListener('keydown', onCannedKey, true);
                cannedListening = true;
            } else if (!on && cannedListening) {
                doc.removeEventListener('keydown', onCannedKey, true);
                cannedListening = false;
            }
        }

        function openCanned() {
            if (chatClosed() || !quickButton || !cannedSheet || !cannedDialog || !cannedClosed()) {
                return false;
            }
            if (!sheetClosed()) {
                closeDrawer();
            }
            cannedLastFocus = doc ? doc.activeElement : null;
            if (draft && draft.blur) {
                draft.blur();
            }
            setCannedClosed(false);
            pinKeyboard();
            quickButton.setAttribute('aria-expanded', 'true');
            setBackdropHidden(true);
            listenCanned(true);
            var firstItem = cannedDialog.querySelector('.md-canned-item');
            if (firstItem && firstItem.focus) {
                firstItem.focus();
            }
            if ((!doc || doc.activeElement !== firstItem) && cannedDialog.focus) {
                cannedDialog.focus();
            }
            return true;
        }

        function closeCanned() {
            if (!cannedSheet || cannedClosed()) {
                return false;
            }
            setCannedClosed(true);
            if (quickButton) {
                quickButton.setAttribute('aria-expanded', 'false');
            }
            setBackdropHidden(false);
            listenCanned(false);
            var back = quickButton;
            if (cannedLastFocus && cannedLastFocus.focus && root.contains && root.contains(cannedLastFocus)) {
                back = cannedLastFocus;
            }
            if (back && back.focus) {
                back.focus();
            }
            return true;
        }

        function applyQuickReply(item) {
            if (chatClosed() || !item || !draft) {
                return false;
            }
            var textNode = item.querySelector('.md-canned-text');
            var text = textNode ? String(textNode.textContent || '') : '';
            if (text === '') {
                return false;
            }
            insertTextAtCaret(draft, text);
            persistDraft();
            grow();
            syncButton();
            closeCanned();
            if (draft.focus) {
                draft.focus();
            }
            return true;
        }

        function bindQuick() {
            if (!quickButton || !cannedSheet || !cannedDialog || quickButton.getAttribute('data-md-bound') === '1') {
                return;
            }
            quickButton.setAttribute('data-md-bound', '1');
            quickButton.addEventListener('click', function () {
                if (cannedClosed()) {
                    openCanned();
                } else {
                    closeCanned();
                }
            });
            if (cannedClose) {
                cannedClose.addEventListener('click', closeCanned);
            }
            if (cannedMask) {
                cannedMask.addEventListener('click', closeCanned);
            }
            var nodes = cannedDialog.querySelectorAll('.md-canned-item');
            var index;
            for (index = 0; index < nodes.length; index += 1) {
                nodes[index].addEventListener('click', function (event) {
                    if (applyQuickReply(event.currentTarget)) {
                        event.preventDefault();
                    }
                });
            }
        }

        var remarkBlocks = [];

        function setRemarkStatus(state, text, isError) {
            if (!state || !state.status) {
                return;
            }
            state.status.textContent = text || '';
            if (isError) {
                state.status.classList.add('is-error');
            } else {
                state.status.classList.remove('is-error');
            }
        }

        function remarkFailureText(result, data) {
            var raw = data && typeof data.result === 'string' ? data.result : '';
            if (raw === '') {
                raw = result && typeof result.text === 'string' ? result.text : '';
            }
            var text = plainError(raw);
            return text === '' ? 'Could not save remarks' : text;
        }

        function mirrorRemark(source, value) {
            var index;
            for (index = 0; index < remarkBlocks.length; index += 1) {
                var other = remarkBlocks[index];
                if (other === source || other.input === source.input) {
                    continue;
                }
                if (doc && doc.activeElement === other.input) {
                    continue;
                }
                if (other.timer) {
                    clearTimeoutImpl(other.timer);
                    other.timer = null;
                }
                if (other.input.value !== value) {
                    other.input.value = value;
                }
                other.saved = value;
            }
        }

        function scheduleRemarks(state) {
            if (!state) {
                return;
            }
            if (state.timer) {
                clearTimeoutImpl(state.timer);
            }
            state.timer = setTimeoutImpl(function () {
                state.timer = null;
                saveRemarks(state);
            }, 600);
        }

        function saveRemarks(state) {
            if (!state) {
                return;
            }
            if (state.timer) {
                clearTimeoutImpl(state.timer);
                state.timer = null;
            }
            var value = state.input.value;
            if (state.saving) {
                state.pending = true;
                return;
            }
            if (value === state.saved) {
                return;
            }
            if (typeof fetchImpl !== 'function') {
                setRemarkStatus(state, 'This browser cannot save remarks', true);
                return;
            }
            state.saving = true;
            fetchImpl(state.url, buildRemarksRequest(value, root.getAttribute('data-csrf') || '')).then(readPayload).then(function (result) {
                var data = result.data;
                if (data && typeof data.token === 'string' && data.token !== '') {
                    root.setAttribute('data-csrf', data.token);
                }
                if (result.ok && sendSucceeded(data)) {
                    state.saved = value;
                    setRemarkStatus(state, 'Saved', false);
                    mirrorRemark(state, value);
                } else {
                    setRemarkStatus(state, remarkFailureText(result, data), true);
                }
            }).catch(function () {
                setRemarkStatus(state, 'Could not save remarks', true);
            }).then(function () {
                state.saving = false;
                if (state.pending) {
                    state.pending = false;
                    if (state.input.value !== state.saved) {
                        scheduleRemarks(state);
                    }
                }
            });
        }

        function bindRemarkBlock(block) {
            if (!block || block.getAttribute('data-md-bound') === '1') {
                return;
            }
            var input = block.querySelector('[data-md-remarks-input]');
            var status = block.querySelector('[data-md-remarks-status]');
            var url = block.getAttribute('data-save-url') || '';
            if (!input || !status || url === '') {
                return;
            }
            block.setAttribute('data-md-bound', '1');
            var state = {
                block: block,
                input: input,
                status: status,
                url: url,
                saved: input.value,
                timer: null,
                saving: false,
                pending: false
            };
            remarkBlocks.push(state);
            input.addEventListener('input', function () {
                scheduleRemarks(state);
            });
            input.addEventListener('blur', function () {
                saveRemarks(state);
            });
        }

        function bindRemarks() {
            var nodes = null;
            if (doc && doc.querySelectorAll) {
                nodes = doc.querySelectorAll('[data-md-remarks]');
            }
            if (!nodes) {
                return;
            }
            var index;
            for (index = 0; index < nodes.length; index += 1) {
                bindRemarkBlock(nodes[index]);
            }
        }

        function flushRemarks() {
            var index;
            for (index = 0; index < remarkBlocks.length; index += 1) {
                var state = remarkBlocks[index];
                if (state.timer) {
                    clearTimeoutImpl(state.timer);
                    state.timer = null;
                    saveRemarks(state);
                }
            }
        }

        function setCloseStatus(state, text, isError) {
            if (!state || !state.status) {
                return;
            }
            state.status.textContent = text || '';
            if (isError) {
                state.status.classList.add('is-error');
            } else {
                state.status.classList.remove('is-error');
            }
        }

        function closePanel(state, focusTrigger) {
            if (!state || !state.panel) {
                return;
            }
            state.panel.hidden = true;
            if (state.trigger) {
                state.trigger.setAttribute('aria-expanded', 'false');
                if (focusTrigger && state.trigger.focus) {
                    state.trigger.focus();
                }
            }
        }

        function openClosePanel(state) {
            if (!state || !state.panel || !state.trigger || chatClosed()) {
                return;
            }
            state.panel.hidden = false;
            state.trigger.setAttribute('aria-expanded', 'true');
            if (state.cancel && state.cancel.focus) {
                state.cancel.focus();
            }
        }

        function setChatStatusPill(label, tone) {
            var pill = root.querySelector('#md-chat-status');
            if (!pill) {
                return;
            }
            while (pill.firstChild) {
                pill.removeChild(pill.firstChild);
            }
            if (doc && doc.createElement) {
                var dot = doc.createElement('span');
                dot.className = 'md-dot';
                dot.setAttribute('aria-hidden', 'true');
                pill.appendChild(dot);
                pill.appendChild(doc.createTextNode(label));
            } else {
                pill.textContent = label;
            }
            pill.setAttribute('data-tone', tone);
        }

        function chatStatusPillText() {
            var pill = root.querySelector('#md-chat-status');
            if (!pill) {
                return '';
            }
            return String(pill.textContent || '').replace(/^\s+|\s+$/g, '');
        }

        // Sending from a pending chat accepts it: LHC flips the status to active
        // and posts "has accepted the pending chat by sending a message". Without
        // this the server-rendered pill keeps saying Pending until a reload.
        function markChatStatusAccepted() {
            var label = chatStatusPillText().toLowerCase();
            if (label !== 'pending' && label !== 'waiting' && label !== '等待') {
                return;
            }
            setChatStatusPill('Active', 'online');
            markRailStatus('Active', 'online');
        }

        function markChatStatusClosed() {
            setChatStatusPill('Closed', 'offline');
        }

        function markRailStatus(label, tone) {
            var id = chatId();
            if (!doc || !doc.getElementById || id === '') {
                return;
            }
            var rail = doc.getElementById('md-inbox');
            var links = rail && rail.querySelectorAll ? rail.querySelectorAll('.md-thread-link') : [];
            for (var index = 0; index < links.length; index += 1) {
                if (links[index].getAttribute && links[index].getAttribute('data-id') === id) {
                    var status = links[index].querySelector ? links[index].querySelector('.md-thread-status') : null;
                    if (status) {
                        status.textContent = label;
                        status.setAttribute('data-tone', tone);
                    }
                    return;
                }
            }
        }

        function markRailClosed() {
            markRailStatus('Closed', 'offline');
        }

        function applyClosedState() {
            closed = true;
            root.setAttribute('data-state', 'closed');
            var index;
            for (index = 0; index < closeBlocks.length; index += 1) {
                var state = closeBlocks[index];
                if (state.note) {
                    state.note.hidden = false;
                }
                if (state.trigger) {
                    state.trigger.hidden = true;
                    state.trigger.setAttribute('aria-expanded', 'false');
                }
                if (state.panel) {
                    state.panel.hidden = true;
                }
                setCloseStatus(state, '', false);
            }
            if (draft) {
                draft.disabled = true;
            }
            if (sendButton) {
                sendButton.disabled = true;
            }
            if (quickButton) {
                closeCanned();
                quickButton.hidden = true;
            }
            if (sheet && !sheetClosed()) {
                closeDrawer();
            }
            if (!openClosedAllowed() || !writeAllowed()) {
                for (index = 0; index < remarkBlocks.length; index += 1) {
                    var remark = remarkBlocks[index];
                    if (remark.timer) {
                        clearTimeoutImpl(remark.timer);
                        remark.timer = null;
                    }
                    if (remark.input) {
                        remark.input.disabled = true;
                    }
                    setRemarkStatus(remark, 'Read-only after closing', false);
                }
            }
            markChatStatusClosed();
            markRailClosed();
            setStatus('Conversation closed', false, false);
        }

        function closeChat(state) {
            if (!state || closing) {
                return Promise.resolve(false);
            }
            if (chatClosed()) {
                setCloseStatus(state, 'This conversation is already closed.', false);
                return Promise.resolve(false);
            }
            if (sending) {
                setCloseStatus(state, 'Wait for the message to finish sending.', true);
                return Promise.resolve(false);
            }
            if (state.url === '') {
                setCloseStatus(state, 'This page is missing the close address.', true);
                return Promise.resolve(false);
            }
            if (typeof fetchImpl !== 'function') {
                setCloseStatus(state, 'This browser cannot close conversations.', true);
                return Promise.resolve(false);
            }
            closing = true;
            if (state.submit) {
                state.submit.disabled = true;
            }
            if (state.cancel) {
                state.cancel.disabled = true;
            }
            setCloseStatus(state, 'Closing…', false);
            return fetchImpl(state.url, buildCloseRequest(root.getAttribute('data-csrf') || '')).then(readPayload).then(function (result) {
                var data = result.data;
                if (data && typeof data.token === 'string' && data.token !== '') {
                    root.setAttribute('data-csrf', data.token);
                }
                if (!result.ok || !sendSucceeded(data)) {
                    var reason = '';
                    if (data && typeof data.result === 'string') {
                        reason = plainError(data.result);
                    } else if (data === null) {
                        // Native changestatus.php answers an HTML page (no JSON) when
                        // hasAccessToRead/hasAccessToWrite denies the request.
                        reason = 'The close request was not accepted. Reload this page and try again.';
                    }
                    setCloseStatus(state, reason || plainError(result.text) || 'Could not close this conversation.', true);
                    return false;
                }
                if (data.is_owner !== true && !closeRemoteAllowed()) {
                    setCloseStatus(state, 'Close was not confirmed: this chat is assigned to another operator and this account cannot close remote chats. Reload this page to check its status.', true);
                    return false;
                }
                applyClosedState();
                if (state.note && state.note.focus) {
                    state.note.setAttribute('tabindex', '-1');
                    state.note.focus();
                }
                return true;
            }).catch(function () {
                setCloseStatus(state, 'Could not close this conversation.', true);
                return false;
            }).then(function (ok) {
                closing = false;
                if (!chatClosed()) {
                    if (state.submit) {
                        state.submit.disabled = false;
                    }
                    if (state.cancel) {
                        state.cancel.disabled = false;
                    }
                    // Keep the panel open on failure so the error stays visible.
                    if (ok) {
                        closePanel(state, false);
                    }
                }
                if (ok) {
                    schedule(pollWait);
                }
                return ok;
            });
        }

        function bindCloseBlock(block) {
            if (!block || block.getAttribute('data-md-bound') === '1') {
                return;
            }
            block.setAttribute('data-md-bound', '1');
            var state = {
                block: block,
                trigger: block.querySelector('[data-md-close-trigger]'),
                panel: block.querySelector('[data-md-close-panel]'),
                cancel: block.querySelector('[data-md-close-cancel]'),
                submit: block.querySelector('[data-md-close-submit]'),
                note: block.querySelector('[data-md-close-note]'),
                status: block.querySelector('[data-md-close-status]'),
                url: block.getAttribute('data-close-url') || ''
            };
            closeBlocks.push(state);
            if (!state.trigger || !state.panel || !state.submit) {
                return;
            }
            state.trigger.addEventListener('click', function () {
                if (state.panel.hidden) {
                    openClosePanel(state);
                } else {
                    closePanel(state, true);
                }
            });
            if (state.cancel) {
                state.cancel.addEventListener('click', function () {
                    closePanel(state, true);
                });
            }
            state.submit.addEventListener('click', function () {
                closeChat(state);
            });
            block.addEventListener('keydown', function (event) {
                if ((event.key === 'Escape' || event.key === 'Esc') && !state.panel.hidden) {
                    event.preventDefault();
                    closePanel(state, true);
                }
            });
        }

        function bindClose() {
            // The desktop context column lives outside #md-mobile-app, so the
            // binding walks the whole document, like the remarks blocks do.
            var scope = doc && doc.querySelectorAll ? doc : root;
            var nodes = scope.querySelectorAll('[data-md-close]');
            var index;
            for (index = 0; index < nodes.length; index += 1) {
                bindCloseBlock(nodes[index]);
            }
        }

        function onRemarksHidden() {
            if (pageHidden()) {
                flushRemarks();
            }
        }

        function start() {
            if (started) {
                return;
            }
            started = true;
            renderLocalTimes(root);
            bindDetails();
            bindQuick();
            bindRemarks();
            bindClose();
            restoreDraft();
            grow();
            syncButton();
            scrollToEnd();
            if (chatClosed()) {
                applyClosedState();
            }
            draft.addEventListener('input', function () {
                persistDraft();
                grow();
                syncButton();
            });
            draft.addEventListener('keydown', onKeyDown);
            sendButton.addEventListener('click', function () {
                send();
            });
            if (doc && doc.addEventListener) {
                doc.addEventListener('visibilitychange', onVisible);
                doc.addEventListener('visibilitychange', onRemarksHidden);
            }
            if (view.addEventListener) {
                view.addEventListener('pagehide', persistDraft);
                if (view.visualViewport && view.visualViewport.addEventListener) {
                    view.visualViewport.addEventListener('resize', pinKeyboard);
                    view.visualViewport.addEventListener('scroll', pinKeyboard);
                    pinKeyboard();
                }
            }
            schedule(0);
        }

        function stop() {
            stopped = true;
            clearTimer();
            flushRemarks();
            if (drawerListening) {
                setBackdropHidden(false);
                listenDrawer(false);
            }
            if (cannedListening) {
                setBackdropHidden(false);
                listenCanned(false);
            }
        }

        return {
            start: start,
            stop: stop,
            poll: poll,
            send: send,
            appendMessages: appendMessages,
            openDetails: openDrawer,
            closeDetails: closeDrawer,
            detailsOpen: function () {
                return !sheetClosed();
            },
            isNearBottom: function () {
                return isNearBottom(list, NEAR_BOTTOM_PX);
            }
        };
    }

    function boot() {
        var doc = global.document;
        if (!doc || !doc.getElementById) {
            return;
        }
        var root = doc.getElementById('md-mobile-app');
        if (!root || root.getAttribute('data-md-ready') === '1') {
            return;
        }
        root.setAttribute('data-md-ready', '1');
        createController({
            root: root,
            fetch: global.fetch ? global.fetch.bind(global) : null,
            storage: global.sessionStorage,
            document: doc,
            window: global
        }).start();
    }

    global.MdMobile = {
        createController: createController,
        withAfterId: withAfterId,
        parseAfter: parseAfter,
        kindOf: kindOf,
        buildSendRequest: buildSendRequest,
        buildRemarksRequest: buildRemarksRequest,
        buildCloseRequest: buildCloseRequest,
        insertTextAtCaret: insertTextAtCaret,
        sendSucceeded: sendSucceeded,
        formatTime: formatTime,
        renderLocalTimes: renderLocalTimes,
        isNearBottom: isNearBottom,
        plainError: plainError,
        POLL_MS: POLL_MS,
        NEAR_BOTTOM_PX: NEAR_BOTTOM_PX
    };

    if (global.document && global.document.addEventListener) {
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }
})(window);
