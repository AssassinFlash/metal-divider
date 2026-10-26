# MaxKB 作为开源知识库与网站问答

- 票：[研究：MaxKB 作为开源知识库客服系统](https://github.com/AssassinFlash/metal-divider/issues/97)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/maxkb-knowledge-chat`（从 `origin/main` 的 `9da89ab` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-97-maxkb`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树和 `/tmp/maxkb-src`；不删除远程分支，不删除本文件

本文件只回答 MaxKB 现行开源版能否承担知识库、网站嵌入和问答，对话后台与人工接管有没有，数据落在哪，以及和 Knocket 使用方式相比还缺什么。不决定它和 Metal Divider 询盘、资料公开范围的关系，也不选模型。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 源码 | 2026-10-26 浅克隆 `1Panel-dev/MaxKB` 默认分支 `v2`，HEAD `c09b873bf83804b1d2f009104af017f6126add64`（2026-10-26 12:25:50 +0800）读到的文件 |
| 官方宣称 | 官方文档、价格页、README、release note。未登录控制台，未跑容器 |
| 合理推断 | 由源码或官方页推出、但没有运行证据的判断 |
| 无法确认 | 外部材料没写，或两份一手材料互相矛盾且本次未实跑 |

未安装 MaxKB，未改生产，未调用会创建会话的接口。星数、Docker pull 数不作为能否上线的依据。Knocket 对照用的是 [票 87 已关闭结论](https://github.com/AssassinFlash/metal-divider/issues/87)，本票不重做 Knocket。

## 结论速览

1. 现行可部署线是 v2，不是营销旧站上的 2 用户 / 5 应用 / 50 知识库版。最新正式版是 [v2.10.8-lts](https://github.com/1Panel-dev/MaxKB/releases/tag/v2.10.8-lts)（2026-10-22，`792a525c5a78`）。默认分支当天仍有提交。许可证是 GPL-3.0，不是 AGPL。仓库没有归档。
2. 社区版能直接做知识库、检索问答、网站浮窗/iframe 嵌入、对话日志、导出，以及应用级 OpenAI 兼容对话 API。原文、分段、向量、对话都在自托管的 PostgreSQL；向量是 pgvector，不是独立向量库。Redis 是缓存和任务，不是向量库。
3. 没有 Knocket 那种收件箱和人工接管。公开源码没有转人工节点，对话日志只能看、标、灌回知识库，不能在后台回复访客。企业微信、钉钉、飞书、公众号、Slack 的后端不在公开仓库，官方价格页标为专业版。
4. 没有官方 WordPress 插件。接入是贴嵌入脚本、iframe，或调对话 API。要把 Woo 图案同步进知识库，需要另写小连接器；MaxKB 不会变成第二份可独立放行的商品源。
5. 硬边界是部署，不是 GPL 禁止自托管。官方生产要求 Ubuntu 22.04 或 CentOS 7、Docker 26+、4 核 / 8 GB / 100 GB。这不能塞进现有 Hostinger 共享主机上的 WordPress。专业版若要买，是永久授权 ¥48,000 / 套，不是月费插件。

## 1. 产品身份与维护

官方仓库是 [https://github.com/1Panel-dev/MaxKB](https://github.com/1Panel-dev/MaxKB)。2026-10-26 读到的 GitHub 元数据：默认分支 `v2`，许可证 SPDX `GPL-3.0`，未归档，`pushed_at` 2026-10-26T09:18:55Z，创建于 2023-09-14。主页指向 [https://maxkb.cn](https://maxkb.cn)。文档现行站是 [https://docs.fit2cloud.com/maxkb/](https://docs.fit2cloud.com/maxkb/)。`maxkb.cn/docs/v2/installation/offline_installtion/` 会跳到该站。

维护没有停。最近正式版：

| 标签 | 发布日期 | 说明 |
| --- | --- | --- |
| v2.10.8-lts | 2026-10-22 | 最新非预发布版。含 X-Pack 登录配置泄密修复，以及知识库文件名字符修复 |
| v2.10.7-lts | 2026-10-15 | 对话历史删除确认、长记忆间隔 |
| v2.10.6-lts | 2026-09-03 | 聊天上传、跨知识库注入、XLSX 解压内存耗尽等安全修复 |
| v1.10.15-lts | 2026-08-11 | 旧主线仍在出安全修复，不是当前默认分支 |

默认分支 HEAD 比 v2.10.8-lts 新。本次读的是 HEAD，不是安装包。另有 `v3` 分支：2026-10-21 有合并提交 [PR #7407](https://github.com/1Panel-dev/MaxKB/pull/7407)（embed mode）。GitHub releases 列表里没有 v3 正式版。v3 未纳入本次能力结论。

2026 年 release 多次写安全漏洞，包括 [v2.9.1](https://github.com/1Panel-dev/MaxKB/releases) 注明的 CVE-2026-45413、CVE-2026-45412。这只说明有人在修，不说明当前版本已经没有同类问题。若采用，应跟最新 LTS，不能拿星数代替补丁。

## 2. 开源与付费边界

[价格页](https://maxkb.cn/price)（2026-10-26 读取；页面写社区版、专业版、企业版）和源码要分开看。旧站 [https://www.maxkb.pro/pricing](https://www.maxkb.pro/pricing) 仍写社区版 2 用户 / 5 应用 / 50 知识库。现行价格页提示④写明：MaxKB V2 于 2025-07-17 发布后，社区版不再限制用户数、应用数、知识库数。对比表三列都是「不限制」。`https://maxkb.cn/pricing.html` 当天返回 404；源码里的升级按钮仍打开这个死链。

源码里还留着旧限额，但本次看到的创建路径没有挂上：

- `apps/system_manage/serializers/valid_serializers.py` 仍写社区版 50 知识库、5 应用、2 用户，并由 `GET /admin/api/valid/<type>/<count>` 执行。字典键是 `dataset`，前端枚举却是 `knowledge`。这个接口若被用旧键调用，仍会拒绝。
- `valid_license()` 在 `apps/common/utils/common.py`，`user.py` 导入了它，本树没有调用点。
- 前端 `asyncGetValid` 有定义，视图里没有调用。导入接口返回 HTTP 400 时，界面会弹出「请升级专业版」，不管 400 的真实原因。

因此：官方现行产品口径是社区版不限数量；公开源码的创建接口没有执行 2/5/50；残留校验接口和错误文案还在。未实跑创建第 6 个智能体，不能把「源码没挂上」说成「已经压测通过」。

专业版和企业版不是另一套开源仓库。公开树没有 `xpack` 包。Celery 启动时 `from xpack import job`，`ImportError` 就跳过。`settings.edition` 在 `apps/maxkb/settings/base/web.py` 写死 `'CE'`。`license_is_valid` 未注册时当作 false。专业版是装到同一代码上的授权包，不是社区版编译开关能打开的。

价格页与源码对得上的付费边界：

| 能力 | 社区版 | 依据 |
| --- | --- | --- |
| 通用知识库、Web 知识库、工作流知识库 | 有 | 价格页；`KnowledgeType` 与 `apps/knowledge` |
| 文件类型 MD/DOCX/PDF/TXT/HTML/XLSX/XLS/CSV，以及源码里的 DOC、ZIP | 有 | 价格页；`apps/common/handle/impl/text/` |
| 简易智能体、工作流、长期记忆、MCP、工具、触发器 | 有 | 价格页；`apps/application/flow/step_node/__init__.py`、`apps/trigger` |
| 模型供应商对接 | 有 | 价格页；`model_provider_constants.py` |
| 网站浮窗 / 全屏 iframe / 移动 iframe | 有 | `EmbedDialog.vue`、`apps/chat/template/embed.js` |
| 应用级 OpenAI 兼容对话 API | 有 | [对话 API 文档](https://docs.fit2cloud.com/maxkb/user_manual/chat_to_API)；`apps/chat/urls.py` |
| 对话日志、导出、标注回知识库 | 有 | [对话日志](https://docs.fit2cloud.com/maxkb/user_manual/app/log) |
| 浮窗图标、可拖位置、AI 头像、免责声明、历史对话显示设置 | 无，专业版 | 价格页；`chat_embed_serializers.py` 只在许可证有效且 `application_setting` 模型存在时改这些值 |
| 企业微信、钉钉、飞书、公众号、Slack | 无，专业版 | 价格页；[接入第三方](https://docs.fit2cloud.com/maxkb/user_manual/X-Pack/app_integrate)。公开 Python 树没有这些渠道的实现，只有 `ChatSourceChoices` 枚举和前端配置页 |
| 飞书知识库同步 | 无，专业版 | 价格页。模型枚举有 `LARK`，`apps/knowledge` 下没有飞书同步实现 |
| 按对话用户授权检索、对话用户管理、RBAC、SSO、操作日志、自定义 Logo | 无，专业版 | 价格页 |
| 平台级完整系统 API | 无，专业版 | 对话 API 文档写「专业版在社区版本基础上提供平台级别的完整 SDK」；系统 API 文档在 X-Pack 路径 |
| 多租户、集群、跨空间共享资源 | 无，企业版 | 价格页 |
| ARM 安装包 | 无，专业版 | 价格页。社区安装包只写 x86_64 |

专业版价格是官方页上的硬数字：¥48,000 / 套永久授权，含一年维保；第二年起维保 ¥9,600 / 年。一客一套一实例。企业版只写「联系销售」，电话 400-052-0755。没有 SaaS 订阅档写在这一页上。试用要另申请，本次未申请。

GPL-3.0 允许自托管未改二进制。它不是 AGPL，网络调用本身不要求公开调用方源码。若把 MaxKB 源码拷进要分发的 WordPress 插件，分发时要按 GPL-3.0 提供对应源码。贴脚本或调 HTTP API 不把 MaxKB 源码带进 WordPress。这是许可证文本的边界，不是律师意见。见仓库 [LICENSE](https://github.com/1Panel-dev/MaxKB/blob/v2/LICENSE) 与 [README](https://github.com/1Panel-dev/MaxKB/blob/v2/README.md)。

## 3. 部署与数据落点

官方生产推荐离线安装包，不是 `docker run` 一行就上生产。[离线安装](https://docs.fit2cloud.com/maxkb/installation/offline_installtion/) 写明：

- 系统：Ubuntu 22.04 或 CentOS 7，内核 ≥ 3.10
- 配置：4 核 / 8 GB 以上，磁盘 100 GB
- Docker ≥ 26，或用安装包自带的 Docker
- 端口：22 与 Web 8080
- 默认可选外部 PostgreSQL / Redis；不选则用容器内置

`installer/Dockerfile` 与 `Dockerfile-base` 把运行时打进一个镜像：Python 3.11、PostgreSQL 17.11、`postgresql-17-pgvector`、Redis、ffmpeg，基础镜像标签 `ghcr.io/1panel-dev/maxkb-base:python3.11-pg17.11-20260903`。`installer/init.sql` 只执行 `CREATE EXTENSION "vector"`。镜像还装了 `postgresql-17-age`，初始化脚本没有启用它，本次未看到业务代码依赖 AGE。

持久化卷是 `/opt/maxkb`。`PGDATA=/opt/maxkb/data/postgresql/pgdata`。知识原文不在对象存储：

- 段落正文在表 `paragraph.content`，最长 102400 字符
- 向量在表 `embedding.embedding`，类型 `vector`，另有 `search_vector` 做关键词
- 上传文件进 PostgreSQL large object：`file.loid`，写入前 zip 压缩，按 sha256 去重（`apps/knowledge/models/knowledge.py` 的 `File`）
- 对话在 `application_chat` / `application_chat_record`。问题最长 10240，答案最长 40960，另有 token、费用、运行节点 `details`
- 长期记忆在 `application_long_term_memory`

删除文档会删对应 embedding 行（`PGVector.delete_by_document_id`）。这是库内删除，不是只藏索引。文件 large object 在没有其它行引用同一 `loid` 时 `lo_unlink`。

默认口令写在 README 和安装文档里：管理员 `admin` / `MaxKB@123..`，库 `Password123@postgres`，Redis `Password123@redis`。暴露到公网前必须改。这是部署事实，不是许可证条款。

官方架构页把 Redis 和 pgvector 写在同一行「向量数据库」下（[系统架构](https://docs.fit2cloud.com/maxkb/system_arch)）。源码不支持这个说法：检索 SQL 在 `apps/knowledge/sql/`，走 PostgreSQL。Redis 配置在 Django cache / Celery。

镜像默认嵌入模型路径是 `/opt/maxkb-app/model/embedding/shibing624_text2vec-base-chinese`。这是随镜像带的中文向量模型，不是唯一可选模型。未用英文目录实测这个默认模型的检索质量。

Hostinger 共享主机上的 WordPress 不满足上述机器要求。MaxKB 必须另有一台能跑 Docker 的 Linux。本次未报价，也未测 4 核 8 GB 是否够 146 个图案的索引。

函数沙箱默认禁止访问 `127.0.0.0/8`、`localhost`、Docker 网桥（`Dockerfile-base` 的 `MAXKB_SANDBOX_PYTHON_BANNED_HOSTS`）。工作流里的自定义函数不能顺手打本机 WordPress。连接应走公网 HTTPS 或明确放行的地址。

## 4. 知识库

社区版知识库不是 Knocket 的 10 条 FAQ / 10 个 URL / 10 个文件。

源码支持的入库：

- 上传：TXT、MD、HTML、PDF、DOC、DOCX、XLS、XLSX、CSV，以及装这些文件的 ZIP。拆分器在 `apps/common/handle/impl/text/` 与 `qa/`、`table/`。
- 单文件默认上限是知识库字段 `file_size_limit`，默认 100，比较单位是 MB（`document.py`：`f.size > 1024 * 1024 * knowledge.file_size_limit`）。`file_count_limit` 默认 50，是这个库的文件数设置，不是旧产品的 50 个库上限。
- Web：`sync_web_knowledge` 用 `ForkManage(url, selector).fork(2, ...)`。`level` 从 2 递减到小于 0 停止，所以是起始页再加两层同前缀链接，不是全站。选择器是 class、id 或标签名。链接限于同一路径前缀，去掉锚点。
- 手工段落、问题表 `problem`（可挂到段落）、术语表 `termbase`（参与分词）。
- 工作流知识库：本地文件节点、Web 节点、分段、写入。节点列表在 `apps/application/flow/step_node/__init__.py`。

检索有三种：`embedding`、`keywords`、`blend`。命中后可以让模型改写，或在相似度默认 0.9 时直接返回段落（`HitHandlingMethod`）。这不是转人工。

更新与删除在社区版里是文档级：重新同步 Web、替换同步、删文档级联删向量。没有「Woo 商品保存即失效索引」的钩子。若图案只在 Woo 维护，MaxKB 里的副本必须由 Woo 变更触发重建，不能手改两边。

飞书、语雀出现在 `KnowledgeType` 枚举和部分前端对话框。公开 `apps/knowledge` Python 没有对应同步实现。价格页只把飞书知识库标为专业版。语雀能否在社区版用，本次无法确认。

## 5. 网站嵌入

嵌入在社区版，不在 X-Pack。`ui/src/views/application-overview/component/EmbedDialog.vue` 给出三种粘贴片段：

- 全屏 iframe，地址是应用的公开 token
- 移动 iframe，查询参数 `mode=mobile`
- 浮窗：`<script src="{协议}://{主机}/chat/api/embed?protocol=...&host=...&token=...">`

`apps/chat/serializers/chat_embed_serializers.py` 用模板 `apps/chat/template/embed.js` 生成脚本。脚本插入右下角按钮和 460×680 iframe，可放大。默认图标是站点上的 `MaxKB.gif`。白名单、公开访问开关、问候引导在社区版生效。可拖拽、自定义浮标、自定义顶栏字色只在许可证有效时从 `application_setting` 读出；否则写死 `is_draggable=false` 和默认图标。

公开访问还有：来源展示 `show_source`、执行详情 `show_exec`、可选认证、界面语言字段。这些字段在 `application_access_token` 表，不依赖 xpack 模型。

每个对话用户每天的访问次数默认 100（`access_num`）。超限抛「The number of visits exceeds today's visits」（`apps/chat/serializers/chat.py`）。这是接入设置，不是许可证。

嵌入脚本没有 Knocket 那种强制水印链接。也没有官方 WordPress 短代码。

## 6. 问答、对话后台、人工接管

问答是智能体，不是坐席收件箱。

社区版有：

- 系统提示词、开场白、多轮、问题优化、无命中时继续问模型或返回指定句
- 语音输入 / 播放，取决于是否配置了 STT/TTS 模型；浏览器播放标为免费
- 工作流节点：检索、条件、直接回复、表单、工具、MCP、意图、循环。没有人工节点
- 访客历史会话 API：`historical_conversation`
- 管理端对话日志：按摘要或用户搜，7/30/90/180 天或自定义区间；详情里的用户反馈只能看不能改；运营可编辑标注并写入知识库；可导出
- 清除策略默认 180 天，次日 00:05 跑（`clean_chat_job.py` 与文档一致）

没有：

- 坐席在同一条线程里回复访客
- 转人工、排队、离线接管、Resume AI
- 统一收件箱把网站、Telegram、邮件收成一个可回复线程
- 浏览器推送或移动坐席 App

「转人工」只出现在示例开场白字符串里（`ui/src/locales/lang/zh-CN/views/application.ts`）。`to_human_message` 是拼给模型的 HumanMessage，不是坐席。

企业微信客服文档写接待方式可选「机器人+人工接待」（[接入第三方](https://docs.fit2cloud.com/maxkb/user_manual/X-Pack/app_integrate)）。那是企业微信自己的接待，人工在企业微信里，而且整条接入是专业版。不能当成 MaxKB 社区版有人工接管。

SMTP 设置在 `email_setting`，是系统发信，不是访客邮件进线。`ChatSourceChoices` 没有 Email、Telegram、WhatsApp、Instagram、Messenger。

## 7. 模型

`ModelProvideConstants` 在公开源码注册了这些供应商：Azure OpenAI、文心、Ollama、OpenAI、Docker AI、Kimi、智谱、讯飞、DeepSeek、Gemini、火山引擎、腾讯、AWS Bedrock、本地、Xinference、vLLM、阿里云百炼、Anthropic、SiliconCloud、Regolo、MiniMax。

对话 API 文档写明：把 OpenAI SDK 的 Base URL 和 API Key 换成 MaxKB 应用自己的地址和 Key，路径是 `{Base URL}/chat/completions`。模型字段在示例里仍写 `gpt-3.5-turbo`，实际答的是 MaxKB 应用，不是把这个名字转给 OpenAI。社区版这一层可用。平台级系统 SDK 文档放在专业版。

密钥存在 MaxKB 自己的库里。本次未核对其加密方式。自托管意味着模型密钥、原文和对话都在这台机器上，不在 Knocket / 腾讯云。

## 8. WordPress 与第三方连接器

公开仓库全文没有 `wordpress` / `WordPress`。2026-10-26 在 WordPress 插件目录搜 `maxkb`，返回的不是 MaxKB 插件。不能据此证明全世界没有第三方非官方插件；能证明的是官方仓库和插件目录首页搜索没有现成官方插件。

现成接入只有：

- 把浮窗脚本放进主题页脚、自定义 HTML 或一个极小的插件。脚本只带 token，图案上下文要靠工作流的 `api_input` 查询参数自己拼（`get_query_api_input` 已读这些参数）。
- 全屏或移动 iframe。
- 服务端调应用对话 API。不要把管理员密钥放进浏览器。

没有 WooCommerce、WhatsApp、Telegram 连接器。企业渠道在专业版，且面向企业微信 / 钉钉 / 飞书 / Slack，不是 Metal Divider 现在的对客 WhatsApp。

若知识必须跟 Woo 发布状态走，缺的是一条同步：只把当前已发布图案的公开字段推进 MaxKB，下架时删文档和向量。MaxKB 的文档 API 在社区版 `apps/knowledge`。这条同步是项目间连接，不是再写一套 RAG。本次未读完该 API 的每个字段，也未实调。

## 9. 和 Knocket 的对照

Knocket 的使用方式见票 87：网站挂件、托管联系页、WebView 进一个收件箱；Telegram 与 Email 进线；WhatsApp 等在教程里是外链；AI 可按渠道和时段开关；低置信或指定短语转人工；知识上限 FAQ/URL/文件各 10。

| Knocket 用法 | MaxKB 社区版 | 还要什么 |
| --- | --- | --- |
| 网站右下角挂件 | 有。浮窗脚本 + iframe | 小段 WordPress 注入。自定义图标和拖拽要专业版 |
| 全屏 / 移动对话页 | 有 iframe 与 `mode=mobile` | 不是 Knocket 的联系页搭建器 |
| 托管联系页、自定义域联系页 | 无 | 不做，或用 WordPress 页面嵌 iframe |
| 开场白、系统提示、自带模型密钥 | 有，且模型选择多于 Knocket | 无 |
| FAQ / URL / 文件知识，可导出可删除 | 有，且没有 10 条上限。Web 只爬起始页加两层 | 与 Woo 的同步要自写 |
| 命中后显示来源 | 字段在社区版，默认关 | 打开 `show_source`。未实看前端是否始终渲染 |
| 统一收件箱、坐席回复、转人工、Resume AI | 无 | 另一个客服项目，或专业版企业微信里的人工接待。后者不是本站收件箱 |
| Telegram、Email 进线 | 无 | 另一个项目 |
| WhatsApp 进收件箱 | Knocket 教程里也只是外链 | MaxKB 同样没有。外链按钮要自己做 |
| 按渠道、按时段开关 AI | 无同等开关 | 工作流条件只能近似，不是坐席策略 |
| 访客气表单 | 工作流有表单节点 | 未实走，不能说等于 Knocket 的聊前姓名邮箱电话 |
| 浏览器推送、移动坐席 | 无 | 另一个项目 |
| 知识与对话留在自己的库 | 有。PostgreSQL 在自有卷 | 机器不在现有共享主机上 |

## 10. 能否少写代码接上

能少写的部分：知识库、检索问答、网站挂件、对话日志、模型接入、删除和导出。这些不用从零做。WordPress 侧只注入脚本或代调 API。

不能靠 MaxKB 社区版补上的部分：人工接管、统一可回复收件箱、Telegram/Email/WhatsApp 进线。专业版补的是企业微信等渠道和界面定制，不补一个通用坐席收件箱。若目标是 Knocket 的完整用法，MaxKB 只覆盖知识与挂件这一半。

不要把 MaxKB 知识库当成可独立发布商品的第二源。图案仍以 Woo 当前发布状态为准，MaxKB 只保存派生副本。

## 11. 无法确认

- 未跑容器，未验证第 6 个智能体、第 51 个知识库是否被残留接口拦住。
- 未验证默认中文嵌入模型对英文图案目录的召回。
- 未验证 `show_source` 在访客页的实际渲染，未验证表单节点能否做成聊前门槛。
- 未核专业版安装包里的 xpack 与公开钩子是否一一对应。价格页与钩子一致，安装包本身未下载。
- v3 分支未读。
- 未找全非官方 WordPress 插件；只确认官方仓库和插件目录搜索没有官方插件。
- 4 核 8 GB 对 146 个图案是否够，未测。
