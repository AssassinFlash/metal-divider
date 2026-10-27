<?php

require_once erLhcoreClassSystem::instance()->SiteDir . 'extension/mdmobile/modules/lhmdmobile/gate.php';

$chatId = erLhcoreClassMdMobile::chatId(
    isset($Params['user_parameters']['chat_id']) ? $Params['user_parameters']['chat_id'] : null
);
$gate = mdmobile_gate($chatId);

if ($gate['denial'] === 'session') {
    $target = erLhcoreClassDesign::baseurldirect('site_admin/user/login');
    if ($chatId > 0) {
        $target .= '/(r)/' . rawurlencode(base64_encode('mdmobile/chat/' . $chatId));
    }
    header('Location: ' . $target);
    exit;
}

if ($gate['denial'] !== null) {
    http_response_code(erLhcoreClassMdMobile::denialStatus($gate['denial']));
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $gate['denial'] === 'missing' ? 'Not found' : 'Forbidden';
    exit;
}

$chat = $gate['chat'];
$rows = erLhcoreClassMdMobile::rowsFromMessages(
    erLhcoreClassModelmsg::getList(erLhcoreClassMdMobile::listQuery($chat->id, 0, true))
);
$payload = erLhcoreClassMdMobile::format(array_reverse($rows), 0);
$urls = erLhcoreClassMdMobile::endpointUrls(mdmobile_admin_base(), (int) $chat->id);
$department = '';
$departmentId = erLhcoreClassMdMobile::departmentId($chat);
if ($departmentId > 0) {
    $department = erLhcoreClassMdMobile::departmentName(
        erLhcoreClassModelDepartament::fetch($departmentId)
    );
}
$history = array();
$historyQuery = erLhcoreClassMdMobile::historyQuery($chat);
if ($historyQuery !== null) {
    $others = erLhcoreClassModelChat::getList($historyQuery);
    $history = erLhcoreClassMdMobile::visibleHistory(
        is_array($others) ? $others : array(),
        $chat->id,
        static function ($other) {
            return is_object($other) && erLhcoreClassChat::hasAccessToRead($other) === true;
        },
        static function ($other) {
            $messageQuery = erLhcoreClassMdMobile::firstMessageQuery(
                is_object($other) && isset($other->id) ? $other->id : 0
            );
            if ($messageQuery === null) {
                return null;
            }
            $messages = erLhcoreClassModelmsg::getList($messageQuery);
            if (!is_array($messages)) {
                return null;
            }

            return erLhcoreClassMdMobile::previewFromMessages(
                erLhcoreClassMdMobile::rowsFromMessages($messages)
            );
        }
    );
}
$bag = erLhcoreClassMdMobile::templateBag(
    (int) $chat->id,
    $payload,
    $urls,
    $gate['user']->getCSFRToken(),
    isset($chat->nick) ? $chat->nick : '',
    erLhcoreClassMdMobile::visitorContext($chat, $department, $history)
);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$Result['pagelayout'] = 'mdmobile';
$Result['mdmobile'] = $bag;
$Result['content'] = '';
if (isset($bag['title'])) {
    $Result['title'] = $bag['title'];
}

$tplPath = erLhcoreClassDesign::designtpl('lhmdmobile/chat.tpl.php');
if (
    is_string($tplPath)
    && $tplPath !== ''
    && strpos($tplPath, 'missing_template.tpl.php') === false
    && is_file($tplPath)
) {
    $tpl = erLhcoreClassTemplate::getInstance('lhmdmobile/chat.tpl.php');
    foreach ($bag as $name => $value) {
        $tpl->set($name, $value);
    }
    $Result['content'] = $tpl->fetch();
}
