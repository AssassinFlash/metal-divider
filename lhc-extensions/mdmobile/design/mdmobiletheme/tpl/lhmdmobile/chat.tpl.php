<?php
if (!function_exists('mdmobile_esc')) {
    function mdmobile_esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('mdmobile_field')) {
    function mdmobile_field($row, $key)
    {
        if (is_array($row) && array_key_exists($key, $row)) {
            return $row[$key];
        }
        if (is_object($row) && isset($row->$key)) {
            return $row->$key;
        }

        return null;
    }
}

if (!function_exists('mdmobile_kind')) {
    function mdmobile_kind($value)
    {
        $kind = strtolower(trim((string) $value));
        if ($kind === 'visitor' || $kind === 'operator' || $kind === 'system' || $kind === 'bot') {
            return $kind;
        }

        return 'system';
    }
}

if (!function_exists('mdmobile_stamp')) {
    function mdmobile_stamp($value)
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d+$/', (string) $value) === 1)) {
            $stamp = (int) $value;
            if ($stamp > 100000000000) {
                $stamp = (int) floor($stamp / 1000);
            }
            if ($stamp > 0) {
                return $stamp;
            }
        }

        return 0;
    }
}

if (!function_exists('mdmobile_utc_label')) {
    function mdmobile_utc_label($stamp, $style)
    {
        $stamp = mdmobile_stamp($stamp);
        if ($stamp < 1) {
            return '';
        }
        if ($style === 'datetime') {
            return gmdate('Y-m-d H:i', $stamp);
        }

        return gmdate('H:i', $stamp);
    }
}

if (!function_exists('mdmobile_time_label')) {
    function mdmobile_time_label($value)
    {
        $stamp = mdmobile_stamp($value);
        if ($stamp > 0) {
            return mdmobile_utc_label($stamp, 'time');
        }
        if ($value === null || $value === '') {
            return '';
        }

        return (string) $value;
    }
}

if (!function_exists('mdmobile_pick')) {
    function mdmobile_pick($sources, $keys)
    {
        foreach ($keys as $key) {
            foreach ($sources as $source) {
                if (is_array($source) && array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                    return $source[$key];
                }
            }
        }

        return null;
    }
}

if (!function_exists('mdmobile_text')) {
    function mdmobile_text($value)
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return trim((string) $value);
        }

        return '';
    }
}

if (!function_exists('mdmobile_label_is')) {
    function mdmobile_label_is($label, array $names)
    {
        $key = strtolower(mdmobile_text($label));
        $key = preg_replace('/\s+/', ' ', $key);
        if (!is_string($key) || $key === '') {
            return false;
        }

        return in_array($key, $names, true);
    }
}

if (!function_exists('mdmobile_is_email')) {
    function mdmobile_is_email($value)
    {
        $value = mdmobile_text($value);
        if ($value === '' || strlen($value) > 254 || strpos($value, ' ') !== false) {
            return false;
        }

        return preg_match('/^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$/', $value) === 1;
    }
}

if (!function_exists('mdmobile_is_phone')) {
    function mdmobile_is_phone($value)
    {
        $value = mdmobile_text($value);
        if ($value === '' || strpos($value, '://') !== false || strpos($value, '@') !== false) {
            return false;
        }
        if (preg_match('/[A-Za-z]/', $value) === 1) {
            return false;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $value);
        if (!is_string($digits)) {
            return false;
        }
        $length = strlen($digits);
        if ($length < 7 || $length > 15) {
            return false;
        }

        return preg_match('/^\+?[0-9(][0-9\s().-]{5,}$/', $value) === 1;
    }
}

if (!function_exists('mdmobile_http_url')) {
    function mdmobile_http_url($value)
    {
        $value = mdmobile_text($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            return '';
        }
        if (strpos($value, '//') === 0) {
            return mdmobile_protocol_relative_url($value);
        }
        if (preg_match('#^https?://[^\s<>"\']+$#i', $value) !== 1) {
            return '';
        }

        return preg_replace_callback(
            '#^https?://#i',
            static function ($match) {
                return strtolower($match[0]);
            },
            $value
        );
    }
}

if (!function_exists('mdmobile_protocol_relative_url')) {
    function mdmobile_protocol_relative_url($value)
    {
        if (!is_string($value) || strpos($value, '//') !== 0 || strpos($value, '///') === 0 || strlen($value) < 3) {
            return '';
        }
        if (preg_match('#^//[^\s<>"\'\\\\]+$#', $value) !== 1) {
            return '';
        }
        $parsed = parse_url('https:' . $value);
        if (!is_array($parsed)) {
            return '';
        }
        $host = isset($parsed['host']) ? (string) $parsed['host'] : '';
        if ($host === '' || $host === '.' || $host === '..') {
            return '';
        }
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return '';
        }
        $named = preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1;
        $ipv6 = preg_match('/^\[[0-9A-Fa-f:.]+\]$/', $host) === 1;
        if (!$named && !$ipv6) {
            return '';
        }

        return $value;
    }
}

if (!function_exists('mdmobile_mailto_href')) {
    function mdmobile_mailto_href($url, $value)
    {
        if (is_string($url) && preg_match('/^mailto:/i', $url) === 1) {
            $address = substr($url, 7);
            $query = strpos($address, '?');
            if ($query !== false) {
                $address = substr($address, 0, $query);
            }
            $address = rawurldecode($address);
            if (mdmobile_is_email($address)) {
                return 'mailto:' . $address;
            }
        }
        if (mdmobile_is_email($value)) {
            return 'mailto:' . mdmobile_text($value);
        }

        return '';
    }
}

if (!function_exists('mdmobile_tel_href')) {
    function mdmobile_tel_href($url, $value)
    {
        $candidate = '';
        if (is_string($url) && preg_match('/^tel:/i', $url) === 1) {
            $candidate = rawurldecode(substr($url, 4));
        } elseif (mdmobile_is_phone($value)) {
            $candidate = mdmobile_text($value);
        }
        if ($candidate === '' || !mdmobile_is_phone($candidate)) {
            return '';
        }
        $compact = preg_replace('/[^\d+]/', '', $candidate);
        if (!is_string($compact)) {
            return '';
        }
        $plus = strpos($compact, '+') === 0;
        $digits = preg_replace('/\D+/', '', $compact);
        if (!is_string($digits) || strlen($digits) < 7) {
            return '';
        }

        return 'tel:' . ($plus ? '+' : '') . $digits;
    }
}

if (!function_exists('mdmobile_link')) {
    function mdmobile_link($label, $value, $url)
    {
        $url = is_string($url) ? trim($url) : '';
        $mailto = mdmobile_mailto_href($url, $value);
        $tel = mdmobile_tel_href($url, $value);
        $emailLabel = mdmobile_label_is($label, array('email', 'e-mail', 'email address', 'e-mail address', '邮箱', '电子邮件'));
        $phoneLabel = mdmobile_label_is($label, array('phone', 'phone number', 'mobile', 'mobile number', 'telephone', 'tel', '电话', '手机'));
        if ($emailLabel) {
            if ($mailto === '') {
                return null;
            }

            return array('href' => $mailto, 'external' => false);
        }
        if ($phoneLabel) {
            if ($tel === '') {
                return null;
            }

            return array('href' => $tel, 'external' => false);
        }
        if ($mailto !== '') {
            return array('href' => $mailto, 'external' => false);
        }
        if ($tel !== '') {
            return array('href' => $tel, 'external' => false);
        }
        $http = mdmobile_http_url($url);
        if ($http === '') {
            $http = mdmobile_http_url($value);
        }
        if ($http !== '') {
            return array('href' => $http, 'external' => true);
        }

        return null;
    }
}

if (!function_exists('mdmobile_value_html')) {
    function mdmobile_value_html($value, $link)
    {
        if (!is_array($link) || empty($link['href'])) {
            return mdmobile_esc($value);
        }
        $html = '<a href="' . mdmobile_esc($link['href']) . '"';
        if (!empty($link['external'])) {
            $html .= ' target="_blank" rel="noopener noreferrer"';
        }
        $html .= '>' . mdmobile_esc($value);
        if (!empty($link['external'])) {
            $html .= '<span class="md-sr"> (opens in a new window)</span>';
        }
        $html .= '</a>';

        return $html;
    }
}

if (!function_exists('mdmobile_status_tone')) {
    function mdmobile_status_tone($value)
    {
        $text = strtolower(mdmobile_text($value));
        if ($text === '') {
            return 'neutral';
        }
        if (strpos($text, '不在线') !== false || strpos($text, '离线') !== false || strpos($text, '已关闭') !== false) {
            return 'offline';
        }
        if (strpos($text, 'inactive') !== false || strpos($text, 'offline') !== false || strpos($text, 'closed') !== false || strpos($text, 'not online') !== false) {
            return 'offline';
        }
        if (strpos($text, '等待') !== false || strpos($text, 'pending') !== false || strpos($text, 'waiting') !== false || strpos($text, 'away') !== false) {
            return 'waiting';
        }
        if (strpos($text, '在线') !== false || strpos($text, 'online') !== false || strpos($text, 'active') !== false || strpos($text, '进行中') !== false) {
            return 'online';
        }

        return 'neutral';
    }
}

if (!function_exists('mdmobile_status_text')) {
    function mdmobile_status_text($label, $value)
    {
        $value = mdmobile_text($value);
        if ($value === '') {
            return '';
        }
        $letters = preg_match('/[A-Za-z]/', $value) === 1;
        $cjk = preg_match('/[\x{4e00}-\x{9fff}]/u', $value) === 1;
        if ($letters || $cjk) {
            return $value;
        }
        $label = mdmobile_text($label);
        if ($label === '') {
            return $value;
        }

        return $label . ': ' . $value;
    }
}

if (!function_exists('mdmobile_has_text')) {
    function mdmobile_has_text(array $items, $needle)
    {
        $needle = strtolower(mdmobile_text($needle));
        if ($needle === '') {
            return false;
        }
        foreach ($items as $item) {
            if (strtolower(mdmobile_text($item)) === $needle) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('mdmobile_context_array')) {
    function mdmobile_context_array($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            $encoded = json_encode($raw);
            if (is_string($encoded)) {
                $decoded = json_decode($encoded, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return array();
    }
}

if (!function_exists('mdmobile_rows')) {
    function mdmobile_rows($raw)
    {
        if (!is_array($raw)) {
            return array();
        }
        $rows = array();
        foreach ($raw as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('mdmobile_history_id')) {
    function mdmobile_history_id($value)
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1 && $value !== '0') {
            return $value;
        }

        return '';
    }
}

if (!function_exists('mdmobile_history_time')) {
    function mdmobile_history_time($value)
    {
        if ($value === null || $value === '') {
            return array('', '');
        }
        $stamp = mdmobile_stamp($value);
        if ($stamp > 0) {
            return array(mdmobile_utc_label($stamp, 'datetime'), gmdate('c', $stamp));
        }
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d+$/', (string) $value) === 1)) {
            return array('', '');
        }
        $text = mdmobile_text($value);
        if ($text === '') {
            return array('', '');
        }

        return array($text, '');
    }
}

if (!function_exists('mdmobile_time_html')) {
    function mdmobile_time_html($text, $stamp, $style, $class = '')
    {
        $style = $style === 'datetime' ? 'datetime' : 'time';
        $stamp = mdmobile_stamp($stamp);
        $text = mdmobile_text($text);
        if ($text === '' && $stamp > 0) {
            $text = mdmobile_utc_label($stamp, $style);
        }
        if ($text === '') {
            return '';
        }
        $html = '<time';
        if ($class !== '') {
            $html .= ' class="' . mdmobile_esc($class) . '"';
        }
        if ($stamp > 0) {
            $html .= ' data-timestamp="' . mdmobile_esc((string) $stamp) . '" data-time-style="' . $style . '" datetime="' . mdmobile_esc(gmdate('c', $stamp)) . '"';
        }
        $html .= '>' . mdmobile_esc($text) . '</time>';

        return $html;
    }
}

if (!function_exists('mdmobile_clock_stamp')) {
    function mdmobile_clock_stamp($label, $value, $timestamp)
    {
        if (!mdmobile_label_is($label, array('started', 'handoff'))) {
            return 0;
        }
        $stamp = mdmobile_stamp($timestamp);
        if ($stamp > 0) {
            return $stamp;
        }

        return mdmobile_stamp($value);
    }
}

if (!function_exists('mdmobile_notes_label')) {
    function mdmobile_notes_label($label)
    {
        return mdmobile_label_is($label, array('remarks', 'remark', 'notes', 'note', '备注'));
    }
}

if (!function_exists('mdmobile_view')) {
    function mdmobile_view($raw)
    {
        $context = mdmobile_context_array($raw);
        $sourceNames = array('source', 'page', 'referrer', 'referer', 'landing', 'landing page', 'current page', 'source page', 'page url', 'url', 'came from', '来源', '来源页', '页面', '当前页面');
        $onlineNames = array('online', 'presence', 'availability', 'visitor status', '在线');
        $sessionNames = array('status', 'session', 'session status', 'chat status', '状态', '会话', '会话状态');
        $chipNames = array('region', 'country', 'city', 'location', 'state', '地区', '国家', '城市', 'language', 'locale', 'lang', '语言', 'device', '设备', 'department', 'dept', '部门');
        $statusWords = array('online', 'offline', 'away', 'active', 'pending', 'closed', 'waiting', '在线', '离线', '等待', '进行中', '已关闭');
        $fields = array();
        $statuses = array();
        $seenStatus = array();
        $source = null;

        foreach (mdmobile_rows(isset($context['fields']) ? $context['fields'] : array()) as $row) {
            if (!is_array($row) && !is_object($row)) {
                continue;
            }
            $label = mdmobile_text(mdmobile_field($row, 'label'));
            $value = mdmobile_text(mdmobile_field($row, 'value'));
            $url = mdmobile_field($row, 'url');
            $url = is_string($url) ? trim($url) : '';
            if ($value === '' && $url !== '') {
                $carried = mdmobile_http_url($url);
                if ($carried === '') {
                    $carried = mdmobile_mailto_href($url, '');
                }
                if ($carried === '') {
                    $carried = mdmobile_tel_href($url, '');
                }
                if ($carried !== '') {
                    $value = $carried;
                }
            }
            if ($label === '' || $value === '') {
                continue;
            }
            if (strlen($value) > 4000) {
                $value = substr($value, 0, 4000);
            }
            $link = mdmobile_link($label, $value, $url);
            $field = array(
                'label' => $label,
                'value' => $value,
                'link' => $link,
            );
            $fieldStamp = mdmobile_stamp(mdmobile_field($row, 'timestamp'));
            if ($fieldStamp > 0) {
                $field['timestamp'] = $fieldStamp;
            }
            $fields[] = $field;
            if (mdmobile_label_is($label, $onlineNames) || mdmobile_label_is($label, $sessionNames)) {
                $text = mdmobile_status_text($label, $value);
                $key = strtolower($text);
                if ($text !== '' && !isset($seenStatus[$key])) {
                    $seenStatus[$key] = true;
                    $statuses[] = array('text' => $text, 'tone' => mdmobile_status_tone($text));
                }
            }
            if ($source === null && mdmobile_label_is($label, $sourceNames)) {
                $href = '';
                if (is_array($link) && !empty($link['external'])) {
                    $href = $link['href'];
                }
                if ($href === '') {
                    $href = mdmobile_http_url($value);
                }
                if ($href === '') {
                    $href = mdmobile_http_url($url);
                }
                if ($href !== '') {
                    $source = array('label' => $label, 'value' => $value, 'href' => $href);
                }
            }
        }

        $chips = array();
        foreach (mdmobile_rows(isset($context['summary']) ? $context['summary'] : array()) as $item) {
            $text = mdmobile_text($item);
            if ($text === '') {
                continue;
            }
            if (strlen($text) > 4000) {
                $text = substr($text, 0, 4000);
            }
            $http = mdmobile_http_url($text);
            if ($http !== '') {
                if ($source === null) {
                    $source = array('label' => 'Page', 'value' => $http, 'href' => $http);
                }
                continue;
            }
            $statusKey = strtolower($text);
            if (in_array($statusKey, $statusWords, true)) {
                if (!isset($seenStatus[$statusKey])) {
                    $seenStatus[$statusKey] = true;
                    $statuses[] = array('text' => $text, 'tone' => mdmobile_status_tone($text));
                }
                continue;
            }
            if (!mdmobile_has_text($chips, $text)) {
                $chips[] = $text;
            }
        }

        if ($chips === array()) {
            foreach ($fields as $field) {
                if (!mdmobile_label_is($field['label'], $chipNames)) {
                    continue;
                }
                $chip = $field['label'] . ' · ' . $field['value'];
                if (mdmobile_has_text($chips, $field['value']) || mdmobile_has_text($chips, $chip)) {
                    continue;
                }
                $chips[] = $chip;
            }
        }

        $history = array();
        foreach (mdmobile_rows(isset($context['history']) ? $context['history'] : array()) as $row) {
            if (!is_array($row) && !is_object($row)) {
                continue;
            }
            $preview = mdmobile_text(mdmobile_field($row, 'preview'));
            $status = mdmobile_text(mdmobile_field($row, 'status'));
            if ($preview === '' && $status === '') {
                continue;
            }
            if (strlen($preview) > 4000) {
                $preview = substr($preview, 0, 4000);
            }
            if ($preview === '') {
                $preview = $status;
                $status = '';
            }
            $historyStamp = mdmobile_stamp(mdmobile_field($row, 'time'));
            $timePair = mdmobile_history_time(mdmobile_field($row, 'time'));
            $past = array(
                'id' => mdmobile_history_id(mdmobile_field($row, 'id')),
                'preview' => $preview,
                'status' => $status,
                'time' => $timePair[0],
                'datetime' => $timePair[1],
                'timestamp' => $historyStamp,
            );
            $history[] = $past;
        }

        return array(
            'chips' => $chips,
            'statuses' => $statuses,
            'source' => $source,
            'fields' => $fields,
            'history' => $history,
            'drawer' => $fields !== array() || $history !== array(),
        );
    }
}

$mdSet = array();
foreach (array('chat_id', 'after_id', 'latest_id', 'messages_url', 'send_url', 'csrf', 'csrf_token', 'sender', 'messages', 'title', 'nick', 'context') as $mdName) {
    if (isset(${$mdName})) {
        $mdSet[$mdName] = ${$mdName};
    }
}
$mdBag = (isset($md) && is_array($md)) ? $md : array();
$mdResult = (isset($Result) && is_array($Result)) ? $Result : array();
if ($mdBag === array() && isset($mdResult['mdmobile']) && is_array($mdResult['mdmobile'])) {
    $mdBag = $mdResult['mdmobile'];
}

$mdSources = array($mdSet, $mdBag, $mdResult);
$mdChatId = mdmobile_pick($mdSources, array('chat_id'));
$mdAfter = mdmobile_pick($mdSources, array('after_id', 'latest_id'));
$mdMessagesUrl = mdmobile_pick($mdSources, array('messages_url'));
$mdSendUrl = mdmobile_pick($mdSources, array('send_url'));
$mdCsrf = mdmobile_pick($mdSources, array('csrf', 'csrf_token'));
$mdSender = mdmobile_pick($mdSources, array('sender'));
$mdNick = mdmobile_pick($mdSources, array('nick'));
$mdTitle = mdmobile_pick($mdSources, array('title'));
$mdMessages = mdmobile_pick($mdSources, array('messages'));
$mdContext = mdmobile_pick($mdSources, array('context'));
if ($mdContext === null && isset($mdResult['mdmobile']) && is_array($mdResult['mdmobile']) && array_key_exists('context', $mdResult['mdmobile'])) {
    $mdContext = $mdResult['mdmobile']['context'];
}
if (!is_array($mdMessages)) {
    $mdMessages = array();
}
if (!is_string($mdMessagesUrl)) {
    $mdMessagesUrl = '';
}
if (!is_string($mdSendUrl)) {
    $mdSendUrl = '';
}
if (!is_string($mdCsrf)) {
    $mdCsrf = '';
}
if (!is_string($mdSender) || $mdSender === '') {
    $mdSender = 'operator';
}
$mdNick = mdmobile_text($mdNick);
if ($mdNick === '') {
    $mdNick = mdmobile_text($mdTitle);
}
if ($mdNick === '对话' || strcasecmp($mdNick, 'Visitor') === 0) {
    $mdNick = '';
}

$mdAfterId = is_numeric($mdAfter) ? (int) $mdAfter : 0;
$mdRows = array();
foreach ($mdMessages as $mdRow) {
    if (!is_array($mdRow) && !is_object($mdRow)) {
        continue;
    }
    $mdId = mdmobile_field($mdRow, 'id');
    $mdText = mdmobile_field($mdRow, 'text');
    if (!is_numeric($mdId) || (int) $mdId < 1 || $mdText === null || trim((string) $mdText) === '') {
        continue;
    }
    $mdId = (int) $mdId;
    if ($mdId > $mdAfterId) {
        $mdAfterId = $mdId;
    }
    $mdKind = mdmobile_kind(mdmobile_field($mdRow, 'kind'));
    $mdTime = mdmobile_field($mdRow, 'time');
    $mdRows[] = array(
        'id' => $mdId,
        'kind' => $mdKind,
        'text' => (string) $mdText,
        'time' => mdmobile_time_label($mdTime),
        'datetime' => mdmobile_stamp($mdTime) > 0 ? gmdate('c', mdmobile_stamp($mdTime)) : '',
        'timestamp' => mdmobile_stamp($mdTime),
    );
}

$mdKindLabel = array(
    'visitor' => 'Visitor',
    'operator' => 'Operator',
    'system' => 'System',
    'bot' => 'Bot',
);
$mdChatAttr = (is_numeric($mdChatId) && (int) $mdChatId > 0) ? (string) (int) $mdChatId : '';
$mdView = mdmobile_view($mdContext);
$mdHasNick = $mdNick !== '';
$mdHasGlance = $mdView['chips'] !== array() || is_array($mdView['source']);
?><div id="md-mobile-app" class="md-app" data-chat-id="<?php echo mdmobile_esc($mdChatAttr); ?>" data-after-id="<?php echo mdmobile_esc($mdAfterId); ?>" data-messages-url="<?php echo mdmobile_esc($mdMessagesUrl); ?>" data-send-url="<?php echo mdmobile_esc($mdSendUrl); ?>" data-csrf="<?php echo mdmobile_esc($mdCsrf); ?>" data-sender="<?php echo mdmobile_esc($mdSender); ?>">
<header class="md-top" data-md-backdrop>
<div class="md-identity<?php echo $mdHasNick ? ' is-named' : ''; ?>">
<div class="md-title-row">
<h1 class="md-title">Visitor</h1>
<?php if ($mdView['statuses'] !== array()) : ?>
<p class="md-pills">
<?php foreach ($mdView['statuses'] as $mdStatus) : ?>
<span class="md-pill" data-tone="<?php echo mdmobile_esc($mdStatus['tone']); ?>"><span class="md-dot" aria-hidden="true"></span><?php echo mdmobile_esc($mdStatus['text']); ?></span>
<?php endforeach; ?>
</p>
<?php endif; ?>
</div>
<?php if ($mdHasNick) : ?>
<p class="md-name"><?php echo mdmobile_esc($mdNick); ?></p>
<?php endif; ?>
</div>
<?php if ($mdView['drawer']) : ?>
<button type="button" id="md-details" class="md-details" aria-expanded="false" aria-controls="md-drawer" aria-haspopup="dialog">Details</button>
<?php endif; ?>
</header>
<?php if ($mdHasGlance) : ?>
<div class="md-glance" data-md-backdrop>
<?php if ($mdView['chips'] !== array()) : ?>
<ul class="md-chips" aria-label="Visitor summary">
<?php foreach ($mdView['chips'] as $mdChip) : ?>
<li class="md-chip"><?php echo mdmobile_esc($mdChip); ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (is_array($mdView['source'])) : ?>
<p class="md-source">
<span class="md-source-label"><?php echo mdmobile_esc($mdView['source']['label']); ?></span>
<?php if ($mdView['source']['value'] !== $mdView['source']['href']) : ?>
<span class="md-source-value"><?php echo mdmobile_esc($mdView['source']['value']); ?></span>
<?php endif; ?>
<a class="md-source-link" href="<?php echo mdmobile_esc($mdView['source']['href']); ?>" target="_blank" rel="noopener noreferrer"><?php echo mdmobile_esc($mdView['source']['href']); ?><span class="md-sr"> (opens in a new window)</span></a>
</p>
<?php endif; ?>
</div>
<?php endif; ?>
<div id="md-messages" class="md-messages" data-md-backdrop role="log" aria-relevant="additions" aria-label="Messages">
<?php if ($mdRows === array()) : ?>
<p id="md-empty" class="md-empty">No messages yet</p>
<?php else : ?>
<?php foreach ($mdRows as $mdItem) : ?>
<div class="md-row md-row-<?php echo mdmobile_esc($mdItem['kind']); ?>" data-id="<?php echo mdmobile_esc($mdItem['id']); ?>" data-kind="<?php echo mdmobile_esc($mdItem['kind']); ?>">
<div class="md-bubble">
<?php if ($mdItem['kind'] === 'bot') : ?>
<span class="md-kicker"><?php echo mdmobile_esc($mdKindLabel['bot']); ?></span>
<?php else : ?>
<span class="md-sr"><?php echo mdmobile_esc($mdKindLabel[$mdItem['kind']]); ?></span>
<?php endif; ?>
<p class="md-text"><?php echo mdmobile_esc($mdItem['text']); ?></p>
<?php if ($mdItem['time'] !== '') : ?>
<?php echo mdmobile_time_html($mdItem['time'], $mdItem['timestamp'], 'time', 'md-time'); ?>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<div class="md-composer" data-md-backdrop>
<p id="md-status" class="md-status" role="status" aria-live="polite" aria-atomic="true"></p>
<div class="md-compose-row">
<label class="md-sr" for="md-draft">Type a message</label>
<textarea id="md-draft" class="md-draft" rows="1" placeholder="Type a message" autocomplete="off" autocapitalize="sentences" enterkeyhint="enter"></textarea>
<button type="button" id="md-send" class="md-send">Send</button>
</div>
</div>
<?php if ($mdView['drawer']) : ?>
<div id="md-sheet" class="md-sheet" hidden>
<div id="md-mask" class="md-mask" aria-hidden="true"></div>
<div id="md-drawer" class="md-drawer" role="dialog" aria-modal="true" aria-labelledby="md-drawer-title" tabindex="-1">
<div class="md-drawer-bar" aria-hidden="true"></div>
<div class="md-drawer-head">
<h2 id="md-drawer-title" class="md-drawer-title">Visitor details</h2>
<button type="button" id="md-drawer-close" class="md-drawer-close">Close</button>
</div>
<div class="md-drawer-body">
<?php if ($mdView['fields'] !== array()) : ?>
<dl class="md-fields">
<?php foreach ($mdView['fields'] as $mdField) : ?>
<div class="md-field">
<dt><?php echo mdmobile_esc($mdField['label']); ?></dt>
<dd<?php if (mdmobile_notes_label($mdField['label'])) : ?> class="md-notes"<?php endif; ?>><?php
$mdFieldStamp = mdmobile_clock_stamp($mdField['label'], $mdField['value'], isset($mdField['timestamp']) ? $mdField['timestamp'] : null);
if ($mdFieldStamp > 0) {
    echo mdmobile_time_html(mdmobile_utc_label($mdFieldStamp, 'datetime'), $mdFieldStamp, 'datetime');
} else {
    echo mdmobile_value_html($mdField['value'], $mdField['link']);
}
?></dd>
</div>
<?php endforeach; ?>
</dl>
<?php endif; ?>
<?php if ($mdView['history'] !== array()) : ?>
<section class="md-history" aria-labelledby="md-history-title">
<h3 id="md-history-title" class="md-history-title">Previous chats</h3>
<ol class="md-history-list">
<?php foreach ($mdView['history'] as $mdPast) : ?>
<li class="md-history-item"<?php if ($mdPast['id'] !== '') : ?> data-id="<?php echo mdmobile_esc($mdPast['id']); ?>"<?php endif; ?>>
<p class="md-history-preview"><?php echo mdmobile_esc($mdPast['preview']); ?></p>
<?php if ($mdPast['time'] !== '' || $mdPast['status'] !== '') : ?>
<p class="md-history-meta">
<?php if ($mdPast['time'] !== '') : ?>
<?php echo mdmobile_time_html($mdPast['time'], $mdPast['timestamp'], 'datetime'); ?>
<?php endif; ?>
<?php if ($mdPast['status'] !== '') : ?>
<span class="md-history-status"><?php echo mdmobile_esc($mdPast['status']); ?></span>
<?php endif; ?>
</p>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ol>
</section>
<?php endif; ?>
</div>
</div>
</div>
<?php endif; ?>
</div>
