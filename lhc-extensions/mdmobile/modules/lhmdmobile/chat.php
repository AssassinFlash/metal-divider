<?php

require_once erLhcoreClassSystem::instance()->SiteDir . 'extension/mdmobile/modules/lhmdmobile/gate.php';

/**
 * The page window is the latest 200 messages and format() drops a duplicate
 * system line, so the acceptance line may be outside it. Ask lh_msg directly
 * for the three native acceptance phrases. A bot-chat line is not evidence:
 * LHC writes it only when the status is already bot.
 */
function mdmobile_acceptance_line_exists($chatId)
{
    $chatId = (int) $chatId;
    if ($chatId < 1) {
        return false;
    }
    $db = ezcDbInstance::get();
    $stmt = $db->prepare(
        'SELECT 1 FROM lh_msg WHERE chat_id = :chat_id AND user_id = -1'
        . ' AND (msg LIKE :pending OR msg LIKE :transfer OR msg LIKE :opened) LIMIT 1'
    );
    $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_INT);
    $stmt->bindValue(':pending', '%has accepted the pending chat by sending a message!%');
    $stmt->bindValue(':transfer', '%has accepted a transferred chat!%');
    $stmt->bindValue(':opened', '%has accepted the chat!%');
    $stmt->execute();

    return $stmt->fetchColumn() !== false;
}

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
$canned = array();
if (class_exists('erLhcoreClassModelCannedMsg')) {
    $cannedItems = erLhcoreClassModelCannedMsg::getCannedMessages(
        (int) $chat->dep_id,
        (int) $gate['user']->getUserID()
    );
    if (is_array($cannedItems) && $cannedItems !== array()) {
        $cannedItems = erLhcoreClassModelCannedMsg::groupItems(
            $cannedItems,
            $chat,
            $gate['user']->getUserData(true)
        );
    }
    $canned = erLhcoreClassMdMobile::cannedGroups($cannedItems, $department);
}
// The transcript can already prove acceptance (a native acceptance system
// line) while a reset or test tool left the stored status at pending. An
// operator row alone is not proof. Derive the display status once, so the
// header, the details field and the rail cannot disagree with evidence.
// Ownership is not changed.
$acceptanceSeen = mdmobile_acceptance_line_exists((int) $chat->id);
$displayStatus = erLhcoreClassMdMobile::effectiveStatus($chat->status, $payload['messages'], $acceptanceSeen);
$bag = erLhcoreClassMdMobile::templateBag(
    (int) $chat->id,
    $payload,
    $urls,
    $gate['user']->getCSFRToken(),
    isset($chat->nick) ? $chat->nick : '',
    erLhcoreClassMdMobile::visitorContext($chat, $department, $history, $displayStatus),
    isset($chat->remarks) ? $chat->remarks : ''
);
$bag['canned'] = $canned;
$bag['status'] = $displayStatus;
$bag['closed'] = erLhcoreClassMdMobile::closedStatus($displayStatus);
$allowCloseRemote = $gate['user']->hasAccessTo('lhchat', 'allowcloseremote') === true;
$bag['allow_close_remote'] = $allowCloseRemote;
$bag['allow_open_closed'] = $gate['user']->hasAccessTo('lhchat', 'allowopenclosedchats') === true;
// Native saveremarks.php gates on hasAccessToRead + hasAccessToWrite and has no
// chat-status test, so closed-chat remarks are only valid with both rights.
$bag['can_write'] = erLhcoreClassChat::hasAccessToWrite($chat) === true;
// Native changestatus.php requires hasAccessToRead + hasAccessToWrite, and its
// route itself requires the lhchat/canchangechatstatus function
// (modules/lhchat/module.php). Write access does not imply that function, so an
// operator without it would see a Close that the route answers with an HTML
// permission page. The helper closes only for the chat owner or
// allowcloseremote and reports success even when the close branch was skipped.
$bag['can_close'] = $bag['closed'] === false
    && $bag['can_write'] === true
    && $gate['user']->hasAccessTo('lhchat', 'canchangechatstatus') === true
    && ($allowCloseRemote || ((int) $chat->user_id > 0 && (int) $chat->user_id === (int) $gate['user']->getUserID()));
$state = mdmobile_inbox_state(isset($Params) ? $Params : array());
$inbox = mdmobile_load_inbox($state, (int) $chat->id);
if ($displayStatus !== (int) $chat->status && isset($inbox['chats']) && is_array($inbox['chats'])) {
    foreach ($inbox['chats'] as $inboxIndex => $inboxItem) {
        if (is_array($inboxItem) && isset($inboxItem['id']) && (int) $inboxItem['id'] === (int) $chat->id) {
            $displayToken = erLhcoreClassMdMobile::statusToken($displayStatus);
            if ($displayToken !== null) {
                $inbox['chats'][$inboxIndex]['status'] = $displayToken;
            }
        }
    }
}
if (isset($inbox['list_url']) && $inbox['list_url'] !== '') {
    $bag['list_url'] = $inbox['list_url'];
}
$readUrl = erLhcoreClassMdMobile::deskUrl(mdmobile_admin_base(), 'mdmobile/read/' . (int) $chat->id);
if ($readUrl !== null) {
    $bag['read_url'] = $readUrl;
}
if (isset($bag['context']['history']) && is_array($bag['context']['history'])) {
    $bag['context']['history'] = erLhcoreClassMdMobile::withHistoryUrls(
        $bag['context']['history'],
        mdmobile_admin_base()
    );
}

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$Result['pagelayout'] = 'mdmobile';
$Result['mdmobile'] = $bag;
$Result['mdmobile_inbox'] = $inbox;
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
    $tpl->set('inbox', $inbox);
    $Result['content'] = $tpl->fetch();
}
