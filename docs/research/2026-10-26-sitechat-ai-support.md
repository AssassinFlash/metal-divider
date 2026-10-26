# Sitechat 等小型一体化开源 AI 客服候选

- 票：[研究：Sitechat 等小型一体化开源 AI 客服候选](https://github.com/AssassinFlash/metal-divider/issues/99)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)（本文件不改地图）
- 日期：2026-10-26
- 分支：`research/sitechat-ai-support`（从 `origin/main` 的 `9da89ab` 拉出，不合并）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-99-sitechat`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树和 `/tmp/sitechat-src`；不删除分支，不删除本文件

本文件只回答 [raksbisht/sitechat](https://github.com/raksbisht/sitechat) 是否已经真实做成 RAG、网站挂件、对话后台和人工接管，以及它能不能作为小型一体化项目减少 WordPress 接入工作。直接同类只核到能改变这个判断的证据，不重复其他票对 Chatwoot、Dify、AnythingLLM 等的全面评估。不决定 AI 与询盘、资料公开范围的关系，也不选模型。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 2026-10-26 克隆并阅读的官方仓库源码、GitHub API 元数据、许可证文件是否存在 |
| 官方宣称 | README、徽章、接口注释里的句子，源码没有走出对应路径 |
| 合理推断 | 由源码路径推出、但没有把服务跑起来的判断 |
| 无法确认 | 外部材料没写，或需要登录、实跑、反序列化才能核实 |

未安装依赖，未跑 pytest / Playwright，未启动 MongoDB、Ollama 或 FastAPI，未连接 `.env.example` 里的数据库，未反序列化仓库里的 FAISS pickle。

核对的精确版本是 `c58ef22733a4d500bc227439574ac2c72ebab436`（2026-04-04，提交说明 `README`）。没有 tag，没有 GitHub Release。`backend/app/main.py` 把 FastAPI `version` 写成 `"1.0.0"`，这不是发布版本。

## 结论速览

1. 不能用 Sitechat 减少整合工作。它不是可嵌入 WordPress 的成品，也不是可自控知识与对话的客服底座。复制进公开商业仓库还有许可证硬伤。
2. README 徽章写 MIT，并链接 `LICENSE`。仓库根目录没有 `LICENSE`，GitHub API 的 `license` 为 `null`。没有许可证文件时，默认版权不允许他人复制、修改或再分发。这是采用硬伤，不是文案疏忽可以事后忽略的。
3. RAG、挂件、后台、人工队列在源码里都有实现，不是空目录。但聊天请求只打 Ollama；上传文档的向量 URL 是 `doc://`，站点检索又按网站 URL 前缀过滤，这条路径对不上；按 URL 删除向量的函数直接返回失败。README 里的多模型、混合检索、查询改写、生产就绪，不能当成现行行为。
4. 维护面是单作者、23 次提交、最后推送 2026-04-04。无 CI、无 Docker、无锁文件。测试文件很多，本次没有执行，也没有 Actions 记录证明它们在该提交上通过。
5. WordPress 接入只有「把脚本贴进页面」。没有插件、短代码，也不读 WooCommerce。知识、向量和对话落在独立的 MongoDB 与进程内 FAISS，不在 WordPress。现有共享主机上的 PHP 站点不能在进程内跑这套栈。
6. 直接同类没有翻案。WebWhiz 有 AGPL-3.0 和 Docker，README 仍是托管产品口吻，代码搜索没有 `handoff`。另一个同名仓库复用了同一提交，仍无许可证。小型一体化项目没有把 Knocket 的使用方式收成可嵌入、可自控、可合法采用的一块。

## 1. 产品身份

官方仓库：[https://github.com/raksbisht/sitechat](https://github.com/raksbisht/sitechat)。

2026-10-26 GitHub API：

| 项 | 值 |
| --- | --- |
| 创建 | 2026-03-04 |
| 最后推送 | 2026-04-04T03:56:53Z |
| 默认分支 | `main` |
| 提交数 | 23，全部作者为 Rakesh Bisht |
| 星 / fork / 关注 | 42 / 16 / 0 |
| 开放 issue | 0 |
| Release / tag | 无 |
| 语言 | Python |
| 归档 | 否 |
| 许可证字段 | `null` |
| 描述 | Open-source, self-hosted AI chatbot trained on your website and docs |

`updated_at` 是 2026-10-14，晚于最后一次推送。这只说明仓库元数据有变动，不能当成代码仍在维护。`.github/` 里只有 `FUNDING.yml`，没有 workflow。

README 自称 production-ready、440+ 测试、无供应商锁定、对话不离开自有服务器。这些句子下面按源码拆开，不作为架构事实。

## 2. 许可证硬伤

实测：

- 克隆树没有 `LICENSE`、`LICENSE.md`、`COPYING`。
- `GET /repos/raksbisht/sitechat/license` 返回 404。
- README 徽章是 `License-MIT`，链接相对路径 `LICENSE`。该文件不存在，徽章指向的是空链。
- 源码文件头没有 MIT 许可声明。

GitHub 文档写明：仓库不选许可证时，适用默认版权，作者保留全部权利，他人不得复制、分发或做衍生作品（[Licensing a repository](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/licensing-a-repository)）。

因此不能把这份代码复制进本仓库、做成 WordPress 插件，或在公开站上再分发改过的副本。读源码作机制证据可以。等作者补上 MIT 文件之前，许可证状态是硬伤，不是「徽章写了就算有」。

付费边界：仓库没有企业版、没有付费开关、没有许可证密钥检查。限制来自缺失的开源许可，不是「开源版裁功能、付费版解锁」。

## 3. RAG：有检索作答，不完整

请求路径是 `POST /api/chat` → `RAGEngine.chat()` → `VectorStore` → `OllamaService`。这不是只写在 README 里的名词。

实际走到的步骤：

1. 若带 `site_id`，先按站点取出 Q&A，用句向量余弦相似度比对，阈值 `0.85`。命中则直接返回批准答案，来源 URL 写成 `qa://<id>`，不再调用聊天模型。
2. 未命中则对 FAISS 做向量近邻，再按分数和词重叠做一次过滤，把剩下的块拼进提示词，交给 Ollama。
3. 置信度是距离换算，不是校准过的概率。无命中文档时返回 `0.3`。
4. 聊天路由再用站点上的置信度阈值和短语表决定 `suggest_handoff`。挂件看到这个字段会问要不要转人工。

文档处理与爬取也有代码：

- 爬虫是 `aiohttp` + BeautifulSoup，同域 BFS，默认最多 100 页，延迟 1 秒。不渲染 JavaScript，不读 sitemap，不看 robots.txt。跳过的只是 `javascript:` / `mailto:` 这类链接。
- 上传声明支持 PDF、DOCX、DOC、TXT、MD、CSV、PPTX、XLSX、HTML。`.doc` 仍交给 `Docx2txtLoader`，旧版二进制 DOC 能否抽出文字，本次未实跑。
- 分块用 `RecursiveCharacterTextSplitter`，默认 1000 字、重叠 200。
- 嵌入模型在运行时向量类里写死为 `sentence-transformers/all-MiniLM-L6-v2`，CPU。`EMBEDDINGS_PROVIDER` / `EMBEDDINGS_MODEL` 不进入这条类。

README 与文件头宣称、但请求路径没走的部分：

| 宣称 | 源码 |
| --- | --- |
| 混合检索 | `_retrieve_documents` 只有向量近邻。词重叠只在分数差时做过滤，没有关键词索引 |
| 查询改写 | `_rewrite_query` 存在，`chat()` / `chat_stream()` 都把 `rewritten_query` 设成原句，没有调用它 |
| 追问建议 | `_generate_follow_ups` 存在，两处调用点被写成空列表，注释是为了少一次模型调用 |
| 多模型一键切换 | `providers/factory.py` 能构造 OpenAI、Anthropic、Azure、Ollama 的 LangChain 对象。`RAGEngine` 只用 `OllamaService`，直接 `POST {OLLAMA_BASE_URL}/api/chat`。全仓库对 `get_llm()` 的调用在测试和工厂本身，不在聊天路由 |
| 按站点独立向量库 | 工厂函数接受 `site_id` 并可以分目录。运行时 `VectorStore` 不用这个工厂，索引路径是 `CHROMA_PERSIST_DIR/faiss_index`，全站一个索引 |

上传文档与站点检索对不上，这是源码路径，不是猜测：

- `index_documents()` 把上传文件的 URL 写成 `doc://{site_id}/{doc_id}`。
- `RAGEngine` 在有站点时用网站 URL 做前缀过滤：`doc_url.startswith(site_url)`。
- `doc://…` 不会以 `https://客户站` 开头。挂件聊天总会带 `site_id`。因此已上传文件会被编进索引，然后在站点聊天里被滤掉。
- 过滤看的是 URL 字符串，不看元数据里的 `site_id`。网站 URL 为空时，`startswith("")` 为真，过滤失效，合理推断是跨站块会混进回答。本次未实跑。

删除与重建也不闭环：

- `delete_by_metadata()` 打一条警告后 `return False`。注释写明 FAISS 没有原生删除。
- `delete_page_index()` 仍调用它，然后删 MongoDB 里的页面记录。向量可以留下。
- 爬取页写入 MongoDB 的正文被截成 1000 字预览。`reindex_all()` 再从这份预览重建，全文不在 MongoDB。
- 上传路由不调用存储提供者。原文件不落盘，抽出的文字进 FAISS 后，临时文件被删。FAISS 丢失后，上传知识无法从 MongoDB 重建。

仓库还提交了 `backend/data/chroma_db/faiss_index/index.faiss`（15KB）和 `index.pkl`（9KB）。加载时 `allow_dangerous_deserialization=True`。本次没有反序列化，不能说明里面是空索引还是某次爬取残留。把 pickle 索引放进仓库，再允许危险反序列化，不是可交付的知识库格式。

结论：爬取页面的向量检索加 Q&A 直答，在源码上是真的。上传文档 RAG、删除、按站点隔离、多模型，不是现行完整行为。不能把「有一个 RAGEngine 类」说成知识输入与删除已可用。

## 4. 挂件：脚本可用，不是 WordPress 插件

嵌入接口 `GET /api/embed/script/{site_id}` 返回一段脚本：动态插入 `{api}/widget/chatbot.js`，带 `data-site-id` 和 `data-api-url`，可选 SRI。

挂件源文件是 `frontend/src/widget/chatbot.js`（833 行）。构建产物在 `frontend/widget/`。它会：

- 读脚本上的站点 ID 和 API 地址。
- 再请求公开的 `GET /api/sites/{site_id}/config`，套颜色、标题、欢迎语、是否隐藏「Powered by SiteChat」。
- 右下角气泡，窄屏有一段 `@media (max-width: 480px)`。
- 把用户消息 `POST` 到 `/api/chat`，不是流式接口。流式路由存在，挂件没用。
- 渲染来源链接、反馈、主动弹出、转人工按钮、离线邮箱表单。
- 会话 ID 在浏览器里用 `widget-` 加随机串生成，不登录。

没有联系页、没有 App WebView 桥、没有 WhatsApp / 邮件 / Telegram 入口、没有附件上传、没有会议链接。关闭挂件时，若人工会话还在 pending 或 active，会 `sendBeacon` 到 abandon。

公开配置接口返回整份 `SiteConfig`，其中包含 `behavior.system_prompt`。挂件只需要外观，接口却把系统提示词也公开出去。

`X-Frame-Options: DENY` 加在应用自己的 HTML 响应上。挂件是脚本注入宿主页，不是 iframe，这条头不阻止贴脚本。宿主页的 CSP 若不允许该源的脚本和 `connect-src`，脚本会失败。那是宿主配置，不是 Sitechat 插件。

## 5. 后台与人工接管：队列是真的，通知不是

仪表盘是 `frontend/js/app.js`（8593 行）单文件，路由 `/app` 与 `/dashboard`。源码里有站点、对话、交接、线索、Q&A、触发器、白标视图。`switchView('handoffs')`、`initHandoffsView()`、队列 SSE 都在。本次没有在浏览器里点过。

角色有 admin、站点所有者（`user`）、support agent。代理默认进交接视图。管理员或站长可以把代理分到站点，再分到某条交接。代理看到未分配池和分给自己的项。

交接状态：`pending`、`active`、`resolved`、`abandoned`。访客创建、代理认领、代理发言、访客再催、访客离开放弃，都有路由和 MongoDB 写入。营业时间可按星期和时区配置；不可用时挂件改成离线邮箱表单，提交后仍 `POST /api/handoff`，把邮箱存在交接记录上。

这不是 Knocket 那种带通知的收件箱：

- 全仓库没有 SMTP、Telegram、浏览器推送或 webhook 发送。`notify_on_completion` 只是爬取计划上的布尔字段，没有发送实现。
- 离线文案写 “We'll get back to you”，成功提示也这么写。没有任何代码发这封信。
- 代理侧「SSE」是服务端每 5 秒查一次 MongoDB，有变化才推。访客侧挂件是每 2 秒 `fetch` 消息。不是即时通信连接。
- `ai_summary` 是最近 10 条消息截断后拼接，不是模型摘要。
- 没有「恢复 AI」接口。状态更新只接受 active / resolved / abandoned。
- 对话收件箱（备注、标签、状态、导出、删除）与人工交接是两套记录。导出和删除在对话路由上有 JWT 和角色限制；交接消息流是另一条公开通道。

所以：人工接管的队列、认领、双向文字，在源码里是完整的最小闭环，前提是有人开着仪表盘。没有站外通知、没有移动端、没有渠道汇入。不能称为与 Knocket 同等方式的收件箱。

## 6. 数据落在哪里

| 数据 | 物理位置 | 删除 / 导出 |
| --- | --- | --- |
| 用户、站点、配置、对话、交接、线索、Q&A、爬取任务 | MongoDB。默认 `mongodb://localhost:27017`，库名 `sitechat` | 对话有 JSON/CSV 导出和删除，需登录。Q&A 在 MongoDB，可关可改 |
| 页面全文 | 不完整。MongoDB 只存 1000 字预览 | 删页面记录不会删掉向量 |
| 上传原件 | 不落盘。存储提供者没有被上传路由调用 | 无法从原件重建 |
| 向量 | 进程内 FAISS，一个目录。仓库还带了一份索引文件 | `delete_by_metadata` 失败。清空是删目录重建，不是按站点删 |
| 嵌入模型 | 本机 HuggingFace 缓存，首次加载下载 `all-MiniLM-L6-v2` | 与对话数据无关 |
| 聊天模型 | 默认本机 Ollama `http://localhost:11434` | 密钥若改走云端，只在有人把工厂接上之后才有意义；现行路径不读 OpenAI 密钥 |
| 挂件会话 | 浏览器内存加 `localStorage` 的访客/触发器标记 | 不在 WordPress |

`.env.example` 第 96 行写了一条非占位符的 `mongodb+srv://` 连接串，用户名是 `root`，带密码，主机是 Atlas 形态的 `sitechat.<cluster>.mongodb.net`。这与「数据永不离开你的服务器」相反，也是示例文件不该出现的凭据。本次没有连接，密码不写入本文。文件已在公开仓库：[backend/.env.example](https://github.com/raksbisht/sitechat/blob/c58ef22733a4d500bc227439574ac2c72ebab436/backend/.env.example)。`config.py` 的代码默认值仍是本地 MongoDB，只有复制这份示例才会指向该云库。

PostgreSQL、S3、GCS、Redis 在配置枚举里，创建函数直接 `raise NotImplementedError`。`.env.example` 标了 coming soon。缓存实际是进程内存。多进程或重启会丢掉缓存，向量则依赖那一个 FAISS 目录，没有文件锁。

## 7. 模型支持

| 层 | 配置声称 | 聊天/索引实际使用 |
| --- | --- | --- |
| 聊天 | ollama、openai、anthropic、azure | 仅 Ollama HTTP `/api/chat`。默认模型在 `config.py` 是 `llama3.1:8b`，示例文件是 `qwen2.5-coder:3b`，README 快速开始写 `ollama pull llama3.2`。三处不一致 |
| 嵌入 | huggingface、openai、ollama | 写死 HuggingFace `all-MiniLM-L6-v2` |
| 向量库 | faiss、chroma、pinecone、qdrant | 运行时类只建 FAISS。Chroma / Pinecone / Qdrant 只在未被聊天调用的工厂里 |
| 依赖锁 | 无 | `requirements.txt` 全是 `>=` 下限，无 lock。2026-10-26 新装可能拉到与 2026-04 代码不兼容的 LangChain。本次未安装，这是推断 |

没有 DeepSeek、OpenRouter 或 OpenAI 兼容端点的现行接线。下游同名仓库有一条提交说明写了 OpenRouter，见第 12 节，那不是本仓库的行为。

## 8. 部署依赖

README 前提：Python 3.10+、MongoDB 6.0+、Ollama、可选 Node.js 18。启动方式是本机 `mongod`、`ollama serve`、`uvicorn app.main:app --host 0.0.0.0 --port 8000`。

仓库没有 Dockerfile、compose、`scripts/`、`deploy/`。README 却写了 `./scripts/run-sitechat.sh` 和 `deploy/APACHE_PROXY.md`。这两个路径在 `c58ef227` 不存在。

这套栈不能放进现有 WordPress 进程。本站生产是 Hostinger 共享主机上的 PHP / LiteSpeed 站点（`AGENTS.md`）。Sitechat 要常驻 Python、MongoDB、句向量模型和 Ollama 或等价的模型进程。那是另一台始终在线的服务，不是 mu-plugin，也不是 LiveCanvas 模板。

## 9. WordPress

仓库内搜索不到 WordPress、短代码或插件头。接入步骤只有把嵌入脚本贴进页面页脚。

贴上之后仍然缺：

- 不读文章、产品、图案或 WooCommerce。知识必须再爬一次公开页，或再上传一份文件。这是第二份资料，不是 Woo 的派生索引。
- 不带图案 ID、询盘 ID 或登录用户。
- 对话不进 WordPress 数据库。
- CORS 默认是 localhost 和 `127.0.0.1` 任意端口。生产要把 WordPress 源站写进 `CORS_ORIGINS`，否则浏览器会拦挂件的 API 调用。
- 域名白名单函数 `validate_widget_domain()` 有单元测试，`embed.py` 导入了它，生产路由没有调用。仪表盘能保存 `enforce_domain_validation`，聊天与交接不检查它。

所以 WordPress 侧不是「装一个插件」。最少也是：另外部署、改 CORS、贴脚本、再维护一份与 Woo 无关的索引。这已经不是少量连接。

## 10. 测试、维护与安全

测试文件存在，数量与 README 的「440+」同级，但不能当成已通过：

| 套件 | 计数方式 | 数量 |
| --- | --- | --- |
| `backend/tests` | `def test_` | 495 |
| `frontend/tests` | `test(` / `it(` | 59 |
| `e2e/tests` | 同上 | 75 |

没有 GitHub Actions。README 写 `pytest tests/test_auth.py`，该路径不存在，认证测试在 `backend/tests/integration/test_auth.py`。本次未执行。通过与否是无法确认。

安全上，源码里有 JWT、密码复杂度、管理员首次必须改密、slowapi 限流、交接/线索蜜罐、安全响应头。这些是真代码。同时有这些未闭环项：

- `GET/DELETE /api/chat/history/{session_id}`、`GET /api/chat/sessions`、`POST /api/chat/feedback` 没有登录。会话列表会把会话 ID 列出来，历史接口再按 ID 读内容。
- 交接的读取、访客发言和消息 SSE 不校验会话。放弃接口才核对 `session_id`。`handoff_id` 是 UUID 截成 12 位十六进制，不好穷举，但 ID 一旦泄漏，外人可以读和代发。
- 队列 SSE 把 JWT 放在查询字符串。
- 请求体中间件上限 10MB。文档处理器自称 50MB。大于 10MB 的上传会先被中间件拒绝。
- 登录页 HTML 写死演示密码 `Admin123!`。`public_html.py` 只替换邮箱占位符，不替换这个密码。`config.py` 默认 `ADMIN_PASSWORD` 为空，空值会关闭自动建管理员。README 快速开始又写默认账号 `admin@sitechat.in` / `Admin123!`。三处不一致。若操作者照 README 设置，登录页就在公开一个已知口令。
- 用户代理黑名单只拦 `sqlmap`、`nikto`、`nessus`、`nmap` 这几个字符串。这不是安全控制。
- 示例环境文件中的云数据库连接串，见第 6 节。

维护活动：2026-03-04 到 2026-04-04，约一个月，23 个提交，一个作者，提交说明多为 `wip`、`sitechat`、`Improvements`。此后到 2026-10-26 没有新推送。0 个开放 issue 更像没有使用者报障，不是问题已清零。

## 11. 相对 Knocket 使用方式缺什么

Knocket 的使用方式来自票 87 已关闭的研究，这里只对照，不重做。Sitechat 没有覆盖、也不能靠「再贴一个脚本」补上的部分：

| Knocket 用法 | Sitechat |
| --- | --- |
| 一个脚本进网站挂件 | 有，但是自有 API，不是单行托管脚本 |
| 联系页、移动 WebView | 无 |
| Telegram、邮件进同一收件箱 | 无渠道接入，无出站通知 |
| 浏览器提醒代理 | 无。代理必须开着仪表盘 |
| 低置信或短语转人工，可恢复 AI | 转人工建议和队列有。无恢复 AI |
| FAQ / URL / 文件进知识，可删 | Q&A 可删。URL 爬取可进索引。文件上传与站点检索错位。向量删除失败 |
| 自带 OpenAI 兼容密钥 | 现行聊天只打 Ollama |
| WordPress 只贴脚本 | 也可以贴脚本，但还要自托管整套后端，并改 CORS |
| 知识与对话在平台 | 自托管时在操作者的 MongoDB 和 FAISS。删除与导出不完整，所以「自己掌握」只是进程在自己机器上，不是可审计的数据控制 |

已有、可当作机制证据而不是可复制代码的部分：脚本挂件、Q&A 直答、交接状态机、营业时间、角色分站。因为没有许可证，这些也不能抄进本仓库。

## 12. 直接同类

只核了两个会改变「小项目能否少写代码」判断的对象。

### WebWhiz

[webwhiz-ai/webwhiz](https://github.com/webwhiz-ai/webwhiz)。2026-10-26 API：未归档，1045 星，172 fork，49 个开放 issue，语言 TypeScript，`pushed_at` 2026-01-08。提交历史上最新一条是 2025-12-31 的路径排除合并（`8262769f5e`）。许可证文件是 `license.md`，正文为 GNU AGPL-3.0。`LICENSE` 路径 404，但许可证文本在仓库里，这和 Sitechat 不同。

README 仍按托管产品写：每月爬一次、套餐页数和 token 上限、当时还不能自带 OpenAI 密钥。自托管节要求 Docker，并在 `.env.docker` 填 `OPENAI_KEY`。仓库有 `widget/`、`knowledgebase/`、`slack/`，没有 WordPress 插件目录。2026-10-26 对仓库做 `handoff` 代码搜索，结果为 0。README 功能列表有离线消息，没有人工收件箱。本次没有通读 WebWhiz 源码，不能把「搜索无 handoff」说成绝对没有人工回复；至少官方功能列表和这次搜索都没有给出可嵌入的接管实现。

AGPL-3.0 对网络服务上的修改有公开对应源码的义务。即便只当现成服务嵌入，它也不提供本票要的人工接管，并且聊天密钥在 README 的自托管步骤里是 OpenAI。它不能补上 Sitechat 的洞，再一起少写代码。

### 同名再发布

[BraenovaITSolutionsPNG/sitechat](https://github.com/BraenovaITSolutionsPNG/sitechat) 不是 GitHub 标记的 fork（`fork: false`，无 parent）。历史里含有与上游相同的 `c58ef22733`（2026-04-04，说明 `README`），其后 2026-10-13 有三条提交，说明分别是 OpenRouter、Render 免费档，以及在仪表盘显示爬取失败原因。`LICENSE` 同样 404，0 星。本次没有审计那三条差异。提交说明不能证明聊天路径已经改接 OpenRouter。

这个仓库说明两件事：上游代码已被整库再发布，许可证缺失已经被实际打破；下游要自己补部署和模型，不是开箱即用。它不是另一条更成熟的产品线。

GitHub 上其他名为 sitechat 的仓库或已归档，或无描述、无星、无许可证，不构成候选。

## 13. 采用判断

不能直接用，也不能靠少量连接用。

硬伤：

- 无许可证，不能合法复制或再分发。
- 单作者，停止推送超过六个月，无发布、无 CI、无容器。
- 示例配置含云数据库凭据形态的连接串。

即使作者补上 MIT，仍然要另写或另找的部分：

- 把上传文档、删除、按站点隔离接到真正使用的向量库。
- 把聊天模型工厂接到请求路径，或明确只支持一种已锁定的模型。
- 代理通知、移动端、邮件/Telegram 汇入。
- WordPress 侧若要图案上下文，得从 Woo 取数再送进外部索引；Sitechat 没有这个连接器。
- 在共享 PHP 主机之外准备 Python、MongoDB 和模型进程。
- 关掉未鉴权的会话列表和交接读写，再实跑测试。

推荐组合价值：没有。Sitechat 不能当组合里的挂件、知识库或收件箱。WebWhiz 也不能补上接管，还多一个 AGPL 约束。小型一体化仓库没有缩短「WordPress 接入 + 自控知识与对话」这条路。本结论不选择其他票里的项目，也不规定 AI 与询盘怎么接。

## 来源

- 仓库与提交：<https://github.com/raksbisht/sitechat> ，<https://github.com/raksbisht/sitechat/commit/c58ef22733a4d500bc227439574ac2c72ebab436>
- 许可证缺失与 GitHub 默认版权：<https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/licensing-a-repository>
- 示例连接串所在文件（勿复制其中的口令）：<https://github.com/raksbisht/sitechat/blob/c58ef22733a4d500bc227439574ac2c72ebab436/backend/.env.example>
- 运行时向量与删除：`backend/app/database/vector_store.py`
- RAG 与未调用的改写：`backend/app/services/rag_engine.py`
- 仅 Ollama 的聊天客户端：`backend/app/services/ollama.py`
- 未被请求路径使用的模型工厂：`backend/app/providers/factory.py`
- 上传 URL 与站点过滤错位：`backend/app/routes/documents.py`，`RAGEngine._retrieve_documents`
- 交接与公开 SSE：`backend/app/routes/handoff.py`，`backend/app/database/mongodb.py`（`handoff_id` 截断）
- 未鉴权的会话列表：`backend/app/routes/chat.py`
- 挂件：`frontend/src/widget/chatbot.js`，嵌入片段 `backend/app/routes/embed.py`
- 未接线的域名校验：`backend/app/core/security.py` 的 `validate_widget_domain`
- WebWhiz 许可证与自托管说明：<https://github.com/webwhiz-ai/webwhiz/blob/main/license.md> ，<https://github.com/webwhiz-ai/webwhiz/blob/main/README.md>
- 同名再发布：<https://github.com/BraenovaITSolutionsPNG/sitechat>
