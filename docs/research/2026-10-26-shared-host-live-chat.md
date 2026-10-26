# 研究:可在共享 PHP 主机运行的开源实时客服与人工接管(票 #110)

日期:2026-10-26。分支 `research/shared-host-live-chat`(自 origin/main b7a0c68)。父地图:#86。

问题:除 WordPress 插件外,是否有真正可自托管在现有 Hostinger 共享 PHP 主机(PHP 8.3 + MariaDB 11.8.9,实测见 #103)、零新增支出的开源**实时客服**,可与 WordPress 知识库 / Rapls 组合出 Knocket 式 AI 客服——知识与对话存储自己控制,人工接管不是简陋留言。

## 结论(摘要)

**推荐:Live Helper Chat(LHC)core(Apache-2.0)+ 自写 1 个 WordPress 知识端点(mu-plugin REST 路由,内部调 Rapls 检索 + DeepSeek)+ LHC bot 配置导入。** FreeScout 与 UVdesk 不适用:前者实时聊天与 API 都是付费模块;后者是工单系统,无实时聊天且官方要求 RAM ≥ 4GB。

- LHC 标准安装只要 PHP 8.2+ 与 MySQL 5.7 / MariaDB 10.2.3+,Apache/Nginx;Redis、NodeJS、Elasticsearch、S3 全部是可选项。无 Node 时前端与坐席端用 AJAX 同步,NodeJS 扩展挂掉会"自动回退到标准 AJAX 查询"——即 AJAX 是默认兜底模式,不是降级 hack。
- AI 应答、工具调用(含 AI 主动转人工 `transfer_operator`)、任意第三方 REST 集成全部在免费 core 的 bot + REST API 机制内,**非流式零代码**(导入官方模板改参数);**流式才需要 NodeJS-Helper + php-resque**(共享主机无常驻进程,放弃流式)。
- 必须自写的只有一件事:WordPress 侧知识检索端点。Rapls 自身无 REST/同步端点(#103 已核),需要一个小 mu-plugin 注册 `wp-json` 路由,内部调 Rapls 检索类拼装上下文、再调 DeepSeek 官方 API 返回答案。对话与访客数据存 LHC 自己的 MariaDB 表(`lh_chat` / `lh_msg` / …),知识存 WordPress 库(Rapls 表),两库都在本站,无厂商锁定。

## 1. Live Helper Chat(优先候选,通过)

版本:release **4.97v(2026-10-23)**;本次源码核查基于 master commit **afa3b17647fbfc0325b5d87fc5e78ac449441c3d(2026-10-26)**,上游当天仍有提交。仓库:https://github.com/LiveHelperChat/livehelperchat 。许可证:core **Apache-2.0**(仓库根 `LICENSE`)。

### 1.1 共享主机适配(核心问题)

| 项 | 证据 |
| --- | --- |
| 依赖门槛 | 官方 install 文档:"PHP version 8.2 or higher";"MySQL 5.7 or MariaDB 10.2.3 or higher with json_ functions support";Apache/Nginx。PHP 扩展必需项为 php-json/cli/gd/xml/pdo/zip/mysqlnd/mbstring/bcmath,Redis 相关(php-phpiredis、php-pecl-redis4)列在 **Optional but Recommended**。https://doc.livehelperchat.com/docs/install (2026-10-26 抓取) |
| 官方对 WordPress 共存的表态 | 同页 Requirements 开头:"If you are running a WordPress site, Live Helper Chat is very likely to work seamlessly",并给出 WP pretty permalinks 下 LHC 子目录共存的 `.htaccess` 规则与 PHP session cookie 改名建议(`php_session_cookie_name`)。 |
| 无 Node 的实时机制 | NodeJS 是独立扩展(https://doc.livehelperchat.com/docs/node-js ):安装页明说 Node "Reduce server load";"If NodeJS dies or a client cannot connect… LHC automatically falls back to standard ajax queries"。即默认模式就是 AJAX 同步,Node 只是负载优化。 |
| 站内环境对号 | Hostinger 实测 PHP 8.3.36 / MariaDB 11.8.9 / memory_limit 512M / max_execution_time 300(#103)≥ 要求。bcmath/gd/xml/curl 逐项是否启用需安装时 phpinfo 核对(debian 系常见默认有)。 |

### 1.2 免费已有 / 付费才有 / 必须自写

**免费已有(core,Apache-2.0,源码核到路径/行号)**

- 网站挂件:官方 embed 代码(`mode:'widget'`,含 `leaveamessage`、`proactive` 参数),install 文档 "Generating Embed Code" 一节。
- 坐席收件箱与人工接管:back office 挂件(Pending / My active and pending chats)、自动分配、在线时间、浏览器通知+声音(install 文档 Notifications 一节);聊天转接、AI 主动转人工见 §1.3。
- 离线留言:`leaveamessage:true` 挂件参数(install 文档);离线即表单留言进收件箱,不是孤立留言箱。
- Bot(可视化 workflow 触发器):`lhc_web/modules/lhgenericbot/`(addtrigger/editrestapi/listrestapi 等,commit afa3b17)。
- 任意第三方 REST 调用(bot 的 AI 代理机制):`lhc_web/lib/core/lhgenericbot/lhgenericbotworkflow.php:767`(`'type' => 'restapi'`);官方文档 https://doc.livehelperchat.com/docs/bot/rest-api :host/子 URL/headers/body 全可配,`{{msg}}`、`{previous_visitor_messages_list_url__10__1}` 等替换变量拼历史,输出 JSON 解析(`response:location` 等)与条件分流,且"your AI can return JSON directly for execution"(AI 可直接返回待执行动作)。
- 入站 webhook → bot 触发器:`lhc_web/lib/core/lhchat/lhchatwebhookhttp.php:8-46`(按 event 取 webhook、命中条件后执行 GenericBotTrigger)。
- REST API(供外部程序读写会话、以外置坐席身份回复):`lhc_web/modules/lhrestapi/`(addmsgadmin.php、addmsguser.php、fetchchat.php、fetchchatmessages.php、chat.php、isonline.php、editwebhook.php 等);文档 https://api.livehelperchat.com 。人工接管因此有两条路:人坐 back office,或外部程序经 REST 以坐席身份发消息。
- 邮件会话(邮件渠道工单化):`lhc_web/modules/lhmailconv/`(core,建议 php-imap,列在可选)。
- MCP server(core 新增):`lhc_web/modules/lhaimcp/mcp.php:1-28`,Streamable HTTP 端点,供 ChatGPT/Claude 连接器操作 LHC;会话表 `lh_mcp_session`。
- 数据存储:聊天 `lh_chat`(`lhc_web/lib/models/lhchat/erlhcoreclassmodelchat.php:7`)、消息 `lh_msg`(`erlhcoreclassmodelmsg.php:13`),全部在本站 MariaDB;附件/文件落盘 `lhc_web/var/storage*`(install 文档 chmod 清单:var/storage、var/storageform、var/storageadmintheme 等)。
- 客户端:桌面 Electron app(免费,https://github.com/LiveHelperChat/electron );移动 app **MIT**(https://github.com/LiveHelperChat/lhc_messenger ,license=MIT,README 提供 App Store / Google Play / 免费 APK 构建下载)。
- Jitsi 语音视频(免费集成;Agora 才是 paid)——README.md:57-58。

**付费才有(明确)**

- Agora 语音/视频/屏幕共享:README.md:57 "powered by agora, paid"。
- 官方托管云服务(livehelperchat.com/hosting)与部分商店扩展。
- WhatsApp 走 Twilio(`LiveHelperChat/twilio` 扩展,README.md:82):模块代码免费但 Twilio 按条计费 → 违反零支出,不采用;open-wa 方案基于 Node 常驻(README.md:84),共享主机不可用。零支出下可用渠道 = 网站挂件 + 邮件 + **Telegram**(Bot API 免费,#103 已核 Telegram 官方条款;官方扩展 https://github.com/LiveHelperChat/telegram ,2026-10-26 仍有提交)。

**必须自写(最少胶水,§3)**

- WordPress 侧知识检索端点 1 个(唯一必须写的代码)。
- LHC bot/REST API 配置(导入官方 Chat Completions 模板后改 host、prompt、transfer_operator,属配置不属代码)。
- 挂件嵌入 CSP 核对(LHC embed 为同源可部署的 script;本站现无第三方追踪脚本,CSP 与 WindPress/LiteSpeed 环境需按 AGENTS.md CookieYes 先例核对后放行,实施时验证)。

### 1.3 AI 应答与人工接管(与 Knocket 对标的关键)

官方 ChatGPT(Chat Completions / Responses)集成就是"core bot + core REST API"的配置模板,不是付费件:

- https://doc.livehelperchat.com/docs/bot/chatgpt-responses :"Live Helper Chat is the agent harness here - it keeps the conversation, the triggers, the tools (actions) and the state - while OpenAI supplies the model"。REST API 定义(URL、Bearer、system prompt)可导入官方配置;同一机制可指到任何 OpenAI-compatible 端点。
- 转人工是官方演示的标准模式:定义 `transfer_operator` function,提示词写明"user asks for human OR you are not confident → call transfer_operator",bot 端用 Custom text matching 触发器接住并执行转接。**人工接管 = 完整坐席收件箱接管会话**,访客上下文(历史消息、部门、变量)保留,不是留言。
- 非流式模板直接可用;"Streaming version requires NodeJS and PHP-Resque extensions"(同页)→ 共享主机只做非流式,等待期间用 `Send typing` 提示(doc/bot/rest-api "Streaming/Trigger workflow" 一节)。
- 模型路由合规:DeepSeek 官方 api.deepseek.com 提供 OpenAI-compatible `chat/completions`(DeepSeek 无 embeddings 端点,#103 已核;嵌入在 WordPress/Rapls 侧走 Gemini 免费档)。LHC 只调 chat,符合 AGENTS.md §6(站内管线 deepseek-flash 经官方 API)。

## 2. FreeScout、UVdesk(比较对象,不适用)

### 2.1 FreeScout(AGPL-3.0)— 实时聊天与 API 均为付费模块

仓库 https://github.com/freescout-help-desk/freescout ,AGPL-3.0,2026-10-26 仍有提交,活跃。README:"pure PHP/MySQL application… can be easily deployed even on a shared hosting. PHP 7.4 - 8.x, MySQL/MariaDB"——共享主机适配本身没问题。

不适用于本票的原因(全部一手):

1. **实时聊天是付费模块**:Live Chat Module 页 https://freescout.net/module/chat/ ,HTML 内 `data-price-mode=single data-price="13.00"`(EDD 下载 id 14310,2026-10-26 抓取)。模块市场声明"lifetime licenses for a single FreeScout instance"、源码 AGPL 但**购买后提供**。
2. **API 与 webhook 也是付费模块**:模块列表页 https://freescout.net/modules/ "API & Webhooks Module — Enable API and webhooks in two clicks!",同一 EDD 结构 `data-price="12.99"`。零支出前提下,AI/外部集成通道不存在。
3. 即便购买,聊天模块本身也不是强实时:模块页 "Specifics":"Messages to customers may be delivered with small delay around **10 seconds**"(轮询投递);"Powered by FreeScout" 去标要再买 Widgets White-Labeling Module。
4. 形态是邮件工单/共享收件箱(Help Scout 克隆),不是实时聊天系统;离线留言语义即"邮件会话"。

定位:若未来需要纯邮件工单台,FreeScout core 免费(AGPL)是合理选择;作为本票"实时客服底座"出局。

### 2.2 UVdesk(OSL-3.0)— 工单系统,无实时聊天

仓库 https://github.com/uvdesk/community-skeleton (OSL-3.0,README "License" 节;skeleton 2026-10-07、core-framework 2025-09-24 有零星提交)。

1. README "Features" 全列表为工单/邮箱/知识库/自动化,无网站聊天挂件、无坐席实时收发——工单 ≠ 实时聊天(票面明确不许混同)。
2. README "Requirements":**RAM 4GB or higher**、PHP 8.1、MySQL 5.7.23+、Composer 2、PHP IMAP & Mailparse。共享主机 512M PHP memory_limit 与无 SSH 常驻下,Symfony 栈的安装/缓存/命令行环节都吃力(README 自己建议受限环境用预打包 zip)。
3. OSL-3.0 为强 copyleft,对本站组合无直接障碍,但相较 Apache-2.0 无优势。

出局理由:没有聊天,没有接管;零支出可装但装出来的是另一类东西。

### 2.3 其余候选

Chatwoot(#91)、Tiledesk(#92)、ChatterMate(#93)、Sitechat(#99)等已在其他票核实出局(Ruby/容器/许可证/停更),本票不重复;Knocket 本身(#87)是 SaaS 不自托管。

## 3. 推荐组合与最少胶水

```
访客 ⇄ LHC 挂件(chat 子目录/子域,PHP+MariaDB,Apache-2.0)
        │  bot 触发器(导入官方 Chat Completions 模板,非流式)
        ▼
   WP mu-plugin REST 路由 /wp-json/md/v1/kb  ←—— 唯一必须自写的代码(1 个文件)
        │  ① Rapls 检索(wp_raplsaich_* 表,Gemini 免费档嵌入,Woo 派生索引)
        │  ② 拼 prompt + 调 DeepSeek 官方 api.deepseek.com(chat/completions)
        ▼
   返回 {"msg": "…"} → LHC 直接展示;AI 调 transfer_operator → 坐席收件箱接管
   离线:leaveamessage → 收件箱 + 邮件;值班通知:Telegram Bot(免费)
```

- 知识与对话存储:知识在 WordPress 库(Rapls 自有表,#103),对话在 LHC 库(`lh_chat`/`lh_msg`),附件在各自 `var/storage*`——全部本站,无 SaaS。
- Woo 唯一资料源合规:知识索引维持 #103 结论(Rapls 从 Woo 派生);LHC 不复制商品数据,只在请求时取检索上下文。
- 胶水量:1 个 mu-plugin 文件(WP 侧端点)+ 1 份 bot 导入配置 + 挂件 embed 一段 + DNS/子目录部署。AI 工具调用、转人工、历史注入(`{previous_visitor_messages_list_url__10__1}`)、输出解析全部用 LHC 现成机制。
- 备选联动:若不想让 WP 端点调 DeepSeek,可两段式(bot 先调 WP 取上下文,再由 LHC 直接调 DeepSeek),仍是纯配置;单端点版把 prompt 权限留在 WP 侧,更好审计。

## 4. 风险与证据局限

1. 未实际安装(票面禁止装到站点/生产);AJAX 模式的轮询间隔与"实时感"无官方数字,需评审站真 Chrome 实测(AGENTS.md §7)。
2. LHC **扩展仓库多无 LICENSE 文件**(GitHub API:telegram、twilio 均 license=None;core 才有 Apache-2.0)。零支出部署若只用 core + 挂件 + 邮件,不碰这些扩展即无灰区;Telegram 渠道若要上,灰区自担或改用 core webhook + 自写 Telegram 转发(mu-plugin 直调 Bot API,#103 已核免费条款)。
3. 流式输出不可用(需 NodeJS-Helper + php-resque 常驻),非流式下 DeepSeek 首字延迟即访客等待,须用 typing 指示 + `max_execution_time`(实测 300s)覆盖。
4. FreeScout 模块价格(13.00 / 12.99 USD)为 2026-10-26 页面抓取,可能变动;不影响"付费才有"的定性。
5. Hostinger PHP 扩展清单(bcmath/gd/xml/curl/zip)未逐项核对主机 phpinfo;#103 只实测了 PHP 版本与 memory_limit。
6. `lhaimcp`(MCP)需 `composer require mcp/sdk`;with-dependencies 官方包是否已含未核——与本票结论无关(不依赖 MCP)。

## 5. 来源

- LHC install 文档(要求、embed、通知、WP 子目录共存):https://doc.livehelperchat.com/docs/install (2026-10-26)
- LHC NodeJS 扩展(AJAX 兜底):https://doc.livehelperchat.com/docs/node-js (2026-10-26)
- LHC bot REST API(任意第三方 AI、变量、输出解析、工具执行):https://doc.livehelperchat.com/docs/bot/rest-api (2026-10-26)
- LHC ChatGPT 集成(harness 定位、transfer_operator、流式前提):https://doc.livehelperchat.com/docs/bot/chatgpt-responses (2026-10-26)
- LHC REST API 参考:https://api.livehelperchat.com ;源码 `lhc_web/modules/lhrestapi/`(commit afa3b17)
- LHC 源码:https://github.com/LiveHelperChat/livehelperchat commit afa3b17647fbfc0325b5d87fc5e78ac449441c3d(2026-10-26);release 4.97v(2026-10-23,GitHub API);`LICENSE`=Apache-2.0;README.md:51-99 集成清单
- LHC 移动 app:https://github.com/LiveHelperChat/lhc_messenger (license=MIT,GitHub API,2026-10-26)
- LHC Telegram 扩展:https://github.com/LiveHelperChat/telegram (license=None,pushed 2026-10-26)
- FreeScout:https://github.com/freescout-help-desk/freescout (AGPL-3.0,pushed 2026-10-26,README Requirements);Live Chat Module 页 HTML `data-price="13.00"`:https://freescout.net/module/chat/ ;模块市场与声明:https://freescout.net/modules/
- UVdesk:https://github.com/uvdesk/community-skeleton (OSL-3.0,README Features/Requirements/License;GitHub API pushed 2026-10-07)
- Rapls、Hostinger 环境实测、DeepSeek 无 embeddings、Gemini 免费档、Telegram Bot 条款:承 #103(`docs/research/2026-10-26-wp-native-kb-capacity.md`,分支 research/wordpress-native-zero-cost)
