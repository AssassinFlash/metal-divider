# 研究：de-linker Genie 与知识索引复用到 Metal Divider 的边界及 VPS 共存

- 票：[#106](https://github.com/AssassinFlash/metal-divider/issues/106)，父地图 [#86](https://github.com/AssassinFlash/metal-divider/issues/86)
- 日期：2026-10-26。分支 `research/de-linker-genie-reuse`（自 origin/main 9da89ab 的干净 worktree）。
- 性质：**只读研究**。未改 de-linker 仓库、VPS 文件/服务/nginx、任何 WordPress；未在 VPS 安装或启动任何东西；未读取任何 env 值/密钥/令牌/客户数据（只取 env 键名）。
- 证据三类：①de-linker 仓库文件（路径+行号）；②VPS `ssh -o BatchMode=yes linker-services` 只读实测（2026-10-26，输出摘录见附录 A）；③metal-divider 既有票 #86/#91/#103/#104 的结论引用。

## 0. 结论摘要

1. **机制可复用，产品不可复用。** de-linker Genie 里真正通用的是四块：知识索引构建管线（Docling→分块→嵌入→SQLite FTS5+sqlite-vec）、运行时检索（`retrieval.py`/`knowledge.py`）、服务令牌门（`gate.py`）与版本化发布/回滚（`ops/release.sh`）。会话、UI、询盘、转人工、价格门、理解层全部长在 de-linker 的 WordPress 壳插件和储能业务上，对 Metal Divider 只能参考重写。
2. **它不是 WordPress 插件，也不是坐席收件箱。** 它是一个部署在 VPS `/opt/linker-genie` 的 FastAPI 服务（uvicorn 127.0.0.1:8902，实测）加 de-linker 仓库内的私有壳插件；人工接管=给团队发邮件+后台质检页，没有坐席回复界面。任何"等于 Chatwoot"的说法都不成立。
3. **VPS 共存可行，前提是加法式隔离**：新 systemd 单元、新 `/opt/md-*` 目录、新回环端口、独立 env 与令牌；不改不重启 de-linker 的任何单元与配置。资源余量充足（实测可用内存 3128MB、磁盘余 44G）。**路线 A（Chatwoot+Dify）在这台 VPS 上与 de-linker 共存不成立**（约 4GB 容器需求 + 无 Docker + 零支出无容器主机，承 #91/#104）。
4. 跨项目复用不需要第三方授权（de-linker 无 LICENSE、全部提交为业主本人，两仓库同主），但**是否真把代码搬过来、用哪些模型通道，是业主裁定**（metal-divider AGENTS §6：任何模型改动=业主裁定）。本研究只给边界，不构成授权。

## 1. 它到底是什么（先钉死类比边界）

**不是 WordPress 插件**。`services/genie/pyproject.toml` 是独立 Python 包 `linker-genie`（FastAPI+Pydantic AI，requires-python ≥3.12，sqlite-vec==0.1.9 精确锁版本），以 systemd 单元跑在 VPS：`ExecStart=…/.venv/bin/python -m uvicorn linker_genie.app:app --host 127.0.0.1 --port 8902`（`systemctl cat linker-genie.service` 实测）。WordPress 侧的对话入口、会话存储、附件检查、转人工表单都在 de-linker 仓库的私有插件 `plugins/linker-tools/includes/Genie/`（Proxy.php、Entries.php、ContactRequest.php 等 20 个类）里——它们没有发布渠道，不能"安装"到 Metal Divider。

**不是完整 Chatwoot 式坐席收件箱**。转人工的完整语义是：服务在回答后附联系卡，客户点卡上按钮 → `ContactRequest` 经 #246 管线在 Woo 里落一条联系记录 + 团队邮件（含对话链接、摘要、页面、Lösungsentwurf），客户拿回执（规格 13「转人工」节，`docs/specs/13-genie.md:289-311`）。**没有坐席在收件箱里回复客户的界面**；`linker-genie-judge.service`（实测常驻 54MB）是质检 worker（Jev 判分），`QualityPage` 是后台检查页，都不是客服工作台。

规模实测：`src/linker_genie/` 共 19628 行 Python（48 个模块），其中约一半是 de-linker 业务（solution/prices/parts/nameplate/stock/inquiry_assessment 等）。

## 2. ①代码 / 数据 / 模型路由 / 所有权

### 2.1 可直接复用（机制层，改动小）

| 资产 | 证据 | 复用形态 |
|---|---|---|
| 索引构建管线：Docling 解析→≤512 token 分块（表格整块）→上下文头→嵌入→单 SQLite（`documents`/`chunks`/`chunks_fts` FTS5/`chunks_vec` sqlite-vec） | 规格 10 `docs/specs/10-knowledge-index.md:8-14`；代码 `services/genie/index/{build,chunking,corpus,extract,local_model}.py` | 代码搬走改包名即可；构建在 Mac 离线跑，VPS 不装 Docling/模型（规格 10:71-72「不做」） |
| 运行时检索 `retrieval.py`：向量单路成序（bake-off 实测 RRF 融合比向量单路低 11.6pp 召回@5，规格 10:49）、BM25 回退、型号圈定、碎片过滤、同页邻块、≤4 页手册整份、上限 16 | `src/linker_genie/retrieval.py`（`RRF_K=60`:49，`keyword_ranking`:243）；规格 10:47-54 | 机制与 de-linker 语料无关，换索引即用 |
| 查询编码 `embedding.py`：OpenRouter `/v1/embeddings`（gemini-embedding-2）或本机 ONNX 单线程；模型名记进索引，`query_encoder` 自动匹配；BM25 为无编码器时回退并显式记日志 | `src/linker_genie/embedding.py` 模块 docstring；规格 10:39-42 | 直用。注意 metal-divider 侧 #103 实测 DeepSeek 官方 API 无 embeddings 端点 |
| 服务令牌门 `gate.py`：全部 POST 要求 `X-Linker-Service-Token`，无变量时 503、`/health` 开放 | `src/linker_genie/gate.py:31`（`TOKEN_VARIABLE = "GENIE_SERVICE_TOKEN"`） | 直用（改名换前缀） |
| 发布/回滚 `ops/release.sh`：`$ROOT/<version>/` + `current` 软链，KEEP=3 滚动保留，`publish/rollback/status` | `ops/release.sh:3-8,26-30`；VPS 实测 `/opt/linker-genie/` 现存 3 个代码版本目录 + `current`，索引目录同构（附录 A） | 模式照抄，Metal Divider 自建根目录 |
| 失败显性原则：编码失败不退关键词、索引不可用抛 `KnowledgeUnavailable` 进兜底、每次检索记 ranking/encoder | 规格 10:39-42；`knowledge.py:52,82` | 设计规则直接继承（与 metal-divider AGENTS「失败必须显性暴露」同源） |

### 2.2 只能参考、须重写（业务层）

- **理解层与路由**（`understand.py`/`route.py`/`lexicon.py`/`jev.py`，规格 13:279-287）：产品词表来自 Woo Store API、Jev 四题意图判定、相数/kWp/kWh 过滤、配置器动作——全部是储能产品域。Metal Divider 的图案域（系列/镂空率 On request/304·316 单口径）要另建词表与路由；Jev 通道是否引入=业主裁定。
- **价格门**（`prices.py` + `CommerceState`，规格 13:262-275）：账号审批三态+净价门是 de-linker 业务。Metal Divider 无账号、价格为展示态，整套删除。
- **询盘/草稿动作**（`inquiry.py`、Lösungsentwurf、`solution.py`、`parts.py` 零件清单、`nameplate.py`/`vision.py` 铭牌识读）：Metal Divider 的询盘是 md-inquiry 单图案上下文 + WhatsApp，无购物车语义（metal-divider AGENTS §1），这层全部换成 MD 自己的询盘链。值得**继承的是规则**：模型文字永不触发发送，唯一发送通路是卡上按钮（规格 13 战役门槛 7，`docs/specs/13-genie.md:36`）。
- **会话与 UI**（`plugins/linker-tools/includes/Genie/` + React `src/genie/`）：会话=WP 自定义文章类型 `linker_conversation` + httponly cookie（规格 13:118-131），90 天保留、浏览器钥匙、登录认领——机制漂亮但绑定 de-linker 插件族；Metal Divider 需按所选路线重建（见 §4）。
- **页面感知**（`page.py` + `PageContext.php`，规格 13:227-258）：匿名 GET 页面取 `<main>`、10 分钟缓存、保存失效钩子——**机制可移植**；但价格剥离选择器（`pdp-price` 等 de-linker 类名）与 Lösungsentwurf 逻辑须按 MD 页面重写。MD 无价格门，剥离规则更简单。

### 2.3 数据：零复用，格式可复用

线上索引装的是 de-linker 的 383 份 PDF 数据手册（规格 10:6）；Metal Divider 的知识源按「Woo 唯一资料源」裁定（metal-divider AGENTS 2026-10-26）只能是 Woo 当前发布状态的自动派生副本。**索引 schema 与构建产物格式可复用，内容必须从 Woo 重建**，且刷新须自动化（de-linker 是 Mac 上手动 `just index` + 版本发布；MD 若上这套，需 Woo 保存→自动重建/失效的通路，否则违反唯一资料源裁定）。

### 2.4 模型路由：两套铁律不同，复用=业主裁定

de-linker 的四通道铁律（deepseek-flash 直连对话、Jev 仅 OpenRouter、gemini-embedding-2 仅 OpenRouter、aihubmix 永不默认）是 **de-linker 仓库的裁定**，不随代码自动带入。metal-divider AGENTS §6 现只登记了站内翻译管线（deepseek-flash，仅官方 API），并明示**任何模型改动=业主裁定**。给 MD 配 AI 客服必然新增对话与嵌入两条通道（嵌入若用 Gemini 免费档，承 #103），无论代码复用与否都要业主先行裁定。

### 2.5 所有权与许可证（实测，不虚构）

- de-linker 仓库根**无 LICENSE 文件**（`ls LICENSE*` 空）；`services/genie/pyproject.toml` 无 license 字段。
- remote 为 `git@github.com:AssassinFlash/de-linker.git`（私有）；1002 个提交全部来自 AssassinFlash（业主）。
- 结论：代码版权归业主一人，metal-divider 仓库同主（AssassinFlash/metal-divider）。**跨项目复用不存在第三方许可障碍，也不需要"开源合规"**；但两仓库 AGENTS 都把工具架构与模型通道定为业主裁定事项，路线 A/B 仍并行保留（#86），所以"把 Genie 代码搬进 MD"本身仍是待裁定事项，本研究不视为已授权。

## 3. ②各环节真实边界

| 环节 | de-linker 现状（证据） | 对 Metal Divider 的真实边界 |
|---|---|---|
| 知识构建 | Mac 离线 `just index`：Docling→分块→嵌入→SQLite；发布到 `/opt/linker-genie/index/<version>/index.sqlite`，`current` 软链（规格 10:8-14；VPS 实测 182MB×2 版+旧版 150MB） | 管线可复用；**输入换成 Woo 派生数据**，发布触发要自动化。MD 规模远小（#103：现实 200–600 块 vs de-linker 33,172 块），这套重型管线的收益要按规模重估 |
| 运行时检索 | 服务进程内读 `current/index.sqlite`，向量单路+圈定+邻块；VPS 一次 0.7–0.9s（OpenRouter 编码中位 0.46s + sqlite-vec 240ms，规格 10:28，#257 实测） | 机制直用；编码通道换成 MD 裁定的嵌入源。延迟数字是 de-linker 语料/通道的实测，**不可照抄当 MD 的 SLO**，须用 MD 索引重测 |
| 会话 | **不在服务里**。会话=WP 文章+cookie，在共享主机 MySQL；服务只留 `turns.sqlite` 轮次日志与 `state/private/*.sqlite`（实测共约 60KB） | 复用服务≠得到会话。MD 的会话归属（WP 库 / Chatwoot / 浏览器）取决于路线选择（§4） |
| 页面感知 | 服务匿名 GET `GENIE_WP_URL` 页面取 `<main>`，缓存 10 分钟，WP 保存钩子 POST `/pages/invalidate`（规格 13:242-253）；生产路径：Hostinger WP → nginx 8443 `location /genie/` → 127.0.0.1:8902（规格 13:252；实测见附录 A） | 机制可移植；MD 需自写 WP 侧失效钩子与选择器。对 MD 的 PDP（图案规格条）适配简单 |
| 询盘 | 对话内确认卡→Woo 订单管线（`Inquiry\Sender`），逐行确认、模型不能触发发送（规格 13 目标 5，:16） | **整体替换**为 md-inquiry（单图案上下文）+ WhatsApp 路由；继承"点击才发送"与确认卡形态 |
| 转人工 | 联系卡→团队邮件（对话链接+摘要+页面+草稿）+回执，原因四类（asked/service/dissatisfied/repeat），短语表+Jev 双路（规格 13:289-311） | 概念可参考（重复检测、不连续两轮、经模型还是经代码）；落点换成 MD 内部通知（sales@sunriverinverter.com 主）与 Telegram/邮件值班（#86 待定项） |
| 附件/铭牌 | deepseek-flash 官方 API 读图、PDFium 子进程读 PDF（规格 13:195-208） | MD 客户上传的是图纸/照片场景不同；是否要此能力是新需求，不是复用 |

**Metal Divider 必须替换的业务清单**：产品 ability 族（search/get/compare/compat→图案与系列）、价格门（删）、配置器与选型（删）、Lösungsentwurf/方案（删）、零件清单（删）、铭牌识读（另议）、EN/DE 双语（MD 首期单英语，metal-divider AGENTS 2026-10-24 多语言推迟二期）、账号三态（删）。

## 4. ③与路线 A / B 的组合（不混路线）

### 路线 A：Chatwoot + Dify —— Genie 运行时不参与，价值只在方法论

- Chatwoot 负责挂件+统一收件箱+人工接管，Dify 负责知识库+应答（#86/#91/#94）。知识、对话、接管各有归属，**把 Genie 服务塞进来就是第三条腿（混路线）**：Dify 的知识在自家 Postgres/Weaviate，不会读 VPS 的 SQLite；Genie 的会话在 WP，不在 Chatwoot。
- 可复用的只有：①Docling 解析/分块配方作为 Dify 知识摄入的参考参数；②反编造设计（结构化输出+服务端水合+引用⊆检索块，规格 13:71-73、验收节：317-323）作为 Dify 提示词与编排的质检标准；③`eval/` 评测 harness 与 TRIAL.md 的对照实测方法（`docs/research/2026-10-17-knocket/TRIAL.md:22-35`）。
- **硬事实：路线 A 上不了这台 VPS。** #91 实测 Chatwoot 需 Ruby+Postgres+Redis 约 4GB 且无生产可用的 Dify 连接器；本 VPS 实测可用内存 3128MB、无 Docker 二进制与容器服务（附录 A）。零支出下也无容器主机（#104）。路线 A 若复活，只能等业主改变支出或主机前提，与 de-linker Genie 复用无关。

### 路线 B：WordPress 原生（Rapls 方向）—— 两种不混的子形态

- **B1（全在 WP 库内，#103 推荐）**：知识 LONGTEXT+向量 LONGBLOB+会话表全在站点 MariaDB，PHP 调 Gemini 免费档嵌入，BYOK。此形态下 de-linker 的复用物=设计规则与评测方法（§2.1 表的下半部分），**不含任何运行时**。MD 规模 200–600 块，B1 成本最低、无 VPS 依赖、天然满足 Woo 唯一资料源（索引在站内随 Woo 更新）。
- **B2（WP + VPS 检索微服务，Genie 机制的窄复用）**：保留 WP 做会话/入口/询盘，把 `retrieval.py`+`knowledge.py`+`embedding.py`+`gate.py` 装成 MD 专用小服务（预计常驻 ≪194MB，MD 索引预计 ≪10MB）。**合法性前提**：索引由 Woo 自动生成、自动失效（唯一资料源裁定），服务只是检索加速件，不持第二份可独立维护的商品资料。B2 只有在 B1 的站内检索实测不够（块数增长、共享主机 PHP 限流）时才值得。
- 不混线的判据：**知识只有一处权威**（Woo；派生副本自动失效）、**对话入口只有一副面孔**（WP 内挂件或 Chatwoot 二选一）、**转人工只有一条值班链**（md-inquiry/wp_mail 队列/Telegram 或 Chatwoot 收件箱）。B1/B2 同属路线 B，可从 B1 起步、按证据升 B2；A 与 B 不叠装。

## 5. ④VPS 共存（全部为 2026-10-26 只读实测，附录 A）

### 资源余量（核实票面线索）

| 项 | 票面线索 | 实测 | 判定 |
|---|---|---|---|
| 系统 | Ubuntu 24.04 KVM | Ubuntu 24.04.4 LTS，1 vCPU（nproc=1） | ✓ |
| 内存 | 3.8GiB / 可用约 3.1GiB | total 3915MB，used 786MB，**available 3128MB** | ✓ |
| 磁盘 | 48GB 余约 44GB | `/` 48G，used 4.3G，**avail 44G（9%）** | ✓ |
| Genie 常驻 | 约 199MB | RSS **194MB**（另有 judge 54MB、engine 100MB、xray 46MB、系统 nginx×4 约 36MB） | ✓ |
| 索引 | 单份约 182MB | 190,205,952B（≈181.5MB）×2 现行版 + 156,868,608B 旧版，`current`→20261025T090058Z | ✓ |
| Docker | 无 | 无 docker 二进制、无容器服务单元 | ✓ |
| 检索延迟 | 0.7–0.9s | 未实测（只读约束未跑负载）；引用 #257/规格 10:28 | 线索保留，标未经本票复测 |

结论：再容纳一个 MD 专用检索服务（≈100–200MB 常驻 + <50MB 索引）在内存与磁盘上都宽裕；1 vCPU 是共享瓶颈，但 B2 场景是低频 B2B 询盘检索且编码走 API（主要等网络），与 de-linker 生产互相挤占的风险低。**Chatwoot+Dify 除外**（§4 路线 A）。

### 关键结构事实：8443 入口属于 de-linker 自己的 nginx 实例

- 公网 8443 不是系统 nginx：`linker-engine-tls.service` 跑独立 nginx master，配置 `/etc/linker-engine-tls/nginx.conf`，`server_name 72.62.97.95`，自签证书 `/etc/linker-engine/engine.crt`；`location /`→`127.0.0.1:8901`（引擎），`location /genie/`→`127.0.0.1:8902`（Genie，body 30m、读写超时 300s），JSON 访问日志进 syslog local6。
- 系统 nginx 只管 80/8444/9443（chestervpn 订阅/伪装），xray 占 443。
- **因此：给 MD 加路由若编辑 `/etc/linker-engine-tls/nginx.conf` 并 reload，就是在改 de-linker 生产入口——违反本票与父地图"不碰 de-linker"的要求。** 共存的正确形态是**加法**：
  1. 新单元 `md-*.service`（WorkingDirectory `/opt/md-assistant/current`、独立 venv、独立 `EnvironmentFile=/etc/md-assistant.env`、独立服务令牌；建议加 de-linker 单元没有的 `User=` 非 root 与 `ProtectSystem=strict` 加固——实测 de-linker 现行单元未设 User，以 root 跑，这是它的现状，MD 不应复制）；
  2. 新回环端口（8901/8902 已占，取 8903+；`ss -tlnp` 实测占用清单见附录 A）；
  3. 公网入口二选一：**(a) 另起一个独立 TLS nginx 实例**（照抄 linker-engine-tls 的单元模式，新端口如 8445、新配置目录，零编辑 de-linker 文件）——本票推荐；**(b) 经 Hostinger→VPS 的 SSH 隧道/WP 出站直连回环不可行（共享主机无常驻进程）**，故公网 HTTPS 端口必须开，防火墙放行是新增操作；
  4. 索引与代码发布目录自建 `/opt/md-assistant/{index,<version>}` + `current` 软链，`release.sh` 模式（KEEP=3）自带回滚；
  5. 隔离红线：不共用 `/etc/linker-genie.env`、不共用令牌、不写 `/opt/linker-*`、不 reload/restart 任何 `linker-*` 单元、不共用 sqlite 文件。

### 备份与回滚

- de-linker 生产纪律：备份只存本机、VPS 不留（de-linker AGENTS §5）；索引是 Mac 构建的可重建产物。MD 沿用同一纪律：**服务设计为无状态（会话在 WP），VPS 上只有可重建的索引与轮次日志，本机留构建产物与发布包即可**；回滚=切 `current` 软链（release.sh 已实现）。
- 索引目录的保留边界：de-linker 现存 3 份代码版本+3 份索引版本（≈530MB），符合 KEEP=3；MD 同样按 KEEP=3 滚动淘汰，不留无限增长。

## 6. ⑤最小验证与真正部署前验收

### 本票已做的只读最小验证（不改任何东西）

VPS 面板核实：系统/内存/磁盘/单元/端口/索引/nginx 结构（附录 A）；de-linker 仓库规格-代码-实测三角互证（规格 10/13 的运行面叙述与 VPS 实际部署一致，含 8443 路由细节）。**唯一与规格叙述有出入处**：规格 13:252 说"经 nginx 8443"，实际是经 `linker-engine-tls` 的独立 nginx 实例而非系统 nginx——对共存的含义见 §5。

### 不装不重启还能做的下一步验证（可行性证明）

1. **本地实例**：Mac 上 `uv run` 起 genie 服务代码 + 用 `index/` 管线从少量 MD 图案数据（Woo 导出的规格与 PDF）构建迷你索引，跑 `eval/` harness 量召回与延迟——仓库测试本就离线可跑（`tests/` pytest；构建依赖在 `index` 依赖组）。这能在零 VPS 接触下证明"机制层复用"的真实成色。
2. **本地 VPS 模拟**：`release.sh` 的发布/回滚演练按规格 10:79 本来就要求"本地 VPS 模拟目录即可"。

### 真正部署前的验收门（按序）

1. **业主裁定门**：模型通道清单（对话+嵌入）与"是否搬 de-linker 代码"获业主明示；嵌入若用 Gemini 免费档，先核实配额与降级策略（#86 未决项）。
2. **Woo 唯一资料源门**：索引 100% 由 Woo 当前发布状态自动生成，Woo 保存→自动重建/失效实证；不存在需要手工同步的第二数据源。
3. **隔离门**：部署前后 `systemctl list-units`、`ss -tlnp`、de-linker 各单元状态与配置文件 diff 为零改动；de-linker 生产可用性复测（一次真实 /genie/turn 或 /health 经 8443）。
4. **资源门**：MD 服务上线后 VPS 内存/磁盘/CPU 采样对比；de-linker 侧延迟不回归（用 de-linker 自己的 `ops/turn_latency.py` 口径，由 de-linker 协作方确认，不由本工程代跑生产负载）。
5. **回滚演练门**：MD 自己的 release 回滚实做一次（软链切换）。
6. **业主路径实走门**（metal-divider AGENTS §5）：评审站先走通 浏览→提问→询盘提交→通知→WhatsApp 路由；线上首次亮相应答质量按 TRIAL.md 式对照题集判分（MD 版题集自建，≥20 问，含规格与"On request"边界题），0 编造数字、检索失败显性。
7. **隐私门**：访客对话保留期限、AI 披露句（EU AI Act 式首条标识，规格 13:21 先例）按 MD 法务文案定稿。

## 7. 风险与证据局限

- **未跑负载**：本票 VPS 侧纯只读，0.7–0.9s 检索与内存"常驻"数字是瞬时 ps/既有记录，不是 MD 负载下的峰值；1 vCPU 上两个服务并发的尾延迟未测。
- **延迟线索未复测**：票面 0.7–0.9s 承自 #257（de-linker 语料+OpenRouter 编码），换嵌入通道与语料后须重测。
- **许可证结论边界**：只验证了 de-linker 仓库无 LICENSE、同主全部提交；未审计 vendor/ 内第三方代码（mu-plugins/vendor 等在 metal-divider 侧，de-linker services/genie 依赖均为开源 PyPI 包，各自有许可证，随依赖传递，不构成复用障碍）。
- **8443 公网可达性**：Hostinger 共享主机出站到 VPS 8443（自签证书）的通路是 de-linker 既有事实；MD 若复用"公网端口+令牌"模式，证书校验策略（钉扎/关闭校验）须独立决定，本票未测 MD 侧通路。
- **Rapls 细节**：路线 B1 的能力边界引用 #103 结论，本票未重测 Rapls 本身。

## 附录 A：VPS 只读实测记录（2026-10-26，`ssh -o BatchMode=yes linker-services`）

```
$ cat /etc/os-release | head -2        → Ubuntu 24.04.4 LTS
$ nproc                                 → 1
$ free -m                               → total 3915 / used 786 / free 699 / buff 2712 / available 3128
$ df -h /                               → /dev/sda1 48G  4.3G  44G  9% /
$ systemctl list-units --state=running（自定义）→
  linker-engine-tls.service   Linker design-engine HTTPS entry   （独立 nginx master，8443）
  linker-engine.service       Linker design engine               （uvicorn 127.0.0.1:8901）
  linker-genie-judge.service  Jev judge worker                    （python，RSS 54MB）
  linker-genie.service        Linker Genie assistant service     （uvicorn 127.0.0.1:8902，RSS 194MB）
  nginx.service / xray-codex-tcp.service（443）
$ systemctl cat linker-genie.service →
  WorkingDirectory=/opt/linker-genie/current
  EnvironmentFile=/etc/linker-genie.env
  ExecStart=/opt/linker-genie/current/.venv/bin/python -m uvicorn linker_genie.app:app --host 127.0.0.1 --port 8902
  Restart=always（未设 User=，以 root 运行；未设沙箱加固）
$ ps RSS（自定义服务）→ genie 194MB / engine 100MB / judge 54MB / xray 46MB / nginx ≈9MB×4
$ ss -tlnp（监听）→ 22, 80, 443, 8443(独立 nginx), 8444(nginx), 9443(lo,nginx), 10000(lo,xray),
                    8901(lo,engine), 8902(lo,genie), 53(lo)        → 8903+ 空闲
$ /opt/linker-genie/      → 3 个代码版本目录(20261023T220701054062Z 等) + current→20261024T121615377632Z + index/ + state/
$ /opt/linker-genie/index/ → 20261024T083927Z(150M) + 20261024T123030Z(181.5M) + 20261025T090058Z(181.5M)
                            + current→20261025T090058Z + .activation.json + .incoming-*
$ index.sqlite 尺寸       → 190,205,952 B ×2、156,868,608 B ×1
$ /opt/linker-genie/state/private → provider.sqlite 28KB + work.sqlite 16KB（服务侧状态极小）
$ /etc/linker-engine-tls/nginx.conf（8443，独立实例）→
  server { listen 8443 ssl; server_name 72.62.97.95; ssl_certificate /etc/linker-engine/engine.crt; …
    location /    { proxy_pass http://127.0.0.1:8901; }
    location /genie/ { client_max_body_size 30m; proxy_pass http://127.0.0.1:8902/; 读写超时 300s; } }
  JSON 访问日志 → syslog local6（route/method/status/duration/request_id）
$ /etc/linker-genie.env 键名（未读值）→ GENIE_WP_URL, GENIE_JUDGE_TOKEN, GENIE_SERVICE_TOKEN,
  LINKER_PROVIDER_BUDGET_MODE/PATH, LINKER_GENIE_WORK_MODE/PATH
$ docker → 无二进制、无容器服务单元
```

## 附录 B：关键文件索引（de-linker 仓库，只读）

- 规格：`docs/specs/10-knowledge-index.md`（构建 8-14；唯一检索源 22-30；失败显式 39-42；运行时约束 44-45；检索 47-54；不做 71-72；验收基线 74-79）；`docs/specs/13-genie.md`（目标 8-25；服务 65-76；壳与会话 117-140；页面感知 225-258；价格 262-275；理解层 277-287；转人工 289-311；门槛 27-49）
- 对照实测：`docs/research/2026-10-17-knocket/TRIAL.md`（结论 6-20；方法 22-35；判分 37-62）
- 服务代码：`services/genie/pyproject.toml`（依赖与 sqlite-vec 锁版）；`src/linker_genie/{gate,retrieval,knowledge,embedding,page,contact,understand,route,prices,solution,parts,nameplate}.py`（共 19628 行/48 模块）；`index/{build,chunking,corpus,extract,local_model}.py`；`ops/release.sh`（版本目录+current 软链+KEEP=3+rollback）
- WP 壳：`plugins/linker-tools/includes/Genie/`（Proxy.php:17,50 选项 `linker_genie_url`；Conversations/ContactRequest/PageCache 等 20 类）
