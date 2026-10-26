# Chatwoot：社区版客服底座、Captain 边界与现成连接

- 票：[研究：Chatwoot 作为自托管客服底座与 AI 集成能力](https://github.com/AssassinFlash/metal-divider/issues/91)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/chatwoot-open-source`（从 `origin/main` 的 `9da89ab` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-91-chatwoot`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树，并删除只读源码副本 `/tmp/chatwoot-v4.19.1`；不删除远程分支，不删除本文件

本文件只回答 Chatwoot 社区版能否承担 Knocket 式网站聊天、统一收件箱、Email/Telegram 和人工接管，Captain / Agent Bot 在自托管开源版实际走到哪一步，以及 WordPress 插件和 Dify 连接是否能直接用。不评估 Dify、RAGFlow 或其他知识引擎本身，不决定 AI 与 Metal Divider 询盘的关系。

未安装 Chatwoot，未购买许可证，未调用 `hub.2.chatwoot.com`，未在 WordPress 或 Dify 里实走一条会话。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 源码 | 2026-10-26 从标签 `v4.19.1` 检出的官方仓库文件。检出提交 `caab07a8f00032d5205b5a5d7e2eb0ba2f3d2d82` |
| 官方文档 | 官方 docs 仓库、帮助中心或价目页上的句子。未用账号点过控制台 |
| 官方宣称与源码不一致 | 价目页或帮助文和现行源码对不上，两边都记下 |
| 无法确认 | 外部材料没写，或需要登录、付费、实跑才能证实 |

星数、README 口号和营销插图不作为生产能力结论。

## 结论速览

1. **社区版可以直接当收件箱和渠道底座，不能当 Knocket 那种知识助手。** MIT 代码已经有网站挂件、统一收件箱、Email、Telegram、WhatsApp / Instagram / Facebook / TikTok 渠道、联系人、帮助中心文章站、分配、自动化，以及 Agent Bot webhook。访客侧检索作答不在社区版里。
2. **人工接管是现成机制，不需要自写状态机。** 收件箱挂上 Agent Bot 后，新会话状态是 `pending`，并记在 `ai_assignee`。机器人用 API 把状态改成 `open` 即交给人；人再改回 `pending` 即交回机器人。这是官方帮助文和 `conversation.rb` 一致的行为。
3. **Captain 是付费边界，不是社区功能开关。** `captain_integration` 在 `premium_features.yml` 里。社区计划的每日任务会把它关掉。官方自托管价目页写明社区版不含 Captain。Premium 是 $19/座席/月，Enterprise 是 $99/座席/月。`enterprise/` 目录的生产使用需要有效订阅和席位，开发测试除外。
4. **WordPress 官方插件只嵌挂件，而且不在 wordpress.org。** `chatwoot/wp-plugin` 0.2.2（2025-10-29）加载 `{安装地址}/packs/js/sdk.js`。这条路径在 v4.19.1 仍然是官方嵌入脚本。插件不存消息，不传 WooCommerce 上下文，不签 HMAC。
5. **没有可直接用于生产的 Dify 连接器。** `langgenius` 组织内没有 Chatwoot 代码。公开连接器 `alexbalandi/chatwoot-dify` 自认 beta、不建议生产，最后推送是 2025-07-06；README 里的克隆地址已经 404。能复用的是 Chatwoot 官方 Agent Bot webhook，不是这份连接器。
6. **托管硬约束是进程模型，不是许可证。** 官方要求 Linux、Ruby 3.4.4、PostgreSQL（生产 compose 用带 pgvector 的 PG 16）、Redis、Sidekiq，建议至少 4GB 内存。这不能装进现在的 Hostinger 共享 PHP 站点。社区软件授权是 $0；主机是另一笔费用。若再买 Captain，官方价是 $19/座席/月起，会吃掉本项目 $20/月订阅上限的几乎全部，还没算主机。

## 1. 版本与维护

| 项 | 值 | 等级 |
| --- | --- | --- |
| 仓库 | [chatwoot/chatwoot](https://github.com/chatwoot/chatwoot) | 源码 |
| 默认分支 | `develop`。2026-10-26 09:41 UTC 仍有推送 | 源码 |
| 当前正式版 | `v4.19.1`，发布于 2026-10-24 04:48 UTC | 源码 |
| 标签指向 | 附注标签对象 `c5c325a0bbc949339dd688d31e3f73cf0b272906`，提交 `caab07a8f00032d5205b5a5d7e2eb0ba2f3d2d82` | 源码 |
| 版本文件 | `VERSION_CW` 内容为 `4.19.1` | 源码 |
| 近期发布 | 4.16.0（2026-07-18）到 4.19.1（2026-10-24），间隔大约一个月 | 源码 |
| 归档 | 否 | 源码 |
| GitHub 许可证字段 | `NOASSERTION`。这是双许可证仓库的 API 结果，不是“没有许可证” | 源码 |

v4.19.1 说明写明 Node.js 只用于编译前端，生产进程是 Ruby；该版仍升级了 Node，并修了 Twilio webhook 校验。说明里标了 `[Enterprise]` 的项包括 Twilio 营业时间、WhatsApp 活动恢复、SAML 证书刷新、Captain 模型更新。社区运行时不依赖 Node。见 [v4.19.1 发布说明](https://github.com/chatwoot/chatwoot/releases/tag/v4.19.1)。

维护是活跃的。开放 issue 约 1577，不能据此说质量差，也不能据此说生产可用。结论只来自下面核对过的代码和文档。

## 2. 许可证边界

根 [LICENSE](https://github.com/chatwoot/chatwoot/blob/v4.19.1/LICENSE)（Copyright 2017-2026 Chatwoot Inc.）把仓库拆成两块：

- `enterprise/` 目录适用 `enterprise/LICENSE`。
- 该目录以外、且不是第三方组件的内容，适用 MIT Expat。

[enterprise/LICENSE](https://github.com/chatwoot/chatwoot/blob/v4.19.1/enterprise/LICENSE) 写明：生产使用必须同意 [订阅条款](https://www.chatwoot.com/terms-of-service/) 或另行约定，并持有正确席位数的 Enterprise 许可证。可以改代码、发补丁，但修改件的权利仍归 Chatwoot，且生产使用仍要有效订阅。开发与测试可以不买订阅。禁止复制、合并、发布、分发、再许可和销售这份企业软件。

`v4.19.1` 标签含 `enterprise/`。该版 [docker/Dockerfile](https://github.com/chatwoot/chatwoot/blob/v4.19.1/docker/Dockerfile) 执行 `COPY . /app`，因此按此文件构建的镜像会带上企业目录。未拉取 Docker Hub 上的 `chatwoot/chatwoot:latest` 核对层内容。`ChatwootApp.enterprise?` 在目录存在且未设 `DISABLE_ENTERPRISE` 时为真（[lib/chatwoot_app.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/lib/chatwoot_app.rb)）。任何已设置的环境变量值在 Ruby 里都为真，包括字符串 `"false"`。这不表示社区自托管违法。官方自托管页把同一套软件的社区计划标为 $0，并写明可以以后升级、数据保留。违法边界是：**在没有订阅时，把 `enterprise/` 的行为用于生产**。关掉目录或设 `DISABLE_ENTERPRISE` 是社区路径；设了该环境变量后，`enterprise?` 直接返回，企业模块不加载。未实跑这个开关。

计划名不是本地自由填写后就永久有效。生产环境每天的 `CheckNewVersionsJob` 向 `https://hub.2.chatwoot.com/ping` 同步，把返回的 `plan` 写入锁定的 `INSTALLATION_PRICING_PLAN`，然后跑 `Internal::ReconcilePlanConfigService`。计划为 `community` 时，该服务把 [premium_features.yml](https://github.com/chatwoot/chatwoot/blob/v4.19.1/enterprise/config/premium_features.yml) 里的功能从所有账号关掉，并把品牌配置重置为 Chatwoot 默认值。未调用 hub，所以没有看到一次真实同步响应。

## 3. 社区版已经有的 Knocket 能力

Knocket 的使用方式来自已关闭的 [#87](https://github.com/AssassinFlash/metal-divider/issues/87)，这里只做对照，不重做 Knocket 调查。

| Knocket 用法 | Chatwoot 社区版 | 等级 | 依据 |
| --- | --- | --- | --- |
| 网站右下角挂件 | 有。官方脚本是 `{FRONTEND_URL}/packs/js/sdk.js`，`chatwootSDK.run({ websiteToken, baseUrl })` | 源码 | [channel/web_widget.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/channel/web_widget.rb) |
| 开聊前收集姓名、邮箱 | 有预聊天表单，默认关。字段在 `pre_chat_form_options` | 源码 | 同上 |
| 身份校验 | 可选 `hmac_mandatory` 和 `hmac_token`。官方插件没有用它 | 源码 | 同上 |
| 允许的网站域名 | `allowed_domains` | 源码 | 同上 |
| 统一收件箱 | 有。会话、消息、联系人、标签、指派都在自己的库 | 源码 | `conversations` / `messages` / `contacts` 表 |
| Email 进同一收件箱 | 有。创建时生成 `forward_to_email`，也可开 IMAP | 源码 + 官方文档 | [channel/email.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/channel/email.rb)；[邮件文档目录](https://github.com/chatwoot/docs/tree/main/self-hosted/configuration/features/email-channel) |
| Telegram 进收件箱 | 有。Bot token 建渠道，webhook 打到自己的 `/webhooks/telegram/{token}` | 源码 | [channel/telegram.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/channel/telegram.rb) |
| 人工接管 | 有，见第 4 节 | 源码 + 官方文档 | 下文 |
| 交回机器人 | 官方帮助文写：人把状态改回 `pending` | 官方文档 | [Agent bots](https://chatwoot.help/hc/user-guide/articles/1677497472-how-to-use-agent-bots) |
| 坐席分配、团队 | 功能旗标默认开。高级容量分配是付费旗标 | 源码 | [config/features.yml](https://github.com/chatwoot/chatwoot/blob/v4.19.1/config/features.yml) |
| 营业时间 | 收件箱有 `working_hours_enabled`，默认关。未逐项核对班表 UI | 源码 | `inboxes` 表注释 |
| 浏览器通知 | 坐席仪表盘有通知。官方移动 App 的推送默认经 Chatwoot hub 中转 | 源码 | `.env.example` 的 `ENABLE_PUSH_RELAY_SERVER=true`；[chatwoot_hub.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/lib/chatwoot_hub.rb) |
| 公开 API | 默认开。Agent Bot 用 access token 发消息、改状态 | 源码 + 官方文档 | 帮助文指向 [创建消息](https://developers.chatwoot.com/api-reference/messages/create-new-message) |
| 联系人 | `crm` 默认开。联系人控制器有 `export` | 源码 | `contacts_controller.rb`。未核对导出字段是否含全部会话正文 |
| 帮助中心 | 默认开。公开文章站，可填 `custom_domain`。这是给人读的文章，不是检索库 | 源码 | [features.yml](https://github.com/chatwoot/chatwoot/blob/v4.19.1/config/features.yml)；`portals` 表 |

社区版渠道模型在 `app/models/channel/`：网站、Email、Telegram、WhatsApp、Facebook、Instagram、TikTok、Line、SMS、Twilio SMS、API。Knocket 把 WhatsApp / Instagram / Messenger 当成外链；Chatwoot 把它们当成收件箱渠道。这是多出来的能力，不是少了的能力。接通要各平台的应用凭据，本次未实接。语音通话模型依赖 `channel_voice`，该旗标 `premium: true`，控制器在 `enterprise/`。

官方嵌入脚本和插件用的路径一致，都是 `/packs/js/sdk.js`。`package.json` 也把 SDK 产物写到 `public/packs/js/sdk.js`。所以 0.2.2 插件的加载地址没有被 v4.19.1 改掉。未在浏览器里加载过这个文件。

社区版挂件底部有品牌链接。英文文案是 `Powered by Chatwoot`（[en.json](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/javascript/widget/i18n/locale/en.json)）。`disableBranding` 为假时渲染该链接（[Branding.vue](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/javascript/shared/components/Branding.vue)）。去掉它的旗标 `disable_branding` 在每日重置名单里。社区计划还会把 `WIDGET_BRAND_URL` 等品牌配置写回 `chatwoot.com`（[premium_installation_config.yml](https://github.com/chatwoot/chatwoot/blob/v4.19.1/enterprise/config/premium_installation_config.yml)）。自托管社区版不能稳定白标。

没有 `knocket.ai/<slug>` 那种托管联系页。替代是自己的页面嵌挂件，或帮助中心文章站。帮助中心不是聊天页。

移动端比 Knocket 的 WebView 说明更完整，但不是这一票能直接交付的表面：

- 坐席 App：[chatwoot-mobile-app](https://github.com/chatwoot/chatwoot-mobile-app)，2026-10-26 仍更新。README 写支持 Chatwoot 3.13.0+，并链到 App Store / Play。未对照 4.19.1 实测。
- 访客 SDK：`ios-sdk` 2026-10-23 更新；`android-sdk` 2026-02-09 更新；另有 Flutter 与 React Native 仓库。未编译。
- 网站挂件自身有 `allow_mobile_webview` 旗标。未核对它和现行 SDK 的桥接。

坐席 App 的推送默认走 `hub.2.chatwoot.com/send_push`。这是数据离开自有机器的一条通道。挂件会话本身打到自己的 `FRONTEND_URL`，不经这个 hub。

## 4. Agent Bot：社区版的 AI 接缝

Agent Bot 功能旗标默认开启，不在付费重置名单里。模型只有一种类型：`webhook`（[agent_bot.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/agent_bot.rb)）。字段是名称、`outgoing_url`、自动生成的 `secret`、头像和 access token。

行为（源码）：

1. 收件箱的 bot 处于 active，且会话还没有人类坐席时，新建会话设为 `pending`，`ai_assignee` 设为该 bot（[conversation.rb `set_active_bot_conversation`](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/conversation.rb)）。注释写明这是硬编码假设，不是每收件箱的配置项。
2. `AgentBotListener` 把 `message_created`、`message_updated`、会话开关和状态变化、`webwidget_triggered` 发到 `outgoing_url`。空 URL 不发。
3. 机器人以 Agent Bot 身份调用会话状态 API，且当前是 `pending`、请求把状态改为 `open` 时，走 `bot_handoff!`：清空 `ai_assignee`，状态改为 `open`，发出 `conversation_bot_handoff`。
4. 人把状态改回 `pending`，官方帮助文称为交回机器人队列。源码侧未再追一条独立的 “resume” 方法；状态枚举就是 `open / resolved / pending / snoozed`。
5. 已解决的会话若收件箱仍有 active bot，访客再发消息会回到 `pending`，而不是直接 `open`。

官方帮助文与上述一致，并给出两个示例仓库：Dialogflow 酒店预订、Rasa。见 [How to use Agent bots](https://chatwoot.help/hc/user-guide/articles/1677497472-how-to-use-agent-bots)（`chwt.app/hc/agent-bots` 于 2026-10-26 302 到该文）。

这覆盖 Knocket 的“先机器人、再人、再交回机器人”。不覆盖 Knocket 内置的 47 种语言接管短语和模型自判 `transfer_to_human`。那些要写在 webhook 另一端，或买 Captain。Captain 的接管工具在 `enterprise/lib/captain/tools/handoff_tool.rb`，原因类别是客户要求、缺知识、不支持、政策、工具失败。它不在社区版。

社区版另有 Dialogflow 集成（`lib/integrations/dialogflow`）。那是 Google 托管机器人，不是自控知识库。

## 5. Captain：有源码，生产要付费

Captain 助手、文档、FAQ 向量、场景、自定义工具和 Copilot 工作流的实现都在 `enterprise/`。数据表也在同一仓库的 `db/schema.rb`，自托管库会有这些表，不表示社区计划能用它们。

| 能力 | 社区计划 | 依据 |
| --- | --- | --- |
| 访客助手、收件箱绑定、转人工工具 | 每日任务关闭 `captain_integration` | `premium_features.yml`；价目页写社区版不含 Captain |
| 文档自动同步 | 关闭 `captain_document_auto_sync` | 同上 |
| Copilot 工作流 | 关闭 `copilot_workflows` | 同上 |
| 编辑器改写、摘要、回复建议、跟进 | 旗标 `captain_tasks` 默认开，不在每日关闭名单。服务在 `lib/captain/`，MIT。调用前检查该旗标和 API key | [base_task_service.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/lib/captain/base_task_service.rb) |
| 自定义 HTTP 工具 | 旗标 `custom_tools` 标了 premium，控制器在 `enterprise/`。不在每日关闭名单，但仍是企业目录代码 | `features.yml`；`enterprise/app/controllers/api/v1/accounts/captain/custom_tools_controller.rb` |

价目页把“回复建议、会话摘要和 AI 助手”都说成 Captain，并写它们随 Premium / Enterprise 提供（[自托管价目](https://www.chatwoot.com/pricing/self-hosted-plans)，2026-10-26 抓取）。源码却把编辑器任务留在 MIT，且默认旗标为开。这是官方宣称与源码不一致。访客自动回复不能靠这个缝使用。编辑器辅助是否在社区 UI 里可见，本次没有打开仪表盘。即便 API 放行，也不应把它当成 Knocket 助手的替代。

知识若走 Captain，物理位置是自有 PostgreSQL，不是外部向量库：

- `captain_documents.external_link` 必填，除非是文件。PDF 用 ActiveStorage，上限 10MB（`pdf_file.blob.byte_size > 10.megabytes` 则拒绝）。
- 简单抓取把正文截到 15,000 字符（`simple_page_crawl_parser_job.rb`）。
- 配了 `CAPTAIN_FIRECRAWL_API_KEY` 时，URL 可走 Firecrawl，抓取上限是可用额度与 500 的较小值。没配则用自带单页抓取。
- FAQ 与文档生成的问答存在 `captain_assistant_responses`，`embedding` 为 1536 维，`ivfflat` 索引。扩展是 `vector`，gem 是 `pgvector` 与 `neighbor`。
- 默认聊天模型 `gpt-6-luna`，默认嵌入 `text-embedding-3-small`（[installation_config.yml](https://github.com/chatwoot/chatwoot/blob/v4.19.1/config/installation_config.yml)、[llm.yml](https://github.com/chatwoot/chatwoot/blob/v4.19.1/config/llm.yml)）。
- `CAPTAIN_OPEN_AI_ENDPOINT` 可改，代码会补上 `/v1`。可选模型列表仍是仓库里的 OpenAI 名称。Claude 与 Gemini 条目标了 `coming_soon: true`。`LlmConstants` 有 `deepseek-` 前缀，但 `llm.yml` 没有 DeepSeek 模型，也没有“任意兼容名”这一档。
- 云计划的文档/回复额度在 `CAPTAIN_CLOUD_PLAN_LIMITS`。自托管若没这份配置，额度代码返回极大值（`ChatwootApp.max_limit` 为 100,000）。这不能理解成社区版可以无限用 Captain。社区计划先被旗标关掉。

帮助中心文章的向量检索旗标 `help_center_embedding_search` 是 premium，且 `chatwoot_internal: true`。自托管界面不把它当普通开关。

Captain 分类页（[chatwoot.com/hc/.../captain](https://www.chatwoot.com/hc/user-guide/en/categories/captain)）还按云套餐写 Hacker / Startups / Business。那是云价目，不是自托管价目。自托管以价目页的三档为准。

## 6. 企业版与价目

2026-10-26 抓取的 [自托管价目页](https://www.chatwoot.com/pricing/self-hosted-plans)：

| 计划 | 价格 | 页上写明包含 | 页上写明不含 |
| --- | --- | --- | --- |
| Community Edition | $0/座席/月 | 自有基础设施 | Captain、语音、自定义品牌、坐席容量、角色权限、SSO/SAML、SLA、优先支持 |
| Premium Support | $19/座席/月 | 上列中的 Captain、语音、自定义品牌、坐席容量、角色权限、优先邮件支持 | SSO/SAML、SLA |
| Enterprise Edition | $99/座席/月 | Premium 全部，再加 SSO/SAML 与 SLA | 页上未写退款；FAQ 写 Enterprise 不退款 |

FAQ 还写：社区支持是 Discord 和 GitHub；Enterprise 有电话和开发办公时间。升级不重装。生产起点写成至少 4GB 内存和 2 个 CPU。这与需求页的“4 核建议最小值”不一致，见第 7 节。

[企业版说明](https://github.com/chatwoot/docs/blob/main/self-hosted/enterprise-edition.mdx) 写计划从 $19/座席/月起，购买入口在 Super Admin 的 Manage Plan，许可证绑 `INSTALLATION_IDENTIFIER`。数据库备份迁服务器可保留该标识。没有实例级一口价；大席位要另询 `sales@chatwoot.com`。

源码里标了 `premium: true`、但每日任务**不会**强制关掉的旗标还有：`custom_tools`、`channel_voice`、`saml`、`advanced_search`、`advanced_assignment`、`auto_translate`、`company_enrichment`、`audit_log_ip_address`，以及若干 internal / deprecated 项。语音和 SAML 的控制器在 `enterprise/`，并检查对应旗标。不在重置名单里，不等于社区计划可以合法使用。价目页已经把语音、角色、SSO、SLA、白标划出价外。

`captain_tasks` 是反例：它默认开，也不在重置名单。见第 5 节，不要把它推广成访客助手。

## 7. 部署依赖与数据落点

官方 [系统需求](https://github.com/chatwoot/docs/blob/main/self-hosted/deployment/requirements.mdx)：

- 点名支持的发行版只有 Ubuntu 20.04。其他 Unix 写成“可能，但不官方支持”。Windows 要求虚拟机。这条发行版名单看起来旧，本次没有另找 2026 年的安装脚本证明它已更新。
- Ruby 以发布包的 `.ruby-version` 为准。v4.19.1 是 3.4.4，且必须是 MRI。
- Node.js 24.x、pnpm 10.x，用于 Vite 编译。`.nvmrc` 为 `24.22.1`。生产进程不跑 Node。
- 数据库只支持 PostgreSQL。需求页建议用当时测试过的稳定版。生产 compose 固定 `pgvector/pgvector:pg16`（[docker-compose.production.yaml](https://github.com/chatwoot/chatwoot/blob/v4.19.1/docker-compose.production.yaml)）。
- Redis 建议 7.0+。compose 用 `redis:alpine`，没有钉 7。
- Sidekiq 与 Rails 可同机，文档建议分开。活跃时 Sidekiq 可能超过 1GB。
- 内存：4GB 为写明的最低值，对应每天最多 10,000 个会话；8GB 对应 20,000。升级时建议再加 1GB swap。
- CPU：4 核是建议最小值，对应每天 10,000 个会话。价目 FAQ 写 2 CPU。两处都是官方文字，数字不一致。未做负载测试，不能选一个当成实测。

进程是 `rails server` 加 `sidekiq`（`Procfile`）。这不是 PHP。Hostinger 共享主机上的 WordPress 站点不能顺带跑它。要另有一台 Linux 虚拟机或一套 Docker 主机。本次未报价，也未申请主机。

数据物理位置（自托管、不把 Chatwoot Cloud 当目的地）：

| 数据 | 位置 | 会不会离开机器 |
| --- | --- | --- |
| 会话、消息正文、联系人、标签、坐席 | PostgreSQL。`messages.content` 是 text | 默认不离开。备份由自己做 |
| 附件 | ActiveStorage。默认 `ACTIVE_STORAGE_SERVICE=local`，compose 卷是 `/app/storage`。可换 S3 兼容存储 | 只有自己改成外部桶才离开 |
| 队列、缓存、品牌重置警告 | Redis | 默认本地 |
| Captain 原文与 1536 维向量 | 同一 PostgreSQL 的 `captain_*` 表 | 生成向量时，提示和文本会发到配置的模型端点 |
| 安装遥测 | `ChatwootHub.sync_with_hub` | 生产每天 POST 到 `hub.2.chatwoot.com/ping`。载荷含安装 ID、版本、主机名、环境和计数。`DISABLE_TELEMETRY` 时不附计数。计数实现是 `model.last&.id`，不是精确行数，也不是正文 |
| 坐席移动推送 | `send_push` | `ENABLE_PUSH_RELAY_SERVER=true` 时，FCM 载荷发到 hub |
| 邮件 | 自己的 SMTP / IMAP，或文档中的 SES、Google、Azure | 取决于所选邮路 |
| URL 抓取 | 自带 HTTP 抓取，或 Firecrawl | 配了 Firecrawl key 才把 URL 交给 Firecrawl |

官方有 [备份文档](https://github.com/chatwoot/docs/blob/main/self-hosted/deployment/backup.mdx)。本次未执行备份，也未核对它是否导出向量和附件。与 Knocket 的差别是：库在自己这边，导出是运维责任，不是“界面没有下载按钮所以拿不走”。

未核对会话保留期限。`.env.example` 里删除 90 天无会话联系人收件箱的任务默认是注释掉的。不能说对话会自动删，也不能说有法定保留期。

## 8. WordPress 插件

官方仓库 [chatwoot/wp-plugin](https://github.com/chatwoot/wp-plugin)：

| 项 | 值 |
| --- | --- |
| 最后推送 | 2025-10-29，提交 `de80bd0b0d`，合并“仅登录用户显示” |
| 版本 | 插件头与 readme 都是 0.2.2 |
| GitHub Release | 无 |
| readme 许可证 | GPLv2 or later |
| readme 兼容 | Requires at least 4.5，Tested up to 5.8，Requires PHP 5.6 |
| wordpress.org | `chatwoot`、`chatwoot-plugin`、`chatwoot-live-chat`、`chatwoot-live-chat-widget` 四个 slug 于 2026-10-26 查询均为 404 |

`js/chatwoot.js` 只做一件事：设置 `window.chatwootSettings` 的 locale、type、position、launcherTitle，然后插入 `{chatwoot_url}/packs/js/sdk.js`，onload 后 `chatwootSDK.run({ websiteToken, baseUrl })`。PHP 把这四个外观项加上网站 token、安装 URL、以及“仅登录用户”存进 `wp_options`。不建消息表，不读 WooCommerce，不传当前图案，不设置 HMAC 身份。

因此它和 Knocket 官方插件同一类：站点侧只负责嵌脚本。差别是脚本指向自己的 Chatwoot，而不是腾讯云。0.2.2 的加载路径与 v4.19.1 官方嵌入片段一致，所以不是明显的路径断裂。readme 的 “Tested up to 5.8” 不能当成 2026 年 WordPress 的兼容证明。未在本站主题里激活。

若只要挂件，这个插件或一段等价的页脚脚本就够。若要把当前图案、登录身份或 HMAC 交给会话，需要少量自有代码。那是接入，不是再写一个收件箱。插件读me 写明需要自托管或 [Chatwoot Cloud](https://app.chatwoot.com) 的网站收件箱 token。Cloud 不满足“存储自己控制”。

## 9. Dify 现成连接

Chatwoot 仓库的集成目录是 Dialogflow、Slack、Linear、OpenAI、Google Translate、Dyte、Facebook，没有 Dify。`langgenius` 组织的代码搜索没有 Chatwoot。Dify 官方仓库不是这条链路的提供方。

公开项目里，名字直接对应的是 [alexbalandi/chatwoot-dify](https://github.com/alexbalandi/chatwoot-dify)：

- MIT，3 星，创建于 2025-02-24，最后推送 2025-07-06。
- README 标题为 Chatdify，并写 “currently in beta and is not recommended for production use”。
- 机制是自写的 Python 服务：收 Chatwoot Agent Bot webhook，调 Dify API，再用 Chatwoot API 回消息；另要 PostgreSQL 和 Celery。
- README 的克隆地址是 `https://github.com/eremeye/chatdify`。2026-10-26 该仓库 API 返回 404。
- 未运行它的测试，未审查它的 webhook 验签是否跟上 v4.19.1。

其他同名仓库是 MVP、学习项目或把 Chatwoot/Dify 写进更大平台的说明，星数为 0 或 1，没有一份是 Chatwoot 或 Dify 官方维护的连接器。不把它们当现成生产件。

可以少量整合的是官方接缝，不是这些仓库：自托管 Dify 应用暴露 API，一个很小的 webhook 适配器收 Agent Bot 事件、回消息、在需要时把状态改成 `open`。适配器本身官方没有提供。Dify 是否适合做知识引擎，由 Dify 那张研究票回答，本文件不评。

## 10. 能否少写代码

| 目标 | 判断 |
| --- | --- |
| 网站聊天、统一收件箱、Email、Telegram、坐席回复、转人工、交回机器人 | 社区版已有。WordPress 侧是嵌脚本。需要一台独立主机，不是共享 PHP |
| 把 WhatsApp / Instagram 收进同一收件箱 | 社区版有渠道，比 Knocket 外链更完整。要平台凭据和公网 HTTPS，不是再写一套收件箱 |
| 访客按自有知识库作答，模型端点自己选 | 社区版没有。Captain 要付费，且模型列表不是任意 OpenAI 兼容名。应接另一知识引擎，经 Agent Bot |
| 去掉 “Powered by Chatwoot” | 付费 `disable_branding`。社区每日任务会把品牌配置重置回去 |
| 现成 Dify 按钮 | 没有可生产使用的官方或活跃连接器 |
| 托管联系页、47 语接管短语、平台代管嵌入 | Chatwoot 不提供 Knocket 那三个表面。联系页可用自有页面加挂件代替。短语和嵌入要在 Agent Bot 另一端 |
| 与现有询盘表单的关系 | 本文件不决定 |

自有代码的合理范围是：WordPress 嵌入（可从官方插件改，补图案上下文和 HMAC），以及 Agent Bot 与所选知识引擎之间的 webhook 适配。收件箱、渠道、坐席状态和消息存储不应重写。

## 11. 硬伤

- **许可证：** 生产使用 `enterprise/` 行为，包括 Captain 访客助手，需要付费席位。社区版 $0 不包括它。Premium $19/座席/月起。这与本项目 $20/月订阅上限冲突：一席 Premium 之后几乎没有余量再付一台符合文档的主机。
- **主机：** 不能装进现有 Hostinger 共享 WordPress。官方最低内存是 4GB，另要 PostgreSQL、Redis、Sidekiq。软件免费不等于运行免费。
- **白标：** 社区计划会重置品牌并保留挂件链接。若产品要求访客界面不出现 Chatwoot，社区版不满足。
- **Dify 连接器：** 现成仓库过期且自认不能生产。不能把“GitHub 上有一个连接器”写成已有集成。
- **WordPress 目录：** 官方插件不在 wordpress.org，兼容声明停在 WordPress 5.8。能用的是源码嵌入方式，不是目录一键安装。

没有发现社区版 MIT 代码禁止自托管、禁止改 WordPress 嵌入、或禁止把 Agent Bot 接到自有服务。hub 遥测和移动推送中转可以关或不用，但默认是开的。

## 12. 无法确认

- hub 对一次真实安装返回的 `plan` 字符串，以及购买后刷新要多久。只读了写入该值的代码。
- 社区仪表盘里编辑器 AI 按钮是否显示。API 策略允许 `captain_tasks`，UI 未打开。
- 4.19.1 与现行 iOS / Android / 坐席 App 的实际兼容。坐席 App README 仍写 3.13.0+。
- 官方插件在 WordPress 6/7 与本站主题上的脚本冲突。未激活。
- `alexbalandi/chatwoot-dify` 对 v4.19.1 webhook 签名是否仍正确。未跑。
- 价目页 4 核与 2 CPU 哪个是现行销售口径。两份官方文字都在。
- Ubuntu 20.04 是否仍是他们自己在用的安装基线。需求页如此写，未对照 2026 年安装脚本的每一行。
- 会话与附件的完整导出是否一步完成。只确认消息在 Postgres、附件在 ActiveStorage、联系人有 export action。

## 来源

- 仓库与发布：<https://github.com/chatwoot/chatwoot>，<https://github.com/chatwoot/chatwoot/releases/tag/v4.19.1>
- 许可证：<https://github.com/chatwoot/chatwoot/blob/v4.19.1/LICENSE>，<https://github.com/chatwoot/chatwoot/blob/v4.19.1/enterprise/LICENSE>
- 功能与重置：<https://github.com/chatwoot/chatwoot/blob/v4.19.1/config/features.yml>，<https://github.com/chatwoot/chatwoot/blob/v4.19.1/enterprise/config/premium_features.yml>，<https://github.com/chatwoot/chatwoot/blob/v4.19.1/enterprise/app/services/internal/reconcile_plan_config_service.rb>
- Agent Bot：<https://chatwoot.help/hc/user-guide/articles/1677497472-how-to-use-agent-bots>，<https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/listeners/agent_bot_listener.rb>
- 需求与企业版说明：<https://github.com/chatwoot/docs/blob/main/self-hosted/deployment/requirements.mdx>，<https://github.com/chatwoot/docs/blob/main/self-hosted/enterprise-edition.mdx>
- 价目：<https://www.chatwoot.com/pricing/self-hosted-plans>
- WordPress 插件：<https://github.com/chatwoot/wp-plugin>
- Dify 连接器：<https://github.com/alexbalandi/chatwoot-dify>
