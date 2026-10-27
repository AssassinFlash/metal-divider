<?php

/**
 * Mobile desk message shape, access decision, and visitor context.
 *
 * The LHC views call this after they have read the operator session and
 * hasAccessToRead. Nothing here talks to the database, so the format and
 * permission tests can run without a Live Helper Chat install.
 *
 * lh_msg.user_id in 4.97: 0 is the visitor, a positive id is the operator,
 * -1 is a system row, -2 is a bot row.
 */
class erLhcoreClassMdMobile
{
    const PAGE_LIMIT = 200;

    const INBOX_LIMIT = 20;

    const INBOX_QUERY_LIMIT = 80;

    const KIND_VISITOR = 'visitor';

    const KIND_OPERATOR = 'operator';

    const KIND_SYSTEM = 'system';

    const KIND_BOT = 'bot';

    /**
     * @param mixed $value Path or query value.
     */
    public static function chatId($value)
    {
        return self::positiveInt($value);
    }

    /**
     * @param mixed $value Query or unordered parameter. Missing and junk are 0.
     */
    public static function afterId($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return self::nonNegativeInt($value);
    }

    /**
     * @param mixed $configured site.default_admin_site_access, string or list.
     */
    public static function adminSiteAccess($configured)
    {
        if (is_array($configured)) {
            $configured = reset($configured);
        }

        if (is_string($configured) && preg_match('/^[A-Za-z0-9_-]+$/', $configured) === 1) {
            return $configured;
        }

        return 'site_admin';
    }

    /**
     * @return array{messages_url: string, send_url: string}
     */
    public static function endpointUrls($siteBase, $chatId)
    {
        $chatId = (int) $chatId;
        if ($chatId < 1) {
            throw new InvalidArgumentException('chat id');
        }

        $base = rtrim((string) $siteBase, '/');
        $urls = array(
            'messages_url' => $base . '/mdmobile/messages/' . $chatId,
            'send_url' => $base . '/chat/addmsgadmin/' . $chatId,
        );
        $list = self::deskUrl($base, 'mdmobile/chats');
        $endpoint = self::deskUrl($base, 'mdmobile/list');
        $read = self::deskUrl($base, 'mdmobile/read/' . $chatId);
        if ($list !== null) {
            $urls['list_url'] = $list;
        }
        if ($endpoint !== null) {
            $urls['list_endpoint'] = $endpoint;
        }
        if ($read !== null) {
            $urls['read_url'] = $read;
        }

        return $urls;
    }

    /**
     * Operator desk paths only. A javascript or protocol-relative base is rejected.
     *
     * @param mixed $siteBase
     * @param mixed $path
     * @return string|null
     */
    public static function deskUrl($siteBase, $path)
    {
        $base = self::safeBase($siteBase);
        $safePath = self::deskPath($path);
        if ($base === null || $safePath === null) {
            return null;
        }

        return $base . '/' . $safePath;
    }

    /**
     * Not logged in is session, so the desk can send the operator to login.
     * A logged-in user without lhchat/use is forbidden. Calling that session
     * would bounce them back to login. A new module permission would lock
     * out that same operator.
     *
     * @return string|null session, missing, forbidden, or null when allowed.
     */
    public static function denial($loggedIn, $chatUse, $chatFound, $canRead)
    {
        if ($loggedIn !== true) {
            return 'session';
        }

        if ($chatUse !== true) {
            return 'forbidden';
        }

        if ($chatFound !== true) {
            return 'missing';
        }

        if ($canRead !== true) {
            return 'forbidden';
        }

        return null;
    }

    /**
     * @return int
     */
    public static function denialStatus($denial)
    {
        if ($denial === 'session') {
            return 401;
        }

        if ($denial === 'missing') {
            return 404;
        }

        if ($denial === 'method') {
            return 405;
        }

        return 403;
    }

    /**
     * The list has no chat id. A logged-in operator without lhchat/use is
     * forbidden. Missing department access is an empty list, not a new permission.
     *
     * @return string|null
     */
    public static function listDenial($loggedIn, $chatUse)
    {
        return self::denial($loggedIn, $chatUse, true, true);
    }

    /**
     * GET never reaches the seen write. CSRF is required only after the same
     * session, lhchat/use, and hasAccessToRead checks as the chat page.
     *
     * @return string|null method, session, missing, forbidden, csrf, or null.
     */
    public static function readDenial($method, $loggedIn, $chatUse, $chatFound, $canRead, $csrfOk)
    {
        if (strtoupper((string) $method) !== 'POST') {
            return 'method';
        }

        $denial = self::denial($loggedIn, $chatUse, $chatFound, $canRead);
        if ($denial !== null) {
            return $denial;
        }

        if ($csrfOk !== true) {
            return 'csrf';
        }

        return null;
    }

    /**
     * Header first, matching addmsgadmin. POST csfr_token is the LHC form field.
     * GET is not a token source.
     *
     * @param mixed $header
     * @param mixed $post
     * @return string|null
     */
    public static function csrfCandidate($header, $post)
    {
        if (is_string($header) && $header !== '') {
            return $header;
        }

        if (is_string($post) && $post !== '') {
            return $post;
        }

        return null;
    }

    /**
     * @return bool
     */
    public static function readOnlyMethod($method)
    {
        return in_array(strtoupper((string) $method), array('GET', 'HEAD'), true);
    }

    /**
     * @return string
     */
    public static function kindFromUserId($userId)
    {
        $userId = (int) $userId;

        if ($userId === 0) {
            return self::KIND_VISITOR;
        }

        if ($userId > 0) {
            return self::KIND_OPERATOR;
        }

        if ($userId === -2) {
            return self::KIND_BOT;
        }

        return self::KIND_SYSTEM;
    }

    /**
     * Latest window for the first paint. The poll uses the forward window.
     *
     * @return array<string, mixed>
     */
    public static function listQuery($chatId, $afterId, $latestWindow)
    {
        $query = array(
            'filter' => array('chat_id' => (int) $chatId),
            'limit' => self::PAGE_LIMIT,
            'sort' => $latestWindow === true ? 'id DESC' : 'id ASC',
        );

        if ($latestWindow !== true && (int) $afterId > 0) {
            $query['filtergt'] = array('id' => (int) $afterId);
        }

        return $query;
    }

    /**
     * @param array<int, object> $messages
     * @return array<int, array{id: int, user_id: int, msg: string, time: int}>
     */
    public static function rowsFromMessages(array $messages)
    {
        $rows = array();

        foreach ($messages as $message) {
            $rows[] = array(
                'id' => (int) $message->id,
                'user_id' => (int) $message->user_id,
                'msg' => (string) $message->msg,
                'time' => (int) $message->time,
            );
        }

        return $rows;
    }

    /**
     * @param array<int, array{id: int, user_id: int, msg: string, time: int}> $rows
     * @return array{messages: array<int, array{id: int, kind: string, text: string, time: int}>, latest_id: int}
     */
    public static function format(array $rows, $afterId)
    {
        $afterId = (int) $afterId;
        $messages = array();

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($id <= $afterId) {
                continue;
            }

            $messages[] = array(
                'id' => $id,
                'kind' => self::kindFromUserId($row['user_id']),
                'text' => (string) $row['msg'],
                'time' => (int) $row['time'],
            );
        }

        usort(
            $messages,
            static function (array $left, array $right) {
                return $left['id'] <=> $right['id'];
            }
        );

        $latestId = $afterId > 0 ? $afterId : 0;
        if ($messages !== array()) {
            $latestId = $messages[count($messages) - 1]['id'];
        }

        return array(
            'messages' => $messages,
            'latest_id' => $latestId,
        );
    }

    /**
     * Values the mobile template and pagelayout read. after_id is the cursor
     * after the embedded messages, not zero. A visitor nick becomes the title.
     * context is always present; empty sections stay empty arrays.
     *
     * @param array{messages: array<int, array<string, mixed>>, latest_id: int} $payload
     * @param array{messages_url: string, send_url: string} $urls
     * @param array<string, mixed>|null $context
     * @return array<string, mixed>
     */
    public static function templateBag($chatId, array $payload, array $urls, $csrf, $nick = '', $context = null)
    {
        $bag = array(
            'chat_id' => (int) $chatId,
            'after_id' => (int) $payload['latest_id'],
            'messages_url' => (string) $urls['messages_url'],
            'send_url' => (string) $urls['send_url'],
            'csrf' => (string) $csrf,
            'sender' => 'operator',
            'messages' => $payload['messages'],
            'latest_id' => (int) $payload['latest_id'],
        );

        $nick = trim((string) $nick);
        if ($nick !== '') {
            $bag['nick'] = $nick;
            $bag['title'] = $nick;
        }

        $bag['context'] = self::normalizeContext($context);
        $desk = self::bagDeskUrls($urls, (int) $chatId);
        if (isset($desk['list_url'])) {
            $bag['list_url'] = $desk['list_url'];
        }
        if (isset($desk['read_url'])) {
            $bag['read_url'] = $desk['read_url'];
        }

        return $bag;
    }

    /**
     * http, https, or protocol-relative only. Anything else is omitted.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function sourceUrl($value)
    {
        if (!is_string($value)) {
            return null;
        }

        $url = trim($value);
        if ($url === '' || strlen($url) > self::URL_LIMIT) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F<>"\'\\\\]/', $url) === 1) {
            return null;
        }

        $protocolRelative = strpos($url, '//') === 0;
        if ($protocolRelative) {
            if (strpos($url, '///') === 0 || strlen($url) < 3) {
                return null;
            }
            $parsed = parse_url('https:' . $url);
            $scheme = 'https';
        } else {
            if (preg_match('#^https?://#i', $url) !== 1) {
                return null;
            }
            $parsed = parse_url($url);
            $scheme = is_array($parsed) && isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';
        }

        if (!is_array($parsed) || ($scheme !== 'http' && $scheme !== 'https')) {
            return null;
        }
        if (!isset($parsed['host']) || $parsed['host'] === '' || $parsed['host'] === '.') {
            return null;
        }
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * Same visitor, other chats. A positive online_user_id wins. Email is
     * used only when that id is empty and the address itself is non-empty.
     * The query asks for more rows than the desk shows, so a permission
     * filter can still fill the visible five.
     *
     * @param object|array<string, mixed> $chat
     * @return array<string, mixed>|null
     */
    public static function historyQuery($chat)
    {
        if (!is_array($chat) && !is_object($chat)) {
            return null;
        }

        $onlineId = self::positiveInt(self::recordValue($chat, 'online_user_id'));
        if ($onlineId > 0) {
            $filter = array('online_user_id' => $onlineId);
        } else {
            $email = self::identityEmail(self::recordValue($chat, 'email'));
            if ($email === null) {
                return null;
            }
            $filter = array('email' => $email);
        }

        $query = array(
            'filter' => $filter,
            'limit' => self::HISTORY_QUERY_LIMIT,
            'sort' => 'time DESC, id DESC',
        );
        $chatId = self::positiveInt(self::recordValue($chat, 'id'));
        if ($chatId > 0) {
            $query['filternot'] = array('id' => $chatId);
        }

        return $query;
    }

    /**
     * Earliest visitor or operator rows. System (-1) and bot (-2) stay out.
     *
     * @param mixed $chatId
     * @return array<string, mixed>|null
     */
    public static function firstMessageQuery($chatId)
    {
        $chatId = self::positiveInt($chatId);
        if ($chatId < 1) {
            return null;
        }

        return array(
            'filter' => array('chat_id' => $chatId),
            'filtergte' => array('user_id' => 0),
            'limit' => self::FIRST_MESSAGE_LIMIT,
            'sort' => 'id ASC',
        );
    }

    /**
     * First non-empty visitor or operator message, one line, or null.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string|null
     */
    public static function previewFromMessages(array $rows)
    {
        $candidates = array();
        $index = 0;
        foreach ($rows as $row) {
            $index += 1;
            if (!is_array($row) || !array_key_exists('user_id', $row)) {
                continue;
            }
            $userId = self::canonicalInt($row['user_id']);
            if ($userId === null || $userId < 0) {
                continue;
            }
            $text = self::previewText(isset($row['msg']) ? $row['msg'] : '');
            if ($text === null) {
                continue;
            }
            $id = 0;
            if (isset($row['id'])) {
                $parsed = self::canonicalInt($row['id']);
                if ($parsed !== null && $parsed > 0) {
                    $id = $parsed;
                }
            }
            $candidates[] = array(
                'id' => $id,
                'index' => $index,
                'text' => $text,
            );
        }
        if ($candidates === array()) {
            return null;
        }

        usort(
            $candidates,
            static function (array $left, array $right) {
                if ($left['id'] !== $right['id']) {
                    if ($left['id'] === 0) {
                        return 1;
                    }
                    if ($right['id'] === 0) {
                        return -1;
                    }

                    return $left['id'] <=> $right['id'];
                }

                return $left['index'] <=> $right['index'];
            }
        );

        return $candidates[0]['text'];
    }

    /**
     * @param object|array<string, mixed> $chat
     * @param mixed $preview
     * @return array<string, mixed>|null
     */
    public static function historyItem($chat, $preview = null)
    {
        if (!is_array($chat) && !is_object($chat)) {
            return null;
        }

        $id = self::positiveInt(self::recordValue($chat, 'id'));
        if ($id < 1) {
            return null;
        }

        $item = array('id' => $id);
        $stamp = self::positiveStamp(self::recordValue($chat, 'time'));
        if ($stamp !== null) {
            $item['time'] = $stamp;
        }
        $text = self::previewText($preview);
        if ($text !== null) {
            $item['preview'] = $text;
        }
        $statusValue = self::recordValue($chat, 'status');
        if ($statusValue !== null) {
            $parsed = self::canonicalInt($statusValue);
            $token = $parsed === null ? null : self::statusToken($parsed);
            if ($token !== null) {
                $item['status'] = $token;
            }
        }

        return $item;
    }

    /**
     * Drops the current chat and any chat the predicate does not allow.
     * Preview runs only for the chats that will be returned.
     *
     * @param array<int, object|array<string, mixed>> $chats
     * @param mixed $currentId
     * @param callable $canRead
     * @param callable $previewFor
     * @return array<int, array<string, mixed>>
     */
    public static function visibleHistory($chats, $currentId, $canRead, $previewFor)
    {
        if (!is_array($chats) || !is_callable($canRead) || !is_callable($previewFor)) {
            return array();
        }

        $current = self::positiveInt($currentId);
        $accepted = array();
        $seen = array();
        foreach ($chats as $chat) {
            if (!is_array($chat) && !is_object($chat)) {
                continue;
            }
            $id = self::positiveInt(self::recordValue($chat, 'id'));
            if ($id < 1 || isset($seen[$id]) || ($current > 0 && $id === $current)) {
                continue;
            }
            $seen[$id] = true;
            if ($canRead($chat) !== true) {
                continue;
            }
            $accepted[] = $chat;
        }

        usort(
            $accepted,
            static function ($left, $right) {
                $leftTime = self::positiveStamp(self::recordValue($left, 'time'));
                $rightTime = self::positiveStamp(self::recordValue($right, 'time'));
                $leftTime = $leftTime === null ? 0 : $leftTime;
                $rightTime = $rightTime === null ? 0 : $rightTime;
                if ($leftTime !== $rightTime) {
                    return $rightTime <=> $leftTime;
                }

                return self::positiveInt(self::recordValue($right, 'id')) <=> self::positiveInt(self::recordValue($left, 'id'));
            }
        );

        $items = array();
        foreach (array_slice($accepted, 0, self::HISTORY_LIMIT) as $chat) {
            $item = self::historyItem($chat, $previewFor($chat));
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param mixed $siteBase
     * @return array<int, array<string, mixed>>
     */
    public static function withHistoryUrls(array $history, $siteBase)
    {
        $items = array();
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $clean = $item;
            unset($clean['url']);
            $id = self::positiveInt(isset($item['id']) ? $item['id'] : 0);
            if ($id > 0) {
                $url = self::deskUrl($siteBase, 'mdmobile/chat/' . $id);
                if ($url !== null) {
                    $clean['url'] = $url;
                }
            }
            $items[] = $clean;
        }

        return $items;
    }

    /**
     * @return array<string, int>
     */
    public static function inboxStatuses()
    {
        return array(
            'pending' => 0,
            'active' => 1,
            'closed' => 2,
            'chatbox' => 3,
            'operators' => 4,
            'bot' => 5,
        );
    }

    /**
     * @param mixed $q
     * @param mixed $status
     * @param mixed $offset
     * @return array{q: string, status: string, offset: int}
     */
    public static function inboxRequest($q, $status, $offset)
    {
        return array(
            'q' => self::inboxQueryText($q),
            'status' => self::inboxStatusToken($status),
            'offset' => self::afterId($offset),
        );
    }

    /**
     * Department limitation is applied by the caller before this query is used.
     * false, empty, or a non-string means no list, not an unfiltered list.
     * Search stays out of customfilter so the nick is quoted at the call site.
     *
     * @param mixed $q
     * @param mixed $status
     * @param mixed $offset
     * @param mixed $limitation
     * @return array<string, mixed>|null
     */
    public static function inboxQuery($q, $status, $offset, $limitation)
    {
        if ($limitation !== true && (!is_string($limitation) || $limitation === '')) {
            return null;
        }

        $request = self::inboxRequest($q, $status, $offset);
        $query = array(
            'limit' => self::INBOX_LIMIT,
            'offset' => $request['offset'],
            'sort' => 'GREATEST(`time`, `last_user_msg_time`, `last_op_msg_time`) DESC, `id` DESC',
        );
        if ($request['status'] !== '') {
            $query['filter'] = array('status' => self::inboxStatuses()[$request['status']]);
        }
        $search = self::inboxSearch($request['q']);
        if ($search !== null) {
            $query['search'] = $search;
        }
        if ($limitation !== true) {
            $query['customfilter'] = array($limitation);
        }

        return $query;
    }

    /**
     * @param mixed $search
     * @param callable $quote
     * @return string|null
     */
    public static function searchFilter($search, $quote)
    {
        if (!is_array($search) || !is_callable($quote)) {
            return null;
        }

        $parts = array();
        if (isset($search['nick']) && is_string($search['nick']) && $search['nick'] !== '') {
            $quoted = $quote('%' . self::likeEscape($search['nick']) . '%');
            if (!is_string($quoted) || $quoted === '') {
                return null;
            }
            $parts[] = '`nick` LIKE ' . $quoted;
        }
        if (isset($search['id'])) {
            $id = self::canonicalInt($search['id']);
            if ($id !== null && $id > 0) {
                $parts[] = '`id` = ' . $id;
            }
        }
        if ($parts === array()) {
            return null;
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * @param mixed $q
     * @param mixed $status
     * @param mixed $offset
     * @return string|null
     */
    public static function inboxListUrl($siteBase, $q, $status, $offset)
    {
        $base = self::deskUrl($siteBase, 'mdmobile/chats');
        if ($base === null) {
            return null;
        }
        $query = self::inboxQueryString(self::inboxRequest($q, $status, $offset));
        if ($query === '') {
            return $base;
        }

        return $base . '?' . $query;
    }

    /**
     * @param mixed $q
     * @param mixed $status
     * @param mixed $offset
     * @return string
     */
    public static function inboxReturnPath($q, $status, $offset)
    {
        $path = 'mdmobile/chats';
        $query = self::inboxQueryString(self::inboxRequest($q, $status, $offset));
        if ($query === '') {
            return $path;
        }

        return $path . '?' . $query;
    }

    /**
     * @param mixed $chatId
     * @return array<string, mixed>|null
     */
    public static function latestMessageQuery($chatId)
    {
        $chatId = self::positiveInt($chatId);
        if ($chatId < 1) {
            return null;
        }

        return array(
            'filter' => array('chat_id' => $chatId),
            'filtergte' => array('user_id' => 0),
            'limit' => self::FIRST_MESSAGE_LIMIT,
            'sort' => 'id DESC',
        );
    }

    /**
     * Newest non-empty visitor or operator row. System and bot rows stay out.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string|null
     */
    public static function latestPreviewFromMessages(array $rows)
    {
        $candidates = array();
        $index = 0;
        foreach ($rows as $row) {
            $index += 1;
            if (!is_array($row) || !array_key_exists('user_id', $row)) {
                continue;
            }
            $userId = self::canonicalInt($row['user_id']);
            if ($userId === null || $userId < 0) {
                continue;
            }
            $text = self::previewText(isset($row['msg']) ? $row['msg'] : '');
            if ($text === null) {
                continue;
            }
            $id = 0;
            if (isset($row['id'])) {
                $parsed = self::canonicalInt($row['id']);
                if ($parsed !== null && $parsed > 0) {
                    $id = $parsed;
                }
            }
            $candidates[] = array(
                'id' => $id,
                'index' => $index,
                'text' => $text,
            );
        }
        if ($candidates === array()) {
            return null;
        }

        usort(
            $candidates,
            static function (array $left, array $right) {
                if ($left['id'] !== $right['id']) {
                    if ($left['id'] === 0) {
                        return 1;
                    }
                    if ($right['id'] === 0) {
                        return -1;
                    }

                    return $right['id'] <=> $left['id'];
                }

                return $right['index'] <=> $left['index'];
            }
        );

        return $candidates[0]['text'];
    }

    /**
     * @param object|array<string, mixed> $chat
     * @param mixed $preview
     * @param mixed $url
     * @return array<string, mixed>|null
     */
    public static function inboxItem($chat, $preview, $url)
    {
        if (!is_array($chat) && !is_object($chat)) {
            return null;
        }
        $id = self::positiveInt(self::recordValue($chat, 'id'));
        if ($id < 1) {
            return null;
        }
        $safeUrl = self::safeChatUrl($url, $id);
        if ($safeUrl === null) {
            return null;
        }

        $item = array(
            'id' => $id,
            'url' => $safeUrl,
        );
        $nick = self::plainText(self::recordValue($chat, 'nick'));
        if ($nick !== null) {
            $item['nick'] = $nick;
        }
        $text = self::previewText($preview);
        if ($text !== null) {
            $item['preview'] = $text;
        }
        $stamp = self::activityStamp($chat);
        if ($stamp !== null) {
            $item['time'] = $stamp;
        }
        $statusValue = self::recordValue($chat, 'status');
        if ($statusValue !== null) {
            $parsed = self::canonicalInt($statusValue);
            $token = $parsed === null ? null : self::statusToken($parsed);
            if ($token !== null) {
                $item['status'] = $token;
            }
        }
        $unread = self::canonicalInt(self::recordValue($chat, 'has_unread_messages'));
        $item['unread'] = $unread === 1;

        return $item;
    }

    /**
     * The caller has already applied the department list limitation and offset.
     * This only drops rows hasAccessToRead rejects. It does not backfill the page.
     *
     * @param array<int, object|array<string, mixed>> $chats
     * @param callable $canRead
     * @param callable $previewFor
     * @param mixed $siteBase
     * @return array<int, array<string, mixed>>
     */
    public static function visibleInbox($chats, $canRead, $previewFor, $siteBase)
    {
        if (!is_array($chats) || !is_callable($canRead) || !is_callable($previewFor)) {
            return array();
        }

        $accepted = array();
        $seen = array();
        foreach ($chats as $chat) {
            if (!is_array($chat) && !is_object($chat)) {
                continue;
            }
            $id = self::positiveInt(self::recordValue($chat, 'id'));
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($canRead($chat) !== true) {
                continue;
            }
            if (self::deskUrl($siteBase, 'mdmobile/chat/' . $id) === null) {
                continue;
            }
            $accepted[] = $chat;
        }

        usort(
            $accepted,
            static function ($left, $right) {
                $leftTime = self::activityStamp($left);
                $rightTime = self::activityStamp($right);
                $leftTime = $leftTime === null ? 0 : $leftTime;
                $rightTime = $rightTime === null ? 0 : $rightTime;
                if ($leftTime !== $rightTime) {
                    return $rightTime <=> $leftTime;
                }

                return self::positiveInt(self::recordValue($right, 'id')) <=> self::positiveInt(self::recordValue($left, 'id'));
            }
        );

        $items = array();
        foreach (array_slice($accepted, 0, self::INBOX_LIMIT) as $chat) {
            $id = self::positiveInt(self::recordValue($chat, 'id'));
            $item = self::inboxItem($chat, $previewFor($chat), self::deskUrl($siteBase, 'mdmobile/chat/' . $id));
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param mixed $chats
     * @param mixed $q
     * @param mixed $status
     * @param mixed $offset
     * @param mixed $currentId
     * @param mixed $siteBase
     * @return array<string, mixed>
     */
    public static function inboxResult($chats, $q, $status, $offset, $currentId, $siteBase)
    {
        $request = self::inboxRequest($q, $status, $offset);
        $listUrl = self::inboxListUrl($siteBase, $request['q'], $request['status'], $request['offset']);
        $endpoint = self::deskUrl($siteBase, 'mdmobile/list');

        return array(
            'chats' => self::cleanInboxChats($chats),
            'q' => $request['q'],
            'status' => $request['status'],
            'list_url' => $listUrl === null ? '' : $listUrl,
            'list_endpoint' => $endpoint === null ? '' : $endpoint,
            'current_id' => self::positiveInt($currentId),
            'offset' => $request['offset'],
            'limit' => self::INBOX_LIMIT,
        );
    }

    /**
     * @param mixed $chats
     * @param mixed $offset
     * @param mixed $q
     * @param mixed $status
     * @return array<string, mixed>
     */
    public static function inboxJson($chats, $offset, $q, $status)
    {
        $request = self::inboxRequest($q, $status, $offset);

        return array(
            'chats' => self::cleanInboxChats($chats),
            'offset' => $request['offset'],
            'limit' => self::INBOX_LIMIT,
            'q' => $request['q'],
            'status' => $request['status'],
        );
    }

    /**
     * Foreground seen-write from LHC 4.97 modules/lhchat/adminchat.php.
     * The owner, unassigned, or closed condition clears visitor unread and
     * sets support_informed / unread_messages_informed. It does not accept,
     * assign, or change status. unanswered_chat follows the same open path
     * when the visitor front status is 0 or 2. Invisible operators who do
     * not own the chat do not write, matching the adminchat outer gate.
     *
     * @param object|array<string, mixed> $chat
     * @param mixed $operatorId
     * @param mixed $canWrite
     * @param mixed $invisible
     * @param mixed $userStatusFront
     * @return array{fields: array<string, int>, messages: array<string, mixed>|null, deliver: bool}
     */
    public static function readMutation($chat, $operatorId, $canWrite, $invisible, $userStatusFront)
    {
        $empty = array(
            'fields' => array(),
            'messages' => null,
            'deliver' => false,
        );
        if ((!is_array($chat) && !is_object($chat)) || $canWrite !== true) {
            return $empty;
        }
        $chatId = self::positiveInt(self::recordValue($chat, 'id'));
        if ($chatId < 1) {
            return $empty;
        }

        $operator = self::positiveInt($operatorId);
        $ownerId = self::canonicalInt(self::recordValue($chat, 'user_id'));
        if ($ownerId === null) {
            $ownerId = -1;
        }
        $owns = $operator > 0 && $ownerId === $operator;
        if ($invisible === true && $owns !== true) {
            return $empty;
        }

        $status = self::canonicalInt(self::recordValue($chat, 'status'));
        $fields = array();
        $messages = null;
        $deliver = false;
        if ($owns || $ownerId === 0 || $status === 2) {
            $unread = self::canonicalInt(self::recordValue($chat, 'has_unread_messages'));
            if ($unread === 1) {
                $fields['has_unread_messages'] = 0;
                $messages = array(
                    'chat_id' => $chatId,
                    'del_st' => 3,
                    'del_st_in' => array(0, 1, 2),
                    'user_id' => 0,
                );
                $deliver = true;
            }
            $fields['support_informed'] = 1;
            $fields['unread_messages_informed'] = 0;
        }
        $unanswered = self::canonicalInt(self::recordValue($chat, 'unanswered_chat'));
        $front = self::canonicalInt($userStatusFront);
        if ($unanswered === 1 && ($front === 0 || $front === 2)) {
            $fields['unanswered_chat'] = 0;
        }

        return array(
            'fields' => $fields,
            'messages' => $messages,
            'deliver' => $deliver,
        );
    }

    /**
     * @param mixed $chatId
     * @param mixed $unread
     * @param mixed $applied
     * @return array{id: int, unread: bool, applied: bool}
     */
    public static function readResult($chatId, $unread, $applied)
    {
        return array(
            'id' => self::positiveInt($chatId),
            'unread' => $unread === true,
            'applied' => $applied === true,
        );
    }

    /**
     * @param object|array<string, mixed> $chat
     */
    public static function departmentId($chat)
    {
        if (!is_array($chat) && !is_object($chat)) {
            return 0;
        }

        return self::positiveInt(self::recordValue($chat, 'dep_id'));
    }

    /**
     * @param mixed $department
     * @return string
     */
    public static function departmentName($department)
    {
        if (!is_object($department) && !is_array($department)) {
            return '';
        }
        $name = self::plainText(self::recordValue($department, 'name'));

        return $name === null ? '' : $name;
    }

    /**
     * Visitor facts for the desk. Missing values are omitted, never filled in.
     *
     * Field labels, when present: Status Name Email Phone Location Language
     * Device IP Page Source Department Started Handoff Wait Remarks. Status is
     * the real chat status with a capital first letter, never a guessed
     * Online. Summary is only Email, Phone, Location, Language, Device, and
     * Department. History status stays pending, active, closed, chatbox,
     * operators, bot. History time is a unix stamp. Started and Handoff
     * values are a UTC fallback; their timestamp is the same unix seconds.
     * Page is the chat page, Source
     * is the session referrer, and only a safe URL is kept.
     *
     * @param object|array<string, mixed> $chat
     * @param array<int, array<string, mixed>> $history
     * @return array{summary: array<int, string>, fields: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>}
     */
    public static function visitorContext($chat, $department = '', array $history = array())
    {
        if (!is_array($chat) && !is_object($chat)) {
            $chat = array();
        }

        $fields = array();
        $summary = array();

        $statusValue = self::recordValue($chat, 'status');
        if ($statusValue !== null) {
            $parsedStatus = self::canonicalInt($statusValue);
            $statusToken = $parsedStatus === null ? null : self::statusToken($parsedStatus);
            if ($statusToken !== null) {
                self::addField($fields, 'Status', ucfirst($statusToken));
            }
        }

        $nick = self::plainText(self::recordValue($chat, 'nick'));
        self::addField($fields, 'Name', $nick);

        $email = self::identityEmail(self::recordValue($chat, 'email'));
        self::addField($fields, 'Email', $email);
        self::addSummary($summary, $email);

        $phone = self::phoneText(self::recordValue($chat, 'phone'));
        self::addField($fields, 'Phone', $phone);
        self::addSummary($summary, $phone);

        $place = self::placeText($chat);
        self::addField($fields, 'Location', $place);
        self::addSummary($summary, $place);

        $locale = self::localeText(self::recordValue($chat, 'chat_locale'));
        self::addField($fields, 'Language', $locale);
        self::addSummary($summary, $locale);

        $device = self::deviceText($chat);
        self::addField($fields, 'Device', $device['detail']);
        self::addSummary($summary, $device['word']);

        $ip = self::ipText(self::recordValue($chat, 'ip'));
        self::addField($fields, 'IP', $ip);

        $page = self::sourceUrl(self::recordValue($chat, 'referrer'));
        self::addField($fields, 'Page', $page, $page);

        $source = self::sourceUrl(self::recordValue($chat, 'session_referrer'));
        self::addField($fields, 'Source', $source, $source);

        $departmentName = self::plainText($department);
        self::addField($fields, 'Department', $departmentName);
        self::addSummary($summary, $departmentName);

        $started = self::positiveStamp(self::recordValue($chat, 'time'));
        self::addTimeField($fields, 'Started', $started);

        $pending = self::positiveStamp(self::recordValue($chat, 'pnd_time'));
        if ($pending !== null && $pending !== $started) {
            self::addTimeField($fields, 'Handoff', $pending);
        }

        $wait = self::waitText(self::recordValue($chat, 'wait_time'));
        self::addField($fields, 'Wait', $wait);

        self::addField($fields, 'Remarks', self::remarkText(self::recordValue($chat, 'remarks')));

        return self::normalizeContext(array(
            'summary' => $summary,
            'fields' => $fields,
            'history' => $history,
        ));
    }

    const HISTORY_LIMIT = 5;

    const HISTORY_QUERY_LIMIT = 50;

    const FIRST_MESSAGE_LIMIT = 20;

    const PREVIEW_LIMIT = 120;

    const REMARK_LIMIT = 1000;

    const UA_LIMIT = 180;

    const URL_LIMIT = 2000;

    /**
     * @param mixed $value
     * @return int|null
     */
    private static function positiveStamp($value)
    {
        $parsed = self::canonicalInt($value);
        if ($parsed === null || $parsed < 1) {
            return null;
        }

        return $parsed;
    }

    /**
     * @param object|array<string, mixed> $record
     * @return mixed
     */
    private static function recordValue($record, $key)
    {
        if (is_array($record)) {
            return array_key_exists($key, $record) ? $record[$key] : null;
        }
        if (is_object($record) && property_exists($record, $key)) {
            return $record->$key;
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function plainText($value)
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            return null;
        }

        return $text;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function identityEmail($value)
    {
        $text = self::plainText($value);
        if ($text === null || strlen($text) > 254 || preg_match('/\s/', $text) === 1) {
            return null;
        }

        return $text;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function phoneText($value)
    {
        $text = self::plainText($value);
        if ($text === null || strlen($text) > 64 || preg_match('/\d/', $text) !== 1) {
            return null;
        }

        return $text;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function localeText($value)
    {
        $text = self::plainText($value);
        if ($text === null || preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,3}$/', $text) !== 1) {
            return null;
        }

        return $text;
    }

    /**
     * @param object|array<string, mixed> $chat
     * @return string|null
     */
    private static function placeText($chat)
    {
        $city = self::plainText(self::recordValue($chat, 'city'));
        $country = self::plainText(self::recordValue($chat, 'country_name'));
        if ($city !== null && $country !== null) {
            return $city . ' · ' . $country;
        }
        if ($city !== null) {
            return $city;
        }
        if ($country !== null) {
            return $country;
        }
        $code = self::plainText(self::recordValue($chat, 'country_code'));
        if ($code !== null && preg_match('/^[A-Za-z]{2}$/', $code) === 1) {
            return $code;
        }

        return null;
    }

    /**
     * @param object|array<string, mixed> $chat
     * @return array{word: string|null, detail: string|null}
     */
    private static function deviceText($chat)
    {
        $type = self::canonicalInt(self::recordValue($chat, 'device_type'));
        $agent = self::plainText(self::recordValue($chat, 'uagent'));
        if ($agent !== null) {
            $agent = self::shorten($agent, self::UA_LIMIT);
        }
        $word = null;
        if ($type === 1) {
            $word = 'Mobile';
        } elseif ($type === 2) {
            $word = 'Tablet';
        } elseif ($type === 0 && $agent !== null) {
            $word = 'Computer';
        }

        $detail = $agent;
        if ($word !== null && $agent !== null) {
            $detail = $word . ' · ' . $agent;
        } elseif ($word !== null) {
            $detail = $word;
        }

        return array(
            'word' => $word,
            'detail' => $detail,
        );
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function ipText($value)
    {
        $text = self::plainText($value);
        if ($text === null || filter_var($text, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $text;
    }

    /**
     * UTC fallback only. The phone formats the unix timestamp locally.
     *
     * @param int|null $stamp
     * @return string|null
     */
    private static function clockText($stamp)
    {
        if (!is_int($stamp) || $stamp < 1) {
            return null;
        }

        return gmdate('Y-m-d H:i', $stamp);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function waitText($value)
    {
        $seconds = self::canonicalInt($value);
        if ($seconds === null || $seconds < 1) {
            return null;
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;
        $parts = array();
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        if ($rest > 0 && $hours === 0) {
            $parts[] = $rest . 's';
        }
        if ($parts === array()) {
            return null;
        }

        return implode(' ', $parts);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function remarkText($value)
    {
        $text = self::fieldValue($value);
        if ($text === null) {
            return null;
        }

        return self::shorten($text, self::REMARK_LIMIT);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function fieldValue($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $text = str_replace(array("\r\n", "\r"), "\n", $value);
        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if (!is_string($stripped)) {
            return null;
        }
        $stripped = trim($stripped);
        if ($stripped === '') {
            return null;
        }

        return self::shorten($stripped, self::URL_LIMIT);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function previewText($value)
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $text = str_replace(array("\r\n", "\r"), ' ', (string) $value);
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($collapsed)) {
            return null;
        }
        $collapsed = trim($collapsed);
        if ($collapsed === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $collapsed) === 1) {
            return null;
        }

        return self::shorten($collapsed, self::PREVIEW_LIMIT);
    }

    /**
     * @param int|null $status
     * @return string|null
     */
    private static function statusToken($status)
    {
        if ($status === 0) {
            return 'pending';
        }
        if ($status === 1) {
            return 'active';
        }
        if ($status === 2) {
            return 'closed';
        }
        if ($status === 3) {
            return 'chatbox';
        }
        if ($status === 4) {
            return 'operators';
        }
        if ($status === 5) {
            return 'bot';
        }

        return null;
    }

    /**
     * @param mixed $status
     */
    private static function knownStatus($status)
    {
        return $status === 'pending'
            || $status === 'active'
            || $status === 'closed'
            || $status === 'chatbox'
            || $status === 'operators'
            || $status === 'bot';
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param int|null $stamp
     */
    private static function addTimeField(array &$fields, $label, $stamp)
    {
        $text = self::clockText($stamp);
        if ($text === null || !is_int($stamp) || $stamp < 1) {
            return;
        }
        $fields[] = array(
            'label' => $label,
            'value' => $text,
            'timestamp' => $stamp,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param string|null $value
     * @param string|null $url
     */
    private static function addField(array &$fields, $label, $value, $url = null)
    {
        if (!is_string($value) || $value === '') {
            return;
        }
        $field = array(
            'label' => $label,
            'value' => $value,
        );
        if (is_string($url) && $url !== '') {
            $safe = self::sourceUrl($url);
            if ($safe !== null) {
                $field['url'] = $safe;
            }
        }
        $fields[] = $field;
    }

    /**
     * @param array<int, string> $summary
     * @param string|null $value
     */
    private static function addSummary(array &$summary, $value)
    {
        if (is_string($value) && $value !== '') {
            $summary[] = $value;
        }
    }

    /**
     * @return string
     */
    private static function shorten($text, $limit)
    {
        $limit = (int) $limit;
        if ($limit < 1) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $limit) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
        }
        if (preg_match('/^.{0,' . $limit . '}/us', $text, $match) !== 1) {
            return $text;
        }
        if (!isset($match[0]) || $match[0] === $text) {
            return $text;
        }

        return rtrim($match[0]) . '…';
    }

    /**
     * @return array{summary: array<int, string>, fields: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>}
     */
    private static function emptyContext()
    {
        return array(
            'summary' => array(),
            'fields' => array(),
            'history' => array(),
        );
    }

    /**
     * @param mixed $context
     * @return array{summary: array<int, string>, fields: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>}
     */
    private static function normalizeContext($context)
    {
        if (!is_array($context)) {
            return self::emptyContext();
        }

        $summary = array();
        if (isset($context['summary']) && is_array($context['summary'])) {
            foreach ($context['summary'] as $item) {
                $text = self::plainText($item);
                if ($text !== null) {
                    $summary[] = $text;
                }
            }
        }

        $fields = array();
        if (isset($context['fields']) && is_array($context['fields'])) {
            foreach ($context['fields'] as $field) {
                if (!is_array($field) || !isset($field['label'], $field['value'])) {
                    continue;
                }
                $label = self::plainText($field['label']);
                $value = self::fieldValue($field['value']);
                if ($label === null || $value === null) {
                    continue;
                }
                $clean = array(
                    'label' => $label,
                    'value' => $value,
                );
                if (isset($field['url'])) {
                    $url = self::sourceUrl($field['url']);
                    if ($url !== null) {
                        $clean['url'] = $url;
                    }
                }
                if (isset($field['timestamp'])) {
                    $stamp = self::positiveStamp($field['timestamp']);
                    if ($stamp !== null) {
                        $clean['timestamp'] = $stamp;
                    }
                }
                $fields[] = $clean;
            }
        }

        $history = array();
        if (isset($context['history']) && is_array($context['history'])) {
            $history = self::normalizeHistory($context['history']);
        }

        return array(
            'summary' => $summary,
            'fields' => $fields,
            'history' => $history,
        );
    }

    /**
     * @param array<int, mixed> $history
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeHistory(array $history)
    {
        $items = array();
        foreach ($history as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $id = self::canonicalInt($item['id']);
            if ($id === null || $id < 1) {
                continue;
            }
            $clean = array('id' => $id);
            if (isset($item['time'])) {
                $time = self::canonicalInt($item['time']);
                if ($time !== null && $time > 0) {
                    $clean['time'] = $time;
                }
            }
            if (isset($item['preview'])) {
                $preview = self::previewText($item['preview']);
                if ($preview !== null) {
                    $clean['preview'] = $preview;
                }
            }
            if (isset($item['status']) && self::knownStatus($item['status'])) {
                $clean['status'] = $item['status'];
            }
            $items[] = $clean;
            if (count($items) >= self::HISTORY_LIMIT) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param mixed $value
     */
    private static function positiveInt($value)
    {
        $parsed = self::canonicalInt($value);
        if ($parsed === null || $parsed < 1) {
            return 0;
        }

        return $parsed;
    }

    /**
     * @param mixed $value
     */
    private static function nonNegativeInt($value)
    {
        $parsed = self::canonicalInt($value);
        if ($parsed === null || $parsed < 0) {
            return 0;
        }

        return $parsed;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private static function canonicalInt($value)
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value) === 1) {
            $parsed = (int) $value;
            if ((string) $parsed === $value) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $urls
     * @return array<string, string>
     */
    private static function bagDeskUrls(array $urls, $chatId)
    {
        $desk = array();
        $list = self::acceptedDeskUrl(isset($urls['list_url']) ? $urls['list_url'] : null, 'mdmobile/chats');
        $read = self::acceptedDeskUrl(isset($urls['read_url']) ? $urls['read_url'] : null, 'mdmobile/read/' . (int) $chatId);
        if ($list === null || $read === null) {
            $derived = self::deskUrlsFromMessages(isset($urls['messages_url']) ? $urls['messages_url'] : '', $chatId);
            if ($list === null && $derived !== null) {
                $list = $derived['list_url'];
            }
            if ($read === null && $derived !== null) {
                $read = $derived['read_url'];
            }
        }
        if ($list !== null) {
            $desk['list_url'] = $list;
        }
        if ($read !== null) {
            $desk['read_url'] = $read;
        }

        return $desk;
    }

    /**
     * @param mixed $url
     * @param mixed $path
     * @return string|null
     */
    private static function acceptedDeskUrl($url, $path)
    {
        if (!is_string($url)) {
            return null;
        }
        $safePath = self::deskPath($path);
        if ($safePath === null) {
            return null;
        }
        $trimmed = rtrim($url, '/');
        $suffix = '/' . $safePath;
        if (strlen($trimmed) <= strlen($suffix) || substr($trimmed, -strlen($suffix)) !== $suffix) {
            return null;
        }
        $base = substr($trimmed, 0, -strlen($suffix));
        $built = self::deskUrl($base, $safePath);
        if ($built === null || $built !== $trimmed) {
            return null;
        }

        return $built;
    }

    /**
     * @param mixed $messagesUrl
     * @param mixed $chatId
     * @return array{list_url: string, read_url: string}|null
     */
    private static function deskUrlsFromMessages($messagesUrl, $chatId)
    {
        $chatId = self::positiveInt($chatId);
        if ($chatId < 1 || !is_string($messagesUrl)) {
            return null;
        }
        $suffix = '/mdmobile/messages/' . $chatId;
        if (strlen($messagesUrl) <= strlen($suffix) || substr($messagesUrl, -strlen($suffix)) !== $suffix) {
            return null;
        }
        $base = substr($messagesUrl, 0, -strlen($suffix));
        $list = self::deskUrl($base, 'mdmobile/chats');
        $read = self::deskUrl($base, 'mdmobile/read/' . $chatId);
        if ($list === null || $read === null) {
            return null;
        }

        return array(
            'list_url' => $list,
            'read_url' => $read,
        );
    }

    /**
     * @param mixed $siteBase
     * @return string|null
     */
    private static function safeBase($siteBase)
    {
        if (!is_string($siteBase)) {
            return null;
        }
        $base = rtrim($siteBase, '/');
        if ($base === '' || strlen($base) > self::URL_LIMIT) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F<>"\'\\\\]/', $base) === 1) {
            return null;
        }
        if (preg_match('#^https?://#i', $base) === 1) {
            $parsed = parse_url($base);
            if (!is_array($parsed) || !isset($parsed['host']) || $parsed['host'] === '') {
                return null;
            }
            if (isset($parsed['user']) || isset($parsed['pass'])) {
                return null;
            }
            $scheme = strtolower((string) $parsed['scheme']);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }

            return $base;
        }
        if (strpos($base, '/') !== 0 || strpos($base, '//') === 0 || strpos($base, '..') !== false) {
            return null;
        }

        return $base;
    }

    /**
     * @param mixed $path
     * @return string|null
     */
    private static function deskPath($path)
    {
        if ($path === 'mdmobile/chats' || $path === 'mdmobile/list') {
            return $path;
        }
        if (!is_string($path) || preg_match('/^mdmobile\/(chat|read)\/([1-9]\d*)$/', $path, $match) !== 1) {
            return null;
        }
        $id = self::canonicalInt($match[2]);
        if ($id === null || (string) $id !== $match[2]) {
            return null;
        }

        return 'mdmobile/' . $match[1] . '/' . $id;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function inboxQueryText($value)
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return '';
        }
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        if (!is_string($stripped)) {
            return '';
        }
        $stripped = trim($stripped);
        if ($stripped === '') {
            return '';
        }

        return self::limitText($stripped, self::INBOX_QUERY_LIMIT);
    }

    /**
     * @param mixed $status
     * @return string
     */
    private static function inboxStatusToken($status)
    {
        if (!is_string($status) || !array_key_exists($status, self::inboxStatuses())) {
            return '';
        }

        return $status;
    }

    /**
     * @param string $q
     * @return array<string, int|string>|null
     */
    private static function inboxSearch($q)
    {
        if ($q === '') {
            return null;
        }
        $search = array('nick' => $q);
        $id = self::canonicalInt($q);
        if ($id !== null && $id > 0) {
            $search['id'] = $id;
        }

        return $search;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function likeEscape($value)
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
    }

    /**
     * @param array{q: string, status: string, offset: int} $request
     * @return string
     */
    private static function inboxQueryString(array $request)
    {
        $parts = array();
        if ($request['q'] !== '') {
            $parts[] = 'q=' . rawurlencode($request['q']);
        }
        if ($request['status'] !== '') {
            $parts[] = 'status=' . rawurlencode($request['status']);
        }
        if ($request['offset'] > 0) {
            $parts[] = 'offset=' . $request['offset'];
        }

        return implode('&', $parts);
    }

    /**
     * @param object|array<string, mixed> $chat
     * @return int|null
     */
    private static function activityStamp($chat)
    {
        $best = 0;
        foreach (array('time', 'last_user_msg_time', 'last_op_msg_time') as $key) {
            $stamp = self::positiveStamp(self::recordValue($chat, $key));
            if ($stamp !== null && $stamp > $best) {
                $best = $stamp;
            }
        }

        return $best > 0 ? $best : null;
    }

    /**
     * @param mixed $url
     * @param int $chatId
     * @return string|null
     */
    private static function safeChatUrl($url, $chatId)
    {
        if (!is_string($url) || $chatId < 1) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > self::URL_LIMIT || strpos($url, '?') !== false || strpos($url, '#') !== false) {
            return null;
        }

        return self::acceptedDeskUrl($url, 'mdmobile/chat/' . $chatId);
    }

    /**
     * @param mixed $chats
     * @return array<int, array<string, mixed>>
     */
    private static function cleanInboxChats($chats)
    {
        if (!is_array($chats)) {
            return array();
        }
        $items = array();
        foreach ($chats as $chat) {
            $item = self::cleanInboxItem($chat);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
            if (count($items) >= self::INBOX_LIMIT) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param mixed $item
     * @return array<string, mixed>|null
     */
    private static function cleanInboxItem($item)
    {
        if (!is_array($item) || !isset($item['id'], $item['url'])) {
            return null;
        }
        $id = self::positiveInt($item['id']);
        $url = self::safeChatUrl(isset($item['url']) ? $item['url'] : null, $id);
        if ($id < 1 || $url === null) {
            return null;
        }
        $clean = array(
            'id' => $id,
            'url' => $url,
        );
        if (isset($item['nick'])) {
            $nick = self::plainText($item['nick']);
            if ($nick !== null) {
                $clean['nick'] = $nick;
            }
        }
        if (isset($item['preview'])) {
            $preview = self::previewText($item['preview']);
            if ($preview !== null) {
                $clean['preview'] = $preview;
            }
        }
        if (isset($item['time'])) {
            $time = self::positiveStamp($item['time']);
            if ($time !== null) {
                $clean['time'] = $time;
            }
        }
        if (isset($item['status']) && self::knownStatus($item['status'])) {
            $clean['status'] = $item['status'];
        }
        $unread = isset($item['unread']) ? $item['unread'] : false;
        $clean['unread'] = $unread === true || $unread === 1 || $unread === '1';

        return $clean;
    }

    /**
     * @param string $text
     * @param int $limit
     * @return string
     */
    private static function limitText($text, $limit)
    {
        $limit = (int) $limit;
        if ($limit < 1) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $limit) {
                return $text;
            }

            return mb_substr($text, 0, $limit, 'UTF-8');
        }
        if (preg_match('/^.{0,' . $limit . '}/us', $text, $match) === 1 && isset($match[0])) {
            return $match[0];
        }

        return $text;
    }
}
