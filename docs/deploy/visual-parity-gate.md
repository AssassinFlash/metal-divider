# 视觉 parity 验收清单（#64 / #65）

旧站参照：https://metalpartition.com/（冻结）。新站：https://metal-divider.com/。
工具：本机真 Chrome；1440×900 与 390×844；禁动画；等字体/图片；客户匿名上下文。

## 每页型必测（computed 精确相等，升级项除外）

| 页型 | URL（新） | 关键选择器 |
|---|---|---|
| 首页 | `/` | `header`, `.xo-menu-horizontal-v1__label`, `h1`, 首节 h2, hero 背景/板, footer |
| Shop | `/shop/` | 面包屑、`h1`, `.pattern-card`, 分页、footer |
| 系列归档 | `/collections/pattern-abstract/` | 面包屑、`h1`（须为 term 名）、卡片、分页 |
| 产品族 | `/laser-cut-geometric-metal-screens/` | hero h1、section、卡片、footer |
| Hub | `/collections/` | 9 张系列卡（图/文案）、标题 |
| PDP | `/pattern/apiary-architectural-metal-screen-pattern/` | 面包屑 54px、hero scene/plate/square、规格条、FAQ |
| About / FAQs / Inquiry / Gallery / Manufacturing / Privacy | 对应 URL | h1/h2、正文、表单/手风琴 |
| 博客 | `/blog/`, 单篇, 分类, `/?s=` | 不出现模板源码文本；文章可达 |
| 移动 | 以上 390 | header 73px、菜单、无横向溢出 |

## 必测 computed 指纹

- body `font-family` 含 `Inter`；`h1..h6` margin 0；根 `font-size:62.5%`（1rem=10px）。
- Tailwind：`lg:text-7xl`=56px、`md:text-5xl`=42px。
- Header：113/73px，白底；导航 label 13px Playfair uppercase；CTA 13px/40px/#141414；gutter 40px。
- 面包屑：白底，124px（shop/archive）/54px（PDP），13px Inter 黑字无下划线。
- Footer：`#1d1d1d`，桌面 629px，4 列。
- 浮动：WhatsApp 固定 56px；回顶 40px；`#open-quote` 有效。
- PDP：Apiary hero=scene、plate=black render、square=第二 scene/accent；无空 `src`。

## 门

- S1/S2 零未登记；S3 入台账。
- 像素门掩膜仅限已批准升级/已登记内容修复；逐项报业主。
- `tools/visual-census`（research 分支）作为可复跑证据工具；部署前本地跑。
- 升级台账：44px 触达、CSP、自托管字体、Woo/Hostinger 出队、Windpress cached、favicon。
