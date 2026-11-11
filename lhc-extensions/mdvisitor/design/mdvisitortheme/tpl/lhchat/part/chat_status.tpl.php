<?php
$mdvChatResolveLang = static function () use ($chat): string {
    $supported = array('en', 'zh', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi');
    $normalize = static function (string $raw) use ($supported): string {
        $clean = strtolower(trim($raw));
        if ($clean === '') {
            return '';
        }
        if (strpos($clean, 'zh') === 0) {
            return 'zh';
        }
        if (strpos($clean, 'pt') === 0) {
            return 'pt-br';
        }
        return in_array($clean, $supported, true) ? $clean : '';
    };
    if (isset($_GET['mdvlang']) && is_string($_GET['mdvlang'])) {
        $fromGet = $normalize($_GET['mdvlang']);
        if ($fromGet !== '') {
            return $fromGet;
        }
    }
    if (isset($chat->chat_locale) && is_string($chat->chat_locale)) {
        $fromChat = $normalize($chat->chat_locale);
        if ($fromChat !== '') {
            return $fromChat;
        }
    }
    $urlCandidates = array();
    if (isset($chat->referrer) && is_string($chat->referrer) && $chat->referrer !== '') {
        $urlCandidates[] = $chat->referrer;
    }
    if (isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '') {
        $httpRef = $_SERVER['HTTP_REFERER'];
        $refQuery = (string) parse_url($httpRef, PHP_URL_QUERY);
        if ($refQuery !== '') {
            parse_str($refQuery, $refParams);
            if (!empty($refParams['mdvlang']) && is_string($refParams['mdvlang'])) {
                $fromRefLang = $normalize($refParams['mdvlang']);
                if ($fromRefLang !== '') {
                    return $fromRefLang;
                }
            }
            if (!empty($refParams['URLReferer']) && is_string($refParams['URLReferer'])) {
                $urlCandidates[] = $refParams['URLReferer'];
            }
        }
        $urlCandidates[] = $httpRef;
    }
    foreach ($urlCandidates as $candidate) {
        $candNorm = strpos($candidate, '//') === 0 ? 'https:' . $candidate : $candidate;
        $path = (string) parse_url($candNorm, PHP_URL_PATH);
        if ($path !== '' && preg_match('#/(zh|es|pt-br|de|fr|ar|id|vi)(?:/|$)#i', $path, $m)) {
            return strtolower($m[1]);
        }
    }
    return 'en';
};
$mdvLang = $mdvChatResolveLang();
$mdvDir = $mdvLang === 'ar' ? 'rtl' : 'ltr';
$mdvChatI18n = array(
    'en' => array(
        'ai_title'      => 'Metal Divider AI',
        'support_title' => 'Metal Divider Support',
        'state_ai'      => 'AI assistant',
        'state_pending' => 'Connecting you to the team',
        'state_online'  => 'Team online',
        'state_closed'  => 'Chat closed',
        'close_aria'    => 'Close assistant',
        'channels_aria' => 'Direct inquiry channels',
        'request_quote' => 'Request Quote',
        'whatsapp_cta'  => 'Chat on WhatsApp',
        'new_chat'      => 'Start a new chat',
        'delete_btn'    => 'Delete this conversation',
        'delete_prompt' => 'Delete this conversation from our records? This cannot be undone.',
        'cancel'        => 'Cancel',
        'delete'        => 'Delete',
        'placeholder'   => 'Write a message…',
        'send_aria'     => 'Send message',
    ),
    'zh' => array(
        'ai_title'      => 'Metal Divider AI 顾问',
        'support_title' => 'Metal Divider 客服团队',
        'state_ai'      => 'AI 助手',
        'state_pending' => '正在为您转接人工团队',
        'state_online'  => '顾问在线',
        'state_closed'  => '对话已结束',
        'close_aria'    => '关闭客服窗口',
        'channels_aria' => '直接询盘通道',
        'request_quote' => '索取报价',
        'whatsapp_cta'  => 'WhatsApp 咨询',
        'new_chat'      => '开启新对话',
        'delete_btn'    => '删除本次对话记录',
        'delete_prompt' => '确定从系统中删除本次对话记录吗？此操作不可撤销。',
        'cancel'        => '取消',
        'delete'        => '删除',
        'placeholder'   => '输入您的问题…',
        'send_aria'     => '发送消息',
    ),
    'es' => array(
        'ai_title'      => 'Metal Divider IA',
        'support_title' => 'Equipo Metal Divider',
        'state_ai'      => 'Asistente IA',
        'state_pending' => 'Conectándole con el equipo',
        'state_online'  => 'Equipo en línea',
        'state_closed'  => 'Conversación cerrada',
        'close_aria'    => 'Cerrar asistente',
        'channels_aria' => 'Canales de consulta directa',
        'request_quote' => 'Solicitar cotización',
        'whatsapp_cta'  => 'Chat en WhatsApp',
        'new_chat'      => 'Iniciar nueva conversación',
        'delete_btn'    => 'Eliminar esta conversación',
        'delete_prompt' => '¿Eliminar esta conversación de nuestros registros? Esta acción no se puede deshacer.',
        'cancel'        => 'Cancelar',
        'delete'        => 'Eliminar',
        'placeholder'   => 'Escriba un mensaje…',
        'send_aria'     => 'Enviar mensaje',
    ),
    'pt-br' => array(
        'ai_title'      => 'Metal Divider IA',
        'support_title' => 'Atendimento Metal Divider',
        'state_ai'      => 'Assistente IA',
        'state_pending' => 'Conectando você à equipe',
        'state_online'  => 'Equipe online',
        'state_closed'  => 'Conversa encerrada',
        'close_aria'    => 'Fechar assistente',
        'channels_aria' => 'Canais diretos de consulta',
        'request_quote' => 'Solicitar orçamento',
        'whatsapp_cta'  => 'Conversar no WhatsApp',
        'new_chat'      => 'Iniciar nova conversa',
        'delete_btn'    => 'Excluir esta conversa',
        'delete_prompt' => 'Excluir esta conversa dos nossos registros? Esta ação não pode ser desfeita.',
        'cancel'        => 'Cancelar',
        'delete'        => 'Excluir',
        'placeholder'   => 'Escreva uma mensagem…',
        'send_aria'     => 'Enviar mensagem',
    ),
    'de' => array(
        'ai_title'      => 'Metal Divider KI',
        'support_title' => 'Metal Divider Team',
        'state_ai'      => 'KI-Assistent',
        'state_pending' => 'Verbindung zum Team wird hergestellt',
        'state_online'  => 'Team online',
        'state_closed'  => 'Gespräch beendet',
        'close_aria'    => 'Assistent schließen',
        'channels_aria' => 'Direkte Anfragekanäle',
        'request_quote' => 'Angebot anfordern',
        'whatsapp_cta'  => 'WhatsApp-Chat',
        'new_chat'      => 'Neues Gespräch starten',
        'delete_btn'    => 'Dieses Gespräch löschen',
        'delete_prompt' => 'Dieses Gespräch dauerhaft aus unseren Unterlagen löschen? Dies kann nicht rückgängig gemacht werden.',
        'cancel'        => 'Abbrechen',
        'delete'        => 'Löschen',
        'placeholder'   => 'Nachricht schreiben…',
        'send_aria'     => 'Nachricht senden',
    ),
    'fr' => array(
        'ai_title'      => 'Metal Divider IA',
        'support_title' => 'Équipe Metal Divider',
        'state_ai'      => 'Assistant IA',
        'state_pending' => 'Mise en relation avec l’équipe',
        'state_online'  => 'Équipe en ligne',
        'state_closed'  => 'Conversation terminée',
        'close_aria'    => 'Fermer l’assistant',
        'channels_aria' => 'Canaux de demande directe',
        'request_quote' => 'Demander un devis',
        'whatsapp_cta'  => 'Discuter sur WhatsApp',
        'new_chat'      => 'Démarrer une nouvelle conversation',
        'delete_btn'    => 'Supprimer cette conversation',
        'delete_prompt' => 'Supprimer cette conversation de nos archives ? Cette action est irréversible.',
        'cancel'        => 'Annuler',
        'delete'        => 'Supprimer',
        'placeholder'   => 'Écrire un message…',
        'send_aria'     => 'Envoyer le message',
    ),
    'ar' => array(
        'ai_title'      => 'مساعد Metal Divider الذكي',
        'support_title' => 'فريق دعم Metal Divider',
        'state_ai'      => 'المساعد الذكي',
        'state_pending' => 'جارٍ توصيلك بالفريق المختص',
        'state_online'  => 'الفريق متصل',
        'state_closed'  => 'تم إغلاق المحادثة',
        'close_aria'    => 'إغلاق المساعد',
        'channels_aria' => 'قنوات الاستفسار المباشر',
        'request_quote' => 'طلب عرض سعر',
        'whatsapp_cta'  => 'تواصل عبر واتساب',
        'new_chat'      => 'بدء محادثة جديدة',
        'delete_btn'    => 'حذف هذه المحادثة',
        'delete_prompt' => 'هل تريد حذف هذه المحادثة من سجلاتنا؟ لا يمكن التراجع عن هذا الإجراء.',
        'cancel'        => 'إلغاء',
        'delete'        => 'حذف',
        'placeholder'   => 'اكتب رسالتك…',
        'send_aria'     => 'إرسال الرسالة',
    ),
    'id' => array(
        'ai_title'      => 'Metal Divider AI',
        'support_title' => 'Tim Metal Divider',
        'state_ai'      => 'Asisten AI',
        'state_pending' => 'Menghubungkan Anda dengan tim',
        'state_online'  => 'Tim online',
        'state_closed'  => 'Percakapan ditutup',
        'close_aria'    => 'Tutup asisten',
        'channels_aria' => 'Saluran permintaan langsung',
        'request_quote' => 'Minta Penawaran',
        'whatsapp_cta'  => 'Chat di WhatsApp',
        'new_chat'      => 'Mulai percakapan baru',
        'delete_btn'    => 'Hapus percakapan ini',
        'delete_prompt' => 'Hapus percakapan ini dari catatan kami? Tindakan ini tidak dapat dibatalkan.',
        'cancel'        => 'Batal',
        'delete'        => 'Hapus',
        'placeholder'   => 'Tulis pesan…',
        'send_aria'     => 'Kirim pesan',
    ),
    'vi' => array(
        'ai_title'      => 'Trợ lý AI Metal Divider',
        'support_title' => 'Đội ngũ Metal Divider',
        'state_ai'      => 'Trợ lý AI',
        'state_pending' => 'Đang kết nối bạn với đội ngũ',
        'state_online'  => 'Đội ngũ trực tuyến',
        'state_closed'  => 'Cuộc trò chuyện đã đóng',
        'close_aria'    => 'Đóng trợ lý',
        'channels_aria' => 'Kênh yêu cầu báo giá trực tiếp',
        'request_quote' => 'Yêu cầu báo giá',
        'whatsapp_cta'  => 'Chat qua WhatsApp',
        'new_chat'      => 'Bắt đầu cuộc trò chuyện mới',
        'delete_btn'    => 'Xóa cuộc trò chuyện này',
        'delete_prompt' => 'Xóa cuộc trò chuyện này khỏi hệ thống? Hành động này không thể hoàn tác.',
        'cancel'        => 'Hủy',
        'delete'        => 'Xóa',
        'placeholder'   => 'Nhập tin nhắn…',
        'send_aria'     => 'Gửi tin nhắn',
    ),
);
$mdvCT = $mdvChatI18n[$mdvLang] ?? $mdvChatI18n['en'];
$mdvStartBaseUrl = erLhcoreClassDesign::baseurl('chat/chatwidget') . '/(department)/' . (int)$chat->dep_id . '?mdvlang=' . rawurlencode($mdvLang) . '&mdvdir=' . rawurlencode($mdvDir);
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim(erLhcoreClassSystem::instance()->wwwDir(), '/') . '/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor-chat.css?v=20261111b', ENT_QUOTES, 'UTF-8'); ?>" />
<div class="row" >
    <div class="col-12">

        <?php
        $mdvStatus = (int)$chat->status;
        $mdvLabel = $mdvCT['state_ai'];
        $mdvTone = 'ai';
        if ($mdvStatus === erLhcoreClassModelChat::STATUS_PENDING_CHAT) { $mdvLabel = $mdvCT['state_pending']; $mdvTone = 'pending'; }
        elseif ($mdvStatus === erLhcoreClassModelChat::STATUS_ACTIVE_CHAT) { $mdvLabel = $mdvCT['state_online']; $mdvTone = 'online'; }
        elseif ($mdvStatus === erLhcoreClassModelChat::STATUS_CLOSED_CHAT) { $mdvLabel = $mdvCT['state_closed']; $mdvTone = 'closed'; }

        $mdvTitle = $mdvCT['ai_title'];
        if ($mdvStatus === erLhcoreClassModelChat::STATUS_ACTIVE_CHAT || $mdvStatus === erLhcoreClassModelChat::STATUS_CLOSED_CHAT) {
            $mdvTitle = $mdvCT['support_title'];
            if (($mdvOperator = $chat->user) !== false) {
                $mdvOperatorName = trim((string) $mdvOperator->name_support);
                if ($mdvOperatorName !== '') {
                    $mdvTitle = $mdvOperatorName;
                }
            }
        }
        ?>
        <div class="mdv-chat-head" dir="<?php echo htmlspecialchars($mdvDir, ENT_QUOTES, 'UTF-8'); ?>" data-mdv-lang="<?php echo htmlspecialchars($mdvLang, ENT_QUOTES, 'UTF-8'); ?>" data-chat-id="<?php echo (int)$chat->id;?>" data-chat-hash="<?php echo htmlspecialchars((string)$chat->hash, ENT_QUOTES, 'UTF-8');?>" data-chat-status="<?php echo (int)$chat->status;?>" data-email-saved="<?php echo trim((string)$chat->email) !== '' ? '1' : '0';?>">
            <div class="mdv-chat-id">
                <span class="mdv-chat-avatar" data-tone="<?php echo $mdvTone;?>">MD<i></i></span>
                <div class="mdv-chat-name"><strong id="mdv-chat-title"><?php echo htmlspecialchars($mdvTitle, ENT_QUOTES, 'UTF-8');?></strong><small><span class="mdv-chat-state mdv-chat-state-<?php echo $mdvTone;?>"><i></i><?php echo htmlspecialchars($mdvLabel, ENT_QUOTES, 'UTF-8');?></span></small></div>
            </div>
            <button class="mdv-chat-close" type="button" aria-label="<?php echo htmlspecialchars($mdvCT['close_aria'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($mdvCT['close_aria'], ENT_QUOTES, 'UTF-8'); ?>" onclick="(function(){var t='';try{var a=window.location.ancestorOrigins;if(a&&a.length)t=a[0];else if(document.referrer)t=new URL(document.referrer).origin}catch(e){}if(t&&window.parent!==window){window.parent.postMessage('lhc_chat_closed_explicit',t);}else{window.close();}})()">×</button>
        </div>
        <div class="mdv-convert-bar" dir="<?php echo htmlspecialchars($mdvDir, ENT_QUOTES, 'UTF-8'); ?>" role="region" aria-label="<?php echo htmlspecialchars($mdvCT['channels_aria'], ENT_QUOTES, 'UTF-8'); ?>">
            <button class="mdv-convert-btn mdv-convert-quote" type="button" data-mdv-open-quote><?php echo htmlspecialchars($mdvCT['request_quote'], ENT_QUOTES, 'UTF-8'); ?></button>
            <a class="mdv-convert-btn mdv-convert-wa" href="https://wa.me/85264731687" target="_blank" rel="noopener" data-mdv-wa><?php echo htmlspecialchars($mdvCT['whatsapp_cta'], ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
        <div class="mdv-closed-actions" dir="<?php echo htmlspecialchars($mdvDir, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($chat->status != erLhcoreClassModelChat::STATUS_CLOSED_CHAT) : ?> hidden<?php endif; ?>>
            <a class="mdv-new-chat" href="<?php echo htmlspecialchars($mdvStartBaseUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($mdvCT['new_chat'], ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
        <div class="mdv-danger-actions" dir="<?php echo htmlspecialchars($mdvDir, ENT_QUOTES, 'UTF-8'); ?>" data-mdv-start-url="<?php echo htmlspecialchars($mdvStartBaseUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <button class="mdv-delete-btn" type="button" aria-haspopup="dialog" aria-expanded="false" aria-controls="mdv-delete-confirm"><?php echo htmlspecialchars($mdvCT['delete_btn'], ENT_QUOTES, 'UTF-8'); ?></button>
            <div class="mdv-confirm" id="mdv-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="mdv-delete-confirm-text" tabindex="-1" hidden>
                <p id="mdv-delete-confirm-text"><?php echo htmlspecialchars($mdvCT['delete_prompt'], ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="mdv-confirm-actions">
                    <button class="mdv-confirm-no" type="button"><?php echo htmlspecialchars($mdvCT['cancel'], ENT_QUOTES, 'UTF-8'); ?></button>
                    <button class="mdv-confirm-yes" type="button"><?php echo htmlspecialchars($mdvCT['delete'], ENT_QUOTES, 'UTF-8'); ?></button>
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
<script src="<?php echo htmlspecialchars(rtrim(erLhcoreClassSystem::instance()->wwwDir(), '/') . '/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor-chat.js?v=20261111b', ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
(function () {
  var I18N = <?php echo json_encode($mdvChatI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var activeLang = <?php echo json_encode($mdvLang); ?>;
  var activeDir = <?php echo json_encode($mdvDir); ?>;
  var botNames = {
    'ai assistant': true,
    'metal divider ai': true,
    'metal divider support': true,
    'live support': true
  };
  Object.keys(I18N).forEach(function (loc) {
    var entry = I18N[loc];
    if (!entry) { return; }
    ['ai_title', 'support_title', 'state_ai'].forEach(function (k) {
      if (entry[k]) {
        botNames[String(entry[k]).trim().toLowerCase()] = true;
      }
    });
  });
  function normalizeLang(raw) {
    if (!raw || typeof raw !== 'string') { return ''; }
    var clean = raw.trim().toLowerCase();
    if (clean.indexOf('zh') === 0) { return 'zh'; }
    if (clean.indexOf('pt') === 0) { return 'pt-br'; }
    return I18N[clean] ? clean : '';
  }
  try {
    var storedLang = normalizeLang(window.sessionStorage.getItem('mdv_lang') || '');
    if (storedLang && activeLang === 'en' && storedLang !== 'en') {
      activeLang = storedLang;
      activeDir = activeLang === 'ar' ? 'rtl' : 'ltr';
    }
    window.sessionStorage.setItem('mdv_lang', activeLang);
    window.sessionStorage.setItem('mdv_dir', activeDir);
  } catch (e) {}
  document.documentElement.setAttribute('lang', activeLang);
  document.documentElement.setAttribute('dir', activeDir);

  function t() {
    return I18N[activeLang] || I18N.en;
  }

  var chip = document.querySelector('.mdv-chat-head .mdv-chat-state');
  var title = document.getElementById('mdv-chat-title');
  var avatar = document.querySelector('.mdv-chat-head .mdv-chat-avatar');

  function setPlaceholder() {
    var copy = t();
    var composer = document.getElementById('CSChatMessage');
    if (composer) {
      composer.placeholder = copy.placeholder;
      composer.setAttribute('aria-label', copy.placeholder);
    }
    var sendBtn = document.getElementById('lhc-send-icon');
    if (sendBtn) {
      sendBtn.setAttribute('aria-label', copy.send_aria);
      sendBtn.setAttribute('title', copy.send_aria);
    }
  }

  function withLocaleParams(rawUrl, lang, dir) {
    if (!rawUrl) { return rawUrl; }
    try {
      var u = new URL(rawUrl, window.location.origin);
      u.searchParams.set('mdvlang', lang);
      u.searchParams.set('mdvdir', dir);
      return u.pathname + u.search;
    } catch (e) {
      return rawUrl;
    }
  }

  window.__mdvApplyChatLocale = function (langCandidate, dirCandidate) {
    var nextLang = normalizeLang(langCandidate);
    if (!nextLang) { return; }
    activeLang = nextLang;
    activeDir = (dirCandidate === 'rtl' || activeLang === 'ar') ? 'rtl' : 'ltr';
    document.documentElement.setAttribute('lang', activeLang);
    document.documentElement.setAttribute('dir', activeDir);
    try {
      window.sessionStorage.setItem('mdv_lang', activeLang);
      window.sessionStorage.setItem('mdv_dir', activeDir);
    } catch (e) {}
    var copy = t();
    var headEl = document.querySelector('.mdv-chat-head');
    if (headEl) {
      headEl.setAttribute('dir', activeDir);
      headEl.setAttribute('data-mdv-lang', activeLang);
    }
    var closeBtn = document.querySelector('.mdv-chat-close');
    if (closeBtn) {
      closeBtn.setAttribute('aria-label', copy.close_aria);
      closeBtn.setAttribute('title', copy.close_aria);
    }
    var convertBar = document.querySelector('.mdv-convert-bar');
    if (convertBar) {
      convertBar.setAttribute('dir', activeDir);
      convertBar.setAttribute('aria-label', copy.channels_aria);
    }
    var quoteBtn = document.querySelector('.mdv-convert-quote');
    if (quoteBtn) { quoteBtn.textContent = copy.request_quote; }
    var waBtn = document.querySelector('.mdv-convert-wa');
    if (waBtn) { waBtn.textContent = copy.whatsapp_cta; }
    var closedBox = document.querySelector('.mdv-closed-actions');
    if (closedBox) {
      closedBox.setAttribute('dir', activeDir);
      var newChatLink = closedBox.querySelector('.mdv-new-chat');
      if (newChatLink) {
        newChatLink.textContent = copy.new_chat;
        newChatLink.setAttribute('href', withLocaleParams(newChatLink.getAttribute('href') || '', activeLang, activeDir));
      }
    }
    var dangerBox = document.querySelector('.mdv-danger-actions');
    if (dangerBox) {
      dangerBox.setAttribute('dir', activeDir);
      var startUrl = dangerBox.getAttribute('data-mdv-start-url') || '';
      if (startUrl) {
        dangerBox.setAttribute('data-mdv-start-url', withLocaleParams(startUrl, activeLang, activeDir));
      }
      var delBtn = dangerBox.querySelector('.mdv-delete-btn');
      if (delBtn) { delBtn.textContent = copy.delete_btn; }
      var delPrompt = document.getElementById('mdv-delete-confirm-text');
      if (delPrompt) { delPrompt.textContent = copy.delete_prompt; }
      var cancelBtn = dangerBox.querySelector('.mdv-confirm-no');
      if (cancelBtn) { cancelBtn.textContent = copy.cancel; }
      var yesBtn = dangerBox.querySelector('.mdv-confirm-yes');
      if (yesBtn) { yesBtn.textContent = copy.delete; }
    }
    setPlaceholder();
    sync();
  };

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
    if (chip.textContent.trim() === label && chip.className === 'mdv-chat-state mdv-chat-state-' + tone) { return; }
    chip.className = 'mdv-chat-state mdv-chat-state-' + tone;
    chip.textContent = '';
    var dot = document.createElement('i');
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(label));
  }
  function isBotAlias(name) {
    if (!name) { return true; }
    return !!botNames[name.trim().toLowerCase()];
  }
  function operatorName(status) {
    var node = status.querySelector('.op-name-widget');
    var name = node ? (node.textContent || '').trim() : '';
    return isBotAlias(name) ? '' : name;
  }
  function sync() {
    var copy = t();
    var status = document.getElementById('status-chat');
    if (!status) { return; }
    var statusText = status.textContent || '';
    if ((status.querySelector('h4') || status.querySelector('h6')) && /closed/i.test(statusText)) {
      setTitle(copy.support_title);
      setChip(copy.state_closed, 'closed');
      var closedBox = document.querySelector('.mdv-closed-actions');
      if (closedBox) { closedBox.hidden = false; }
      var emailBox = document.querySelector('.mdv-email');
      if (emailBox) { emailBox.hidden = true; }
      try { window.localStorage.removeItem('mdv:lastChat'); } catch (e) {}
      return;
    }
    if (/no logged in support staff|no support staff|no operators/i.test(statusText)) {
      setTitle(copy.ai_title);
      setChip(copy.state_ai, 'ai');
      return;
    }
    if (/pending a support staff member|pending confirm|in the queue/i.test(statusText)) {
      setTitle(copy.ai_title);
      setChip(copy.state_pending, 'pending');
      return;
    }
    var name = operatorName(status);
    if (!name) {
      var rows = document.querySelectorAll('#messages .message-row.message-admin .op-nick-title');
      for (var i = rows.length - 1; i >= 0; i -= 1) {
        var rowName = (rows[i].textContent || '').trim();
        if (rowName && !isBotAlias(rowName)) { name = rowName; break; }
      }
    }
    if (name) {
      setTitle(name);
      setChip(copy.state_online, 'online');
      return;
    }
    setTitle(copy.ai_title);
    setChip(copy.state_ai, 'ai');
  }
  var sourcePrefixRe = /(?:Based on:|参考来源：|Basado en:|Com base em:|Basierend auf:|Basé sur\s*:|بناءً على:|Berdasarkan:|Dựa trên:)/;
  function tagSources() {
    var bodies = document.querySelectorAll('#messages .msg-body');
    for (var i = 0; i < bodies.length; i += 1) {
      if (bodies[i].querySelector('.mdv-sources')) { continue; }
      var walker = document.createTreeWalker(bodies[i], NodeFilter.SHOW_TEXT, null, false);
      var node = walker.nextNode();
      while (node) {
        var m = sourcePrefixRe.exec(node.nodeValue || '');
        if (m) {
          var after = node.splitText(m.index);
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
    var re = /\[([^\]]+)\]\((https?:\/\/(?:www\.|staging\.)?metal-divider\.com(?:\/[A-Za-z0-9\-._~:\/?#\[\]@!$&'*+,;=%]*)?|\/[a-z0-9][a-z0-9-\/]*)\)|https?:\/\/(?:www\.|staging\.)?metal-divider\.com\/[A-Za-z0-9\-._~:\/?#\[\]@!$&'*+,;=%]+|(?:\/(?:zh|es|pt-br|de|fr|ar|id|vi))?\/(?:pattern|patterns|product|pattern-category|collections)\/[a-z0-9-]+\/?/gi;
    for (var i = 0; i < bodies.length; i += 1) {
      var existingLinks = bodies[i].querySelectorAll('a[href]');
      for (var k = 0; k < existingLinks.length; k += 1) {
        var el = existingLinks[k];
        if (el.closest && el.closest('.mdv-sources')) { continue; }
        var href = el.getAttribute('href') || '';
        if (/^(?:https?:\/\/(?:www\.|staging\.)?metal-divider\.com\/|\/)/i.test(href)) {
          var cleanHref = href.replace(/[.,;:!?)]+$/, '');
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
        var changedNode = false;
        while ((match = re.exec(val)) !== null) {
          var raw = match[0];
          var mdLabel = match[1];
          var mdUrl = match[2];
          if (!mdLabel && raw.charAt(0) === '/' && match.index > 0) {
            var prevChar = val.charAt(match.index - 1);
            if (/[A-Za-z0-9._:\/-]/.test(prevChar)) {
              continue;
            }
          }
          changedNode = true;
          var clean;
          var text;
          var trail = '';
          if (mdLabel && mdUrl) {
            clean = mdUrl.replace(/[.,;:!?)]+$/, '');
            text = mdLabel.replace(/\*\*/g, '').trim();
          } else {
            clean = raw.replace(/[.,;:!?)]+$/, '');
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
        if (!changedNode) { continue; }
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
    window.__mdvApplyChatLocale(activeLang, activeDir);
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
