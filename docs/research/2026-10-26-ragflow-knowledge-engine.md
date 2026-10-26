# RAGFlow 作为自控知识检索与问答引擎

- 票：[研究：RAGFlow 作为自控知识检索与问答引擎](https://github.com/AssassinFlash/metal-divider/issues/96)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 对照基线：[Knocket 研究](https://github.com/AssassinFlash/metal-divider/issues/87)，文档在 `research/knocket-capabilities`
- 日期：2026-10-26
- 分支：`research/ragflow-knowledge-engine`（从 `origin/main` 的 `9da89ab` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-96-ragflow`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树；不删除远程分支，不删除本文件

本文件只回答 RAGFlow 能不能作为自控知识库的解析、索引、检索和问答引擎，以及它离 Knocket 的使用方式还差什么。不决定 AI 与询盘、资料公开范围或人工流程的关系，也不替其他票评估 Chatwoot、Dify、FastGPT 或 MaxKB。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 源码核对 | 2026-10-26 从 GitHub 读到的仓库文件。推荐部署标签是 `v1.0.0-rc2`（`ac29b4b29928ca65d3efca19b6cfae3f86069b81`）。文中写明读的是该标签还是当天 `main` |
| 官方文档 | 仓库 `docs/` 或当天打开的 [ragflow.io/docs](https://ragflow.io/docs/dev/) |
| 官方发布说明 | GitHub Release 正文 |
| 合理推断 | 由上述材料推出、但没有跑起来的判断 |
| 无法确认 | 没读到、页面打不开，或两份材料互相矛盾且没有实跑 |

没有启动容器，没有注册 [cloud.ragflow.io](https://cloud.ragflow.io/)，没有上传文件，没有调用活的 API。星数不作为能否上生产的依据。二手评测里的云价格互相矛盾，且仍按 Redis / 0.27.x 描述架构，不采用。

## 结论速览

1. RAGFlow 是 InfiniFlow 的 Apache-2.0 知识引擎，不是客服收件箱。它已经能做大知识库的导入、模板解析、切块、混合检索、带引用的回答、HTTP API、OpenAI 兼容补全和 MCP。这些能力超过 Knocket 的 FAQ / URL / 文件各 10 条。
2. 它不能单独复现 Knocket 的使用方式。已核对的通道和聊天文档没有统一收件箱、人工接管、营业时间、离线留言、访客气表单或邮件回复通道。网站嵌入是 iframe，不是一行挂件脚本。
3. 仓库内没有 WordPress 插件，也没有 Chatwoot 连接器。2026-10-26 查 WordPress.org 插件目录，`ragflow` 结果为 0。接入要另写少量代码，或让另一个客服底座去调它的 API。
4. 许可证不是硬伤。Apache-2.0，仓库文档把 “Enterprise Edition” 明确写成外部系统的账号版本，不是 RAGFlow 自己的付费档。没有读到把检索锁进闭源模块的条款。
5. 部署是硬伤，如果要求它和现有 WordPress 同机。官方起步是 4 核、16 GB 内存、50 GB 磁盘、Docker Compose，默认还要 Elasticsearch、MySQL、MinIO、Kvrocks、NATS。这进不了当前 Hostinger 共享主机。官方 Cloud 页面本次打不开，不能把托管价写成事实，也不能把 Cloud 当成“数据自己掌握”。
6. 官方快速开始指向 `v1.0.0-rc2`，它仍是候选版。同一周还发布了 Python 线的 `v0.27.3`。从 0.27.2 升到 1.0 不可逆。未实跑，不能把它写成已验收的生产版本。

组合价值：RAGFlow 适合做知识原文、解析、索引和带引用的回答；客服窗口、收件箱和人工接管要另一个项目；WordPress 只做嵌入或服务端代理。不要用它的 WhatsApp 扫码通道代替官方 Business API，也不要把它装进站点所在的共享主机。

## 1. 身份、版本与维护

官方仓库是 [infiniflow/ragflow](https://github.com/infiniflow/ragflow)。2026-10-26 的 GitHub API：创建于 2023-12-12，未归档，许可证 `Apache-2.0`，主页 `https://ragflow.io`，默认分支 `main`。当天 `main` 头是 `3e313daf6ffdcb08e8501f31429a85bf0b4becfd`（2026-10-26T09:34:59Z，`fix(ingestion): support legacy .xls BIFF8 as tag source`）。开放 issue 约 1339。这些数字只说明仓库活着，不说明本站能直接上。

两条发布线同时存在：

| 标签 | 提交 | 发布日期 | 怎么读 |
| --- | --- | --- | --- |
| `v1.0.0-rc2` | `ac29b4b29928ca65d3efca19b6cfae3f86069b81` | 2026-10-16 | 官方快速开始让用户检出的 Go 重写候选版 |
| `v1.0.0-rc1` | 见 [Release](https://github.com/infiniflow/ragflow/releases/tag/v1.0.0-rc1) | 2026-09-29 | Go 重写第一候选。发布说明写明从 v0.27.2 升级不可逆 |
| `v0.27.3` | `8090ffd8ba6098813d7f7ec2c4b8774f36c8423a` | 2026-10-23 | 非 RC 标签，晚于 rc2。Python 线仍在收修复 |
| `nightly` | 发布说明指向 `176e91d7c26c479ffcb2fb305743bb64bdf43250` | 标签页显示预发布 | 不是可固定的生产版本 |

当天打开的 [快速开始](https://ragflow.io/docs/dev/) 仍写 `git checkout v1.0.0-rc2`。仓库 `main` 的 README 也指向这个标签，并写 macOS 目前不在 Go Docker 镜像的支持范围内（[README 本地部署节](https://github.com/infiniflow/ragflow/blob/main/README.md)）。

`v1.0.0-rc1` 发布说明里的架构变化：消息队列从 Redis 改为 NATS；缓存和检查点改为 Kvrocks。已知问题包括 DeepDoc 只支持 CPU、本地 sandbox 不再支持、当时还没有 Team/Me 权限。`v1.0.0-rc2` 发布说明称修好了 rc1 的若干问题，并加入 Team/Me、Agentic 模式、arm64 镜像声明。这些是发布说明，不是本次实跑。

当天的在线快速开始又写：“supported native Docker build target is Linux x86-64. Linux ARM64 is not currently a supported native target”。这和 rc2 发布说明里的 arm64 镜像声明互相矛盾。`docker-compose-base.yml` 里 Infinity 镜像钉的是 `infiniflow/infinity:v0.7.4-x64-v3`，文件名本身是 x64。默认文档引擎是 Elasticsearch，不是 Infinity。ARM 能不能整栈跑起来，本次无法确认。

许可证文件是 [Apache License 2.0](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/LICENSE)。源文件头也写 Apache-2.0。这允许自托管、修改和商用，不要求衍生作品开源。没有另读到 RAGFlow 专有的字段级商业限制。

## 2. 部署依赖与数据落点

### 官方起步配置

[快速开始](https://ragflow.io/docs/dev/) 和仓库 [docs/quickstart.mdx](https://github.com/infiniflow/ragflow/blob/main/docs/quickstart.mdx) 写的起步配置是 4 CPU、16 GB 内存、50 GB 可用磁盘，Docker ≥ 24.0.0，Compose ≥ v2.26.1。它同时写明实际占用取决于文档引擎、数据量、解析任务和并发；本地模型和 OceanBase 还要更多资源。16 GB 是官方起步建议，不是本站语料的实测峰值。

[配置文档](https://ragflow.io/docs/dev/configurations) 把 `MEM_LIMIT` 默认值写成 `8073741824` 字节，约 7.52 GiB。这是每个套用该上限的容器的上限，不是整机最低内存，也不是每个容器都会吃满。`v1.0.0-rc2` 的 [docker-compose-base.yml](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/docker/docker-compose-base.yml) 对 Elasticsearch 套了这个上限。RAGFlow 自己的 `ragflow-cpu` 服务在同标签的 [docker-compose.yml](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/docker/docker-compose.yml) 里没有这条内存上限。

默认 Elasticsearch 还要求宿主机 `vm.max_map_count` 至少 262144。改用 Infinity 时，官方 [切换文档](https://github.com/infiniflow/ragflow/blob/main/docs/develop/switch_doc_engine.md) 说这一步通常不需要，但切换会新建索引，文档必须重索引。

### 默认会起来的服务

`v1.0.0-rc2` 的 `docker/.env` 默认 `DOC_ENGINE=elasticsearch`、`DB_TYPE=mysql`、`RAGFLOW_IMAGE=infiniflow/ragflow:v1.0.0-rc2`。按 Compose profile，默认栈是：

| 部件 | 镜像或实现 | 存什么 | 等级 |
| --- | --- | --- | --- |
| RAGFlow Go 进程 | `infiniflow/ragflow:v1.0.0-rc2`，服务名 `ragflow-cpu` | API、摄入、同步、内置 Nginx | 源码核对 |
| 全文与向量 | Elasticsearch `8.11.3`，可换成 Infinity | 切块、全文、向量 | 官方文档 + compose |
| 业务元数据 | MySQL `8.0.40`，库名 `rag_flow` | 官方备份表称为元数据 | 源码核对 |
| 原文对象 | MinIO 兼容镜像 `pgsty/silo:RELEASE.2026-08-06T00-00-00Z` | 上传文件。可换成 S3、OSS、Azure Blob、GCS、Tigris | 官方文档 |
| 缓存与检查点 | Apache Kvrocks `2.16.0`，Redis 协议 | Go 服务的缓存。不是消息队列 | 官方文档 |
| 队列 | NATS `2.14.2` JetStream | Go 服务的消息队列 | 官方文档 |
| 公开入口 | Nginx，默认 80 / 443 | 反代。API 口默认 9380，管理口 9381 | 官方文档 |

ClickHouse、Kibana、TEI、Jaeger、OceanBase、OpenSearch、sandbox 都挂在 profile 上，不是默认必起。Go 部署注释写明不再使用 Python 线的 Valkey/Redis 服务。

[v1 备份文档](https://github.com/infiniflow/ragflow/blob/main/docs/administrator/upgrade_and_migration/backup_and_restore_v1.md) 把恢复点分成：`docker_mysql_data`、`docker_minio_data`、`docker_esdata01` 或 `docker_infinity_data`、`docker_kvrocks_data`、`docker_nats_data`，外加 `docker/.env` 和配置模板。日志丢了不影响业务恢复。这意味着自托管时原文、索引和元数据都在操作者的卷里，可以停机打包带走。这是相对 Knocket 的控制点。本次没有做一次备份恢复。

对话正文落在哪张表，本次没有逐表打开。能确认的是业务元数据在 MySQL，原文在对象存储，可检索切块在 Elasticsearch 或 Infinity。不能把 “MySQL 里有元数据” 写成 “每条访客消息都已核对到某张表”。

### 和当前站点的冲突

本仓库 `AGENTS.md` 写明评审站和生产都在现有 Hostinger 共享主机上，共享主机没有 Redis，首期零对象缓存。RAGFlow 的官方路径是多容器 Docker，还要改 `vm.max_map_count`。合理推断：它不能装进当前 WordPress 所在的共享主机。这是同机部署的硬伤，不是许可证硬伤。另开一台 Docker 主机不在本票的购买裁定里，只记资源事实。

`v1.0.0-rc2` 的 `docker/.env` 默认密码是 `infini_rag_flow`（MySQL 与 MinIO）。官方快速开始要求在对网络开放前改掉。`ENABLE_REGISTER` 默认为 `1`。这两项是部署时必须改的默认值，不是功能缺失。

rc1 发布说明称 Go 重写大幅降低资源占用。同一套官方快速开始仍把 16 GB 写成起步配置。发布说明里的 “lightweight” 不能当成已经把主机需求降到共享主机能承受。

## 3. 知识导入、解析、索引与检索

### 输入面

[快速开始](https://ragflow.io/docs/dev/) 列出的上传格式包括 PDF、DOC、DOCX、TXT、MD、MDX、CSV、XLSX、XLS、JPEG、JPG、PNG、TIF、GIF、PPT、PPTX。数据集配置还单列音频（WAV、MP3、AAC、FLAC、OGG）和邮件文件（EML、MSG）。这已经覆盖 Knocket 的 PDF / TXT / DOC / DOCX / HTML / MD / CSV / XLSX，并多出幻灯片、图片和音频。HTML 是否作为独立上传类型，快速开始列表里没有单独写出，不能从 “能解析网页” 推成 “任意 HTML 文件都走同一条上传入口”。

大小限制要拆开，不能写成一个数：

| 路径 | 限制 | 来源 |
| --- | --- | --- |
| 数据集文档上传 | 固定 128 MiB。Go 常量 `maxUploadDocSize = 128 * 1024 * 1024` | [document.go](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/internal/service/document/document.go)，rc2 与当天 main 都有这行。注释写明这是对齐 Python 的默认值；Python 那边可由 `MAX_CONTENT_LENGTH` 覆盖，Go 这里是常量 |
| 文件管理器上传 | 默认 1 GiB（`1073741824` 字节），可用 `MAX_CONTENT_LENGTH` 改 | [FAQ](https://github.com/infiniflow/ragflow/blob/main/docs/faq.mdx)，[docker/.env](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/docker/.env) |
| Agent 里的上传 | 上面两项都不提高这个上限 | 同一 FAQ 与 `.env` 注释 |
| 知识库头像 | 4 MB | [数据集概览](https://github.com/infiniflow/ragflow/blob/main/docs/guides/dataset/dataset_overview.md) |

没有读到 FAQ、URL、文件各 10 条这种产品上限。条数上限本次无法确认；能确认的是没有 Knocket 那种 10 条界面计数器。

外部数据源在 [分类文档](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/docs/guides/data_source/data_source_categories_and_selection.md) 里包括网盘、对象存储、MySQL / PostgreSQL / BigQuery、Git 与工单系统、邮件与 Slack / Teams、以及 REST API、RSS、Sitemap。这里的 “Enterprise Edition” 句子写明指的是外部系统账号，不是 RAGFlow 许可证。邮件连接器是把邮件同步进知识库，不是把 RAGFlow 变成可回复的邮箱。对本站有用的现成入口是本地上传、Sitemap 和 REST / HTTP 文档 API，不是 Confluence 或 Salesforce。

文档要解析出切块才参与检索。可以按文档启用或禁用，不必删除。官方文档写删除文档会去掉它的切块、元数据和解析结果。对象存储里的原件是否同步物理删除，本次没有沿删除代码追到 MinIO 的 `RemoveObject`，不能写成已证实。

下载原文是文档管理和 HTTP API 都有的操作（`GET` 下载文档）。Knocket 已核对界面没有导出。RAGFlow 至少能下载源文件，并能备份整个卷。向量索引没有单独的 “导出向量” 按钮；操作者拿走的是 Elasticsearch 或 Infinity 的卷。

### 解析与切块

[数据集配置](https://github.com/infiniflow/ragflow/blob/main/docs/guides/dataset/configuration.md) 的内置解析方法包括 General、Q&A、Manual、Table、Paper、Book、Laws、Presentation、One、Tag、Audio、Email。PDF 解析可选 DeepDOC、Naive、Docling、OpenDataLoader、TCADP、MonkeyOCRv2，或已配置的外部视觉模型。DeepDOC 做 OCR、表格结构和版面分析。纯文本 PDF 可用 Naive，避免这些步骤。

rc1 / rc2 发布说明和快速开始都写：开源 1.0 的 DeepDoc 布局分析、OCR 和表格识别用 CPU。FAQ 承认因此比 LangChain 慢。这是能力存在、成本在 CPU 时间上的事实，不是 “不能解析扫描件”。本次没有用本站 PDF 计时。

切块结果可以在界面里改、加关键词。检索测试页可调相似度阈值、向量权重、重排模型。测试页的改动不会自动写回 Chat，必须再到 Chat 或 Retrieval Agent 里保存。知识图谱和 RAPTOR / 知识编译会明显加长解析和回答时间；FAQ 建议排障时先关掉。

嵌入模型在第一次解析之后不能改。快速开始把原因写成：同一数据集必须处在同一个嵌入空间。这是操作约束。换模型意味着新建数据集并重解析，不是热切换。

### 检索

HTTP 检索是 `POST /api/v1/retrieval`，Bearer API key。请求体包括 `question`、`dataset_ids`、`document_ids`、相似度阈值、关键词权重、`knn_top_k`、`rerank_id`、元数据条件、跨语言和知识编译范围。文档中的默认值：相似度 `0.2`，关键词权重 `0.7`，`knn_top_k` `1024`，`rerank_candidates_count` `64`，每页 30 条。`knn_num_candidates` 目前只对 Elasticsearch 生效。

混合检索的官方说明：没选重排模型时，关键词相似度加权加向量余弦加权；选了重排模型时，后半段换成重排分。知识图谱切块只用向量相似度。这些是文档中的公式说明，不是本站语料上的质量测量。

另有 Search 应用：多知识库、AI 摘要、相关搜索、思维导图。那是站内搜索面，不是访客客服挂件。

## 4. 模型依赖

RAGFlow 不自带可用的聊天模型。快速开始要求先在 Model providers 里配置 LLM 和嵌入模型，否则没有问答。

[模型文档](https://github.com/infiniflow/ragflow/blob/main/docs/guides/models/llm_api_key_setup.md) 把模型分成 LLM、Embedding、Rerank、VLM、ASR、TTS、OCR。本地 Ollama、vLLM、LM-Studio 允许空 API key。也支持 OpenAI 兼容网关，Base URL 由操作者填写。

`v1.0.0-rc2` 的 [conf/llm_factories.json](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/conf/llm_factories.json) 有 80 个 factory 名。和本站可能相关的名字包括 OpenAI、OpenAI-API-Compatible、DeepSeek、Moonshot、Tongyi-Qianwen、ZHIPU-AI、Gemini、Anthropic、Azure-OpenAI、Bedrock、OpenRouter、Ollama、VLLM、LM-Studio、SiliconFlow、Jina、Voyage AI、HuggingFace、Cohere、Mistral、Groq。目录里有名字不等于该厂商当前账号可用，也不是选型结论。

模型费用不在 RAGFlow 许可证里。自托管的软件费是零；每次解析、嵌入、重排和回答都另计模型调用。本地嵌入可以用可选的 TEI profile，内存另计。没有官方的 “本站每月多少钱” 数字，本次也不编。

聊天侧可以设系统提示词、开场白、空回答、引用开关、多轮改写和跨语言检索。空回答留空时，文档写明模型可能用自身知识继续答，也就是会幻觉。客服场景若要求只据资料作答，必须填写空回答。这是配置，不是默认保证。

## 5. API、嵌入与会话

API key 在用户设置的 API 页生成（[取得密钥](https://github.com/infiniflow/ragflow/blob/main/docs/develop/acquire_ragflow_api_key.md)）。管理接口用 `Authorization: Bearer <API_KEY>`。

已在 [HTTP API 参考](https://github.com/infiniflow/ragflow/blob/main/docs/references/http_api_reference.md) 读到的、和本票相关的面：

| 能力 | 方法与路径 | 备注 |
| --- | --- | --- |
| 健康检查 | `GET /api/v1/system/healthz` | 快速开始用它判断就绪。容器自身没有 Compose healthcheck |
| 数据集 | `POST/GET/PATCH/DELETE /api/v1/datasets` | 1.0 用 `parse_type` + `parser_id`，不再认旧的 `chunk_method: naive` |
| 上传与解析 | 数据集文档上传、`parse`、停止解析 | 上传上限见上一节 |
| 切块 | 增删改查，以及可用性开关 | 可手工改切块 |
| 检索 | `POST /api/v1/retrieval` | 不生成回答，只回切块 |
| Chat | `POST /api/v1/chats`，会话与完成接口 | 1.0 去掉了 `POST /api/v1/chats/{chat_id}/completions` |
| OpenAI 兼容 | `POST /api/v1/openai/{chat_id}/chat/completions` | 旧路径 `chats_openai` 在 Go 1.0 不存在 |
| Agent OpenAI 兼容 | `POST /api/v1/agents/chat/completions`，body 里带 agent id | 旧的 `agents_openai/{id}` 路径已去掉 |
| MCP | `POST /api/v1/mcp` | 让外部应用调用检索、数据集和聊天列表工具 |

1.0 的路径替换表写在同一份 API 参考的 “Migrate to 1.0” 节。网上 0.2x 示例不能直接拿来接。Python SDK 在 rc1 发布说明里称不受影响；本次没有审计 SDK 源码，集成应以 HTTP 参考为准。

MCP 文档把 RAGFlow 同时写成服务端和客户端：[MCP 概览](https://github.com/infiniflow/ragflow/blob/main/docs/develop/mcp/overview.md)。这是给别的 Agent 调检索，不是给网站访客用的挂件。

### 网站嵌入

官方有两条嵌入说明：

- Chat：会话文档写可以复制 iframe，地址来自 Chat 的嵌入窗口（[Using Chat conversations](https://github.com/infiniflow/ragflow/blob/main/docs/guides/chat/using_chat_sessions.md)）。
- Agent：可选全屏 iframe 或 Intercom 式浮动挂件，并要求先有 API key（[Embed into Web Pages](https://github.com/infiniflow/ragflow/blob/main/docs/guides/agent/embed_into_web_pages.md)）。

源码里的实际代码在 [embed-dialog/index.tsx](https://github.com/infiniflow/ragflow/blob/main/web/src/components/embed-dialog/index.tsx)。浮动挂件指向 `/chats/widget`，全屏 Chat 指向 `/chats/share`，Agent 指向 `/agent/share`。查询参数包括 `shared_id`、`from`、`auth`。`shared_id` 在 Chat 会话页传入的是路由里的 Chat id，不是随机公开令牌（[sessions.tsx](https://github.com/infiniflow/ragflow/blob/main/web/src/pages/next-chats/chat/sessions.tsx)）。`auth` 取的是系统令牌列表第一项的 `beta` 字段。

完成接口是 `POST /api/v1/chatbots/{dialog_id}/completions` 或 agent 对应路径。这些路由挂在 `BetaAuthMiddleware` 上。该中间件只读 `Authorization` 头或名为 OAuth 的 cookie，不读查询参数（[auth.go](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/internal/handler/auth.go)，rc2 已核对）。它依次接受用户令牌、普通 API 令牌、beta 令牌。

会把 `?auth=` 写入本地授权存储的，是根布局 loader（[routes.tsx](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/web/src/routes.tsx)）。Chat 分享页、Agent 分享页和 `/chats/widget` 都是 `layout: false` 的顶层路由，不走这个 loader。分享页发请求时调用 `getAuthorization()`，本次没有找到这些路由把 URL 里的 `auth` 抄进请求头的代码。

因此：官方界面会生成一段 iframe。把这段代码贴进 WordPress 是否真能答问，本次没有实跑，源码路径也没有把它证明为可用。同时，生成的 URL 把 beta 字段放在查询参数里，看网页源码就能复制。beta 路由组覆盖 chatbot、agentbot 和 searchbot。它是不是还能调用数据集管理接口，本次没有把一枚真实令牌打过去，不能下结论。能下的结论是：官方嵌入不是 Knocket 那种只带 identifier 的公开脚本，它把一份租户凭证放进了页面。

浮动挂件的官方片段还把宽度设成 100px、高度 100px，再用 `postMessage` 拉出 380×500 的第二个 iframe。那是界面代码，不是已在本站主题里验证过的挂件。

## 6. 通道、人工与客服底座

[通道概览](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/docs/guides/chatchannel/chat_channels_overview.md) 写明通道只做平台接入和转发。回答内容由绑定的 Chat 决定。已列出的通道：Discord、钉钉、飞书 / Lark、QQ Bot、Telegram、企业微信、WhatsApp。没有网站访客通道、Instagram、Messenger，也没有把入站邮件变成可回复线程的通道。

Telegram 是 BotFather token，和 Knocket 的 Telegram 收件类似，但是答的是 RAGFlow Chat，不是一个有人工座席的收件箱。文档没有营业时间、离线留言或 “转人工” 开关。

WhatsApp 不是 Business Cloud API。`v1.0.0-rc2` 的 [package.json](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/internal/channels/whatsapp-gateway/package.json) 依赖 `baileys` `7.0.0-rc13`。网关 README 写明这是扫码关联设备，登录态落在网关数据目录。`WHATSAPP_GATEWAY_ENABLED` 为空时网关不起（[whatsapp_gateway.go](https://github.com/infiniflow/ragflow/blob/v1.0.0-rc2/internal/channels/whatsapp_gateway.go)）。RAGFlow 文档没有讨论这是否符合 WhatsApp 条款。对本站已定的对客 WhatsApp 号码，这条通道不能当成现成、可上线的官方集成。

仓库代码搜索 `wordpress` 和 `chatwoot` 均为 0。WordPress.org 插件 API 搜索 `ragflow` 返回 0。没有现成的 WordPress 插件或 Chatwoot 连接器可以安装。

和客服底座的现成接法只有协议层，没有产品层：

- 另一个系统若能调用 OpenAI Chat Completions，可以指向 `POST /api/v1/openai/{chat_id}/chat/completions`。密钥留在服务端。
- 另一个系统若只需要检索、自己生成回答，调用 `POST /api/v1/retrieval`。
- 另一个 Agent 若讲 MCP，可以接 `POST /api/v1/mcp`。

Chatwoot 是否已经内置 “自定义 OpenAI 兼容端点” 由 Chatwoot 那张票回答。RAGFlow 侧没有名为 Chatwoot 的连接器，这一点已经核对完。

团队权限存在：资源可设 Only me 或 Team，邀请要接受后才成为成员（[团队权限](https://github.com/infiniflow/ragflow/blob/main/docs/guides/team/permission_system_overview/team_and_resource_permissions.md)）。rc2 发布说明称已支持 Team/Me。这是操作者之间的共享，不是访客与座席的接管。

`internal/harness/agent_handoff.go` 是 Agent 运行时里的确定性转移，不是客服人工接管。已读的通道文档和 bot handler 没有座席、工单、SLA 或 “Transferred to a human agent” 这种访客状态。未启动界面，不能排除某个未写入这些文档的隐藏开关；按已核对材料，人工接管不存在于产品面上。

## 7. 和 Knocket 的对照

Knocket 基线来自已关闭的 [#87](https://github.com/AssassinFlash/metal-divider/issues/87)：三条入口进一个收件箱，知识上限是 FAQ / URL / 文件各 10 条、文件 10 MB，聊天密钥自带，嵌入由平台管，WordPress 插件只插入腾讯云脚本。

| Knocket 能力 | RAGFlow | 还要什么 |
| --- | --- | --- |
| 网站挂件，脚本只带 identifier | 官方 iframe / 浮动挂件。凭证在 URL 里，且分享路由未见把 `auth` 送进请求头 | 少量自有代码：服务端代理或自己画挂件。不能直接贴官方片段就当已验证 |
| 联系页、移动 WebView | 没有托管联系页产品。分享 URL 可以放进 WebView，未验证 | 另一项目或一张 WordPress 页 |
| 统一收件箱 | 没有 | 客服底座 |
| Telegram 收件 | 有 Bot 通道，直接由 Chat 作答 | 若要人工插话，仍要底座 |
| Email 收件并可回复 | 只有把邮件同步进知识库的连接器 | 底座的邮件通道 |
| WhatsApp | Baileys 扫码，默认关闭 | 不用这条。官方号码应走底座或官方 API |
| Instagram / Messenger | 通道列表没有 | 底座，如果还需要 |
| 营业时间、离线留言、访客气表单 | 已读文档没有 | 底座 |
| 人工接管、Resume AI | 没有对应产品面 | 底座 |
| 浏览器推送、座席通知 | 没有 | 底座 |
| 问候语、系统提示词 | 有开场白和系统提示词。没有核对 400 / 2000 字界面上限 | 够用 |
| 自带聊天模型密钥 | 有，且可选多家和 OpenAI 兼容端点 | 够用。密钥在自有实例 |
| 嵌入由平台管 | 相反：嵌入模型和向量在自有实例，操作者必须自己配置 | 这是目标，不是缺口 |
| FAQ / URL / 文件各 10 条，文件 10 MB | 没有这个上限。数据集单文件 128 MiB。URL 可走 Sitemap 或 API，不是 10 个单页槽 | 知识面已经超过基线 |
| 界面无导出 | 可下载原文，可备份卷 | 控制点在自有主机 |
| 引用与 “没有就不编” | 有引用开关和空回答。空回答必须主动填写 | 配置，不是默认 |
| 站长 API | 有完整 HTTP API、OpenAI 兼容和 MCP | 这是集成面 |
| 数据在腾讯云、终止后无交还义务 | 自托管时数据在自己的 MySQL、对象存储和搜索卷 | 前提是不要改用官方 Cloud |

## 8. 开源、付费与托管

自托管软件是 Apache-2.0。数据源文档明确把 Personal / Enterprise Edition 排除在 RAGFlow 许可证之外。本次在仓库 Markdown 里没有找到 “开源版不可用、企业版才开放” 的检索功能门。rc1 去掉的是本地 sandbox，不是把检索改成付费。

[cloud.ragflow.io](https://cloud.ragflow.io/) 在 README 里是官方托管入口。2026-10-26 打开该页只得到网络异常，没有读到价目、区域或数据处理条款。二手文章把起步价写成 29、49 或 59 美元，彼此冲突，不引用为事实。因此：Cloud 的价格、额度和数据驻留无法确认。即便它便宜，它也把原文和索引放回厂商一侧，不符合 “知识输入和存储自己控制”。Cloud 不能作为本路线的底座。

自托管的可报价部分只有资源，没有许可证费：一台至少按 4 核 / 16 GB / 50 GB 起步的 Docker 主机，加上模型 API。Elasticsearch 单容器默认上限已约 7.52 GiB。解析扫描件时 CPU 时间会上去。精确月费取决于主机供应商和模型用量，本次没有代购、也没有用本站语料压测，不写一个美元数字。

## 9. 能否少量整合

能直接用的是知识引擎，不是整条客服链路。

适合直接采用、不必自写的部分：

- 多格式解析、切块可视、手工改切块、混合检索、重排、元数据过滤、引用、空回答、跨语言检索。
- 数据集、文档、切块、检索、Chat、OpenAI 兼容补全和 MCP。
- 原文、索引、元数据和模型密钥留在自有主机，并用卷备份带走。
- 团队内的知识库权限。这解决不了访客接管。

必须另有项目或少量自有代码的部分：

- WordPress 挂件、联系页和主题里的入口。没有官方插件。合理的少量代码是：主题或 mu-plugin 放一个不携带管理密钥的入口，由服务端代理检索或完成接口。不要把 beta 令牌写进公开页面。
- 收件箱、人工接管、通知、营业时间和邮件回复。RAGFlow 通道补不上。
- 若知识来自 WooCommerce，索引只能是 Woo 当前发布状态生成的派生副本。RAGFlow 的数据集不能变成第二份可独立放行的商品目录。这是本仓库既有约束，不是 RAGFlow 的功能。本票不决定哪些资料对访客可见。
- 从 Woo 或页面同步到 RAGFlow 的增量更新、删除后的索引失效。API 有上传、解析和删除，但没有名为 WordPress 的同步器。这是连接代码，不是从零写 RAG。

不建议采用的部分：

- 把 RAGFlow 装进当前 Hostinger 共享主机。
- 把官方 Cloud 当作自控存储。
- 用 Baileys WhatsApp 通道承载对客号码。
- 把 `v1.0.0-rc2` 未经实跑就定成生产版本。它是官方当前推荐标签，也仍是候选版，且离开 0.27.2 的升级不可逆。`v0.27.3` 仍在修，但新文档和快速开始已经离开这条线。选哪条线要在有一台隔离 Docker 主机之后，用真实文档做一次导入和检索，而不是在本票里猜。

## 10. 证据局限

- 没有运行 `v1.0.0-rc2` 或 `v0.27.3`，没有测解析耗时、检索质量和 16 GB 是否够本站文档。
- 嵌入代码生成了，但没有在浏览器里打开 iframe，所以 “贴上就能聊” 未验证；源码反而显示认证路径不完整。
- 删除文档后对象存储和向量是否同事务失效，没有追完删除实现。
- 对话保留期限没有在已读文档里写成一个天数。
- Cloud 价目和区域没有一手页面。
- 在线文档与 rc2 发布说明对 ARM64 的说法冲突。
- 本文件不评估其他开源客服或知识库项目，也不改父地图。
