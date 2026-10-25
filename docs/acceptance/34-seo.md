# IMP-9 验收记录:SEO 配置(SEOPress)

票:#34(IMP-9)。规格:`research/spec-seo` 分支 `docs/specs/22-seo-execution.md`。
交付面:md-site 的 SEO 配置平面(`wp md-site seo [--check]`,幂等、带漂移报告)+ schema 装配钩子;`just seo` 配方;部署管线 DB 面自动执行;GSC 迁移文档。验收在 dev 实例(127.0.0.1:9890,146 品全量导入)逐页型 curl 实测,2026-10-24。

## 配置面交付

| 面 | 落点 | 值 |
|---|---|---|
| 标题/描述模板表 | `seopress_titles_option_name` | sep `—`;首页手工位;single post/page/product = `%%post_title%% %%sep%% %%sitetitle%%` + `%%post_excerpt%%`(O3 最小兜底);product_cat / category 模板含 `%%current_pagination%%`(T13);shop 档案手工位 + 分页后缀;四 noindex 开关(搜索/日期/作者/附件) |
| Knowledge Graph | `seopress_social_option_name` | Organization:MetalDivider + info@metal-divider.com + WhatsApp +85264731687;OG/Twitter 卡开 |
| sitemap | `seopress_xml_sitemap_option_name` | page/post/product + category/product_cat;图片扩展 ON;无 tag/author/附件 |
| robots.txt | PRO 虚拟文件 | §4.2 七行逐字 + `Sitemap: <home>/sitemaps.xml` |
| 面包屑 | PRO breadcrumbs + JSON-LD | 开;JSON-LD 形状按旧站实际两级(PDP=Home>图案;内容页=Home>页;去 /pages 壳层) |
| Schema builder | `seopress_schemas` ×3 | Product(post_type=product;brand=MetalDivider;价格 none ⇒ 无 offers)、FAQPage(custom,postId=<`/faqs/` 的页 ID>,Q&A 自单源模板实时抽取 40 对)、BlogPosting(post_type=post) |
| /pages 壳 | SEOPress redirect | `/pages` → `/` 301(#4 裁定:URL 前缀不是页面,故**不设 page meta**:无页可 noindex,301 在 `template_redirect` 先于任何渲染触发,壳自然出 sitemap 与索引) |
| 逐页 meta | `_seopress_*` postmeta | inquiry / privacy 手工 title;blog 页 title+分页;thank-you noindex;sample-page(WP 安装残留,内容面落地前先不可见)noindex |
| 运行时装配 | `SchemaFilters`(六个 filter) | WebSite+SearchAction;Organization 双 contactPoint(sales 邮箱 + WhatsApp 电话);Product 补 material/category/ACF 四属性(16 空值品省略 open_area)/画廊前 6/绝对 @id/QuoteAction;BlogPosting author+publisher=@id;面包屑 crumbs 塑形;标题空白收敛 |

## 逐页型 curl 实测(全绿)

| 页型 | title | desc | canonical | robots | schema |
|---|---|---|---|---|---|
| 首页 `/` | 手工位逐字 | 手工位 | 自指 | index | WebSite(+SearchAction `/?s=`)+ Organization(双 contactPoint、@id 互指);og:title/desc 继承 |
| PDP ×10 抽测 | 146 迁移 meta 逐字 | 迁移逐字 | 自指 `/pattern/<slug>/` | index | **恰一块 Product、无 offers**、QuoteAction、material 304/316、category=系列名、additionalProperty(空值品省 open_area)、image=featured+画廊 6;BreadcrumbList 两级;og:image=featured |
| 系列档案 ×9 | `%%term_title%% %%sep%% %%sitetitle%%` | —(O4 逐 term 待写) | 自指 | index | BreadcrumbList Home>系列 |
| `/shop/` 与 `/shop/page/2/` | 手工位 / 手工位+`2` | 手工位 | — | index | — |
| 博客首页 `/blog/` | `Blog — …`+分页 | — | — | — | — |
| 博客分类 | `… — Blog — …` | — | — | — | — |
| 博客文 `/hello-world/` | 页面模板 | 继承 | 自指 | index | BlogPosting(author/publisher=@organization);og:title/desc 继承 |
| 内容页 ×13(`/about-us/` 等,根级) | 页面模板 | — | 自指 | index | BreadcrumbList 两级 |
| `/faqs/` | 页面模板 | — | — | index | **FAQPage 40 问**(与单源组件同源);PDP 无 FAQPage(单源,无 ×146 重复) |
| `/inquiry/`、`/privacy/` | 手工位 | — | — | index | — |
| `/inquiry/thank-you/` | — | — | — | **noindex** | — |
| 搜索/日期/作者档案 | — | — | — | **noindex** ×3 | — |
| 附件页 | 301 → `/`(WP 无父附件重定向,薄页不渲染;noindex 开关仍按规格在) | | | | |
| `/pages/` 壳 | **301 → `/`**;不在 sitemap | | | | |

Woo 核心零双发实证:PDP 仅 Product+BreadcrumbList 两块(F7 预期坐实)。

## sitemap / robots / llms.txt / hreflang

- 索引五张:post(2)/ page(19)/ product(147=146+shop)/ category(1)/ product_cat(9);`tag-*`/`author-*` 404。
- product-sitemap 含 **1063 条 `image:loc`**(与媒体管线终稿数一致)。
- 无 thank-you / 搜索 / 附件 / `/pages/` / sample-page 条目。
- `robots.txt` 与 §4.2 逐字一致(仅 Sitemap 行指原生端点);`/llms.txt` 200,PRO 原生生成(站名、搜索 URL、近期内容、sitemap 链接);`/sitemap.xml` 301 → `/sitemaps.xml`(SEOPress 原生文件名)。
- hreflang:七类面 grep 全部 **0 条**(D4 一期零输出)。

## 幂等与管线

- 二次 `just seo dev`:全部 unchanged/synced,零写入;`--check` 双命令(present/seo)drift 均 0 行。
- `just check` / `just test` 全绿(md-site PHPUnit 67 tests,含 FaqSchema/Config/SchemaFilters/SeoCommand 四组新测试)。
- 部署:`tools/deploy` 在 present 后自动执行 `md-site seo`(staging/prod 的 SEO 面随部署走,不靠手配)。

## 规格外裁定与已知边界

1. **Sitemap 行指 `/sitemaps.xml`**:规格 §4.2 写 `/sitemap.xml`,但 SEOPress 原生端点是 `/sitemaps.xml`(前者恒 301);robots 指令指向规范端点,避免一跳。
2. **og:price 关闭**:PRO Woo 模块的 `product:price:amount` 会把空价断言为 `content="0"`——与 §3.2 不写 offers 同一诚实规则,配置面显式移除。
3. **logo / og:image 兜底留空**:chrome logo 媒体资产未落地(模板引用的 uploads 路径无附件),knowledge logo 与站级默认 og:image 留空并在报告提示;资产入库后重跑 `just seo` 自动回填。首页 og:image 亦待此(内容面图片)。
4. **`/shop/`、`/blog/` 在页面与类型 sitemap 各一条**:SEOPress 原生行为(类型档案入口),URL 同一、canonical 自指,无害,不加过滤代码。
5. **`sample-page` / `hello-world`**:WP 安装默认残留,前者已 noindex(不可见),后者是博客样文(BlogPosting 验证用);正式内容面落地时删除/替换。
6. **Rich Results Test**:本地实例不可达 Google 工具;源码双验已做(JSON-LD 全部可解析、字段与规格骨架一致、无 offers/无重复块),RRT 留评审站(cutover #36 的 T2 前置)复跑。
7. **跨票缺陷修复(顺手)**:Templates 的 thank-you 页 slug 写的是扁平 `inquiry-thank-you`(URL 会成 `/inquiry-thank-you/`),与 spec 07/22 的 `/inquiry/thank-you/` 及 md-inquiry `ThankYou::PATH` 冲突——改为 inquiry 子页(`thank-you`),dev 实例已按此重摆。
8. **验收脚手架**:home/collections/inquiry/inquiry-thank-you/privacy/blog 六页与 front/posts 设定为 dev 实例一次性脚手架(空内容,present 已装面对应模板);正式页面属内容面/迁移器扩展,落地后自然接替。
9. **inquiry-smoke 现状(非本票引入,已实证)**:`just inquiry-smoke dev` 2 项失败——「零内联样式」断言撞上 #44 落地的 chrome partials(裸页现在渲染站点头尾,带 LiveCanvas/picostrap 的 `style='--xs:…'` 属性),断言中止连累「整包一封邮件」计数。已用 stash 本票全部改动复跑核实:失败与本票零相关(同 88 断言同 2 失败)。该套件断言需按 #43 的原意对齐 chrome 现实,归 md-inquiry 面修;`just check`/`just test`/`just mu-smoke` 全绿。
