# Tiledesk 一体化开源 AI 客服的完整性与可整合性

- 票：[研究：Tiledesk 一体化开源 AI 客服的完整性与可整合性](https://github.com/AssassinFlash/metal-divider/issues/92)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/tiledesk-open-source`（从 `origin/main` 的 `9da89ab7937f8e4e2db3c989b37f6bcdeab370b4` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-92-tiledesk`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树与可重建下载物；不删除远程分支，不删除本文件

本文件只回答：Tiledesk 自托管开源版能否一套覆盖知识库 RAG、网站聊天、统一收件箱和人工接管；仓库如何组成；数据落在哪里；WordPress 如何嵌入；模型、渠道、部署和许可证是否适合低自研整合。对照物是 [Knocket 研究](https://github.com/AssassinFlash/metal-divider/blob/research/knocket-capabilities/docs/research/2026-10-26-knocket-capabilities.md) 已确认的使用方式，不重做其他项目评估，也不决定 AI 与询盘或资料权限的关系。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 2026-10-26 直接读到的公开仓库、npm 包、Docker Hub 标签、WordPress.org 插件源码、官方 Markdown 文档 |
| 官方宣称 | 官方帮助中心、部署 README、定价页、插件 readme 中的句子，本次未注册、未起容器、未实走 |
| 合理推断 | 由已读源码或部署文件推出、但没有运行实例读回的判断 |
| 无法确认 | 材料没写、两份一手材料矛盾，或必须登录/起容器才能核实 |

未安装生产，未执行 `docker compose up`，未注册 Tiledesk 云账号，未提交聊天，未上传知识。Google AI Overview 不作为事实。

## 结论速览

1. Tiledesk 不是一个可装进 WordPress 的开源客服。它是一组容器：API 服务端、仪表盘、Design Studio、Chat21 挂件、坐席端、消息 HTTP 服务、消息服务、MongoDB、Redis、RabbitMQ，再加上可选的 chatbot 引擎、LLM 训练/问答服务和 Qdrant。官方自己把 Docker Compose 标成 beta，把 Helm 写成部署模板，不是开箱生产件。
2. 网站聊天、收件箱、人工接管、RAG 在源码和文档里都有对应物。能把四者放进同一份编排文件的，是 [docker-compose.yml](https://github.com/Tiledesk/tiledesk/blob/master/docker-compose/docker-compose.yml)，不是 Helm。Helm 1.1.105 默认 `KNB:F`，不部署 LLM、Qdrant 或 chatbot，却把问答端点指向不存在的服务名。
3. 不能把它当成「克隆官方仓库即可审计」的开源栈。`Tiledesk/tiledesk-server` 与 `chat21/chat21-server` 在 2026-10-26 返回 404。服务端以 npm `@tiledesk/tiledesk-server@3.0.1` 和 Docker 镜像发布。同一份 npm 包里，`package.json` 和 `publiccode.yml` 写 AGPL-3.0，包内 `LICENSE` 文件写 MIT。这是许可证硬伤，不是措辞差异。
4. 官方 WordPress 插件 1.0.11 只把云端挂件插进页脚，默认打到 `api.tiledesk.com`、`panel.tiledesk.com`、`widget.tiledesk.com`。readme 提供三个 filter 改自托管地址，但插件不保存消息或知识，GitHub 主页仓库已 404，测试上限停在 WordPress 6.6.2。
5. 默认数据路径不是自控。服务端默认向量库是 Pinecone，默认嵌入是 OpenAI `text-embedding-ada-002`。官方 RAG 文章写明：原文上传后删除，分块进向量库，嵌入请求发往 OpenAI。Compose 把向量库改成本地 Qdrant，但嵌入仍要另配才会离开 OpenAI。对话落在本机 Mongo 的 `tiledesk` 与 `chat21` 库，前提是不用他们建议的外部 Mongo，也不走云端邮件域 `tickets.tiledesk.com`。
6. 相对 Knocket，Tiledesk 的渠道、多知识库、sitemap 和可视化接管更全，也没有 10/10/10 那条官方上限。代价是另一套 Docker 主机、版本钉扎互不一致、许可证无法干净引用，以及一份把公开网页抄进向量库的副本。这不是「少量胶水接到现有共享主机 WordPress」能完成的。

## 1. 仓库与运行时怎么组成

官方组织是 [github.com/Tiledesk](https://github.com/Tiledesk)。部署说明把产品拆成这些运行时（[部署 README](https://github.com/Tiledesk/tiledesk/blob/master/README.md)）：

| 运行时 | 公开源码（2026-10-26） | 编排里的镜像 | 许可证证据 |
| --- | --- | --- | --- |
| 部署编排 | [Tiledesk/tiledesk](https://github.com/Tiledesk/tiledesk)，Helm chart `1.1.105`，提交 `3e6a21f2d2`（2026-10-23） | 无，这是编排仓库 | GitHub SPDX：MIT。Chart `sources` 仍写已改名的 `tiledesk-deployment` |
| API 服务端 | GitHub `Tiledesk/tiledesk-server` **404**。npm `@tiledesk/tiledesk-server@3.0.1`（2026-10-23，578 个文件，解包约 5.4MB）仍把 `repository` 指回该 404 地址 | `tiledesk/tiledesk-server` | 见第 3 节，三套声明互相矛盾 |
| 仪表盘 | [tiledesk-dashboard](https://github.com/Tiledesk/tiledesk-dashboard)，`package.json` 版本 `2.8.34`，提交 `07830dde24`（2026-10-26） | `tiledesk/tiledesk-dashboard` | GitHub SPDX 与 `package.json`：MIT |
| Design Studio | [design-studio](https://github.com/Tiledesk/design-studio)，源码已到 `1.41.1`（2026-10-23）；镜像 `latest` 为 `1.41.2` | `tiledesk/design-studio` | GitHub SPDX：MIT |
| 自动化引擎 | [tiledesk-chatbot](https://github.com/Tiledesk/tiledesk-chatbot)，源码与镜像均为 `3.0.0`（2026-10-23） | `tiledesk/tiledesk-chatbot` | GitHub SPDX：MIT。服务端另依赖 `@tiledesk/tiledesk-tybot-connector@^2.3.0`，有 `TILEBOT_ENDPOINT` 时改打外部引擎 |
| RAG 服务 | [tiledesk-llm](https://github.com/Tiledesk/tiledesk-llm)，`pyproject.toml` 版本 `0.12.4-rc1`，最近提交 2026-10-08 | `tiledesk/tiledesk-backend-llm`，Hub `latest` 停在 2026-10-15，`0.12.3-rc9` 更新（2026-10-16） | `pyproject.toml` 写 `license = "MIT"`；仓库无 `LICENSE` 文件，GitHub 许可证检测为 null |
| 访客挂件 | 应看 [Tiledesk/chat21-web-widget](https://github.com/Tiledesk/chat21-web-widget) 分叉，`5.3.0`（2026-10-20）。上游 [chat21/chat21-web-widget](https://github.com/chat21/chat21-web-widget) 的 `package.json` 仍是 `5.0.24-rc.2` | `chat21/chat21-web-widget` | 分叉 `package.json`：MIT。上游 `LICENSE` 文件是 MIT，旧 `package.json` 字段写 AGPL-3.0 |
| 坐席端 | [Tiledesk/chat21-ionic](https://github.com/Tiledesk/chat21-ionic) 2026-08-31 提交写 `3.4.35 in PROD`。上游 chat21 组织副本最后代码提交在 2022–2023 | `chat21/chat21-ionic`，Hub 有 `3.4.35`（2026-08-31） | 分叉仓库 GitHub SPDX：MIT |
| 消息 HTTP | [chat21/chat21-http-server](https://github.com/chat21/chat21-http-server)，`0.3.1`（2026-10-13） | `chat21/chat21-http-server:0.3.1` | GitHub SPDX：MIT |
| 消息服务 | `chat21/chat21-server` **404** | `chat21/chat21-server`，Hub 有 `1.1.0`（2026-10-07）与 `latest`/`1`/`1.1` | 无公开许可证文件可核 |
| 企业模块 | npm 名 `@tiledesk-ent/tiledesk-server-enterprise`、`tiledesk-server-graphql`、`tiledesk-server-jwthistory` | 部署 README 写企业客户需向 `info@tiledesk.com` 要凭据拉私有镜像 | 公开 npm 包只 `require` 它们，缺失则记日志跳过 |

消息层不在 Tiledesk 组织里单独叫 `chat21-server`。公开的只有 HTTP API。MQTT/AMQP 那一层以镜像交付。

`pushed_at` 不能当维护证据。`tiledesk-ios-app` 与 `tiledesk-android` 的 `pushed_at` 是 2026-10-06，最后提交都是 2021-11-11 的 `Update README.md`。同日被摸过时间戳的仓库很多。下面只采用提交日期或镜像 `last_updated`。

活跃、且 2026-10 仍有代码提交的公开面：仪表盘（10-26）、Design Studio（10-23）、chatbot（10-23）、部署 chart（10-23）、挂件分叉（10-20）、tiledesk-llm（10-08）、chat21-http-server（10-13）、坐席端分叉（08-31）。服务端没有公开 Git 提交可看；npm 与 Docker Hub 在 10-23 发布了 `3.0.1`。

## 2. 当前精确版本，以及三套钉扎不是同一套

查询时间 2026-10-26。Docker Hub `tiledesk/tiledesk-server` 有 2377 个标签，`latest` 与 `3.0.1` 同摘要，更新于 2026-10-23 14:56 UTC。npm `latest` 也是 `3.0.1`，时间 2026-10-23 14:56:30 UTC。

| 面 | Helm 1.1.105 钉扎 | Compose 文件钉扎 | 同日 Hub `latest` |
| --- | --- | --- | --- |
| 服务端 | `2.17.20`（镜像 2026-10-22） | `docker-compose.yml`：`2.13.23-op1`（镜像 2025-10-13）；`docker-compose-latest.yml`：`:latest` | `3.0.1`（2026-10-23） |
| 服务端 worker | `2.17.20` | 该文件未单独列出 | 未核独立标签 |
| 仪表盘 | `2.7.165-im`（2026-08-28） | `2.7.134-stage` 或 `:latest` | `2.8.34`（2026-10-26） |
| Design Studio | `1.40.8-im`（2026-08-31） | `1.39.9-rc3` 或 `:latest` | `1.41.2`（2026-10-23） |
| 挂件 | `5.2.3`（2026-06-10） | `5.1.2` 或 `:latest` | `latest` 更新于 2026-10-20；源码分叉已标 `5.3.0` |
| 坐席端 | `3.4.31` | `3.4.22` 或 `:latest` | `3.4.35`（2026-08-31） |
| chat21 HTTP | `0.3.1` | `0.2.37` 或 `:latest` | `latest` 更新于 2026-10-13 |
| chat21 server | `1.1.0`（2026-10-07） | `0.2.53` 或 `:latest` | `latest`/`1`/`1.1` 均为 2026-10-07 |
| chatbot | Helm 模板不部署；`TILEBOT_ENDPOINT` 被注释 | `2.0.32-rc8` 或 `:latest` | `3.0.0`（2026-10-23） |
| LLM | Helm 模板不部署 | `0.5.3-rc1` 或 `:latest` | `latest` 落后于 `0.12.3-rc9` |
| 向量库 | 无 | `qdrant/qdrant:latest` | 浮动标签 |
| Mongo / Redis | Bitnami chart | `mongo:7.0.2`、`redis:7.0.5` | 编排钉死，不是 Hub latest |

Helm chart 提交在 2026-10-23 13:42 UTC，比 `3.0.1` 镜像早约一小时。所以「今天的官方 chart」并没有跟上「今天的官方镜像」。

Compose README 写 Community Compose「安装最新开发版」，给出的下载命令却是：

`https://raw.githubusercontent.com/Tiledesk/tiledesk-deployment/master/docker-compose/docker-compose.yml`

该路径现在落到 `Tiledesk/tiledesk` 的 `docker-compose.yml`，服务端镜像是 2025-10-13 的 `2.13.23-op1`，不是 `3.0.1`。README 与它让人下载的文件不是同一套。

GitHub Releases 只有 2021 年的 `1.1` / `1.2`。现行版本在 Helm changelog、npm 和镜像标签上，不在 Release。

## 3. 开源与付费边界

### 许可证不能引用成单一结论

同一份 `@tiledesk/tiledesk-server@3.0.1`：

- npm `license` 字段：`AGPL-3.0`
- 包内 `publiccode.yml` 的 `legal.license`：`AGPL-3.0-only`
- 包内 `LICENSE` 文件正文：MIT，版权行 `Copyright (c) 2020 Tiledesk`
- 依赖 `@tiledesk/tiledesk-chat21-app@^1.1.8` 的 npm `latest` `1.1.11` 自己声明 `AGPL-3.0`，仓库 URL 同样指向已 404 的 `tiledesk-server`

部署仓库、仪表盘、Design Studio、chatbot、挂件分叉可以分别引用 MIT。这不能把服务端一起洗成 MIT。在出版方把这三份声明改成同一份之前，不能把服务端写成「MIT，可随意并进专有站」或「已确认必须按 AGPL 公开全站」。能确认的是：许可证位置互相矛盾，且规范源码仓库不存在。这是整合硬伤。

tiledesk-llm 只在 `pyproject.toml` 写了 MIT，没有许可证正文。GitHub 检测不到许可证。使用前应要求补 `LICENSE`，不能靠 README 的 “open source” 句子。

WordPress 插件是 GPLv2 or later。它与服务端是不同作品；页脚插脚本本身不把 AGPL 传染进主题。若改服务端再对外提供网络服务，才进入 AGPL 第 13 条的修改件源码提供问题。本次不下法律结论，只记下触发点。

### 社区开关、功能串、企业包是三套门

服务端 `models/profile.js`：环境变量 `COMMUNITY_VERSION=true` 时，新建项目默认名 `Custom`，坐席上限 1000，订阅到期写死 2099-12-31，配额 `chatbots/kbs/namespace` 各 10000，并把 `knowledgeBases`、`telegram`、`messenger`、`chatbot`、`widgetUnbranding`、`copilot`、`webhook`、`smtpSettings`、`reindex` 标为 true。不设该变量时，默认名是 `Sandbox`，坐席上限 0，类型 `free`，这些 customization 为 undefined。

`COMMUNITY_VERSION=true` 只出现在两份 Compose 文件。Helm `values.yaml` 与 `server-deployment.yaml` 都没有这个变量。按源码，Helm 默认安装不会走进上述社区默认值。

功能串是另一套门，仪表盘用 `-` 分段、`CODE:T/F` 控制菜单。`home.component.ts` 里读到的包括 `PAY`、`ANA`、`APP`、`OPH`、`HPB`、`KNB`。`KNB:F` 隐藏知识库入口。其余代码本次没有逐个对上名称，不猜测。

两份官方默认串不同：

- Compose：`KNB:T`，同时 `ANA:T`、`OPH:T`、`DEP:T`、`GPT:T`
- Helm `values.yaml`：`KNB:F`，同时 `ANA:F`、`OPH:F`、`DEP:F`，串里没有 `GPT`

所以「官方 Helm」默认不显示知识库；「官方 Compose」显示。这不是文档口误，是两份提交进仓库的配置。

配额是第三套门。`QuoteManager.js` 在 `QUOTES_ENABLED=false` 时跳过计数。Compose 设了 `false`。Helm 默认也是 `false`。云端计划常量仍在源码里：Sandbox 200 次会话 / 10 万 token / 2 个 chatbot / 50 条知识；Pro 3000 / 500 万 / 20 / 300；Business 5000 / 1000 万 / 50 / 1000。这些是云计费表，不是 Compose 在 `QUOTES_ENABLED=false` 时的执行上限。

企业包是第四套门。`modulesManager.js` 尝试加载 `@tiledesk-ent/*`。公开包没有这些依赖，加载失败就当模块不存在。部署 README 写明企业镜像是私有的，要邮件索取凭据。Helm 把 `LICENSE_KEY` 放进名为 `tiledesk-license` 的 Secret。本次没有企业凭据，不能列出私有镜像多出来的功能。能确认的是：公开 npm 不包含这些模块，缺失时服务继续启动。

云定价是另一条产品，不是自托管报价。2026-10-26 的 [定价页](https://tiledesk.com/chatbot-features-pricing/) 写 Pro €149/月（年付 €124），3 用户、20 个 AI Agent、每月 500 万 token、3000 次会话、300 条知识库；Business €499/月（年付 €416），5 用户、50 Agent、1000 万 token、5000 次会话、1000 条知识库；Enterprise 为联系销售、不限量。页首写 14 天试用、不需信用卡。插件 readme 仍写 30 天 Pro 试用和试用结束后可留在免费计划。两份官方文本不一致。定价页摘录里没有与 Pro 并列的免费档。

## 4. 部署依赖：不能放进现有 WordPress 站点

Compose 实际拉起的进程包括 dashboard、cds、webwidget、ionic、chatbot、server、chat21 HTTP、chat21 server、两个 LLM 容器、nginx 代理、Qdrant、RabbitMQ、Mongo 7.0.2、Redis 7.0.5。README 点名要空出的端口包括 3000、4200、4500、8081、8082、8004、5672、15672、1883、15675、27017。这不是 PHP 插件的运行时。

官方 [容器支持政策](https://guide.tiledesk.com/containerization-support-policy.md) 写：他们支持自己的镜像，不支持客户选的 Docker Compose、Kubernetes、脚本或云编排；Helm 与 Compose 是参考模板。部署 README 又写 Helm「不应原样用于生产」，生产 Mongo 建议用 MongoDB Atlas 或外部副本集，而不是 chart 自带的 Mongo。若照这条生产建议做，对话和知识元数据会离开本机，进 Atlas。Compose 文件本身用本地 `mongo:7.0.2` 卷 `tiledesk-datadb`，这条路径才是进程在本机的存储。

Compose README 把该文件标为 beta，并公布默认管理员 `admin@tiledesk.com` / `superadmin`。同一份 Compose 还把 `JWT_SECRET` 设为 `tokenKey`，并提交了多枚长寿命 JWT。这些是公开仓库里的默认值，不是本次发现的私密密钥。不改就暴露，等于没有自托管。

Helm 的知识库端点默认是字符串 `CHANGEIT`。未覆盖时，`KB_ENDPOINT_QA` / `KB_ENDPOINT_TRAIN` 会指向 `*-backendllm-qa` 和 `*-backendllm-train`，但 chart 的 `templates/` 没有这两个 Deployment，也没有 Qdrant。Helm 路径在本次读到的文件里组不成 RAG。

本次未测内存和磁盘下限。不采用搜索摘要里的「4GB / 40GB」说法，官方 Compose README 没有这两个数字。

## 5. 数据实际写到哪里

| 数据 | 落点 | 等级 |
| --- | --- | --- |
| 项目、坐席、请求、知识元数据 | Mongo 库 `tiledesk`。Compose：卷 `tiledesk-datadb`。Helm 生产建议：外部 Mongo 或 Atlas | 实测 Compose 环境变量与 mongoose 模型；Atlas 是官方建议 |
| 即时消息 | Mongo 库 `chat21`（HTTP 服务与 chat21 server 的 `MONGODB_URI`） | 实测 Compose |
| 上传文件、头像 | 服务端 `fileGridFsService.js` 使用 Mongo GridFS | 实测源码 |
| 会话缓存 | Redis。`CACHE_ENABLED=true` 时是缓存，不是主库 | 实测 Compose |
| 投递 | RabbitMQ，挂件走 MQTT WebSocket `/mqws/ws` | 实测 Compose |
| 向量 | 代码默认 Pinecone：`config/kb/engine.js` 的 `VECTOR_STORE_NAME \|\| 'pinecone'`，索引类型默认 `serverless`。Compose 覆盖为 `qdrant`，主机 `qdrant`，端口 6333，卷 `./qdrant_storage` | 实测源码与 Compose |
| 嵌入 | 默认提供方 `openai`，模型 `text-embedding-ada-002`，维度 1536。密钥来自 `GPTKEY` 或项目级集成设置 | 实测 `config/kb/embedding.js` 与 Compose README |
| 知识原文 | Mongo `KB` 模型有可选 `content` 字符串、`source`、`url`、`type`。官方文章写上传文件在解析后删除，分块文本与向量放在向量库 | 模型是实测；「删除原文」是官方文章，未在 LLM 索引器里逐行对上 |
| 未答问题 | `kb_setting.js` 默认 7 天 TTL，可用 `UNANSWERED_QUESTION_EXPIRATION_TIME` 改 | 实测源码 |
| 云端邮件工单 | 文档地址是 `support@<Project_Id>.tickets.tiledesk.com` | 官方宣称。Compose 只有出站 `EMAIL_*`，没有入站 MX |

官方 [RAG 工作方式](https://guide.tiledesk.com/ai-chatbots-and-automation/knowledge-base/how-does-the-knowledge-base-work.md) 写的是云端基本服务：分块经 HTTPS 送到 OpenAI 做嵌入，向量与原文分块放进 Pinecone，查询时再次把问题送给 OpenAI。同一页把「整套装在本地、用 Qdrant/LLAMA」写成加强数据保护的可选项，不是默认。

因此「自己控制知识与存储」只有在同时满足这些条件时才成立：向量库保持 Compose 的本地 Qdrant 而不是代码默认的 Pinecone；嵌入提供方改离 OpenAI；Mongo 用本机卷而不是 Atlas；挂件和 API 不指向 `*.tiledesk.com`；不把入站邮件交给 `tickets.tiledesk.com`。漏掉任何一项，对应的数据就在别人的服务上。本次没有把这套覆盖跑起来。

知识类型在源码常量里是 `url`、`text`、`faq`、`pdf`、`docx`。路由另外处理 `sitemap` 和 `txt`。管理文档还写 CSV 导入问答、多 URL、sitemap 刷新。`MAX_UPLOAD_FILE_SIZE` 未设置时，服务端日志写 “infinity”，没有 Knocket 那种 10MB 硬顶。这是源码默认，不是建议值。

## 6. 模型

服务端 Compose 与 Design Studio 的 `AI_MODELS` 是一串 OpenAI 模型加单价，包括 `gpt-5`、`gpt-4.1`、`gpt-4o`、`gpt-4`、`gpt-3.5-turbo`。`OPENAI_ENDPOINT` 默认 `https://api.openai.com/v1`。Compose README 允许每项目另配密钥。

tiledesk-llm README 另列 Anthropic、Google、Groq、Deepseek、Ollama、vLLM，以及多种嵌入（OpenAI、HuggingFace、Ollama、Google、Cohere、Voyage、vLLM、TEI）。这是该仓库 README，不是服务端已经接好的清单。

帮助中心 [Supported LLMs](https://guide.tiledesk.com/ai-chatbots-and-automation/supported-llms.md) 更旧：Cohere `command-r`、Google `gemini-pro`、Anthropic `claude-3-5-sonnet`、一批 Groq 上的 Llama，以及「用户自定义」的 Ollama/vLLM。它没有 README 里的 `gpt-5.2` 或 `claude-opus-4.5`。三份模型名单不能互相代替。DeepSeek 只在 LLM README 出现，没有在服务端 `AI_MODELS` 默认串里。

## 7. WordPress 接入

WordPress.org 插件 [tiledesk-live-chat](https://wordpress.org/plugins/tiledesk-live-chat/) 1.0.11，2024-11-08 更新，活跃安装约 80，测试至 WordPress 6.6.2，要求 PHP 7.0，许可证 GPLv2 or later。插件主页写的 GitHub `Tiledesk/tiledesk-wordpress-plugin` 返回 404。现行源码在 [plugins.svn.wordpress.org/tiledesk-live-chat/trunk](https://plugins.svn.wordpress.org/tiledesk-live-chat/trunk/tiledesk-live-chat.php)。

插件做的事：

- 后台用邮箱密码调用 `auth/signin`，再拉项目列表，把项目 ID 存进 `tiledesk-one-public-key`
- 页脚输出 `window.tiledeskSettings`，只含 `projectid`；已登录 WordPress 用户再附加 `userEmail` 与 `userFullname`
- 加载的脚本默认是 `https://widget.tiledesk.com/v6/launch.js`
- API 默认 `https://api.tiledesk.com/v3/`，控制台默认 `https://panel.tiledesk.com/v3/dashboard/#/project/`
- 卸载只删自己的 option，不碰对话或知识

readme 写「本插件只配合云平台」，同时又给出三个 filter：`tiledesk_api_url`、`tiledesk_console_url`、`tiledesk_jssdk_url`。代码确实调用了这三个 filter。所以自托管地址在代码层能改，但管理界面仍是云登录流，没有自托管安装向导。

挂件地址现在有三套，不能混用：

| 来源 | 脚本 |
| --- | --- |
| 帮助中心安装文 | `https://widget.tiledesk.com/v5/launch.js` |
| 插件 1.0.11 | `https://widget.tiledesk.com/v6/launch.js` |
| Compose | 本机 `WEBWIDGET` 容器，`WIDGET_LOCATION` 为 `{EXTERNAL_BASE_URL}/widget/` |

插件不读 WooCommerce，不索引商品，不保存收件箱。若只用它，知识、对话和模型都在 Tiledesk 云。若改 filter 指向自托管，仍要自己部署整套容器，并自己决定知识从哪来。官方知识库文档的网站接入方式是粘贴 URL 或 sitemap，那是爬取副本，不是 Woo 的实时查询。Woo 仍是商品资料源的约束下，这种爬取会制造第二份可独立过期的商品文本。本次没有找到现成的 Woo 连接器。

帮助中心另有 Shopify MCP 集成页。那是 Shopify，不是 WordPress，不构成本站连接器。

## 8. 与 Knocket 使用方式对照

Knocket 基线来自已关闭的研究票，不在本次重测。下表只标 Tiledesk 侧证据。

| Knocket 已确认的用法 | Tiledesk 开源/自托管侧 | 等级 |
| --- | --- | --- |
| 网站挂件，脚本只带 identifier | 有挂件。云脚本带 `projectid`。自托管要换脚本主机，见上一节 | 实测插件与安装文；自托管路径来自 Compose，未在浏览器打开 |
| `knocket.ai/<slug>` 联系页 | 本次未找到对等的托管联系页。入口是挂件或自建页 | 无法确认有同等物；安装文只有脚本 |
| App WebView | 挂件是网页，可被 WebView 包住。官方 iOS/Android 应用最后提交在 2021，不能当成现行客户端 | 实测提交日期 |
| 统一收件箱 | 仪表盘加 Chat21 坐席端。Compose 与 Helm 都包含 ionic。三入口（站、Telegram、邮件）是否进同一线程，邮件侧依赖云域名 | 坐席端是实测编排；全渠道同一线程未实走 |
| Telegram 入站 | 服务端打包 `@tiledesk/tiledesk-telegram-connector`（npm latest 0.1.15，2025-10-29，声明 MIT，无仓库 URL）。社区默认值打开 telegram | 源码依赖是实测；未接 Bot |
| Email 入站 | 文档是 `*.tickets.tiledesk.com`，或把自有邮箱转发到该域。Compose 只有出站 SMTP | 官方宣称。自托管入站邮件无法确认 |
| WhatsApp / Instagram / Messenger 在 Knocket 是外链 | Tiledesk 服务端打包 WhatsApp 与 Messenger 连接器，并有 WhatsApp Business 官方教程。社区 customization 对象写了 messenger 与 telegram，没有 whatsapp 键。是否因此隐藏界面，本次未追到判断点 | 连接器与教程是实测/官方文；UI 门无法确认 |
| 人工接管、可恢复 AI | 部门路由在 `requestService.routeInternal`。Design Studio 有 `cds-action-agent-handoff`。帮助中心写坐席菜单 Reassign，可转人，也可在打开 “Available to Agents” 后转回机器人 | 源码加官方文；未实走 |
| 自带 OpenAI 兼容密钥 | `GPTKEY`、`OPENAI_ENDPOINT`、项目级密钥。兼容端点是否接受任意 OpenAI 兼容服务，只看到端点可改，未用非 OpenAI 服务试 | 配置是实测；兼容范围未试 |
| FAQ / URL / 文件各 10，文件 10MB | 类型更多，含 sitemap 与多知识库。社区且关闭配额时，源码没有 10 条或 10MB 上限。云计划有 300/1000 条。文件大小默认不封顶 | 源码与定价页 |
| 知识无导出 | 本次未核导出 API。删除内容的路由存在（sitemap diff 会删条目）。不能写成「有完整导出」 | 无法确认导出 |
| 营业时间、离线、预聊天表单 | 帮助中心有营业时间与预聊天表单专页。Helm 默认 `OPH:F`，Compose 为 `OPH:T` | 官方文；未点界面 |
| 浏览器/Telegram 通知 | 帮助中心有通知文。Helm 邮件模板含分配、离线消息、转写。推送引擎 Compose 设为 `PUSH_ENGINE=none` | 模板是实测；推送未开 |
| 官方 WP 插件只插脚本 | 同样只插脚本，并多了云登录。不保存知识或消息 | 实测插件 |

Knocket 没有、Tiledesk 文档或源码有的：多知识库串联、hybrid 检索、可视化流程、WhatsApp 连接器、部门与角色、工单邮件域。这些不自动变成自托管可用，见第 2、3、5 节的门。

## 9. 若要低自研整合，缺的是什么

已经有的，不必从零写：访客挂件、坐席收件箱、部门分配、流程里的转人工、知识库管理 API、Ask Knowledge Base 动作、Qdrant 编排样例、OpenAI 密钥配置。

仍要另做或另找，且不是换一个 README 就能消失的：

1. 一台能跑上述容器的主机。现有 WordPress 共享主机进程装不下这套。插件 filter 只改 URL，不提供运行时。
2. 把三套版本钉扎收成一套，并验证镜像与 npm 包一致。今天没有官方文件同时钉住服务端 `3.0.1`、仪表盘 `2.8.34`、Design Studio `1.41.2`、挂件 `5.3.0`、chatbot `3.0.0` 和现行 LLM。
3. 若知识必须留在自己这边：显式设置 Qdrant、本地嵌入、本地 Mongo，并禁止回落到 Pinecone / OpenAI / Atlas / `tickets.tiledesk.com`。Compose 只做了向量库这一项。
4. WordPress 接入代码：要么在子主题/自有插件里输出自托管脚本，要么给官方插件加 filter。官方插件不能同步 Woo 商品。sitemap 爬取会把公开页复制进 Tiledesk，形成第二份资料，且官方文章称原始上传文件会删，检索靠分块。
5. 入站邮件若要对齐 Knocket 的 Email 通道，不能依赖文档里的 `tickets.tiledesk.com`。Compose 没有这条管道。
6. 联系页与现行原生 App 没有可引用的开源对等物。移动入口若要做，是 WebView 包挂件，不是启用 2021 年的应用仓库。
7. `chat21-server` 与 `tiledesk-server` 的 Git 源缺失。消息总线和服务端缺陷不能通过向官方仓库发 PR 修复，只能换镜像或改 npm 包后自行构建。后者立刻碰上第 3 节的许可证矛盾。

不需要为了「有没有 RAG / 收件箱 / 转人工」再找一个平行的开源客服来补这三块。缺的是可审计源码、一致的部署钉扎、自控默认值，以及 WordPress/Woo 与这套容器之间的连接。连接代码是小的；让默认数据路径留在自己这边，不是小的。

## 10. 不适合直接当生产底座的点

- 服务端许可证三份文件三种说法，且 Git 仓库 404。
- 消息服务 `chat21-server` 无公开仓库，只能信任镜像。
- 官方 Compose 被作者标为 beta，下载命令指向一年前的服务端镜像，README 却说装的是最新开发版。
- Helm 被作者写明不要原样上生产，且默认关知识库、不部署 RAG 容器。
- 默认管理员口令、`tokenKey` 和仓库里的长寿命 JWT 不能原样使用。
- 代码默认把嵌入和向量送到 OpenAI 与 Pinecone。不改环境变量就不是自控知识库。
- 云插件、云挂件文档和自托管挂件是三个地址。装上 WordPress 插件不等于接上自托管。
- 帮助中心、LLM README、服务端 `AI_MODELS` 的模型名单不一致。
- 本次没有运行实例，不能把「文件里写了」写成「容器能起来且四条链路都通」。

## 证据局限

- 未启动 Compose 或 Helm，未对照容器内文件与 npm 包，未测量内存。
- 未登录云控制台，未验证 WhatsApp、Telegram、入站邮件、Reassign 的真实界面。
- 未逐行读 LLM 索引器，因此「上传文件解析后删除」只引用官方文章；Mongo `content` 字段是否保存全文，未在上传路径上追完。
- 企业私有镜像的功能差只知道「要凭据才能拉」，不知道差在哪几个功能。
- 功能串里除已点名的代码分支外，其余缩写没有在源码里逐个对上，表中不解释。
- Apify Google 搜索只用来发现帮助中心 URL。该次返回的 AI Overview 未采用。

## 来源

- 部署仓库与许可证：<https://github.com/Tiledesk/tiledesk>
- Compose：<https://github.com/Tiledesk/tiledesk/blob/master/docker-compose/docker-compose.yml>
- Compose 说明（beta、默认账号、Qdrant、下载 URL）：<https://github.com/Tiledesk/tiledesk/blob/master/docker-compose/README.md>
- Helm chart `1.1.105`：<https://github.com/Tiledesk/tiledesk/blob/master/helm/Chart.yaml>
- Helm 镜像与 `FEATURES_TOKEN`：<https://github.com/Tiledesk/tiledesk/blob/master/helm/values.yaml>
- 容器支持政策：<https://guide.tiledesk.com/containerization-support-policy.md>
- npm 服务端 `3.0.1`：<https://www.npmjs.com/package/@tiledesk/tiledesk-server>
- 服务端 Git 404：<https://github.com/Tiledesk/tiledesk-server>
- 消息服务 Git 404：<https://github.com/chat21/chat21-server>
- 仪表盘：<https://github.com/Tiledesk/tiledesk-dashboard>
- Design Studio 转人工动作：<https://github.com/Tiledesk/design-studio/blob/master/src/app/chatbot-design-studio/cds-dashboard/cds-canvas/actions/list/cds-action-agent-handoff/cds-action-agent-handoff.component.ts>
- chatbot `3.0.0`：<https://github.com/Tiledesk/tiledesk-chatbot>
- LLM：<https://github.com/Tiledesk/tiledesk-llm>
- 挂件分叉：<https://github.com/Tiledesk/chat21-web-widget>
- 坐席端分叉：<https://github.com/Tiledesk/chat21-ionic>
- Chat21 HTTP：<https://github.com/chat21/chat21-http-server>
- 知识库概览：<https://guide.tiledesk.com/ai-chatbots-and-automation/knowledge-base/knowledge-base-overview.md>
- RAG 与 Pinecone/OpenAI：<https://guide.tiledesk.com/ai-chatbots-and-automation/knowledge-base/how-does-the-knowledge-base-work.md>
- 知识库管理（URL、sitemap、问答、CSV）：<https://guide.tiledesk.com/ai-chatbots-and-automation/knowledge-base/knowledge-base-administration.md>
- Ask Knowledge Base：<https://guide.tiledesk.com/ai-chatbots-and-automation/actions-explained/ask-knowledge-base.md>
- 人工接管：<https://guide.tiledesk.com/human-in-the-loop-hitl/human-in-the-loop-chatbot-back-in-the-conversation.md>
- 安装挂件（仍写 v5 云脚本）：<https://guide.tiledesk.com/manage-your-workspace/install-widget-on-your-website.md>
- 邮件工单域：<https://guide.tiledesk.com/manage-your-workspace/getting-started-with-email-ticketing-in-tiledesk.md>
- 模型名单（帮助中心）：<https://guide.tiledesk.com/ai-chatbots-and-automation/supported-llms.md>
- 定价：<https://tiledesk.com/chatbot-features-pricing/>
- WordPress 插件：<https://wordpress.org/plugins/tiledesk-live-chat/>
- 插件源码：<https://plugins.svn.wordpress.org/tiledesk-live-chat/trunk/tiledesk-live-chat.php>
- Docker Hub 服务端：<https://hub.docker.com/r/tiledesk/tiledesk-server>
