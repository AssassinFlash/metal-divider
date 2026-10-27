(function (global) {
    'use strict';

    // Desk list refresh, separate from the 3.5s lh_msg poll. Backs off on failure.
    var POLL_MS = 4000;
    var POLL_MAX_MS = 15000;
    var READ_GRACE_MS = 8000;
    var SEARCH_DEBOUNCE_MS = 400;
    var REFRESH_ERROR = 'Conversation list could not be refreshed';
    var READ_ERROR = 'Could not mark this conversation read';
    var STATUS_MAP = {
        pending: 'Pending',
        active: 'Active',
        closed: 'Closed',
        chatbox: 'Chatbox',
        operators: 'Operators',
        bot: 'Bot'
    };
    var STATUS_CODE = {
        0: 'pending',
        1: 'active',
        2: 'closed',
        3: 'chatbox',
        4: 'operators',
        5: 'bot'
    };
    var acked = {};

    function itemId(value) {
        var text = String(value == null ? '' : value);
        if (!/^[1-9]\d*$/.test(text)) {
            return '';
        }
        var parsed = parseInt(text, 10);
        if (String(parsed) !== text) {
            return '';
        }
        return String(parsed);
    }

    function textOf(value, limit) {
        if (value === null || value === undefined) {
            return '';
        }
        var text = String(value).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '');
        text = text.replace(/^\s+|\s+$/g, '');
        if (limit && text.length > limit) {
            text = text.slice(0, limit);
        }
        return text;
    }

    function safeUrl(value) {
        var url = String(value == null ? '' : value).replace(/^\s+|\s+$/g, '');
        if (url === '' || url.length > 2000 || /[\u0000-\u0020\u007f\\<>"']/.test(url)) {
            return '';
        }
        if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^https?:\/\//i.test(url)) {
            return '';
        }
        if (url.charAt(0) === '/') {
            if (url.charAt(1) === '/' || url.charAt(1) === '\\') {
                return '';
            }
            return url;
        }
        if (/^https?:\/\//i.test(url)) {
            try {
                var parsed = new URL(url);
                if (!parsed.hostname || parsed.username || parsed.password) {
                    return '';
                }
                return parsed.toString();
            } catch (error) {
                return '';
            }
        }
        if (url.indexOf('//') === 0 && url.indexOf('///') !== 0 && url.length > 2) {
            return url;
        }
        return '';
    }

    function statusLabel(value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }
        if (typeof value === 'number' || /^\d+$/.test(String(value))) {
            var token = STATUS_CODE[parseInt(value, 10)];
            return token ? STATUS_MAP[token] : '';
        }
        var key = String(value).replace(/\s+/g, '').toLowerCase();
        if (STATUS_MAP[key]) {
            return STATUS_MAP[key];
        }
        var text = textOf(value, 40);
        return text;
    }

    function statusTone(label) {
        var text = String(label || '').toLowerCase();
        if (text === '') {
            return '';
        }
        if (text.indexOf('不在线') !== -1 || text.indexOf('离线') !== -1 || text.indexOf('已关闭') !== -1) {
            return 'offline';
        }
        if (text.indexOf('inactive') !== -1 || text.indexOf('offline') !== -1 || text.indexOf('closed') !== -1 || text.indexOf('not online') !== -1) {
            return 'offline';
        }
        if (text.indexOf('等待') !== -1 || text.indexOf('pending') !== -1 || text.indexOf('waiting') !== -1 || text.indexOf('away') !== -1) {
            return 'waiting';
        }
        if (text.indexOf('在线') !== -1 || text.indexOf('online') !== -1 || text.indexOf('active') !== -1 || text.indexOf('进行中') !== -1) {
            return 'online';
        }
        return 'neutral';
    }

    function unreadOf(value) {
        if (value === true || value === 'true') {
            return { mode: 'flag', count: 1 };
        }
        if (value === false || value === null || value === undefined || value === '' || value === 0 || value === '0') {
            return null;
        }
        if (typeof value === 'number' && isFinite(value) && value > 0) {
            return { mode: 'count', count: Math.floor(value) };
        }
        if (typeof value === 'string' && /^[1-9]\d*$/.test(value)) {
            return { mode: 'count', count: parseInt(value, 10) };
        }
        return null;
    }

    function itemsFrom(data) {
        if (Array.isArray(data)) {
            return data;
        }
        if (!data || typeof data !== 'object') {
            return null;
        }
        if (Array.isArray(data.items)) {
            return data.items;
        }
        if (Array.isArray(data.chats)) {
            return data.chats;
        }
        if (Array.isArray(data.list)) {
            return data.list;
        }
        return null;
    }

    function withQuery(url, q, status, offset, base) {
        var source = String(url || '');
        if (source === '') {
            return '';
        }
        var query = q == null ? '' : String(q);
        var filter = status == null ? '' : String(status);
        var page = parseInt(offset, 10);
        if (isNaN(page) || page < 0) {
            page = 0;
        }
        try {
            var parsed = new URL(source, base || 'http://local.invalid/');
            parsed.searchParams.set('q', query);
            parsed.searchParams.set('status', filter);
            parsed.searchParams.set('offset', String(page));
            if (/^[a-z][a-z0-9+.-]*:/i.test(source)) {
                return parsed.toString();
            }
            return parsed.pathname + parsed.search + parsed.hash;
        } catch (error) {
            var join = source.indexOf('?') === -1 ? '?' : '&';
            return source + join + 'q=' + encodeURIComponent(query) + '&status=' + encodeURIComponent(filter) + '&offset=' + encodeURIComponent(String(page));
        }
    }

    function buildReadRequest(csrf) {
        return {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRFToken': String(csrf || ''),
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: 'csfr_token=' + encodeURIComponent(String(csrf || ''))
        };
    }

    function stampOf(value) {
        if (value === null || value === undefined || value === '') {
            return 0;
        }
        if (typeof value === 'number' || /^\d+$/.test(String(value))) {
            var stamp = parseInt(value, 10);
            if (isNaN(stamp) || stamp < 1) {
                return 0;
            }
            if (stamp > 100000000000) {
                stamp = Math.floor(stamp / 1000);
            }
            return stamp;
        }
        return 0;
    }

    function firstValue(raw, keys) {
        var index;
        for (index = 0; index < keys.length; index += 1) {
            if (raw[keys[index]] !== undefined && raw[keys[index]] !== null && raw[keys[index]] !== '') {
                return raw[keys[index]];
            }
        }
        return null;
    }

    function normalizeItem(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }
        var id = itemId(raw.id);
        if (id === '') {
            return null;
        }
        var preview = textOf(firstValue(raw, ['preview', 'summary', 'last_message', 'excerpt']), 180).replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        var status = statusLabel(firstValue(raw, ['status', 'status_label', 'state']));
        var unread = unreadOf(firstValue(raw, ['unread', 'unread_count', 'has_unread', 'has_unread_messages']));
        return {
            id: id,
            nick: textOf(firstValue(raw, ['nick', 'name', 'title']), 120),
            preview: preview,
            status: status,
            tone: status === '' ? '' : statusTone(status),
            unread: unread,
            url: safeUrl(firstValue(raw, ['url', 'href'])),
            time: stampOf(firstValue(raw, ['time', 'timestamp', 'last_time', 'updated']))
        };
    }

    function rememberAck(id, count) {
        if (!id) {
            return;
        }
        acked[id] = { at: Date.now(), count: count > 0 ? count : 1 };
    }

    function applyAck(id, unread) {
        var ack = acked[id];
        if (!ack) {
            return unread;
        }
        if (Date.now() - ack.at >= READ_GRACE_MS) {
            delete acked[id];
            return unread;
        }
        if (unread && unread.count > ack.count) {
            delete acked[id];
            return unread;
        }
        return null;
    }

    function formatDeskTime(stamp) {
        var date = new Date(stamp * 1000);
        if (isNaN(date.getTime())) {
            return '';
        }
        var now = new Date();
        var hours = date.getHours();
        var minutes = date.getMinutes();
        var clock = (hours < 10 ? '0' : '') + hours + ':' + (minutes < 10 ? '0' : '') + minutes;
        var sameDay = date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate();
        if (sameDay) {
            return clock;
        }
        var month = date.getMonth() + 1;
        var day = date.getDate();
        return (month < 10 ? '0' : '') + month + '-' + (day < 10 ? '0' : '') + day;
    }

    function createController(options) {
        var root = options.root;
        var doc = options.document || global.document;
        var view = options.window || global;
        var fetchImpl = options.fetch;
        var setTimeoutImpl = options.setTimeout || global.setTimeout;
        var clearTimeoutImpl = options.clearTimeout || global.clearTimeout;
        var list = root.querySelector('#md-inbox-list');
        var form = root.querySelector('#md-filters');
        var qInput = root.querySelector('#md-q');
        var statusSelect = root.querySelector('#md-filter-status');
        var statusEl = root.querySelector('#md-inbox-status');
        var emptyEl = root.querySelector('#md-inbox-empty');
        var endpoint = root.getAttribute('data-list-endpoint') || '';
        var pageBase = root.getAttribute('data-page-base') || '';
        var timer = null;
        var searchTimer = null;
        var polling = null;
        var pollWait = POLL_MS;
        var stopped = false;
        var started = false;

        function pageHidden() {
            return !!(doc && doc.visibilityState === 'hidden');
        }

        function formValues() {
            return {
                q: qInput ? qInput.value : '',
                status: statusSelect ? statusSelect.value : ''
            };
        }

        function currentOffset() {
            var value = parseInt(root.getAttribute('data-offset') || '0', 10);
            if (isNaN(value) || value < 0) {
                return 0;
            }
            return value;
        }

        function currentLimit() {
            var value = parseInt(root.getAttribute('data-limit') || '20', 10);
            if (isNaN(value) || value < 1) {
                return 20;
            }
            return value;
        }

        function setOffset(value) {
            var page = parseInt(value, 10);
            if (isNaN(page) || page < 0) {
                page = 0;
            }
            root.setAttribute('data-offset', String(page));
            return page;
        }

        function pageHref(offset) {
            if (pageBase === '') {
                return '';
            }
            var values = formValues();
            var page = parseInt(offset, 10);
            if (isNaN(page) || page < 0) {
                page = 0;
            }
            try {
                var parsed = new URL(pageBase, view.location ? view.location.href : 'http://local.invalid/');
                parsed.searchParams.set('q', values.q);
                parsed.searchParams.set('status', values.status);
                if (page > 0) {
                    parsed.searchParams.set('offset', String(page));
                } else {
                    parsed.searchParams.delete('offset');
                }
                if (/^[a-z][a-z0-9+.-]*:/i.test(pageBase)) {
                    return parsed.toString();
                }
                return parsed.pathname + parsed.search + parsed.hash;
            } catch (error) {
                return withQuery(pageBase, values.q, values.status, page, view.location ? view.location.href : undefined);
            }
        }

        function setPageLink(link, visible, offset) {
            if (!link) {
                return;
            }
            link.setAttribute('data-offset', String(offset));
            var href = pageHref(offset);
            if (href !== '') {
                link.setAttribute('href', href);
            }
            if (visible) {
                link.hidden = false;
                link.removeAttribute('hidden');
            } else {
                link.hidden = true;
                link.setAttribute('hidden', '');
            }
        }

        function updatePager(count) {
            var offset = currentOffset();
            var limit = currentLimit();
            var newerOffset = offset > limit ? offset - limit : 0;
            setPageLink(root.querySelector('#md-newer'), offset > 0, newerOffset);
            if (count === null || count === undefined) {
                var older = root.querySelector('#md-older');
                if (older) {
                    older.setAttribute('data-offset', String(offset + limit));
                    var href = pageHref(offset + limit);
                    if (href !== '') {
                        older.setAttribute('href', href);
                    }
                }
                return;
            }
            setPageLink(root.querySelector('#md-older'), count >= limit, offset + limit);
        }

        function selectedId() {
            var app = doc.getElementById('md-mobile-app');
            var fromApp = app ? itemId(app.getAttribute('data-chat-id')) : '';
            if (fromApp !== '') {
                return fromApp;
            }
            return itemId(root.getAttribute('data-selected'));
        }

        function setRefreshStatus(text) {
            if (!statusEl || statusEl.textContent === text) {
                return;
            }
            statusEl.textContent = text;
            if (text) {
                statusEl.classList.add('is-error');
            } else {
                statusEl.classList.remove('is-error');
            }
        }

        function clearRefreshStatus() {
            if (statusEl && statusEl.textContent === REFRESH_ERROR) {
                setRefreshStatus('');
            }
        }

        function emptyCopy() {
            var values = formValues();
            if (values.q.replace(/^\s+|\s+$/g, '') !== '' || values.status !== '') {
                return 'No matching conversations';
            }
            return 'No conversations';
        }

        function syncEmpty(count) {
            if (!emptyEl) {
                return;
            }
            if (count === 0) {
                emptyEl.textContent = emptyCopy();
                emptyEl.hidden = false;
                emptyEl.removeAttribute('hidden');
            } else {
                emptyEl.hidden = true;
                emptyEl.setAttribute('hidden', '');
            }
        }

        function deriveChatUrl(id) {
            if (!list) {
                return '';
            }
            var sample = list.querySelector('a.md-thread-link[href]');
            if (!sample) {
                return '';
            }
            var href = sample.getAttribute('href') || '';
            if (!/\/mdmobile\/chat\/\d+/.test(href)) {
                return '';
            }
            return safeUrl(href.replace(/\/mdmobile\/chat\/\d+/, '/mdmobile/chat/' + id));
        }

        function ensureChild(parent, selector, tag, className) {
            var node = parent.querySelector(selector);
            if (!node) {
                node = doc.createElement(tag);
                node.className = className;
                parent.appendChild(node);
            }
            return node;
        }

        function paintTime(link, stamp) {
            var node = link.querySelector('.md-thread-time');
            if (stamp < 1) {
                if (node) {
                    node.textContent = '';
                }
                return;
            }
            if (!node || String(node.tagName || '').toLowerCase() !== 'time') {
                var next = doc.createElement('time');
                next.className = 'md-thread-time';
                if (node && node.parentNode) {
                    node.parentNode.replaceChild(next, node);
                } else {
                    link.appendChild(next);
                }
                node = next;
            }
            node.setAttribute('data-timestamp', String(stamp));
            node.setAttribute('data-time-style', 'desk');
            node.setAttribute('datetime', new Date(stamp * 1000).toISOString());
            node.textContent = formatDeskTime(stamp);
        }

        function paintBadge(meta, unread) {
            var badge = meta.querySelector('.md-unread');
            if (!unread) {
                if (badge && badge.parentNode) {
                    badge.parentNode.removeChild(badge);
                }
                return;
            }
            if (!badge) {
                badge = doc.createElement('span');
                meta.appendChild(badge);
            }
            if (unread.mode === 'flag') {
                badge.className = 'md-unread is-flag';
                badge.setAttribute('data-unread', '1');
                badge.setAttribute('aria-label', 'Unread');
                while (badge.firstChild) {
                    badge.removeChild(badge.firstChild);
                }
                var dot = doc.createElement('span');
                dot.className = 'md-dot';
                dot.setAttribute('aria-hidden', 'true');
                var sr = doc.createElement('span');
                sr.className = 'md-sr';
                sr.textContent = 'Unread';
                badge.appendChild(dot);
                badge.appendChild(sr);
                return;
            }
            badge.className = 'md-unread';
            badge.setAttribute('data-unread', String(unread.count));
            badge.setAttribute('aria-label', unread.count === 1 ? '1 unread' : unread.count + ' unread');
            badge.textContent = unread.count > 99 ? '99+' : String(unread.count);
        }

        function ensureLink(li, url) {
            var current = li.querySelector('.md-thread-link');
            var want = url ? 'A' : 'DIV';
            if (current && String(current.tagName || '').toUpperCase() === want) {
                return current;
            }
            var next = doc.createElement(url ? 'a' : 'div');
            next.className = 'md-thread-link';
            if (current) {
                while (current.firstChild) {
                    next.appendChild(current.firstChild);
                }
                var focused = doc.activeElement === current;
                current.parentNode.replaceChild(next, current);
                if (focused && next.focus) {
                    next.focus();
                }
            } else {
                li.appendChild(next);
            }
            return next;
        }

        function updateThread(li, item, currentId) {
            var url = item.url || deriveChatUrl(item.id);
            var link = ensureLink(li, url);
            link.setAttribute('data-id', item.id);
            if (url && link.setAttribute) {
                link.setAttribute('href', url);
            } else if (!url && link.removeAttribute) {
                link.removeAttribute('href');
            }
            if (currentId !== '' && item.id === currentId) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
            var unread = applyAck(item.id, item.unread);
            if (unread) {
                link.classList.add('is-unread');
            } else {
                link.classList.remove('is-unread');
            }
            var name = ensureChild(link, '.md-thread-name', 'span', 'md-thread-name');
            name.textContent = item.nick !== '' && item.nick.toLowerCase() !== 'visitor' ? item.nick : 'Visitor #' + item.id;
            paintTime(link, item.time);
            var preview = ensureChild(link, '.md-thread-preview', 'span', 'md-thread-preview');
            preview.textContent = item.preview;
            var meta = ensureChild(link, '.md-thread-meta', 'span', 'md-thread-meta');
            var statusNode = meta.querySelector('.md-thread-status');
            if (item.status === '') {
                if (statusNode && statusNode.parentNode) {
                    statusNode.parentNode.removeChild(statusNode);
                }
            } else {
                if (!statusNode) {
                    statusNode = doc.createElement('span');
                    statusNode.className = 'md-thread-status';
                    meta.insertBefore(statusNode, meta.firstChild);
                }
                statusNode.textContent = item.status;
                if (item.tone) {
                    statusNode.setAttribute('data-tone', item.tone);
                } else {
                    statusNode.removeAttribute('data-tone');
                }
            }
            paintBadge(meta, unread);
            return li;
        }

        function reconcile(rawItems) {
            if (!list) {
                return 0;
            }
            var currentId = selectedId();
            var existing = {};
            var children = list.children;
            var index;
            for (index = 0; index < children.length; index += 1) {
                var found = children[index].querySelector('[data-id]');
                var foundId = found ? itemId(found.getAttribute('data-id')) : '';
                if (foundId !== '') {
                    existing[foundId] = children[index];
                }
            }
            var seen = {};
            var ordered = [];
            for (index = 0; index < rawItems.length; index += 1) {
                var item = normalizeItem(rawItems[index]);
                if (!item || seen[item.id]) {
                    continue;
                }
                seen[item.id] = true;
                var li = existing[item.id] || doc.createElement('li');
                li.className = 'md-thread';
                updateThread(li, item, currentId);
                ordered.push(li);
            }
            for (index = 0; index < ordered.length; index += 1) {
                list.appendChild(ordered[index]);
            }
            var ids = Object.keys(existing);
            for (index = 0; index < ids.length; index += 1) {
                if (!seen[ids[index]] && existing[ids[index]].parentNode) {
                    existing[ids[index]].parentNode.removeChild(existing[ids[index]]);
                }
            }
            syncEmpty(ordered.length);
            return ordered.length;
        }

        function readPayload(response) {
            return response.text().then(function (text) {
                var data = null;
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    data = null;
                }
                return { ok: response.ok, status: response.status, data: data };
            });
        }

        function syncAddress(values) {
            if (!view.history || !view.history.replaceState || !view.location) {
                return;
            }
            try {
                var url = new URL(view.location.href);
                url.searchParams.set('q', values.q);
                url.searchParams.set('status', values.status);
                if (currentOffset() > 0) {
                    url.searchParams.set('offset', String(currentOffset()));
                } else {
                    url.searchParams.delete('offset');
                }
                view.history.replaceState(null, '', url.pathname + url.search + url.hash);
            } catch (error) {
                return;
            }
        }

        function poll(force) {
            if (!endpoint) {
                return Promise.resolve(false);
            }
            if (polling && !force) {
                return polling;
            }
            if (pageHidden() && !force) {
                return Promise.resolve(false);
            }
            if (typeof fetchImpl !== 'function') {
                setRefreshStatus('This browser cannot refresh the conversation list');
                stopped = true;
                return Promise.resolve(false);
            }
            var values = formValues();
            var url = withQuery(endpoint, values.q, values.status, currentOffset(), view.location ? view.location.href : undefined);
            var request = fetchImpl(url, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            }).then(readPayload).then(function (result) {
                var items = itemsFrom(result.data);
                if (!result.ok || items === null) {
                    pollWait = Math.min(POLL_MAX_MS, pollWait * 2);
                    setRefreshStatus(REFRESH_ERROR);
                    return false;
                }
                if (result.data && result.data.offset !== undefined && result.data.offset !== null && String(result.data.offset) !== '') {
                    setOffset(result.data.offset);
                }
                if (result.data && result.data.limit) {
                    var nextLimit = parseInt(result.data.limit, 10);
                    if (!isNaN(nextLimit) && nextLimit > 0) {
                        root.setAttribute('data-limit', String(nextLimit));
                    }
                }
                var shown = reconcile(items);
                updatePager(shown);
                pollWait = POLL_MS;
                clearRefreshStatus();
                return true;
            }).catch(function () {
                pollWait = Math.min(POLL_MAX_MS, pollWait * 2);
                setRefreshStatus(REFRESH_ERROR);
                return false;
            });
            var tracked = request.then(function (ok) {
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
            if (stopped || !endpoint) {
                return;
            }
            clearTimer();
            if (pageHidden()) {
                return;
            }
            timer = setTimeoutImpl(function () {
                timer = null;
                poll(false).then(function () {
                    schedule(pollWait);
                });
            }, delay);
        }

        function onVisible() {
            if (!pageHidden()) {
                schedule(0);
            }
        }

        function localizeTimes() {
            if (!list) {
                return;
            }
            var nodes = list.querySelectorAll('time.md-thread-time[data-timestamp]');
            var index;
            for (index = 0; index < nodes.length; index += 1) {
                var stamp = stampOf(nodes[index].getAttribute('data-timestamp'));
                if (stamp > 0) {
                    nodes[index].textContent = formatDeskTime(stamp);
                }
            }
        }

        function bindForm() {
            if (form && form.getAttribute('data-md-bound') !== '1') {
                form.setAttribute('data-md-bound', '1');
                form.addEventListener('submit', function (event) {
                    if (!endpoint) {
                        return;
                    }
                    if (event && event.preventDefault) {
                        event.preventDefault();
                    }
                    setOffset(0);
                    updatePager(null);
                    syncAddress(formValues());
                    poll(true);
                });
            }
            if (statusSelect && statusSelect.getAttribute('data-md-bound') !== '1') {
                statusSelect.setAttribute('data-md-bound', '1');
                statusSelect.addEventListener('change', function () {
                    if (!endpoint) {
                        return;
                    }
                    setOffset(0);
                    updatePager(null);
                    syncAddress(formValues());
                    poll(true);
                });
            }
            if (qInput && qInput.getAttribute('data-md-bound') !== '1') {
                qInput.setAttribute('data-md-bound', '1');
                qInput.addEventListener('input', function () {
                    if (!endpoint) {
                        return;
                    }
                    if (searchTimer) {
                        clearTimeoutImpl(searchTimer);
                    }
                    searchTimer = setTimeoutImpl(function () {
                        searchTimer = null;
                        setOffset(0);
                        updatePager(null);
                        syncAddress(formValues());
                        poll(true);
                    }, SEARCH_DEBOUNCE_MS);
                });
            }
        }

        function bindPager() {
            var pager = root.querySelector('#md-pager');
            if (!pager || pager.getAttribute('data-md-bound') === '1') {
                return;
            }
            pager.setAttribute('data-md-bound', '1');
            pager.addEventListener('click', function (event) {
                if (!endpoint) {
                    return;
                }
                var link = event.target;
                while (link && link !== pager) {
                    if (link.getAttribute && link.hasAttribute('data-offset') && !link.hasAttribute('hidden')) {
                        break;
                    }
                    link = link.parentNode;
                }
                if (!link || link === pager || !link.getAttribute) {
                    return;
                }
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button) {
                    return;
                }
                if (event.preventDefault) {
                    event.preventDefault();
                }
                setOffset(link.getAttribute('data-offset'));
                updatePager(null);
                syncAddress(formValues());
                poll(true);
            });
        }

        function start() {
            if (started) {
                return;
            }
            started = true;
            localizeTimes();
            bindForm();
            if (doc && doc.addEventListener) {
                doc.addEventListener('visibilitychange', onVisible);
            }
            bindPager();
            if (endpoint) {
                schedule(POLL_MS);
            }
        }

        function stop() {
            stopped = true;
            clearTimer();
            if (searchTimer) {
                clearTimeoutImpl(searchTimer);
                searchTimer = null;
            }
        }

        return {
            start: start,
            stop: stop,
            poll: poll,
            reconcile: reconcile,
            formValues: formValues
        };
    }

    function badgeCount(doc, chatId) {
        if (!doc || !chatId) {
            return 0;
        }
        var node = doc.querySelector('#md-inbox-list [data-id="' + chatId + '"] .md-unread');
        if (!node) {
            return 0;
        }
        var count = parseInt(node.getAttribute('data-unread') || '', 10);
        return isNaN(count) ? 1 : count;
    }

    function setListUnread(doc, chatId, unread) {
        if (!doc || !chatId) {
            return;
        }
        var link = doc.querySelector('#md-inbox-list [data-id="' + chatId + '"]');
        if (!link) {
            return;
        }
        var meta = link.querySelector('.md-thread-meta');
        if (!meta) {
            return;
        }
        link.classList.add('is-unread');
        var badge = meta.querySelector('.md-unread');
        if (!badge) {
            badge = doc.createElement('span');
            meta.appendChild(badge);
        }
        badge.className = 'md-unread';
        badge.setAttribute('data-unread', String(unread.count));
        badge.setAttribute('aria-label', unread.count === 1 ? '1 unread' : unread.count + ' unread');
        badge.textContent = unread.count > 99 ? '99+' : String(unread.count);
    }

    function clearBadge(doc, chatId) {
        if (!doc || !chatId) {
            return;
        }
        var link = doc.querySelector('#md-inbox-list [data-id="' + chatId + '"]');
        if (!link) {
            return;
        }
        link.classList.remove('is-unread');
        var badge = link.querySelector('.md-unread');
        if (badge && badge.parentNode) {
            badge.parentNode.removeChild(badge);
        }
    }

    function showOnce(doc, text) {
        var node = doc.getElementById('md-inbox-status') || doc.getElementById('md-status');
        if (!node) {
            return;
        }
        if (node.getAttribute('data-md-note') === text || node.textContent === text) {
            return;
        }
        if (node.id === 'md-status' && node.textContent) {
            return;
        }
        node.textContent = text;
        node.classList.add('is-error');
        node.setAttribute('data-md-note', text);
    }

    function readPayload(response) {
        return response.text().then(function (text) {
            var data = null;
            try {
                data = JSON.parse(text);
            } catch (error) {
                data = null;
            }
            return { ok: response.ok, data: data };
        });
    }

    function markRead(doc, fetchImpl) {
        var app = doc.getElementById('md-mobile-app');
        var desk = doc.getElementById('md-desk');
        var url = app ? (app.getAttribute('data-read-url') || '') : '';
        var csrf = app ? (app.getAttribute('data-csrf') || '') : '';
        var chatId = app ? itemId(app.getAttribute('data-chat-id')) : '';
        if (url === '' && desk && desk.getAttribute('data-page') === 'chat') {
            url = desk.getAttribute('data-read-url') || '';
        }
        url = safeUrl(url);
        if (url === '') {
            return Promise.resolve(false);
        }
        var sentOn = app || desk;
        if (sentOn && sentOn.getAttribute('data-md-read-sent') === '1') {
            return Promise.resolve(false);
        }
        if (sentOn) {
            sentOn.setAttribute('data-md-read-sent', '1');
        }
        if (typeof fetchImpl !== 'function') {
            showOnce(doc, 'This browser cannot mark the conversation read');
            return Promise.resolve(false);
        }
        return fetchImpl(url, buildReadRequest(csrf)).then(readPayload).then(function (result) {
            if (!result.ok) {
                showOnce(doc, READ_ERROR);
                return false;
            }
            if (app && result.data && typeof result.data.token === 'string' && result.data.token !== '') {
                app.setAttribute('data-csrf', result.data.token);
            }
            var reported = result.data && Object.prototype.hasOwnProperty.call(result.data, 'unread') ? unreadOf(result.data.unread) : null;
            if (reported && reported.count > 0) {
                rememberAck(chatId, reported.count - 1);
                setListUnread(doc, chatId, reported);
            } else {
                rememberAck(chatId, badgeCount(doc, chatId));
                clearBadge(doc, chatId);
            }
            return true;
        }).catch(function () {
            showOnce(doc, READ_ERROR);
            return false;
        });
    }

    function closeDrawerIfDesktop(doc, view) {
        if (!doc.getElementById('md-desk') || !view.matchMedia) {
            return;
        }
        var query = view.matchMedia('(min-width: 960px)');
        function closeIfWide() {
            if (!query.matches) {
                return;
            }
            var sheet = doc.getElementById('md-sheet');
            if (!sheet || sheet.hasAttribute('hidden') || sheet.hidden) {
                return;
            }
            var button = doc.getElementById('md-drawer-close');
            if (button && button.click) {
                button.click();
            }
        }
        if (query.addEventListener) {
            query.addEventListener('change', closeIfWide);
        } else if (query.addListener) {
            query.addListener(closeIfWide);
        }
        closeIfWide();
    }

    function whenChatReady(doc, callback) {
        var app = doc.getElementById('md-mobile-app');
        if (!app) {
            return;
        }
        if (app.getAttribute('data-md-ready') === '1' || !global.MutationObserver) {
            callback(app);
            return;
        }
        var observer = new global.MutationObserver(function () {
            if (app.getAttribute('data-md-ready') === '1') {
                observer.disconnect();
                callback(app);
            }
        });
        observer.observe(app, { attributes: true, attributeFilter: ['data-md-ready'] });
    }

    function boot() {
        var doc = global.document;
        if (!doc || !doc.getElementById) {
            return;
        }
        closeDrawerIfDesktop(doc, global);
        var inbox = doc.getElementById('md-inbox');
        if (inbox && inbox.getAttribute('data-md-inbox-ready') !== '1') {
            inbox.setAttribute('data-md-inbox-ready', '1');
            createController({
                root: inbox,
                document: doc,
                window: global,
                fetch: global.fetch ? global.fetch.bind(global) : null
            }).start();
        }
        whenChatReady(doc, function () {
            markRead(doc, global.fetch ? global.fetch.bind(global) : null);
        });
    }

    global.MdMobileInbox = {
        createController: createController,
        markRead: markRead,
        itemId: itemId,
        safeUrl: safeUrl,
        itemsFrom: itemsFrom,
        withQuery: withQuery,
        buildReadRequest: buildReadRequest,
        unreadOf: unreadOf,
        normalizeItem: normalizeItem,
        formatDeskTime: formatDeskTime,
        POLL_MS: POLL_MS,
        POLL_MAX_MS: POLL_MAX_MS
    };

    if (global.document && global.document.addEventListener) {
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }
})(typeof window !== 'undefined' ? window : this);
