<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MdMobileInboxTest extends TestCase
{
    private const BASE = 'https://lhc.test/index.php/site_admin';

    public function testDeskUrlsStayOnTheMobileModule(): void
    {
        $urls = erLhcoreClassMdMobile::endpointUrls(self::BASE . '/', 15);

        self::assertSame(self::BASE . '/mdmobile/messages/15', $urls['messages_url']);
        self::assertSame(self::BASE . '/chat/addmsgadmin/15', $urls['send_url']);
        self::assertSame(self::BASE . '/mdmobile/chats', $urls['list_url']);
        self::assertSame(self::BASE . '/mdmobile/list', $urls['list_endpoint']);
        self::assertSame(self::BASE . '/mdmobile/read/15', $urls['read_url']);
        self::assertSame('/index.php/site_admin/mdmobile/chats', erLhcoreClassMdMobile::deskUrl('/index.php/site_admin/', 'mdmobile/chats'));
        self::assertNull(erLhcoreClassMdMobile::deskUrl('javascript:alert(1)', 'mdmobile/chats'));
        self::assertNull(erLhcoreClassMdMobile::deskUrl('//evil.example/admin', 'mdmobile/list'));
        self::assertNull(erLhcoreClassMdMobile::deskUrl(self::BASE, '../mdmobile/chats'));
        self::assertNull(erLhcoreClassMdMobile::deskUrl(self::BASE, 'mdmobile/chat/0'));
        self::assertNull(erLhcoreClassMdMobile::deskUrl('https://user:pass@lhc.test/admin', 'mdmobile/list'));
    }

    public function testTemplateBagKeepsTheMessageContractAndAddsDeskUrls(): void
    {
        $urls = erLhcoreClassMdMobile::endpointUrls(self::BASE, 15);
        $payload = erLhcoreClassMdMobile::format(
            array(
                array('id' => 6, 'user_id' => 0, 'msg' => 'hi', 'time' => 1),
            ),
            0
        );
        $bag = erLhcoreClassMdMobile::templateBag(15, $payload, $urls, 'csrf-token', ' Ada ');

        self::assertSame(15, $bag['chat_id']);
        self::assertSame(6, $bag['after_id']);
        self::assertSame(6, $bag['latest_id']);
        self::assertSame('csrf-token', $bag['csrf']);
        self::assertSame('operator', $bag['sender']);
        self::assertSame('Ada', $bag['nick']);
        self::assertSame('Ada', $bag['title']);
        self::assertSame($payload['messages'], $bag['messages']);
        self::assertSame(array('id', 'kind', 'text', 'time'), array_keys($bag['messages'][0]));
        self::assertSame(self::BASE . '/mdmobile/messages/15', $bag['messages_url']);
        self::assertSame(self::BASE . '/chat/addmsgadmin/15', $bag['send_url']);
        self::assertSame(self::BASE . '/mdmobile/chats', $bag['list_url']);
        self::assertSame(self::BASE . '/mdmobile/read/15', $bag['read_url']);
        self::assertArrayNotHasKey('list_endpoint', $bag);

        $derived = erLhcoreClassMdMobile::templateBag(
            15,
            $payload,
            array(
                'messages_url' => self::BASE . '/mdmobile/messages/15',
                'send_url' => self::BASE . '/chat/addmsgadmin/15',
            ),
            'csrf-token'
        );
        self::assertSame(self::BASE . '/mdmobile/chats', $derived['list_url']);
        self::assertSame(self::BASE . '/mdmobile/read/15', $derived['read_url']);

        $mismatched = erLhcoreClassMdMobile::templateBag(
            9,
            $payload,
            array(
                'messages_url' => self::BASE . '/mdmobile/messages/15',
                'send_url' => self::BASE . '/chat/addmsgadmin/15',
            ),
            'csrf-token'
        );
        self::assertArrayNotHasKey('read_url', $mismatched);
        self::assertArrayNotHasKey('list_url', $mismatched);
    }

    public function testHistoryUrlsAreAddedAfterTheBagIsAssembled(): void
    {
        $urls = erLhcoreClassMdMobile::endpointUrls(self::BASE, 15);
        $payload = erLhcoreClassMdMobile::format(array(), 0);
        $bag = erLhcoreClassMdMobile::templateBag(
            15,
            $payload,
            $urls,
            'csrf-token',
            '',
            array(
                'history' => array(
                    array('id' => 3, 'time' => 10, 'preview' => 'hi', 'status' => 'closed', 'url' => 'javascript:alert(1)'),
                ),
            )
        );

        self::assertArrayNotHasKey('url', $bag['context']['history'][0]);
        self::assertSame(
            array(
                array(
                    'id' => 3,
                    'time' => 10,
                    'preview' => 'hi',
                    'status' => 'closed',
                    'url' => self::BASE . '/mdmobile/chat/3',
                ),
            ),
            erLhcoreClassMdMobile::withHistoryUrls($bag['context']['history'], self::BASE . '/')
        );
        self::assertSame(
            array(
                array('id' => 3, 'time' => 10, 'preview' => 'hi', 'status' => 'closed'),
            ),
            erLhcoreClassMdMobile::withHistoryUrls($bag['context']['history'], 'javascript:alert(1)')
        );
    }

    public function testInboxRequestNormalizesSearchStatusAndOffset(): void
    {
        self::assertSame(20, erLhcoreClassMdMobile::INBOX_LIMIT);
        self::assertSame(
            array('q' => 'ada', 'status' => 'pending', 'offset' => 20),
            erLhcoreClassMdMobile::inboxRequest('  ada  ', 'pending', '20')
        );
        self::assertSame(
            array('q' => '', 'status' => '', 'offset' => 0),
            erLhcoreClassMdMobile::inboxRequest(" \n ", 'Pending', '20abc')
        );
        self::assertSame('ada', erLhcoreClassMdMobile::inboxRequest("ada\x00", '', 0)['q']);
        self::assertSame(str_repeat('a', 80), erLhcoreClassMdMobile::inboxRequest(str_repeat('a', 81), '', 0)['q']);
        self::assertSame(str_repeat('测', 80), erLhcoreClassMdMobile::inboxRequest(str_repeat('测', 81), '', 0)['q']);
        self::assertSame(
            array('pending', 'active', 'closed', 'chatbox', 'operators', 'bot'),
            array_keys(erLhcoreClassMdMobile::inboxStatuses())
        );
    }

    public function testInboxQueryAppliesLimitationBeforeRowChecks(): void
    {
        $limitation = '(`lh_chat`.`dep_id` IN (1,2) AND (`user_id` = 7 OR (`user_id` = 0 AND `status` = 0)))';
        $query = erLhcoreClassMdMobile::inboxQuery(' ada ', 'active', 0, $limitation);

        self::assertIsArray($query);
        self::assertSame(erLhcoreClassMdMobile::INBOX_BATCH, $query['limit']);
        self::assertSame(0, $query['offset']);
        self::assertSame(
            'GREATEST(`time`, `last_user_msg_time`, `last_op_msg_time`) DESC, `id` DESC',
            $query['sort']
        );
        self::assertSame(array('status' => 1), $query['filter']);
        self::assertSame(array('nick' => 'ada'), $query['search']);
        self::assertSame(array($limitation), $query['customfilter']);
        self::assertArrayNotHasKey('filterlike', $query);
        self::assertStringNotContainsString('ada', $query['customfilter'][0]);

        $open = erLhcoreClassMdMobile::inboxQuery('', '', 0, true);
        self::assertIsArray($open);
        self::assertArrayNotHasKey('customfilter', $open);
        self::assertArrayNotHasKey('filter', $open);
        self::assertArrayNotHasKey('search', $open);

        self::assertNull(erLhcoreClassMdMobile::inboxQuery('ada', 'pending', 0, false));
        self::assertNull(erLhcoreClassMdMobile::inboxQuery('ada', 'pending', 0, ''));
        self::assertNull(erLhcoreClassMdMobile::inboxQuery('ada', 'pending', 0, array('dep_id' => 1)));
        self::assertNull(erLhcoreClassMdMobile::inboxQuery('ada', 'pending', 0, 1));

        $statuses = array(
            'pending' => 0,
            'active' => 1,
            'closed' => 2,
            'chatbox' => 3,
            'operators' => 4,
            'bot' => 5,
        );
        foreach ($statuses as $token => $code) {
            $filtered = erLhcoreClassMdMobile::inboxQuery('', $token, 0, true);
            self::assertSame(array('status' => $code), $filtered['filter']);
        }

        $numeric = erLhcoreClassMdMobile::inboxQuery('15', '', 0, true);
        self::assertSame(array('nick' => '15', 'id' => 15), $numeric['search']);
        self::assertSame(array('nick' => '15abc'), erLhcoreClassMdMobile::inboxQuery('15abc', '', 0, true)['search']);
        self::assertSame(array('nick' => '0'), erLhcoreClassMdMobile::inboxQuery('0', '', 0, true)['search']);
        self::assertSame(array('nick' => '015'), erLhcoreClassMdMobile::inboxQuery('015', '', 0, true)['search']);
        self::assertSame(array('nick' => '15', 'id' => 15), erLhcoreClassMdMobile::inboxQuery(15, '', 0, true)['search']);
    }

    public function testSearchFilterQuotesNickAndKeepsIdExact(): void
    {
        $seen = null;
        $sql = erLhcoreClassMdMobile::searchFilter(
            array('nick' => "a'%b_c\\", 'id' => '15'),
            static function ($value) use (&$seen) {
                $seen = $value;

                return "'quoted'";
            }
        );

        self::assertSame('%a\'\\%b\\_c\\\\%', $seen);
        self::assertSame('(`nick` LIKE \'quoted\' OR `id` = 15)', $sql);
        self::assertStringNotContainsString("a'%b", $sql);
        self::assertSame('(`id` = 15)', erLhcoreClassMdMobile::searchFilter(array('id' => 15), static function () {
            return "'unused'";
        }));
        self::assertNull(erLhcoreClassMdMobile::searchFilter(array('nick' => 'ada', 'id' => '15;drop'), static function () {
            return '';
        }));
        self::assertNull(erLhcoreClassMdMobile::searchFilter(array('id' => '15abc'), static function () {
            return "'x'";
        }));
    }

    public function testInboxItemUsesRealFieldsAndDropsUnsafeUrls(): void
    {
        $url = self::BASE . '/mdmobile/chat/15';
        $item = erLhcoreClassMdMobile::inboxItem(
            array(
                'id' => '15',
                'nick' => ' Ada ',
                'status' => 1,
                'time' => 10,
                'last_user_msg_time' => 50,
                'last_op_msg_time' => 40,
                'has_unread_messages' => '1',
                'email' => 'ada@example.com',
                'referrer' => 'javascript:alert(1)',
            ),
            " latest\nline ",
            $url
        );

        self::assertSame(
            array(
                'id' => 15,
                'url' => $url,
                'nick' => 'Ada',
                'preview' => 'latest line',
                'time' => 50,
                'status' => 'active',
                'unread' => true,
            ),
            $item
        );
        self::assertSame(
            array('id' => 16, 'url' => self::BASE . '/mdmobile/chat/16', 'unread' => false),
            erLhcoreClassMdMobile::inboxItem(array('id' => 16, 'nick' => '   ', 'status' => 9), null, self::BASE . '/mdmobile/chat/16')
        );
        self::assertFalse(erLhcoreClassMdMobile::inboxItem(array('id' => 16, 'has_unread_messages' => 2), null, self::BASE . '/mdmobile/chat/16')['unread']);
        self::assertFalse(erLhcoreClassMdMobile::inboxItem(array('id' => 16, 'status' => 2, 'has_unread_messages' => 1), null, self::BASE . '/mdmobile/chat/16')['unread']);
        // last_op_msg_time is also written by whispers, invisible sends,
        // holds, and modal commands, so it cannot prove acceptance.
        self::assertSame(
            'pending',
            erLhcoreClassMdMobile::inboxItem(array('id' => 16, 'status' => 0, 'last_op_msg_time' => 50), null, self::BASE . '/mdmobile/chat/16')['status']
        );
        self::assertSame(
            'pending',
            erLhcoreClassMdMobile::inboxItem(array('id' => 16, 'status' => 0), null, self::BASE . '/mdmobile/chat/16')['status']
        );
        self::assertNull(erLhcoreClassMdMobile::inboxItem(array('id' => 16), null, 'javascript:alert(1)'));
        self::assertNull(erLhcoreClassMdMobile::inboxItem(array('id' => 16), null, self::BASE . '/mdmobile/chat/15'));
        self::assertNull(erLhcoreClassMdMobile::inboxItem(array('id' => 16), null, self::BASE . '/mdmobile/chat/16?next=1'));
    }

    public function testVisibleInboxFiltersAfterTheQueryAndKeepsPageOrder(): void
    {
        $chats = array(
            array('id' => 4, 'time' => 10, 'nick' => 'No', 'has_unread_messages' => 1),
            (object) array('id' => 8, 'time' => 80, 'last_user_msg_time' => 90, 'nick' => ' Bea ', 'status' => 0, 'has_unread_messages' => 1),
            array('id' => 8, 'time' => 1, 'nick' => 'duplicate'),
            array('id' => 6, 'time' => 90, 'nick' => 'Cam', 'status' => 2, 'has_unread_messages' => 0),
            array('id' => 0, 'nick' => 'drop'),
            'junk',
        );
        $checked = array();
        $previewed = array();
        $items = erLhcoreClassMdMobile::visibleInbox(
            $chats,
            static function ($chat) use (&$checked) {
                $id = is_object($chat) ? (int) $chat->id : (int) $chat['id'];
                $checked[] = $id;

                return $id !== 4;
            },
            static function ($chat) use (&$previewed) {
                $id = is_object($chat) ? (int) $chat->id : (int) $chat['id'];
                $previewed[] = $id;

                return 'msg ' . $id;
            },
            self::BASE
        );

        self::assertSame(array(4, 8, 6), $checked);
        self::assertSame(array(8, 6), $previewed);
        self::assertSame(array(8, 6), array_column($items, 'id'));
        self::assertSame(self::BASE . '/mdmobile/chat/8', $items[0]['url']);
        self::assertSame('Bea', $items[0]['nick']);
        self::assertSame('msg 8', $items[0]['preview']);
        self::assertSame(90, $items[0]['time']);
        self::assertSame('pending', $items[0]['status']);
        self::assertTrue($items[0]['unread']);
        self::assertFalse($items[1]['unread']);
        self::assertArrayNotHasKey('email', $items[0]);
        self::assertSame(array(), erLhcoreClassMdMobile::visibleInbox($chats, static function () {
            return true;
        }, static function () {
            return 'no';
        }, 'javascript:alert(1)'));
    }

    public function testLatestPreviewIsNotTheHistoryPreview(): void
    {
        self::assertSame(
            array(
                'filter' => array('chat_id' => 8),
                'filtergte' => array('user_id' => 0),
                'limit' => 20,
                'sort' => 'id DESC',
            ),
            erLhcoreClassMdMobile::latestMessageQuery('8')
        );
        self::assertNull(erLhcoreClassMdMobile::latestMessageQuery(0));
        self::assertSame(
            'newest real',
            erLhcoreClassMdMobile::latestPreviewFromMessages(array(
                array('id' => 2, 'user_id' => 0, 'msg' => 'older real'),
                array('id' => 9, 'user_id' => -1, 'msg' => 'system later'),
                array('id' => 8, 'user_id' => 3, 'msg' => "newest\nreal"),
                array('id' => 7, 'user_id' => -2, 'msg' => 'bot'),
            ))
        );
        self::assertSame(
            'older real',
            erLhcoreClassMdMobile::previewFromMessages(array(
                array('id' => 2, 'user_id' => 0, 'msg' => 'older real'),
                array('id' => 8, 'user_id' => 3, 'msg' => 'newest real'),
            ))
        );
    }

    public function testInboxPayloadsExposeOnlyTheDeskContract(): void
    {
        $chats = array(
            array(
                'id' => 15,
                'url' => self::BASE . '/mdmobile/chat/15',
                'nick' => 'Ada',
                'preview' => 'hi',
                'time' => 50,
                'status' => 'active',
                'unread' => 1,
                'email' => 'ada@example.com',
            ),
            array('id' => 16, 'email' => 'hidden@example.com'),
        );
        $inbox = erLhcoreClassMdMobile::inboxResult($chats, ' ada ', 'active', 20, '15', self::BASE);
        $json = erLhcoreClassMdMobile::inboxJson($chats, 20, ' ada ', 'active');

        self::assertSame(
            array('chats', 'q', 'status', 'list_url', 'list_endpoint', 'current_id', 'offset', 'limit', 'has_more'),
            array_keys($inbox)
        );
        self::assertSame(false, $inbox['has_more']);
        self::assertSame('ada', $inbox['q']);
        self::assertSame('active', $inbox['status']);
        self::assertSame(self::BASE . '/mdmobile/chats?q=ada&status=active&offset=20', $inbox['list_url']);
        self::assertSame(self::BASE . '/mdmobile/list', $inbox['list_endpoint']);
        self::assertSame(15, $inbox['current_id']);
        self::assertSame(20, $inbox['offset']);
        self::assertSame(20, $inbox['limit']);
        self::assertCount(1, $inbox['chats']);
        self::assertTrue($inbox['chats'][0]['unread']);
        self::assertArrayNotHasKey('email', $inbox['chats'][0]);
        self::assertSame(
            array('chats', 'offset', 'limit', 'q', 'status', 'has_more'),
            array_keys($json)
        );
        self::assertSame(false, $json['has_more']);
        self::assertSame($inbox['chats'], $json['chats']);
        self::assertSame('mdmobile/chats?q=a%20b&status=bot&offset=40', erLhcoreClassMdMobile::inboxReturnPath('a b', 'bot', 40));
        self::assertSame('mdmobile/chats', erLhcoreClassMdMobile::inboxReturnPath('', 'nope', 0));
        self::assertSame('', erLhcoreClassMdMobile::inboxResult(array(), '', '', 0, 0, 'javascript:alert(1)')['list_url']);
        self::assertSame('', erLhcoreClassMdMobile::inboxResult(array(), '', '', 0, 0, 'javascript:alert(1)')['list_endpoint']);
    }

    public function testReadMutationMatchesForegroundAdminChatSeenFields(): void
    {
        $owned = erLhcoreClassMdMobile::readMutation(
            array(
                'id' => 15,
                'user_id' => 7,
                'status' => 1,
                'has_unread_messages' => 1,
                'unanswered_chat' => 1,
            ),
            7,
            true,
            false,
            0
        );

        self::assertSame(
            array(
                'fields' => array(
                    'has_unread_messages' => 0,
                    'support_informed' => 1,
                    'unread_messages_informed' => 0,
                    'unanswered_chat' => 0,
                ),
                'messages' => array(
                    'chat_id' => 15,
                    'del_st' => 3,
                    'del_st_in' => array(0, 1, 2),
                    'user_id' => 0,
                ),
                'deliver' => true,
            ),
            $owned
        );
        self::assertArrayNotHasKey('status', $owned['fields']);
        self::assertArrayNotHasKey('user_id', $owned['fields']);
        self::assertArrayNotHasKey('last_msg_id', $owned['fields']);

        $already = erLhcoreClassMdMobile::readMutation(
            array('id' => 15, 'user_id' => '7', 'status' => 1, 'has_unread_messages' => 0, 'unanswered_chat' => 0),
            '7',
            true,
            false,
            1
        );
        self::assertSame(
            array(
                'support_informed' => 1,
                'unread_messages_informed' => 0,
            ),
            $already['fields']
        );
        self::assertNull($already['messages']);
        self::assertFalse($already['deliver']);

        $delivered = erLhcoreClassMdMobile::readMutation(
            array('id' => 15, 'user_id' => 7, 'status' => 1, 'has_unread_messages' => 2),
            7,
            true,
            true,
            0
        );
        self::assertArrayNotHasKey('has_unread_messages', $delivered['fields']);
        self::assertSame(1, $delivered['fields']['support_informed']);
        self::assertNull($delivered['messages']);
        self::assertNotContains(2, $delivered['fields']);

        $other = erLhcoreClassMdMobile::readMutation(
            array(
                'id' => 15,
                'user_id' => 9,
                'status' => 1,
                'has_unread_messages' => 1,
                'unanswered_chat' => 1,
            ),
            7,
            true,
            false,
            0
        );
        self::assertSame(array('unanswered_chat' => 0), $other['fields']);
        self::assertNull($other['messages']);
        self::assertFalse($other['deliver']);

        $closed = erLhcoreClassMdMobile::readMutation(
            array('id' => 15, 'user_id' => 9, 'status' => '2', 'has_unread_messages' => '1'),
            7,
            true,
            false,
            '2abc'
        );
        self::assertSame(0, $closed['fields']['has_unread_messages']);
        self::assertSame(15, $closed['messages']['chat_id']);
        self::assertArrayNotHasKey('unanswered_chat', $closed['fields']);
        self::assertTrue($closed['deliver']);

        $unassigned = erLhcoreClassMdMobile::readMutation(
            array('id' => 15, 'user_id' => 0, 'status' => 0, 'has_unread_messages' => 1, 'unanswered_chat' => 1),
            7,
            true,
            false,
            2
        );
        self::assertSame(0, $unassigned['fields']['has_unread_messages']);
        self::assertSame(0, $unassigned['fields']['unanswered_chat']);
        self::assertArrayNotHasKey('user_id', $unassigned['fields']);
        self::assertArrayNotHasKey('status', $unassigned['fields']);

        $noop = array('fields' => array(), 'messages' => null, 'deliver' => false);
        self::assertSame($noop, erLhcoreClassMdMobile::readMutation(
            array('id' => 15, 'user_id' => 9, 'status' => 1, 'has_unread_messages' => 1, 'unanswered_chat' => 1),
            7,
            true,
            true,
            0
        ));
        self::assertSame($noop, erLhcoreClassMdMobile::readMutation(
            array('id' => 15, 'user_id' => 7, 'status' => 1, 'has_unread_messages' => 1),
            7,
            false,
            false,
            0
        ));
        self::assertSame($noop, erLhcoreClassMdMobile::readMutation(array('id' => 0, 'user_id' => 7), 7, true, false, 0));
    }

    public function testReadRequiresPostAndDoesNotTreatGetAsRead(): void
    {
        self::assertSame(
            'method',
            erLhcoreClassMdMobile::readDenial('GET', true, true, true, true, true)
        );
        self::assertSame('method', erLhcoreClassMdMobile::readDenial('HEAD', false, false, false, false, false));
        self::assertSame('session', erLhcoreClassMdMobile::readDenial('POST', false, false, false, false, true));
        self::assertSame('forbidden', erLhcoreClassMdMobile::readDenial('POST', true, false, true, true, true));
        self::assertSame('missing', erLhcoreClassMdMobile::readDenial('POST', true, true, false, false, true));
        self::assertSame('forbidden', erLhcoreClassMdMobile::readDenial('POST', true, true, true, false, true));
        self::assertSame('csrf', erLhcoreClassMdMobile::readDenial('POST', true, true, true, true, false));
        self::assertNull(erLhcoreClassMdMobile::readDenial('POST', true, true, true, true, true));
        self::assertSame(405, erLhcoreClassMdMobile::denialStatus('method'));
        self::assertSame(403, erLhcoreClassMdMobile::denialStatus('csrf'));
        self::assertSame('forbidden', erLhcoreClassMdMobile::listDenial(true, false));
        self::assertNull(erLhcoreClassMdMobile::listDenial(true, true));
        self::assertSame('header-token', erLhcoreClassMdMobile::csrfCandidate('header-token', 'post-token'));
        self::assertSame('post-token', erLhcoreClassMdMobile::csrfCandidate('', 'post-token'));
        self::assertNull(erLhcoreClassMdMobile::csrfCandidate(null, null));
        self::assertSame(
            array('id' => 15, 'unread' => false, 'applied' => true),
            erLhcoreClassMdMobile::readResult(15, false, true)
        );
    }

    public function testModuleDoesNotAddAPermissionAndGetViewsDoNotClearUnread(): void
    {
        $moduleFile = dirname(__DIR__) . '/modules/lhmdmobile/module.php';
        $Module = null;
        $ViewList = array();
        $FunctionList = array('stale');
        include $moduleFile;

        self::assertSame(array(), $FunctionList);
        self::assertArrayNotHasKey('functions', $ViewList['chats']);
        self::assertArrayNotHasKey('functions', $ViewList['list']);
        self::assertArrayNotHasKey('functions', $ViewList['read']);
        self::assertSame(array(), $ViewList['chats']['params']);
        self::assertSame(array('q', 'status', 'offset'), $ViewList['chats']['uparams']);
        self::assertSame('mdmobile', $ViewList['chats']['pagelayout']);
        self::assertTrue($ViewList['list']['ajax']);
        self::assertSame(array('q', 'status', 'offset'), $ViewList['list']['uparams']);
        self::assertTrue($ViewList['read']['ajax']);
        self::assertSame(array('chat_id'), $ViewList['read']['params']);

        $root = dirname(__DIR__) . '/modules/lhmdmobile/';
        foreach (array('list.php', 'chats.php', 'chat.php', 'messages.php') as $name) {
            self::assertFileExists($root . $name);
            $source = file_get_contents($root . $name);
            self::assertIsString($source);
            self::assertStringNotContainsString('updateThis', $source);
            self::assertStringNotContainsString('del_st', $source);
            self::assertStringNotContainsString('has_unread_messages', $source);
            self::assertStringNotContainsString('mdmobile_mark_read', $source);
        }

        $read = file_get_contents($root . 'read.php');
        self::assertIsString($read);
        self::assertStringContainsString('mdmobile_mark_read', $read);
        self::assertStringContainsString('readDenial', $read);
        self::assertStringContainsString('validateCSFRToken', $read);
        self::assertStringContainsString('HTTP_X_CSRFTOKEN', $read);
        self::assertStringNotContainsString("\$_GET['csfr_token']", $read);
        self::assertStringNotContainsString("\$_GET['csrf']", $read);
    }

    public function testInboxChatUrlKeepsTheDeepLinkAndTheCurrentFilter(): void
    {
        self::assertSame(
            self::BASE . '/mdmobile/chat/15?q=ada&status=active&offset=20',
            erLhcoreClassMdMobile::inboxChatUrl(self::BASE . '/', 15, ' ada ', 'active', '20')
        );
        self::assertSame(
            self::BASE . '/mdmobile/chat/15',
            erLhcoreClassMdMobile::inboxChatUrl(self::BASE, 15, " \n ", 'Pending', 0)
        );
        self::assertSame(
            self::BASE . '/mdmobile/chat/8?q=a%20b&status=bot&offset=40',
            erLhcoreClassMdMobile::inboxChatUrl(self::BASE, '8', 'a b', 'bot', 40)
        );
        self::assertSame(
            self::BASE . '/mdmobile/chat/8?q=ada',
            erLhcoreClassMdMobile::inboxChatUrl(self::BASE, 8, 'ada', 'nope', '0abc')
        );
        self::assertNull(erLhcoreClassMdMobile::inboxChatUrl('javascript:alert(1)', 15, 'ada', 'active', 20));
        self::assertNull(erLhcoreClassMdMobile::inboxChatUrl('//evil.example/admin', 15, 'ada', '', 0));
        self::assertNull(erLhcoreClassMdMobile::inboxChatUrl(self::BASE, 0, 'ada', 'active', 20));

        $url = erLhcoreClassMdMobile::inboxChatUrl(self::BASE, 15, 'ada', 'active', 20);
        $kept = erLhcoreClassMdMobile::inboxResult(
            array(
                array(
                    'id' => 15,
                    'url' => $url,
                    'nick' => 'Ada',
                    'unread' => false,
                    'email' => 'ada@example.com',
                ),
            ),
            'ada',
            'active',
            20,
            15,
            self::BASE
        );
        self::assertSame($url, $kept['chats'][0]['url']);
        self::assertStringStartsWith(self::BASE . '/mdmobile/chat/15', $kept['chats'][0]['url']);
        self::assertStringContainsString('q=ada', $kept['chats'][0]['url']);
        self::assertStringContainsString('status=active', $kept['chats'][0]['url']);
        self::assertStringContainsString('offset=20', $kept['chats'][0]['url']);
        self::assertArrayNotHasKey('email', $kept['chats'][0]);

        $hostile = erLhcoreClassMdMobile::inboxResult(
            array(
                array('id' => 16, 'url' => self::BASE . '/mdmobile/chat/16?next=1', 'unread' => false),
                array('id' => 17, 'url' => self::BASE . '/mdmobile/chat/17?q=ada&next=1', 'unread' => false),
                array('id' => 18, 'url' => self::BASE . '/mdmobile/chat/18#ada', 'unread' => false),
            ),
            'ada',
            '',
            0,
            0,
            self::BASE
        );
        self::assertSame(array(), $hostile['chats']);
    }

    public function testInboxQueryUsesRawOffsetAndAFixedBatch(): void
    {
        self::assertSame(20, erLhcoreClassMdMobile::INBOX_BATCH);
        $query = erLhcoreClassMdMobile::inboxQuery(' ada ', 'active', 40, true);

        self::assertIsArray($query);
        self::assertSame(40, $query['offset']);
        self::assertSame(20, $query['limit']);
        self::assertSame(
            'GREATEST(`time`, `last_user_msg_time`, `last_op_msg_time`) DESC, `id` DESC',
            $query['sort']
        );
        self::assertSame(array('status' => 1), $query['filter']);
        self::assertSame(array('nick' => 'ada'), $query['search']);
        self::assertNotSame(41, $query['limit']);

        self::assertSame(0, erLhcoreClassMdMobile::inboxQuery('', '', 0, true)['offset']);
        self::assertSame(20, erLhcoreClassMdMobile::inboxQuery('', '', 0, true)['limit']);
        self::assertSame(0, erLhcoreClassMdMobile::inboxQuery('', '', '20abc', true)['offset']);
        self::assertSame(50, erLhcoreClassMdMobile::inboxQuery('', '', 0, true, 50)['limit']);
    }

    public function testVisibleInboxSkipsRejectedRowsAndLetsTheExtraItemDecideHasMore(): void
    {
        $chats = array();
        for ($id = 30; $id >= 1; $id--) {
            $chats[] = array(
                'id' => $id,
                'time' => $id,
                'nick' => 'N' . $id,
                'has_unread_messages' => $id === 27 ? 1 : 0,
            );
        }
        $previewed = array();
        $hasMore = null;
        $items = erLhcoreClassMdMobile::visibleInbox(
            $chats,
            static function ($chat) {
                return (int) $chat['id'] < 28;
            },
            static function ($chat) use (&$previewed) {
                $previewed[] = (int) $chat['id'];

                return 'p' . $chat['id'];
            },
            self::BASE,
            0,
            20,
            $hasMore
        );

        self::assertSame(range(27, 8), array_column($items, 'id'));
        self::assertSame(true, $hasMore);
        self::assertSame(range(27, 7), $previewed);
        self::assertSame(self::BASE . '/mdmobile/chat/27', $items[0]['url']);
        self::assertTrue($items[0]['unread']);
        self::assertNotContains(30, $previewed);
        self::assertNotContains(1, $previewed);

        $previewed = array();
        $hasMore = true;
        $later = erLhcoreClassMdMobile::visibleInbox(
            $chats,
            static function ($chat) {
                return (int) $chat['id'] < 28;
            },
            static function ($chat) use (&$previewed) {
                $previewed[] = (int) $chat['id'];

                return 'later';
            },
            self::BASE,
            20,
            20,
            $hasMore
        );
        self::assertSame(array(7, 6, 5, 4, 3, 2, 1), array_column($later, 'id'));
        self::assertSame(false, $hasMore);
        self::assertSame(array(7, 6, 5, 4, 3, 2, 1), $previewed);

        $previewed = array();
        $hasMore = null;
        $exact = erLhcoreClassMdMobile::visibleInbox(
            array_slice($chats, 10, 20),
            static function () {
                return true;
            },
            static function ($chat) use (&$previewed) {
                $previewed[] = (int) $chat['id'];

                return null;
            },
            self::BASE,
            0,
            null,
            $hasMore
        );
        self::assertCount(20, $exact);
        self::assertSame(false, $hasMore);
        self::assertCount(20, $previewed);

        $previewed = array();
        $hasMore = false;
        $three = array(
            array('id' => 3, 'time' => 1, 'has_unread_messages' => 0),
            array('id' => 9, 'time' => 9, 'has_unread_messages' => 0),
            array('id' => 4, 'time' => 4, 'has_unread_messages' => 0),
            array('id' => 8, 'time' => 8, 'has_unread_messages' => 0),
        );
        $window = erLhcoreClassMdMobile::visibleInbox(
            $three,
            static function ($chat) {
                return (int) $chat['id'] !== 8;
            },
            static function ($chat) use (&$previewed) {
                $previewed[] = (int) $chat['id'];

                return 'x';
            },
            self::BASE,
            1,
            1,
            $hasMore
        );
        self::assertSame(array(4), array_column($window, 'id'));
        self::assertSame(true, $hasMore);
        self::assertSame(array(4, 3), $previewed);
    }

    public function testInboxPayloadsCarryHasMoreAndListPassesItThrough(): void
    {
        $chats = array(
            array(
                'id' => 15,
                'url' => self::BASE . '/mdmobile/chat/15',
                'unread' => false,
            ),
        );
        $inbox = erLhcoreClassMdMobile::inboxResult($chats, 'ada', 'active', 20, 15, self::BASE, true);
        $json = erLhcoreClassMdMobile::inboxJson($chats, 20, 'ada', 'active', true);

        self::assertSame(true, $inbox['has_more']);
        self::assertSame(true, $json['has_more']);
        self::assertSame(false, erLhcoreClassMdMobile::inboxResult($chats, '', '', 0, 0, self::BASE)['has_more']);
        self::assertSame(false, erLhcoreClassMdMobile::inboxJson($chats, 0, '', '')['has_more']);
        self::assertSame(
            array('chats', 'q', 'status', 'list_url', 'list_endpoint', 'current_id', 'offset', 'limit', 'has_more'),
            array_keys($inbox)
        );
        self::assertSame(
            array('chats', 'offset', 'limit', 'q', 'status', 'has_more'),
            array_keys($json)
        );

        $root = dirname(__DIR__) . '/modules/lhmdmobile/';
        $gate = file_get_contents($root . 'gate.php');
        $list = file_get_contents($root . 'list.php');
        $module = file_get_contents($root . 'module.php');
        self::assertIsString($gate);
        self::assertIsString($list);
        self::assertIsString($module);
        self::assertStringContainsString('inboxChatUrl', $gate);
        self::assertMatchesRegularExpression(
            "/visibleInbox\\(\\s*\\\$rows,[\\s\\S]*\\\$base,\\s*\\\$state\\['offset'\\],\\s*erLhcoreClassMdMobile::INBOX_LIMIT,\\s*\\\$hasMore\\s*\\)/",
            $gate
        );
        self::assertStringContainsString('has_more', $list);
        self::assertMatchesRegularExpression(
            "/\\\$ViewList\\['chat'\\][\\s\\S]*'uparams' => array\\('q', 'status', 'offset'\\)/",
            $module
        );
    }

    public function testReadableInboxContinuesAfterRejectedRowsLeaveTheFirstBatchShort(): void
    {
        $rows = array();
        for ($id = 50; $id >= 1; $id--) {
            $rows[] = array(
                'id' => $id,
                'time' => $id,
                'nick' => 'N' . $id,
                'has_unread_messages' => 0,
            );
        }
        $calls = array();
        $collected = erLhcoreClassMdMobile::collectReadableInbox(
            static function ($rawOffset, $limit) use ($rows, &$calls) {
                $calls[] = array((int) $rawOffset, (int) $limit);

                return array_slice($rows, (int) $rawOffset, (int) $limit);
            },
            static function ($chat) {
                return (int) $chat['id'] <= 35;
            },
            0,
            20,
            20
        );

        self::assertSame(array(array(0, 20), array(20, 20)), $calls);
        self::assertSame(false, $collected['exhausted']);
        self::assertSame(2, $collected['batches']);
        self::assertSame(40, $collected['raw_read']);
        self::assertSame(range(35, 11), array_column($collected['chats'], 'id'));

        $previewed = array();
        $hasMore = false;
        $items = erLhcoreClassMdMobile::visibleInbox(
            $collected['chats'],
            static function () {
                return true;
            },
            static function ($chat) use (&$previewed) {
                $previewed[] = (int) $chat['id'];

                return 'kept';
            },
            self::BASE,
            0,
            20,
            $hasMore
        );

        self::assertSame(range(35, 16), array_column($items, 'id'));
        self::assertSame(true, $hasMore);
        self::assertSame(range(35, 15), $previewed);
        self::assertNotContains(50, $previewed);
        self::assertNotContains(14, $previewed);
        self::assertNotContains(10, $previewed);

        $gate = file_get_contents(dirname(__DIR__) . '/modules/lhmdmobile/gate.php');
        self::assertIsString($gate);
        self::assertStringContainsString('collectReadableInbox', $gate);
        self::assertStringNotContainsString(
            "inboxQuery(\$state['q'], \$state['status'], \$state['offset']",
            $gate
        );
    }

    public function testReadableInboxStopsWhenRawRowsRunOut(): void
    {
        $rows = array();
        for ($id = 25; $id >= 1; $id--) {
            $rows[] = array(
                'id' => $id,
                'time' => $id,
                'has_unread_messages' => 0,
            );
        }
        $calls = array();
        $fetch = static function ($rawOffset, $limit) use ($rows, &$calls) {
            $calls[] = array((int) $rawOffset, (int) $limit);

            return array_slice($rows, (int) $rawOffset, (int) $limit);
        };
        $canRead = static function ($chat) {
            return (int) $chat['id'] <= 7;
        };
        $collected = erLhcoreClassMdMobile::collectReadableInbox($fetch, $canRead, 0, 20, 20);

        self::assertSame(array(array(0, 20), array(20, 20)), $calls);
        self::assertSame(true, $collected['exhausted']);
        self::assertSame(25, $collected['raw_read']);
        self::assertSame(array(7, 6, 5, 4, 3, 2, 1), array_column($collected['chats'], 'id'));

        $previewed = array();
        $hasMore = true;
        $items = erLhcoreClassMdMobile::visibleInbox(
            $collected['chats'],
            static function () {
                return true;
            },
            static function ($chat) use (&$previewed) {
                $previewed[] = (int) $chat['id'];

                return null;
            },
            self::BASE,
            0,
            20,
            $hasMore
        );
        self::assertSame(array(7, 6, 5, 4, 3, 2, 1), array_column($items, 'id'));
        self::assertSame(false, $hasMore);
        self::assertSame(array(7, 6, 5, 4, 3, 2, 1), $previewed);

        $calls = array();
        $paged = erLhcoreClassMdMobile::collectReadableInbox($fetch, $canRead, 20, 20, 20);
        self::assertSame(array(array(0, 20), array(20, 20)), $calls);
        self::assertSame(true, $paged['exhausted']);
        self::assertSame(array(), erLhcoreClassMdMobile::visibleInbox(
            $paged['chats'],
            static function () {
                return true;
            },
            static function () {
                return 'no';
            },
            self::BASE,
            20,
            20,
            $hasMore
        ));
        self::assertSame(false, $hasMore);
    }
}
