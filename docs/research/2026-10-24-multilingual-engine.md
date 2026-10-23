# 多语言引擎选型:Polylang 系 × WPML × TranslatePress(9 语种 + WooCommerce 图案目录)

- 票:#3(Research)
- 日期:2026-10-24
- 状态:供协调者裁定;本文只给推荐与证据,不做最终决定
- 价格核实日:2026-10-24(官网现价,均为未税价 ex VAT)
- 汇率:1 € = 1.1192 USD(Xe 中间价,2026-10-24 09:07 UTC;Wise 同日报 1.120;来源见 §6)

## 0. 结论速览

**推荐:Polylang Business Pack(= Polylang Pro + Polylang for WooCommerce 打包,€139/年 未税,约 $13.0/月;续费 50% 折扣后约 €70/年 ≈ $6.5/月)。**

理由骨架:
1. 程序化写入 API 是全家最贴合自研 deepseek 管线的:`pll_set_post_language` / `pll_save_post_translations` / `pll_set_term_language` / `pll_save_term_translations` 均为官方文档化函数,且已在 de-linker 生产环境用同一套管线(EN/DE)验证过;
2. WooCommerce 产品/属性/页面翻译与同步官方归属 Woo 附加组件,Business Pack 是拿到它的最便宜路径;
3. hreflang 由 Polylang 输出、SEOPress 出每语言 meta/sitemap,双方官方文档均确认分工且无冲突;
4. 预算内:首年 $13.0/月 < $20/月上限,续年更低。

次选:WPML Multilingual CMS(€99/年,同样预算内,含 WooCommerce 与 ACF 支持、钩子 API 同样文档化)——落选原因见 §7。

明确淘汰:Polylang 免费版单打(WooCommerce 无官方兼容承诺)、TranslatePress(免费版仅 2 语言,9 语种必须 Pro,且前端字典模型与「WP-CLI 写库」管线不匹配,见 §4.4)。

## 1. 前提(既定,不重新论证)

- WordPress + WooCommerce,146 个"产品"=图案,无结账无价格($0 展示),无账户;
- ACF Pro 承载规格字段(镂空率、风格、适用场景等);
- 首发语种 EN 基座 + ZH / ES / PT-BR / DE / FR / AR / ID / VI;加语言边际成本 = 配置 + 翻译;
- 机器翻译:自研 WP-CLI 脚本调 deepseek-flash(官方 api.deepseek.com)写库,不走插件自带翻译集成;
- 页面层 LiveCanvas 动态模板 + WindPress;SEO 用 SEOPress Pro;缓存 LiteSpeed + Redis + Cloudflare 免费档;CookieYes 同意横幅;
- 订阅预算上限 $20/月,免费优先;
- 参考实现:de-linker 仓库(本机 `~/Documents/de-linker`)的 `plugins/linker-i18n`,Polylang 免费版 + 自研窄插件,EN/DE 已上线。

## 2. 候选与当前版本(2026-10-24 核实)

| 候选 | 当前版本 | 来源 |
|---|---|---|
| Polylang(免费) | 3.9.1(2026-10-21 更新,900,000+ 活跃安装,要求 WP 6.8+ / PHP 7.4+) | wordpress.org/plugins/polylang/(2026-10-24) |
| Polylang Pro | 3.9.1(2026-10-21,与免费版同轨;3.9 起支持 WP-CLI 管理语言与 fallback、Business Pack 单一许可证键) | polylang.pro/polylang-pro-changelog/(2026-10-24,经 Apify) |
| Polylang for WooCommerce | 2.2.7(2026-10-12,持续跟进 WooCommerce 11.x) | polylang.pro/polylang-for-woocommerce-changelog/(2026-10-24,经 Apify) |
| WPML Multilingual CMS | 4.9.1(2026-02 changelog) | wpml.org/category/changelog/(2026-10-24) |
| TranslatePress | 3.3.8(约 1 周前更新,400,000+ 活跃安装,测试至 WP 7.1.3) | fi.wordpress.org/plugins/translatepress-multilingual/(2026-10-24,经 Apify) |

## 3. 对比表

| 维度 | Polylang 免费 | Polylang Pro | + Polylang for WooCommerce(Business Pack €139/年) | WPML CMS(€99/年) | TranslatePress(免费 / Pro €99+/年) |
|---|---|---|---|---|---|
| ① Woo 产品/属性(term)/slug | ❌ 无 Woo 兼容承诺(官方支持论坛:核心不含 Woo 兼容) | 产品可译但无属性同步/页面翻译 | ✅ 产品、分类、标签、全局属性、变体、Woo 页面、邮件;slug 经"URL slugs"组翻译(需 Pro) | ✅ WCML 内含;产品、属性、变体、结账全链 | ⚠️ 产品作为前端字符串可译;Woo slug 在 SEO Pack(Pro);无按语言独立产品对象 |
| ② ACF 字段值翻译 | ❌ 无官方集成(只能自写 per-post 字段) | ✅ Pro 内置(3.7 重写:Ignore/Copy Once/Translate/Translate Once/Synchronize) | 同 Pro | ✅ ACFML 内含(5.0) | ⚠️ 字段值按渲染字符串翻译(字典表),非字段级 |
| ③ URL 结构 / hreflang | ✅ 子目录 /zh/(免费即有);hreflang 免费版即自动输出 | + 译 slug(不同语言不同 slug)+ 内容复制/同步 | 同 Pro;含 Woo URL 基座翻译 | ✅ 目录格式;hreflang 自动;slug 可译 | ✅ 子目录模型;hreflang 需 SEO Pack(Pro);免费版仅 2 语言 |
| ④ 程序化写库 API | ✅ pll_* 官方函数,已在本项目前置仓库验证 | 同 + pll_insert_post/term(3.7+) | 同 | ✅ wpml_set_element_language_details / wpml_get_element_translations(trid 模型,文档化) | ⚠️ 无"写入译文"的官方函数 API;译文存字典表,程序化批量写入非文档化路径 |
| ⑤ LiteSpeed / Cloudflare / CookieYes | ✅ 子目录=天然独立缓存键;坑见 §5 | 同 | 同 | ✅ LiteSpeed 官方兼容表 Confirmed(每语言独立缓存副本) | ⚠️ 同 URL 缓存;按语言整页缓存依赖缓存层 vary |
| ⑥ 价格(未税/年,2026-10-24) | €0 | €99(续费 50% off) | 打包 €139(原 €198;续费 50% off) | €99(Blog €39 / Agency €199) | €99(Personal,1 站)/ €199 / €349 |

## 4. 分维度证据

### 4.1 WooCommerce 产品、属性、slug

- **Woo 兼容不随免费版/裸 Pro 提供**:Polylang 官方产品页与安装指南均把产品/页面/邮件翻译归给附加组件 "Polylang for WooCommerce";安装流程为「装 Polylang(或 Pro)→ 再装 polylang-wc.zip」。来源:polylang.pro/pricing/polylang-for-woocommerce/、polylang.pro/documentation/support/guides/polylang-wc-installation/(2026-10-24)。wordpress.org 支持论坛(官方回复,旁证):"There isn't any compatibility in Polylang for the WooCommerce plugin"。来源:wordpress.org/support/topic/broken-product-translation-with-woocommerce-multilingual/(2026-10-24)。
- **附加组件覆盖**:产品(simple/variable/grouped)、产品分类/标签、全局属性(含 term)在 Woo 界面内翻译;SKU、价格、税、重量尺寸、库存(含变体)、up-sell/cross-sell 自动同步;一键复制产品及其全部翻译;HPOS ready;Shop/Cart/Checkout/My Account 页面与邮件翻译。来源:polylang.pro/documentation/support/guides/managing-products/(2026-10-24,页面标注 "Polylang for WooCommerce")。
- **属性 slug 翻译要求 Pro**:「attribute slug … available for translation only if you are using Polylang Pro and you enabled the 'Enable Archives?' option」(同上指南)——Business Pack 内含 Pro,满足。
- **自定义(局部)属性不可译,必须用全局属性**(同上指南 §2.1)。对本案无影响:规格走 ACF;若未来用 Woo 属性做筛选项,须建成全局属性。
- WPML 对照:Multilingual CMS 计划含 "E-Commerce support — Translate WooCommerce sites and popular extensions"(app.wpml.org/purchase,2026-10-24);WPML 官方对 SEOPress 的兼容页同样确认产品翻译链路(wpml.org/documentation/plugins-compatibility/optimize-wpml-multilingual-sites-for-search-engines-using-seopress/,2026-10-24)。

### 4.2 ACF 字段值翻译

- **Polylang Pro 内置 ACF Pro 集成**(免费版无):3.7 起整体重写,字段级五种行为 Ignore / Copy Once / Translate / Translate Once / Synchronize;字段组不再翻译,改用 Language location rule;Options 页需社区插件 "ACF Options for Polylang";ACF blocks 不支持 Synchronize。来源:polylang.pro/documentation/support/guides/working-with-acf-pro/(2026-10-24)。
- **对本项目的真实意义**:我们的译文是 deepseek 管线直接写进各语言 post 的 ACF 字段值(update_field 级别),插件 UI 的字段翻译面板只在人工补译时才用到——集成保证的是「翻译副本创建时字段结构/可同步性正确」,这个由 Pro 提供。
- WPML:ACF Multilingual(ACFML)含在账号内,ACFML 5.0 提供字段组级 Multilingual Setup;有独立钩子参考(wpml.org/documentation/support/acfml-hooks-reference/,2026-10-24)。
- TranslatePress:ACF 值只作为输出字符串进字典表;字段级语义(如镂空率数值 vs 需译文本)无法区分,除非逐串人工圈选。

### 4.3 URL 结构与 hreflang(与 SEOPress 的分工)

- **Polylang URL**:免费版即支持「子目录 /en/(默认)、子域名、独立域名、查询参数」;选项「Hide the language code in the URL only for the default language」可让 EN 基座走裸路径、其余 /zh/ /de/ 等。来源:polylang.pro/documentation/support/guides/url-modifications/(2026-10-24)。注意文档警示:停用 Polylang 后 URL 失去语言信息,外链全断。
- **Polylang hreflang**:自动输出,W3C locale(下划线转连字符,pt-BR 正确);x-default 默认只在自动重定向的首页出现,其他页面可经 `pll_rel_hreflang_attributes` 过滤器补(官方示例即此)。来源:polylang.pro/hreflang-tag-attributes-and-polylang-everything-you-need-to-know/(2026-10-24)。
- **与 SEOPress 分工(官方双向确认)**:
  - SEOPress 官方指南《SEOPress + Polylang: The Complete Guide to Multilingual SEO》:"SEOPress integrates seamlessly with Polylang",SEOPress 负责每语言 SEO metadata、XML sitemap、全局设置;hreflang 由 Polylang 实现。来源:seopress.org/support/guides/seopress-polylang-multilingual-seo-guide/(2026-10-24,Apify 取回)。
  - SEOPress 官方指南《Translate SEOPress options with WPML / Polylang》:SEOPress 选项可按语言翻译。来源:seopress.org/support/guides/translate-seopress-options-with-wpml-polylang/(2026-10-24)。
  - WPML 侧官方兼容页:"WPML also creates hreflang tags… There are no unresolved compatibility issues between this plugin and WPML"(针对 SEOPress)。来源:wpml.org/documentation/plugins-compatibility/optimize-wpml-multilingual-sites-for-search-engines-using-seopress/(2026-10-24,Apify 取回)。
  - **结论:hreflang/schema 分工不打架**——无论选 Polylang 还是 WPML,hreflang 都由多语言插件输出,SEOPress Pro 不重复输出(其 hreflang 能力仅以"教育文章+集成说明"形式存在,非自有开关);de-linker 的 Seo.php 还演示了在 Polylang hreflang 之上补 x-default 并排除搜索页的窄插件做法(本地 ~/Documents/de-linker/plugins/linker-i18n/includes/Seo.php)。
- TranslatePress 的 hreflang/多语言 sitemap/译 slug 都在 **SEO Pack 附加组件(仅付费档)**;其多语言 sitemap 官方明确列出支持 SEOPress。来源:translatepress.com/docs/addons/seo-pack/(2026-10-24)。

### 4.4 程序化写入翻译的 API(deepseek 管线核心)

- **Polylang:全部文档化,且已被本管线验证**。官方函数参考(2026-10-23 更新)列明:`pll_set_post_language( $post_id, $lang )`、`pll_set_term_language`、`pll_get_post_language`、`pll_get_term_language`、`pll_save_post_translations( $arr )`、`pll_save_term_translations`、`pll_get_post`、`pll_get_term`、`pll_languages_list`、`pll_current_language`、`pll_is_translated_post_type`、`pll_is_translated_taxonomy`、`pll_register_string`/`pll__`/`pll_translate_string`;3.7 起 `pll_insert_post / pll_update_post / pll_insert_term / pll_update_term`(带 translations 参数的 wp_insert_post 包装)。另有 Filter reference、REST API、Polylang CLI、wpml-config.xml 与 WPML API 兼容层。来源:polylang.pro/documentation/support/developers/function-reference/(2026-10-24)。文档要求调用前 `function_exists()` 检查。
  - **已验证实现**(本地证据):de-linker `linker-i18n` 用 `pll_set_post_language`/`pll_save_post_translations`/`pll_set_term_language`/`pll_save_term_translations` 写库,`pll_get_post_types`/`pll_get_taxonomies` 过滤器注册自定义类型,`pll_rel_hreflang_attributes`/`pll_translation_url`/`pll_check_canonical_url` 过滤器接线——EN/DE 双语线上运行。来源:~/Documents/de-linker/plugins/linker-i18n/includes/(Languages.php、Plugin.php、Seo.php)。
  - **绕开插件翻译集成 = 直接不碰它的 DeepL/XLIFF 面板,只用上述函数写 post/term 及其 meta**。官方 API 面十年稳定(函数签名自 2.x 未变,3.7 只增不删),版本风险低。
- **WPML:同样文档化但走 trid 模型**:`do_action('wpml_set_element_language_details', args)`(3.2.7+)设语言与翻译组,`apply_filters('wpml_get_element_translations', NULL, $trid, $element_type)`(3.3.5+)取译文,`wpml_element_language_details` 取源语言信息;element_type 形如 `post_product`。来源:wpml.org/wpml-hook/wpml_set_element_language_details/、wpml.org/wpml-hook/wpml_get_element_translations/、wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/(2026-10-24)。可行性同级,但 trid 间接层比 pll_* 的「post_id+lang slug」多一跳,且 de-linker 代码无法复用。
- **TranslatePress:模型不匹配**。官方开发者文档列明其自建表:`wp_trp_dictionary*`(人工/自动译文字符串字典)、`wp_trp_gettext*`、`wp_trp_original_strings`(默认语言原文)、`wp_trp_slug_o*`(slug)等——原文→译文按字符串全局配对,不存在「每语言一个产品对象」。来源:translatepress.com/docs/developers/delete-all-translations-and-plugin-data/(2026-10-24,经 Apify)。官方开发者目录只有 URL 转换器(`get_url_for_language`)等前端工具,没有「批量写入译文」的文档化函数。146 产品 × 8 译语 × 多字段的结构化写入(ACF 字段、产品标题、excerpt、slug)在字典模型下退化为字符串去重的散装写入,且回读校验、逐字段失败重试都失去 post 级锚点。免费版还硬限 2 语言(官方 free-vs-pro 文,2026-10-24);现版本 3.3.8(2026-10-24,wordpress.org,经 Apify)。

### 4.5 版本与价格(官网核实,2026-10-24)

| 产品 | 首年价(未税) | 续费 | 月均(首年,按 1 EUR=1.12 USD) | 备注 |
|---|---|---|---|---|
| Polylang 免费 | €0 | — | $0 | wordpress.org |
| Polylang Pro | €99/年(1 站起) | 50% off | ≈ $9.2 | 1 年更新+支持;staging/localhost 不占名额;30 天退款 |
| Polylang for WooCommerce | €99/年 | 50% off | ≈ $9.2 | 附加组件,需搭配免费版或 Pro |
| **Polylang Business Pack(Pro+Woo)** | **€139/年**(标价划线 €198) | 50% off(≈€70/年) | **≈ $13.0(续年 ≈ $6.5)** | 本案推荐口径 |
| WPML Multilingual CMS | €99/年 | 官方口径约 25% off(2021 公告 $99/$74;现行购买页未列续费价) | ≈ $9.2 | Blog €39(无电商/字符串翻译);Agency €199;CMS 含 22,500 AI 词 |
| TranslatePress Personal | €99/年(€8.25/月) | 同价自动续 | ≈ $9.2 | 1 站,50K AI 词;Business €199(3 站+DeepL+语种检测);Developer €349 |

来源:polylang.pro/pricing/(2026-10-24);app.wpml.org/purchase(2026-10-24);wpml.org/announcements/2021/07/(续费价旁证);translatepress.com/pricing/ 与 translatepress.com/translatepress-free-vs-pro/(2026-10-24)。

**预算结论**:上限 $20/月 = $240/年。Business Pack 首年 €139 ≈ $156/年(月均 $13.0),续年 €70 ≈ $78/年(月均 $6.5),均在预算内。WPML CMS €99 ≈ $111/年也在预算内,价差不构成决定因素。

## 5. 缓存 / CDN / 同意横幅交互

- **LiteSpeed 官方兼容表(一手)**:WPML 在 "Confirmed Compatible" 区,注明「Separate cache copies are stored for each page for each language」;**Polylang 不在该名单任何区**(既非确认兼容也非不兼容);WooCommerce 本体在确认区(仅"最近浏览"小工具需开 ESI)。来源:docs.litespeedtech.com/lscache/lscwp/thirdparty/(2026-10-24,经 Apify)。对 Polylang 的实际含义:子目录 URL(/zh/、/de/)是不同 URL,页面缓存天然按 URL 分键,不需要 vary——de-linker 生产站即 LiteSpeed 主机跑 Polylang 免费版(本地证据:~/Documents/de-linker/docs/local-stack.md 及托管事故日志中的 LiteSpeed/LSAPI 记录),无语言缓存错乱工单。
- **唯一的已知坑是「浏览器语言检测」**:Polylang 首次访问首页按浏览器语言重定向并落 cookie,仅作用于首页;若首页被整页缓存,重定向会被缓存吞掉。来源:polylang.pro/documentation/support/guides/detect-the-browser-preferred-language/(2026-10-24)。对策(工程侧):保持浏览器语言检测关闭(默认即关),语言选择交给 hreflang 与切换器;或对 / 与各语言根做显式缓存键。
- **Cloudflare 免费档**:LiteSpeed 官方兼容表同时列 Cloudflare 为「Compatible if you are not using Cloudflare APO」(重复缓存冲突)——本案用 LiteSpeed 做页面缓存,Cloudflare 只做 DNS/CDN/防护,**不得开 APO**。来源:同上 thirdparty 页(2026-10-24)。子目录页按 URL 分键缓存;metalpartition.com 301 并入不受语言影响。
- **CookieYes(官方文档,经 Apify)**:横幅支持 41 种语言,横幅语言在 CookieYes 账号的 Languages 面板配置,可按地理位置定向投放(geo-targeting);**横幅语言不跟随 URL 语言**——/zh/ 页面上横幅语言由 CookieYes 自己的地理/浏览器逻辑决定,与 Polylang/WPML 无 API 耦合。来源:cookieyes.com/documentation/display-consent-banner-in-multiple-languages/(2024-04-29 更新)、/documentation/change-default-lang/、/blog/geo-targeting-banner/(均 2026-10-24 查)。工程含义:9 语种下横幅文案要在 CookieYes 内逐语言配置;「URL 语言=横幅语言」需另行评估(如按页面 lang 属性注入脚本配置),记入落地要点。
- TranslatePress 特别注意:整页输出即译文页,同样按 URL 缓存;但其译文发生在渲染层,任何缓存插件配置失误(如按 cookie vary 过宽)都会造成错语串页,排查成本高于"每语言独立 URL+独立 post"模型。

## 6. 汇率

1 EUR = 1.1192 USD(Xe 中间价,2026-10-24 09:07 UTC;Wise 同日报 1.120)。来源:xe.com/currencyconverter/convert/?Amount=1&From=EUR&To=USD、wise.com/gb/currency-converter/eur-to-usd-rate(经 Apify 取回)。全部月均/年均价按 1.12 折算,仅用于预算核对,不作为采购依据。

## 7. 推荐与淘汰理由

**推荐 Polylang Business Pack(Pro + Polylang for WooCommerce),€139/年未税(≈$13.0/月,续年 ≈$6.5/月)。**

1. **管线契合度最高(决定性)**:deepseek-flash WP-CLI 写库走 `pll_*` 官方函数,与 de-linker 已上线实现同构,linker-i18n 的窄插件模式(Twins/Setup/Seo 的 hreflang 与 canonical 接线)可平移,只多了 Woo 的 post type 与 taxonomy 注册。WPML 虽有同等文档化钩子,但 trid 模型需重写写入层,且丢掉已验证代码。
2. **Woo 目录场景需要官方兼容承诺**:产品、全局属性、Woo 页面(Shop/分类归档)翻译官方归属附加组件;裸 Polylang 对 Woo「无兼容」是官方口径。$0 价格目录(无库存、无结账、无邮件)虽然只用到附加组件的一小部分,但分类归档与产品循环的跨语言查询正确性正是这一小部分。
3. **ACF 集成要 Pro 档**:字段级 Translate/Synchronize 保证翻译副本的字段结构与同步语义,人工补译时可用。
4. **SEO 分工已证**:hreflang=Polylang,meta/sitemap=SEOPress Pro,双方官方文档互认。
5. **预算**:首年 $13.0/月,续年 $6.5/月,低于 $20/月上限;免费优先原则已用尽——免费档(Polylang 裸版)缺 Woo 兼容与 ACF 集成,省下 €139 的代价是自担 Woo 跨语言查询的无支持区,不符合「一上来就完美」的上线标准。

**淘汰理由**:
- **Polylang 免费版单独使用**:Woo 无兼容承诺 + 无 ACF 集成 + 无译 slug;de-linker 能用免费版是因为它是纯页面站,没有 Woo CPT/分类循环。
- **WPML Multilingual CMS**:能力全覆盖、价格相当,是合格的次选;落选只因管线复用与团队肌肉记忆在 Polylang 一侧,以及 WPML 的 String Translation 组件接管全站字符串(购买页明确列入 CMS 计划功能),运行时面更重。若未来 Polylang 侧出现不可修复的 Woo 缺陷,WPML 是明确的退路。
- **TranslatePress**:免费版 2 语言硬限;Pro 后仍是前端字典模型,与「结构化、可回读校验的 WP-CLI 写库」根本不匹配;hreflang/slug 还要再加 SEO Pack。不适合本案。

## 8. 落地要点(给后续票)

1. 语言配置:EN 默认并隐藏默认语言代码(裸 URL),其余 /zh/ /es/ /pt-br/ /de/ /fr/ /ar/ /id/ /vi/ 子目录;PT-BR 的 hreflang 由 Polylang 自动转 `pt-BR`。
2. 翻译管线移植(linker-i18n → 本仓库插件)范围票:写入序列 = pll_set_post_language → wp_update_post(译标题/slug/content)→ update_field(ACF 译值)→ pll_save_post_translations;term 同构。属性/分类先行:先译全局属性与分类,再译产品(官方指南要求,否则同步错乱)。
3. hreflang 增强:x-default 指 EN(照抄 de-linker Seo.php 模式),搜索页剔除。
4. 浏览器语言检测保持关闭;首页缓存键显式覆盖 / 与各语言根。
5. ACF 字段建档时即标 Translate(文本类)/ Synchronize(数值类如镂空率)。
6. Woo 页面(Shop/分类归档)由 Woo 附加组件向导生成各语言副本,再交管线译标题与内容。
7. 预算记账:Business Pack 首年 €139 + SEOPress Pro(另票),多语言引擎口径月均 $13.0。

## 9. 来源清单(全部 2026-10-24 查询;标注「经 Apify」者为 Apify REST API 取回)

一手(官方):
- https://polylang.pro/pricing/ — 定价与档位
- https://polylang.pro/pricing/polylang-for-woocommerce/ — 附加组件功能矩阵
- https://polylang.pro/polylang-pro-changelog/ — Pro 3.9.1(经 Apify)
- https://polylang.pro/polylang-for-woocommerce-changelog/ — Woo 附加组件 2.2.7(经 Apify)
- https://polylang.pro/documentation/support/developers/function-reference/ — pll_* 函数(页面 2026-10-23 更新)
- https://polylang.pro/documentation/support/guides/managing-products/ — Woo 产品/属性翻译(标注 Woo 附加组件)
- https://polylang.pro/documentation/support/guides/polylang-wc-installation/ — 附加组件安装关系
- https://polylang.pro/documentation/support/guides/url-modifications/ — URL 结构
- https://polylang.pro/documentation/support/guides/working-with-acf-pro/ — ACF 集成(Pro)
- https://polylang.pro/documentation/support/guides/detect-the-browser-preferred-language/ — 检测/cookie/缓存注意
- https://polylang.pro/hreflang-tag-attributes-and-polylang-everything-you-need-to-know/ — hreflang 与 x-default
- https://wordpress.org/plugins/polylang/ — 免费版版本与装机量
- https://docs.litespeedtech.com/lscache/lscwp/thirdparty/ — LiteSpeed 官方第三方兼容表(WPML 确认区;Cloudflare APO 限制)(经 Apify)
- https://app.wpml.org/purchase — WPML 现行定价
- https://wpml.org/wpml-hook/wpml_set_element_language_details/ 、…/wpml_get_element_translations/ 、…/wpml-coding-api/wpml-hooks-reference/ — WPML 钩子 API
- https://wpml.org/documentation/plugins-compatibility/optimize-wpml-multilingual-sites-for-search-engines-using-seopress/ — WPML×SEOPress 官方兼容(经 Apify)
- https://wpml.org/documentation/support/acfml-hooks-reference/ — ACFML 钩子
- https://seopress.org/support/guides/seopress-polylang-multilingual-seo-guide/ — SEOPress×Polylang 官方分工(经 Apify)
- https://seopress.org/support/guides/translate-seopress-options-with-wpml-polylang/ — SEOPress 选项按语言译(经 Apify)
- https://translatepress.com/pricing/ 、/translatepress-free-vs-pro/ 、/docs/addons/seo-pack/ 、/docs/settings/ 、/docs/developers/translating-an-internal-url/ — TranslatePress 定价/免费版限制/SEO Pack/设置
- https://translatepress.com/docs/developers/delete-all-translations-and-plugin-data/ — TranslatePress 自建表清单 wp_trp_*(经 Apify)
- https://fi.wordpress.org/plugins/translatepress-multilingual/ — TranslatePress 3.3.8 / 40 万安装(经 Apify)
- https://www.cookieyes.com/documentation/display-consent-banner-in-multiple-languages/ 、/documentation/change-default-lang/ 、/blog/geo-targeting-banner/ — CookieYes 语言机制(经 Apify)
- https://www.xe.com/currencyconverter/convert/?Amount=1&From=EUR&To=USD 、https://wise.com/gb/currency-converter/eur-to-usd-rate — 汇率(经 Apify)

旁证(第三方,仅辅助):
- wordpress.org/support/topic/broken-product-translation-with-woocommerce-multilingual/ — Polylang 核心不含 Woo 兼容的官方支持回复
- blog.litespeedtech.com/2018/11/05/litespeed-cache-and-wpml-now-compatible/ — LiteSpeed 多语言缓存机制
- wpml.org/announcements/2021/07/… — WPML 续费价历史口径

本地证据:
- ~/Documents/de-linker/plugins/linker-i18n/(includes/Languages.php、Plugin.php、Seo.php)— pll_* 写库与 hreflang 已验证实现
