# #25 否决点数据搜寻结果:16 品镂空率 / 材质清洗 / gradient 系列 / Hostinger 机房

- 票:#25(Research:否决点数据搜寻)
- 执行日期:2026-10-24(文件名按票面约定用 2026-10-25 前缀)
- 业主裁定基线:**先搜后否决**——Apify 多搜,搜不到才回落为业主否决点;材质口径已更新为五材质(Stainless/Aluminium/Corten/Mild Steel/Copper),304/316 仅为 Stainless 的牌号注记。
- 抓取通道:Apify `apify~website-content-crawler`(`run-sync-get-dataset-items`,主令牌)。`apify~web-scraper` 返回 403 `full-permission-actor-not-approved`(需控制台授权,未用);`apify~rag-web-browser` 对直连 URL 无效(把 prompt 当搜索,被目标站 403)。本报告所有外部页面均经 Apify 抓取(除注明 curl/whois 的项)。

---

## ① 16 品镂空率(open area)真值:0/16 有源值,回落业主否决点

### 源判定(本地证据)

16 品在 `data/shopify-public/products.json`(2026-10-24 导出)中全部带 `pattern-source:dsd` 标签、spec_strip `Best fit: Decorative screening`、`Open area: "Project set"`。146 品来源分布:96 parasoleil + 50 dsd。**16 品无一属于 parasoleil,主源全部是 DSD**。

溯源链(本地文件):
- `/Users/chesterjohn/Documents/shopify-builder/tmp/pattern-repair/source-map.json` — 16 品的 handle → DSD slug 完整映射(146 品全录);
- `/Users/chesterjohn/Documents/shopify-builder/docs/superpowers/specs/2026-05-17-pattern-import-dry-run-design.md` — 当初管线:DSD = `decorativescreensdirect.com.au`,产品页 URL 格式 `/product/{slug}/`,逐页抓取 "open area percent if present";
- `/Users/chesterjohn/Documents/shopify-builder/scripts/_archive/build_dsd_gallery_enrichment_manifest.py` — 同 URL 格式的代码证据。

对照组验证:50 个 DSD 源产品中 34 个有小数精度真值(19.8%–85.5%),如 bamboo=19.8%。今日经 Apify 抓线上 bamboo 页,原文 "The Bamboo Design has a 19.8% Open area. (Open areas are based on a 1000 x 1000mm panel with no border)" 与库中值逐字一致——证明当初数值即来自 DSD 页面文案,抓取方法有效;16 品缺值是因为 **DSD 页面上没有这句话**。

### 16 品逐一结果表

抓取时间 2026-10-24T02:05–02:06 UTC(批次),列 "页面" = 经 Apify 抓取的产品页正文;"原始 HTML" = `htmlTransformer: none` + `saveHtml: true` 免变换全量 HTML(排除折叠 tab / readability 丢内容)。

| # | 图案 | DSD 源 URL | 页面正文 | 原始 HTML | 结论 |
|---|------|-----------|---------|-----------|------|
| 1 | atherton | https://www.decorativescreensdirect.com.au/product/atherton/ | 200,空壳页(仅 Reviews 区,正文 148 字符) | 31,796B,无 description tab、无 open area、无 PDF | 无源值 |
| 2 | baleine | https://www.decorativescreensdirect.com.au/product/baleine-laser-cut-decorative-wall-art/ | 200,全文 1843 字符,无 open area | —(同批 4 品抽查定论,见下) | 无源值 |
| 3 | coconut-dreaming | https://www.decorativescreensdirect.com.au/product/coconut-dreaming-decorative-screen/ | 200,全文 2689 字符,无 open area | — | 无源值 |
| 4 | forrest-perforated | https://www.decorativescreensdirect.com.au/product/forrest-perforated-decorative-panel-design/ | 200,全文 482 字符(短描述页),无 open area | — | 无源值 |
| 5 | herringbone | https://www.decorativescreensdirect.com.au/product/herringbone-corten-decorative-screening/ | 200,全文 2499 字符,无 open area | — | 无源值 |
| 6 | hiroko | https://www.decorativescreensdirect.com.au/product/hiroko-decorative-screen-design/ | 200,全文 2345 字符,无 open area | — | 无源值 |
| 7 | la-pente-gradient | https://www.decorativescreensdirect.com.au/product/la-pente-gradient-screen-design/ | 200,全文 2659 字符,无 open area | — | 无源值 |
| 8 | lombok | https://www.decorativescreensdirect.com.au/product/lombok-decorative-garden-screen/ | 200,全文 2741 字符,无 open area | — | 无源值 |
| 9 | nara-gradient | https://www.decorativescreensdirect.com.au/product/nara-decorative-gradient-screening/ | 200,全文 2521 字符,无 open area | — | 无源值 |
| 10 | painted-daisy | https://www.decorativescreensdirect.com.au/product/painted-daisy-privacy-screens/ | 200,全文 1584 字符,无 open area | — | 无源值 |
| 11 | papyrus | https://www.decorativescreensdirect.com.au/product/papyrus/ | 200,全文 1352 字符,无 open area | 34,060B,tab 仅 description+reviews,无 open area,唯一 PDF 是 Dulux 色卡 | 无源值 |
| 12 | ripples | https://www.decorativescreensdirect.com.au/product/ripples-laser-cut-decorative-window-screens/ | 200,全文 1408 字符,无 open area | — | 无源值 |
| 13 | skyfall-gradient | https://www.decorativescreensdirect.com.au/product/skyfall-gradient-privacy-screen/ | 200,全文 2488 字符,无 open area | — | 无源值 |
| 14 | tree-of-life | https://www.decorativescreensdirect.com.au/product/tree-of-life-laser-cut-screens/ | 200,全文 2881 字符,无 open area | 52,565B,五材质清单原文在列,无 open area | 无源值 |
| 15 | trees-by-the-lake | https://www.decorativescreensdirect.com.au/product/trees-by-the-lake-cnc-laser-cut-panels/ | 200,全文 2825 字符,无 open area | — | 无源值 |
| 16 | winderoo | https://www.decorativescreensdirect.com.au/product/winderoo-decorative-room-divider/ | 200,全文 3178 字符,无 open area | 54,810B,五材质清单原文在列,无 open area | 无源值 |

注:表内 "无 open area" 指正则 `[Oo]pen\s*area` 与任意 `数字%` 组合均零命中;正文里出现的百分数只有图片宽度(1400px 等)与 Dulux 色卡链接,无镂空率语义。

### 穷尽路径记录(每条都试过)

1. 产品页正文批量抓取(上表,16/16,Apify website-content-crawler,2026-10-24T02:05–02:06Z)。
2. 原始 HTML 免变换抓取(atherton/winderoo/tree-of-life/papyrus 四品 + 3 品复核批,2026-10-24T02:08–02:12Z):排除 readability 丢折叠 tab 的可能——tab 仅 description+reviews,无 "Additional information" 规格表。
3. atherton 换住宅代理(RESIDENTIAL 组)重抓 + saveHtml:同样 148 字符空壳,HTTP 200——空壳是 DSD 页面本身,不是反爬。
4. Wayback Machine(`web.archive.org/web/2025/...atherton/`,经 Apify):返回 0 字节,无可用存档。
5. DSD 聚合页 `https://www.decorativescreensdirect.com.au/all-designs/`(252KB,16 品名全部出现):open area 零命中;`/product/all-designs/` 404。
6. 搜索引擎交叉(Z.ai web search,`decorativescreensdirect.com.au atherton OR winderoo OR "la pente" open area percentage screen`):无任何一品的镂空率数据。
7. parasoleil 侧排除:96 个 parasoleil 源产品中无 gradient 命名品,16 品名称也不在 parasoleil 图案谱系(apiary/agave/allure/…),无对位真值可借。

### 结论

**16/16 在源头(DSD)均不公示镂空率,数值无法从公开源恢复——按业主裁定回落为业主否决点**:16 品的 Open area 由业主定值(或从 CAD 图纸实测),`"Project set"` 占位语义在定值前保持。

---

## ② 材质数据清洗:系列级清单全站适用,产品级字段可弃用

数据源:`data/shopify-admin-export/products.json`(exportedAt 2026-10-24T01:00:51+08:00,146 品)与 `data/shopify-admin-export/collections.json`(9 系列)。

### 事实

1. **产品级** `custom.material_options`:仅 49/146 品有(48 dsd + 1 parasoleil)。值分布:
   - 46 品:`["Corten (lyten rusting steel)","Aluminium (powder coated)","Stainless steel","Mild steel","Copper"]` — 与 DSD 产品页文案逐字一致(本次 winderoo/tree-of-life 原始 HTML 复核,五项清单原文在列),系当初从 DSD 描述拷贝;
   - 2 品:`["Corten",...]`(Corten 缺 "lyten rusting steel" 注记):**herringbone**(herringbone-architectural-metal-screen-pattern)、**the-vlahos**(the-vlahos-architectural-metal-screen-pattern);
   - 1 品:垃圾值 `["architectural metal panel systems","custom fabricated screen panels"]`:**apiary**(apiary-architectural-metal-screen-pattern,parasoleil 源,疑似 SEO 残留文本)。
2. **系列级** `custom.pattern_materials`:9 系列全部同串 `"Corten, Aluminium, Stainless Steel, Mild Steel, Copper"`(multi_line_text_field)。
3. **覆盖面**:146 品每品恰好一个 `pattern-collection:*` 标签(无漏挂、无重复挂);9 系列产品数 90+4+15+0+8+8+16+1+4 = 146。系列级清单在数据上覆盖全站,**产品级字段只覆盖 1/3**。

### 结论

- **系列级五材质清单全站适用,产品级 `material_options` 字段可弃用**(迁移时不再迁移该字段;它本就是 DSD 文案拷贝的冗余,覆盖不全且 3 处脏值)。3 个不同串产品的处置随此结论消失于迁移:**不需要改齐**——apiary 的垃圾值与 herringbone/the-vlahos 的短串都不再被引用。
- **规格条常量 "304 / 316 stainless" 需在迁移时替换为五材质短形式**(Corten / Aluminium / Stainless Steel / Mild Steel / Copper;304/316 降为 Stainless 的牌号注记)。依据:业主 2026-10-24 口径裁定("只有不锈钢"是旧站错误)+ 9 系列 metafield 已是五材质串、覆盖 146 品全站。

---

## ③ gradient 系列:三处皆空,但有 3 个真品 → 按"有真品就归位"留并归位

| 检查点 | 结果 | 来源 |
|--------|------|------|
| 线上 `https://metalpartition.com/collections/pattern-gradient` | HTTP 200,标题 "Gradient Patterns \| Metal Divider",**0 个产品**(页面无任何 `/products/` 链接;页面出现的 "16 items" 是 Tailwind 类名 `h-16 w-16 items-center` 误报),无 301 | curl -L 直查,2026-10-24 |
| 本地公开导出 `data/shopify-public/collections.json` | `product_count: 0`,`members: []`,metafields 未渲染 | 2026-10-24 导出 |
| 本地 admin 导出 `data/shopify-admin-export/collections.json` | `productsCount: 0 (EXACT)`;但 metafields 齐备:family=Gradient、pattern_materials=五材质串、difficulty_level=Complex、typical_applications、bottom FAQ | 2026-10-24 导出 |
| 3 个 gradient 命名真品 | la-pente-gradient、nara-gradient、skyfall-gradient 均为真实 DSD 源产品,现挂在 `pattern-geometric` 系列 | products.json tags |
| parasoleil 有无 gradient 分类 | **无**。parasoleil.com/patterns/ 全量 HTML 中 79 处 "gradient" 全部是筛选器图标文件名 `/images/gradient-panel.svg`,大写 "Gradient" 分类零出现 | Apify 抓取 2026-10-24,https://www.parasoleil.com/patterns/ |

**结论:留,并把 3 个真品归位。** gradient 不是 parasoleil 谱系,是本店自建系列;metafields(难度 Complex、应用场景、FAQ)已配好,只缺成员。按票面裁定规则"有真品就归位",将 la-pente-gradient / nara-gradient / skyfall-gradient 三品的 `pattern-collection` 从 `pattern-geometric` 改挂 `pattern-gradient` 即成真系列(geometric 15→12,gradient 0→3);不做 301 砍除。归位动作属数据写入,留待实施票,本票只定结论。

---

## ④ Hostinger 数据中心:德国法兰克福(既有订阅,de-linker 同款)

| 证据 | 结果 |
|------|------|
| de-linker 生产入口 `ssh hostinger-de-linker` → HostName `46.202.156.7`(`~/.ssh/config`;AGENTS.md 载明生产写入走此通道、四站共用订阅) | — |
| RIPE whois `46.202.156.7` | netname **HOSTINGER-HOSTING**,country **DE**,org ORG-HIL18-RIPE / AS47583 Hostinger International Limited |
| ipinfo.io `46.202.156.7` | **Frankfurt am Main, Hesse, DE** |
| Hostinger 官方文档 [Where are Hostinger servers located](https://www.hostinger.com/support/1583267-where-are-hostinger-servers-located) | Germany(法兰克福)是 Web/Cloud 主机欧洲可选机房之一;法兰克福为其第五座欧洲机房([roadmap 公告](https://roadmap.hostinger.com/c/335-a-new-data-center-location-in-germany)),设施在 Digital Realty(案例研究) |

**隐私页填空用:现有订阅(de-linker 所在,四站共用)机房 = 德国法兰克福(Frankfurt am Main, DE,欧盟境内)。** 仓库 a158baf 号裁定 metal-divider 生产/暂存作为同订阅附加站点上线,故新站沿用该机房。

---

## 附:证据文件

- `/tmp/apify_16_out.json` — 16 品批次抓取数据集(run-sync 原始输出)
- `/tmp/apify_ath_raw.json` / `/tmp/apify_3raw.json` — atherton 与 winderoo/tree-of-life/papyrus 免变换原始 HTML
- `/tmp/apify_alldesigns.json` — DSD all-designs 聚合页(252KB)
- `/tmp/apify_para_patterns.json` — parasoleil.com/patterns/ 全量 HTML
- `/tmp/gradient_live.html` — 线上 gradient collection 页(curl 原样)
- `/tmp/apify_wcc_out.json` — bamboo 对照验证页
