# 内容改写边界(票 #8)

日期:2026-10-24。执行会话定稿(业主 2026-10-24 裁定:grilling 票按执行会话推荐定稿,业主保留否决)。

本文钉死「视觉复刻为先、允许升级」的边界:逐页型 ledger(验收基准)、文案质量流程、编码债台账、像素门对照基准、合规 8 页处置。升级的唯一目的是修复旧站积压 HTML/CSS/JS 编码问题(AGENTS.md §2);一切「顺手改进视觉/文案」不在此列。

## 0. 依据事实(已定,不再研究)

| # | 事实 | 来源 |
|---|---|---|
| F1 | 视觉复刻为先、允许升级;升级目的是修复积压编码问题;升级与复刻的边界由本票钉死、逐页型记账;博客全重做;旧代码一行不进新站 | AGENTS.md §2/§5 |
| F2 | PDP 文案是模板生成:Design intent 标题 146 个全同、正文 15 种句式变体(风格名代入);Where-works 146 个 payload 完全相同;Technical FAQ 146 个 payload 完全相同;Material route 线上渲染 146 个全部同一值 "304 / 316 stainless";16 产品镂空率 = "Project set" 待补数 | #2 体检 docs/research/2026-10-24-shopify-content-audit.md §2 |
| F3 | Admin 导出 31 页 = 24 已发布 + 7 草稿;7 张草稿全部是 2026-06 内容管线测试残留(body_html 0–45 字符,template_suffix 全是 *-test / *-production 试验版),从未发布、无公开 URL | #10 导出 data/shopify-admin-export/pages.json(published_at 判定) |
| F4 | 产品 metafields 真实数据底账(146 产品,2934 条):pattern_summary / pattern_inspiration 仅 43 份(42 dsd + 1 parasoleil);material_options 仅 49 份(48 份有效 5 材质清单 + 1 份垃圾值),其余 97 个无此字段;openness_percent 130 份真值,16 缺失与 inferred_from_pattern(50)完全重合;motif_tags 21 种、visual_density 4 种、repeat_behavior 2 种、notable_effect 仅 2 种、best_for 仅 2 种 | #10 导出 products.json metafields nodes 逐 key 唯一值统计(2026-10-24) |
| F5 | slug 全保留:R2 /products/<handle>、R3 /collections/<handle>、R5 /pages/<handle>;R4 /collections/all→/shop/;R6 旧 landing /pages/metal-divider→首页;R7 合规 8 页→统一隐私页(slug PENDING);裸 /collections/ rewrite 优先级留评审站实测 | #4 docs/research/2026-10-24-seo-baseline.md §3/§4 |
| F6 | 询盘链已规格化:旧 /pages/contact 301→/inquiry/;新感谢页 /inquiry/thank-you/(303 目标),旧感谢页 URL 301;全站唯一表单在 /inquiry/;WhatsApp 单号 85264731687 | #7 docs/specs/07-inquiry-chain.md D2/D3/D5 |
| F7 | 博客重做:废弃旧三分类,5 个 topic-cluster 分类;复用 de-linker 四个博客 LiveCanvas 模板面;内容工作流 = 业主写 Markdown → yansir-md | #5 提案 + AGENTS.md §2 |
| F8 | 多语言推迟二期,先做完整英语站;9 语种翻译 QA(机器直发 vs 抽检)随之移出本工程首期 | 业主 2026-10-24 深夜补充,AGENTS.md §2 |
| F9 | 旧站编码债实测(2026-10-24,渲染 HTML /tmp/md-r-work/raw/html/en/,#2 脚本可复跑;主题 ~/Documents/shopify-builder/theme 只读参考):各页型渲染体积 209–439 KB;`style="` 内联属性每页 13–191 处(首页 191、laser-cut-geometric 90、custom-screens 80);`<style>` 内嵌块每页 9–16 个;`<script>` 每页 12–24 个(custom-screens 24);外链 CSS 每页仅 1 条(样式全部走内联+内嵌);主题 assets:main.css 552,599 B、xo-webcomponents.min.js 408,027 B(第三方 UI 组件包)、三条 Tailwind 产物并存(tailwind-production.css 121,121 B + tailwin.min.css 22,931 B + gp-global.css 68,386 B);sections 266 个 / snippets 346 个,其中 `-vN` 版本堆叠文件 56 个(banner-v1…v11、about-v5…v15 等);合规页 body_html 单页 31–36 KB 平铺 | 本票实测(方法:regex 计数渲染 HTML body;`ls`/`wc` 计数主题目录) |
| F10 | 旧站页面正文是真内容(16 张内容页 3.4k–29.6k 字符,含 faqs 29.6k),PDP 逐品真数据只有 标题/pattern code/style/open area(缺 16)/图片/来源标签;wayfinder 目的地明文「146 图案 + 17 页内容平迁」 | #2 §2/§5 + #1 Destination |
| F11 | 呈现层规则:LC 原生 tag → Woo 原生 → WindPress;PHP 零内联样式零设计决策;插件零 CSS;禁止第二条 Tailwind 构建;自定义代码最后手段须穷尽证明 | AGENTS.md §5 呈现层与原生优先 |
| F12 | 本地栈已定:LiveCanvas + WindPress(wp.org 免费)+ CI 单工作流 check.yml(SHA 钉 action,零 secrets) | #14 docs/specs/14-local-stack.md |

## 1. 决策总表

| # | 决策 | 结论 | OPEN(业主否决点) |
|---|---|---|---|
| D1 | 三分类 ledger 与分布 | 复刻 0 / **复刻+编码升级 11 页型** / **重设计 3 组**;另设「内容修复」修饰符(仅 PDP);退休 4 类旧页 + 弃 7 草稿。编码升级不豁免像素门:视觉仍像素对齐,升级只改结构与行为 | — |
| D2 | PDP 模板文案处置 | 模板文案废弃,按「真数据 + 9 系列句式组」重建;43 份既有 pattern_summary 逐份过审沿用;FAQ 雷同块降为全站单源组件;Material route 改为「全局基线 + 49 份逐品覆盖」;16 个镂空率缺口业主补数、未补前渲染 "On request",禁止编造 | O1 镂空率 16 值;O2 材质全局基线确认 |
| D3 | 页面级文案处置 | 16 张内容页正文**平迁**(F10),只做事实修正且逐条入台账;不在复刻页顺手改文案——保像素门可判定 | — |
| D4 | 文案评审分层 | T1 全量对抗评审(新写/重写文案)/ T2 模板句式组一次过审 + 抽样 / T3 平迁免审(事实修正除外);EN only,9 语种 QA 随二期移出 | — |
| D5 | 编码债清单化 | 8 项 DEBT 台账 + 8 条可 CI 判定的 CHECK;升级完成 = 该页型 CHECK 全绿 + 像素门通过 + 台账行闭档(证据链接) | — |
| D6 | 像素门基准与掩膜 | 基准 = 旧站线上同 URL 截图(#4 URL 集,切换前采集,清单+sha256 入库、图档不入库);掩膜三类 M1 动态区 / M2 内容修复区 / M3 升级变更区,逐项报业主;M2 双轨验收(几何检查 + 文案评审) | — |
| D7 | 合规 8 页处置 | 全部退休,Consentmo 生成文本 100% 弃用;新建一张 /privacy/ 页(大纲见 §7)+ CookieYes 仅严格必要类起步 | O3 保留年限/数据中心两个填空 |
| D8 | gradient 空系列 | 不建档案页(0 产品薄页);/collections/pattern-gradient 301→/shop/;导航不出现。修正 #4 R3 的 2 行(gradient EN+ZH) | O4 业主否决窗口 |

## 2. D1 逐页型 ledger(验收基准)

### 2.1 分类定义

| 分类 | 定义 | 验收 |
|---|---|---|
| 复刻 | 结构照旧重排、内容原样,无列名升级项 | 全页像素门,掩膜仅 M1 |
| **复刻+编码升级** | 结构按 §5 呈现层规则重写(语义 HTML、单一 WindPress 管线、零内联样式),**视觉像素对齐旧站**;行为变化仅限本表列名的升级项 | 像素门 + 升级项逐项 M3 掩膜/核对 + DEBT CHECK 全绿 |
| 复刻+**内容修复**(修饰符) | 页型内存在成片文案替换区;布局骨架不变 | 内容区 M2 双轨(§5.3),其余区域照常像素门 |
| 重设计 | 无旧站视觉基准 | 设计评审(业主/原型票),不适用像素门 |

判定依据(F9):**没有任何页型够格进「纯复刻」**——渲染债(style 属性 + style 块 + 脚本数)在全部页型上存在(最少 9 个 `<style>` 块/页),而 §5 规则(LC+WindPress、零内联样式)本身就是新栈的强制实现标准,不是可选项。所以「复刻」类在本站为空集,三分类里它只作为词汇保留,防止实现票把「结构重写」误报成升级。

### 2.2 ledger 主表

「基准 URL 集」指像素门采集清单的来源,全量 385 行见 #4 data/redirect-map.csv 的 source 列(EN+ZH);同一页型多实例时按 §5.1 采样。

| # | 页型(实例数) | 分类 | 内容处置 | 列名升级项(掩膜类别) | 基准 |
|---|---|---|---|---|---|
| L1 | 首页(1) | 复刻+编码升级 | 平迁(D3) | U1 4 处表单→询盘链 CTA(M3,行为变化);U2 交互组件去 xo-webcomponents 依赖逐个重实现(轮播/手风琴/导航,枚举清单在实现票);U3 内联样式/14 个 style 块→WindPress;U4 91 个内联 SVG 图标单源化 | `/` |
| L2 | 图案 PDP(146,1 模板) | 复刻+编码升级+**内容修复** | 见 D2(§3) | U1 内容修复区四处(M2);U2 材质路线值改为数据驱动(M2);U3 加入图案清单入口(#7 D1,按钮区 M3);U4 图库灯箱/缩略图交互重实现 | 每系列代表品 + 全量自动 diff(§5.1) |
| L3 | 系列档案页(8 建,gradient 砍,见 D8) | 复刻+编码升级 | 标题/描述平迁;系列描述真值在 collection 数据(#10 已补) | U1 筛选/排序交互重实现(枚举);U2 分页(abstract 90 品)重实现;U3 内联样式清理 | `/collections/<handle>` ×8 |
| L4 | Pattern Series Hub(/collections/ 裸路径,1) | 复刻+编码升级 | 平迁 | U1 同 L3;U2 rewrite 优先级评审站实测(#4 遗留项) | `/collections/` |
| L5–L12 | 产品族页 8 张:laser-cut-geometric / glass-hybrid / outdoor-privacy / chinese-style / stainless-steel(hub)/ laser-cut-panels / perforated-panels / expanded-metal | 复刻+编码升级 | 平迁 | U1 hero 视频(5 张 mp4)过终稿媒体管线后原位替换(M1 媒体指纹除外);U2 表单→CTA(M3);U3 内联样式/style 块→WindPress;U4 交互组件重实现 | `/pages/<handle>` ×8 |
| L13 | manufacturing-process(1) | 复刻+编码升级 | 平迁 | 同 U3/U4 通用项 | `/pages/manufacturing-process` |
| L14 | project-gallery(1) | 复刻+编码升级 | 平迁(64 图过媒体管线) | U1 灯箱/筛选重实现;U2 U3 通用 | `/pages/project-gallery` |
| L15 | about-us(1) | 复刻+编码升级 | 平迁 | U3/U4 通用 | `/pages/about-us` |
| L16 | faqs(1) | 复刻+编码升级 | 平迁;吸收 PDP 雷同 FAQ 中仍成立的全站级 Q&A(D2-FAQ) | U1 折叠交互重实现;U2 U3 通用 | `/pages/faqs` |
| L17 | custom-metal-divider-screens(1) | 复刻+编码升级 | 平迁(12.4k 正文;正身是 published 版,7 张测试草稿不是) | U1 2 处表单→询盘链(M3);U2 24 个 script 收敛到白名单;U3 156 张 img 过媒体管线;U4 U3 通用 | `/pages/custom-metal-divider-screens` |
| L18 | /shop/(承接 /collections/all) | 复刻+编码升级 | 档案页无独立文案 | U1 网格/分页重实现;U2 U3 通用 | `/collections/all` |
| R1 | 博客(index/single/category/search 四面) | 重设计 | 全新(#5 IA + #7 内容工作流) | — | 无 |
| R2 | 询盘功能面(/inquiry/、/inquiry/thank-you/) | 重设计(新功能面) | #7 已规格,不在本票重开 | — | 无 |
| R3 | /privacy/(新,见 §7) | 重设计(新页) | 全新撰写 | — | 无 |
| X1 | contact | 退休:301→/inquiry/(F6) | 正文弃(其联系事实已被 F6 供给覆盖) | — | — |
| X2 | custom-metal-divider-screens-thank-you | 退休:301→/inquiry/thank-you/(F6) | 弃 | — | — |
| X3 | /pages/metal-divider 旧 landing | 退休:301→首页(F5 R6) | 弃 | — | — |
| X4 | 合规 8 页(gdpr/lgpd/popia/pdpa/appi/apa-nzpa/canadian/us-laws-compliance) | 退休:301→/privacy/(D7) | Consentmo 生成文本 100% 弃用 | — | — |
| X5 | 草稿 7 张(全部 *-test / *-production 试验版) | 弃:不迁移、不映射 | 无公开 URL、无索引面、正文 0–45 字符(F3) | — | — |

分布(供 #1 台账记账):复刻+编码升级 **11 个页型**(L1–L18 行,其中产品族页 8 张属同族异构);重设计 **3 组**;退休 **11 张旧 URL** + 草稿 7 张弃。

## 3. D2 PDP 模板文案处置(内容修复规格)

### 3.1 处置表

PDP 模板区块逐块裁定(证据 F2/F4):

| 区块 | 旧站状态 | 处置 | 理由 |
|---|---|---|---|
| 规格条 Pattern code / Style / Open area | 逐品真数据 | 数据平迁;16 缺口渲染 "On request"(O1 业主补数后回填真值) | 真数据;镂空率是核心规格,缺失不得伪装成数值,禁止编造 |
| Material route | 线上 146 全同值,系模板默认;metafield 真数据仅 48 份有效 | **全局基线(业主确认的一句材质能力声明,全站单源)+ 逐品覆盖**(48 份有效清单按品渲染;1 份垃圾值 "architectural metal panel systems…" 剔除不用) | 单值冒充逐品数据是模板债本身;渲染逻辑必须数据驱动。全局基线文案 = O2,基线事实以旧站线上表述为默认,业主可否决 |
| Design intent | 标题 146 全同、正文 15 句式变体 | 标题保留为全站单源组件(一处定义);正文按「9 系列句式组 × 真实属性槽」重建(T2 评审,§4);43 份既有 pattern_summary/pattern_inspiration 逐份改写到自家语气后逐品使用(T1) | 模板文案无信息量(风格名代入同一句);真属性(code/style/motif_tags/visual_density/repeat_behavior/openness/best_for)足够支撑差异化的诚实文案;43 份既有真文案是资产,改写而非重造 |
| Where this pattern works | 146 payload 完全相同 | 收敛为**全站单源组件**,文案过一次 T1 评审;不再逐品复制 146 份 | 同一字符串 ×146 是纯冗余;单源后改一处即全站生效 |
| Technical FAQ(3 问) | 146 payload 完全相同 | PDP 内降为同一单源组件(视觉槽位保留,像素门照比);全局 FAQ 页(/pages/faqs)为 FAQ 唯一权威面,PDP 组件链接锚点过去 | 雷同 FAQ 在 146 页重复是内容债;B2B 用户路径上它承担的是「常见疑虑就地解答」,保留槽位但内容单源 |
| meta description | 146 份,中位 160 字符,SEO 状态良好 | 平迁;仅在系列句式组定稿后按需微调(逐处入事实修正台账) | F10;meta 不进像素门,但为保 SEO 连续性以不动为默认 |
| body_html | 真内容仅约 10 个 | 平迁(有内容的);无内容的不再补占位 | 真内容是资产;占位无价值 |

### 3.2 与像素门的关系(本文最关键的边界定义)

PDP 是一个模板 ×146 实例。像素门基准 = **同一 URL 的旧站截图**,因此规格条数值、图片等同品数据天然可比,不需掩膜。只有内容真的变了的区域进 M2:

- **M2 内容修复区** = Design intent 正文、Where-works 文案、FAQ 文案、Material route 值。掩膜粒度 = 该区块的包围盒。
- M2 区验收**双轨**:(a) 几何检查——区块包围盒的位置/宽度、字体、行高、区块次序与旧站一致(DOM 度量断言,不比字形,因为文字长度必然变化);(b) 文案评审——该区文案按 §4 分层过审并留档。两轨都过才算过。
- M2 区之外(gallery、规格条布局、面包屑、CTA 栏、页脚)照常全像素比对,差异 = 0。

这个「同 URL 基准 + 变更区掩膜 + 双轨验收」的机制同时适用于所有复刻页的**事实修正**(D3):修正一行掩一行,掩膜逐项报业主(AGENTS §5)。

## 4. D3+D4 文案质量流程

### 4.1 三层评审(EN only;9 语种 QA 随二期移出,F8)

| 层 | 适用 | 流程 |
|---|---|---|
| **T1 全量对抗评审** | 一切新写/重写文案:privacy 大纲成文、CookieYes 横幅与偏好中心文案、UI 字符串表(按钮/标签/toast/空态/错误提示,一张总表)、404/空态、9 个系列句式组、43 份 pattern_summary 改写、全局材质基线、Where-works 单源文案、FAQ 标题与新增 Q&A、内容页事实修正的替换句 | AGENTS §5 既有规则:初稿 → 对抗评审(独立评审会话;清单:与真数据逐项对账、规格声明可证、B2B 语气、EN 质量、无夸大承诺)→ 定稿留档 `docs/copy/`(公开文案,无密钥,可入库) |
| **T2 模板+抽样** | PDP 属性槽句式(146 份属性代入文本) | 句式模板(9 系列 × 每系列 1 组)过一次 T1;属性槽是数据不是散文;每系列抽 2 品整页走 T1(共约 18 品) |
| **T3 平迁免审** | 16 张内容页与 PDP 既有正文、meta description、43 份 summary 的**未改动部分** | 免对抗评审;唯一允许的改动 = 事实修正,逐条入 `docs/specs/08-fact-fixes.csv`(页/位置/原文/新文/依据) |

博客文章正文:业主亲写的 Markdown(§2 博客裁定)是业主内容,不进对抗评审(业主对自己文字有终审权);博客**chrome 文案**(分类标签、订阅引导、面包屑等)属 T1 字符串表。

### 4.2 为什么内容页平迁而不是顺手改写(D3 理由)

①业主目的地明文「17 页内容平迁」(F10);②像素门的可判定性依赖内容不变——每改一句就多一块掩膜,评审成本随掩膜数线性涨;③文案升级不是本票授权的升级目的(升级目的只有编码债,F1);④要改的文案(PDP 模板文案)已在 D2 单独处置。若实现中发现内容页存在**事实错误**(数字、工艺、参数),走事实修正通道,不整段重写。

## 5. D5+D6 编码债台账与像素门基准

### 5.1 基准采集协议

- **采集对象**:#4 data/redirect-map.csv 的 source URL 全集,**只采 EN**:首页 1 + PDP 146 + 系列 8(gradient 不建,见 D8)+ Hub 1 + 内容页 13(体检的 16 张内容页减去首页单列、退休的 contact 与旧 landing)+ /collections/all 1 = **170 张**。ZH 不入像素门基准——首期只做 EN(F8),新站没有 ZH 页可对;旧 ZH 半壳已在 #4 实锤不抢救。退休页(contact/感谢页/旧 landing/合规 8)不建不复刻,不采集。
- **采集时点**:旧站内容冻结、切换解约之前(AGENTS §5「移除旧通道前必须先证明对等」);由第一个复刻实现票执行,一次性采全。
- **协议**:同一浏览器同机同视口、禁动画、等字体与图片加载(AGENTS §5);每页型另存 DOM 快照(供 M2 几何断言与 DEBT 复核)。
- **存储**:截图与 DOM 快照入本地验收档案(gitignore,循 #2 media-raw 先例);仓库只入清单 `docs/acceptance/pixel-baseline-manifest.csv`(URL、文件名、sha256、页型、掩膜清单引用)。公开仓库零密钥不受影响(截图为公开页面)。
- **PDP 采样**:全量 146 自动 diff 跑回归;人审阻断门 = 每系列 1 品 + 16 个镂空率缺口品全数 + 43 份既有 summary 品抽样,共约 30 品。

### 5.2 掩膜规则(承 AGENTS §5,细化三类)

| 类 | 定义 | 举例 | 报业主 |
|---|---|---|---|
| M1 动态区 | 真实随时间/会话变化的内容 | 图案清单徽标数、表单已填值、日期、随机轮播帧;媒体管线替换后的图片**指纹**变化(同图重压缩) | 逐项列出,注明原因 |
| M2 内容修复区 | 本票裁定的文案替换区 | PDP 四处、事实修正行 | 逐项列出 + 文案评审档号 |
| M3 升级变更区 | 列名升级项造成的结构/行为差异 | 表单→CTA、交互组件重实现的 hover/动画细节 | 升级台账逐项过目(即本表 2.2 列名项) |

掩膜外差异 = 0,任何类别的掩膜都必须能在 ledger/升级台账里找到对应行——**没有台账行就没有掩膜**,这是防「掩膜漂移」的硬约束。

### 5.3 编码债台账(DEBT)与完成判定

升级完成的判定标准,全部可由 CI 或评审站一条命令判定(数值均有 F9 实测来源;新站标准均由 AGENTS §5 规则推出):

| ID | 债 | 旧站证据(F9) | 新站标准(CHECK) | 判定 |
|---|---|---|---|---|
| DEBT-1 | 内联样式属性 | `style="` 每页 13–191 处(首页 191) | **CHECK-1**:渲染 HTML `style="` 计数 = 0 | curl 评审站页 → grep -c |
| DEBT-2 | 每节一个 `<style>` 块 | 每页 9–16 个 `<style>` | **CHECK-2**:`<style` 计数 = 0;**CHECK-3**:外链 CSS 每页恰 1 条(WindPress 编译产物) | 同上 |
| DEBT-3 | 脚本堆叠 + 第三方 UI 包 | `<script>` 每页 12–24;xo-webcomponents.min.js 408 KB;main.css 552 KB | **CHECK-4**:每页外链脚本 ≤ 5 且在白名单(LC 运行时 + Turnstile + 询盘插件 + WindPress 零 JS;白名单冻结在台账,加项须过票);**CHECK-5**:依赖树无 xo-webcomponents 等通用 UI 组件包 | curl + 构建图审计(评审站实测 LC 实际脚本数后回填白名单) |
| DEBT-4 | 多条 Tailwind/样式管线并存 | tailwind-production.css 121 KB + tailwin.min.css 23 KB + gp-global.css 68 KB 三产物并存 | **CHECK-6**:仓库无第二条 Tailwind 构建(仅 WindPress 管线) | CI:扫描 tailwind.config* 与 npm 构建图 |
| DEBT-5 | 模板版本堆叠 | sections 266 / snippets 346,`-vN` 文件 56 个(banner-v1…v11、about-v5…v15) | **CHECK-7**:LiveCanvas 模板无版本族命名;被接替模板在同一提交删除(不留 -v2 尸体) | CI:模板目录命名审计 `-v[0-9]` |
| DEBT-6 | 表单复制粘贴 | contact 4 表单、首页 4、custom-screens 2(F9/#2 §6) | **CHECK-8**:`<form` 仅存在于 /inquiry/(+wp-admin);其余一切 CTA 是链接 | curl 全页型抽查 |
| DEBT-7 | 图标重复内联 | 每页内联 `<svg` 35–91 个(首页 91) | 同一字节串的内联 SVG 在单页内不得出现 >1 次(单源引用);仅装饰性唯一图形豁免 | DOM 快照去重断言 |
| DEBT-8 | 渲染体积 | 每页 HTML 209–439 KB | 结构性 CHECK 全绿后的观测指标(首屏 HTML ≤ 150 KB 为软目标,来源:旧站最小值 209 KB 降 30%;不作阻断门,perf 另立项) | 评审站度量入台账 |

**页型闭档条件**:该页型 CHECK-1…8 全绿 + 像素门通过(掩膜外 0 差异,掩膜均有台账行)+ 升级项(2.2 列名)逐项核对 + 台账行写明证据链接(CI run / 截图 diff / DOM 断言输出)。任一不满足,该页型不许从「升级中」转「完成」。

## 6. D8 gradient 空系列(对 #4 的修正)

- 事实:pattern-gradient 系列 0 产品(#2 §1),却仍在导航与 sitemap(F9 同源)。
- 决定:不建档案页;`/collections/pattern-gradient`(EN+ZH)301→`/shop/`;导航与页脚不出现;系列数据保留在库里,未来补品后随常规流程上线。
- 对 #4 的修正:redirect-map.csv R3 的 gradient 2 行目标由 `/collections/pattern-gradient` 改为 `/shop/`(实现票执行时改,不在本票动 #4 分支)。
- 理由:空档案页是薄内容页,对 SEO 与用户都是负资产;301 到 /shop/ 保住权重入口。
- O4:业主否决窗口——若业主计划近期补 gradient 图案,改回 R3 原映射即可。

## 7. D7 合规 8 页 → /privacy/ 大纲

旧 8 页正文是 Consentmo 生成的合规壳(单页 body_html 31–36 KB 平铺,渲染主文本 ≤2.4k,F2/F9),无一句业主手笔,100% 弃用(与 §5「旧代码一行不进新站」同构:旧合规**文本**也不进新站)。slug 定 `/privacy/`(落定 #4 R7 的 PENDING)。

新页大纲(T1 全量评审,EN):

1. **我们是谁与本页范围**——询盘制 B2B 站,无账户/无结账/无支付;只处理询盘相关个人数据。
2. **我们收集什么**——询盘表单四字段 + 附带的图案清单条目;服务器日志;CookieYes 同意记录;图案清单 localStorage 不出浏览器、仅在提交时随表单上送;WhatsApp 对话发生在 WhatsApp 自家受其政策约束。
3. **为什么与法律基础**——GDPR Art. 6(缔约前步骤/合法利益/同意)为主表述,映射各法域。
4. **Cookie 与本地存储表**——CookieYes 分类;上线仅「严格必要」类(Turnstile、询盘链、图案清单、会话);无分析/广告类;表格列 名称/用途/类别/存续期。
5. **共享与处理者**——主机(Hostinger)、CDN/WAF(Cloudflare)、邮件(wp_mail/Hostinger mail);不出售数据、无追踪像素。
6. **保留**——询盘记录默认 5 年(B2B 跟踪周期惯例;O3 业主确认),期满删除或匿名。
7. **跨境传输**——数据中心位置(O3 业主填空,业主才知道主机机房区位)+ 标准条款引用。
8. **你的权利(权利矩阵表)**——一行一法域:GDPR(欧盟/英国)、LGPD(巴西)、POPIA(南非)、PDPA(新加坡/泰国,按旧页覆盖面)、APPI(日本)、澳大利亚 Privacy Act + 新西兰 Privacy Act(apa-nzpa 的两个法)、加拿大 PIPEDA/魁北克 Law 25、美国州法(CCPA 级);列 访问/更正/删除/投诉 途径,行使入口 = info@metal-divider.com(对客 mailto,#7 D4)。
9. **儿童、安全措施、政策变更、联系方式**。

CookieYes 集成边界:横幅与偏好中心文案 = 客户可见文案(T1);仅严格必要类预载;consent 记录留存;与 Turnstile/询盘链的类别归属按 #7 D7 归严格必要。

## 8. 实现票如何消费本文

- 复刻实现票以 §2.2 为验收清单;每个页型开工前先跑该页型的基准采集(§5.1),完工跑 CHECK(§5.3)+ 像素门。
- PDP 文案票输入 = §3.1 处置表 + §4 分层;产出 = 9 系列句式组 + 43 份改写 + UI 字符串表,全部入 `docs/copy/`。
- /privacy/ 票输入 = §7 大纲;CookieYes 文案同批 T1。
- 事实修正随时入 `docs/specs/08-fact-fixes.csv`(T3 通道),像素门按行掩膜。
- OPEN 清单(O1–O4)集中在本票 issue 评论向业主报备,否决即改,不阻塞其余裁定生效。

## 9. OPEN(业主否决/填空点,不阻塞)

| # | 事项 | 默认值(否决前生效) |
|---|---|---|
| O1 | 16 品镂空率补数(atherton、baleine、coconut-dreaming、forrest-perforated、herringbone、hiroko、la-pente-gradient、lombok、nara-gradient、painted-daisy、papyrus、ripples、skyfall-gradient、tree-of-life、trees-by-the-lake、winderoo) | 渲染 "On request",不编造 |
| O2 | Material route 全局基线确认 | 以旧站线上表述 "304 / 316 stainless" 为基线文案,48 份逐品清单覆盖 |
| O3 | 隐私页保留年限(默认 5 年)与主机数据中心区位(填空) | 5 年 / 待填 |
| O4 | gradient 空系列砍(301→/shop/) | 按砍执行 |
