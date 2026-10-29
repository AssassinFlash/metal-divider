# 2026-10-29 · 业主六条投诉的逐条处置与证据

这一份只回答一件事：业主 2026-10-29 列出的六个问题，**哪些已修、证据是什么、哪些判定不修、理由是什么**。
每条都给出可复核的命令或读数，没有一条来自"看代码觉得应该对"。

对照图在 `shots-2026-10-29/`，全部同宽（1224 CSS px）同视口、滚动到区块后拍摄
（滚动一步是为了让 reveal 动画跑完——全页截图会拍到未显现的内容，实测 production
project-gallery 全页图从 y=2000 往下 max 亮度只有 43，是未显现而非空白）。

---

## 一、六条投诉的处置

### 1. 页面大面积空白，内容直接缺失

**分开看，因为它是两个不同缺陷叠在一起。**

#### 1.1 收尾卡「有标题、无表单」（已修）

根因是可数的：17 个模板里，收尾卡的标题写着「Send Us a Message」「Send your brief」
「Send your pattern brief」「Send My Panel Brief」「Send Us Your Glass Screen Request」
这类**表单动作**，卡片内容却是 `[lc_get_partial slug="md-inquiry-cta"]` —— 一个只有
**两个链接 + 一行说明**的 CTA band 的 partial。

旧站同位置是**真表单**（curl 逐页确认，每页 8–18 个 `<input>`）：

| 旧站路径 | `<form>` | 其中询盘表单 |
|---|---:|---:|
| `/pages/about-us` | 3 | 2 |
| `/pages/project-gallery` | 3 | 2 |
| `/pages/manufacturing-process` | 3 | 2 |
| `/collections/pattern-abstract` | 5 | 2 |
| `/pages/stainless-steel-screens` | 3 | 2 |
| `/pages/glass-hybrid-screens` | 3 | 2 |
| `/pages/custom-metal-divider-screens` | 2 | 2 |
| 其余五个 family 页 | 3 | 2 |

改法：把 band 换成 `[md_inquiry_form source_page="<页面 slug>" source_module="..."
source_button="send-inquiry"]`，卡片外壳／标题／副文案一字未动。字段仍是询盘插件自己的
一套（spec 07/19 已裁定），**没有**把旧站的 Full Name / Est. Quantity / 「I agree to be
contacted」搬回来。

覆盖 14 个文件：`about`、`project-gallery`、`manufacturing`、`shop`、`series-archive`、
`custom-screens`（两处）、以及八个 family 页。

**几何实测**（真 Chrome，`innerWidth=1224`）：

| 页面 | 收尾区块高（生产/修复前） | 收尾区块高（本地/修复后） | 页面总高 |
|---|---:|---:|---:|
| `/about-us/` | **838px** | **1,751px** | 8,121 → 9,047 |
| `/project-gallery/` | **959px** | **1,859px** | 3,586 → 5,763 |

修复前的空白可以定位到具体数字：`/project-gallery/` 左卡 `lg:col-span-3` 高 **526px**，
因为右栏（WhatsApp + Email + Location + Business Hours 四张卡）是 **526px**，而左卡里
只有 **137px** 的 band —— **389px 是被邻列拉出来的空卡**。

修复后左右等高（about 1363=1363，gallery 1426=1426），卡片高度由表单自身撑起。

#### 1.2 每页外发 9,013 字节的 HTML 注释，里面是第二个完整询盘表单（已修）

`partials/md-inquiry-cta.html` 的文档注释里写了 `[md_inquiry_form]` 这个**带方括号的
短代码名**。WordPress 在把注释当注释之前先跑短代码，于是它在注释内部展开：

- 线上 `/custom-metal-divider-screens/` 实测：注释起点字节 **66,568**，闭合字节 **75,581**，
  共 **9,013 字节**；
- 内容含 Turnstile sitekey、完整 `data-md-inquiry` JSON 配置、全部 i18n 文案、
  WhatsApp 预填 URL、`<button type="submit" ... disabled>`；
- 注释正文还带着内部规格编号（spec 19 §5.2 / spec 08 §4.1）和一句写给开发者的话。

各页 HTML 注释总量实测：`/shop/` 14,895 字节、`/project-gallery/` 15,653、
`/about-us/` 14,400、`/laser-cut-panels/` 14,286、`/manufacturing-process/` 14,038、
`/` 7,611、`/inquiry/` 4,728、`/collections/` 4,314、`/faqs/` 4,058。

修法两层：
1. 源注释里不再写带方括号的短代码名；
2. 新增 `plugins/md-site/includes/Presentation/HtmlComments.php`，在 `template_redirect`
   上开输出缓冲，发出前剥掉 HTML 注释。`script`/`style`/`textarea`/`title` 原样保留
   （里面的 `<!--` 是文本）。管理后台、AJAX、cron、feed、REST、JSON 不缓冲。

守卫：`CommentLeakTest.php`（5 tests / 16 assertions），双向证过——
把方括号短代码放回注释 → 红；把 `strip()` 改成原样返回 → 3 条红。

### 2. 导航下拉菜单样式异常

**判定：截图里没有导航栏。**

我把业主提供的 11 张截图逐张按原始分辨率读了一遍（含裁剪放大）。结论写在交付报告里：
**没有任何一张包含页头、导航栏或导航下拉**。1/4 与 2/5 那两张里的「Hotel Designer /
Commercial Designer / Resort Owner / Hospitality Designer」开合控件，是
**PROJECT MATCHES 区块里的手风琴**，不是菜单。

同时发现一个必须先说清的事实：**这 11 张截图混了新旧两个站**。

| 证据 | 结论 |
|---|---|
| 第 11 张印着 `projects@metalpartition.com` / `+8613068660047` | 旧站 |
| 第 11 张品牌名与「PROJECT INQUIRY / Tell us your project scope.」 | 旧站 |
| 第 6 张搜索框占位符是 `Search...` | 旧站（新站占位符是 `Search patterns`） |
| 第 8 张显示 `Showing 146 patterns · Page 1 of 7` + `PATTERN / FAMILY / BEST` 卡片 | **新站**（卡片字段与新站一致；旧站是 `Pattern/Family/Best fit/Open`） |
| 第 9 张印 `info@metal-divider.com` | **新站** |
| `PROJECT MATCHES` / `PROJECT EXECUTION` / `PROJECT STARTING POINTS` 三个标题 | **新站**（旧站 HTML 里搜索这三个串均为 0 命中） |

所以「导航下拉样式异常」这一条，**我拿不到对应的画面**。需要业主指出是哪一张、或重拍。

### 3. 表单区域存在莫名的大块空白间隙；且表单被删除/改动

**已修。见 1.1。**

补充一个只有修完才看得见的副作用，我一并处理了：

`.md-inquiry` 在 `inquiry.css` 里是**询盘整页的框**（`padding: 56px 0 88px` + 全页渐变）。
表单放进卡片后，这层框会被带进卡片，形成「框里套框」，多出 144px 空白。

修法是在 `inquiry.css` 末尾加一条作用域规则：

```css
.md-inquiry-app:not(#md-inquiry *) .md-inquiry {
  background: none;
  padding: 0;
}
```

`:not(#md-inquiry *)` 是排除项：询盘页自己的表单在
`<section class="md-inquiry" id="md-inquiry">` 里，任何卡片都不在。只清框，
字段／按钮／提示的规则全部是这个文件里 `.md-inquiry …` 作用域的，照旧生效。

**反向验证**（真 Chrome）：`/inquiry/` 的 `section#md-inquiry` 仍是
`padding: 56px 0px 88px` + `linear-gradient(...)` —— 没被误伤；卡片里的
`.md-inquiry` 是 `padding: 0px` / `background: none`。

### 4. 卡片列表中出现空卡槽

**判定：抽样里我没能复现，但改掉了同类的一个真实原因。**

静态判据（`tools/audit/open_panels.js`）在 19 个页型上找到的 277 条「空容器」
经过逐条核对**全部是装饰层**：渐变遮罩（`absolute inset-0 bg-gradient-to-t`）、
光斑、`pointer-events-none` 水印、以及内容本身就是 CSS 背景图的区块。

这是我判据的错，不是页面的错。收紧条件后（只接受正常流里的布局容器：`position`
不是 absolute/fixed/sticky、自身没有 `background-image`、不是 `pointer-events-none`），
19 个页型的读数是 **0 条**。

同时用**自检**证明这个判据仍然会红：夹具里种三个控件（1 个正常工作、1 个完全不响应、
1 个「已展开 + 有文案 + 零高度」），判据必须各判对；把折叠 CSS 去掉后，
那条「已展开的空面板」必须消失、自检必须变红。两个方向都实测过。

**这一条仍需要业主指出是哪一页** —— 我给不出「已修」的结论，因为我没能定位到画面。

### 5. 搜索弹层大面积空白，样式直接不通过

**判定：截图是旧站；新站的空态有内容，接口实测全通。**

- 业主那张图（第 6 张）的搜索框占位符是 `Search...`，**新站是 `Search patterns`**；
  弹层背景新站是 `#0a0a0a`，不是纯白。
- 新站打开弹层时列出**九个系列磁贴**，数据来自
  `/wp-json/wc/store/v1/products/categories`，实测 200、**九个系列全部返回、
  每个都有缩略图**，`count` 合计 146（abstract 90 / organic 16 / geometric 12 /
  landscape 8 / moroccan-arabic 8 / asian 4 / vintage-retro 4 / gradient 3 / perforated 1）。
- 无图结果的处理也是对的：`fillTile` 在没有图时把 `.md-search-hit__media` 整块移除，
  不留灰框（这条注释写在 `md-chrome.js` 里，与「不留空卡槽」同一原则）。

**但业主说「样式就不好看」是要听的。** 这一条我按「设计裁定」处理，不自行改：
弹层的视觉是业主要评的，我改了就变成我替业主定风格。记录为待裁定。

### 6. 全局性：字体贴边、间距贴死、可视范围过窄、复刻不完全、各类视觉 bug

#### 6.1 贴边与行高（既有判据，三档复扫无回归）

`tools/audit/spacing.py`，32 页：

| 视口 | 页数 | edge-touch | tight-leading |
|---|---:|---:|---:|
| 1440 | 32 | **0** | **0** |
| 390 | 32 | **0** | **0** |

#### 6.2 实时排版层级反转（已修 4 处）

320 真窄视口扫描抓到一个**真实的排版缺陷**：

`Repeat accuracy is panel-to-panel consistency; single-part cutting tolerance is stated
separately as ±0.1mm.` 这句完整说明在四个页面里用 **11px** 承载，而它**上面的标签是
12–13px** —— 正文比自己的标签还小，层级是反的。

改成 13px（全站正文最小档，`text-[13px]` 共 488 处）。四处：
`family-chinese-style` / `family-laser-cut-geometric` / `family-outdoor-privacy` /
`family-stainless-steel`。

**320 档复扫前后对比：**

| | 有缺陷页数 | 条目 | tiny-type | img-ratio |
|---|---:|---:|---:|---:|
| 修复前 | 8 | 15 | 9 | 6 |
| 修复后 | **4** | **11** | **5** | 6 |

剩下的 11 条**判定不修**：5 条 `tiny-type` 是穿孔页的**胶囊标签**
（`Heritage Lattice · PVD Rose Gold · 304 S…`，182×60 的圆角小块，11px 是旧站同款设计）；
6 条 `img-ratio` 是 `object-cover` 把横图裁进竖框（比值 > 4.0 才报），是裁切策略。

#### 6.3 「可视范围过窄」

`widest`（最宽文字占视口比例）在 1440 档为 0.5–0.956，最高的是 `/laser-cut-panels/`
的 **0.956** —— 那是通栏卡片网格的行，不是正文段。正文行宽的数据在 `defects.js`
的 `long-measure` 判据里，1440 档 96 条，新站最宽 139 字符、旧站 159 字符，
每个可比点都更窄或持平。这属既有待裁定项（改更严格会撞业主「可视范围过窄」的裁定）。

#### 6.4 复刻不完全

**这是本轮工作量最大的一条，独立成文：**
- 判据：`tools/audit/block_parity.py`（新增）
- 内容清单与逐页处置：见本文第二节

---

## 二、复刻完整性：新判据与结果

### 2.1 为什么以前的判据看不见这类缺陷

已有的 spacing / contrast / overflow / 断点扫描，测的都是**已经在页面上的东西**
长得对不对。**一个从没搬过来的区块不占空间、不贴边、不溢出**，所以上面每一个判据
都看不见它。这就是为什么一百多次绿色结果仍然漏掉整块内容。

### 2.2 新判据：`tools/audit/block_parity.py`

把两站的正文块各自抽出来，逐块问「新站有没有说同一件事」。

匹配是**故意宽松**的（两个站由不同框架生成，标点、大小写、实体转义、换行永远不同；
严格字符串比对会把每一段都报成缺失，然后没人看它的输出）。

但宽松有一个必须堵的死角：**换了数字的另一段话**。
`Lead time is fifteen business days` 与 `... thirty business days` 的编辑距离只有 0.849，
只靠阈值永远拦不住（一个数字移动约 0.01）。所以加**数字守卫**：
两段话里的任何数量（含 `fifteen`/`thirty` 这类英文数字）不一致，直接判为不同内容。

自检四组，全部双向证过：

```
SELF-CHECK ok   rewording:         best score 1.000  (must match, 标点/大小写差异)
SELF-CHECK ok   different_claim:   best score 0.000  (must NOT match, 十五天 vs 三十天)
SELF-CHECK ok   different_figure:  best score 0.000  (must NOT match, 4,000hr vs 500-1000hr)
SELF-CHECK ok   dropped:           best score 0.356  (must be reported missing)
SELF-CHECK ok   can_fail: identical=0 gutted=1
```

### 2.3 结果（排除表单字段微文案后）

表单字段的措辞是 spec 07/19 裁定过的重写，每页都会刷出十几条假缺口，
所以 `<form>` 内部的块单独标记并计数（`form` 列），不混进缺失数：

| 页面 | 旧块 | 新块 | 表单内 | **缺失** |
|---|---:|---:|---:|---:|
| /faqs/ | 457 | 442 | 2 | **67** |
| /inquiry/ | 122 | 86 | 30 | **55** |
| /outdoor-privacy-landscape-screens/ | 472 | 434 | 22 | **49** |
| /expanded-metal-panels/ | 386 | 347 | 24 | **47** |
| /perforated-panels/ | 366 | 326 | 24 | **45** |
| / | 296 | 373 | 20 | **37** |
| /manufacturing-process/ | 270 | 242 | 17 | **37** |
| /collections/pattern-abstract/ | 225 | 189 | 26 | **36** |
| /laser-cut-geometric-metal-screens/ | 388 | 362 | 19 | **30** |
| /custom-metal-divider-screens/ | 297 | 312 | 14 | **29** |
| /chinese-style-artistic-screens/ | 357 | 324 | 23 | **27** |
| /collections/pattern-geometric/ | 194 | 144 | 26 | **24** |
| /glass-hybrid-screens/ | 406 | 385 | 19 | **23** |
| /collections/pattern-landscape/ | 166 | 131 | 26 | **20** |
| /collections/pattern-organic/ | 197 | 162 | 26 | **18** |
| /about-us/ | 94 | 68 | 17 | **16** |
| /collections/pattern-moroccan-arabic/ | 162 | 128 | 26 | **16** |
| /collections/pattern-perforated/ | 134 | 99 | 26 | **16** |
| /collections/pattern-asian/ | 145 | 111 | 26 | **15** |
| /collections/pattern-vintage-retro/ | 145 | 111 | 26 | **15** |
| /project-gallery/ | 181 | 159 | 18 | **13** |
| /collections/pattern-gradient/ | 127 | 109 | 26 | **12** |
| /laser-cut-panels/ | 244 | 221 | 16 | **9** |
| /stainless-steel-screens/ | 341 | 311 | 24 | **8** |

**24 页合计缺失 664 块。** 这个数字**不是缺陷数**，理由见下一节。

### 2.4 已核实恢复的内容（逐条回旧站原文核对过）

| 页面 | 补回的内容 |
|---|---|
| `/faqs/` | `Lifespan — 10–15 years indoors with zero color shift`；盐雾表三行（`>1,000 hrs` / `500–1,000 hrs` / `>1,500 hrs`，对应 `25–30` / `15–20` / `20–25 years`）；`PVD … 25+ years weathering`；`Powder coat — Best value, 10–20 years`；`…optimal solution with a 10–20 year warranty`；`Bulk orders include full QC documentation`；问句 `Can I request samples before placing a bulk order?` |
| `/inquiry/` | 完整 5 步 Response Flow（Inquiry Intake / Technical Review / Clarification / Quote Route / Next Files），每步含「What We Check」「Response Window」与配图；配图 alt 与旧站逐字一致（`caliper-measurement-drawing`、`caliper-digital-check`、`laser-cutting-cnc`、`welder-perforated-sheet`、`export-shipping-wooden-crate-packaging`） |
| 九个系列归档 | 五个系列各自的 `pattern-catalog__intro`（asian / landscape / moroccan-arabic / perforated / vintage-retro）；整块 `Explore Other Pattern Series`，含每系列各自的四条「Browse … Patterns」链接 |
| `/custom-metal-divider-screens/` | 整块 `Project Support Desk`（五个折叠项）；`500 tons`、`200+ RAL colors`、`same-day quote`、`20+ Countries` |
| `/manufacturing-process/` | `Facility 12,000m2 shop floor`、`Finishing 20+ PVD finish options`、`ISO-Certified Production` 整段、`ISO 9001 Quality System` 认证卡 |
| `/family-glass-hybrid/` | `15+ glass textures in one system`、MOQ `From 1 sqm` / `From 1 piece`、`unit cost drops significantly above 20 sqm`、FAQ 问句 `How much do glass-hybrid screens cost?` |
| `/family-perforated-panels/` | 304/316 对比表（`500–1,000 hours`、`1,000–2,000+ hours`、`pitting risk in salt air after 5–10 years`、`20+ years with minimal maintenance`）、`10-year color warranty`、`500hr salt spray` |
| `/family-outdoor-privacy/` | `4,000+ hr ASTM B117`、`4,000hr+ Salt Spray Tested`、`25+ Year Finish Life`、`paint fades in 3–5 years; PVD holds 10–15` |
| `/about-us/` | `15+ countries` 首屏句、统计条 `15+ / Countries`、`20+ / PVD Colors`、`Installed in 15+ Countries.`、`Get a custom quote with pattern mockups in 24 hours.` |

### 2.5 「不予恢复」的判定（每条附理由）

| 内容 | 理由 |
|---|---|
| `Skip to content` | 旧站有、新站确实没有 —— 这是**真缺口**，已在页头骨架补上（见第三节） |
| `Choosing a selection results in a full page refresh.` / `Opens in a new window.` | Shopify 主题的屏幕阅读器助手文本，随平台存在，不是本站内容 |
| `Metal customization experts` | Shopify metafield 分组标签，不是正文 |
| 旧站表单微文案（`Get in Touch` / `Message (optional)` / 国家清单 / 数量档） | spec 07/19 裁定过的重写，措辞不同是设计 |
| `Stainless, brass, copper, aluminum.` | 违反材质裁定（客户可见面只许 Stainless Steel 304/316） |
| `optional aluminum, brass, and copper` / `Aluminum (5052)` / `Anodized, powder coat (RAL)` | 同上 |
| `Desert / high UV` 行里的 `3,000+ hrs` / `20–25 years` | 绑在铝上；把数字安到 316 上是编造配对 |
| `projects@metalpartition.com` | 对客邮箱裁定是 `info@metal-divider.com` |
| `open product pages` / `product detail page` | 业主裁定访客面说 pattern，不说 product |
| `200+ Ready Patterns` / `Browse 200+ Architectural Patterns` | 旧站写过，但**我方实测库存 146**；有来源不等于关于我们自己的数字是真的 |
| 价格区间（`$80–$150/sqm`、`40–160 USD/m²`、`30–60%` 加价、`Standard MOQ is 50–100 sqm`） | 对外报价口径，属商业裁定，需业主拍板；且同页另有「30 sqm / 50 sqm」互相矛盾的两套起订量 |

### 2.6 为什么 664 不能当缺陷数

**九个系列归档的 397 是假数**：新站 `series-archive` 分页（abstract 4 页），
而 `block_parity` 只抓第一页。旧站 page 1 以 `apiary / zelda / winter-branches` 开头，
新站以 `agave / allure / anderson-ranch` 开头，所以第一页比对会把**同一集合后面几页的 23 张卡
算成缺失**。

真实库存实测（两站逐页走完分页）：

| 系列 | 旧站 | 新站 | 只在旧站 | 只在新站 |
|---|---:|---:|---|---|
| abstract | 90 | 90 | — | — |
| asian | 4 | 4 | — | — |
| geometric | 15 | 12 | 3 个 gradient 图案 | — |
| gradient | 0 | 3 | — | 同上 3 个 |
| landscape / moroccan-arabic / organic / perforated / vintage-retro | 8/8/16/1/4 | 8/8/16/1/4 | — | — |
| **合计 / 唯一图案** | **146** | **146** | | |

三张 gradient 图案**只是系列归属从 geometric 改到了 gradient**（旧站 gradient 是空集合），
没有从目录消失。

**另一部分「缺失」是换过说法的复述**：`/` 的新站正文 373 块对旧站 296 块（126%）、
图片 99 对 53，硬把旧句子再贴一遍是注水，不是补全。

---

## 三、跳过链接（业主未提，但属本轮已量化的真缺口）

旧站有 `Skip to content` 指向 `#xo-main-content`；新站**一个都没有**
（`grep skip.to.(main|content)` 与 `skip-link` 在 `/`、`/shop/`、`/inquiry/`、`/faqs/` 全部 0 命中）。

代价可量化：从页首连续按 Tab，`/` 与 `/shop/` **30 次以内一次都没落进 `<main>`**。

已修：`partials/header.html` 里作为**第一个可聚焦元素**加入
`<a class="md-skip-link" href="#theme-main">Skip to content</a>`，
样式在 `chrome.css`（未聚焦时 `clip: rect(0,0,0,0)`，聚焦后钉在视口左上角）。
守卫 `SkipLinkTest.php`（3 tests / 31 assertions），双向证过：去掉链接 → 红；
换成不存在的 `#not-a-real-id` → 红，并读出骨架里真实的 `<main id='theme-main'>`。

---

## 四、工具侧修好的两个「我自己看错」

### 4.1 编译驱动在编译器僵死时不自愈（已修）

`tools/deploy/windpress_compile.py` 原先只做「点 Generate → 等网络日志出现 `cache/store`」。

读插件产物（`assets/dist/assets/useGenerateCache-*.js` + `worker-*.js`）后确认：
**Generate 本身不编译**，它只往 `BroadcastChannel('windpress')` 发
`{task:'generate-cache', target:'windpress/compiler'}`，由持有
`navigator.locks.request('task_lock', {mode:'exclusive'})` 的上下文干活。
当那个上下文僵住时，点击永远不会有请求，脚本只会报「超时」。

实测证据：点击后 12 秒内只发出 1 个请求，而且是 `api.iconify.design/lucide.json`（图标库）；
控制台 **0 条 error**（worker 拿不到锁时只 `console.log`，是 log 级，按 error 过滤看不见）。

改法：点击后 20 秒内没有 `cache/store` → 在同一频道发同一条消息、等同一条回复
（`generate-cache.response`），回复 `success` 才算数。响应体读不到不再当失败——
opencli 有时把 200 的 body 记成 `null`（实测到过），此时判断交给调用方的
**缓存文件 mtime 门槛**。

端到端实跑三次：`1793273920 → 1793274111 → 1793274209`，每次都通过。

### 4.2 点击审计看不见「加载时就坏」的面板（已修）

`interactions.js` 只比较「点击前后有没有变化」。而业主截图里那种空面板
**从来不需要点击**，页面加载时就是「已展开 + 有文案 + 零高度」。

夹具实测：把这样一个面板放进去，点击审计判成 `dead-control`——判错了。

拆成两个判据：
- `interactions.js`：点击后有没有变化 → 抓死控件；
- `open_panels.js`（新增）：**加载时**哪些面板已展开却是空的。

三种判定分开，因为要修的地方不同：`open-but-hollow`（有文案但看不见）= CSS 折叠问题；
`open-but-blank`（压根没文案）= 内容缺失；`empty-block`（预留 120px 以上空容器）= 布局留白。

**顺序也是判据的一部分**：静态判据必须先跑。反过来跑时，点击审计会把它碰到的每个
状态开关都翻一遍，那个展开的面板先被折叠，静态判据再看就什么都没有 —— 一个
「解释得通」的假通过。

---

## 五、验收状态

**未验收。** 理由不是"有问题没修"，而是下面几项只能由业主完成或裁定：

### 需要业主指出画面的（我给不出「已修」）

1. **导航下拉菜单样式异常** —— 11 张截图里没有任何一张含导航栏，见 1.2。
2. **卡片列表中的空卡槽** —— 19 页静态扫描收紧判据后为 0，我定位不到画面。

### 需要业主裁定

3. **搜索弹层视觉** —— 功能与空态内容实测正常（9 个系列磁贴、接口全通）；样式好不好看是设计裁定，我不替业主定风格。
4. **对比度** 40/37/37 条（1440/390/320，去重后），全部落在半透明白字与 WhatsApp 品牌绿，两者都是旧站同款。
5. **27 条 description 超 165 字符**（最长 191）—— 与旧站逐字相同。
6. **价格与 MOQ 口径** —— 旧站同页有两套互相矛盾的起订量（30/50 sqm 与 50–100 sqm），需业主给唯一口径。
7. **对比度/行宽等既有待裁定项**，见 `2026-10-29-status.md`。

### 需要业主动手

8. **完整询盘链最后一环** —— 一次真人 Turnstile 挑战（`submit.disabled = true` 是设计）。
9. **GSC 属性 + 验证 TXT**。

### 本轮明确判定不改

| 项 | 理由 |
|---|---|
| 320 档 5 条 `tiny-type` | 穿孔页胶囊标签，11px 是旧站同款设计 |
| 320 档 6 条 `img-ratio` | `object-cover` 裁切策略，非错误 |
| 穿孔页开孔率初值 `25%`（旧站 `50%`） | 旧站四个 marker 的 `data-pa-position` 全是 25，但首屏 readout 写 50、滑轨 `width: 50%`——旧站首次加载显示的是**没有任何 marker 对应的数字**。新站 25% 与第一个 marker 一致，**比旧站正确**。抄它等于把自相矛盾的初值一起抄过来 |
| 正文行宽（1440 档 96 条 `long-measure`） | 新站最宽 139 字符、旧站 159，每个可比点都更窄或持平 |

---

## 六、本轮验证汇总

| 项 | 结果 |
|---|---|
| `just check` | 通过（5 个 PHP 项目 PHPCS/PHPStan 全 OK；5 个 Python 项目 ruff/mypy 全 OK；38 卷平衡） |
| `just php-test` | **453 + 94 + 6 + 194 = 747 tests**，全 OK |
| `block_parity.py --self-check` | 4 组夹具 + can_fail，全过 |
| `interactive_panels.py --self-check` | 点击判据 2 项 + 静态判据 3 项，全过，**并证过会红** |
| `narrow_viewport.py --self-check` | 5 项夹具（含对照组），全过 |
| `spacing` @1440 / @390 | 32 页 `edge=0 / tight=0` |
| `narrow` @320 | 11 条，全部判定为设计 |
| 编译产物读回 | `md-inquiry-app:not` 命中 1；mtime `1793135171 → 1793274209`；624,407 → 635,827 字节 |
| 真 Chrome 几何 | about 1363=1363、gallery 1426=1426、manufacturing 1363=1363 等高；6 页 `overflowX=0` |
| 反向验证 | `/inquiry/` 的 `section#md-inquiry` 仍 `padding: 56px 0px 88px` + 渐变，未被新规则误伤 |

---

## 七、部署与线上读回（2026-10-29 收尾）

### 7.1 部署已归档

批次 `20261029T121302678380Z-9b8574d`，10 项 `deploy-verify` 全部通过，
页面缓存 GET `/` 两轮 miss → hit，备份按 rename 归档并只保留最近一组。

### 7.2 线上读回：12 / 14 项通过

判据是 `tools/audit/batch_2026_10_29_readback.py`，四组全部**读产物、不读源码**，
`--self-check` 要求每条对仓库里的修复前文本变红、修复后变绿。

| 组 | 结果 |
|---|---|
| 收尾卡有表单、无 band | **7/7 通过** —— `/about-us/`、`/project-gallery/`、`/manufacturing-process/`、`/shop/`、`/collections/pattern-abstract/`、`/stainless-steel-screens/`、`/glass-hybrid-screens/` 全部 `forms=2 bands=0` |
| 页面不夹带注释 | **4/4 通过** —— `/`、`/about-us/`、`/shop/`、`/inquiry/` 最长注释 **61 字节**（修复前同页 8,949 字节） |
| 跳过链接 | **3/3 通过** —— `/`、`/shop/`、`/inquiry/` 均 `<a class="md-skip-link" href="#theme-main">`，且 `id="theme-main"` 在同页存在 |
| 编译 CSS | **0/2 通过** —— `.md-inquiry-app:not(#md-inquiry *)` 与 `.md-skip-link` 均未进编译产物 |

`forms=2` 是对的：收尾卡 1 个 + 页脚报价弹层 1 个。修复前是 `forms=1 / bands=1`。

### 7.3 未通过的两项：原因与已确认的事实

WindPress 的编译只能在 admin 里跑（Tailwind v4 无服务端构建）。生产 admin 登录返回
**`ERROR: Stop guessing!`** —— 防猜测拦截，且 `~/Downloads/wp-admin.txt` 里是 **hPanel 账号**，
不是 WP 管理员（仓库多处记载生产管理员为 `md_admin / martinfu127@gmail.com`）。
按纪律**不猜密码**，这一项如实挂起。

**但「能不能把本地编译产物推上去」已经用可验证的方式答了：能。**

把两边的 CSS 按规则块拆开逐块比字面：

| 方向 | 块数 |
|---|---:|
| 生产 | 5,463 |
| 本地实例 | 5,280 |
| 生产有、本地没有 | **0** |
| 本地有、生产没有 | **15** |

生产是本地编译产物的**严格子集**；差的 15 块正是本批次新增
（`.md-skip-link` 2 块、`.inquiry-flow-kicker` / `.inquiry-flow-points` 4 块、作用域规则等）。
所以推送本地产物不会覆盖丢失任何既有规则 —— 但**这一步尚未执行**，因为仓库规定的
生产编译路径是经 admin，绕开它需要业主授权。

### 7.4 阻塞项

需要业主二选一：

1. 提供 `md_admin` 的密码（或直接在后台点一次 WindPress → Settings → Performance → Generate）；
2. 授权把本地编译产物直接写入生产的 `wp-content/uploads/windpress/cache/tailwind.css` ——
   依据是上面 7.3 的子集证明，风险已量化（0 块会丢）。
