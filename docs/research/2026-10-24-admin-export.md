# Shopify Admin API 权威导出(票 #10)

日期:2026-10-24。执行方式:Admin API 全量拉取,API 版本 `2026-07`,店铺 `90hamq-fs.myshopify.com`(metalpartition.com)。凭据与刷新方法见 `~/Documents/shopify-builder/.env` 文件内注释(client_credentials flow,token 24h 有效;本文档不含任何凭据)。

数据文件:`data/shopify-admin-export/`(已 gitignore,不入库;本地另有一份 rsync 到 `~/Documents/metal-divider/data/shopify-admin-export/`)。

## 导出量

| # | 内容 | 条数 | 文件 | 方式 |
|---|---|---|---|---|
| ① | 产品(图案)全量 | 146(全部 ACTIVE) | `products.json` | GraphQL `products` 游标分页,4 页 × 40 |
| ② | Collections(图案系列) | 9 | `collections.json` | GraphQL,单页 |
| ③ | Pages | 31(24 已发布 + 7 草稿) | `pages.json` | REST `/pages.json`,单页 |
| ④ | Blogs / Articles | 3 / 6(5 已发布 + 1 草稿) | `blogs.json`、`articles.json` | REST,articles 用 `published_status=any` 含草稿 |
| ⑤ | URL redirects | 16 | `redirects.json` | REST `/redirects.json`,Link 头 cursor 分页 |
| ⑥ | 通知/表单可达项 | 见下 | `notifications.json`、`theme-settings-data.json` | shop.json + GraphQL shop + 主题 settings |
| ⑦ | Store 基本设置 | 55 字段 | `shop.json` | REST `/shop.json`(全量字段) |

票面预估(146/9/24/3+5)与实际差异:pages 的 24 是已发布数,实际另有 7 张草稿页;articles 的 5 同理,另有 1 篇草稿(test-blog-6)。全部按含草稿的全量导出。

## Schema 说明

`products.json`:每个产品含 `id/legacyResourceId/title/handle/status/vendor/productType/tags/bodyHtml`、时间戳、`templateSuffix`、`seo{title,description}`、`options[]`、`variants[]`(2026-07 已移除 `available/weight/weightUnit/taxonomyNode`,改用 `availableForSale/inventoryItem.measurement.weight`)、`media[]`(本站全部为 MediaImage:URL/宽高/alt/status)、`metafields[]`。metafields 共 2934 条,命名空间 `custom`(33 个 key)+ `global`(title_tag/description_tag);覆盖面最广的 key:pattern_code、motif_tags、best_for、cad_status、openness_percent(130/146)、seo_primary_phrase、seo_supporting_phrases、source_manifest(json)等 35 个 key,完整清单可从文件直接统计。无任何产品 metafields 或 media 超出单页上限,无二次分页需求。

`collections.json`:9 个 series(handle 前缀 `pattern-`),各含 6 条 `custom` metafields(family/accent_color/difficulty_level/pattern_materials/typical_applications/bottom_content)、productsCount、seo、image。注意 2026-07 的 Collection 类型已移除 `ruleSet` 与 `publishedAt`,无法导出自动规则;本站 series 是手工/受控归属,productsCount 分别为 abstract 90、geometric 15、organic 16、landscape 8、moroccan-arabic 8、asian 4、vintage-retro 4、perforated 1、gradient 0。

`pages.json`:REST 全字段(title/handle/body_html/template_suffix/author/时间戳/admin_graphql_api_id)。模板引用 26 种 `template_suffix`,复刻时按模板分组。

`blogs.json`/`articles.json`:blog handle 为 design-guide、knowledge、technical;article 含 body_html、summary_html、image、tags、template_suffix。

`redirects.json`:path → target 全量 16 条,多为旧 collection handle 的归并。

`shop.json`:55 字段全量。唯一含 "password" 字样的是布尔 `password_enabled`(店面密码开关状态),非密钥;shop.json 本身不含任何凭钥类字段,未做删减。

## ⑥ 通知/表单设置结论

Admin API 不暴露 Staff/Order 通知收件人列表(NotificationCenter 内部数据,无公开 endpoint)。可达项已全部记录:

- 账号邮箱(通知兜底):`martinfu127@gmail.com`(shop.json `email`)
- 对客联系/发件邮箱:`sales@sunriverinverter.com`(GraphQL `shop.contactEmail` 与 shop.json `customer_email`,两处一致)
- 在线主题 `beddy-home-1` 的 `config/settings_data.json`(161 个设置键)已全量导出;其中未发现任何邮箱、API key 或 secret 类设置(表单收件应走 Shopify 原生 contact form,默认投递到账号邮箱)。

## 导不出 / 缺失项

1. **Collection 自动规则(ruleSet)**:2026-07 GraphQL 已删除该字段,REST collections.json 亦无。本站影响为零(productsCount 均正常取到),如需归属关系可用各 collection 的 products 关联重建。
2. **Staff/订单通知收件人**:无公开 API,只能业主后台自查(Staff notification settings)。
3. **产品变体旧字段**:`available`/`weight`/`weightUnit` 已从 2026-07 的 ProductVariant 移除,已用 `availableForSale` + `inventoryItem.measurement.weight` 等价替代,数据无缺失。
4. **草稿内容已含**:pages/articles 的草稿均已导出(REST 侧无密码限制);若重建只要已发布内容,按 `published_at` 过滤即可。

## Token 刷新(引用)

`SHOPIFY_ADMIN_ACCESS_TOKEN` 24 小时过期;刷新命令(client_credentials curl)与回写规则写在 `~/Documents/shopify-builder/.env` 的文件注释里,此处不复制。本次导出用的是 2026-10-24 00:54 CST 获取的 token,全程有效,未触发刷新,.env 未改动。
