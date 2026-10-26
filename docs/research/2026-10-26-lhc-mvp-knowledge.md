# Live Helper Chat MVP 的三类知识源与最小集成

- 票：[#114](https://github.com/AssassinFlash/metal-divider/issues/114)（父地图 [#86](https://github.com/AssassinFlash/metal-divider/issues/86)）
- 日期：2026-10-26
- 分支：`research/lhc-mvp-knowledge`（worktree `.scratch/research-114-lhc-mvp`，基线 origin/main@941b496，不合并、不开 PR）
- 工作树用途：本票研究文档的唯一临时工作副本；负责人：本研究会话（assignee）；回收条件：分支推送成功且票关闭后删除工作树，远程分支保留
- 证据分级：【实测】= 本次直接读取的页面/源码/HTTP；【源码】= 插件/LHC 源码 file:line；【官方】= 一手官方文档；【承前】= 复用前置票已核实证据（#87/#103/#108/#110，未重测）

## 结论速览

**最小架构（推荐）**：LHC core（Apache-2.0，独立子目录/子域）+ Rapls 免费版（知识层，WP 库内）+ 1 个 WP mu-plugin 知识端点（唯一必须写的代码）+ LHC bot 导入官方非流式配置后改指本站端点。三类知识全部由 Rapls 免费版承载：FAQ（知识条目 CRUD）、URL（站内爬虫）、文件（TXT/CSV/MD/PDF/DOCX 上传解析）——**三类都能低成本映射，无需为统一后台重写知识系统**。

- Rapls 免费版对三类知识的覆盖度约 85%：FAQ 与文件上传全量可用；URL 入口是「站内爬虫」而非 Knocket 的「任意公网 URL 单页抓取」，对本站场景（知识=本站 Woo 派生内容）反而更贴合；缺口仅 HTML/DOC/XLSX 三种格式、10MB 上限与任意外部 URL。
- 人工接管零代码：官方 bot 配置导入即带 `transfer_operator` 模式，源码级证据为 `transferToOperatorAction()` 把会话置 `STATUS_PENDING_CHAT` 进坐席分配队列（commit afa3b17）。
- 本票全程只读：未安装、未改任何实例与生产；本地启动最短路径见 §6。

## 1. Knocket 三类知识入口：字段、边界与 MVP 复现范围

承 #87（`docs/research/2026-10-26-knocket-capabilities.md`，分支 research/knocket-capabilities，2026-10-26 截图像素级实测；本次未重测）。

### 1.1 三类入口的实际字段与操作

| 入口 | 字段/操作 | 实测证据 |
| --- | --- | --- |
| FAQ | 计数器 `2/10`；Add FAQ、搜索、**编辑、删除**；无导出 | 控制台截图 knowledge-faq.png |
| URL | 计数器 `3/10`；每行一个完整 URL；行上 `Last Synced` 时间戳与删除；**无独立 Sync 按钮**，重爬=删行再加；无深度/选择器/排除规则（单页抓取） | knowledge-url.png |
| 文件 | 计数器 `1/10`；Max size 10MB；格式 PDF, TXT, DOC, DOCX, HTML, MD, CSV, XLSX | knowledge-file.png |
| 索引状态 | 行上 `Indexed` 徽章；教程称通常数秒 | 徽章实测，耗时是宣称 |
| 模型侧 | 系统提示词 2000 字、问候 400 字、头像 1MB；默认作答规则「不编造；只根据提供的知识；没有就说没有」；嵌入凭据与模型由平台管理 | bot.png / llm.png / setting.png |
| 访客体验 | AI 开启时消息先经 AI；高置信直接回复，低置信或命中人工短语（宣称 47 语默认短语库）立即转人工；收件箱可按 All/AI Resolved/Human 过滤，可 Resume AI | 官方教程（未实走） |

### 1.2 MVP 应复现的边界

**复现**：三类输入入口；每条的编辑/删除与索引状态可见；「只据知识作答、答不出就说没有」的默认规则；转人工（短语 + 模型判断两路）；坐席接管后 AI 停止抢答；离线留言进同一线程。

**不复现**（Knocket 的产品限制或超出 MVP）：10/10/10 条数与 10MB 上限（是它的输入限制，不是质量目标）；嵌入由平台管理（自控正是本工程理由）；无导出（我们的知识本来就在自家库里，导出即 SQL）；Resume AI 交回按钮（LHC 后台原生有 transferToBotAction，不另做界面）；挂件主页静态 FAQ、社交快捷方式、会议链接、联系页托管、移动 WebView 入口（父地图已排除移动 SDK/联系页）。

## 2. LHC core 最小安装与配置要点

版本与源码基线：release **4.97v（2026-10-23）**；master commit **afa3b17647fbfc0325b5d87fc5e78ac449441c3d**（2026-10-26T09:02:49Z，GitHub API【实测】）；许可证 core Apache-2.0。以下【官方】除注明外均出自 2026-10-26 抓取的官方文档。

### 2.1 最小安装

| 项 | 要求 | 来源 |
| --- | --- | --- |
| PHP | ≥ 8.2（本机 php@8.3 8.3.35_1、主机 PHP 8.3.36 均满足） | doc.livehelperchat.com/docs/install |
| PHP 扩展（必需） | php-json, php-cli, php-gd, php-xml, php-common, php-pdo, php-pecl-zip, php-mysqlnd, php-mbstring, php-bcmath | 同上（页面 Required 清单） |
| PHP 扩展（可选推荐） | php-phpiredis, php-imap, php-pecl-redis4, php-pecl-igbinary, php-geos, php-fpm, php-opcache（**MVP 全不需要**；无 Redis 时 LHC 直接跑数据库） | 同上 |
| 数据库 | MySQL 5.7 / MariaDB 10.2.3+（json_ 函数支持）；库须**预先创建**，安装页只填连接信息 | 同上 |
| 依赖 | 用 `*.*v-with-dependencies.tgz` 发布包免 composer；否则 `composer.phar install` | 同上 |
| 目录（chmod 755） | `settings/`、`cache/`、`var/storage`、`var/userphoto`、`var/storageform`、`var/storageadmintheme`、`var/botphoto`、`var/bottrphoto`、`var/storageinvitation`、`var/storagedocshare`、`var/storagetheme`、`var/tmpfiles` | 同上 |
| 安装方式 | Web 向导 `index.php/site_admin/install/install`，或 CLI：`cp cli/install-cli.php .` + `settings.ini` + `php install-cli.php cli/settings.ini` | 同上（MVP 隔离环境用 CLI 更可脚本化） |
| WP 共存 | 官方明示 "very likely to work seamlessly"；WP pretty permalinks 下子目录共存需 WP 根 `.htaccess` 加 `RewriteRule ^lhc_web/.*$ - [L]`；建议改 `php_session_cookie_name`（如 `lhc_session_id`）避免与 WP 会话互踢 | 同上 |

### 2.2 cron（最小集）

【官方】`php cron.php -s site_admin -c <job>`：

- `cron/workflow`——每分钟（自动分配、工作流；官方高性能示例为 1 分钟 12 个错峰实例，MVP 单实例即可）
- `cron/notifications`——每分钟（未读消息 web push 通知）
- 可选：`cron/department-availability`（部门在线状态切换）、`8 */8 * * * cron/archive`（归档）、`cron/util/update_database`（升级时手动）

来源：doc.livehelperchat.com/docs/performance（cronjobs 全表）、/docs/system/system-commands、/docs/chat/web-push-notifications。

### 2.3 挂件与坐席接管

- 挂件 embed：设置窗 → Embed code → Widget embed code (new)，参数含 `mode:'widget'`、`leaveamessage:true`（离线即留言表单，进收件箱）、`proactive:true`；脚本同源可部署（【官方】install 文档，示例代码已核对）。
- 坐席端：back office Pending / My active and pending chats 挂件 + 自动分配（自动分配官方建议只经 cron 跑，见 performance 页）；浏览器通知 + 声音。
- **AI 转人工（源码级）**：`lhc_web/lib/core/lhgenericbot/actionsChat/lhgenericbotupdateactions.php:5-23`（commit afa3b17【实测】）——`transferToOperatorAction()` 写入系统消息 "Chat was transferred to operator!" 并把 `chat->status` 置 `STATUS_PENDING_CHAT`、记 `pnd_time`，会话随即进入坐席分配/通知流；同文件 `transferToBotAction()`（:24-42）置回 `STATUS_BOT_CHAT` 实现「交回 AI」。两者都是 bot 触发器内置动作，配置即用。
- 非接管期 AI 应答、接管后 bot 静默：bot 状态机内建（bot 只在 `STATUS_BOT_CHAT` 应答）。

### 2.4 bot 调第三方 REST 与非流式 DeepSeek 配置

【官方】doc.livehelperchat.com/docs/bot/rest-api：

- Rest API Call：Host + Sub URL + Method + Params（header，含 Basic/Bearer）+ Body（RAW JSON 或 POST 参数）；Body 内可用替换变量 `{{msg}}`、`{{chat_id}}`、`{{args.chat.chat_locale}}`、`{previous_visitor_messages_list_url__10__1}`（注入历史）等。
- 输出解析：最多 6 个 Response Location（`msg`、`response:location`、数组/条件提取均可）映射为触发器变量 `{content_1..6}`；可按 HTTP 状态码或**响应属性条件**（如 `{"action":"transfer_operator"}`）匹配不同输出组合，未匹配走 Default trigger；失败兜底变量 `{http_code}`、`{http_error}`。
- 官方 ChatGPT 集成即此机制的配置模板（doc/bot/chatgpt-responses）：**非流式版可直接导入**（"Streaming version requires NodeJS and PHP-Resque extensions"——共享主机不做流式，等待期用 Send typing 提示）；`transfer_operator` 是官方演示函数，系统提示词建议 "Always call transfer_operator if (and only if) you are explicitly asked for a human OR if you can not help"；转人工落法 = 触发器事件 Custom text matching（词 `transfer_operator`）→ 执行 §2.3 的转接动作。
- DeepSeek：官方 api.deepseek.com 为 OpenAI-compatible `chat/completions`（承 #103：无 embeddings 端点，chat 兼容已核），非流式=不设 `"stream":true`。两种接法：
  - **A（推荐）**：LHC 只调本站 WP 端点（§4），DeepSeek 密钥与提示词留在 WP 侧，LHC 侧零密钥；
  - B（两段式）：LHC 直接以官方 Chat Completions 非流式模板指向 `https://api.deepseek.com`（Bearer + model `deepseek-flash`，AGENTS §6 模型路由），另配一条 Rest API 调 WP 检索端点拼上下文——配置量翻倍、提示词在 LHC 后台，不推荐为 MVP 默认。
- 响应慢时：chatgpt-responses 页建议调大 "Maximum execution time"；主机 `max_execution_time` 实测 300s（承 #103），余量充足。

## 3. Rapls 免费版：三类知识的可复用度与最小缺口

对象：wordpress.org 包 `rapls-ai-chatbot` v1.21.4（2026-10-26 下载解包，全源码静态阅读【源码】；#108/#103 已核总体架构与会话/接管分界）。**注意**：#108 曾记「内容类型与计划选择属 Pro」（changelog 1.21.1），当前 1.21.4 免费包模板 `templates/admin/crawler.php:178-210` 已直接暴露 post types 多选与 crawl interval 下拉——该旧结论按现行源码修正，爬虫 UI 免费可用。

### 3.1 三类入口逐一对照（全部免费包内【源码】）

| 能力 | 免费版现状 | 依据 |
| --- | --- | --- |
| FAQ | 知识条目 CRUD（add/get/update/delete/toggle/priority AJAX）、`type` 仅 `qa`/`template` 两值；另有 AI 生成 FAQ 草稿（`ajax_generate_faq_draft`，免费） | class-main.php:208-214、195-196；class-knowledge.php:104 |
| 文件上传解析 | 后台上传 TXT/CSV/MD/PDF/DOCX，≤ **5MB**；PDF 仅文本型（扫描/加密报 `pdf_no_text`）、DOCX 需 ZipArchive、CSV/编码（含 SJIS→UTF-8）自带处理；大文档自动分组切块入库 | admin/class-admin.php:3951-3981（5MB 判定）；models/class-knowledge.php:880-960（switch 各格式）；parsers/class-pdf-parser.php、class-docx-parser.php |
| URL | **站内爬虫**：`save_post` 即时索引（发布索引、非发布删除——自动失效，符合 Woo 唯一资料源）、定时全量爬（hourly..monthly）、手动全量爬（`ajax_manual_crawl`）、排除/包含指定 ID、`crawler_post_types` 默认 `['post','page']` 可选 `all`（展开全部公开类型含 product）、Woo 产品结构化抽取（价格/尺寸入索引） | crawler/class-site-crawler.php:404-425；class-main.php:185、442；admin/class-admin.php:693-697；content-extractor.php:452-496 |
| 索引状态 | 嵌入生成/清除/状态 AJAX（`ajax_generate_embeddings` / `embedding_status`）、单条知识启停（toggle）、索引单删/全删、知识版本表 | class-main.php:186-188、213、217-219 |
| 检索 | `RAPLSAICH_Search_Engine::search($query, $limit)` + `search_knowledge_only()` + `build_context()`（拼上下文字符串）；批 500 读 LONGBLOB→PHP 余弦 top-N 剪枝（容量承 #103：本站 200-600 块 <0.5s） | crawler/class-search-engine.php:46、122、354、961；class-vector-search.php |
| 删除 | 知识删除（AJAX）、索引删除、会话删除/归档/批删、卸载可选全删 | class-main.php:210-213、186-187 |
| 外部调用面 | 仅 BYOK AI 端点（OpenAI-compatible Provider 自带 `send_message()`，DeepSeek 可直接走）+ 可选 reCAPTCHA；无知识库同步端点 | ai-providers/class-openai-compatible-provider.php:66-79 |

### 3.2 结论：三类能低成本映射，最小缺口如下

统一后台**不需要重写**：三类入口的终点都是 `wp_raplsaich_knowledge` + `wp_raplsaich_index`（同一检索面），Rapls 免费后台已经是「三类一屏」的知识管理页（templates/admin/knowledge.php + crawler.php）。真实缺口只有三处，全部可忽略或用最省替代：

1. **任意外部 URL 单页抓取**（Knocket URL 入口的字面能力）：Rapls 爬虫只吃本站内容。本站知识= Woo 派生（AGENTS 2026-10-26 裁定），外部 URL 不是需求；真需要时最省替代=把页面正文粘贴为 FAQ/知识条目或存 TXT/MD 上传，零代码。
2. **HTML/DOC/XLSX 三种文件格式与 10MB 上限**（Knocket 8 格式/10MB vs Rapls 5 格式/5MB）：MVP 语料（FAQ、产品规格、文档）以纯文本为主，TXT/MD/PDF/DOCX/CSV 已覆盖；XLSX 如真出现，转 CSV 即可，零代码。
3. **条数计数器/Indexed 徽章的界面样式**：Rapls 有嵌入状态与启停但没有 Knocket 式行内 Indexed 徽章——纯界面差异，不为它写代码（AGENTS：不为界面完整重写知识系统）。

若坚持三类完全字面映射（外部 URL 抓取入知识库）：需写约一个抓取胶水（wp_remote_get→strip 标签→`RAPLSAICH_Knowledge::create`，估 30-50 行 mu-plugin）——MVP 不做，列入排除项。

## 4. 最小 WP 知识端点规格（唯一必须写的代码）

一个 mu-plugin 文件（建议 `mu-plugins/md-kb-endpoint.php`，仓库内）。LHC 与它之间是唯一跨系统调用。

### 4.1 契约

```
POST /wp-json/md/v1/kb/answer
Authorization: Bearer <token>          # ≥32 字节随机串，存 WP option（不入仓库）；
                                       # LHC 侧配在 Rest API 的 Params(header) 里
Content-Type: application/json

请求：{"q": "<访客问题>", "chat_id": "<LHC chat id>", "history": ["…", "…"]}   # q 必填，其余可选
```

成功（200，始终同步返回，无流式）：

```json
{"msg": "给访客的答案（纯文本/bbcode）", "action": "",          "sources": ["faq#12","pattern#…"]}
{"msg": "正在为您转接人工…",             "action": "transfer_operator", "sources": []}
```

- `action` 只有两个值：`""`（正常答）与 `transfer_operator`（转人工）。转人工判定在 WP 侧完成：系统提示词要求模型输出 JSON `{"answer":…,"handoff":bool}`——命中「明说要人工/agent」或「模型自认无法依据知识回答」即 handoff（复刻 Knocket 的两路触发：短语 + 模型判断）。
- `sources` 仅供审计/验收，不展示给访客。
- 提示词基线沿用 Knocket 默认作答规则：只依据提供的知识回答；没有就说没有并建议转人工/询盘；答案带图案/页面链接时用检索命中条目的 URL。

### 4.2 服务端流程与超时

1. Bearer 校验（`hash_equals`，不匹配 401 `{"error":{"code":"unauthorized"}}`，限速 10 次/分防爆破）。
2. 参数校验（`q` 缺失/超 4000 字符 → 400 `bad_request`）。
3. 检索：`RAPLSAICH_Search_Engine`（`set_bot_filters()` → `search($q, 5)` → `build_context()`）；Rapls 未启用/无索引 → 503 `kb_unavailable`。
4. 生成：`wp_remote_post` 调 `https://api.deepseek.com/chat/completions`（model `deepseek-flash`，非流式；密钥经环境/option，不入仓库），HTTP 超时 **45s**（检索 <0.5s + 生成 10-30s 余量）；上游失败/超时 → 502 `upstream_error`，响应里带兜底 `msg`（引导用 WhatsApp/询盘表单），**不向访客裸抛错误**。
5. 总预算 ≤50s；LHC 侧 Rest API 的 "Maximum execution time" 设 60s；bot 在调用前发 Send typing（官方非流式等待建议）。

### 4.3 LHC 侧接线（纯配置）

- 新建 Rest API：Host=WP 实例基址，Sub URL=`/wp-json/md/v1/kb/answer`，Method=POST，Params 加 `Authorization: Bearer <token>`，Body（RAW JSON）：`{"q": {{msg}}, "chat_id": {{chat_id}}, "history": []}`（历史注入 MVP 用 LHC 触发器默认即可，不传也行）。
- 输出解析：Location1=`msg`（→ `{content_1}`），Location2=`action`（→ `{content_2}`）。
- 输出组合①：条件 `{"action":"transfer_operator"}` → 执行「转人工触发器」（含 §2.3 transferToOperatorAction；访客看到 payload_message 自定义文案）。
- 输出组合②（Default）：文本消息响应 `{content_1}`。
- 失败兜底：另配 HTTP 状态码非 200 的组合 → 文本「转人工或稍后再试」（可用 `{http_code}` 记审计）；也可用端点 502 自带的兜底 msg。
- 人工接管触发路径汇总：访客短语 → WP 端点提示词判定 → `action=transfer_operator` → LHC 输出组合① → chat 置 PENDING → 坐席收件箱接管（此后 bot 静默）；坐席后台可 transferToBot 交回 AI。

## 5. MVP 隔离验收用例与排除项

验收全部在本地隔离实例（§6）跑，真 Chrome（AGENTS §7），不触生产、不触 de-linker VPS。

### 5.1 用例

| # | 用例 | 通过判据 |
| --- | --- | --- |
| A1 | FAQ 增：后台加一条 qa 知识→生成嵌入→挂件问 | 答案含该条内容，sources 含其 id |
| A2 | FAQ 改/删：改写后重嵌入；删除该条 | 改后答案随新文；删后不再引用该内容 |
| B1 | URL（站内）：爬虫含 /pattern/ 公开页（或手动 crawl），问某图案规格 | 答案来自该 PDP 内容；**全程无手工录入第二份商品数据**（爬虫自 Woo 发布态派生） |
| B2 | Woo 派生失效：本地实例将某品转草稿→重爬 | 该品内容不再被引用（save_post/爬虫自动失效） |
| C1 | 文件上传：TXT/MD/CSV/PDF(文本型)/DOCX 各一，≤5MB | 嵌入状态就绪；挂件问答命中文件内容 |
| C2 | 文件例外：>5MB、扫描 PDF、DOCX（无 ZipArchive 环境可不测） | 报错信息正确，不损坏现有索引 |
| D1 | 能答不编造：三类知识各问 2 题 + 1 题知识外 | 知识内引用正确；知识外明说没有并转人工/引导询盘 |
| E1 | 转人工-短语：访客说 "human"/"agent" | 端点返回 transfer_operator；LHC 会话进 Pending；坐席后台收到并接管；接管后 bot 不再抢答 |
| E2 | 转人工-模型判断：连续知识外问题 | 同 E1 |
| F1 | 坐席回复：接管后坐席发消息 | 访客挂件 AJAX 收到；消息落 `lh_msg` |
| F2 | 交回 AI：坐席 transferToBot 后再问 | bot 恢复应答 |
| G1 | 离线留言：坐席全离线 + `leaveamessage:true` | 挂件出留言表单；留言进收件箱 |
| H1 | 数据落点核对：查两张库 | 知识在 `wp_raplsaich_*`（WP 库），对话在 `lh_chat`/`lh_msg`（LHC 库），无第三方 SaaS 存储（DeepSeek/Gemini 仅 API 出站） |

### 5.2 排除项（MVP 不做）

移动 SDK/WebView 入口；联系页托管复刻；多渠道接入（WhatsApp/Telegram/Facebook——Telegram 通知另行按 #103 通道）；流式输出（需 NodeJS+php-resque）；任意外部 URL 抓取入口；HTML/DOC/XLSX 文件格式；完整 Woo 对话侧集成（产品卡/转化追踪/购物车感知）；**Woo 商品手工录第二份**（只允许爬虫自动派生）；多语言（首期单英）；Knocket 的 10/10/10 条数与 10MB 上限；主动招呼；生产部署与 DNS/子域上线（后续票）；Resume AI 独立按钮（用 LHC 原生交回）。

## 6. 本地工具链启动隔离 WP + LHC 的最短路径（只读，本票未执行）

工具链事实（【实测】读 justfile、tools/local 源码与 docs/specs/14-local-stack.md，分支 research/spec-local-stack）：

- `just up <name>`：实例不存在即建（WP+插件面+许可注入），存在则刷新后重启；实例数据根 `~/metal-divider-local/<name>/`（wordpress/、php.ini、router.php、mail/、logs/、run/），登记表 `instances.toml`（端口、库、链接检出）。【实测】justfile:75-101
- 服务：Homebrew `php@8.3` 8.3.35_1、`mysql` 26.10.0（socket 免密 root 管理，专用用户 `md_local`，库 `md_<name>`）、`wp-cli` 2.12.0；WP 走 PHP 内置 server + router.php。【实测】spec-14 §1.3/§3；tools/local/src/md_local/mysql.py
- 管理命令：`just wp <name> <args>`、`just login <name>`、`just status`（列实例端口/库/版本）、`just down`、`just reset`。

最短路径（后续实施票照此执行）：

1. **WP 侧**：在本工作树（或后续实施分支）`just up lhc114`——得到带 md-inquiry/md-site 插件面的隔离 WP 实例（端口 P1、库 `md_lhc114`）；`just wp lhc114 plugin install --activate rapls-ai-chatbot.zip`（wp.org 免费包）；配 BYOK（嵌入 Gemini 免费档、生成走 WP 端点内 DeepSeek）；放入 `mu-plugins/md-kb-endpoint.php` 与 token。
2. **LHC 侧**：下载 `livehelperchat-4.97v-with-dependencies.tgz` 解压到 `~/metal-divider-local/lhc114-lhc/lhc_web/`（同数据根，受本地存储规程约束，任务结束回收）；`mysql -u root -e "CREATE DATABASE md_lhc114_lhc"`；`cp cli/install-cli.php . && cp cli/example.settings.ini cli/settings.ini`（填库连接）→ `php install-cli.php cli/settings.ini`（php@8.3 路径 `/opt/homebrew/opt/php@8.3/bin/php`，按 justfile 的 PHP 解析或 `PHP=` 覆盖）；chmod §2.1 目录清单。
3. **起服务**：`php -S 127.0.0.1:<P2> -t ~/metal-divider-local/lhc114-lhc/lhc_web`（内置 server 原生支持 `/index.php/...` PATH_INFO 路由，静态文件直出；后台运行）。cron 用后台循环：`while :; do php cron.php -s site_admin -c cron/workflow; php cron.php -s site_admin -c cron/notifications; sleep 60; done &`（记 PID，回收时杀）。
4. **接线**：LHC 后台导入官方非流式 bot/Rest API 配置→按 §4.3 改指 WP 端点；WP 页面（或本地 hosts 的测试页）贴 LHC embed 代码；跨端口嵌入需在 Chat configuration → Misc → Domains where script can be embedded 核对放行（127.0.0.1 本地按提示配置）。
5. **验收**：跑 §5.1 用例矩阵；真 Chrome + Playwright `channel:'chrome'`。
6. **回收**：`just down lhc114`、杀 cron 循环、`mysql -u root -e "DROP DATABASE md_lhc114; DROP DATABASE md_lhc114_lhc"`、删 `~/metal-divider-local/lhc114*`、`just reset lhc114`，按 docs/agents/local-storage.md 删除后读回核验。

## 7. 证据局限

1. LHC 与 Rapls 均未实际安装运行（票面只读约束）；AJAX 同步的轮询间隔、非流式端到端延迟、`php -S` 下 LHC 的兼容性（PATH_INFO/静态资源/session）需隔离实例实跑确认——§6 路径即为此准备。
2. LHC bot 配置导入件（ChatGPT 非流式 V1/V2 yaml）未逐行核对字段，只核了文档机制与源码动作；DeepSeek 经 LHC 直连（方案 B）未实测，本票推荐方案 A 不依赖它。
3. Rapls 结论限 v1.21.4 静态源码阅读；#108 关于爬虫 UI Pro 门控的记载与现行源码冲突处已按源码修正，但作者后续版本可能再变。
4. WP 端点规格（§4）为设计产物，尚未有实现与实测；转人工的「模型自认答不出」判定依赖提示词遵从，验收用例 D1/E2 就是针对它的判据。
5. Knocket 部分全部承 #87（2026-10-26 官方教程/截图/脚本实测），本次未重测其控制台是否变化。
6. 共享主机 PHP 扩展（bcmath/gd/zip/xml/mysqlnd/mbstring）逐项是否启用仍未核对主机 phpinfo（承 #110 局限）；本地 php@8.3 环境与 Hostinger 8.3.36 需在实施票各核一次。

## 8. 来源

一手（2026-10-26）：

- LHC 安装：https://doc.livehelperchat.com/docs/install
- LHC bot Rest API：https://doc.livehelperchat.com/docs/bot/rest-api
- LHC ChatGPT 集成（非流式/transfer_operator）：https://doc.livehelperchat.com/docs/bot/chatgpt-responses
- LHC cron/命令/性能：https://doc.livehelperchat.com/docs/performance 、/docs/system/system-commands 、/docs/chat/web-push-notifications
- LHC 源码 commit afa3b17647fbfc0325b5d87fc5e78ac449441c3d（2026-10-26T09:02:49Z）：`lhc_web/lib/core/lhgenericbot/actionsChat/lhgenericbotupdateactions.php`（transferToOperator/transferToBot）；release 4.97v（2026-10-23）——GitHub API
- Rapls 免费包 v1.21.4 源码：https://downloads.wordpress.org/plugin/rapls-ai-chatbot.zip （file:line 见 §3.1 表）
- 本仓库工具链：justfile、tools/local/src/md_local/*、docs/specs/14-local-stack.md（分支 research/spec-local-stack）

承前（未重测，出处见各前置文档）：#87 Knocket 全量（research/knocket-capabilities）；#103 容量/性能/DeepSeek 无 embeddings/Gemini 免费档/主机实测（research/wordpress-native-zero-cost）；#108 Rapls 会话与接管分界（research/rapls-handoff-integration）；#110 LHC 选型与机制总览（research/shared-host-live-chat）。
