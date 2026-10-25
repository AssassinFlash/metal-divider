# IMP-7 收编/升级台账(票 #32)

规格基准:docs/specs/19-page-templates.md(分支 research/spec-page-templates)+ docs/specs/08-content-boundary.md 的 DEBT/CHECK 台账(分支 research/spec-content-boundary)。本文件记录票 #32 交付时每项债的处置;数值证据来自转换管线 `.scratch/32/debt-ledger.json`(管线在 18 张管线面 + 2 个 chrome partial 上实测)。

## 收编段(第一段提交)

管线实测(`.scratch/32/debt-ledger.json`,代表品 akira 的 PDP;R1 博客四面、R2 询盘两面、R3 privacy 为新写,不经管线、无旧站债):

| 面 | sections | 剥除脚本 | 内联样式铸类 | style 块归卷 |
|---|---|---|---|---|
| about | 8 | 0 | 2 | 3 |
| custom-screens | 13 | 12 | 79 | 13 |
| family-chinese-style | 11 | 3 | 32 | 6 |
| family-expanded-metal | 11 | 6 | 194 | 7 |
| family-glass-hybrid | 11 | 3 | 53 | 6 |
| family-laser-cut-geometric | 11 | 3 | 77 | 5 |
| family-laser-cut-panels | 11 | 7 | 47 | 8 |
| family-outdoor-privacy | 11 | 3 | 42 | 6 |
| family-perforated-panels | 10 | 6 | 32 | 5 |
| family-stainless-steel | 10 | 4 | 36 | 3 |
| faqs | 2 | 0 | 3 | 1 |
| home | 10 | 4 | 178 | 4 |
| hub | 2 | 0 | 0 | 2 |
| manufacturing | 11 | 3 | 80 | 4 |
| pdp(代表 akira;8 张代表品 tag 序列 100% 同构) | 2 | 1 | 1 | 1 |
| project-gallery | 3 | 2 | 4 | 1 |
| series-archive(代表 pattern-organic) | 6 | 4 | 5 | 5 |
| shop | 6 | 4 | 5 | 5 |
| **合计(18 面)** | **149** | **65** | **870** | **85** |

(chrome partial 的 5 个 section 变换未入此账——header/search/mega-menu×2/footer 走同一管线,样式入 chrome.css。PDP 的低计数是槽位化替换把逐品值换成模板 tag 的结果,不是旧站债少:PDP 全站债实测见 #8 F9。)

## DEBT-1…8 逐项状态

| # | 债(#8 §5.3) | #32 处置 | CHECK 判定状态 |
|---|---|---|---|
| DEBT-1 | 内联样式属性 | **模板层已清零**:870 处 `style="…"` 全部铸为 `md-t<hash>` 类、规则入卷(同声明序列同哈希,重跑同名) | CHECK-1 绿(#47 渲染层复核):#32 的静态断言与实况不符——chrome partial(header/footer 52 处)与 15 张面的面包屑/背景图共 **113 处 `style=` 未铸类**,#47 全部归卷(声明入卷、选择器挂既有类名、9 个新 `md-` 类),并加全树守卫测试(TemplatesTest)防回归;curl dev 实例各面 0 残留。Alpine `:style` 绑定(6 处,marquee)保留,属 T6 升级项 |
| DEBT-2 | 每节一个 `<style>` 块 | **85 块全部归卷**(`:root` 变量块进 chrome.css,其余进所属卷) | CHECK-2 模板层已清零;CHECK-3 复核发现阻断(#47):归卷产物 **36 卷中 24 卷花括号不平衡**(提取丢闭合括号,custom-screens +186、gallery +101、series-archive/shop +81、chrome +53),WindPress 编译器首错即 fatal——dev 实例浏览器实测 `windpress-cached-inline-css` 为空,整站卷样式未生效。修复需从旧站原始证据重跑提取(规格允许重采集)+ 像素门,独立立项。**#49 已修**:根因是归卷 `add_rules` 的**逐行去重**把多行规则里独立的 `}` 行与重复声明行当"重复规则"丢弃(固定证据复现验证:未改管线重跑,25/28 卷与仓库逐字节一致);修复为整规则单元去重后,从冻结站重采集 18 页全树再生,恢复 3,741 行(1,357 个闭合括号 + 2,384 行被吞的声明/选择器——逐行平衡修补会漏掉后者),custom-screens 另修旧站自带的重复 `@media` 起始(依赖浏览器 EOF 恢复,WindPress 不容忍,管线按浏览器计算语义折叠);卷平衡+语法守卫 `just volume-check` 进 `just check`(#49);dev 实例浏览器实测 5 页型 WindPress 全量编译 0 错、`windpress-cached-inline-css` 426KB、36/36 卷探针全中(#49 当时的卷数;现 37 卷,`just volume-check` 报 `37 volumes balanced`,见 DEBT-4)。像素门仍留 #31 后(分批 4) |
| DEBT-3 | 脚本堆叠 + 第三方 UI 包 | **65 个 `<script>` 全数出模板**(T3 收编期动作);xo-webcomponents 自定义元素保留原 DOM(T6,分批) | CHECK-4 待渲染层复核(LC 运行时白名单);CHECK-5 构建图无 xo 依赖(仓库无该包) |
| DEBT-4 | 多条样式管线并存 | **单 WindPress 管线**:37 卷全部经 `content/windpress/` 编译进同一缓存;零第二条构建 | CHECK-6 仓库无第二条 Tailwind 构建(静态可判)。卷数现为 37 = 规格 19 §7 的 36 卷 + #66 的 `base.css` 基线卷(`WindPressVolumeTest` 断言 `assertCount(37)`;`just volume-check` → `37 volumes balanced`) |
| DEBT-5 | 模板版本堆叠 | 模板/卷命名无 `-vN`(WindPressVolumeTest 断言) | CHECK-7 绿 |
| DEBT-6 | 表单复制粘贴 | **升级段已做**:16 个 api.form-data.com 询盘表单(15 张面)全部替换为 `[lc_get_partial slug="md-inquiry-cta"]` 单源件;`/pages/contact` 链接全部改指 `/inquiry/`(source 参数保留)。当前树:模板与 partial 内 `api.form-data.com` 命中 **0**;引用该单源件的根模板 **16** 个、引用行 **17** 处(比替换时多出的那处是新面接入,不是新表单) | CHECK-8 部分绿:series/shop 的搜索/排序 GET 表单保留(U1 筛选/分页重实现,分批);/inquiry/ 表单本体归 #33 |
| DEBT-7 | 图标重复内联 | 未处置(U4 升级项,分批:91 个内联 SVG 的单源化在像素门调试期做) | 待 U4 |
| DEBT-8 | 渲染体积 | 收编模板 DOM 体量 = 旧站渲染 HTML 去脚本/样式块后的原文;最终度量在 #31 导入后的渲染层 | 观测指标,导入后度量 |

## 分批记录(票面授权的剩余升级项)

1. **U1 筛选/排序/分页重实现**(series-archive、shop 的搜索/排序 GET 表单;分页)——#31 导入**已完成**,本项按实测部分落地(2026-10-26,dev):分页与排序两侧都工作(`/shop/page/2/`、`/collections/pattern-abstract/page/2/` 均 200 且有卡片;`?orderby=date` 两侧 200),搜索仍未闭环——`/shop/?s=apiary` 被 SEOPress 单跳到一个 PDP,根 `/?s=apiary` 200 但 0 张 `pattern-card`。故本项**未完成**,剩余部分只剩搜索路由。验收证据须按页型补齐后才能划掉。
2. **U2 xo-webcomponents 语义重写**(收编模板保留 `<xo-parallax>`/`<xo-animate>`/`<xo-container>` 原自定义元素与 Alpine `@click`/`:style` 绑定;浏览器未知元素降级为块级容器,视觉无损但语义/交互待重做)。
3. **U4 图标 SVG 单源化**(DEBT-7)。
4. **像素门**:全部复刻面的 G3 门(掩膜 M1 = 媒体指纹;媒体终稿未全量,#29)+ PDP 30 品采样。#31 的导入**已完成**、门已可跑并跑过代表作——**当前未通过**(`home@1440`、`collections/pattern-abstract@1440` 均 FAIL,`masks.json` 0 条掩膜);状态与实测数字见 `docs/deploy/visual-parity-gate.md` 的「现状」节。这一项留在本清单,是因为它还没做完,不是因为它还没开始。

## 升级段:业主授权的现代内容与交互升级(2026-10-26 登记)

这一段登记**已获业主授权、已在 dev 实测的、与旧站不等的客户可见差异**。两件事必须分清:登记 ≠ 像素门通过。每一项都会在旧站/新站之间产生肉眼可见差异,所以它们要么按台账报业主(本节),要么在像素门里立掩膜行;本节只做前者。

### 升级-3 · 材料口径收敛到不锈钢(业主 2026-10-24 裁定,实施 2026-10-26)

- **旧站事实(实测,2026-10-26 抓取)**:旧站户外隐私族页与 FAQ 页的可见文案含五材质口径(`304, 316, brass, copper, aluminum — single-source`、`Powder-coated aluminum, AAMA 2605`、`Marine aluminum + multi-layer topcoat`),FAQ 页另有 `Anodized (aluminum only)` 与 `USD/m²` 价目表(该页实测 `USD/m` 命中 2 次、`aluminum` 命中 11 次)。旧站 PDP 的零价写在 Shopify 内嵌 `productVariants` JSON(`price: {amount: 0.0, currencyCode: "USD"}`),不是 JSON-LD——该页只有 WebPage/BreadcrumbList/Organization 三块。
- **新站口径**:客户可见面只出现 Stainless Steel(304/316)。规格条 Material 格的值来自 `plugins/md-site/includes/Presentation/Shortcodes.php` 的 `MATERIAL_WORDING = 'Stainless Steel (304 / 316)'`(SEO 面同口径在 `Seo\SchemaFilters.php:29` 与 `Seo\Config.php:93`),模板不各自写材质词;dev `/pattern/apiary-architectural-metal-screen-pattern/` 实测该格渲染为 `Stainless Steel (304 / 316)`。旧站五材质清单属裁定认定的错误数据,迁移时丢弃。
- **实施面(工作树,14 个 LiveCanvas 文件,按受影响的改动行数)**:`family-expanded-metal`(16)、`family-perforated-panels`(11)、`family-outdoor-privacy`(10)、`faqs`(10)、`family-chinese-style`(7)、`family-laser-cut-geometric`(6)、`family-glass-hybrid`(3)、`manufacturing`(3)、`project-gallery`(3)、`family-stainless-steel`(1)、`family-laser-cut-panels`(1)、`custom-screens`(1)、`series-archive`(1)、`shop`(1)。典型替换:`304, 316, brass, copper, aluminum — single-source` → `Stainless steel 304 and 316 — single-source`;`Powder-coated aluminum, AAMA 2605` → `Powder-coated 304, AAMA 2605`;`Marine aluminum + multi-layer topcoat` → `Marine-grade 316L + multi-layer topcoat`;`SS 304 / Aluminum` → `SS 304 / 316`(manufacturing 三处技法条);PVD 表 `304 SS or aluminum` → `304 SS`。旧站五材质原句在树上只剩 3 个文件曾有此行(`family-chinese-style`、`family-outdoor-privacy`、`family-stainless-steel`)。
- **树上仍有的词与边界**:`brass` 仍出现在 7 个模板文件,但均为迁移媒体的文件名/alt(`our-brass-screens-…webp`、`our-paris-lounge-brass-screen-…`);`Corten` 同理出现在 2 个文件的图片路径(`yeppoon-corten-facade-screen-…`)。**这些是媒体资源的既有名字,不是材质主张**,改文件名会破坏媒体管线与已入库的 `_md_variants` 映射,故不动。可见文案层面 dev 实测为 0:`/faqs/`、`/laser-cut-geometric-metal-screens/`、`/outdoor-privacy-landscape-screens/` 三页 `aluminum`/`aluminium`/`brass`/`copper`/`corten` 全 0。
- **dev 实测排除**:`/faqs/` 全文对 `aluminum`/`aluminium`/`brass`/`copper`/`Corten` 命中 **0**;FAQPage JSON-LD 仍是 **40** 问(改文案未动 schema 抽取)。建筑铝材在防腐场景被 304/316 顶替,属于**内容改写**,不是视觉细节;具体字句的最终审校仍走客户文案的对抗评审流程。
- **部署状态(2026-10-26 实测,必须写清)**:本节与升级-4 的改动**只在工作树与 dev 实例**,生产**尚未生效**。原因已查明,不是"没部署文件":生产客户可见文案由 `wp md-site present` 写进 LiveCanvas 的 `lc_dynamic_template` 行,页面按行渲染;生产那行 FAQ 模板(ID 31)的 `post_modified` 是 `2026-10-25 13:02:42`,其 `post_content` 仍含 `USD/m` ×2、无"quoted per project"新句。生产插件目录里的 HTML 文件是新的(部署过),但**没有人对生产跑 `present`**,所以线上仍是旧文案——这正是"模板/配置不得成为第二数据源、Woo/后台为唯一维护面"的同一类陷阱,写在这里以免下次误判。
- **实测分布**:生产 `/faqs/` 旧价目仍在(`USD/m²` ×2、`Price Range` ×2、`Anodized (aluminum only)` ×1、`aluminum` ×4),而生产 PDP 已是新口径(Material 格 `Stainless Steel (304 / 316)`、可见 `aluminum/brass/copper/corten` 全 0、可见价格记号 0、Product JSON-LD 无 `offers`)。两者不矛盾:不同页面最后一次 `present` 的时间不同。凡"新站口径"一律指工作树当前状态,不指线上。
- **不动的部分**:WooCommerce 的材质属性仍是商品数据源;本节只登记模板字句,不新增任何第二数据源(业主 2026-10-26 Woo 唯一资料源裁定)。

### 升级-4 · 价格展示改为“per project”,撤掉价格区间表(业主授权,实施 2026-10-26)

- **旧站事实(实测,2026-10-26 按页抓取,逐条注明出处)**:`https://metalpartition.com/pages/outdoor-privacy-landscape-screens` 含数量折扣表(`Under 50 sqm List price / 50–200 sqm 8–12% off list / 200–500 sqm 15–20% off list / 500+ sqm 20–25% off list`)、`custom Pantone match adds $30–$50/sqm`、`316L upgrade — coastal or chlorine-pool sites add 20–28% over 304`;`/pages/faqs` 含 `Price Range` 表 ×2 与 `$80`/`$150`/`$250` 区间;`/pages/chinese-style-artistic-screens` 含 `Price Range` 表与同类折扣行;`/pages/perforated-panels` 含 `USD/m²` ×2。**旧站 PDP 本身没有价格区间**(实测该页 `$80`/`% off`/`Pantone`/`Price Range` 全为 0),它的零价只出现在 Shopify 商品 JSON 里:`price: {amount: 0.0, currencyCode: "USD"}`。这些价格文案的来源页是族页与 FAQ 页,不是 PDP。
- **新站口径**:区间与折扣表**撤除**,改为“三个形态族,逐项目报价;报价随形态、图案复杂度、板厚、表面处理变化”;数量折扣从“百分比折扣”改写为“重复板分摊开模/调机成本”(不承诺折扣数字)。穿孔板页同理撤掉 `Price Range (USD/m²)` 列。
- **实施面(工作树,8 个文件有价格记号被删除)**:`faqs`(4 行)、`family-chinese-style`(3)、`family-outdoor-privacy`(3)、`family-glass-hybrid`(2)、`family-laser-cut-geometric`(2)、`family-perforated-panels`(2)、`custom-screens`(1)、`family-expanded-metal`(1)。
- **理由(与裁定一致)**:站点不提供交易、价格为展示态且为零,印死区间既与 JSON-LD 零价矛盾,又在报价前替业主承诺了数字。改为逐项目报价后,数字只出现在实际报价里。
- **dev 实测**:`/faqs/` 页内 `$`/`USD` 价格记号 **0** 处(旧站同页 `USD/m` 2 处)、`sqm` 仅剩交期与运费阈值语境(不是价目);`/custom-metal-divider-screens/`、`/collections/pattern-moroccan-arabic/` 同为 0 处。新站 PDP 的 Product JSON-LD 有 Product 节点且**无 `offers` 键**;旧站 PDP 实测三个 ld+json 块是 WebPage / BreadcrumbList / Organization,**没有 Product 节点**,零价只出现在 Shopify 的 `productVariants` 内嵌 JSON 里。所以"输出 Product 但不写 offers"是新站新增的诚实做法,不是对旧站结构的复刻。
- **部署状态**:同升级-3,仅工作树与 dev;生产 `/faqs/` 仍有 `USD/m²` 价目 ×2 与 `Price Range` 表头 ×2。上线前不得对外称"价格已改为逐项目报价"。
- **未做的部分**:没有为这些内容差异在任何页面立掩膜;它们会进像素门的差异计数。

### 升级-5 · sticky header(业主 2026-10-26 明确批准)

- 台账明细在 `docs/deploy/visual-parity-gate.md` 的「升级-1」(旧站 `scrollY=1200` 时 header top=`-1200` 滚走;本站 top=`0` 固定,桌面 1440/1280/1024/992 与移动 390/320 实测横向溢出 0)。这里是同一项的**交叉登记**,不是第二项升级。
- 本项**不参与**像素门「掩膜外差异 = 0」判定,但也**不是已完成的门**:门的现状见 visual-parity-gate.md 的「现状」节,当前未通过。

### 尚未完成,不得记为绿

- 全 170 项 × 双视口的像素门**一次都没跑过完整一遍**;已跑的两项(`home@1440`、`collections/pattern-abstract@1440`)都是 FAIL。
- 上述内容升级与 sticky 产生的差异**既没有收敛、也没有立掩膜**;`masks.json` 仍为空(0 条)。按规格「没有台账行就没有掩膜」,先有裁定再有掩膜——这个顺序没走完之前,门不能记为绿。

## 接口假设(记录给相邻票)

- **站点图地址**:模板内站点图为 `/wp-content/uploads/<终稿文件名>`(spec 15 §3.1 命名,`--{width}w` 档宽随 srcset);#31 导入侧载时按 `_md_variants` 解析真实路径后如需改写模板地址,一次性 sed(映射表 `.scratch/32/asset-map.json` 是管线与导入器的合同)。
- **custom-screens 专属 landing chrome**(L17):收编为标准 chrome + 页面内容;旧 header-landing/landing-footer 与标准 chrome 的差异是 DEBT 台账行,升级处置归后续批次。
- **`md_series_grid` 的系列卡图**:读 term meta `thumbnail_id`(WooCommerce 后台 Products → Categories 的图字段;卡文案读同一 term 的 description)。迁移站由 `wp md-site seed-series` 一次性 seed:只填空字段,并在 term 上写 `_md_series_seeded` 标记;**标记过的 term 永不再动**——管理员把图或文案清空是明确的意图,重跑不会填回(仅"只填空字段"做不到这点:清空后字段正好为空)。无图渲染无图卡。
- **PDP 文案(Woo 唯一资料源,两段均与旧站逐字一致)**:`[md_pdp_design_intent]` 不读任何仓库文件。hero = 站级句式 + 本品 `best_fit` 词;**lead = 系列的 ACF `style_blurb`**(product_cat term 级文案,同系列共用一句)→ 产品 `summary` → 产品 short description → 系列 description;**supplemental = 本品的 ACF `notable_effect`**(96×"Filtered light and pattern shadow"、50×"Decorative screening"),系列 description 仅在无该字段时兜底。全部为空则不渲染该段(`[lc_if condition="md_pdp_has_design_intent"]` 已落在模板与转换管线里),不编造句子。
- **系列 `style_blurb` 是系列级编辑内容,不是产品事实**:旧站每个系列 146/146 成员共用同一句(实测 9 个系列各 1 个 distinct 值),所以载体是 term 而非 product。gradient 系列沿用 geometric 的句子(旧站实测 `/products/la-pente-gradient-…` 渲染的正是 geometric 句),因为该系列是从 geometric 拆出来的、文案未随拆分重写。种子来自 `content/copy/series-style-blurbs.json`(旧站渲染原文),由 `wp md-site seed-series` 一次性写入空字段;运行时只读 Woo。
- **`notable_effect` 从丢弃键改为保留产品数据**:它原在迁移的 `DROPPED_KEYS` 里,导致 146 品中 103 品丢掉第二段。现为 ACF groups B 的第三字段,迁移 plan 逐品写入、reconcile 不再断言其残留。全 146 品两段实测与旧站逐字一致。
- **`wp md-site seed-series` 接入 deploy**:`plan.SEED_SERIES` 排在 `present` 之后、`seo` 之前,干净目标自动补全 9 张卡图与 9 段文案;写入只填空字段并标记 term,故后续 Woo 编辑(含主动清空)永不被回退。
