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
5. **是否需要为 146 个 PDP 各写描述** —— 目前 PDP 走 `%%post_excerpt%%`，
   若产品 excerpt 也为空，则 146 页同样缺描述。**这是更大的面，需单独确认。**
