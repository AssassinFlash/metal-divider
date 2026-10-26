# WordPress 原生工单/客服后台与 Rapls 的组合接缝（#109）

- 日期：2026-10-26 · 分支 `research/wordpress-helpdesk-ai` · 票 [#109](https://github.com/AssassinFlash/metal-divider/issues/109)，父地图 [#86](https://github.com/AssassinFlash/metal-divider/issues/86)
- 方法：三个插件的 **wordpress.org 当前免费版全量源码静态审计**（下载包见附录），辅以 WordPress.org Plugins API 元数据、Apify 抓取的官方定价页。全部 file:line 指下述版本源码包。未装站运行（票面禁止），未购未读任何 Pro 包——Pro 能力以免费包内的门控代码与官方页面交叉印证。
- 审计对象：Fluent Support 2.4.7（2026-10-23 更新，1 万+ 活跃安装）、SupportCandy 3.5.4（2026-09-29 更新，1 万+）、Awesome Support 6.4.2（2026-10-16 更新，6000）。三者均 GPL（Fluent/AS：GPLv2+，SupportCandy：GPLv3+），纯 PHP + MySQL，无 Node、无长驻进程，共享主机（PHP 8.3 / MariaDB）直接适配。
- 关联票：Rapls 免费版会话与 hooks 的完整结论引用自已闭 [#108](https://github.com/AssassinFlash/metal-divider/issues/108)（`research/rapls-handoff-integration` 分支文档）；零成本基线（md-inquiry + wp_mail 队列 + Telegram Bot）引用自 [#103](https://github.com/AssassinFlash/metal-divider/issues/103)。本票不重复其内容。

## 结论一页纸

1. **现成连接器：不存在。** #108 已证 Rapls 源码零工单插件引用；本次反向核验，三插件源码中也零 Rapls 引用。任何组合都必须写胶水，区别只在写多少。
2. **推荐：SupportCandy 免费版做工单收件箱 + Rapls 免费版做 AI 层 + 一个 mu-plugin 胶水（约 1 个文件）。** 决定性理由是本站架构约束「**无账户**」（AGENTS.md §1）：三家中**只有 SupportCandy 免费版支持游客开票与游客凭 auth-code 链接查看/回复工单**（不需要 WordPress 账号）；Fluent Support 与 Awesome Support 的免费前端都强制客户注册登录。胶水的模板不用自己发明——SupportCandy 自带的 AI 聊天挂件里就有一套官方实现的「聊天会话 → 工单」升级代码（含确认门、游客身份收集、转录附件、HANDOFF 状态），mu-plugin 照它的数据结构写即可。
3. **SupportCandy 免费版还自带一个 AI 聊天挂件**（BYOK OpenAI/Gemini，会话存本站表），但其**知识库上传到 OpenAI vector store / Gemini file_search**——知识不自控，违反本工程前提，所以 AI 层仍用 Rapls（知识全在本站库，#103/#108 已裁），SupportCandy 挂件默认关闭或卸载 UI，仅留其代码当胶水范本。
4. **不适用**：Fluent Support（客户门户强制账号 + 游客通道全部在 Pro：piping、Telegram）；Awesome Support（免费前端表单强制登录注册，无游客路径）。Fluent Support 免费版有一个真正独特的接缝——**内置 MCP server**（AI 代理可直接 listTicket/createTicket/replyToTicket），但它是坐席侧/代理侧工具，不解决访客侧无账号开票，且需 WP 6.9+ Abilities API 与额外 adapter 插件。
5. **工单 ≠ 实时聊天（票面要求区分）**：三者的核心都是异步工单（表单提交 → 坐席后台回复 → 邮件通知），**没有任何一家免费版提供「访客与坐席在同一聊天线程里实时对话」**。SupportCandy 的挂件是 bot 对话结束时升级成工单（`HANDOFF` 后会话终结，`class-wpsc-acb-create-support-ticket.php:369`）；人工接管后的往返 = 工单页回复 + 邮件，不是聊天窗口。Knocket 式同线程实时接管在免费三家中不存在，仍须 #108 的方案（Rapls 会话 + 轮询/通知）或未来 Rapls Pro。

## 1. 三插件现状与共享主机适配

| | Fluent Support | SupportCandy | Awesome Support |
|---|---|---|---|
| 版本 / 更新 | 2.4.7 / 2026-10-23 | 3.5.4 / 2026-09-29 | 6.4.2 / 2026-10-16 |
| 活跃安装（wp.org） | 10,000+ | 10,000+ | 6,000 |
| 许可证 | GPLv2+（`fluent-support.php:9`） | GPLv3+（`supportcandy.php:14`） | GPL-2.0+（`awesome-support.php:19`） |
| 存储 | 自有表（`fs_*` 迁移器 14 个，`database/Migrations/`） | 自有表 `psmsc_*`（`class-wpsc-installation.php:115-378`） | **CPT**（post 表：`ticket`/`ticket_reply`/`ticket_history`，`functions-post-type.php:102,227-228`） |
| 游客开票（免费） | ✗（门户要账号，`app/` 内零 guest 路径） | **✓**（`shortcode-one.php:181`、`shortcode-two.php:43`） | ✗（`shortcode-submit.php:32-35` 未登录显示注册表单） |
| 前端 PHP 版本要求 | PHP 7.4+ | PHP 7.4+ | PHP 7.4+ |
| Node / 长驻进程 / cron 依赖 | 无（自带 action-scheduler vendor，走 WP cron） | 无（`class-wpsc-cron.php:63-64` 五分钟级 WP cron） | 无 |

维护状态：三者均在 2026-09/10 有更新。Awesome Support 6.4.1/6.4.2 全是安全补丁（`readme.txt:293-313`），项目仍活着但近年节奏以补丁为主；SupportCandy 处于大版本演进期（表前缀从 `wpsc_` 换代到 `psmsc_`，`upgrade/class-wpsc-upgrade-db-v1.php`、`v2.php` 可见迁移史，同时新写入 AI 挂件），单供应商（ticketyhq）；Fluent Support 背靠 WPManageNinja（Fluent 全家桶），免费包迭代最快（MCP、AI provider 层都是近期加入）。

## 2. SupportCandy 3.5.4 免费版深审

### 免费已有（源码证据）

- **游客建票**：`[supportcandy]` / `[wpsc_create_ticket]` 短代码（`shortcode-one.php:47`、`shortcode-two.php:16`），设置项 `allow-create-ticket` 含 `guest`（`shortcode-one.php:181`、`shortcode-two.php:43`）；游客提交姓名+邮箱即可。
- **游客查看与回复自己工单**：`[wpsc_open_ticket]`（`shortcode-three.php:22`）；每张工单有随机 `auth_code`（`models/class-wpsc-ticket.php:936-939`），邮件里的工单链接直接带 `ticket-id`+`auth-code`（`class-wpsc-ticket.php:941-961`），另有邮箱 OTP 认证路径（`shortcode-three.php:26-28`）。**访客全程无需账号。**
- **坐席收件箱与人工回复**：wp-admin 工单列表与单票页（`includes/admin/tickets/`），回复即插 `psmsc_threads`；分配、状态、优先级、分类齐全。
- **同一对话历史**：工单首帖 + 全部 thread 回复在同一单票页；游客经 auth-code 看到完整往返。
- **出站邮件**：触发器 → 后台邮件队列表 → 五分钟级 WP cron 扫描 → `wp_mail()` 发送（`class-wpsc-email-notifications.php:129` `send_background_emails`、`:151` `process_queue`、`:240` `wp_mail`；`class-wpsc-cron.php:63-64`）。
- **AI 聊天挂件（免费自带，BYOK）**：`wp_footer` 注入（`ai-chatbot/includes/class-wpsc-acb-admin.php:18-19`）；前端 AJAX 含 `nopriv`（`controller/class-wpsc-acb-chats.php:62-73`）；会话/消息存本站表 `psmsc_acb_sessions` / `psmsc_acb_messages`（`class-wpsc-installation.php:360,378`）；provider 仅 OpenAI / Gemini（`providers/` 目录），模块内零 license 检查（grep `is_pro|premium|upgrade` 于 `ai-chatbot/` 零命中）。
- **聊天 → 工单的官方升级实现**（本票最重要的范本）：`tools/core/class-wpsc-acb-create-support-ticket.php`——确认门用 transient 强制「先问后建」（`:93-170`）、游客必须给真实姓名/邮箱（`:175-230`）、`create_ticket_from_chat_session()`（`:250`）查活跃会话（`:283-300`）→ `WPSC_Ticket::insert($data)`（`:360`）→ 会话置 `HANDOFF`（`:369`）→ 首帖 thread 写入 AI 摘要（`:373-384`）→ **聊天转录生成为工单附件**（`:388`，`generate_session_attachment` `:438`）→ `do_action('wpsc_create_new_ticket', $ticket)`（`:390`）→ 出站邮件。
- **REST API（免费）**：命名空间 `supportcandy/v2`，工单 CRUD、customers、agents、categories、custom fields 等 11 个路由类 30 条路由（`includes/rest-api/`，`class-wpsc-rest-api.php:26,53`）。注意两点：需后台设置开启（`wpsc-ms-advanced-settings` 的 `rest-api`）；**POST `/tickets` 的 `permission_callback` 是 `is_user_logged_in`**（`class-wpsc-rest-tickets.php:22-37`）——REST 建票面向登录客户，游客路径仍走前端表单/内部函数。
- **hooks 面**：免费包约 195 个唯一 filter 名 + 198 个唯一 action 名（grep `wpsc_` 前缀统计）。
- **数据自有 + 可导出**：全部在 `psmsc_*` 表（tickets/threads/customers/custom_fields/attachments/acb_sessions/acb_messages/…，`class-wpsc-installation.php:115-378`）；官方导出 UI 是付费 addon，但 SQL/WP 工具直接可导，不构成锁定。

### 付费才有（免费包 addons 页逐一列名，`admin/misc/class-wpsc-addons.php:297` 等）

- Email Piping（入站邮件转工单）、工单导出 UI、Webhooks、SLA、Workflows、Canned Reply、报表、满意度调查、定时关单、**WooCommerce 集成**、Telegram/Slack 类集成（addons 目录 24 个付费扩展；免费包 grep 无任何 Telegram 实现，仅 `class-wpsc-rest-tickets.php:184` 注释提到外部来源可扩展）。
- **AI 知识库不自控是免费 AI 挂件的硬伤**：`search_knowledge_base` 工具（`tools/core/class-wpsc-acb-search-knowledge-base.php:24`）直接调 provider 的托管检索——OpenAI `file_search` + `vector_store_ids`（`providers/class-wpsc-aibot-openai.php:720-721`）、Gemini `file_search_store_names`（`providers/class-wpsc-aibot-gemini.php:730-735`）。知识文件须上传到 OpenAI/Gemini 的云端库。**违反「知识与对话存储自己控制」，AI 层因此不采用它。**

### 必须自写

- Rapls 会话 → SupportCandy 工单的升级胶水（见 §5）；入站邮件回复通道（不买 piping 时，客户回复走工单页链接）；Telegram 通知（沿 #103 的 Telegram Bot 方案，与工单系统平行）。

## 3. Fluent Support 2.4.7 免费版深审

### 免费已有

- **坐席收件箱（wp-admin SPA）**：REST 命名空间 `fluent-support`（`config/app.php:10`），路由含工单 CRUD、回复（`TicketController@createResponse`）、分配、bulk 动作、草稿、通知、产品（`app/Http/Routes/api.php:29-90`）。
- **hooks 面大且命名规整**：`fluent_support/before_ticket_create`、`ticket_created`、`response_added_by_*`、`agent_assigned_to_ticket`、`ticket_closed` 等 60+ 处 do_action；`ticket_created` 直连出站邮件（`app/Hooks/actions.php:30`）。
- **程序化建票**：`CustomerPortalService::createTicket()`（`app/Services/CustomerPortalService.php:119`）→ `TicketService::storeTicket()`（`app/Services/Tickets/TicketService.php:189`）；坐席代客建票有专用 hook（`ticket_created_behalf_of_customer`，`actions.php:35`）。
- **MCP server（免费包内置，默认关闭）**：`app/Modules/MCP/`——端点 `/wp-json/fluent-support/mcp`（`MCPInit.php:75-79`），工具含 `listTickets/getTicket/createTicket/updateTicket/closeTicket/replyToTicket/addInternalNote`（`Tools/TicketTools.php:454`、`Tools/ResponseTools.php:66,638`）。依赖 WP 6.9+ Abilities API + 外部 MCP adapter 插件（FluentHub 或 mcp-adapter，均不自带；`MCPInit.php:8-13` 注释明说），鉴权走 WP 用户 + 每能力权限门。
- **AI provider 层（BYOK）**：OpenAI/Gemini/Anthropic（`app/Services/Integrations/AI/`），设置页免费可配（`SettingsController.php:368,442`）；另有 FluentBot 托管 API 客户端（`Integrations/FluentBot/FluentBotAPI.php`，外部服务，键自备）。坐席侧 AI 辅助（回复润色等）与额度治理未见免费门控，但完整调用链只核实到设置层，列为局限。
- **WooCommerce**：免费注册「My Account → 工单」门户端点（`app/Services/Integrations/IntegrationInit.php:40-71`）；产品表存在（`database/Migrations/ProductsMigrator.php`）。产品同步深度未在免费包核实到 `wc_get_products` 调用（grep 零命中），官方文档页为 `docs.fluentsupport.com/woocommerce-integration`（未逐页核对）。

### 付费才有（免费包门控代码 + 官方定价页，Apify 2026-10-26 抓取）

- **Telegram**：处理代码就放在免费包（`app/Services/ThirdParty/HandleTelegramEvent.php:24-27,78`），但 `verifyProVersion()` 无 `FLUENTSUPPORTPRO` 常量即抛 "Telegram Integration requires pro version"——**代码在场不等于能力免费**。
- **Email Piping**：免费包只有 UI 文案（`Services/TranslationStrings.php:633-741`），无解析实现；官方定价页 Pro（$129→$90/yr 单站）明含 "10-business-email piping"。
- **客户门户游客路径**：免费包 `app/` 内 grep `guest` 零命中——门户必须注册登录（`ShortcodeHandler@fluentSupportPortal`，`app/Hooks/actions.php:18`）；游客经邮件开票依赖 Pro piping。

### 与本站冲突

「无账户、无结账」（AGENTS.md §1）直接排除其免费客户侧；无游客路径时，Rapls 升级若走 Fluent 只能创建 Person + 工单（内部 API 可做，源码可行），但**客户后续无法不注册就查看/回复工单**——人工接管退化为单向邮件，不满足「人工接管不能只是简陋留言」的对等要求。

## 4. Awesome Support 6.4.2 免费版深审

### 免费已有

- **程序化接缝最干净**：`wpas_insert_ticket( $data )`（`includes/functions-post.php:274`）与 `wpas_add_reply( $data, $parent_id )`（`:663`）——面向开发者的公开函数，Rapls 胶水可直接调用。
- **REST API（免费）**：命名空间 `wpas-api/v1`（`includes/rest-api/includes/api.php:67`），21 条路由（工单、回复、历史、状态、自定义字段、附件、用户）；鉴权挂 `determine_current_user`（`Auth/Init.php:55`）——即 **WP Application Passwords**，零额外依赖。
- **出站邮件**：`class-email-notifications.php` + `functions-email-notifications.php`，模板可改。
- **产品分类同步（免费）**：`includes/class-product-sync.php:5` 起，从指定 post_type 同步产品 taxonomy（含 WooCommerce 产品 post type 的机制在免费包）。
- 存储 = CPT：工单即 post，导出/查询直接用 WP 工具链。

### 付费/冲突

- **免费前端无游客路径**：`[ticket-submit]` 未登录时渲染注册表单（`includes/shortcodes/shortcode-submit.php:32-35`）——同 Fluent，与本站无账户裁定冲突。
- 维护以安全补丁为主（6.4.1/6.4.2 均为 XSS/权限修复，`readme.txt:293-313`），功能演进缓慢；扩展生态（getawesomesupport.com/addons/）全付费。Live Chat 是其付费扩展生态里的独立产品线，不在免费核心。

## 5. Rapls 组合接缝：现成连接器与最小胶水

**现成连接器：两级都为零**（Rapls 侧 #108 grep 零命中；本票反向在三插件包内 grep `rapls` 零命中）。必须自写，最小形态如下（推荐落点）：

**mu-plugin（1 个文件，仓库代码，非 Code Snippets）**，挂 #108 已核实的免费 hook：

```
do_action( 'raplsaich_new_message', $conversation, $message, $response['content'] )
    // rapls-ai-chatbot 1.21.4 rest-controller.php:1622，载荷含完整会话与双向消息
do_action( 'raplsaich_new_conversation', ... )   // conversation.php:128
```

人工意图判断（关键词或调 DeepSeek 分类）命中后，照 SupportCandy 官方范本（§2）执行：

1. `WPSC_DF_Customer::get_customer_record( $name, $email )`（游客找/建客户）；
2. 组装 `$data`（subject=AI 摘要、status/priority/category 默认值、`source` 自定义标记如 `rapls`）；
3. `WPSC_Ticket::insert( $data )`（范本 `:360`）+ `WPSC_Thread::insert( transcript )`（`:373`）+ 聊天转录附件（`:388`）；
4. `do_action( 'wpsc_create_new_ticket', $ticket )`（`:390`）触发既有通知/邮件链；
5. 把工单 auth-code 链接（`WPSC_Ticket::get_url()`）回贴到 Rapls 会话（经 `raplsaich_new_message` 同轮响应或前端注入）。

坐席侧无需新代码：wp-admin 单票页回复 → 既有 cron 邮件队列通知客户 → 客户点 auth-code 链接在工单页继续对话。**对话历史完整（聊天转录随单 + 后续 thread），人工接管不是留言板。** 与 #108 结论的关系：#108 的最简路径（md-inquiry + wp_mail 队列 + Telegram Bot）保持为零依赖兜底；本票的 SupportCandy 路径把「收件箱、指派、状态、同单历史、客户自助查看」换成现成产品面，代价是多装一个 1 万+ 安装的 GPL 插件。两者不互斥，Telegram 通知仍按 #103/#108 平行自建。

**明确不推荐的接缝**：

- Fluent Support MCP（`replyToTicket`/`createTicket`）：它是 AI 代理 → 工单的坐席侧通道，会让 AI（而非人）成为回复者，且不解决访客无账号查看；若未来做「AI 坐席自动回工单」再启用。
- SupportCandy 自带 AI 挂件直接当访客入口：知识上传 OpenAI/Gemini 云端，不自控（§2）。
- 任何「工单系统当实时聊天」的用法：三家免费版都没有同线程实时坐席对话；游客体验是异步工单页 + 邮件。

## 6. 推荐组合与理由

**Rapls 免费版（AI 层，知识/会话自控）+ SupportCandy 免费版（工单收件箱与人工回复）+ mu-plugin 胶水（`raplsaich_new_message` → `WPSC_Ticket::insert` 模板）+ Telegram Bot 通知（沿 #103）。**

- 零新增支出 ✓（两插件免费、AI BYOK、GPL 可自托管）；
- 无账户约束 ✓（游客开票 + auth-code 查看，三家唯一）;
- 知识与对话自控 ✓（Rapls 全本站库 #103；SupportCandy 工单/转录/会话全在 `psmsc_*` 自有表）；
- 人工接管对等 ✓（坐席收件箱 + 状态/指派 + 同单完整历史 + 客户免登录续答）；
- 共享主机 ✓（纯 PHP + WP cron，无 Node/常驻）;
- 胶水最小且有官方范本（SupportCandy 自己的聊天升级代码就是规格说明）。

## 7. 不适用与理由

| 候选 | 不适用理由 |
|---|---|
| Fluent Support 免费版做客户侧 | 门户强制账号；游客通道（piping/Telegram）全在 Pro；与本站无账户裁定冲突 |
| Awesome Support 免费版做客户侧 | 前端表单强制登录注册；同冲突；其干净 REST/`wpas_insert_ticket` 不足以弥补 |
| SupportCandy AI 挂件做知识层 | 知识上传 OpenAI vector store / Gemini file_search，不自控 |
| 三者任一当「实时聊天」 | 免费版均无同线程实时坐席对话；Knocket 式接管须 Rapls 会话方案（#108）或其 Pro |
| 购买任何 Pro | 零新增支出裁定（#86 前提）；Rapls Pro $49 买断是唯一已识别的例外候选，归业主未来裁定（#108 已记账） |

## 8. 证据局限

- 静态源码阅读，未装站运行、未发测试工单（票面禁止安装）；「游客建票/查看」为代码路径结论，评审站实测可在实施票补。
- Pro 包未购未读：Fluent/SupportCandy/AS 的 Pro 能力边界以免费包门控代码 + 官方定价页交叉印证；SupportCandy 定价页 JS 渲染，Apify 仅取到 424 字符（其 addons 清单以免费包 `class-wpsc-addons.php` 为准，更为可靠）。
- Fluent Support 坐席侧 AI（BaseAIProvider）只核实到设置层与 hook，完整回复生成调用点未定位；MCP 依赖的 WP Abilities API（6.9+）与 adapter 插件未实测，且本站 WP 版本未核对。
- SupportCandy hook 计数（195 filter / 198 action）为 grep 统计，未逐个验证语义。
- 三插件 wp.org 元数据取自 Plugins API 1.2（2026-10-26），安装量为区间显示。

## 附录：一手来源

- 源码包（本次审计对象，可从 wordpress.org 重建）：
  - https://downloads.wordpress.org/plugin/fluent-support.2.4.7.zip
  - https://downloads.wordpress.org/plugin/supportcandy.3.5.4.zip
  - https://downloads.wordpress.org/plugin/awesome-support.6.4.2.zip
- WordPress.org Plugins API 元数据：`https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=<slug>`（2026-10-26）
- Fluent Support 官方定价（Apify website-content-crawler 抓取，2026-10-26，run https://console.apify.com/actors/aYG0l9s7dbB7j3gbS/runs/2Ak71JE4rhF7NVrNR / dataset e9WWJvmU4Xe94q1xp）：https://fluentsupport.com/pricing/
- SupportCandy 付费 addons 清单（以免费包 `includes/admin/misc/class-wpsc-addons.php` 内链为准）：https://supportcandy.net/downloads/email-piping 、https://supportcandy.net/downloads/woocommerce-integration 等
- Awesome Support 官方文档/addons 入口（免费包 `includes/` 内 24 处引用）：https://getawesomesupport.com/documentation/ 、https://getawesomesupport.com/addons/
- Rapls 侧证据（引用，非本票重做）：#108 分支 `research/rapls-handoff-integration` 文档 `docs/research/2026-10-26-rapls-handoff-integration.md`（commit c1eb7f4）；#103 文档 `docs/research/2026-10-26-wp-native-kb-capacity.md`（分支 `research/wordpress-native-zero-cost`，commit 45fb5b4）
