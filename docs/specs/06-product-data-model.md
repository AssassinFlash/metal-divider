# 06 · 产品数据模型定型

状态:已定稿(Grilling 票 #6,执行会话推荐即定稿,业主保留否决;AGENTS.md §2 2026-10-24)。
事实基础:#10 后台权威导出(`data/shopify-admin-export/products.json`,146 产品 / 2934 条 metafields / 35 key;`collections.json` 9 系列×6 metafield)+ #2 公开体检(`docs/research/2026-10-24-shopify-content-audit.md`,research/content-export 分支)。本文所有数字均为对上述两份导出的实测统计,标注来源;平台能力主张标注一手来源。
下游:#18(迁移器规格)与 #15(媒体终稿管线)以本文为输入;#19(PDP 模板)消费字段组。

## 0. 结论一览

1. **35 个产品 metafield key:17 个保留迁移,18 个丢弃**。保留里 4 个进 Woo 原生位(SKU、product_cat、SEOPress 标题/描述),8 个进 ACF 产品字段组(2 组),5 个进 ACF 内部溯源组;丢弃的是模板生成文案、派生值、常量、可推导值、n=1 试点残留。
2. **taxonomy 只用一个:Woo 原生 `product_cat` 承载 8 个图案系列**(gradient 空系列不建 term)。材质=全站常量,应用=系列级 term meta,镂空率=ACF 数值字段 + 既有 `visual_density` 即档位;不建自定义分类法、不建全局产品属性。
3. **图片全走 Woo 原生:featured = 旧 media[0],gallery = 旧 media[1:] 原序平迁**;场景/技术图的角色信息保存在 alt 原文与 attachment postmeta,ACF 不建图组。
4. **16 个 "Project set" 镂空率不补数**:ACF 数值留空 + `visual_density='custom'`,PDP 规格条照旧渲染 "Project set"。旧管线自己的推断置信度只有 0.05–0.20(已填的 35 个是 0.17–1.0),按风格均值估是假精确,不造数。
5. **Woo 产品设置:价格留空(不写 $0)**——空价格使 `is_purchasable()` 原生返回 false,全站零代码禁购(源码证据见 §6);虚拟产品、可见、有货、SKU=pattern_code。
6. **本地测试数据 = 全量 146 品 + 全量产品图**,不抽样。

---

## 1. 事实基础(定稿依据)

### 1.1 35 个产品 metafield 的实测画像

对 146 个产品的 2934 条 metafield 逐 key 统计(来源:#10 `products.json`):

| 画像 | key | 实测 |
|---|---|---|
| 逐品真数据 | `pattern_code` | 146/146,146 个唯一值(无重复) |
| | `pattern_style` | 146/146,仅 8 个值,与 8 个非空系列计数逐一吻合(abstract 90 / organic 16 / geometric 15 / moroccan-arabic 8 / landscape 8 / asian 4 / vintage-retro 4 / perforated 1);与 tag `pattern-collection` 交叉核对 0 失配 |
| | `openness_percent` | 130/146 为数值(6.0–85.48,中位 35.0,68 个不同值);16 缺 |
| | `visual_density` | 146/146,4 值:open 46 / balanced 54 / dense 30 / unknown 16(unknown 恰=缺镂空率的 16 个);与镂空率强相关:open 均值 49.5%、balanced 30.9%、dense 15.6% |
| | `repeat_behavior` | 146/146,2 值:repeating 134 / flowing 12 |
| | `motif_tags` | 146/146,21 种组合,拆开后 15 个词(abstract 96 / organic 16 / geometric 15 / moroccan 6 / arabic 6 / landscape 5 / leaf 5 / vine 4 / floral 4 / asian 3 / vintage-retro 3 / garden 2 / bamboo 1 / lattice 1 / perforated 1) |
| | `cad_status` | 146/146,2 值:gated 68 / missing 78 |
| | `best_for` | 146/146,仅 2 值,与来源 1:1(parasoleil 96→Architectural screening,dsd 50→Decorative screening) |
| 逐品真文案 | `pattern_summary` / `pattern_inspiration` | 各 43/146(42+1 个来自 dsd);与 bodyHtml 部分重叠(DSD 产品的 bodyHtml 即 summary 全文) |
| 溯源 | `source_site` / `source_slug` / `source_manifest` | 2 值 / 146 唯一 / 51 种 JSON |
| | `inferred_from_pattern` / `inference_confidence` | 50/146 与 51/146;镂空率由推断填充的 35 个置信度 0.17–1.0(均值 0.328),**缺镂空率的 16 个 0.05–0.204**(旧管线自己弃填) |
| 模板/派生 | `notable_effect` | 2 值,与 best_for 同构(来源 1:1) |
| | `fabrication_note` / `compliance_notes` | 3 值 / 2 值,由 cad_status+来源组合决定 |
| | `seo_primary_phrase` / `seo_supporting_phrases` | 146 唯一但纯公式:`{code} architectural metal screen pattern` |
| | `global.title_tag` / `global.description_tag` | 146 唯一,与 `seo.title`/`seo.description` 同内容;体检证实 meta description 中位 160 字符、质量良好 |
| 常量/可推导 | `material_options` | 49/146(dsd 48+1),其中 46 个是同一份 5 材质清单;5 材质与 9 个系列级 `pattern_materials`(9/9 同串)完全一致 |
| | `asset_count` | 5 种值,= 各品图片张数,可从媒体推导 |
| n=1 试点残留 | `biomimicry_score`、`cad_note`、`customization_options`、`finish_options_note`、`mesh_score`、`privacy_score`、`recommended_spaces`、`reference_pdf`、`shade_score`、`small_opening_score`、`structure_score` | 全部只在 apiary 1 个产品上有值,从未铺开 |

体检(§2/§3)已证实:PDP 的 Design intent / Where-this-works / Technical FAQ 区块是模板生成文案(146 个 payload 1–15 种变体),`best_for`/`notable_effect` 由来源派生,**不要把模板输出当逐品数据迁移**。

### 1.2 图片画像(来源:#10 media 节点 + #2 体检 §2/§7)

- 每品 2–16 张,中位 8,共 1063 张,0 品无图。
- alt 文本是结构化角色词汇(1063 张逐张子串实测):accent render 479(其中 standard/blue/ochre/burgundy 各恰好 96)、local installation scene 102、official installation scene 92、installed screen scene 54、cut sheet 96、finish preview 96、pattern render 48、detail reference 14、reference image 10、project installation 5、QC photo 1。
- 96 个 parasoleil 品**全部**有 4 色渲染 + cut sheet + finish preview;50 个 dsd 品全无(0/50)。
- media[0](旧站主图)角色分布:finish preview 94 / pattern render 26 / detail reference 13 / installed scene 6 / reference image 4 / QC photo 1 / 其他 2——**主图本来就不是统一"渲染图"**,原序平迁零编辑裁量。
- #16 已闭票:146 个图案授权无虞,原样平迁;官方安装场景图(official installation scene,parasoleil 摄影图)保留。

### 1.3 系列级(collection)metafield 实测

9 个系列各 6 个 key(#10 `collections.json`):`family`(9 值,显示名如 "Asian Inspired")、`accent_color`(**9/9 同值 #c9a962**)、`pattern_materials`(**9/9 同串**,五材质清单)、`difficulty_level`(3 值分布:Simple/Moderate/Complex)、`typical_applications`(9 串各异的 4 项清单)、`bottom_content`(9 份各异的 FAQ HTML)。gradient 系列 productsCount=0(空系列,体检 §8.5)。体检 §3:这 6 项在旧站前台 **0 渲染**(业主写了没用上)。

### 1.4 产品级其余字段

status 全 ACTIVE;vendor 全 'MetalDivider';productType 全 'Decorative Screen Pattern';templateSuffix 全 'pattern-spec'(体检 §8.10 佐证);variants 全为单变体 Default Title;tags 为 `key:value` 数组(admin 导出已是数组,公开导出才是逗号串——体检 §8.2)。授权已裁定无风险(#16),溯源字段是运营资产不是风险门。

---

## 2. 决策 1:35 个 metafield key 的去留与 ACF 字段组

### 2.1 三条去留判据

1. **逐品有信息量的进字段**(唯一值多、或值有业务含义);
2. **常量不建字段**(146/146 同值或 9/9 同串 → 模板层常量;字段化是假数据建模);
3. **模板生成的文案不迁移**(体检结论:放弃旧模板文案,按新站文案体系重写,#8 的地盘)。

### 2.2 保留 17 个:落位表

**进 Woo 原生位(4 个,不经 ACF——原生优先,AGENTS §5):**

| 旧 key | 新落位 | 说明 |
|---|---|---|
| `custom.pattern_code` | Woo **SKU**(`_sku`) | 146 唯一实测 → 原生唯一性校验免费;PDP 规格条 Pattern code 读 SKU |
| `custom.pattern_style` | **`product_cat` term**(§3) | 与系列 1:1,零失配 |
| `global.title_tag`(≡`seo.title`) | **SEOPress** 标题 meta | 146/146 已写好且质量良好,直接迁入,不重写 |
| `global.description_tag`(≡`seo.description`) | **SEOPress** 描述 meta | 同上(中位 160 字符) |

**进 ACF 产品字段组(8 个,2 组):**

组 A `图案规格 pattern_spec`(位置:产品编辑侧栏,前台 PDP 规格条的数据源):

| ACF 字段 | 类型 | 旧 key | 取值/备注 |
|---|---|---|---|
| `open_area` | 数字(0–100,步进 0.01,单位 %) | `openness_percent` | 130/146;16 个留空(§5) |
| `visual_density` | 单选:open / balanced / dense / custom | `visual_density` | 旧值 unknown(16 个)→ custom |
| `repeat_behavior` | 单选:repeating / flowing | `repeat_behavior` | 146/146 |
| `motifs` | 复选框(选项=§1.1 实测 15 词) | `motif_tags` | 146/146 |
| `best_fit` | 单选:architectural-screening / decorative-screening | `best_for` | 值虽与来源 1:1,但它是规格条第 5 项的既有展示值,保留为字段使其可独立改写 |
| `cad_status` | 单选:gated / missing | `cad_status` | 驱动 PDP 资源块(CAD/切割表按需提供) |

组 B `图案文案 pattern_copy`:

| ACF 字段 | 类型 | 旧 key | 取值/备注 |
|---|---|---|---|
| `summary` | 文本域 | `pattern_summary` | 43/146,其余空,#8 内容重写补 |
| `inspiration` | 文本域 | `pattern_inspiration` | 43/146,同上 |

**进 ACF 内部溯源组(5 个,组 C `来源与许可 pattern_provenance`,仅后台编辑用,前台模板永不读取):**

| ACF 字段 | 类型 | 旧 key |
|---|---|---|
| `source_site` | 单选:parasoleil / dsd | `source_site` |
| `source_slug` | 文本 | `source_slug` |
| `source_manifest` | 文本域(JSON 原文) | `source_manifest` |
| `openness_inferred_from` | 文本 | `inferred_from_pattern` |
| `openness_confidence` | 数字(0–1) | `inference_confidence` |

保留理由:#16 虽裁定授权无虞,但 35 个镂空率是推断值、96/50 的来源构成、上游 slug 都是持续运营要用的底账(续授权核对、按来源批量处理、将来补真规格时找参照),占 5 个字段成本极低。

### 2.3 丢弃 18 个:逐项理由

| 旧 key | 处置 | 理由 |
|---|---|---|
| `notable_effect` | 丢弃 | 2 值,来源 1:1 派生;它喂的 Design intent 区块是模板文案(#8 重写) |
| `fabrication_note` | 丢弃→模板层 | 3 值由 cad_status+来源决定;PDP 资源块由模板按 `cad_status` + 全站常量渲染 |
| `compliance_notes` | 丢弃→模板层 | 2 值同上 |
| `seo_primary_phrase` | 丢弃→模板层 | 公式串(`{code} architectural metal screen pattern`),需要时模板可零成本重生成;SEOPress 已有真标题描述 |
| `seo_supporting_phrases` | 丢弃→模板层 | 同上 |
| `material_options` | 丢弃→全站常量 | 49/146 且 46 个同值;五材质清单 9 个系列级也同串 → 不是逐品数据。**材质菜单对外口径是 OPEN 项(§8)** |
| `asset_count` | 丢弃→导入时推导 | = 图片张数,`count(gallery)+1`,存字段必漂移 |
| `biomimicry_score` 等 10 个 n=1 字段 | 丢弃 | apiary 试点残留,从未铺开;概念上有价值的(`recommended_spaces`、`customization_options`)由 #8 新文案体系按需承接,不迁死字段 |
| —(`shopify.color-pattern`、`shopify.style` metaobject 定义,仅存在于 shopify-builder `.shopify/metafields.json`,产品上无值) | 不迁移 | Shopify 原生分类定义,零数据 |

**对账:4(原生)+8(组 A/B)+5(组 C)=17 保留;18 丢弃;合计 35。** 丢弃 key 在 WP 侧不建任何对应物(迁移器 #18 按本表对账,丢弃项不得以序列化残值混入 `wp_postmeta`)。

---

## 3. 决策 2:taxonomy 划分

| 维度 | 载体 | 理由 |
|---|---|---|
| **图案系列(9→8)** | **Woo 原生 `product_cat`**(扁平 8 term,无父级) | 每品恰属一个系列(146=Σ成员数,零交叉,实测);系列是主导航面,product_cat 原生带档案页、面包屑、菜单、小工具;term 名用 `family` 显示名(如 "Asian Inspired"),slug 用系列 slug(pattern-asian 等,#4 映射) |
| **gradient 系列** | **不建 term** | 0 产品(实测),空档案页=薄 SEO 页;3 个带 "gradient" 的产品实测归 geometric,维持不动;补内容时再建 |
| **材质** | **无载体:全站常量**(模板层) | 146/146 同值 "304 / 316 stainless" + 材质清单 9/9 同串;若未来真出现逐品分化,届时再上 Woo 原生全局属性(pa_*),字段先不占位 |
| **应用(适用场景)** | **系列级**:ACF term meta(§4 `typical_applications`) | 产品级无数据(仅 n=1 试点);应用叙事本来就挂在系列页 |
| **镂空率档位** | **ACF 字段,非分类法**:`open_area` 数值 + `visual_density` 单选 | 档位已存在(visual_density 与数值强相关:open 49.5%/balanced 30.9%/dense 15.6% 均值);数值是规格真值,档位是展示层; Woo 原生无数字区间筛选项,建分类法只丢精度不换功能 |
| **motif 主题词** | **ACF 复选框**(§2.3),非分类法/非属性 | 15 词、仅 PDP 展示;建 pa_* 全局属性会生成 15 个薄 term 档案页,无检索面需求 |

**为什么不建自定义分类法 / 全局产品属性(穷尽证明,AGENTS §5):** 系列是唯一需要"档案页+导航"的维度 → product_cat 原生胜任;镂空率数值筛选用 WP 查询(`meta_query` 数字比较)即可,LiveCanvas 模板直接读;材质常量、应用系列级、motif 展示级都不构成检索面。自定义分类法与 pa_* 在本站没有任何 product_cat 或原生查询覆盖不了的需求。

---

## 4. 系列级数据:collection metafields → product_cat term meta(ACF 组 D `系列属性 series_meta`)

| ACF 字段(挂 product_cat) | 类型 | 旧 key | 处置理由 |
|---|---|---|---|
| `accent_color` | 颜色选择器 | `accent_color` | 9/9 同值 #c9a962,但逐系列换色是自然设计杠杆,term 级字段成本为零,保留 |
| `difficulty_level` | 单选:simple / moderate / complex | `difficulty_level` | 逐系列真数据(3 值分布) |
| `typical_applications` | 文本域(原串) | `typical_applications` | 9 串各异真数据 |
| `bottom_content` | 可视化编辑器(wysiwyg) | `bottom_content` | 9 份各异 FAQ HTML;旧站 0 渲染(写了没用),迁入留底,**是否展示由 #8/#19 定** |
| `family` | 折叠进 **term 显示名** | `family` | 与 term 名二选一,不重复存 |
| `pattern_materials` | 丢弃(全站常量,同 §2.3 material_options) | `pattern_materials` | 9/9 同串 |

---

## 5. 决策 3:图片字段结构

**全走 Woo 原生,ACF 零图组:**

| 槽位 | 载体 | 迁移规则 |
|---|---|---|
| 主图 | Woo featured(`_thumbnail_id`) | = 旧 `media[0]` **原样**(94 个是 finish preview、26 个渲染图……旧站本就这样,零编辑裁量,像素门同序) |
| 画廊 | Woo 原生 gallery(`_product_image_gallery`,有序) | = 旧 `media[1:]` 原序(每品 1–15 张) |
| 角色信息 | **alt 原文平迁**(local/official installation scene、accent render、cut sheet preview、finish preview 等既有词汇)+ attachment postmeta `_md_role`(导入时按 alt 词表解析)、`_md_source_url`(CDN master_src) | #15 媒体管线按 alt 角色定变体策略;#19 PDP 模板按角色分组渲染(如"安装场景"区块);`_md_source_url` 是媒体对账底账(#15 用 manifest sha256) |

**理由:**
1. **原生优先**:featured + gallery 是 Woo 核心机制,PDP 画廊交互(灯箱、缩略图)免费;ACF 图组要自建渲染,违反 AGENTS §5 第②层先于第③+层。
2. **1063 张图不重复挂载**:ACF 图组会把引用复制进 postmeta 表,同一附件两套索引必漂移。
3. **场景图(official/local)不抽离画廊**:抽离会改变画廊序列,破坏复刻基线(#21 像素门);角色已在 alt/postmeta,模板按需取。
4. **finish preview / cut sheet 留在画廊**:96 个 parasoleil 品的技术图(cut sheet/finish preview 各 96)旧站就在画廊里;是否在 PDP 单独成区块是 #19 模板的事,数据层不动。

## 6. 决策 4:16 个镂空率 "Project set" 的补数规则

**规则:不估、不标 custom 文案、留空。**

1. `open_area` ACF 数值**留空**(NULL);`visual_density` = `custom`。
2. PDP 规格条 Open area 槽渲染 **"Project set"**(旧站现状即此文案,复刻一致;渲染逻辑:字段空 → 输出该词)。
3. 溯源照记:16 个的 `openness_inferred_from` / `openness_confidence`(0.05–0.204)进组 C,业主日后拿到 DSD 原厂规格时一条 wp-cli 即可补真数。
4. **不按图案风格估**:旧管线对这 16 个的最近参照(the-intercontinental 46.0%、night-life 15.0% 等)置信度只有 0.05 级,它自己都没敢填;风格均值(abstract 中位 vs landscape 中位差 >10 个百分点)只会制造假精确。B2B 规格数字是询盘信任基础,宁可空,不可编。
5. 已由推断填充的 35 个(置信度 0.17–1.0)**照旧显示**——它们已在旧站公开两年,回撤反而制造不一致;溯源同样落库。

## 7. 决策 5:Woo 产品设置($0 展示态)

| 项 | 值 | 理由 |
|---|---|---|
| 产品类型 | simple;**virtual=yes**;downloadable=no | 无履约无配送;材质/尺寸是询盘期定制,**不做变体**(变体会暗示可配置 SKU 报价,与唯一链路"询盘"冲突) |
| `regular_price` / `sale_price` | **空字符串(不写 0)** | **空价格 ⇒ `WC_Product::is_purchasable()` 返回 false**(WooCommerce 源码 `plugins/woocommerce/includes/abstracts/abstract-wc-product.php` trunk:1867–1875:`'' !== $this->get_price()` 是合取项)⇒ 全站原生禁购、循环与 PDP 均无加购按钮,**零代码零过滤器**。写 $0 则产品变为"可购买的 $0 商品",反而需要加 `woocommerce_is_purchasable` 过滤器才能禁购——违反原生优先 |
| `catalog_visibility` | visible | 可浏览可搜索是站点存在的意义 |
| `stock_status` | instock;manage_stock=false | 避免 out-of-stock 角标;无库存概念 |
| SKU | = pattern_code | 146 唯一实测 |
| `tax_status` | none | 无价格无税,阻断税务插件交互 |
| 评论/trackback | 关闭 | 无账户站(AGENTS §1) |
| permalink | `/products/<slug>` | #4 已定 custom base;slug 映射用 #4 `data/redirect-map.csv` |
| status | publish | 146 全 ACTIVE |
| vendor / productType / templateSuffix / tags | 不迁移 | 全量常量('MetalDivider'/'Decorative Screen Pattern'/'pattern-spec'),零信息量 |

**"$0 展示态"的口径(AGENTS §1 F1)**:体检 §2 证实旧站 PDP 规格条 5 项**不含价格**、页面不渲染任何价格,`$0` 只是数据字段状态。因此"空价格 + 前端不渲染价格"是忠实等价物。**否决点**:若业主要求字面展示 $0,需改回 0 价 + 禁购过滤器(偏离原生优先),业主否决时再切。

## 8. 决策 6:本地测试数据

**全量 146 品 + 1063 张产品图(经 #15 终稿管线后),不抽样。**

理由:① 导入器(#18)反正必须吃全量才能上生产,抽样=测两遍;② 边缘形态只在全量出现——16 个 Project set、1 品的 perforated 系列、2 图与 16 图的极值产品、96 品技术图子集、85 个 code 大小写差异;③ 585MB 原图已在本地(体检 §7),媒体侧无增量成本;④ #21 像素门基准采集需要全量 PDP。落在 #14 的 `dev` 单实例,导入后即成为后续所有票的共享基线,按 #14 的复用/回收规则管理。

## 9. 迁移映射总表(#18 迁移器的对账基准)

| Shopify(#10 导出) | WordPress/WooCommerce 目标 |
|---|---|
| `title` | `post_title` |
| `handle` | `post_name`(按 #4 redirect-map,必要时改 slug 并记 301) |
| `bodyHtml` | `post_content`(34 个 >400 字符有真内容,其余占位照迁,#8 重写) |
| `pattern_summary` | ACF `summary` + `post_excerpt`(双落:摘要是 PDP 短描述位) |
| `pattern_inspiration` | ACF `inspiration` |
| `pattern_code` | `_sku` |
| `pattern_style` / tag `pattern-collection:*` | `product_cat` term(两者互核,实测 0 失配) |
| `openness_percent` | ACF `open_area`(16 缺→空) |
| `visual_density` | ACF `visual_density`(unknown→custom) |
| `repeat_behavior` / `motif_tags` / `best_for` / `cad_status` | ACF 同名字段 |
| `source_site` / `source_slug` / `source_manifest` / `inferred_from_pattern` / `inference_confidence` | ACF 组 C 对应字段 |
| `seo.title` / `seo.description`(≡ `global.title_tag`/`description_tag`) | SEOPress 标题/描述 postmeta(键名以实装版 10.3 为准,#18 落地时核对) |
| `media[0]` | featured image;alt 原文 → attachment `post_content`(alt 字段) |
| `media[1:]` | gallery(原序);alt 同上 |
| (导入时派生) | attachment postmeta `_md_role`(按 alt 词表解析)、`_md_source_url`(manifest master_src) |
| 9 × collection(6 metafield) | 8 个 `product_cat` term + ACF 组 D(§4);`family`→term 名、`pattern_materials` 丢弃 |
| `variants` / `options` / `vendor` / `productType` / `templateSuffix` / `status` / `asset_count` / §2.3 丢弃 18 key | 不迁移 |

## 10. 验收要点

1. **对账**:导入后 146 产品、8 个 `product_cat` term(gradient 不存在)、每品恰 1 系列、SKU 146 唯一、35 个旧 key 按 §9 表逐 key 对账(17 迁移到位、18 无 WP 残留)。
2. **禁购实证**:任意 PDP 与循环页无加购按钮、无价格渲染(空价格原生行为,非 CSS 遮挡);`is_purchasable()` 抽查返回 false。
3. **规格条**:PDP 规格条 5 项(Pattern code/Style/Open area/Material route/Best fit)与旧站一致;16 个 Open area 显示 "Project set";Material route 由模板常量渲染。
4. **图片**:抽 5 品(含 2 图、8 图、16 图各一)核对 featured=旧 media[0]、画廊序=旧 media 序、alt 逐字平迁。
5. **空值纪律**:16 个 `open_area` 为 NULL(非 0 非空串);`visual_density=custom`;溯源组 16/16 有 inferred_from+confidence。
6. **SEOPress**:146/146 标题描述非空,与旧 meta 逐字一致(抽样 10)。
7. **溯源隔离**:组 C 字段在任何前台页面(含 feed、REST 公开响应)无输出。

## 11. OPEN 项(业主私有事实)

- **材质菜单对外口径**:旧数据两套并存——规格条常量 "304 / 316 stainless"(146/146)vs 材质清单 "Corten, Aluminium, Stainless Steel, Mild Steel, Copper"(9 系列 + 49 品同串)。数据模型已按"全站常量、模板层渲染"定型,不阻塞;**该常量显示哪个口径**(或两处分工:规格条 vs 材质区块)由业主一句话定,落在 #8 文案票执行。
- **16 个镂空率真值**:若业主从 DSD 处拿到原厂规格,按 §6.3 补数;拿不到就维持 "Project set"。
