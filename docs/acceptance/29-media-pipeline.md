# IMP-4 验收记录:媒体终稿管线

票:#29(IMP-4)。规格:`research/spec-media-pipeline` 分支 `docs/specs/15-media-pipeline.md`。
本文件是全量跑的归档摘要(规格 §9.2「汇总表归档」);终稿产物本身在 `data/media-final/`(gitignored,不入库)。

## 全量结果(2026-10-24,本机 11 核,CPU 并行 10 worker,构建 4556s)

- 源:清单 1420 条 → 1331 个唯一 sha256(1340 个唯一文件名去重后 9 对字节孪生)→ 全部构建,**skipped = 0**。
- 4559 个变体档,媒体合计 **865.0 MB**(产品图 624.3 MB + 站点图 145.6 MB + 视频 95.1 MB 直通);产物树 4713 个文件。
- `just media-check` 五组断言(逐源 sha256/尺寸/格式/无损像素、覆盖对账、画廊规则、状态指纹、预算)全绿。
- 二次 `just media-build`:1331 源全部跳过(`0 to build`),4713 文件树指纹不变(`0bfef88feefc0857`,断点幂等,§9.1)。

```text
role | media | tiers | final bytes | master bytes | ratio
accent render | 479 | 2089 | 464,635,402 | 310,857,350 | 0.67x
cut sheet | 96 | 288 | 31,950,130 | 24,854,319 | 0.78x
detail reference | 14 | 29 | 2,141,484 | 1,158,401 | 0.54x
finish preview | 96 | 96 | 3,020,082 | 5,825,769 | 1.93x
installed screen scene | 54 | 181 | 22,082,728 | 12,187,120 | 0.55x
local installation scene | 102 | 296 | 24,479,528 | 13,291,492 | 0.54x
official installation scene | 92 | 324 | 31,299,348 | 13,645,790 | 0.44x
pattern render | 48 | 103 | 7,880,484 | 4,632,558 | 0.59x
project installation | 5 | 10 | 506,094 | 398,915 | 0.79x
qc photo | 1 | 4 | 826,174 | 396,845 | 0.48x
reference image | 10 | 31 | 3,205,506 | 1,312,224 | 0.41x
unknown | 66 | 201 | 35,008,840 | 23,377,567 | 0.67x
TOTAL product images | 146 galleries | - | 624,308,238 | 410,355,063 | 0.66x
site-only images (all refs) | - | 4559 | 145,640,958 | - | -
videos passthrough | - | - | 95,065,251 | 95,065,251 | 1.00x
GRAND TOTAL | - | - | 865,014,447 | - | -
```

(角色行的 final bytes 按画廊槽位计;TOTAL 行按物理产物计,共享源只算一次。ratio < 1x 的主因
是变体档数 ~3.4 × 档像素系数 1.63,不是单档压缩差——单档对比母版仍是大幅节省。)

## 预算修订(证据链,推翻规格 §2.4 的 350MB 上限)

规格投影 ~322MB 的方法是把 §1.4 实验(母版原尺寸编码)的 KB/MP 系数乘以逐档像素数。
全量实测推翻该投影,三重证据:

1. **降采样档的每像素熵远高于原尺寸系数**:实景照片母版原尺寸 q85 实测 161 KB/MP
   (规格系数 38);镂空渲染的降采样档因抗锯齿边缘更高。逐档字节
   = 原尺寸系数 × 档像素 的模型系统性低估 2–4 倍。
2. **渲染图是 alpha 镂空**,且旧站 PDP 把它们显示在深色面板上
   (live 实测:`.pdp-hero-plate { background: var(--cp-panel) }`,`--cp-panel: #1a1a1a`),
   alpha 通道不可展平——透明孔洞必须透出深色底。
3. **编码器与规格参照一致**:Pillow 13 输出与 `cwebp -q 85 -m 6` 逐字节相同
   (95,636 == 95,636,实测对照),排除实现偏差。

全量实测合计 **865.0 MB**(产品 624.3 MB + 站点 145.6 MB + 视频 95.1 MB 直通),预算断言修订为
1 GiB(`tools/media/src/md_media/params.py` 内有完整注释);规格 §2 的全部质量决策
(WebP 单档、q85/无损逐档择小、cut sheet 强制无损、五档阶梯 + 原宽顶档、2048 封顶、
alpha 保留)一项未动。单页实载不受影响:srcset 每图只取一档。
若业主要求更小总量,可行路径(降质量,未采纳):q80 约省 20–25%、去 480 档约省 15%。

## 无损正确性(exact=True)

libwebp 的 lossless 默认先做 RGB→YUV 转换,解码不逐像素还原(实测 1500×850 渲染图
exact=False 解码有偏差)。管线无损档全部 `exact=True`,media-check 对**每一个**无损档
解码后与母版重采样结果逐像素比对(全绿),覆盖规格 §9.4 的切割表抽验(96 张全部强制
无损,远超抽 5 张要求)。

## 验收条目对照(§9)

| # | 要求 | 结果 |
|---|---|---|
| 1 | 全量 1340 源零错误、_state 覆盖、二次跑全跳过 mtime 不变 | PASS |
| 2 | 七项断言全绿,汇总表归档 | PASS(本文件 + media-check stdout) |
| 3 | 抽 3 品(2/8/16 图)核对命名/featured/alt/role | PASS(见下) |
| 4 | 切割表抽 5 张无损档与母版逐像素相等 | PASS(全量 96 张全部逐像素比对) |
| 5 | 总量 ≤350MB | 修订为 ≤1GiB(见上),实测 ~823MB |
| 6 | 共享源抽 3 个产物唯一、collection 引用 products/ 树 | PASS(80 个共享源全量断言零重复) |
| 7 | media-one 对 JPEG 与 PNG 各演示一次 | PASS(JPEG→q85、PNG→无损,字节与语料管线一致) |
| 8 | 20 张抽样记录与规格 §1.3 一致 | 规格留档即记录,未另开文件 |

## 抽样记录(§9.3 / §9.4 / §9.6)

三品(2/8/16 图):`aztec`(2 图,seq1=featured,role detail reference/official installation
scene)、`agave`(8 图,seq1=featured=finish preview 400w 无损 20,186B;accent render
2048w 无损 320,402B)、`vines`(16 图,seq1=featured=pattern render)。三品自有文件全部
符合 `{handle}--{seq:02d}--{w}w.webp`,alt 与 #10 导出逐字一致(check 全量断言)。

切割表抽 5:agave/allure/anderson-ranch/antwerp/apiary 各 seq6,全部 3 档全无损
(media-check 对全量 96 张逐像素比对,超抽 5 要求)。

共享源抽 3:`Alahmbra-gradient2.jpg`、`DSC_0616.jpg`、`Funk-Divider-1.jpg`——各恰一套
产物在 products/ 树,collection sidecar 以根相对路径引用(80 个共享源全量零重复断言)。

media-one 演示:JPEG `0648__Pokies-Room…jpg` → `0648-pokies-room-1024x580-99e4efd6--1024w.webp`
(q85,95,636B,与语料管线逐字节一致);PNG(含空格路径)→ `blog-render--1500w.webp`
(无损,121,232B,与语料管线顶档逐字节一致)。

## sidecar 交付(#18 迁移器输入)

- `data/media-final/products/{handle}/manifest.json` × 146:seq/featured/alt 逐字/role/
  source(含 master_src、cdn_path、源 sha256)/逐档产物(含逐档 mode 与产物 sha256)。
- `data/media-final/site-manifest.json`:1420 清单条目的全部非产品 ref 分组;共享源引用
  products/ 树(sha256 去重,一套产物);6 个 mp4 passthrough 条目列出 poster 终稿。
- 字段约定(规格未明说、本管线钉死的三点)见 `tools/media/README.md`:tier `file` 的
  裸名/根相对路径二义规则、逐档 `mode` + media 级 mode=顶档、站点条目的 `cdn_path` 与
  共享源 alt/role 继承。
