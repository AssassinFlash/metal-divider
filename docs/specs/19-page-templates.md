# 页面构建策略(票 #19)

日期:2026-10-24。执行会话定稿(业主裁定:grilling 票按执行会话推荐定稿,业主保留否决)。
上游:#8 内容改写边界(`docs/specs/08-content-boundary.md`,逐页型 ledger 为本题对齐基准)。本文回答:17 张活动页的 LiveCanvas 模板怎么生产、复用件怎么拆、模板与数据怎么分工、样式卷怎么组织、每页型怎么验收。**不重开 #8 已钉死的任何裁定**。

## 0. 依据事实

| # | 事实 | 来源 |
|---|---|---|
| F1 | 11 个复刻+编码升级页型(L1–L18)、3 组重设计(R1 博客/R2 询盘功能面/R3 privacy)、退休 11 张旧 URL;像素门 + M1/M2/M3 掩膜 + CHECK-1…8 是验收框架 | #8 §2/§5 |
| F2 | 呈现层顺序:LC 原生 tag → Woo 原生 → WindPress;PHP 只做数据装配零设计;插件零 CSS;禁止第二条 Tailwind 构建 | AGENTS.md §5 |
| F3 | 博客设计复用 de-linker 四个博客 LiveCanvas 模板面(blog_index/blog_single/category_archive/search,含对应 WindPress 卷),按本站 5 分类 IA 适配 | AGENTS.md §2(2026-10-24 深夜补充 2);de-linker `plugins/linker-commerce/content/livecanvas/` |
| F4 | de-linker #175 收编先例:模板 `post_content` 逐字搬成仓库文件 + 五类机械变换;`present` 按 LC 赋值键就地写回 DB 行(不建第二份);`drift()` 读回逐字节核对;收编后像素门 0 差异;页内 `<!-- LC DT ID: n -->` 戳可断言渲染来源 | de-linker `docs/specs/175-blog-legacy-templates.md`、`includes/Presentation/Templates.php` |
| F5 | LC 模板承接机制两类(生产实证):页型赋值键(`is_single_product`/`is_shop_page`/`is_archive_for_post_type_product`/`is_blog_posts_index`/`is_search`/`is_archive_for_tax_category` 等);单页逐页赋值 `lc_use_template_of_slug`(LC `modules/templating.php`,写入页面 post)。另有 partials(header/footer 全局复用件) | de-linker `Templates.php` FILES/PAGES 常量与注释 |
| F6 | 旧站 17 个页型模板的 section 顺序实测(本文 §3 表):产品族页 8 张全部同构(breadcrumb→hero→内容节→mid_cta→…→faq→contact);PDP 全站一个模板 `product.pattern-spec.json`(breadcrumb+pattern_product_cinematic,即旧站本身就是 1 模板×146 实例);custom-screens 用专属布局 `theme.metal-divider-landing`(11 节) | `~/Documents/shopify-builder/theme/templates/*.json` 逐文件读出(order 数组),2026-10-24 |
| F7 | 旧站渲染 HTML 已有全量:#2 体检脚本可复跑,产物在 `/tmp/md-r-work/raw/html/en/`(每页 209–439 KB,内联样式/内嵌 style 块/脚本数实测见 #8 F9) | #2;#8 F9 |
| F8 | 本地栈:LiveCanvas 4.9.5 + WindPress 3.2.92(wp.org 免费)+ 单实例 `dev` + 仓库自研插件符号链接;Polylang 不装 | #14 `docs/specs/14-local-stack.md` §0/§2 |
| F9 | 询盘链已规格化:全站唯一表单在 /inquiry/,其余页面一切表单转 CTA 链接(#8 U1 各行 + CHECK-8);感谢页 /inquiry/thank-you/ | #7(经 #8 F6 摘引) |
| F10 | PDP 文案四区处置已钉:Design intent 按 9 系列句式组重建、Where-works 与 Technical FAQ 收敛为全站单源、Material route = 全局基线+48 份逐品覆盖、镂空率 16 缺口渲染 "On request" | #8 §3(D2) |
| F11 | de-linker WindPress 卷模式:扁平卷文件 ~21 个(tokens/chrome/pdp/journal/blog-*/storefront/products/account/faq…),全部编译进同一缓存产物 | de-linker `plugins/linker-commerce/content/windpress/` 目录实测 |
| F12 | 主题基座票 #17 未定稿;本票按「picostrap5-child 子主题平移」假设写接口,受影响面见 §9 | #1 地图 |

## 1. 决策总表

| # | 决策 | 结论 | OPEN/接口 |
|---|---|---|---|
| D1 | 生产方式 | 11 个复刻+编码升级页型**全部走转换管线**:旧站渲染 HTML → 仓库 LC 动态模板文件(逐字搬 + 七类机械变换),拒绝在 LC 编辑器里手工重建;两段式提交(§4.3)。3 组重设计直接新写(R1 fork de-linker 四博客面适配) | — |
| D2 | LC 承接面清单 | 25 张 LC 动态模板 + 2 个 partials,逐张列赋值机制与文件名(§3);模板与卷的仓库位置 `plugins/md-site/content/{livecanvas,windpress}/`(插件切分归 #17) | IA1/IA3 |
| D3 | 复用件 | **14 件**:站级 partials 2(header/footer)+ 单源内容组件 3(where-works / technical-FAQ / inquiry-CTA 带)+ 组件件 9(breadcrumb/hero/cta-band/faq-accordion/spec-table/stats-band/gallery/pattern-grid/marquee),命名与复用矩阵见 §5 | — |
| D4 | 模板×数据分工 | 静态文案一律进 LC 模板;PDP = **1 张模板 ×146 实例**(`is_single_product` 赋值键),逐品数据经 LC 原生 tag(优先)与 `[md_pdp_*]` 短代码(数据装配,零设计)填充;分工表见 §6 | — |
| D5 | 样式卷组织 | WindPress 扁平卷,**一条规则:一张模板一卷 + 组件件一卷 + tokens/chrome 两卷**,共 36 卷;清单见 §7 | IA2 |
| D6 | 每页型验收 | 四门齐过才闭档:present drift=0 + 页内 DT ID 戳断言 + #8 CHECK-1…8 全绿 + 像素门(data/pixel-baseline/ 基准,掩膜按 #8 ledger);PDP 加采样门(§8) | — |

## 2. D1 生产方式:转换管线,不是 LC 编辑器重建

### 2.1 结论

11 个复刻+编码升级页型,生产方式一律是**转换管线**:以旧站线上渲染 HTML(F7)为唯一结构来源,逐字搬进仓库 LC 模板文件,只做 §4.2 列名的七类机械变换;随后把 #8 ledger 列名的升级项(U1–U4)作为独立提交逐项叠加,每项挂 M3 掩膜台账。3 组重设计(R1/R2/R3)无旧站基准,直接新写,不经过管线。

**否决「在 LC 编辑器里重建」**,理由五条:

1. **像素门可判定性**:11 页型的验收是掩膜外差异=0(#8 §2.1)。渲染 HTML 里的 DOM 结构、类名、section 顺序、文案就是基准的机器可读形态;逐字搬保的是「与基准同源」,手工重建是人对着浏览器抄,每一个抄写偏差都变成验收时的掩膜谈判。de-linker #175 收编后六面像素 0 差异(F4)证明管线做得到;LC 编辑器重建没有任何等效证据。
2. **仓库可评审性**:LC 模板的存储是 DB `post_content`;不经仓库文件 + present 机制(F4),模板就成了 GUI 会话产物——git 不可评审、CI 不可断言(#8 CHECK-1…8 全部无从挂起)、多页型并产时不可回滚。业主管线是 本地→评审站→线上(AGENTS §5),评审站的东西必须先在仓库里。
3. **规模经济**:11 页型 + 146 PDP + 8 系列,全部同构骨架(F6)。变换规则写一次,`generate` 脚本跑全部页型;手工重建的成本随页型数线性涨且每张质量独立漂移。#175 的生成脚本 + 逐文件比对留档(.scratch/)是可直接平移的方法。
4. **两段式可归因**:先「收编提交」(像素对齐旧站,差异仅 M1)再「升级提交」(逐 U 项),每个升级差异都有台账行——这正是 #8「没有台账行就没有掩膜」硬约束想要的可归因性。编辑器里重建把收编与升级混在一次手工操作里,差异无法归因。
5. **旧代码一行不进新站的合规性**:管线天然逐类清点要丢什么(脚本、内联样式、xo-webcomponents 元素);编辑器重建对旧站债务的清除是「大概没带过来」,不构成证据。

LC 编辑器的角色:验收阶段微调的输入面,改动照旧落回仓库文件经 present 发布(F4 的纪律),**编辑器不是生产源**。

### 2.2 逐页型结论表

「代表性输入」列 = 管线取哪份渲染 HTML 起稿;「实例数」= 该模板渲染多少 URL。

| 页型(#8 行) | 模板实例数 | 生产方式 | 代表性输入 | 页型特记 |
|---|---|---|---|---|
| L1 首页 | 1 | 管线 | `/raw/html/en/index.html` | 内联债最重(191 处 style 属性);U1 四表单→CTA、U4 91 个 SVG 单源化在升级提交做 |
| L2 图案 PDP | 146 URL / 1 模板 | 管线 + 槽位化 | 每系列代表品 9 张(抽象/几何/摩洛哥/有机/穿孔/渐变*/景观/亚洲/复古;*gradient 系 0 品不采) | 旧站本来就是 1 模板×146(F6);管线先出「无数据版」骨架,再把逐品值替换为 §6 槽位;九张代表品互查骨架一致性,不一致处逐处对账(旧站若对个别品有硬编码分叉,分叉进台账) |
| L3 系列档案页 | 8 URL / 1 模板 | 管线 + 槽位化 | 代表系列页 1 张(选产品数中等的 organic 系)+ 全 8 张互查 | 标题/描述走 Woo collection 数据(#10 已补真值);筛选/排序/分页(U1)在升级提交重实现 |
| L4 Pattern Series Hub | 1 | 管线 | `/collections/` 渲染页 | 裸路径 rewrite 优先级是 #4 遗留项,不影响模板生产 |
| L5–L12 产品族页 ×8 | 各 1 | 管线(每页各自走一遍) | 各自的 `/pages/<handle>` 渲染页 | 8 张同构但异构(F6):骨架共享件按 §5 拆,页面专有节(对比表/工艺流程/质检带等)留在各自模板 |
| L13 manufacturing-process | 1 | 管线 | 同名渲染页 | — |
| L14 project-gallery | 1 | 管线 | 同名渲染页 | 64 图过媒体管线后原位替换(M1 指纹掩膜) |
| L15 about-us | 1 | 管线 | 同名渲染页 | — |
| L16 faqs | 1 | 管线 | 同名渲染页 | 吸收 PDP 雷同 FAQ 的全站级 Q&A(#8 D2)在升级提交做,文案走 T1 |
| L17 custom-screens | 1 | 管线 | published 版渲染页(7 张测试草稿不是) | 旧站专属布局 11 节;24 个 script 收敛白名单(U2)、156 图过管线(U3) |
| L18 /shop/ | 1 | 管线 | `/collections/all` 渲染页 | 网格/分页重实现(U1) |
| R1 博客四面 | 4 模板 | **新写:fork de-linker 四面**(F3 裁定) | de-linker `content/livecanvas/{blog_index,blog_single,category_archive,search}.html` + 对应卷 | 按 5 分类 IA 与本站 tokens 适配;不适用像素门,设计评审归 R1 自己的票 |
| R2 询盘功能面 | 2 模板 | 新写(#7 已规格) | #7 规格文档 | 本票只登记承接面(inquiry.html / inquiry-thank-you.html),不重开设计 |
| R3 /privacy/ | 1 模板 | 新写(#8 §7 大纲) | #8 §7 | 文案 T1 评审 |

## 3. D2 LC 承接面清单(25 模板 + 2 partials)

赋值机制两类(F5):**页型键**(该页型全站一张模板)与**逐页赋值**(`lc_use_template_of_slug` 写到页面 post)。仓库位置 `plugins/md-site/content/livecanvas/`(IA3);命名 = 页型英文名,禁版本族后缀(#8 CHECK-7)。

| 模板文件 | 赋值机制 | 键 / 目标 | 页型 |
|---|---|---|---|
| `partials/header.html` `partials/footer.html` | LC partial(全局) | — | 站级 chrome(IA1) |
| `home.html` | 逐页赋值 | 首页 post(静态首页) | L1 |
| `pdp.html` | 页型键 | `is_single_product` | L2 |
| `series-archive.html` | 页型键 | `is_archive_for_tax_product_cat`(键名与 #175 的 `is_archive_for_tax_category` 同构;实现票第一步在 dev 实例核键名,不符即改此行) | L3 |
| `hub.html` | 逐页赋值 | `/collections/` 页 post | L4 |
| `family-laser-cut-geometric.html` … `family-expanded-metal.html`(8) | 逐页赋值 | 各 `/pages/<handle>` post | L5–L12 |
| `manufacturing.html` `project-gallery.html` `about.html` `faqs.html` `custom-screens.html` | 逐页赋值 | 各页 post | L13–L17 |
| `shop.html` | 页型键 | `is_shop_page` | L18 |
| `blog-index.html` `blog-single.html` `blog-category.html` `blog-search.html` | 页型键 | `is_blog_posts_index` / `is_single_post` / 分类归档键 / `is_search` | R1 |
| `inquiry.html` `inquiry-thank-you.html` | 逐页赋值 | `/inquiry/`、`/inquiry/thank-you/` 页 post | R2 |
| `privacy.html` | 逐页赋值 | `/privacy/` 页 post | R3 |

`present` 机制(仓库文件 → DB `post_content`,按赋值键就地接管 + `drift()` 读回)平移 de-linker(F4)。这是自定义 PHP 的合法一处:LC 没有原生「模板以文件为源」的能力,不经 present 则模板退化为 GUI 产物,违反 §2.1 理由 2;穷尽证明即本段。

## 4. 管线规格(D1 展开)

### 4.1 输入与产物

- 输入:旧站渲染 HTML(F7,#2 脚本可复跑;若 `/tmp/md-r-work` 已回收则重跑采集)+ 本表逐页型代表性输入(§2.2)。旧站内容冻结前采集(#8 §5.1 时点)。
- 产物:每页型 1 份 LC 模板仓库文件 + WindPress 卷(§7)+ `.scratch/<票号>/` 生成脚本与逐文件比对(证据属性,不随插件发布,循 #175)。

### 4.2 七类机械变换(承 #175 五类,按本站债扩)

| # | 变换 | 说明 |
|---|---|---|
| T1 | Shopify 脚手架剥离 | `shopify-section` 包裹 div、`#shopify-section-*` id、Shopify 生成的 JSON-LD(新站结构化数据归 SEOPress,不搬)换成仓库注释 |
| T2 | 样式归卷 | 行内 `style="…"` 与 `<style>` 块 → WindPress 卷规则,铸语义类名(`md-` 前缀);**同渲染原则**:搬迁不改计算样式(每对带前缀声明 `-webkit-` 在前,#175 §4 的编译陷阱写进生成器) |
| T3 | 脚本剥离 | `<script>` 全数出模板;交互按 #8 U2 各行在升级提交以白名单重实现(LC 运行时之外零默认脚本) |
| T4 | 资产地址 | `/cdn/shop/…` → 终稿媒体管线根相对路径(§5 媒体件;替换前后图片指纹差异 = M1 掩膜) |
| T5 | 表单 → 询盘链 CTA | 一切 `<form>` 嵌入换 inquiry-CTA 单源组件(#7/#8 CHECK-8;升级提交做,收编提交保留原 DOM 占位以保证像素可比) |
| T6 | xo-webcomponents 元素 | 自定义元素 → 语义 HTML 重写(升级提交逐个 U2 项;收编提交保持原 DOM) |
| T7 | 注释与元数据 | 旧作者注释 → 仓库注释;实例主机名类绝对地址 → 根相对(#175 第 4/5 类) |

T2 收编期就要做(style 归卷不改渲染,#175 实证 0 差异);T3/T5/T6 是「升级提交」的动作,收编提交里它们的原物仍在——**收基线的像素门在收编提交上判定,升级差异走 M3 台账**。

### 4.3 两段式提交纪律

1. **收编提交**:逐字搬 + T1/T2/T4/T7。验收 = 像素门掩膜外差异 0(掩膜仅 M1)+ present drift 0。
2. **升级提交**:逐 U 项一提交(#8 §2.2 列名项),每项自带 M3 掩膜行 + 台账链接。CHECK-1…8 在升级提交全绿后才闭档。

## 5. D3 复用件清单与复用图谱(14 件)

组件类名一律 `md-<件名>[-<变体>]`,section id `md-<模板名>-<块名>`。复用分三层:

### 5.1 站级 partials(2 件,LC partial 全局单源)

| 件 | 旧站来源 | 复用于 |
|---|---|---|
| `md-header`(导航/下拉/图案清单入口) | 首页渲染 HTML 的 chrome | 全站 25 模板 |
| `md-footer` | 同上 | 全站 25 模板 |

### 5.2 单源内容组件(3 件,#8 已裁定的内容收敛,LC partial 单源引用)

| 件 | 内容 | 复用于 |
|---|---|---|
| `md-where-works` | Where this pattern works 文案(T1 一次过审) | pdp.html |
| `md-pdp-faq` | Technical FAQ 三问(T1;链接锚点去 /pages/faqs) | pdp.html;faqs 页吸收后链接回指 |
| `md-inquiry-cta` | 询盘链 CTA 带(T5 的替换物:按钮文案/WhatsApp 单号 85264731687/#7 链路) | 全站一切表单位:home、custom-screens、8 族页 contact 带、gallery、manufacturing、shop、hub、PDP 清单入口 |

### 5.3 组件件(9 件:DOM 配方 + WindPress 组件卷单源;跨模板复用的是「件」不是内容)

| 件 | 覆盖的旧站节(F6) | 复用于 |
|---|---|---|
| `md-breadcrumb` | breadcrumb / breadcrumb-v2(旧 3 个变体收敛为 1 件 2 变体) | 除 home/shop 外全部模板 |
| `md-hero` | 各 hero(视频/图像/cinematic 三变体) | home、8 族页、manufacturing、custom-screens、about、hub |
| `md-cta-band` | *_mid_cta ×9 + contact_v5 + *_contact ×8(表单转 CTA 后同件两变体:mid / contact) | home、8 族页、manufacturing、custom-screens、gallery、collection 系(档案页 contact 带) |
| `md-faq-accordion` | faq_v1 + *_faq ×9 + faqs 页折叠体 | home、8 族页、manufacturing、faqs、collection 系 |
| `md-spec-table` | *_specs / comparison-table / manufacturing-envelope / technical_specifications / home_specification_map | home、8 族页、manufacturing、custom-screens、collection 系(spec-guide) |
| `md-stats-band` | *_manufacturing_stats ×4 + about_stats | 4 族页、about、home(manufacture_excellence) |
| `md-gallery` | project_gallery、showcase ×4、featured_projects(灯箱/筛选为 U2 升级项) | gallery、4 族页、about(projects)、collection 系(project-proof) |
| `md-pattern-grid` | home_pattern_library、patterns_marquee 的卡格、pattern-catalog-main 网格、shop 网格(LC 原生产品 loop) | home、custom-screens(marquee 相邻件)、series-archive、shop、hub |
| `md-marquee` | patterns_marquee 跑马灯带 | custom-screens、home(若复用) |

复用矩阵的执行含义:组件卷(§7)单源定义这 9 件的样式;模板里同件 DOM 配方同名同类。**不做成内容 partial**——各族页的 CTA/FAQ 文案互不相同(#8 平迁),复用的是配方与样式,不是字符串;强行内容单源会撞 D3 平迁裁定。单源内容组件只有 §5.2 的 3 件,全部有 #8 裁定背书。

## 6. D4 模板×数据分工

原则(F2):静态文案(平迁内容、节标题、标签)进 LC 模板;逐 URL 变化的数据经 LC 原生 tag 或短代码装配;PHP 零设计决策。

### 6.1 分工表(LC 模板内静态 vs 数据槽)

| 面 | 模板静态 | 数据槽(机制) |
|---|---|---|
| L1 home | 全部 10 节文案平迁 | pattern-grid 卡格(产品 loop);徽标数等动态区 = M1 |
| L2 PDP | 节标题、规格条标签、FAQ 标题、UI 字符串 | 见 §6.2 |
| L3 series-archive | spec-guide/application-guide/project-proof 节文案 | 系列标题/描述(Woo collection term 数据);产品网格(loop);筛选/分页(U2 升级件) |
| L4 hub | 节文案 | 系列卡(loop over product_cat terms,LC 原生 term loop) |
| L5–L17 内容页 | 正文全量平迁(#8 D3),媒体过管线 | 无逐品数据 |
| L18 shop | 节文案 | 网格/分页(loop) |
| R1–R3 | 新写文案(T1 评审) | R2 表单 = 询盘插件(唯一 `<form>`) |

### 6.2 PDP:1 模板 ×146 实例机制

`pdp.html` 以 `is_single_product` 赋值(F5,de-linker 生产实证)对全部 146 品生效——与旧站 `product.pattern-spec.json` 一模板全品的结构同构(F6),像素门的「同 URL 基准」因此天然可比(#8 §3.2)。槽位按呈现层顺序(F2:原生优先,自定义穷尽证明):

| 槽 | 数据源 | 机制 | 自定义? |
|---|---|---|---|
| 面包屑 | Woo | LC/Woo 原生 breadcrumb tag | 否 |
| 图库 | Woo 产品图库 | Woo 原生 gallery + `md-gallery` 件(灯箱 = U4 升级件) | 否 |
| Pattern code / Style | Woo 标题/属性 | LC 原生产品字段 tag | 否 |
| Open area | ACF/metafield(#10) | LC 原生自定义字段 tag(`lc_get_cf` 同族);16 缺口渲染 "On request" 用 LC 条件原生实现 | 否 |
| Material route | 全局基线(#8 O2)+ 48 份逐品覆盖 | 基线 = 模板内单源组件;逐品覆盖 = `[md_pdp_material]` 短代码(基线/覆盖二选一的数据装配) | 是(数据装配;Woo 无「全局默认+逐品覆盖」原生机制,穷尽证明即本括号) |
| Design intent 正文 | 9 系列句式组(#8 D2)+ 属性槽;43 份既有 summary 逐品 | `[md_pdp_design_intent]` 短代码(按系列取句式组、填属性槽的纯数据装配) | 是(同上;句式组本体是 `docs/copy/` 的 T2 过审文案,不是代码) |
| Where-works / Technical FAQ | 单源内容组件 | §5.2 partial | 否 |
| 图案清单入口 CTA | #7 链路 | `md-inquiry-cta` 件 | 否 |
| meta description | — | SEOPress(不进模板) | 否 |

两个短代码归呈现层插件(IA3),职责边界:输入产品 ID,输出语义标记+类名的数据片段,零内联样式零设计(AGENTS §5 原文);视觉修复落点只能是模板或卷。

## 7. D5 样式卷组织(WindPress)

承 de-linker 模式(F11):扁平卷文件、全站唯一 WindPress 管线、全部编译进同一缓存产物(#8 CHECK-3:每页外链 CSS 恰 1 条)。**一条组织规则:一张模板一卷、一个组件件一卷、tokens 与 chrome 各一卷。**

| 卷 | 数量 | 内容 |
|---|---|---|
| `tokens.css` | 1 | 全站设计令牌(色彩/字阶/间距;令牌值来自 design-system MASTER.md 的既有 tokens,进卷路径归 #17 IA1) |
| `chrome.css` | 1 | header/footer 两 partial 的样式 |
| 组件卷 | 9 | `breadcrumb.css` `hero.css` `cta-band.css` `faq.css` `spec-table.css` `stats-band.css` `gallery.css` `pattern-grid.css` `marquee.css`(与 §5.3 一一对应) |
| 模板卷 | 25 | 与 §3 模板文件一一对应:`home.css`、`family-*.css` ×8、`manufacturing.css`、`project-gallery.css`、`about.css`、`faqs.css`、`custom-screens.css`、`pdp.css`、`series-archive.css`、`hub.css`、`shop.css`、`blog.css`/`blog-index.css`/`blog-category.css`/`blog-search.css`(承 de-linker 四卷分法)、`inquiry.css`、`inquiry-thank-you.css`、`privacy.css` |

共 36 卷。卷只增不减的理由:模板卷承载页型专有节的样式(族页 8 张同构但视觉各异,F6),合一卷会让「哪张族页改了样式」在 diff 里不可读;组件卷是 §5.3 单源 的物理形态。规则可 CI 审计:每张模板文件有同名卷、每卷被至少一张模板引用、无 `-v[0-9]` 命名(CHECK-7 同源)。

插件零 CSS(F2):询盘插件/呈现层插件输出语义 HTML,样式一律经卷编译进缓存。

## 8. D6 每页型验收(闭档四门)

对 #8 §5.3 页型闭档条件的**生产方式侧**补强;不重述 CHECK-1…8 本体。

| 门 | 判定 | 命令形态 |
|---|---|---|
| G1 present drift = 0 | 每张模板的 DB `post_content` 逐字节等于仓库文件;页面/赋值键存在且指向正确 | `wp md-site present-check`(drift() 同法,F4) |
| G2 DT ID 戳断言 | 每页型渲染页的 `<!-- LC DT ID -->` 戳 = 预期模板行,且仅此一行(防第二份模板接管,#175 §3 重复键检查同法) | curl 页面 grep |
| G3 像素门 | 对 `data/pixel-baseline/` 基准(#8 §5.1 协议采集;截图与 DOM 快照本体本地 gitignore,清单 sha256 入 `docs/acceptance/`)全页比对,掩膜外差异 = 0;掩膜逐项有 #8 台账行 | 同机同视口双截图 + diff |
| G4 CHECK-1…8 | #8 §5.3 全绿(升级提交后) | curl + CI |

页型顺序与采样:

- **先 chrome 后内容**:header/footer partial 先行(全站模板依赖),然后 home 打样走通四门,再铺 L5–L12 同构族页,最后 PDP/系列/shop(数据槽面)。
- **PDP 门**:骨架对 9 系列代表品像素门;全量 146 品自动 diff 回归 + 人审阻断门 ≈30 品(#8 §5.1 采样:每系列 1 品 + 16 镂空率缺口品 + 43 份 summary 品抽样);M2 四区双轨验收(#8 §3.2)。
- **系列档案页门**:8 系列全量(实例少,不采样)。
- **收编/升级分段判门**:收编提交过 G1/G2/G3(M1 掩膜);升级提交后才过 G4 与 M3 核对(§4.3)。

## 9. 接口假设(#17 未定稿;若改选的受影响面)

| # | 假设(按 picostrap5-child 平移写) | 若 #17 改选的受影响面 |
|---|---|---|
| IA1 | 主题基座 = picostrap5-child 子主题;header/footer 以 LC partials 承接;tokens.css 进卷路径与 MASTER.md 令牌映射沿用 picostrap 惯例 | 若选裸 2027 子主题:partials 机制不变(LC 自带,与主题无关);受影响 = ①模板内可用的网格/间距工具类词汇(picostrap 供 Bootstrap 工具类,裸主题下这些类改铸语义类进卷,生成器 T2 的类名映射表加一行来源标注)②tokens 卷的字号/断口基准。§3/§5/§6/§7 的结构不动 |
| IA2 | WindPress 卷目录 `content/windpress/`(de-linker 平移) | 不受 #17 影响(WindPress 独立于主题);仅当 #17 引入自带样式管线的基座时需按 F2「禁止第二条构建」重审,重审范围 = 卷的组织不动、编译入口重接 |
| IA3 | 呈现层插件 = 仓库插件 `md-site`(模板、卷、present、两个 `[md_pdp_*]` 短代码的宿主),符号链接挂入 dev 实例(#14 自研插件同法) | 插件切分(单插件 vs 呈现/询盘分体)归 #17/实现票;若分体,`content/` 与 present 随呈现插件走,短代码宿主不变,本文路径名同步改 |

## 10. 实现票如何消费本文

- 每页型实现票的输入 = §2.2 行 + §3 赋值行 + §4 管线 + §8 该页型的门;开工先跑该页型基准采集(#8 §5.1),收编提交与升级提交分列。
- 复用件实现票按 §5 清单逐件交付(件 + 组件卷 + 在 2 张以上模板的引用证明);`md-inquiry-cta` 等 #7 文案走 T1。
- 生成脚本与逐文件比对入 `.scratch/<票号>/`(证据属性);DEBT/CHECK 数值判定归 #8 台账,不在本文重复维护。
- R1 博客票:fork de-linker 四面(F3),按 5 分类 IA 适配后走 R1 自己的设计评审;不占本文像素门。
- OPEN:无业主否决点(本票全部裁定由 #8 ledger 与 de-linker 生产证据推出;业主否决 #8 任一行时本文对应行联动改)。
