<?php
require_once erLhcoreClassDesign::designtpl('lhmdmobile/helpers.php');

if ((!isset($Result) || !is_array($Result)) && isset($GLOBALS['Result']) && is_array($GLOBALS['Result'])) {
    $Result = $GLOBALS['Result'];
}

$mdResult = (isset($Result) && is_array($Result)) ? $Result : array();
$mdInboxRaw = null;
if (isset($mdmobile_inbox) && is_array($mdmobile_inbox)) {
    $mdInboxRaw = $mdmobile_inbox;
} elseif (isset($inbox) && is_array($inbox)) {
    $mdInboxRaw = $inbox;
} elseif (isset($mdResult['mdmobile_inbox']) && is_array($mdResult['mdmobile_inbox'])) {
    $mdInboxRaw = $mdResult['mdmobile_inbox'];
}
if (!is_array($mdInboxRaw)) {
    $mdInboxRaw = array();
}

echo mdmobile_render_chats_page(mdmobile_inbox_normalize($mdInboxRaw));
