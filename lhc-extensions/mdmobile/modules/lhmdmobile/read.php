<?php

require_once erLhcoreClassSystem::instance()->SiteDir . 'extension/mdmobile/modules/lhmdmobile/gate.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if (erLhcoreClassMdMobile::readDenial($method, true, true, true, true, true) === 'method') {
    http_response_code(erLhcoreClassMdMobile::denialStatus('method'));
    header('Allow: POST');
    echo erLhcoreClassChat::safe_json_encode(array('error' => 'method'));
    exit;
}

$chatId = erLhcoreClassMdMobile::chatId(
    isset($Params['user_parameters']['chat_id']) ? $Params['user_parameters']['chat_id'] : null
);
$gate = mdmobile_gate($chatId);
$candidate = erLhcoreClassMdMobile::csrfCandidate(
    isset($_SERVER['HTTP_X_CSRFTOKEN']) ? $_SERVER['HTTP_X_CSRFTOKEN'] : null,
    isset($_POST['csfr_token']) ? $_POST['csfr_token'] : null
);
$csrfOk = false;
if ($gate['logged_in'] === true && $candidate !== null) {
    $csrfOk = $gate['user']->validateCSFRToken($candidate) === true;
}
$denial = erLhcoreClassMdMobile::readDenial(
    $method,
    $gate['logged_in'] === true,
    $gate['chat_use'] === true,
    $gate['chat'] !== null,
    $gate['denial'] === null,
    $csrfOk
);

if ($denial !== null) {
    http_response_code(erLhcoreClassMdMobile::denialStatus($denial));
    $body = array('error' => $denial);
    if ($denial === 'csrf' && $gate['logged_in'] === true) {
        $body['token'] = $gate['user']->getCSFRToken();
    }
    echo erLhcoreClassChat::safe_json_encode($body);
    exit;
}

$result = mdmobile_mark_read($gate['chat'], $gate['user']);
if (isset($result['denial'])) {
    http_response_code(erLhcoreClassMdMobile::denialStatus($result['denial']));
    echo erLhcoreClassChat::safe_json_encode(array('error' => $result['denial']));
    exit;
}
if (isset($result['error'])) {
    http_response_code(500);
    echo erLhcoreClassChat::safe_json_encode(array('error' => 'read'));
    exit;
}

echo erLhcoreClassChat::safe_json_encode(erLhcoreClassMdMobile::readResult(
    (int) $gate['chat']->id,
    $result['unread'] === true,
    $result['applied'] === true
));
exit;
