# 视觉与体验验收清单（#64 / #65，2026-10-26 设计终版修订）

旧站参照：https://metalpartition.com/（冻结）。新站：https://metal-divider.com/。
工具：本机真 Chrome；1440×900 与 390×844；禁动画；等字体/图片；客户匿名上下文。

**当前裁定：旧站只提供设计语言，新站允许独立、现代化设计，不设逐像素通过线。**下方历史 parity 记录与升级台账保留作证据；像素差值只用于定位，不再作为 go/no-go。验收看视觉质量、响应式、内容正确、交互、键盘可访问性和实测性能。

## 每页型必测（computed 作参照；独立设计不要求相等）

| 页型 | URL（新） | 关键选择器 |
|---|---|---|
| 首页 | `/` | `header`, `.xo-menu-horizontal-v1__label`, `h1`, 首节 h2, hero 背景/板, footer |
| Shop | `/shop/` | 面包屑、`h1`, `.pattern-card`, 分页、footer |
| 系列归档 | `/collections/pattern-abstract/` | 面包屑、`h1`（须为 term 名）、卡片、分页 |
| 产品族 | `/laser-cut-geometric-metal-screens/` | hero h1、section、卡片、footer |
| Hub | `/collections/` | 9 张系列卡（图/文案）、标题 |
| PDP | `/pattern/apiary-architectural-metal-screen-pattern/` | 面包屑 54px、hero scene/plate/square、规格条、FAQ |
| About / FAQs / Inquiry / Gallery / Manufacturing / Privacy | 对应 URL | h1/h2、正文、表单/手风琴 |
| 博客 | `/blog/`, 单篇, 分类, `/?s=` | 不出现模板源码文本；文章可达 |
| 移动 | 以上 390 | header 73px、菜单、无横向溢出 |

## 必测 computed 指纹

- body `font-family` 含 `Inter`；`h1..h6` margin 0；根 `font-size:62.5%`（1rem=10px）。
- Tailwind：`lg:text-7xl`=56px、`md:text-5xl`=42px。
- Header：113/73px，白底；导航 label 13px Playfair uppercase；CTA 13px/40px/#141414；gutter 40px。
- 面包屑：白底，124px（shop/archive）/54px（PDP），13px Inter 黑字无下划线。
- Footer：`#1d1d1d`，桌面 629px，4 列。
- 浮动：WhatsApp 固定 56px；回顶 40px；`#open-quote` 有效。
- PDP：Apiary hero=scene、plate=black render、square=第二 scene/accent；无空 `src`。

## 门

- S1/S2 零未登记；S3 入台账。
- 像素门掩膜仅限已批准升级/已登记内容修复；逐项报业主。
- `tools/visual-census`（research 分支）作为可复跑证据工具；部署前本地跑。
- 升级台账：44px 触达、CSP、自托管字体、Woo/Hostinger 出队、Windpress cached、favicon、**sticky header**、**现代材料/价格口径**（见 `docs/acceptance/32-debt-ledger.md` 的升级段）。

## 现状：像素门尚未通过（2026-10-26 实测，不得记为绿）

门本身是「掩膜外差异数 = 0」，**当前不成立**。代表性测量（真 Chrome，dev 实例 `http://127.0.0.1:9890`，`masks.json` 仍为 0 条掩膜）：

| 目标 | 视口 | 结果 | 跑在哪个 manifest |
|---|---|---|---|
| `home/home` | 1440 | FAIL，掩膜外差异 **18,023,677** px（exit 1） | 更正后的 `a9e8432e…`（证明哈希链可用） |
| `home/home` | 1440 | FAIL，掩膜外差异 18,203,475 px | 更正前的 `8db2b28a…` |
| `collections/pattern-abstract` | 1440 | FAIL，掩膜外差异 3,481,349 px | 更正前的 `8db2b28a…` |

`home` 两次数字略有出入（18,203,475 → 18,023,677），因为 dev 站点在被其他会话持续改动；两次都是 FAIL。后两项未在更正后的 manifest 上重跑——它们的结果与 manifest 的 provenance 字段无关（差异来自页面本身），重跑只会重复同一结论，故按收敛规则不重跑。

所以本文件只登记**口径与比对集**，不登记门已通过的结论。旧站与新站在这些页型上确有可见差异；差异的处置有两条合法出路，二者都还没做完：①按页型逐项收敛到像素相等，②对每一项差异立掩膜行并附台账依据、报业主过目。已登记的升级项（sticky header 等）不参与像素门判定，但它们的差异仍需按台账报备，不能用掩膜掩盖未经裁定的差异。

比对集口径本身是实测自洽的：`check.mjs` 已用更正后的 manifest 哈希正常启动并在上述两项上给出结果，说明校验链（manifest sha256 → url-map → 基线文件 sha256）是通的——**通的是工具链，不是门**。全 170 项 × 双视口尚未跑过一遍，任何"全绿"的说法都没有证据支持。

### 基线 provenance（2026-10-26 更正）

`data/pixel-baseline/manifest.json` 的 `config.browser` 原先写着 `playwright chromium (headless)`——那是采集工具改 rule 之前留下的硬编码字符串，不是实测值。实际采集用真 Chrome（`capture.mjs` 的 `chromium.launch({channel:'chrome'})`），采集时点 2026-10-25 09:14–10:27 在该改动之后；本机打包 Chromium（revision 1248 / 156.0.8078.4）与已装 Chrome（155.0.8059.40）是不同构建，打包版无法产出这批文件。`config.userAgent` 是 `lib.mjs` 里的固定常量（基线/比对同身份），其中的 `Chrome/131` 不代表真实渲染版本。更正只动了 provenance 字段：targets、summary、generated_at、每张图的 sha256 与字节数均未变；manifest 哈希因此变为 `a9e8432e55b7eb1698368e82965e4a202806e387cf4c4475a15587d90583fac7`，`url-map.json` 的 `sources.baseline_manifest_sha256` 已同步重生成（`check.mjs` 遇哈希不符会拒绝开跑，这是唯一诚实的做法）。

## 比对集：182 目标 → 170 比对 / 12 退役

来源 `tools/pixel-check/url-map.json`（由 `gen-url-map.mjs` 从基线 manifest 生成，每次重跑自校验总数）。退役只表示**不入像素比对**，不表示站上该 URL 有任何跳转。

| 类别 | 行数 | 说明 |
|---|---|---|
| 总目标 | 182 | 基线 manifest 的 182 个抓取目标 |
| 参与比对 | 170 | 1 home + 13 pages + 146 PDP + 8 collections + 2 index |
| 退役 | 12 | 11 个 301 页（X1–X4/D7）+ 1 个空内容基线（下表） |

| 退役目标 | 台账行 | 原因 |
|---|---|---|
| `collections/pattern-gradient` | D8/#25 | **#25 已取消 D8**：新站该系列是活系列（La Pente / Nara / Skyfall 三品归位），路径 `/collections/pattern-gradient/` 保留、**无 301**。退役仅限比对：旧站该集合**有基线，但是空内容基线**——空态页，无可比的产品网格 |

`collections/pattern-gradient` 的判定证据（2026-10-26 真 Chrome 实测旧站）：页面自己在网格位置写着 `Showing 0 patterns` / `No patterns found`，`a[href*="/products/"]` 计数 **0**，`main` 高 5,491px；同期 `/collections/pattern-abstract` 为 24 个产品链接、`main` 12,804px。**基线是存在的**（两张截图、sha256 齐全，页面 HTTP 200），但它记录的是一个空态：主题外壳（筛选条、决策指南、项目证明、询盘表单）包着一个空网格。把新站的三张卡片放进这个基线里 diff，测出来的是「有没有内容」，不是「样式差」；要掩盖这个差异，掩膜得盖住整块网格，也就是整页本身——所以这里的判定是「基线内容为空、不可比」，不是「没有基线」。故该行退役——掩膜表 `masks.json` 不新增任何行。

这一行的**站点行为**没有被退役改动，两个决定互不相干：以 `plugins/md-site/includes/Seo/Config.php` 的 `REDIRECT_TARGETS` 为准，表里只有 `/pages` 壳、不含该系列；dev 实测 `http://127.0.0.1:9890/collections/pattern-gradient/` → 200、H1 `Gradient`、卡片 3 张（La Pente / Nara / Skyfall），`wp term list product_cat` 里该 term `count=3`，`/collections/` 枢纽仍链出 **9** 个系列（含 gradient）。`url-map.json` 的一行不是路由决定，也不改站上系列数。

## 升级台账明细

### 升级-1 · sticky header（业主 2026-10-26 批准）

- **旧站行为（实测，真 Chrome）**：`https://metalpartition.com/` 在 `scrollY=1200` 时 `header` top = `-1200`，即头部随页面滚走。
- **本站行为**：`scrollY=1200` 时 `#lc-header` top = `0`，头部固定在视口顶部；桌面 1440/1280/1024/992 与移动 390/320 实测一致，横向溢出 0。
- **裁定依据**：业主 2026-10-26 明确「旧站缺陷按判断力修复」，并单独批准 sticky 作为可取升级，推翻此前「仅 parity」的口径。**因此本项不是旧站 parity 声明**，不参与像素门「掩膜外差异 = 0」的判定，只按本台账登记。
- **实现**：`plugins/md-site/content/windpress/chrome.css` 的一条声明 —— `body > header#lc-header:has(> #HeaderV9-…)` 取 `position: sticky; top: 0; z-index: 100`。LiveCanvas 把 header partial 包进 `<header id="lc-header">`，它正是 `<body>` 下高度等于整条带子的那个块，所以直接子代选择器就把「谁负责 sticky」写清楚了。
  - 2026-10-26 前 `setupSticky()` 用「从 `<xo-sticky>` 向上爬到第一个比带子高的父级（+40px 容差）」来猜同一个块；那是关于包裹层高度的猜测，任何近似带高的新包裹层都会把它挪走。现在该启发式已删除，`md-chrome.js` 只保留无 `#lc-header` 时的兜底（给 tag 自身设 sticky 并打 `data-md-sticky-fallback` 标记，便于发现），实测当前头部落入 CSS 分支、**不发任何内联样式**。
  - 带高同一出处：`tokens.css` 的 `--md-header-h`（73px / 991px 起 76px / 1200px 起 113px，真 Chrome 实测 320…1440 九档），两处 `.xo-header-grid` 的 `min-height` 也读它，因此带高与偏移不可能再各自漂移。
- **未包含**：未加 `xo-is-sticky` 类，`chrome.css` 里 `.xo-header-v9__wrapper` 的滚动阴影规则因此保持惰性（实测滚动前后 `box-shadow: none`、`xo-sticky[xo-is-sticky]` 计数 0）；滚动时的阴影/背景变化如需启用，另立裁定。
- **不改动的相邻机制**：`xo-modal` 的 `z-index: 1000` 仍在头部 `100` 之上，sticky 后搜索弹窗实测覆盖头部（`elementFromPoint` 命中 `xo-modal[xo-name="search"]`）；固定态下点击 mega 面板卡片可正常跳转（`/laser-cut-geometric-metal-screens/` → 200）。
- **sticky 带来的偏移连带修复（同日）**：带子固定后，原先写死的滚动偏移都会把目标压到带子底下。统一改为读 `--md-header-h`：`html { scroll-padding-top: calc(var(--md-header-h) + 16px) }` 覆盖片段跳转/`scrollIntoView()`/滚动到焦点三条路径；目录锚点原来写 `96px`、目录侧栏 `top: 24px`、PDP 应用栏 `96px`、marquee 面板 `100px`、expanded-metal 图列 `40px`，以及 10 处模板里的 `lg:top-24`/`lg:top-10`（改为 `.md-sticky-rail`）全部换成同一 token。实测 1440：`#faq-download-questions` 落点 top = 129 = 113 + 16；390：89 = 73 + 16，均未被带子遮住。

### 升级-2 · mega 面板 h4 标题字号链（2026-10-26，为 parity 修正）

- **不是升级，是修复**：转换把 mega-menu 的 section 并成 `.xo-section` 后，卡标题 `h4` 丢失了旧主题的两条声明（`font-family: "Playfair Display"` 与 `line-height: 19.4286px` = 16 × calc(1 + 0.3/1.4)），回落到 body 的 Inter/24px。已在 `chrome.css` 的 `[data-id^="sections--21844031144189__mega_menu_v2_"] .mmv2__title` 补回这两条，`.mmv2__link` 从 h4 继承字体族。
- **实测对照（真 Chrome 2026-10-26 复核，panel 高 / 卡片高 / 链接高）**：992 旧 `665.5/310.8/[28.8×4]` = 新 `665.5/310.8/[28.8×4]`；1024 旧 `355.5/329.5/[47.5,28.8,47.5,47.5]` = 新同值；1280 旧 `336.8/310.8/[28.8×4]` = 新同值；1440 旧 `336.8/310.8/[28.8×4]` = 新同值。四档全部对齐。
- **1280 残差已收口（2026-10-26，唯一真实的 4 行折行来源）**：旧 `336.8`、新 `356`，差 19.2px = 恰好一条 `19.4286px` 标题行。根因是**字形步进宽度**，与 `text-wrap`、容器、列宽、卡片宽、字号、字重、行高都无关——这些两侧逐项相同（容器 1240、`294.5px ×4`、卡片 295、800/15px、行高 1.25）。
  - 实测同串步进（同 265px 盒，canvas `measureText`）：`Laser-Cut & Geometric Metal Screens` 旧 **261.37**（余 3.63px，单行）/ 新 **265.59**（超 0.59px，折行）；`Outdoor Privacy & Landscape Screens` 旧 **263.49**（余 1.51）/ 新 **268.85**（超 3.85）。即本仓库自托管的 Playfair 比旧站那面**宽约 1.6%**，仅这 ~4–5px 就把两张卡推成两行。
  - **此前记录的两条解释都不成立，已废弃**：(a)「旧站静态 600 面 + 合成加粗、本站可变字体 800 真面」——旧站在 800/15px 下实测步进 261.37，并未更宽；(b)「`text-wrap: balance` 导致多折」——同 DOM 只翻转该声明的 A/B 实测：balance / wrap / 完全去掉 clamp 三种状态下 `linkH` 均为 `[48,29,29,48]`、panel 与卡片高度完全相同，balance 只改变两行内断点位置，从不改变行数。
  - **修法**：`chrome.css` 的 `.mmv2__link` 加 `letter-spacing: -0.008em`（=0.12px/字，33 字标题约 4.0px）。单一字体来源不变、不引入第二套字体、不动字号字重。实测 1280 恢复 `336.8/310.8`；1024 仍为 `355.5/329.5`，与旧站一致（旧站 1024 本身就是两行）；992/1440 不受影响；四档横向溢出均为 0。
  - 同时撤掉该处 `text-wrap: balance`：它既不减少行数，又会在 1024 把断点移离旧站位置（旧站 1024 为 `Chinese-Style Artistic / Screens`），属于无收益的偏差。

### 升级-3 · 下拉子菜单（Resources）字体与行高归位（2026-10-26，为 parity 修正）

- **不是升级，是修复**：转换把普通下拉行列表（`.xo-menu-horizontal-v1__link--sub` 与 `.xo-menu-horizontal-v1__label`）留在了 Playfair 15px/19px，而旧站是 Inter 15px/22.5px、weight 500。后果是行高与菜单高双漂移，且工具型菜单用错了字体族（面板卡片标题继续用 Playfair，只有行列表回 Inter）。
- **旧站 computed（真 Chrome，metalpartition.com，2026-10-26，1280 与 1440 两档一致）**：行 `Inter 15px / 22.5px / 500`、行高 `33`、label `33`（padding `5px 0`）、菜单 `95`、宽 `200`、padding `15px 0`。
- **修前本站**：`Playfair Display 15px / 19px / 500`、行 `29`、菜单 `88` —— 行差 4px、菜单差 7px。
- **修后本站实测**：行 `Inter 15px / 22.5px / 500`、行 `32.5`、菜单 `95`、宽 `200`、padding `15px 0`，992 / 1024 / 1280 / 1440 四档同值。
- **行高 32.5 与旧站 33 的 0.5px 差**：来自 `22.5px` 行高 × 1 行 + `5px×2` padding 的亚像素取整，不是缺声明；菜单总高与旧站同为 `95`，因此不另设特例去追那 0.5px。
- **影响面**：只有可见的那一个子菜单（Resources）变化；带 mega 面板的两项（Screens / Manufacturing Process）其 `sub-menu` 本就 `display:none`（实测高 0），改动对它们无表现。
