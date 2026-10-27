<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MdMobileDeskTest extends TestCase
{
    public function testKindMapsLhMsgUserId(): void
    {
        self::assertSame('visitor', erLhcoreClassMdMobile::kindFromUserId(0));
        self::assertSame('operator', erLhcoreClassMdMobile::kindFromUserId(7));
        self::assertSame('system', erLhcoreClassMdMobile::kindFromUserId(-1));
        self::assertSame('bot', erLhcoreClassMdMobile::kindFromUserId(-2));
        self::assertSame('system', erLhcoreClassMdMobile::kindFromUserId(-3));
    }

    public function testFormatKeepsTheContractAndTheCursor(): void
    {
        $payload = erLhcoreClassMdMobile::format(
            array(
                array('id' => 4, 'user_id' => -1, 'msg' => 'joined', 'time' => 30),
                array('id' => 2, 'user_id' => 0, 'msg' => 'hello', 'time' => 10),
                array('id' => 3, 'user_id' => 9, 'msg' => '[b]reply[/b]', 'time' => 20),
                array('id' => 5, 'user_id' => -2, 'msg' => 'bot line', 'time' => 40),
            ),
            2
        );

        self::assertSame(
            array(
                array('id' => 3, 'kind' => 'operator', 'text' => 'reply', 'time' => 20),
                array('id' => 4, 'kind' => 'system', 'text' => 'joined', 'time' => 30),
                array('id' => 5, 'kind' => 'bot', 'text' => 'bot line', 'time' => 40),
            ),
            $payload['messages']
        );
        self::assertSame(5, $payload['latest_id']);
        self::assertSame(
            array('messages', 'latest_id'),
            array_keys($payload)
        );
        self::assertSame(
            array('id', 'kind', 'text', 'time'),
            array_keys($payload['messages'][0])
        );
    }

    public function testFormatRendersOfflineBodiesAsReadableText(): void
    {
        $payload = erLhcoreClassMdMobile::format(
            array(
                array(
                    'id' => 7,
                    'user_id' => -1,
                    'msg' => "[b]Visitor query[/b]:\nDo you ship to Iceland?\n\n[b]E-mail[/b]: visitor@example.com\n[b]Nick[/b]: Visitor",
                    'time' => 50,
                ),
                array('id' => 8, 'user_id' => -2, 'msg' => '<b>Bot</b> reply', 'time' => 60),
            ),
            0
        );

        self::assertSame(
            "Visitor query:\nDo you ship to Iceland?\n\nE-mail: visitor@example.com\nNick: Visitor",
            $payload['messages'][0]['text']
        );
        self::assertSame('Bot reply', $payload['messages'][1]['text']);
    }

    public function testFormatDropsBotStatusJsonButKeepsOtherKinds(): void
    {
        $payload = erLhcoreClassMdMobile::format(
            array(
                array('id' => 10, 'user_id' => 0, 'msg' => '{"ok":true}', 'time' => 10),
                array('id' => 11, 'user_id' => 9, 'msg' => '{"status":"closed"}', 'time' => 11),
                array('id' => 12, 'user_id' => -2, 'msg' => '{"ok":true}', 'time' => 12),
                array('id' => 13, 'user_id' => -2, 'msg' => '{"status":"typing"}', 'time' => 13),
                array('id' => 14, 'user_id' => -2, 'msg' => '{"ok":true,"status":"done"}', 'time' => 14),
                array('id' => 15, 'user_id' => -2, 'msg' => '{"ok":true,"message":"still here"}', 'time' => 15),
                array('id' => 16, 'user_id' => -2, 'msg' => 'not json', 'time' => 16),
            ),
            0
        );

        self::assertSame(
            array(
                array('id' => 10, 'kind' => 'visitor', 'text' => '{"ok":true}', 'time' => 10),
                array('id' => 11, 'kind' => 'operator', 'text' => '{"status":"closed"}', 'time' => 11),
                array('id' => 15, 'kind' => 'bot', 'text' => '{"ok":true,"message":"still here"}', 'time' => 15),
                array('id' => 16, 'kind' => 'bot', 'text' => 'not json', 'time' => 16),
            ),
            $payload['messages']
        );
    }

    public function testFormatCollapsesRepeatedSystemRowsOnly(): void
    {
        $payload = erLhcoreClassMdMobile::format(
            array(
                array('id' => 20, 'user_id' => -1, 'msg' => 'Visitor left the chat', 'time' => 20),
                array('id' => 21, 'user_id' => 0, 'msg' => 'hello', 'time' => 21),
                array('id' => 22, 'user_id' => -1, 'msg' => 'Visitor left the chat', 'time' => 22),
                array('id' => 23, 'user_id' => -1, 'msg' => 'Operator joined', 'time' => 23),
                array('id' => 24, 'user_id' => 0, 'msg' => 'hello', 'time' => 24),
                array('id' => 25, 'user_id' => 9, 'msg' => 'hello', 'time' => 25),
            ),
            0
        );

        self::assertSame(
            array(
                array('id' => 20, 'kind' => 'system', 'text' => 'Visitor left the chat', 'time' => 20),
                array('id' => 21, 'kind' => 'visitor', 'text' => 'hello', 'time' => 21),
                array('id' => 23, 'kind' => 'system', 'text' => 'Operator joined', 'time' => 23),
                array('id' => 24, 'kind' => 'visitor', 'text' => 'hello', 'time' => 24),
                array('id' => 25, 'kind' => 'operator', 'text' => 'hello', 'time' => 25),
            ),
            $payload['messages']
        );
    }

    public function testFormatCursorStaysOnDroppedTrailingBotJson(): void
    {
        $payload = erLhcoreClassMdMobile::format(
            array(
                array('id' => 30, 'user_id' => 0, 'msg' => 'hello', 'time' => 30),
                array('id' => 31, 'user_id' => -2, 'msg' => '{"ok":true}', 'time' => 31),
            ),
            0
        );

        self::assertSame(
            array(
                array('id' => 30, 'kind' => 'visitor', 'text' => 'hello', 'time' => 30),
            ),
            $payload['messages']
        );
        self::assertSame(31, $payload['latest_id']);
    }

    public function testEmptyPollKeepsTheCursor(): void
    {
        $payload = erLhcoreClassMdMobile::format(
            array(
                array('id' => 2, 'user_id' => 0, 'msg' => 'old', 'time' => 1),
            ),
            8
        );

        self::assertSame(array(), $payload['messages']);
        self::assertSame(8, $payload['latest_id']);
    }

    public function testPermissionMatrix(): void
    {
        self::assertSame('session', erLhcoreClassMdMobile::denial(false, false, false, false));
        self::assertSame('session', erLhcoreClassMdMobile::denial(false, true, true, true));
        self::assertSame('forbidden', erLhcoreClassMdMobile::denial(true, false, true, true));
        self::assertSame('forbidden', erLhcoreClassMdMobile::denial(true, false, false, false));
        self::assertSame('missing', erLhcoreClassMdMobile::denial(true, true, false, false));
        self::assertSame('forbidden', erLhcoreClassMdMobile::denial(true, true, true, false));
        self::assertNull(erLhcoreClassMdMobile::denial(true, true, true, true));
        self::assertSame(401, erLhcoreClassMdMobile::denialStatus('session'));
        self::assertSame(404, erLhcoreClassMdMobile::denialStatus('missing'));
        self::assertSame(403, erLhcoreClassMdMobile::denialStatus('forbidden'));
    }

    public function testMessagesEndpointIsReadOnly(): void
    {
        self::assertTrue(erLhcoreClassMdMobile::readOnlyMethod('GET'));
        self::assertTrue(erLhcoreClassMdMobile::readOnlyMethod('head'));
        self::assertFalse(erLhcoreClassMdMobile::readOnlyMethod('POST'));
    }

    public function testIdsRejectJunk(): void
    {
        self::assertSame(12, erLhcoreClassMdMobile::chatId('12'));
        self::assertSame(0, erLhcoreClassMdMobile::chatId('12abc'));
        self::assertSame(0, erLhcoreClassMdMobile::chatId('0'));
        self::assertSame(0, erLhcoreClassMdMobile::afterId(null));
        self::assertSame(0, erLhcoreClassMdMobile::afterId('12abc'));
        self::assertSame(4, erLhcoreClassMdMobile::afterId('4'));
        self::assertSame(0, erLhcoreClassMdMobile::afterId(-3));
    }

    public function testTemplateBagPointsAtNativeSend(): void
    {
        $urls = erLhcoreClassMdMobile::endpointUrls('https://lhc.test/index.php/site_admin/', 15);
        $payload = erLhcoreClassMdMobile::format(
            array(
                array('id' => 6, 'user_id' => 0, 'msg' => 'hi', 'time' => 1),
            ),
            0
        );
        $bag = erLhcoreClassMdMobile::templateBag(15, $payload, $urls, 'csrf-token');

        self::assertSame(15, $bag['chat_id']);
        self::assertSame(6, $bag['after_id']);
        self::assertSame(6, $bag['latest_id']);
        self::assertSame('csrf-token', $bag['csrf']);
        self::assertSame('operator', $bag['sender']);
        self::assertArrayNotHasKey('title', $bag);

        $named = erLhcoreClassMdMobile::templateBag(15, $payload, $urls, 'csrf-token', ' Ada ');
        self::assertSame('Ada', $named['nick']);
        self::assertSame('Ada', $named['title']);
        self::assertSame(
            'https://lhc.test/index.php/site_admin/mdmobile/messages/15',
            $bag['messages_url']
        );
        self::assertSame(
            'https://lhc.test/index.php/site_admin/chat/addmsgadmin/15',
            $bag['send_url']
        );
        self::assertSame(
            'https://lhc.test/index.php/site_admin/chat/saveremarks/15',
            $bag['remarks_url']
        );
        self::assertArrayNotHasKey('remarks', $bag);
        $annotated = erLhcoreClassMdMobile::templateBag(
            15,
            $payload,
            $urls,
            'csrf-token',
            '',
            null,
            "  call back\r\nweekday  "
        );
        self::assertSame("call back\nweekday", $annotated['remarks']);
        self::assertSame('site_admin', erLhcoreClassMdMobile::adminSiteAccess(array('site_admin')));
        self::assertSame('site_admin', erLhcoreClassMdMobile::adminSiteAccess('../evil'));
    }

    public function testListQueryReadsForwardFromLhMsg(): void
    {
        self::assertSame(
            array(
                'filter' => array('chat_id' => 15),
                'limit' => 200,
                'sort' => 'id ASC',
                'filtergt' => array('id' => 6),
            ),
            erLhcoreClassMdMobile::listQuery(15, 6, false)
        );
        self::assertSame('id DESC', erLhcoreClassMdMobile::listQuery(15, 0, true)['sort']);
        self::assertArrayNotHasKey('filtergt', erLhcoreClassMdMobile::listQuery(15, 0, false));
    }

    public function testVisitorContextUsesOnlyRealFields(): void
    {
        $stamp = 1760000000;
        $pending = 1760000120;
        $history = array();
        for ($id = 1; $id <= 6; $id += 1) {
            $history[] = array(
                'id' => $id,
                'time' => 100 + $id,
                'preview' => 'earlier ' . $id,
                'status' => 'closed',
                'extra' => 'drop',
            );
        }

        $context = erLhcoreClassMdMobile::visitorContext(
            array(
                'id' => 15,
                'nick' => ' Ada ',
                'email' => ' ada@example.com ',
                'phone' => ' +852 6473 1687 ',
                'city' => 'Central',
                'country_name' => 'Hong Kong',
                'country_code' => 'HK',
                'chat_locale' => 'zh_HK',
                'device_type' => 1,
                'uagent' => 'Mozilla/5.0 (iPhone)',
                'ip' => '203.0.113.8',
                'referrer' => 'https://metal-divider.com/pattern/ada',
                'session_referrer' => '//metal-divider.com/collections/screens',
                'time' => $stamp,
                'pnd_time' => $pending,
                'wait_time' => 90,
                'remarks' => "call back\nweekday",
                'dep_id' => 4,
                'online_user_id' => 9,
                'status' => 1,
            ),
            ' Sales ',
            $history
        );

        self::assertSame(array('summary', 'fields', 'history'), array_keys($context));
        self::assertSame(
            array(
                'ada@example.com',
                '+852 6473 1687',
                'Central · Hong Kong',
                'zh_HK',
                'Mobile',
                'Sales',
            ),
            $context['summary']
        );
        self::assertNotContains('Ada', $context['summary']);
        self::assertNotContains('Visitor', $context['summary']);
        self::assertNotContains(gmdate('Y-m-d H:i', $stamp), $context['summary']);
        self::assertNotContains('1m 30s', $context['summary']);
        self::assertSame(
            array(
                array('label' => 'Status', 'value' => 'Active'),
                array('label' => 'Name', 'value' => 'Ada'),
                array('label' => 'Email', 'value' => 'ada@example.com'),
                array('label' => 'Phone', 'value' => '+852 6473 1687'),
                array('label' => 'Location', 'value' => 'Central · Hong Kong'),
                array('label' => 'Language', 'value' => 'zh_HK'),
                array('label' => 'Device', 'value' => 'Mobile · Mozilla/5.0 (iPhone)'),
                array('label' => 'IP', 'value' => '203.0.113.8'),
                array(
                    'label' => 'Page',
                    'value' => 'https://metal-divider.com/pattern/ada',
                    'url' => 'https://metal-divider.com/pattern/ada',
                ),
                array(
                    'label' => 'Source',
                    'value' => '//metal-divider.com/collections/screens',
                    'url' => '//metal-divider.com/collections/screens',
                ),
                array('label' => 'Department', 'value' => 'Sales'),
                array('label' => 'Started', 'value' => gmdate('Y-m-d H:i', $stamp), 'timestamp' => $stamp),
                array('label' => 'Handoff', 'value' => gmdate('Y-m-d H:i', $pending), 'timestamp' => $pending),
                array('label' => 'Wait', 'value' => '1m 30s'),
            ),
            $context['fields']
        );
        self::assertNotContains('Remarks', array_column($context['fields'], 'label'));
        self::assertSame("call back\nweekday", erLhcoreClassMdMobile::remarksText("call back\r\nweekday"));
        self::assertCount(5, $context['history']);
        self::assertSame(
            array('id' => 1, 'time' => 101, 'preview' => 'earlier 1', 'status' => 'closed'),
            $context['history'][0]
        );
        self::assertArrayNotHasKey('extra', $context['history'][0]);
        self::assertSame(5, $context['history'][4]['id']);
        self::assertNotContains(6, array_column($context['history'], 'id'));
    }

    public function testVisitorContextOmitsEmptyUnsafeAndUnclassifiedDevice(): void
    {
        $stamp = 1760000000;
        $context = erLhcoreClassMdMobile::visitorContext(
            array(
                'nick' => '   ',
                'email' => '',
                'phone' => 'n/a',
                'city' => '',
                'country_name' => '',
                'country_code' => 'HONG',
                'chat_locale' => 'english',
                'device_type' => 0,
                'uagent' => '',
                'ip' => 'not-an-ip',
                'referrer' => 'javascript:alert(1)',
                'session_referrer' => '/pattern/ada',
                'time' => $stamp,
                'pnd_time' => $stamp,
                'wait_time' => 0,
                'remarks' => "  \n  ",
                'dep_id' => '4abc',
            ),
            '',
            array()
        );

        self::assertSame(array(), $context['summary']);
        self::assertSame(
            array(
                array('label' => 'Started', 'value' => gmdate('Y-m-d H:i', $stamp), 'timestamp' => $stamp),
            ),
            $context['fields']
        );
        self::assertSame(array(), $context['history']);
        self::assertNotContains('Visitor', $context['summary']);
        self::assertNotContains('Computer', $context['summary']);
        self::assertNotContains('Online', array_column($context['fields'], 'value'));
        self::assertNotContains('Status', array_column($context['fields'], 'label'));
        self::assertSame(0, erLhcoreClassMdMobile::departmentId(array('dep_id' => '4abc')));
        self::assertSame(4, erLhcoreClassMdMobile::departmentId(array('dep_id' => '4')));
        self::assertSame('', erLhcoreClassMdMobile::departmentName(false));
        self::assertSame('Sales', erLhcoreClassMdMobile::departmentName((object) array('name' => ' Sales ')));

        $place = erLhcoreClassMdMobile::visitorContext(
            array('city' => 'Central', 'country_code' => 'hk'),
            '',
            array()
        );
        self::assertSame(array('Central'), $place['summary']);
        $codeOnly = erLhcoreClassMdMobile::visitorContext(array('country_code' => 'hk'), '', array());
        self::assertSame(array('hk'), $codeOnly['summary']);

        $pc = erLhcoreClassMdMobile::visitorContext(
            array('device_type' => 0, 'uagent' => 'Mozilla/5.0'),
            '',
            array()
        );
        self::assertContains('Computer', $pc['summary']);
        self::assertSame('Computer · Mozilla/5.0', $pc['fields'][0]['value']);
        $tablet = erLhcoreClassMdMobile::visitorContext(array('device_type' => '2'), '', array());
        self::assertSame('Tablet', $tablet['fields'][0]['value']);
        $unknown = erLhcoreClassMdMobile::visitorContext(
            array('device_type' => '2abc', 'uagent' => 'SomeAgent'),
            '',
            array()
        );
        self::assertSame(array(), $unknown['summary']);
        self::assertSame('SomeAgent', $unknown['fields'][0]['value']);
        self::assertNotContains('Tablet', $unknown['summary']);
        self::assertNotContains('Computer', $unknown['summary']);
        self::assertSame(
            'Pending',
            erLhcoreClassMdMobile::visitorContext(array('status' => 0), '', array())['fields'][0]['value']
        );
        self::assertSame(
            'Closed',
            erLhcoreClassMdMobile::visitorContext(array('status' => 2), '', array())['fields'][0]['value']
        );
        self::assertSame(
            'Chatbox',
            erLhcoreClassMdMobile::visitorContext(array('status' => 3), '', array())['fields'][0]['value']
        );
        self::assertSame(
            'Operators',
            erLhcoreClassMdMobile::visitorContext(array('status' => 4), '', array())['fields'][0]['value']
        );
        self::assertSame(
            'Bot',
            erLhcoreClassMdMobile::visitorContext(array('status' => 5), '', array())['fields'][0]['value']
        );
        $botHistory = erLhcoreClassMdMobile::visitorContext(
            array(),
            '',
            array(array('id' => 11, 'status' => 'bot', 'time' => 12))
        );
        self::assertSame('bot', $botHistory['history'][0]['status']);
        $unknownStatus = erLhcoreClassMdMobile::visitorContext(array('status' => 9), '', array());
        self::assertSame(array(), $unknownStatus['fields']);
        self::assertNotContains('Online', array_column($unknownStatus['fields'], 'value'));

        $linked = erLhcoreClassMdMobile::visitorContext(
            array(
                'referrer' => ' http://example.com/a?q=1 ',
                'session_referrer' => 'ftp://example.com/a',
                'ip' => '2001:db8::1',
                'remarks' => "a\x00b\r\nc",
                'wait_time' => 3661,
            ),
            '',
            array()
        );
        $labels = array();
        foreach ($linked['fields'] as $field) {
            $labels[$field['label']] = $field;
        }
        self::assertSame('http://example.com/a?q=1', $labels['Page']['url']);
        self::assertArrayNotHasKey('Source', $labels);
        self::assertSame('2001:db8::1', $labels['IP']['value']);
        self::assertArrayNotHasKey('Remarks', $labels);
        self::assertSame("ab\nc", erLhcoreClassMdMobile::remarksText("a\x00b\r\nc"));
        self::assertSame('1h 1m', $labels['Wait']['value']);
        self::assertNotContains('1h 1m', $linked['summary']);
        self::assertNotContains('1 小时 1 分', $linked['summary']);

        self::assertSame('https://ok.example/a', erLhcoreClassMdMobile::sourceUrl(' https://ok.example/a '));
        self::assertSame('//ok.example/a', erLhcoreClassMdMobile::sourceUrl('//ok.example/a'));
        self::assertNull(erLhcoreClassMdMobile::sourceUrl('javascript:alert(1)'));
        self::assertNull(erLhcoreClassMdMobile::sourceUrl("java\tscript:alert(1)"));
        self::assertNull(erLhcoreClassMdMobile::sourceUrl('data:text/html,hi'));
        self::assertNull(erLhcoreClassMdMobile::sourceUrl('/pattern/ada'));
        self::assertNull(erLhcoreClassMdMobile::sourceUrl('ftp://example.com/a'));
        self::assertNull(erLhcoreClassMdMobile::sourceUrl('https://user:pass@example.com/a'));
        self::assertNull(erLhcoreClassMdMobile::sourceUrl('///example.com/a'));
        self::assertNull(erLhcoreClassMdMobile::sourceUrl('//'));
    }

    public function testHistoryQueryUsesOnlineUserBeforeEmail(): void
    {
        self::assertSame(5, erLhcoreClassMdMobile::HISTORY_LIMIT);
        self::assertSame(50, erLhcoreClassMdMobile::HISTORY_QUERY_LIMIT);
        self::assertSame(
            array(
                'filter' => array('online_user_id' => 9),
                'limit' => 50,
                'sort' => 'time DESC, id DESC',
                'filternot' => array('id' => 15),
            ),
            erLhcoreClassMdMobile::historyQuery(array(
                'id' => 15,
                'online_user_id' => 9,
                'email' => 'ada@example.com',
            ))
        );
        self::assertSame(
            array(
                'filter' => array('email' => 'ada@example.com'),
                'limit' => 50,
                'sort' => 'time DESC, id DESC',
                'filternot' => array('id' => 15),
            ),
            erLhcoreClassMdMobile::historyQuery(array(
                'id' => '15',
                'online_user_id' => 0,
                'email' => ' ada@example.com ',
            ))
        );
        self::assertNull(erLhcoreClassMdMobile::historyQuery(array(
            'id' => 15,
            'online_user_id' => 0,
            'email' => '   ',
        )));
        self::assertNull(erLhcoreClassMdMobile::historyQuery(array(
            'online_user_id' => '12abc',
            'email' => '',
        )));
        self::assertSame(
            array('email' => 'ada@example.com'),
            erLhcoreClassMdMobile::historyQuery(array(
                'online_user_id' => '0',
                'email' => 'ada@example.com',
            ))['filter']
        );
    }

    public function testVisibleHistoryDropsUnreadCurrentAndSixth(): void
    {
        $chats = array(
            (object) array('id' => 15, 'time' => 50, 'status' => 1),
            (object) array('id' => 3, 'time' => 30, 'status' => 2),
            (object) array('id' => 4, 'time' => 40, 'status' => 0),
            (object) array('id' => 8, 'time' => 80, 'status' => 1),
            (object) array('id' => 7, 'time' => 70, 'status' => 9),
            (object) array('id' => 6, 'time' => 60, 'status' => 3),
            (object) array('id' => 5, 'time' => 55, 'status' => 4),
            (object) array('id' => 2, 'time' => 20, 'status' => 2),
            (object) array('id' => 8, 'time' => 80, 'status' => 1),
        );
        $checked = array();
        $previewed = array();
        $items = erLhcoreClassMdMobile::visibleHistory(
            $chats,
            15,
            static function ($chat) use (&$checked) {
                $checked[] = (int) $chat->id;

                return (int) $chat->id !== 4;
            },
            static function ($chat) use (&$previewed) {
                $previewed[] = (int) $chat->id;

                return 'msg ' . $chat->id;
            }
        );

        self::assertSame(array(3, 4, 8, 7, 6, 5, 2), $checked);
        self::assertSame(array(8, 7, 6, 5, 3), $previewed);
        self::assertSame(array(8, 7, 6, 5, 3), array_column($items, 'id'));
        self::assertSame('active', $items[0]['status']);
        self::assertSame('msg 8', $items[0]['preview']);
        self::assertSame(80, $items[0]['time']);
        self::assertArrayNotHasKey('status', $items[1]);
        self::assertSame('chatbox', $items[2]['status']);
        self::assertSame('operators', $items[3]['status']);
        self::assertSame('closed', $items[4]['status']);
        self::assertSame(
            array('id' => 9, 'time' => 12, 'preview' => 'hi', 'status' => 'pending'),
            erLhcoreClassMdMobile::historyItem(array('id' => 9, 'time' => 12, 'status' => 0), '  hi  ')
        );
        self::assertSame(
            array('id' => 10, 'time' => 13),
            erLhcoreClassMdMobile::historyItem(array('id' => 10, 'time' => 13), null)
        );
        self::assertSame(
            array('id' => 11, 'status' => 'bot'),
            erLhcoreClassMdMobile::historyItem(array('id' => 11, 'status' => 5), null)
        );
    }

    public function testPreviewUsesTheFirstRealMessage(): void
    {
        self::assertNull(erLhcoreClassMdMobile::firstMessageQuery(0));
        self::assertNull(erLhcoreClassMdMobile::firstMessageQuery('8abc'));
        self::assertSame(
            array(
                'filter' => array('chat_id' => 8),
                'filtergte' => array('user_id' => 0),
                'limit' => 20,
                'sort' => 'id ASC',
            ),
            erLhcoreClassMdMobile::firstMessageQuery('8')
        );
        self::assertSame(
            'real line',
            erLhcoreClassMdMobile::previewFromMessages(array(
                array('id' => 1, 'user_id' => -1, 'msg' => 'joined'),
                array('id' => 2, 'user_id' => 0, 'msg' => '   '),
                array('id' => 4, 'user_id' => 9, 'msg' => "real\nline"),
                array('id' => 3, 'user_id' => -2, 'msg' => 'bot line'),
                array('id' => 5, 'user_id' => 0, 'msg' => 'later'),
                array('id' => 6, 'user_id' => '0abc', 'msg' => 'junk id'),
            ))
        );
        self::assertNull(erLhcoreClassMdMobile::previewFromMessages(array(
            array('id' => 1, 'user_id' => -1, 'msg' => 'only system'),
            array('id' => 2, 'user_id' => -2, 'msg' => 'only bot'),
        )));
        self::assertSame(
            str_repeat('测', 120) . '…',
            erLhcoreClassMdMobile::previewFromMessages(array(
                array('id' => 1, 'user_id' => 0, 'msg' => str_repeat('测', 121)),
            ))
        );
    }

    public function testTemplateBagCarriesContextWithoutChangingMessages(): void
    {
        $urls = erLhcoreClassMdMobile::endpointUrls('https://lhc.test/index.php/site_admin/', 15);
        $payload = erLhcoreClassMdMobile::format(
            array(
                array('id' => 6, 'user_id' => 0, 'msg' => 'hi', 'time' => 1),
            ),
            0
        );
        $empty = erLhcoreClassMdMobile::templateBag(15, $payload, $urls, 'csrf-token', ' Ada ');
        self::assertSame('Ada', $empty['nick']);
        self::assertSame('Ada', $empty['title']);
        self::assertSame('operator', $empty['sender']);
        self::assertSame($payload['messages'], $empty['messages']);
        self::assertSame(
            array('summary' => array(), 'fields' => array(), 'history' => array()),
            $empty['context']
        );

        $context = erLhcoreClassMdMobile::visitorContext(
            array('email' => 'ada@example.com'),
            '',
            array()
        );
        $bag = erLhcoreClassMdMobile::templateBag(
            15,
            $payload,
            $urls,
            'csrf-token',
            '',
            array(
                'summary' => array(' x ', ''),
                'fields' => array(
                    array(
                        'label' => 'Page',
                        'value' => 'https://ok.example/a',
                        'url' => 'javascript:alert(1)',
                    ),
                ),
                'history' => array(
                    array('id' => 3, 'time' => 10, 'preview' => ' hi ', 'status' => 'closed', 'extra' => 'no'),
                    array('id' => 0, 'status' => 'pending'),
                ),
            )
        );
        self::assertArrayNotHasKey('title', $bag);
        self::assertSame(array('x'), $bag['context']['summary']);
        self::assertSame(
            array(
                array('label' => 'Page', 'value' => 'https://ok.example/a'),
            ),
            $bag['context']['fields']
        );
        self::assertSame(
            array(
                array('id' => 3, 'time' => 10, 'preview' => 'hi', 'status' => 'closed'),
            ),
            $bag['context']['history']
        );
        self::assertSame($payload['messages'], $bag['messages']);
        self::assertSame(array('id', 'kind', 'text', 'time'), array_keys($bag['messages'][0]));
        self::assertSame('ada@example.com', $context['fields'][0]['value']);

        $timed = erLhcoreClassMdMobile::templateBag(
            15,
            $payload,
            $urls,
            'csrf-token',
            '',
            array(
                'fields' => array(
                    array(
                        'label' => 'Started',
                        'value' => '2026-10-09 08:53',
                        'timestamp' => 1760000000,
                        'extra' => 'no',
                    ),
                    array(
                        'label' => 'Handoff',
                        'value' => '2026-10-09 08:55',
                        'timestamp' => '1760000120',
                    ),
                    array(
                        'label' => 'Wait',
                        'value' => '1h 1m',
                        'timestamp' => 0,
                    ),
                ),
            )
        );
        self::assertSame(
            array(
                array('label' => 'Started', 'value' => '2026-10-09 08:53', 'timestamp' => 1760000000),
                array('label' => 'Handoff', 'value' => '2026-10-09 08:55', 'timestamp' => 1760000120),
                array('label' => 'Wait', 'value' => '1h 1m'),
            ),
            $timed['context']['fields']
        );
        self::assertSame($payload['messages'], $timed['messages']);
    }

    public function testRemarksTextKeepsMultilineAndBoundsLength(): void
    {
        self::assertNull(erLhcoreClassMdMobile::remarksText(null));
        self::assertNull(erLhcoreClassMdMobile::remarksText(array('notes')));
        self::assertNull(erLhcoreClassMdMobile::remarksText(" \n\t "));
        self::assertSame('a b', erLhcoreClassMdMobile::remarksText(" a\x01 b "));
        self::assertSame(
            str_repeat('x', 1000) . '…',
            erLhcoreClassMdMobile::remarksText(str_repeat('x', 1200))
        );
    }

    public function testCannedGroupsNormalizesNativeMessages(): void
    {
        $groups = erLhcoreClassMdMobile::cannedGroups(
            array(
                '0_4' => array(
                    array('id' => 9, 'msg_to_user' => "Hello {nick}\nHow can I help?", 'title' => 'Greeting'),
                    array('id' => 11, 'msg_to_user' => "First line\nsecond line\n[html_snippet]3[/html_snippet]", 'title' => ''),
                    array('id' => 0, 'msg_to_user' => 'junk id'),
                    array('id' => 12, 'msg_to_user' => "  \n  "),
                    array('id' => 13, 'msg_to_user' => 'No title here'),
                ),
                '1_7' => array(
                    array('id' => 21, 'msg_to_user' => ' Mine ', 'title' => 'Mine'),
                ),
                '2_0' => array(
                    array('id' => 31, 'msg_to_user' => "Multi\r\nline", 'title' => 'Global one'),
                ),
                'junk' => array(array('id' => 41, 'msg_to_user' => 'ignored')),
                '3_9' => array(array('id' => 51, 'msg_to_user' => 'unknown type')),
            ),
            ' Sales '
        );

        self::assertCount(3, $groups);
        self::assertSame(array('label', 'items'), array_keys($groups[0]));
        self::assertSame('Sales', $groups[0]['label']);
        self::assertSame('Personal', $groups[1]['label']);
        self::assertSame('Global', $groups[2]['label']);
        self::assertSame(
            array(
                array('id' => 9, 'title' => 'Greeting', 'text' => "Hello {nick}\nHow can I help?"),
                array('id' => 11, 'title' => null, 'text' => "First line\nsecond line"),
                array('id' => 13, 'title' => null, 'text' => 'No title here'),
            ),
            $groups[0]['items']
        );
        self::assertSame(
            array(array('id' => 21, 'title' => 'Mine', 'text' => 'Mine')),
            $groups[1]['items']
        );
        self::assertSame(
            array(array('id' => 31, 'title' => 'Global one', 'text' => "Multi\nline")),
            $groups[2]['items']
        );

        $unnamed = erLhcoreClassMdMobile::cannedGroups(
            array('0_4' => array(array('id' => 9, 'msg_to_user' => 'Hi'))),
            ''
        );
        self::assertSame('Department', $unnamed[0]['label']);
        self::assertSame(array(), erLhcoreClassMdMobile::cannedGroups(array()));
        self::assertSame(array(), erLhcoreClassMdMobile::cannedGroups('nope'));
    }
}
