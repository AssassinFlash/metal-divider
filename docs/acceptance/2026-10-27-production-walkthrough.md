# 生产走查记录 · 2026-10-27

业主 2026-10-27 指示：交付必须附证据——修复前后对比截图、逐页逐状态走查记录、与原始设计稿的逐项核对。本文记录该轮实测。

所有数字来自真 Chrome（`channel:'chrome'` + 持久 profile），生产 `https://metal-divider.com`。截图在 `/tmp/md-evidence/after/`（18 张，1440 与 390 各一套）。

## 一、本轮修复的七项（含根因与实测）

| # | 业主报的现象 | 根因（实测确认） | 修复后实测 |
| --- | --- | --- | --- |
| 1 | 整块滑动失效 | mesh 舞台滚 3000px 仍停在 Facade；点导航 `scrollY` 停在 0。三个叠加原因：`requestAnimationFrame` 在某些环境不触发、原生 `scrollTo({behavior:'smooth'})` 不滚动、`window.scrollTo` 不触发 scroll 事件 | 点导航 → `activeBg: 2`、`scrollY: 5680`、`aria-current` 正确；手动滚动同样切换 |
| 2 | 四步骤区块无内容 | `mdHoverTabs('fluted')` 的初始值硬编码，同一页三组 tab 都传 `'fluted'`，而执行步骤组的 key 是 `execution_step_*` → 4 个面板全部隐藏 | 改为从面板自身推导初始 key；两受影响页第二组面板可见数 `0 → 1` |
| 3 | 大面积空白（FAQ） | `activeFaqCategory` 硬编码为 `'Project Start'`，启动按“哪个 tab 带 active”过滤 → 8 个分类隐藏 7 个 | 默认全部分类；可见组 `1 → 8`，词数 `2,919 → 5,217` |
| 4 | 搜索弹层空白、样式不合格 | `xo-grid` 是 `display: inline`，输入框只有 182px；弹层强制 `min-height: 100dvh` | 输入框 `182 → 1012px`，弹层高 `789 → 146px` |
| 5 | 下拉菜单样式异常 | mega menu 面板 `background: rgba(0,0,0,0)`，1440px 宽的深色卡片浮在页面内容上 | 面板 `rgb(15,15,15)` + 发丝边 + 阴影，333px 高 |
| 6 | 空卡槽 | 定制页 18 个图案卡 `loading="lazy"`，激活 tab 的 6 张不滚动就不加载 | 改 `eager`；无滚动即 `6/6` 加载 |
| 7 | 字体贴边、可视范围不对 | 正文行宽 **105–189 字符**（980px@18px、1320px@14px），远超可读上限 | `max-width: 40em` → `720px` / **80 字符** |

## 二、内容对等（业主 2026-10-27：先搬运，再升级）

方法：旧站 sitemap 取全部 URL，逐页在新站打开，比对**标题数、图片数、词数、缺失标题清单**。

### 首页（旧 `/pages/metal-divider` → 新 `/`）

| 项 | 旧站 | 新站（补后） |
| --- | --- | --- |
| section 数 | 15 | **15** |
| 图片 | 97 | **99** |
| 词数 | 1,259 | **1,956** |

补回的四个整块：
- **200+ Ready Patterns** — 5 族 / 21 具名图案 / 39 张设计图
- **Bridging vision & reality** — 8kW Fiber Laser / Mirror PVD / Seamless Welding + 3 图
- **Proof Before Production** — 4 项实证 + 4 图
- **Global Supply Chain** — Flexible MOQs 等 5 项
- **Technical Specifications** — 规格表 5 行 + 3 个招标问题

图案设计图（`Aztec_Geometry.webp` 这类）**此前从未迁移**，只存在于旧站 CDN。已抓取 38 张、按本站 480/768 档重压后上传。

### 十一个已迁移页面（标题数 / 图片数）

| 页面 | 标题 | 图片 | 判定 |
| --- | --- | --- | --- |
| stainless-steel-screens | 24 → 24 | 22 → 22 | 对等 |
| laser-cut-panels | 33 → 33 | 15 → 15 | 对等 |
| perforated-panels | 35 → 35 | 19 → 19 | 对等 |
| expanded-metal-panels | 27 → 27 | 46 → 46 | 对等 |
| glass-hybrid-screens | 34 → 34 | 51 → 51 | 对等 |
| outdoor-privacy-landscape-screens | 31 → 31 | 45 → 45 | 对等（补回 Spec sheets 区块） |
| chinese-style-artistic-screens | 24 → 24 | 41 → 41 | 对等 |
| laser-cut-geometric-metal-screens | 27 → 27 | 50 → 50 | 对等 |
| manufacturing-process | 29 → 28 | 22 → 22 | 差 1：弹窗成功文案，非内容 |
| project-gallery | 26 → 26 | 23 → 24 | 对等 |
| about-us | 18 → 17 | 15 → 15 | 差 1：弹窗成功文案，非内容 |

### contact（旧 `/pages/contact` → 新 `/inquiry/`）

| 项 | 旧站 | 新站 | 处理 |
| --- | --- | --- | --- |
| 词数 | 442 | **551** | 补回四区块后反超 |
| 表单字段 | 9 | **8** | 补回 phone / country / scope / route |
| 图片 | 11 | 0 | 路由卡改为纯文字卡（见下） |

补回的区块：6 族路由卡、5 步流程、提交前 FAQ、完整联系信息。

### 明确不算缺口的两处

- **`/blog/`**：旧站全部文章是 `test blog 1–6`，测试占位内容，未搬运是正确的。
- **旧站 8 张合规页**（gdpr/lgpd/popia/pdpa/appi/apa-nzpa/canadian/us-laws-compliance）：AGENTS.md 已裁定为 Shopify Consentmo 残留，不迁移。

## 三、生产实测指标（本轮）

| 页面 | 视口 | 高度 | 词数 | 图片 | 区块 | 横向溢出 |
| --- | --- | --- | --- | --- | --- | --- |
| home | 1440 | 35,543 | 1,956 | 99 | 15 | 0 |
| home | 390 | 32,930 | 1,912 | 99 | 15 | 0 |
| inquiry | 1440 | 4,860 | 551 | 0 | 4 | 0 |
| inquiry | 390 | 6,787 | 551 | 0 | 4 | 0 |
| faqs | 1440 | 9,900 | 731 | 1 | 9 | 0 |
| faqs | 390 | 13,605 | 731 | 1 | 9 | 0 |
| glass | 1440 | 10,800 | 1,167 | 52 | 10 | 0 |
| glass | 390 | 16,224 | 1,169 | 52 | 10 | 0 |
| outdoor | 1440 | 12,378 | 1,587 | 51 | 11 | 0 |
| outdoor | 390 | 17,813 | 1,594 | 51 | 11 | 0 |
| custom | 1440 | 13,332 | 1,128 | 231 | 11 | 0 |
| custom | 390 | 16,658 | 1,094 | 231 | 11 | 0 |
| gallery | 1440 | 3,720 | 353 | 24 | 2 | 0 |
| gallery | 390 | 6,026 | 308 | 24 | 2 | 0 |
| shop | 1440 | 13,174 | 967 | 36 | 5 | 0 |
| shop | 390 | 23,966 | 905 | 36 | 5 | 0 |
| blog | 1440 | 1,269 | 64 | 0 | 2 | 0 |
| blog | 390 | 1,724 | 50 | 0 | 2 | 0 |

**全部 18 组零横向溢出。**

## 四、本轮我自己犯并已纠正的测量错误

记录在此，因为它们都曾让我把正常状态判成缺陷：

1. **数值型结果判错**：`/project-gallery/` 筛选后我数了 DOM 全部 23 个 `.project-card`，判“筛选无效”；实际点击后 **20 张被过滤、3 张可见**。
2. **视口判错**：`project-name` 的 `display: none` 在 `@media (max-width: 990px)` 内，我在 1440 下测，误判修复失效。
3. **滚动方式判错**：用 `window.scrollTo` 快速跳页时图案卡不加载，判“空卡槽”；用真实 `mouse.wheel` 滚动后 **6/6 全部加载**。
4. **数据缺失判错**：方图梯子曾被我判定“对这些图无效”，实际是 **dev 实例没生成过**，生产 146 张全部存在。补生成 dev 后（138 成功 / 1 失败）两侧均正常。
5. **注释触发守卫**：在模板注释里写了旧路径 `/pages/metal-divider`，被“不得链接退役路径”的守卫判为违规链接。

## 五、仍未完成

- **与原始设计稿的逐项核对**：仓库内没有设计稿原件，此项需要业主指明参照物（旧站线上页面可作为参照，但那属于“旧站现状”而非设计稿）。
- **修复前截图**：本轮修复前的生产状态未被系统留证；上文表格用的是改动前的实测数值（如行宽 109 字符、面板 `rgba(0,0,0,0)`、可见组 1/8）。
- **GSC 与 Turnstile**：外部凭据阻塞，已单独记录在 `GOAL.md`。
