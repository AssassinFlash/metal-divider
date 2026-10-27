# 图案缩略图不可见 — 证据与处置(2026-10-28)

业主验收意见里有两条指向同一件事:「Agave 卡片几乎不可读」与「内容少了很多」。
排查结论:**146 个图案里 94 个(64%)在深色页面上几乎无法辨认**,原因是缩略图取的是
「白线稿透明掩膜」而不是真实照片。旧站 metalpartition.com 有完全相同的缺陷。

本文件只记录证据与判定,处置方案见末节。

## 1. 现象

生产 `/shop/` 首屏 24 张图案卡,14 张是可见的纯黑区域。逐张量测(采样 32×32,
亮度 = Rec.709 加权):

| 图案 | mean | max | 判定 |
|---|---|---|---|
| agave | 0.0 | 0.0 | 纯黑 |
| falling-petals | 0.0 | 0.0 | 纯黑 |
| akira | 133.0 | 250.0 | 正常(对照) |
| alhambra | 71.5 | 44.0→255.0 | 正常(对照) |

## 2. 原因

源图是 **RGBA 透明掩膜**,不是照片:

```
agave-architectural-metal-screen-pattern--01--400w.webp
  mode=RGBA  size=400x800  RGB 只有 (0,0,0) 与 (255,255,255) 两色  alpha 46-255

akira-architectural-metal-screen-pattern--01--480w.webp
  mode=RGB   size=480x959  691 种颜色                          ← 正常照片
```

决定性实验(同一张 agave 合成到两种底):

```
合成到白底 (255,255,255): mean=42.8  max=255.0   → 图案清晰
合成到黑底 (  10, 10, 10): mean= 3.4  max= 22.0  → 完全消失
```

本站页面底色 `#0a0a0a`、卡片 `#141414`。所以白色线条图案铺在近黑底上必然隐形。

## 3. 全站范围

对 146 个产品逐个把 `-01` 源图合成到 `#0a0a0a` 后量测,

```
total patterns with -01 image       : 146
RGBA mask-type                      :  99
invisible on dark (max luminance<30):  94
```

代表值:`falling-petals` mean=0.5 / max=1.0;`white-sands` 0.7 / 3.0;
`migration` 0.8 / 7.0;`agave` 1.7 / 9.0。

## 4. 旧站同款

旧站两处容器均为深底,与掩膜叠加后同样隐形:

| 位置 | 旧站测得的容器底色 |
|---|---|
| 目录卡 `.pattern-card__media` | `rgb(20, 20, 20)` |
| PDP 主图 `.pdp-hero-plate` | `rgb(26, 26, 26)`(`background: var(--cp-panel)`;该变量在 `main.css` 与 `tailwind-production.css` 中均未定义,回退到 `#1a1a1a`) |

旧站源图 `pattern-media-0oki4kx8.png`(agave 主图)同样是 RGBA 掩膜,
alpha 0-255、39% 全不透明;合成到旧站卡片底后 `mean=3.4 / max=22`。

**结论:这是旧站长期存在的显示缺陷,不是移植过程中丢失的内容。**

## 5. 已排除的两种修法

两种填色方案都做过实尺寸(338px)评审,均被否决,理由不是观感偏好:

- **镂空填浅色 `#f0ede8`**:图案对比度确实从 1.14:1 升到约 18:1,
  但密集图案(apiary 开放 40%、mandala 55% 亮面)读起来是**浴室马赛克**;
  且它把「深色卡上的光」变成「浅色卡上的黑图」,与 52 张正常照片的介质不同。
- **把图案染成浅金属 `#d6d1c8`**:agave 有 90,679/114,244 像素是同一个值
  (无颗粒、无厚度、无投影),读起来是**矢量贴纸**;且对不透明照片无效果
  (akira/alhambra 整张被涂成单色)。

## 6. 正确修法(待实施)

`media/manifest.json` 的 `media[]` 带 `role` 字段。当前特色图取 `media[0]`,
而 94 个图案的 `media[0].role` 是 `finish preview`(掩膜);真实安装现场照在第 6、7 位。

```
agave 的 manifest media[]:
  [0] finish preview            ← 当前特色图,透明掩膜
  [1..4] accent render          渲染稿
  [5] cut sheet                切割图
  [6] installed screen scene    ← 真实安装现场照 600x800
  [7] accent render
```

按「角色 ∈ {installed screen scene, local installation scene,
official installation scene, project installation, reference image,
detail reference, qc photo} 且为不透明影调图」筛选,**145/146 个图案可解析出可用照片**
(仅 `sampoerna` 无)。

落点在 `plugins/md-site/includes/Presentation/PatternFacts.php` 的 `image()`;
调用方为 `Cards.php`(3 处)与 `PartialFields.php`(3 处:url/width/height),
影响目录卡、图案图块、PDP 与相关推荐。

## 7. 为什么单独记录而不是顺手改

- 改动横跨数据层(换特色图)与呈现层(alt 文案写死 "finish preview")。
- 会一次改变 94 个产品在目录、PDP、相关推荐三处的首图。
- 按 AGENTS.md「生产写入分层:数据写入前做完整备份」,特色图变更属于数据写入。

因此先出证据与判定,处置单独成批并附前后对比。
