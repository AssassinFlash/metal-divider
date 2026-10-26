# FastGPT 作为开源知识库与 AI 客服引擎

- 票：[研究：FastGPT 作为开源知识库与 AI 客服引擎](https://github.com/AssassinFlash/metal-divider/issues/98)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/fastgpt-knowledge-engine`（从 `origin/main` 的 `9da89ab7937f8e4e2db3c989b37f6bcdeab370b4` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-98-fastgpt`
- 上游核对点：`labring/FastGPT` `main` `487cc599614c2c88987b0611928bca37c910e2c2`（2026-10-26 16:42 +0800）
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树和可重建的上游浅克隆；不删除分支，不删除本文件

本文件只回答 FastGPT 社区版能否承担大知识库、聊天嵌入和 API 应答，有没有现成的 Chatwoot 等连接器，开源与商业边界、存储和部署是什么，以及相对 Knocket 的使用方式还缺什么。不决定它和 Metal Divider 询盘、资料公开范围的关系，也不选模型。不重复评估 Chatwoot、Dify、RAGFlow 或其他候选本身。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 2026-10-26 直接读到的官方仓库文件、许可证、发行说明、部署模板，或对公开 HTTP 接口的读取 |
| 官方宣称 | 官方文档句子。文档源文件在仓库 `document/content/`，与运行中的产品可能有滞后 |
| 合理推断 | 由源码结构推出、但没有把服务跑起来验证的判断 |
| 无法确认 | 材料没写，或文档与源码不一致且未部署核实 |

未部署 Docker，未注册 fastgpt.io / fastgpt.cn 账号，未创建应用，未上传知识，未调用会写入会话的接口。星数不作为能否上生产的依据。

## 结论速览

1. FastGPT 社区版可以当自托管的知识库、检索作答和网站嵌入引擎，不适合单独复现 Knocket。它有免登录分享链接、iframe 和脚本气泡，也有 OpenAI 兼容的对话 API 和知识库增删改查。它没有统一人工收件箱，没有人工接管，没有 Telegram / Email / WhatsApp 收件。
2. 仓库和官方文档里没有 Chatwoot 连接器，也没有 WordPress 插件。WordPress.org 插件检索 `fastgpt` 结果为 0。Labring 的 Sealos 应用商店另有一份 Chatwoot 部署模板，那是独立应用，不是 FastGPT 的渠道。
3. 协议是 Apache 2.0 加附加条件，GitHub SPDX 为 `NOASSERTION`。把自己的应用当后端使用被明文允许；用源码经营与 FastGPT 类似的多租户 SaaS 必须书面商业授权；控制台 LOGO 和版权信息不能去掉。商业功能在私有子模块 `labring/fastgpt-pro`，社区进程只做转发，未配置时拒绝。
4. 自托管时，对话和知识元数据在部署者的 MongoDB，向量在部署者选定的向量库，原文件在部署者的 S3 兼容存储，模型密钥在部署者的 AI Proxy。这是相对 Knocket「嵌入由平台管理、界面无导出」的控制点。云服务免费套餐的 30 天清空只写在云文档 FAQ，不能套到自托管。
5. 官方部署是 Docker Compose，不是 WordPress 插件。4.17 起必须接入 AI Proxy。标准栈含 Mongo 副本集、Redis、对象存储、向量库、主服务，以及插件、代码沙盒和 Agent 沙盒相关容器。测试环境文档写最低约 2 核 4–8 GB。这不能装进共享主机上的 PHP 站点。
6. 若接受单独的容器主机，社区版适合做知识与应答层，WordPress 只贴官方脚本或做一次 enqueue。人工收件箱要另选项目。两者之间没有现成连接器；工作流里有 HTTP 节点，那是自己编排，不是开箱集成。购买商业版也买不到 Chatwoot、人工收件箱或 WordPress 插件。

## 1. 产品身份与维护

官方仓库 [labring/FastGPT](https://github.com/labring/FastGPT)。2026-10-26 读取的 GitHub 元数据：默认分支 `main`，语言 TypeScript，未归档，创建于 2023-02-23，最后推送 `2026-10-26T09:14:56Z`。主页指向 `https://fastgpt.io`。文档站是 `https://doc.fastgpt.io`。许可证字段为 Other / `NOASSERTION`，不是 Apache-2.0 SPDX。

发行版：

| 标签 | 发布时间 | 等级 |
| --- | --- | --- |
| v4.17.2 | 2026-10-09 | 实测 GitHub Release |
| v4.17.1 | 2026-09-30 | 同上 |
| v4.17.0 | 2026-09-11 | 同上 |
| v4.16.2 | 2026-09-03 | 同上 |

`main` 在发行之后仍有提交。2026-10-26 当天可见的提交包括依赖升级、S3 分片上传修复、权限修复和向量召回并发限制。自 2026-09-26 起的提交列表最后一页为第 200 页（`per_page=1`），约 200 次提交。维护是活跃的，不只是 README 星数。

部署模板 `deploy/version/main/args.json` 把主服务钉在 `v4.17.2`，不是浮动的 `latest`。同文件里的配套标签：

| 组件 | 标签 |
| --- | --- |
| fastgpt | v4.17.2 |
| fastgpt-plugin | v1.1.4 |
| fastgpt-code-sandbox | v4.17.0 |
| aiproxy | v0.7.3 |
| mongo | 5.0.32 |
| redis | 7.2-alpine |
| minio | RELEASE.2025-09-07T16-13-09Z |
| pgvector | 0.8.0-pg15 |
| milvus-standalone | v2.6.22 |

根 `package.json` 的 `version` 仍是 `"4.0"`，`engines.node` 为 `>=22.23.2`，包管理器 `pnpm@10.33.4`。发行版号以 Git tag 和部署 args 为准，不以 `package.json` 的 `4.0` 为准。

v4.17.2 发行说明同时要求更新商业镜像 `fastgpt-pro:v4.17.2`。社区版和商业版是两套镜像，版本号对齐，不是同一个镜像的开关。

## 2. 许可证与商业边界

### 许可证文本

[LICENSE](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/LICENSE) 与文档 [开源协议](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/version/opensource/license.mdx) 一致。要点是实测文本，不是法律意见：

- 基础是 Apache License 2.0，另加条件。
- 允许商业化：可以作为其他应用的后端服务，或作为应用开发平台交付给企业。
- 未经书面授权，不得用源码经营与 FastGPT 类似的多租户 SaaS。
- 使用过程中不得移除或修改控制台内的 LOGO 或版权信息。
- 贡献者同意生产者日后把协议改严或改松，并同意代码用于 FastGPT 云业务。
- 文末写「本产品的交互设计受到外观专利保护。© 2023 Sealos.」没有专利号。专利是否存在、保护范围是什么，外部无法确认。
- 许可咨询邮箱：`dennis@sealos.io`。

对「一个站点自己用」：许可证把 BaaS 写成允许。对「把 FastGPT 做成给多家客户开租户的同类 SaaS」：许可证写成必须另购授权。控制台去标不行。分享窗口是否强制水印，本次未打开运行中的页面，不能从控制台条款直接推出。

`.gitmodules` 把 `pro` 指到 `https://github.com/labring/fastgpt-pro.git`。该路径在公开仓库里是子模块指针，不是可审计的商业源码。社区版因此不能靠改公开仓库打开商业功能。

### 官方功能对照

来源是仓库 [commercial.mdx](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/version/commercial.mdx)。下表只列和本票有关的行。

| 能力 | 社区版 | 商业版 / 云服务 | 等级 |
| --- | --- | --- | --- |
| 工作流编排 | 有 | 有 | 官方对照表 |
| 分享链接和 API | 有 | 有 | 同上 |
| 知识库 | 有 | 有 | 同上 |
| 多模型配置、模型日志看板 | 有 | 有 | 同上 |
| 应用发布安全配置 | 无 | 有 | 同上 |
| 第三方发布（表内写飞书、公众号） | 无 | 有 | 同上；源码比这张表更细，见第 4 节 |
| 运行日志看板、应用评测 | 无 | 有 | 同上 |
| 第三方知识库定时同步、Web 站点同步、图片知识库、文件级权限 | 无 | 有 | 同上 |
| 自定义版权、多租户与支付、团队空间与权限、管理后台、SSO | 无 | 有或「设计中」 | 同上 |

商业交付形态写明：完整版 = 社区版镜像 + 商业版镜像；商业镜像需要 License，不支持修改商业镜像。社区版代码可以改，之后升级要自己合并。

标价（官方文档，不是本次询价）：

| 方式 | 文档中的价格 |
| --- | --- |
| Sealos 全托管 | 10000 元起/月（3 个月起），或 120000 元起/年；8C32G，额外资源另计 |
| Sealos 多节点全托管 | 22000 元起/月，或 264000 元起/年；32C128G |
| 自有服务器 | 不写死价格，要求联系咨询；文档称含 6 个版本的升级协助 |
| 定开 / 维护 / 迁移 / 三方接入 | 2000–3000 元/人/天 |

这些价格买的是商业镜像、授权和人工服务。对照表里没有 Chatwoot、WordPress、Telegram、Email 或人工座席。买商业版不能补上第 6 节缺的收件箱。

### 源码里的门，比对照表具体

发布页 `projects/app/src/pageComponents/app/detail/Publish/index.tsx` 用 `feConfigs.isPlus` 决定能不能点开。未开通时点开会弹出商业提示，而不是静默降级。

| 渠道 | 界面 `isProFn` | 后端 |
| --- | --- | --- |
| 分享链接 | 否 | 社区路由创建链接、生成 iframe 和脚本 |
| API Key | 否 | 社区路由 |
| 站内聊天 Playground | 是 | 界面拦截 |
| 飞书、钉钉 | 是 | `feishu/[token].ts`、`dingtalk/[token].ts` 转发到 Pro |
| 企业微信 | 是，且默认要 `show_publish_wecom === true` 才出现 | `wecom/[token].ts` 转发到 Pro |
| 微信公众号 | 是 | `offiaccount/[token].ts` 转发到 Pro |
| 微信个人号 | 否 | 社区源码里的 `ILinkClient` 适配器，不走 `plusRequest` |

`packages/service/thirdProvider/fastgptPro/plusRequest.ts`：未配置 Pro 地址时抛出 `The request was denied...`；未配置 `PRO_TOKEN` 时抛出 `FastGPT Pro token is not configured`。飞书、钉钉、企微、公众号、内容审查、深度 RAG、用量钱包都走这条转发。社区部署不填 Pro 地址，这些调用失败，不会在社区进程里自己完成。

分享链接编辑框里，过期时间、QPM、积分上限、身份验证 `hookUrl` 包在 `feConfigs.isPlus` 里。引用片段、引用全文、下载原文、运行状态开关不在这个条件里。文档 [免登录窗口](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/build/publish/link.mdx) 也写身份验证「目前只在商业版中提供」。

同一界面在已有 10 条分享链接时禁用「创建链接」。这是界面硬编码，不是 Knocket 那种知识条数上限。本次没有在服务端再找到第二处相同限制，不能断言 API 也拒绝第 11 条。

## 3. 知识库、对话 API 与嵌入

### 知识怎么进去

社区源码和文档能对上的输入：

| 输入 | 社区版 | 证据 |
| --- | --- | --- |
| 手动文本 / QA | 有。集合处理模式含 `chunk` 和 `qa` | `DatasetCollectionDataProcessModeEnum` |
| 本地文件 | 有。内置解析 txt、md、html、pdf、docx、pptx、xlsx、csv、ofd；anydoc 再补 doc、xls、ppt、rtf、epub、odt 等 | `packages/global/common/file/constants.ts`，`readFile/index.ts` |
| 单个 URL | 有。`/core/dataset/collection/create/link` 在社区路由，可带 `webPageSelector` | `collection/create/link.ts` |
| 整站同步 | 无。文档写「目前仅向商业版用户开放」，同域名最多 200 个静态子页 | [websync.mdx](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/websync.mdx) |
| 飞书 / 语雀知识库 | 文档写商业版。飞书文档写自 v4.8.16 起商业版可导入，且拿不到个人空间 | [lark_dataset.mdx](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/third-party/lark_dataset.mdx) |
| API 文件库 | 文档说明如何用 baseURL 拉取已有文档库，该页未写「仅商业版」。对照表把「第三方知识库定时同步」标为商业版。定时同步是否包含这个 API 文件库，两处没有对上 | [api_dataset.mdx](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/third-party/api_dataset.mdx) 与 commercial.mdx |
| 图片知识库 | 对照表标商业版。社区枚举里有 `images` 集合类型。本次未证明社区运行时能否绕过界面创建 | commercial.mdx；`constants.ts` |
| 文件级权限 | 文档首句写商业版特有 | [collection_permission.mdx](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/collection_permission.mdx) |

自动分块默认 `chunkSize = 1000`，最小 64。索引长度缺省 512，随嵌入模型的 `defaultToken` / `maxToken` 变。QA 模式默认块上限 8000。这些是源码常量，不是云控制台截图。

上传额度函数 `getFileUploadLimits`：团队套餐、系统配置都没有时，回退为 1000 MB、1000 个文件。0 被当成未配置。这是代码回退，不是压测结果，也不是文档承诺。商业套餐可以覆盖。自托管若没写系统限额，就会落到这个回退。

检索文档写明：语义、全文、混合；混合用 RRF；可选 Rerank。引用上限按 token，不按固定 top-k。最低相关度只在语义检索或重排时过滤。Pg / OceanBase / SeekDB / openGauss 的全文走 MongoDB `$text` 加 jieba；Milvus ≥ 2.5.16 用 Milvus BM25，低于该版本启动报错退出。部署模板钉的是 Milvus 2.6.22。

知识结构是库 → 集合（约等于一个文件）→ 数据行。Mongo `dataset.datas` 存原文和索引 ID，向量库存向量。一组数据可以有多条向量。搜索单位是整个库，不是单个集合。

没有 Knocket 那种 FAQ / URL / 文件各 10 条、单文件 10 MB 的产品上限。容量随自备磁盘和向量库走。文档给的 PgVector 量级是宣称，不是本次测量：5000 万组向量以下适合 PgVector；测试最低 2c4g，100 万组建议 4c8g / 50 GB。

### 对话 API

仓库 `document/content/openapi/chat.mdx` 侧栏标了「不再维护」。`openapi/intro.mdx` 写从 4.15.0 起，手写说明不再更新，现行说明是运行实例的 `{{endpoint}}/apidoc/systemopenapi`。

2026-10-26 读取云实例 `https://cloud.fastgpt.io/api/apidoc/systemopenapi.json`：OpenAPI 3.1.0，49 条路径。云实例包含商业路由，不能把每条都当成社区版。下面只列社区仓库里能找到同名路由文件的：

| 路径 | 作用 |
| --- | --- |
| `POST /api/v1/chat/completions` 与 `POST /api/v2/chat/completions` | 对话。v2 源码在 `projects/app/src/pages/api/v2/chat/completions.ts`，鉴权后跑工作流并落库 |
| `POST/PUT/DELETE /api/core/chat/history/*` | 列会话、改标题、删除、清空 |
| `POST /api/core/chat/record/getPaginationRecords` 等 | 读、删单条对话 |
| `POST /api/core/dataset/create`、`DELETE /api/core/dataset/delete` | 建库、删库 |
| `POST /api/core/dataset/collection/create/localFile`、`/link`、`/text` | 文件、单页、文本 |
| `POST /api/core/dataset/data/pushData`、`PUT .../data/update`、`DELETE .../data/delete` | 推送、改、删数据行 |
| `POST /api/core/dataset/searchTest` | 检索测试 |
| 社区文件 `exportAll.ts`、`collection/export.ts`、`collection/getDownloadTicket.ts`、`collection/batchDownload.ts` | 导出。`exportAll.ts` 不调用 `plusRequest`，导出前有团队级频率检查 |

云 OpenAPI 里的 `/proApi/core/app/logs/getTotalData` 和 `getChartData` 带 `proApi` 前缀，对应对照表里的运行日志看板，不算社区能力。`/core/app/logs/list` 在云规格里，本次没有把它当成社区已核实的收件箱。

对话接口的使用方式（手写文档与 v2 源码一致的部分）：`Authorization: Bearer <apiKey>`，推荐 body 里带 `appId`。传入的 `model`、`temperature` 不覆盖应用编排。`chatId` 为空时不使用 FastGPT 的会话存储，上下文完全来自本次 `messages`；`chatId` 非空时从数据库取历史，只用 messages 最后一条当问题。

这是应用 API，不是客服座席 API。没有分配座席、改会话状态、内部备注或转人工的路径。

### 网站嵌入

分享链接的「选择使用方式」在社区组件里生成三种片段，都不检查 `isPlus`：

- 链接：`{origin}/chat/share?shareId=...`
- iframe：全宽全高，`allow="microphone *; *"`
- 脚本气泡：加载 `{origin}/js/iframe.js`，用 `data-bot-src` 指向同一分享链接，可设默认展开、可拖拽和图标

这是现成嵌入，不需要自写聊天 UI。它连的是 FastGPT 自己的分享页，不是 WordPress 短代码，也不是 Chatwoot widget。

文档对免登录窗口的限制：每个浏览器一个 `localId`；只允许拉取近 30 天的 20 条记录；换设备或清缓存会丢。30 天和 20 条写在 [link.mdx](https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/build/publish/link.mdx)。本次在 `packages/service/core/chat` 没有定位到对应常量，这两数字保持「官方文档宣称」。

要把 WordPress 登录用户绑到对话上，文档给的办法是分享链接的 `authToken` 加三个回调（`/shareAuth/init`、`/start`、`/finish`）。该身份验证在界面和文档里都是商业版。社区版嵌入是匿名 `localId`。

## 4. 现成连接器

发布渠道枚举只有：`share`、`iframe`、`apikey`、`feishu`、`dingtalk`、`wecom`、`wecom_websocket`、`official_account`、`wechat`、`playground`。

会话来源枚举 `ChatSourceEnum`：`test`、`online`、`share`、`api`、`cronJob`、`feishu`、`official_account`、`wecom`、`wechat`、`mcp`。没有 dingtalk、telegram、email、whatsapp、slack、discord、chatwoot。钉钉能在发布页出现，却不在会话来源枚举里，两处不一致，未运行核实钉钉消息落成哪种 `source`。

在 `487cc59961` 的已检出源码和 `document/content` 中检索 `chatwoot`、`wordpress`、`slack`、`discord`、`telegram`、`whatsapp`，无匹配。

2026-10-26 查询 WordPress.org 插件信息接口，`search=fastgpt`，`results` 为 0。

GitHub 代码检索：`labring/FastGPT` 无 `chatwoot`。`owner:labring` 的命中是 Sealos 应用商店模板和部署菜谱，例如 [sealos-run-content 的 Chatwoot 模板](https://github.com/labring/sealos-run-content/blob/main/content/appstore/templates/chatwoot.mdx)。该文件标题是 Chatwoot，描述是开源客户互动平台，`github` 指向 `chatwoot/chatwoot`。它是另一个可部署应用，不是 FastGPT 发布渠道。

因此：没有现成的 Chatwoot 连接器。同一家公司能分别部署这两个程序，不能当成已经接好。

其他可称为现成、但和本站客服不对口的出口：

- MCP Server：文档在社区指南，地址形如 `/api/mcp/app/<key>/mcp`，给 Cursor 一类客户端调用应用。不是网站访客入口。
- 微信个人号：社区发布页不标商业版。教程写只支持 iOS 最新微信，扫码后出现「微信 ClawBot」。实现类名是 `ILinkClient`。这不是企业微信，也不是网站挂件。未实连。
- 工作流节点含 `httpRequest468`。可以用 HTTP 调外部系统，包括将来自己调 Chatwoot API。节点存在不等于连接器存在。

## 5. 存储与部署

### 数据落在哪

官方部署文把三块写死：

- MongoDB：除向量外的各类数据。
- PostgreSQL/PgVector、Milvus、OceanBase、SeekDB、openGauss 或 Zilliz 云：向量。一次部署选一种，模板里的服务名是 `vectorDB` / `fastgpt-vector`。
- 对象存储：MinIO、AWS S3、阿里云 OSS、腾讯云 COS、Cloudflare R2。默认桶名 `fastgpt-public` 与 `fastgpt-private`。上传走 FastGPT 后端代理。

源码还能对上：

- 会话文档在 Mongo，字段含 `teamId`、`chatId`、`source`、`shareId`、`outLinkUid`、标题、token 汇总、软删除 `deleteTime`。消息正文在另一套对话记录接口后面，本次未逐字段打开消息集合。
- 旧注释仍写 Mongo 文件桶名 `dataset` 和 `chat`。现行部署文已把文件放到 S3 兼容存储。以部署文和 `STORAGE_*` 环境变量为准。
- Redis 在 Compose 里是必填 `REDIS_URL`。发行说明里的 BullMQ 用它做队列。它不是知识原文的主库。
- 模型不在 FastGPT 进程里。4.17.0 起必须配置 `AIPROXY_API_ENDPOINT` 和 `AIPROXY_API_TOKEN`，缺一启动校验失败。`OPENAI_BASE_URL` 不能替代。渠道密钥写在 AI Proxy 的管理员渠道里。自托管时密钥在自己的 AI Proxy，不在 fastgpt.io。

至少要配置一个语言模型和一个索引模型。可选重排、语音合成、语音识别。协议可以是 OpenAI 兼容，也可以选厂商协议。嵌入模型由部署者指定，不是平台代管。这是和 Knocket「Embedding provider credentials and model settings are managed by the platform」相反的控制点。

导出和删除在社区路由里存在，见第 3 节。删除数据行之后向量是否同一事务失效，本次没有顺着删除函数追到向量库写入，保持无法确认。能确认的是：原文、向量和文件都在部署者的库里，不在 FastGPT 云；界面和 API 都提供删除，不像 Knocket 已核对界面那样只有删除、没有导出。

云文档 FAQ 有一句：免费版三十天不登录后清空知识库。该节配图是云套餐。自托管文档没有这条保留规则。不能把云免费套餐的清空说成自托管行为。

### 要跑起来的东西

官方路径是：

```bash
bash <(curl -fsSL https://doc.fastgpt.io/deploy/install.sh)
docker compose up -d
```

文档同时给出 `doc.fastgpt.cn` 镜像。安装脚本会下载 compose、写随机 root 密码和服务间 token。README 里的默认账号 `root` / `1234` 是模板占位；脚本部署会改掉密码。模板注释写每次重启按 `DEFAULT_ROOT_PSW` 重置 root 密码。

标准 Compose 网络里的服务名包括 `fastgpt-app`、`fastgpt-mongo`、`fastgpt-redis`、`fastgpt-minio`、`fastgpt-vector`、`fastgpt-aiproxy`、`fastgpt-aiproxy-pg`、`fastgpt-plugin`、`fastgpt-code-sandbox`、`fastgpt-agent-sandbox-proxy`、`fastgpt-opensandbox-server`、`fastgpt-volume-manager`。Mongo 以副本集 `rs0` 启动。文档写 CPU 不支持 AVX 时要换 Mongo 4.x。

必须对外或经反代到达的端口：主服务默认 3000，对象存储 9000。启用 Agent/Skill 沙盒时还要 Sandbox Proxy 的 WebSocket 地址，4.16 起另要 HTTP 预览地址。MCP SSE 默认 3003，文档写不需要可以不开。

AI Proxy、Mongo、Redis、对象存储、一种向量库是文档中的运行条件。沙盒是启用 Agent/Skill 沙盒时的条件。官方没有给出「只跑知识库问答、去掉沙盒和插件仍能启动」的精简清单。本次未做删服务实验，不能把全量 Compose 说成每一项都不可省，也不能假设可以省。

这不是 WordPress 插件，也不是共享主机上的 PHP 模块。Node 要求 ≥ 22.23.2，还要 Mongo 副本集和 Redis。和本站现有的 Hostinger 共享主机形态不是同一种运行环境。换不换主机是后续票的事；这里只记录依赖形状。

## 6. 相对 Knocket 使用方式

对照来自已关闭的 [Knocket 研究](https://github.com/AssassinFlash/metal-divider/issues/87)。只看使用方式和数据控制，不把 Knocket 的 10/10/10 当质量目标。

| Knocket 已核实的使用方式 | FastGPT 社区版 | 还要什么 |
| --- | --- | --- |
| 网站气泡，脚本只带 identifier | 有。脚本指向自己的 `/js/iframe.js` 和 `shareId` | WordPress 侧一次 enqueue 或页脚粘贴。没有官方插件 |
| 开聊前的静态 FAQ、外链、预约链接 | 无对等的挂件主页 | 不在 FastGPT。若要，是挂件表面，不是知识库 |
| 在线时段、离线留言表单 | 无 | 另一项目或自写 |
| 联系页、移动 WebView | 无 | 不在本产品 |
| 统一收件箱，人工回复回到原渠道 | 无。有应用日志和会话 API，没有座席收件箱 | 另一客服项目。Chatwoot 无现成连接器 |
| Telegram、Email 收件 | 无 | 同上 |
| WhatsApp / Instagram / Messenger | Knocket 官方教程是外链，不是收件。FastGPT 也没有 | 不要为了对齐营销去找 FastGPT 连接器 |
| AI 先答，短语或低置信转人工，可交回 AI | 无转人工状态机。工作流可做条件分支和 HTTP 调用 | 人工状态在收件箱项目里。FastGPT 只到模型回复 |
| 自带聊天模型密钥 | 有，而且必须自带。密钥在自己的 AI Proxy | 还要自备嵌入模型 |
| 嵌入由平台管理 | 相反。嵌入模型、向量库都在部署者侧 | 这是要的控制点，不是缺口 |
| FAQ、单页 URL、文件 | 有，且没有 10 条 / 10 MB 的产品上限 | 整站爬取要商业版，最多 200 静态页 |
| 知识导出、删除后索引失效 | 导出和删除路由在社区源码。向量同步失效未逐行核实 | 比 Knocket 已核对界面完整；失效时序仍要实测 |
| 系统提示词 2000 字、问候 400 字 | 不沿用这两个上限。提示词在应用编排里 | 行为规则要自己写进工作流，不能假设模型遵守 |
| 官方 WordPress 插件只插入远程脚本 | FastGPT 没有插件。嵌入片段是自托管 URL | 少量自有代码的合理边界是 enqueue，不是重写聊天 |

Knocket 必须接管的八项里，FastGPT 社区版已经覆盖知识原文、分块与嵌入、模型密钥、检索作答、导出和删除的产品面。它没有覆盖会话作为客服线程、人工接管、以及「一个收件箱看全部渠道」。分析上报不是 Knocket 那种编进挂件的神策；自托管没有这条包。不能据此说 FastGPT 没有任何遥测，本次未审它的可选统计开关。

## 7. 能否少量整合，而不是从零做

能直接用的：

- 社区版 Docker 部署后的知识库、工作流和分享嵌入。
- `v1`/`v2` `chat/completions` 给已有系统调用。
- 知识库 OpenAPI 做导入、更新、删除、检索测试。
- 单页 URL 和八类以上文件，覆盖 Knocket 的输入种类，并放宽条数。

少量自有代码就够的：

- WordPress 把官方脚本或 iframe 放进页脚。身份如果必须是站内用户，要么购买商业版的分享鉴权，要么自己在 WordPress 里代理 API 并传入稳定 `chatId`。后者是连接代码，不是新的知识库。
- 若 WooCommerce 图案资料要进知识库，社区版没有 Woo 连接器。可用数据集 API 推送，或按 API 文件库规范提供只读接口。这是项目间连接，不是 FastGPT 已有插件。

不能靠 FastGPT 现成功能完成、也不该在本项目里从零做的：

- 人工收件箱、座席、离线留言、Telegram、Email。应另选客服底座。本票不评估 Chatwoot 本身。
- FastGPT 与该底座的双向接管。仓库没有连接器。HTTP 节点或一小段适配器是后续才需要写的胶水；在客服底座选定之前，不应该先写。
- 商业版才有的整站爬取、飞书文档库、文件级权限、团队权限、SSO、去标。单站自用若接受控制台保留 LOGO，并且不做多租户 SaaS，许可证文本没有要求先买商业版。

不建议的用法：

- 把社区版包成对外多租户 SaaS。许可证写明要书面授权。
- 去掉控制台 LOGO。许可证写明不可以。
- 只看星数或 README 勾选就当生产可用。本文件没有部署证据。
- 把 FastGPT 云当作知识主人。那样嵌入和原文又回到别人的账号，和离开 Knocket 的理由冲突。云 FAQ 的免费套餐清空是额外理由，不是唯一理由。

## 8. 无法确认

- 自托管实例的分享窗口是否仍只保留 30 天、20 条。文档如此，源码常量未定位。
- 分享页是否带不可关的 FastGPT 水印。许可证只约束控制台 LOGO。
- 删除数据行后向量是否立即从 PgVector 或 Milvus 消失。
- 未配置套餐时，1000 MB 回退是否会被镜像内的默认 `feConfigs` 改小。
- 第 11 条分享链接是否被 API 拒绝。界面在 10 条时禁用创建。
- 社区版能否邀请第二个管理员。对照表把团队空间与权限标为商业版；Mongo 模式仍有 `teamId`，单团队也能解释这个字段。未登录核实。
- API 文件库的定时同步算不算对照表里的商业功能。
- 微信个人号的 iLink 通道是否依赖额外未公开服务，以及它是否稳定。
- 外观专利的专利号和权利要求。
- 官方全量 Compose 里，沙盒和插件容器对「只做知识库问答」是否可停。未做删服务实验。
- 云 OpenAPI 的 49 条路径里，除已在社区仓库找到文件的那些，其余是否会在自托管社区镜像里注册。

## 9. 来源

一手（2026-10-26 读取仓库 `487cc59961` 或公开 HTTP）：

- https://github.com/labring/FastGPT
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/LICENSE
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/README_en.md
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/package.json
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/.gitmodules
- https://github.com/labring/FastGPT/releases/tag/v4.17.2
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/deploy/version/main/args.json
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/deploy/version/main/docker-compose.template.yml
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/version/commercial.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/version/opensource/license.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/build/publish/link.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/build/publish/wechat.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/build/publish/mcp_server.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/dataset_engine.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/websync.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/collection_permission.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/third-party/lark_dataset.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/guide/dataset/third-party/api_dataset.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/self-host/deploy/docker.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/self-host/config/model/intro.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/self-host/config/object-storage.mdx
- https://github.com/labring/FastGPT/blob/487cc599614c2c88987b0611928bca37c910e2c2/document/content/openapi/intro.mdx
- https://cloud.fastgpt.io/api/apidoc/systemopenapi.json
- https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[search]=fastgpt
- https://github.com/labring/sealos-run-content/blob/main/content/appstore/templates/chatwoot.mdx

发布渠道、Pro 转发、嵌入片段、文件扩展名、分块常量、上传回退、会话模式和数据集路由，来自上述提交中的：

- `projects/app/src/pageComponents/app/detail/Publish/index.tsx`
- `projects/app/src/pageComponents/app/detail/Publish/Link/SelectUsingWayModal.tsx`
- `projects/app/src/pageComponents/app/detail/Publish/Link/index.tsx`
- `packages/service/thirdProvider/fastgptPro/api.ts`
- `packages/service/thirdProvider/fastgptPro/plusRequest.ts`
- `packages/global/support/outLink/constant.ts`
- `packages/global/core/chat/constants.ts`
- `packages/global/core/chat` 的会话模式与 `packages/service/core/chat/chatSchema.ts`
- `packages/global/common/file/constants.ts`
- `packages/global/common/file/limit.ts`
- `packages/global/core/dataset/training/utils.ts`
- `projects/app/src/pages/api/v2/chat/completions.ts`
- `projects/app/src/pages/api/core/dataset/exportAll.ts`
- `projects/app/src/pages/api/core/dataset/collection/create/link.ts`

未采用为架构证据的材料：仓库星数、README 功能勾选（已用源码和文档复核过的除外）、云服务营销页价格图、任何未打开的控制台截图。
