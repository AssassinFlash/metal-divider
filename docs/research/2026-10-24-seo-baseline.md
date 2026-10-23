# 现站 SEO 底账与 301 映射规则(票 #4)

日期:2026-10-24 · 执行:Claude(SEO 底账票)· 分支:`research/seo-baseline`
配套数据:`data/url-status-2026-10-24.csv`(全量 385 条实测底账)、`data/redirect-map.csv`(385 条映射,入库交付物)

## 1. 底账方法与礼貌约束

- URL 全集取自 https://metalpartition.com/sitemap.xml 及其 11 个子 sitemap(2026-10-24 抓取),另补 9 条:sitemap 之外的 3 条 Shopify 内部 301 源、`/collections/all`、5 条 `/policies/*`。
- 实测:GET 首跳状态(不跟随重定向,单独记录 Location),并发 ≤5,超时 10s,UA 含 `metal-divider-rebuild-audit/1.0` 审计标识。共 385 请求;1 条超时(`zh/products/papyrus-…`)复测得 200。只访问 metalpartition.com,未用第三方 SEO 工具。
- 两阶段实测(前一会话被中断后续跑),结果已合并,底账见 CSV。

## 2. 两域名收录面清点

### 2.1 sitemap 结构(实测)

| 子 sitemap | 条数 | 说明 |
|---|---|---|
| sitemap_products_1(EN) | 147 | = 主页 `/` + 146 产品 |
| sitemap_pages_1(EN) | 24 | 含 8 张合规页、thank-you、旧 landing |
| sitemap_collections_1(EN) | 9 | 全部 `pattern-*` |
| sitemap_blogs_1 / articles_1(EN) | 3 + 5 | 3 个博客根 + 5 篇 test 占位文 |
| zh/ 镜像 5 份 | 147+24+9+3+5 | 与 EN 同构(`/zh` 条目代替主页) |
| sitemap_agentic_discovery | 1 | 仅 `https://metalpartition.com/agents.md` |

去重后唯一 URL 376(EN 188 / ZH 187,主页与 `/zh` 各一条)。

值得注意的 sitemap 细节:

- 子 sitemap URL 带 query 参数(`sitemap_products_1.xml?from=…&to=…`),Shopify 分片惯例;新站(WP 原生 `wp-sitemap.xml` 或 SEOPress)不会复用这些 URL,无需对应。
- 子 sitemap 内 **无 `xhtml:link` hreflang 条目**(三个抽样文件计数为 0);语言信号完全靠页面 `<head>` 输出(见 §4)。
- 产品子 sitemap 内含 `<image:image>` 扩展;新站 sitemap 若用 SEOPress 需确认图片扩展开启。

### 2.2 全量实测结果(385 条)

| 首跳状态 | 条数 | 明细 |
|---|---|---|
| 200 | 378 | 全部产品(146×2)、类目(9×2)、页面(24×2)、主页、/collections/all、policies/privacy-policy、博客全部 |
| 301 | 3 | `/pages/mashrabiya-facade-screening → /pages/laser-cut-geometric-metal-screens`;`/pages/interior-room-partitions → /pages/glass-hybrid-screens`;`/pages/custom-metal-divider-screens-quote → /pages/custom-metal-divider-screens`(与 shopify-builder 仓库 pages-manifest `redirects` 记录、Shopify Admin urlRedirects 2026-07-11 核验一致) |
| 404 | 4 | `/policies/refund-policy`、`/policies/shipping-policy`、`/policies/terms-of-service`、`/policies/contact-information`(商家从未填写这些政策页) |
| 超时 | 0 | 1 条复测后 200 |

结论:收录面干净、无软 404、无意外重定向;sitemap 与实活页一一对应。全量逐条状态见 `data/url-status-2026-10-24.csv`。

### 2.3 页面清单与 manifest 对账

sitemap 24 张 pages = 页面清单 17 张活动页(见 shopify-builder `pages-manifest.json`,已核对一致)+ 8 张 Consentmo 合规页(不迁移,业主裁定 2026-10-24)+ 旧 landing `/pages/metal-divider` + 表单致谢页 `custom-metal-divider-screens-thank-you`。

## 3. 旧 → 新映射规则(核心决定)

通式:源一律为 `https://metalpartition.com/<path>`,目标 `https://metal-divider.com/<path'>`,全站单跳 301,由 Cloudflare(免费档)在边缘执行,不落 WordPress。

| # | 规则 | 源 → 目标 | 行数(EN+ZH) |
|---|---|---|---|
| R1 | 主页 | `/` → `/`;`/zh` → `/zh` | 2 |
| R2 | 产品,slug=handle | `/products/<handle>` → `/products/<handle>`(zh 镜像同构) | 292 |
| R3 | 类目,slug=handle | `/collections/<handle>` → `/collections/<handle>` | 18 |
| R4 | 类目索引 | `/collections/all` → `/shop/`(Woo 商店档案页) | 1 |
| R5 | 页面,slug 保留 | `/pages/<handle>` → `/pages/<handle>` | 34 |
| R6 | 旧 landing | `/pages/metal-divider` → 首页(新站即品牌站) | 2 |
| R7 | 合规 8 页(Consentmo 残留,不迁移) | → `/privacy/`(统一隐私页;**最终 slug PENDING**,随隐私票定) | 16 |
| R8 | Shopify 原生隐私页 | `/policies/privacy-policy` → `/privacy/` | 1 |
| R9 | 博客(IA 另票重做) | **PENDING**(3 博客根 + 5 test 文 ×2 语) | 16 |
| R10 | 表单致谢页 | **PENDING**(随询盘链票) | 2 |
| R11 | 预存 301 源直映 | 3 条 Shopify 内部 301 的源路径直接映射到**最终**目标,避免二级链 | 3 |
| R12 | 旧站已 404 的 policies | 不设 301,维持 404 | 4(不设) |

附带裁定:

- **参数剥离**:一切 query(`utm_*`、`variant`、`ls`、`oseid`、`preview_theme_id` 等)301 到净 URL,单条通配规则即可;旧站 robots.txt 的参数陷阱清单(§6)佐证这些参数确实在外部流传。
- **不迁移面**:无账户/无结账/无支付(业主裁定),`/cart`、`/checkout`、`/account`、`/search` 等交易与搜索路径不做映射,301 到首页或保持 404 均可,建议 301 到 `/`(保留权重入口)。
- 逐条 385 行映射见 `data/redirect-map.csv`(PENDING 18 行:博客 16 + 致谢页 2)。

## 4. WordPress/WooCommerce 保留旧路径的可行性(结论:可行)

一手来源两条,均已抓全文存档:

1. **`/products/<slug>` 可行,且是一等选项**。WooCommerce 官方文档 [Permalinks](https://woocommerce.com/document/permalinks/):Settings → Permalinks → Product permalinks 提供 Default(`/product/<slug>`)、Shop base、Shop base with category、**Custom base** 四选;Custom base 填 `products` 即得 `metal-divider.com/products/<slug>`。**不要**在 base 里掺 `%product_cat%`——开发者公告 [Product Permalink Changes Coming in WooCommerce 10.5](https://developer.woocommerce.com/2026/01/13/product-permalink-changes-coming-in-woocommerce-10-5/)(2026-01-13)明确:10.5 起多类目产品的类目选取逻辑变更(改选最深类目),**仅影响含 `%product_cat%` 的结构**;扁平 custom base 不受影响。
2. **`/collections/<slug>` 可行,同为原生选项**。同一文档 Optional 区:**Product category base** 默认 `product-category`,改为 `collections` 即得 `/collections/<term-slug>`。Shopify 的 9 个 collection handle 与 Woo `product_cat` slug 一一对应(建库时 slug=handle)。

冲突面(官方警示 + WP rewrite 通识):

- 文档原文:custom base 与 taxonomy base **必须互异**("if you set the product base to shop, do not also set the product category base to shop")。本方案 `products` vs `collections` 互异,满足。
- Woo 商店页(Page)slug **不得**叫 `collections`(会撞类目档案);定为 `/shop/`,故 R4 把 `/collections/all` 映到 `/shop/`。同理不要建 `/products/` 页面。
- 旧站 `/collections/` 裸路径是 Pattern Series Hub(见 manifest)。WP 里可用 slug=`collections` 的普通页面承载(精确匹配优先于类目档案的通配 rewrite),但 **rewrite 优先级须在评审站实测**;若冲突,Hub 改用 `/patterns/` 并把 `/collections/` 301 过去。已记入检查清单。
- 官方告诫:改 permalink 会使存量 URL 失效,**必须配 redirects**——即本 CSV 底账存在的意义。
- `/pages/<slug>`:WP 页面层级(父页 slug=`pages`)可原样产出 `/pages/<slug>/`(尾斜杠差异由 WP canonical 重定向吸收,单跳)。父页 `pages` 本身做成 301 到首页的占位,不产内容页。

## 5. canonical 与 hreflang 策略草案

现状实测(旧站):每个页面 `<head>` 输出自指 canonical(绝对 URL)+ hreflang 三元组:`x-default`→EN、`en`→EN、`zh-Hans`→`/zh/` 路径;`<html lang>` EN=`en`、zh 页=`zh-CN`。

新站策略:

1. **双域名并存期**:新站在评审站/预发阶段整站 `noindex`(管线:本地 → 评审站 → 线上,评审环境绝不可索引);此时旧站 canonical 不动。
2. **切换日**:metalpartition.com 全站 301 → metal-divider.com 对应 URL(单跳、保留路径、剥参数)。301 本身就是最强的规范化信号,**新站页 canonical 一律自指 metal-divider.com 绝对 URL**,不写跨域 canonical。
3. **hreflang**:EN 为基座驻根(`https://metal-divider.com/<path>`),`x-default` 指 EN;`zh-Hans` 指 `/zh/<path>`;首发共 9 语种(EN、ZH、ES、PT-BR、DE、FR、AR、ID、VI,业主裁定),由 Polylang 自动输出,语组用 `zh-Hans`/`pt-BR` 而非裸 `zh`/`pt`(与旧站现用值一致);全部 alternate 互指(reciprocal),head 输出为准,不在 sitemap 里重复。AR 注意 `dir="rtl"`。
4. **zh 半壳风险(旧站实锤)**:`/zh/products/*` 实测 `<title>` 为英文、主体内容为英文,但 canonical 自指 zh URL 且 hreflang 声明 `zh-Hans`(样本页 CJK 字符 626,集中在导航/页脚壳,EN 版仅 419——即壳翻译、主体未翻)。三重矛盾 = 对 Google 是同一内容双 URL 的重复内容风险,且 zh-Hans 声明失真。**处置:旧站 zh 不抢救、不抢救内容也不改标签**;新站 zh 起步即完整翻译(9 语种同此标准);旧 zh URL 按镜像规则 301 到新 `/zh/` 对应页,权重与索引随 301 转移后由新页的正经中文内容接住。
5. 切换后 metalpartition.com 域名**长期持有**并保持 301(不释出、不让证书过期),这是域名并入的常规要求。

## 6. 旧站 robots.txt 与 sitemap 特别条目(实测全文已核)

- **robots.txt 为 Shopify 平台生成**,要点:全局 `Allow: /`;交易面禁爬(`/cart`、`/checkout`、`/orders`、`/account`);参数陷阱禁爬(`sort_by`、`+`/`%2B` 组合、多 `filter`、`ls`、`oseid`、`preview_theme_id`);`Disallow: /services`、`/sf_*`、`/771747023337`(店铺 ID 路径);adsbot-google 单独一段;末行 `Sitemap: https://metalpartition.com/sitemap.xml`。
- **代理面(Shopify 新能力)**:robots.txt 头部注释与 `sitemap_agentic_discovery.xml`(单条:`/agents.md`)指向 `/agents.md`——Shopify 的 AI 代理面,内容为 Shop skill 推荐、UCP(`/.well-known/ucp` + `/api/ucp/mcp` MCP 端点)、WebMCP 结账工具、Web Bot Auth 代理识别要求、只读浏览指引。
- **新站要不要对应物(建议)**:
  - UCP/MCP、Shop skill、WebMCP 结账:**不建**。这些是 Shopify 平台原生能力,WP/Woo 无等价实现,且新站无账户无结账,链路只有询盘;自造半吊子 MCP 反而误导代理。
  - `agents.md`:**建议建一张静态的**(成本极低):声明本站只读浏览 + 询盘表单/WhatsApp 入口 + projects@metalpartition.com,明示无交易能力、无代理 API,引导代理走页面与邮件。不进 sitemap、不加 `sitemap_agentic_discovery` 对应物(那是 Shopify 私有约定,WP 生态无此消费者);`llms.txt` 同理,可选、非必需,随内容票再定。
  - 新站 robots.txt 由 WP 生成,需自写的关键差异:禁爬询盘表单预览/参数化 URL、`/wp-admin/`(留 `/wp-admin/admin-ajax.php` allow)、搜索结果页 `/?s=`;Sitemap 行指向新 sitemap。**不要**把 Shopify 的参数陷阱清单原样搬(那是 Shopify URL 形态),按 WP 的参数面重写。

## 7. SEO 连续性检查清单(切换日前后)

前置(新站上线前):

- [ ] 评审站/预发整站 noindex;上线同日撤除并逐模板抽检 meta robots
- [ ] `data/redirect-map.csv` 中 PENDING 18 行(博客 16、致谢页 2)在对应票落地后回填,再导入 Cloudflare 批量重定向
- [ ] `/collections/` 裸路径 rewrite 优先级实测(page vs product_cat archive),冲突则启用 `/patterns/` 备选
- [ ] Woo permalink 设置落地:custom base=`products`、product category base=`collections`、商店页 slug=`shop`,保存后抽测 10 个产品/类目 URL 无 404
- [ ] 全部 146 产品与 9 类目 slug=旧 handle 建库核对(CSV 可直接当对账单)

切换日:

- [ ] metalpartition.com DNS/CDN 切到 301 全量(逐规则抽测:产品、类目、page、zh 镜像、带参数 URL、legacy 3 条源路径、404 policies 保持 404)
- [ ] metal-divider.com 新 sitemap 提交;robots.txt Sitemap 行生效
- [ ] GSC:metalpartition.com 与 metal-divider.com 均以 **URL 前缀属性**验证(GSC 的地址变更工具不支持域名属性;两个属性都要在);用地址变更工具把 metalpartition.com 指向 metal-divider.com;度量工具本身不在本工程内,但站长侧验证随 SEO 连续性保留
- [ ] 旧 GSC 属性导出覆盖率/索引报告存档作基线

切换后 30 天:

- [ ] GSC 新属性收录量、旧属性索引衰减曲线;旧域名 301 抽测仍全绿
- [ ] 重点 URL(`/`、9 类目、头部产品)在 Google 索引里 canonical 已指向新域
- [ ] 404 监控里无本应 301 的旧路径

## 8. 来源

- 实测:metalpartition.com sitemap.xml 及 11 子 sitemap、robots.txt、agents.md、sitemap_agentic_discovery.xml、EN/zh 产品页 head 与正文样本(2026-10-24,UA 带审计标识;385 条状态底账见 `data/url-status-2026-10-24.csv`)
- WooCommerce 官方文档:https://woocommerce.com/document/permalinks/(全文抓取存档)
- WooCommerce 开发者公告:https://developer.woocommerce.com/2026/01/13/product-permalink-changes-coming-in-woocommerce-10-5/(全文抓取存档)
- 页面清单与预存 301:shopify-builder `.claude/page-director/pages-manifest.json`(2026-07-11 经 Shopify Admin urlRedirects 核验)
- 业主裁定:本仓库 AGENTS.md §1(2026-10-24:8 合规页不迁移、博客 IA 另票、9 语种、无账户无结账)
