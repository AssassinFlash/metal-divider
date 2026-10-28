<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/design/mdmobiletheme/tpl/lhmdmobile/helpers.php';

final class MdMobileCloseTest extends TestCase
{
    public function testEndpointUrlsCarryTheNativeChangeStatusAddress(): void
    {
        $urls = erLhcoreClassMdMobile::endpointUrls('https://lhc.test/index.php/site_admin/', 15);

        self::assertSame(
            'https://lhc.test/index.php/site_admin/chat/changestatus/15',
            $urls['close_url']
        );
        self::assertSame(
            'https://lhc.test/index.php/site_admin/mdmobile/messages/15',
            $urls['messages_url']
        );

        $bag = erLhcoreClassMdMobile::templateBag(
            15,
            erLhcoreClassMdMobile::format(array(), 0),
            $urls,
            'csrf-token'
        );

        self::assertSame($urls['close_url'], $bag['close_url']);
        self::assertArrayNotHasKey('status', $bag);
    }

    public function testBagKeepsOnlyTheChatsOwnChangeStatusPath(): void
    {
        $urls = erLhcoreClassMdMobile::endpointUrls('https://lhc.test/index.php/site_admin/', 15);
        $payload = erLhcoreClassMdMobile::format(array(), 0);

        foreach (array(
            'https://lhc.test/index.php/site_admin/chat/changestatus/16',
            'https://lhc.test/index.php/site_admin/chat/changestatus/0',
            'https://lhc.test/index.php/site_admin/chat/changestatus/15?x=1',
            'https://lhc.test/index.php/site_admin/chat/changestatus/15/../16',
            'javascript:alert(1)',
        ) as $foreign) {
            $urls['close_url'] = $foreign;
            $bag = erLhcoreClassMdMobile::templateBag(15, $payload, $urls, 'csrf-token');
            self::assertArrayNotHasKey('close_url', $bag, $foreign . ' must not become a close target');
        }

        $urls['close_url'] = 'https://lhc.test/index.php/site_admin/chat/changestatus/15';
        $bag = erLhcoreClassMdMobile::templateBag(15, $payload, $urls, 'csrf-token');
        self::assertSame($urls['close_url'], $bag['close_url']);
    }

    public function testClosedStatusReadsTheNativeClosedCode(): void
    {
        self::assertTrue(erLhcoreClassMdMobile::closedStatus(2));
        self::assertTrue(erLhcoreClassMdMobile::closedStatus('2'));
        self::assertFalse(erLhcoreClassMdMobile::closedStatus(1));
        self::assertFalse(erLhcoreClassMdMobile::closedStatus('1'));
        self::assertFalse(erLhcoreClassMdMobile::closedStatus('02'));
        self::assertFalse(erLhcoreClassMdMobile::closedStatus(2.0));
        self::assertFalse(erLhcoreClassMdMobile::closedStatus(true));
        self::assertFalse(erLhcoreClassMdMobile::closedStatus(null));
    }

    public function testTemplateRendersTheTwoStepCloseForAnOpenChat(): void
    {
        $html = $this->render($this->bag(array('allow_close_remote' => true)));

        self::assertStringContainsString('data-close-url="/site_admin/chat/changestatus/15"', $html);
        self::assertStringContainsString('data-md-close-trigger', $html);
        self::assertStringContainsString('data-md-close-panel', $html);
        self::assertStringContainsString('id="md-close-question-md-composer"', $html);
        self::assertStringContainsString('role="dialog" aria-modal="false" aria-labelledby="md-close-question-md-composer" tabindex="-1"', $html);
        self::assertStringContainsString('Close this conversation? It will show as closed in the chat list.', $html);
        self::assertStringContainsString('>Close conversation</button>', $html);
        self::assertStringContainsString('>Cancel</button>', $html);
        self::assertStringContainsString('data-allow-close-remote="1"', $html);
        self::assertStringContainsString('data-allow-open-closed="1"', $html);
        self::assertStringContainsString('data-can-write="1"', $html);
        self::assertStringNotContainsString('data-state="closed"', $html);
        self::assertStringContainsString('<p class="md-close-note" data-md-close-note hidden>Conversation closed.</p>', $html);
        self::assertSame(0, preg_match('/<textarea id="md-draft"[^>]*disabled/', $html));
        self::assertSame(0, preg_match('/<button type="button" id="md-send"[^>]*disabled/', $html));
    }

    public function testTemplateRendersTheClosedStateOnReload(): void
    {
        $html = $this->render($this->bag(array(
            'status' => 2,
            'allow_open_closed' => false,
            'can_close' => false,
            'can_write' => false,
            'canned' => array(array(
                'label' => '',
                'items' => array(array('id' => 3, 'text' => 'Thanks')),
            )),
        )));

        self::assertStringContainsString('data-state="closed"', $html);
        self::assertSame(1, preg_match('/<textarea id="md-draft"[^>]*disabled/', $html));
        self::assertSame(1, preg_match('/<button type="button" id="md-send"[^>]*disabled/', $html));
        self::assertStringNotContainsString('id="md-quick"', $html);
        self::assertStringNotContainsString('md-canned-sheet', $html);
        self::assertStringContainsString('<p class="md-close-note" data-md-close-note>Conversation closed.</p>', $html);
        self::assertStringContainsString('aria-controls="md-close-panel-md-composer" hidden>Close conversation</button>', $html);
        self::assertStringContainsString('id="md-chat-status" data-md-chat-status="1" data-status-code="2" data-tone="offline"', $html);
        self::assertStringContainsString('>Closed</span>', $html);
        self::assertStringContainsString('id="md-remarks-md-drawer"', $html);
        self::assertStringContainsString('placeholder="Private remarks. Visitors never see these." disabled>', $html);
        self::assertStringContainsString('data-can-write="0"', $html);
        self::assertStringContainsString('Read-only after closing: this account cannot edit remarks on a closed conversation.', $html);
    }

    public function testClosedChatKeepsRemarksEditableOnlyWithOpenAndWriteRights(): void
    {
        $html = $this->render($this->bag(array(
            'status' => 2,
            'allow_open_closed' => true,
            'can_close' => false,
            'can_write' => true,
        )));

        self::assertStringContainsString('data-state="closed"', $html);
        self::assertStringContainsString('data-can-write="1"', $html);
        self::assertStringContainsString('id="md-remarks-md-drawer"', $html);
        self::assertStringNotContainsString('Read-only after closing', $html);
        self::assertSame(0, preg_match('/<textarea id="md-remarks-md-drawer"[^>]*disabled/', $html));
    }

    public function testClosedChatDisablesRemarksWithoutNativeWriteAccess(): void
    {
        $html = $this->render($this->bag(array(
            'status' => 2,
            'allow_open_closed' => true,
            'can_close' => false,
            'can_write' => false,
        )));

        self::assertStringContainsString('data-state="closed"', $html);
        self::assertStringContainsString('data-can-write="0"', $html);
        self::assertSame(1, preg_match('/<textarea id="md-remarks-md-drawer"[^>]*disabled/', $html));
        self::assertStringContainsString('Read-only after closing: this account cannot edit remarks on a closed conversation.', $html);
    }

    public function testTemplateOmitsTheCloseAffordanceWithoutANativeAddress(): void
    {
        $bag = $this->bag();
        unset($bag['close_url']);
        $html = $this->render($bag);

        self::assertStringNotContainsString('data-close-url', $html);
        self::assertStringNotContainsString('Close this conversation?', $html);
        self::assertStringNotContainsString('data-md-close-trigger', $html);
    }

    public function testTemplateOmitsTheCloseAffordanceWhenTheOperatorCannotClose(): void
    {
        $html = $this->render($this->bag(array('can_close' => false, 'allow_close_remote' => false)));

        self::assertStringNotContainsString('data-close-url', $html);
        self::assertStringNotContainsString('Close this conversation?', $html);
    }

    public function testTemplateIgnoresACloseTargetWithoutAChat(): void
    {
        $html = $this->render($this->bag(array('chat_id' => 0)));

        self::assertStringNotContainsString('data-close-url', $html);
        self::assertStringNotContainsString('Close this conversation?', $html);
    }

    public function testRemarksRendererFollowsTheReadOnlyFlag(): void
    {
        $editable = mdmobile_render_remarks(array(
            'value' => 'note',
            'url' => '/site_admin/chat/saveremarks/15',
        ), 'md-drawer');

        self::assertStringContainsString('placeholder="Private remarks. Visitors never see these.">note</textarea>', $editable);
        self::assertStringNotContainsString('Read-only after closing', $editable);
        self::assertStringNotContainsString(' disabled>', $editable);

        $readOnly = mdmobile_render_remarks(array(
            'value' => 'note',
            'url' => '/site_admin/chat/saveremarks/15',
            'disabled' => true,
        ), 'md-drawer');

        self::assertStringContainsString('placeholder="Private remarks. Visitors never see these." disabled>note</textarea>', $readOnly);
        self::assertStringContainsString('Read-only after closing: this account cannot edit remarks on a closed conversation.', $readOnly);
    }

    public function testContextColumnCarriesItsOwnCloseAffordance(): void
    {
        $open = mdmobile_render_context(
            array('fields' => array(), 'history' => array()),
            true,
            array('value' => '', 'url' => '/site_admin/chat/saveremarks/15'),
            array('url' => '/site_admin/chat/changestatus/15', 'closed' => false, 'can_close' => true)
        );

        self::assertStringContainsString('id="md-context"', $open);
        self::assertStringContainsString('data-close-url="/site_admin/chat/changestatus/15"', $open);
        self::assertStringContainsString('id="md-close-panel-md-context"', $open);
        self::assertStringContainsString('Close this conversation? It will show as closed in the chat list.', $open);
        self::assertStringNotContainsString('No visitor details', $open);

        $closed = mdmobile_render_context(
            array('fields' => array(), 'history' => array()),
            true,
            array('value' => '', 'url' => '/site_admin/chat/saveremarks/15', 'disabled' => true),
            array('url' => '/site_admin/chat/changestatus/15', 'closed' => true, 'can_close' => false)
        );

        self::assertStringContainsString('Conversation closed.', $closed);
        self::assertStringNotContainsString('No visitor details', $closed);
        self::assertStringContainsString('Read-only after closing', $closed);
    }

    public function testContextColumnRendersInsideTheDeskLayout(): void
    {
        $bag = $this->bag();
        $bag['inbox'] = array(
            'items' => array(array('id' => 15, 'nick' => 'Visitor', 'time' => 1, 'status' => 'Active')),
            'list_url' => '/site_admin/mdmobile/chats',
        );
        $html = $this->render($bag);

        self::assertStringContainsString('class="md-app md-has-context"', $html);
        self::assertStringContainsString('id="md-context"', $html);
        self::assertStringContainsString('id="md-close-panel-md-context"', $html);
        self::assertStringContainsString('data-close-url="/site_admin/chat/changestatus/15"', $html);
    }

    /**
     * @param array<string, mixed> $bag
     * @param array<string, mixed> $extra
     */
    private function render(array $bag, array $extra = array()): string
    {
        $vars = array_merge(array('md' => $bag), $extra);
        extract($vars, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__) . '/design/mdmobiletheme/tpl/lhmdmobile/chat.tpl.php';
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function bag(array $overrides = array()): array
    {
        return array_merge(array(
            'chat_id' => 15,
            'after_id' => 6,
            'latest_id' => 6,
            'messages_url' => '/site_admin/mdmobile/messages/15',
            'send_url' => '/site_admin/chat/addmsgadmin/15',
            'csrf' => 'csrf-token',
            'sender' => 'operator',
            'messages' => array(
                array('id' => 6, 'kind' => 'visitor', 'text' => 'hi', 'time' => 1),
            ),
            'context' => array('fields' => array(), 'history' => array()),
            'remarks' => '',
            'remarks_url' => '/site_admin/chat/saveremarks/15',
            'close_url' => '/site_admin/chat/changestatus/15',
            'status' => 1,
            'allow_close_remote' => false,
            'allow_open_closed' => true,
            'can_close' => true,
            'can_write' => true,
        ), $overrides);
    }
}
