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

$chatId = erLhcoreClassMdMobile::chatId(
    isset($Params['user_parameters']['chat_id']) ? $Params['user_parameters']['chat_id'] : null
);
$gate = mdmobile_gate($chatId);

if ($gate['denial'] !== null) {
    http_response_code(erLhcoreClassMdMobile::denialStatus($gate['denial']));
    echo erLhcoreClassChat::safe_json_encode(array('error' => $gate['denial']));
    exit;
}

$afterId = 0;
if (isset($_GET['after_id'])) {
    $afterId = erLhcoreClassMdMobile::afterId($_GET['after_id']);
} elseif (isset($Params['user_parameters_unordered']['after_id'])) {
    $afterId = erLhcoreClassMdMobile::afterId($Params['user_parameters_unordered']['after_id']);
}

$chat = $gate['chat'];
$rows = erLhcoreClassMdMobile::rowsFromMessages(
    erLhcoreClassModelmsg::getList(erLhcoreClassMdMobile::listQuery($chat->id, $afterId, false))
);

echo erLhcoreClassChat::safe_json_encode(erLhcoreClassMdMobile::format($rows, $afterId));
exit;
