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
            $historyUrl = mdmobile_app_url(mdmobile_field($row, 'url'));
            if ($historyUrl === '') {
                $historyUrl = mdmobile_app_url(mdmobile_field($row, 'href'));
            }
            if ($historyUrl !== '') {
                $past['url'] = $historyUrl;
            }
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

if (!function_exists('mdmobile_app_url')) {
    function mdmobile_app_url($value)
    {
        $http = mdmobile_http_url($value);
        if ($http !== '') {
            return $http;
        }
        $value = mdmobile_text($value);
        if ($value === '' || strlen($value) > 2000 || !isset($value[0]) || $value[0] !== '/') {
            return '';
        }
        if (isset($value[1]) && ($value[1] === '/' || $value[1] === '\\')) {
            return '';
        }
        if (preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $value) === 1) {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) === 1) {
            return '';
        }

        return $value;
    }
}

if (!function_exists('mdmobile_clip')) {
    function mdmobile_clip($text, $limit)
    {
        $text = mdmobile_text($text);
        $limit = (int) $limit;
        if ($text === '' || $limit < 1) {
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

if (!function_exists('mdmobile_one_line')) {
    function mdmobile_one_line($value, $limit)
    {
        $text = mdmobile_text($value);
        if ($text === '') {
            return '';
        }
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($collapsed)) {
            $collapsed = $text;
        }
        $collapsed = trim($collapsed);
        $clipped = mdmobile_clip($collapsed, $limit);
        if ($clipped !== '' && $clipped !== $collapsed) {
            return rtrim($clipped) . '…';
        }

        return $clipped;
    }
}

if (!function_exists('mdmobile_status_map')) {
    function mdmobile_status_map()
    {
        return array(
            'pending' => 'Pending',
            'active' => 'Active',
            'closed' => 'Closed',
            'chatbox' => 'Chatbox',
            'operators' => 'Operators',
            'bot' => 'Bot',
        );
    }
}

if (!function_exists('mdmobile_filter_status')) {
    function mdmobile_filter_status($value)
    {
        $text = strtolower(mdmobile_text($value));
        $text = preg_replace('/\s+/', '', is_string($text) ? $text : '');
        if (!is_string($text) || $text === '' || $text === 'all') {
            return '';
        }
        $map = mdmobile_status_map();
        if (isset($map[$text])) {
            return $text;
        }
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $text) === 1) {
            return $text;
        }

        return '';
    }
}

if (!function_exists('mdmobile_status_label')) {
    function mdmobile_status_label($value)
    {
        $codes = array(
            0 => 'pending',
            1 => 'active',
            2 => 'closed',
            3 => 'chatbox',
            4 => 'operators',
            5 => 'bot',
        );
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            $code = (int) $value;
            if (!isset($codes[$code])) {
                return '';
            }
            $value = $codes[$code];
        }
        $text = mdmobile_text($value);
        if ($text === '') {
            return '';
        }
        $key = strtolower($text);
        $map = mdmobile_status_map();
        if (isset($map[$key])) {
            return $map[$key];
        }

        return mdmobile_clip($text, 40);
    }
}

if (!function_exists('mdmobile_item_id')) {
    function mdmobile_item_id($value)
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            $parsed = (int) $value;
            if ((string) $parsed === $value) {
                return (string) $parsed;
            }
        }

        return '';
    }
}

if (!function_exists('mdmobile_first_field')) {
    function mdmobile_first_field($row, array $keys)
    {
        foreach ($keys as $key) {
            $value = mdmobile_field($row, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

if (!function_exists('mdmobile_unread')) {
    function mdmobile_unread($value)
    {
        if ($value === true) {
            return array('mode' => 'flag', 'count' => 1);
        }
        if ($value === false || $value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return array('mode' => 'count', 'count' => $value);
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return array('mode' => 'count', 'count' => (int) $value);
        }
        if (is_string($value) && strtolower(trim($value)) === 'true') {
            return array('mode' => 'flag', 'count' => 1);
        }

        return null;
    }
}

if (!function_exists('mdmobile_is_list')) {
    function mdmobile_is_list($value)
    {
        if (!is_array($value)) {
            return false;
        }
        if ($value === array()) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}

if (!function_exists('mdmobile_inbox_pick')) {
    function mdmobile_inbox_pick($raw, array $keys)
    {
        if (!is_array($raw)) {
            return null;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '') {
                return $raw[$key];
            }
        }

        return null;
    }
}

if (!function_exists('mdmobile_rewrite_url')) {
    function mdmobile_rewrite_url($url, $pattern, $replacement)
    {
        $url = mdmobile_text($url);
        if ($url === '') {
            return '';
        }
        $count = 0;
        $rewritten = preg_replace($pattern, $replacement, $url, 1, $count);
        if (!is_string($rewritten) || $count !== 1) {
            return '';
        }

        return mdmobile_app_url($rewritten);
    }
}

if (!function_exists('mdmobile_known_chats_url')) {
    function mdmobile_known_chats_url($explicit, $messagesUrl)
    {
        $explicit = mdmobile_app_url($explicit);
        if ($explicit !== '') {
            return $explicit;
        }

        return mdmobile_rewrite_url(
            $messagesUrl,
            '~\/mdmobile\/messages\/\d+\/?(\?[^#]*)?(#.*)?$~',
            '/mdmobile/chats$1$2'
        );
    }
}

if (!function_exists('mdmobile_thread_url')) {
    function mdmobile_thread_url($item, $messagesUrl, $chatsUrl, $query = null)
    {
        $direct = is_array($item) && !empty($item['url']) ? $item['url'] : '';
        if ($direct !== '') {
            return is_array($query) ? mdmobile_with_list_query($direct, isset($query['q']) ? $query['q'] : '', isset($query['status']) ? $query['status'] : '', isset($query['offset']) ? $query['offset'] : 0) : $direct;
        }
        $id = is_array($item) && isset($item['id']) ? $item['id'] : '';
        if (!preg_match('/^[1-9]\d*$/', (string) $id)) {
            return '';
        }
        $fromMessages = mdmobile_rewrite_url(
            $messagesUrl,
            '~\/mdmobile\/messages\/\d+\/?(\?[^#]*)?(#.*)?$~',
            '/mdmobile/chat/' . $id . '$1$2'
        );
        if ($fromMessages !== '') {
            if (!is_array($query)) {
                return $fromMessages;
            }

            return mdmobile_with_list_query($fromMessages, isset($query['q']) ? $query['q'] : '', isset($query['status']) ? $query['status'] : '', isset($query['offset']) ? $query['offset'] : 0);
        }

        $derived = mdmobile_rewrite_url(
            $chatsUrl,
            '~\/mdmobile\/chats\/?(\?[^#]*)?(#.*)?$~',
            '/mdmobile/chat/' . $id . '$1$2'
        );
        if ($derived === '' || !is_array($query)) {
            return $derived;
        }

        return mdmobile_with_list_query($derived, isset($query['q']) ? $query['q'] : '', isset($query['status']) ? $query['status'] : '', isset($query['offset']) ? $query['offset'] : 0);
    }
}

if (!function_exists('mdmobile_known_read_url')) {
    function mdmobile_known_read_url($candidates, $selectedId, array $items)
    {
        if (is_array($candidates)) {
            foreach ($candidates as $candidate) {
                $url = mdmobile_app_url($candidate);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        if ($selectedId === '') {
            return '';
        }
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'], $item['read_url'])) {
                continue;
            }
            if ($item['id'] === $selectedId && $item['read_url'] !== '') {
                return $item['read_url'];
            }
        }

        return '';
    }
}

if (!function_exists('mdmobile_inbox_item')) {
    function mdmobile_inbox_item($row)
    {
        if (!is_array($row) && !is_object($row)) {
            return null;
        }
        $id = mdmobile_item_id(mdmobile_field($row, 'id'));
        if ($id === '') {
            return null;
        }
        $nick = mdmobile_one_line(mdmobile_first_field($row, array('nick', 'name', 'title')), 120);
        $preview = mdmobile_one_line(mdmobile_first_field($row, array('preview', 'summary', 'last_message', 'excerpt')), 180);
        $status = mdmobile_status_label(mdmobile_first_field($row, array('status', 'status_label', 'state')));
        $url = mdmobile_app_url(mdmobile_first_field($row, array('url', 'href')));
        $read = mdmobile_app_url(mdmobile_first_field($row, array('read_url')));

        return array(
            'id' => $id,
            'nick' => $nick,
            'preview' => $preview,
            'time' => mdmobile_stamp(mdmobile_first_field($row, array('time', 'timestamp', 'last_time', 'updated'))),
            'status' => $status,
            'tone' => $status === '' ? '' : mdmobile_status_tone($status),
            'unread' => mdmobile_unread(mdmobile_first_field($row, array('unread', 'unread_count', 'has_unread', 'has_unread_messages'))),
            'url' => $url,
            'read_url' => $read,
        );
    }
}

if (!function_exists('mdmobile_status_options')) {
    function mdmobile_status_options($provided, $current)
    {
        $map = mdmobile_status_map();
        $options = array(array('value' => '', 'label' => 'All'));
        $seen = array('' => true);
        $rows = array();
        if (is_array($provided) && $provided !== array()) {
            foreach ($provided as $row) {
                if (is_string($row) || is_int($row)) {
                    $rows[] = array('value' => $row, 'label' => '');
                } elseif (is_array($row)) {
                    $rows[] = $row;
                }
            }
        } else {
            foreach ($map as $value => $label) {
                $rows[] = array('value' => $value, 'label' => $label);
            }
        }
        foreach ($rows as $row) {
            $value = mdmobile_filter_status(isset($row['value']) ? $row['value'] : '');
            $label = mdmobile_text(isset($row['label']) ? $row['label'] : '');
            if ($label === '' && $value !== '' && isset($map[$value])) {
                $label = $map[$value];
            }
            if ($label === '') {
                $label = $value === '' ? 'All' : $value;
            }
            $label = mdmobile_clip($label, 40);
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $options[] = array('value' => $value, 'label' => $label);
        }
        if ($current !== '' && !isset($seen[$current])) {
            $label = isset($map[$current]) ? $map[$current] : $current;
            $options[] = array('value' => $current, 'label' => $label);
        }

        return $options;
    }
}

if (!function_exists('mdmobile_nonneg_int')) {
    function mdmobile_nonneg_int($value)
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }
}

if (!function_exists('mdmobile_bool_flag')) {
    function mdmobile_bool_flag($value)
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return null;
    }
}

if (!function_exists('mdmobile_url_base')) {
    function mdmobile_url_base($url)
    {
        $url = mdmobile_app_url($url);
        if ($url === '') {
            return '';
        }
        $cut = strcspn($url, '?#');

        return substr($url, 0, $cut);
    }
}

if (!function_exists('mdmobile_page_url')) {
    function mdmobile_page_url($base, $q, $status, $offset)
    {
        $base = mdmobile_url_base($base);
        if ($base === '') {
            return '';
        }
        $parts = array();
        $q = mdmobile_text($q);
        $status = mdmobile_filter_status($status);
        if ($q !== '') {
            $parts[] = 'q=' . rawurlencode($q);
        }
        if ($status !== '') {
            $parts[] = 'status=' . rawurlencode($status);
        }
        $offset = mdmobile_nonneg_int($offset);
        if ($offset > 0) {
            $parts[] = 'offset=' . $offset;
        }
        if ($parts === array()) {
            return $base;
        }

        return $base . '?' . implode('&', $parts);
    }
}

if (!function_exists('mdmobile_query_names')) {
    function mdmobile_query_names($url)
    {
        $names = array();
        $query = '';
        $pos = strpos($url, '?');
        if ($pos !== false) {
            $query = substr($url, $pos + 1);
            $hash = strpos($query, '#');
            if ($hash !== false) {
                $query = substr($query, 0, $hash);
            }
        }
        if ($query === '') {
            return $names;
        }
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            $name = $eq === false ? $part : substr($part, 0, $eq);
            $name = rawurldecode($name);
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return $names;
    }
}

if (!function_exists('mdmobile_with_list_query')) {
    function mdmobile_with_list_query($url, $q, $status, $offset)
    {
        $url = mdmobile_app_url($url);
        if ($url === '') {
            return '';
        }
        $hash = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $hash = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }
        $names = mdmobile_query_names($url);
        $extra = array();
        if (!isset($names['q']) && mdmobile_text($q) !== '') {
            $extra[] = 'q=' . rawurlencode(mdmobile_text($q));
        }
        if (!isset($names['status']) && mdmobile_filter_status($status) !== '') {
            $extra[] = 'status=' . rawurlencode(mdmobile_filter_status($status));
        }
        $offset = mdmobile_nonneg_int($offset);
        if (!isset($names['offset']) && $offset > 0) {
            $extra[] = 'offset=' . $offset;
        }
        if ($extra === array()) {
            return $url . $hash;
        }
        $url .= (strpos($url, '?') === false ? '?' : '&') . implode('&', $extra);

        return $url . $hash;
    }
}

if (!function_exists('mdmobile_inbox_normalize')) {
    function mdmobile_inbox_normalize($raw)
    {
        if (!is_array($raw)) {
            $raw = array();
        }
        $source = array();
        if (isset($raw['chats']) && is_array($raw['chats'])) {
            $source = $raw['chats'];
        } elseif (isset($raw['items']) && is_array($raw['items'])) {
            $source = $raw['items'];
        } elseif (isset($raw['list']) && is_array($raw['list'])) {
            $source = $raw['list'];
        } elseif (mdmobile_is_list($raw)) {
            $source = $raw;
        }
        $items = array();
        $seen = array();
        foreach ($source as $row) {
            $item = mdmobile_inbox_item($row);
            if ($item === null || isset($seen[$item['id']])) {
                continue;
            }
            $seen[$item['id']] = true;
            $items[] = $item;
        }
        $q = mdmobile_one_line(mdmobile_inbox_pick($raw, array('q', 'query', 'search')), 200);
        $status = mdmobile_filter_status(mdmobile_inbox_pick($raw, array('status', 'filter')));
        $listUrl = mdmobile_app_url(mdmobile_inbox_pick($raw, array('list_url')));
        $endpoint = mdmobile_app_url(mdmobile_inbox_pick($raw, array('list_endpoint')));
        $pageBase = mdmobile_url_base($listUrl);
        $chatsUrl = $pageBase !== '' ? $pageBase : mdmobile_app_url(mdmobile_inbox_pick($raw, array('chats_url', 'index_url', 'list_page')));
        $limit = mdmobile_nonneg_int(mdmobile_inbox_pick($raw, array('limit')));
        if ($limit < 1) {
            $limit = 20;
        }

        return array(
            'items' => $items,
            'q' => $q,
            'status' => $status,
            'action' => mdmobile_app_url(mdmobile_inbox_pick($raw, array('action', 'form_action'))),
            'list_url' => $listUrl,
            'list_endpoint' => $endpoint,
            'page_base' => $pageBase !== '' ? $pageBase : mdmobile_url_base($chatsUrl),
            'chats_url' => $chatsUrl,
            'read_url' => mdmobile_app_url(mdmobile_inbox_pick($raw, array('read_url'))),
            'current_id' => mdmobile_item_id(mdmobile_inbox_pick($raw, array('current_id'))),
            'offset' => mdmobile_nonneg_int(mdmobile_inbox_pick($raw, array('offset'))),
            'limit' => $limit,
            'has_more' => is_array($raw) && array_key_exists('has_more', $raw) ? mdmobile_bool_flag($raw['has_more']) : null,
            'statuses' => mdmobile_status_options(isset($raw['statuses']) ? $raw['statuses'] : null, $status),
        );
    }
}

if (!function_exists('mdmobile_history_external')) {
    function mdmobile_history_external($href)
    {
        if ($href === '' || $href[0] === '/' || strpos($href, '/mdmobile/chat/') !== false) {
            return false;
        }

        return mdmobile_http_url($href) !== '';
    }
}

if (!function_exists('mdmobile_history_preview_html')) {
    function mdmobile_history_preview_html($past)
    {
        $preview = is_array($past) && isset($past['preview']) ? $past['preview'] : '';
        $url = is_array($past) && isset($past['url']) ? $past['url'] : '';
        $safe = mdmobile_app_url($url);
        if ($safe === '') {
            return mdmobile_esc($preview);
        }
        $html = '<a class="md-history-link" href="' . mdmobile_esc($safe) . '"';
        if (mdmobile_history_external($safe)) {
            $html .= ' target="_blank" rel="noopener noreferrer"';
        }
        $html .= '>' . mdmobile_esc($preview);
        if (mdmobile_history_external($safe)) {
            $html .= '<span class="md-sr"> (opens in a new window)</span>';
        }
        $html .= '</a>';

        return $html;
    }
}

if (!function_exists('mdmobile_render_fields')) {
    function mdmobile_render_fields($fields)
    {
        if (!is_array($fields) || $fields === array()) {
            return '';
        }
        $html = '<dl class="md-fields">';
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['label'], $field['value'])) {
                continue;
            }
            $html .= '<div class="md-field"><dt>' . mdmobile_esc($field['label']) . '</dt><dd>';
            $stamp = mdmobile_clock_stamp($field['label'], $field['value'], isset($field['timestamp']) ? $field['timestamp'] : null);
            if ($stamp > 0) {
                $html .= mdmobile_time_html(mdmobile_utc_label($stamp, 'datetime'), $stamp, 'datetime');
            } else {
                $html .= mdmobile_value_html($field['value'], isset($field['link']) ? $field['link'] : null);
            }
            $html .= '</dd></div>';
        }
        $html .= '</dl>';

        return $html;
    }
}

if (!function_exists('mdmobile_render_history')) {
    function mdmobile_render_history($history, $titleId)
    {
        if (!is_array($history) || $history === array()) {
            return '';
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', (string) $titleId) !== 1) {
            $titleId = 'md-history-title';
        }
        $html = '<section class="md-history" aria-labelledby="' . $titleId . '">';
        $html .= '<h3 id="' . $titleId . '" class="md-history-title">Previous chats</h3><ol class="md-history-list">';
        foreach ($history as $past) {
            if (!is_array($past)) {
                continue;
            }
            $html .= '<li class="md-history-item"';
            if (!empty($past['id'])) {
                $html .= ' data-id="' . mdmobile_esc($past['id']) . '"';
            }
            $html .= '><p class="md-history-preview">' . mdmobile_history_preview_html($past) . '</p>';
            $time = isset($past['time']) ? $past['time'] : '';
            $status = isset($past['status']) ? $past['status'] : '';
            if ($time !== '' || $status !== '') {
                $html .= '<p class="md-history-meta">';
                if ($time !== '') {
                    $html .= mdmobile_time_html($time, isset($past['timestamp']) ? $past['timestamp'] : 0, 'datetime');
                }
                if ($status !== '') {
                    $html .= '<span class="md-history-status">' . mdmobile_esc($status) . '</span>';
                }
                $html .= '</p>';
            }
            $html .= '</li>';
        }
        $html .= '</ol></section>';

        return $html;
    }
}

if (!function_exists('mdmobile_empty_copy')) {
    function mdmobile_empty_copy(array $inbox)
    {
        if ((!empty($inbox['q'])) || (!empty($inbox['status']))) {
            return 'No matching conversations';
        }

        return 'No conversations';
    }
}

if (!function_exists('mdmobile_unread_html')) {
    function mdmobile_unread_html($unread)
    {
        if (!is_array($unread) || empty($unread['mode'])) {
            return '';
        }
        if ($unread['mode'] === 'count') {
            $count = isset($unread['count']) ? (int) $unread['count'] : 0;
            if ($count < 1) {
                return '';
            }
            $shown = $count > 99 ? '99+' : (string) $count;
            $label = $count === 1 ? '1 unread' : $count . ' unread';

            return '<span class="md-unread" data-unread="' . mdmobile_esc((string) $count) . '" aria-label="' . mdmobile_esc($label) . '">' . mdmobile_esc($shown) . '</span>';
        }

        return '<span class="md-unread is-flag" data-unread="1" aria-label="Unread"><span class="md-dot" aria-hidden="true"></span><span class="md-sr">Unread</span></span>';
    }
}

if (!function_exists('mdmobile_thread_time_html')) {
    function mdmobile_thread_time_html($stamp)
    {
        $stamp = mdmobile_stamp($stamp);
        if ($stamp < 1) {
            return '<span class="md-thread-time"></span>';
        }

        return '<time class="md-thread-time" data-timestamp="' . mdmobile_esc((string) $stamp) . '" data-time-style="desk" datetime="' . mdmobile_esc(gmdate('c', $stamp)) . '">' . mdmobile_esc(gmdate('m-d H:i', $stamp)) . '</time>';
    }
}

if (!function_exists('mdmobile_render_thread')) {
    function mdmobile_render_thread($item, $selectedId, $messagesUrl, $chatsUrl, $query = null)
    {
        if (!is_array($item) || empty($item['id'])) {
            return '';
        }
        $url = mdmobile_thread_url($item, $messagesUrl, $chatsUrl, $query);
        $tag = $url !== '' ? 'a' : 'div';
        $name = $item['nick'] !== '' && strcasecmp($item['nick'], 'Visitor') !== 0 ? $item['nick'] : 'Visitor #' . $item['id'];
        $current = $selectedId !== '' && $selectedId === $item['id'];
        $html = '<li class="md-thread"><' . $tag . ' class="md-thread-link';
        if (!empty($item['unread'])) {
            $html .= ' is-unread';
        }
        $html .= '" data-id="' . mdmobile_esc($item['id']) . '"';
        if ($url !== '') {
            $html .= ' href="' . mdmobile_esc($url) . '"';
        }
        if ($current) {
            $html .= ' aria-current="page"';
        }
        $html .= '><span class="md-thread-name">' . mdmobile_esc($name) . '</span>';
        $html .= mdmobile_thread_time_html(isset($item['time']) ? $item['time'] : 0);
        $html .= '<span class="md-thread-preview">' . mdmobile_esc(isset($item['preview']) ? $item['preview'] : '') . '</span>';
        $html .= '<span class="md-thread-meta">';
        if (!empty($item['status'])) {
            $html .= '<span class="md-thread-status" data-tone="' . mdmobile_esc($item['tone']) . '">' . mdmobile_esc($item['status']) . '</span>';
        }
        $html .= mdmobile_unread_html(isset($item['unread']) ? $item['unread'] : null);
        $html .= '</span></' . $tag . '></li>';

        return $html;
    }
}

if (!function_exists('mdmobile_render_filters')) {
    function mdmobile_render_filters(array $inbox, $fallbackAction = '')
    {
        $action = !empty($inbox['action']) ? $inbox['action'] : mdmobile_app_url($fallbackAction);
        $html = '<form id="md-filters" class="md-filters" method="get"';
        if ($action !== '') {
            $html .= ' action="' . mdmobile_esc($action) . '"';
        }
        $html .= '>';
        $html .= '<label class="md-sr" for="md-q">Search conversations</label>';
        $html .= '<input id="md-q" class="md-q" type="search" name="q" value="' . mdmobile_esc($inbox['q']) . '" placeholder="Search" autocomplete="off" enterkeyhint="search">';
        $html .= '<label class="md-sr" for="md-filter-status">Status</label>';
        $html .= '<select id="md-filter-status" class="md-filter-status" name="status">';
        foreach ($inbox['statuses'] as $option) {
            $html .= '<option value="' . mdmobile_esc($option['value']) . '"';
            if ($option['value'] === $inbox['status']) {
                $html .= ' selected';
            }
            $html .= '>' . mdmobile_esc($option['label']) . '</option>';
        }
        $html .= '</select>';
        $html .= '<input type="hidden" name="offset" value="0">';
        $html .= '<button type="submit" class="md-filter-submit">Search</button></form>';

        return $html;
    }
}

if (!function_exists('mdmobile_render_pager')) {
    function mdmobile_render_pager(array $inbox, $pageBase)
    {
        $pageBase = mdmobile_url_base($pageBase);
        if ($pageBase === '') {
            return '';
        }
        $offset = isset($inbox['offset']) ? mdmobile_nonneg_int($inbox['offset']) : 0;
        $limit = isset($inbox['limit']) ? mdmobile_nonneg_int($inbox['limit']) : 20;
        if ($limit < 1) {
            $limit = 20;
        }
        $count = isset($inbox['items']) && is_array($inbox['items']) ? count($inbox['items']) : 0;
        $q = isset($inbox['q']) ? $inbox['q'] : '';
        $status = isset($inbox['status']) ? $inbox['status'] : '';
        $newerOffset = $offset > $limit ? $offset - $limit : 0;
        $olderOffset = $offset + $limit;
        $newerHref = mdmobile_page_url($pageBase, $q, $status, $newerOffset);
        $olderHref = mdmobile_page_url($pageBase, $q, $status, $olderOffset);
        $html = '<nav id="md-pager" class="md-pager" aria-label="Earlier conversations">';
        $html .= '<a id="md-newer" class="md-page" href="' . mdmobile_esc($newerHref) . '" data-offset="' . mdmobile_esc((string) $newerOffset) . '"';
        if ($offset < 1) {
            $html .= ' hidden';
        }
        $html .= '>Newer<span class="md-sr"> conversations</span></a>';
        $html .= '<a id="md-older" class="md-page" href="' . mdmobile_esc($olderHref) . '" data-offset="' . mdmobile_esc((string) $olderOffset) . '"';
        $showOlder = array_key_exists('has_more', $inbox) && $inbox['has_more'] !== null ? $inbox['has_more'] === true : $count >= $limit;
        if (!$showOlder) {
            $html .= ' hidden';
        }
        $html .= '>Older<span class="md-sr"> conversations</span></a></nav>';

        return $html;
    }
}

if (!function_exists('mdmobile_render_rail')) {
    function mdmobile_render_rail(array $inbox, $selectedId, $page, $messagesUrl)
    {
        $heading = $page === 'chats' ? 'h1' : 'h2';
        if ($selectedId === '' && !empty($inbox['current_id'])) {
            $selectedId = $inbox['current_id'];
        }
        $pageBase = isset($inbox['page_base']) ? $inbox['page_base'] : '';
        $chatsUrl = $pageBase !== '' ? $pageBase : mdmobile_known_chats_url(isset($inbox['chats_url']) ? $inbox['chats_url'] : '', $messagesUrl);
        if ($pageBase === '') {
            $pageBase = mdmobile_url_base($chatsUrl);
        }
        $offset = isset($inbox['offset']) ? mdmobile_nonneg_int($inbox['offset']) : 0;
        $limit = isset($inbox['limit']) && mdmobile_nonneg_int($inbox['limit']) > 0 ? mdmobile_nonneg_int($inbox['limit']) : 20;
        $html = '<nav id="md-inbox" class="md-rail" aria-label="Conversations" data-selected="' . mdmobile_esc($selectedId) . '" data-offset="' . mdmobile_esc((string) $offset) . '" data-limit="' . mdmobile_esc((string) $limit) . '"';
        if (!empty($inbox['list_endpoint'])) {
            $html .= ' data-list-endpoint="' . mdmobile_esc($inbox['list_endpoint']) . '"';
        }
        if ($pageBase !== '') {
            $html .= ' data-page-base="' . mdmobile_esc($pageBase) . '"';
        }
        if (array_key_exists('has_more', $inbox) && $inbox['has_more'] !== null) {
            $html .= ' data-has-more="' . ($inbox['has_more'] ? '1' : '0') . '"';
        }
        $html .= '><' . $heading . ' class="md-rail-title" id="md-inbox-title">Conversations</' . $heading . '>';
        $html .= mdmobile_render_filters($inbox, $pageBase);
        $html .= '<p id="md-inbox-status" class="md-inbox-status" role="status" aria-live="polite" aria-atomic="true"></p>';
        $html .= '<p id="md-inbox-empty" class="md-inbox-empty"';
        if ($inbox['items'] !== array()) {
            $html .= ' hidden';
        }
        $html .= '>' . mdmobile_esc(mdmobile_empty_copy($inbox)) . '</p>';
        $html .= '<ul id="md-inbox-list" class="md-inbox-list">';
        $threadQuery = array(
            'q' => isset($inbox['q']) ? $inbox['q'] : '',
            'status' => isset($inbox['status']) ? $inbox['status'] : '',
            'offset' => $offset,
        );
        foreach ($inbox['items'] as $item) {
            $html .= mdmobile_render_thread($item, $selectedId, $messagesUrl, $chatsUrl, $threadQuery);
        }
        $html .= '</ul>';
        $html .= mdmobile_render_pager($inbox, $pageBase);
        $html .= '</nav>';

        return $html;
    }
}

if (!function_exists('mdmobile_render_remarks')) {
    function mdmobile_render_remarks($remarks, $suffix = 'md-drawer')
    {
        $value = '';
        $url = '';
        if (is_array($remarks)) {
            $value = isset($remarks['value']) ? (string) $remarks['value'] : '';
            $url = mdmobile_app_url(isset($remarks['url']) ? $remarks['url'] : '');
        }
        if ($url === '') {
            return '';
        }
        $suffix = (string) $suffix;
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $suffix) !== 1) {
            $suffix = 'md-drawer';
        }
        $inputId = 'md-remarks-' . $suffix;
        $titleId = 'md-remarks-title-' . $suffix;
        $statusId = 'md-remarks-status-' . $suffix;
        $html = '<section class="md-remarks" aria-labelledby="' . mdmobile_esc($titleId) . '" data-md-remarks data-save-url="' . mdmobile_esc($url) . '">';
        $html .= '<h3 id="' . mdmobile_esc($titleId) . '" class="md-remarks-title">Remarks</h3>';
        $html .= '<label class="md-sr" for="' . mdmobile_esc($inputId) . '">Private remarks</label>';
        $html .= '<textarea id="' . mdmobile_esc($inputId) . '" class="md-remarks-input" data-md-remarks-input rows="3" placeholder="Private remarks. Visitors never see these.">' . mdmobile_esc($value) . '</textarea>';
        $html .= '<p id="' . mdmobile_esc($statusId) . '" class="md-remarks-status" data-md-remarks-status role="status" aria-live="polite" aria-atomic="true"></p>';
        $html .= '</section>';

        return $html;
    }
}

if (!function_exists('mdmobile_render_canned')) {
    function mdmobile_render_canned($groups)
    {
        if (!is_array($groups) || $groups === array()) {
            return '';
        }
        $sections = '';
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $label = mdmobile_text(isset($group['label']) ? $group['label'] : '');
            $items = isset($group['items']) && is_array($group['items']) ? $group['items'] : array();
            $rows = '';
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = isset($item['id']) ? (int) $item['id'] : 0;
                $text = isset($item['text']) ? (string) $item['text'] : '';
                if ($id < 1 || trim($text) === '') {
                    continue;
                }
                $title = mdmobile_text(isset($item['title']) ? $item['title'] : '');
                $rows .= '<li><button type="button" class="md-canned-item" data-md-canned-id="' . mdmobile_esc((string) $id) . '">';
                if ($title !== '') {
                    $rows .= '<span class="md-canned-title">' . mdmobile_esc($title) . '</span>';
                }
                $rows .= '<span class="md-canned-text">' . mdmobile_esc($text) . '</span></button></li>';
            }
            if ($rows === '') {
                continue;
            }
            $sections .= '<section class="md-canned-group">';
            if ($label !== '') {
                $sections .= '<h3 class="md-canned-group-title">' . mdmobile_esc($label) . '</h3>';
            }
            $sections .= '<ul class="md-canned-list">' . $rows . '</ul></section>';
        }
        if ($sections === '') {
            return '';
        }

        return '<div id="md-canned-sheet" class="md-sheet md-canned-sheet" hidden>'
            . '<div id="md-canned-mask" class="md-mask" aria-hidden="true"></div>'
            . '<div id="md-canned-dialog" class="md-drawer md-canned-dialog" role="dialog" aria-modal="true" aria-labelledby="md-canned-title" tabindex="-1">'
            . '<div class="md-drawer-bar" aria-hidden="true"></div>'
            . '<div class="md-drawer-head"><h2 id="md-canned-title" class="md-drawer-title">Quick replies</h2>'
            . '<button type="button" id="md-canned-close" class="md-drawer-close">Close</button></div>'
            . '<div class="md-canned-body"><p class="md-canned-hint">Inserts into the message box. Edit before sending.</p>'
            . $sections
            . '</div></div></div>';
    }
}

if (!function_exists('mdmobile_render_context')) {
    function mdmobile_render_context($view, $hasChat, $remarks = null)
    {
        if (!is_array($view)) {
            $view = array('fields' => array(), 'history' => array());
        }
        $fields = isset($view['fields']) && is_array($view['fields']) ? $view['fields'] : array();
        $history = isset($view['history']) && is_array($view['history']) ? $view['history'] : array();
        $remarksHtml = $hasChat === true ? mdmobile_render_remarks($remarks, 'md-context') : '';
        $html = '<aside id="md-context" class="md-context" aria-labelledby="md-context-title">';
        $html .= '<h2 id="md-context-title" class="md-context-title">Visitor details</h2><div class="md-context-body">';
        if ($hasChat !== true) {
            $html .= '<p class="md-context-empty">Select a conversation to see visitor details.</p>';
        } elseif ($fields === array() && $history === array() && $remarksHtml === '') {
            $html .= '<p class="md-context-empty">No visitor details</p>';
        } else {
            $html .= mdmobile_render_fields($fields);
            $html .= $remarksHtml;
            $html .= mdmobile_render_history($history, 'md-context-history-title');
        }
        $html .= '</div></aside>';

        return $html;
    }
}

if (!function_exists('mdmobile_desk_open')) {
    function mdmobile_desk_open($page, $selectedId, $listUrl, $readUrl)
    {
        $page = $page === 'chats' ? 'chats' : 'chat';
        $html = '<div id="md-desk" class="md-desk" data-page="' . $page . '" data-selected="' . mdmobile_esc($selectedId) . '"';
        if ($page === 'chat' && $readUrl !== '') {
            $html .= ' data-read-url="' . mdmobile_esc($readUrl) . '"';
        }
        $html .= '>';

        return $html;
    }
}

if (!function_exists('mdmobile_stage_empty')) {
    function mdmobile_stage_empty()
    {
        return '<div id="md-stage-empty" class="md-stage-empty"><p class="md-stage-title">Select a conversation</p><p class="md-stage-copy">Choose a chat from the list to read it and reply.</p></div>';
    }
}

if (!function_exists('mdmobile_back_link')) {
    function mdmobile_back_link($href)
    {
        $href = mdmobile_app_url($href);
        if ($href === '') {
            return '';
        }

        return '<a id="md-back" class="md-back" href="' . mdmobile_esc($href) . '" aria-label="Back to conversations">‹ Chats</a>';
    }
}

if (!function_exists('mdmobile_render_chats_page')) {
    function mdmobile_render_chats_page(array $inbox, $messagesUrl = '')
    {
        $html = mdmobile_desk_open('chats', '', isset($inbox['list_url']) ? $inbox['list_url'] : '', '');
        $html .= mdmobile_render_rail($inbox, '', 'chats', $messagesUrl);
        $html .= '<div class="md-stage">' . mdmobile_stage_empty() . '</div>';
        $html .= mdmobile_render_context(null, false);
        $html .= '</div>';

        return $html;
    }
}
