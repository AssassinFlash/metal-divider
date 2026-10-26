# WordPress AI 知识库：输入、索引与存储

- 票：[研究：WordPress AI 知识库插件的输入、索引与存储实现](https://github.com/AssassinFlash/metal-divider/issues/88)
- 父地图：[Wayfinder map：自建 WordPress AI 客服（Knocket 功能对标）](https://github.com/AssassinFlash/metal-divider/issues/86)
- 日期：2026-10-26
- 分支：`research/wordpress-ai-knowledge`
- 状态：供协调者使用。本文只回答知识怎样进入、索引写在哪、哪些代码能合法复用。不决定模型、不决定和询盘或资料公开范围的关系，也不重复客服收件箱研究。
- 审计方式：WordPress.org 插件 API、各插件当前稳定 zip 的源码、官方文档与 Knocket 官方教程。没有在 WordPress 里安装运行这些插件。运行时超时、包大小和共享主机实际吞吐没有实测。

## 0. 结论速览

「知识输入和存储由自己控制」不是某个插件的功能名。能成立的实现只有这条路径：

1. 原文的维护点留在本站。文章、页面、自建问答，以及本站图案所在的 WooCommerce 产品，仍由 WordPress 发布状态决定能不能被看见。
2. 切块和向量是派生副本。它们写在本站 MySQL，或者写在自己运营的向量库里，并且随原文保存、下架、删除而重建或失效。
3. 嵌入和回答如果要调用模型，用自己的密钥打到自己选择的端点。请求正文会离开站点，但知识库和索引不交给客服厂商保管。
4. 回答时先检索，再把命中的原文片段放进提示词，并带出处。删掉原文，对应片段必须不再被检索到。

这条路径在免费、可审计的源码里已经有几种做法，不需要把知识交给 Knocket。Knocket 自己的 WordPress 插件并不保存知识；它的控制台教程写明，嵌入由 Knocket 平台托管。

可以当作机制参照、并且许可证允许阅读或按 GPL 再分发的，是下面这些免费包里的本机实现，不是它们的整包聊天产品：

- WordPress 官方 AI 插件 1.4.0 的向量表：可移植的 MySQL 存法，但不存原文，也没有访客聊天。
- Rapls AI Chatbot 1.21.4：原文和向量在同一张本站表，保存文章时更新，PHP 里做余弦检索。
- AI Puffer 2.5.15 的 Local 策略：本站两张表，向量是归一化的 float32，PHP 点积检索，上限写在代码里。
- Limb AI Chatbot 2.1.4：本站向量表，Pinecone 可选。
- MxChat 3.2.27：本站 `LONGTEXT` 向量加处理队列。
- Hyve Lite 2.0.4 的自带 OpenAI 密钥模式：本站表。它的 Hyve Connect 模式会把知识库同步出去，不能算自己控制。
- AI Search 1.44.1：向量写在文章自己的 post meta 上，原文不复制到第二张内容表。

明确不适合当作自控知识库的：Knocket 平台嵌入、Knocket / HelpJet / Tidio 一类只加载外部脚本的挂件、Queryra、StoreAgent 的 AI Memory、Ask My Content 的 Node 后端、以及把文件唯一存放在 OpenAI Vector Store 的路径。AI Engine 3.8.7 免费包把默认环境写成「WordPress 数据库」，但真正检索的类不在免费包里，不能把未见过的 Pro 代码当成可复用实现。

## 1. 范围和既有约束

本票只看知识库与 AI 检索：资料类型、上限、导入、切块、嵌入、索引、原文和索引的物理位置、检索依据、更新删除、WooCommerce 接入、模型 API、后台任务、导出、许可证。

不在本票里展开的：

- Knocket 的挂件、收件箱、Telegram、移动 SDK 和完整使用流程。那是[研究：Knocket 功能、知识输入限制与数据控制边界](https://github.com/AssassinFlash/metal-divider/issues/87)。这里只用它的知识输入和存储边界做对照。
- 聊天窗口、统一收件箱、人工接管。那是[研究：WordPress 客服插件的聊天、收件箱与人工接管实现](https://github.com/AssassinFlash/metal-divider/issues/89)。
- 不预设 AI 客服和现有直接询盘、资料可见范围的关系。

用来判断「第二份资料是否合格」的既有约束，不是本票新做的产品决定：2026-10-26 业主裁定，商品与关联资料只在 WooCommerce 维护；模板、JSON 或索引不能成为可独立放行商品的第二数据源。派生副本可以存在，但必须由 Woo 的当前发布状态生成、更新和失效。下架保留草稿，不等于删除。共享主机首期没有 Redis。这些只说明哪些存储机制和约束冲突，不代替尚未做的产品选择。

## 2. 怎样筛选

2026-10-26 用 WordPress.org Plugins API 1.2 搜索 `ai chatbot`、`ai knowledge base`、`embeddings chatbot`、`woocommerce ai chatbot`、`vector search`、`rag chatbot`。第一页合计约 83 个不重复 slug。入选标准不是安装量，而是同时满足：有官方说明或插件自带 readme，并且当前稳定 zip 里能读到建表、切块或外传代码。

云挂件只用来划出「知识不在本站」的边界，不把它们的营销功能名单当成存储实现。没有拆包的插件不写入存储结论。

审计包是 `https://downloads.wordpress.org/plugin/<slug>.zip` 在 2026-10-26 取回的当前稳定版。版本号与 wordpress.org `plugin_information` 一致。zip 的 SHA-256 见文末，便于以后核对是不是同一份源码。

## 3. Knocket 的知识边界

只记录和「自己控制输入与存储」直接有关的事实。其余功能不在这里展开。

### 3.1 WordPress 插件不保存知识

`knocket-live-chat-widget` 1.0.2（2026-08-21 更新，活跃安装数 API 报 0）。许可证声明为 GPLv2 or later。插件文件只有设置页和一段入队脚本：把管理员粘贴的 SDK 地址交给 `wp_enqueue_script`。readme 写明脚本来自 `https://trtc.io/knocket-sdk/sdk.js`，访客消息送到 Knocket 服务器。插件自己不建知识表，不切块，不调用嵌入 API。

同一份 readme 把 AI Agent 写成 “Coming soon”，并描述知识库可以来自 FAQ、URL 或上传文件。这和下面的控制台教程不一致：教程已经把 Knowledge 页写成现成功能。事实是插件包和教程的发布状态不同；不能用插件 readme 的 “coming soon” 否定教程，也不能用教程假定 WordPress 插件里已经有知识库代码。

来源：wordpress.org 插件页与 zip 内 `readme.txt`、`knocket-live-chat-widget.php`。zip SHA-256：`0e83bdf00a57684ed96a439f343e4337a5e50f60fb493fca1ce4d0c8da81ee04`。

### 3.2 控制台教程里的输入和上限

官方教程 [Get Started with the Knocket AI Agent](https://knocket.trtc.io/tutorials/ai-agent/)，页面自标 Updated Sep 7, 2026。2026-10-26 读取。

事实，教程原文：

- 知识页有三个标签：FAQ、URL、File。每个标签最多 10 条。
- 文件格式：PDF、TXT、DOC、DOCX、HTML、MD、CSV、XLSX。单文件最大 10 MB。
- 教程写：加入的每个来源都会被切块、嵌入，并在回复时检索；代理只引用找到的内容。
- 切块大小、重叠、嵌入模型、向量库存放位置，教程没有写。
- 嵌入凭据由 Knocket 平台管理，免费计划包含这部分。用户只配置聊天补全：OpenAI 兼容协议，预设含 OpenAI、Hunyuan、DeepSeek，或自定义端点、密钥和模型名。教程截图示例是 `https://tokenhub.tencentmaas.com/v1` 与 `kimi-k3`。
- 系统提示最多 2000 字符，问候最多 400 字符，头像 JPG/JPEG/PNG 最大 1 MB。
- 删掉一条 URL 会触发重新抓取。教程没有写 WordPress 保存文章或 Woo 下架时自动同步。
- 教程 FAQ 写：知识库内容和对话记录只用于该账号内的检索和统计，不用于训练第三方模型。
- 教程没有记载知识导出。没有记载不等于功能不存在；只是这份一手教程没有给出导出方法。

推断，已标明：消息和嵌入不在 WordPress 数据库里。依据是插件把消息送到 Knocket 服务器，教程又把嵌入凭据划给平台。向量具体落在哪一个地区、哪一种数据库，外部看不到，不能写成事实。

GitHub 上能搜到的相关仓库也不能补上这个缺口。`rrivory/knocket-browser` 是 MIT 的挂件安装说明，作者和 WordPress 插件贡献者相同，不含知识库。`fangxinmoon/knocket-inbox-agent` 的 GitHub API 记录没有许可证，最后推送 2026-03-24，内容是用浏览器自动化操作 Knocket 控制台，不是可审计的索引实现。不能复用。

## 4. 代表插件和版本

活跃安装数来自 2026-10-26 的 WordPress.org API，只说明当时的公开规模，不作为质量判断。

| 插件 | 版本 | API 更新时间 | 活跃安装 | 许可证（包内声明） | 知识存在哪 |
| --- | --- | --- | --- | --- | --- |
| AI（WordPress.org 官方） | 1.4.0 | 2026-10-05 | 60,000 | GPL-2.0-or-later | 本站表，仅向量，无访客聊天 |
| AI Engine | 3.8.7 | 2026-10-23 | 90,000 | GPLv2 or later | 默认配置写本站；检索类不在免费包 |
| Hyve Lite | 2.0.4 | 2026-10-22 | 7,000 | GPLv3 | 本站表，或 Qdrant，或 Hyve Connect |
| MxChat | 3.2.27 | 2026-10-24 | 2,000 | GPLv2 or later | 本站表；Pinecone 代码也在免费包 |
| Rapls AI Chatbot | 1.21.4 | 2026-10-20 | 100 | GPLv2 or later | 本站表，原文加向量 |
| Limb AI Chatbot | 2.1.4 | 2026-10-22 | 100 | GPLv2 or later | 本站表或 Pinecone |
| AI Puffer（原 AI Power） | 2.5.15 | 2026-10-22 | 10,000 | GPLv2 or later | Local / Pinecone / Qdrant / Chroma / 厂商云 |
| Geeky Bot | 2.1.2 | 2026-10-22 | 6,000 | GPLv2 or later | 本站产品索引表，未见向量列 |
| AI Search | 1.44.1 | 2026-10-19 | 90 | GPLv2 | 文章 post meta |
| WPBot | 8.8.7 | 2026-10-22 | 5,000 | 声明 GPLv2 or later | 本站 `rag_documents` |
| Ask My Content | 1.10.0 | 2026-10-15 | 20 | GPL-2.0-or-later | 外部 Node 服务 |
| Queryra | 1.5.5 | 2026-10-25 | 200 | GPLv2 or later | `queryra.com` |
| StoreAgent | 1.2.1 | 2026-10-20 | 700 | GPLv2 or later | `storeagent.ai` 的 AI Memory |
| HelpJet | 1.2.1 | 2026-09-23 | 100 | GPLv2 or later | `app.helpjet.com` |
| Knocket Widget | 1.0.2 | 2026-08-21 | 0 | GPLv2 or later | Knocket / trtc.io |

WordPress 官方插件另有 Git 锚点：标签 `1.4.0` 指向提交 `e44ed4956da2ab8a419024818cb3fcf08e77a31f`，发布于 2026-10-05T19:52:09Z。来源：`https://github.com/WordPress/ai/releases/tag/1.4.0`。Rapls 源码里有 `RAPLSAICH_BUILD = d68861c`，注释写明由 git archive 替换，可作为该 zip 的短提交指针，但不是完整 SHA。

## 5. 机制对照

「免费包里看得到」和「官方声称」分开。Pro 专属而免费 zip 里没有的实现，记为未审计。

| 机制 | 自己能管原文和索引 | 索引在别人那里 | 免费包里看不到实现 |
| --- | --- | --- | --- |
| 原文留在 WP 文章，向量另存 | AI Search 的 post meta；Rapls / Puffer Local / Limb / MxChat / Hyve 自托管模式会再复制一份正文 | Knocket、HelpJet、Queryra、StoreAgent、Ask My Content | AI Engine 的 `MeowPro_MWAI_Embeddings` |
| 切块 | 见第 6 节的具体数字 | Knocket 只说会切块，不说参数 | AI Engine PDF 自动切块的实现类不在免费包 |
| 嵌入 API | 多数本机插件用站点保存的 OpenAI / Gemini / 兼容端点密钥 | Knocket 平台托管嵌入；Hyve Connect 由 Themeisle 转交 OpenAI | — |
| 检索 | PHP 余弦或点积，扫本站表 | 厂商检索后再把答案或片段送回 | AI Engine 语义检索走 Pro 类 |
| 更新 / 删除 | Rapls 钩 `save_post`；AI Search 在状态变化时写 meta；StoreAgent 更新日志称删除产品会从云记忆移除 | Knocket 要在控制台删行；Hyve 更新后进入 Needs attention，要人工确认 | — |
| Woo 产品 | 可作为文章类型被索引，或被复制进索引表 | StoreAgent 把目录上传到自己的记忆 | Hyve 的实时价格和订单技能在 Pro 文档，不在本次对 Lite 存储的结论里 |
| 导出 | 本站表会进数据库备份 | 已读的 Knocket 教程没有知识导出 | 多数插件没有在已读源码里看到独立的知识库导出器 |

## 6. 分插件：存到哪里，输入怎样交出去

### 6.1 WordPress 官方 AI 插件：可移植的向量表，不是聊天知识库

包内 readme 写明它是 AI Building Blocks 的参考实现，功能是编辑辅助、分类、摘要、翻译实验和连接器，不是访客知识库聊天。提供者密钥不在这个包里，要另装连接器。FAQ 写明也可以使用 Ollama 连接器这类本地提供者。来源：zip 内 `readme.txt`；连接器目录 `https://wordpress.org/plugins/tags/connector/`。

向量存储是 1.4.0 新增的，而且写明为了兼容 WordPress 支持的每一种 MySQL / MariaDB，不用厂商向量类型。表名 `{$wpdb->prefix}wpai_embeddings`。一行对应 `(object_type, object_id, provider, model, chunk_index)`。向量是小端 float32 的 `MEDIUMBLOB`，另有一个粗量化的 `VARBINARY(512)` 做两段检索的第一段，以及 `content_hash`。表里没有原文列。重新嵌入同一对象会替换，不会堆第二行。来源：`includes/Embeddings/Embedding_Schema.php`。

切块只出现在 WP-CLI 命令里：750 个字符，重叠 125 个字符，优先在窗口末尾约 25% 内的空白或句读处断开。来源：`includes/CLI/Embeddings_Command.php` 的 `CHUNK_SIZE`、`CHUNK_OVERLAP` 和 `chunk_text()`。在本包内，这个仓库类的调用者只有 CLI 和卸载，没有访客检索。事实：这是一套可审计的存储和切块参考，不是 Knocket 同类产品。

对「自己控制」的意义：原文仍在 WordPress 对象里，索引只存哈希和向量。引用原文时必须回到原对象再切一次，或者自己加一列正文。它没有把知识交给第三方。它也没有回答 Woo 下架后如何失效；那一层不在这个包里。

### 6.2 AI Engine 3.8.7：本站向量是默认配置，实现不在免费包

免费包许可证 GPLv2 or later。默认嵌入模型常量是 `text-embedding-3-small`，默认维度 1536。默认 `embeddings_envs` 第一条是 `type = internal`，名称 “Internal (WordPress DB)”，注释写新安装不需要外部账号。第二条是 `openai-vector-store`。同步意向里的文章类型包含 `post`、`page`、`product`，状态为 `publish`。来源：`constants/init.php`、`ai-engine.php`。

同一 zip 的 changelog 写：“Internal (WordPress DB) vector environment that stores embeddings locally, now the default for new installs.” readme 的 Knowledge 小节列出的却是 Chroma、Qdrant、Pinecone 和 OpenAI Vector Store，以及 PDF 自动切块。免费 JS 文案里有 Pinecone、Qdrant 的帮助文字，并写 DeepSeek 没有嵌入模型。来源：`changelog.txt`、`readme.txt`、`app/i18n.js`。

检索代码在 `classes/modules/search.php`：`search_with_embeddings()` 先检查 `class_exists('MeowPro_MWAI_Embeddings')`，不存在就返回 `Embeddings module not available`。免费包的自动加载只在类名以 `MeowPro_MWAI` 开头时去加载 Pro 文件，这些文件不在 wordpress.org zip 里。免费包的 `CREATE TABLE` 有聊天、文件和任务表，没有一张可审计的嵌入向量表。

事实：免费包表达了「默认把向量放在 WordPress 数据库」的配置和更新说明。事实：免费包里没有执行检索和写入的那个类。推断：内部向量环境的真实表结构、切块参数和删除行为在 Pro 附加组件里。Pro 源码本次没有取得，不能复用，也不能把 changelog 的句子当成已审计实现。

OpenAI Vector Store 路径在免费包里看得到：Responses API 会对 `type === openai-vector-store` 的环境加上 `file_search`。那是把检索交给 OpenAI，文件和索引不在本站。来源：`classes/engines/openai.php`。

### 6.3 Hyve Lite 2.0.4：本站表能审计，托管模式会把库交出去

许可证是 GPLv3，不是 “or later” 的笼统写法。来源：`readme.txt`。

本站表是 `{$wpdb->prefix}hyve`。列包括 `post_id`、`post_title`、`post_content`、`embeddings`、`embedding_model`、`token_count`、`post_status`、`storage`。`storage` 默认值是 `WordPress`。来源：`inc/DB_Table.php`。这是一份正文副本加向量，不是只存哈希。

块数上限在代码里是 `apply_filters('hyve_chunks_limit', 500)`。来源：`inc/Main.php`、`inc/DB_Table.php`。官方文档写默认最多 500 块；接上 Qdrant 后，上限变成 Qdrant 集群的容量。Qdrant 不能和 Hyve Connect 同时开。断开 Qdrant 时，超出本站 500 块上限的内容会被丢掉，要手动再加。来源：[Hyve Documentation](https://docs.themeisle.com/hyve/hyve-documentation)、[Integrate Hyve with Qdrant](https://docs.themeisle.com/hyve/integrate-hyve-with-qdrant)，2026-10-26 读取。

输入，免费版：WordPress 文章、页面、产品和自定义文章类型。文档把 Custom Data、URL、Sitemap、Documents 标为 pro。Pro 文档支持 PDF、DOCX、TXT、Markdown、CSV；扫描版 PDF 读不了，因为它抽文本，不读图。来源：同一份 Hyve Documentation。

更新：文档写，已加入知识库的文章如果后来改过，会出现在 Needs attention，要在那里更新。这是为了让内容先过审核，不是保存即自动替换。推断：这和「派生副本必须随 Woo 当前发布状态自动失效」冲突；至少在已读文档里，更新不是自动的。

两种 AI 路径，readme 和文档一致：

- Hyve Connect：Themeisle 托管，免费档有额度，有效许可证会提高额度。已读页面没有写出具体条数，所以这里不写数字。打开 Connect 时，知识库会同步到该服务；断开时可以选择导回或清空。索引过的内容和访客消息经 Themeisle 再交给 OpenAI。
- 自己的 OpenAI 密钥：请求从站点直接到 OpenAI，readme 写不经过 Themeisle。正文和向量仍在 `wp_hyve` 或 Qdrant。嵌入模型调用仍会把正文送到 OpenAI。

文档还写：放进知识库的私密或密码保护文章，可以被用来回答任何访客，即使对方打不开原页面。这是机制事实，不是产品建议。

Woo：免费版可以把产品当作文章类型加入知识库。实时价格、库存和已登录顾客的订单状态在 Pro 的 Skills 文档里，不在 Lite 的存储实现里。来源：readme 与 [Use Hyve Skills for WooCommerce](https://docs.themeisle.com/hyve/hyve-woocommerce-skills) 的文档索引；Skills 页本次没有逐段拆。

导出：已读文档写的是 Pro 可导出对话 CSV，不是知识库本身。删对话是永久的。知识库一行可以用 Delete 从回答里移除；Pro 文档的文件删除不删除媒体库原件。

### 6.4 Rapls 1.21.4：最接近「原文和索引都在本站」的小实现

许可证 GPLv2 or later。构建号 `d68861c`。

两张内容表：

- `{$wpdb->prefix}raplsaich_index`：来自站点内容。列有 `post_id`、`post_type`、`title`、`content`、`content_hash`、`chunk_index`、`url`，并有标题和正文的 FULLTEXT。随后 `ALTER` 增加 `embedding LONGBLOB` 和 `embedding_model VARCHAR(50)`。
- `{$wpdb->prefix}raplsaich_knowledge`：手工知识。列有 `title`、`content`、`category`、`is_active`、`group_id`、`chunk_index`、`chunk_total`，同样后加 `embedding LONGBLOB`。

来源：`includes/class-activator.php`。

切块默认 1000 字符。类里另有 `overlap = 100`，设置默认值 `crawler_chunk_size = 1000`。段落和句子切分本身不加重叠；只有单句超过块长、落到 `force_split()` 时，步进才是 `chunk_size - overlap`。来源：`includes/crawler/class-content-chunker.php`、`class-activator.php`。

检索：把 float32 打包进 `LONGBLOB`，PHP 里算余弦。索引表每批 500 行只取 `id` 和 `embedding`，再只为前几名取回正文。默认最多返回 10 条索引结果、5 条手工知识。来源：`includes/crawler/class-vector-search.php`。注释写 OpenAI 向量已归一化，仍算完整余弦以兼容 Gemini。

更新：`save_post` 接到爬虫的 `on_save_post`。另有每日 `raplsaich_crawl_site`。readme 写 Site Learning 默认开启，学习已发布的文章、页面和其他公开文章类型，更新文章时再学一次。来源：`includes/class-main.php`、readme。事实：这是自动派生，不是 Hyve 那种人工确认。未完全追踪下架、私密切换和 Woo 目录隐藏是否都会删行，所以不能写成已经满足 2026-10-26 的发布状态约束。

模型：源码里有 OpenAI、Gemini、Anthropic 的主机名。插件说明写首次设置可从 OpenRouter 领免费密钥。嵌入具体默认模型本次没有逐个常量抄全；向量列允许按行记录 `embedding_model`。

导出：有设置导出的 AJAX，readme 写对话可导出。知识正文在本站表里，数据库备份即包含它。没有单独核对一个「导出知识库 JSON」按钮。

后台：WP-Cron 每日爬站，另有半小时清理旧对话。没有 Redis 队列。

### 6.5 AI Puffer 2.5.15：本地策略写在免费包里，云和外部向量库并列

wordpress.org 显示名称已是 AI Puffer，slug 仍是 `gpt3-ai-content-generator`。许可证 GPLv2 or later。readme 写每月 25 个免费 AI 额度，可连 AI Puffer Cloud 而不填密钥；也可以用自己的 OpenAI、Anthropic、Gemini、xAI、DeepSeek、OpenRouter 或 Azure。DeepSeek 在外部服务说明里标的是文本，端点 `api.deepseek.com`。Pinecone、Qdrant、Chroma 若被选为知识库，readme 写明会把片段和嵌入送到那些地址。来源：readme。

Local 策略的类注释写明：块和向量在本站数据库，PHP 里搜索；向量存成单位长度，相似度是点积。表是 `{$wpdb->prefix}aipkit_vector_stores` 和 `{$wpdb->prefix}aipkit_vectors`。存储记录含嵌入提供者、模型、维度、块数和字节数。向量用 `pack('g*', ...)` 存成小端 float32。单库默认最多 10000 块，全站 25000 块，都可通过过滤器改。扫描批大小 1000。来源：`classes/knowledge-base/providers/local.php`。

切块器注释写它是给嵌入用的。默认每块 3000 token、重叠 150 token、按 4 字符估算 1 token。另有按目标库收紧的字符上限：OpenAI 6000、Chroma 5000、Ollama 2000、MxBai 384。注释写 AI Puffer Cloud 每个文本最多 8000 字节，因此云路径按最多 2000 字符切。默认最多 2000 块。来源：`classes/knowledge-base/chunker.php` 的 `AIPKit_Vector_Text_Chunker`。

文章索引有 `LocalPostProcessor`，把 WordPress 文章送进 Local 存储。readme 写可索引文章、页面、产品、自定义类型、问答和粘贴文本。来源：`classes/knowledge-base/indexing-local.php`、readme。

设置里允许的向量提供者是 `local`、`pinecone`、`qdrant`、`chroma`。来源：`classes/settings/actions.php`。事实：免费包同时包含自己控制和交出去的两条路。自己控制只在选择 Local，并且嵌入密钥是自己的时候成立。选 Cloud、Pinecone、Qdrant 或 Chroma 云，索引就不在本站。

未完全追踪：Local 表里正文存在哪一列，以及产品下架时是否删除向量。类注释说块和向量都在本站数据库，但本次没有把 `aipkit_vectors` 的每一列抄进本文。

### 6.6 Limb 2.1.4：本站向量表或 Pinecone，任务表很完整

许可证 GPLv2 or later。readme 写本地 WordPress 数据库免费且不限量，Pinecone 可选，面向 10,000 条以上。Pinecone 端点写为 `https://api.pinecone.io`。来源：readme。

向量对象表名 `lbaic_vectors`。可填充字段包括 `uuid`、`values`、`status`、`dimension`、`dataset_entry_id`、`ai_model_id`、`config_id`、`access_roles`。来源：`includes/data-objects/class-lbaic-vector.php`。事实：向量和数据集条目是分开的，向量行通过 `dataset_entry_id` 指向条目。正文因此不在向量行的已读字段里。本次没有把数据集条目表的全部列抄出。

免费包含有完整的 Pinecone 客户端目录，所以「可选 Pinecone」不是只写在 readme 里。也有任务、任务载荷和数据集相关的建表。来源：`includes/class-lbaic.php` 的 require 列表，以及初次扫描到的 `lbaic_*` 建表。

readme 列出 WooCommerce 购物协助。本次没有逐项区分哪些购物动作在免费包、哪些在付费层，所以不把 Limb 写成已经审计过的 Woo 实时库存实现。

「不限量」是 readme 的说法。抽样源码里没有看到像 Puffer 那样的硬上限常量。没有写成「代码证明没有上限」。

### 6.7 MxChat 3.2.27：本站 LONGTEXT 向量，切块后整段送回模型

许可证 GPLv2 or later。插件头写支持 OpenAI、Claude、xAI、DeepSeek、PDF、WooCommerce 和网站内容训练。

知识表 `{$wpdb->prefix}mxchat_system_prompt_content` 含 `embedding_vector LONGTEXT`。另有意图表、处理队列表 `mxchat_processing_queue`、队列 meta、Pinecone 角色表 `mxchat_pinecone_roles`。Pinecone 管理类在免费包内被 require。来源：`mxchat-basic.php`。

切块器默认 4000 字符，允许范围 1000 到 10000。注释写：同一 URL 的块在送给模型前会重新拼起来，所以不需要重叠。来源：`includes/class-mxchat-chunker.php`。这和 Rapls、Puffer、官方 AI 插件的重叠切块不是同一设计。推断：检索单位和送进模型的单位不同，引用粒度会更粗。

PDF、YouTube 和 Woo 的具体上限没有在切块器里看到。插件头和说明页的功能名不能当成上限证据。

### 6.8 另外三种本站做法

Geeky Bot 2.1.2，GPLv2 or later。它是 WooCommerce 销售助手，不是通用文档 RAG。安装器建立会话、消息、未回答问题、产品索引和政策页表。产品索引从目录复制标题、SKU、类型等字段；已读的建表语句里没有向量列。readme 写它搜索可见产品，并从 Woo 描述、属性、变体、价格、库存和获准的政策页回答。来源：`includes/Services/Installer.php`、readme。事实：这是一份目录影子表加关键词/规则路由，不是向量知识库。影子表仍然是第二份商品资料，除非重建严格跟随 Woo 的可见性。本次没有证明它已经做到。

AI Search 1.44.1，许可证行是 GPLv2。向量写在 `_ai_search_embedding` post meta，模型写在 `_ai_search_embedding_model`，用 `transition_post_status` 在发布时生成。术语向量写在 term meta，注释写 Woo 商店默认开启。来源：`ai-search.php`。原文不复制到另一张内容表。嵌入请求仍会把正文送到所选提供者。这是「索引附在 Woo 对象上」的做法，比复制整段正文更接近单一资料源，但 meta 本身仍是派生数据，下架后是否立即停用本次没有沿回收路径读完。

WPBot 8.8.7 声明 GPLv2 or later，License URI 却指向 `https://www.wpbot.pro/`，不是 GPL 文本。wordpress.org 上架要求 GPL 兼容，包内许可证行仍按它自己的声明记录。表 `{$wpdb->prefix}rag_documents` 有 `content LONGTEXT` 和 `embedding LONGTEXT`。免费包里的 `generate_embedding()` 会按设置调用 OpenAI、Gemini、OpenRouter 或 xAI。来源：`qcld-wpwbot.php`、`includes/class-qcld-bot-rag.php`。WoowBot 5.0.1 是同一作者的 Woo 插件，扫描到同名 RAG 表结构，不构成另一种存储架构。

### 6.9 知识不在本站的对照

这些结论来自已读源码或官方说明，不是安装量。

- HelpJet 1.2.1：WordPress 包只连接 `https://app.helpjet.com`。readme 写训练和外观在 HelpJet 仪表盘；免费计划 1 个机器人、每月 100 次交互。来源：`helpjet.php`、readme。
- Queryra 1.5.5：没有本站向量表。默认 API 地址 `https://queryra.com`，同步类把目录送出去。来源：`queryra-ai-search.php`。
- StoreAgent 1.2.1：聊天和记忆走 `storeagent.ai`。更新日志写 AI Memory 是上传；永久删除产品会从记忆移除；目录中隐藏的产品不再索引；达到方案上限后停止重试；并提到服务器端向量记忆在降级时被裁剪。来源：zip 内 `changelog.txt`。事实：即使删除钩子跟着 Woo，向量本体在对方服务里。
- Ask My Content 1.10.0：readme 写嵌入、搜索和补全在单独的 Node.js 后端，并给一次性 5 美元额度。完成提供者可选 OpenAI、Gemini、DeepSeek。来源：readme、`includes/settings.php`。
- Tidio、Chatway、Jotform、Collect.chat 出现在目录搜索里，本次没有拆包。它们的插件说明把自己呈现为外部客服服务的挂件。没有拆包，就不写它们的表结构。它们也不是自控知识库的候选。

SiteGround 的 AI Agent 和 WP Engine 的 Atlas Search 只读了目录说明。后者写明使用向量数据库并绑定 WP Engine。没有拆包，不写内部表。按说明，它们不是可搬到任意主机的自控索引。

## 7. 模型 API 和嵌入是两件事

聊天补全能换成 OpenAI 兼容端点，不等于嵌入也能用同一个模型和密钥。

- Knocket 教程把这两件事拆开：聊天由用户配置，嵌入由平台做。
- AI Engine 免费文案写 DeepSeek 没有嵌入模型，默认嵌入是 `text-embedding-3-small`。
- AI Puffer 把 DeepSeek 标成文本提供者，把向量库另列。
- 2026-10-26 读取的 DeepSeek 官方定价页列出 `deepseek-flash` 和 `deepseek-v4-pro`，提取文本里没有 embedding。`https://api-docs.deepseek.com/api/create-embedding` 返回的是 “Your First API Call” 页面，不是一份嵌入接口说明。来源：`https://api-docs.deepseek.com/quick_start/pricing`。不能据此写成「DeepSeek 永远没有嵌入接口」；只能写成这次一手页面没有给出可用的嵌入端点。

因此，即使用自有密钥，嵌入调用仍会把切块正文送到嵌入提供者。自己控制的是索引和原文落在哪，不是「正文永不离开服务器」。若嵌入提供者是 OpenAI，正文会到 OpenAI；若是自己运营的兼容端点，正文到那个端点。这和把整库交给 Knocket 或 Themeisle 不是同一件事。

## 8. 后台任务

共享主机、无 Redis 的条件下，已审计实现用的是 WP-Cron、自建队列表或同步的 `save_post`，不是外部队列服务。

- Rapls：保存时更新，每日爬站，半小时清理对话。
- MxChat：`mxchat_processing_queue`。
- Limb：任务表和任务载荷表。
- AI Puffer：有 `embedding-batches.php`。本次没有把批大小和失败重试全部抄出。
- AI Engine 免费包登记了 `mwai_tasks_internal_run` 这一类 WP-Cron 钩子。嵌入同步是否走它，取决于缺失的 Pro 类，不能当成已审计事实。
- Hyve 已读文档把内容更新放在 Needs attention 的人工步骤。没有把它写成可靠的自动失效队列。

没有实测这些 cron 在 Hostinger 上是否准时。WP-Cron 依赖访问触发，这是 WordPress 的一般机制，不是某个插件的承诺。

## 9. 可以合法复用什么

合法复用指许可证允许阅读、改写并再分发，而且被复用的代码确实在已审计的包里。不指「装上这个插件就等于自建完成」。

可以按声明的许可证使用的源码：

- WordPress 官方 AI 插件 1.4.0，GPL-2.0-or-later，提交 `e44ed4956da2ab8a419024818cb3fcf08e77a31f`。向量编解码、表结构和 CLI 切块是最干净的参考。它不包含聊天知识库，复制它不会得到 Knocket 的知识页。
- Rapls 1.21.4、AI Puffer 2.5.15、Limb 2.1.4、MxChat 3.2.27、WPBot 8.8.7、Geeky Bot 2.1.2、AI Search 1.44.1：包内声明 GPL。GPL 允许在保留版权声明和许可证的前提下形成衍生作品，衍生作品也必须是 GPL。可以把它们的本机索引循环当作已能工作的例子，不建议把 Limb 的 900 多个 PHP 文件或 WPBot 的整套营销模块整包搬进来。
- Hyve Lite 是 GPLv3。只按 GPLv2 发布的代码不能并入它。若作品本身是 GPLv2-or-later，组合后要按 GPLv3 分发。Hyve 还带 Themeisle SDK 和 Qdrant PHP 客户端，那些依赖有自己的许可证，不能因为插件是 GPL 就把 `vendor/` 整树改成别的许可证。
- `rrivory/knocket-browser` 是 MIT，但只是挂件安装，没有知识存储，复用它不解决本票的问题。

不能复用的：

- 任何没有出现在已审计 zip 里的 Pro 类。AI Engine 的 `MeowPro_MWAI_Embeddings`、Hyve Pro 的文件导入和 Skills、Themeisle 商店里的 `hyve.zip`，都没有取得。即使日后证明那些 Pro 也是 GPL，本次也不能复制没见过的代码。
- `fangxinmoon/knocket-inbox-agent`：GitHub API 没有许可证记录。没有许可证就不能当成可再分发的源码。
- 厂商云的 API 不是可复用的知识库实现。调用它们等于把索引交给对方。

建议复用的是机制，不是整包插件：

1. 原文只维护一份。图案继续只在 Woo 里发布。
2. 索引是派生的。要么像 AI Search 那样把向量放在同一产品的 meta 里，要么像 Rapls / Puffer Local 那样放在本站表，但必须在未发布、隐藏和删除时失效。已审计插件都没有被证明已经满足这条，所以这是要自己做的约束，不是现成功能。
3. 存储格式用可移植的 float32，不要依赖只有新版 MySQL 才有的 `VECTOR` 类型。官方 AI 插件和 Puffer Local、Rapls 都是这个选择。
4. 切块参数要写死并记在每行上，否则换模型后无法判断旧向量是否失效。官方插件用内容哈希；Rapls 同时有 `content_hash` 和 `embedding_model`。
5. 嵌入端点和聊天端点分开配置。不要假设翻译用的 DeepSeek 密钥就能做嵌入。
6. 检索结果把片段和来源 URL 或产品 ID 一起交给回答步骤。Knocket 教程要求只根据提供的知识回答；Hyve 文档用相似度阈值和来源链接做同一件事。
7. 后台用本站队列或 WP-Cron，失败要可见，不要静默丢掉一块。

## 10. 明显不适合的方案

- 把 Knocket 当作知识库宿主。嵌入由平台托管，WordPress 插件没有本地库，教程上限是每类 10 条、每文件 10 MB，而且没有写 Woo 发布状态同步。
- 只安装 Knocket、HelpJet、Tidio、Chatway、Jotform 或 Collect.chat 的 WordPress 挂件，指望知识留在本站。已审计的前两个只加载外部服务；后四个没有拆包，但说明文本已表明它们是外部服务，不能当自控存储候选。
- Hyve Connect、Ask My Content 的 Node 后端、Queryra、StoreAgent AI Memory。它们可以跟着 Woo 删除做同步，存储位置仍然不在本站。
- 把 OpenAI Vector Store 或 Assistants 文件库当作唯一知识库。AI Engine 免费包已有这条路径。文件和检索都在 OpenAI。
- 把 Pinecone、Qdrant 云或 Chroma 云当作唯一索引，又没有自己的原文副本和可重建流程。自托管 Qdrant 是另一种架构：数据可以在自己的集群，但已经不是「只在 WordPress 里」。Hyve 文档还写明，断开 Qdrant 时超出 500 块的内容会丢。
- 直接依赖 AI Engine 免费包的 “Internal (WordPress DB)” 作为已实现功能。配置和 changelog 在，执行类不在。
- 把 SiteGround 或 WP Engine 的宿主搜索插件当成可搬迁的知识库。目录说明把它们绑在宿主上，本次也没有审计其源码。
- 整包分叉 Limb 或 WPBot 来获得一个知识表。许可证上 GPL 包可以分叉，但它们混有线索、自动化和客服界面，不是一条窄的索引接口。维护面和本站「PHP 只做数据装配」的约束都不合适。这是架构判断，不是许可证禁止。

## 11. 证据局限

- 没有安装插件，没有对真实 Woo 产品做一次导入、更新、下架和检索。表结构和钩子来自源码，不来自运行轨迹。
- Pro、Cloud 和付费 zip 没有购买或下载。凡是免费包里缺失的类，都标成未审计，不用论坛转述补全。
- Knocket 的切块参数、嵌入模型和向量库物理位置，官方教程没有写。不能从外部确认。
- DeepSeek 这次读到的官方定价页没有嵌入接口。没有把「不存在」写成永久事实。
- Tidio、Chatway、Jotform、Collect.chat、BetterDocs、weDocs、Echo Knowledge Base 没有拆包。它们不进入存储机制结论。
- Hyve Connect 的具体免费额度和 Limb 的 Woo 动作是否全部在免费包里，已读材料没有给全，所以不写数字、不写「免费已包含购物车」。
- PHP 全表余弦在共享主机上的上限没有实测。Puffer 自己用批大小和块数上限承认这是内存扫描，不是数据库向量索引。146 个图案的量级远小于这些上限，这是规模对照，不是性能实测。
- Apify Google 搜索用于发现官方 URL。Knocket 的知识事实最终以 `knocket.trtc.io` 教程为准，不以搜索摘要为准。

## 12. 来源

插件事实以 2026-10-26 下载的 wordpress.org 稳定 zip 和同日 `plugin_information` 为准。

- WordPress.org Plugins API：`https://api.wordpress.org/plugins/info/1.2/`
- 官方 AI 插件：`https://wordpress.org/plugins/ai/`，`https://github.com/WordPress/ai/releases/tag/1.4.0`
- AI Engine：`https://wordpress.org/plugins/ai-engine/`，`https://meowapps.com/ai-engine/`
- Hyve：`https://wordpress.org/plugins/hyve-lite/`，`https://docs.themeisle.com/hyve/hyve-documentation`，`https://docs.themeisle.com/hyve/integrate-hyve-with-qdrant`
- MxChat：`https://wordpress.org/plugins/mxchat-basic/`
- Rapls：`https://wordpress.org/plugins/rapls-ai-chatbot/`
- Limb：`https://wordpress.org/plugins/limb-chatbot/`，`https://wpaichatbot.com`
- AI Puffer：`https://wordpress.org/plugins/gpt3-ai-content-generator/`
- Geeky Bot：`https://wordpress.org/plugins/geeky-bot/`
- AI Search：`https://wordpress.org/plugins/ai-search/`
- Knocket 教程：`https://knocket.trtc.io/tutorials/ai-agent/`
- Knocket 插件：`https://wordpress.org/plugins/knocket-live-chat-widget/`
- DeepSeek 定价页：`https://api-docs.deepseek.com/quick_start/pricing`

已审计 zip 的 SHA-256：

| slug | SHA-256 |
| --- | --- |
| ai | `c9606a481a89b254de467207ac65f6e4e6ac28a7721999705dddd10b42e87ef1` |
| ai-engine | `b721867b058826f0e2eaf56d4c1bd8bd9c90e6bd46fc4b639053aaf7c00dae67` |
| hyve-lite | `8fddb00bebd33451715bdf54ce6af12c2a89731e94a2d8129d9ff57490f591c9` |
| mxchat-basic | `568e8d19a050766870c655d98c78c1e27ece04dd37e9309a52ad3042ff32d912` |
| rapls-ai-chatbot | `cb19ce4b9ddd68403d97c3a2b36d03da6bc455cf9a0763624d3965a589428bb5` |
| limb-chatbot | `1f15cb1a57ecba62404e3ba1cdca6c46f972aad64acfbe66f6ebd0778b962c65` |
| gpt3-ai-content-generator | `648484cd8e652b9b93dab05f46a75b3b0dce9483c697e0721c183f25bcf039be` |
| geeky-bot | `54640807c48d2d5870ea84185670a3a6d791d2cf7bd186236c41b839cbb913c2` |
| ai-search | `44b87ba0acc52cef73cbaba5f0ff933654f0437ac6f3504745610682084726f1` |
| chatbot | `fafcc24677816649550478a9f3f0b3fd1c798d654e1ac532a3e7986a119625e6` |
| woowbot-woocommerce-chatbot | `0782659abeba149ea793ede60ea22fbd66008e764eb5344e398298c459315e33` |
| ask-my-content | `b7bbdec01b2f9f3c0311d9233a5e44576073fbeadbc586212e566ff47d10300f` |
| queryra-ai-search | `5e655047b85559d1daeb4bb9a7a6356f720ef84b696804b43ebc2a34bead18d0` |
| storeagent-ai-for-woocommerce | `a80236d3c9af19e8a9a32607bb255f997ac5975279a948a19b0bd3519da8b7c7` |
| helpjet | `d3a14110fda44feae2715e3918393fd7daa3264e8b6afb19cba90bb9854d81f4` |
| knocket-live-chat-widget | `0e83bdf00a57684ed96a439f343e4337a5e50f60fb493fca1ce4d0c8da81ee04` |
