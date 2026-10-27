<?php

require_once erLhcoreClassSystem::instance()->SiteDir . 'extension/mdmobile/modules/lhmdmobile/gate.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if (!erLhcoreClassMdMobile::readOnlyMethod($method)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo erLhcoreClassChat::safe_json_encode(array('error' => 'method'));
    exit;
}

$gate = mdmobile_list_gate();
if ($gate['denial'] !== null) {
    http_response_code(erLhcoreClassMdMobile::denialStatus($gate['denial']));
    echo erLhcoreClassChat::safe_json_encode(array('error' => $gate['denial']));
    exit;
}

$state = mdmobile_inbox_state(isset($Params) ? $Params : array());
$inbox = mdmobile_load_inbox($state, 0);

echo erLhcoreClassChat::safe_json_encode(erLhcoreClassMdMobile::inboxJson(
    $inbox['chats'],
    $inbox['offset'],
    $inbox['q'],
    $inbox['status'],
    isset($inbox['has_more']) && $inbox['has_more'] === true
));
exit;
