<?php

require_once erLhcoreClassSystem::instance()->SiteDir . 'extension/mdmobile/modules/lhmdmobile/gate.php';

$gate = mdmobile_list_gate();
$state = mdmobile_inbox_state(isset($Params) ? $Params : array());

if ($gate['denial'] === 'session') {
    $target = erLhcoreClassDesign::baseurldirect('site_admin/user/login');
    $target .= '/(r)/' . rawurlencode(base64_encode(
        erLhcoreClassMdMobile::inboxReturnPath($state['q'], $state['status'], $state['offset'])
    ));
    header('Location: ' . $target);
    exit;
}

if ($gate['denial'] !== null) {
    http_response_code(erLhcoreClassMdMobile::denialStatus($gate['denial']));
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Forbidden';
    exit;
}

$inbox = mdmobile_load_inbox($state, 0);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$Result['pagelayout'] = 'mdmobile';
$Result['mdmobile_inbox'] = $inbox;
$Result['title'] = 'Chats';
$Result['content'] = '';

$tplPath = erLhcoreClassDesign::designtpl('lhmdmobile/chats.tpl.php');
if (
    is_string($tplPath)
    && $tplPath !== ''
    && strpos($tplPath, 'missing_template.tpl.php') === false
    && is_file($tplPath)
) {
    $tpl = erLhcoreClassTemplate::getInstance('lhmdmobile/chats.tpl.php');
    foreach ($inbox as $name => $value) {
        $tpl->set($name, $value);
    }
    $tpl->set('inbox', $inbox);
    $Result['content'] = $tpl->fetch();
}
