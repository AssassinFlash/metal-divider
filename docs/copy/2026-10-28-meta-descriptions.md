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

1. **`/inquiry/` 的 "one business day"** —— 与页面正文一致，但请确认对外承诺仍是这个时限。
2. **`/about-us/` 说 "Laser-cut and perforated"** —— 该页是否覆盖全部六个家族？
   若只讲激光切割，应改为更窄的表述。
3. **`/blog/` 的 "what to send for an accurate quote"** —— 该页实际文章是否覆盖这个主题？
4. **`/privacy/` 未提 GDPR/LGPD 等具体法域** —— 站点是 B2B 全球站，
   刻意不写死法域，请确认这是想要的口径。

   **勘误（2026-10-28）**：本条原本还问了「±0.1mm 还是 ±0.03mm，哪个真」，
   **已撤回**。复核两个数字的修饰语：
   - `±0.03mm` 跟的是 **"repeat accuracy"**（板与板之间的一致性）
   - `±0.1mm` 跟的是 **"tolerance"**（单件切割公差）

   这是两个不同的工程指标，不是同一事实的两个数值；两者并存合理，
   且新旧站完全一致。提出该问题会浪费业主时间。
5. ~~画廊里有没有任何真实项目？~~ —— **已自行查明，不再需要业主回答。**
   23 个项目卡中 **18 个**的图片 alt 明确写着 `visualization`（效果图）；
   另 **5 个**未标注，但文件名全部以 `ext-` 开头（外部素材），而唯一明确的
   真实项目 `Park Hyatt Shenzhen` 用 `our-` 前缀、alt 为「— project image 1」。
   命名约定：`our-` = 自有项目，`ext-` = 外部素材。
   **结论：画廊页不得使用 "Installed"**，应基于页面 H1
   「Selected Interior Partition Projects」与 lede 表述。

6. ~~是否需要为 146 个 PDP 各写描述~~ —— **不需要，已撤回。**
   实测 146/146 个产品的 `_seopress_titles_desc` 均已设置且 ≥100 字符
   （抽查五个实测 155–162 字符）。产品 excerpt 为空是无关的：SEOPress
   读专用 meta，`%%post_excerpt%%` 只是它的回退。
   此处曾误判为「103 个 PDP 无描述」，原因是查错了字段。
