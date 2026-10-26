# 研究：零新增支出下 WordPress 原生知识库与 AI 客服的真实容量

- 票：[#103](https://github.com/AssassinFlash/metal-divider/issues/103)（父地图 #86）
- 日期：2026-10-26
- 分支：`research/wordpress-native-zero-cost`（基线 origin/main@9da89ab）
- 证据分级：【源码】= 插件源码 file:line；【官方】= 一手官方文档/条款；【实测】= 本次运行得到的数据；【估算】= 由实测外推、未直接测量

## 结论摘要

**推荐 Rapls（rapls-ai-chatbot）**：六个候选中唯一「知识库 + 向量 + 检索 + 会话 + 线索全部落在 WordPress 自己的数据库、无任何厂商同步、嵌入与聊天全 BYOK」的自托管 RAG。切块数无源码硬上限，检索为批扫 + top-N 剪枝的 PHP 余弦。嵌入走 Gemini API 免费档（文本嵌入免费），聊天走 DeepSeek（业主既定路由），两者都不新增支出。

**它先撑不住的地方**（按出现顺序）：

1. **嵌入免费配额**：Gemini 免费档有速率/日配额（官方 rate-limits 页），全量重建索引时几百次嵌入调用可能触发 429，需增量重索引 + 退避。【官方】+【估算】
2. **PHP 全扫余弦的 CPU 时间**：块数到数千后，单次检索在共享主机上从几十 ms 涨到秒级（见 §性能），占用 LiteSpeed PHP worker；并发询盘时最先在这里排队。【实测外推 = 估算】
3. **没有块数硬上限**：库增长无人拦，撑不住是静默变慢而不是显式报错（AI Puffer 有 10k/25k 上限，Rapls 没有——自由与风险同源）。【源码】

**实用容量判断**：本站现实规模 200–600 块，即使文档层扩张到 2–3k 块，Rapls 在共享主机上仍可用（检索 < 0.5s 量级）；**超过约 3000 块应切换混合检索（关键词初筛 + 向量精排）或换 AI Puffer Local**。10000 块是共享主机上纯 PHP 余弦的不可用线（1–3s/查询）。

**明确不适用**：AI Search（语义检索走 `service.wp-search.ai` 厂商云，知识出站，零成本假设也不成立）；Hyve Lite（默认 500 块上限、嵌入仅 OpenAI、Lite 版文档/URL/自定义数据导入全是 Pro 位）。

---

## 1. 插件层：数据归属与切块上限（【源码】，wp.org 官方包 2026-10-26 下载）

| 插件（slug@版本） | 原文/切块/向量去向 | 切块上限 | 检索方式 |
| --- | --- | --- | --- |
| Rapls（rapls-ai-chatbot@1.21.4） | 全部自有表：`wp_raplsaich_knowledge`（LONGTEXT）+ `wp_raplsaich_index.embedding LONGBLOB` + 会话/消息/线索表 | 块大小默认 **1000 字符**（`class-content-chunker.php:15`，可调）；**块数无硬上限** | 批 500 行读回 → `unpack('f*')` → PHP 全量余弦 + top-N×3 剪枝（`class-vector-search.php`） |
| AI Puffer（gpt3-ai-content-generator@2.5.15） | Local 策略：`wp_aipkit_vector_stores` + `wp_aipkit_vectors.embedding mediumblob`（归一化 float32）；Pinecone/Qdrant/OpenAI 向量库是**可选** provider | **10000 块/store、25000 块/站**，源码常量、filter 可覆写（`providers/local.php:25-26`）；块大小默认 **3000 token/150 重叠**（`chunker.php:15-16`） | 批 1000 行（注释：512 维时约 2MB/批）点积 |
| Limb（limb-chatbot@2.1.4） | 向量在自有表 `lbaic_vectors.values`（**longtext**，JSON 文本）；本地向量库实现 + 可选 Pinecone；BYOK OpenAI/Gemini/Claude/Grok/DeepSeek + 自家 `api.wpaichatbot.com` | 未见源码级块数硬上限 | 本地向量库余弦（`includes/vector-dbs/local/`） |
| MxChat（mxchat-basic@3.2.27） | 会话记录自有表；知识向量 `embedding_vector LONGTEXT`（JSON）；默认嵌入模型 text-embedding-ada-002；知识编排偏向 OpenAI Vector Stores（外部）与 Pinecone（外部）作为可选 | 未见块数硬上限 | 本地 longtext + 外部向量库两路 |
| Hyve Lite（hyve-lite@2.0.4） | self-hosted 模式数据在自有表（`embeddings longtext`）；**Hyve Connect 模式数据出站到 `ai.themeisle.com`**；嵌入与审核仅 OpenAI（`inc/OpenAI.php`） | **默认 500 块**（`inc/Main.php:398` `apply_filters('hyve_chunks_limit', 500)`）；Lite 版文档/URL/sitemap/自定义数据导入是 Pro 位，免费只能导 WordPress 内容 | OpenAI 嵌入 + 本地检索 |
| AI Search（ai-search@1.44.1） | **核心服务在 `service.wp-search.ai` 厂商云**（smart-embed、语义检索都出站）；本地只有搜索日志表 | 厂商侧计划限制（wp-search.ai/pricing） | 厂商云 |

「Needs attention」机制（Hyve Lite）：管理端徽章聚合两类状态——`pending`（索引后内容又被编辑，需重索引）与 `moderation`（内容审核失败），见 `inc/API.php:1061` 与后台构建产物 `build/backend/index.js` 中 `attention` 路由。【源码】

Rapls 的外部调用面：仅 BYOK 的 AI 提供方端点（OpenAI / Gemini / Claude / OpenRouter / DashScope）+ Google reCAPTCHA 可选项；无知识库同步端点。【源码】

## 2. 性能层：MySQL 读回 + PHP 余弦的真实成本

方法：本机（Apple Silicon, PHP 8.5.11 CLI, MySQL 9.6.0 InnoDB）建临时库，合成归一化 float32 向量，按 Rapls 路径「批读 LONGBLOB → `unpack('f*')` → PHP 余弦」全表扫，每档 3 次取中位。脚本见附录。【实测】

| 块数 | 1536 维 批500 | 1536 维 批1000 | 768 维 批500 | 768 维 批1000 |
| --- | --- | --- | --- | --- |
| 1000 | 52 ms | 56 ms | 26 ms | 26 ms |
| 3000 | 177 ms | 168 ms | 93 ms | 94 ms |
| 10000 | 724 ms | 633 ms | 395 ms | 344 ms |

- AI Puffer 式纯点积（归一化后免范数）10000 块@1536 维：440 ms（约省 35%）。【实测】
- 单批内存（500 行同时驻留 blob+float 数组）：1536 维 13.5 MB，768 维 7.7 MB——远低于站点 512M memory_limit。【实测】
- 表体积：1536 维 ≈ 6KB blob/块 + 正文，10000 块 ≈ 60–80 MB（MariaDB 无压力）。【估算】

**换算到 Hostinger 共享主机**（站点 CPU 为共享虚拟核，单核算力按本机的 1/2–1/4 折算，未实测）：【估算】

| 块数 | 共享主机单次检索（估算） | 判断 |
| --- | --- | --- |
| ≤1000 | 0.1–0.2 s | 可用，叠加 LLM 延迟后体感正常 |
| ~3000 | 0.35–0.7 s | 可用边界；并发询盘时占用 PHP worker 开始可感 |
| ~10000 | 1.3–3 s | 纯向量检索不可用线；必须关键词初筛（MariaDB FULLTEXT）/混合检索，或上限化 |

结论：**是否必须改关键词/混合检索的分界约在 3000 块**。本站现实规模（§4）在其下，纯向量检索够用；设计上应预留混合检索位。

局限：本机 MySQL 9.6 vs 站点 MariaDB 11.8（同为 InnoDB 逐行读 blob，差异不在版本特性）；本机 PHP 8.5 vs 站点 8.3（循环算术性能同量级）；共享主机折算系数未实测，结论取数量级。

## 3. 站点环境（【实测】，SSH `hostinger-metal-divider` 只读，2026-10-26）

| 项 | 值 | 取证方式 |
| --- | --- | --- |
| Web PHP | 8.3.36 | `curl -I https://staging.metal-divider.com/` → `x-powered-by: PHP/8.3.36`（server: hcdn） |
| memory_limit | 512M | `/opt/alt/php83/etc/php.ini`（无站点级 .user.ini 覆盖） |
| max_execution_time | 300 | 同上 |
| CLI PHP | 8.3.36，mem=-1/met=0 | `php -v` / `php -r`（wp-cli 与 cron 走 CLI 侧） |
| MySQL | MariaDB 11.8.9-log | `wp db query "SELECT VERSION();"` |
| 站点库 | 34.9 MB / 66 表 | information_schema 汇总 |
| 内容量 | product 发布 146、page 发布 20、post 0、附件 1311 | `wp post list --format=count` / posts 分组统计 |
| 产品正文 | 平均 206 字符/品，合计 29.4 KB；正文薄、实义内容在 ACF meta（postmeta 共 2.35 MB） | `AVG(CHAR_LENGTH(post_content))` |

只读承诺兑现：全程未写文件、未改配置、未重启、未装插件；`wp eval`/`wp db query` 仅执行 SELECT 与只读求值。

## 4. 规模估算：146 图案 + 公开页面 + 文档 ≈ 多少块

- 图案 PDP：146 品 ×（标题 + 规格 + ACF 字段 + 短描述，合计约 0.5–1.5k 字符）→ 1000 字符切块 ≈ **1–2 块/品 → 150–300 块**。【估算，基于 §3 实测均值】
- 公开页面（20 页 + 合规页）：**20–40 块**。partial 与 LiveCanvas 模板是设计标记，不是知识语料，不入库。
- 博客/文档（未来）：50 篇 × 2–4 块 = **100–200 块**。
- **合计：现状约 200–400 块；文档层长成后约 300–600 块**。远低于 AI Puffer 的 10000/store；达到并可能超过 Hyve Lite 默认 500（其超限行为是管理端提示，需 Qdrant 或 Pro 解锁）；对 Rapls 无上限约束。【估算】

## 5. 零成本接管：离线留联系方式 → 人工回复

三段路全部零新增支出且两段已在本仓库存在：【源码】+【官方】

1. **站内留言**：`plugins/md-inquiry` 询盘表单已在位（PDP 直接询盘链路）。
2. **wp_mail 到既有收件人**：`plugins/md-inquiry/includes/Mail/Queue.php` 已实现出站队列 + 重试（原生 wp_mail，Hostinger mail() 模式），收件人为既有 sales@sunriverinverter.com / info@metal-divider.com，零新支出。
3. **Telegram Bot 通知**：Bot API 免费。官方条款（telegram.org/tos/bot-developers）：「broadcast up to 30 messages per second to users who have granted permission to contact them, **free of charge**」；超过 30/s 才需 Telegram Stars（0.1 Stars/条）。core.telegram.org/bots/faq：「In a single chat, avoid sending more than one message per second … eventually you'll begin receiving 429 errors」。站→`api.telegram.org/bot<token>/sendMessage` 为普通出站 HTTPS，业主单私聊接收询盘通知的量级（每天几十条）距所有限制差 4 个数量级。商用允许：Bot 条款按 Third-Party App 规范管理，禁止的是垃圾营销与违规内容，不是商业通知用途。

结论：**可以组成 Knocket 式「离线留联系方式 → 人工回复」**，且是全链路里最先该做的一段（无 AI 依赖、无配额风险、当天可上线）。

## 6. 模型层

**DeepSeek 无 embeddings 端点**。【官方】api-docs.deepseek.com（2026-10-26 抓取）：全站导航只有 Your First API Call / Models & Pricing / Token Usage / Rate Limit / Error Codes / vision / chat-completion；pricing 与 API reference 均无 embedding 字样与 `/embeddings` 端点；现行模型 `deepseek-flash`、`deepseek-v4-pro` 均为 chat/reasoning 系。交叉印证【源码】：Limb 插件 DeepSeek 适配注释明写「DeepSeek only supports chatbot functionality - no embeddings or fine-tuning」（`class-lbaic-setting.php:15`）。按票面要求明说：**找不到官方 embeddings 端点，结论以官方文档缺位为准，不从第三方博客推断。**

**零成本嵌入的替代**：

| 路线 | 成本 | 质量/工程代价 |
| --- | --- | --- |
| **Gemini API 免费档（gemini-embedding-2）** | 文本输入官方定价页明示 Free Tier **"Free of charge"**（付费档 $0.20/1M tokens）【官方】 | 与付费同模型同质量；受限速/日配额，需增量索引+退避。Rapls 原生支持 Gemini 嵌入并为此保留全量余弦（源码注释）【源码】 |
| 本地 ONNX（服务器跑嵌入模型） | 0 | 共享主机无 Python/ONNX 运行时，PHP 侧无实用推理路径——**不可行**【估算】 |
| 浏览器端模型（Transformers.js 等） | 0 | 查询向量在访客浏览器算，切块向量须下发到客户端（库越大下发越重），且客户端算力不可控；只适合小库实验【估算】 |
| 既有其他免费额度（OpenAI 等） | OpenAI 嵌入无免费档 | 无 |

结论：**嵌入用 Gemini 免费档，聊天用 DeepSeek**，与业主模型路由（§6 模型路由：站内管线 deepseek-flash 经官方 API）兼容，零新增支出。

## 7. 推荐与不适用（汇总）

- **推荐：Rapls**。理由：六家中唯一全自有数据 + 全 BYOK + 自带会话/线索/表单/限流等客服闭环；切块与检索实现最克制（批扫+剪枝，内存有界）；GPL、代码量小（62 个 PHP 文件）可审计可由本仓库接管维护。容量边界与先撑不住的点见摘要。
- **备选：AI Puffer（Local 策略）**。当块数将超 ~3000 或需要文档级知识库管理/多存储分区时切换；其 10k/25k 上限、mediumblob 归一化存储、批 1000 扫描是六个中最工程化的本地方案。
- **不适用**：AI Search（厂商云，数据出站）；Hyve Lite（500 块默认上限 + 嵌入绑 OpenAI + Lite 版只能导 WordPress 内容）；MxChat（知识编排面向 OpenAI Vector Stores/Pinecone，本地 longtext JSON 路线库膨胀最差）；Limb（可本地但插件最重、自家云服务选项与 BYOK 混杂，且本站不需要其多渠道编排）。

## 8. 证据局限

1. 共享主机折算系数（本机→Hostinger CPU）未实测；§2 的共享主机数值是量级估算。可在评审站装 Rapls + 真实数据后用真 Chrome 实测覆盖（浏览器铁律）。
2. 插件源码核查基于 wp.org 当前发布版（版本见 §1 表）；各插件 Pro 版功能未购核查，仅就免费版源码下结论。
3. Gemini 免费档的精确 RPM/RPD 数值在 rate-limits 页为 JS 渲染未能静态抓取，未引用具体数字；免费档存在本身以 pricing 页为准。
4. Telegram 结论限「服务器出站通知」用途；若未来要做 Bot 收发双向客服，再按 core.telegram.org/bots/api 核对当期限制。
5. 块数估算依赖「实义内容主要在 ACF meta」的判断（postmeta 2.35MB 的字段构成未逐字段展开），±50% 量级。

## 9. 一手来源

- DeepSeek：https://api-docs.deepseek.com/ 、https://api-docs.deepseek.com/quick_start/pricing （2026-10-26，无 embeddings）
- Gemini：https://ai.google.dev/gemini-api/docs/pricing （gemini-embedding-2 Text input Free tier "Free of charge"）、https://ai.google.dev/gemini-api/docs/rate-limits
- Telegram：https://telegram.org/tos/bot-developers （30/s free of charge、Stars 超额）、https://core.telegram.org/bots/faq （单聊 1/s、429、30/s 免费）、https://core.telegram.org/bots/api
- 插件源码包（本分析的对象）：https://downloads.wordpress.org/plugin/rapls-ai-chatbot.1.21.4.zip 、gpt3-ai-content-generator.2.5.15.zip 、limb-chatbot.2.1.4.zip 、mxchat-basic.3.2.27.zip 、hyve-lite.2.0.4.zip 、ai-search.1.44.1.zip
- 站点环境：SSH 只读实测 + 响应头（§3 表内逐项注明）

## 附录：benchmark 脚本

`/tmp/research-103-plugins/bench-cosine.php`（任务后随临时目录回收；要点已在上文，可按本节复建）：建 InnoDB 表（`content LONGTEXT` + `embedding LONGBLOB`）→ 插入合成归一化 float32 向量 → 按批 `SELECT id, embedding` → `unpack('f*')` → PHP 余弦循环，记录每档中位耗时与单批内存；对照变体为归一化点积。

```php
function cosine(array $a, array $b): float {
    $dot = 0.0; $na = 0.0; $nb = 0.0; $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i]; $na += $a[$i] * $a[$i]; $nb += $b[$i] * $b[$i];
    }
    $d = sqrt($na) * sqrt($nb);
    return $d > 0 ? $dot / $d : 0.0;
}
// 查询主循环（Rapls 路径）：批 500 行读回 → unpack → cosine → top-N 剪枝
```
