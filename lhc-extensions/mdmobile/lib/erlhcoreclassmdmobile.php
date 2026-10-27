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

        return array(
            'messages_url' => $base . '/mdmobile/messages/' . $chatId,
            'send_url' => $base . '/chat/addmsgadmin/' . $chatId,
        );
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

        return 403;
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
}
