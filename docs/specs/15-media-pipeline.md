# 15 · 媒体终稿管线规格

状态:已定稿(Grilling 票 #15,执行会话推荐即定稿,业主保留否决;AGENTS.md §2 2026-10-24)。
铁律(AGENTS.md §2):**服务器零运行时转换,上传即终稿**——上传到服务器的每个媒体文件都是本地管线处理完成的最终字节,服务器不做任何尺寸/格式/压缩变换,不装任何运行时图片优化插件。
事实基础:#2 媒体清单(`data/shopify-public/media-manifest.json`,1420 条带 sha256,hashed_at 2026-10-24)+ #10 后台权威导出(`data/shopify-admin-export/products.json` alt 联表)+ 原图 `data/media-raw/`(585MB,1340 个唯一文件)+ 2026-10-24 本机实测(全量格式嗅探、20 张随机抽样、18 样本 × 9 档编码实验、Pillow/cwebp 对照)。本文所有数字均标注来源;投影数字标"投影"并给方法。
上游:#6(图片字段结构:featured=旧 media[0]、gallery 原序平迁、角色随 alt 原文 + attachment postmeta、零 ACF 图组)。下游:#18(迁移器消费本文 sidecar 与 WP 接口)、#19(PDP 模板消费 `_md_variants`)、#21(像素门用全量终稿)、#14(工具链:uv + just,CI 钉 Python 3.12)。

## 0. 结论一览

1. **格式:WebP 单档**,不做 AVIF 双档。证据:字节大头是渲染图(占终稿 ~60%),WebP 无损(66–87 KB/MP)压过 AVIF q60(104 KB/MP);双档多 ~85% 存储只为省照片小头的 ~25%。AVIF 作为参数插槽保留,不实现。
2. **编码自适应,逐档择小**:PNG 源 → 无损与 q85 两种都算、取字节小者(JPEG 源只算 q85);cut sheet 角色强制无损(技术图纸文字零风险,全档代价 +6MB)。实测依据见 §2.2。
3. **宽度档位 [480, 768, 1024, 1536, 2048] + 原宽顶档(源 <2048 时),不放大,2048 封顶**。封顶同时让 WordPress big image 阈值(默认 2560)永不触发——服务器端零 `-scaled` 副本。
4. **命名 `{owner-slug}--{seq:02d}--{width}w.webp`**(产品)/`{stem}--{width}w.webp`(站点);构建产物按消费单元分树 `products/` + `site/`;WP 媒体库走原生 `uploads/YYYY/MM`,零自定义上传路径代码。
5. **sidecar:每个产品一份 `manifest.json` + 站点一份 `site-manifest.json`**,schema `md-media/sidecar@1`(§5),承载 alt 原文、角色、源 sha256、逐档产物清单——#18 迁移器的唯一媒体输入。
6. **工具:`tools/media` uv 项目 `md-media`**(Python 3.12,Pillow 钉版 uv.lock;实测 Pillow 13.0.0 与 cwebp 字节持平 1.00–1.01);just 配方 `media-build` / `media-check` / `media-one`(just 配方名不允许 `::`,票面 `media::build` 即此映射);内容寻址断点重跑;`media-check` 全量 sha256 + 尺寸断言。
7. **非产品图同管线**:351 张站点图(home/页面/系列/博客)同参数处理;6 个 mp4(95MB)直通零转码;业主后传博客图走 `just media-one <file>`(单文件终稿),备用 Squoosh 参数卡。
8. **总量**:产品图 412MB → **~180MB**(全档合计,投影)、站点图 111MB → **~47MB**(投影)、视频 95MB 直通;终稿合计投影 **~322MB**,验收上限 350MB。

---

## 1. 实测分布(管线参数的依据)

### 1.1 清单与语料构成(来源:media-manifest.json 1420 条 + #10 products.json 逐张 alt 联表,联表命中 1063/1063)

| 切面 | 实测 |
|---|---|
| 清单条目 | 1420(产品 1063 + 非产品 357);去重后唯一文件 1340(**80 个文件同时被产品画廊与系列页引用**,两条目共享 local_name) |
| 产品图按角色(alt 词表解析,#6 §1.2 同源) | accent render 479(310.9MB,**占总字节 75%**)、local installation scene 102、finish preview 96、cut sheet 96(24.9MB)、official installation scene 92、其他场景/可视化 120、pattern render 48、detail/reference 24、project installation 5、QC photo 1 |
| 产品图尺寸(长边) | 中位 1366、p10 800、p90 3000、最大 3000;精确分布:长边 1500 共 308 张、3000 共 173 张、1024 共 121、1200 共 114、1000 共 104、800 共 99;>2000px 共 186 张(**"原图普遍 2000px+"不成立**:75% 长边 ≤1500) |
| 横竖比 | 横图 777(宽中位 1500)、竖图 286(宽中位 500);宽高比无单一主流(868 张为 other)→ 档位只按宽,不按长边 |
| 产品图字节 | 中位 273KB、p90 895KB、合计 411.9MB |
| 非产品图 351 | 真实格式 jpeg 290 + png 51;长边中位 1000、p90 2048、最大 5514;合计 111.3MB |
| 视频 6 | mp4,720p/1080p,3.0–4.8Mbps,10.7–28.1MB,合计 95MB;被 home/9 页面/测试博客引用;另有对应 poster 帧 jpg(`*.thumbnail.0000000000_2200x.jpg`)已入清单 |

### 1.2 扩展名会说谎:全量内容嗅探(来源:`file` 逐文件嗅探 1340 个,2026-10-24)

| 扩展名 → 实际格式 | 张数 |
|---|---|
| jpg → JPEG | 404 |
| png → PNG | 631 |
| **webp → PNG(62)/ JPEG(237)** | **299 个 `.webp` 全部是假扩展名**;全库真 WebP 为 0 |

推论:① 管线解码必须按内容嗅探,不许信扩展名(Pillow `Image.open` 即内容嗅探);② 不存在"WebP 母版再压 WebP"的代际损失问题——所有母版都是 PNG/JPEG 一次转码。

### 1.3 20 张随机抽样实看(seed 20261024,来源:data/media-raw/ 逐张 `file` + `sips`)

抽样覆盖:3000×1700 PNG 渲染 5 张(467–914KB)、1500×850 PNG 渲染 2 张(190–308KB)、927×1200 PNG 切割表 4 张(203–330KB)、1000–1786px 宽 JPG 场景照 6 张(131–471KB)、≤1000px 小图 3 张(46–148KB)。抽样与 §1.1 全量分布一致;渲染图是 PNG 平色图形,场景图是 JPEG 照片——两类内容压缩行为完全不同,是自适应编码(§2.2)的直接动因。

### 1.4 编码实验(来源:18 分层样本 × {WebP 有损 q70–90、WebP 无损、AVIF q40–60},PSNR 用 ImageMagick `compare -metric PSNR` 对母版逐张实测,2026-10-24;原始数据 results.csv 180 行)

每角色 2 样本的关键读数(KB/MP = 千字节每百万像素,用于全量投影):

| 角色 | WebP 无损 | WebP q85 | AVIF q60 | PSNR(q85 档,对母版) |
|---|---|---|---|---|
| accent render(平色渲染,3000/1500 PNG) | **66–87 KB/MP** | 100–158 KB/MP | 104 KB/MP(全角色均值) | 44.1–46.8 dB |
| accent render(网纹渲染,假 webp 母版) | 340 KB/MP | **179 KB/MP** | — | 44.3–46.2 dB |
| cut sheet(技术图纸) | 91 KB/MP | 61 KB/MP | 34 KB/MP | 42.6–43.5 dB |
| finish preview | **106 KB/MP** | 108 KB/MP | 99 KB/MP | ≈无损(78 dB+) |
| pattern render | 280 KB/MP | 154 KB/MP | 93 KB/MP | 42.2–45.1 dB |
| 场景照片(JPEG 母版) | 198 KB/MP | **38 KB/MP** | 28 KB/MP | 36.4–43.5 dB |

三个决定性事实:

1. **平色渲染图无损比任何有损都小**(平色样本 66–87 vs 100–158 KB/MP):无损既省字节又像素级完美,零 banding 风险;
2. **网纹渲染相反**(340 vs 179 KB/MP):同是"渲染"角色,内容决定胜负 → 必须逐文件择小,不能按角色一刀切;
3. **AVIF 只在照片类赢**(28 vs 38 KB/MP,-26%),而照片类只占终稿字节 ~15%——双档收益封顶。

Pillow 13.0.0 对照 cwebp(libwebp 同源):无损档字节比 1.00–1.01,q85 档 133KB vs 135KB——**编码器取 Pillow(uv 钉版可复现),实验结论直接成立**。

### 1.5 兼容性(来源:caniuse 官方数据仓库 Fyrd/caniuse main 分支,2026-10-24 拉取;Apify web-scraper 因需账号级权限审批改走官方数据源)

WebP 全局支持 97.1%(usage_perc_y)+0.1% 部分支持;AVIF 96.3%。差距 0.8 个百分点(≈Safari ≤15.x 与 2020 年前 Chrome)。WebP 单档的未覆盖面为 IE11/Opera Mini/KaiOS 级浏览器,B2B 建筑师受众实际为零。

---

## 2. 决策 1:变体策略

### 2.1 格式:WebP 单档(否决 AVIF 双档)

| 项 | WebP 单档 | WebP+AVIF 双档 |
|---|---|---|
| 全库终稿(投影,方法 §2.4) | ~227MB | ~420MB(WebP 227 + AVIF ~190) |
| AVIF 带来的字节节省 | — | 仅照片类 -26%,全库折算 **~4–8MB** |
| 文件数/导入/校验复杂度 | 1× | ~2×(每个 attachment 旁挂一套 AVIF) |
| 呈现层(#19) | `<img srcset>` | 每图 `<picture><source>` 包裹 |
| 兼容 | 97.1% | 96.3% + 回退到 97.1% |

**结论:WebP 单档**。双档 ~85% 额外存储与呈现层复杂度,换来全库 ~4–8MB 的交付节省(照片本来就只占 ~15% 字节,渲染图 AVIF 压不过无损 WebP);B2B 站点在 Cloudflare CDN 后,单页照片字节差 <100KB。管线把 `formats=("webp",)` 做成参数插槽:若二期实测表明需要,加 "avif" 重跑全量即可,不改架构。**不提供 JPEG 回退档**(未覆盖浏览器为 IE11/Opera Mini 级,实测受众为零;留回退=三倍文件)。

### 2.2 编码模式:内容自适应,逐档择小

按内容嗅探的母版格式分流,角色只做一条例外:

| 母版真实格式 | 每档编码 | 决定依据(§1.4) |
|---|---|---|
| PNG(渲染/切割表/饰面预览等) | **无损 与 q85 各编一次,取字节小者** | 平色渲染无损胜(66–87 < 100–158 KB/MP);网纹渲染有损胜(179 < 340 KB/MP)——逐文件分胜负,不按角色猜 |
| JPEG(全部照片类) | **仅 q85** | JPEG 母版无损化只涨不省(198 vs 38 KB/MP) |
| 例外:role=cut sheet(96 张) | **强制无损** | 图纸含小字规格,q85 PSNR 42.6–43.5 dB 虽高但文字边缘不赌;代价全档 +6MB((91−61)KB/MP × 1.11MP × 96 张 × 档位像素系数 1.95) |

参数(Pillow 语义):有损 `quality=85, method=6`;无损 `lossless=True, method=6`。q85 在照片类的实测 PSNR 36.4–43.5 dB(对已是有损的 JPEG 母版),为视觉无损安全档;渲染图走无损,无质量损失议题。RGBA 透明通道保留(Pillow 原生)。

### 2.3 宽度档位

| 档 | 用途 |
|---|---|
| 480 | 网格缩略/移动端卡片 |
| 768 | 移动端整幅/平板半幅 |
| 1024 | 平板整幅/桌面画廊主图(1×) |
| 1536 | 桌面画廊 2×/灯箱(常规屏) |
| 2048 | 灯箱全屏/放大查看(顶档) |

规则:**只生成 ≤ 源宽的档;源宽 <2048 且不在档内时,源宽本身作为顶档**(例:1500px 渲染图 → 480/768/1024/1500 四档,1500 顶档零重采样再压);**源宽 >2048 一律缩到 2048 封顶,不留 3000 原档**。3000px 渲染图降到 2048 在 ≥27" 屏灯箱内无可辨差异(平色图形),而 WordPress big image 阈值(默认 2560,developer.wordpress.org `big_image_size_threshold`)对 >2560 文件会生成 `-scaled` 副本——2048 封顶从源头保证永不触发,配合 #18 的双保险(§7)。竖图按同一宽度档缩放(浏览器 srcset 按布局宽度选档,与高度无关)。

### 2.4 全量投影(方法:实测 KB/MP × 逐文件逐档像素数,像素数 = 源像素 × (档宽/源宽)²)

| 语料 | 母版 | 终稿(全档合计) | 档均系数 |
|---|---|---|---|
| 产品图 1063 | 411.9MB | **~180MB**(投影,±15%) | 每图平均 3.4 档、像素总量 1.63×(逐文件实测统计) |
| 站点图 351 | 111.3MB | **~47MB**(投影) | 同法 |
| 视频 6 | 95MB | 95MB(直通) | — |
| 合计 | 618MB | **~322MB**(投影;验收上限 350MB) | |

典型 PDP(8 图:4 渲染 + 切割表 + 饰面 + 2 场景)桌面端单次实载(每图只取一档)约 0.6–0.8MB,移动端约 0.25MB。

---

## 3. 决策 2:命名规范与目录布局

### 3.1 文件名

```text
产品:  {product-handle}--{seq:02d}--{width}w.webp
站点:  {stem}--{width}w.webp
```

- `product-handle`:产品 slug(apiary-architectural-metal-screen-pattern 等,#6 全量);媒体库里同品文件天然聚簇、可溯到产品、全局唯一。
- `seq`:该品媒体序号(1 基,01 = featured = 旧 media[0],与 #6 平移规则一致;每品最多 16 张,两位足够)。
- `{stem}`(站点):原文件名 slug 化(小写、`[a-z0-9-]`、连折叠),如 `Alhambra_Star.webp` → `alhambra-star--1024w.webp`。站点图无产品序号语境,保原名可溯即可。
- `--{width}w`:档宽后缀,附件文件名即自描述;`_md_variants` 与模板枚举同目录兄弟档时按后缀模式匹配。
- **不用原文件名承载产品图**(母版名如 `paralocal__…-desktop_2x_808a5d2c-…` 长达 90+ 字符且含孤立哈希);**文件名不含角色**(角色随 alt/postmeta,可修正而文件名字节级稳定)。字符集 `[a-z0-9.-]`,全 ASCII,长度 ≤96。

### 3.2 目录布局

构建产物(**gitignored,产物不入公开仓库**;体积 ~322MB + sidecar 若干 KB):

```text
data/media-final/
  products/{product-handle}/           # 每品一目录:终稿文件 + manifest.json(sidecar)
  site/{context}/{ref-slug}/           # context ∈ page|home|collection|blog|article
  site/_videos/                        # 6 个 mp4 直通 + poster 终稿
  site-manifest.json                   # 站点全量 sidecar
  _state.json                          # 断点状态(§6.3)
```

- **产品按产品、站点按页型**两棵子树:产品树服务 #18 逐品导入;站点树按消费单元(page/home/collection/blog/article)服务页面复刻票(#19/#21)。
- 同一源文件多引用(80 个产品↔系列共用、站点跨页复用)按 **sha256 去重,只产一套产物**;各 sidecar 引用同一批文件名,产品侧为准(collection sidecar 指向 products/ 树的产物)。
- **WP 媒体库:原生 `uploads/YYYY/MM/`,零自定义上传路径**。#18 侧载顶档文件成 attachment,其余档复制进同月目录(非 attachment);媒体库检索靠文件名前缀聚簇,不给页面型建 uploads 子树(原生优先,AGENTS §5)。1420+ 文件进 1–2 个月度目录对 WP 无规模问题。

---

## 4. 决策 3:alt、postmeta 与角色标记随文件进 WP

#6 已定:alt 原文逐字平迁(attachment 的 alt 字段);`_md_role`(按 alt 词表解析)、`_md_source_url`(manifest master_src)入 attachment postmeta。本文补三件:

1. **`_md_sha256`**:源母版 sha256(对账底账——线上附件可反查是否被服务器侧动过字节);
2. **`_md_variants`**(JSON):`[{"w":1536,"f":"apiary-…--01--1536w.webp"},…]` 各档文件与宽;#19 模板据此发 srcset(同一 uploads 目录内的兄弟文件,PHP 数据装配,AGENTS §5 允许层);
3. **attachment 文件 = 顶档**(2048 或源宽),不是母版——母版永不进服务器。

角色词表(#6 §1.2 实测 11 词 + 解析不中 → `unknown`):accent render / cut sheet / finish preview / local installation scene / official installation scene / installed screen scene / pattern render / detail reference / reference image / project installation / QC photo。解析在**本地管线**做(sidecar 里给 `role` 字段),#18 只抄写不解析——服务器零逻辑。

---

## 5. sidecar 规格(md-media/sidecar@1)

**产品**:`data/media-final/products/{handle}/manifest.json`

```json
{
  "schema": "md-media/sidecar@1",
  "product": "apiary-architectural-metal-screen-pattern",
  "media": [
    {
      "seq": 1,
      "featured": true,
      "role": "finish preview",
      "alt": "Apiary | Architectural Metal Screen Pattern finish preview",
      "source": {
        "file": "pattern-media-ebzkt4p1.png",
        "sha256": "…(源母版 sha256,manifest 原值)",
        "width": 400, "height": 800,
        "master_src": "https://cdn.shopify.com/s/files/…/pattern-media-ebzkt4p1.png?v=…"
      },
      "mode": "lossless",
      "tiers": [
        {"w": 400, "file": "apiary-architectural-metal-screen-pattern--01--400w.webp", "bytes": 32234, "sha256": "…(产物 sha256)"}
      ]
    }
  ]
}
```

**站点**:`site-manifest.json` 同构,顶层按 ref 分组(`{"page:laser-cut-geometric-metal-screens": [ …同 media 条目… ], "home": […]}`),`media` 条目无 seq/featured,改带 `ref`。共享源(产品↔系列)在站点条目里 `source.sha256` 指向产品侧产物,`file` 字段直接引用 `products/` 树相对路径。视频条目 `passthrough: true`,无 tiers,列 poster 终稿。

字段纪律:`alt` 逐字原文(含 "|" 竖线,不清洗——#6 平移原则);`role` 词表封闭;`mode` ∈ lossless|q85;每档 `sha256` 必填(media-check 对账用)。

---

## 6. 决策 4:管线工具形态

### 6.1 `tools/media` = uv 项目 `md-media`(否决 node/sharp)

- **uv(Python 3.12)与 #14 栈一致**(`tools/local` 同法):CI 钉版、`uv.lock` 可复现、`just check` 静态检查直接覆盖(ruff+mypy)。sharp 需为一条管线单独引入 Node 工具链,且 #14 的 CI/工具链矩阵没有它的位。
- 依赖 **Pillow 13.x(uv.lock 钉)**:解码(内容嗅探)、Lanczos 缩放、WebP 编码一体;实测与 cwebp 字节持平(§1.4),libwebp 经 Pillow wheel 供货,版本随锁文件走,不依赖 Homebrew。
- 包结构:`tools/media/src/md_media/`(params.py 参数常量唯一来源、build.py、check.py、one.py、sidecar.py、state.py),`tools/media/tests/` 进 CI 的 `just test`。

### 6.2 just 配方(just 配方名语法不允许 `:`,票面 `media::build/media::check` 落为连字符)

| 配方 | 作用 |
|---|---|
| `just media-build` | 全量构建:读 manifest + #10 alt 联表 → 1340 源 → 终稿 + sidecar + _state;`ProcessPoolExecutor` 并行(worker=CPU 数)。实测单档编码 0.1–1.3s(Pillow),全量约 6800 次编码:单线程 ~30 分钟,CPU 并行后数分钟 |
| `just media-check` | 全量校验(§6.4),非零退出码即 FAIL;CI 不跑(语料不在仓库),#18 导入前与 #21 基准采集前的本地门 |
| `just media-one <file>` | 单文件终稿(§8 业主路径) |

### 6.3 断点重跑(内容寻址)

`_state.json`:`{"params_fingerprint": "<sha256(参数常量+工具版本)>", "entries": {"<源sha256>": {"outputs": {"<档w>": "<产物sha256>"}}}}`。重跑时:源 sha256 未变 + params_fingerprint 未变 + 各产物存在且首检(size+mtime)吻合 → 跳过;任一变化 → 该源全档重建。参数或工具升级 → fingerprint 变 → 全量自动重建(不会新旧参数混编)。

### 6.4 产物校验(media-check 断言,全部必须绿)

1. **覆盖对账**:1420 清单 refs 全部命中 sidecar;1340 唯一源各有一套产物;80 共享源零重复产物;
2. **sha256**:每档产物重算 sha256 == sidecar 声明;
3. **尺寸**:解码顶档与逐档,宽度 == 声明档宽,高度按纵横比 ±1px;顶档 ≤2048;档宽 ≤ 源宽(不放大);
4. **格式**:产物嗅探 = WebP;
5. **#6 规则**:每品 sidecar `seq` 连续、`featured=true` 恰一条且 seq=1、media 条数 == 旧 media 数、`alt` 逐字(与 #10 导出 diff 为零)、role 命中词表或 unknown;
6. **总量**:终稿合计 ≤350MB(投影 322MB + 余量);
7. 汇总表(stdout):每角色档数、字节、压缩比,供验收记录归档。

---

## 7. WordPress 侧接口(对 #18 的硬要求,"上传即终稿"的最后一公里)

1. **关闭中间尺寸生成**:#18 挂 `intermediate_image_sizes_advanced → []`(developer.wordpress.org 证实:空数组 = 上传时零子尺寸生成)。否则 WP 会在导入时用服务端 PHP GD/Imagick 再生成 thumbnail/medium/large 副本——违反字节级"上传即终稿"。代价:媒体库网格回退加载顶档全尺寸 URL(单操作员站点,顶档均 <300KB,可接受;记入 #18 决策日志)。
2. **big image 阈值双保险**:顶档 ≤2048 已保证 <2560;#18 另挂 `big_image_size_threshold → false`(官方文档:返回 false 禁用缩放),防御未来参数漂移。
3. **attachment = 顶档文件**,其余档为同目录普通文件;#18 按 sidecar 写 `_md_role` / `_md_source_url` / `_md_sha256` / `_md_variants`(§4)。
4. **视频直通**:mp4 原字节上传,零转码;poster 用管线终稿。
5. 旧站图片 URL(cdn.shopify.com)**不做 301**(非 #15 范围,记为 non-goal:Shopify 解约后 CDN 自然消亡,图片搜索流量随新 URL 重建)。

---

## 8. 决策 5:非产品图与业主运维路径

1. **现存量 351 张站点图**(home 46 / page 258 / collection 91 / blog 7 / article 12 引用,含跨页复用):走同一管线同参数(§2.2–2.3),产出 `site/` 树;其中 5514px 一张按 2048 封顶。
2. **6 个 mp4 直通**(720p/1080p,3.0–4.8Mbps,实测码率合理,总 95MB):零转码;poster jpg 进管线。
3. **业主后传(博客配图等)**:`just media-one ~/Desktop/foo.jpg` → 同参数单文件终稿(内容自适应无损/q85、>2048 缩到 2048),输出 `<stem>--{w}w.webp` 于原目录并打印"上传此文件"。**单文件、无档位阶梯**——yansir-md 博客正文图由内容宽度约束,不需要 srcset 家族;一条命令与语料库同参数,不引入第二套质量口径。上传动作:WP 后台媒体库直接上传该文件(零运行时转换,与主语料同一铁律)。
4. **备用路径(脱离仓库时)**:Squoosh(squoosh.app,浏览器本地处理,零上传),参数卡:**WebP / quality 85(照片)或 Lossless(图形)/ resize 至宽 ≤2048**。与 `media-one` 参数一致,产物体积同量级。
5. **视频后传约定**:mp4(H.264)≤1080p、码率 ≤5Mbps、时长 ≤60s,直传不转码——与现存量规格一致。

---

## 9. 验收要点

1. `just media-build` 全量 1340 源零错误;`_state.json` 覆盖 1420 refs;二次运行全跳过且产物 mtime 不变(断点幂等);
2. `just media-check` 七项断言(§6.4)全绿,汇总表归档本票目录;
3. 抽 3 品(2 图、8 图、16 图各一)人工核对:文件名 `{handle}--{seq}--{w}w.webp`、featured=seq1、alt 与 #10 导出逐字一致、role 词表命中;
4. 切割表抽 5 张无损档与母版像素级比对(解码后逐像素相等);
5. 总量:products+site+videos ≤350MB;
6. 共享源抽 3 个(产品↔系列):产物唯一,collection sidecar 引用 products/ 树;
7. `just media-one` 对一张 JPEG 与一张 PNG 各演示一次,产物参数与语料管线一致;
8. 20 张抽样原始记录与本文件 §1.3 一致(留档本票分支 `docs/specs/` 邻位,不另开文件——本文即记录)。

## 10. OPEN 项(业主私有事实)

- 无。本票全部决策基于实测与公开文档;业主后传视频时长上限(§8.5)与 Squoosh 备用路径如需调整,改本文即生效。

## 11. 证据清单(核实日期均为 2026-10-24)

| 事实 | 来源 |
|---|---|
| 清单 1420 条/1063 产品图/357 非产品/80 共享文件/尺寸与字节分布 | `data/shopify-public/media-manifest.json` 逐条统计(python,本文 §1.1);hashed_at 2026-10-24 |
| 角色 11 词分布、alt 原文、featured=media[0] | #10 `data/shopify-admin-export/products.json` media.nodes 联表,1063/1063 命中;#6 §1.2 同源 |
| 299 个 .webp 假扩展名(62 PNG + 237 JPEG)、全库真 WebP=0 | `file` 逐文件嗅探 1340 个(§1.2) |
| 20 张随机抽样尺寸/格式/体积 | seed 20261024,`file`+`sips`+`stat` 逐张(§1.3) |
| 无损/q85/AVIF 各档字节与 PSNR、KB/MP 系数 | 18 分层样本 × 9 档编码实验(cwebp 1.x/avifenc libaom s6/ImageMagick 7.1.2 compare),results.csv 180 行(§1.4) |
| Pillow 13.0.0 与 cwebp 字节持平(1.00–1.01) | 同日对照实验 6 样本(§1.4) |
| 全量投影 412→180MB / 111→47MB / 合计 ~322MB | KB/MP 系数 × 逐文件逐档像素求和(§2.4 方法;系数即实验实测) |
| WebP 97.1% / AVIF 96.3% | caniuse 官方数据仓库 Fyrd/caniuse main(fulldata usage_perc_y),2026-10-24 拉取;Apify web-scraper 因需账号权限审批不可用,官方数据源直读 |
| WP big image 阈值默认 2560,>2560 生成 -scaled,filter false 禁用 | developer.wordpress.org/reference/hooks/big_image_size_threshold |
| 空数组关闭上传时中间尺寸生成 | developer.wordpress.org/reference/hooks/intermediate_image_sizes_advanced(wp_create_image_subsizes) |
| mp4 规格 720p/1080p 3.0–4.8Mbps 共 95MB | 清单字节 + 文件名(media-raw 逐个 stat) |
| 工具链 uv/just/Python 3.12 钉版语境 | #14 规格 §1.3/§6.3(上游已定稿) |
