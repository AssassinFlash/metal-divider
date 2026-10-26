# 研究：Rapls 免费版的会话、人工接管、通知、hooks/API 与 WooCommerce 集成

- 票：[#108](https://github.com/AssassinFlash/metal-divider/issues/108)（父地图 [#86](https://github.com/AssassinFlash/metal-divider/issues/86)）
- 分支：`research/rapls-handoff-integration`（worktree `.scratch/research-108-rapls`，基线 origin/main@941b496）
- 日期：2026-10-26
- 范围：Rapls（rapls-ai-chatbot）免费版的会话、人工接管、通知、hooks/API 与 Woo 集成，能否直接接 WordPress 工单。**不含**容量/索引/embedding 研究（#103 已裁）。

## 研究对象与版本

| 项 | 值 | 来源 |
| --- | --- | --- |
| 版本 | 1.21.4（stable tag） | readme.txt:7；wordpress.org API `1.0/rapls-ai-chatbot.json` |
| 最后更新 | 2026-10-20 | wordpress.org API |
| 要求 | WP 6.3+ / PHP 7.4+ / MySQL 5.7+，tested up to WP 7.1.3 | readme.txt:4-6；官方页 System Requirements |
| 许可证 | GPLv2 or later（含 LICENSE 文件） | readme.txt:8；LICENSE:1-5 |
| 厂商 | Rapls Works（日本，单人），raplsworks.com | 官方页页脚；profiles.wordpress.org/rapls |
| 规模 | 9 条评分 / 6,435 下载（小生态） | wordpress.org API |
| 免费包 | https://downloads.wordpress.org/plugin/rapls-ai-chatbot.zip | 一手下载并全源码阅读 |
| 官方页（EN，含 Free vs Pro） | https://raplsworks.com/rapls-ai-chatbot-en/ （2026-10-11 更新） | 一手抓取 |
| Pro 购买页 | https://raplsworks.com/plugins/rapls-ai-chatbot-pro/ = `RAPLSAICH_PRO_URL`（rapls-ai-chatbot.php:45） | 源码 + 一手抓取 |

下文 file:line 均指免费包 v1.21.4 源码。

## 结论一页纸

**Rapls 免费版是完整的"AI 聊天 + 自有会话存储"件，不是客服收件件。人工接管的每一环（关键词触发、operator 回复界面、实时轮询、交回 AI、离线留言表单）都在 Pro**；免费版连"转人工"这个意图都无法识别（`is_handoff_keyword()` 免费恒返 false，includes/class-extensions.php:311-313）。

但免费版留了一条**真正可用的胶水通道**：`do_action('raplsaich_new_message', $conversation, $message, $response['content'])`（includes/api/class-rest-controller.php:1622）与 `raplsaich_new_conversation`（includes/models/class-conversation.php:128），载荷含完整会话记录与双向消息，配合全量自有数据表，足以自写 mu-plugin 把对话转出到任意目的地（工单插件、邮件队列、Telegram）。

**不能直接接 WordPress 工单**：WordPress 无原生工单系统，Rapls 源码内也无任何工单/客服插件连接器（SupportCandy/Awesome Support/Fluent Support/helpdesk 零命中）。工单化必须自写监听 action 的胶水。

**推荐组合**：Rapls 免费版做 AI 层 + 会话存储；人工层沿用 #103 已裁定的零成本接管（md-inquiry + wp_mail 队列 + Telegram Bot），以 `raplsaich_new_message` 为触发源；Rapls 自带后台会话页当只读档案用。**不建议**自写 operator 回复插表 + 轮询 JS 去复刻 Pro 的 operator 模式——那是自费重造付费件，且免费挂件刷新前看不到回复。

Pro 是 $49 买断（1 站，更新不限期，14 天退款）——超出零支出裁定，不采购，仅记录为事实。

## 1. 对话数据模型与保留

免费包建 9 张自有表（includes/class-activator.php:92-232 dbDelta）：`raplsaich_conversations`、`raplsaich_messages`、`raplsaich_index`、`raplsaich_knowledge`、`raplsaich_leads`、`raplsaich_user_context`、`raplsaich_audit_log`、`raplsaich_knowledge_versions` 等。

- **conversations**（activator.php:92-110）：`session_id VARCHAR(64)`、`user_id`、`visitor_ip VARCHAR(64)`（注释明示是 IP+UA+salt 的 SHA-256 指纹，非明文 IP）、`user_agent`、`country_code`、`page_url`、`channel`、`status`；Pro 装后增列 `handoff_status`/`handoff_at`（activator.php:643-653 迁移，免费包内该列默认不存在）。
- **messages**（activator.php:115-136）：`role`（user/assistant/operator）、`content LONGTEXT`、token 三列、`ai_provider`/`ai_model`、`metadata LONGTEXT`（存 sources、卡片等展示元数据）。
- **leads 表免费建但不写**：全包唯一写入点在 Pro（免费无任何 `RAPLSAICH_Lead::create` 调用；免费仅在会话列表模板读取 `RAPLSAICH_Lead::get_by_conversation`，templates/admin/conversations.php:148）。
- **保留**：`retention_days` 设置默认 90 天，0 = 永久（readme 1.21.1 changelog 明示）；清理走 `raplsaich_cleanup_old_conversations` 半小时 cron（activator.php:909），批量 1000 条删旧会话+消息，顺带把 30 分钟无活动的 active 会话自动置 closed（includes/class-main.php:461-510）。Pro 另有 `data_retention_enabled/days` 覆盖（class-main.php:469-472）。

## 2. 访客端功能（免费）

挂件（templates/frontend/chatbot-widget.php + assets/js/chatbot.js，3,094 行）免费已有：预设问题 chip（含零 token 的 canned 模式）、页面上下文感知（代词问"这个多少钱"落到当前页，rest-controller.php:1057-1059）、13 语自动回复、访客**自删本人历史**按钮（widget 模板:63，服务端校验所有权）、图片/文件上传参数位（/chat args，rest-controller.php:307-318）、Gutenberg 区块、`?raplsaich_q=` 深链自动发问。

**访客端没有**：转人工按钮、离线留言表单、lead 表单——挂件模板只留了 `do_action('raplsaich_widget_lead_form')` 空槽给 Pro 注入表单（templates/frontend/chatbot-widget.php:75，注释原文"Pro plugin injects the actual form via this action"）。

## 3. 人工回复与接管：免费/Pro 分界（源码逐环核实）

| 环节 | 免费 | 依据 |
| --- | --- | --- |
| 后台会话页 | **只读**：列表、筛选、查看消息流、删除、归档 | class-main.php:199-205 注册的 AJAX 仅 get/delete/archive/unarchive/bulk |
| operator 回复界面 | 模板里有按钮和回复框，但无后端 | templates/admin/conversations.php:328-342 存在 UI；class-admin.php 与 class-main.php 均无 operator 发送 handler |
| 转人工触发（关键词/按钮） | 无 | `raplsaich_pre_ai_handoff_check` filter 免费无人挂，恒返 null 跳过（rest-controller.php:1028-1035）；`is_handoff_keyword()` 恒 false（class-extensions.php:311） |
| 离线留言表单 | 无 | `offline_message_enabled` 属 Pro 扩展设置（class-chatbot-widget.php:713-714）；免费未注册 /offline 路由 |
| 访客端实时收 operator 消息 | 无轮询 | chatbot.js 仅两处 `typeof self.startHandoffPolling === "function" && ...` 守卫调用（chatbot.js:1520、1570），**该函数在免费 JS 中无定义** |
| 交回 AI（cancel handoff） | 空操作 | 免费仅留 `ajax_reset_handoff` → `$pro->cancel_handoff()` 空 stub（class-extensions.php:318-319；class-admin.php:3880-3895） |
| 导出会话 CSV/JSON | 无（模板 `$is_pro_active` 门控） | templates/admin/conversations.php:12、112-129 |

**结构性可行的自写通道**（免费确实具备的半程）：`/history/{session_id}` GET 会返回**所有角色**的消息含 operator（rest-controller.php:2056-2075 不按 role 过滤），且挂件加载历史时会渲染 operator 角色气泡与头像（chatbot.js:1829-1844）。因此自写代码直接向 `wp_raplsaich_messages` 插 `role='operator'` 行，访客**刷新页面后**能看到人工回复。要做到"不刷新实时收"，须再自写前端轮询——等于复刻 Pro，不推荐（见"不适用"）。

## 4. 通知

- **免费已有**：周报邮件（会话数、成本、未答问题 Top3 → `wp_mail(get_option('admin_email'))`，class-main.php:434；设置可关）；GA4 事件；handoff 待处理 admin notice 的**渲染代码**在免费包内，但仅当 Pro 已启用 handoff 才显示（class-admin.php:117-124 检查 `$pro_settings['human_handoff_enabled']`）。
- **Pro 才有**：新线索邮件通知、Slack/Google Sheets/Zapier webhook、LINE（官方页 Pro 列表；免费包源码侧证：`RAPLSAICH_Webhook` 类只在 `class_exists` 守卫内调用，rest-controller.php:1627-1636；`webhook_secret`/`slack_webhook_url`/`line_channel_*` 列在 Pro 专属敏感设置项，class-admin.php:4214）。
- **Telegram：免费包与官方文档均无任何提及**（grep 零命中）。要 Telegram 通知必须自写（沿 #103 裁定的 Bot 路线）。

## 5. Hooks / REST / AJAX / MCP

- **WordPress hooks**：120 处调用、92 个唯一名（17 action + 75 filter）。人工接管相关关键位：`raplsaich_new_message`（rest-controller.php:1622，载荷 `$conversation, $message, $response['content']`，仅 `save_history` 开启时触发）、`raplsaich_new_conversation`（conversation.php:128）、`raplsaich_pre_ai_handoff_check`、`raplsaich_pre_chat_check`（IP 封禁/敏感词挂点，rest-controller.php:848-856）、`raplsaich_ai_response`、`raplsaich_system_prompt`、`raplsaich_widget_lead_form`、`raplsaich_rate_limit_exceeded`、`raplsaich_recaptcha_failed`。
- **REST**（namespace `rapls-ai-chatbot/v1`，class-rest-controller.php:252-441）：免费注册 8 条路由——`GET /session`、`POST /chat`、`POST /preset-canned`、`GET /history/{session_id}`、`GET /lead-config`、`GET /message-limit`、`POST /feedback`、`POST /history/delete`。官方页写"10+ endpoints"，多出的为 Pro 路由（/offline、/save-context 等，JS 调用免费 404 忽略，chatbot.js:1549-1558）。`/lead-config` 免费强制返回禁用（`raplsaich_is_pro_active() && lead_capture_enabled`，rest-controller.php:3556-3557，注释明示防 Pro 停用后脏值）。
- **AJAX**（wp-admin 侧，class-main.php:185-240）：会话查看/删除/归档、知识库 CRUD、embedding 生成、设置导入导出、MCP key 生成等约 40 个，全走 manage capability（`raplsaich_manage_cap` filter 可收窄）。
- **MCP Server**（免费 5 工具，官方页与 MCP.md）：`get_conversation`、`list_conversations`、`get_site_info`、`search_knowledge`、`send_message`；Bearer key 鉴权，key 只存 bcrypt hash（class-mcp-server.php:78-115）；Pro 另有 2 工具（product search、analytics）。注意：**MCP `send_message` 走的是 AI provider 链路**（RAG→AI→存 assistant 消息，class-tool-send-message.php:105-160），不是人工回复通道；`list/get_conversation` 提供的是只读会话访问（可给外部 agent 做值班看板）。

## 6. 鉴权与速率限制（免费已有，质量高）

- 会话所有权三层验证：cookie → HMAC 签名 token（`X-RAPLSAICH-Session-Token` header，TTL 可 filter）→ IP+UA 哈希对照（rest-controller.php:2721-2770）；管理员 capability 直通。session_id 强制 UUID4 格式（rest-controller.php:472-476），并优先走 header 传递避免 session 泄漏进 URL/日志（rest-controller.php:2616-2622）。
- 公开端点独立限速（/session 30 次/60s，rest-controller.php:458）；/chat 默认 20 msg/小时/访客可配，0=不限（rest-controller.php:2897-2922）；消息长度 8000 字符上限（rest-controller.php:270-286）；reCAPTCHA 可选 + 蜜罐/时序检测（字段名如 `honeypot_lead`/`timing_lead`，rest-controller.php:3026）；AI 失败分类记录（changelog 1.15.0）。

## 7. 隐情 / 导出 / 删除

- **无 WordPress 原生 GDPR 集成**：`wp_privacy_*`/exporter/eraser 零注册（grep 零命中）——不会出现在 WP 后台"导出个人数据"工具里。
- 访客自助：`POST /history/delete`（所有权校验后物理删本人会话）。
- 管理端：单删/批删/全删会话（AJAX）；CSV/JSON 导出 = Pro。
- 卸载：`uninstall.php` 按设置可选删全部表与 options，默认保留；transient 恒清。
- IP 以 SHA-256 指纹存储（schema 注释），明文 IP 不落库。

## 8. WooCommerce 集成

- 免费包**含** `extract_woocommerce_data()`（includes/crawler/class-content-extractor.php:452-496）：爬到 `product` 类型文章时把价格/重量/尺寸等抽成结构化文本进索引——这是**知识索引侧**，不是对话侧。
- 但免费默认只爬 post+page（`crawler_post_types` 默认 `['post','page']`，class-site-crawler.php:409），**选择内容类型的界面在 Pro**（changelog 1.21.1 原文"Choosing content types and the schedule … stay in Pro"）；`'all'` 会展开到全部公开类型含 product（class-site-crawler.php:410-413）。官方对比表把"WooCommerce product data auto-crawl"列在 Pro（营销口径与免费代码能力在此有灰区：代码在免费包内，控制 UI 在 Pro）。
- **对话侧 Woo 能力全在 Pro**：聊天内产品卡（免费 REST 仅透传 metadata 里的 `product_cards`，不生成，rest-controller.php:1763-1764）、转化追踪、订单/购物车感知。源码无任何 Woo order/cart hook。
- 结论：免费版对 Woo 的实际用法 = 把产品页当普通内容学习 + 页面上下文感知（访客在 PDP 上问"这个"能对上）。这对本站 /pattern/ PDP 语境已够用，与 #103 的"知识= Woo 派生副本"裁定兼容。

## 9. 许可证与商业模式

免费 GPLv2+ 无功能阉割式限制（消息数/知识条数 `PHP_INT_MAX`，class-extensions.php:22-24）；Pro 是同 repo 叠加插件（`RAPLSAICH_Extensions` stub + Pro 类覆盖，class-extensions.php:19-27 守卫注释），数据无缝保留。Pro $49 买断 1 站 / $99 5 站，更新不限期、支持 1 年、14 天无理由退款（官方页 Pricing，一手）。

## 10. 免费已有 / 付费才有 / 必须自写

| 能力 | 状态 | 依据 |
| --- | --- | --- |
| AI 聊天、RAG、知识库、挂件、会话存储 | 免费已有 | #103 + 本文 §1-2 |
| 会话后台只读查看/删除/归档 | 免费已有 | class-main.php:199-205 |
| 转人工关键词识别、handoff 状态机 | **Pro**（免费 stub 全空） | class-extensions.php:311、318 |
| operator 后台回复 + 访客端实时轮询 + 交回 AI | **Pro** | §3 表 |
| 离线留言表单 | **Pro** | class-chatbot-widget.php:713 |
| Lead 表单/导出/新线索邮件 | **Pro**（表已建、免费不写） | rest-controller.php:3556；§1 |
| Slack/LINE/Google Sheets/webhook 通知 | **Pro** | §4 |
| Telegram 通知 | **必须自写**（两级均无） | grep 零命中 |
| 接 WordPress 工单插件 | **必须自写**（无任何连接器） | §0/§5 grep 零命中 |
| 会话导出 CSV/JSON | **Pro** | conversations.php:112 |
| Woo 聊天内产品卡/转化追踪 | **Pro** | §8 |
| 把对话转出（工单/邮件/TG）的触发点 | 免费已有（hooks + 自有表） | §5 |
| operator 消息插表后访客刷新可见 | 免费已有（渲染路径在） | §3 |
| GDPR 原生导出/擦除 | **必须自写**（未注册） | §7 |

## 11. 推荐组合与最少胶水

1. **Rapls 免费版 = AI 层 + 会话档案**。`save_history` 开启（不然后述 action 全不触发）。
2. **人工接管走 #103 已裁定通道**：md-inquiry + wp_mail 出站队列 + Telegram Bot。新增的最少胶水是一个 mu-plugin：
   - 挂 `raplsaich_new_message`，对 `(message, response)` 做意图判断（关键词或让 AI 在系统提示里输出结构化信号），命中"需要人工/询盘"即写 md-inquiry 询盘或发 Telegram——数据全部本站，无新增支出。
   - 不做 operator 插表、不做前端轮询：免费挂件刷新前看不到回复，体验半残；正确的人工回复落点是 md-inquiry 的邮件/WhatsApp 链路，与现有询盘链一致（AGENTS.md：链路只有一条）。
3. **WordPress 工单**：若后续要工单形态，胶水同上（action → 工单插件 REST/创建函数），但首期不建议引入工单插件——本站询盘链无工单语义，会重复 md-inquiry 容量。
4. **Pro $49 买断**是唯一官方 operator 模式路径：若业主未来愿意一次性支出，它是现成解（数据同表、无缝升级）；在零支出裁定内不采。

## 12. 不适用与理由

- **把 Rapls 后台会话页当收件箱**：免费只读，无法回复；operator UI 是 Pro 的壳。
- **自写 operator 模式复刻 Pro**：插表+轮询 JS+handoff 状态机三件都要自造，维护面大于价值，且免费挂件的轮询函数缺失说明作者明确把该体验划给 Pro——逆向补齐会在每次插件更新时承压。
- **MCP send_message 当人工回复**：它是 AI 链路（会再调 provider、计费、存 assistant 消息），不是坐席回复接口。
- **离线留言**：属 Pro；免费版的"离线"语义应由自写胶水把对话引导到 md-inquiry 表单实现。

## 13. 证据局限

- 只核免费包 v1.21.4 源码；**Pro 包未购未读**，Pro 行为结论来自免费包内的门控/stub/接口与官方页交叉印证，未逐行验证 Pro 实现。
- 未安装运行（票面禁装生产/站点），行为结论为静态源码阅读；"免费 JS 无轮询定义"等结论以 1.21.4 为准，后续版本可能变化。
- 官方 Free Manual（https://raplsworks.com/rapls-ai-chatbot-free-manual-en/）未逐页核对，仅用官方页对比表与 changelog。
- wordpress.org 生态小（9 评分/6,435 下载、单人厂商）：功能与更新质量当前良好（changelog 密集、修复认真），但长期维护是选型风险，应由父地图统一记账。
