<link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim(erLhcoreClassSystem::instance()->wwwDir(), '/') . '/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor-chat.css?v=20261028s', ENT_QUOTES, 'UTF-8'); ?>" />
<div class="row" >
    <div class="col-12">

        <?php
        $mdvStatus = (int)$chat->status;
        $mdvLabel = 'AI assistant';
        $mdvTone = 'ai';
        if ($mdvStatus === erLhcoreClassModelChat::STATUS_PENDING_CHAT) { $mdvLabel = 'Connecting you to the team'; $mdvTone = 'pending'; }
        elseif ($mdvStatus === erLhcoreClassModelChat::STATUS_ACTIVE_CHAT) { $mdvLabel = 'Team online'; $mdvTone = 'online'; }
        elseif ($mdvStatus === erLhcoreClassModelChat::STATUS_CLOSED_CHAT) { $mdvLabel = 'Chat closed'; $mdvTone = 'closed'; }
        ?>
        <div class="mdv-chat-head">
            <div class="mdv-chat-id">
                <span class="mdv-chat-avatar">MD<i></i></span>
                <div class="mdv-chat-name"><strong>Metal Divider AI</strong><small><span class="mdv-chat-state mdv-chat-state-<?php echo $mdvTone;?>"><i></i><?php echo htmlspecialchars($mdvLabel);?></span></small></div>
            </div>
            <button class="mdv-chat-close" type="button" aria-label="Close chat" onclick="(function(){var t='*';try{if(document.referrer)t=new URL(document.referrer).origin}catch(e){}if(window.parent!==window)window.parent.postMessage({source:'mdvisitor',type:'mdvisitor-close'},t);})()">×</button>
        </div>
        <div id="status-chat">
            <?php if (!($chat->status == erLhcoreClassModelChat::STATUS_BOT_CHAT && $chat->bot !== null && isset($chat->bot->configuration_array['profile_hide']) && $chat->bot->configuration_array['profile_hide'] == true)) : ?>
                <?php if ($chat->status == erLhcoreClassModelChat::STATUS_CLOSED_CHAT) : ?>
                    <h6 class="fs12"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/chat','This chat is closed.'); ?></h6>
                <?php elseif (($user = $chat->user) !== false) : ?>
                    <?php \LiveHelperChat\Models\Departments\UserDepAlias::getAlias(array('user' => & $user, 'chat' => $chat)); ?>
                    <?php include(erLhcoreClassDesign::designtpl('lhchat/part/operator_profile_main_pre.tpl.php')); ?>
                    <?php include(erLhcoreClassDesign::designtpl('lhchat/part/operator_profile.tpl.php')); ?>
                <?php else : ?>
                    <h6 class="fs12"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/chat','Pending confirm')?></h6>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ( erLhcoreClassModelChatConfig::fetch('reopen_chat_enabled')->current_value == 1 && erLhcoreClassModelChatConfig::fetch('allow_reopen_closed')->current_value == 1 && erLhcoreClassChat::canReopen($chat) ) : ?>
            <a href="<?php echo erLhcoreClassDesign::baseurl('chat/reopen')?>/<?php echo $chat->id?>/<?php echo $chat->hash?><?php if ( isset($chat_widget_mode) && $chat_widget_mode == true ) : ?>/(mode)/widget<?php endif; ?><?php if ( isset($chat_embed_mode) && $chat_embed_mode == true ) : ?>/(embedmode)/embed<?php endif;?>" class="btn btn-secondary" ><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/chatnotexists','Resume chat');?></a>
        <?php endif; ?>

    </div>
</div>
<script>
(function () {
  var chip = document.querySelector('.mdv-chat-head .mdv-chat-state');
  if (!chip) { return; }
  function setChip(label, tone) {
    if (chip.textContent.trim() === label) { return; }
    chip.className = 'mdv-chat-state mdv-chat-state-' + tone;
    chip.textContent = '';
    var dot = document.createElement('i');
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(label));
  }
  function sync() {
    var status = document.getElementById('status-chat');
    if (!status) { return; }
    if (status.querySelector('h4')) { setChip('Chat closed', 'closed'); return; }
    var queue = status.querySelector('.status-text');
    if (queue && /pending a support staff member|pending confirm|queue|wait/i.test(queue.textContent)) { setChip('Connecting you to the team', 'pending'); return; }
    var rows = document.querySelectorAll('#messages .message-row.message-admin .usr-tit');
    for (var i = 0; i < rows.length; i += 1) {
      var name = (rows[i].textContent || '').trim();
      if (name && !/ai assistant|metal divider ai/i.test(name)) { setChip('Team online', 'online'); return; }
    }
    setChip('AI assistant', 'ai');
  }
  var messages = document.getElementById('messages');
  if (messages && window.MutationObserver) {
    new MutationObserver(sync).observe(messages, { childList: true, subtree: true, characterData: true });
  }
  if (window.MutationObserver) {
    var status = document.getElementById('status-chat');
    if (status) { new MutationObserver(sync).observe(status, { childList: true, subtree: true, characterData: true }); }
  }
  sync();
}());
</script>
