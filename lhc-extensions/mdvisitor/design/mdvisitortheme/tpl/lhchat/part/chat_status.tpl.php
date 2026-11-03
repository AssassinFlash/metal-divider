<link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim(erLhcoreClassSystem::instance()->wwwDir(), '/') . '/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor-chat.css?v=20261103c', ENT_QUOTES, 'UTF-8'); ?>" />
<div class="row" >
    <div class="col-12">

        <?php
        $mdvStatus = (int)$chat->status;
        $mdvLabel = 'AI assistant';
        $mdvTone = 'ai';
        if ($mdvStatus === erLhcoreClassModelChat::STATUS_PENDING_CHAT) { $mdvLabel = 'Connecting you to the team'; $mdvTone = 'pending'; }
        elseif ($mdvStatus === erLhcoreClassModelChat::STATUS_ACTIVE_CHAT) { $mdvLabel = 'Team online'; $mdvTone = 'online'; }
        elseif ($mdvStatus === erLhcoreClassModelChat::STATUS_CLOSED_CHAT) { $mdvLabel = 'Chat closed'; $mdvTone = 'closed'; }

        $mdvTitle = 'Metal Divider AI';
        if ($mdvStatus === erLhcoreClassModelChat::STATUS_ACTIVE_CHAT || $mdvStatus === erLhcoreClassModelChat::STATUS_CLOSED_CHAT) {
            $mdvTitle = 'Metal Divider Support';
            if (($mdvOperator = $chat->user) !== false) {
                $mdvOperatorName = trim((string) $mdvOperator->name_support);
                if ($mdvOperatorName !== '') {
                    $mdvTitle = $mdvOperatorName;
                }
            }
        }
        ?>
        <div class="mdv-chat-head" data-chat-id="<?php echo (int)$chat->id;?>" data-chat-hash="<?php echo htmlspecialchars((string)$chat->hash, ENT_QUOTES, 'UTF-8');?>" data-chat-status="<?php echo (int)$chat->status;?>" data-email-saved="<?php echo trim((string)$chat->email) !== '' ? '1' : '0';?>">
            <div class="mdv-chat-id">
                <span class="mdv-chat-avatar" data-tone="<?php echo $mdvTone;?>">MD<i></i></span>
                <div class="mdv-chat-name"><strong id="mdv-chat-title"><?php echo htmlspecialchars($mdvTitle);?></strong><small><span class="mdv-chat-state mdv-chat-state-<?php echo $mdvTone;?>"><i></i><?php echo htmlspecialchars($mdvLabel);?></span></small></div>
            </div>
            <button class="mdv-chat-close" type="button" aria-label="Close assistant" title="Close assistant" onclick="(function(){var t='';try{var a=window.location.ancestorOrigins;if(a&&a.length)t=a[0];else if(document.referrer)t=new URL(document.referrer).origin}catch(e){}if(t&&window.parent!==window){window.parent.postMessage('lhc_chat_closed_explicit',t);}else{window.close();}})()">×</button>
        </div>
        <div class="mdv-convert-bar" role="region" aria-label="Direct inquiry channels">
            <button class="mdv-convert-btn mdv-convert-quote" type="button" data-mdv-open-quote>Request Quote</button>
            <a class="mdv-convert-btn mdv-convert-wa" href="https://wa.me/85264731687" target="_blank" rel="noopener" data-mdv-wa>Chat on WhatsApp</a>
        </div>
        <div class="mdv-closed-actions"<?php if ($chat->status != erLhcoreClassModelChat::STATUS_CLOSED_CHAT) : ?> hidden<?php endif; ?>>
            <a class="mdv-new-chat" href="<?php echo erLhcoreClassDesign::baseurl('chat/chatwidget')?>/(department)/<?php echo (int)$chat->dep_id;?>">Start a new chat</a>
        </div>
        <div class="mdv-danger-actions" data-mdv-start-url="<?php echo erLhcoreClassDesign::baseurl('chat/chatwidget')?>/(department)/<?php echo (int)$chat->dep_id;?>">
            <button class="mdv-delete-btn" type="button" aria-haspopup="dialog" aria-expanded="false" aria-controls="mdv-delete-confirm">Delete this conversation</button>
            <div class="mdv-confirm" id="mdv-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="mdv-delete-confirm-text" tabindex="-1" hidden>
                <p id="mdv-delete-confirm-text">Delete this conversation from our records? This cannot be undone.</p>
                <div class="mdv-confirm-actions">
                    <button class="mdv-confirm-no" type="button">Cancel</button>
                    <button class="mdv-confirm-yes" type="button">Delete</button>
                </div>
            </div>
            <p class="mdv-delete-status" role="status" aria-live="polite" data-tone="" hidden></p>
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
<script src="<?php echo htmlspecialchars(rtrim(erLhcoreClassSystem::instance()->wwwDir(), '/') . '/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor-chat.js?v=20261103b', ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
(function () {
  var chip = document.querySelector('.mdv-chat-head .mdv-chat-state');
  var title = document.getElementById('mdv-chat-title');
  var avatar = document.querySelector('.mdv-chat-head .mdv-chat-avatar');
  function setPlaceholder() {
    var composer = document.getElementById('CSChatMessage');
    if (composer) { composer.placeholder = 'Type your message…'; }
  }
  setPlaceholder();
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', setPlaceholder); }
  window.setTimeout(setPlaceholder, 500);
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') { return; }
    var confirmBox = document.getElementById('mdv-delete-confirm');
    if (confirmBox && !confirmBox.hidden) { return; }
    var target = '';
    try {
      var origins = window.location.ancestorOrigins;
      if (origins && origins.length) { target = origins[0]; }
      else if (document.referrer) { target = new URL(document.referrer).origin; }
    } catch (err) {}
    if (target && window.parent !== window) {
      event.preventDefault();
      window.parent.postMessage('lhc_chat_closed_explicit', target);
    } else {
      window.close();
    }
  });
  if (!chip) { return; }
  function setTitle(name) {
    if (title && name && title.textContent.trim() !== name) { title.textContent = name; }
  }
  function setChip(label, tone) {
    if (avatar) { avatar.setAttribute('data-tone', tone); }
    if (chip.textContent.trim() === label) { return; }
    chip.className = 'mdv-chat-state mdv-chat-state-' + tone;
    chip.textContent = '';
    var dot = document.createElement('i');
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(label));
  }
  function operatorName(status) {
    var node = status.querySelector('.op-name-widget');
    var name = node ? (node.textContent || '').trim() : '';
    return /^(ai assistant|metal divider ai|metal divider support|live support)$/i.test(name) ? '' : name;
  }
  function sync() {
    var status = document.getElementById('status-chat');
    if (!status) { return; }
    var statusText = status.textContent || '';
    if ((status.querySelector('h4') || status.querySelector('h6')) && /closed/i.test(statusText)) {
      setTitle('Metal Divider Support');
      setChip('Chat closed', 'closed');
      var closedBox = document.querySelector('.mdv-closed-actions');
      if (closedBox) { closedBox.hidden = false; }
      var emailBox = document.querySelector('.mdv-email');
      if (emailBox) { emailBox.hidden = true; }
      try { window.localStorage.removeItem('mdv:lastChat'); } catch (e) {}
      return;
    }
    if (/no logged in support staff|no support staff|no operators/i.test(statusText)) { setTitle('Metal Divider AI'); setChip('AI assistant', 'ai'); return; }
    if (/pending a support staff member|pending confirm|in the queue/i.test(statusText)) { setTitle('Metal Divider AI'); setChip('Connecting you to the team', 'pending'); return; }
    var name = operatorName(status);
    if (!name) {
      var rows = document.querySelectorAll('#messages .message-row.message-admin .op-nick-title');
      for (var i = rows.length - 1; i >= 0; i -= 1) {
        var rowName = (rows[i].textContent || '').trim();
        if (rowName && !/^(ai assistant|metal divider ai|metal divider support|live support)$/i.test(rowName)) { name = rowName; break; }
      }
    }
    if (name) { setTitle(name); setChip('Team online', 'online'); return; }
    setTitle('Metal Divider AI');
    setChip('AI assistant', 'ai');
  }
  function tagSources() {
    var bodies = document.querySelectorAll('#messages .msg-body');
    for (var i = 0; i < bodies.length; i += 1) {
      if (bodies[i].querySelector('.mdv-sources')) { continue; }
      var walker = document.createTreeWalker(bodies[i], NodeFilter.SHOW_TEXT, null, false);
      var node = walker.nextNode();
      while (node) {
        var at = node.nodeValue.indexOf('Based on:');
        if (at !== -1) {
          var after = node.splitText(at);
          var label = document.createElement('span');
          label.className = 'mdv-sources';
          after.parentNode.insertBefore(label, after);
          label.appendChild(after);
          var prev = label.previousSibling;
          while (prev && ((prev.nodeType === 3 && !prev.nodeValue.trim()) || prev.nodeName === 'BR')) {
            var before = prev.previousSibling;
            prev.parentNode.removeChild(prev);
            prev = before;
          }
          break;
        }
        node = walker.nextNode();
      }
    }
  }
  function linkifyPatterns() {
    var bodies = document.querySelectorAll('#messages .message-admin .msg-body');
    var re = /\[([^\]]+)\]\((https?:\/\/(?:www\.|staging\.)?metal-divider\.com(?:\/[^\s)<>"']*)?|\/[a-z0-9][a-z0-9-\/]*)\)|https?:\/\/(?:www\.|staging\.)?metal-divider\.com\/[^\s)<>"']+|\/(?:pattern|product|collections)\/[a-z0-9-]+\/?/gi;
    for (var i = 0; i < bodies.length; i += 1) {
      var existingLinks = bodies[i].querySelectorAll('a[href]');
      for (var k = 0; k < existingLinks.length; k += 1) {
        var el = existingLinks[k];
        if (el.closest && el.closest('.mdv-sources')) { continue; }
        var href = el.getAttribute('href') || '';
        if (/^(?:https?:\/\/(?:www\.|staging\.)?metal-divider\.com\/|\/)/i.test(href)) {
          var cleanHref = href.replace(/[.,;:!?]+$/, '');
          if (cleanHref !== href) {
            var trailHref = href.slice(cleanHref.length);
            el.setAttribute('href', cleanHref);
            if ((el.textContent || '') === href) {
              el.textContent = cleanHref;
              if (el.parentNode) {
                el.parentNode.insertBefore(document.createTextNode(trailHref), el.nextSibling);
              }
            }
          }
          var prevNode = el.previousSibling;
          var nextNode = el.nextSibling;
          if (prevNode && prevNode.nodeType === 3 && nextNode && nextNode.nodeType === 3) {
            var prevMatch = (prevNode.nodeValue || '').match(/\[([^\]]+)\]\s*\(\s*$/);
            var nextMatch = (nextNode.nodeValue || '').match(/^\s*\)/);
            if (prevMatch && nextMatch) {
              el.textContent = prevMatch[1].replace(/\*\*/g, '').trim();
              prevNode.nodeValue = (prevNode.nodeValue || '').slice(0, prevMatch.index);
              nextNode.nodeValue = (nextNode.nodeValue || '').slice(nextMatch[0].length);
            }
          }
          if (el.textContent && el.textContent.indexOf('**') !== -1) {
            el.textContent = el.textContent.replace(/\*\*/g, '').trim();
          }
          el.classList.add('mdv-inline-link');
          el.target = '_top';
          el.rel = 'noopener';
        }
      }
      var walker = document.createTreeWalker(bodies[i], NodeFilter.SHOW_TEXT, null, false);
      var textNodes = [];
      var n = walker.nextNode();
      while (n) {
        var parent = n.parentElement || n.parentNode;
        if (parent && (!parent.closest || !parent.closest('a, .mdv-sources'))) {
          textNodes.push(n);
        }
        n = walker.nextNode();
      }
      for (var j = 0; j < textNodes.length; j += 1) {
        var node = textNodes[j];
        var val = node.nodeValue || '';
        re.lastIndex = 0;
        if (!re.test(val)) { continue; }
        re.lastIndex = 0;
        var frag = document.createDocumentFragment();
        var lastIdx = 0;
        var match;
        while ((match = re.exec(val)) !== null) {
          var raw = match[0];
          var mdLabel = match[1];
          var mdUrl = match[2];
          var clean;
          var text;
          var trail = '';
          if (mdLabel && mdUrl) {
            clean = mdUrl.replace(/[.,;:!?]+$/, '');
            text = mdLabel.replace(/\*\*/g, '').trim();
          } else {
            clean = raw.replace(/[.,;:!?]+$/, '');
            text = clean;
            trail = raw.slice(clean.length);
          }
          if (match.index > lastIdx) {
            frag.appendChild(document.createTextNode(val.slice(lastIdx, match.index)));
          }
          var a = document.createElement('a');
          a.className = 'mdv-inline-link';
          a.href = clean;
          a.target = '_top';
          a.rel = 'noopener';
          a.textContent = text;
          frag.appendChild(a);
          if (trail) {
            frag.appendChild(document.createTextNode(trail));
          }
          lastIdx = match.index + raw.length;
        }
        if (lastIdx < val.length) {
          frag.appendChild(document.createTextNode(val.slice(lastIdx)));
        }
        if (node.parentNode) {
          node.parentNode.replaceChild(frag, node);
        }
      }
    }
  }
  function initObservers() {
    tagSources();
    linkifyPatterns();
    var messages = document.getElementById('messages');
    if (messages && window.MutationObserver && !messages.getAttribute('data-mdv-observed')) {
      messages.setAttribute('data-mdv-observed', '1');
      new MutationObserver(function () { sync(); tagSources(); linkifyPatterns(); }).observe(messages, { childList: true, subtree: true, characterData: true });
    }
    var status = document.getElementById('status-chat');
    if (status && window.MutationObserver && !status.getAttribute('data-mdv-observed')) {
      status.setAttribute('data-mdv-observed', '1');
      new MutationObserver(sync).observe(status, { childList: true, subtree: true, characterData: true });
    }
    sync();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initObservers);
  } else {
    initObservers();
  }
}());
</script>
