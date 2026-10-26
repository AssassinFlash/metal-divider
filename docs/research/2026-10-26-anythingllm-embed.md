# AnythingLLM 与 Embed：知识库、网站挂件与客服缺口

- 票：[研究：AnythingLLM 知识库与网站 Embed 的客服适配性](https://github.com/AssassinFlash/metal-divider/issues/95)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/anythingllm-embed`（从 `origin/main` 的 `9da89ab` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-95-anythingllm`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树和源码克隆；不删除分支，不删除本文件

本文件只回答 AnythingLLM 与它的 Embed 挂件能不能直接或少量接入，用来自托管知识库和网站聊天。对照物是 [Knocket 研究](https://github.com/AssassinFlash/metal-divider/issues/87) 已经钉死的使用方式，不重做 Knocket，也不评估其他开源客服底座。不决定 AI 与询盘、资料公开范围的关系。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 2026-10-26 读到的官方仓库文件、许可证、Prisma schema、路由和挂件源码。源码克隆是 `master` 浅克隆，提交 `9b1fcab141000eaba9f78ed131151de04904454b`（2026-10-25）。Embed 子模块停在 `a3a2fea57cfc753698110dd0973a607e3209f634`（2026-09-21） |
| 官方宣称 | 官方文档仓库 `Mintplex-Labs/anythingllm-docs`（`main`，2026-10-25 仍有推送）和 GitHub Release 正文。未在运行中的实例上点过界面 |
| 合理推断 | 由上述材料推出、但没有本机安装或线上实走的判断 |
| 无法确认 | 材料没写，或文档与源码不一致且没有运行实例可以裁决 |

没有安装 Docker，没有创建 embed，没有调用聊天或上传接口，没有登录 Mintplex 托管结账页。星数不作为能否上生产的证据。

## 结论速览

1. AnythingLLM 可以当自托管知识库加网站聊天气泡用，但不能单独复现 Knocket 的使用方式。知识原文、向量、会话都能留在自己的实例里；网站接入是一段指向该实例的 `<script>`。WordPress 侧没有官方插件，要写的是贴脚本和把本站资料推过去，不是再做一个 RAG 引擎。
2. 许可证没有挡住这条路。主仓库、Embed 子模块、文档仓库都是 MIT。付费的 Pro 是桌面端系统级 Magic 功能，官方写明不拿走自托管能力。Cloud 是 Mintplex 代管的隔离实例，可选，不是开源功能的钥匙。
3. 部署有硬条件，而且和当前共享主机冲突。官方最低配置是 2GB 内存、支持 AVX2 的 2 核、5GB 磁盘。镜像是 Ubuntu + Node 18 + Chromium，compose 还要 `SYS_ADMIN`。它不是 PHP 插件。访客浏览器会直接请求 AnythingLLM 的公网地址。共享主机上的 WordPress 站点不能把这个进程塞进自己。
4. 人工接管是缺口，不是配置项。Embed 管理接口只有创建、修改、停用、查看和删除记录。没有坐席回复、转人工、在线时段、线索表单、邮件入站或 Chatwoot。Telegram 是站长自己的机器人，而且官方写明多用户模式会把它断开。
5. 因此它的位置是知识引擎加公开挂件，不是收件箱。若要接近 Knocket，挂件和知识用它，收件箱、通知和人工回复要另一套项目；两边的连接代码不在 AnythingLLM 里。

## 1. 身份、版本与维护

| 项 | 值 | 等级 |
| --- | --- | --- |
| 主仓库 | [Mintplex-Labs/anything-llm](https://github.com/Mintplex-Labs/anything-llm) | 实测 |
| 许可证 | MIT，版权 Mintplex Labs Inc. | 实测，[LICENSE](https://github.com/Mintplex-Labs/anything-llm/blob/master/LICENSE) |
| 已发布版 | `v1.18.0`，2026-10-13 发布，非预发布 | 实测，[Release](https://github.com/Mintplex-Labs/anything-llm/releases/tag/v1.18.0) |
| 审计的源码 | `master` `9b1fcab141000eaba9f78ed131151de04904454b`，`package.json` 与 Dockerfile `DEPLOYMENT_VERSION` 都是 `1.19.0`，此时还不是 GitHub Release | 实测 |
| 近期发布 | 2026-06-16 至 2026-10-13：`v1.14.1`、`v1.14.2`、`v1.15.0`、`v1.16.0`、`v1.16.1`、`v1.16.2`、`v1.17.0`、`v1.18.0` | 实测 |
| 最近提交 | 2026-10-25，仓库未归档 | 实测 |
| Embed 仓库 | [Mintplex-Labs/anythingllm-embed](https://github.com/Mintplex-Labs/anythingllm-embed)，MIT，默认分支 `main`，最近提交 2026-09-21，没有 GitHub Release | 实测 |
| 文档 | [Mintplex-Labs/anythingllm-docs](https://github.com/Mintplex-Labs/anythingllm-docs)，MIT，站点 [docs.anythingllm.com](https://docs.anythingllm.com) | 实测 |
| 镜像 | 文档写 `mintplexlabs/anythingllm:latest` 跟踪 `master` 每次提交；`v*.*.*` 在发版时打 | 官方宣称，[Available Images](https://github.com/Mintplex-Labs/anythingllm-docs/blob/main/pages/installation-docker/available-images.mdx)。本次没有 `docker pull` |

源码结论以 `9b1fcab` 为准。已发布包以 `v1.18.0` 为准。没有对这两个树做全量 diff。`v1.18.0` 的 release note 已经包含「按浏览器 origin 比较 embed allowlist」。`EMBED_REQUIRE_ALLOWLIST` 是在 `9b1fcab` 的中间件里读到的，不能据此说 `v1.18.0` 镜像里一定有这个变量。

维护活动是发版和提交记录，不是星数。2026 年仍在合并外部 PR，并在 10 月发了正式版。这只说明项目没停，不代替部署实测。

同组织里还有这些相关仓库，本票不把它们算进客服方案：

- [anythingllm-mobile](https://github.com/Mintplex-Labs/anythingllm-mobile) 是 GPL-3.0，官方描述是装在自己口袋里的本地代理，不是访客 App。
- [anythingllm-gapps](https://github.com/Mintplex-Labs/anythingllm-gapps) 是 AGPL-3.0 的 Google 应用桥。不用它就不继承 AGPL。
- [helm-charts](https://github.com/Mintplex-Labs/helm-charts) 是 MIT，最近推送停在 2025-10-14。图表是否跟上 `v1.18.0`，本次没核对。

## 2. 开源与付费边界

自托管核心是 MIT。[TERMS_SELF_HOSTED.md](https://github.com/Mintplex-Labs/anything-llm/blob/master/TERMS_SELF_HOSTED.md)（文内写 Last Updated: March 2026）写明：

- Docker、Desktop、源码这三种自托管，Mintplex 不托管文档、聊天、工作区和嵌入。
- 遥测可关。关掉之后不向 Mintplex 发送使用数据。这是官方条款，不是本次抓包。
- 接 OpenAI、Anthropic、Pinecone 等第三方时，数据从实例直接发给该厂商，适用对方条款。
- 默认先在本机做。不手动打开外部集成，就不适用第三方条款。
- 自托管没有 SLA，除非另签企业协议。条款没有把 RAG、Embed 或 API 写成企业版专属。

[Pro 文档](https://github.com/Mintplex-Labs/anythingllm-docs/blob/main/pages/pro/overview.mdx) 写：Pro 只在 Desktop `v1.15.0` 及以后，解锁 Magic Echo / Beacon / Tab 的每日用量，以及生成文档去水印。文档写「今天免费的继续免费」，Pro 是叠加，不是自托管的许可证闸。本次在 Embed 管理、文档 API、向量提供方路由里没有读到 Pro key 校验。没有把整个 `server/` 做许可证字符串穷尽扫描，所以不能说源码里绝对没有别处的付费判断。

[Cloud 文档](https://github.com/Mintplex-Labs/anythingllm-docs/blob/main/pages/cloud/overview.mdx) 写：Mintplex 在隔离的 AWS 实例上代管，结账入口是 `https://my.mintplexlabs.com/aio-checkout?product=anythingllm`。价格在页面图片里，Markdown 没有写出数字，本文不报价。[限制页](https://github.com/Mintplex-Labs/anythingllm-docs/blob/main/pages/cloud/limitations.mdx) 写明代管实例没有内置 LLM、内置嵌入器容易把小机器打成 502、不支持自定义 Agent、不支持 MCP。要自己掌握存储，不应选这条代管路径。

许可证结论：自托管 RAG 和 Embed 没有许可证硬伤。硬伤在运行环境和功能缺口，不在 MIT。

## 3. 部署依赖

官方最低配置，[System Requirements](https://github.com/Mintplex-Labs/anythingllm-docs/blob/main/pages/installation-docker/system-requirements.mdx)：

| 项 | 官方推荐最低值 |
| --- | --- |
| 内存 | 2GB |
| CPU | 2 核，且必须有 AVX2 |
| 磁盘 | 5GB |

AVX2 是默认向量库 LanceDB 的硬要求。没有 AVX2 时，文档写进程会以 `Illegal instruction` 退出。ARM64 走另一套构建，不受这条 x86 限制。内置 LLM 或内置嵌入器会在这 2GB 之上再吃内存和磁盘；文档建议大文档改用外部嵌入器。

`docker/Dockerfile` 在 `9b1fcab` 的事实：

- 基础镜像 `ubuntu:noble-20251013`。
- Node 来自 NodeSource `node_18.x`，前端构建阶段是 `node:18-slim`。`server/package.json` 的 engines 是 `node >= 18.12.1`。
- 安装 Chromium 依赖、ffmpeg、git、构建工具。ARM 阶段还下载一份 Chromium 压缩包，因为 Puppeteer 不带 ARM 构建。
- 生产环境变量 `ANYTHING_LLM_RUNTIME=docker`，`DEPLOYMENT_VERSION=1.19.0`。
- 健康检查每 1 分钟打一次。

`docker/docker-compose.yml` 把宿主机 `server/storage`、`collector/hotdir`、`collector/outputs` 挂进容器，端口 `3001`，并 `cap_add: SYS_ADMIN`。这是浏览器抓取需要的权限，不是 WordPress 插件能申请的能力。

合理推断：当前站点所在的 Hostinger 共享主机（PHP / LiteSpeed，首期无 Redis，见仓库 `AGENTS.md`）跑不了这个容器。依据是运行时形态，不是一次失败的安装。WordPress 只能做访客入口和资料同步客户端。AnythingLLM 必须另有一台访客能访问的机器或容器，并且挂件脚本里的 `data-base-api-url` 必须是公网 HTTPS 源。桌面版即使能打开设置页，也没有公网地址给网站访客用。

官方文档写 Embed 设置「DOCKER VERSION ONLY」。`9b1fcab` 的前端路由 `/settings/embed-chat-widgets` 没有按 runtime 隐藏。这是文档与源码不一致。对网站来说差别不大：没有公网地址，挂件就没有访客。本次没有打开桌面版确认那一页是否可见。

## 4. 数据落在哪里

默认不是云。`server/prisma/schema.prisma` 的数据源是 SQLite，文件 `server/storage/anythingllm.db`。PostgreSQL 块被注释掉。文档另有 `mintplexlabs/anythingllm:pg`，默认改用 PostgreSQL + PGVector；那是另一张镜像，不是默认 compose。

`server/storage/README.md` 写这个目录存放：

- `documents`：解析后、准备嵌入的文档。
- `lancedb`：默认向量库的磁盘文件。
- `vector-cache`：嵌入缓存。
- `anythingllm.db`：应用库。

`document_vectors` 表只存 `docId` 和 `vectorId`，不存浮点向量。向量本体在 LanceDB 目录，或在你另选的向量库里。

`docker/.env.example` 里可选的向量库：LanceDB、PGVector、Chroma、Chroma Cloud、Pinecone、Weaviate、Qdrant、Milvus、Zilliz、Astra。选云向量库，向量就离开本机。默认注释掉的选择是 LanceDB。

聊天分两套表：

- `workspace_chats`：登录用户、线程和开发者 API 的对话。有 `api_session_id`。
- `embed_chats`：网站挂件。字段是 `prompt`、`response`、`session_id`、`include`、`connection_information`、`embed_id`。schema 没有过期列。`server/jobs` 里没有清理 embed 聊天的任务。删除是管理接口按条删，或访客重置会话时把历史标成无效。

`connection_information` 由中间件写成 `host`（请求的 `Origin`）和 `ip`。若脚本带了 `data-username`，再写入 `username`。没有姓名、邮箱、电话字段。

[隐私文档](https://github.com/Mintplex-Labs/anythingllm-docs/blob/main/pages/features/privacy-and-data-handling.mdx) 把删除分成两步，这和「下架必须失效」直接有关：

- 只从工作区移除：该工作区不再检索，但原文仍在 My Documents，可被其他工作区复用。
- 从 My Documents 删除：解析文件和嵌入缓存一起删，并从所有工作区移除。

开发者 API 对应的是 `POST /api/v1/workspace/:slug/update-embeddings`（`adds` / `deletes`）和 `DELETE /api/v1/system/remove-documents`。只做前者，原文还在实例里。

切块默认值在 `server/utils/TextSplitter/index.js`：`chunkSize` 1000，`chunkOverlap` 20。工作区 schema 默认 `similarityThreshold` 0.25、`topN` 4、`chatMode` `chat`、历史 20 条。这些都是可改的实例设置，不是 Knocket 那种写死的 10 条上限。

内置嵌入器默认模型写在 env 示例里：`EMBEDDING_ENGINE=native`，`EMBEDDING_MODEL_PREF=Xenova/all-MiniLM-L6-v2`。条款写这个 ONNX 模型可能从 Mintplex CDN 下载一次并缓存在本机。完全断网要自己放入模型，或改用别的嵌入器。

## 5. 知识怎么进去

没有 Knocket 那种 FAQ / URL / 文件各 10 条的实体。知识是工作区文档。

`collector/utils/constants.js` 的转换器覆盖：`.txt` `.md` `.org` `.adoc` `.rst` `.csv` `.json` `.html` `.pdf` `.docx` `.pptx` `.xlsx` `.odt` `.odp` `.mbox` `.epub`，以及一组音频、视频和 `png/jpg/webp`。`.doc` 被注释掉，旁边有 TODO，当前转换器表里没有它。文档上传的 multer 没有 `fileSize`。JSON 体上限在 `server/index.js` 是 `3GB`；这是解析器上限，不是产品承诺的单文件政策。音频转写那条另有 25MB。本次没有上传大文件，不能把「没有代码上限」说成「共享磁盘一定扛得住」。

开发者 API 都要 `Authorization: Bearer <api_keys.secret>`。`validApiKey` 只查这张表，不查角色。密钥必须留在服务器，不能进公开脚本。实例上的接口说明在 `/api/docs`，这是[官方文档](https://github.com/Mintplex-Labs/anythingllm-docs/blob/main/pages/features/api.mdx)的说法，本次没打开一个运行中的 `/api/docs`。

已读到的文档与工作区路由：

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| POST | `/api/v1/document/upload` | 上传文件，可用 `addToWorkspaces` 顺手嵌入 |
| POST | `/api/v1/document/upload/:folderName` | 上传到指定文件夹 |
| POST | `/api/v1/document/upload-link` | 抓一个 URL，或一次提交一组 URL |
| POST | `/api/v1/document/raw-text` | 不传文件，直接交原文和元数据 |
| GET | `/api/v1/documents` | 列出文档 |
| GET | `/api/v1/document/accepted-file-types` | 当前接受的类型 |
| POST | `/api/v1/workspace/:slug/update-embeddings` | 把已解析文档加入或移出工作区 |
| POST | `/api/v1/workspace/:slug/update-pin` | 钉住文档，全文进上下文，不再走 RAG 片段 |
| DELETE | `/api/v1/system/remove-documents` | 从系统里永久删除 |
| POST | `/api/v1/workspace/:slug/chat` 与 `stream-chat` | 用 API 密钥对工作区聊天，不是公开挂件 |
| POST | `/api/v1/openai/chat/completions` | OpenAI 兼容聊天。`model` 必须是工作区 slug |
| POST | `/api/v1/embed/new` 等 | 用 API 密钥管理挂件配置和读取挂件聊天 |

采集器还有这些连接器，都在 `collector/utils/extensions/`：GitHub、GitLab、Gitea、Confluence、YouTube 字幕、Obsidian、Drupal Wiki、Paperless-ngx。网站深度抓取是 `POST /ext/website-depth`，默认 `depth = 1`、`maxLinks = 20`，调用前要过 `verifyPayloadIntegrity`。这不是公开挂件接口，也不是整站爬虫产品。

没有 WordPress、WooCommerce、Shopify 连接器。`experimental_live_file_sync` 是实验功能，看的是已进入系统的文件队列，不是 Woo 的发布状态。

和本站约束的关系：WooCommerce 仍是商品的唯一维护点。AnythingLLM 的 My Documents 是派生副本。它可以存在，但保存、下架、删除必须由本站调用上面的 API 去重建或失效。仓库里没有这段同步。只把公开产品页丢给 `upload-link`，下架后向量不会自己消失。

挂件路径本身不做检索以外的代理。`streamChatWithForEmbed` 写明：`automatic` 模式对 embed 无效，强制改成 `chat`。网站访客不会走到 `@agent`、MCP 或计算机使用。那些是登录后的工作区能力。

## 6. 模型

`docker/.env.example` 是提供方名单的一手清单，不是本次连通性测试。聊天提供方包括 OpenAI、Gemini、Azure OpenAI、Anthropic、LM Studio、LocalAI、Ollama、Together、Mistral、Perplexity、OpenRouter、Groq、KoboldCPP、text-generation-webui、generic-openai、LiteLLM、Cohere、Bedrock、Vertex、DeepSeek、xAI、Foundry，以及容器内的 `embedded_lemonade`。DeepSeek 的键是 `DEEPSEEK_API_KEY` 和 `DEEPSEEK_MODEL_PREF`，默认示例模型名是 `deepseek-chat`。generic-openai 可以指到任意 `/v1` 兼容端点。

嵌入提供方包括 native、OpenAI、Azure、LocalAI、Ollama、LM Studio、Cohere、Voyage、LiteLLM、generic-openai、Gemini、OpenRouter、Lemonade。聊天模型和嵌入模型是分开选的。可以聊天走自己的密钥，嵌入留在本机，也可以反过来。Knocket 把嵌入密钥留在平台、只让用户带聊天密钥；这里两套都可以自己管。

请求正文会离开实例，当且仅当选了外部提供方。这一点条款写了，代码路径也是实例直接请求该提供方。没有在本票验证 DeepSeek 官方端点的具体模型名是否与示例一致。

## 7. Embed 挂件实际做什么

挂件源码是子模块 [anythingllm-embed](https://github.com/Mintplex-Labs/anythingllm-embed)。主应用发布预构建文件 `frontend/public/embed/anythingllm-chat-widget.min.js`。要改挂件，改子模块再构建，不是改 WordPress。

管理端复制出的脚本是：

```html
<script
  data-embed-id="挂件 uuid"
  data-base-api-url="https://你的实例/api/embed"
  src="https://你的实例/embed/anythingllm-chat-widget.min.js">
</script>
```

`data-base-api-url` 指向实例自己。访客浏览器随后请求：

- `POST /api/embed/:embedId/stream-chat`
- `GET /api/embed/:embedId/:sessionId`
- `DELETE /api/embed/:embedId/:sessionId`

会话号是挂件在 `localStorage` 里生成的 UUID，键名 `allm_<embedId>_session_id`。刷新页面会续上同一会话。重置会 DELETE 该会话历史。

公开流式接口没有 API 密钥。门是 `canRespond`：

- 挂件必须 `enabled`。
- `sessionId` 必须是 UUID。
- 消息不能空。
- `chat_mode` 必须是合法模式。schema 默认是 `query`。
- 可选的每日上限和每会话上限，窗口都是 24 小时。0 表示不限制。
- 域名名单存在 `allowlist_domains`。空名单默认接受任意 Origin。设置环境变量 `EMBED_REQUIRE_ALLOWLIST` 后，空名单改成拒绝。比较的是 `scheme://host[:port]`，不是随便一段字符串。`v1.18.0` 的 release note 已经修过 origin 比较。

流式响应头写了 `Access-Control-Allow-Origin: *`。全局 CORS 是 `origin: true`。挂件要给任意已允许的站点用，这是预期行为，也是必须把实例当成公开 API 来防护的原因。

历史和删除接口只检查 embed 是否存在，不检查域名名单，也不检查 `enabled`。知道 embed UUID 和 session UUID 的人可以读或作废该会话。UUID 不可猜，但不是登录凭证。

访客看不到检索片段。流式块里的 `sources` 被写成空数组。完整 `sources` 存在 `embed_chats.response` JSON 里。管理端聊天行只渲染 JSON 里的 `text`，不渲染 `sources`。所以「存了出处」不等于「访客或坐席界面看得到出处」。

`query` 模式在没有向量，或本次和历史都没有命中时，返回工作区的 `queryRefusalResponse`，默认英文是 “I do not have enough information to answer that. Try another question.” `chat` 模式没有这个限制，会在没有资料时也回答。网站客服若要「只根据找到的内容回答」，必须选 `query`，并接受拒答句。

挂件 README 里已有、Knocket 挂件也有的表面设置：问候语、助手名、图标、按钮色、气泡色、位置、打开时展开、默认建议句、界面语言、支持邮箱。支持邮箱是三点菜单里的 `mailto` 草稿，不是收件箱。`data-username` 只是写进日志的标识，不是聊前表单。

Embed 语言包在 `embed/src/locales/resources.js`：en、zh、zh-TW、es、de、fr、pt、ar、vi、ko、ru、it、he、nl、fa、tr、da、ja、lt。这是界面字符串，不是回答语言。回答语言取决于模型和系统提示。

iframe 嵌入在 README 里仍是 “work in progress”。不要把它当成现成的第二种嵌入。

管理接口在登录会话下，多用户时要求 admin：

- `GET /api/embeds`、`POST /api/embeds/new`、`POST /api/embed/update/:embedId`、`DELETE /api/embed/:embedId`
- `POST /api/embed/chats` 分页查看，`DELETE /api/embed/chats/:chatId` 删除一条

单用户模式会跳过角色检查，所以 Docker 单用户也能建挂件。查看记录还要过 `chatHistoryViewable`。界面上的操作是查看和删除，没有回复框。

公开挂件的请求体上限是 `1mb`。`ChatService.streamChat` 只提交 `message`、`sessionId`、`username` 和可选的 prompt/model/temperature。界面里的 `attachments` 参数没有传给这个请求。访客不能在挂件里发文件。登录后的工作区聊天可以拖文件，那是另一条界面，[文档](https://github.com/Mintplex-Labs/anythingllm-docs/blob/main/pages/chatting-with-documents/introduction.mdx) 写它从 1.8.5 起可用，而且嵌入后对该工作区的所有线程可见。

## 8. 和 Knocket 使用方式对照

Knocket 的边界来自已关闭的 [#87](https://github.com/AssassinFlash/metal-divider/issues/87)，这里只用来对照，不重核。

| Knocket 使用方式 | AnythingLLM + Embed | 缺口 |
| --- | --- | --- |
| 网站右下角挂件，脚本指向厂商 | 有。脚本指向自己的实例 | 无厂商脚本，但实例必须公网可达 |
| 挂件外观、问候、语言 | 有，且语言包含本站二期名单里的 zh、es、pt、de、fr、ar、vi | 回答语言不是挂件语言包 |
| 开聊前的短 FAQ | 只有逗号分隔的建议句，不是可维护的 FAQ 知识 | 若要短 FAQ，做成建议句或另写页面 |
| 聊前姓名、邮箱、电话 | 没有。只有可选 `data-username` | 要表单就得改挂件或在 WordPress 先收集再传入 |
| 在线时段、离线留言 | 没有 | 另一项目或自写 |
| 只根据资料回答 | `query` 模式有，未命中则拒答 | 必须主动选择；`chat` 模式会脱离资料 |
| 访客看见出处 | 流式响应把 sources 清空 | 出处在库里，不在挂件上 |
| FAQ / URL / 文件各 10 条、文件 10MB | 没有这个上限。类型更多，`.doc` 除外 | 上限要自己在同步程序里定 |
| URL 深度 | 单链 API，另有深度抓取，默认深度 1、最多 20 链 | 不是开箱的整站爬虫 |
| 知识与向量在自己机器 | 默认 SQLite + 磁盘文档 + LanceDB | 选云模型或云向量库时，对应数据会离开 |
| 自己带聊天密钥，嵌入由平台管 | 两套都能自己管，也能分开 | 无 |
| 统一收件箱 | 只有挂件日志表，管理员查看和删除 | 没有坐席回复 |
| 人工接管、恢复 AI | 源码里唯一的 handoff 字符串是工作区 `@agent` 切换提示，embed 还禁用了 automatic 模式 | 不存在转人工 |
| Telegram 收访客消息并回复 | 有 Telegram，但是站长自己的机器人，单用户模式专用，多用户会断开 | 不能当客服通道 |
| Email 入站 | 没有 | 另一项目 |
| WhatsApp / Instagram / Messenger | 挂件选项里没有这些外链按钮 | 若要图标，得改挂件或放在 WordPress 页面上 |
| 浏览器推送新访客消息 | Web Push 存在，但发送点在定时任务完成，不在 embed 聊天 | 不能当坐席提醒 |
| 会议链接 | 没有 | 页面自己放 |
| 联系页 `knocket.ai/<slug>` | 没有托管联系页 | 用 WordPress 页面加同一段脚本 |
| 移动 WebView | 没有访客专用移动 SDK。另有 GPL 口袋代理，服务对象是操作者 | 手机站用同一挂件即可，不是原生聊天 SDK |
| WordPress 插件只贴脚本 | wordpress.org 没有 AnythingLLM 插件 | 贴脚本这件事很小，同步资料不是 |

## 9. WordPress 与收件箱

2026-10-26 查 WordPress.org Plugins API 1.2：

- `anythingllm`：0 条。
- `useanything`：0 条。
- `mintplex`：1 条，slug `speechable`，是文章转语音，主页 `https://github.com/tansihmittal/speechable/`，与 AnythingLLM 无关。

主仓库 `9b1fcab` 全文检索没有 `wordpress`、`woocommerce`、`chatwoot`。没有官方连接器，也没有在本仓库看到可直接复用的第三方集成代码。私有或未上架插件无法用这次检索排除。

因此 WordPress 接入只能是本站代码，而且范围可以很小：

1. 在子主题或自有插件里输出官方那段 script。`data-embed-id` 和脚本地址可以是设置项。不要把开发者 API 密钥放进页面。
2. 若要识别访客，用 `data-username` 传一个本站生成的不透明 ID。它只出现在挂件日志里。
3. 商品、页面、问答的原文由本站在发布、更新、下架时调用 `raw-text` 或 `upload`，再 `update-embeddings`。下架必须 `deletes`，彻底删除还必须 `remove-documents`。Woo 的发布状态仍是能不能被看见的依据，AnythingLLM 只保存派生副本。
4. 挂件域名名单应写上正式站和评审站的 origin，并打开 `EMBED_REQUIRE_ALLOWLIST`，避免空名单接受任意网站。这个环境变量是否已进入 `v1.18.0` 镜像，本次没有核对。

收件箱不能靠这套代码接上。Chatwoot、邮件、WhatsApp 入站、坐席回复，都不在 AnythingLLM 的路由里。若另一张研究票选定了收件箱，连接方式不是现成插件，而是：要么访客在挂件里点到另一个入口，要么自写代码把 `embed_chats` 抄进收件箱。后者要动会话模型，因为挂件没有「人工插入一条回复并推回访客」的接口。抄日志做不到接管。

## 10. 能不能少量整合

能用，但不能整包替换 Knocket。

可以直接用的：

- Docker 自托管实例，默认磁盘存储。
- 官方挂件脚本，贴进 WordPress 页脚。
- 开发者 API 做资料进出和挂件配置。
- 自有或兼容的聊天、嵌入端点。DeepSeek 在提供方名单里，但是否采用仍不是本票的决定。

必须另找项目或自写的：

- 人工接管和统一收件箱。
- 邮件、Telegram 访客通道、WhatsApp。AnythingLLM 的 Telegram 不能顶替。
- 聊前线索、在线时段、离线留言、访客附件。
- Woo 发布状态到向量库的同步与失效。这是本站约束要求的连接代码，不是 AnythingLLM 的功能。
- 把挂件日志里的 sources 显示给访客或坐席。数据在，界面不画。

不该做的：

- 把 AnythingLLM 装进当前共享主机。
- 把 My Documents 当成可以独立上下架商品的第二资料源。
- 用 Cloud 代管来换「少运维」。那会把原文、向量和聊天交给 Mintplex 的 AWS 实例，和「存储自己控制」相反。
- 为了客服去装 GPL 移动端或 AGPL 的 Google 桥。网站方案用不到它们。

组合价值：它是这条路线里成熟、仍在发版、许可证允许自托管的知识库加网站挂件。价值在于不用从零写切块、向量库、多模型和工作区。它补不上 Knocket 的收件箱半边。单独采用会得到一个没有人工接管的公开聊天机器人，而且资料同步仍要自己写。

## 11. 未验证

- 没有 `docker pull`，没有跑健康检查，没有创建挂件，没有走完一次 `query` 拒答。
- 没有对 `v1.18.0` 与 `9b1fcab` 做全量 diff。`:latest` 按文档会领先已发布标签。
- 没有打开桌面版，确认文档所说的「仅 Docker」是否仍被打包脚本裁掉。
- 没有转录 Cloud 价格图，没有登录结账页。
- 没有测量 146 个图案嵌入后的内存、CPU 和响应时间。2GB 是官方最低值，不是本站容量结论。
- 没有验证 DeepSeek 示例模型名在 2026-10-26 是否仍可调用。
- WordPress.org 零结果只覆盖公开插件目录。
- 内置嵌入器的 CDN 回退、遥测关闭后的网络行为，以条款和设置为准，本次没有抓包。
