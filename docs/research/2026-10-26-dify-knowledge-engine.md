# Dify 作为自控知识库与 AI 应答引擎

- 票：[研究：Dify 作为自控知识库与 AI 应答引擎](https://github.com/AssassinFlash/metal-divider/issues/94)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/dify-knowledge-engine`（从 `origin/main` 的 `9da89ab7937f8e4e2db3c989b37f6bcdeab370b4` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-94-dify`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee）
- 回收条件：分支推送成功且票关闭后删除该工作树和 `/tmp/dify-research-94` 可重建下载物；不删除远程分支，不删除本文件

本文件只回答 Dify 自托管版能不能承担大资料量知识导入、索引、检索、API 应答和聊天嵌入，知识与对话存在哪里，模型和嵌入怎么接，Chatwoot 有没有现成闭环，以及开源版边界。不决定它和 Metal Divider 询盘或资料公开范围的关系，也不替其他票评估 Chatwoot、Ragflow 或别的底座。

审计钉在标签 `1.18.0`，提交 `50abea97062110044f15d0e03ccecadd9ccc87ca`。官方插件钉在 `langgenius/dify-official-plugins` 的 `039b7fd3e012`（2026-10-26）。没有安装 Dify，没有起容器，没有把任何知识或对话送进云端。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 源码 | 2026-10-26 从标签 `1.18.0` 或上述插件提交读到的文件 |
| 官方文档 | 同日读取的 docs.dify.ai、dify.ai 定价页、品牌页、OpenAPI 规范或 release 正文 |
| 合理推断 | 由上述材料推出、但没有运行证据的判断 |
| 无法确认 | 材料没写，或两份材料不一致且没有实跑 |

星数、README 功能名单和营销句不单独当作生产能力。下面凡是写「能做」，都指源码或官方文档已经给出机制，不是指本票在 Hostinger 上跑通过。

## 结论速览

1. 自托管 Dify 1.18.0 可以当知识库和应答引擎用，不需要从零写切块、嵌入、检索和聊天 API。社区版默认是单工作区。云端文档数和消息额度的检查在 `DEPLOYMENT_EDITION != CLOUD` 时直接跳过，所以自托管没有 Knocket 那种 FAQ/URL/文件各 10 条的硬上限。
2. 自己能掌握的存储是三处，都在自己的机器上，不在 WordPress 里：切块正文在 PostgreSQL `document_segments.content`；上传原件默认在 OpenDAL 本地目录，可换成 S3 等；向量在独立向量库，默认 Weaviate，可换成 pgvector、Qdrant 等。对话在同一套 PostgreSQL 的 `conversations` / `messages`。Redis 只做队列和缓存。
3. 网站接入是现成的：气泡脚本 `embed.min.js`，或 iframe 到 `/chatbot/<token>`。`/chatbot`、`/chat`、`/agent`、`/workflow` 即使 `ALLOW_EMBED=false` 也允许被嵌入；控制台默认拒绝被 iframe。没有官方 WordPress 插件。WooCommerce 发布状态不会自动推进或失效这份索引，要自己写同步。
4. 模型不在核心仓库里，走官方插件。DeepSeek 官方插件只注册 LLM，不注册嵌入模型。嵌入要另接 OpenAI、Jina、Ollama、Hugging Face TEI，或一个真正提供 embeddings 的 OpenAI 兼容端点。请求正文会离开站点；知识库文件不必交给 Dify 云。
5. 许可证不是干净的 Apache-2.0。单工作区把 Dify 当自己应用的后端，许可文本允许商用。多工作区对外运营需要商业许可。使用 `web/` 前端时不能去掉 Logo 和版权；`CAN_REPLACE_LOGO` 默认 false。不用他们的前端、只调 Service API，这条前端限制按许可文本不适用。
6. 它不是 Knocket 的收件箱。没有官方 Chatwoot 连接器，Chatwoot 官方仓库里也没有 Dify 集成。Human Input 是工作流暂停表单，不是人工坐席接管。社区连接器要么标明 beta、不建议生产，要么没有许可证。AI 加人工闭环要另接一个收件箱项目，再写连接，不是装上即用。
7. 当前 Hostinger 共享主机跑不了这套 Docker Compose。官方最低 2 核、4 GiB，还要 PostgreSQL、Redis、向量库和一组容器。这是部署硬伤，不是许可证硬伤。单独主机的月费本票没有报价。

## 1. 身份、版本、维护

官方仓库是 [langgenius/dify](https://github.com/langgenius/dify)。2026-10-26 读取：未归档，默认分支 `main`，`pushed_at` 为 `2026-10-26T09:31:42Z`。GitHub 许可证字段是 `Other` / SPDX `NOASSERTION`，与仓库里的修改版 Apache 一致，不是「Apache-2.0」徽章能概括的。

稳定标签：

| 标签 | 发布时间 | 说明 |
| --- | --- | --- |
| `1.18.0` | 2026-10-23 | 本票审计点。轻量标签，直接指向 `50abea97062110044f15d0e03ccecadd9ccc87ca` |
| `1.17.1` | 2026-09-10 | 上一补丁 |
| `1.17.0` | 2026-08-25 | |
| `1.16.1` | 2026-07-28 | |
| `1.16.0` | 2026-07-17 | |
| `1.15.0` | 2026-06-25 | |

来源：[releases](https://github.com/langgenius/dify/releases)、[标签对象](https://github.com/langgenius/dify/releases/tag/1.18.0)。

`main` 在审计当日仍在前进。同日 09:09 UTC 的提交是 `173d52d46abe`，`refactor(api): unify more-like-this generation`。自 2026-09-26 起，默认分支提交列表的 `rel=last` 是第 838 页、每页 1 条，约 838 个提交。这只说明维护没有停，不说明每个提交都是产品功能，也不说明升级无成本。

1.18.0 release 正文要求 API、worker、Agent Backend、Agent runtime 镜像一起升；本地 Agent sandbox 必须有非空认证令牌；源码安装要求 Python 3.13。官方自托管文档把 Docker Compose 当主路径，不把源码安装当日常部署。

官方插件仓库 [langgenius/dify-official-plugins](https://github.com/langgenius/dify-official-plugins) 许可证是 Apache-2.0，2026-10-26 仍有提交（`039b7fd3e012`）。模型供应商在这里，不在核心 `api/core/model_runtime/model_providers/`。1.18.0 的文件树里该目录为空。

## 2. 许可证与付费边界

`1.18.0` 的 [LICENSE](https://github.com/langgenius/dify/blob/1.18.0/LICENSE) 全文是修改版 Apache-2.0，附加条件有两条：

1. 可以商用，包括当作其他应用的后端，或当作企业应用开发平台。但下面两种情况要另买商业许可：
   - 多租户服务。许可把一个 tenant 定义成一个 workspace。未经书面授权，不能用这份源码运营多租户环境。
   - 使用 Dify 前端时，不能去掉或修改控制台或应用里的 Logo 和版权信息。前端指源码 `web/` 目录，或 Docker 的 web 镜像。不使用前端的用法，这条不适用。
2. 贡献者同意生产者可以以后把协议改严或改松，贡献代码可用于其云业务。交互设计另有外观专利声明。版权行是 `© 2025 LangGenius, Inc.`

这不是「不能商用」。Metal Divider 自己一个工作区、给自己的网站当后端，落在许可明确允许的句子里。硬伤是另外三件：

- 不能拿社区版给多个客户各开一个 workspace 来卖托管。那是商业许可范围。
- 若嵌入官方气泡或 iframe，用的就是 `web/` 前端，不能摘 Logo / Powered by。`EnterpriseFeatureConfig.CAN_REPLACE_LOGO` 默认 `False`，注释写明使用前邮件询问 `business@dify.ai`。前端钩子 `useWebAppBrand` 在 `can_replace_logo` 不为真时不保存自定义品牌。
- 协议允许以后改严。这是治理风险，不是今天已经禁止自托管。

定价页把边界写得更直：[dify.ai/pricing](https://dify.ai/pricing)（2026-10-26 读取）。

| 计划 | 官方页面上的边界 |
| --- | --- |
| Cloud Sandbox | 免费。200 message credits，1 个成员，50 个知识文档。高质量索引消耗 Knowledge Data Storage，到顶后不能再上传 |
| Cloud Professional | 每工作区年费。5,000 message credits / 月，3 个成员，500 个知识文档 |
| Cloud Team | 每工作区年费。10,000 message credits / 月，50 个成员，1,000 个知识文档 |
| Community | 免费。单工作区。遵守开源许可和品牌指引 |
| Enterprise | 商业许可、多工作区、企业功能、技术支持。网页没有标价 |

云端这些数字是托管配额，不是自托管代码里的常量。Service API 的 `cloud_edition_billing_resource_check` 在 `DEPLOYMENT_EDITION != CLOUD` 时直接执行视图、不做向量空间和文档数检查。`docker/envs/core-services/shared.env.example` 写 `DEPLOYMENT_EDITION=COMMUNITY`。

企业版才有的、社区版默认关掉的，源码里能看见：`CAN_REPLACE_LOGO=false`，`RBAC_ENABLED=false`。Web App 设置文档写：企业版才能给已发布 Web App 加认证、成员级访问和外部用户管理；社区版 Web App 默认公开，拿到 URL 就能开。

品牌页 [dify.ai/brand-guidelines](https://dify.ai/brand-guidelines) 要求使用官方 “Powered by Dify” 徽章的场合，包括「包含 Dify 能力的网站、应用或系统」。这和许可证的前端条款是同一方向。只调 API、自己画聊天窗，不经过 `web/`，许可证说前端限制不适用；品牌页的营销句不能单独推翻许可证里那句例外，但若页面同时打 Dify 商标，仍要遵守品牌使用条款。

官方插件是 Apache-2.0，和核心的修改版许可不是同一份。把插件代码并进自己的仓库，按插件仓库的 Apache-2.0；运行官方 Dify 前端，仍受核心 LICENSE 约束。

## 3. 部署依赖与数据落点

### 3.1 跑起来要什么

官方自托管文档：[Deploy Dify with Docker Compose](https://docs.dify.ai/en/self-host/deploy/quick-start/docker-compose.md)。最低 2 核、4 GiB 内存。软件是 Docker 19.03+ 与 Docker Compose 2.24.0+。文档列出默认会起来的容器：

- 7 个核心：`api`、`api_websocket`、`worker`、`worker_beat`、`web`、`plugin_daemon`、`agent_backend`
- 8 个依赖：`weaviate`、`db_postgres`、`redis`、`nginx`、`ssrf_proxy`、`agent_ssrf_proxy`、`sandbox`、`local_sandbox`
- 一次性 `init_permissions`

镜像钉在 `langgenius/dify-api:1.18.0`、`langgenius/dify-web:1.18.0`、`langgenius/dify-plugin-daemon:0.6.10-local`、`langgenius/dify-agent-backend:1.18.0`、`postgres:15-alpine`、`redis:6-alpine`、`weaviate:1.39.13`。见 [docker-compose.yaml](https://github.com/langgenius/dify/blob/1.18.0/docker/docker-compose.yaml)。

`knowledge_fs` 及其 worker 挂在 Compose profile `knowledge-fs` 上，不在上述默认启动清单里。默认镜像名还写着 `langgenius/dify-knowledge-fs-api:deploy-konwledge`（源码拼写就是 konwledge）。1.18.0 release 把 Agent Knowledge Base 写成 opt-in。本票没有审计 knowledge-fs 的表结构。下面的存储结论只覆盖默认就会用到的 dataset / 向量库 / 对象存储。

文档要求先生成 `DIFY_AGENT_LOCAL_SANDBOX_AUTH_TOKEN`，否则 agent backend 和 sandbox 不起。仓库 README 的快速开始仍只写 `cp .env.example .env && docker compose up -d`，没有这一步。以自托管文档为准。`.env.example` 里还有一组开发用默认口令（数据库、Redis、插件密钥、Agent token）。生产必须换掉。本文件不抄这些默认值。

这套栈不是 PHP 插件。当前站点是 Hostinger 共享主机，首期没有 Redis，评审站和生产都挂在现有共享订阅上。合理推断：不能把 Dify 装进现有 WordPress 站点目录里一起跑。要自托管，就得另有一台能跑 Docker 的机器。那台机器的月费、磁盘和备份本票没有测。官方 4 GiB 是最低值，不是 146 个图案加多年对话的容量证明。

### 3.2 三类数据分别在哪

| 数据 | 物理位置 | 证据 |
| --- | --- | --- |
| 知识库、文档、切块正文、关键词、对话、消息、上传文件元数据、终端用户 | PostgreSQL 15，库名默认 `dify`。MySQL 8 在 Compose 里是另一个 profile，不是默认 | `DB_TYPE=postgresql`；模型 `__tablename__` |
| 切块正文 | `document_segments.content`，`LongText`。父子切块的子块在 `child_chunks.content` | [dataset.py](https://github.com/langgenius/dify/blob/1.18.0/api/models/dataset.py) |
| 上传原件 | 默认 `STORAGE_TYPE=opendal`、`OPENDAL_SCHEME=fs`、`OPENDAL_FS_ROOT=storage`，落在 Compose 卷。可换 `local`、`s3`、Azure、GCS、OSS、COS、Supabase 等 | [storage_type.py](https://github.com/langgenius/dify/blob/1.18.0/api/extensions/storage/storage_type.py)、`.env.example` |
| 检索用向量 | 独立向量库。默认 `VECTOR_STORE=weaviate`，数据卷 `./volumes/weaviate`。代码还注册了 pgvector、pgvecto-rs、qdrant、milvus、chroma、elasticsearch、opensearch、oceanbase 等 30 余种 | [vector_type.py](https://github.com/langgenius/dify/blob/1.18.0/api/core/rag/datasource/vdb/vector_type.py) |
| 嵌入缓存 | PostgreSQL `embeddings.embedding`，pickle 后的 `BinaryData`，按模型名、供应商、文本哈希去重。这是缓存，不是检索索引 | 同上 dataset.py 的 `Embedding` |
| 队列、Celery、事件 | Redis 6。不存知识正文 | `.env.example` |
| 插件包 | 插件守护进程自己的库 `dify_plugin`，文件默认在本地 `/app/storage` | `.env.example` |
| 对话日志 | `conversations`、`messages`、`message_files`、`message_annotations`、`dataset_retriever_resources` | [model.py](https://github.com/langgenius/dify/blob/1.18.0/api/models/model.py) |

经济索引不把切块送去嵌入，文档写每块用 10 个关键词做倒排。高质量索引才调用嵌入模型，向量进上面的向量库。高质量一旦选定，不能退回经济模式。见 [索引方法](https://docs.dify.ai/en/self-host/use-dify/knowledge/create-knowledge/setting-indexing-methods.md)。

向量库迁移文档只声明测过 Qdrant、Milvus、AnalyticDB。其他后端在代码里有实现，不能据此说迁移命令对它们同样可用。文件从本地卷迁到对象存储有 `flask upload-local-files-to-cloud-storage`。备份文档要求 Docker 部署备份整个 `dify/docker/volumes`。见 [Storage & Migration](https://docs.dify.ai/en/self-host/deploy/troubleshooting/storage-and-migration.md)。

会离开自己机器的，不是默认存储，而是这些调用：

- 聊天和嵌入请求发到你填的模型端点。检索到的切块会进提示词。
- 用 Firecrawl 或 Jina Reader 的云 API 抓网页时，页面内容先经过他们的服务。文档写的是去对方网站注册拿 Key。源码里还有 WaterCrawl 提取器，`ENABLE_WEBSITE_WATERCRAWL=true`，但这篇网站导入文档没有写它。
- Notion 同步走 Notion OAuth。
- 默认 `CHECK_UPDATE_URL=https://updates.dify.ai`，`MARKETPLACE_API_URL=https://marketplace.dify.ai`，`WEAVIATE_DISABLE_TELEMETRY=false`。本票没有审计这些请求的正文，不能说它们上传了知识库。要收紧，就改这些变量并关掉不需要的市场访问。

插件安装默认 `FORCE_VERIFYING_SIGNATURE=true`。未签名的第三方插件不能靠「把 zip 丢进去」当成官方通道。

## 4. 知识怎么进、怎么改、怎么删

默认路径是文档里的 ready-to-use knowledge base，不是 knowledge-fs。Service API 在 1.18.0 仍注册这套数据集路由。OpenAPI 规范于 2026-10-26 取自 [openapi_service.json](https://docs.dify.ai/en/api-reference/openapi_service.json)，`servers` 默认 `api.dify.ai/v1`，说明自托管要换成自己的 API 基址。路径与源码控制器一致。鉴权是 `Authorization: Bearer <token>`，见 [wraps.py](https://github.com/langgenius/dify/blob/1.18.0/api/controllers/service_api/wraps.py)。

### 4.1 输入

| 输入 | 机制 | 上限 | 等级 |
| --- | --- | --- | --- |
| 本地文件 | 控制台上传，或 `POST /datasets/{id}/document/create-by-file` | 单文件 15 MB（`UPLOAD_FILE_SIZE_LIMIT`，单位兆字节）；一批 5 个（`UPLOAD_FILE_BATCH_LIMIT`）。官方上传文档把这两个变量写明。Nginx `client_max_body_size` 默认 100M，应用层仍是 15 MB，除非改环境变量 | 源码 + [上传文档](https://docs.dify.ai/en/self-host/use-dify/knowledge/create-knowledge/import-text-data/readme.md) |
| 纯文本 / FAQ 文本 | `POST .../document/create-by-text` | 没有 10 条 FAQ 上限。社区版文档数检查被云分支跳过 | 源码 |
| 网页 | Firecrawl 或 Jina Reader。可选子页、深度、条数、路径过滤 | 不是单页列表。依赖第三方解析 API，除非把 Key 指到自己托管的兼容服务。本票没有验证自建 Firecrawl 能否直接填进这个配置槽 | 官方文档 |
| Notion | OAuth 后同步页面 | 数据在 Notion，再复制进 Dify | 文档索引 + `notion_extractor.py` |
| 手工切块 | 文档和切块都可以后加、后改 | 经济模式每块最多 10 个关键词 | [管理文档](https://docs.dify.ai/en/self-host/use-dify/knowledge/manage-knowledge/maintain-knowledge-documents.md) |
| 标注库 | 应用级问答，命中则跳过生成 | 导入文件默认 2 MB（`ANNOTATION_IMPORT_FILE_SIZE_LIMIT`） | [Annotation](https://docs.dify.ai/en/self-host/use-dify/monitor/annotation-reply.md) |

提取器按扩展名分支，见 [extract_processor.py](https://github.com/langgenius/dify/blob/1.18.0/api/core/rag/extractor/extract_processor.py)：

- 自带提取：`.xlsx` / `.xls`、`.pdf`、`.md` / `.markdown` / `.mdx`、`.htm` / `.html`、`.docx`、`.csv`，其余走 `TextExtractor`（注释写明 txt）。
- 要 Unstructured API 的：`.doc`、`.ppt`、`.pptx`、`.xml`、`.epub`、`.msg`、`.eml`。默认 `ETL_TYPE=dify`，这些格式不是开箱即用。
- DOCX / XLSX 里小于 2 MB 的 JPG、JPEG、PNG、GIF 会抽成切块附件，每块默认最多 10 张。PDF 内嵌图要换知识流水线里的提取插件，默认提取器不承诺抽出。

Knocket 教程里的 PDF、TXT、DOC、DOCX、HTML、MD、CSV、XLSX，除了 `.doc` 依赖 Unstructured，其余在默认提取器里有对应分支。

### 4.2 切块与索引

切块模式在知识库创建后不能改。分隔符、最大长度、重叠可以之后调。两种模式：

- General：命中的块直接返回。可设重叠。
- Parent-child：用子块检索，返回父块。只兼容高质量索引。Full Doc 父块只处理前 10,000 tokens，超出截断，且父块创建后不能编辑。

来源：[切块设置](https://docs.dify.ai/en/self-host/use-dify/knowledge/create-knowledge/chunking-and-cleaning-text.md)。

`INDEXING_MAX_SEGMENTATION_TOKENS_LENGTH` 默认 4000，描述是分段的最大 token 长度。文档里的「最大块长度」按字符说。这是两个旋钮，不能当成同一个数字。

检索：向量、全文、混合。默认 TopK 文档写 3，Score 阈值示例 0.5。混合检索可以不配 Rerank，改用语义/关键词权重。Rerank 是另一个模型供应商，要单独填 Key。经济模式只有倒排和 TopK。

`datasets` 行上存 `embedding_model` 和 `embedding_model_provider`。换嵌入模型在设置页被写成可以随时改。本票没有追到「改模型是否自动全库重嵌」的任务代码，不能把自动重嵌说成已证实。

### 4.3 更新、禁用、删除

Service API：

- 更新：`POST .../update-by-text` 与 `.../update-by-file`，带向量空间和知识速率装饰器。社区版跳过云配额，仍会走更新逻辑。
- 状态：`PATCH /datasets/{id}/documents/status/{action}`，以及控制台的启用、禁用、归档。禁用后不参与检索，文档还在。
- 删除文档：`DELETE /datasets/{id}/documents/{document_id}` 返回 204，调用 `DocumentService.delete_document`。随后 `clean_document_task` 删切块行、向量或关键词索引、对象存储里的原件和图片附件。文档写明删除不可撤销。
- 删切块：segments 与 child_chunks 有 DELETE。
- 检索测试：`POST /datasets/{id}/retrieve`。
- 下载：`GET .../documents/{document_id}/download` 和 zip 批量下载。本票没有逐行读下载处理函数，只能确认端点存在，不能确认 zip 是否包含向量。

没有 WordPress `save_post` 或 Woo 下架钩子。要让「Woo 是唯一放行源、索引只是派生副本」，必须另写同步：发布时 `create-by-text` / `update-by-file`，下架时禁用或删除对应 document。Dify 控制台里手改过的切块，下一次全量覆盖会丢掉。这是整合时要设计的点，不是 Dify 已经做了的事。

对话删除有 `DELETE /conversations/{conversation_id}`。列表、改名、读写会话变量也有。存储文档还提供手工命令 `flask clear-free-plan-tenant-expired-logs --days N`。这是命令，不是社区版默认的自动过期策略。本票没有证实 Beat 会在社区版自动删对话。

## 5. 模型与嵌入

核心不内置供应商 Key。文档要求在 Integrations 里安装插件、填自己的 Key，工作区级共享。[Model Providers](https://docs.dify.ai/en/self-host/use-dify/workspace/model-providers.md)。默认模型分五类：推理、嵌入、Rerank、语音转文字、文字转语音。嵌入默认空着时，高质量知识库不能索引。

官方插件目录（`039b7fd3e012`）里有独立目录的，包括 `openai`、`openai_api_compatible`、`deepseek`、`ollama`、`huggingface_tei`、`jina`、`localai`、`xinference`、`gpustack`、`voyage`、`cohere`、`gemini`、`bedrock`、`azure_openai` 等。完整目录名以该提交的 `models/` 为准，这里不把目录名当成「每个都提供嵌入」。

和本站已有模型选择直接相关的：

| 插件 | 版本（manifest） | 实际注册的模型类型 | 含义 |
| --- | --- | --- | --- |
| `deepseek` | 0.0.26 | `supported_model_types` 只有 `llm`。模型文件都在 `models/llm/`。端点默认可填 `https://api.deepseek.com/v1` 或 `https://api.deepseek.com` | 能做聊天，不能当 Dify 的嵌入供应商。manifest 的 permission 虽写了 `text_embedding: true`，provider 没有注册嵌入模型，以 provider 为准 |
| `openai` | 1.0.8 | 描述写明 language、embedding、moderation、audio。permission 含 `text_embedding` | 可做高质量索引，Key 和向量请求发到 OpenAI |
| `openai_api_compatible` | 0.0.72 | `llm`、`rerank`、`text-embedding`、`speech2text`、`tts`。嵌入要填 endpoint、模型名、`max_chunks` | 可把自托管嵌入服务接进来，前提是对方实现它期望的接口。本票没有对 DeepSeek 官方 API 做嵌入探测，不能把这个插件指到 DeepSeek 就当成有嵌入 |

经济索引可以完全不调用嵌入，用关键词。文档写明精度更低，且不能再退回。父块检索和多模态嵌入都不走这条。

托管试用 Key（`HOSTED_OPENAI_*`、`HOSTED_DEEPSEEK_*` 等）默认关闭。那是 Dify Cloud 的额度，不是自托管送的免费模型。

## 6. API 与网站嵌入

发布文档把同一份已发布应用分成直链、气泡、iframe。[Embed Your Web App](https://docs.dify.ai/en/self-host/use-dify/publish/webapp/embedding-in-websites.md)。

源码生成的脚本见 [app-card-utils.ts](https://github.com/langgenius/dify/blob/1.18.0/web/app/components/app/overview/app-card-utils.ts)：

- `window.difyChatbotConfig` 带 `token`、`baseUrl`、可选 `inputs`、`systemVariables.user_id`、`conversation_id`。
- 再加载 `{baseUrl}/embed.min.js`。
- iframe 指向 `{baseUrl}/{chatbot|agent}/{token}`。

[web/proxy.ts](https://github.com/langgenius/dify/blob/1.18.0/web/proxy.ts) 把 `/agent`、`/chat`、`/chatbot`、`/completion`、`/workflow`、`/webapp-signin` 定为可嵌入路径。`ALLOW_EMBED` 默认 false 时，这些路径不加 `X-Frame-Options: DENY`；控制台和其他路径加 DENY，CSP `frame-ancestors 'none'`。同文件的测试期望 `/chatbot/token` 可嵌入。本票没有在 WordPress 页面里实嵌。

应答 API 是 `POST /chat-messages`，停止生成是 `POST /chat-messages/{task_id}/stop`。还有 `completion-messages`。这是把 Dify 嵌进自己的界面所需的最小接口，不必使用他们的前端。

Web App 默认公开。企业版才有登录墙。若气泡暴露在公网，任何拿到 token 的人都能开聊，直到你在前面加自己的门。预聊天表单、开场白、引用、反馈，文档写是应用配置打开后 Web App 会继承。系统提示词的字符上限本票没有在 1.18.0 里找到对应 Knocket 2000 字的常量，不能说没有上限，也不能把 Knocket 的数字套过来。

Chrome 应用商店插件 ID `ceehdapohffmjmkdcifjofadiaoeggaf` 写在嵌入对话框里。那是浏览器扩展，不是 WordPress，也不是移动端原生 SDK。

## 7. WordPress 接入

1.18.0 文件树没有 `wordpress` 路径。WordPress.org Plugins API 1.2 对 slug `dify` 和 `dify-ai` 返回 404。搜索词 `dify` 的第一页是 aicom、flavoursuite-ai、igen-seo-api，描述里都不是 Dify。没有官方插件可装。

能少写代码的接法只有两种：

1. 在 LiveCanvas 或主题里放入官方嵌入脚本或 iframe。访客聊的是 Dify Web App。对话不进 WordPress 数据库，进 Dify 的 PostgreSQL。品牌按第 2 节保留。
2. 自有小段代码调 `POST /v1/chat-messages`，自己画窗口。对话仍在 Dify，除非再把消息抄回 WordPress。不用 `web/` 时，许可证的 Logo 条款不适用。这比第一种多写 UI，但仍然不是从零做 RAG。

Woo 是商品唯一放行源。Dify 的 dataset 只能是派生副本。没有现成连接器会在产品发布、改价、下架时更新或失效索引。同步代码是本项目必须自己写的那一层，体量应限于「读 Woo、调 dataset API」，不是再写一套切块器。

共享主机上的 PHP 站点调远程 Dify API 是可行的方向。把 Dify 进程装进同一共享主机，官方部署要求对不上。

## 8. Chatwoot 与人工闭环

### 8.1 官方没有连接器

- `1.18.0` 全树 15,208 个文件路径，没有 `chatwoot`。
- 官方插件树 9,973 条路径，没有 `chatwoot`，也没有 `wordpress`。
- `gh search code "dify" --repo chatwoot/chatwoot` 的命中是 `modify` 一类无关行，不是集成代码。不能据此宣称已经逐行读完 Chatwoot，只能说这次代码搜索没有找到 Dify 集成。

Dify 市场页面是前端应用，本票用过的几个猜测 API 返回 404 或整页 HTML，没有拿到可引用的插件清单 JSON。市场没有被证明「有 Chatwoot 插件」，也没有被证明「页面上绝对没有」。官方仓库和官方插件仓库已经是空的，这足够否定「装官方插件即闭环」。

### 8.2 Human Input 不是坐席接管

[Human Input](https://docs.dify.ai/en/self-host/use-dify/nodes/human-input.md) 把工作流暂停，发一张表单。投递渠道文档只写两种：Web App 里给当前终端用户，或邮件给工作区成员、外部邮箱、全体成员。第一个回复后关闭。Service API 可以驱动这张表单。它没有会话分配、没有坐席收件箱、没有「Resume AI」、没有 Telegram / WhatsApp 渠道。

标注系统是另一件事：相似问题直接返回写好的答案，用来绕过生成。它不是人工接手后的对话。

控制台有应用日志。那是这个应用的调试和监控，不是 Knocket 那种三入口加邮件加 Telegram 的统一收件箱。

### 8.3 社区桥

[alexbalandi/chatwoot-dify](https://github.com/alexbalandi/chatwoot-dify)（2026-10-26 读取）：MIT，3 star，`pushed_at` 2025-07-06。README 自标 beta，写明不建议生产。它描述的是 Chatwoot Agent Bot webhook 到一个自建 Python 桥，再调 Dify，并用自己的 PostgreSQL 存会话映射。README 的克隆地址写成 `eremeye/chatdify`，和仓库名不一致。本票没有运行它。

[KartikMouli/dify-chatwoot](https://github.com/KartikMouli/dify-chatwoot)：0 star，无许可证字段，`pushed_at` 2025-12-12。没有许可证就不能当可复用代码。

这些不是「现成、维护中、可直接形成闭环」的官方集成。Chatwoot 侧的 Agent Bot 出站 URL 是社区 README 里的接法，不是 Dify 发布的连接器。真要 AI 先答、低置信或点人工后进坐席，需要一个收件箱项目，再加一段本票没有找到现成生产实现的胶水。胶水的量是 webhook 和 API 对打，不是重写 RAG。

## 9. 对照 Knocket

Knocket 的功能面以票 87 的已关闭结论为对照，不在这里重做产品调查。

| Knocket 使用方式 | Dify 1.18.0 | 还要什么 |
| --- | --- | --- |
| 网站右下角气泡 | 有。`embed.min.js` + token | 自己的 Dify 主机；保留前端品牌，或改走自绘 UI |
| 全页聊天 / iframe | 有。`/chatbot/<token>` 默认可被嵌入 | 同上。没有 `knocket.ai/<slug>` 这种厂商托管联系页 |
| 联系页、自定义域联系页 | 无对应产品面 | 用自己的 WordPress 页嵌 iframe，不是 Dify 功能 |
| 移动 WebView | 无官方原生桥。Web App 是响应式网页 | 若要 App 内入口，自己用 WebView 打开已发布 URL。没有对照过 Knocket 的 JS bridge |
| 统一收件箱 | 无 | 另一个客服项目 |
| Telegram、Email 进同一线程 | 无 | 同上 |
| WhatsApp / Instagram / Messenger 外链 | 无现成渠道按钮 | 网站自己放链接，或收件箱项目提供 |
| 人工接管、Resume AI、坐席通知 | 无。Human Input 是表单暂停 | 收件箱项目 + 胶水 |
| 开场白、预聊天字段 | 文档写 Web App 可继承会话开场和预聊天表单 | 未实走 |
| 营业时间、离线留言 | 未见对等开关 | 未证实有 |
| FAQ / URL / 文件各 10 条、文件 10 MB | 无此上限。文件默认 15 MB，可改环境变量。网页可爬子页，但依赖解析 API | 大库的耗时、磁盘、嵌入费用未测 |
| 聊天模型自带 OpenAI 兼容 Key | 有，而且供应商更多。DeepSeek 官方插件可接官方端点 | Key 自己保管 |
| 嵌入由平台托管 | 相反：嵌入模型和向量库都要自己选、自己存 | 要另选嵌入供应商；DeepSeek 插件不够 |
| 知识导出 | 有文档下载端点；是否含向量未逐行证实。对话可按 ID 删除 | 全库打包工具本票未找到 |
| WordPress 插件只插脚本 | Dify 连插件都没有，脚本要自己贴 | 少量模板改动，或一小段 API 客户端 |
| 知识随 Woo 上下架 | 无 | 必须自写同步，否则索引成为第二套可独立放行的商品资料 |

## 10. 能不能少写代码

能直接用的：

- 自托管知识库、切块、高质量或经济索引、检索、命中测试。
- Service API 的增删改文档、检索、聊天、停生成、列对话、删对话。
- 网站气泡和 iframe。
- 官方模型插件，含 DeepSeek 聊天和 OpenAI 兼容嵌入槽。
- 单工作区商用，数据落在自己的 Postgres、文件卷和向量库。

必须另有项目或少量自有代码的：

- WordPress / LiveCanvas 贴脚本，或一个只调 `/v1/chat-messages` 的窗口。
- Woo 发布状态到 dataset 的生成与失效。没有这个同步，就违反「索引不能独立放行商品」的既有约束。
- 收件箱、人工接管、Telegram、邮件入站、坐席通知。Dify 没有。官方也没有 Chatwoot 闭环。
- 若不想显示 Dify 品牌，就不能用官方前端，要自己画 UI。这仍是连接，不是重写 RAG。
- 一台 Docker 主机、备份 `docker/volumes`、换掉默认口令、决定嵌入供应商。

不该选的用法：

- Dify Cloud。文档数、消息额度和存储都在他们的配额里，知识不由自己掌握。
- 把社区版当成多租户 SaaS。许可不允许。
- 把未维护的社区 Chatwoot 桥当成生产闭环。
- 装进当前共享主机。部署要求对不上。

推荐组合价值：Dify 适合当「自控知识库 + 应答 API + 可选官方挂件」，不适合当 Knocket 的整包替代。和收件箱项目组合时，Dify 提供检索和生成，收件箱提供人工；两者之间今天没有官方连接器，胶水是边界，不是再做一个知识库。是否用 Chatwoot 当那个收件箱，要看 Chatwoot 自己的研究票，本票只证明 Dify 侧没有现成接头。

## 11. 未验证

- 没有 `docker compose up`，没有导入 146 个图案，没有测检索延迟、内存和磁盘。
- 没有在 WordPress 页面实嵌脚本，没有测 LiteSpeed 或 CSP 是否挡住 `embed.min.js`。
- 没有对 DeepSeek 官方 API 发嵌入请求。只确认官方插件不注册嵌入模型。
- 没有读完 knowledge-fs 的表，也没有启用该 profile。
- 没有逐行读文档下载处理函数，不能把 zip 端点说成完整知识导出。
- 换嵌入模型后是否自动重嵌，没有追到任务代码。
- 对话是否在社区版自动过期，只看到手工清理命令。
- 系统提示词、开场白的字符上限没有在 1.18.0 里定位到常量。
- 市场网站没有给出可引用的插件 JSON。官方仓库为空，不能被市场页面推翻，但也不能写成「市场搜索返回零条」。
- 单独 Docker 主机的价格、以及 4 GiB 是否够用，没有报价和压测。
- 更新检查和 Weaviate 遥测的请求正文没有抓包。

## 来源

- 仓库与标签：<https://github.com/langgenius/dify> ，<https://github.com/langgenius/dify/releases/tag/1.18.0>
- 许可证：<https://github.com/langgenius/dify/blob/1.18.0/LICENSE>
- Compose 与环境：<https://github.com/langgenius/dify/blob/1.18.0/docker/docker-compose.yaml> ，<https://github.com/langgenius/dify/blob/1.18.0/docker/.env.example> ，<https://github.com/langgenius/dify/blob/1.18.0/docker/envs/core-services/shared.env.example>
- 存储与向量枚举：<https://github.com/langgenius/dify/blob/1.18.0/api/extensions/storage/storage_type.py> ，<https://github.com/langgenius/dify/blob/1.18.0/api/core/rag/datasource/vdb/vector_type.py> ，<https://github.com/langgenius/dify/blob/1.18.0/api/models/dataset.py> ，<https://github.com/langgenius/dify/blob/1.18.0/api/models/model.py>
- 删除清理：<https://github.com/langgenius/dify/blob/1.18.0/api/tasks/clean_document_task.py>
- 云配额短路：<https://github.com/langgenius/dify/blob/1.18.0/api/controllers/service_api/wraps.py>
- 企业开关：<https://github.com/langgenius/dify/blob/1.18.0/api/configs/enterprise/__init__.py>
- 嵌入与框架头：<https://github.com/langgenius/dify/blob/1.18.0/web/app/components/app/overview/app-card-utils.ts> ，<https://github.com/langgenius/dify/blob/1.18.0/web/proxy.ts>
- 品牌钩子：<https://github.com/langgenius/dify/blob/1.18.0/web/app/components/custom/custom-web-app-brand/hooks/use-web-app-brand.ts>
- 提取器：<https://github.com/langgenius/dify/blob/1.18.0/api/core/rag/extractor/extract_processor.py>
- 官方插件：<https://github.com/langgenius/dify-official-plugins/blob/039b7fd3e012/models/deepseek/provider/deepseek.yaml> ，<https://github.com/langgenius/dify-official-plugins/blob/039b7fd3e012/models/openai_api_compatible/provider/openai_api_compatible.yaml> ，<https://github.com/langgenius/dify-official-plugins/blob/039b7fd3e012/models/openai/manifest.yaml>
- 自托管文档：<https://docs.dify.ai/en/self-host/deploy/quick-start/docker-compose.md> ，<https://docs.dify.ai/en/self-host/deploy/troubleshooting/storage-and-migration.md>
- 知识文档：<https://docs.dify.ai/en/self-host/use-dify/knowledge/readme.md> ，<https://docs.dify.ai/en/self-host/use-dify/knowledge/create-knowledge/chunking-and-cleaning-text.md> ，<https://docs.dify.ai/en/self-host/use-dify/knowledge/create-knowledge/setting-indexing-methods.md> ，<https://docs.dify.ai/en/self-host/use-dify/knowledge/create-knowledge/import-text-data/readme.md> ，<https://docs.dify.ai/en/self-host/use-dify/knowledge/create-knowledge/import-text-data/sync-from-website.md> ，<https://docs.dify.ai/en/self-host/use-dify/knowledge/manage-knowledge/maintain-knowledge-documents.md>
- 发布与人工：<https://docs.dify.ai/en/self-host/use-dify/publish/webapp/embedding-in-websites.md> ，<https://docs.dify.ai/en/self-host/use-dify/publish/webapp/web-app-settings.md> ，<https://docs.dify.ai/en/self-host/use-dify/nodes/human-input.md> ，<https://docs.dify.ai/en/self-host/use-dify/monitor/annotation-reply.md> ，<https://docs.dify.ai/en/self-host/use-dify/workspace/model-providers.md>
- OpenAPI：<https://docs.dify.ai/en/api-reference/openapi_service.json>
- 定价与品牌：<https://dify.ai/pricing> ，<https://dify.ai/brand-guidelines>
- 社区桥：<https://github.com/alexbalandi/chatwoot-dify> ，<https://github.com/KartikMouli/dify-chatwoot>
