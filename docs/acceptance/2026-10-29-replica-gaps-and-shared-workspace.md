# 2026-10-29 · 复刻缺口修复、共享暂存区缺陷与主图数据画像

本文件记录业主指出的「复刻没复刻完全」之后的一整轮工作。每一条都给出可复核的
命令与输出，没有一条来自"看代码觉得应该对"。

## 一、八个页面域的只读盘点

八个子代理并行比对旧站 `metalpartition.com` 与本站在线内容，目标是找出**旧站有、
我方缺**的内容块与样式。总量：`plugins/md-site/content/livecanvas/` 26 个模板与
`content/windpress/` 38 个卷。

盘点得出的三类问题，按可复现性排序：

1. **线上可复现的硬缺陷**：stainless 主 CTA 无背景（`#1a1a1a` 压在 `#141414` 上，
   对比度 1.06:1）；outdoor `Spec sheets by pattern` 整块出现两次；outdoor 15 行采购
   档位表因 `ocm-*` 无样式退化为连续段落。
2. **死类名**：类名写在 `class` 属性里，但在对应卷、全量 `content/windpress/*.css`
   与生产编译产物三处命中均为 0，而旧站对同名类有规则。
3. **内容缺口**：PDP 的 21:9 宽幅槽位有数据、有样式、有文档，唯独模板没接；
   首页收尾区块的询盘表单被删成两个链接。

## 二、守卫本身失效的三处（比缺内容更值得记）

### 2.1 不可证数字的守卫对 en-dash 全盲

`TemplatesTest::test_no_unverified_numeric_claims_on_customer_faces` 的模式缺 `/u`
标志，而全站年份跨度用的都是 en dash（U+2013）。PCRE 按字节走，三个字节被逐个
比对，任何范围都匹配不上。补上 `/u` 并收紧后，一次性抓出 8 条本应被拦下的声明：

| 位置 | 声明 |
|---|---|
| `family-outdoor-privacy.html:207` | `4,000+ hr ASTM B117` |
| `family-outdoor-privacy.html:784` | `4,000hr+ Salt Spray Tested` |
| `family-outdoor-privacy.html:843` | `25+ Year Finish Life` |
| `family-outdoor-privacy.html:2396` | `paint fades in 3–5 years; PVD holds 10–15` |
| `family-expanded-metal.html:1448` | `ASTM B117 500–1000 hrs` |
| `family-perforated-panels.html:617` | `10-year color warranty. 500hr salt spray pass` |
| `family-perforated-panels.html:1078` | `ASTM B117 500–1000 hrs` |
| `faqs.html:932` / `family-perforated-panels.html:1272` | `10–20 year warranty` |

处理原则：保留结论（316L 适合海岸、PVD 抗 UV），去掉无来源的数字。`ASTM B117`
作为测试标准代号保留，它不是我方的实测值。阴性对照（`24 hours` / `48 hours` /
`72 Hours` / `4 hours` 这类回复时限）全部不误伤。

证据：删掉 `family-expanded-metal.html` 的说明句后守卫变红
（`unverified numeric claims on customer faces: family-expanded-metal.html:
salt-spray or life figures (1000 hrs)`），恢复后绿。

### 2.2 一条恒真断言

`ChatDeleteTest::test_forget_wecom_card_drops_only_the_deleted_chat` 原文：
`assertSame( array( 11 => 555 ), array( 11 => 555 ) )` —— 两边是同一个字面量。
它在任何行为下都不会失败。

改成按调用前后的增量统计 `update_option` 写入次数（`setUp()` 未重置写入日志，
故必须比增量）。实证：删掉 `md_chat_delete_forget_wecom_card()` 里的
`array_key_exists( $chat_id, $map )` 守卫，测试报
`Failed asserting that 2 is identical to 1`；旧写法在此 bug 下仍是绿的。

### 2.3 一条把缺陷写成契约的断言

`MobileChromeTest:66` 断言 JS 里必须出现
`/wp-json/wc/store/v1/products?per_page=6`。任何真正修好搜索的改动都会被它判红。
它测的是实现字符串，不是它声称保护的性质。改为断言全站搜索端点，并保留原有的
负面保护（不许用 `/wp/v2/product`）。

## 三、部署"假成功"的结构缺陷

### 现象

部署日志：11 个 artifact 全部 `deployed … by rename`、`total switch duration:
0.023 s`、`deploy-verify` 五项全 200、页面缓存 miss→hit。批次号
`20261028T163924413324Z-3fb2535`，无 `-dirty` 后缀（树是干净的）。

线上实际内容（`?mdaudit` 绕缓存读取）：

```
Spec sheets by pattern   出现 2 次   ← 本次要删的重复区块还在
4,000hr                  1 处        ← 本次要清的声明还在
25+ Year                 1 处        ← 同上
```

### 根因

`tools/deploy/src/md_deploy/backup.py:55`：

```python
DATA_HOME = Path.home() / "metal-divider-local"
```

`cli.py` 的 `_workspace()` 用它拼出暂存区 `~/metal-divider-local/deploy-workspace`
—— **全机唯一固定路径**。而 `stage_tree()` 用"源指纹 vs 树旁戳"决定是否重建：

```python
if stamp.is_file() and stamp.read_text().strip() == fingerprint and (...):
    return destination
```

两个 checkout 共用一个暂存区时，后者重建会覆盖前者即将发布的树。

指纹对照（决定性证据）：

| 来源 | 指纹 |
|---|---|
| 那次部署写下的戳 | `11052c53…` |
| 另一 checkout `.scratch/impl-162-merge`（提交 `7cf70a8`，不含本次改动） | `11052c53…` 相同 |
| 本 checkout | `de769d2e…` 不同 |

### 修法

`_workspace(repository)` 按 checkout 隔离：

```python
return DATA_HOME / "deploy-workspace" / _checkout_id(repository)

def _checkout_id(repository: Path) -> str:
    return hashlib.sha256(str(Path(repository).resolve()).encode("utf-8")).hexdigest()[:12]
```

两个 checkout 的 id：`c620c0ce6bae`（本仓库）与 `b1aae010fef6`（另一 checkout）。

守卫（均实证会红）：同一 checkout 的 workspace 稳定（缓存仍要工作）；两个
checkout 永不落在同一目录。把 `_workspace` 改回共享路径，两条立即变红。

### 修复后的验证顺序

隔离后的首次部署（`20261028T165638318132Z-4418556`）先读隔离暂存树的内容：
`RELATED_LIMIT = 4` 命中 1、`announce: true` 命中 1、`Spec sheets by pattern`
1 次、`4,000hr` 0 次——树上是要发布的内容。切换后再比对生产：

| 文件 | 仓库 | 生产 |
|---|---|---|
| `Shortcodes.php` | `6b0374d9…` | `6b0374d9…` |
| `family-outdoor-privacy.html` | `f2a81d16…` | `f2a81d16…` |
| `md-chrome.js` | 含未部署的搜索改动 | `74583d96…` = 提交 `4418556` 版本 |

## 四、修复的线上实证

### 内容读回（11 项，`fail=0`）

outdoor 重复区块 2→1；`4,000hr`、`25+ Year`、`3166`、`optione`、`500hr`、
`500–1000 hrs`、`10–20 year warranty` 全部归零；outdoor 的 316L 说明保留；
首页原生询盘表单存在。

### WindPress 重编译

Tailwind v4 没有服务端构建，规则不进编译缓存就不生效。编译前
`windpress-check` 报缺 5 个锚类，其中两个是首页表单用的
`[&_.md-inquiry]:[background:none]` 与 `[&_.md-inquiry]:p-0`。编译后：

```
windpress cache mtime: 1793178261 → 1793207316
windpress-check: anchor classes present
```

编译产物 635KB 里逐类确认命中 10/10：`sh-hero-btn-primary`、`sps-primary-cta`、
`sps-btt-size`、`ocm-path-row`、`ocm-info-card`、`gh-hero-chip`、`gh-feature-title`、
`lc-pattern-overlay`、`ppr-scope`、`emf-acc-body`。

### 真 Chrome 实测（不是读源码）

stainless 主 CTA：

```
background-image: linear-gradient(rgb(201,169,98), rgb(212,184,120))   ← 修复前 none
color: rgb(26,26,26)   → 7.74:1（修复前 1.06:1）
visible: true, 204×53px
```

outdoor 采购档位表：

```
ocm-path-row   display:flex  ← 修复前为普通段落
               border 1px rgba(255,255,255,0.06), radius 12px, 高 67px
ocm-info-card  border 1px, radius 16px
is-active 态   border rgba(201,169,98,0.24)   ← 修复前无此规则
```

### 首页询盘表单

页面渲染出 2 个表单，`source_module` 分别为 `home-contact` 与 `quote-modal`
（页内收尾区块与页眉报价弹窗，非重复渲染）。字段完整：
`md_inquiry_{nonce,action,locale,source_page,source_module,source_button,name,email,company,phone,country,scope}`，
表单体 6771 字符。

## 五、PDP 主图：数据画像而非代码缺陷

线上实测（样本取自 `/shop/` 的 24 个真实产品链接）：
`pdp-hero-plate: 22 / 24`。缺的两个是 `alhambra` 与 `aztec`。

查证过程中我撤回了两条自己的错误推断，记在这里因为它们容易被再次犯：

- **撤回**「媒体里没有适合竖框的图」——错。两者在旧站导出里各有 5 张与 2 张图。
- **撤回**用 `md_media_class` taxonomy 去查——**该分类法在生产不存在**，附件上
  没有任何 taxonomy。真实机制是 `_md_role` 元字段 + alt 文本关键词回退
  （`Shortcodes.php:715-721`）。

真实数据（生产 WP-CLI 实测）：

```
已发布产品 146
├─ 1 张图: 15
│   ├─ role=accent render → 7   （accent 在候选内，主图取得到）
│   └─ role=scene         → 8   （唯一那张被 hero 背景占用 → 主图无候选）
└─ 2 张以上: 131
```

`alhambra`（`role=installed screen scene`）与 `aztec`（`role=official installation
scene`）属那 8 个 `scene` 单图产品。`first_in_order` 正确排除了已用图，没有让同一
张图填两个槽位。

**这不是代码缺陷**：一个只有一张场景图的产品无法同时填 hero 与主图。可选处置
（待业主定）：接受主图为空（当前，hero 单列，页面正常）／为该 8 个产品补图／
允许两槽复用同一张（不推荐——同一张图在首屏出现两次）。

## 六、本轮未完成

- 搜索改造（`daa638e`）已通过门禁，部署中；首次部署因 SSH 断连失败于切换前，
  已重试。
- 390 / 320 窄屏复核：改动后未重跑。
- 生产管理员只有 `md_admin / martinfu127@gmail.com`，与业主指定的
  `srchesterjohn@gmail.com` 不一致，需业主确认，未擅改。

## 七、我自己的一次操作失误

生成 WP 认证 cookie 的 SSH 命令**没有封住输出**，凭据被回显到会话。cookie 一小时
后自然过期，风险有限，但违反了仓库"含密钥命令先封输出"的纪律。记录在此，
后续同类操作必须先重定向再取用。

## 八、图集恢复与最后四个主图位

### 8.1 图集只挂了应有量的四分之一

生产 WP-CLI 实测（2026-10-29）：

```
已发布产品 146
当前挂到产品的图   277
媒体库里可用的孤儿 1063
可恢复量            786
附件总数 1319，其中 post_parent=0 的 1319 个 —— 全部是孤儿
```

`data/media-final/products/<slug>/` 里每个产品都有完整的序号变体（alhambra
`--01--`…`--05--`、breeze-block 28 个文件、kyoto-dynasty 27 个），导入时只把
一张写进 `_thumbnail_id`，其余留在库里未挂回。

`tools/media/reattach-orphans.sh` 按文件名里的 `--NN--` 序号把孤儿挂回
`_product_image_gallery`，并把附件的 `post_parent` 指回产品。先 dry run 后写库：

```
products_changed = 145 / 146
images_added     = 785
```

写库前快照 `db-20261028T165621Z`。验证 `white-sands`：raw 与
`wc_get_product()->get_gallery_image_ids()` 都返回 10 个 id，含 `1258`
（400×800 竖版 `finish preview`）。`media_ids()` 读的正是 thumbnail 加
gallery（`Shortcodes.php:687-705`），所以挂回即刻生效。

**注意顺序不等于尺寸**：`white-sands --01--` 是 400×800 竖版，`--02--` 才是
2048 宽。序号是旧站图集顺序，不是清晰度排序。

### 8.2 主图真正缺的只有四个

逐产品调用 `[md_pdp_image slot="plate"]` 全量审计：

```
filled=142  empty=4
  poseidon / pebble-creek / funk / alhambra
```

早先 22/24 的抽样口径漏掉了这几个，全量审计才是准的。

### 8.3 搜索记录（Apify）

| 查询 | 结果 |
|---|---|
| 四个图案名 + 描述性限定 | 命中的是 Pinterest 与非相关页面 |
| `site:parasoleil.com <name>` × 4 | **0 条** |
| 按来源确认 | 这四个是 `dsd`；全站 `parasoleil` 96 / `dsd` 50 |
| 找到 DSD 源头 `decorativescreensdirect.com.au` | 抓取其激光切割页，**四个图案都不在目录里** |
| 图案名 + decorative metal screen | 同名噪音（希腊神话 Poseidon、Alhambra 宫殿） |

结论：外部不存在可用的竖版实景照。所以没有走"外部补素材"这条路。

### 8.4 站内逐张看图的结果

四张 `pattern render` 都用图像方式核对过，不是读文件名：

| 产品 | 图 | 判定 |
|---|---|---|
| pebble-creek | `725` 500×1000 | 黑白二值图稿，底部标题条在 3:4 裁切线外 |
| funk | `327` 499×998 | 同上 |
| alhambra | `247` 500×999 | 图稿；标题条 y≈938，裁切可见区 y=166–833，恰好裁掉 |
| poseidon | `728` 500×1000 | 图稿；`POSEIDON` 标题条 y≈943，同样被裁掉 |

而 `poseidon 726`（`reference image`）是黄昏卧室广角，屏风只是背景一角；按
3:4 居中裁切后留下吊扇、半张床与地毯，**不可用**。`poseidon 727` 才是屏风为主
体的实景照，但它已被 hero 占用。

`alhambra 246`（768×768）是深色棚拍渲染，有板厚与地面倒影，会被读成"一块深色
成品板"，也不采用。

### 8.5 为什么用样张而不是复用

`pdp.css:53` 的遮罩是 `linear-gradient(90deg, .92 → .42)`，右端仍有 58% 可见；
主图框是 `justify-self: end`，正好落在那一侧。复用会让同一张照片在首屏出现两次。

`pdp.css:74` 是 `object-fit: cover`，横图同样能填满 3:4 框——"必须竖图"是先前
我和子代理误加的约束，这一条已撤回。

### 8.6 旧站同样是空的

抓 `metalpartition.com/products/alhambra-…`：`pdp-hero-plate` 只出现 3 次，全部
是 `<style>` 里的 CSS 规则，DOM 里没有对应元素，并且布局类是
`pdp-hero-grid-cols pdp-hero-solo`。所以填满主图是**升级**，不是复刻缺口。

### 8.7 实现与守卫

主图候选链末端加一条精确兜底：仅当其他候选全空时，取 `_md_role` 恰为
`pattern render` 的图（`ids_with_role()` 按角色精确筛选，不把整个 `other` 桶
放进来）。`reference image`、`qc photo`、`unknown` 仍然排除。

两条守卫，各自实证会红（去掉兜底即失败）：

- `test_image_plate_falls_back_to_a_pattern_sample`
- `test_image_plate_does_not_reach_for_a_reference_image`

同时更新了 `test_image_two_image_pattern_*`：`pebble-creek` 正是"一场景 + 一
样张"的真实形状，主图现在取自样张，`square` 与 `wide` 仍为空。

### 8.8 线上验证（2026-10-29，部署 `20261028T174730302265Z-4b466be`）

代码 md5 与仓库一致（`Shortcodes.php` = `ed1215c9…`）。

**服务端全量审计**（逐产品调用 `[md_pdp_image slot="plate"]`）：

```
修复前：filled=142  empty=4   （poseidon / pebble-creek / funk / alhambra）
修复后：filled=146  empty=0
```

**HTTP 抽样**：`/shop/` 取 24 个真实产品链接，24/24 命中 `pdp-hero-plate`，零缺失。

**实际渲染的是哪张图**（说明选图逻辑选对了，不只是"有元素"）：

| 产品 | plate 图 |
|---|---|
| poseidon | `…-03-500w.webp`（样张） |
| pebble-creek | `…-02-500w.webp`（样张） |
| funk | `…-02-499w.webp`（样张） |
| alhambra | `…-04-500w.webp`（样张） |
| white-sands | `…-01-400w.webp`（竖版 `finish preview`） |

**真 Chrome 实测**（alhambra）：主图框 420×561，比例正好 0.75；图 `428×855` 已加载
（`complete: true`）；`object-fit: cover`；可见。视觉复核确认框内是图案样张、不是
房间照片，已露出的约 85% 高度里没有 "ALHAMBRA" 标题条。

**图集落库核对**：全站图集挂载 1062 张，逐张检查 `get_attached_file()` 与
`file_exists()`，**缺失文件 0 个**——挂回的是真实文件，不是空引用。

### 8.9 必须说清的一件事：挂回不等于页面出现图集

PDP 模板只有四个媒体槽位（`hero-bg` / `hero-plate` / `square` / `wide`），
**没有图集组件**；旧站同样没有（抓 white-sands 老页面，`pdp-gallery` 与
`pdp-thumb` 计数均为 0）。

所以 785 张挂回图的直接收益是：主图/方图/宽图的候选池变充足（这是四个空主图得以
修好的原因）、`SchemaFilters.php:306` 的结构化数据 `image` 数组更完整、
`ThumbnailCommand` 有更多素材。它**不会**让页面多出一个画廊——那是新增功能，
需业主决定，本轮未做。

### 8.10 交叉缺陷（不属于本 GOAL，移交）

真 Chrome 首屏复核实测：聊天挂件 `.md-launcher`
（`lhc-extensions/mdvisitor/.../mdvisitor.css:44`，`position:fixed; right:32px;
bottom:32px`）压住主图右下角约 160px。

GOAL.md:666 明确写着 AI 客服票 #86/#102/#147 不在本 GOAL 范围，所以本轮不修改
它，仅记录位置与实测数据供该工作流处理。
