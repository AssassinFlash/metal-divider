# 2026-10-27 · 审计与修复记录（第二轮）

本文件只记录**经实测证实**的事。每条都写明证据来源、修前修后的数字、以及测量工具。凡我不确定的,写在「未解决」里,不写成结论。

## 0. 先纠正上一轮我自己的错误

上一轮我报过「732 条内容缺口」和「一页 4 份表单撞 13 个重复 id」。两条都是**错的**,原因不同,都必须记下来以免重犯。

### 错误一:缺口数被缓存污染

生产站 HTML 带 `cache-control: public, max-age=604800`(一周)。实测响应头:

```
x-litespeed-cache: hit
age: 229
cache-control: public, max-age=604800
```

同一个 URL 加查询参数绕过缓存后,`x-litespeed-cache: miss`,内容立刻不同——生产弹窗表单在缓存副本里只有 5 个字段,绕过后是完整 9 个。

**结论**:所有不绕缓存的抓取测的是缓存,不是站。已经写进 `tools/audit/capture.py`,现在强制追加 `mdaudit=1`。

### 错误二:重复 id 在 HTML 注释里

生产页有 4 处 `.md-inquiry-app`,但其中 3 处在 `<!-- -->` 内。剥掉注释后:

```
live ids total: 14   unique: 14   duplicated: {}
```

所以「13 个 id 各重复 4 次」是数注释数出来的。

**但这条错误里藏着一个真缺陷**,见 §2。

## 1. 手风琴展开空白(业主截图指出的那一处)

**业主提供的对照**:左图 `Hotel Designer` 行是展开态(金色标签 + 向上箭头 + 金色分隔线),但展开体是空的;右图才是应有的四行内容。业主原话「有跟没有差别就是天差地别」。

**修前实测**(生产 `https://metal-divider.com/stainless-steel-screens/`):

| 行 | is-active | 面板 hidden | 面板高 | grid-template-rows |
|---|---|---|---|---|
| Hotel Designer | true | **true** | 0 | 1fr |
| Commercial Designer | false | false | 0 | 0px |
| Resort Owner | false | true | 0 | 0fr |
| Hospitality Designer | false | true | 0 | 0fr |

内容在 DOM 里(13–18 词),被 HTML `hidden` 属性强制隐藏。

**根因**:同一页两组控件共用 `data-sps-panel`——上方四个项目 Tab 是 `1..4`,下方四行手风琴是 `0..3`。通用控制器 `bindSwitchable` 把全页同名属性合成一组,再按 `panel.getAttribute(panelAttr) !== value` 判断可见性,于是 0 号(正是展开那行)不匹配任何 Tab 值而被隐藏。

**修法两层**:
1. `bindSwitchable` 按**控件所在区块**分组(`regionOf`),一个控件只管辖自己容器内的面板;抽出 `bindSwitchableGroup` 承载原逻辑
2. 手风琴面板改名为 `data-sps-story-panel`,从命名上不可能再撞

**修后实测**(dev,真 Chrome,等过渡结束后测量):

| 操作 | 展开的行 | 面板高 | 场景图 index |
|---|---|---|---|
| 初始 | Hotel Designer | 167 | — |
| 点第 2 行 | Commercial Designer | 167 | 1 |
| 点第 3 行 | Resort Owner | 167 | 2 |
| 点第 4 行 | Hospitality Designer | 167 | 3 |
| 点回第 1 行 | Hotel Designer | 167 | 0 |

其余切换族无回归(dev 实测):expanded-metal 2 组、manufacturing 2 组、stainless 1 组、collections 1 组,可见面板数均 `1 → 1`。

**新增检查器** `tools/audit/collisions.py`:读真实调用点,列出每个被绑定的属性在单个模板里跨区块共用的值域。修前报 1 处,修后 0 处。

## 2. 表单:重复 id 与只绑定第一份(真缺陷)

虽然重复 id 在注释里的那部分是误判,但**同一份 HTML 里确实存在结构性问题**,且比注释问题更严重。

### 2a. id 不唯一

`Form.php` 打印固定 id,而一页会渲染多份表单。修前生产(剥注释后仍存在的风险)与 dev 实测:

| 指标 | 修前 | 修后(dev) |
|---|---|---|
| `md-inquiry-*` id 总数 | 53 | 53 |
| 唯一 id 数 | 14 | **53** |
| 重复 id 组 | 13 | **0** |
| 重复 `label for` | 9 | **0** |

**修法**:每份表单渲染时取实例后缀(`id_of()`),`id` / `label for` / `error id` / `aria-describedby` 统一走它;第一份保留裸 id(PDP 的 `#md-inquiry-source-page` 锚点还要用)。

### 2b. 只绑定第一份表单

`inquiry.js` 用的是 `document.querySelector('.md-inquiry-app')`——只拿第一个。页面上其余表单没有校验、没有 WhatsApp 文案同步、没有 Turnstile 状态。

**修法**:遍历绑定全部;查询改为在各自表单内按 `name` 查找(`field()`),不再依赖全局 id。

**验证(dev 实测)**:对每份表单派发空提交事件,`defaultPrevented: true`、`invalidFields: 2`、`errorMsgs: 2`、WhatsApp href 已生成。

### 2c. 表单资质字段与内部邮件

旧站联系表单要求「Est. Quantity」并勾选「I confirm this is a commercial or project inquiry.」;新站两者都没有。

同时发现更严重的:**内部通知邮件只打印 name / email / company / message**,客户填写的 phone、country、scope、route 全部没有出现在销售收件箱里——数据在记录里,但销售看不到。

**修法**:
- `Validator::QUANTITIES`(旧站原文的档位)+ 必填校验
- `Form` 渲染该 select;`Submission` 落库为 `project_quantity`;ACF 字段组加 `field_md_inquiry_project_quantity`
- `Emails::internal()` 补 Phone / Country / Project scope / Inquiry route / Est. quantity 五行

**端到端实测(dev,真 Chrome 真实提交)**:

提交后跳到 `/inquiry/thank-you/?md_inquiry=2092&key=…`,记录 #2092 的 meta:

```
customer_name        Ada Lovelace
customer_email       ada@example.com
customer_company     Analytical Engines Ltd
customer_phone       +852 5555 0101
customer_country     Hong Kong
project_scope        Single zone
inquiry_route        Interior partitions
project_quantity     50-100 sqm
message              Lobby feature wall, 40 panels, brushed finish.
source_page          glass-hybrid-screens
source_module        quote-modal
```

队列里两封邮件(内部 + 客户确认),内部封正文含:

```
Phone: +852 5555 0101
Country: Hong Kong
Project scope: Single zone
Inquiry route: Interior partitions
Est. quantity: 50-100 sqm
```

收件人 `["sales@sunriverinverter.com","info@metal-divider.com"]`,符合裁定。

## 3. 落地页三个案例只在灯箱里(业主所说"有跟没有天差地别")

**实测对照**(旧站 `/pages/custom-metal-divider-screens` vs 新站 `/custom-metal-divider-screens/`):

| 项 | 旧站 | 新站(修前) |
|---|---|---|
| applications 区块字符数 | 1585 | 458 |
| 可见客户名 | 3 个 | **0 个** |
| 地点 | Orchard Road / Mayfair / California | 无 |
| 每案规格行 | 5 行 | 无 |

差的约 1100 字符,正好是三个案例的全部事实。它们在新站模板里存在,但被 `inert` 关在 `VIEW CASE` 弹窗里。

**修法**:在 applications 之后新增 `#custom-project-proof` 可见区块,复用弹窗里的同一批字符串(客户名、地点、挑战、5 项规格),弹窗保留照片。加守护测试钉住每个事实,防止两处文案漂移。

**实测(dev)**:区块高 921px、172 词、三个客户名均在。

**四视口实测**(`tools/audit/viewport.mjs`,真 Chrome):

| 视口 | 横向滚动 | 超宽元素 | 贴边文本 | 超高文字块 | 区块高 |
|---|---|---|---|---|---|
| 1440 | 无 | 0 | 0 | 0 | — |
| 768 | 无 | 0 | 0 | 0 | 1734 |
| 390 | 无 | 0 | 0 | 0 | 1892 |
| 320 | 无 | 0 | 0 | 0 | 2189 |

## 4. 内容对等的真实缺口(分类后)

`tools/audit/diff.py` 出原始差异,`tools/audit/classify.py` 用仓库自己的裁定分类。旧站 24 页、新站 15 页 + 9 个集合页,同浏览器同口径(`mdaudit=1` 绕缓存)。

| 类别 | 条数 | 是否要修 | 依据 |
|---|---|---|---|
| 真缺失 | 174 | 是 | 见下 |
| 同义改写 | 170 | 否 | 词元存活率 ≥ 62%,如 `Your Name *` → `Name` |
| 授权升级 | 58 | 否 | 价格表撤回、材质收敛,`docs/acceptance/32-debt-ledger.md` 升级-3/4 |
| 用词替换 | 59 | 否 | product → pattern,AGENTS.md §2 修订 5 |
| 分页策略 | 130 | 否 | 旧站全量渲染 146 件 vs 新站真分页 |
| 噪声 | 40 | 否 | Shopify 残留(Add to cart、Swipe to explore 等) |

**174 条真缺失里 89 条是表单字段**——就是 §2c 刚补的那批,要重新抓取才会消失。其余 85 条按页分布:custom-screens 23(§3 已修)、glass-hybrid 7、laser-cut-geometric 8、expanded-metal 7、outdoor-privacy 6、about-us 6 等。

### 分类过程中的两次自我纠错

1. **`faqs.json` 抓错了站**:`old/faqs.json` 的 url 是 `metal-divider.com/collections/pattern-asian/`——上一轮并发抓取时页面串了。重抓后该页从「100 条缺失」变成「4 条」,总缺口从 306 降到 174。
2. **`collection` 页的图案名不是缺失**:旧站把 146 件全渲染进 DOM 再客户端筛,新站分页。按页比图案名会得到大量假缺口,已归入「分页策略」。

## 5. 本轮新增的工具(全部只用真 Chrome)

| 工具 | 作用 |
|---|---|
| `tools/audit/page-graph.js` | 单页结构化提取:标题树、段落、列表、表格、链接、按钮、图片(含加载失败)、表单字段、区块高度/词数/图数 |
| `tools/audit/defects.js` | 九类视觉缺陷判定:横向溢出、空容器、破图、贴边、被强制隐藏的有内容面板、原生控件泄漏、字号过小、超长行宽、死空白间隙、零内容区块 |
| `tools/audit/interactions.js` | 点击每个自称可交互的控件,测量页面指纹变化,抓「死控件」与「空面板」 |
| `tools/audit/capture.py` | 多会话并行抓页,滚动让懒加载落地,强制绕缓存 |
| `tools/audit/diff.py` | 旧新对等差异 |
| `tools/audit/classify.py` | 按裁定分类差异(真缺失/改写/升级/用词/分页/噪声) |
| `tools/audit/collisions.py` | 属性值域跨区块冲突检测 |
| `tools/audit/probe.py` | 经 opencli 跑页面探针并结构化输出 |
| `tools/audit/viewport.mjs` | 真 Chrome 四视口量测单区块 |

## 6. 状态

- `just check` 全过(PHPCS / PHPStan / Ruff / mypy / volume-check 38 卷)
- 测试:391 + 74 + 6 + 26 全绿(5948 + 323 + 33 + 97 断言)
- 生产**尚未部署**本轮三项修复(手风琴作用域、表单 id/绑定、案例可见区块)。部署后需按 §1/§2/§3 的同一套口径在线上复测
- 未做:174 条真缺失中剩余的 85 条非表单条目逐页补齐;`docs/acceptance/2026-10-27-production-walkthrough.md` 的结论需要按本文件修正后重发
