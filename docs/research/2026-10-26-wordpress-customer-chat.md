# WordPress 客服插件的聊天、收件箱与人工接管

- 票：[#89 研究：WordPress 客服插件的聊天、收件箱与人工接管实现](https://github.com/AssassinFlash/metal-divider/issues/89)
- 父地图：[#86 Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)
- 日期：2026-10-26
- 分支：`research/wordpress-customer-chat`
- 基线：`origin/main` @ `efa3ca86b3788d6db433352e2549c2ed87a5fa9e`
- 相邻票、本文件不代替：[#87](https://github.com/AssassinFlash/metal-divider/issues/87) 负责 Knocket 全量功能与知识输入限制；[#88](https://github.com/AssassinFlash/metal-divider/issues/88) 负责知识切块、索引与向量存储。本文只使用人工接管的控制面，不展开检索实现，也不判断 AI 客服应如何改 Metal Divider 的询盘。

## 结论速览

1. **Knocket 不是 WordPress 插件，也不能把对话留在 WordPress 里。** 官方安装是一行远程脚本 `https://trtc.io/knocket-sdk/sdk.js`。对话、收件箱、Telegram 引用回复、邮件别名和人工接管都在 Knocket / 腾讯 RTC 一侧。WordPress.org 上的 `knocket-live-chat-widget` 1.0.2 只把该脚本放进 `wp_enqueue_script`，卸载时只删一个选项，没有消息表。
2. **已核对的 WordPress 方案分成四类，没有一类同时复现 Knocket 的使用方式并把数据留在本站。** 闭源商业插件没有公开源码，不在这个“没有”之内。
   - SaaS 挂件（Tawk.to、Crisp、Tidio、3CX、ThriveDesk）：访客气泡和厂商收件箱可以很像，存储与导出不归站点所有。
   - 本站实时聊天（Captain Live Chat 1.1.1；架构参照 Better Messages 3.1.6）：对话可以在自定义表里，传输是 HTTP 轮询。Captain 有离线表单、坐席邮箱群发和指派字段，没有 Telegram、联系页、移动 WebView，活跃安装桶为 0。
   - 本站工单（Fluent Support、Awesome Support、SupportCandy）：收件箱、指派、出站邮件和数据库导出是真的；它们不是同一条实时聊天线程。
   - 外链按钮（Chaty）：复现的是社交/Telegram/WhatsApp 跳转，不是收件箱。
3. **若目标是 Knocket 的使用方式，且对话与路由数据由本站控制，通信层必须自研。** 可合法阅读并按 GPL 借鉴的是表结构、ajax/REST、`wp_mail` 多收件人和工单指派，不是把某个现成插件装上当作 Knocket。WebSocket、入站邮件、Telegram 引用回复、托管联系页和人工接管状态机，要么在付费附加里，要么在厂商云上，要么公开材料里根本没有。
4. **WordPress 核心没有客服收件箱。** 内建近实时只有 Heartbeat 轮询（15–120 秒）。`wp_mail()` 只发信。入站邮件、Telegram、持久连接都不是核心能力。

## 方法与代表选择

外部事实只取官方页面、wordpress.org 插件信息 API，以及 2026-10-26 下载的插件 zip。营销对比（包括 Knocket 自己写的竞品表）不当作对手事实。活跃安装数是目录 API 的桶，不是精确计数。

| 角色 | 代表 | 为什么选它 | 深读程度 |
| --- | --- | --- | --- |
| 对标物 | Knocket 官网、教程、隐私模块、SDK 响应头 | 本票要复现的使用方式 | 公开页面与 SDK 宿主；未反编译协议，未登录控制台 |
| Knocket 的 WP 包装 | `knocket-live-chat-widget` 1.0.2 | 目录里唯一同名插件 | 全文件阅读 |
| SaaS 挂件 | Tawk.to 0.9.3、Crisp 0.49、Tidio 8.0.1 | 安装量最大的三类官方 WP 插件 | 插件源码证明只嵌远程脚本；功能边界用各家官网，不用 Knocket 的对比文 |
| 曾本地、现外置 | 3CX `wp-live-chat-support` 10.0.19 | 历史自托管插件的现状 | zip 无消息表；官方页写明在 3CX 客户端回复 |
| SaaS 帮助台 + Woo 上下文 | ThriveDesk 2.7.1 | 收件箱在厂商，插件仍建本地表 | 源码见 API 与本地 `ticket_id` 表 |
| 本站访客聊天 | Captain Live Chat 1.1.1 | 公开源码里最接近“气泡 + wp-admin 收件箱 + 本站表” | 表结构、轮询、邮件收件人已核对；未运行 |
| 本站实时消息架构 | Better Messages 3.1.6 | 可审计的自定义表、REST、ajax/WebSocket 分界 | 机制与许可门控已核对；产品不是客服气泡 |
| 本站工单 | Fluent Support 2.4.7、Awesome Support 6.4.2、SupportCandy 3.5.4 | 收件箱、指派、邮件、机器人升级工单 | 表名、许可和 Pro 标记来自 zip / readme |
| 外链联系 | Chaty 3.6.3 | 30 万以上活跃安装的多通道按钮 | readme 的免费/Pro 边界 |
| 外部自托管挂件 | Hexabot Chat Widget 3.2.3 | WP 只嵌挂件，对话在操作者自己的 Hexabot | 设置项与许可证 |

未逐行审计、因此不进入矩阵单元格的：Danico Chat 1.0.0（目录自称本站表，活跃安装 0）、Simple Ajax Chat（公共聊天室）、Joinchat 6.4.2 与 Click to Chat 4.45（WhatsApp 点击发起，Chaty 已代表这一类）、Support Board 等商业闭源自托管产品、GhostChat、Collect.chat。知识库插件归 #88。

## 1. Knocket 对照基线

Knocket 把自己定义成产品的联系层，不是帮助台。首页的三块表面是 Web Widget、Contact Page、Mobile Widget，后面是一个收件箱，再加 Telegram 回复和 AI。[首页](https://knocket.trtc.io/)。免费信说今天已经发布的核心功能永久免费，AI 也包含在内，但模型密钥自带；团队协作、开发者 API 一类是以后才做、且可能收费的东西。[Why free](https://knocket.trtc.io/why-free/)。

### 1.1 网站聊天与嵌入

官方安装是 `</body>` 前的异步脚本，宿主为 `https://trtc.io/knocket-sdk/sdk.js?identifier=YOUR_IDENTIFIER`。控制台可改颜色、欢迎语、在线状态和离线表单，不用重新部署。访客不用注册。离线时改成收集邮箱和留言，回来后仍是同一线程。可传 `data-user-id` 或在脚本前设置 `window.knocketConfig`。附件声称包括图片、视频和文件。已读回执和撤回是产品宣称，没有公开实现。[Web Widget](https://knocket.trtc.io/products/web-widget/)，[入门教程](https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/)。

2026-10-26 实测该 URL 返回 `200 application/javascript`，`server: tencent-cos`，对象 MD5 `93e511434e20541702d4df4d21be8cb1`，体积 3,207,329 字节。文件里没有单独的 Knocket semver；能看到的版本串属于打包进去的库（例如 `18.3.1`）。传输是 WebSocket 还是长轮询，公开页面没写，本票没有反编译这 3.2MB 脚本。

WordPress 官方模板页写的是“No Plugin”：贴进主题 `header.php` 的 `</head>`，或用 Insert Headers and Footers。[WordPress 模板](https://knocket.trtc.io/templates/wordpress-chat/)。这和产品页的 `</body>` 冲突。目录插件又要求 URL 同时带 `identifier` 和 `v`，公开片段只展示 `identifier`。三处不能当成同一条安装规格。

### 1.2 联系页、社交与预约

Contact Page 是 `https://knocket.ai/<slug>` 的托管卡片，不需要客户有网站。固定有 Live Chat。其余是可配置块：社交图标、链接、作品、媒体、服务、评价、Booking/RSVP、FAQ、营业时间等，页面写 18 块。访客零注册；离线改成留言表单。回复通道写的是收件箱或 Telegram，没有单独的坐席 App。[Contact Page](https://knocket.trtc.io/products/contact-page/)。

同一页 FAQ 写明完整自定义域（如 `hi.yourdomain.com`）今天不支持。入门教程的表格却写 “knocket.ai or a custom domain”。邮件集成里的自定义域是 MX 指到 Knocket，那是收件地址，不是联系页 URL。预约在产品页只被描述成“给访客一个直接预订或回应的入口”；WordPress 模板页把 “meeting booking link” 列为控制台可配项。没有公开的日历引擎、时段或回调。社交图标是跳出的链接。入门教程写 Widget 首页可挂 WhatsApp、Instagram、Telegram、Messenger，作为不想打字时的替代通道，不是收件箱频道。

### 1.3 收件箱、通知与路由

入门教程把集成写成两路：Telegram 用 BotFather 的 token 贴进 Integrations；邮件用 `hello.you@knocket.ai` 别名，或把 MX 指到 Knocket。入站邮件和聊天并排变成线程。回复从原通道回去：网站回复回挂件，邮件回复回邮件，Telegram 回复回 Telegram。[入门教程](https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/)，[统一收件箱指南](https://knocket.trtc.io/blog/unified-inbox-guide)。

通知有浏览器推送和 Telegram。Telegram 分 Full（正文进 Telegram，可引用回复；高量时会丢一部分以避开 Telegram 速率限制）和 Notification only（只有提醒，必须回控制台回复）。指南里的控制台地址是 `console.trtc.io`。

小团队页的“四个收件箱、一个队列”展示的是 Web / Contact Page / Mobile 的 Open/Replied 状态，没有指派字段、轮询规则或多个 Telegram 收件人。[Small teams](https://knocket.trtc.io/solutions/small-teams/)。免费信把团队协作放到以后。所以“无席位费”是价格声称，不是多坐席路由已经存在的证据。首页收件箱图的 alt 写了 WhatsApp 和 Instagram；集成教程没有把它们写成收件箱频道。以教程为准，alt 视为未证实的营销图。

开发者页画了 `message.created` 箭头。那是示意图。免费信把开发者 API 放在以后的付费层。本票没有找到公开的 webhook 或导出 API 规格。[Devs](https://knocket.trtc.io/solutions/devs/)。

### 1.4 人工接管

AI 教程（页内标注 Updated Sep 7, 2026）把接管写成控制台四张卡片里的一张，不依赖本票去重做检索：[AI Agent](https://knocket.trtc.io/tutorials/ai-agent/)。

- 频道：Web Widget、Mobile Widget、Contact Page 可分别开关。关掉的频道直接进人工收件箱。
- 时段：Always，或只在离线时由 AI 回答。
- 第一层：47 种语言的短语表，命中则不调用模型，直接转人工。可追加短语。通知可只开接管、不开每条新消息。
- 第二层：模型调用内建 `transfer_to_human`。入门教程还写低置信线程置顶，并附 AI 草稿，可发送、修改或丢弃。
- 人工回复后可 Resume AI。详情栏有两句摘要。
- LLM 卡片要求 OpenAI Chat Completions 兼容端点。教程截图默认是 `https://tokenhub.tencentmaas.com/v1` 的 `kimi-k3`。嵌入由平台做，聊天补全密钥自带。

目录插件 1.0.2（2026-08-21）的 readme 仍把 AI Agent 写成 Coming soon。产品教程比插件说明新，不能用插件 readme 判断 AI 是否已上线。

### 1.5 移动端

Mobile Widget 是把 `https://knocket.ai/<handle>?platform=mobile` 装进 WKWebView / Android WebView / React Native / Flutter。关闭通道名必须是 `closeWebView`。官方 FAQ 写明这不是原生聊天 SDK。[Mobile Widget](https://knocket.trtc.io/products/mobile-widget/)。开发者页脚的 “SPM · Gradle · npm” 没有对应的安装步骤，不能当成已发布的原生包。

坐席侧没有 Knocket App。手机回复路径是 Telegram 引用回复或浏览器控制台。

### 1.6 存储、导出与许可

插件 readme 指向 [https://knocket.trtc.io/legal/privacy/](https://knocket.trtc.io/legal/privacy/) 和 [https://knocket.trtc.io/legal/terms/](https://knocket.trtc.io/legal/terms/)。隐私页最后更新 2026-10-15，联系邮箱 `info_rtc@tencent.com`。它自称 Instant Messaging / Chat 功能模块，并入一份底层隐私政策；抓取文本没有给出那份底层政策的独立 URL。模块写处理链路使用 CLB、TencentDB for MySQL、TencentDB for MongoDB、TencentDB for Redis、CKafka 和 COS；配置项里有 “selected data centre for operation” 和 “historical message storage period settings”。保留表给了日志 7 天、监控 2 天、配置数据停用后 2 个月，没有给聊天正文一个数字，也没有自助导出或删除接口。

因此能确认的是：对话离开 WordPress，进入 Knocket/腾讯侧；区域和历史保留在模块里被说成可配置。不能确认的是：某一条访客消息具体落在哪张表、客户能否自己导出全量线程。移动页还声称控制台有数据区域开关，并引用腾讯云 RTC 的 ISO 认证。本票没有登录控制台，也没有核对证书原文。

`https://trtc.io/solutions/knocket` 在 2026-10-26 跳到 Knocket 首页。插件头把 Author URI 写成这个地址，目录贡献者账号是 `rrivory`。产品模板页又说不要插件。它是“自称 Knocket、只做脚本入队”的包装，不是产品文档里的安装路径。

## 2. WordPress 原生机制

核心没有聊天、收件箱、Telegram 或入站邮箱。一个自研插件能用的是这些已发布机制：

| 机制 | 官方事实 | 对客服意味着什么 |
| --- | --- | --- |
| Heartbeat | 页面加载后每 15–120 秒用 admin-ajax 轮询，过滤器是 `heartbeat_received`。手册最后更新 2022-11-17。[Heartbeat API](https://developer.wordpress.org/plugins/javascript/heartbeat-api/) | 唯一内建近实时。间隔太疏，不能当 Knocket 那种即时气泡。 |
| `wp_mail()` | “Sends an email, similar to PHP’s mail function.” `$to` 可以是数组或逗号分隔列表；Cc/Bcc 走 `$headers`。成功只表示发送过程没报错。文档站引用的源码是 WordPress 7.1 的 `wp-includes/pluggable.php`。[wp_mail()](https://developer.wordpress.org/reference/functions/wp_mail/) | 出站通知和多收件人够用。它不收信。入站回复要另做邮箱轮询或入站 webhook。 |
| 自定义表 / REST / admin-ajax | 下面各插件实际调用了 `dbDelta`、`register_rest_route` 和 `wp_ajax_*` | 对话不该塞进文章正文。实时收发在共享主机上走普通 HTTP，而不是核心不存在的 WebSocket 服务器。 |
| 角色与后台菜单 | Captain、Fluent Support、Awesome Support 都在 wp-admin 做坐席界面 | 坐席台可以是插件后台，不需要第二套 SaaS。 |
| 短代码、块、`wp_enqueue_script` | Knocket 包装和各 SaaS 插件都这样嵌挂件 | 嵌入方式可复用；嵌进去的仍可能是别人的服务器。 |

本票没有把 Application Passwords 或个人数据导出钩子再读一遍手册。导出结论以各插件自己的导出入口和“表在不在本站”为准。

共享主机约束只记两条已核对的说法，不做未测的 Hostinger WebSocket 结论：项目规则写明共享主机、首期无 Redis；Captain 的 readme 声称 ajax 轮询能在 Hostinger 上工作。后者是插件作者声明，本票没有实测。

## 3. 代表方案

### 3.1 只提供挂件的 SaaS

**Knocket 包装，1.0.2，2026-08-21，GPL-2.0-or-later，活跃安装 0，下载 198。** 设置只存 `knocket_settings`（脚本 URL、是否仅首页）。`knocket_is_valid_script_src()` 要求 `https://trtc.io/knocket-sdk/sdk.js` 且查询串有 `identifier` 和 `v`。卸载删除该选项。没有消息、没有 webhook、没有收件箱。

**Tawk.to，0.9.3，2026-01-14，GPLv3，活跃安装桶 100000+。** `templates/widget.php` 把脚本设为 `https://embed.tawk.to/{page}/{widget}`。设置同步走 `https://plugins.tawk.to`。zip 里没有消息表。官方产品页写：无限席位、无按席计费、永久免费；收件箱在 `dashboard.tawk.to`；有 iOS、Android、macOS、Windows 应用；可转接、内部 whisper；Apollo 答常规问题，需要人时把完整记录转给坐席，坐席批准后才发送。页面也列出 WhatsApp、邮件等进入共享收件箱，图标里有 Telegram。[Live Chat](https://www.tawk.to/products/live-chat/)。对话不在 WordPress。Knocket 模板页说 Tawk.to 按席收费，与 Tawk.to 自己的页面相反，不采用。

**Crisp，0.49，2026-10-21，MIT，活跃安装桶 20000+。** 前台只入队 `https://client.crisp.chat/l.js`。后台按钮打开 `https://app.crisp.chat/website/{id}/inbox/`。定价页正文写明：Free 永久免费，含网站挂件、共享收件箱、2 个席位、移动应用、Chat SDK、联系表单、推送，且不含 AI 额度。Mini 为每工作区每月 $45，在免费之上加共享邮件收件箱、4 席、自定义发信域。Essentials 为 $95，正文写有全渠道路由与指派、知识库。比较表把 Telegram 写成“在 Crisp Inbox 里收发 Telegram”，但抓取结果没有露出勾选落在哪一列，本票不指定套餐。[定价](https://crisp.chat/en/pricing/)。数据页写云端、EU-Hosted；同样不能从空单元格推断免费档是否包含。WP 插件 MIT，服务本身不是开源，也不能把对话留在站点数据库。

**Tidio，8.0.1，2026-09-01，GPLv2，活跃安装桶 70000+。** `config.php` 默认 API `https://api-v2.tidio.co`，面板 `https://www.tidio.com/panel`。架构扫描没有看到本地消息表。付费边界在 [tidio.com/pricing](https://www.tidio.com/pricing/)，本票没有逐项核对，因此不写 Tidio 的人工接管或 Telegram 细节。

**3CX，`wp-live-chat-support` 10.0.19，2026-10-20，GPLv2，活跃安装桶 100000+。** zip 没有 `CREATE TABLE`。公开脚本是插件自带的 `callus.js`；`callus_url` 必须是带路径的 `https://` URL，坐席入口被拼成该主机的 `/webclient`。注册指向 `https://www.3cx.com/signup/live-chat`。官方页写在 3CX Web Client 回复，含队列式群组处理，以及 iOS/Android 应用。[3CX 插件页](https://www.3cx.com/live-chat/free-wordpress-plugin/)。这是“免费的 3CX 账号”，不是“对话在 WordPress”。该页写“超过 30,000 活跃安装”，与目录桶 100000+ 不一致，安装量以目录 API 为准。

**ThriveDesk，2.7.1，2026-09-07，GPLv2 or later，活跃安装桶 100+。** 常量指向 `app.thrivedesk.com`、`api.thrivedesk.com`、`assistant.thrivedesk.com`。`TDApiService` 用 `wp_remote_post` / `wp_remote_get`。本地 `TDConversation` 表有 `ticket_id`。readme 写 7 天试用，坐席用厂商的 [iOS](https://apps.apple.com/app/thrivedesk/id1612184462) / [Android](https://play.google.com/store/apps/details?id=com.thrivedesk) 应用。它会把 WooCommerce 订单显示在厂商收件箱里。那是厂商侧的上下文，不是本站对话存储，也不构成改变询盘流程的证据。

### 3.2 本站实时聊天与消息

**Captain Live Chat，1.1.1，2026-10-08，GPL-2.0-or-later，活跃安装 0，下载 400。** `dbDelta` 建五张表，前缀 `{$wpdb->prefix}`：

- `captlc_threads`：访客 id/姓名/邮箱、`assigned_agent_id`、status、来源 URL、设备、语言、软删除
- `captlc_messages`：`sender_type`、正文、`attachment_url`、已读
- `captlc_agents`：在线标记、`last_active_at`、可用模式
- `captlc_activity_log`
- `captlc_notifications`

访客协议在 `class-captlc-ajax.php` 注册为 `wp_ajax_` 与 `wp_ajax_nopriv_`：开线程、发消息、拉消息、在线与输入状态。已读源码里没有 Telegram 字符串。轮询在 `assets/js/widget.js`：可见时 `captlcData.pollInterval || 3000` 毫秒，隐藏时 30000 毫秒。离线表单可收姓名和邮箱。导出与永久删除是 `captlc_export_history`、`captlc_export_thread_transcript`、`captlc_permanently_delete_thread`。

邮件发给 `CAPTLC_Roles::get_agent_users()` 的全部邮箱，没有按 `assigned_agent_id` 过滤；没有坐席时落到 `admin_email`。所以它有指派字段，但通知是广播，不是 Knocket 那种按原通道回一条、也不是按被指派人路由。readme 自称无外部服务器；本票没有运行时抓包验证。它证明“共享主机上的 ajax 气泡 + 本站表 + wp-admin”能写成一个 GPL 插件，不能当作可直接承接生产的 Knocket。

**Better Messages，3.1.6，2026-10-25，GPLv3，活跃安装桶 10000+。** `bm_get_table()` 使用 `$wpdb->base_prefix` 加 `bm_threads`、`bm_messages`、`bm_recipients`、`bm_guests`、`bm_files` 等。REST 命名空间 `better-messages/v1`。默认 `'mechanism' => 'ajax'`。没有 premium 许可时设置被强制回 ajax；有许可才允许 websocket。WebSocket 类是否加载还取决于 zip 里是否有 `websocket.php`（`better_messages_is_websocket_build()`）。邮件通知存在，但是站内私信/聊天室的通知，不是客服 Telegram。`guests` 表说明“未登录参与者”是数据概念，产品形态仍是私信和聊天室，不是角落里的匿名支持气泡。免费档因此给出可复用的表、REST 和 ajax 降级；持久连接被许可门控，而且需要插件自己的 socket 服务，不是 WordPress 核心。

### 3.3 本站工单收件箱

**Fluent Support，2.4.7，2026-10-23，GPLv2 or later，活跃安装桶 10000+。** 迁移器写出的表包括 `fs_tickets`、`fs_conversations`、`fs_persons`、`fs_mail_boxes`、`fs_attachments`、`fs_activities`。readme 写自托管、可指派多名坐席并设权限。变更日志把邮件 piping（入站邮件串进工单、退信防护）标成 Pro，把 AI 回复草稿、工作流自动指派也标成 Pro。`register_rest_route` 扫描没有命中；它有自己的 Http Controllers，本票没有把每条路由展开。附件可在 Action Scheduler 存在时后台上传（`function_exists('as_schedule_single_action')`），这不是核心函数。导出入口有 `wp_ajax_fs_export_agent_report`。没有访客实时气泡。它复现的是工单收件箱和出站邮件，不是 Knocket 的聊天线程。

**Awesome Support，6.4.2，2026-10-16，GPLv2 or later，活跃安装桶 6000+。** `register_post_type( 'ticket' )`，回复、历史和日志也是非公开的文章类型，不是自定义消息表。通知类注释写明走 `wp_mail()`，并建议使用真正的 SMTP。没有 WebSocket 命中。这是“用内容类型做工单”的原生路径，适合异步票据，不适合 3 秒一轮的聊天。

**SupportCandy，3.5.4，2026-09-29，GPL v3，活跃安装桶 10000+。** 安装类建 `{$wpdb->prefix}psmsc_tickets`、`psmsc_threads`、`psmsc_customers` 等。AI 机器人在免费 zip 里：`WPSC_AIBOT_Provider_Factory` 默认 OpenAI 与 Google Gemini，并留了 `wpsc_aibot_providers` 过滤器。转人工是 `wp_ajax_wpsc_chatbot_create_ticket`，也就是升级成工单，不是在同一条实时线程里把发言权交给坐席。运行时是否还要许可证密钥，本票没有装站验证。

### 3.4 外链按钮与外部自托管

**Chaty，3.6.3，2026-10-08，GPLv3，活跃安装桶 300000+。** 免费档是 WhatsApp、Messenger、Telegram、邮件、电话、SMS 等点击跳转。readme 写明它和 live chat 的差别：访客离开网站，到对方的 App 里等回复。Pro 才有多坐席按钮、按页面显示不同挂件、营业时间、联系表单的 CC/BCC。这些“坐席”是多个外链目的地，不是统一收件箱。预约若只是一个 URL，和这里的自定义链接是同一类。

**Hexabot Chat Widget，3.2.3，2026-06-11，AGPLv3，活跃安装桶 10+。** 设置项是自托管 Hexabot API 的 URL、Source Ref，以及 WebSocket 或 polling。对话在操作者的 Hexabot，不在 WordPress 表。AGPL 对修改后再提供网络服务有传染义务，和 GPL 插件的复用边界不同。[readme 指向的文档](https://docs.hexabot.ai/)。

## 4. 机制矩阵

单元格只写本票已核对的事实。空的官方网格勾选不填。“宣称”表示只有产品页，没有源码或控制台实测。

| 能力 | Knocket | SaaS 挂件 | 本站实时 | 本站工单 | 外链按钮 | WP 原生 |
| --- | --- | --- | --- | --- | --- | --- |
| 访客实时聊天 | 宣称有；脚本在 `trtc.io` | Tawk/Crisp/Tidio/3CX 有，数据在厂商 | Captain 有，3 秒 ajax；Better Messages 是站内消息，默认 ajax | 无等价气泡。SupportCandy 机器人可升级成工单 | 无。点击后离开站点 | 无 |
| 后台统一收件箱 | 宣称有，在 Knocket/TRTC 控制台 | 厂商面板或 3CX Web Client | Captain 在 wp-admin；Better Messages 是消息后台 | Fluent / Awesome / SupportCandy 的工单列表 | 无 | 无 |
| 离线留言并入同一线程 | 宣称三块表面都这样 | 未在本票逐家核对离线表单 | Captain 离线表单写入同一插件的线程 | 工单本身就是异步留言，不是聊天线程 | 无 | 无 |
| 邮件出站通知 | 宣称有 | 厂商自己的通知 | Captain 用 `wp_mail` 发给全部坐席 | Fluent、Awesome、SupportCandy 用 `wp_mail` | 无对话可通知 | `wp_mail()`，可多地址、Cc、Bcc |
| 邮件作为对话通道 | 宣称别名或 MX 入站，回复回邮件 | Crisp 把共享邮件收件箱放在 Mini，不在免费正文里 | 未见入站解析 | Fluent 的 piping 标 Pro | 只是 `mailto:` 或外链 | 只发不收 |
| Telegram 通知 | 宣称有；Full 模式可能丢消息 | Tawk 产品页图标含 Telegram；Crisp 列出该频道但套餐列未抽出 | Captain 源码无 Telegram | Fluent 变更日志提到 Telegram webhook 的签名密钥，不是访客引用回复 | Chaty 免费档可挂 Telegram 用户名，打开 App | 无 |
| Telegram 引用回复回原通道 | 教程的主要坐席路径 | 不在 WP 插件里实现 | 无 | 无已核对的等价物 | 不能从站点线程回复 | 无 |
| 人工接管 | 短语绕过模型，或模型调用 `transfer_to_human`；可 Resume AI；可按频道和离线时段 | Tawk 官方页写 Apollo 转人工并保留记录。Crisp 免费档写明无 AI 额度 | Captain 无 AI 接管 | SupportCandy 是升级工单；Fluent AI 草稿标 Pro | 无 | 无 |
| 社交联系页 | 托管 `knocket.ai/slug`，社交是外链 | 各家是聊天挂件，不是 link-in-bio | 无 | 无 | Chaty 就是这一类 | 用普通页面拼链接可以，没有现成产品 |
| 预约 | 块或 “meeting booking link”，无日历引擎证据 | 本票未核对 | 无 | 无 | 可挂一个 URL | 无 |
| 坐席移动端 | 无官方 App；Telegram 或浏览器 | Tawk、Crisp 免费正文写有移动应用；3CX、ThriveDesk 有自己的 App | 响应式 wp-admin，无已核对的坐席 App | Fluent readme 写后台适配手机 | 坐席用各通道自己的 App | 无客服 App |
| 访客嵌入进 App | WebView 打开托管页 | 厂商 SDK，不在 WP 里 | 无 | 无 | 无 | 无 |
| 嵌入方式 | 远程脚本；WP 包装只入队 | 远程脚本或 3CX 的 `callus.js` + 远程 URL | 插件自己的 JS | 短代码/门户/后台 | 浮动按钮 | `wp_enqueue_script`、短代码、块 |
| 对话物理存储 | 离开 WP。隐私模块点名腾讯云 MySQL/Mongo/Redis/COS 等，未给出客户可查的表 | 厂商云。Crisp 写 EU-Hosted，未按套餐核实 | `captlc_*` 或 `bm_*` 在本站 MySQL | `fs_*`、`psmsc_*` 或 `ticket` 文章类型 | 不存对话 | 无对话表 |
| 实时方式 | 宣称实时；线协议未公开 | 厂商网络，WP 插件不实现 | ajax 轮询。Better Messages 的 WebSocket 要 premium | 刷新/邮件，不是聊天推送 | 不适用 | Heartbeat 15–120 秒 |
| API / hooks | 身份可从页面传入。公开 webhook 未找到；开发者 API 被写成以后的层 | 厂商 API 不在 WP 插件内。Crisp 定价页列出 Developer APIs / Webhook，套餐列未抽出 | Better Messages 有 REST。Captain 已核对的是 admin-ajax | SupportCandy 有 REST 和 `wpsc_aibot_providers`。Fluent 的路由方式未展开 | 无对话 API | `wp_mail` 过滤器、Heartbeat 过滤器、REST |
| 多收件路由 | 教程只见一个 bot token 和一个收件箱。无指派规则 | Tawk 写可转接、无限席位。Crisp 指派写在 Essentials 正文。均在厂商侧 | Captain 有指派字段，邮件仍广播给全部坐席 | Fluent 免费 readme 有坐席权限；自动指派标 Pro | Pro 才是多个外链目的地 | `wp_mail` 多地址，没有会话状态 |
| 导出与删除 | 公开材料无自助导出。隐私模块无聊天正文保留数字 | 受厂商控制。Crisp 比较表有 transcript/export 字样，套餐未抽出 | Captain 有导出和永久删除 ajax。表在本站即可 SQL 导出 | 表或文章在本站。Fluent 另有报表导出 ajax | 无 | 本票未重读核心隐私导出钩子 |
| 许可证与费用 | 产品宣称核心永久免费、模型自带、以后的 API/团队功能可能收费。包装插件 GPL | 插件 GPL 或 MIT，服务另计费。Tawk 官方页写挂件免费且不按席。Crisp 免费 2 席且无 AI 额度 | Captain GPL。Better Messages 免费档锁定 ajax | 插件 GPL。入站邮件和部分 AI 在 Pro | 按钮 GPL；营业时间、多挂件、多坐席按钮在 Pro | 核心免费。入站邮件和 Telegram 要自建 |

## 5. 哪些能复用，哪些必须自研

这里的“复用”指可以合法阅读、按许可证借鉴或装上就得到该机制。不指把 Metal Divider 的询盘改成这些插件。

**可以复用的机制**

- 嵌入：`wp_enqueue_script` 把挂件放进页脚，并用选项限制官方 HTTPS 来源。Knocket 包装、Tawk、Crisp 都是这个形状。它复现不了自控存储。
- 本站对话表：Captain 的线程/消息/坐席/活动日志，或 Better Messages 的 threads、messages、recipients、files。GPL。实时收发用 admin-ajax 或 REST，隐藏页放慢轮询。这是共享主机上唯一被源码证明走得通的近实时，不依赖核心没有的 WebSocket 服务器。
- 出站通知：`wp_mail()` 的多地址和 Cc/Bcc。Captain 说明“全部坐席”和“被指派坐席”是两件不同的事，广播不能冒充路由。
- 工单收件箱的坐席、状态和权限：Fluent Support 与 Awesome Support 的 wp-admin 模型。若产品最终要的是异步票据而不是即时气泡，这条比实时聊天更接近现成实现。SupportCandy 说明机器人可以在本站调用 OpenAI/Gemini，再开一张工单；那不是同一线程接管。
- 社交与预约链接：Chaty 的免费外链。只在联系页需要“跳到 WhatsApp/Telegram/一个预约 URL”时有用，不产生可导出的对话。

**不能靠现成插件复现、若要对齐 Knocket 且自控数据就必须自研的**

- 访客气泡、同一线程的离线表单、坐席收件箱，作为一个产品。Captain 是标本，不是可依赖的上游：活跃安装 0，作者同时发布多个不相关插件，本票没有安全审计。
- Telegram 通知和引用回复回到原线程。公开 WP 源码里没有这一条。Knocket 把它做在云端，而且 Full 模式会因 Telegram 速率限制丢消息。自研要自己持有 bot token、把 `reply` 映射回本站线程，并处理丢消息。
- 邮件作为对话通道。出站够用；入站别名/MX 不是核心，Fluent 的可用实现标了 Pro。
- 人工接管状态机：按频道、按在线时段、短语短路、模型工具调用、草稿、Resume。检索与向量不在本票。现成机器人要么在厂商云（Tawk Apollo、Crisp 付费 AI），要么升级成另一张工单。
- 托管联系页。`knocket.ai/slug` 不在 WordPress。自建应是本站页面，不要把 Knocket 托管页当成 WP 功能。
- 坐席手机。不要等一个 WordPress 官方客服 App。Knocket 自己也没有。可达路径是 Telegram 加响应式后台。访客 WebView 只对自有 App 有意义，对本站网站不是必需。
- 导出与删除的控制权。只有表在本站才成立。装 SaaS 挂件等于放弃这一点。Knocket 隐私模块没有给出客户自助导出。
- 多收件路由规则。Knocket 公开教程没有。自研要单独定义：广播、指派，还是按原通道回，三者不要混成一个“统一收件箱”词。

**明确不要当作数据层复用的**

- Tawk、Crisp、Tidio、3CX、ThriveDesk、Knocket 包装。它们能做出很像的气泡，存储和路由在别人的账号里。
- Better Messages 的 WebSocket 附加和 Fluent 的邮件 piping。免费 zip 要么把门控住，要么把功能标成 Pro。
- Hexabot 挂件，除非接受对话在另一个进程，并接受 AGPL。
- Knocket 模板页对 Tawk.to、Crisp 的收费描述。与这两家自己的页面冲突。

## 6. 证据局限

- 没有登录 Knocket、Tawk、Crisp、Tidio、3CX 或 ThriveDesk 控制台，没有发送测试消息，没有看数据库。实时、已读、撤回、文件落点和区域开关都是官方宣称或源码静态阅读。
- Knocket SDK 只确认了宿主、腾讯 COS 和体积。线协议、版本号和是否把正文再转发到第三方模型，没有从脚本里证完。
- 隐私模块像腾讯 IM 功能的政策，不是一份 Knocket 客户导出说明书。底层隐私政策的独立 URL 没有随抓取出现。ISO 认证没有对照证书页。
- 安装位置、`v` 参数、联系页自定义域、收件箱是否包含 WhatsApp/Instagram、插件 readme 与 2026-09-07 AI 教程，存在官方材料互相冲突。冲突已记下，没有选一个当成事实。
- Crisp 定价比较表的勾选在抓取里丢失。只采用正文写明的套餐差异。Tidio 定价页没有逐项读。
- Captain 的“无外部请求”只来自 readme 和源码扫描，没有运行时网络记录。`register_rest_route` 没有在已打印的 Captain 片段里出现，不能写成“完全没有 REST”。
- Fluent Support 的控制器路由和 SupportCandy 的运行时许可证门没有装站验证。
- 商业闭源的自托管客服（如 Support Board）没有公开 wordpress.org 源码，本票不把它写进“可复用代码”。
- 本票不判断这些机制应否接入现有询盘、WhatsApp 或 WooCommerce 资料。ThriveDesk/Crisp 的订单上下文是厂商功能，不是本站裁定。
