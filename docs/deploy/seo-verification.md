# SEO 上线验证(GOAL 阶段二)

对生产 https://metal-divider.com 的一手证据核查,2026-10-25 04:29–04:5x UTC。
基线:dev 实例验收记录 [34-seo](../acceptance/34-seo.md) + spec 22。
原始输出为本页内嵌 curl/wp-cli/API 摘录;完整原始文件在执行会话 /tmp/md-seo-verify/(临时,已按 §0 回收规则不保留)。

## 结论总表

| # | 检查 | 结果 |
|---|---|---|
| 1 | robots.txt | ✅ 通过 |
| 2 | sitemap | ✅ 通过(1 个无害注记) |
| 3 | Schema(首页/PDP/faqs) | ⚠️ 首页+PDP 通过;**faqs 的 FAQPage 缺失** |
| 4 | meta(首页/PDP) | ✅ 通过(og:image 站级兜底留空=已知边界) |
| 5 | noindex 面 | ⚠️ 搜索 noindex✅、作者/附件 301✅;**/inquiry/thank-you/ 本身 404**(应为 noindex 页) |
| 6 | llms.txt | ✅ 通过 |
| 7 | hreflang | ✅ 通过(EN 单语期零输出) |
| 8 | 301 重定向 | ✅ 6/6 通过(1 条注记:链跳 2 跳) |
| 9 | GSC TXT | ⛔ 未完成:Hostinger API 写入能力已确认;**token 尚未生成**(GSC 属性未建) |

**不通过/待修(已列 GOAL.md)**:/inquiry/thank-you/ 404、/privacy/ 404、FAQPage JSON-LD 缺失、GSC 属性+TXT 未建。

---

## 1. robots.txt ✅

```
$ curl -sS -D - https://metal-divider.com/robots.txt
HTTP/2 200
content-type: text/plain; charset=utf-8

User-agent: *
Allow: /
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php
Disallow: /?s=
Disallow: /search/

Sitemap: https://metal-divider.com/sitemaps.xml
```

与 spec 22 §4.2 七行逐字一致(SEOPress PRO 虚拟文件;`seopress_pro_option_name.seopress_robots_file` DB 读回同文)。Sitemap 行指规范端点 `/sitemaps.xml`。

## 2. sitemap ✅(注记:category 子图空)

```
$ curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' https://metal-divider.com/sitemap.xml
301 https://metal-divider.com/sitemaps.xml          # /sitemap.xml 恒 301 → 原生端点 ✅

$ curl -sS https://metal-divider.com/sitemaps.xml   # HTTP 200, x-robots-tag: noindex, follow
# 索引五张:post-sitemap1 / page-sitemap1 / product-sitemap1 / category-sitemap1 / product_cat-sitemap1
```

条目计数:

| 子图 | `<loc>` | `image:loc` | 对照 dev 验收 |
|---|---|---|---|
| post-sitemap1 | 1(`/blog/` 档案口;hello-world 已删) | 0 | dev=2(含样文),prod 无帖 → 1 |
| page-sitemap1 | 18(首页+16 内容页+/shop/) | 0 | dev=19,删样文/空壳后一致 |
| product-sitemap1 | **147**(146 PDP + `/shop/`) | **1063** | 一致 ✅ |
| category-sitemap1 | 0(空 urlset,合法 XML) | 0 | dev=1(Uncategorized+样文),prod 零帖 → 空 |
| product_cat-sitemap1 | 9(系列归档) | 0 | 一致 ✅ |

排除面核对:无 thank-you / 搜索 / 附件 / `/pages/` / `/home/`(301→/)/sample-page 条目 ✅。`tag-*`/`author-*` 不在索引 ✅。
注记(无害):博客零帖 ⇒ category-sitemap1 为空文件;SEOPress 仍索引它,Google 视为合法空图。首篇博文落地后自然有内容。

## 3. Schema(JSON-LD)—— 首页 ✅ / PDP ✅ / faqs ⛔

### 首页 `/`(✅ 两块,与 dev 验收一致)

块 1 `WebSite`(含 SearchAction):
```json
{"@type": "WebSite", "@id": "https://metal-divider.com/#website",
 "name": "Laser-Cut Stainless Steel Metal Screen Patterns — MetalDivider",
 "url": "https://metal-divider.com",
 "publisher": {"@id": "https://metal-divider.com/#organization"},
 "potentialAction": {"@type": "SearchAction",
   "target": {"@type": "EntryPoint", "urlTemplate": "https://metal-divider.com/?s={search_term_string}"},
   "query-input": "required name=search_term_string"}}
```

块 2 `Organization`(双 contactPoint、@id 互指):
```json
{"@type": "Organization", "name": "MetalDivider", "url": "https://metal-divider.com",
 "alternateName": "metal-divider.com",
 "contactPoint": [
   {"@type": "ContactPoint", "contactType": "sales", "email": "info@metal-divider.com", "availableLanguage": ["English"]},
   {"@type": "ContactPoint", "contactType": "WhatsApp", "telephone": "+85264731687", "availableLanguage": ["English"]}],
 "@id": "https://metal-divider.com/#organization"}
```

### PDP `/pattern/apiary-architectural-metal-screen-pattern/`(✅ 恰一块 Product + BreadcrumbList)

```json
{"@type": "Product", "name": "Apiary | Architectural Metal Screen Pattern",
 "image": ["…-01-400w.webp", "…-02-2048w.webp", "…-03-1500w.webp", "…-04-2048w.webp", "…-05-1500w.webp", "…-06-927w.webp", "…-07-1353w.webp"],
 "sku": "Apiary", "@id": "…/pattern/apiary-…/#product",
 "brand": {"@type": "Brand", "name": "MetalDivider"},
 "material": "Stainless Steel (304 / 316)", "category": "Abstract",
 "additionalProperty": [
   {"@type": "PropertyValue", "name": "Open area", "value": "33"},
   {"@type": "PropertyValue", "name": "Visual density", "value": "balanced"},
   {"@type": "PropertyValue", "name": "Repeat behavior", "value": "repeating"},
   {"@type": "PropertyValue", "name": "Best fit", "value": "architectural-screening"}],
 "potentialAction": {"@type": "QuoteAction", "name": "Request a quote",
   "target": {"@type": "EntryPoint", "urlTemplate": "https://metal-divider.com/inquiry/"}}}
```

无 `offers`(空价不断言为 0)✅;featured + 画廊 6 = 7 图 ✅;BreadcrumbList 两级 Home>图案 ✅;Woo 核心零双发(全页仅 Product+BreadcrumbList 两块 ld+json)✅。

### faqs ⛔ FAQPage 缺失

- `/faqs/` 200,**页面 HTML 实际渲染 40 条 Q&A**(正文含 "FAQ 40 Reply 24h")——单源组件在。
- 但全页 ld+json **只有 BreadcrumbList 一块,无 FAQPage**(dev 验收为 40 问 FAQPage)。
- 根因(wp-cli 生产库读回):SEOPress schema CPT 只迁到 2 条,FAQPage 条目缺失:

```
$ wp post list --post_type=seopress_schemas --fields=ID,post_title,post_status
41  Product — pattern pages    publish
42  BlogPosting — journal posts publish
                                   # dev 验收 ×3 中的「FAQPage — /pages/faqs」在 prod 不存在
```

## 4. meta 标签 ✅

| 面 | 首页 `/` | PDP `/pattern/apiary-…/` |
|---|---|---|
| title | `Laser-Cut Stainless Steel Metal Screen Patterns — MetalDivider`(手工位) | `Apiary Metal Screen Pattern`(迁移 meta 逐字) |
| description | 手工位(146 laser-cut stainless steel…)| 迁移逐字(Review the Apiary…) |
| canonical | `https://metal-divider.com/` 自指 | `…/pattern/apiary-…/` 自指 |
| robots | `index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1` | 同左 |
| OG | 6 标签(url/site_name/locale/title/description/type);**og:image 缺=已知边界**(chrome logo 资产未落地,34-seo 边界 3) | 11 标签,**og:image + alt/width/height/secure_url 齐**(featured 图) |

## 5. noindex 面 ⚠️(搜索✅;thank-you 页 404 是独立缺陷)

```
/?s=apiary            → 200  <meta name="robots" content="follow, noindex">      ✅
/author/admin/        → 301 → /                        (作者枚举被拦,强于 noindex)✅
/2026/ /2026/10/      → 404(零帖 ⇒ 日期档案不存在;noindex 开关无对象,配置仍在)✅(记录在案)
?attachment_id=100    → 301 → /pattern/dan-di-lion-…/  (附件 301 回父页,不可索引)✅
/pages/               → 301 → /                        (壳层)✅
/home/                → 301 → /                        (front 页副 URL 无重复)✅
/inquiry/thank-you/   → 404 ⛔⛔  # 应为 200+noindex;wp post list 无此页(page 表仅 21 行,无 thank-you)
```

## 6. llms.txt ✅

```
$ curl -sS -D - https://metal-divider.com/llms.txt
HTTP/2 200
content-type: text/plain
last-modified: Sun, 25 Oct 2026 01:13:16 GMT
# metal-divider.com
## Pages … ## Products …(SEOPress PRO 原生:站名、Pages/Products 清单)
```
33.7 KB,页面/图案清单与 sitemap 同源。注意:PRO 生成时把 `/home/` 列进 Pages(该 URL 本身 301→/,无索引面)。

## 7. hreflang ✅(EN 单语期零输出)

grep 命中:首页/PDP/faqs HTML `hreflang` ×0;五张子图 `xhtml:link`/`hreflang` ×0;响应头 `Link: …rel="alternate"` ×0。七类面口径与 dev 验收一致(D4)。

## 8. 301 重定向 ✅ 6/6

```
$ curl -sS -o /dev/null -w '%{http_code} %{redirect_url}'
301 https://metal-divider.com/pattern/apiary-architectural-metal-screen-pattern/   <= /products/apiary-…   (regex 规则)✅
301 https://metal-divider.com/inquiry/                <= /pages/contact                                    ✅
301 https://metal-divider.com/shop/                   <= /collections/all                                  ✅
301 https://metal-divider.com/privacy/                <= /policies/privacy-policy                          ✅(但落点 404,见缺陷 2)
301 https://metal-divider.com/                        <= /pages                                            ✅
301 https://metal-divider.com/inquiry/thank-you/      <= /pages/custom-metal-divider-screens-thank-you     ✅(但落点 404,见缺陷 1)
301 https://metal-divider.com/about-us/               <= /pages/about-us/   (页基座搬迁后旧前缀回指)      ✅
```

跳数:`/pattern/<slug>`(无尾斜杠)再 301 一次补斜杠 ⇒ 旧产品 URL 全链 2 跳、终态 200(≤2 跳,runbook 容限内)。
旧站内链式重定向例:`/pages/railing-fence-infill-panels/` → `/pages/glass-hybrid-screens` → `/glass-hybrid-screens/` 200(2 跳,终态正确; violates 迁移器 no-chain 设计,后果=多一跳,记录不立案)。
主机规范化:`www.` → 裸域 301 ✅;`http://` → `https://` 301 ✅。

## 9. GSC 属性/TXT ⛔(写入能力就绪,token 未生成)

DNS 区 API 读回(2026-10-25,refresh 后 Bearer):

```
GET https://developers.hostinger.com/api/dns/v1/zones/metal-divider.com → 200
9 组记录:@ ALIAS→cdn.hstgr.net、www CNAME、MX×2、DKIM CNAME×3(hostingermail-{a,b,c})、
autodiscover/autoconfig CNAME、ftp A
TXT 记录:0 条 —— 无 GSC 验证 TXT、无 SPF、无 _dmarc(T1 邮件三防未落,另票范围,在此记录)
```

- **API 支持写区**(OpenAPI 实读):`PUT /api/dns/v1/zones/{domain}`,body
  `{"overwrite": false, "zone": [{"name": "@", "type": "TXT", "ttl": 3600,
  "records": [{"content": "google-site-verification=<TOKEN>"}]}]}`,
  `overwrite:false` = 追加不动存量。本机 TUN 代理握手该域失败,执行路径=SSH 到 `hostinger-metal-divider` 上跑 curl(已验证可行)。
- **token 不存在**:GSC 属性未建(cutover-log 待办),全库/全区/文档无 `google-site-verification` 值。token 须在 GSC 建 `https://metal-divider.com`(URL 前缀)属性时由 Google 生成(需 Google 账号浏览器登录,本会话无凭据)。拿到 token 后用上行 PUT 一条即完成验证,无其它面板动作。
- 顺手:Hostinger OAuth token 已过期 11 小时,经 `auth.hostinger.com/api/external/v1/oauth-server/token`(refresh_token grant)刷新并回写 `~/.config/hostinger-mcp/credentials.json`(600)。

## 附:验证期间用到的其它一手证据

- 生产 page 表(wp-cli,`wp post list --post_type=page`):21 行;**无 thank-you 页**;`privacy-policy`(ID 3)与 `refund_returns`(ID 10)均为 **draft** ⇒ `/privacy/` 404、`/privacy-policy/` 404、`/refund_returns/` 404。
- `seopress_pro_option_name` 读回:breadcrumbs/robots(虚拟文件全文)/404-cleaning/rich-snippets 开关齐。
- SEOPress PRO 重定向落点:`seopress_404` CPT + `_seopress_redirections_*` postmeta(非 option)——排查时勿找错地方。
