# Knocket 功能、知识输入限制与数据控制边界

- 票：[研究：Knocket 功能、知识输入限制与数据控制边界](https://github.com/AssassinFlash/metal-divider/issues/87)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/knocket-capabilities`（从 `origin/main` 的 `efa3ca8` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-87-knocket`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树；不删除分支，不删除本文件

本文件只回答 Knocket 现在实际提供什么、知识怎么进、数据由谁掌握，以及一个要自己控制知识输入和存储的 WordPress 同类插件必须复现或接管哪些点。不决定它和 Metal Divider 询盘、资料公开范围的关系，也不选模型或插件。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 2026-10-26 直接读到的公开页面、公开脚本、插件源码、HTTP 头或控制台截图像素 |
| 官方宣称 | 官方教程、产品页、插件 readme、why-free 信中的句子，尚未用账号实走 |
| 合理推断 | 由实测材料推出、但没有内部实现证据的判断 |
| 无法确认 | 外部材料没写，或两份一手材料互相矛盾且无法登录核实 |

未注册账号，未提交聊天，未上传知识，未调用会创建会话的接口。营销对比文（对 Intercom / Tidio / Tawk.to 的博客）不作为架构事实。

## 结论速览

1. Knocket 是挂在腾讯云 TRTC / 即时通信上的联系层，不是 WordPress 里的资料库。公开挂件脚本 `https://trtc.io/knocket-sdk/sdk.js` 于 2026-10-21 更新，3,207,329 字节，由 Tencent COS 提供；启动时向 `https://api.trtc.io/common/portal-api-lc` 要 `userSig`，之后走腾讯云 IM。
2. 使用方式是三条入口进一个收件箱：网站挂件、`knocket.ai/<slug>` 联系页、用 WebView 嵌进 App 的移动入口。Telegram 与 Email 是收件通道；WhatsApp / Instagram / Messenger 在官方挂件教程里是外链快捷方式，不是收件箱摄入。
3. 知识输入的硬上限写在官方控制台截图上，不是营销句：FAQ、URL、文件各 10 条；文件最大 10MB，格式为 PDF、TXT、DOC、DOCX、HTML、MD、CSV、XLSX。URL 界面是单页列表，没有爬取深度。截图里的操作只有编辑（FAQ）和删除，没有导出。
4. 聊天补全是自带 OpenAI 兼容密钥；嵌入凭据和模型由平台管理。系统提示词上限 2000 字，问候语 400 字，机器人头像 1MB。这些数字在 2026-09 的教程截图里可见。
5. 官方 WordPress 插件 1.0.2（2026-08-21，活跃安装 0）只做一件事：在页脚插入官方脚本。它不保存消息，不保存知识，卸载时只删 `knocket_settings`。readme 仍写 AI「即将推出」，晚于 2026-09-07 的 AI 教程。
6. 若要复现使用方式又自己掌握知识与存储，不能再包一层 `trtc.io` 脚本。必须接管的是会话与附件、知识原文与索引、密钥、检索作答、收件箱与人工接管、导出和删除。联系页托管和移动 WebView 是独立表面，不是 WordPress 插件运行时。

## 1. 产品身份

官方站是 [https://knocket.trtc.io/](https://knocket.trtc.io/)。产品页与脚本在 `trtc.io`。控制台截图顶栏写着「Tencent RTC | Knocket」（[对话页截图](https://knocket.trtc.io/assets/images/tutorials/web-widget/04-conversation-page.png)）。why-free 信写明消息基础设施用的是 Tencent RTC，不是自建后端（[https://knocket.trtc.io/why-free](https://knocket.trtc.io/why-free)）。

法律文本不是 Knocket 单独起草的数据处理协议：

- [条款](https://knocket.trtc.io/legal/terms/) 标题是 TENCENT CLOUD TERMS OF SERVICE。
- [隐私](https://knocket.trtc.io/legal/privacy/) 是「Instant Messaging / Chat」功能模块，最后更新 2026-10-15，联系邮箱 `info_rtc@tencent.com`。静态 HTML 里没有指向另一份父隐私政策的独立 URL。

首页英雄图里的收件箱侧栏画了 Contacts、Analytics、Automations。同一套官方截图里的真实侧栏是 Inbox、Web Widget、Mobile Widget、Contact Page、AI Agent、Installation、Integrations、Notifications。英雄图是插画，不是控制台信息架构。

## 2. 公开集成机制（实测）

2026-10-26 读取首页 HTML，挂件标签是：

```html
<script src="https://trtc.io/knocket-sdk/sdk.js?identifier=…" async></script>
```

查询参数只有 `identifier`，没有 `v`。同一页还加载 `/js/shence814.min.js`。

脚本响应头：`server: tencent-cos`，`last-modified: Wed, 21 Oct 2026 10:34:12 GMT`，`content-length: 3207329`，`cache-control: no-cache`，`access-control-allow-origin: *`。

脚本内可见的启动配置（实测字符串，不是反编译后的业务注释）：

| 项 | 公开脚本中的值 |
| --- | --- |
| 门户 API | `BASE_URL` / `Ih` = `https://api.trtc.io/common/portal-api-lc` |
| 启动动作 | `POST`，body 为 `{Action:"DescribeUserSig", Data:{identifier, visitorId, type}}` |
| 脚本自识别 | 扫描 `script[src]` 中含 `/knocket-sdk/sdk.js` 的标签，读取 `identifier`、`type`、`autoShow` |
| 分析 | `SENSORS_ENABLED:"1"`，SDK 为 `https://trtc.io/js/shence814.min.js`，上报 `https://sc-rp.tencentcloud.com:8106/sa?project=production` |
| 本地缓存 | `localStorage` 包装，带过期时间 |
| 水印 | CSS 类 `knocket-watermark`，图片 `https://console.trtc.io/resource/knocket/knocket-watermark.png`（HEAD 200，10,164 字节），链接带 `utm_content=watermark` |
| 人工接管文案 | `waiting_human`、`human_handling`、`handoff_offline`，以及 “Transferred to a human agent” 等内置字符串 |
| 不存在的符号 | 全文无 `knocketConfig`、`knowledge`、`embedding`、`transfer_to_human` |

合理推断：检索和模型调用在服务端。公开挂件只负责身份、IM 会话和接管后的界面文案。

教程 [Get Started](https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/) 写 `window.knocketConfig` 可在加载前设置用户 ID、会话上下文和启动器位置。2026-10-21 的公开 `sdk.js` 没有这个符号。这是官方文档与现行脚本不一致，不能当成可用 API。

捆绑的腾讯云 IM 上传类里有默认值 `region="ap-shanghai"`，以及附件上限 A/I 20MB、F/V 100MB。这是 SDK 默认值，不能当成 Knocket 账号的数据驻留地，也不能当成知识库的 10MB 上限。知识库 10MB 来自控制台截图，是另一套限制。

## 3. 访客与站长流程

以下流程来自官方教程和截图，不是本次实走。

### 访客

1. 打开带脚本的页面，或打开 `https://knocket.ai/<slug>`，或在 App 的 WebView 里打开联系页。
2. 挂件主页可出现欢迎语、品牌标志、若干 FAQ、开始聊天、会议链接（Calendly / Cal.com 等，新标签打开）、WhatsApp / Instagram / Telegram / Messenger 图标。空白渠道不渲染。
3. 开始聊天前，可能先填姓名、邮箱、电话。收集时机为关闭、每次对话前、或仅离线。离线时显示离线文案。
4. 若该渠道打开了 AI，且处于「始终」或「仅离线」的生效时段，消息先经过 AI。教程称高置信则直接回复，低置信或命中人工短语则转人工。
5. 附件（图、视频、文件）在挂件教程中宣称会话内可用。公开 IM SDK 另有自己的上传上限，见上一节。

### 站长

1. 在 [knocket.trtc.io/register/](https://knocket.trtc.io/register/) 注册。教程未要求信用卡。
2. 配挂件（外观 / 主页 / 对话页）或联系页（模板或 AI 生成，块编辑器）。保存后挂件配置数秒内生效，不必改网站。只有第一次贴脚本要动站点。
3. Installation 复制脚本或联系页 URL。
4. Integrations：Telegram 用 BotFather token；Email 用 Knocket 别名（教程举例 `hello.you@knocket.ai`）或自有域 MX。
5. Notifications：浏览器推送；Telegram 分 Full（正文 + 引用回复，教程称高量时会因 Telegram 速率丢部分消息）和 Notification only（只有提醒，不能在 Telegram 里回）。
6. 回复从收件箱发出，或在 Full 模式下引用回复 Telegram。教程称回复回到访客原来的渠道。
7. AI Agent 有 Setting / Knowledge / Usage 三页。收件箱可按 All、AI Resolved、Human 过滤；详情里有 User ID、渠道、状态、首次响应、处理时长和 AI 摘要。可 Resume AI。

移动端教程写明没有原生聊天 SDK：iOS `WKWebView` + `knocket:` scheme，Android `WebView` + `addJavascriptInterface`，React Native `postMessage`，Flutter `addJavaScriptChannel`。首页「Native-feeling」是营销措辞。本次未核对控制台里的示例代码是否与现行脚本桥接一致。

## 4. 功能与控制点矩阵

| 能力 | 访客侧 | 站长侧 | 数据落点 | 等级 | 来源 |
| --- | --- | --- | --- | --- | --- |
| 网站挂件 | 右下角气泡；圆标或胶囊按钮 | 控制台三页配置，脚本只带 identifier | 配置与消息在腾讯云，不在 WP | 实测脚本 + 官方截图 | [挂件教程](https://knocket.trtc.io/tutorials/web-widget/)（Updated Sep 10, 2026）；首页 HTML |
| 界面语言 | UI 字符串与 RTL | 外观页语言下拉 | 远端配置 | 官方宣称；精确语种数未逐条点计 | [语言表](https://knocket.trtc.io/tutorials/web-widget-supported-languages/) |
| 挂件 FAQ | 开聊前的短问答 | 「若干条」，教程没有整数上限 | 远端配置，不是 AI 知识库 | 官方宣称 | 同上挂件教程 |
| 社交快捷方式 | 点图标离开站点 | WhatsApp、Instagram、Telegram、Messenger URL | 只存链接 | 官方教程；与部分营销「进收件箱」矛盾 | 挂件教程 |
| 会议链接 | 新标签打开 | 粘贴任意预约 URL | 只存 URL | 官方宣称 | 挂件教程 |
| 在线时段与离线留言 | 离线文案 + 表单 | 始终或按星期/时区；时区示例为 GMT+8 | 远端配置；留言进同一线程 | 截图实测 | [对话页截图](https://knocket.trtc.io/assets/images/tutorials/web-widget/04-conversation-page.png) |
| 访客气表单 | 姓名、邮箱、电话；预览里还有 Message | Off / Before conversation / When Offline；姓名邮箱电话可标必填 | 进收件箱线程 | 截图见三个开关；Message 是否可关，教程有、截图开关区未见 | 同上 |
| 联系页 | `https://knocket.ai/<slug>` | 同时只发布一页；免费计划页脚保留 Powered by Knocket | 托管在 Knocket，不在客户站点 | 官方宣称，本次未打开一个真实 slug | [联系页教程](https://knocket.trtc.io/tutorials/get-started-with-contact-page) |
| 联系页自定义域 | — | 总览表写「knocket.ai 或自定义域」；专页教程只写 slug | 无法确认自定义域是否已交付 | 文档互相不一致 | [总览](https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/) vs 联系页教程 |
| 移动入口 | App 内聊天气泡 | WebView 加载联系页，注入身份 | 仍是托管页 + IM | 官方宣称 | 总览教程 |
| 统一收件箱 | 无 | 三入口 + Telegram + Email 同一线程 | 腾讯云 IM / 控制台 | 教程 + 侧栏截图 | 对话页截图；总览教程 |
| Telegram | 不直接进 Telegram | Bot token；引用回复回访客；高量可能丢消息 | Bot token 存 Knocket；消息经 Knocket 与 Telegram | 官方宣称，未实接 Bot | 总览教程 Notifications 节 |
| Email | 发到别名或 MX 域 | 入站变成线程 | 邮件进 Knocket | 官方宣称 | 同上 Integrations |
| 浏览器推送 | 无 | 控制台标签页提醒 | 浏览器权限 + Knocket | 官方宣称 | 同上 |
| AI 开关 | 开了才先见到 AI | 按渠道、按「始终 / 仅离线」 | 远端 | 截图实测 | [Setting 截图](https://knocket.trtc.io/assets/images/tutorials/console/ai/setting.png) |
| 机器人人设 | 名称、问候、头像 | 问候 400、提示词 2000、头像 1MB JPG/JPEG/PNG | 远端 | 截图实测 | [Bot 截图](https://knocket.trtc.io/assets/images/tutorials/console/ai/bot.png) |
| 聊天模型 | 无 | 自带 OpenAI Chat Completions 兼容端点；有 Test Connection | 密钥存在 Knocket（教程称加密，未独立验证） | 截图见掩码密钥与端点；加密是宣称 | [LLM 截图](https://knocket.trtc.io/assets/images/tutorials/console/ai/llm.png) |
| 知识 FAQ / URL / 文件 | 不直接看见资料 | 每类 10 条；文件 10MB；URL 为单页 | 原文与向量在平台；界面无导出 | 截图实测 | 见第 5 节 |
| 人工接管 | 短语立即转人工；否则模型判断 | 默认短语库宣称 47 种语言，可自加；通知走浏览器或 Telegram | 短语在远端；`transfer_to_human` 不在公开脚本 | 教程宣称 + 挂件内置文案实测 | [AI 教程](https://knocket.trtc.io/tutorials/ai-agent/)；[短语页](https://knocket.trtc.io/tutorials/ai_agent_handoff_phrases/) 未逐条核对 |
| 用量 | 无 | 请求数、转人工数、响应时间、按模型的 token、按渠道分布 | 平台统计 | 官方宣称；截图 URL 存在，本次以教程文字为准 | AI 教程 Usage 节 |
| 主动招呼 | 教程称延迟后弹出 | 总览有，2026-09-10 挂件专页的三页结构未列此项 | 无法确认现行控制台是否还有 | 文档漂移 | 总览 vs 挂件教程 |
| 工单、SLA、座席路由、角色 | 无 | 教程与截图均未出现 | 不存在于已核对的控制台面 | 未见即未证实；营销称无座席限制 | 教程全集；why-free |
| 站长 API | 无 | 教程无；条款无 export | 社区项目用 Chrome CDP 操作收件箱，不是官方 API | 无法确认有稳定 owner API | [fangxinmoon/knocket-inbox-agent](https://github.com/fangxinmoon/knocket-inbox-agent) SKILL，2026-03-18 创建，2026-03-24 最后推送 |

样本 FAQ 截图的答案正文里出现 “Developer APIs / MCP”。那是他们自己知识库里的一段样例文字，不是已发布的 API 说明。条款和教程都没有 owner API。

## 5. 知识输入与存储限制

来源是 [AI Agent 教程](https://knocket.trtc.io/tutorials/ai-agent/)（页眉 Updated Sep 7, 2026）及其截图。截图 HTTP 200，本次看过像素。

| 限制 | 截图或教程中的值 | 等级 |
| --- | --- | --- |
| FAQ 条数 | 计数器 `2/10`，有 Add FAQ、搜索、编辑、删除 | 实测 |
| URL 条数 | 计数器 `3/10`；每行一个完整 URL，有 Last Synced 与删除 | 实测 |
| 文件条数 | 计数器 `1/10` | 实测 |
| 文件大小与格式 | “Max size: 10MB”；PDF, TXT, DOC, DOCX, HTML, MD, CSV, XLSX | 实测 |
| 索引状态 | 行上 Indexed；教程称通常数秒 | 徽章实测；耗时是宣称 |
| URL 刷新 | 有 Last Synced（截图为 2026/9/2）；教程称删行再加才能重爬 | 时间戳实测；重爬步骤是宣称。界面无单独 Sync 按钮 |
| 爬取范围 | 三行分别是单页或站点根 URL，没有深度、选择器或排除规则 | 合理推断为单页抓取 |
| 问候 / 系统提示词 | `126/400`、`613/2000` | 实测 |
| 头像 | JPG/JPEG/PNG，1MB | 实测 |
| 嵌入 | 截图原文：“Embedding provider credentials and model settings are managed by the platform.” | 实测界面文字 |
| 聊天模型 | 自带；截图示例 Provider Custom、`https://tokenhub.tencentmaas.com/v1`、模型 `kimi-k3` | 实测为界面示例，不是唯一可用模型 |
| 默认作答规则 | 不编造；只根据提供的知识；没有就说没有 | 实测于系统提示词框 |
| 导出 / 下载原文或向量 | 三张知识截图的 Actions 只有编辑或删除 | 实测未见 |
| 分块大小、重叠、嵌入模型、向量库、Top-K、相似度阈值 | 教程只说 chunked, embedded, retrieved | 无法确认 |
| 单条 FAQ 或单页 URL 的字符上限 | 未写 | 无法确认 |
| URL 是否跟随站内链接、是否执行 JS、失败如何显示 | 未写 | 无法确认 |
| 删除后向量是否同步失效 | 未写 | 无法确认 |
| 是否用客户知识训练第三方模型 | 教程 FAQ 称否，只用于账号内检索和分析 | 官方宣称，不在隐私模块里 |

教程建议先放 3–5 条 FAQ、首页和定价 URL、一两份文档，并称这通常能挡住小 SaaS 七成以上重复问题。这是销售性建议，不是测量结果。

知识库 FAQ 和挂件主页 FAQ 不是同一个东西。挂件 FAQ 是开聊前的静态短列表；知识库 FAQ 才进入检索。

## 6. 数据存放、保留与导出

### 已核对的存放

- 访客身份：`DescribeUserSig` 换腾讯云 IM `userSig`。`visitorId` 由脚本提交。`localStorage` 有过期缓存，键名未逐一对照到业务字段。
- 会话传输：公开脚本连接 `*.my-imcloud.com`、`wss.im.qcloud.com`、`wss.tim.qq.com` 等区域端点。账号实际落到哪个区域，外部看不到。
- 日志类数据：隐私模块写明与 CLB、TencentDB MySQL、MongoDB、Redis、CKafka、COS 集成。配置项里包括「historical message storage period」和「selected data centre」，这是 IM 应用配置字段，不是 Knocket 站长界面上的开关。
- 分析：挂件构建常量打开神策，上报腾讯云。不能据此断言聊天正文被送进神策，只能断言分析 SDK 被编进挂件并启用。
- 知识与嵌入：站长界面明确由平台管理嵌入。原文上传后只有 Indexed / 删除，没有下载。

### 保留（仅隐私模块写明的类别）

| 数据 | 模块中的保留 |
| --- | --- |
| 网络条件、系统属性、IP、网站交互 | 5 天 |
| Log Data | 7 天 |
| Monitoring Data | 2 天 |
| Configuration Data | 使用期间保留；停用后 2 个月删除 |

该表没有聊天正文、附件、知识文件、向量、LLM 提示词或 API 密钥。不能把 7 天日志保留说成对话保留 7 天。

### 条款里站长实际拿到的控制

2026-10-26 从 [条款第 8 节与终止节](https://knocket.trtc.io/legal/terms/) 核对的句子：

- User Data 包括由你或终端用户提交的数据、信息和媒体，含个人数据，但不包括账户本身的资料。
- 你授予腾讯非独占、可再许可的权利，为提供服务而访问、复制和使用 User Data。
- 腾讯可在通知或不通知的情况下，为守法或保护自身权利、或为配合法院、政府、执法程序披露 User Data；也可依法屏蔽或删除。
- “You are solely responsible for maintaining and backing up User Data.”
- 你保留 User Data 中你原有的知识产权。
- 终止后你应删除软件并从服务中移除应用和 User Data。
- “Tencent has no obligation to make accessible to you any User Data after the termination of these Terms.”

条款 HTML 中没有 “knowledge base”，也没有站长导出。隐私页同样没有导出步骤。

因此：知识「由自己控制」在 Knocket 上不成立。站长能增删条目、能换聊天模型密钥，但不能拿走向量，不能从已核对的界面导出原文，终止后也没有交还义务。备份责任在站长，产品却没给备份工具。

## 7. 免费范围

没有独立的价目表。已核对的说法彼此并不完全相同，必须分开记。

| 说法 | 出处 | 怎么读 |
| --- | --- | --- |
| 核心联系层永久免费：挂件、联系页、收件箱；不按座席收费、无广告 | [why-free](https://knocket.trtc.io/why-free)；首页 “100% Free” | 产品信，不是合同报价。信中同时说，团队变大后的集成、自动化、开发者 API、AI 工作流可以收费 |
| 核心免费，AI 自动回复和部分未来能力可能作为附加项 | 知识库 FAQ 截图中的样例答案 | 与 why-free 一致：免费的是核心联系层 |
| AI Agent 本身在免费计划里；只为自带模型的 token 付费；嵌入由平台包含 | [AI 教程 FAQ](https://knocket.trtc.io/tutorials/ai-agent/) | 2026-09-07 的教程宣称。与「未来可能附加收费」不矛盾，但是现状描述，不是永久承诺 |
| 无消息上限、无座席上限、无历史锁定 | 多篇官方对比博客 | 营销。教程和隐私模块都没有给出可执行的上限或「无上限」的控制台开关 |
| 联系页免费计划保留 Powered by Knocket | 联系页教程 | 与「挂件无厂商标」的营销矛盾 |
| 挂件无厂商标 | 多篇对比博客；WordPress 插件 readme | 被现行材料否定：公开脚本有水印类和图片；对话页预览底部有 Knocket 标记 |

本次未走注册，不能证明新账号今天仍免信用卡、仍看得到 AI 菜单。

WordPress.org 插件 readme 写 AI Agent 与 AI 联系页「即将推出」，截图说明第 4 张也写 coming soon。插件 `last_updated` 为 2026-08-21。AI 教程日期是 2026-09-07，URL 截图的 Last Synced 是 2026/9/2。插件文案旧于控制台教程。不能用插件 readme 否定 9 月教程，也不能用教程代替一次新账号登录。

## 8. 官方 WordPress 插件实际做了什么

来源：WordPress.org [插件页](https://wordpress.org/plugins/knocket-live-chat-widget/) 与 SVN r3765890 的 `trunk`（2026-10-26 下载）。API：版本 1.0.2，要求 WordPress 5.9+、PHP 7.4，tested up to 7.0.7，活跃安装 0，作者资料页为 ivory；插件头写 Author Knocket。

插件文件只有主文件、`admin/settings.php`、`uninstall.php`、`readme.txt`。

它做的事：

- 设置页只有 Script Source URL 和「仅首页」。
- URL 必须是 `https://trtc.io/knocket-sdk/sdk.js`，且查询参数同时有非空的 `identifier` 和 `v`。不允许用户信息、端口、片段。
- 通过 `wp_enqueue_script` 放在页脚。
- 卸载只 `delete_option('knocket_settings')`。

它不做的事：不存消息，不存知识，不提供收件箱，不代理 Telegram，不保存模型密钥，不渲染挂件 HTML。readme 自己写：访客使用挂件后，消息送到 Knocket 服务器。

与首页实测的不一致：首页脚本没有 `v`。把首页那种标签贴进插件会被拒绝。控制台 Installation 是否总带 `v`，未登录不能确认。插件错误文案要求 identifier 和 v。

同类公开插件的形态，只用来说明「WordPress 插件」常常不是资料主人，不作为选型：

- [tawkto-live-chat](https://wordpress.org/plugins/tawkto-live-chat/) 0.9.3：官方描述是把 tawk.to 挂件装进 WordPress；知识库在对方产品里。
- [chatway-live-chat](https://wordpress.org/plugins/chatway-live-chat/) 1.5.2：描述为厂商的 AI 聊天与人工聊天，WhatsApp 等是点击外链。
- [ai-engine](https://wordpress.org/plugins/ai-engine/) 3.8.7：描述为在 WordPress 后台连接外部模型并做理解站点内容的聊天机器人。这是「插件在 WP 内编排」的一种公开形态，不是 Knocket 收件箱的克隆，本次未审其向量存在哪里。

## 9. 自控知识与存储时必须复现或接管的点

目标约束来自父地图：使用方式尽量像 Knocket，知识输入和存储由自己控制。下面不是实现规格，也不预设和询盘的关系。

必须接管，否则「自己控制」不成立：

1. 会话与附件存储。现在的主人是腾讯云 IM / COS。WordPress 若只 enqueue 远程脚本，站内没有任何对话。
2. 知识原文。FAQ、抓来的页面、上传文件必须有站长可下载、可删除、删除后索引失效的副本。Knocket 界面没有导出。
3. 分块、嵌入、检索。Knocket 把嵌入凭据和模型收在平台。要自控，这一层不能留在对方账号里。分块参数他们没公开，所以不能「照抄默认值」，只能自己定义并写进后续规格。
4. 聊天模型密钥。Knocket 已允许自带端点，但密钥存在他们一侧。自控意味着密钥不离开自己的秘密存储。
5. 系统提示词与作答边界。2000 字上限是他们的产品限制，不是技术定律。自控插件可以放宽，但「只根据提供的知识、不编造」是他们默认提示词里的使用方式，若要对齐行为，这条规则要自己实现，不能假设模型会遵守。
6. 人工接管状态。短语立即转人工、模型判断转人工、把线程交回 AI，都是收件箱行为。公开脚本只有文案，没有策略。
7. 导出与删除。条款把备份责任放在站长，又写明终止后无义务交还。这是当前最大的控制缺口。自控插件要把导出和删除当成功能，而不是事后脚本。
8. 分析。现行挂件把神策编进构建。自控实现不应继承这条上报。

若使用方式也要对齐，需要复现，但是否自建由后续票定：

- 网站气泡、主页（欢迎、静态 FAQ、外链、预约链接）、离线时段与留言表单。
- 一个收件箱看全部线程，并按渠道标记。
- Telegram 引用回复，以及邮件入站变成同一线程。这两条会把令牌和邮件基础设施带进来；继续用第三方通知时，要单独写明哪些字段离开站点。
- 按渠道、按在线时段开关 AI。
- 用量：请求、转人工、耗时、token。这是操作读数，不是必须复制他们的图表。

不应误当成必须复现的东西：

- WhatsApp / Instagram / Messenger 进收件箱。官方挂件教程是外链。营销插画不能当需求。
- 英雄图里的 Analytics、Automations、Contacts。真实侧栏没有。
- `window.knocketConfig`。现行公开脚本没有。
- 原生移动 SDK。他们自己用 WebView。WordPress 插件覆盖不了 App 进程；若以后要这个入口，是客户端工作，不是插件里的一个类。
- 联系页的全部块类型（作品集、菜单、GitHub 星数等）。那是托管个人页，不是客服知识库。要不要做，是后续表面选择。
- 10/10/10 和 10MB。这是他们的输入上限，不是质量目标。自控的理由正是不要被这个上限卡住。后续规格应另定上限，并说明为什么。
- 社区 [knocket-inbox-agent](https://github.com/fangxinmoon/knocket-inbox-agent)。它用调试端口的 Chrome 去点控制台，知识也不在这个仓库里。不能当作集成机制，也不能当作官方 AI。它和 2026-09 的控制台 AI Agent 是两条线：一个在浏览器里代操作收件箱，一个在控制台里做检索回复。

官方插件已经证明：WordPress 侧最少可以只有一个设置页和一次 enqueue。那满足不了自控。自控插件的 WordPress 部分至少要拥有配置、知识、会话和导出；挂件可以仍是前端，但前端必须连自己的端点，而不是 `trtc.io`。

## 10. 对后续选型的影响

这些是事实边界，不是决定。

- 下一张「哪些能力要复现」的票，应把矩阵里的「截图实测」和「营销插画」分开。收件箱、离线留言、人工接管、自带聊天模型是有界面证据的。座席、工单、WhatsApp 摄入、开发者 API、自定义联系页域名没有。
- 「知识如何导入、切块、索引、删除」不能从 Knocket 抄参数。能抄的只有输入种类：手写问答、公网单页、八种文件。切块和嵌入模型必须另选，并决定原文存在 WordPress 还是只存派生索引。
- 聊天窗口、收件箱、通知、人工接管可以拆开选。Knocket 把它们放在一个腾讯云控制台。WordPress 插件不必先选一个 SaaS 收件箱再谈知识自控；一旦会话离开站点，第 9 节的接管就不完整。
- 和现有询盘、资料可见范围的关系仍应后置。本文件没有证据表明 Knocket 知道 WooCommerce 商品，也没有证据表明它应该知道。
- Hostinger、模型账单、验收，本文件没有测量。已知的成本形状只是：聊天 token 由自带供应商计费，嵌入在对方免费宣称里、因而不可审计。

## 11. 无法外部确认

- 新注册账号在 2026-10-26 是否仍免信用卡、是否看得到 AI Agent 菜单。
- 聊天正文、附件、知识文件、向量、提示词、API 密钥的保留期限和区域。
- 嵌入模型、向量库产品、分块参数、检索条数。
- URL 抓取是否只取静态 HTML、是否定期自动刷新。
- 删除知识后索引是否立即失效。
- 密钥「加密存储」的算法和谁能解密。
- 一个工作区能有几个操作员，有没有角色。
- 站长能否导出对话或知识。已核对的界面和条款都没有这个功能；不能证明后台完全没有。
- `v` 查询参数的含义。插件强制它，首页脚本没有它。
- 联系页自定义域、主动招呼、挂件 FAQ 的整数上限。
- 47 种人工短语是否与语言表一一对应。
- 神策事件里有没有消息正文。
- `ap-shanghai` 是否为 Knocket 租户的真实区域。

## 12. 来源

一手（2026-10-26 读取或看过像素）：

- https://knocket.trtc.io/
- https://knocket.trtc.io/why-free
- https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/
- https://knocket.trtc.io/tutorials/web-widget/
- https://knocket.trtc.io/tutorials/get-started-with-contact-page
- https://knocket.trtc.io/tutorials/ai-agent/
- https://knocket.trtc.io/legal/terms/
- https://knocket.trtc.io/legal/privacy/
- https://knocket.trtc.io/assets/images/tutorials/console/ai/setting.png
- https://knocket.trtc.io/assets/images/tutorials/console/ai/bot.png
- https://knocket.trtc.io/assets/images/tutorials/console/ai/llm.png
- https://knocket.trtc.io/assets/images/tutorials/console/ai/knowledge-faq.png
- https://knocket.trtc.io/assets/images/tutorials/console/ai/knowledge-url.png
- https://knocket.trtc.io/assets/images/tutorials/console/ai/knowledge-file.png
- https://knocket.trtc.io/assets/images/tutorials/web-widget/04-conversation-page.png
- https://trtc.io/knocket-sdk/sdk.js
- https://wordpress.org/plugins/knocket-live-chat-widget/
- https://plugins.svn.wordpress.org/knocket-live-chat-widget/trunk/ （r3765890）
- https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=knocket-live-chat-widget

旁证，不作为产品事实：

- https://github.com/fangxinmoon/knocket-inbox-agent （社区 CDP 代操作，不是官方 API）
- https://wordpress.org/plugins/tawkto-live-chat/
- https://wordpress.org/plugins/chatway-live-chat/
- https://wordpress.org/plugins/ai-engine/

未采用为架构证据的材料：knocket.trtc.io 与 trtc.io 上的竞品对比博客、AlternativeTo 条目、首页英雄插画中的侧栏。
