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
        'logged_in' => $loggedIn,
        'chat_use' => $chatUse,
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

/**
 * The inbox has no chat id. Missing department access is an empty list.
 *
 * @return array{user: erLhcoreClassUser, denial: string|null}
 */
function mdmobile_list_gate()
{
    $currentUser = erLhcoreClassUser::instance();
    $loggedIn = $currentUser->isLogged();
    $chatUse = $loggedIn && $currentUser->hasAccessTo('lhchat', 'use');

    return array(
        'user' => $currentUser,
        'denial' => erLhcoreClassMdMobile::listDenial($loggedIn, $chatUse),
    );
}

/**
 * @param mixed $params
 * @param string $name
 * @return mixed
 */
function mdmobile_request_value($params, $name)
{
    if (isset($_GET[$name])) {
        return $_GET[$name];
    }
    if (is_array($params) && isset($params['user_parameters_unordered'][$name])) {
        return $params['user_parameters_unordered'][$name];
    }

    return null;
}

/**
 * @param mixed $params
 * @return array{q: string, status: string, offset: int}
 */
function mdmobile_inbox_state($params)
{
    return erLhcoreClassMdMobile::inboxRequest(
        mdmobile_request_value($params, 'q'),
        mdmobile_request_value($params, 'status'),
        mdmobile_request_value($params, 'offset')
    );
}

/**
 * Native department limitation first, then per-row hasAccessToRead.
 * A false limitation does not run a query. Search is quoted before it is
 * added to the query. This function does not write unread state.
 *
 * @param array{q: string, status: string, offset: int} $state
 * @param mixed $currentId
 * @return array<string, mixed>
 */
function mdmobile_load_inbox($state, $currentId)
{
    $limitation = false;
    if (class_exists('erLhcoreClassChat')) {
        $limitation = erLhcoreClassChat::getDepartmentLimitation('lh_chat', array('check_list_permissions' => true));
    }
    $query = erLhcoreClassMdMobile::inboxQuery($state['q'], $state['status'], $state['offset'], $limitation);
    $rows = array();
    if ($query !== null) {
        $search = isset($query['search']) ? $query['search'] : null;
        unset($query['search']);
        $ready = true;
        if ($search !== null) {
            $fragment = null;
            if (class_exists('ezcDbInstance')) {
                $fragment = erLhcoreClassMdMobile::searchFilter($search, static function ($value) {
                    return ezcDbInstance::get()->quote($value);
                });
            }
            if (!is_string($fragment) || $fragment === '') {
                $ready = false;
            } else {
                if (!isset($query['customfilter']) || !is_array($query['customfilter'])) {
                    $query['customfilter'] = array();
                }
                $query['customfilter'][] = $fragment;
            }
        }
        if ($ready) {
            $listed = erLhcoreClassModelChat::getList($query);
            $rows = is_array($listed) ? $listed : array();
        }
    }

    $base = mdmobile_admin_base();
    $chats = erLhcoreClassMdMobile::visibleInbox(
        $rows,
        static function ($chat) {
            return is_object($chat) && erLhcoreClassChat::hasAccessToRead($chat) === true;
        },
        static function ($chat) {
            $messageQuery = erLhcoreClassMdMobile::latestMessageQuery(
                is_object($chat) && isset($chat->id) ? $chat->id : 0
            );
            if ($messageQuery === null) {
                return null;
            }
            $messages = erLhcoreClassModelmsg::getList($messageQuery);
            if (!is_array($messages)) {
                return null;
            }

            return erLhcoreClassMdMobile::latestPreviewFromMessages(
                erLhcoreClassMdMobile::rowsFromMessages($messages)
            );
        },
        $base
    );

    return erLhcoreClassMdMobile::inboxResult(
        $chats,
        $state['q'],
        $state['status'],
        $state['offset'],
        $currentId,
        $base
    );
}

/**
 * Writes the LHC 4.97 adminchat foreground seen fields. Caller must already
 * have checked POST, session, lhchat/use, hasAccessToRead, and CSRF.
 *
 * @param object $chat
 * @param erLhcoreClassUser $user
 * @return array<string, mixed>
 */
function mdmobile_mark_read($chat, $user)
{
    if (!is_object($chat) || !isset($chat->id)) {
        return array('denial' => 'missing');
    }

    $operatorId = (int) $user->getUserID();
    $db = ezcDbInstance::get();
    $db->beginTransaction();
    try {
        $locked = erLhcoreClassModelChat::fetchAndLock($chat->id);
        if (!($locked instanceof erLhcoreClassModelChat) || erLhcoreClassChat::hasAccessToRead($locked) !== true) {
            $db->rollback();

            return array('denial' => ($locked instanceof erLhcoreClassModelChat) ? 'forbidden' : 'missing');
        }
        $userData = $user->getUserData(true);
        $invisible = is_object($userData) && isset($userData->invisible_mode) && (int) $userData->invisible_mode !== 0;
        $canWrite = erLhcoreClassChat::hasAccessToWrite($locked) === true;
        $front = $locked->user_status_front;
        $plan = erLhcoreClassMdMobile::readMutation($locked, $operatorId, $canWrite, $invisible, $front);
        if (
            $plan['messages'] !== null
            && (int) $plan['messages']['chat_id'] === (int) $locked->id
            && (int) $plan['messages']['user_id'] === 0
            && (int) $plan['messages']['del_st'] === 3
        ) {
            $stmt = $db->prepare('UPDATE `lh_msg` SET `del_st` = :del_st WHERE `chat_id` = :chat_id AND `del_st` IN (0,1,2) AND `user_id` = :user_id');
            $stmt->bindValue(':del_st', 3, PDO::PARAM_INT);
            $stmt->bindValue(':chat_id', (int) $locked->id, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', 0, PDO::PARAM_INT);
            $stmt->execute();
        }
        $allowed = array(
            'has_unread_messages' => true,
            'support_informed' => true,
            'unread_messages_informed' => true,
            'unanswered_chat' => true,
        );
        $columns = array();
        foreach ($plan['fields'] as $field => $value) {
            if (!isset($allowed[$field])) {
                continue;
            }
            $locked->$field = $value;
            $columns[] = $field;
        }
        if ($columns !== array()) {
            $locked->updateThis(array('update' => $columns));
        }
        $db->commit();
    } catch (Exception $exception) {
        if ($db->inTransaction()) {
            $db->rollback();
        }

        return array('error' => 'read');
    }

    if ($plan['deliver'] === true && class_exists('erLhcoreClassChatEventDispatcher')) {
        try {
            erLhcoreClassChatEventDispatcher::getInstance()->dispatch(
                'chat.vi_msg_delivered',
                array('chat' => &$locked, 'user' => $user)
            );
        } catch (Exception $exception) {
            $plan['deliver'] = false;
        }
    }

    $unread = isset($plan['fields']['has_unread_messages'])
        ? (int) $plan['fields']['has_unread_messages'] === 1
        : (isset($locked->has_unread_messages) && (int) $locked->has_unread_messages === 1);

    return array(
        'applied' => $plan['fields'] !== array() || $plan['messages'] !== null,
        'unread' => $unread,
        'chat' => $locked,
    );
}
