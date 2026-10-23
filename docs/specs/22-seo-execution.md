# 22 · SEO 执行规格(SEOPress × Schema × sitemap/robots × GSC 迁移)

日期:2026-10-24。票 #22(Grilling)。执行会话定稿(业主裁定 2026-10-24:grilling 票按执行会话推荐定稿,业主保留否决;AGENTS.md §2)。

本文钉死新站(metal-divider.com,WordPress + WooCommerce)的 SEO 执行面:SEOPress PRO 配置与逐页型 meta 模板、结构化数据(schema)映射、sitemap 与 robots.txt、hreflang 边界、GSC 迁移执行步骤、AI 代理面对应物。实现票(#17/#19 页面构建、#18 迁移器、上线 runbook)以本文为对账基准;本文不部署、不改生产。

## 0. 依据事实(已定,不再研究)

| # | 事实 | 来源 |
|---|---|---|
| F1 | URL 映射通则:slug 全保留(R2 `/products/<handle>`、R3 `/collections/<handle>`、R5 `/pages/<handle>`);`/collections/all`→`/shop/`;8 合规页→`/privacy/`;3 条预存 301 源直映;参数在 Cloudflare 边缘剥离;301 全站单跳由 CF 承担;GSC 用**双 URL 前缀属性 + 地址变更工具**(域名属性不被地址变更工具支持);旧域长期持有保 301 | #4 `docs/research/2026-10-24-seo-baseline.md` §3/§5/§7 |
| F2 | 数据模型:146 份逐品 title/description **逐字迁入 SEOPress meta 位**;SKU=pattern_code;`product_cat` 8 系列(gradient 不建);镂空率=ACF `open_area`(16 空值)+ `visual_density`;**价格留空(不写 $0)⇒ `is_purchasable()` false,原生禁购**;评论/trackback 关闭;pattern_summary 双落 `post_excerpt` | #6 `docs/specs/06-product-data-model.md` §2.2/§7(标题描述迁移 §9) |
| F3 | 内容边界:PDP 雷同 FAQ 收敛为全站单源组件,`/pages/faqs` 是 FAQ 唯一权威面;contact 退休→`/inquiry/`;`/inquiry/thank-you/` noindex;`/privacy/` 新建;11 个页型复刻+编码升级、博客四面重设计 | #8 `docs/specs/08-content-boundary.md` D2/D7、§2.2 |
| F4 | 博客 IA:`/blog/` 基座、文章扁平、5 个 topic-cluster 分类(分类档案=cluster hub,300–500 词导言) | #5 `docs/research/2026-10-24-blog-ia.md` §2/§3 |
| F5 | 切换顺序草案 10 步;步骤 6「GSC 验证 301 矩阵」是转出/解约前置硬门(AGENTS §5 先证后删) | #9 `docs/specs/09-domain-dns-mail.md` §6 |
| F6 | 业主策略库(必须对齐):Organization 完善、Product+QuoteAction、FAQPage、BreadcrumbList 多级、WebSite+SearchAction、llms.txt(P0)、AI 爬虫放行;以及诚实纪律(2026-07-10 编者按:供应商中位值/竞品映射数字不得进 schema 断言) | Marketing-Vault `02-SEO/schema-markup.md`、`02-SEO/ai-seo-strategy.md` |
| F7 | WooCommerce 核心源码(trunk,2026-10-24 抓取):`class-wc-structured-data.php` L264 offers 仅在 `'' !== $product->get_price()` 时生成;L547–549 **无 aggregateRating 且无 offers 且无 review ⇒ 整个 Product schema 不输出**(直接 return) | GitHub woocommerce/woocommerce trunk 源码 |
| F8 | SEOPress 官方文档(2026-10-24 Apify 抓取,见 §11):sitemap 按内容类型含/排除、**noindex 内容自动出 sitemap**;图片 sitemap(SEO > XML-HTML Sitemap > Enable XML Image Sitemap,含 Woo 产品图与画廊);面包屑=PRO(SEO > PRO > Breadcrumbs,product/product_cat 层级可配);robots.txt=PRO(SEO > PRO > robots.txt 虚拟文件,30 版修订历史;物理文件优先);llms.txt=PRO 9.5+ 原生(SEO > PRO > llms.txt;官方声明「目前多数 LLM 并不实际依赖」);结构化数据构建器(PRO)含 Product/FAQ/Article/Custom 类型,FAQ 可映射 ACF 字段、按 display condition 加载;Woo 模块含自动/手动产品 schema、GTIN 字段、og:price/currency、cart/checkout 集中 noindex;Polylang 集成=meta 与 sitemap 按语言,**hreflang 无原生功能**(官方指南把 hreflang 实现外链给通用教程) | seopress.org(各页 URL 见 §11) |
| F9 | Google 官方(2026-10-24):Product 结构化数据两条路线——Product snippets(**不可直接购买**的页面)/ Merchant listings(可购买页面);product snippet 的 Required 条目写明 **"Product snippets require either `review` or `aggregateRating` or `offers`"**;Rich Results Test 判定口径=修复 critical errors 后部署 | developers.google.com(§11) |

## 1. 决策总表

| # | 决策 | 结论 | OPEN(业主否决/填空点) |
|---|---|---|---|
| D1 | SEOPress 配置与逐页型 title/description 模板 | 模板表(§2)只做**兜底**;首页/系列归档/商店档案/逐页文案走手工位;146 PDP 逐品 meta 已平迁,模板不覆盖 | O4 系列 8 份 term meta 文案 |
| D2 | Schema 映射 | 首发面六类:Product(146 PDP,**不输出 offers**,QuoteAction 替代)/ Organization(名称+邮箱+WhatsApp,无地址)/ WebSite+SearchAction / BreadcrumbList(SEOPress PRO 面包屑)/ FAQPage(仅 /pages/faqs)/ BlogPosting;骨架见 §3 | O1 地址→LocalBusiness 升级;O2 sameAs |
| D3 | sitemap 与 robots.txt | SEOPress sitemap(内容类型白名单 + 图片扩展 ON + noindex 自动排除);robots.txt 极简四行Disallow + Sitemap 行,AI 爬虫零屏蔽 | — |
| D4 | hreflang | 一期 EN 单语**不输出任何 hreflang**(SEOPress 本无此功能;票面「hreflang 归 SEOPress」的预设据 F8 修正为「hreflang 归二期翻译引擎」);二期打开条件四条(§5) | — |
| D5 | GSC 迁移 | 时序 T0–T5(§6),嵌 #9 §6 十步;T2(301 矩阵全绿)= 地址变更工具前置 | — |
| D6 | AI 代理面 | llms.txt 用 **SEOPress PRO 原生生成**;agents.md 建静态;UCP/MCP/sitemap_agentic_discovery 不建;AI 爬虫全放行(§7) | — |

---

## 2. D1 SEOPress 配置与 meta 模板表

### 2.1 全局配置

| 配置位 | 值 | 理由 |
|---|---|---|
| 站名(sitetitle) | `MetalDivider` | 与 Organization schema 的 name 一致(单一实体名) |
| 分隔符 sep | `—`(em dash) | 全站一致;146 份迁入 meta 不受影响 |
| 首页 title/description | **手工位**(SEO > Titles > Home page),T1 对抗评审文案,不用变量模板 | 首页是站级权重入口,模板拼不出竞争力文案;对齐 Vault「首页 Organization 完善」的同等手工态度 |
| Open Graph / X 卡片 | 开启;og:title/og:description 继承对应 meta;og:image 兜底 = 站级默认图(终稿媒体集产出);卡片类型 summary_large_image | 分享面零额外维护;og:image 逐页优先取 featured |
| noindex 面 | 搜索结果、日期/作者档案、附件页(attachment);`/inquiry/thank-you/` 逐页 noindex(#7 D5) | 搜索页/档案页是标准薄页;attachment 独立页无内容价值(图片发现走图片 sitemap) |
| SEOPress Woo 模块的 cart/checkout 集中 noindex | 不启用 | WP 站无这些路径;不开无效开关 |
| XML sitemap / robots.txt / llms.txt / 面包屑 | 见 D3/D6/§3 | |

### 2.2 逐页型模板表

**模板的作用域 = 兜底**:凡已逐页手写/平迁的 meta 优先于模板(SEOPress 原生行为:单页 metabox 值 > 全局模板)。变量语法以实装版 SEOPress 编辑器「变量下拉」为准(官方:数十个内置动态变量,可用于 title/description/social/schema;自定义变量经 `seopress_titles_template_variables_array` 等 filter 注册,见 §11 来源 16)。

| # | 页型(实例数) | SEOPress 配置位 | title 模板(兜底) | description 模板(兜底) | 逐页覆盖来源 |
|---|---|---|---|---|---|
| T1 | 首页(1) | Titles > Home page | 手工(T1 文案) | 手工(T1 文案) | — |
| T2 | PDP(146) | Titles > Single: `product` | `%%post_title%% %%sep%% %%sitetitle%%` | `%%post_excerpt%%`(= pattern_summary 双落位) | **146/146 已逐字平迁旧 meta(F2),模板只对未来新增/漏迁兜底**;兜底描述可加 SKU/镂空率变量,粒度以实装版变量下拉定(O3) |
| T3 | 产品族页(8)+ 内容页(13)+ `/collections/` Hub | Titles > Single: `page` | `%%post_title%% %%sep%% %%sitetitle%%` | `%%post_excerpt%%` | 逐页手写(内容票 #8 的 T1/事实修正通道产出时同步);旧站这 24 张 page 无逐页 meta 底账,不虚构平迁 |
| T4 | product_cat 系列归档(8) | Titles > Taxonomy: `product_cat` | `%%term_title%% %%sep%% %%sitetitle%%` | —(必须逐 term 手写) | **8 份逐 term 手写是必做项**(T1 评审,O4):term 显示名= family 名(如 Asian Inspired),description 结合系列真数据(typical_applications/difficulty_level,#6 §4)与镂空率分布 |
| T5 | `/shop/`(Woo 商店档案) | Titles > Archives: shop(手工位) | 手工(方向:`Pattern Library — Laser Cut Metal Screen Patterns`) | 手工 | 承接 `/collections/all` 权重入口(F1 R4) |
| T6 | 博客首页 `/blog/` | Titles > Archives: post | `Blog %%sep%% %%sitetitle%%`(可改手工) | 手工 | — |
| T7 | 博客分类(5) | Titles > Taxonomy: `category` | `%%term_title%% %%sep%% Blog %%sep%% %%sitetitle%%` | 逐 term 手写(自 300–500 词导言提炼) | #5:分类档案=cluster hub |
| T8 | 博客文章 | Titles > Single: `post` | `%%post_title%% %%sep%% %%sitetitle%%` | `%%post_excerpt%%`(yansir-md front-matter excerpt) | — |
| T9 | `/inquiry/` | Titles > Single: `page` | 同 T3 模板;title 手写(方向:`Request a Quote %%sep%% %%sitetitle%%`) | 手写 | 承接退休的 contact(F3) |
| T10 | `/privacy/` | Titles > Single: `page` | `Privacy Policy %%sep%% %%sitetitle%%` | 手写(一句话范围声明) | — |
| T11 | `/inquiry/thank-you/` | 逐页 noindex | —(不进索引) | — | #7 D5 |
| T12 | 搜索结果 / 404 / 日期·作者档案 | 全局 noindex | — | — | §2.1 |
| T13 | 一切分页(`/page/N/`) | 分页后缀 | 归档/博客分页 title 追加分页变量(`%%sep%% Page N`,变量名以实装版为准) | — | 分页 title 消重,避免「重复 title」信号 |

---

## 3. D2 Schema 映射(首发面)

### 3.1 清单与落点

| # | 类型 | 页面 | 落点(SEOPress) | 说明 |
|---|---|---|---|---|
| S1 | `Product` | 146 PDP | PRO 结构化数据构建器:Product 类型,display condition = post type `product`,字段映射 Woo(title/sku/image/description/excerpt)+ ACF(additionalProperty) | **不输出 offers**,见 3.2 |
| S2 | `Organization` | 全站单块 | PRO 结构化数据(Knowledge/Custom),头部输出 | 见 3.3 骨架;含 @id 供全站引用 |
| S3 | `WebSite`(+SearchAction) | 全站单块 | 构建器 Custom 类型(mu-plugin 头部装配亦可,呈现层规则内) | SearchAction target = `/?s={search_term_string}`(博客四面含搜索,F4) |
| S4 | `BreadcrumbList` | 全站内页 | **SEOPress PRO Breadcrumbs**(SEO > PRO > Breadcrumbs),schema 随可见面包屑自动输出 | product/product_cat 层级配置按旧站实际面包屑结构(复刻像素门,#19 对齐);插件输出语义 HTML,样式走 WindPress |
| S5 | `FAQPage` | 仅 `/pages/faqs` | 构建器 FAQ 类型,display condition = page `faqs`;Q&A 数据映射单源 FAQ 组件数据(可 ACF 映射) | **PDP 的单源 FAQ 组件不带 FAQPage 标记**(避免同一组 Q&A ×146 重复;Google FAQ 政策 + #8 D2 单源裁定)。FAQ rich result 2023.8 起只对权威政府/健康站展示——价值在 AI 引用面(Vault 同判) |
| S6 | `BlogPosting` | 博客文章 | PRO Article schema(自动) | author/publisher = S2 的 @id |

**首发不做**(上线后内容迭代按 Vault P1/P2 逐步补,不阻塞):`LocalBusiness`(等 O1 真实地址)、`ItemList`(/shop/、Hub)、`HowTo`、`Service`、`VideoObject`、`CollectionPage`。逐项与 Vault 的对齐关系见 §8。

### 3.2 Product 的 offers 处理($0 展示态)——核心结论

**结论:PDP 的 Product schema 不输出 `offers`,以 `potentialAction: QuoteAction` 表达转化路径。**

理由链(每环有据):

1. 价格事实是「留空」不是 $0(F2):#6 已裁定空价格 ⇒ 原生禁购。schema 是喂给机器的断言(F6 诚实纪律),**没有可声明的价格就不声明价格**。
2. WooCommerce 核心**不会**与我们打架:空价格 + 评论关闭 + 无评分 ⇒ 核心代码直接不输出 Product schema(F7,L264/L547)。因此页面上只有 SEOPress 一块 Product,无双发。验收时实测确认。
3. Google 规则(F9):Product snippets 的 Required 写明需要 `review`/`aggregateRating`/`offers` 三者之一。询盘制目录:
   - `offers` 无真价格可填;
   - 评分/评论是 146 全新页,无真实数据(**伪造 aggregateRating = Google spam 政策红线,永不考虑**);
   - ⇒ **明确接受「PDP 不进 product snippet 富结果」这个事实**。Google 自己把 Product snippets 定义给「不可直接购买的页面」——不满足其字段要求的,诚实缺省即可。
4. 伪造路径全部否决:
   - `offers.price: 0` —— 把「询盘制」错误地断言为「$0 商品」,rich result 报错(price 无效/availability 失真)且会被当真价格展示,违反 F2 与 F6;
   - Vault 里的 `$80–200/sqm` AggregateOffer —— 那是 26 家**供应商市场**中位区间(F6 数据来源说明),不是本厂报价;写进 offers 即「竞品映射数字进 schema 断言」,正是 Vault 编者按清理的类别;
   - 有 offers 无 price —— 直接 rich result 报错,最差解。
5. 判定口径:Rich Results Test 对 PDP = **0 错误**;「不 eligible product snippet(缺 offers/aggregateRating)」是预期状态,**不算失败项**,写入验收脚本白名单。

### 3.3 JSON-LD 骨架(变量位 = 实装时由 SEOPress 字段映射填充)

Product(PDP,SEOPress 构建器):

```json
{
  "@context": "https://schema.org",
  "@type": "Product",
  "@id": "https://metal-divider.com/products/<slug>#product",
  "name": "<post_title>",
  "sku": "<pattern_code>",
  "description": "<pattern_summary / excerpt>",
  "image": ["<featured>", "<gallery 原序,可全量或前 6>"],
  "brand": { "@type": "Brand", "name": "MetalDivider" },
  "category": "<product_cat 显示名,如 Asian Inspired>",
  "material": "304 / 316 stainless steel",  "additionalProperty": [
    { "@type": "PropertyValue", "name": "Open area", "value": "<open_area %>%(16 空值品省略此条)" },
    { "@type": "PropertyValue", "name": "Visual density", "value": "<open|balanced|dense|custom>" },
    { "@type": "PropertyValue", "name": "Repeat behavior", "value": "<repeating|flowing>" },
    { "@type": "PropertyValue", "name": "Best fit", "value": "<architectural-screening|decorative-screening>" }
  ],
  "potentialAction": {
    "@type": "QuoteAction",
    "name": "Request a quote",
    "target": { "@type": "EntryPoint", "urlTemplate": "https://metal-divider.com/inquiry/" }
  }
}
```

(`material` 取 #8 O2 的全局基线默认口径「304 / 316 stainless steel」;业主裁定口径变化时,#6 数据模型、PDP 规格条与本文此处联动改,单源对齐。)

Organization(全站单块):

```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "@id": "https://metal-divider.com/#organization",
  "name": "MetalDivider",
  "url": "https://metal-divider.com/",
  "logo": { "@type": "ImageObject", "url": "<终稿媒体集 logo URL>" },
  "email": "info@metal-divider.com",
  "contactPoint": [
    { "@type": "ContactPoint", "contactType": "sales", "email": "info@metal-divider.com", "availableLanguage": ["English"] },
    { "@type": "ContactPoint", "contactType": "WhatsApp", "telephone": "+85264731687", "availableLanguage": ["English"] }
  ]
}
```

刻意不写(与 Vault 的差异,见 §8):`address`/`geo`(业主真实地址未取得,O1)、`sameAs`(无经核实的社媒档案,O2)、`numberOfEmployees`/`priceRange`/`foundingDate`(Vault 编者按清理过的供应商中位值/映射值)。**因此不升级为 `LocalBusiness`**(其价值在地址/营业时间;无真实地址的 LocalBusiness 是负资产)。O1 落地后升级为 `["Organization","LocalBusiness"]` 双类型,对齐 Vault §3.1。

WebSite:

```json
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "@id": "https://metal-divider.com/#website",
  "name": "MetalDivider",
  "url": "https://metal-divider.com/",
  "inLanguage": "en",
  "publisher": { "@id": "https://metal-divider.com/#organization" },
  "potentialAction": {
    "@type": "SearchAction",
    "target": { "@type": "EntryPoint", "urlTemplate": "https://metal-divider.com/?s={search_term_string}" },
    "query-input": "required name=search_term_string"
  }
}
```

FAQPage(仅 /pages/faqs;Q&A 与单源组件同数据):

```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "@id": "https://metal-divider.com/pages/faqs/#faqpage",
  "mainEntity": [
    { "@type": "Question", "name": "<Q>", "acceptedAnswer": { "@type": "Answer", "text": "<A>" } }
  ]
}
```

BreadcrumbList(SEOPress PRO 面包屑自动输出;结构示例 = PDP):

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://metal-divider.com/" },
    { "@type": "ListItem", "position": 2, "name": "<series family 名>", "item": "https://metal-divider.com/collections/<slug>" },
    { "@type": "ListItem", "position": 3, "name": "<pattern 名>" }
  ]
}
```

(逐页型面包屑层级以旧站实际结构为准,#19 模板对齐;末级 item 可省。)

---

## 4. D3 sitemap 与 robots.txt

### 4.1 SEOPress XML sitemap 配置

| 项 | 值 | 理由 |
|---|---|---|
| XML sitemap | 开启;URL `https://metal-divider.com/sitemap.xml` | #4 检查清单指定 |
| HTML sitemap | 不启用 | 无消费面;将来要加是运营决策非 SEO 必需 |
| 内容类型 | `page`、`post`、`product` 入;`attachment` 排除;其他自定义类型(无) | 附件页全站 noindex(§2.1),也不该出现在 sitemap |
| 分类法 | `product_cat`、`category` 入;`tag`、作者档案排除 | tag 是薄重复页(SEOPress 官方同款建议);作者档案无单人运营价值 |
| **图片 sitemap** | **开启**(SEO > XML-HTML Sitemap > Enable XML Image Sitemap) | #4 §2.1 明确要求确认图片扩展;F8 证实含 Woo 产品图+画廊(1063 张产品图的图片搜索入口) |
| 视频 sitemap(PRO) | 不启用 | 5 个家族页 hero mp4 是装饰元素非视频内容;VideoObject/视频 sitemap 随内容迭代另行评估 |
| noindex 自动排除 | 依赖(F8 官方行为);验收逐项实测 thank-you/搜索页确在 sitemap 外 | — |
| hreflang 条目 | 无(单语,D4) | #4:语言信号以 head 输出为准,不在 sitemap 重复 |

### 4.2 robots.txt(SEO > PRO > robots.txt 虚拟文件)

```
User-agent: *
Allow: /
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php
Disallow: /?s=
Disallow: /search/

Sitemap: https://metal-divider.com/sitemap.xml
```

理由与边界:

- 全文就这七行。**AI 爬虫零屏蔽**:不加任何针对 GPTBot/ClaudeBot/PerplexityBot/Google-Extended/CCBot 等的 User-agent 分段——缺省即全放行,对齐 Vault 审计结论「未被屏蔽 = 良好」与 ai-seo-strategy 全篇主张。
- 不搬 Shopify 的参数陷阱清单(#4 §6:那是 Shopify URL 形态;utm/variant 等参数由 CF 边缘 301 剥净,robots 不承担)。
- `/cart` `/checkout` `/account` 不写死(WP 无此路径);SEOPress Woo 的对应 noindex 开关不开。
- 落地前确认站点根目录**无物理 robots.txt**(物理文件会覆盖虚拟文件,F8)。
- 保存走 PRO 面板(自带 30 版修订历史,误改可回滚)。

---

## 5. D4 hreflang

**一期(EN 单语):不输出任何 hreflang;`<html lang="en">`;无 x-default。**

- 依据:多语言推迟二期是业主裁定(AGENTS §2);单语站输出 hreflang(哪怕只有 x-default)无收益、徒增噪声。
- 分工修正:票面预设「hreflang/meta 归 SEOPress」。查证(F8):**SEOPress 没有原生 hreflang 功能**——其多语言方案是与 Polylang/WPML 集成(meta、sitemap 按语言),hreflang 的输出责任在翻译引擎一侧。据此定稿:**meta/sitemap 归 SEOPress,hreflang 归二期翻译引擎**(单点输出,避免双发)。

**二期打开条件(四条全满足才开,开时另立票):**

1. ≥2 语种完整内容上线(业主翻译资源到位;AGENTS §2「新站 zh 起步即完整翻译」标准);
2. 引擎选定落地(倾向 WPML,AGENTS §2;Polylang Business Pack 选型结论 #3 备用);
3. hreflang 由引擎**单点**输出:代码用 `zh-Hans`/`pt-BR`(非裸 zh/pt,与旧站一致,#4 §5)、全部 alternate 互指(reciprocal)、`x-default` 指 EN、head 输出为准不进 sitemap、AR 语种 `dir="rtl"`——全部承 #4 §5 既定参数;
4. GSC 国际定位(hreflang 报告)核对无误。

---

## 6. D5 GSC 迁移执行步骤(时序,嵌 #9 §6 十步)

```
T0  切换前(评审站 noindex 期;与 #9 步骤 1–4 并行)
    ├─ 两个 GSC 属性均以【URL 前缀】建好并验证:
    │    https://metalpartition.com(旧)+ https://metal-divider.com(新)
    │    (地址变更工具不支持域名属性,F1)
    ├─ 旧属性导出覆盖率/索引报告存档,作衰减对照基线(#4 §7)
    └─ redirect-map 的 18 行 PENDING(博客 16 + 致谢页 2)在对应票落地后回填,
       再随全量导入 Cloudflare(#4;博客/致谢页票就绪是 T1 前置)

T1  DNS 与 301 上线(= #9 步骤 3–5)
    ├─ 主域 NS→Cloudflare Active;301 矩阵在源站/CF 配好
    └─ Shopify 后台 metalpartition.com A/www → <HOST_IP>(≤24h 生效)

T2  301 生效验证(= #9 步骤 6;AGENTS §5 硬门:此步不过,禁转出/解约)
    ├─ 逐规则抽测全绿:产品、类目(含 gradient→/shop/ 修正行)、page、
    │  zh 镜像、带参 URL(剥参后落净 URL)、legacy 3 条源路径、
    │  404 policies 维持 404
    ├─ 新站 sitemap 提交(metal-divider.com 属性);robots.txt Sitemap 行生效
    └─ 新属性 URL 检查抽检 10 个重点页(可索引、canonical 自指新域)

T3  地址变更工具(T2 全绿后立即)
    └─ 旧属性 → 设置 → 地址变更:选新属性、确认 301 已全量生效 → 提交
       (Google 要求重定向已生效才提交;T2 即其证据)

T4  双前缀监控期(≥30 天;与 #9 步骤 7–9 并行,不阻塞转出/解约)
    ├─ 新属性:收录量上升曲线、重点 URL(/ 、8 类目、头部产品)canonical 已指新域
    ├─ 旧属性:索引衰减曲线正常(被 301 替代)
    └─ 新属性 404 监控:无「本应 301 的旧路径」漏网;旧域 301 抽测仍全绿

T5  长期
    └─ metalpartition.com 域名长期持有、301 与证书不中断(#4 §5.5);
       T4 指标转为月度例行查看
```

与 #9 的对齐声明:#9 的步骤 6(GSC 验证 301)即本文 T2;#9 步骤 7–10(转出→NS→复查→解约)在 T3 之后按其自身顺序执行,不依赖 T4 监控期完成。本文不新增任何 DNS/注册商动作。

---

## 7. D6 AI 代理面(旧站 agents.md / sitemap_agentic_discovery 的新站对应物)

| 项 | 结论 | 理由 |
|---|---|---|
| `llms.txt` | **启用 SEOPress PRO 原生生成**(SEO > PRO > llms.txt,9.5+;F8) | ①对齐 Vault ai-seo-strategy P0-6(「行业零先例的一等奖机会」);②原生优先:自动随内容更新、WPML 兼容为二期铺路,不手搓静态文件;③官方明示「目前多数 LLM 不实际依赖」,定位是前瞻布局非流量杠杆——预期管理写进文档,不为它做任何内容妥协。上线后人工核对一次生成内容(联系方式、核心面链接),记入 §9 验收 |
| `agents.md` | **建,静态文件**(仓库内容,非 WP 页面):声明只读浏览 + 询盘表单 `/inquiry/` + WhatsApp +85264731687 + info@metal-divider.com;明示无交易/无账户/无代理 API;链接 `/shop/`、8 系列、`/blog/`、`/pages/faqs`、`/llms.txt` | #4 §6 建议(成本极低);承接旧站 sitemap_agentic_discovery 的语义(给 AI 代理一张正确的站地图,而不是让它们猜一个不存在的结账流)。与 llms.txt 同批核对、同源信息,后续内容变更同步改 |
| `sitemap_agentic_discovery` 对应物 / UCP / `/api/ucp/mcp` / WebMCP 结账工具 | **不建** | Shopify 平台私有约定,WP 生态无消费者;新站无账户无结账,自造半吊子 MCP 端点反而误导代理(#4 §6 同判) |
| AI 爬虫策略 | 全放行(robots.txt 零 AI 分段,§4.2);站点内容本身按 Vault 的 passage-level citability 原则生产(#8 文案体系 + #5 博客 IA 已承) | Vault 审计:放行是「良好」态;屏蔽 GPTBot 等只会放弃 AI 引用面 |

---

## 8. 与 Marketing-Vault 对齐核对表

Vault 两文件是业主自己的策略库(以 metaldivider.com 旧 Shopify 站为对象,2026-03/07 定稿)。本规格逐项对齐;URL 类主张按新站结构改写,数字类主张按其编者按纪律执行。

| Vault 主张 | 本规格 | 关系 | 理由 |
|---|---|---|---|
| Organization 完善 + 升级 Organization+LocalBusiness 双标(schema §3.1) | Organization 单类型,仅名称/邮箱/WhatsApp/logo | **部分采纳,延后** | Vault 的 address/geo/numberOfEmployees 标注「映射自供应商数据库中位值」,非本厂事实;其编者按(2026-07-10)已清理同源数字。O1 业主给真实地址后升级双类型 |
| Product + QuoteAction(schema §3.3,offers $80–200/sqm) | Product + QuoteAction,**无 offers** | **采纳形式,修正数据** | QuoteAction 照单全收;价格区间是 26 家供应商市场中位数,不是本厂报价——「竞品映射数字进 schema 断言」正是编者按清理的类别;F2 价格留空 |
| `offers` 价格区间来自 Alibaba 采集(26 家,2026-03-29) | 不用于 schema;留给博客 buying-guides 内容引用(Vault 自己的用法) | 转移 | 市场行情数字在**编辑内容**里可引用并标注来源,在**机器断言**里不可 |
| FAQPage schema(重写 FAQ 后) | 仅 `/pages/faqs` 单页 | 采纳并收紧 | #8 D2:雷同 FAQ 收敛单源;×146 重复标记违反 Google FAQ 政策 |
| BreadcrumbList 多级全站 | SEOPress PRO 面包屑全站 | 一致 | 落点从 Liquid 换成 SEOPress |
| WebSite + SearchAction | 保留,target `/?s=` | 一致 | |
| ItemList(Hub/Patterns)、HowTo(craftsmanship)、Service、VideoObject(+transcript) | 首发不做,上线后内容迭代逐项补 | **时序后移** | 首发面收敛(像素门/复刻优先);这些类型都依附尚不存在的页面形态(新站无 /pages/screens、/pages/craftsmanship 等_slug,Vault 的示例页是旧站计划稿);补的时候按 Vault 模板 |
| LocalBusiness 知识面板机会 | 延后同 O1 | 一致(条件化) | |
| llms.txt(P0-6,「一等奖先发优势」) | SEOPress 原生生成 | 一致 | 落点从手写文件换原生功能 |
| AI 爬虫不屏蔽(GPTBot/ClaudeBot/PerplexityBot/Google-Extended) | robots.txt 零 AI 分段 | 一致 | |
| 段落级可引用性、具体数据元素、vs 内容、采购指南 | 不在本票范围;#5 博客 IA(buying-guides/comparisons 分类)+ #8 文案体系承接 | 一致(分工) | 内容策略归内容票;本票只定基础设施 |
| 实体建设(LinkedIn/GBP/B2B 目录 NAP) | 不在本票范围;O2(sameAs)留口 | 一致(分工) | 站外动作等业主启动,不属建站工程 |

## 9. 实现票消费方式与验收清单

消费:#17/#19(模板)按 §2/§3 配 SEOPress 与面包屑;#18(迁移器)按 F2 迁 146 份 meta——**键名已文档级确认**:`_seopress_titles_title`(标题)/ `_seopress_titles_desc`(描述),noindex 用 `_seopress_robots_*` 家族(来源 15,#6 §9 的「键名以实装版为准」注就此闭合);上线 runbook 按 §6 时序嵌 #9;agents.md 内容进仓库(#8 的 docs/copy/ 同批)。

验收(评审站可全项执行):

1. **模板抽检**:§2.2 每行渲染一页;分页 title 含页码不重复;146 PDP meta 与迁移底账逐字一致(抽 10)。
2. **noindex 抽检**:thank-you、搜索页、日期/作者档案、附件页响应头含 noindex;其余页无。
3. **Schema**(Rich Results Test + 源码双验):10 个 PDP **0 错误**(「缺 offers → 不 eligible product snippet」为预期白名单项);`/pages/faqs` FAQPage 0 错误;首页 Organization/WebSite 0 错误;博客样文 BlogPosting;任一内页 BreadcrumbList;每页**无 Woo 核心 Product 双发**(F7 理论上不会,实测坐实);同页无重复同类型块;@id 交叉引用可达。
4. **sitemap**:内容类型面 = page/post/product;含图片扩展;无 thank-you/搜索/附件/作者;product_cat 与 category 在、tag 不在。
5. **robots.txt**:全文与 §4.2 逐字一致;`curl https://<评审站>/robots.txt` 200;Sitemap 行正确;确认无物理 robots.txt。
6. **hreflang**:全页型源码 grep `hreflang` = 0 条。
7. **AI 面**:`/llms.txt` 200 且生成内容核对(联系方式/核心链接);`/agents.md` 200 且内容与 llms.txt 同源一致;两者不在 sitemap。
8. **OG**:首页 + 1 PDP + 1 博客文 og:title/og:description/og:image 抽检。

## 10. OPEN(不阻塞,否决/填空点)

| # | 事项 | 默认值(否决前生效) |
|---|---|---|
| O1 | Organization 真实邮政地址(业主私有事实) | 不写地址,不升 LocalBusiness;业主要求知识面板时提供地址,升级双类型 |
| O2 | `sameAs` 社媒档案 | 空数组省略;业主建立真实档案后逐个补(仅收经核实 URL) |
| O3 | PDP 兜底模板的变量粒度(SKU/`open_area` 等变量是否进兜底描述) | 兜底 = `%%post_title%%`+`%%post_excerpt%%` 最小面;实装版变量下拉确认后再加 |
| O4 | 8 份系列归档 term meta 文案 | 模板兜底生效;T1 文案评审后逐 term 回填(必做,时点在内容票) |

## 11. 来源

| # | 来源 | URL/位置 | 用于 |
|---|---|---|---|
| 1 | WooCommerce trunk 源码 | github.com/woocommerce/woocommerce `plugins/woocommerce/includes/class-wc-structured-data.php`(2026-10-24 抓取;L264、L482–494、L547–549) | F7:空价格 ⇒ 无 offers;三缺 ⇒ 无 Product 输出 |
| 2 | SEOPress 官方:wp-sitemaps 指南 | seopress.org/newsroom/featured-stories/wp-sitemaps/ | 内容类型含/排除、noindex 自动出 sitemap、图片 sitemap |
| 3 | SEOPress 官方:图片 sitemap 指南 | seopress.org/support/guides/enable-xml-image-sitemaps/ | 图片扩展开关位置、Woo 产品图+画廊 |
| 4 | SEOPress 官方:sitemap 排除指南 | seopress.org/support/guides/how-to-exclude-a-post-page-or-single-post-type-from-xml-sitemaps/ | 逐内容类型/逐页排除 |
| 5 | SEOPress 官方:面包屑指南 | seopress.org/support/guides/customize-breadcrumbs/ | PRO 面包屑、product/product_cat 层级配置 |
| 6 | SEOPress 官方:robots.txt 指南 | seopress.org/support/guides/edit-robots-txt-file/ + features/htaccess-robots-txt/ | SEO > PRO > robots.txt 虚拟文件、修订历史、物理文件优先 |
| 7 | SEOPress 官方:9.5 更新日志 | seopress.org/newsroom/product-news/seopress-9-5/ | llms.txt 原生功能(SEO > PRO > llms.txt)、官方免责声明、ProfilePage |
| 8 | SEOPress 官方:10.1 更新日志 | seopress.org/newsroom/product-news/seopress-10-1/ | WPML × llms.txt 兼容(二期铺路) |
| 9 | SEOPress 官方:WooCommerce SEO 功能页 | seopress.org/features/woocommerce-seo/ | 自动/手动产品 schema、GTIN 字段、og:price、cart/checkout 集中 noindex |
| 10 | SEOPress 官方:FAQ schema × ACF 指南 | seopress.org/support/guides/create-an-automatic-faq-schema-with-acf-repeater-fields/ | 构建器 FAQ 类型 + ACF 字段映射 + display condition |
| 11 | SEOPress 官方:Polylang 多语言指南 | seopress.org/support/guides/seopress-polylang-multilingual-seo-guide/ | SEOPress×引擎分工;hreflang 无原生功能(外链通用教程) |
| 12 | Google 官方:Product 结构化数据总览 | developers.google.com/search/docs/appearance/structured-data/product | Product snippets(不可购买页)/Merchant listings 二分 |
| 13 | Google 官方:product-snippet 定义 | developers.google.com/search/docs/appearance/structured-data/product-snippet | Required:「require either review or aggregateRating or offers」;RRT 判定口径 |
| 14 | SEOPress 官方:自定义动态变量指南 | seopress.org/support/guides/create-your-custom-dynamic-variable-for-your-meta-title-description-social/ | 变量机制:内置数十变量可用于 meta/social/schema;自定义变量 filter 族 |
| 15 | SEOPress 官方:SEOPress 生成的 postmeta 全列表 | seopress.org/support/guides/list-of-all-post-metas-generated-by-seopress/ | 迁移键名:`_seopress_titles_title` / `_seopress_titles_desc`(及 `_seopress_robots_*` noindex 家族、`_seopress_social_*`) |
| 16 | 本仓库已闭票产出 | #4/#5/#6/#8/#9 spec 与 research(分支 research/seo-baseline、blog-ia、spec-data-model、spec-content-boundary、spec-domain-dns) | F1–F5 |
| 17 | Marketing-Vault(只读) | `02-SEO/schema-markup.md`、`02-SEO/ai-seo-strategy.md`(2026-07-10 编者按版) | F6、§8 对齐 |

(SEOPress 页面均于 2026-10-24 经 Apify google-search-scraper + website-content-crawler 抓取全文核对;seopress.org 对直连 curl 有防护,Apify 通道正常。)
