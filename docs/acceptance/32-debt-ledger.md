# IMP-7 收编/升级台账(票 #32)

规格基准:docs/specs/19-page-templates.md(分支 research/spec-page-templates)+ docs/specs/08-content-boundary.md 的 DEBT/CHECK 台账(分支 research/spec-content-boundary)。本文件记录票 #32 交付时每项债的处置;数值证据来自转换管线 `.scratch/32/debt-ledger.json`(管线在 18 张管线面 + 2 个 chrome partial 上实测)。

## 收编段(第一段提交)

管线实测(`.scratch/32/debt-ledger.json`,代表品 akira 的 PDP;R1 博客四面、R2 询盘两面、R3 privacy 为新写,不经管线、无旧站债):

| 面 | sections | 剥除脚本 | 内联样式铸类 | style 块归卷 |
|---|---|---|---|---|
| about | 8 | 0 | 2 | 3 |
| custom-screens | 13 | 12 | 79 | 13 |
| family-chinese-style | 11 | 3 | 32 | 6 |
| family-expanded-metal | 11 | 6 | 194 | 7 |
| family-glass-hybrid | 11 | 3 | 53 | 6 |
| family-laser-cut-geometric | 11 | 3 | 77 | 5 |
| family-laser-cut-panels | 11 | 7 | 47 | 8 |
| family-outdoor-privacy | 11 | 3 | 42 | 6 |
| family-perforated-panels | 10 | 6 | 32 | 5 |
| family-stainless-steel | 10 | 4 | 36 | 3 |
| faqs | 2 | 0 | 3 | 1 |
| home | 10 | 4 | 178 | 4 |
| hub | 2 | 0 | 0 | 2 |
| manufacturing | 11 | 3 | 80 | 4 |
| pdp(代表 akira;8 张代表品 tag 序列 100% 同构) | 2 | 1 | 1 | 1 |
| project-gallery | 3 | 2 | 4 | 1 |
| series-archive(代表 pattern-organic) | 6 | 4 | 5 | 5 |
| shop | 6 | 4 | 5 | 5 |
| **合计(18 面)** | **149** | **65** | **870** | **85** |

(chrome partial 的 5 个 section 变换未入此账——header/search/mega-menu×2/footer 走同一管线,样式入 chrome.css。PDP 的低计数是槽位化替换把逐品值换成模板 tag 的结果,不是旧站债少:PDP 全站债实测见 #8 F9。)

## DEBT-1…8 逐项状态

| # | 债(#8 §5.3) | #32 处置 | CHECK 判定状态 |
|---|---|---|---|
| DEBT-1 | 内联样式属性 | **模板层已清零**:870 处 `style="…"` 全部铸为 `md-t<hash>` 类、规则入卷(同声明序列同哈希,重跑同名) | CHECK-1 待渲染层复核(dev 实例 present 后 curl;收编产物静态断言:模板文件 `style="` 计数=1,为 Alpine `:style` 绑定,属 T6 升级项) |
| DEBT-2 | 每节一个 `<style>` 块 | **85 块全部归卷**(`:root` 变量块进 chrome.css,其余进所属卷) | CHECK-2 模板层已清零;CHECK-3 待渲染层复核(每页外链 CSS 恰 1 条 = WindPress 编译产物) |
| DEBT-3 | 脚本堆叠 + 第三方 UI 包 | **65 个 `<script>` 全数出模板**(T3 收编期动作);xo-webcomponents 自定义元素保留原 DOM(T6,分批) | CHECK-4 待渲染层复核(LC 运行时白名单);CHECK-5 构建图无 xo 依赖(仓库无该包) |
| DEBT-4 | 多条样式管线并存 | **单 WindPress 管线**:36 卷全部经 `content/windpress/` 编译进同一缓存;零第二条构建 | CHECK-6 仓库无第二条 Tailwind 构建(静态可判) |
| DEBT-5 | 模板版本堆叠 | 模板/卷命名无 `-vN`(WindPressVolumeTest 断言) | CHECK-7 绿 |
| DEBT-6 | 表单复制粘贴 | **升级段已做**:16 个 api.form-data.com 询盘表单(15 张面)全部替换为 `[lc_get_partial slug="md-inquiry-cta"]` 单源件;`/pages/contact` 链接全部改指 `/inquiry/`(source 参数保留) | CHECK-8 部分绿:series/shop 的搜索/排序 GET 表单保留(U1 筛选/分页重实现,分批);/inquiry/ 表单本体归 #33 |
| DEBT-7 | 图标重复内联 | 未处置(U4 升级项,分批:91 个内联 SVG 的单源化在像素门调试期做) | 待 U4 |
| DEBT-8 | 渲染体积 | 收编模板 DOM 体量 = 旧站渲染 HTML 去脚本/样式块后的原文;最终度量在 #31 导入后的渲染层 | 观测指标,导入后度量 |

## 分批记录(票面授权的剩余升级项)

1. **U1 筛选/排序/分页重实现**(series-archive、shop 的搜索/排序 GET 表单;分页)——数据面重实现,依赖 #31 导入后的真实产品查询。
2. **U2 xo-webcomponents 语义重写**(收编模板保留 `<xo-parallax>`/`<xo-animate>`/`<xo-container>` 原自定义元素与 Alpine `@click`/`:style` 绑定;浏览器未知元素降级为块级容器,视觉无损但语义/交互待重做)。
3. **U4 图标 SVG 单源化**(DEBT-7)。
4. **像素门**:全部复刻面的 G3 门(掩膜 M1 = 媒体指纹;媒体终稿未全量,#29)+ PDP 30 品采样,按票面留给 #31 导入后。

## 接口假设(记录给相邻票)

- **站点图地址**:模板内站点图为 `/wp-content/uploads/<终稿文件名>`(spec 15 §3.1 命名,`--{width}w` 档宽随 srcset);#31 导入侧载时按 `_md_variants` 解析真实路径后如需改写模板地址,一次性 sed(映射表 `.scratch/32/asset-map.json` 是管线与导入器的合同)。
- **custom-screens 专属 landing chrome**(L17):收编为标准 chrome + 页面内容;旧 header-landing/landing-footer 与标准 chrome 的差异是 DEBT 台账行,升级处置归后续批次。
- **`md_series_grid` 的系列卡图**:读 term meta `hero_image`(#31 写入;无图渲染无图卡)。
- **docs/copy/pdp-series-phrases.json**:design_intent 装配的运行时读入路径为仓库根 `docs/copy/`;部署形态(#35)需携带该文件或改路径。
