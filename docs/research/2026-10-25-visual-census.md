# 全站视觉差异普查(票 #61)

- 票:`AssassinFlash/metal-divider#61`(父图 #60「视觉 parity 修复」)
- 日期:2026-10-25
- 对照:旧站 `https://metalpartition.com`(Shopify,内容冻结)vs 新站 `https://metal-divider.com`(WordPress + WooCommerce)
- 机器可读台账:[`2026-10-25-visual-census.json`](2026-10-25-visual-census.json)(64 条 delta,含逐页覆盖、旧/新值、严重度、证据路径与截图 sha256)
- 截图清单:[`2026-10-25-visual-census-manifest.json`](2026-10-25-visual-census-manifest.json)(268 张页面截图 + 2 张 header 裁剪对照,路径 + sha256)
- 本票只产出证据,**不改任何生产代码、模板或内容**。

---

## 0. 结论

1. **全局字体基座失效是最大面积回归**:新站 70/70 个页面快照的 body 字体都是 Times(旧站 70/70 是 Inter);凡是失去组件规则的标题还会吃浏览器 UA margin(h1 48.2px、h2 39.8px),旧站是 0。受影响的是首页、产品族页、about、faqs、gallery、manufacturing、博客与搜索;**shop / 系列归档 / hub / PDP 保留了自己的组件字号,没被波及**——修复必须落在全局层,不能逐页补。
2. **导航栏在 1440 溢出行盒并裁切报价 CTA**:旧站 nav 15px Inter 全大写、单行、CTA 右侧留 40px;新站 24px Times、`Manufacturing Process` 换两行、nav 超出 6px、CTA 右边缘贴死 1440(0 gutter,胶囊被视口切掉)。搜索图标在新站 DOM 里存在但**渲染不出图形**(不可见)。
3. **四类页面(series archive / hub / 博客 / 搜索)有 S1 级结构问题**:9 个系列归档的 H1 全部显示 `Organic Patterns`;hub 的 9 张卡片**没有任何图片**且首格空槽;`/blog/` 与 `/?s=` 把模板源码当正文渲染(转义后的 `<d="md-…">` 片段);博客文章页 404、全站 0 篇文章。另有两页(产品族 outdoor、manufacturing)在 390 横向溢出 +350/+370px。
4. **图片槽位问题与 #63 对口**:首页 hero 换了素材;PDP 的 hero 背景与右侧 plate 互换(背景变成图案本身,plate 变成红色变体);PDP hero 那句副标题丢失。
5. **浮动 WhatsApp 入口全站消失**(旧站每页都有固定 "Message us" 按钮);页脚高度翻倍(629→1341px);面包屑从白底黑字横条变成更高的透明条 + 蓝色下划线链接。
6. 计数:**S1 15 条、S2 36 条、S3 13 条**;分类:**回归 50、已批准升级 6、待定 8**。

### 与协调者先验证据的差异(实测修正)

| 先验说法 | 实测 |
|---|---|
| 旧 nav 13px Playfair | 旧站**计算值 15px Inter + 全大写**;`chrome.css` 里确有 `h3.xo-menu-horizontal-v1__label{font-size:13px}` 规则,但它在旧站没有赢(被别的规则覆盖) |
| 新 nav 22.4px Times | 顶层标签实测 **24px Times**;22.4px 是**二级/下拉标签**的字号 |
| 新标题 48px 上下 margin | 成立,且可定性:是 **Chrome UA 默认 margin**(h1 0.67em、h2 0.83em),不是设计值——说明缺 heading reset |
| 面包屑旧白底 124px(shop)/54px(PDP) | 成立(另:产品族 60px、about/faqs/mfg 60-90px、gallery 120px);新站对应 150/80/126/90/150px,透明底、链接 `#9e9eff` 下划线 |

---

## 1. 方法与复跑

### 1.1 采集协议(与仓库像素门 `tools/pixel-baseline/lib.mjs` 对齐)

| 项 | 值 |
|---|---|
| 浏览器 | 本机真 Chrome(`chromium.launch({channel:'chrome'})`,Playwright 驱动;禁 Playwright 自带 Chromium) |
| UA / locale / 时区 | `Chrome/131.0.0.0` macOS UA / `en-US` / `UTC` |
| 视口 | 1440×900、390×844,`deviceScaleFactor:1` |
| 登录态 | 干净上下文,未登录(客户视角) |
| 动效 | `reducedMotion:'reduce'` + 仓库同一份 kill CSS(animation-play-state paused / transition none / scroll-behavior auto) |
| 懒加载 | 逐屏滚到底再回顶,等图片 complete(≤3s) |
| 稳定判定 | `scrollHeight` 连续 3 次不变;`document.fonts.ready` 之后才取数 |
| 第三方覆盖层 | 两侧都拦截 `consentmo / cookieyes / gdpr-backpack / consent-tracking` 脚本并兜底点掉 Accept——旧站 CookieYes 横条会遮住页脚与浮动按钮,新站也有同引擎;遮罩属于第三方 chrome,不参与 parity 判定 |
| 统计/追踪 | 拦截 GA/GTM/Hotjar/Clarity/Meta/DoubleClick(不影响布局) |
| 截图 | 每页 full-page + fold 各一张,PNG |

### 1.2 采集范围

- **35 个页面条目 × 2 视口 × 2 站 = 140 个页面快照**;140 份 `facts`(computed style + DOM 事实)全部落盘。
- **268 张页面截图**(另加 2 张 header 裁剪对照);6 个 404 快照按协议不截图(blog-single 新站、blog-cat 两侧),HTTP 状态即证据。
- 覆盖:首页、shop、hub、**9 个系列归档**(含待退役的 pattern-gradient)、**9 个产品族页**、**3 个 PDP**(Apiary + Zelda + Tafoni)、about、faqs、inquiry、thank-you、gallery、manufacturing、privacy、博客四面(index / single / category / search)。
- 旧站 8 个 Consentmo 合规页、旧站双语 `/zh/`、旧站 146 个 PDP 全量不在本票(单模板 3 个代表品已覆盖)。

### 1.3 复跑

```bash
# 采集(可断点续跑;结果写入 $VISUAL_CENSUS_OUT,默认 /tmp/visual-census)
VISUAL_CENSUS_OUT=/tmp/visual-census node tools/visual-census/capture.mjs
# 只跑某页型/视口:node ... capture.mjs home 1440x900

# chrome 专项探针(header/logo/CTA/面包屑条/浮动件/页脚)
node tools/visual-census/chrome-probe.mjs
```

两个脚本优先从 `tools/pixel-baseline/node_modules` 解析 Playwright(可用 `PLAYWRIGHT_HOME` 覆盖;本机也保留了 `/tmp/md-chrome` 兜底)。`facts.json` 内的 `protocol` 字段记录了本次运行的浏览器、UA、视口、kill CSS 与拦截清单;`records.jsonl` 是逐任务原始记录,`manifest.json` 是截图 sha256 清单。

### 1.4 证据位置

- 原始事实:`/tmp/visual-census/facts.json`(140 条)、`chrome-probe.json`(36 条)
- 截图:`/tmp/visual-census/shots/<page>-<old|new>-<viewport>-<full|fold>.png`
- 仓库内只提交**台账 + 清单 + 脚本**:截图体积大且属可重建产物,按 AGENTS §0 不入库;清单含每张图的 sha256,可校验或重拍。

### 1.5 不在本票范围

hover/展开菜单/轮播/手风琴/粘性等交互与动画态;320px 专项(仅对已知窄屏问题补测,本票未出现 320 独有证据);多语言;第三方遮罩的视觉设计;图片槽位的**正确素材映射**(归 #63);差异的**根因收敛**(归 #62)。

---

## 2. 页型覆盖矩阵

`docH` = full-page 高度(1440×900),用于快速感知版面漂移;状态列为 `旧/新`。

| 页型 | 旧 URL | 新 URL | HTTP | docH |
|---|---|---|---|---|
| home | `/` | `/` | 200/200 | 19931/22691 |
| shop | `/collections/all` | `/shop/` | 200/200 | 13224/11495 |
| hub | `/collections/` | `/collections/` | 200/200 | 2967/4071 |
| archive | `/collections/pattern-abstract` | `/collections/pattern-abstract/` | 200/200 | 13545/11495 |
| archive | `/collections/pattern-asian` | `/collections/pattern-asian/` | 200/200 | 7530/7782 |
| archive | `/collections/pattern-geometric` | `/collections/pattern-geometric/` | 200/200 | 11087/10220 |
| archive | `/collections/pattern-gradient` | `/collections/pattern-gradient/` | 200/200 | 6232/7782 |
| archive | `/collections/pattern-landscape` | `/collections/pattern-landscape/` | 200/200 | 8727/8991 |
| archive | `/collections/pattern-moroccan-arabic` | `/collections/pattern-moroccan-arabic/` | 200/200 | 8707/8971 |
| archive | `/collections/pattern-organic` | `/collections/pattern-organic/` | 200/200 | 11087/11448 |
| archive | `/collections/pattern-perforated` | `/collections/pattern-perforated/` | 200/200 | 6823/7158 |
| archive | `/collections/pattern-vintage-retro` | `/collections/pattern-vintage-retro/` | 200/200 | 7431/7762 |
| family | `/pages/laser-cut-geometric-metal-screens` | `/laser-cut-geometric-metal-screens/` | 200/200 | 9523/11724 |
| family | `/pages/glass-hybrid-screens` | `/glass-hybrid-screens/` | 200/200 | 9748/11972 |
| family | `/pages/outdoor-privacy-landscape-screens` | `/outdoor-privacy-landscape-screens/` | 200/200 | 10690/13832 |
| family | `/pages/chinese-style-artistic-screens` | `/chinese-style-artistic-screens/` | 200/200 | 11309/14246 |
| family | `/pages/stainless-steel-screens` | `/stainless-steel-screens/` | 200/200 | 9797/12245 |
| family | `/pages/custom-metal-divider-screens` | `/custom-metal-divider-screens/` | 200/200 | 12703/15246 |
| family | `/pages/laser-cut-panels` | `/laser-cut-panels/` | 200/200 | 12365/15378 |
| family | `/pages/perforated-panels` | `/perforated-panels/` | 200/200 | 11050/13490 |
| family | `/pages/expanded-metal-panels` | `/expanded-metal-panels/` | 200/200 | 17219/20385 |
| pdp | `/products/apiary-architectural-metal-screen-pattern` | `/pattern/apiary-architectural-metal-screen-pattern/` | 200/200 | 5377/6246 |
| pdp | `/products/zelda-architectural-metal-screen-pattern` | `/pattern/zelda-architectural-metal-screen-pattern/` | 200/200 | 5377/6246 |
| pdp | `/products/tafoni-architectural-metal-screen-pattern` | `/pattern/tafoni-architectural-metal-screen-pattern/` | 200/200 | 5908/6246 |
| about | `/pages/about-us` | `/about-us/` | 200/200 | 7684/9577 |
| faqs | `/pages/faqs` | `/faqs/` | 200/200 | 4000/5899 |
| inquiry | `/pages/contact` | `/inquiry/` | 200/200 | 4446/2848 |
| thank-you | `/pages/custom-metal-divider-screens-thank-you` | `/inquiry/thank-you/` | 200/200 | 900/2202 |
| gallery | `/pages/project-gallery` | `/project-gallery/` | 200/200 | 3718/6582 |
| manufacturing | `/pages/manufacturing-process` | `/manufacturing-process/` | 200/200 | 13731/18622 |
| privacy | `/policies/privacy-policy` | `/privacy/` | 200/200 | 5885/4135 |
| blog-index | `/blogs/knowledge` | `/blog/` | 200/200 | 5618/2162 |
| blog-single | `/blogs/knowledge/test-blog-1` | `/blog/test-blog-1/` | **200/404** | 2776/1767 |
| blog-category | `/blogs/knowledge/tagged/news` | `/category/design-guide/` | 404/404 | 1202/1767 |
| search | `/search?q=divider` | `/?s=divider` | 200/200 | 8399/1873 |

> 旧站博客实际句柄是 `knowledge`(sitemap 里另有 design-guide / technical),`/blogs/news` 是 404;`blog-category` 两侧都不存在,保留为"无该面"的记录,不计差异。

---

## 3. S1 结构性差异(15 条)

| ID | 页型 | 元素 | 旧值 | 新值 | 证据 |
|---|---|---|---|---|---|
| VC-003 | 全局 | nav 行盒 / 报价 CTA | nav 自适应;CTA 142×40 @x1258(右边 1400,40px gutter) | nav scrollW 1079 / clientW 1073(+6px);CTA 132×64 @x1308,右边 **1440**(贴边裁切) | `home-{old,new}-1440x900-fold.png`、`chrome-probe.json#home/1440x900` |
| VC-008 | 全局 | 浮动 WhatsApp | 70/70 页都有固定 195×60 @ (1225,820) / 移动 (175,764) | **0/70 页有 WhatsApp 浮动件**;仅 fam-custom 另有两处 fixed 元素(粘性子栏 + 菜单面板) | `home-*-fold.png`、`facts.json#.../chrome.fixed` |
| VC-037 | 9 个系列归档 | 归档 H1 / 面包屑 / 侧栏选中 | 各自集合名(Abstract / Asian / Geometric / …) | **全部 `Organic Patterns`**,而列表数量仍属所请求集合 | `arc-*-new-*-fold.png`、curl 九个 URL 一致 |
| VC-038 | 归档 | 集合成员数 | Geometric 15;Gradient 0(空集,计划退役) | Geometric 12;Gradient 3 | `arc-geometric-*`、`arc-gradient-*` |
| VC-041 | hub | 系列卡片图片 | 8 张卡片均带项目照片(页面 17 张图) | **9 张卡片 0 图片**(页面仅 2 张图:页头/页脚 logo),首格空槽 | `hub-new-1440x900-full.png` |
| VC-044 | home | hero 图片槽 | `hotel-lobby-decorative-stainless-steel-screen-divider.webp`(592×591) | `hero-hotel-lobby-gold-1024w.webp`(1440×1440,暗调竖鳍) | `home-*-fold.png`(**待定**,见 §7) |
| VC-045 | PDP×3 | hero 背景槽 | 场景照(`paralocal__apiary-…-desktop_2x.jpg`,1353×820) | 图案本体(`apiary-…-01-400w.webp`,400×800)拉满全幅 | `pdp-*-fold.png` |
| VC-046 | PDP×3 | 右侧 plate | 黑白图案 plate(`pattern-media-*.png`,400×800) | **红色**图案 plate(458×611) | `pdp-apiary/zelda-*-fold.png` |
| VC-050 | blog-index | 模板输出 | 正常博客索引(Knowledge hero、分类 chips、文章卡) | 模板源码转义成正文(`<d="md-–dx"="ju-h">…`、`{Fd u}` 占位符、H1 "Kwd f yu x j.") | `blog-index-new-{1440x900,390x844}-fold.png` |
| VC-051 | search | 模板输出 | Search Hub:111 条结果、hero、搜索框、结果列表 | 同样的转义模板源码,无结果列表 | `search-new-{1440x900,390x844}-fold.png` |
| VC-052 | blog-single | 文章面 | 200,完整文章模板(面包屑/分类/标题/作者/分享/正文) | **404 "Page not found"** | `facts.json#blog-single/.../status` |
| VC-054 | blog-index | 文章存量 | 5 篇测试文章 | **0 篇**(post-sitemap 只有 `/blog/`) | 两侧 sitemap + `facts.json` |
| VC-055 | inquiry | 页面区块 | 4 块:Tell us your project scope / Route your inquiry / From Inquiry to Quote Route — 5 Steps / Questions before you submit | **1 块**:Request your quote + 原生表单;docH 4446→2848 | `inquiry-*-fold.png`(**待定**,见 §7) |
| VC-060 | family (outdoor) | 390 横向溢出 | scrollWidth 390 | **740(+350px)** | `fam-outdoor-new-390x844-*.png` |
| VC-061 | manufacturing | 390 横向溢出 | scrollWidth 390 | **760(+370px)** | `mfg-new-390x844-*.png` |

---

## 4. S2 明显视觉差异(36 条)

### 4.1 全局类型与 chrome

| ID | 范围 | 元素 | 旧 | 新 |
|---|---|---|---|---|
| VC-001 | 全站 | body 字体 | Inter 16px(70/70 快照) | **Times 16px(70/70)** |
| VC-002 | 1440 | 一级导航标签 | 15px Inter、全大写、单行 | **24px Times**、标题式大小写、`Manufacturing Process` 换两行;下拉标签 22.4px Times |
| VC-004 | 全站 | header 搜索控件 | 可见放大镜,40×40 @x1204(图标 20×20 @x1214) | DOM 存在(40×40 @x1254)但**不渲染图形**——白底上不可见 |
| VC-009 | 全站 | 页脚 | 629px 高、背景 `rgb(29,29,29)`、14 链接(移动 1226px) | **1341px 高**、背景透明、15 链接(移动 1377px);同一组三列标题被拉成纵向堆叠 |
| VC-011 | 9+ 页型 | 标题纵向节奏 | h1 mt/mb 0,h2 mt/mb 0(组件规则) | UA 默认:h1 mt/mb 48.2/37.5/45.6px,h2 mt 39.8/49.8px;移动 h1 24.1px、h2 26.6px |

### 4.2 面包屑(逐页型,9 条)

| ID | 页型 | 旧 | 新 | 备注 |
|---|---|---|---|---|
| VC-027 | shop | 124px 白底,13px 黑链接、无下划线 | 150px 透明底,16px `#9e9eff` 下划线链接 | 移动:98→125px |
| VC-028 | archive | 124px 白底 | 150px 透明底 | 移动:98→125px |
| VC-029 | family | 60px 白底 | 126px 透明底 | 移动:50→116px |
| VC-030 | pdp | 54px 白底 | 80px 透明底 | 移动:23→73px |
| VC-031 | about | 60px 白底 | 90px 透明底 | 移动:50→80px |
| VC-032 | faqs | 60px 白底 | 126px 透明底 | 移动:50→116px |
| VC-033 | gallery | 120px 白底 | 150px 透明底 | 移动:95→125px |
| VC-034 | manufacturing | 60px 白底 | 90px 透明底 | 移动:50→80px |
| VC-035 | family (perforated) | 120px 白底 | 186px 透明底 | 移动:95→161px |

### 4.3 标题字号/字体(逐页型)

| ID | 页型 | 元素 | 旧 → 新 |
|---|---|---|---|
| VC-012 | home | h1 | 56px → **72px**(mt 0 → 48.2) |
| VC-013 | faqs | h1 | 56px(mt 15)→ **72px**(mt 24) |
| VC-014 | manufacturing | h1 | 56px → **72px**(mt 48.2) |
| VC-015 | family (chinese) | h1 | 56px → **72px**(mt 48.2) |
| VC-016 | family (expanded) | h1 | 56px → **72px**(mt 48.2) |
| VC-020 | 5 个产品族页 | h2 | 48px(mt 0)→ **60px**(mt 49.8) |
| VC-021 | home | h2 | 42px(mt 0)→ **48px**(mt 39.8) |
| VC-022 | gallery | h2 | 42px → **48px**(mt 39.8);h1 字体 Playfair → **Times** |
| VC-023 | manufacturing | h2 | 42px → **48px**(mt 39.8) |
| VC-024 | faqs | h2 | 42px(mt 10)→ **48px**(mt 16) |
| VC-025 | inquiry | h2 | 48px → **20px** |
| VC-026 | search | h2 | 28px → **24px Times** |
| VC-063 | gallery | h1 字体 | 56px Playfair Display → **56px Times** |
| VC-064 | family (custom) | h1 | 56px Playfair(mb 24)→ **72px ui-serif**(mb 24);该页走独立模板(两侧都无面包屑,均有粘性条) |

### 4.4 版面与内容槽位

| ID | 页型 | 元素 | 旧 → 新 |
|---|---|---|---|
| VC-039 | shop / archive | 分页页容量 | 146 条 / 7 页(≈21/页)→ **146 条 / 10 页(15/页)**;Abstract 90 条 4 页 → 6 页 |
| VC-042 | hub | 卡片文案 | 标题 + 一行说明 → 标题 + **EXPLORE** |
| VC-043 | hub | 卡片集合与顺序 | Perforated, Geometric, Abstract / Organic, Landscape, Classic Motif / Structured Lattice, Interlaced Lattice | Abstract, Asian Inspired, Geometric, Gradient / Landscape, Moroccan & Arabic, Organic / Perforated, Vintage & Retro(**待定**:IA) |
| VC-047 | pdp | hero 副标题 | "Built for light behavior, privacy, and surface impact in Architectural screening." | **缺失** |
| VC-049 | home | hero 报价卡位置 | 桌面 1350×134 @y783(在 900 fold 内);移动 365×360 @y436 | 桌面 1360×152 @**y901**(落到 fold 外);移动 350×477 @y548 |
| VC-040 | archive | 待退役集合 | Gradient 空集,按 url-map 应 301 → `/shop/` | **200 且含 3 个图案**,不跳转 |

### 4.5 已计入 S2 的批准项(详见 §6)

VC-056(inquiry 表单引擎)、VC-059(页面内联询盘表单移除)。

---

## 5. S3 细节差异(13 条)

| ID | 页型 | 元素 | 旧 → 新 |
|---|---|---|---|
| VC-005 | 全局 | logo 左侧留白 | x=40 桌面 / x=20 移动 → **x=0 / x=0**(贴边) |
| VC-006 | 全局 | header 高度(390) | 73 → 81px |
| VC-017 | inquiry | h1 | 56 → 44px |
| VC-036 | 8 页型 | 面包屑文案 | `Home › Products` → `Home › – Products`(多一个短横,部分页型尾部多一个 `›`) |
| VC-048 | pdp | 相邻图案条 | Zelda / Winter Branches / Wild Spinach → Constella / Dan di Lion / Cellular Flow(**待定**,数据排序) |
| VC-053 | blog-category | 分类归档 | 两侧都 404(旧站无 tag 面,新站无分类) | 说明:该页型两侧皆无,非回归 |
| VC-062 | 全局 | 页面总高 | home 19931→22691(+14%)、family 9523→11724(+23%)、mfg 13731→18622(+36%)、gallery 3718→6582(+77%)、shop 13224→11495(−13%) | 汇总症状,逐项拆在上表 |
| VC-018 / VC-019 / VC-057 / VC-058 / VC-010 / VC-007 | 见 §6–§7 | thankyou / privacy / 邮件 / CTA 触达 | — | 分类为批准或待定 |

> 注:表中"13 条"含被归到 §6/§7 的 6 条 S3(VC-007/010/018/019/057/058);其余为回归项。

---

## 6. 已批准升级(6 条,不得用来解释其他回归)

| ID | 页型 | 内容 | 依据 |
|---|---|---|---|
| VC-007 | 全局 | 报价 CTA 触达高度 34/40px → **60/64px**(≥44px) | 地图 #60:44px 触达为批准项 |
| VC-010 | 全局 | 页脚邮箱 projects@metalpartition.com → **info@metal-divider.com** | 2026-10-24 裁定:对客 mailto 用 info@ |
| VC-019 | privacy | Shopify 政策页 → 重建的隐私页(H1 16→44px) | AGENTS §2:八张 Consentmo 页不迁移,另做一张正经隐私政策 |
| VC-056 | inquiry | 第三方 api.form-data.com 表单 → **原生询盘表单**(8 字段 → 5 字段) | DEBT-6 已批准(单一原生表单) |
| VC-058 | privacy | 内容 2385 词 → 796 词、docH 5885→4135 | 同上,内容重写授权内 |
| VC-059 | 5 页型 | 页面内联询盘表单移除(旧 form-data 表单 → 询盘 CTA partial) | DEBT-6 单一来源 partial;视觉充分性归 #65 |

---

## 7. 待定(8 条,需 #65 裁定)

| ID | 页型 | 待定问题 |
|---|---|---|
| VC-044 | home | hero 换成 `hero-hotel-lobby-gold` 是否为批准的终稿素材,还是 #63 的槽位错配 |
| VC-043 | hub | 卡片集合/顺序从旧 IA(含 Classic Motif/Structured Lattice)换成集合词表 |
| VC-048 | pdp | 相邻图案条内容变化(数据驱动排序) |
| VC-055 | inquiry | 原生表单之外的 3 个说明区块被删,是否符合内容边界 |
| VC-040 | archive | 待退役集合 `pattern-gradient` 未按 url-map 301 → `/shop/` |
| VC-018 | thankyou | 感谢页文案与结构重写是否算批准项 |
| VC-057 | thankyou | 新增 "While you wait / Need something now?" 两节 |
| VC-053 | blog-category | 两侧皆 404——确认该页型不建,而非遗漏 |

---

## 8. 交给下一张票的结论

1. **修在全局层**:body 字体与 heading reset(VC-001/011)一次修好可覆盖 9+ 页型;不要逐页打补丁。
2. **组件规则失配是第二类系统性问题**:导航标签(VC-002/003)、面包屑(VC-027–035)、页脚(VC-009)、搜索图标(VC-004)、logo gutter(VC-005)都表现成"组件 CSS 没赢/没生效",与 #62 的根因审计直接对口。
3. **S1 里有两组必须单独立项**:归档标题/成员(VC-037/038/040)属数据+模板绑定;博客与搜索(VC-050/051/052/054)属模板渲染损坏,不是样式问题。
4. **图片槽位只做到"错"的证据,没做"应该用哪张"**:首页 hero、PDP hero-bg/plate 交 #63。
5. **页型豁免**:shop / 系列归档 / hub / PDP 的字号与间距基本与旧站一致,验收时可作为"未受影响对照面";被波及页型的差异全部有逐条数值。

### 台账使用建议

- 每条 delta 带 `evidence.oldShot/newShot`(路径 + sha256)与 `evidence.facts`(facts.json 内定位);修复后重跑 `capture.mjs` 即可读回同一字段做前后对照。
- 像素门掩膜只允许引用本台账中 `classification=approved-upgrade` 的行(与 spec 08 §5.2"没有台账行就没有掩膜"一致);`pending` 行在 #65 裁定前不得进掩膜。

---

## 附录 A · 证据文件清单

| 文件 | 内容 |
|---|---|
| `docs/research/2026-10-25-visual-census.json` | 64 条 delta + 35 页覆盖 + protocol + summary |
| `docs/research/2026-10-25-visual-census-manifest.json` | 268 张页面截图 + 2 张 header 裁剪的路径 / 字节数 / sha256 |
| `tools/visual-census/capture.mjs` | 采集脚本(可续跑,协议内嵌) |
| `tools/visual-census/chrome-probe.mjs` | chrome 专项探针 |
| `/tmp/visual-census/facts.json` | 140 份 computed-style/DOM 事实(本机,未入库) |
| `/tmp/visual-census/chrome-probe.json` | 36 份 chrome 探针结果(本机,未入库) |
| `/tmp/visual-census/shots/` | 268 张 full/fold 截图(本机,未入库) |

## 附录 B · 判定口径

- **S1 结构**:缺区块/多区块/顺序错、图片槽位错、导航不可用或裁切、页面面 404/损坏、横向溢出。
- **S2 明显视觉**:字体/字号/行高、间距节奏、颜色、面包屑、浮动 CTA、页脚、可见控件的可见性。
- **S3 细节**:小范围数值偏移、文案标点、可忽略的布局差。
- **regression** = 未批准差异;**approved-upgrade** = 有裁定/台账依据的升级;**pending** = 需 #65 裁定。
