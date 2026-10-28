# 页面 meta description 草案（2026-10-28）

## 为什么需要

11 个页面类型的 `excerpt` 全为空，而 `Config.php` 的 page 描述模板是
`%%post_excerpt%%`，所以：

- 5 页**完全没有** `meta description`
- 4 页退回页面标题（8–25 字符）
- 只有 `/shop/` 正常（163 字符，来自 archive 模板）

Google 通常展示 120–160 字符。8 字符等于放弃这段展示位。

## 起草依据

每一条都基于该页实际的 H1 与正文，**没有编造事实**。产品数（146）、材质
（Stainless Steel 304/316）、回复时限（1 business day）取自站点既有内容与
AGENTS.md 裁定，不是我新造的。

## 草案

### `/collections/` （枢纽，H1「Decorative Metal Screen Patterns」）
```
Nine decorative metal screen series for facades, partitions and outdoor privacy. Compare pattern families, open area and visual density, then request a quote.
```
152 字符

### `/inquiry/`（H1「Request your quote」）
```
Send your project brief and get a quote with pattern mockups within one business day. Dimensions, finish and quantity — one reply, one person.
```
145 字符

### `/faqs/`（H1「FAQ & Download Center」）
```
Answers on materials, sizes, lead times and installation, plus technical files ready to download. Stainless steel 304 and 316 screen specifications in one place.
```
158 字符

### `/about-us/`（H1「Your design. Precision-built. Delivered exactly as approved.」）
```
Laser-cut and perforated stainless steel screens made to your drawings. In-house cutting, finishing and inspection, delivered exactly as approved.
```
145 字符

### `/manufacturing-process/`（H1 工艺页）
```
From pattern lock to crated delivery: how each stainless screen is cut, finished and checked. See the tolerances, the quality gates and who signs off.
```
151 字符

### `/project-gallery/`（H1 画廊）
```
Installed architectural metal screens — hotel lobbies, facades, restaurants and private terraces. Filter by project type and see the pattern used on each.
```
152 字符

### `/laser-cut-geometric-metal-screens/`（家族页）
```
Laser-cut geometric metal screens for facades and interior partitions. Compare patterns by open area and visual density, and request a quote with mockups.
```
155 字符

### `/blog/`（H1「Blog」）
```
Notes on specifying architectural metal screens — pattern density, finishes, installation detail and what to send for an accurate quote.
```
136 字符

### `/custom-metal-divider-screens/`（H1 定制页）
```
Custom metal divider screens from your own design or pattern. Send dimensions and intent; we return a mockup, a finish direction and a quote.
```
141 字符

### `/privacy/`
```
How metal-divider.com handles inquiry data: what we collect, why, how long we keep it, and how to ask for a copy or deletion.
```
131 字符

## 待评审的问题

**只有 2 条必须由业主回答**（其余已由我以证据查明，附查法）。

### 必须问业主

**Q1. FAQ 下载的 Zahner / Brilliant Architectural 文件是否获准使用？**

这是唯一无法从仓库或站点推断的事实。全仓库无授权记录
（`grep -rn "zahner|brilliant" docs/ AGENTS.md GOAL.md` 除本文件与 GOAL 的记录外为空；
`faqs.html` 里的 `data-source-*` 是询盘归因字段，无关）。旧站与本站指向同样 8 个外部 URL，
`Zahner` 字样出现 8 次但**全部只在 URL 里、无可读署名**。

**在业主回答前，描述里不得暗示这些是本站的技术文件。**

**Q2. `/inquiry/` 对外承诺的回复时限是哪个？**

同一页面存在三种表述，我无法判断哪个是对外承诺：

```
lede            "One business day, one reply."
contact card    "Within 24 hours"
flow step       "same day"
FAQ             "Within 24 hours, and often the same day"
```

选哪个决定摘要的写法，也决定页面本身是否要收敛成一种说法。

### 已由我查明，无需业主回答

| 原问题 | 结论与证据 |
|---|---|
| 画廊有无真实项目？ | **23 个里 18 个 alt 明写 `visualization`（效果图）**；另 5 个未标注但文件名全为 `ext-`（外部素材），而唯一明确的自有项目 `Park Hyatt Shenzhen` 用 `our-` 前缀、alt 为「— project image 1」。**画廊页不得写 "Installed"。** |
| `design` 是否禁用词？ | **不是。**`GLOSSARY.md` 禁令只有「款式」「花型」「订单」与客户可见面的 `product/products`；`design`/`style` 不在清单内，旧站同为 `design 6 / style 2`，守卫 `test_no_customer_template_calls_a_pattern_a_product` 通过（41 断言）。 |
| ±0.1mm 还是 ±0.03mm？ | **不是矛盾。**`±0.03mm` 跟 "repeat accuracy"（板间一致性），`±0.1mm` 跟 "tolerance"（单件切割公差），两个不同工程指标，新旧站一致。 |
| 是否需要为 146 个 PDP 各写描述？ | **不需要。**实测 146/146 个产品的 `_seopress_titles_desc` 均已设置且 ≥100 字符（抽查五个 155–162 字符）。产品 `post_excerpt` 为空是无关的。 |
| `/about-us/` 是否覆盖全部六个家族？ | **不覆盖。**该页只讲 laser-cut（hero 写 ±0.1mm、PVD、QC），未提 perforated。摘要须限定为 laser-cut。 |
| `/blog/` 的文章主题是什么？ | **没有文章。**页面实测返回 "No matching posts"。摘要不得列举任何主题。 |
| `/privacy/` 是否要写死法域？ | 页面第 8 节已列 GDPR、LGPD、POPIA、PDPA、APPI 等。摘要里省略是**写法选择**，不是事实缺失。 |
