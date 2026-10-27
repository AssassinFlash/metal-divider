<?php

require_once dirname(__DIR__, 2) . '/lib/erlhcoreclassmdmobile.php';

/**
 * @return array{user: erLhcoreClassUser, chat: erLhcoreClassModelChat|null, denial: string|null}
 */
function mdmobile_gate($chatId)
{
    $currentUser = erLhcoreClassUser::instance();
    $loggedIn = $currentUser->isLogged();
    $chatUse = $loggedIn && $currentUser->hasAccessTo('lhchat', 'use');
    $chat = false;

    if ($chatUse && (int) $chatId > 0) {
        $chat = erLhcoreClassModelChat::fetch($chatId);
    }

    $found = $chat instanceof erLhcoreClassModelChat;

    return array(
        'user' => $currentUser,
        'chat' => $found ? $chat : null,
        'denial' => erLhcoreClassMdMobile::denial(
            $loggedIn,
            $chatUse,
            $found,
            $found && erLhcoreClassChat::hasAccessToRead($chat)
        ),
    );
}

/**
 * @return string
 */
function mdmobile_admin_base()
{
    $configured = 'site_admin';
    if (class_exists('erConfigClassLhConfig')) {
        $configured = erConfigClassLhConfig::getInstance()->getSetting('site', 'default_admin_site_access', 'site_admin');
    }

    return erLhcoreClassDesign::baseurldirect(erLhcoreClassMdMobile::adminSiteAccess($configured));
}
