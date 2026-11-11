<?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/chatwidget_pre_multiinclude.tpl.php'));?>
<?php
$mdvResolveLang = static function (): string {
    $supported = array('en', 'zh', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi');
    if (isset($_GET['mdvlang']) && is_string($_GET['mdvlang'])) {
        $raw = strtolower(trim($_GET['mdvlang']));
        if (strpos($raw, 'zh') === 0) {
            return 'zh';
        }
        if (strpos($raw, 'pt') === 0) {
            return 'pt-br';
        }
        if (in_array($raw, $supported, true)) {
            return $raw;
        }
    }
    $urlCandidates = array();
    if (isset($_GET['URLReferer']) && is_string($_GET['URLReferer'])) {
        $urlCandidates[] = $_GET['URLReferer'];
    }
    if (isset($referer) && is_string($referer) && $referer !== '') {
        $urlCandidates[] = $referer;
    }
    if (isset($_POST['URLRefer']) && is_string($_POST['URLRefer'])) {
        $urlCandidates[] = $_POST['URLRefer'];
    }
    if (isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])) {
        $httpRef = $_SERVER['HTTP_REFERER'];
        $refQuery = (string) parse_url($httpRef, PHP_URL_QUERY);
        if ($refQuery !== '') {
            parse_str($refQuery, $refParams);
            if (!empty($refParams['mdvlang']) && is_string($refParams['mdvlang'])) {
                $rawRef = strtolower(trim($refParams['mdvlang']));
                if (strpos($rawRef, 'zh') === 0) {
                    return 'zh';
                }
                if (strpos($rawRef, 'pt') === 0) {
                    return 'pt-br';
                }
                if (in_array($rawRef, $supported, true)) {
                    return $rawRef;
                }
            }
            if (!empty($refParams['URLReferer']) && is_string($refParams['URLReferer'])) {
                $urlCandidates[] = $refParams['URLReferer'];
            }
        }
        $urlCandidates[] = $httpRef;
    }
    foreach ($urlCandidates as $candidate) {
        $path = (string) parse_url($candidate, PHP_URL_PATH);
        if ($path !== '' && preg_match('#/(zh|es|pt-br|de|fr|ar|id|vi)(?:/|$)#i', $path, $m)) {
            return strtolower($m[1]);
        }
    }
    return 'en';
};
$mdvLang = $mdvResolveLang();
$mdvDir = ($mdvLang === 'ar' || (isset($_GET['mdvdir']) && is_string($_GET['mdvdir']) && strtolower(trim($_GET['mdvdir'])) === 'rtl')) ? 'rtl' : 'ltr';
$mdvEsc = static function (string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
};
$mdvI18nAll = array(
    'en' => array(
        'brand_tag'         => 'AI concierge',
        'desk_closed'       => 'Desk closed',
        'close_aria'        => 'Close assistant',
        'welcome'           => 'Welcome',
        'hero_sub'          => 'How can we help you today?',
        'ai_assistant'      => 'AI assistant',
        'replies_instantly' => 'Replies instantly',
        'team_online'       => 'Team online',
        'leave_msg_title'   => 'Leave a message',
        'leave_msg_sub'     => 'The desk is closed right now. Leave a message and the team will read it when the desk opens.',
        'channels_aria'     => 'Direct inquiry channels',
        'request_quote'     => 'Request Quote',
        'whatsapp_cta'      => 'Chat on WhatsApp',
        'resume_title'      => 'Continue your last conversation',
        'resume_sub'        => 'Pick up where you left off',
        'resume_reset'      => 'Not your conversation? Start a new one',
        'quick_connect'     => 'Quick Connect',
        'pick_start'        => 'Pick a start',
        'act1_title'        => 'Ask about a pattern',
        'act1_sub'          => 'Send a code, photo or drawing',
        'act1_fill'         => 'I have a pattern in mind. Can you help me check it?',
        'act2_title'        => 'Materials & finishes',
        'act2_sub'          => '304 / 316 stainless, PVD, finishes',
        'act2_fill'         => 'What materials and finishes do you offer?',
        'act3_title'        => 'Talk to a person',
        'act3_sub'          => 'Connect with the Metal Divider team',
        'act3_fill'         => "I'd like to speak with a person, please.",
        'browse_faq'        => 'Browse common questions',
        'chip1_label'       => 'Recommend patterns',
        'chip1_fill'        => 'Can you recommend a geometric pattern with 30-40% open area for outdoor privacy?',
        'chip2_label'       => 'Lead time',
        'chip2_fill'        => 'What is your typical lead time?',
        'chip3_label'       => 'Custom patterns',
        'chip3_fill'        => 'Can you match a pattern from my drawing?',
        'chip4_label'       => 'Shipping',
        'chip4_fill'        => 'Do you ship to my country?',
        'foot_email'        => 'Email',
        'input_aria'        => 'Enter your message',
        'input_placeholder' => 'Write a message…',
        'tabs_aria'         => 'Assistant sections',
        'tab_home'          => 'Home',
        'tab_faq'           => 'FAQ',
        'back_aria'         => 'Back',
        'help_centre'       => 'Help centre',
        'faq_heading'       => 'Common questions',
        'faq_search'        => 'Search questions',
        'faq_q1_label'      => 'What is your typical lead time?',
        'faq_q1_fill'       => 'What is your typical lead time?',
        'faq_q2_label'      => 'Can you match a pattern from my drawing?',
        'faq_q2_fill'       => 'Can you match a pattern from my drawing?',
        'faq_q3_label'      => 'Do you ship to my country?',
        'faq_q3_fill'       => 'Do you ship to my country?',
        'faq_q4_label'      => 'What materials and finishes do you offer?',
        'faq_q4_fill'       => 'What materials and finishes do you offer?',
        'faq_q5_label'      => 'Can you send CAD files?',
        'faq_q5_fill'       => 'Can you send CAD files for a pattern?',
        'ask_assistant'     => 'Ask the assistant',
    ),
    'zh' => array(
        'brand_tag'         => 'AI 顾问',
        'desk_closed'       => '团队休息中',
        'close_aria'        => '关闭客服窗口',
        'welcome'           => '您好',
        'hero_sub'          => '今天有什么可以帮您？',
        'ai_assistant'      => 'AI 助手',
        'replies_instantly' => '即时回复',
        'team_online'       => '顾问在线',
        'leave_msg_title'   => '留言咨询',
        'leave_msg_sub'     => '团队当前暂不在线。请留下您的询盘信息，我们上线后将第一时间回复。',
        'channels_aria'     => '直接询盘通道',
        'request_quote'     => '索取报价',
        'whatsapp_cta'      => 'WhatsApp 咨询',
        'resume_title'      => '继续上次对话',
        'resume_sub'        => '接着之前的记录继续沟通',
        'resume_reset'      => '不是您的对话？开启新对话',
        'quick_connect'     => '快捷咨询',
        'pick_start'        => '选择话题',
        'act1_title'        => '咨询特定图案',
        'act1_sub'          => '发送图案编号、现场照片或图纸',
        'act1_fill'         => '我有心仪的图案，能帮我核对一下吗？',
        'act2_title'        => '材质与表面处理',
        'act2_sub'          => '304 / 316 不锈钢、PVD 镀色与表面工艺',
        'act2_fill'         => '你们提供哪些不锈钢材质等级和表面处理工艺？',
        'act3_title'        => '联系人工顾问',
        'act3_sub'          => '转接 Metal Divider 专属团队',
        'act3_fill'         => '我想联系人工客服沟通项目细节。',
        'browse_faq'        => '浏览常见问题',
        'chip1_label'       => '推荐图案',
        'chip1_fill'        => '能否推荐几款镂空率 30-40%、适合户外隐私遮挡的几何图案？',
        'chip2_label'       => '生产交期',
        'chip2_fill'        => '定制不锈钢屏风的常规交期是多久？',
        'chip3_label'       => '定制图案',
        'chip3_fill'        => '可以根据我的图纸深化定制图案吗？',
        'chip4_label'       => '全球运输',
        'chip4_fill'        => '你们支持海运或空运发往我的国家吗？',
        'foot_email'        => '邮件',
        'input_aria'        => '输入您的问题',
        'input_placeholder' => '输入您的问题…',
        'tabs_aria'         => '助手导航',
        'tab_home'          => '首页',
        'tab_faq'           => '常见问题',
        'back_aria'         => '返回',
        'help_centre'       => '帮助中心',
        'faq_heading'       => '常见问题',
        'faq_search'        => '搜索问题',
        'faq_q1_label'      => '定制不锈钢屏风的常规交期是多久？',
        'faq_q1_fill'       => '定制不锈钢屏风的常规交期是多久？',
        'faq_q2_label'      => '可以根据我的图纸深化定制图案吗？',
        'faq_q2_fill'       => '可以根据我的图纸深化定制图案吗？',
        'faq_q3_label'      => '你们支持海运或空运发往我的国家吗？',
        'faq_q3_fill'       => '你们支持海运或空运发往我的国家吗？',
        'faq_q4_label'      => '你们提供哪些不锈钢材质等级和表面处理工艺？',
        'faq_q4_fill'       => '你们提供哪些不锈钢材质等级和表面处理工艺？',
        'faq_q5_label'      => '可以提供图案的 CAD 图纸吗？',
        'faq_q5_fill'       => '可以提供图案的 CAD 图纸吗？',
        'ask_assistant'     => '向 AI 助手提问',
    ),
    'es' => array(
        'brand_tag'         => 'Asesor IA',
        'desk_closed'       => 'Fuera de horario',
        'close_aria'        => 'Cerrar asistente',
        'welcome'           => 'Bienvenido',
        'hero_sub'          => '¿Cómo podemos ayudarle hoy?',
        'ai_assistant'      => 'Asistente IA',
        'replies_instantly' => 'Respuesta inmediata',
        'team_online'       => 'Equipo en línea',
        'leave_msg_title'   => 'Dejar un mensaje',
        'leave_msg_sub'     => 'El equipo no está disponible ahora. Déjenos su mensaje y le responderemos en cuanto abra la oficina.',
        'channels_aria'     => 'Canales de consulta directa',
        'request_quote'     => 'Solicitar cotización',
        'whatsapp_cta'      => 'Chat en WhatsApp',
        'resume_title'      => 'Continuar su última conversación',
        'resume_sub'        => 'Retomar donde lo dejó',
        'resume_reset'      => '¿No es su conversación? Iniciar una nueva',
        'quick_connect'     => 'Inicio rápido',
        'pick_start'        => 'Elija un tema',
        'act1_title'        => 'Consultar un patrón',
        'act1_sub'          => 'Envíe un código, foto o plano',
        'act1_fill'         => 'Tengo un patrón en mente. ¿Pueden ayudarme a revisarlo?',
        'act2_title'        => 'Materiales y acabados',
        'act2_sub'          => 'Acero inox 304 / 316, PVD y acabados',
        'act2_fill'         => '¿Qué grados de acero inoxidable y acabados ofrecen?',
        'act3_title'        => 'Hablar con una persona',
        'act3_sub'          => 'Conectar con el equipo de Metal Divider',
        'act3_fill'         => 'Me gustaría hablar con una persona del equipo, por favor.',
        'browse_faq'        => 'Ver preguntas frecuentes',
        'chip1_label'       => 'Recomendar patrones',
        'chip1_fill'        => '¿Pueden recomendar un patrón geométrico con 30-40% de área abierta para privacidad exterior?',
        'chip2_label'       => 'Plazo de entrega',
        'chip2_fill'        => '¿Cuál es su plazo de entrega habitual?',
        'chip3_label'       => 'Patrones a medida',
        'chip3_fill'        => '¿Pueden fabricar un patrón personalizado a partir de mi plano?',
        'chip4_label'       => 'Envíos',
        'chip4_fill'        => '¿Realizan envíos internacionales a mi país?',
        'foot_email'        => 'Correo',
        'input_aria'        => 'Escriba su mensaje',
        'input_placeholder' => 'Escriba un mensaje…',
        'tabs_aria'         => 'Secciones del asistente',
        'tab_home'          => 'Inicio',
        'tab_faq'           => 'Preguntas',
        'back_aria'         => 'Volver',
        'help_centre'       => 'Centro de ayuda',
        'faq_heading'       => 'Preguntas frecuentes',
        'faq_search'        => 'Buscar preguntas',
        'faq_q1_label'      => '¿Cuál es su plazo de entrega habitual?',
        'faq_q1_fill'       => '¿Cuál es su plazo de entrega habitual?',
        'faq_q2_label'      => '¿Pueden fabricar un patrón a partir de mi plano?',
        'faq_q2_fill'       => '¿Pueden fabricar un patrón personalizado a partir de mi plano?',
        'faq_q3_label'      => '¿Realizan envíos a mi país?',
        'faq_q3_fill'       => '¿Realizan envíos internacionales a mi país?',
        'faq_q4_label'      => '¿Qué materiales y acabados ofrecen?',
        'faq_q4_fill'       => '¿Qué grados de acero inoxidable y acabados ofrecen?',
        'faq_q5_label'      => '¿Pueden enviar archivos CAD?',
        'faq_q5_fill'       => '¿Pueden enviar archivos CAD de un patrón?',
        'ask_assistant'     => 'Preguntar al asistente',
    ),
    'pt-br' => array(
        'brand_tag'         => 'Consultor IA',
        'desk_closed'       => 'Atendimento fechado',
        'close_aria'        => 'Fechar assistente',
        'welcome'           => 'Bem-vindo',
        'hero_sub'          => 'Como podemos ajudar no seu projeto hoje?',
        'ai_assistant'      => 'Assistente IA',
        'replies_instantly' => 'Resposta imediata',
        'team_online'       => 'Equipe online',
        'leave_msg_title'   => 'Deixe uma mensagem',
        'leave_msg_sub'     => 'O atendimento está fechado agora. Deixe sua mensagem e nossa equipe responderá assim que abrir.',
        'channels_aria'     => 'Canais diretos de consulta',
        'request_quote'     => 'Solicitar orçamento',
        'whatsapp_cta'      => 'Conversar no WhatsApp',
        'resume_title'      => 'Continuar sua última conversa',
        'resume_sub'        => 'Retome de onde parou',
        'resume_reset'      => 'Não é sua conversa? Iniciar uma nova',
        'quick_connect'     => 'Acesso rápido',
        'pick_start'        => 'Escolha um tema',
        'act1_title'        => 'Consultar um padrão',
        'act1_sub'          => 'Envie um código, foto ou desenho',
        'act1_fill'         => 'Tenho um padrão em mente. Podem me ajudar a conferir?',
        'act2_title'        => 'Materiais e acabamentos',
        'act2_sub'          => 'Inox 304 / 316, PVD e acabamentos',
        'act2_fill'         => 'Quais graus de aço inoxidável e acabamentos vocês oferecem?',
        'act3_title'        => 'Falar com um consultor',
        'act3_sub'          => 'Conectar com a equipe Metal Divider',
        'act3_fill'         => 'Gostaria de falar com um atendente humano, por favor.',
        'browse_faq'        => 'Ver perguntas frequentes',
        'chip1_label'       => 'Recomendar padrões',
        'chip1_fill'        => 'Podem recomendar um padrão geométrico com 30-40% de área aberta para privacidade externa?',
        'chip2_label'       => 'Prazo de produção',
        'chip2_fill'        => 'Qual é o prazo médio de entrega e produção?',
        'chip3_label'       => 'Padrões sob medida',
        'chip3_fill'        => 'Vocês podem desenvolver um padrão personalizado a partir do meu desenho?',
        'chip4_label'       => 'Envio internacional',
        'chip4_fill'        => 'Vocês enviam para o meu país?',
        'foot_email'        => 'E-mail',
        'input_aria'        => 'Digite sua mensagem',
        'input_placeholder' => 'Escreva uma mensagem…',
        'tabs_aria'         => 'Seções do assistente',
        'tab_home'          => 'Início',
        'tab_faq'           => 'Dúvidas',
        'back_aria'         => 'Voltar',
        'help_centre'       => 'Central de ajuda',
        'faq_heading'       => 'Perguntas frequentes',
        'faq_search'        => 'Buscar perguntas',
        'faq_q1_label'      => 'Qual é o prazo médio de entrega?',
        'faq_q1_fill'       => 'Qual é o prazo médio de entrega e produção?',
        'faq_q2_label'      => 'Podem produzir um padrão a partir do meu desenho?',
        'faq_q2_fill'       => 'Vocês podem desenvolver um padrão personalizado a partir do meu desenho?',
        'faq_q3_label'      => 'Vocês enviam para o meu país?',
        'faq_q3_fill'       => 'Vocês enviam para o meu país?',
        'faq_q4_label'      => 'Quais materiais e acabamentos vocês oferecem?',
        'faq_q4_fill'       => 'Quais graus de aço inoxidável e acabamentos vocês oferecem?',
        'faq_q5_label'      => 'Vocês podem enviar arquivos CAD?',
        'faq_q5_fill'       => 'Vocês podem enviar arquivos CAD de um padrão?',
        'ask_assistant'     => 'Perguntar ao assistente',
    ),
    'de' => array(
        'brand_tag'         => 'KI-Berater',
        'desk_closed'       => 'Derzeit geschlossen',
        'close_aria'        => 'Assistent schließen',
        'welcome'           => 'Willkommen',
        'hero_sub'          => 'Wie können wir Ihnen heute helfen?',
        'ai_assistant'      => 'KI-Assistent',
        'replies_instantly' => 'Antwortet sofort',
        'team_online'       => 'Team online',
        'leave_msg_title'   => 'Nachricht hinterlassen',
        'leave_msg_sub'     => 'Unser Team ist gerade nicht erreichbar. Hinterlassen Sie eine Nachricht — wir melden uns umgehend.',
        'channels_aria'     => 'Direkte Anfragekanäle',
        'request_quote'     => 'Angebot anfordern',
        'whatsapp_cta'      => 'WhatsApp-Chat',
        'resume_title'      => 'Letztes Gespräch fortsetzen',
        'resume_sub'        => 'Dort weitermachen, wo Sie aufgehört haben',
        'resume_reset'      => 'Nicht Ihr Gespräch? Neues starten',
        'quick_connect'     => 'Schnellstart',
        'pick_start'        => 'Thema wählen',
        'act1_title'        => 'Muster prüfen lassen',
        'act1_sub'          => 'Code, Foto oder Zeichnung prüfen',
        'act1_fill'         => 'Ich habe ein bestimmtes Muster im Sinn. Können Sie es für mich prüfen?',
        'act2_title'        => 'Materialien & Oberflächen',
        'act2_sub'          => 'Edelstahl 304 / 316, PVD & Oberflächen',
        'act2_fill'         => 'Welche Edelstahl-Güten und PVD-Oberflächen bieten Sie an?',
        'act3_title'        => 'Mit dem Team sprechen',
        'act3_sub'          => 'Direkt mit Metal Divider verbinden',
        'act3_fill'         => 'Ich möchte bitte mit einem Mitarbeiter sprechen.',
        'browse_faq'        => 'Häufige Fragen ansehen',
        'chip1_label'       => 'Muster empfehlen',
        'chip1_fill'        => 'Können Sie ein geometrisches Muster mit 30-40% Öffnungsanteil für Sichtschutz im Außenbereich empfehlen?',
        'chip2_label'       => 'Lieferzeit',
        'chip2_fill'        => 'Wie lang ist Ihre übliche Lieferzeit?',
        'chip3_label'       => 'Sondermuster',
        'chip3_fill'        => 'Können Sie ein individuelles Muster nach meiner Zeichnung fertigen?',
        'chip4_label'       => 'Versand',
        'chip4_fill'        => 'Liefern Sie international in mein Land?',
        'foot_email'        => 'E-Mail',
        'input_aria'        => 'Nachricht eingeben',
        'input_placeholder' => 'Nachricht schreiben…',
        'tabs_aria'         => 'Assistent-Bereiche',
        'tab_home'          => 'Start',
        'tab_faq'           => 'FAQ',
        'back_aria'         => 'Zurück',
        'help_centre'       => 'Hilfebereich',
        'faq_heading'       => 'Häufige Fragen',
        'faq_search'        => 'Fragen durchsuchen',
        'faq_q1_label'      => 'Wie lang ist Ihre übliche Lieferzeit?',
        'faq_q1_fill'       => 'Wie lang ist Ihre übliche Lieferzeit?',
        'faq_q2_label'      => 'Fertigen Sie Muster nach meiner Zeichnung?',
        'faq_q2_fill'       => 'Können Sie ein individuelles Muster nach meiner Zeichnung fertigen?',
        'faq_q3_label'      => 'Liefern Sie in mein Land?',
        'faq_q3_fill'       => 'Liefern Sie international in mein Land?',
        'faq_q4_label'      => 'Welche Materialien und Oberflächen bieten Sie an?',
        'faq_q4_fill'       => 'Welche Edelstahl-Güten und PVD-Oberflächen bieten Sie an?',
        'faq_q5_label'      => 'Können Sie CAD-Dateien senden?',
        'faq_q5_fill'       => 'Können Sie CAD-Dateien für ein Muster bereitstellen?',
        'ask_assistant'     => 'Assistenten fragen',
    ),
    'fr' => array(
        'brand_tag'         => 'Conseiller IA',
        'desk_closed'       => 'Bureau fermé',
        'close_aria'        => 'Fermer l’assistant',
        'welcome'           => 'Bienvenue',
        'hero_sub'          => 'Comment pouvons-nous vous aider aujourd’hui ?',
        'ai_assistant'      => 'Assistant IA',
        'replies_instantly' => 'Répond instantanément',
        'team_online'       => 'Équipe en ligne',
        'leave_msg_title'   => 'Laisser un message',
        'leave_msg_sub'     => 'Le bureau est actuellement fermé. Laissez un message et notre équipe vous répondra dès l’ouverture.',
        'channels_aria'     => 'Canaux de demande directe',
        'request_quote'     => 'Demander un devis',
        'whatsapp_cta'      => 'Discuter sur WhatsApp',
        'resume_title'      => 'Reprendre votre dernière conversation',
        'resume_sub'        => 'Continuer là où vous vous étiez arrêté',
        'resume_reset'      => 'Ce n’est pas votre conversation ? En démarrer une nouvelle',
        'quick_connect'     => 'Accès rapide',
        'pick_start'        => 'Choisir un sujet',
        'act1_title'        => 'Vérifier un motif',
        'act1_sub'          => 'Envoyez un code, une photo ou un plan',
        'act1_fill'         => 'J’ai un motif précis en tête. Pouvez-vous m’aider à le vérifier ?',
        'act2_title'        => 'Matériaux et finitions',
        'act2_sub'          => 'Inox 304 / 316, PVD et finitions',
        'act2_fill'         => 'Quels aciers inoxydables et quelles finitions proposez-vous ?',
        'act3_title'        => 'Parler à un conseiller',
        'act3_sub'          => 'Contacter l’équipe Metal Divider',
        'act3_fill'         => 'Je souhaiterais parler à un conseiller humain, s’il vous plaît.',
        'browse_faq'        => 'Voir les questions fréquentes',
        'chip1_label'       => 'Recommander des motifs',
        'chip1_fill'        => 'Pouvez-vous recommander un motif géométrique avec 30-40% d’ouverture pour brise-vue extérieur ?',
        'chip2_label'       => 'Délai de fabrication',
        'chip2_fill'        => 'Quel est votre délai de livraison habituel ?',
        'chip3_label'       => 'Motifs sur mesure',
        'chip3_fill'        => 'Pouvez-vous réaliser un motif sur mesure à partir de mon plan ?',
        'chip4_label'       => 'Expédition',
        'chip4_fill'        => 'Livrez-vous à l’international dans mon pays ?',
        'foot_email'        => 'E-mail',
        'input_aria'        => 'Saisissez votre message',
        'input_placeholder' => 'Écrire un message…',
        'tabs_aria'         => 'Sections de l’assistant',
        'tab_home'          => 'Accueil',
        'tab_faq'           => 'FAQ',
        'back_aria'         => 'Retour',
        'help_centre'       => 'Centre d’aide',
        'faq_heading'       => 'Questions fréquentes',
        'faq_search'        => 'Rechercher une question',
        'faq_q1_label'      => 'Quel est votre délai de livraison habituel ?',
        'faq_q1_fill'       => 'Quel est votre délai de livraison habituel ?',
        'faq_q2_label'      => 'Pouvez-vous reproduire un motif depuis mon plan ?',
        'faq_q2_fill'       => 'Pouvez-vous réaliser un motif sur mesure à partir de mon plan ?',
        'faq_q3_label'      => 'Livrez-vous dans mon pays ?',
        'faq_q3_fill'       => 'Livrez-vous à l’international dans mon pays ?',
        'faq_q4_label'      => 'Quels matériaux et finitions proposez-vous ?',
        'faq_q4_fill'       => 'Quels aciers inoxydables et quelles finitions proposez-vous ?',
        'faq_q5_label'      => 'Pouvez-vous envoyer des fichiers CAO / CAD ?',
        'faq_q5_fill'       => 'Pouvez-vous fournir les fichiers CAD d’un motif ?',
        'ask_assistant'     => 'Demander à l’assistant',
    ),
    'ar' => array(
        'brand_tag'         => 'مستشار ذكي',
        'desk_closed'       => 'خارج أوقات العمل',
        'close_aria'        => 'إغلاق المساعد',
        'welcome'           => 'مرحباً بك',
        'hero_sub'          => 'كيف يمكننا مساعدتك في مشروعك اليوم؟',
        'ai_assistant'      => 'المساعد الذكي',
        'replies_instantly' => 'يرد فوراً',
        'team_online'       => 'الفريق متصل',
        'leave_msg_title'   => 'اترك رسالة',
        'leave_msg_sub'     => 'المكتب مغلق حالياً. اترك رسالتك وسيقوم فريقنا بالرد عليك فور بدء الدوام.',
        'channels_aria'     => 'قنوات الاستفسار المباشر',
        'request_quote'     => 'طلب عرض سعر',
        'whatsapp_cta'      => 'تواصل عبر واتساب',
        'resume_title'      => 'متابعة محادثتك الأخيرة',
        'resume_sub'        => 'أكمل من حيث توقفت',
        'resume_reset'      => 'ليست محادثتك؟ ابدأ محادثة جديدة',
        'quick_connect'     => 'تواصل سريع',
        'pick_start'        => 'اختر موضوعاً',
        'act1_title'        => 'استفسر عن نمط محدد',
        'act1_sub'          => 'أرسل رمز النمط أو صورة أو مخططاً',
        'act1_fill'         => 'لدي نمط معين في ذهني. هل يمكنك مساعدتي في التحقق منه؟',
        'act2_title'        => 'الخامات والتشطيبات',
        'act2_sub'          => 'ستانلس ستيل 304 / 316 وطلاء PVD',
        'act2_fill'         => 'ما هي درجات الفولاذ المقاوم للصدأ والتشطيبات المتوفرة لديكم؟',
        'act3_title'        => 'التحدث مع مختص',
        'act3_sub'          => 'التواصل المباشر مع فريق Metal Divider',
        'act3_fill'         => 'أرغب في التحدث مع موظف من خدمة العملاء من فضلك.',
        'browse_faq'        => 'تصفح الأسئلة الشائعة',
        'chip1_label'       => 'ترشيح أنماط',
        'chip1_fill'        => 'هل يمكنك ترشيح نمط هندسي بنسبة فتحات 30-40% للخصوصية الخارجية؟',
        'chip2_label'       => 'مدة التصنيع',
        'chip2_fill'        => 'ما هي مدة التصنيع والتسليم المعتادة؟',
        'chip3_label'       => 'أنماط مخصصة',
        'chip3_fill'        => 'هل يمكنكم تنفيذ نمط مخصص مطابق لمخططي الهندسي؟',
        'chip4_label'       => 'الشحن الدولي',
        'chip4_fill'        => 'هل تشحنون إلى بلدي عبر الشحن البحري أو الجوي؟',
        'foot_email'        => 'البريد الإلكتروني',
        'input_aria'        => 'اكتب رسالتك',
        'input_placeholder' => 'اكتب رسالتك…',
        'tabs_aria'         => 'أقسام المساعد',
        'tab_home'          => 'الرئيسية',
        'tab_faq'           => 'الأسئلة',
        'back_aria'         => 'رجوع',
        'help_centre'       => 'مركز المساعدة',
        'faq_heading'       => 'الأسئلة الشائعة',
        'faq_search'        => 'ابحث في الأسئلة',
        'faq_q1_label'      => 'ما هي مدة التصنيع والتسليم المعتادة؟',
        'faq_q1_fill'       => 'ما هي مدة التصنيع والتسليم المعتادة؟',
        'faq_q2_label'      => 'هل يمكنكم تنفيذ نمط مطابق لمخططي؟',
        'faq_q2_fill'       => 'هل يمكنكم تنفيذ نمط مخصص مطابق لمخططي الهندسي؟',
        'faq_q3_label'      => 'هل تشحنون إلى بلدي؟',
        'faq_q3_fill'       => 'هل تشحنون إلى بلدي عبر الشحن البحري أو الجوي؟',
        'faq_q4_label'      => 'ما الخامات والتشطيبات المتوفرة لديكم؟',
        'faq_q4_fill'       => 'ما هي درجات الفولاذ المقاوم للصدأ والتشطيبات المتوفرة لديكم؟',
        'faq_q5_label'      => 'هل يمكنكم إرسال ملفات CAD؟',
        'faq_q5_fill'       => 'هل يمكنكم تزويدي بملفات CAD للنمط؟',
        'ask_assistant'     => 'اسأل المساعد الذكي',
    ),
    'id' => array(
        'brand_tag'         => 'Konsultan AI',
        'desk_closed'       => 'Layanan tutup',
        'close_aria'        => 'Tutup asisten',
        'welcome'           => 'Selamat datang',
        'hero_sub'          => 'Bagaimana kami dapat membantu proyek Anda hari ini?',
        'ai_assistant'      => 'Asisten AI',
        'replies_instantly' => 'Membalas instan',
        'team_online'       => 'Tim online',
        'leave_msg_title'   => 'Tinggalkan pesan',
        'leave_msg_sub'     => 'Layanan sedang tutup saat ini. Tinggalkan pesan dan tim kami akan membalas saat jam kerja.',
        'channels_aria'     => 'Saluran permintaan langsung',
        'request_quote'     => 'Minta Penawaran',
        'whatsapp_cta'      => 'Chat di WhatsApp',
        'resume_title'      => 'Lanjutkan percakapan terakhir',
        'resume_sub'        => 'Mulai kembali dari bagian terakhir',
        'resume_reset'      => 'Bukan percakapan Anda? Mulai baru',
        'quick_connect'     => 'Akses Cepat',
        'pick_start'        => 'Pilih topik',
        'act1_title'        => 'Tanyakan pola tertentu',
        'act1_sub'          => 'Kirim kode pola, foto, atau gambar kerja',
        'act1_fill'         => 'Saya sudah memiliki pilihan pola. Bisa bantu memeriksanya?',
        'act2_title'        => 'Material & finishing',
        'act2_sub'          => 'Baja nirkarat 304 / 316, PVD & finishing',
        'act2_fill'         => 'Material baja tahan karat dan finishing apa saja yang Anda tawarkan?',
        'act3_title'        => 'Bicara dengan tim kami',
        'act3_sub'          => 'Terhubung langsung dengan tim Metal Divider',
        'act3_fill'         => 'Saya ingin bicara dengan petugas tim Metal Divider.',
        'browse_faq'        => 'Lihat pertanyaan umum',
        'chip1_label'       => 'Rekomendasi pola',
        'chip1_fill'        => 'Bisakah Anda merekomendasikan pola geometris dengan 30-40% area terbuka untuk privasi luar ruangan?',
        'chip2_label'       => 'Waktu produksi',
        'chip2_fill'        => 'Berapa lama waktu pengerjaan dan produksi biasanya?',
        'chip3_label'       => 'Pola kustom',
        'chip3_fill'        => 'Bisakah Anda membuat pola kustom sesuai gambar kerja saya?',
        'chip4_label'       => 'Pengiriman',
        'chip4_fill'        => 'Apakah Anda melayani pengiriman internasional ke negara saya?',
        'foot_email'        => 'Email',
        'input_aria'        => 'Ketik pesan Anda',
        'input_placeholder' => 'Tulis pesan…',
        'tabs_aria'         => 'Bagian asisten',
        'tab_home'          => 'Beranda',
        'tab_faq'           => 'FAQ',
        'back_aria'         => 'Kembali',
        'help_centre'       => 'Pusat bantuan',
        'faq_heading'       => 'Pertanyaan umum',
        'faq_search'        => 'Cari pertanyaan',
        'faq_q1_label'      => 'Berapa lama waktu produksi biasanya?',
        'faq_q1_fill'       => 'Berapa lama waktu pengerjaan dan produksi biasanya?',
        'faq_q2_label'      => 'Bisakah membuat pola dari gambar kerja saya?',
        'faq_q2_fill'       => 'Bisakah Anda membuat pola kustom sesuai gambar kerja saya?',
        'faq_q3_label'      => 'Apakah bisa kirim ke negara saya?',
        'faq_q3_fill'       => 'Apakah Anda melayani pengiriman internasional ke negara saya?',
        'faq_q4_label'      => 'Material dan finishing apa yang tersedia?',
        'faq_q4_fill'       => 'Material baja tahan karat dan finishing apa saja yang Anda tawarkan?',
        'faq_q5_label'      => 'Bisakah mengirim file CAD?',
        'faq_q5_fill'       => 'Bisakah Anda mengirimkan file CAD untuk pola ini?',
        'ask_assistant'     => 'Tanya asisten',
    ),
    'vi' => array(
        'brand_tag'         => 'Tư vấn AI',
        'desk_closed'       => 'Ngoài giờ làm việc',
        'close_aria'        => 'Đóng trợ lý',
        'welcome'           => 'Xin chào',
        'hero_sub'          => 'Hôm nay chúng tôi có thể hỗ trợ gì cho dự án của bạn?',
        'ai_assistant'      => 'Trợ lý AI',
        'replies_instantly' => 'Phản hồi tức thì',
        'team_online'       => 'Đội ngũ trực tuyến',
        'leave_msg_title'   => 'Để lại lời nhắn',
        'leave_msg_sub'     => 'Hiện đang ngoài giờ trực tuyến. Hãy để lại lời nhắn và đội ngũ của chúng tôi sẽ phản hồi sớm nhất.',
        'channels_aria'     => 'Kênh yêu cầu báo giá trực tiếp',
        'request_quote'     => 'Yêu cầu báo giá',
        'whatsapp_cta'      => 'Chat qua WhatsApp',
        'resume_title'      => 'Tiếp tục cuộc trò chuyện trước',
        'resume_sub'        => 'Xem lại nội dung đang trao đổi',
        'resume_reset'      => 'Không phải cuộc trò chuyện của bạn? Bắt đầu mới',
        'quick_connect'     => 'Kết nối nhanh',
        'pick_start'        => 'Chọn chủ đề',
        'act1_title'        => 'Tra cứu hoa văn',
        'act1_sub'          => 'Gửi mã hoa văn, ảnh chụp hoặc bản vẽ',
        'act1_fill'         => 'Tôi đã chọn được một mẫu hoa văn. Bạn có thể giúp tôi kiểm tra không?',
        'act2_title'        => 'Vật liệu & hoàn thiện',
        'act2_sub'          => 'Inox 304 / 316, mạ PVD & bề mặt',
        'act2_fill'         => 'Bên bạn cung cấp các mác thép không gỉ và lớp hoàn thiện bề mặt nào?',
        'act3_title'        => 'Gặp chuyên viên tư vấn',
        'act3_sub'          => 'Kết nối trực tiếp với đội ngũ Metal Divider',
        'act3_fill'         => 'Tôi muốn nói chuyện với nhân viên tư vấn của đội ngũ.',
        'browse_faq'        => 'Xem câu hỏi thường gặp',
        'chip1_label'       => 'Gợi ý hoa văn',
        'chip1_fill'        => 'Bạn có thể gợi ý mẫu hoa văn hình học có tỷ lệ lỗ thoáng 30-40% cho vách ngăn ngoài trời không?',
        'chip2_label'       => 'Thời gian sản xuất',
        'chip2_fill'        => 'Thời gian gia công và giao hàng thông thường là bao lâu?',
        'chip3_label'       => 'Hoa văn theo bản vẽ',
        'chip3_fill'        => 'Bên bạn có nhận gia công hoa văn tùy chỉnh theo bản vẽ của tôi không?',
        'chip4_label'       => 'Vận chuyển',
        'chip4_fill'        => 'Bên bạn có giao hàng quốc tế đến quốc gia của tôi không?',
        'foot_email'        => 'Email',
        'input_aria'        => 'Nhập tin nhắn của bạn',
        'input_placeholder' => 'Nhập tin nhắn…',
        'tabs_aria'         => 'Các mục trợ lý',
        'tab_home'          => 'Trang chủ',
        'tab_faq'           => 'Hỏi đáp',
        'back_aria'         => 'Quay lại',
        'help_centre'       => 'Trung tâm trợ giúp',
        'faq_heading'       => 'Câu hỏi thường gặp',
        'faq_search'        => 'Tìm câu hỏi',
        'faq_q1_label'      => 'Thời gian sản xuất thông thường là bao lâu?',
        'faq_q1_fill'       => 'Thời gian gia công và giao hàng thông thường là bao lâu?',
        'faq_q2_label'      => 'Có thể gia công theo bản vẽ riêng không?',
        'faq_q2_fill'       => 'Bên bạn có nhận gia công hoa văn tùy chỉnh theo bản vẽ của tôi không?',
        'faq_q3_label'      => 'Có vận chuyển đến quốc gia của tôi không?',
        'faq_q3_fill'       => 'Bên bạn có giao hàng quốc tế đến quốc gia của tôi không?',
        'faq_q4_label'      => 'Có những vật liệu và bề mặt hoàn thiện nào?',
        'faq_q4_fill'       => 'Bên bạn cung cấp các mác thép không gỉ và lớp hoàn thiện bề mặt nào?',
        'faq_q5_label'      => 'Có thể gửi file CAD không?',
        'faq_q5_fill'       => 'Bạn có thể gửi file CAD của mẫu hoa văn này không?',
        'ask_assistant'     => 'Hỏi trợ lý AI',
    ),
);
$mdvT = $mdvI18nAll[$mdvLang] ?? $mdvI18nAll['en'];
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim(erLhcoreClassSystem::instance()->wwwDir(), '/') . '/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor.css?v=20261111b', ENT_QUOTES, 'UTF-8'); ?>">
<script>
(function(){
  var lang = <?php echo json_encode($mdvLang); ?>;
  var dir = <?php echo json_encode($mdvDir); ?>;
  document.documentElement.setAttribute('lang', lang);
  document.documentElement.setAttribute('dir', dir);
  try {
    window.sessionStorage.setItem('mdv_lang', lang);
    window.sessionStorage.setItem('mdv_dir', dir);
  } catch (e) {}
}());
</script>

<?php if (isset($maintenance_mode)) : ?>
    <div class="pt-2">
        <?php $errors[] = $maintenance_mode;?>
        <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
    </div>
<?php else : ?>

<?php if ($disabled_department === true) : ?>

<h1><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Department is disabled');?></h1>

<?php elseif (isset($department_invalid) && $department_invalid === true) : ?>

    <?php $errors[] =erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Please provide a department');?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>

<?php else : ?>

<?php if (!isset($start_data_fields['show_operator_profile']) || $start_data_fields['show_operator_profile'] == false) : ?>
<div class="pl10 max-width-180 float-end absolute-language-top-right">
	<?php $rightLanguage = true;?>
	<?php include(erLhcoreClassDesign::designtpl('pagelayouts/parts/switch_language.tpl.php'));?>
</div>
<?php endif;?>

<?php include(erLhcoreClassDesign::designtpl('lhchat/getstatus/widget_geo_adjustment.tpl.php'));?>
<?php if ($exitTemplate == true) return; ?>

<?php $isOnlineGeneral = erLhcoreClassChat::isOnline($department, false, array('ignore_user_status'=> (int)erLhcoreClassModelChatConfig::fetch('ignore_user_status')->current_value, 'online_timeout' => (int)erLhcoreClassModelChatConfig::fetch('sync_sound_settings')->data['online_timeout']))?>

<?php if ($leaveamessage == false || ($forceoffline === false && $isOnlineGeneral === true)) : ?>


<?php if ($isOnlineGeneral === false) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhchat/chat_not_available.tpl.php'));?>
<?php else : ?>

<?php if (isset($start_data_fields['pre_chat_html']) && $start_data_fields['pre_chat_html'] != '') : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/pre_chat_html.tpl.php'));?>
<?php endif?>

<?php $onlyBotOnline = erLhcoreClassChat::isOnlyBotOnline($department); ?>

<?php if (isset($start_data_fields['show_operator_profile']) && $start_data_fields['show_operator_profile'] == true) : ?>
<?php include_once(erLhcoreClassDesign::designtpl('lhchat/part/operator_profile_start_chat.tpl.php'));?>
<?php endif;?>

<?php if (isset($errors['blocked_user'])) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/blocked_user_startchat.tpl.php'));?>
<?php elseif (isset($errors)) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
<?php endif; ?>

<?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/chatwidget_pre_form_multiinclude.tpl.php'));?>

<?php $hasExtraField = false;

if ($theme !== false && $theme->explain_text != '' && $onlyBotOnline == false) : ?>
<p class="start-chat-intro"><?php echo erLhcoreClassBBCode::make_clickable(htmlspecialchars($theme->explain_text))?></p>
<?php endif;?>

<?php if (isset($theme) && $theme !== false && isset($theme->bot_configuration_array['custom_html_widget']) && !empty($theme->bot_configuration_array['custom_html_widget']) && $onlyBotOnline == false) : ?>
       <?php echo $theme->bot_configuration_array['custom_html_widget']?>
<?php elseif (isset($theme) && $theme !== false && isset($theme->bot_configuration_array['custom_html_widget_bot']) && !empty($theme->bot_configuration_array['custom_html_widget_bot']) && $onlyBotOnline == true) : ?>
    <?php echo $theme->bot_configuration_array['custom_html_widget_bot']?>
<?php elseif (isset($theme) && $theme !== false && isset($theme->bot_configuration_array['trigger_id']) && !empty($theme->bot_configuration_array['trigger_id']) && $theme->bot_configuration_array['trigger_id'] > 0) :  ?>
    <?php include(erLhcoreClassDesign::designtpl('lhchat/part/render_intro.tpl.php'));?>
<?php endif;?>

<?php if (isset($theme) && $theme !== false && isset($theme->bot_configuration_array['inject_html']) && !empty($theme->bot_configuration_array['inject_html'])) : ?>
            <script>lhinst.sendHTML(<?php echo $theme->id?>,{'type':'theme','id':0});</script>
<?php endif; ?>

<?php $mdvTheme = (isset($_GET['mdvtheme']) && $_GET['mdvtheme'] === 'forest') ? 'forest' : 'charcoal'; ?>
<div class="md-card md-start" data-theme="<?php echo $mdvTheme; ?>" dir="<?php echo $mdvEsc($mdvDir); ?>" data-mdv-lang="<?php echo $mdvEsc($mdvLang); ?>">
  <div id="mdv-live" class="md-sr" aria-live="polite"></div>
  <div class="md-view md-home is-active" data-mdv-view="home">
    <header class="md-hero">
      <div class="md-topline">
        <div class="md-brand"><svg class="md-brand-mark" viewBox="0 0 28 28" aria-hidden="true"><rect x="0.75" y="0.75" width="26.5" height="26.5" fill="none" stroke="currentColor" stroke-width="1"/><text x="14" y="17.5" text-anchor="middle" font-size="9" font-family="Georgia, Times New Roman, serif" fill="currentColor">MD</text></svg><span class="md-brand-name">Metal Divider</span><span class="md-brand-tag"><?php echo $mdvEsc($mdvT['brand_tag']); ?></span></div>
        <button class="md-icon-btn md-close" type="button" data-mdv-close aria-label="<?php echo $mdvEsc($mdvT['close_aria']); ?>">×</button>
      </div>
      <div class="md-avatar md-avatar-hero" aria-hidden="true"><svg viewBox="0 0 32 32"><text x="16" y="20" text-anchor="middle" font-size="11" font-family="Georgia, Times New Roman, serif" fill="currentColor">MD</text></svg><i></i></div>
      <h1><?php echo $mdvEsc($mdvT['welcome']); ?></h1>
      <p class="md-hero-sub"><?php echo $mdvEsc($mdvT['hero_sub']); ?></p>
      <?php if ($onlyBotOnline) : ?>
      <div class="md-status"><span class="md-pulse"></span><span><?php echo $mdvEsc($mdvT['ai_assistant']); ?></span><span class="md-status-sep">·</span><span><?php echo $mdvEsc($mdvT['replies_instantly']); ?></span></div>
      <?php else : ?>
      <div class="md-status"><span class="md-pulse"></span><span><?php echo $mdvEsc($mdvT['ai_assistant']); ?></span><span class="md-status-sep">·</span><span><?php echo $mdvEsc($mdvT['team_online']); ?></span></div>
      <?php endif; ?>
    </header>
    <div class="md-convert-bar" role="region" aria-label="<?php echo $mdvEsc($mdvT['channels_aria']); ?>">
      <button class="md-convert-btn md-convert-quote" type="button" data-mdv-open-quote><?php echo $mdvEsc($mdvT['request_quote']); ?></button>
      <a class="md-convert-btn md-convert-wa" href="https://wa.me/85264731687" target="_blank" rel="noopener" data-mdv-wa><?php echo $mdvEsc($mdvT['whatsapp_cta']); ?></a>
    </div>
    <div class="md-panel md-scroll">
      <a class="md-resume" href="#" hidden>
        <span class="md-resume-icon" aria-hidden="true">↩</span>
        <span class="md-resume-copy"><b><?php echo $mdvEsc($mdvT['resume_title']); ?></b><small><?php echo $mdvEsc($mdvT['resume_sub']); ?></small></span>
        <span class="md-chev" aria-hidden="true">›</span>
      </a>
      <button class="md-resume-reset" type="button" hidden><?php echo $mdvEsc($mdvT['resume_reset']); ?></button>
      <div class="md-section-head"><p class="md-eyebrow"><svg class="md-spark" viewBox="0 0 10 10" aria-hidden="true"><path d="M5 0 L6.1 3.9 L10 5 L6.1 6.1 L5 10 L3.9 6.1 L0 5 L3.9 3.9 Z" fill="currentColor"/></svg> <?php echo $mdvEsc($mdvT['quick_connect']); ?></p><span class="md-section-hint"><?php echo $mdvEsc($mdvT['pick_start']); ?></span></div>
      <button class="md-action" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['act1_fill']); ?>">
        <span class="md-action-icon">▦</span>
        <span class="md-action-copy"><b><?php echo $mdvEsc($mdvT['act1_title']); ?></b><small><?php echo $mdvEsc($mdvT['act1_sub']); ?></small></span>
        <span class="md-chev">›</span>
      </button>
      <button class="md-action" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['act2_fill']); ?>">
        <span class="md-action-icon">◫</span>
        <span class="md-action-copy"><b><?php echo $mdvEsc($mdvT['act2_title']); ?></b><small><?php echo $mdvEsc($mdvT['act2_sub']); ?></small></span>
        <span class="md-chev">›</span>
      </button>
      <button class="md-action" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['act3_fill']); ?>">
        <span class="md-action-icon">◉</span>
        <span class="md-action-copy"><b><?php echo $mdvEsc($mdvT['act3_title']); ?></b><small><?php echo $mdvEsc($mdvT['act3_sub']); ?></small></span>
        <span class="md-chev">›</span>
      </button>
      <button class="md-text-link" type="button" data-mdv-go="faq"><?php echo $mdvEsc($mdvT['browse_faq']); ?> <span>→</span></button>
      <div class="md-chips">
        <button type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['chip1_fill']); ?>"><?php echo $mdvEsc($mdvT['chip1_label']); ?></button>
        <button type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['chip2_fill']); ?>"><?php echo $mdvEsc($mdvT['chip2_label']); ?></button>
        <button type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['chip3_fill']); ?>"><?php echo $mdvEsc($mdvT['chip3_label']); ?></button>
        <button type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['chip4_fill']); ?>"><?php echo $mdvEsc($mdvT['chip4_label']); ?></button>
      </div>
      <div class="md-panel-foot"><span class="md-foot-mark">MD</span><a href="https://wa.me/85264731687" target="_blank" rel="noopener" data-mdv-wa>WhatsApp</a><span class="md-foot-dot"></span><a href="mailto:info@metal-divider.com"><?php echo $mdvEsc($mdvT['foot_email']); ?></a></div>
    </div>
    <div class="md-composer md-start-composer">
<form method="post" id="form-start-chat" action="<?php echo erLhcoreClassDesign::baseurl('chat/chatwidget')?><?php echo $append_mode?><?php $department !== false ? print '/(department)/'.$department : ''?><?php $input_data->priority !== false ? print '/(priority)/'.$input_data->priority : ''?><?php $input_data->vid !== false ? print '/(vid)/'.htmlspecialchars($input_data->vid) : ''?><?php $input_data->hash_resume !== false ? print '/(hash_resume)/'.htmlspecialchars($input_data->hash_resume) : ''?><?php $leaveamessage == true ? print '/(leaveamessage)/true' : ''?><?php $forceoffline == true ? print '/(offline)/true' : ''?><?php echo $append_mode_theme?>" onsubmit="return <?php if (isset($start_data_fields['message_auto_start']) && $start_data_fields['message_auto_start'] == true) : ?>lhinst.prestartChat('<?php echo time()?>',$(this))<?php else : ?>lhinst.addCaptchaSubmit('<?php echo time()?>',$(this))<?php endif?>">

<?php if (isset($start_data_fields['message_visible_in_page_widget']) && $start_data_fields['message_visible_in_page_widget'] == true && isset($start_data_fields['show_messages_box']) && $start_data_fields['show_messages_box'] == true) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhchat/startchatformsettings/presend.tpl.php'));?>
<?php endif;?>

<?php $formResubmitId = 'form-start-chat'; ?>
<?php include(erLhcoreClassDesign::designtpl('lhchat/part/auto_resubmit.tpl.php'));?>

<input type="hidden" name="onlyBotOnline" value="<?php echo $onlyBotOnline == true ? 1 : 0?>">

<div class="row">
    <?php if (isset($start_data_fields['name_visible_in_page_widget']) && $start_data_fields['name_visible_in_page_widget'] == true) : $hasExtraField = true;?>
    
    <?php if (isset($start_data_fields['name_hidden']) && $start_data_fields['name_hidden'] == true) : ?>
	<input type="hidden" name="Username" value="<?php echo htmlspecialchars($input_data->username);?>" />
	<?php else : ?>	
		<?php if (in_array('username', $input_data->hattr)) : ?>
			<input type="hidden" name="Username" value="<?php echo htmlspecialchars($input_data->username);?>" />
		<?php elseif (!($onlyBotOnline == true && isset($start_data_fields['name_hidden_bot']) && $start_data_fields['name_hidden_bot'] == true)) : ?>
		    <?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/form_parts/name_hidden.tpl.php'));?>
	    <?php endif; ?>    
    <?php endif; ?>
    
    <?php endif; ?>

    <?php if (isset($start_data_fields['email_visible_in_page_widget']) && $start_data_fields['email_visible_in_page_widget'] == true) : $hasExtraField = true;?>
    
    <?php if (isset($start_data_fields['email_hidden']) && $start_data_fields['email_hidden'] == true) : ?>
	<input type="hidden" name="Email" value="<?php echo htmlspecialchars($input_data->email);?>" />
	<?php else : ?>
		<?php if (in_array('email', $input_data->hattr)) : ?>
			<input type="hidden" name="Email" value="<?php echo htmlspecialchars($input_data->email);?>" />
		<?php elseif (!($onlyBotOnline == true && isset($start_data_fields['email_hidden_bot']) && $start_data_fields['email_hidden_bot'] == true)) : ?>
	    <div class="col-6 form-group">
	        <label class="col-form-label"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','E-mail');?><?php if (isset($start_data_fields['email_require_option']) && $start_data_fields['email_require_option'] == 'required') : ?>*<?php endif;?></label>
	        <input <?php if (!(isset($is_embed_mode) && $is_embed_mode ==true)) :?><?php endif;?> class="form-control form-control-sm<?php if (isset($errors['email'])) : ?> is-invalid<?php endif;?>" aria-label="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Enter your email address')?>" placeholder="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Enter your email address')?>" <?php if (isset($start_data_fields['email_require_option']) && $start_data_fields['email_require_option'] == 'required') : ?>aria-required="true" required<?php endif;?> type="text" name="Email" value="<?php echo htmlspecialchars($input_data->email);?>" />
	    </div>
	    <?php endif; ?>
    <?php endif; ?>
    
    <?php endif; ?>
</div>

<?php if (isset($start_data_fields['phone_visible_in_page_widget']) && $start_data_fields['phone_visible_in_page_widget'] == true) : $hasExtraField = true;?>
<?php if (isset($start_data_fields['phone_hidden']) && $start_data_fields['phone_hidden'] == true) : ?>
<input type="hidden" name="Phone" value="<?php echo htmlspecialchars($input_data->phone);?>" />
<?php else : ?>
		<?php if (in_array('phone', $input_data->hattr)) : ?>
		<input type="hidden" name="Phone" value="<?php echo htmlspecialchars($input_data->phone);?>" />
		<?php elseif (!($onlyBotOnline == true && isset($start_data_fields['phone_hidden_bot']) && $start_data_fields['phone_hidden_bot'] == true)) : ?>
		<div class="form-group">
		  <label class="col-form-label"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Phone');?><?php if (isset($start_data_fields['phone_require_option']) && $start_data_fields['phone_require_option'] == 'required') : ?>*<?php endif;?></label>
		  <input <?php if (!(isset($is_embed_mode) && $is_embed_mode ==true)) :?><?php endif;?> <?php if (isset($start_data_fields['phone_require_option']) && $start_data_fields['phone_require_option'] == 'required') : ?>aria-required="true" required<?php endif;?> aria-label="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Enter your phone')?>" placeholder="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Enter your phone')?>" class="form-control form-control-sm<?php if (isset($errors['phone'])) : ?> is-invalid<?php endif;?>" aria-label="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Enter your phone')?>" type="text" name="Phone" value="<?php echo htmlspecialchars($input_data->phone);?>" />
		</div>
		<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php $canReopen = erLhcoreClassModelChatConfig::fetch('reopen_chat_enabled')->current_value == 1 && ($reopenData = erLhcoreClassChat::canReopenDirectly(array('reopen_closed' => erLhcoreClassModelChatConfig::fetch('allow_reopen_closed')->current_value))) !== false; ?>



<?php $adminCustomFieldsMode = 'on';?>
<?php include(erLhcoreClassDesign::designtpl('lhchat/part/admin_form_variables.tpl.php'));?>

<?php if (isset($start_data_fields['message_visible_in_page_widget']) && $start_data_fields['message_visible_in_page_widget'] == true) : ?>
<?php if (isset($start_data_fields['message_hidden']) && $start_data_fields['message_hidden'] == true) : $hasExtraField = true; ?>

<div id="ChatMessageContainer">
<?php include(erLhcoreClassDesign::designtpl('lhchat/part/above_text_area_send_button.tpl.php'));?>
<textarea class="hide" placeholder="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Enter your message');?>" name="Question"><?php echo htmlspecialchars($input_data->question);?></textarea>
</div>

<?php elseif (!($onlyBotOnline == true && isset($start_data_fields['message_hidden_bot']) && $start_data_fields['message_hidden_bot'] == true)) : ?>
<div class="<?php if (isset($errors['question'])) : ?> is-invalid<?php endif;?>">
<?php if (!isset($start_data_fields['hide_message_label']) || $start_data_fields['hide_message_label'] == false) : ?><label class="col-form-label"><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('chat/startchat','Your question');?><?php if (isset($start_data_fields['message_require_option']) && $start_data_fields['message_require_option'] == 'required') : ?>*<?php endif;?></label><?php endif;?>
<?php include(erLhcoreClassDesign::designtpl('lhchat/part/above_text_area_user_start_chat.tpl.php'));?>

<div id="ChatMessageContainer">
<?php include(erLhcoreClassDesign::designtpl('lhchat/part/above_text_area_send_button.tpl.php'));?>
<textarea <?php if (!(isset($is_embed_mode) && $is_embed_mode ==true)) :?><?php endif;?> class="form-control form-control-sm form-group <?php if ($hasExtraField !== true && $canReopen !== true) : ?>btrad-reset<?php endif;?>" <?php if (isset($start_data_fields['user_msg_height']) && $start_data_fields['user_msg_height'] > 0) : ?>style="height: <?php echo $start_data_fields['user_msg_height']?>px"<?php endif;?> aria-label="<?php echo $mdvEsc($mdvT['input_aria']);?>" placeholder="<?php if ($mdvLang === 'en' && isset($theme) && $theme !== false && isset($theme->bot_configuration_array['placeholder_message']) && !empty($theme->bot_configuration_array['placeholder_message'])) : ?><?php echo htmlspecialchars($theme->bot_configuration_array['placeholder_message']); else : ?><?php echo $mdvEsc($mdvT['input_placeholder']); ?><?php endif;?>" <?php if (isset($start_data_fields['message_require_option']) && $start_data_fields['message_require_option'] == 'required') : ?>aria-required="true" required<?php endif;?> id="id_Question" name="Question"><?php echo htmlspecialchars($input_data->question);?></textarea>
</div>

<?php include(erLhcoreClassDesign::designtpl('lhchat/part/below_text_area_user_start_chat.tpl.php'));?>
</div>
<?php else : $hasExtraField = true; endif; ?>
<?php else : $hasExtraField = true; endif; ?>

<?php include_once(erLhcoreClassDesign::designtpl('lhchat/part/user_variables.tpl.php'));?>

<?php if ($department === false) : ?>
	<?php include_once(erLhcoreClassDesign::designtpl('lhchat/part/department.tpl.php'));?>
<?php endif;?>

<?php include(erLhcoreClassDesign::designtpl('lhchat/part/product.tpl.php'));?>

<?php include(erLhcoreClassDesign::designtpl('lhchat/part/user_timezone.tpl.php'));?>

<?php $tosVariable = 'tos_visible_in_page_widget';$tosCheckedVariable = 'tos_checked_online';?>
<?php include_once(erLhcoreClassDesign::designtpl('lhchat/part/accept_tos.tpl.php'));?>

<?php if ($hasExtraField == true || $canReopen == true) : ?>
<div class="btn-group" role="group" aria-label="...">  
  <?php if ($hasExtraField === true) : ?>
  <?php include(erLhcoreClassDesign::designtpl('lhchat/part/buttons/start_chat_button_widget.tpl.php'));?>
  <?php endif;?>
  
  <?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget_button_multiinclude.tpl.php'));?>
  
  <?php if ( $canReopen == true ) : ?>
  <?php include(erLhcoreClassDesign::designtpl('lhchat/part/buttons/reopen_button_widget.tpl.php'));?>
  <?php endif; ?>
</div>
<?php endif;?>

<input type="hidden" value="<?php echo htmlspecialchars($referer);?>" name="URLRefer" />
<input type="hidden" value="<?php echo htmlspecialchars($referer_site);?>" name="r" />
<input type="hidden" value="<?php echo htmlspecialchars($input_data->operator);?>" name="operator" />
<input type="hidden" value="1" name="StartChat"/>
<?php if ($hasExtraField === true) : ?>
    <input type="hidden" value="1" id="hasFormExtraField"/>
<?php endif;?>
</form>
    </div>
    <nav class="md-tabs" aria-label="<?php echo $mdvEsc($mdvT['tabs_aria']); ?>">
      <button class="is-active" type="button" data-mdv-go="home" aria-label="<?php echo $mdvEsc($mdvT['tab_home']); ?>" aria-current="page"><span class="md-tab-icon" aria-hidden="true">⌂</span><span class="md-tab-label"><?php echo $mdvEsc($mdvT['tab_home']); ?></span></button>
      <button type="button" data-mdv-go="faq" aria-label="<?php echo $mdvEsc($mdvT['tab_faq']); ?>"><span class="md-tab-icon" aria-hidden="true">?</span><span class="md-tab-label"><?php echo $mdvEsc($mdvT['tab_faq']); ?></span></button>
    </nav>
  </div>
  <div class="md-view md-faq" data-mdv-view="faq">
    <header class="md-faq-head">
      <button class="md-icon-btn md-back" type="button" data-mdv-go="home" aria-label="<?php echo $mdvEsc($mdvT['back_aria']); ?>">‹</button>
      <div><p class="md-eyebrow"><?php echo $mdvEsc($mdvT['help_centre']); ?></p><h2><?php echo $mdvEsc($mdvT['faq_heading']); ?></h2></div>
      <button class="md-icon-btn md-close" type="button" data-mdv-close aria-label="<?php echo $mdvEsc($mdvT['close_aria']); ?>">×</button>
    </header>
    <div class="md-panel md-scroll">
      <label class="md-search"><span>⌕</span><input id="mdv-faq-search" placeholder="<?php echo $mdvEsc($mdvT['faq_search']); ?>" aria-label="<?php echo $mdvEsc($mdvT['faq_search']); ?>"></label>
      <div class="md-faq-list">
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q1_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q1_label']); ?></span><i>→</i></button>
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q2_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q2_label']); ?></span><i>→</i></button>
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q3_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q3_label']); ?></span><i>→</i></button>
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q4_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q4_label']); ?></span><i>→</i></button>
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q5_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q5_label']); ?></span><i>→</i></button>
      </div>
      <button class="md-primary" type="button" data-mdv-go="chat"><?php echo $mdvEsc($mdvT['ask_assistant']); ?> <span>→</span></button>
    </div>
    <nav class="md-tabs" aria-label="<?php echo $mdvEsc($mdvT['tabs_aria']); ?>">
      <button type="button" data-mdv-go="home" aria-label="<?php echo $mdvEsc($mdvT['tab_home']); ?>"><span class="md-tab-icon" aria-hidden="true">⌂</span><span class="md-tab-label"><?php echo $mdvEsc($mdvT['tab_home']); ?></span></button>
      <button class="is-active" type="button" data-mdv-go="faq" aria-label="<?php echo $mdvEsc($mdvT['tab_faq']); ?>"><span class="md-tab-icon" aria-hidden="true">?</span><span class="md-tab-label"><?php echo $mdvEsc($mdvT['tab_faq']); ?></span></button>
    </nav>
  </div>
</div>
<script src="<?php echo htmlspecialchars(rtrim(erLhcoreClassSystem::instance()->wwwDir(), '/') . '/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor.js?v=20261111b', ENT_QUOTES, 'UTF-8'); ?>" defer></script>

<?php if (isset($start_data_fields['pre_chat_html']) && $start_data_fields['pre_chat_html'] != '') : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/post_chat_html.tpl.php'));?>
<?php endif?>

<?php include_once(erLhcoreClassDesign::designtpl('lhchat/part/switch_to_offline.tpl.php'));?>

<?php if ($hasExtraField === false) : ?>
<script>
<?php if ($canReopen == false) : ?>
jQuery('#id_Question').addClass('mb-0');
<?php endif;?>

<?php if ($hasExtraField == false && isset($start_data_fields['message_auto_start']) && $start_data_fields['message_auto_start'] == true && isset($start_data_fields['message_auto_start_key_press']) && $start_data_fields['message_auto_start_key_press'] == true) : ?>
$('#id_Question').on('keydown', function (e) {
	if ($( "#form-start-chat").attr("key-up-started") != 1) {
    	$( "#form-start-chat").attr("key-up-started",1);
    	$( "#form-start-chat").submit();	
	}
});
<?php endif;?>

var formSubmitted = false;
jQuery('#id_Question').bind('keydown', 'return', function (evt){
	if (formSubmitted == false) {
		$( "#form-start-chat" ).submit();	
		<?php if (!isset($start_data_fields['message_auto_start']) || $start_data_fields['message_auto_start'] == false) : ?>
		formSubmitted = true;
		jQuery('#id_Question').attr('readonly','readonly');		
		<?php endif;?>
	};
	return false;	
});
</script>
<?php endif;?>

<?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/chatwidget_post_multiinclude.tpl.php'));?>

<?php endif;?>

<?php else : ?>

<?php $mdvTheme = (isset($_GET['mdvtheme']) && $_GET['mdvtheme'] === 'forest') ? 'forest' : 'charcoal'; ?>
<div class="md-card md-start md-offline" data-theme="<?php echo $mdvTheme; ?>" dir="<?php echo $mdvEsc($mdvDir); ?>" data-mdv-lang="<?php echo $mdvEsc($mdvLang); ?>">
  <div id="mdv-live" class="md-sr" aria-live="polite"></div>
  <div class="md-view md-home is-active" data-mdv-view="home">
  <header class="md-hero">
    <div class="md-topline">
      <div class="md-brand"><svg class="md-brand-mark" viewBox="0 0 28 28" aria-hidden="true"><rect x="0.75" y="0.75" width="26.5" height="26.5" fill="none" stroke="currentColor" stroke-width="1"/><text x="14" y="17.5" text-anchor="middle" font-size="9" font-family="Georgia, Times New Roman, serif" fill="currentColor">MD</text></svg><span class="md-brand-name">Metal Divider</span><span class="md-brand-tag"><?php echo $mdvEsc($mdvT['desk_closed']); ?></span></div>
      <button class="md-icon-btn md-close" type="button" data-mdv-close aria-label="<?php echo $mdvEsc($mdvT['close_aria']); ?>">×</button>
    </div>
    <div class="md-avatar md-avatar-hero" aria-hidden="true"><svg viewBox="0 0 32 32"><text x="16" y="20" text-anchor="middle" font-size="11" font-family="Georgia, Times New Roman, serif" fill="currentColor">MD</text></svg></div>
    <h1><?php echo $mdvEsc($mdvT['leave_msg_title']); ?></h1>
    <p class="md-hero-sub"><?php echo $mdvEsc($mdvT['leave_msg_sub']); ?></p>
    <div class="md-status"><span class="md-pulse md-pulse-off"></span><span><?php echo $mdvEsc($mdvT['desk_closed']); ?></span><span class="md-status-sep">·</span><span><?php echo $mdvEsc($mdvT['leave_msg_title']); ?></span></div>
  </header>
  <div class="md-convert-bar" role="region" aria-label="<?php echo $mdvEsc($mdvT['channels_aria']); ?>">
    <button class="md-convert-btn md-convert-quote" type="button" data-mdv-open-quote><?php echo $mdvEsc($mdvT['request_quote']); ?></button>
    <a class="md-convert-btn md-convert-wa" href="https://wa.me/85264731687" target="_blank" rel="noopener" data-mdv-wa><?php echo $mdvEsc($mdvT['whatsapp_cta']); ?></a>
  </div>
  <div class="md-panel md-scroll md-offline-panel">
    <a class="md-resume" href="#" hidden>
      <span class="md-resume-icon" aria-hidden="true">↩</span>
      <span class="md-resume-copy"><b><?php echo $mdvEsc($mdvT['resume_title']); ?></b><small><?php echo $mdvEsc($mdvT['resume_sub']); ?></small></span>
      <span class="md-chev" aria-hidden="true">›</span>
    </a>
    <button class="md-resume-reset" type="button" hidden><?php echo $mdvEsc($mdvT['resume_reset']); ?></button>
    <?php if (isset($start_data_fields['pre_offline_chat_html']) && $start_data_fields['pre_offline_chat_html'] != '') : ?>
        <?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/pre_offline_chat_html.tpl.php'));?>
    <?php endif?>

	<?php if (isset($start_data_fields['show_operator_profile']) && $start_data_fields['show_operator_profile'] == true) : ?>
		<div class="pl10 position-relative float-end">
		<?php $rightLanguage = true;?>
		<?php include(erLhcoreClassDesign::designtpl('pagelayouts/parts/switch_language.tpl.php'));?>
		</div>
	<?php endif;?>

	<?php if (isset($errors)) : ?>
		<?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
	<?php endif; ?>

	<?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/chatwidget_pre_offline_form_multiinclude.tpl.php'));?>

	<?php include(erLhcoreClassDesign::designtpl('lhchat/offline_form.tpl.php'));?>

    <?php if (isset($start_data_fields['pre_offline_chat_html']) && $start_data_fields['pre_offline_chat_html'] != '') : ?>
        <?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/post_offline_chat_html.tpl.php'));?>
    <?php endif?>

	<?php include(erLhcoreClassDesign::designtpl('lhchat/chatwidget/chatwidget_post_offline_form_multiinclude.tpl.php'));?>
    <div class="md-panel-foot"><span class="md-foot-mark">MD</span><a href="https://wa.me/85264731687" target="_blank" rel="noopener" data-mdv-wa>WhatsApp</a><span class="md-foot-dot"></span><a href="mailto:info@metal-divider.com"><?php echo $mdvEsc($mdvT['foot_email']); ?></a></div>
  </div>
  <nav class="md-tabs" aria-label="<?php echo $mdvEsc($mdvT['tabs_aria']); ?>">
    <button class="is-active" type="button" data-mdv-go="home" aria-label="<?php echo $mdvEsc($mdvT['tab_home']); ?>" aria-current="page"><span class="md-tab-icon" aria-hidden="true">⌂</span><span class="md-tab-label"><?php echo $mdvEsc($mdvT['tab_home']); ?></span></button>
    <button type="button" data-mdv-go="faq" aria-label="<?php echo $mdvEsc($mdvT['tab_faq']); ?>"><span class="md-tab-icon" aria-hidden="true">?</span><span class="md-tab-label"><?php echo $mdvEsc($mdvT['tab_faq']); ?></span></button>
  </nav>
  </div>
  <div class="md-view md-faq" data-mdv-view="faq">
    <header class="md-faq-head">
      <button class="md-icon-btn md-back" type="button" data-mdv-go="home" aria-label="<?php echo $mdvEsc($mdvT['back_aria']); ?>">‹</button>
      <div><p class="md-eyebrow"><?php echo $mdvEsc($mdvT['help_centre']); ?></p><h2><?php echo $mdvEsc($mdvT['faq_heading']); ?></h2></div>
      <button class="md-icon-btn md-close" type="button" data-mdv-close aria-label="<?php echo $mdvEsc($mdvT['close_aria']); ?>">×</button>
    </header>
    <div class="md-panel md-scroll">
      <label class="md-search"><span>⌕</span><input id="mdv-faq-search" placeholder="<?php echo $mdvEsc($mdvT['faq_search']); ?>" aria-label="<?php echo $mdvEsc($mdvT['faq_search']); ?>"></label>
      <div class="md-faq-list">
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q1_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q1_label']); ?></span><i>→</i></button>
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q2_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q2_label']); ?></span><i>→</i></button>
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q3_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q3_label']); ?></span><i>→</i></button>
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q4_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q4_label']); ?></span><i>→</i></button>
        <button class="md-faq-q" type="button" data-mdv-fill="<?php echo $mdvEsc($mdvT['faq_q5_fill']); ?>"><span><?php echo $mdvEsc($mdvT['faq_q5_label']); ?></span><i>→</i></button>
      </div>
      <button class="md-primary" type="button" data-mdv-go="chat"><?php echo $mdvEsc($mdvT['leave_msg_title']); ?> <span>→</span></button>
    </div>
    <nav class="md-tabs" aria-label="<?php echo $mdvEsc($mdvT['tabs_aria']); ?>">
      <button type="button" data-mdv-go="home" aria-label="<?php echo $mdvEsc($mdvT['tab_home']); ?>"><span class="md-tab-icon" aria-hidden="true">⌂</span><span class="md-tab-label"><?php echo $mdvEsc($mdvT['tab_home']); ?></span></button>
      <button class="is-active" type="button" data-mdv-go="faq" aria-label="<?php echo $mdvEsc($mdvT['tab_faq']); ?>" aria-current="page"><span class="md-tab-icon" aria-hidden="true">?</span><span class="md-tab-label"><?php echo $mdvEsc($mdvT['tab_faq']); ?></span></button>
    </nav>
  </div>
</div>
<script src="<?php echo htmlspecialchars(rtrim(erLhcoreClassSystem::instance()->wwwDir(), '/') . '/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor.js?v=20261111b', ENT_QUOTES, 'UTF-8'); ?>" defer></script>

<?php endif;?>

<?php endif;?>

<?php endif;?>