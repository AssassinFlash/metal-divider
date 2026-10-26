# 研究：ChatterMate 一体化 AI 客服与人工接管能力

- 票：[研究：ChatterMate 一体化 AI 客服与人工接管能力](https://github.com/AssassinFlash/metal-divider/issues/93)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/chattermate-open-source`（从 `origin/main` 的 `9da89ab` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-93-chattermate`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee）
- 回收条件：分支推送成功且票关闭后删除该工作树与可重建的上游克隆；不删除远程分支，不删除本文件

本文件只回答 ChatterMate 现在公开仓库里实际有什么、知识与对话落在哪里、WordPress 插件做了什么，以及它相对 Knocket 使用方式缺什么。不决定它和 Metal Divider 询盘、资料公开范围的关系，也不选模型。不重复评估 Chatwoot 或其他客服项目。

对照基准来自已关闭的 [Knocket 研究](https://github.com/AssassinFlash/metal-divider/issues/87)：三条入口进一个收件箱、自带聊天模型、知识为 FAQ / 单页 URL / 文件、人工接管、导出与删除由自己掌握。10/10/10 是 Knocket 的上限，不是质量目标。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 源码核对 | 2026-10-26 读取的公开仓库 `448f735d8abf3ee7d1bcc67c3caf700ef68abd0d`、其 WordPress 插件 zip、许可证、compose、测试目录与 GitHub API |
| 官方文档 | docs.chattermate.chat 的 Markdown 页，以及 README 里与源码一致的句子 |
| 官方宣称 | 产品站价目、隐私页、README 营销句、发布说明。未注册账号，未实走托管控制台 |
| 无法确认 | 私有 submodule 内容、未登录的托管行为、未运行的测试与镜像内部 |

未安装 ChatterMate，未注册 app.chattermate.chat，未向生产或评审站部署，未跑 pytest / Playwright。营销对比文（对 Intercom / Chatwoot / Chatbase）不作为架构事实。

## 结论速览

1. 公开仓库 `chattermate/chattermate.chat` 是 Apache-2.0 开源核，不是「README 有、源码没有」的空壳。知识库、挂件、人工接管、收件箱、渠道适配器、角色和测试都在这个提交里。最新发布是 **v1.0.27**（2026-10-19）；`main` 在 2026-10-26 仍有提交，比已发布镜像新。
2. 企业前后端是私有 git submodule，公开克隆是空目录。社区版在缺模块时关闭计费门禁，公开代码里的功能默认放行。不能审计企业模块里还有什么。Docker Hub 上的 `chattermate/backend:latest` 明确不检出企业模块，时间戳与 v1.0.27 同日。
3. 自托管时，知识原文分块和向量在自己的 PostgreSQL（pgvector，schema `ai`）。嵌入默认在本机用 FastEmbed，不把嵌入密钥交给 ChatterMate。聊天模型是自带密钥；要连推理也不离开自己的机器，必须再配 OpenAI 兼容端点。对话正文和模型密钥在库内加密；知识分块正文是明文。
4. WordPress 插件 1.0.0 只在页脚插入挂件脚本。默认指向 `https://app.chattermate.chat/webclient/chattermate.min.js`。自托管可改 Script URL。它不保存消息，不保存知识，卸载只删自己的 option。WordPress.org 目录没有这个插件。
5. 相对 Knocket：网站挂件、统一收件箱、人工接管、自带聊天模型、知识增删改，公开源码里都有，而且爬取深度和文件大小不受 10/10/10 约束。没有「把线程交回 AI」、没有短语库立即转人工、没有联系页、没有挂件主页上的社交/预约外链、没有对话批量导出 API。知识上传只有 PDF 和粘贴文本，没有 DOC/DOCX/HTML/MD/CSV/XLSX 入库。
6. 能否少量整合：能，但是「旁边跑一套 Docker 栈，WordPress 只嵌挂件」，不是「装进现有 Hostinger 共享主机上的 WordPress」。当前站点没有 Redis，也不是跑 FastAPI + Postgres + worker 的地方。托管默认脚本则把知识和对话留在对方云上，和「存储自己控制」冲突。许可证本身不是商用自托管的硬伤；私有企业模块和托管数据驻留才是控制边界。

## 1. 身份、版本、维护

官方仓库：https://github.com/chattermate/chattermate.chat  
产品站：https://chattermate.chat  
文档：https://docs.chattermate.chat  
创建：2025-02-01。GitHub API 2026-10-26：公开、未归档、Apache-2.0、默认分支 `main`、约 148 star、60 fork、语言以 Python 为主。星数不作为可用结论。

| 项 | 值 | 等级 |
| --- | --- | --- |
| 本次核对的提交 | `448f735d8abf3ee7d1bcc67c3caf700ef68abd0d`，2026-10-26 07:42 UTC，作者名 Runix，「An organization's website is required but no longer unique」 | 源码核对 |
| 最新发布 | `v1.0.27`，2026-10-19 08:25 UTC，标签指向 `7f59c0a60a99` | 源码核对 |
| 已发布镜像 | Docker Hub `chattermate/backend` 的 `latest` 与 `1.0.27` 同为 2026-10-19 08:54 UTC，摘要前缀 `sha256:a941eab773df2`。仓库共 12 个标签 | 源码核对（Hub API） |
| 提交规模 | API 分页最后一页为 1345。贡献者：`chattermate` 1292、`cdmx-in` 49，其余三人各 1–2 | 源码核对 |
| 未关闭的非 PR 议题 | #360 E2E nightly failed（2026-10-13）、#323 Slack 线程上下文、#291 线索捕获不触发、#201 开发文档 | 源码核对 |

v1.0.27 发布说明的主项是挂件身份漏洞 [GHSA-rfh4-6ghv-m2rr](https://github.com/chattermate/chattermate.chat/security/advisories/GHSA-rfh4-6ghv-m2rr)：默认不开启 token 认证时，访客输入已有客户邮箱就能拿到该客户未关闭对话的令牌。修复是令牌绑定发起浏览器的密钥。发布说明同时写了 WhatsApp / Messenger / Instagram / Telegram 的交互消息，以及端到端测试。本次未复现该漏洞，也未下载托管站的 `chattermate.min.js` 核对它是否已是 1.0.27。

`main` 在 v1.0.27 之后还有挂件过期令牌重连、CSP 回退、模型调用期间释放数据库连接等提交。`latest` 镜像不是今天的 `main`。自托管若拉 `latest`，拿到的是 2026-10-19 的 OSS 镜像，不是 `448f735`。

维护是活跃的，也是集中的。公开提交几乎都在 `chattermate` 这个账号下，提交作者名是 Runix。一个人或一个组织账号停更，没有第二维护者能接。这是维护风险，不是许可证禁令。

## 2. 开源与付费边界

### 公开核

根目录 `LICENSE` 是 Apache License 2.0 全文。`NOTICE` 写 Copyright 2024-2026 ChatterMate。README 末节写明 open-core：公开核 Apache-2.0，含专利授予、不要求开源衍生作品；企业功能和优先支持另签商业协议，联系 contact@chattermate.chat。Apache-2.0 第 3 节的专利授予在许可证正文里，不是 README 自造的句子。

公开代码可以个人和商业使用，包括自托管。没有 copyleft，不要求把 WordPress 侧的接入代码开源。WordPress 插件另发 GPLv2 or later，见第 8 节。两套许可证是分开的：插件不捆绑挂件 JS。

### 私有企业模块

`.gitmodules`：

- `backend/app/enterprise` → `git@github.com-chattermate:chattermate/enterprise_backend.git`
- `frontend/src/modules/enterprise` → `git@github.com-chattermate:chattermate/enterprise_frontend.git`

2026-10-26 用公开 API 请求这两个仓库，均为 404。浅克隆后两个目录是空的。`.github/workflows/publish-images.yml` 写明：打 `v*` 标签或手动触发才推镜像，且「Enterprise submodules are NOT checked out — the published images are OSS-only」。

`backend/app/core/edition.py` 写明：CI 和自托管构建里 `app/enterprise` 为空，所有 `try: import app.enterprise` 落到社区模式。企业模块存在时才会打开计划和订阅门禁。`NO_ENTERPRISE=1` 用来让开发者本机也走社区路径。

`backend/app/services/feature_gate.py`：企业模块缺失时 `feature_allowed` 返回 True，`check_feature_access` 直接返回。企业模块存在时，按订阅计划的功能旗标决定，查不到则失败关闭。前端 `useEnterpriseFeatures.ts` 在模块缺失时渲染空组件，文案是 “Feature not available in open source version”。空模块对应的界面包括注册、订阅、账单、Shopify App Bridge 各页、促销、托管支持聊天按钮、外部应用集成设置、安装向导进度条。

因此：公开源码里已经写出的知识库、挂件、接管、收件箱、工作流、MCP、工单、线索捕获，自托管默认不受价目表限额。价目表是托管站在企业模块打开之后的行为，不能当成自托管上限。

### 托管价目（官方宣称，与源码门禁不是同一层）

2026-10-26 读取 https://chattermate.chat/pricing 。页面写 Free 永久、Base $19/座席/月、Pro $39/座席/月，并写「All plans are open source and self-hostable」。表内数字：

| 计划 | 座席 | 知识源 | 每源子页 | 消息/月 | 保留 |
| --- | --- | --- | --- | --- | --- |
| Free | 1 | 1 | 10 | 300 | 30 天 |
| Base | 5 | 5 | 30 | 1,000 | 90 天 |
| Pro | 10 | 20 | 50 | 10,000 | 1080 天 |

页面把人工接管放在三档都有；把分析、自定义品牌、团队、工作流、角色、自定义模型放在 Base 起；把 AI 工单、线索捕获、主动招呼、API、MCP、评分放在仅 Pro。这些旗标只在企业模块安装时才会执行。公开 `feature_gate.py` 在社区版直接放行。不能用这张表证明自托管镜像缺少接管或知识库。也不能用「open source and self-hostable」证明企业 submodule 的账单、SSO 和 Shopify 嵌入式后台可以自己编译出来——那些文件不在公开树里。

隐私页第 7 节又写：自托管由客户控制保留；Professional 12 个月；Enterprise 自定义。这和价目表的 30/90/1080 天不是同一套数字。两份都是官方页面，互相矛盾，本次不能裁定托管站实际删数据的期限。公开源码里没有按天数清理对话的保留任务（见第 4 节）。

## 3. 部署依赖

自托管不是 WordPress 插件能扛起来的。`docker-compose.yml` 与 `docker-compose.prod.yml` 的服务是：

- Vue 前端（生产镜像由 Nginx 提供）
- FastAPI 后端（生产为 Gunicorn）
- `knowledge_processor`（爬取、PDF、FAQ 队列）
- `ticket_investigator`
- `crm_sync`
- PostgreSQL，镜像从 `postgres:16` 编译安装 pgvector **v0.5.1**（`Dockerfile.postgres`）
- Redis 7

README 的手动安装前提：Python 3.12+、PostgreSQL 14+ 带 vector、可选 Redis、可选 Firebase 推送。后端 `requirements.txt` 固定 `fastapi==0.137.2`、`agno==1.7.6`、`fastembed==0.7.3`。官方 Docker 文档写后端镜像要拉 torch、opencv、onnxruntime，从源码构建建议至少约 15 GB 空闲磁盘；预构建镜像可避开这次构建。本次未构建镜像，15 GB 是官方文档的数字，不是本机测量。

知识嵌入在进程内加载 Hugging Face 模型，compose 把 `HF_HOME` 指到卷。首次索引会从 Hugging Face 下载模型，除非镜像或卷里已经有缓存。这是启动依赖，不是每次问答都把原文送到 Hugging Face。

Redis：`.env.example` 默认 `REDIS_ENABLED=true`。工单调查的实时帧和速率限制用它；文档写没有 Redis 时前端退回轮询。对话自动关闭不靠 Redis，它在 API 进程启动时作为 asyncio 任务跑（`app/main.py`）。

Firebase 只用于处理完成的推送，可选。不配也能索引，只是没有那条手机/浏览器推送。

`chattermate-deploy`（npm）是脚手架 CLI，`chattermate-cli`（pip）是打托管 API 的另一套命令。README 自己警告不要混用。

对当前 Metal Divider 主机：项目规则写明共享主机无 Redis，评审站和生产都是 Hostinger 附加站点。ChatterMate 的运行时是独立的 Docker 栈，不是 mu-plugin，也不是可在共享 PHP 主机上 `pip install` 后由 WordPress 拉起的进程。这是部署事实，不是「不能用 ChatterMate」的许可证结论。若要用，运行时必须在另一台能跑 Docker 的机器上，WordPress 只嵌脚本。

## 4. 数据物理存储

自托管、默认配置（`S3_FILE_STORAGE=false`）时，数据在自己的 Postgres 和本地磁盘，不在 ChatterMate 云。

| 数据 | 落点 | 等级 |
| --- | --- | --- |
| 知识源登记 | 表 `knowledge`：`source`、`source_type`（database / file / api / website / custom）、`schema`、`table_name`、`organization_id` | 源码核对 |
| 分块原文与向量 | 每组织一张 pgvector 表，schema 默认 `ai`（`OptimizedPgVector`）。列含 `id`、`content`、`meta_data`、`name`、embedding。分块是互不重叠的原文切片，拼回去等于该页提取文本 | 源码核对 |
| 嵌入 | 进程内 FastEmbed，默认模型 `BAAI/bge-small-en-v1.5`（`FASTEMBED_MODEL`）。向量维度由该模型决定，本次未跑嵌入，未量维度 | 源码核对 |
| 分块大小 | `KNOWLEDGE_CHUNK_SIZE` 默认 1100 字符。嵌入时会在计算向量的文本前拼上一块的尾部，存储的 `content` 不加这段 | 源码核对 |
| 爬取上限 | 自托管 `KB_MAX_LINKS` 默认 25、`KB_MAX_DEPTH` 默认 5、单源时限 1800 秒。范围 `path` / `host` / `domain`。企业模块存在时子页上限改读计划的 `max_sub_pages` | 源码核对 |
| PDF | 上传上限 25 MB，页数默认 300（`KB_PDF_MAX_PAGES`）。本地上传先写入 `temp/`，处理结束后 `os.remove` 删掉原件。S3 打开时原件留在桶里，本地路径不以 `http` 开头才会删 | 源码核对 |
| 对话正文 | 表 `chat_history.message` 为 `EncryptedText`。密钥是 Fernet，经 `ENCRYPTION_KEY`。丢失密钥则旧对话不可读 | 源码核对 |
| 模型密钥 | `ai_configs.encrypted_api_key` | 源码核对 |
| 会话附件 | 默认本地 `uploads` 卷；`S3_FILE_STORAGE=true` 时走 S3，预签名默认 3600 秒。允许的对话附件含 PDF、DOC、DOCX、TXT、CSV、XLS、XLSX 和图片。这些是聊天附件，不是知识库入库格式 | 源码核对 |
| 缓存与实时 | Redis。不存知识原文 | 源码核对 |

知识分块的 `content` 在读取接口里按明文 SELECT，没有经过 `EncryptedText`。对话和密钥加密，知识正文不加密。谁能连上 Postgres，谁就能读知识原文。这和「密钥加密」不是同一层。

删除：`DELETE /knowledge/{id}` 调用 `delete_with_data`，先删关联，再按 `name = source` 删向量行，再删登记行。向量删除失败会记日志并继续删登记。因此「删了源就一定没有向量」在失败分支不成立，可能留下孤儿行。页面级删除接口另有 `DELETE /{knowledge_id}/page/{page_id}`。本次未跑删除。

读回：`GET /{knowledge_id}/content` 返回该源全部分块的 `content` 与 metadata。这是 Knocket 已核对界面没有的能力。它不是文件下载按钮；自托管要拿走原文，可以调这个接口，或直接查 pgvector 表。没有名为 export 的对话或知识批量导出路由（在 `backend/app/api` 搜索 `export` 无匹配）。

本地 PDF 原件在索引后被删除。能拿回的是提取文本，不是上传的那份 PDF 字节，除非打开了 S3 且没有另外删桶对象。扫描件走 `PDFReader` 失败后的 `PDFImageReader`；`requirements.txt` 有 `rapidocr-onnxruntime==1.4.4`，但业务代码没有直接 import RapidOCR，OCR 发生在 agno 的 `PDFImageReader` 里。本次未用扫描件验证。

托管部署的物理位置：隐私页写数据在「hosting sub-processor」，具体名单要向 support@chattermate.chat 索取。没有区域、没有库产品名。自托管不走他们的托管和托管模型子处理者，这句话在隐私页第 4 节。使用默认挂件脚本就是托管路径。

公开源码没有按 30/90/1080 天删除对话的任务。自动关闭只把「仍由 AI 处理、超过 1 天无新消息」的会话标为 CLOSED（`auto_close_inactive_agent_chats`），不删行。人工接管后的会话（`user_id` 非空）不在这条清理里。

## 5. 知识库：源码里有什么

入口在 `backend/app/api/knowledge.py`，处理在 `knowledge_processor`。官方文档与源码一致的输入种类：

| 输入 | 源码 | 和 Knocket 的差别 |
| --- | --- | --- |
| 网站 | `POST /add/urls` 的 `websites`。必须 `https://`。可指定 `max_links` 与 `crawl_scope`。默认不是单页；单页要自己把 `max_links` 设为 1 | Knocket 截图是单页列表、每类 10 条。这里默认可跟链接，自托管默认每源 25 页，环境变量可改 |
| Sitemap | 同一接口的 `sitemaps`，含 sitemap index | Knocket 没有 |
| PDF | `POST /upload/pdf`，每请求最多 20 个，25 MB，魔数校验，页数上限 | Knocket 是八种格式、10 MB、每类 10 个。这里格式更窄、体积更大、没有 10 个上限 |
| 粘贴文本 | `POST /add/text` | 对应 Knocket 的手写 FAQ 的一部分，但不是单独的 FAQ 条目类型 |
| 子页手补 | `POST /{id}/subpage` | Knocket 没有 |

没有 DOC、DOCX、HTML、MD、CSV、XLSX 的知识上传路由。这些格式若在公网页面上，只能指望爬虫抽到的文本，不是文件解析器。对话里可以传 DOC/XLSX，那不会进入向量表。

爬虫会跳过登录墙路径（`login_wall.py`），并用容器选择尽量留下正文而不是页眉（v1.0.25、v1.0.26 的发布说明描述了先前「只索引第一屏」的缺陷和约 1100 字分块的修复）。发布说明是官方宣称加已合并代码；本次未对一个真实站点爬取。

索引后可以读、改、删单块或整页，保存会重新嵌入（`PUT .../chunk`、`PUT .../page`）。同一源可链到多个 agent（`POST /link`）。这是「原文在自己库里、改完索引跟着变」的机制，不是第二份独立维护的商品目录。

它不知道 WooCommerce。全库搜索 `woocommerce` / `WooCommerce` 无匹配。Shopify 订单/商品工具在公开 `backend/app/api/shopify.py`，那是 Shopify Admin API，不是本站的商品源。本站若要让图案资料进入检索，公开代码没有现成连接器；可选的现成路径只是爬公开 URL，或把文本/PDF 送进上述接口。那是连接代码，不是 ChatterMate 已有的 WordPress 集成。

Help Center / FAQ 生成是另一条队列，由同一个 knowledge worker 带上，不是 Knocket 那种「挂件主页上的静态 FAQ」或「知识库 FAQ 计数器」。本次不把它算成 Knocket FAQ 的对等物。

## 6. 模型

`AIModelType`：`OPENAI`、`ANTHROPIC`、`DEEPSEEK`、`GOOGLE`、`GOOGLEVERTEX`、`GROQ`、`MISTRAL`、`HUGGINGFACE`、`OLLAMA`、`XAI`、`OPENAI_COMPATIBLE`、`CHATTERMATE`。

`create_model` 对 `CHATTERMATE` 与 `OPENAI` 走同一条 OpenAI 客户端，注释写「own model for enterprise customers」。社区版没有这个托管模型的密钥下发。`OPENAI_COMPATIBLE` 要求 `base_url`，会去掉末尾的 `/chat/completions`。`OLLAMA` 类型不接收 `base_url`，文档让自托管推理走 OpenAI 兼容端点，而不是这个枚举值。官方文档列出的建议模型 ID（gpt-4.1、claude-opus-4-8 等）会过期；源码只保存调用方填写的 `model_name`。

保存配置时会打一条测试请求（官方文档）。密钥加密存储。嵌入不走这些供应商，走本地 FastEmbed。所以「聊天自控」和「嵌入自控」是两件已分开的事：嵌入默认已经在本机；聊天要自控，必须把端点指到自己的机器，并接受官方文档的警告——小模型工具调用弱时，知识检索、转人工、结束会话会失败而不是悄悄降级。

`AGNO_TELEMETRY` 默认不打开。打开才会在每次 agent 运行时向 `api.agno.com` 上报。社区版默认不继承这条上报。本次未抓包确认运行时没有其他外连。

## 7. 挂件

挂件身份是表 `widgets` 的 UUID，绑定一个 agent，可设 `allowed_domains`（空则任意域）。外观不在这张表，在 `agent_customizations`：颜色、字体、`custom_css`、气泡位置、欢迎标题/副标题/首条消息、快捷按钮、是否收集邮箱、是否显示 AI 声明、是否允许访客新开对话。

加载方式有两条，官方文档与仪表盘代码一致：

```html
<script>window.chattermateId="WIDGET_ID";</script>
<script src="https://app.chattermate.chat/webclient/chattermate.min.js" async></script>
```

自托管把脚本 URL 换成自己的 `/webclient/chattermate.min.js`，并用 `window.chattermateBaseUrl` 或 `chattermateConfig.baseUrl` 指向自己的 API。`.env.example` 写明 `BACKEND_URL` 必须是挂件能够访问的公网地址，否则嵌入后连不回后端。可选 `WIDGET_FRAME_URL` 把挂件帧放到另一主机，避免仪表盘登录 cookie 被挂件源站带上。

JS API 在 `frontend/src/webclient/chattermate.js` 与文档中：`init`、`open`、`close`、`identify`、`logout`、`destroy`、`reload`、`on`。`identify` 用于单页应用登录后换访客，不刷新页面。这是 Knocket 公开脚本里没有的能力。

相对 Knocket 挂件主页，公开模型和文档里没有：开聊前的静态 FAQ 列表、WhatsApp/Instagram/Telegram/Messenger 外链图标、预约链接、按星期的离线表单（姓名/邮箱/电话/留言四件套）。有的是欢迎文案、快捷按钮、可选邮箱、营业时间只影响「谁在回复」那一行文案，以及 AI 关闭或人工接管后的等待状态。移动端文档写小屏全屏打开，没有原生 SDK。没有 `knocket.ai/<slug>` 那种托管联系页；Help Center 是另一产品面。

v1.0.27 之前，默认挂件（不要求 token）有邮箱冒充漏洞。修复在该版本。嵌入到公开站点时，应使用不低于 1.0.27 的镜像或更新的 `main`，并决定要不要打开 token 认证。本次未在浏览器里验证修复。

## 8. WordPress 接入

两处源码，内容同一套：

- 独立仓库 https://github.com/chattermate/chattermate-wordpress-plugin ，GPL-2.0，`pushed_at` 2026-07-15，唯一发布「v1.0.0 — ChatterMate Chat for WordPress」，资产 `chattermate-chat.zip`
- 主仓库 `wordpress/chattermate-chat.zip`，zip 内文件日期 2026-07-15，版本 1.0.0

2026-10-26 查询 WordPress.org 插件 API：`slug=chattermate-chat` 返回 Plugin not found；搜索 “ChatterMate” 结果数为 0。README 写目录提交进行中。`readme.txt` 的 Tested up to 是 6.7。

插件做的事（`chattermate-chat.php`）：

- 设置页两个字段：Widget ID、Script URL。
- Script URL 默认 `https://app.chattermate.chat/webclient/chattermate.min.js`。说明文字写明自托管才改。
- `wp_footer` 打印 `window.chattermateId` 和脚本标签。Widget ID 为空则不输出。
- 不在管理后台输出。

`uninstall.php` 只 `delete_option('chattermate_options')`，多站点逐站删。不删对话，因为插件从未保存对话。

`readme.txt` 的 External services 写：默认脚本来自 app.chattermate.chat，挂件再与 `https://api.chattermate.chat` 收发消息；自托管则改为自己的 Script URL。它没有写自托管时还要改 `chattermateBaseUrl`。只改 Script URL、不改 API 基址，加载的是自己的 JS，请求仍可能打到脚本内写死的云地址。文档把 `baseUrl` 标成自托管必填项。这个缺口是集成时必须自己补的一行配置，插件设置页没有这个字段。本次未把插件装进 WordPress 实测。

没有 WooCommerce 商品同步、没有询盘表单、没有把对话写入 `wp_posts` 或自有表。和 Knocket 官方插件同一形态：WordPress 只 enqueue 远程脚本。差别是 Script URL 允许指向自己的实例，Knocket 插件把 URL 锁在 `trtc.io`。

## 9. 人工接管与对话后台

公开源码里的接管不是文案占位。

触发（`chat_agent.py`）：`transfer_to_human` 为真时，系统提示词要求模型在答不上、访客明确要人、表现出不满、需要人工判断、问题复杂、或要做模型做不到的动作时，把结构化字段 `transfer_to_human` 设为真。没有多语言短语表，没有「命中短语立即转、不等模型」的代码路径。官方文档警告：结构化输出在 OpenAI / Anthropic 上最稳，小模型和自建端点可能漏掉转人工。这是行为依赖模型，不是 Knocket 那种短语立即转。

人工侧：

- `POST /{session_id}/takeover`：把会话记到当前用户，`user_id` 已有则失败。
- `POST /{session_id}/route-to-human`：停掉 AI，进入队列，不派给点击的人。v1.0.20 发布说明称界面按钮为 “Hand to my team”。
- `POST /{session_id}/reassign`：转给另一用户。
- 关闭会话、按状态过滤、角色权限（`view_all_chats`、`view_assigned_chats`、`view_unassigned_chats`、`manage_all_chats`）。
- 组（`groups`）用于路由。营业时间在 `business_hours.py`，转接文案会区分班内有人和班外无人。
- `ai_replies_enabled` 关闭时，该 agent 从第一条消息起就不由 AI 回答。

AI 继续回答的条件在 `widget_chat.py`：会话 OPEN 且 `user_id` 为空。接管后 `user_id` 被写上。在 `backend/app` 里搜索把会话 `user_id` 设回空、从而交回 AI 的产品代码，没有匹配。前端也没有 “Resume AI” / “交回 AI”。Knocket 教程里的 Resume AI，这里没有对等接口。关闭后再开是 `reopen_closed_session`，不是把人工会话还给模型。

收件箱标签（官方文档，与 v1.0.20 发布说明一致）：AI、Waiting for human、You、同事姓名。过滤有 agent、用户、客户邮箱、日期。实时用 Socket.IO。

渠道适配器在公开 `backend/app/channels/`，注册表导入 Telegram、WhatsApp、Messenger、Instagram、Slack、Email、SMS、LINE。README 写这些适配器都在开源树里，自托管可用；WhatsApp/Messenger/Instagram 要自己的 Meta 应用，Telegram 贴 Bot token，邮件贴邮箱凭据。这比 Knocket 已核对的「Telegram 与 Email 进收件箱，WhatsApp 等只是外链」更宽。本次未接任何一个渠道，未验证 Meta 审核或 24 小时会话窗。

自动关闭：API 启动时跑 `chat_auto_closer`，只关闭仍由 AI 处理、最后一条消息早于 24 小时的 OPEN 会话，原因写成 ISSUE_RESOLVED。不删除消息。

通知：Firebase 可选；渠道侧有自己的投递状态。没有核对浏览器推送是否不配 Firebase 也能用。

工单、工作流、Jira、MCP、只读 SQL 调查在公开树里，且社区版门禁放行。它们超出 Knocket 已核对的控制台，不是复现 Knocket 的必选项。SQL 连接器在源码里有 AST 限制（只读 SELECT、表允许列表、强制 LIMIT）。本次未连库验证。

## 10. 测试与源码完整度

不是只有 README。

| 套件 | 本次看到的规模 | 是否执行 |
| --- | --- | --- |
| 后端 pytest | `backend/tests` 下 242 个 `test_*.py`，约 3550 个 `test_` 函数。CI（`.github/workflows/test.yml`）在 PR 与 push main 时跑，`--cov-fail-under=65` | 未跑 |
| 前端 Vitest | 96 个 `*.spec.ts`，CI 同时跑 `npm run type-check` | 未跑 |
| Playwright e2e | `e2e/specs` 57 个文件，覆盖挂件、接管、知识生命周期、PDF、邮件、Shopify、Jira、工作流、权限。用假 LLM 和一次性库。夜间还有多浏览器和 schema 升级任务 | 未跑 |

知识、挂件、接管不是未测试的空目录：存在 `tests/knowledge/`、`tests/api/test_knowledge.py`、`e2e/specs/knowledge/`、`e2e/specs/handover/`、`e2e/specs/widget/`。覆盖率门槛是 65%，不是证明每条路径都对。议题 #360「E2E nightly failed」自 2026-10-13 仍开着，所以不能把「有 e2e」说成「夜间套件当前是绿的」。

企业模块不在这次克隆里，其测试也不在。社区版行为以 `NO_ENTERPRISE` 和 import 失败分支为准，有 `tests/core/test_edition.py`。

## 11. 与 Knocket 使用方式的对照

| Knocket 已核对能力 | ChatterMate 公开代码 | 判断 |
| --- | --- | --- |
| 网站气泡，脚本只带 identifier | 有。脚本带 widget id；自托管还要 API 基址 | 已有。接入是改 URL，不是重写挂件 |
| 挂件主页：欢迎、静态 FAQ、社交外链、预约链接 | 有欢迎文案和快捷按钮。无静态 FAQ、无社交图标、无预约字段 | 缺主页那三块。快捷按钮可近似「开始某句提问」，不是外链 |
| 离线时段与留言表单 | 有营业时间和转接文案。无 Knocket 那种班次表单（姓名/邮箱/电话/留言，关/开聊前/仅离线） | 部分有。留言表单要另做或用线索捕获，线索捕获是对话里收集，不是开聊前表单 |
| 联系页 `knocket.ai/<slug>` | 无对等托管联系页。Help Center 是文档站 | 缺。若使用方式依赖联系页，要另一表面 |
| 移动 WebView | 文档写小屏全屏。无原生 SDK | 与 Knocket「没有原生 SDK」同级，未实装 App |
| 统一收件箱 | 有。网页挂件与已注册渠道进同一会话模型 | 已有，且渠道比 Knocket 已核对的更宽 |
| Telegram、Email 进收件箱 | 适配器在公开树 | 已有代码。未实接 |
| WhatsApp 等进收件箱 | 公开适配器有，要自己的 Meta 应用 | Knocket 教程是外链。这里是真适配器，但是另一套凭据工程，不是开箱 |
| 自带 OpenAI 兼容聊天模型 | 有，密钥加密存在自己的库 | 已有。托管版的「自定义模型」价目旗标不约束社区版 |
| 嵌入由平台管 | 默认本地 FastEmbed，模型 ID 可改环境变量 | 自托管已把嵌入拿回来。仍会下载该模型权重 |
| FAQ / URL / 文件各 10，文件 10 MB | 无 10 条上限。URL 可爬。文件知识只有 PDF 25 MB / 300 页，另加粘贴文本 | 上限更松，格式更窄。不要把八种办公文件当成已支持 |
| 短语立即转人工，模型判断转人工，Resume AI | 只有模型判断（加人工按钮接管、交给队列、改派）。无短语库，无交回 AI | 接管有，策略和回交没有 |
| 用量页 | 分析路由在公开代码，托管价目把分析放在 Base。社区版门禁放行 | 自托管可用与否以社区门禁为准；本次未打开分析页 |
| 导出对话与知识 | 知识可经 content 接口或 SQL 读回分块文本。无对话 export 路由。本地 PDF 原件索引后删除 | 知识文本可取回；对话导出和 PDF 原件不是现成功能 |
| 站长 API | 有 REST，`/docs` 由 FastAPI 提供。托管价目把 API Access 放在 Pro | 自托管公开代码有 API。托管免费档有没有，未登录不能证明 |
| WordPress 只插脚本 | 同样只插脚本，但 Script URL 可指向自己的实例 | 插件不能成为资料主人。自托管时插件可用，还要自己设 API 基址 |

不应误当成 ChatterMate 已经复现的东西：Knocket 英雄图里的 Analytics/Automations 插画、WhatsApp 外链冒充收件、`window.knocketConfig`。那些在 Knocket 研究里已经排除。

## 12. 能否直接或少量整合

可以直接用的，是自托管整栈，不是 WordPress 插件本身。

少量整合若指「不从零写客服」：

1. 在一台能跑 Docker 的机器上用 OSS 镜像或源码把 Postgres、Redis、后端、知识 worker、前端跑起来。密钥、`ENCRYPTION_KEY`、`BACKEND_URL` 自己保管。
2. WordPress 安装现成插件，Widget ID 填仪表盘里的 id，Script URL 指向自己的 `chattermate.min.js`，并在页面上设置 `chattermateBaseUrl`（插件没有这个字段，要自己加一行，或改插件设置）。这是接入，不是新客服系统。
3. 知识：公开页用 URL/sitemap 爬，或粘贴文本，或上传 PDF。图案资料若只存在 WooCommerce，没有现成同步器。爬公开 PDP 是使用现成爬虫，不是 Woo 连接器；登录后的草稿、下架商品、ACF 字段不会被这个爬虫看见。
4. 人工接管用现成收件箱。通知若要浏览器推送，要自己的 Firebase，或者接受没有那条推送。

这样知识原文、向量、对话、密钥都在自己的 Postgres，不在 Knocket/腾讯云，也不在 ChatterMate 云。嵌入默认也不离开这台机器。聊天推理只有在选用 OpenAI 兼容的本机端点时才不离开。

不能算少量整合、本次源码里没有的：

- 把 ChatterMate 收成 Hostinger 共享主机上的一个 WordPress 插件。
- 把对话和知识写入 WooCommerce，让 Woo 成为对话的唯一资料源。公开代码的资料源是它自己的 Postgres。
- 短语级立即转人工、交回 AI、联系页、挂件社交/预约主页、DOCX/表格知识入库、对话一键导出。这些要补代码，或接受不做。
- 使用 app.chattermate.chat 默认脚本。那是托管，存储不在自己这边。

## 13. 托管资源与许可证硬伤

没有「Apache-2.0 禁止商用自托管」这种硬伤。公开核允许商用、允许不公开衍生作品。专利授予在许可证第 3 节，以遵守许可证为前提。

硬边界是这些：

- **私有企业模块不可构建。** 要账单、托管注册、Shopify 嵌入式 App Bridge 界面、价目旗标的强制执行，公开仓库给不了。404 的 submodule 不能当已审计代码。
- **托管默认路径不满足存储自控。** 插件默认脚本和文档 iframe 示例指向 `app.chattermate.chat` / `api.chattermate.chat`。隐私页不公布区域和子处理者名单。价目与隐私的保留期限互相矛盾。
- **对话无导出 API，知识 PDF 原件默认不保留。** 自托管可以用 SQL 读 `chat_history`（需 `ENCRYPTION_KEY`）和向量表。这是运维能力，不是产品里的导出按钮。删源时向量删除失败仍会删登记，孤儿行要自己清。
- **运行时不能塞进当前共享主机。** 这是环境约束。换一台机器可以绕过，换许可证不能绕过企业模块缺失。
- **WordPress.org 没有官方插件。** 只能侧载 zip。zip 停在 2026-07-15，但安全修复在挂件 JS 不在插件里；风险是自托管时加载了旧 JS，或托管脚本尚未升级。后者本次未核对。

不是硬伤、但是使用条件：

- 聊天模型密钥和推理默认打到外部供应商，除非改 OpenAI 兼容端点。
- 首次嵌入要能访问 Hugging Face 下载 `BAAI/bge-small-en-v1.5`，或事先放进缓存卷。
- 转人工质量依赖模型的结构化输出。官方文档把这一点写成警告，不是保证。
- 维护集中在一个账号。v1.0.27 的安全发布说明是认真的，不能据此假定未来漏洞也会在几天内修。

## 14. 无法确认

- 企业 submodule 里除账单和 Shopify 嵌入式界面之外还有哪些公开代码没有的功能。
- 今天注册托管账号是否仍免信用卡、Free 档是否真能打开接管、消息用尽后是否如价目 FAQ 所说「不离线」。
- 托管数据的区域、库产品、子处理者名称、实际删除期限。价目与隐私页冲突。
- 托管站当前的 `chattermate.min.js` 是否包含 GHSA-rfh4-6ghv-m2rr 的修复。
- Docker Hub `1.0.27` 镜像内的 git 提交是否等于标签 `7f59c0a`。时间戳吻合，未解包镜像。
- 扫描 PDF 的 OCR 在 agno `PDFImageReader` 里是否在本依赖组合下成功。
- 删源时向量删除失败的发生率和有没有清扫任务。
- 不配 Firebase 时，人工坐席能否收到可用的新消息通知。
- 自建小模型接上后，转人工字段是否稳定出现。文档警告了，本次未测。
- 插件只改 Script URL、不设 `chattermateBaseUrl` 时，自托管挂件是否仍请求云 API。文档要求设基址；未装插件验证。
- 夜间 e2e 失败的具体用例。议题开着，未读日志。

## 15. 来源

源码与仓库（2026-10-26 克隆或 API）：

- https://github.com/chattermate/chattermate.chat （`448f735`）
- https://github.com/chattermate/chattermate.chat/blob/main/LICENSE
- https://github.com/chattermate/chattermate.chat/blob/main/README.md
- https://github.com/chattermate/chattermate.chat/blob/main/.gitmodules
- https://github.com/chattermate/chattermate.chat/blob/main/docker-compose.yml
- https://github.com/chattermate/chattermate.chat/blob/main/docker-compose.prod.yml
- https://github.com/chattermate/chattermate.chat/releases/tag/v1.0.27
- https://github.com/chattermate/chattermate.chat/security/advisories/GHSA-rfh4-6ghv-m2rr
- https://github.com/chattermate/chattermate-wordpress-plugin
- 主仓库内 `wordpress/chattermate-chat.zip`（插件 1.0.0，2026-07-15）
- Docker Hub tags API：https://hub.docker.com/v2/repositories/chattermate/backend/tags

官方文档（2026-10-26 读取 Markdown）：

- https://docs.chattermate.chat/features/knowledge-base.md
- https://docs.chattermate.chat/features/chat-management.md
- https://docs.chattermate.chat/features/widget.md
- https://docs.chattermate.chat/features/human-agents.md
- https://docs.chattermate.chat/features/ai-configuration.md
- https://docs.chattermate.chat/deployment/docker.md
- https://docs.chattermate.chat/deployment/self-hosted-models.md

官方宣称，未实走：

- https://chattermate.chat/pricing
- https://chattermate.chat/privacy
- https://chattermate.chat/ （首页「Free for your first 300 chats」与价目表同一口径）

目录查询：

- WordPress.org 插件 API，`slug=chattermate-chat`，2026-10-26 返回 Plugin not found

未采用为架构证据：chattermate.chat 上对 Chatwoot / Intercom / Chatbase 的对比文、星数、首页营销句「open source forever」在未对照 `feature_gate.py` 之前。
