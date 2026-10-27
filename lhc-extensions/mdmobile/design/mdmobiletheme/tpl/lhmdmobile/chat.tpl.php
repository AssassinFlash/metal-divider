<?php
require_once class_exists('erLhcoreClassDesign')
    ? erLhcoreClassDesign::designtpl('lhmdmobile/helpers.php')
    : __DIR__ . '/helpers.php';

if ((!isset($Result) || !is_array($Result)) && isset($GLOBALS['Result']) && is_array($GLOBALS['Result'])) {
    $Result = $GLOBALS['Result'];
}

$mdSet = array();
foreach (array('chat_id', 'after_id', 'latest_id', 'messages_url', 'send_url', 'csrf', 'csrf_token', 'sender', 'messages', 'title', 'nick', 'context', 'read_url', 'list_url', 'remarks', 'remarks_url', 'canned', 'close_url', 'status', 'allow_close_remote', 'allow_open_closed', 'can_close', 'can_write') as $mdName) {
    if (isset(${$mdName})) {
        $mdSet[$mdName] = ${$mdName};
    }
}
$mdBag = (isset($md) && is_array($md)) ? $md : array();
$mdResult = (isset($Result) && is_array($Result)) ? $Result : array();
if ($mdBag === array() && isset($mdResult['mdmobile']) && is_array($mdResult['mdmobile'])) {
    $mdBag = $mdResult['mdmobile'];
}

$mdSources = array($mdSet, $mdBag, $mdResult);
$mdChatId = mdmobile_pick($mdSources, array('chat_id'));
$mdAfter = mdmobile_pick($mdSources, array('after_id', 'latest_id'));
$mdMessagesUrl = mdmobile_pick($mdSources, array('messages_url'));
$mdSendUrl = mdmobile_pick($mdSources, array('send_url'));
$mdCsrf = mdmobile_pick($mdSources, array('csrf', 'csrf_token'));
$mdSender = mdmobile_pick($mdSources, array('sender'));
$mdNick = mdmobile_pick($mdSources, array('nick'));
$mdTitle = mdmobile_pick($mdSources, array('title'));
$mdMessages = mdmobile_pick($mdSources, array('messages'));
$mdContext = mdmobile_pick($mdSources, array('context'));
if ($mdContext === null && isset($mdResult['mdmobile']) && is_array($mdResult['mdmobile']) && array_key_exists('context', $mdResult['mdmobile'])) {
    $mdContext = $mdResult['mdmobile']['context'];
}
if (!is_array($mdMessages)) {
    $mdMessages = array();
}
if (!is_string($mdMessagesUrl)) {
    $mdMessagesUrl = '';
}
if (!is_string($mdSendUrl)) {
    $mdSendUrl = '';
}
if (!is_string($mdCsrf)) {
    $mdCsrf = '';
}
if (!is_string($mdSender) || $mdSender === '') {
    $mdSender = 'operator';
}
$mdNick = mdmobile_text($mdNick);
if ($mdNick === '') {
    $mdNick = mdmobile_text($mdTitle);
}
if ($mdNick === '对话' || strcasecmp($mdNick, 'Visitor') === 0) {
    $mdNick = '';
}

$mdAfterId = is_numeric($mdAfter) ? (int) $mdAfter : 0;
$mdRows = array();
foreach ($mdMessages as $mdRow) {
    if (!is_array($mdRow) && !is_object($mdRow)) {
        continue;
    }
    $mdId = mdmobile_field($mdRow, 'id');
    $mdText = mdmobile_field($mdRow, 'text');
    if (!is_numeric($mdId) || (int) $mdId < 1 || $mdText === null || trim((string) $mdText) === '') {
        continue;
    }
    $mdId = (int) $mdId;
    if ($mdId > $mdAfterId) {
        $mdAfterId = $mdId;
    }
    $mdKind = mdmobile_kind(mdmobile_field($mdRow, 'kind'));
    $mdTime = mdmobile_field($mdRow, 'time');
    $mdRows[] = array(
        'id' => $mdId,
        'kind' => $mdKind,
        'text' => (string) $mdText,
        'time' => mdmobile_time_label($mdTime),
        'datetime' => mdmobile_stamp($mdTime) > 0 ? gmdate('c', mdmobile_stamp($mdTime)) : '',
        'timestamp' => mdmobile_stamp($mdTime),
    );
}

$mdKindLabel = array(
    'visitor' => 'Visitor',
    'operator' => 'Operator',
    'system' => 'System',
    'bot' => 'Bot',
);
$mdRemarks = mdmobile_pick($mdSources, array('remarks'));
$mdRemarksUrl = mdmobile_pick($mdSources, array('remarks_url'));
$mdCanned = mdmobile_pick($mdSources, array('canned'));
$mdCloseUrl = mdmobile_app_url(mdmobile_pick($mdSources, array('close_url')));
$mdStatus = mdmobile_pick($mdSources, array('status'));
$mdAllowCloseRemote = mdmobile_bool_flag(mdmobile_pick($mdSources, array('allow_close_remote'))) === true;
$mdAllowOpenClosed = mdmobile_bool_flag(mdmobile_pick($mdSources, array('allow_open_closed'))) === true;
$mdCanClose = mdmobile_bool_flag(mdmobile_pick($mdSources, array('can_close'))) === true;
$mdCanWrite = mdmobile_bool_flag(mdmobile_pick($mdSources, array('can_write'))) === true;
$mdStatusLabel = mdmobile_status_label($mdStatus);
$mdClosed = $mdStatusLabel === 'Closed';
$mdView = mdmobile_view($mdContext);
$mdPills = array();
if ($mdStatusLabel !== '') {
    $mdPills[] = array('text' => $mdStatusLabel, 'tone' => mdmobile_status_tone($mdStatusLabel), 'chat' => true);
}
foreach ($mdView['statuses'] as $mdStatusRow) {
    $mdStatusText = isset($mdStatusRow['text']) ? mdmobile_text($mdStatusRow['text']) : '';
    if ($mdStatusText === '') {
        continue;
    }
    if ($mdStatusLabel !== '' && strcasecmp($mdStatusText, $mdStatusLabel) === 0) {
        continue;
    }
    $mdPills[] = array('text' => $mdStatusText, 'tone' => isset($mdStatusRow['tone']) ? $mdStatusRow['tone'] : 'neutral', 'chat' => false);
}
$mdChatAttr = (is_numeric($mdChatId) && (int) $mdChatId > 0) ? (string) (int) $mdChatId : '';
$mdRemarksHtml = $mdChatAttr !== ''
    ? mdmobile_render_remarks(
        array(
            'value' => is_string($mdRemarks) ? $mdRemarks : '',
            'url' => is_string($mdRemarksUrl) ? $mdRemarksUrl : '',
            'disabled' => $mdClosed && (!$mdAllowOpenClosed || !$mdCanWrite),
        ),
        'md-drawer'
    )
    : '';
$mdCannedHtml = $mdChatAttr !== '' ? mdmobile_render_canned($mdCanned) : '';
$mdCloseHtml = $mdChatAttr !== '' ? mdmobile_render_close($mdCloseUrl, $mdClosed, $mdCanClose, 'md-composer') : '';
if ($mdCloseHtml !== '') {
    $mdCloseHtml = '<div class="md-close-composer">' . $mdCloseHtml . '</div>';
}
$mdDrawer = $mdView['drawer'] || $mdRemarksHtml !== '';
$mdHasNick = $mdNick !== '';
$mdHasGlance = $mdView['chips'] !== array() || is_array($mdView['source']);
$mdInboxRaw = null;
if (isset($mdmobile_inbox) && is_array($mdmobile_inbox)) {
    $mdInboxRaw = $mdmobile_inbox;
} elseif (isset($inbox) && is_array($inbox)) {
    $mdInboxRaw = $inbox;
} elseif (isset($mdResult['mdmobile_inbox']) && is_array($mdResult['mdmobile_inbox'])) {
    $mdInboxRaw = $mdResult['mdmobile_inbox'];
} elseif (isset($mdBag['inbox']) && is_array($mdBag['inbox'])) {
    $mdInboxRaw = $mdBag['inbox'];
}
$mdHasInbox = is_array($mdInboxRaw);
$mdInbox = $mdHasInbox ? mdmobile_inbox_normalize($mdInboxRaw) : null;
$mdChatsUrl = mdmobile_known_chats_url($mdHasInbox ? $mdInbox['chats_url'] : '', is_string($mdMessagesUrl) ? $mdMessagesUrl : '');
$mdListUrl = $mdHasInbox ? $mdInbox['list_url'] : '';
if ($mdListUrl === '') {
    $mdListUrl = mdmobile_app_url(mdmobile_pick($mdSources, array('list_url')));
}
$mdReadUrl = mdmobile_known_read_url(
    array(
        mdmobile_pick($mdSources, array('read_url')),
        $mdHasInbox ? $mdInbox['read_url'] : '',
    ),
    $mdChatAttr,
    $mdHasInbox ? $mdInbox['items'] : array()
);
$mdShowApp = !$mdHasInbox || $mdChatAttr !== '' || $mdMessagesUrl !== '' || $mdSendUrl !== '';
if ($mdHasInbox && !$mdShowApp) {
    echo mdmobile_render_chats_page($mdInbox, is_string($mdMessagesUrl) ? $mdMessagesUrl : '');
    return;
}
$mdBack = mdmobile_back_link($mdListUrl !== '' ? $mdListUrl : $mdChatsUrl);
$mdReadAttr = $mdReadUrl !== '' ? ' data-read-url="' . mdmobile_esc($mdReadUrl) . '"' : '';
$mdCloseAttr = ($mdCloseHtml !== '' && $mdChatAttr !== '' && $mdCloseUrl !== '') ? ' data-close-url="' . mdmobile_esc($mdCloseUrl) . '"' : '';
$mdStateAttr = $mdClosed ? ' data-state="closed"' : '';
$mdAppClass = 'md-app' . ($mdHasInbox ? ' md-has-context' : '');
if ($mdHasInbox) {
    echo mdmobile_desk_open('chat', $mdChatAttr, $mdListUrl, $mdReadUrl);
    echo mdmobile_render_rail($mdInbox, $mdChatAttr, 'chat', is_string($mdMessagesUrl) ? $mdMessagesUrl : '');
    echo '<div class="md-stage">';
}
?><div id="md-mobile-app" class="<?php echo $mdAppClass; ?>" data-chat-id="<?php echo mdmobile_esc($mdChatAttr); ?>" data-after-id="<?php echo mdmobile_esc($mdAfterId); ?>" data-messages-url="<?php echo mdmobile_esc($mdMessagesUrl); ?>" data-send-url="<?php echo mdmobile_esc($mdSendUrl); ?>" data-csrf="<?php echo mdmobile_esc($mdCsrf); ?>" data-sender="<?php echo mdmobile_esc($mdSender); ?>" data-allow-close-remote="<?php echo $mdAllowCloseRemote ? '1' : '0'; ?>" data-allow-open-closed="<?php echo $mdAllowOpenClosed ? '1' : '0'; ?>" data-can-write="<?php echo $mdCanWrite ? '1' : '0'; ?>"<?php echo $mdReadAttr; ?><?php echo $mdCloseAttr; ?><?php echo $mdStateAttr; ?>>
<header class="md-top" data-md-backdrop>
<?php echo $mdBack; ?>
<div class="md-identity<?php echo $mdHasNick ? ' is-named' : ''; ?>">
<div class="md-title-row">
<h1 class="md-title">Visitor<?php if (!$mdHasNick && $mdChatAttr !== '') : ?> #<?php echo mdmobile_esc($mdChatAttr); ?><?php endif; ?></h1>
<?php if ($mdPills !== array()) : ?>
<p class="md-pills">
<?php foreach ($mdPills as $mdPill) : ?>
<span class="md-pill<?php echo $mdPill['chat'] ? ' md-pill-chat' : ''; ?>"<?php echo $mdPill['chat'] ? ' id="md-chat-status" data-md-chat-status="1"' : ''; ?> data-tone="<?php echo mdmobile_esc($mdPill['tone']); ?>"><span class="md-dot" aria-hidden="true"></span><?php echo mdmobile_esc($mdPill['text']); ?></span>
<?php endforeach; ?>
</p>
<?php endif; ?>
</div>
<?php if ($mdHasNick) : ?>
<p class="md-name"><?php echo mdmobile_esc($mdNick); ?></p>
<?php endif; ?>
</div>
<?php if ($mdDrawer) : ?>
<button type="button" id="md-details" class="md-details" aria-expanded="false" aria-controls="md-drawer" aria-haspopup="dialog">Details</button>
<?php endif; ?>
</header>
<?php if ($mdHasGlance) : ?>
<div class="md-glance" data-md-backdrop>
<?php if ($mdView['chips'] !== array()) : ?>
<ul class="md-chips" aria-label="Visitor summary">
<?php foreach ($mdView['chips'] as $mdChip) : ?>
<li class="md-chip"><?php echo mdmobile_esc($mdChip); ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (is_array($mdView['source'])) : ?>
<p class="md-source">
<span class="md-source-label"><?php echo mdmobile_esc($mdView['source']['label']); ?></span>
<?php if ($mdView['source']['value'] !== $mdView['source']['href']) : ?>
<span class="md-source-value"><?php echo mdmobile_esc($mdView['source']['value']); ?></span>
<?php endif; ?>
<a class="md-source-link" href="<?php echo mdmobile_esc($mdView['source']['href']); ?>" target="_blank" rel="noopener noreferrer"><?php echo mdmobile_esc($mdView['source']['href']); ?><span class="md-sr"> (opens in a new window)</span></a>
</p>
<?php endif; ?>
</div>
<?php endif; ?>
<div id="md-messages" class="md-messages" data-md-backdrop role="log" aria-relevant="additions" aria-label="Messages">
<?php if ($mdRows === array()) : ?>
<p id="md-empty" class="md-empty">No messages yet</p>
<?php else : ?>
<?php foreach ($mdRows as $mdItem) : ?>
<div class="md-row md-row-<?php echo mdmobile_esc($mdItem['kind']); ?>" data-id="<?php echo mdmobile_esc($mdItem['id']); ?>" data-kind="<?php echo mdmobile_esc($mdItem['kind']); ?>">
<div class="md-bubble">
<?php if ($mdItem['kind'] === 'bot') : ?>
<span class="md-kicker"><?php echo mdmobile_esc($mdKindLabel['bot']); ?></span>
<?php else : ?>
<span class="md-sr"><?php echo mdmobile_esc($mdKindLabel[$mdItem['kind']]); ?></span>
<?php endif; ?>
<p class="md-text"><?php echo mdmobile_esc($mdItem['text']); ?></p>
<?php if ($mdItem['time'] !== '') : ?>
<?php echo mdmobile_time_html($mdItem['time'], $mdItem['timestamp'], 'time', 'md-time'); ?>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<div class="md-composer" data-md-backdrop>
<?php echo $mdCloseHtml; ?>
<p id="md-status" class="md-status" role="status" aria-live="polite" aria-atomic="true"></p>
<div class="md-compose-row">
<?php if ($mdCannedHtml !== '' && !$mdClosed) : ?>
<button type="button" id="md-quick" class="md-quick" aria-label="Quick replies" aria-expanded="false" aria-controls="md-canned-dialog" aria-haspopup="dialog"><svg class="md-quick-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h11M4 18h7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
<?php endif; ?>
<label class="md-sr" for="md-draft">Type a message</label>
<textarea id="md-draft" class="md-draft" rows="1" placeholder="Type a message" autocomplete="off" autocapitalize="sentences" enterkeyhint="enter"<?php echo $mdClosed ? ' disabled' : ''; ?>></textarea>
<button type="button" id="md-send" class="md-send"<?php echo $mdClosed ? ' disabled' : ''; ?>>Send</button>
</div>
</div>
<?php echo $mdClosed ? '' : $mdCannedHtml; ?>
<?php if ($mdDrawer) : ?>
<div id="md-sheet" class="md-sheet" hidden>
<div id="md-mask" class="md-mask" aria-hidden="true"></div>
<div id="md-drawer" class="md-drawer" role="dialog" aria-modal="true" aria-labelledby="md-drawer-title" tabindex="-1">
<div class="md-drawer-bar" aria-hidden="true"></div>
<div class="md-drawer-head">
<h2 id="md-drawer-title" class="md-drawer-title">Visitor details</h2>
<button type="button" id="md-drawer-close" class="md-drawer-close">Close</button>
</div>
<div class="md-drawer-body">
<?php echo mdmobile_render_fields($mdView['fields']); ?>
<?php echo $mdRemarksHtml; ?>
<?php echo mdmobile_render_history($mdView['history'], 'md-history-title'); ?>
</div>
</div>
</div>
<?php endif; ?>
</div>
<?php
if ($mdHasInbox) {
    echo '</div>';
    echo mdmobile_render_context(
        $mdView,
        true,
        array(
            'value' => is_string($mdRemarks) ? $mdRemarks : '',
            'url' => is_string($mdRemarksUrl) ? $mdRemarksUrl : '',
            'disabled' => $mdClosed && (!$mdAllowOpenClosed || !$mdCanWrite),
        ),
        array(
            'url' => $mdCloseUrl,
            'closed' => $mdClosed,
            'can_close' => $mdCanClose,
        )
    );
    echo '</div>';
}
?>
