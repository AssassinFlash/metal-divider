# Knocket 访客体验与视觉对标

- 票：[研究：Knocket 功能/视觉对标](https://github.com/AssassinFlash/metal-divider/issues/156)
- 父地图：[Wayfinder map：评审站 AI 客服](https://github.com/AssassinFlash/metal-divider/issues/148)（本文件不改地图）
- 日期：2026-10-27
- 分支：`research/knocket-benchmark-2`（从 `origin/main` 的 `f2a8bde` 拉出，不合并，不开 PR）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-156`
- 截图：`/Users/chesterjohn/Documents/metal-divider/.scratch/verify-156/`（不入库）
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树；不删除分支，不删除本文件

本文件只回答一件事：以 Knocket 为视觉与能力标尺，Chatwoot、Crisp、Intercom、Tidio 为参照，Metal Divider 评审站现在的 LHC 访客面和座席面还缺什么、什么已经裁定不再做。不改栈，不重新选型，不部署 Chatwoot 或 Dify，不加订阅。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 2026-10-27 用 opencli 真 Chrome 打开公开页，读到的 DOM、computed style 或 HTTP 正文 |
| 官方宣称 | 官方教程、帮助中心、README。没有登录对方控制台，也没有替对方发出一条聊天 |
| 已裁定 | #149–#152 已关闭的结论。本文件复述，不重新提案 |
| 无法确认 | 公开页没写，两份官方材料互相矛盾，或评审站当时不提供可测的挂件 |

未注册 Knocket / Chatwoot / Crisp / Intercom / Tidio 账号。未向任何挂件发送消息。评审站只做 GET 和页面阅读，没有登录后台，没有改配置。

## 方法

- 浏览器只走 `/Users/chesterjohn/.local/bin/opencli` 2.0.2，本机真 Chrome。Knocket 挂件在 open shadow root 里，`browser click --text` 报 `semantic_not_found`；数字 ref 点不到，因为 `document.querySelector` 不穿透 shadow。可用路径是把标签页切到前台，把宿主 `#contact-widget-auto` 的盒子盖住目标按钮（宿主 `zoom: 0.9`，CSS 坐标要除以 0.9），再 CDP 点击宿主。后台点击的原文是 `Native input requires a visible browser page; input was not dispatched.`
- Apify `apify/website-content-crawler` 两次运行成功：`cl65C1I7tfmXIitpi`（dataset `8dzCZhs813vJ0QBSG`，只抓挂件教程）和 `hQwORTRWadgTiVgiA`（dataset `nvZYABA8dn5fPZtqQ`，7 页）。更早一次运行 `Bz61LM39eQZXTu5WW` 对当前令牌返回 403，属于另一个账号的 run，不作为证据。
- 官方 HTML 另用直接 GET 和 Firecrawl 交叉读过。Tidio 帮助中心直接 GET 返回 403，改用 Firecrawl 读到正文。

## 结论速览

1. Knocket 公开挂件不是一条聊天气泡，而是一张先于对话的卡片。桌面实测 324×551、圆角 20px；390px 宽的截图里卡片铺满视口，底栏仍是 Home / Chat / FAQ。Chatwoot 自己网站上的真挂件（不是营销插画）打开后也是主页：问候、在线状态、Start Conversation、Popular Articles，外壳 400×626、圆角 16px。Intercom 官方把同一结构叫做 Home space。我们不该为了像素去重做这张卡片；该抄的是「先给答案和入口，再进对话」。
2. 2026-10-27 22:13（北京时间）访问 `https://staging.metal-divider.com/chat/` 和 `/chat/index.php/chat/start`，两处都是「Chat is currently unavailable. Please try again later.」没有离线表单，没有开始聊天。这个时刻已经过了 #154 记录的值班结束点（北京 21:00）。LHC 官方主题文档里有离线表单。所以这是「下班后访客无路可留」的观察，不是「挂件坏了」的证明，也不是新功能。验收要在 21:00 之后复测：应看到 LHC 原生离线表单，而不是这句 unavailable。
3. 同一时刻，`https://staging.metal-divider.com/` 是默认 WordPress 主题（Hello world!，页脚中文，日期 26 10 月 2026），HTML 里没有 lhc / widget / knocket。访客挂件的视觉验收不能在这个首页上做。这是环境观察，不是本票要修的主题。
4. 值得做的展示差只有两处，都用现有 LHC，不新增控件：机器人消息使用稳定的 AI 名称；转人工之后显示操作员名称。#149 已永久排除「交回 AI」假按钮，本文件不重新提案。
5. #149–#152 排除项仍然有效。本轮复读官方材料后，没有一条证据把它们翻回来。218 条话术库、访客上传、CSAT、暗色、第二座席工具，都不进下一批。

## 已裁定，不得再提案

| 来源 | 不再做 |
| --- | --- |
| #149 | 重写座席；「交回 AI」（`!gotobot` / `changestatus-to-bot` 只改状态，不设 `gbot_id`，不跑触发器）；罐头选择器；输入状态控件；在线足迹；完整 `chat/list`；座席间转接 UI；官方移动 App；座席 Web Push。访客上传是业务决定，当时 `lh_chat_file` 为 0 行，本文件不把它改成「建议打开」 |
| #150 | 轮询、班组、容量、宏、批量、命令面板、联系人 CRM、全文搜索、优先级、碰撞。第二个人类座席出现之前，只用 LHC 原生手动转接。不抄 Chatwoot 付费的 `advanced_assignment` / `advanced_search` / `conversation_monitors`。不重新部署 Chatwoot |
| #151 | 换栈；CSAT；访客参考页；把 218 条话术库搬进来；Captain / ChatterMate / Dify；从聊天自动发布知识。图案规格不得经「加入知识」写进 FAQ（#102 仍开着） |
| #152 | 交回 AI；离开 / 回到待接入；分配；模板卡片按钮；在微信里回复；官方 LHC App；座席 Web Push；第二邮箱收件箱；Telegram；新的付费服务 |

#153 已做：罐头填入输入框（可编辑，不自动发送，不用 `html_snippet`）、备注可写。#154 已做：值班、企业微信 textcard、补发、`changestatus` 关闭。#155 已做：只有 `no_knowledge` 写入未答清单。这些行在矩阵里标「已交付」，不重复提案。

## 实测：Knocket 公开挂件

页面 `https://knocket.trtc.io/`，脚本 `https://trtc.io/knocket-sdk/sdk.js?identifier=6322e0c8aa1daebe1c`。宿主 `DIV#contact-widget-auto`，open shadow root。视口 1224×789，dpr 2。没有发送消息。

| 元素 | 实测 |
| --- | --- |
| 启动器 | 文案 Contact us。`.trtc-float-card__content.size-medium` 背景 `rgb(74, 181, 85)`，圆角 24px，白字 14px / 500，阴影 `rgba(0,0,0,0.5) 0 7.733px 11.6px -7.733px` 与 `rgba(0,0,0,0.1) 0 7.733px 23.2px -3.867px` |
| 主页卡片 | `.trtc-knocket-card.widget-new-card.is-home` 324×551，位于 x867 y166，圆角 20px，阴影 `rgba(0,0,0,0.12) 0 12px 40px`，字体 RobotoTRTC |
| 头图 | 内边距 36px 24px 0。`linear-gradient(rgba(255,255,255,0.25) 2.4%, rgba(74,181,85,0.25) 100%), linear-gradient(rgb(74,181,85) 0%, rgb(74,181,85) 62%, rgb(255,255,255) 100%)` |
| 标题 | Welcome👋，34px / 800，行高 40.8px，白色。副标题 How can we help you today? 16px / 400 |
| 动作 | `.trtc-knocket-card__action` 285×59，圆角 14px，白底。Start a Chat / We reply within a few minutes；Book a Meeting。频道钮 37×37，圆角 12px，本页是 Telegram |
| 底栏 | Home / Chat / FAQ，89×39，圆角 12px。选中底 `rgba(30, 79, 216, 0.1)`，字 `rgb(74, 181, 85)`。这三个按钮有 `aria-label`，同卡其他按钮没有。页脚 Knocket 是 `SPAN role=link` |
| 对话 | 头「AI Assistant」加灰色 Offline 点。问候：Hi! I'm your knocket AI assistant. I can help answer questions about our products, features, and services. How can I assist you today? 输入占位 Enter your message...，旁有 emoji、回形针、发送。隐藏的 `input[type=file]` 为 0×0 |
| FAQ | 头 Help Center。五条折叠：Is it really free? / Do visitors need to sign up to chat? / What can visitors send me in a chat? / Is there a mobile app for me to reply? / How does install work? |
| 窄屏 | `screenshot --width 390 --height 844` 的画面里，FAQ 卡片铺满视口，底栏仍在。这是这次截图的画面，不是我在实时视口里重测的断点 |

截图：`knocket-home.png`、`knocket-widget-open.png`、`knocket-chat.png`、`knocket-faq.png`、`knocket-mobile.png`。

Offline 是这次公开演示的状态，不是「所有 Knocket 安装都离线」。

## 官方：Knocket 控制台能配什么

教程 [Set up the Knocket Web Widget](https://knocket.trtc.io/tutorials/web-widget/)，页内 Updated Sep 10, 2026。Apify dataset `8dzCZhs813vJ0QBSG` 的正文与直接 GET 一致。

- Appearance / Home page / Conversation page 三页。主题色 10 个预设或一个 hex，驱动头图渐变、Start a Chat 和启动器。启动器是圆图标或胶囊。FAQ「up to a handful」，可整段关掉。
- 语言列表含阿拉伯语等 RTL，教程写切换后问候、表单、错误和 RTL 布局一起变。
- Home：方形 logo、Welcome Title、一行 Description、Live Chat 开关、会议链接（Calendly / Cal.com / Savvycal 或任意 URL，新标签打开）、WhatsApp / Instagram / Telegram / Messenger 外链。留空的频道不渲染。
- Conversation：助手显示名、问候、Always available 或按星期时段、离线文案、收集姓名 / 邮箱 / 电话 / 留言，时机是 Off、对话前、或仅离线。
- 教程 FAQ 写：附件（图片、视频、文件）在每个对话里都支持，并进收件箱和 Telegram。改颜色和文案不需要重新部署脚本。
- 教程没有写暗色模式，也没有写引用卡片。本轮打开的对话只有静态问候，没有流式回复，因为没有发送消息。

[AI Agent 教程](https://knocket.trtc.io/tutorials/ai-agent/)，Updated Sep 7, 2026：

- 能答就用知识库回答，并「cites your own knowledge base」；不能答或访客要人，就静默转人工并通知。这是官方宣称。公开挂件的问候里没有引用芯片。
- 渠道：Web Widget、Mobile Widget、Contact Page。关掉的渠道仍进收件箱，只是不先过 AI。时段：Always，或 When offline。
- Bot：头像 ≤1MB；名称；问候 ≤400 字；系统提示 ≤2000 字。默认提示写 Never invent information，只根据提供的知识回答。
- LLM：OpenAI 兼容 Chat Completions。预设含 OpenAI、Hunyuan、DeepSeek，或自定义 endpoint。嵌入凭据由平台管。有 Test Connection。
- Knowledge：FAQ、URL、File 各 10 条。文件 PDF、TXT、DOC、DOCX、HTML、MD、CSV、XLSX，单文件 10MB。与 #87 的上限一致，本轮用教程正文再次读到，不是只引用旧票。
- Usage：AI Requests、Handoffs、Avg Response Time，以及按模型的 token。没有「从未答清单一键加入知识」的描述。

话术库两份官方页不一致，都不作为我们要抄的清单：

- [Default Handoff Phrases](https://knocket.trtc.io/tutorials/ai_agent_handoff_phrases/)，Updated Sep 4, 2026：218+ 条，50 种语言，只匹配明确的转人工说法，不含单独的 agent / customer service。命中后不调用模型。
- AI 教程同一链接旁仍写「47 languages」。教程比参考页新三天，数字却更旧。#151 已排除搬这套词库。冲突只说明不能把任一数字写成事实，不改变排除。

## 参照产品

### Chatwoot，实测加官方

`https://www.chatwoot.com/features/live-chat` 上的真挂件不是页面中部那张插画。真外壳是 `#cw-widget-holder`，打开后 class 去掉 `woot--hide`，400×626，圆角 16px，阴影 `rgba(0,0,0,0.16) 0 5px 40px`。启动器文案 Chat with us，156×48，背景 `rgb(40, 129, 246)`，圆角 100px，阴影 `rgba(0,0,0,0.16) 0 8px 24px`，字体 Inter。打开后的主页文字：Hi there!、We are online、Typically replies in a day、Start Conversation、Popular Articles（How to use Agent bots? 等）。没有点 Start Conversation，避免给对方建一条会话。截图 `chatwoot-widget-open.png`。

官方：

- [Website live chat settings explained](https://www.chatwoot.com/hc/user-guide/articles/1677580558-website-live-chat-settings-explained)：widget color、welcome heading、welcome tagline、channel greeting、营业时间。Captain 打开时，非营业时间的 unavailability message 会被压掉，由 Captain 继续接。
- [Widget customization](https://www.chatwoot.com/features/widget-customization)：任意 hex、左右位置、标准圆钮或带字胶囊、`darkMode: auto|light`、50+ 语言、RTL。这是产品页宣称。
- 帮助目录里有 pre-chat forms、interactive messages、CSAT、dark mode 专文。本轮没有逐篇读字段，所以不写「Chatwoot 预聊表单有哪几个字段」。

### Crisp，官方

[How to customize the Crisp Chatbox](https://help.crisp.chat/en/article/how-to-customize-the-crisp-chatbox-1hj537j/)：Settings → Chatbox → Customization，分 Text translations、Position offsets、Custom colors。Appearance 管颜色、文案、行为、位置。行为项写了 availability tooltip、privacy mode、mobile visibility、homepage section。付费计划才有高级定制；免费计划是预设色。`crisp.chat/en/` 页面上 `window.$crisp` 存在，但没有打开他们的聊天框，所以没有 Crisp 的 computed style。

### Intercom，官方

[Set up and customize the Messenger](https://www.intercom.com/help/en/articles/6612589-set-up-and-customize-the-messenger)：Launcher 是气泡；Home 是第一次打开的空间；Messages 不能关；还可以有 Help、News、Tickets、Tasks。Home 可关，也可配置成直接进入 Conversation。logo、启动器图标和品牌色在 Essential 计划不可改，要 Advanced 或 Expert。同文有 dark mode theme。我打开的 `/articles/182-...` 被帮助中心重定向到发信地址文章，不作为 Messenger 证据。

### Tidio，官方

直接 GET `help.tidio.com` 为 403。Firecrawl 读到 [Using your widget's online/offline status](https://help.tidio.com/hc/en-us/articles/5399286434460-Using-your-widget-s-online-offline-status)：每个座席自己的在线状态决定挂件；全员离线则挂件离线；Operating Hours 可自动切换；Flows 和 Lyro 可以按在线状态走不同路径。

[Lyro 产品页](https://www.tidio.com/ai-agent/) 是营销句：知识库外的问题建一张工单交给人；可配置 handoff。没有当官方字段表用。`/features/live-chat/` 返回站内 404。

## 我们这边，今天能读到的

| 观察 | 证据 |
| --- | --- |
| `/chat/` 与 `/chat/index.php/chat/start` 都是 unavailable，没有表单 | 2026-10-27 22:13 CST，curl 与 opencli。响应头 `x-powered-by: PHP/8.3.36`，`server: LiteSpeed`。截图 `staging-chat.png` |
| 公开登录页 HTML 没有版本号 `4.97` | GET `/chat/index.php/site_admin/user/login`。#154 / #148 记录的 4.97v 今天没有在公开 HTML 里复核到 |
| 首页没有挂件 | `https://staging.metal-divider.com/`，标题就是域名，默认主题，Hello world!。截图 `staging-home.png` |
| 座席视觉不在本轮重测 | `origin/impl/154-desk:lhc-extensions/mdmobile/design/mdmobiletheme/css/mdmobile.css`：底 `#f3f5f4`，字 `#1b2420`，白面，边 `#e2e6e4`，标题 17px/600，胶囊圆角 999px，系统字体加 PingFang SC / Noto Sans SC。这是源码，不是今天的评审站截图 |
| 访客上传、交回 AI、罐头选择器、第二座席工具 | 仍以 #149–#152 为准。本轮没有新的公开证据推翻它们 |

LHC 官方能力，用来填矩阵的「原生」列，不表示评审站已经打开：

- [README Features](https://github.com/LiveHelperChat/livehelperchat/blob/master/README.md)：文件上传、对方输入预览、延迟罐头（像真人在打字）、关闭后恢复、转接、部门、营业时间、主动邀请、多语言、自定义开场字段、历史聊天。
- [Theme](https://doc.livehelperchat.com/docs/theme)：头色、边、离线表单后的文字、关闭后的 survey 按钮、`.offline-status`、`.proactive-need-help`、自定义头 HTML、可预加载字体。
- [How to use bot](https://doc.livehelperchat.com/docs/how-to-use-bot)：快捷回复按钮、转人工。文档自己解释了「没人在线时看到离线表单而不是机器人」的配置原因。
- [ChatGPT responses](https://doc.livehelperchat.com/docs/bot/chatgpt-responses)：有 streaming 与 non-streaming 两套 Rest API。这是 LHC 的能力，不是我们机器人今天在访客面前流式输出的证据。

## 差距矩阵

优先级只按这条询盘链：图案页上的访客要能问到人，或在没人时留下可跟进的消息。P1 是这条链上的洞。P2 是信任和少打字。不提案 = 参照产品有，但对本站收益不够，或官方能力在付费计划里。已排除 = #149–#152。已交付 = #153–#155。

| 能力 | Knocket | 参照 | LHC 原生或扩展 | 访客或座席收益 | 成本 | 优先级 | 评审站验收 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 启动器 | 实测胶囊 Contact us，品牌绿，圆角 24px | Chatwoot 实测胶囊 Chat with us，蓝 `rgb(40,129,246)`，圆角 100px | 原生挂件启动器。今天首页没有嵌入 | 访客找得到入口 | 配置嵌入，不是新控件 | P1，但是环境项 | 值班时间内，访客实际看到的主题页上有启动器。今天的默认主题首页不算通过 |
| 先于对话的主页 | 实测 Home：问候、Start a Chat、会议、频道、FAQ 底栏 | Chatwoot 实测主页含在线状态和文章；Intercom 官方 Home space；Crisp 官方 homepage section | 机器人快捷回复可以给 3–5 个第一句。三栏卡片不是原生皮肤 | 少打第一句话。完整三栏卡片对询盘站收益低 | 快捷回复是配置；重做卡片是中等主题工作 | 快捷回复 P2；三栏卡片不提案 | 在线时，未输入也能点一条公开问题并得到机器人回复。不要求像素复刻 324px 卡片 |
| 问候 | 实测 AI 问候一句；教程问候 ≤400 字 | Chatwoot 官方 channel greeting；设置页 welcome heading / tagline | 原生开场消息，或机器人第一条 | 知道自己在跟谁说话 | 配置 | P1，并入「AI 身份」 | 见 AI 身份行 |
| 快捷回复 / FAQ | 实测 5 条折叠，未展开答案 | Chatwoot 实测 Popular Articles；Intercom Help space | 原生 bot quick reply。主题可关 FAQ 段 | 公开问题不用先建聊天 | 配置。文案不得抄图案规格（#102、#151） | P2 | 3–5 条来自已公开页面的问题，点按后有回复。规格数字仍走询盘，不进 FAQ |
| 气泡与输入框 | 实测占位 Enter your message...，emoji、回形针、发送 | 本轮没有点进 Chatwoot 的对话，避免建会话 | 原生消息列表和输入框 | 能打字 | 无 | 不提案 | 值班时间内能打字并看到自己的消息。本轮未测到 |
| 附件 | 教程写图片、视频、文件进收件箱和 Telegram。实测有 0×0 的 file input | 参照产品普遍有。本轮没有在他们的对话里上传 | 原生文件上传。#149：访客上传关闭，0 行文件，留下业务决定 | 图纸可以另走询盘表单。打开上传会收到客户文件 | 不在本票改这个决定 | 已排除 | 不验收「打开上传」。若业主另作决定，再单独立项 |
| 转人工 | 教程：不能答或访客要人则静默转出并通知。话术库 47 与 50 的官方数字冲突 | Tidio 官方：离线与 Lyro 可分路径；Lyro 营销页写知识外建工单 | `transferToOperatorAction` / `transferToBotAction` 在 #151 已核对存在。#154 已把转接接到值班和企业微信 | 人能接到。不再加假的「交回 AI」 | 已做通知。身份展示见下行 | 已交付通知；身份是 P1 | 在线转接后，企业微信 textcard 仍按 #154。访客侧要看到操作员名，而不是继续只显示机器人名 |
| 离线 / 留言 | 教程：时段外显示离线文案，When Offline 时收集姓名、邮箱、电话、留言，回复仍在同一条 | Chatwoot：营业时间加 unavailability；Captain 打开则会压掉这句并继续接。Tidio：全员离线则挂件离线，Operating Hours 自动切换 | 原生离线表单。主题文档有离线后的文字。bot 文档解释没人在线时为何掉进离线表单 | 21:00 之后的询盘不会消失 | 配置，先核对是不是「隐藏挂件」 | P1 | 北京 21:00 之后，公开 start URL 出现 LHC 离线表单（姓名、邮箱、留言至少三项），而不是 unavailable 空页。次日座席能打开这条。不新建表单产品 |
| 移动访客 | 390×844 截图里卡片铺满，底栏还在。另有官方 Mobile Widget 产品，本轮没测 App SDK | Intercom 官方写了直接进入对话在部分 Mobile SDK 才支持。Crisp 官方有 mobile visibility | 原生挂件应响应式。mdmobile 是座席页，不是访客挂件。官方 App 已排除 | 手机上的建筑师打得开 | 先测，不新建 App | P1 只做复测 | 390px 宽、值班时间内，启动器可点，输入框不被键盘挡住。不验收独立 App |
| 暗色 / 品牌色 | 教程只写一个 hex 驱动渐变和启动器。实测是浅色绿。教程未写暗色 | Chatwoot 产品页写 `darkMode: auto`。Intercom 官方有 dark mode，品牌色在高套餐。Crisp 付费才有自定义色 | 主题可改头色和 CSS。没有读到原生暗色开关 | 本站没有暗色主题。单做挂件暗色没有收益 | 不做 | 不提案 | 不验收暗色 |
| 无障碍 | 实测底栏三个按钮有 aria-label，其余按钮没有；页脚是 span | 本轮没有做参照产品的键盘审计 | 原生挂件的键盘路径今天没测到，因为页面是 unavailable | 键盘用户能开关 | 小，只在挂件可打开后查 | P2，排在离线表单之后 | 挂件可打开时：Tab 到启动器，Enter 打开，Esc 关闭，输入框有可见焦点。今天无法测 |
| 加载 / 错误 / 重试 | 未发送，未看到错误态 | 未测 | unavailable 页本身就是今天的错误/关闭态 | 失败时要有一句人话和下一步 | 与离线行合并 | P1，并入离线 | unavailable 不得作为下班后的唯一画面 |
| AI 身份 | 实测头「AI Assistant」加 Offline 点，问候自称 knocket AI assistant | Chatwoot 主页写 Captain，但是入口不是对话气泡。Tidio 产品名叫 Lyro | 机器人可有名称。#149 记录当时 `gbot_id=0`、没有 `user_id=-2` 的消息。那是当时的数据，今天没在公开页复核 | 访客知道答复是机器，转人工后知道是人 | 配置机器人名称和操作员显示名 | P1 | 机器人消息作者是稳定的 AI 名，不是空或网址。转人工后的新消息作者是操作员名。不出现「交回 AI」按钮 |
| 输入中 / 等待 | 未发送，未观察 | 未观察 | README 有「看到对方正在输入」和延迟罐头。#149 排除把输入状态做成新的座席控件 | 访客等待时少重复发送 | 若原生气泡已有，只验收；不新增控件 | 不提案新控件 | 不新增 typing 开关。若原生点点出现，记一笔即可 |
| 流式 | 未观察。教程没写 token 流 | 未观察 | 官方 chatgpt-responses 有 streaming Rest API | 短答复收益小 | 只有在现有机器人动作里本来就有、且访客现在是整段蹦出时才值得开 | P2，且先证明现状 | 值班时间内发一条公开问题。若整段延迟 >3 秒且 LHC 动作已支持流式，再考虑开。本轮没有这个计时 |
| 引用 | 教程写 cites knowledge base。问候里没有引用 UI | Chatwoot 营销句写 Answered by Captain from your Help Center。不是本轮在真对话里看到的 | `origin/impl/155-unanswered-log` 的 PHP 没有访客引用字段。#151 排除访客参考页 | 一条来源链接有用；做一个参考页产品没有 | 不提案参考页。来源链接要等机器人返回值里真有 URL | 不提案 | 不验收引用芯片，直到有一条真实回复带了可打开的本站 URL |
| 转人工提示 | 教程是静默转出 | Lyro 营销页写立刻通知团队 | #154 的通知在座席侧。访客侧要不要一句「已转给同事」是文案 | 访客知道不用再问一遍 | 一条机器人话术 | P2 | 转人工后访客看到一句固定说明，且企业微信仍收到。不在微信里回复（#152） |
| 接管后的操作员身份 | 本轮对话停在 AI Assistant / Offline，没有接管 | 未进入人工对话 | 原生操作员名。与「交回 AI」无关 | 信任 | 配置 | P1，并入 AI 身份 | 同 AI 身份行 |
| 关闭 / 重开 | 未测 | 未测 | README：关闭后可恢复。主题：关闭后可显示 survey 按钮而不是自动跳调查。#149 的关闭是 `chat/changestatus` | 座席能收口。CSAT 不做 | 已有关闭。调查不提案 | 已交付关闭；CSAT 已排除 | 不新增调查。#154 的关闭路径保持 |
| 历史 | 未用账号，看不到跨次历史 | Chatwoot 官方有 continue conversations through email，本轮没读正文 | README：previous users chats、resume after close | 回访不用重讲 | 先不建账户体系。本站无账户 | 不提案 | 不验收登录后的历史。同一浏览器未清 cookie 时，原生恢复若已开启，只记录，不新做 |
| 座席收件箱、未读、访客上下文、备注、罐头、关闭、移动值班、企业微信 | 未登录 Knocket 控制台。教程只说明消息进收件箱和 Telegram | #150 已对照过 Chatwoot，付费分配默认关闭 | #153 罐头与备注；#154 值班、补发、关闭、textcard；mdmobile 是移动值班面 | 单座席已经够用 | 已做 | 已交付 | 不在本票重测。不重做 #150 排除的列表、转接、推送 |
| 未答清单 / 加入知识 / 失效 / 统计 | Usage 页有请求数、转人工数、耗时、按模型 token。知识是 FAQ/URL/File 各 10，删除即移除。没有读到「未答一键入库」 | Tidio 营销页写 review conversations and add missing knowledge。不是字段证据 | #155：仅 `no_knowledge` 写入，重复累加，失败重试一次仍转人工。图案规格不自动成 FAQ | 人知道哪句没答上。自动入库会把未核实的规格写进答复 | 已做清单。统计页不提案，单座席看企业微信和清单就够 | 已交付清单；统计不提案 | 不验收 Knocket 式 Usage 图表。#155 的写入规则保持 |
| 视觉系统 | 见上表 token。绿 `rgb(74,181,85)`，卡片 20px，动作 14px，启动器 24px，字 RobotoTRTC，阴影很轻 | Chatwoot 真挂件 16px 圆角，阴影 `0 5px 40px / 16%`，胶囊启动器。营销插画不要当成挂件 | mdmobile 是浅灰底、1px 边、999px 胶囊，和 Knocket 的大圆角卡片不是一套 | 座席页保持 mdmobile。访客挂件只用主题色对齐站点，不换字体家族 | 主题色配置。不引入 RobotoTRTC，不单做一套 token 文件 | 不提案新设计体系 | 挂件头色使用站点已有的一个颜色。不验收与 Knocket 像素一致。AGENTS.md 2026-10-26：像素差不是通过线 |

## 视觉系统，只记测量

Knocket 桌面卡片是固定浮层，不是页面栏。320px 量级的宽度让它在 1224px 视口里不压住主标题。390px 截图里它改为铺满，底栏保留，这是「窄屏不要再留 324px 浮层」的参照，不是要我们抄 20px 圆角。

Chatwoot 真挂件更宽（400px）也更高（626px），圆角更小（16px），阴影更散。两者的共同结构是：启动器是带字胶囊，打开后先是主页而不是空输入框，底栏或文章列表承担「不必说话也能离开」的出口。

不采纳的视觉：Knocket 营销首屏的 Inbox / Contacts / Analytics 插画不是控制台信息架构（#87 已说过，本轮首页截图仍是插画）。Chatwoot 功能页中部那张「3 online / Popular Articles」在真挂件打开前是页面插画；真挂件打开后内容相近，但尺寸以 `#cw-widget-holder` 的 400×626 为准，不以插画为准。

## 无法确认

- 评审站挂件在北京 09:00–21:00 的真实外观。22:13 的 unavailable 不能外推到值班时间内。
- 公开 HTML 里的 LHC 版本。登录页没有 `4.97`。不把旧票的版本号写成今天的复核结果。
- unavailable 是「到点隐藏」还是嵌入损坏。要在后台读主题的离线策略才能分辨。本票不登录。
- Knocket 真实回复是否流式、是否带引用。没有发送消息，也没有控制台账号。
- Knocket 收件箱、Usage 图表的像素。只有教程文字。
- Crisp、Intercom、Tidio 的 computed style。Crisp 页面有 `$crisp` 但没有打开；Intercom 文章被重定向；Tidio 功能 URL 404。
- Chatwoot 预聊表单的字段清单、交互消息的种类。只确认帮助中心有这些文章。
- 话术库是 47 还是 50。两份官方页冲突。不影响「不搬词库」。

## 下一批只该看的三件事

1. 值班时间外，把公开 start URL 从 unavailable 换成 LHC 已有的离线表单。先核对配置，不写新表单。
2. 值班时间内，在访客真正看到的主题上确认启动器存在；机器人名和转人工后的操作员名稳定。不加重回 AI。
3. 若 1 和 2 通过，再配 3–5 条快捷回复，问题必须已经在公开页面上。不重做三栏卡片，不打开访客上传，不搬 218 条话术，不做暗色，不做 CSAT，不做 Usage 图表。
