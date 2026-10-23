# 14 · 本地栈与 CI 骨架规格

状态:已定稿(Grilling 票,执行会话推荐即定稿,业主保留否决;AGENTS.md §2 2026-10-24)。
蓝本:de-linker 仓库的 `tools/local/`(uv 项目 `linker-local`)、根 `justfile`、`docs/local-stack.md`、`.github/workflows/check.yml`——只读参考,按下述裁剪。
本文档是本地栈实现票的输入;规格只写路径引用与选项名,**任何密钥内容永不进入本仓库**(AGENTS.md §4 公开仓库红线)。

## 0. 结论一览

1. **实例拓扑**:默认唯一开发实例 `dev`(每任务默认 1 个、复用优先,业主 2026-10-24 裁定);登记表沿用 de-linker 的机器级 `instances.toml`,记录项从三端口简化为单端口。
2. **首期插件面**:WordPress 7.1.3 + WooCommerce 11.2.1 + ACF Pro 6.3.1(本地 zip,见 §2 升级警示)+ LiveCanvas 4.9.5 + WindPress 3.2.92(wp.org 免费版)+ SEOPress 10.3 + SEOPress PRO(业主账号 zip)+ yansir-md 1.0.1 + Redis Object Cache 3.0.0 + 仓库自研插件(符号链接)。**Polylang 不装**,只留版本钉版插槽(现行 3.9.1)。
3. **CI 骨架**:公开仓库单工作流 `check.yml`(静态检查 + 单元测试;PHP 8.3 / Python 3.12 锁定),零 secrets 设计;GitHub Actions 对公开仓库标准 runner 免费,额度不构成约束(证据见 §9)。

---

## 1. 实例拓扑

### 1.1 默认一实例,复用优先

- 首期全程一个实例,名 `dev`。每张执行票复用它:接手时 `just up`,交付前按 AGENTS.md §0 与 `docs/agents/local-storage.md` 决定是否 `just reset dev` 重建(干净基线)或保留(后续票复用)。
- `just` 配方的实例名参数带默认值 `dev`:`just up` ≡ `just up dev`。这是对 de-linker(强制显式名)唯一有意简化;需要隔离对照时(如二期 Polylang 迁移演练)才建第二实例,并按 local-storage.md 登记。
- 实例名规则沿用 de-linker:小写字母、数字、`_`,字母开头(它同时是库名与目录名)。

### 1.2 登记表(简化自 linker-local 的 registry)

- 位置:`~/metal-divider-local/instances.toml`(数据根,不在仓库;本机所有检出/工作树共用,配机器级 `fcntl` 文件锁,与 de-linker 同法)。
- 每实例记录:`port`(一个,WordPress 内置服务器;metal-divider 首期没有 engine/Genie 侧服务,去掉 de-linker 的三端口)、`checkout`(链接到哪个检出)、`created`。
- 端口分配:从 **9890** 起向上取空闲端口(逐个 bind 测试)。不与 de-linker 抢:同机 de-linker 从 8888 向上扫(`registry.py` 的 `FIRST_PORT = 8888`),9890 起步加 bind 测试即可稳定避让;两者同时停机时登记表各管各的,无冲突。
- 删除实例 = 停进程、drop 库 `md_<name>`、删目录、登记表移除、按前缀 `SCAN`/`UNLINK` 清本实例 Redis 键(禁 `FLUSHDB`/`FLUSHALL`,local-storage.md 已裁定)。

### 1.3 服务与本机事实(2026-10-24 `brew list --versions` 实测)

| 项 | 本机 | 说明 |
|---|---|---|
| PHP | `php@8.3` 8.3.35_1 | 实例服务器与 WP-CLI 都在 8.3 上跑(同 de-linker;默认 `php` 8.5 不动) |
| MySQL | 26.10.0 | 未钉版(同 de-linker 策略;生产主机未定,差异随主机供给票钉) |
| Redis | 8.10.2_1 | 对象缓存,`127.0.0.1:6379` 第 0 号库,键前缀 `md_<name>:` |
| WP-CLI | 2.12.0 | `brew pin wp-cli` 防漂移 |
| just / uv / composer | 1.59.0 / 0.13.4 / 2.10.3 | CI 钉同版本(§6) |

MySQL 经本机 socket 免密 root 管理;专用用户 `md_local`,口令写 `~/metal-divider-local/md_local.my.cnf`(600),库 `md_<name>`——与 de-linker 的 `linker_local` 完全同法。

### 1.4 隔离(承接 de-linker `tools/local/mu/`,每次 `up` 复制进实例)

- 邮件:`wp_mail()` 落盘 `mail/*.eml`;php.ini `sendmail_path = /usr/bin/false`。
- 外联:HTTP API 只放行 `127.0.0.1`,其余记 `logs/isolation.log` 并拒绝。
- 浏览器:CSP `default-src 'self' http://127.0.0.1:*` + `X-Robots-Tag: noindex, nofollow`。
- **二期接口点**:DeepSeek 翻译 API(deepseek 官方)需要外联。隔离 allowlist 设计为可配置(实例目录内文件,如 `allow-hosts.txt`,由 mu-plugin 读),首期为空;二期加 `api.deepseek.com` 一行即可,不改代码结构。

---

## 2. 首期插件面与版本钉版

### 2.1 版本表(全部 2026-10-24 核实,来源见 §9)

| 组件 | 钉版 | 来源与核对 |
|---|---|---|
| WordPress | 7.1.3 | wp.org 当前版(api.wordpress.org 版本检查 API);zip 带 `.sha1` 旁车核对(`13eb1d04…`) |
| WooCommerce | 11.2.1 | wp.org 插件 API;`wp plugin verify-checksums` 逐文件核对 |
| ACF Pro | **6.3.1** | 本地 zip `~/Downloads/advanced-custom-fields-pro.6.3.1.zip`(头读得 6.3.1);zip 解包目录 `advanced-custom-fields-pro/`。**警示见 §2.2** |
| LiveCanvas | 4.9.5 | livecanvas.com changelog 现行版(2026-09-30);wp.org 无 → 本地档案收录 |
| WindPress | 3.2.92 | wp.org 插件 API(免费版);verify-checksums |
| SEOPress(免费) | 10.3 | wp.org 插件 API;verify-checksums |
| SEOPress PRO | 业主账号 zip,装时读头 | seopress.org 账号下载;changelog 现行 10.3(2026-10-01) |
| yansir-md | 1.0.1 | 本地 zip `~/Downloads/yansir-md-1.0.1.zip`;**解包目录是 `28-wp-markdown-editor/`**,主文件 `yansir-md.php`——安装逻辑按真实目录,不按 zip 名 |
| Redis Object Cache | 3.0.0 | wp.org 插件 API;verify-checksums |
| Polylang(**不装**) | 插槽记 3.9.1 | wp.org 插件 API;二期启用 |
| 基础主题 picostrap5 | 本地档案,装时读头 | picostrap.com 分发(wp.org 无);§7 |
| 自研询盘插件 | 仓库 HEAD | 符号链接挂入实例(de-linker 同法);落地前插件面留位 |

版本唯一来源:`tools/local/src/md_local/versions.py` 常量(对应 de-linker 的 `wordpress.py` 钉版块)。本地与 CI 不一致时在这里改,一处生效。

### 2.2 ACF Pro 版本警示(证据,供业主决断)

业主裁定(2026-10-24)与手中 zip 均为 **6.3.1**;advancedcustomfields.com changelog 现行版 **6.8.10**(2026-09-10),更新内容含多项安全修复(PDF 头校验、REST 字段读权限等)。本规格遵裁定钉 6.3.1 起步,但**建议生产装机前经业主账号重新下载现行 zip 收档**(AGENTS.md §2 对破解 zip 的顾虑同理适用于旧档);档案机制(§3.1)使换入 6.8.10 zip 是纯收档动作,不改代码。

### 2.3 版本对齐策略(与 de-linker 反向)

de-linker 有线上主机可读,版本合同方向是"主机 → 本地"。metal-divider 生产主机未建(#1 Not yet specified 的缓存/主机配置未定),方向反转:**本地钉版 = 目标机的版本合同**——主机供给票(未来)必须按 `versions.py` 装;主机上线后合同方向翻回 de-linker 式(读回主机为源,本地跟随)。PHP 同理:本栈钉 **PHP 8.3**(composer `config.platform.php` "8.3"、phpcs `testVersion 8.3-`、CI 矩阵 8.3;依据:本机 php@8.3 已装、de-linker 生产实证 WP 7.1.3 + Woo 11.2 兼容 8.3、LiteSpeed 主机普遍供给 8.3;WP/Woo 最低要求仅 PHP 7.4,8.3 是余量选择)。

---

## 3. 许可注入(公开仓库红线)

### 3.1 档案与密钥的本地布局

```text
~/metal-divider-local/          数据根(600/700,永不进仓库)
  archives/                     付费件本地档:ACF zip、LiveCanvas 解包目录、
                                SEOPress PRO zip、yansir-md zip、picostrap5 zip
  archives.toml                 本地档案清单:slug → 路径 + 版本 + SHA-256(首收时记)
                                之后的每次安装先核 SHA-256,zip 被调包即报错
  .cache/                       wp.org 下载缓存(Woo/WindPress/SEOPress/ROC/语言包)
  instances.toml  md_local.my.cnf  <name>/…

~/.config/metal-divider/        密钥目录(600;apify.env 已在此)
  livecanvas.env                LIVECANVAS_LICENSE_KEY=…(一行一变量,同 de-linker seopress.env 格式)
  seopress.env                  SEOPRESS_LICENSE_KEY=…
  acf.env                       槽位:ACF 许可键(业主确认持有后收档)
```

- 收档动作:`up` 首次运行发现 `~/Downloads/` 有对应 zip(按名)且档案缺失时提示收档(复制进 `archives/`、算 SHA-256、写 `archives.toml`);`~/Downloads` 只是中转,不是长期家。
- **仓库只写路径引用与选项名,永不写密钥内容**;`.gitignore` 显式排除 `*.env`、`archives/`、`*.zip` 模式兜底。

### 3.2 激活态写入(免网络)

本地实例外联被隔离(§1.4),许可服务器不可达,所以激活态**直接写选项**,不在线验证——de-linker `licenses.py` 的已验证机制:

| 插件 | 写入选项 | 依据 |
|---|---|---|
| LiveCanvas | `lc_apikey` ← livecanvas.env | de-linker licenses.py/anonymize.py 实测选项名 |
| SEOPress PRO | `seopress_pro_license_key`、`seopress_pro_license_status`、`seopress_pro_license_expiry` ← seopress.env | 同上(免费版 SEOPress 无需许可) |
| ACF Pro | 首期不写(6.3.1 无许可可运行,仅无自动更新);acf.env 收档后补 | ACF Pro 运行不强制激活 |

线上主机由部署流程在真实网络下正规激活,与本地写入互不干扰。实例 `reset` 后 `up` 重新注入,保证后台无"未激活"横幅(AGENTS.md 交付口径:后台无杂项提示)。

### 3.3 零泄漏守卫

`tools/local/tests/` 加一条本地测试:读 `~/.config/metal-divider/*.env` 的真实值,在整个工作树内 grep,命中即 FAIL(密钥进了仓库);本机跑、CI 无密钥则跳过。这是公开仓库的第二道闸(第一道是 .gitignore 与规格红线)。

---

## 4. 目录结构草图

仓库(公开,不含任何密钥/档案):

```text
metal-divider/
  justfile                      §5 配方;PHP 解析逻辑沿用 de-linker(php@8.3 优先,PHP= 可覆盖)
  tools/local/                  uv 项目 md-local:src/md_local/(registry、instance、wordpress、
                                downloads、licenses、objectcache…自 linker-local 裁剪移植)
                                mu/(隔离三件,改名 md-local-*)、router.php、tests/
  plugins/md-inquiry/           自研询盘插件(未来;符号链接挂入实例;composer 项目位预留)
  themes/md-child/              子主题(呈现层承接,§7)
  docs/specs/14-local-stack.md  本规格
  .github/workflows/check.yml   §6
```

实例(de-linker 同构,去掉 engine/genie 日志):

```text
~/metal-divider-local/dev/
  wordpress/          文档根;wp-content/plugins/md-inquiry、themes/md-child 是指向检出的链接
  php.ini  router.php  admin.txt(600)
  mail/  logs/(server.log、php-error.log、isolation.log)  run/(pid)
```

---

## 5. just 配方清单

保留(命名沿用 de-linker,降低迁移成本;`[name]` 默认 `dev`):

| 配方 | 作用 |
|---|---|
| `just up [name]` | 实例不存在就建(WP+插件面+许可注入);存在则把 php.ini、router、mu、链接刷新到当前检出再(重)启 |
| `just down [name]` | 停服务器;数据保留 |
| `just reset [name]` | 删库、删目录、清本实例 Redis 键、重建;可反复 |
| `just login [name] [--user]` | 一次性登录链接(10 分钟一用;默认 `local-admin`) |
| `just status` | 列实例:端口、库、版本、链接的检出 |
| `just wp [name] <args…>` | 对实例跑 WP-CLI |
| `just present [name]` | 安装/刷新 LiveCanvas 动态模板与 WindPress tokens 卷(幂等;§7) |
| `just windpress [name]` | 经后台重编译 WindPress 缓存并校验锚类在产物里(§7;de-linker windpress-compile 的对应面) |
| `just check` | 全链静态检查:PHP(PHPCS+PHPStan,tools/local 与未来插件项目)、Python(ruff+mypy) |
| `just test` | 单元测试:md-local pytest(建真实实例的栈测试本机跑、CI 跳过) |
| `just build` | 槽位:首个带前端构建的自研插件落地时启用(@wordpress/scripts;首期不建空配方) |

裁掉(de-linker 有、本站不用):

- `sync`/`pull` 整族——线上快照链。metal-divider 内容来自 #10 的 Shopify Admin API 导出,导入是另一条管线,不经本地栈。
- `serve`/`index`/`eval`/`mock-erp`/`genie-concurrency`/`page-cache-chain`——Genie/engine/ERP 是 de-linker 的产品面,本站没有。
- `e2e`、`mu-smoke`——评审站与自研插件未落地;真实实例冒烟是 CI 槽位(§6)。
- `cutover` 族——上线切换是未来独立 runbook 票。
- `i18n` 族、`german-copy` 等德语/i18n 门——二期(Polylang 插槽)。

---

## 6. CI 骨架

### 6.1 约束与额度

- 公开仓库:Actions 标准 runner 免费,分钟数不设限(docs.github.com 原句见 §9);不使用付费 runner、不自托管。
- **零 secrets**:工作流不引用任何 `secrets.*`;付费插件档案与密钥只在本机,CI 侧相关测试自动跳过(de-linker 同法)。缓存(setup-uv cache 等)只缓存公开包,不含凭据。

### 6.2 工作流清单(首期一个文件)

`.github/workflows/check.yml`,`on: pull_request / push: main / workflow_dispatch`,`permissions: contents: read`:

| 步骤 | 内容 |
|---|---|
| runner | `ubuntu-24.04`(命名钉版,防镜像漂移;de-linker 同法) |
| 语言 | setup-php **8.3**(tools: composer:v2);setup-uv(钉本机 uv 版本)Python **3.12**;just(钉版本) |
| 第三方 action | 按 commit SHA 钉,注释记 tag(de-linker 同法) |
| 执行 | `just check` → `just test` |

矩阵:首期单点(php 8.3 × python 3.12),不铺多版本——版本合同唯一(§2.3),多版本矩阵与"钉版即合同"矛盾。

**槽位**:`smoke` job——runner 自带 MySQL/Redis 上建裸实例跑插件冒烟(de-linker `mu-smoke` 先例:MYSQL_PWD=root、apt 装 redis-server、`just up smoke`)。自研询盘插件落地时启用;裸实例不需要任何付费档案,零 secrets 不破。

### 6.3 版本锁定落点

| 项 | 锁点 |
|---|---|
| PHP | 各 composer 项目 `config.platform.php: "8.3"`;phpcs `testVersion 8.3-`;CI 矩阵 8.3 |
| Python | `tools/local/.python-version` = 3.12;uv.lock 提交;CI 钉同版 |
| just / uv / WP-CLI | README 工具链表记版本(实现票在仓库 README 建"工具链"一节,de-linker README 同构);CI 钉同版 |

---

## 7. 呈现层承接(与 AGENTS.md §5 呈现层规则的接口)

实例承接三件套的路径(de-linker picostrap5-child + LC 动态模板的对应面):

1. **基础主题 picostrap5**(本地档案):LiveCanvas 的同源配套主题,picostrap.com 分发;选它因为整条 present/WindPress/provider 管线在 de-linker 已实证跑通,迁移成本最低。**子主题 `themes/md-child` 进仓库**,`up` 以符号链接挂入;子主题只有 functions.php(主题自身行为),视觉零决策——样式只在 WindPress。
2. **LiveCanvas 动态模板**:图案清单(Woo 产品归档)、图案页(单产品)、博客页由 LC 动态模板承接;模板源文件随仓库走,`just present` 幂等安装进实例。B2B 数据(图案规格字段)经 ACF + 语义短代码供给,PHP 只做数据装配。
3. **WindPress 管线**:tokens 进 WindPress 卷(`just present` 维护);自研插件输出 HTML 的类名经 provider 注册进扫描面(`f!windpress/core/cache:compile.providers` 过滤器,picostrap 先例);Tailwind v4 无服务端构建,唯一编译在后台 worker——`just windpress` 驱动该编译并断言缓存 mtime 变新、锚类在产物里(机制与断言逐条沿用 de-linker 配方)。
4. 像素门支撑:复刻页验收的全页截图以本实例为拍摄基座(同浏览器同机同视口),实例即验收环境。

---

## 8. 验收要点(实现票的过关线)

1. 全新数据根上一条 `just up` 出可用实例:WordPress 7.1.3、Woo 11.2.1、插件面全量激活,`just status` 版本与 §2.1 一致;后台无任何"未激活/需设置"许可横幅。
2. `just login dev` 十分钟一用;`just reset dev` 可反复;reset 后插件面与许可态自动回满。
3. 隔离三件生效:外联拒绝且落 isolation.log、`wp_mail()` 落 .eml 且直调 `mail()` 失败、响应带 CSP 与 noindex。
4. 密钥零泄漏:全仓 grep 不到任何本地密钥值(§3.3 守卫测试本机通过;CI 按设计跳过);规格与代码里只有路径与选项名。
5. 与 de-linker 同机并存:两栈同时 `up` 无端口、无库、无 Redis 前缀冲突。
6. CI:`check.yml` 在 PR 上全绿,工作流零 secrets;仓库 Settings→Actions 无任何配置的 secrets。
7. `just present` 与 `just windpress` 幂等重跑;windpress 断言锚类在编译产物中。
8. 存储纪律:实例、档案、缓存各有登记;`just down` 后按 local-storage.md 可完整回收(停进程、drop 库、删目录、按前缀清 Redis、登记表移除、读回核验)。
9. ACF 版本警示(§2.2)已向业主报备,收档与否有记录。

---

## 9. 证据清单(核实日期均为 2026-10-24)

| 事实 | 来源 |
|---|---|
| WordPress 现行 7.1.3;zip SHA-1 旁车 `13eb1d04…` | api.wordpress.org `core/version-check/1.7/`;wordpress.org/wordpress-7.1.3.zip.sha1 直读 |
| WooCommerce 11.2.1(Requires PHP 7.4,Tested WP 7.1.3);zip URL 200 | api.wordpress.org `plugins/info/1.2` 直读;downloads.wordpress.org HEAD |
| WindPress 3.2.92、SEOPress 10.3、Redis Object Cache 3.0.0、Polylang 3.9.1 均 wp.org 现行 | 同上 API,slug 逐个查 |
| LiveCanvas 现行 4.9.5(2026-09-30),wp.org 无此插件 | livecanvas.com/changelog(Apify 读取);de-linker docs/local-stack.md 同证 |
| ACF Pro 现行 6.8.10(2026-09-10,含安全修复) | advancedcustomfields.com/changelog(Apify 读取) |
| SEOPress changelog 现行 10.3(2026-10-01) | seopress.org/changelog(Apify 读取) |
| 业主 ACF zip = 6.3.1、解包目录 `advanced-custom-fields-pro/`;yansir-md zip = 1.0.1、解包目录 `28-wp-markdown-editor/`、主文件 `yansir-md.php` | 本地 zip 头读(2026-10-24,只读未执行) |
| picostrap 不在 wp.org(下载 404);分发在 picostrap.com | downloads.wordpress.org HEAD 404;picostrap.com(Apify 读取) |
| GitHub Actions 对公开仓库标准 runner 免费 | docs.github.com `githubs-plans`:"GitHub Actions usage is free for standard GitHub-hosted runners in public repositories"(Apify 读取) |
| 本机 php@8.3 8.3.35_1 / mysql 26.10.0 / redis 8.10.2_1 / wp-cli 2.12.0 / just 1.59.0 / uv 0.13.4 / composer 2.10.3 | `brew list --versions`(2026-10-24) |
| 登记表/隔离/许可写入机制、端口扫描法、WP-CLI 校验和法 | de-linker 仓库(只读):tools/local/src/linker_local/(registry、downloads、licenses 等)、tools/pull/src/linker_pull/licenses.py、docs/local-stack.md、justfile、.github/workflows/check.yml |
| 默认单实例、复用优先 | 业主裁定 2026-10-24(票 #14 面与 AGENTS.md §2) |
