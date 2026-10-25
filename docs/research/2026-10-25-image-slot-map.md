# 图片槽位映射核对:旧站 → 新站(票 #63)

状态:research 完成。**未改任何生产代码、模板或数据。**
核对日期:2026-10-25。样本:3 个 PDP(Apiary / Akira / Autumn Leaves,外加边界品 Pebble Creek)、首页 hero、8 个产品族页、系列归档(organic)、商店卡片(146)、系列卡(hub)。

---

## 0. 结论摘要

| # | 问题 | 严重度 | 影响面 |
|---|---|---|---|
| M1 | **PDP 三个图槽改成按位置取图,旧站是按 alt 角色取图**;hero-bg 取 featured(=旧 media[0],多为竖版图案渲染),plate 取 gallery[0](错位一格),square 取 gallery[1] | **S1 阻断** | 全部 146 个 PDP;30 品抽样 hero 错 28、plate 错 30、square 错 24 |
| M2 | **PDP `img` 的 width/height 是样例品(Akira)的字面值**,与逐品实际画幅无关;hero 常把 400×800 竖图铺满 2400×1351 的通栏 | **S1 阻断** | 146 PDP;抽样 28/30 的 hero 源图是竖版 |
| M3 | **5 个只有 2 张图的图案,`square` 槽渲染 `<img src="">`**(空 src,浏览器会请求页面 URL) | **S1 阻断** | 5 个 PDP(aztec / la-pente-gradient / naughts-x-plus / painted-daisy / pebble-creek) |
| M4 | **PDP 的 alt 是样例品的字面角色词**:每个产品的 plate 都写 “pattern detail reference”、square 都写 “official installation scene 3” | S2 高 | 146 PDP |
| M5 | **系列卡(hub)9 张卡的图片全部为空**:`hero_image` term meta 从未被写入 | S2 高 | 8 个旧有系列 + 新增 Gradient |
| M6 | **首页 hero 用了别的 section 的图**(`hero-hotel-lobby-gold`,即同页 “Interior Partitions” 卡的图),旧 hero 原图 `hotel-lobby-decorative-…` 从未导入 WP | S2 高 | 首页 hero |
| M7 | 商店页 / 系列归档的 **4 张 route 卡**与 **7 张 reference 卡**换成图案缩略图或别的场景图 | S3 中 | 商店 + 9 个系列归档,每页 11 张 |
| M8 | PDP 的 **wide(context)槽整个消失**(旧模板有条件渲染) | S3 低 | 抽样 1/30 命中(≈3%) |
| M9 | PDP 相关卡的**选品语义变了**(旧=首个 collection 的前 3 个兄弟品,确定性;新=`wc_get_related_products`,Woo 默认 shuffle + 日缓存) | S3 低 | 146 PDP |
| M10 | 仓库里 `shop.html`/`series-archive.html` 把样例品值写死在 `aria-label`/`alt` 上,而**线上渲染是逐品的** —— 仓库与线上模板内容不一致,下次 `md-site present` 会把线上打回“Agave / Timothy's Vine” | S3 需复核 | 商店 + 系列归档 |

**正确的部分(不要动)**:商店与系列归档的图案卡片缩略图正确(迁移规则 `featured = 旧 media[0]` 与旧站卡片 `product.featured_image` 逐字同源,实测首屏 16 张卡片逐一对上);8 个产品族页的图片 100% 对得上(仅 logo 品牌件与 2 处已在 #56 备案的替换);PDP 相关卡的**卡片结构**与图片取法正确(取被推荐品的 featured)。

---

## 1. 方法与证据来源

旧站(一手,2026-10-25 实取):

- 渲染 HTML:`https://metalpartition.com/products/<handle>`,`/`、`/collections`、`/collections/all`、`/collections/pattern-organic`、`/pages/<family-slug>`。
- 逐品媒体全集与 alt:Shopify 公开商品接口 `https://metalpartition.com/products/<handle>.js`(含 `media[].alt`、`preview_image` 原生宽高);本次取 30 品样本(每 5 个 handle 取 1,共 30,覆盖全部系列)+ 4 个细查品。
- 槽位语义:**主题源码** `~/Documents/shopify-builder/theme/sections/product-pattern-cinematic.liquid`(行 65–191 媒体分类与槽位推导,196–255 hero,257–290 identity,292–313 context,357–388 相关卡);模板挂载 `templates/product.pattern-spec.json`;卡片 `sections/collection-custom.liquid` 行 806–820;系列卡 `sections/collections-pattern-hub.liquid` 行 364–387;首页 `templates/index.json` 行 16(hero)与行 144(Interior Partitions 卡)。

新站:

- 渲染 HTML:`https://metal-divider.com/pattern/<handle>/`、`/`、`/shop/`、`/collections/<series>/`、`/pages/<family-slug>/`。
- 槽位实现:`plugins/md-site/includes/Presentation/Shortcodes.php` 行 222–257(`image()`)、269–290(`related()`)、302–331(`series_grid()`);模板 `plugins/md-site/content/livecanvas/pdp.html`、`shop.html`、`series-archive.html`、`hub.html`、`home.html`。
- 槽位化转换:`tools/template-convert/src/md_template_convert/slots.py`(行 23–68 抽样例值,126–130 换成三个 `md_pdp_image` 槽,240–251 卡片槽)。
- 数据绑定:`tools/migrate/18-migrate.py` 行 21–22 与 1137–1140(`featured = 旧 media[0]`,`gallery = 旧 media[1:]` 原序)。
- 附件表:`tools/migrate/data/dev-attachment-map.json`(1310 条 `_md_source_url → _wp_attached_file`),用于把旧 CDN URL 逐条翻译成新文件并比对。
- 角色元数据:`tools/media/src/md_media/params.py` 行 45–64 的 11 词角色表由 `tools/migrate/18-wordpress.php` 行 237 写成附件 meta `_md_role`。
- Woo 行为:`~/metal-divider-local/dev/wordpress/wp-content/plugins/woocommerce/includes/wc-product-functions.php` 行 1436 起(`shuffle()` 与日缓存 transient)。

复算脚本(临时,不在仓库):以 Liquid 行 79–190 的算法逐行复刻,对 30 个 `.js` 重算旧槽位;新槽位从新站渲染 HTML 用正则取 `pdp-hero-bg / pdp-hero-plate / pdp-square`。

---

## 2. 旧站槽位语义(权威定义)

### 2.1 PDP 媒体槽:按 **alt 角色** 取图,不是按位置

`product-pattern-cinematic.liquid` 行 79–111 先把每张图按 alt 归入一个角色(首个命中者胜):

| 角色 | 命中片段 | 备注 |
|---|---|---|
| cutsheet | `cut sheet` / `cad` / `drawing` / `scale preview` | 直接排除,永不进任何图像槽 |
| finish | `finish preview` / `powder coat` / `anod` / `coating` | |
| scene | `application scene` / `installation scene` / `installed screen scene` / `installed facade` / `facade screen` / `interior divider` / `privacy screen` / `room divider` / `project scene` | 只有场景摄影 |
| detail | `pattern detail` / `detail reference` / `texture detail` | |
| accent | `accent render` | |
| other | 其余(**含 `pattern render`**) | 只填 pos0 |

槽位推导(行 113–170):

| 槽 | 规则 |
|---|---|
| `hero_bg` | 第一张 scene;没有 scene 时**不渲染图片**,落回金色网格底纹 |
| `plate` | 有 hero 时:第一张 detail(且≠hero)→ 否则第一张 finish(且≠hero)→ 否则**空(该槽隐藏)**;没有 hero 时 = pos0(第一张非 cutsheet) |
| `identity_square` | 有 hero 时:第二张 scene → 否则第一张 accent(且≠plate);没有 hero 时:detail 或 finish(与 plate 不同) |
| `context_wide` | 第一张 accent,且没被 plate/square/hero 用过;否则隐藏 |

关键点:**三张图两两不重复**,并且 hero 一定是“已安装场景”,plate/square 一定是渲染图;图片重复时宁可不渲染。

### 2.2 其余槽

| 面 | 旧站取法 | 出处 |
|---|---|---|
| 商店 / 系列归档卡片 | `product.featured_image`(= 旧 `media[0]`),`image_url: width: 720` | `collection-custom.liquid` 806–820 |
| PDP 相关卡 | `product.collections.first` 的前 3 个兄弟品,**用其 featured_image**;顺序确定 | `product-pattern-cinematic.liquid` 172–190、357–388 |
| 系列卡(hub) | `block.settings.image`(**section 里人工挑的主题图**,非 collection featured),回退 `collection_item.featured_image` | `collections-pattern-hub.liquid` 364–387 |
| 首页 hero | `templates/index.json` 行 16 `hotel-lobby-decorative-stainless-steel-screen-divider.webp` | |
| 商店 route 卡(4)/ reference 卡(7) | 主题里的实拍素材 `screen-3…screen-10`、`facade-1/2`、`process-10` | 渲染 HTML |

---

## 3. 新站槽位实现

### 3.1 PDP:按位置取图(根因)

`Shortcodes.php` 行 222–257:

- `hero-bg` → `get_the_post_thumbnail_url(full)` = WP featured = **旧 `media[0]`**
- `plate` → `get_gallery_image_ids()[0]` = **旧 `media[1]`**
- `square` → `get_gallery_image_ids()[1]` = **旧 `media[2]`**

`slots.py` 行 126–130 只把样例品(Akira)三个槽的 URL 换成三个槽标签,角色语义在转换时即丢失。旧站的“scene/detail/finish/accent”分类在新站**完全不存在**。

后果:**新站 hero 永远等于旧站 featured**。30 品抽样里 featured 的角色是 `finish preview` 19、`pattern render` 等 other 8、scene 2、detail 1(即 27/30 是渲染图),原生尺寸 400×800 占 19/30——而旧站 hero 30/30 是场景照。两套语义正好互换。

**修复可行性**:迁移已把角色写进附件 meta `_md_role`(11 词),`md_pdp_image` 完全可以在 PHP 装配层按角色取图,不需要改数据、不需要改模板。11 词 → 旧算法 6 类的映射:

| 旧算法类 | WP `_md_role` |
|---|---|
| scene | `local installation scene`、`official installation scene`、`installed screen scene`、`project installation` |
| detail | `detail reference` |
| finish | `finish preview` |
| accent | `accent render` |
| cutsheet | `cut sheet` |
| other | `pattern render`、`reference image`、`qc photo`、`unknown`(注意:旧算法**不**把 `pattern render` 当 finish,仍只填 pos0) |

### 3.2 PDP 模板写死了样例品的尺寸与 alt

`pdp.html` 行 80–85 / 114–119 / 145–150:

| 槽 | 写死的 width×height | 写死的 alt |
|---|---|---|
| hero-bg | 2400×1351 | `""` |
| plate | 1000×1998(1:2 竖) | `[lc_the_title] pattern detail reference` |
| square | 1200×680(16:9 横) | `[lc_the_title] official installation scene 3` |

两处后果:(a) 声明尺寸取自**旧槽位**在样例品上的图片(旧 plate = media[0] 的 1000×1998 竖图),而新槽位取的是 media[1] —— 尺寸与内容已经脱节;(b) alt 同理取自旧槽位的角色词:plate 的 “pattern detail reference” 对**全部 146 品**都错(含 Akira 自己,它的 media[1] 是 `official installation scene 2`),square 的 “official installation scene 3” 只在 media[2] 恰好是官方 3 号场景时才对。样例品可从 `docs/acceptance/32-debt-ledger.md` 行 20 的“pdp(代表 akira)”与 Akira 的 `.js` 对齐确认。

### 3.3 卡片 / 系列卡 / 相关卡

- 卡片:`shop.html` 269、`series-archive.html` 267 用 `[lc_the_thumbnail_url]` = featured。**取图正确**。
- 系列卡:`hub.html` 45–46 整段换成 `[md_series_grid]`;`Shortcodes.php` 321 读 `get_term_meta($term,'hero_image')`。全仓库只有这一处读、**没有任何地方写** → 恒空。
- 相关卡:`Shortcodes.php` 275 `wc_get_related_products($id,3)`,Woo 端 `shuffle()`(行 ~1503)且日缓存 → 随机、不稳定。

---

## 4. 映射表

图例:`旧 media[n]` 为 `.js` 里的 0 基序号;新文件名 `-NN` = `media[NN-1]`,一一对应。

### 4.1 PDP Apiary(abstract)

| 槽 | 旧站(角色) | 新站实际 | 状态 |
|---|---|---|---|
| hero-bg | `paralocal__apiary-apy-new-gallery-image-desktop_2x_8bdbbff6….jpg`(installed screen scene,media[6],1353×820) | `apiary-…-01-400w.webp`(media[0] finish preview,400×800 竖) | **错图**(场景 → 黑白图案),且 400px 竖图铺满 2400×1351 |
| plate | `pattern-media-3dbr9fk3.png`(finish preview,media[0],400×800 黑白) | `apiary-…-02-2048w.webp`(media[1] standard accent render,3000×1700 **红**) | **错图 + 错画幅**(红渲染塞进 3:4 竖框) |
| square | `pattern-media-n2rh02wj.png`(standard accent render,media[1]) | `apiary-…-03-1500w.webp`(media[2] blue accent render) | **错图 + 错序**(整体后移一格) |
| wide | 无(accent 已被 square 占用) | 模板无此槽 | n/a |
| 相关卡 | Zelda / Winter Branches / Wild Spinach,用各自 featured | Constella / Dan di Lion / Cellular Flow | **选品语义错**(图本身对) |

已用像素目视确认:新 hero 是黑白图案平铺,新 plate 是猩红图案,旧 hero 是屋顶泳池边的实景安装照(见 §7 证据文件)。

### 4.2 PDP Akira(样板品,abstract)

| 槽 | 旧站 | 新站 | 状态 |
|---|---|---|---|
| hero-bg | media[1] `official installation scene 2`(pattern-media-sq21n8x5.jpg) | media[0] `pattern detail reference`(500×999 竖) | **错图(与 plate 对调)** |
| plate | media[0] `pattern detail reference`(1000×1998 竖) | media[1] `official installation scene 2`(1000×563 横) | **错图(对调)+ 错画幅** |
| square | media[2] `official installation scene 3` | media[2] | **正确** |

即连“样板品”自己都不对:转换时取的是位置,而当时模板里写死的尺寸/alt 恰好来自 media[0]/media[1]/media[2] 的**角色文本**,两套来源不同源,所以样板品也错位。

### 4.3 PDP Autumn Leaves(organic)

| 槽 | 旧站 | 新站 | 状态 |
|---|---|---|---|
| hero-bg | media[2] `official installation scene 3` | media[0] `pattern render` | **错图** |
| plate | **无**(无 detail/finish → `pdp-hero-solo`,该槽隐藏,已核对旧 HTML 有 `pdp-hero-solo` 类) | media[1] `pattern render` | **本应缺失却填了错图** |
| square | media[3] `official installation scene 4` | media[2] `official installation scene 3`(即旧站 hero 的那张) | **错序(后移一格)** |

### 4.4 PDP Pebble Creek(只有 2 张图的边界品)

| 槽 | 旧站 | 新站 | 状态 |
|---|---|---|---|
| hero-bg | media[0] `installed screen scene` | media[0] | **正确**(罕见巧合:featured 本身就是场景) |
| plate | 无(隐藏) | media[1] `pattern render` | **本应缺失却填了错图** |
| square | 无(隐藏) | **`<img src="">`** | **缺失/坏图** |

### 4.5 首页 hero

| 项 | 旧站 | 新站 | 状态 |
|---|---|---|---|
| hero 背景 | `hotel-lobby-decorative-stainless-steel-screen-divider.webp`(原生 790×788) | `hero-hotel-lobby-gold-1024w.webp`(原生 1024×1024) | **错图**(已在 #56/PR #57 备案的替换) |
| 该替代图在原页的位置 | 只出现 1 次:specification map 的 “Interior Partitions” 卡 | 出现 2 次:hero + 同一张卡 | 同页重复 |
| 原图是否在 WP | — | 附件表 1310 条里**没有**该源 URL(也查不到同名的任何变体) | 源文件从未导入 |

补充事实:旧页 `img` 上也写着 `width="2400" height="2394"`,但源图原生只有 790×788——旧站自己也是放大;替换件 1024×1024 反而分辨率更高。所以这条的问题在**内容**(泳池/大堂场景换成了另一张暗调室内),不在清晰度。

### 4.6 商店 / 系列归档卡片

| 项 | 旧站 | 新站 | 状态 |
|---|---|---|---|
| 图案卡片缩略图 | `product.featured_image` = media[0] | `[lc_the_thumbnail_url]` = featured = media[0] | **正确**(迁移规则 `featured = 旧 media[0]` 对 146 品逐字一致;商店首屏 16 张卡片的标题与 alt 已逐一核对,含 featured 本身是场景照的案例) |
| route 卡 ×4 | `screen-3` / `facade-1` / `screen-4` / `process-10`(实拍) | `athelstan-01` / `alhambra-01` / `autumn-leaves-01` / `forrest-perforated-01`(图案渲染) | **错图**(#56 备案替换) |
| reference 卡 ×7 | `screen-5…screen-10`、`facade-2`(实拍) | 6 张 `ext-*` + `outdoor-black-leaf-…-01` | **错图**(#56 备案替换) |
| 卡片 alt / aria-label | 逐品 | 线上逐品;**但仓库模板写死样例值**(见 M10) | 需复核 |

### 4.7 系列卡(hub)

| 系列 | 旧站图 | 新站 | 状态 |
|---|---|---|---|
| Perforated | `ext-pp-hero-perforated-facade.webp` | (空) | **缺失** |
| Geometric | `ext-bronze-grid-panel-workshop.webp` | (空) | **缺失** |
| Abstract | `our-aurum-suite-circle-screen-hero.webp` | (空) | **缺失** |
| Organic | `Leaf_Vines.webp` | (空) | **缺失** |
| Landscape | `ext-cn-mountain-profile-screen.webp` | (空) | **缺失** |
| Classic Motif(vintage-retro) | `our-xiamen-bronze-fret-hero.webp` | (空) | **缺失** |
| Structured Lattice(asian) | `our-shanghai-lattice-dining-hero.webp` | (空) | **缺失** |
| Interlaced Lattice(moroccan-arabic) | `Moroccan_Love_Scene.webp` | (空) | **缺失** |
| Gradient | 旧站无此卡 | (空) | 新增,无源 |

同时 `cph__card-copy` 全空(旧卡有文案,如 “Airflow, screening, clean repeat openings.”);`md_series_grid` 用 term description,而 term description 也是空的。属同一转换动作的连带损失。

**注**:这 8 张源图本身**已导入 WP**(附件表逐条可查,见 §7 第 3 条),所以缺的只是“谁引用它们”,不是素材。

### 4.8 8 个产品族页(全量)

| 页面 | 图片集合比对 | 结论 |
|---|---|---|
| stainless-steel-screens | 28 张共有;差异仅 2 个 logo + 1 个视频 poster 档位 | **正确** |
| laser-cut-geometric-metal-screens | 39 共有,差异仅 logo | **正确** |
| glass-hybrid-screens | 39 共有,差异仅 logo | **正确** |
| chinese-style-artistic-screens | 33 共有,差异仅 logo | **正确** |
| laser-cut-panels | 16 共有;新站多 1 张 poster 档 | **正确** |
| perforated-panels | 23 共有;新站多 1 张 poster 档 | **正确** |
| expanded-metal-panels | 35 共有,差异仅 logo | **正确** |
| outdoor-privacy-landscape-screens | 31 共有;`repair-athelstan-pergola-screens` → `athelstan-pergola`(同项目)、`repair-kirra-pool-safe-fence-1` → `outdoor-black-leaf-…-01` | **正确**,2 处为 #56 备案替换 |

族页 hero(`hero-hotel-lobby-gold.webp`)与旧站同源同档(源图原生即 1024×1024),**正确**。

---

## 5. 错位清单与根因

| 编号 | 错位 | 根因 | 落点 |
|---|---|---|---|
| M1 | PDP hero/plate/square 语义全错 | `slots.py` 只做位置化;`Shortcodes::image()` 用 featured + gallery 下标,旧站是 alt 角色 | `Shortcodes.php` 222–257(数据装配层;角色已在 `_md_role`) |
| M2 | 声明尺寸写死 | `pdp.html` 从样例品继承 `width/height` | `pdp.html` 模板层 |
| M3 | 5 品 square 渲染 `src=""` | 位置式取图在 gallery 不足 2 张时不判空 | `Shortcodes.php`(应返回空并让模板隐藏) |
| M4 | alt 逐品错 | `pdp.html` 把样例品的角色词写死 | `pdp.html` 模板层 |
| M5 | 系列卡无图 | 转换丢图 + `hero_image` meta 无人写入 | 数据(term meta)+ `series_grid()` |
| M6 | 首页 hero 错图 | 源图从未导入(#56 备案替换) | 媒体导入 + `home.html` |
| M7 | route/reference 卡错图 | 同上(#56 备案替换) | 媒体导入 + shop/series-archive 模板 |
| M8 | wide 槽消失 | 转换时该槽未进模板 | `pdp.html` |
| M9 | 相关卡选品随机 | `wc_get_related_products` + Woo shuffle | `related()` |
| M10 | 仓库与线上模板不一致 | 未定;仓库任何提交都不含线上那种逐品写法 | 待核实 |

### 5.1 关于 M10(需要下一票核实,不在本票范围)

- 仓库 `shop.html` 行 265/270 与 `series-archive.html` 行 263/268 是**字面样例值**(`View Agave specification`、`alt="Agave | … finish preview"`)。
- `lc_loop` 的实现只是逐次 `do_shortcode($enclosed_content)`(`~/metal-divider-local/dev/wordpress/wp-content/plugins/livecanvas/modules/templating-shortcodes.php` 行 557–570),**不做样例值替换**。
- 但线上 `/shop/`(带 cache-buster 复取)对每个卡片输出的是**逐品** aria-label / alt。
- 全部本地 ref(含所有分支)的历史里都搜不到线上那种写法(`git log --all -S "View [lc_the_title] specification"` 无结果)。

结论:线上 LiveCanvas 模板内容与仓库文件不同源。风险:任何一次 `md-site present`(逐字同步文件)都会把线上打回“146 张卡片同一个 Agave alt”。需要下一票用 `wp post get`(线上 LC 模板)取证并回填仓库。

---

## 6. 30 品抽样统计(复刻旧算法 vs 新站位置式)

| 指标 | 结果 |
|---|---|
| 旧 hero 的角色 | scene **30/30**(没有一例是渲染图) |
| 旧 hero 的位置 | media[7]×13、media[6]×6、media[1]×6、media[2]×2、media[0]×2、media[10]×1 |
| 旧 plate 的位置 | media[0]×20、**空(隐藏)×9**、media[3]×1 |
| 旧 square 的位置 | media[1]×20、media[2]×6、media[3]×2、media[4]×1、media[9]×1 |
| 新槽位命中旧槽位 | hero **2/30**、plate **0/30**、square **6/30** |
| 有 scene 图但三槽里一张都没用上 | **20/30** |
| 新 hero(media[0])是竖版 | **28/30**(典型 400×800、500×1000) |
| 旧站有 context wide | 1/30 |

全量侧写(由附件表统计,146/146 命中):每品图片数最少 2、最多 16;分布 `2×5, 3×10, 4×5, 5×7, 6×5, 7×26, 8×58, 9×19, 10×6, 11×4, 16×1`。**恰有 2 张图的 5 品**即 M3 的受影响集合。

---

## 7. 建议处置(供下一票,不在本票执行)

1. **M1/M3/M9**:改 `md_pdp_image` / `md_pdp_related` 的取图逻辑(数据装配层,符合 AGENTS.md §5 的“PHP 只做数据装配”)。输入用已有的 `_md_role`;按 §3.1 的映射复刻旧算法,含“角色缺失/重复时该槽返回空”。不新增数据、不动模板结构。
2. **M2/M4/M8**:模板层 `pdp.html` 去掉写死的 `width/height` 与样例 alt(改由装配层给尺寸或直接省属性),补回 wide 槽。视觉差距落模板/WindPress,不落 PHP。
3. **M5**:8 张源图**都已导入 WP**(附件表可查:`ext-pp-hero-perforated-facade-2048w`、`ext-bronze-grid-panel-workshop-550w`、`our-aurum-suite-circle-screen-hero-2048w`、`leaf-vines-500w`、`ext-cn-mountain-profile-screen-2048w`、`our-xiamen-bronze-fret-hero-2048w`、`our-shanghai-lattice-dining-hero-2048w`、`moroccan-love-scene-1016w`),因此这条是**纯数据修复**:给 9 个 `product_cat` 写 `hero_image` term meta 即可,不必改代码;文案另行补 term description 或复用旧卡文案。
4. **M6/M7**:先把 `hotel-lobby-decorative-stainless-steel-screen-divider.webp`、`screen-3…screen-10`、`facade-1/2`、`process-10` 补进媒体导入,再回填 URL;#56 的替换件只作过渡。
5. **M10**:先从线上取出 LC 模板正文与仓库对齐,再决定是否 `present`。

---

## 8. 证据文件

- 旧站 PDP 渲染:Apiary / Akira / Autumn Leaves / Pebble Creek 四份 HTML 与 `.js`。
- 旧站首页、`/collections`、`/collections/all`、`/collections/pattern-organic`、8 个族页 HTML。
- 新站同路径 HTML(均 2026-10-25 实取)。
- 像素目视:Apiary 旧 hero(泳池实景)、新 hero(黑白图案)、新 plate(猩红图案)。
- 复算脚本:旧算法复刻(`.js` → 旧槽位)、新槽位抽取(渲染 HTML)、两页图片集合按附件表对齐。

样本 URL 清单:

```
https://metalpartition.com/products/apiary-architectural-metal-screen-pattern
https://metalpartition.com/products/akira-architectural-metal-screen-pattern
https://metalpartition.com/products/autumn-leaves-architectural-metal-screen-pattern
https://metalpartition.com/products/pebble-creek-architectural-metal-screen-pattern
https://metalpartition.com/  https://metal-divider.com/
https://metalpartition.com/collections  https://metal-divider.com/collections/
https://metalpartition.com/collections/all  https://metal-divider.com/shop/
https://metalpartition.com/collections/pattern-organic  https://metal-divider.com/collections/pattern-organic/
https://metalpartition.com/pages/<8 family slugs>  https://metal-divider.com/pages/<同上>/
```
