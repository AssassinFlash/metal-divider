# Shopify 全量内容与资产导出体检(metalpartition.com)

- 票:#2(Research:Shopify 全量内容与资产导出体检)
- 日期:2026-10-24
- 方法:只用公开渠道——sitemap、`/products/<handle>.json`、`/collections/<handle>/products.json`、渲染 HTML(EN 与 /zh)、Shopify CDN 原图直下;无任何后台凭据。
- 采集快照:EN/zh 各 188 页(146 产品 + 24 页面 + 9 系列 + 3 博客 + 5 文章 + 首页)共 376 份 HTML + 146 份产品 JSON + 9 份系列 JSON,全部落 `/tmp/md-r-work/raw/`(过程数据,不入库)。
- 结构化产出:`data/shopify-public/`(products.json / collections.json / pages.json / pages-content.md / blogs.json / media-manifest.json);原图下载至 `data/media-raw/`(.gitignore 已排除,不入库)。
- 复现脚本:`scripts/01_sitemaps.py` → `05_pages_md.py`,顺序执行,全部可断点续跑。

## 1. 底账总览(每个数字的来源)

| 对象 | 数量 | 来源 |
|---|---|---|
| 产品 | 146 | `sitemap_products_1.xml` 共 147 个 URL,其中 1 个是首页,产品 146 |
| 页面 | 24 | `sitemap_pages_1.xml` |
| 系列 | 9 | `sitemap_collections_1.xml` |
| 博客 | 3(design-guide / knowledge / technical) | `sitemap_blogs_1.xml` |
| 文章 | 5(全部是 test 占位) | `sitemap_articles_1.xml` |
| /zh 镜像 | 与 EN 一一对应(146/24/9/3/5) | `sitemap.xml` 列出的 5 个 zh 子 sitemap,URL 数与 EN 相同 |
| 媒体清单 | 1420 个 CDN 文件 | 产品 JSON images(1063)+ 各渲染页 `<main>` 内 CDN 引用去重 |
| 产品图 | 1063 张(png 626 / jpg 383 / webp 54) | 146 份产品 JSON 的 images 数量汇总 |

系列产品数(来源:`/collections/<handle>/products.json?limit=250`):abstract 90、organic 16、geometric 15、moroccan-arabic 8、landscape 8、asian 4、vintage-retro 4、perforated 1、**gradient 0(空系列)**。合计 146,与产品总数对平。

## 2. 产品内容完备度(146 个,来源:products.json 汇总 + 渲染 HTML 解析)

公开 JSON 极薄:body_html 中位长度 30 字符(96 个产品正好 30 字符,即一句 `<p>Architectural screening</p>` 级别占位);价格全部 0.00;SKU/barcode 全空;单 variant「Default Title」;无库存管理。真实内容全在主题渲染的 HTML 里,已解析回结构化字段(`dl.pdp-spec-strip` 规格条、Design intent 区块、"Where this pattern works" 行、Technical FAQ)。

| 字段 | 完备度 | 明细 |
|---|---|---|
| 规格条 5 项(Pattern code / Style / Open area / Material route / Best fit) | 146/146 渲染 | 0 缺失 |
| Pattern code | 146 唯一无重复 | tag `pattern-code` 与渲染值大小写差异 85 个,语义一致 |
| Style | 8 个值 | 与 8 个非空系列一一对应(abstract 90 / geometric 15 / organic 16 / landscape 8 / moroccan-arabic 8 / asian 4 / vintage-retro 4 / perforated 1) |
| Open area | 130 个为数值(6.0%–85.5%,68 个不同值);**16 个 = "Project set"(非数值,规格缺失)** | 16 个:atherton、baleine、coconut-dreaming、forrest-perforated、herringbone、hiroko、la-pente-gradient、lombok、nara-gradient、painted-daisy、papyrus、ripples、skyfall-gradient、tree-of-life、trees-by-the-lake、winderoo(完整 handle 见 products.json) |
| Material route | 146 个全部同一值 "304 / 316 stainless" | 单一值 ⇒ 高度疑似模板默认而非逐品数据 |
| Best fit | 仅 2 个值:Architectural screening 96 / Decorative screening 50 | 与 `pattern-source` 标签计数完全重合(parasoleil 96 / dsd 50),即由来源派生,非独立字段 |
| Design intent | 146/146,但正文是模板:15 种变体 = 8 个风格名代入同一句式("{Style} geometry gives this screen its project read…"),标题 146 个全同 "Visual rhythm and light behavior" | 逐字对比得出 |
| "Where this pattern works" 4 行 | 146/146,但 146 个 payload 完全相同(1 种) | 纯模板文案 |
| Technical FAQ(3 问) | 146/146,146 个 payload 完全相同(1 种) | 纯模板文案 |
| body_html | 真有内容的仅约 10 个(>400 字符,如 herringbone 1113、baleine 911);其余为一句占位或空 | products.json `body_html_len` |
| 图片 | 每产品 2–16 张(众数 8 张,59 个产品),0 个产品无图 | images 计数分布 |
| 图片质量(产品图 1063 张,来源:产品 JSON 宽高) | 宽度 min 100 / 中位 1333 / max 3000 px;≥2000px 184 张;<600px 160 张(其中 <300px 仅 2 张:`0669__FUNK-DIVIDER-…250x500….png`、`pattern-media-zbid_62n.jpg`)。抽查下载原图尺寸与 JSON 记录一致(zelda.jpg 1024×1024、pattern-media-g3dxosvz.png 400×800,sips 实测) | 整体可用,小图集中在部分 DSD 采集图 |
| meta description | 146/146,中位 160 字符(SEO 状态良好) | 渲染 HTML `<meta name='description'>` |
| tags | `pattern-code` / `pattern-collection` / `pattern-style` / `pattern-source`(parasoleil 96 / dsd 50)/ `pattern-import-batch` | **注意:公开 JSON 里 tags 是逗号分隔字符串,不是数组** |

结论:逐品真实数据只有 标题、pattern code、style、open area(缺 16)、图片、来源标签;PDP 其余区块是模板生成。重建 WordPress 时这些模板文案可以放弃,按新站文案体系重写。

## 3. metafields 可恢复性

公开 JSON 不含 metafields。已知命名空间(custom.pattern_code / pattern_style / best_for / openness_percent / notable_effect / video_embed / product_360 / collection_name)中,产品侧实际被主题消费并渲染的就是规格条 5 项 + design intent(来自 `notable_effect` 一类字段),已在 products.json 恢复。**video_embed 与 product_360:146 个产品页 0 个 iframe/youtube/vimeo 嵌入**(逐页检查),5 个页面含 "360" 字样均为脚本随机数字误报——这两个字段线上无用,不可经公开渠道恢复,也无需恢复。

**Collection metafields(custom.family / accent_color / pattern_materials / typical_applications / difficulty_level / bottom_content)在 9 个系列页 0 渲染**(逐页字符串检查,四项标记全 False)。公开渠道完全拿不到,只能等业主的后台导出票。

## 4. /zh 中文版实际状态(逐项验证)

- 产品页 146/146:**仅面包屑(Home→家)和导航翻译,`<main>` 正文与 EN 逐字符相同(diff 只差面包屑一个词)**。CJK 字符数(正文)中位约 5 个——就是「家」加个别符号。products.json 里每个产品有 `zh.verdict`,146 个全部 `en-only`。
- 页面 24 个:同样只差面包屑(zh 与 en 的 main 文本相同或仅差「家 联系我们」级差异)。唯一例外 `appi` 页 EN 正文自带 312 个 CJK 字符——那是 Shopify 合规表单页的中文按钮文案,不是翻译。
- 系列页 9 个:同上,仅面包屑「家」(zh 正文 CJK 字符 = 1)。
- 博客列表页:zh 有部分翻译——分类名(知识/技术的/设计指南)与文章标题(测试博客 3)是中文,列表描述文案中英混杂;但文章本身是英文占位。文章详情页:仅面包屑与标题区翻译,正文英文。
- 定论:/zh 是「翻译导航 + 英文正文」的半壳,产品/页面/系列几乎零翻译,博客列表是唯一有正文级中文的地方(内容还是 test 占位)。重建 9 语种时,中文正文需要从零翻译,不能从旧站搬。

## 5. 页面与博客底账

页面正文长度(main 文本字符数,来源 pages.json)分三档:

- 合规壳(9 个,≤2.4k):gdpr / us-laws-compliance / canadian-laws-compliance / pdpa / popia / lgpd / apa-nzpa / appi + custom-metal-divider-screens-thank-you(507 字符致谢页)。
- 内容页(16 个,3.4k–29.6k):about-us 3.4k、contact 4.7k(4 个表单)、project-gallery 5.7k(64 张图)、manufacturing-process 11.0k、laser-cut-panels 11.0k、metal-divider 12.4k、custom-metal-divider-screens 12.4k、首页 13.7k(46 张图)、laser-cut-geometric-metal-screens 16.4k、glass-hybrid-screens 16.6k、chinese-style-artistic-screens 17.6k、stainless-steel-screens 17.7k、perforated-panels 17.8k、expanded-metal-panels 18.4k、outdoor-privacy-landscape-screens 24.2k、faqs 29.6k(1 张图)。
- 正文已全量提取为 `data/shopify-public/pages-content.md`(267 KB,24 页 + 首页)。

博客 3 个(design-guide 1 篇 / knowledge 3 篇 / technical 1 篇),文章全部是 test-blog-N 占位,正文 315–321 字符。**旧站没有可迁移的博客内容。**

## 6. 表单与联系渠道(来源:contact/home/custom-metal-divider-screens 渲染 HTML)

- WhatsApp:wa.me/8613068660047 与 8613312892178 两个号;tel:+8613068660047;邮箱:projects@metalpartition.com。
- contact 页 4 个 `<form>`,首页 4 个,custom-metal-divider-screens 2 个(询盘表单结构需在重建时对齐)。

## 7. 媒体资产与原始来源

- `data/shopify-public/media-manifest.json`:1420 个文件,每项含文件名、master_src(产品 JSON 权威 URL,带 ?v=)、宽高(产品图有)、引用位置(refs:product:/page:/collection:/home)、bytes、sha256(下载完成后回填)。
- 文件名即来源指纹:`pattern-media-*` 629 个(产品主图/渲染)、`paralocal__*` 131 个(parasoleil 图案的本地重渲,文件名内嵌原 pattern 名)、`our-*` 91 个(自有项目照片)、`ext-*` 52 个(外部素材)、`06xx__原文件名-宽x高__hash.*` 一批(DSD 素材,原名与分辨率直接在文件名里)。这些全部可直接从 CDN 拿原图,不需要后台。
- 与本地参考仓库 `/Users/chesterjohn/Documents/shopify-builder` 交叉:`uploaded_images.json`(500 条)中 183 个文件名与线上清单重合(主要是 our-* 项目图);`all_sourced_urls.json` 与 `batch_upload-batch-*.json`(70 条,Alibaba 供应商参考图)与线上 **0 重合**——那批参考图没有用在当前线上主题。即:线上媒体不依赖 builder 映射即可完整恢复,builder 映射只提供部分文件的本地上游线索。
- 下载结果(2026-10-24 完成):**1420 条清单 → 1340 个唯一文件(80 个重名 = 产品 JSON 与 HTML 双引用),585 MB,0 失败,0 空文件**;类型:png 631 / jpg 404 / webp 299 / **mp4 6**。mp4 是分类落地页(laser-cut-geometric / glass-hybrid / outdoor-privacy / chinese-style / stainless-steel)的 hero 视频,单个 10–28 MB,已一并下回。
- 下载:`scripts/04_download.py`,5 并发、断点续跑(.part 临时文件 + os.replace),按 basename 去重,只下 manifest 内文件,落到 `data/media-raw/`(gitignore 排除)。

## 8. 发现的坑(重建时要注意)

1. **版权红旗**:146 个图案里 96 个标签 `pattern-source:parasoleil`(Parasoleil 是真实图案授权商),50 个 `dsd`。WordPress 重建若继续使用这批图案图片,需要确认授权链;这是内容迁移之外的商业风险,建议在内容决策票里单独裁定。
2. 公开 JSON 的 `tags` 是逗号拼接字符串,解析时按 `,` 拆(脚本已处理;直接当数组会静默得到空)。
3. Material route 全部同值、Best fit 只有两值且与来源标签重合、Design intent / Where-works / FAQ 是模板文案——不要把模板输出当成逐品数据迁移。
4. 16 个产品 Open area = "Project set",数值规格缺失,需要业主补数或标为 TBD。
5. `pattern-gradient` 系列是空的(0 产品),但仍在导航与 sitemap 里;重建时要么补内容要么砍掉。
6. collection metafields 公开渠道 0 渲染,只能靠后台导出票。
7. 产品 JSON `src` 指向 cdn.shopify.com,渲染页用 metalpartition.com/cdn——同一文件双域,manifest 以产品 JSON 的 master_src 为权威。
8. meta 引号不统一(部分页面单引号 `content='…'`),解析 meta 时两种引号都要匹配。
9. 线上 sitemap_products 里混着首页 URL(147 = 146 + home),按 URL 前缀过滤。
10. 历史佐证:shopify-builder `tmp/pattern-product-seo-audit.json` 记录 146 产品全部用 `pattern-spec` 模板_suffix,与本体检渲染结果一致。

## 9. 产出物索引

| 文件 | 内容 |
|---|---|
| `data/shopify-public/products.json` | 146 产品:公开 JSON 全字段 + 规格条/设计意图/应用行/FAQ 解析 + zh 状态 + 图片表 |
| `data/shopify-public/collections.json` | 9 系列:标题、成员 handle 列表、metafield 渲染检查、zh 状态 |
| `data/shopify-public/pages.json` | 24 页 + 首页:标题、正文长度、h1–h3 标题清单、CDN 引用、zh 状态 |
| `data/shopify-public/pages-content.md` | 24 页 + 首页正文全文(EN) |
| `data/shopify-public/blogs.json` | 3 博客 5 文章:标题、正文长度、zh 状态 |
| `data/shopify-public/media-manifest.json` | 1420 文件:文件名、CDN URL、宽高、sha256、引用位置 |
| `data/media-raw/`(不入库) | CDN 原图,脚本 04 下载 |
| `scripts/01–05*.py` | 采集→解析→提取全流程,可复跑 |
