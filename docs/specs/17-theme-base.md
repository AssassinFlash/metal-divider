# 主题基座与呈现层架构(票 #17)

日期:2026-10-24。执行会话定稿(业主裁定:grilling 票按执行会话推荐定稿,业主保留否决)。
上游:#14 本地栈(`docs/specs/14-local-stack.md`)、#19 页面模板(`origin/research/spec-page-templates:docs/specs/19-page-templates.md`,25 模板/36 卷/14 复用件的既定结构)。本文回答:主题基座选什么、LC 动态模板承接面与页头页脚落点、设计令牌怎么进 WindPress 卷、扫描面怎么注册、主题/functions.php 与插件的分工。**不重开 #14/#19/#8 已钉死的任何裁定**;#19 §9 的三个接口假设(IA1/IA2/IA3)在本文逐一落定。

## 0. 依据事实

| # | 事实 | 来源 |
|---|---|---|
| F1 | #14 版本表已钉 picostrap5 为基础主题(本地档案、装时读头,picostrap.com 分发、wp.org 无),理由「整条 present/WindPress/provider 管线在 de-linker 已实证跑通」;目录草图定 `themes/md-child/`「子主题只有 functions.php,视觉零决策」 | #14 §2.1/§4/§7 |
| F2 | de-linker 生产实证:picostrap5 + picostrap5-child(仅 functions.php,40 行:html 根 `js` 类 + `data-theme="light"` 标记,无 style.css)跑在 WordPress 7.1.3 上;线上启用 LiveCanvas 4.9.x、WindPress 3.2.x | de-linker `themes/picostrap5-child-base/`(目录实测)、`docs/local-stack.md`、#14 §9(WP 7.1.3 现行) |
| F3 | picostrap 自带 `inc/windpress-support.php` 并在 `f!windpress/core/cache:compile.providers` 注册自己——picostrap 5 的设计就是「样式经 WindPress 单缓存产出」;de-linker 自研插件的 provider 注册「the way picostrap does」 | de-linker `includes/Presentation/WindPress.php` 头注 |
| F4 | #8 CHECK-3:每页外链 CSS 恰 1 条(WindPress 编译产物);CHECK-4:外链脚本 ≤5 且白名单(LC 运行时 + Turnstile + 询盘插件 + WindPress 零 JS,白名单冻结,加项须过票)。旧站本身就是每页 1 条外链 CSS | #8 `docs/specs/08-content-boundary.md` DEBT-2/DEBT-3 行(F9:旧站外链 CSS 每页仅 1 条) |
| F5 | LC 动态模板承接机制两类:页型赋值键(`is_single_product`/`is_shop_page`/`is_blog_posts_index`/`is_single_post`/`is_search`/`is_archive_for_tax_category` 等,LC `modules/templating.php` 官方 meta 键)+ 单页逐页赋值 `lc_use_template_of_slug`(写入页面 post);partials 是 LC 自带机制(`lc_partial` post type),与主题无关。present 按键就地写回 DB 行、drift() 读回逐字节核对、页内 `<!-- LC DT ID: n -->` 戳断言渲染来源 | #19 F4/F5;de-linker `Templates.php` FILES/PAGES 常量 |
| F6 | #19 已按 picostrap5-child 假设写完全部结构(25 模板 + 2 partials、36 卷、14 复用件);IA1 列明改选裸 2027 块子主题的受影响面(T2 工具类词汇来源标注 + tokens 字号/断口基准),§3/§5/§6/§7 结构不动 | #19 §9 IA1 |
| F7 | 令牌消费模型(de-linker 生产实证):tokens 为 `:root` 自定义属性 + @font-face(字体文件随插件资产自托管);卷规则内 `var(--proto-*)` 消费(chrome.css 单卷 82 处);模板的 Tailwind 类里零 var() 引用;卷目录无 @theme。卷安装:`present` 把 `content/windpress/*.css` 复制进 `uploads/windpress/data/` 并从卷入口 `main.css` import(编译器只认 import 触达),变更文件留 .bak;Tokens 单源 `content/windpress/tokens.css`,`uninstall()` 移除 Additional CSS 里标记块、`detach_from_partials()` 移除 LC partial 内嵌 tokens style 块 | de-linker `WindPressVolume.php`、`Tokens.php`、卷目录实测(grep) |
| F8 | LC 自带 provider 对动态模板按 `do_shortcode()` 渲染扫描:产品上下文外 Woo 模板 tag 打印自身标记进扫描 REST 应答、毁掉 JSON(浏览器 worker 报 `o.contents is not iterable`、整条编译停摆);对策 = `f!windpress/integration/livecanvas/compile:get_contents.render` 对自家模板返回 false,类名由自研 provider 读模板源文件供给 | de-linker `WindPress.php` `render_raw()` 及其注释(生产缺陷记录) |
| F9 | 缓存编译驱动:经后台(admin 会话)触发编译,断言 `uploads/windpress/cache/tailwind.css` mtime 变新 + 锚类在编译产物中(`missing_anchor_classes`);de-linker 裁定「部署流程含缓存强制重建(消除陈旧缓存缺陷)」 | de-linker justfile 190–229、`tools/windpress-compile.mjs`、de-linker AGENTS §4 |
| F10 | 旧站设计令牌权威:色板(#1a1a1a/#141414/#0a0a0a 背景、#c9a962 金系 4 阶、#faf9f7、#25D366)、白字不透明度阶梯(/90 正文、/80 次级、/75 可读下限、/50 仅 UI)、字体(Playfair Display 标题 + Inter 正文)、间距配方(容器 max-w-[1400px] 等)、750px 断口(Shopify 而非 Tailwind 768)、「Tailwind 类里禁 CSS 变量」反模式 | `~/Documents/shopify-builder/design-system/metaldivider/MASTER.md`(P1 权威,2026-04-16 版,只读) |
| F11 | Inter 与 Playfair Display 均为 SIL OFL 许可,可随公开仓库再分发 | 两字族官方许(Google Fonts 标注 OFL) |
| F12 | 本地栈:单实例 `dev`、Polylang 不装(EN 单语首期)、`just present`/`just windpress` 配方已立项(#14 §5) | #14 §1/§5 |

## 1. 决策总表

| # | 决策 | 结论 |
|---|---|---|
| D1 | 主题基座 | **picostrap5 父主题 + 仓库子主题 `themes/md-child/`(仅 functions.php,平移 de-linker picostrap5-child 模式)**;裸 2027 块子主题与自研极简壳主题否决(§2) |
| D2 | LC 承接面与页头页脚 | 确认 #19 的 25 模板 + 2 partials 清单与赋值键;页头页脚 = `plugins/md-site/content/livecanvas/partials/{header,footer}.html`,present 装成 `lc_partial` post,**EN 单语单份、无 Twins、无 per-language 机制**,二期 Polylang 时再开双份(§3) |
| D3 | 设计令牌进卷 | MASTER.md 令牌 → `plugins/md-site/content/windpress/tokens.css`(36 卷之一):色板/字体铸 `--md-*` 自定义属性 + @font-face 自托管(OFL);工具类配方保持工具类、750px 断口保持迁移规则;消费模型承 F7(var() 只在卷规则,模板类零 var(),禁 @theme)。present 安装进卷 + 移除遗留副本(Additional CSS 标记块、partial 内嵌块,幂等守卫)(§4) |
| D4 | WindPress 扫描面 | `md-site` provider(25 模板 + 2 partials + Presentation PHP)+ `render_raw` 守卫(关掉 LC 自家 provider 对 md-site 模板的 do_shortcode 渲染)+ `md-inquiry` provider(表单 HTML);部署 runbook 硬性含缓存强制重建 + 锚类断言(§5) |
| D5 | functions.php 职责边界 | 主题级只做「换掉父主题即失去意义」的文档根/资产队列行为;首期清单 = 父主题前端资产策略(保 CHECK-3/4)+ `js` 类标记(条件项),再无其他;分工表见 §6 |
| D6 | 插件切分 | **呈现/询盘分体**:`md-site`(模板、卷、present、drift、`[md_pdp_*]` 短代码)+ `md-inquiry`(表单、通知、WhatsApp、出站队列);确认 #19 IA3 的路径与宿主命名 |

## 2. D1 主题基座:picostrap5 子主题平移

### 2.1 结论

父主题 picostrap5(#14 已钉版、本地档案收档),仓库子主题 **`themes/md-child/`**(承 #14 §4 草图命名;即「de-linker picostrap5-child 平移改名」),**只含 functions.php**——de-linker 生产同构(F2:仅 functions.php、无 style.css、WP 7.1.3 实证)。`just up` 符号链接挂入实例(#14 同法)。

### 2.2 WP 7.1 块主题时代的取舍(题目要求写明)

WP 7.1 时代的默认主题(2027 系)是块主题,块主题是官方新站方向;但**本站选经典主题基座**,裁定依据:

1. **经典主题未被淘汰,且本配对有生产实证**:de-linker 线上就是 WP 7.1.3 + picostrap5 经典主题 + LC 4.9(F2)。LC 动态模板拦截的是经典模板层(`template_include` 一族);present/drift/DT 戳/卷安装整条链在这个壳上跑通过,换壳 = 全链路重验证。
2. **块基座引入第二样式权威**:块主题的 theme.json/全局样式会注入自身 CSS(预设变量、全局样式块),与「样式只在 WindPress 卷」单通道纪律(AGENTS §5、#19 F2)直接冲突,也威胁 CHECK-3 的单外链 CSS。本站令牌体系已裁定进 WindPress 卷(§4),theme.json 的设计令牌能力对本站是冗余通道。
3. **站点编辑器是 GUI 模板通道,本站必须锁死**:本站模板唯一来源是仓库文件经 present(#19 §2.1 理由 2);块基座会把「编辑器改模板」变成一个必须额外封堵的入口。经典基座无此面。
4. **块壳上零证据**:LC 4.9.5 的动态模板/partials 在块主题壳上的行为(渲染路径、赋值键解析)无任何本工程可用证据;像素门管线不容许在渲染来源上做考古。
5. **工具类词汇**:旧站词汇是 Tailwind(MASTER.md 全表),WindPress 原生供给,picostrap 的 Bootstrap 工具类是增量词汇而非依赖;裸块基座下网格/间距词汇反而要另起炉灶(#19 IA1 ①)。

### 2.3 否决项

| 备选 | 否决理由 |
|---|---|
| 裸 2027 子主题(块) | §2.2 第 2/3/4 条;#19 IA1 已列受影响面(T2 词汇表加来源标注、tokens 字号/断口基准重校),收益(站点编辑器/theme.json)本站全用不上 |
| 自研极简经典壳主题(header/footer/index.php 自写) | 零生产证据,LC↔主题的隐性契约(文档壳、模板层级兜底:404/搜索空/feeds 等 LC 未接管页型、body 结构约定)全要自己发现,发现成本落在像素门调试期;picostrap 这些面已被 de-linker 生产覆盖。违反「先复用,再新增」 |
| 其他经典轻基座(wp.org 经典主题) | 与 LC 无配套关系,picostrap 的 WindPress 单缓存设计(F3)与 provider 先例全部作废,管线重验证等同自研壳 |

### 2.4 资产面:CHECK-3/CHECK-4 的对账

picostrap 5 的样式设计就是经 WindPress 单缓存产出(F3:自带 windpress-support、注册 provider)。据此:

- **目标态**:前端外链 CSS 恰 1 条 = WindPress 编译产物(F4 CHECK-3);外链脚本 ≤5 且全在 #8 白名单(CHECK-4)。
- **md-child functions.php 的资产策略行为**(D5 首期清单第一项):实现票第一步在 dev 实例实测 picostrap5 前端实际 enqueue 清单;凡构成第二条 CSS 外链或白名单外脚本的父主题句柄,由 md-child 在 `wp_enqueue_scripts`(仅前端)移除。这是标准 WP 机制、约十行;若实测发现 picostrap 在 WindPress 激活态已零外链(F3 的设计意图),则此行为退化为空操作 + 一条断言注释。
- **Bootstrap 工具类**:经 picostrap 的 provider 编译进同一缓存,可用不依赖——旧站词汇由 WindPress 的 Tailwind 供给,#19 T2 的类名映射表按「Tailwind 原生类直迁、picostrap 专有类标注来源」处理(IA1 ①落地)。
- 验收闸即 CHECK-3/CHECK-4 本身(§7),不另设机制。

## 3. D2 LC 动态模板承接面清单(确认 #19)与页头页脚落点

### 3.1 清单确认

#19 §3 的 25 模板 + 2 partials 清单、赋值机制、文件名、仓库位置 `plugins/md-site/content/livecanvas/` **全部确认,逐字沿用**,本文不复制第二份(单一来源在 #19)。两条执行注记:

1. `series-archive.html` 的赋值键按 #19 原注:实现票第一步在 dev 实例核 LC 4.9.5 对 `product_cat` 归档的实际键名(候选 `is_archive_for_tax_product_cat`,与 de-linker 的 `is_archive_for_tax_category` 同构),不符即改 #19 该行。
2. present/drift/DT 戳机制平移 de-linker(F5);模板 post 类型 `lc_dynamic_template`、partials 为 `lc_partial`。

### 3.2 页头页脚(EN 单语版)

- **实现位置**:`plugins/md-site/content/livecanvas/partials/header.html` + `partials/footer.html`(#19 §5.1 的 `md-header`/`md-footer`),present 装成两个 `lc_partial` post(slug `header`/`footer`),全站 25 模板经 LC partial 机制引用。
- **EN 单语 = 单份**:`content/livecanvas/partials/` 下没有 `de/` 子目录、没有 Twins、present 不做任何 `pll_get_post` 解析(de-linker 的 Twins 机制是 Polylang 存在时的德语面,本站首期不装 Polylang,F12)。partial 机制本身与语言无关(LC 自带),二期启用 Polylang 时按当期票决定 partial 复制还是字符串翻译——**接口预留仅此一句,不预建机制**。
- partials 先行于内容模板(#19 §8 已定顺序:先 chrome 后内容);`md-inquiry-cta` 等 3 个单源内容组件按 #19 §5.2 以 partial 单源引用,同目录管理。

## 4. D3 设计令牌进卷:MASTER.md → tokens.css

### 4.1 源与目标

令牌值的**出处**是旧工程 P1 权威 `shopify-builder/design-system/metaldivider/MASTER.md`(F10,只读参考,不复制进仓库);本工程的**单一来源**是 `plugins/md-site/content/windpress/tokens.css`(36 卷之一,#19 D5)。`--md-` 命名空间(承 de-linker `--proto-` 模式)。

### 4.2 映射表(色板与字体 → 自定义属性)

| MASTER.md 角色 | 值 | tokens.css |
|---|---|---|
| Primary Background | `#1a1a1a` | `--md-bg: #1a1a1a` |
| Deep Background | `#141414` | `--md-bg-deep: #141414` |
| Darkest Background | `#0a0a0a` | `--md-bg-darkest: #0a0a0a` |
| Gold Primary | `#c9a962` | `--md-gold: #c9a962` |
| Gold Light | `#d4b878` | `--md-gold-light: #d4b878` |
| Gold Dark | `#a88a4a` | `--md-gold-dark: #a88a4a` |
| Gold Accent | `#d4af37` | `--md-gold-accent: #d4af37` |
| Cream | `#faf9f7` | `--md-cream: #faf9f7` |
| WhatsApp Green | `#25D366` | `--md-whatsapp: #25D366` |
| Headings 字族 | Playfair Display | `--md-font-display: "Playfair Display", Georgia, serif` |
| Body 字族 | Inter | `--md-font-sans: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif` |
| 主容器宽 | `max-w-[1400px]` | `--md-container: 1400px`(卷规则 `max-width: var(--md-container)`;模板新写可用 Tailwind 任意值) |
| 色彩方案 | 暗站 | `:root { color-scheme: dark }`(de-linker 的 `data-theme` 标记是亮站主题配套,本站不需要) |

**不铸变量、保持原形态**的部分(逐项理由):

| MASTER.md 项 | 处置 |
|---|---|
| 白字不透明度阶梯(/90 正文、/80 次级、/75 可读下限、/50 仅 UI)与对比规则 | 是文案可读性规则不是色值:迁移 CSS 里保持原不透明度(T2 逐字搬);规则文本进 tokens.css 头注 + #8 内容评审执行。可读下限 /75 是 #8/文案票的硬规则,不归样式卷重新发明 |
| 字阶/间距/组件配方(py-16 md:py-24、卡片、CTA、渐变底、金色分隔线等) | 全部是 Tailwind 工具类配方:WindPress 原生编译,保持工具类形态;配方属 #19 的组件卷/模板卷,不进 tokens |
| 750px 断口 | 自定义属性不能进 `@media` 条件(plain CSS 限制),不铸变量:迁移规则里旧 CSS 的 `@media (min-width: 750px)` 原样保留(T2 生成器映射注记),新写样式用 Tailwind 默认断口;差异按 #8 像素门台账归档 |
| 「Tailwind 类里禁 CSS 变量」反模式 | **原样保留**:旧规的 CDN 理由消失了,但 F7 的生产消费模型就是 var() 只在卷规则(chrome.css 单卷 82 处)、模板 Tailwind 类零 var();保留它同时保住类扫描确定性。tokens.css 不用 @theme(F7:de-linker 全卷无 @theme,工具类与变量各走各路) |

### 4.3 字体:@font-face 自托管

- Inter(300/400/500/600/700)与 Playfair Display(400/500/600/700)字文件**随 md-site 插件资产自托管**(`plugins/md-site/assets/fonts/`,woff2 优先),`@font-face` 置 tokens.css 头部、`font-display: swap`,src 指向插件 URL——de-linker 同构(F7)。两族均 SIL OFL(F11),公开仓库再分发合法。
- 字文件走终稿资产管线(AGENTS §2:上传即用、零运行时转换);像素门的字体加载等待按 #8 协议。

### 4.4 安装方式(de-linker 模式,present 统一驱动)

1. **装**:`just present`(md-site CLI)把 `content/windpress/*.css`(含 tokens.css)复制进 `uploads/windpress/data/`,从卷入口 `main.css` import(F7 机制);变更文件留 `.bak`,回滚 = 一次改名。
2. **移除遗留副本**:present 附带两个幂等守卫——Additional CSS 里若出现带标记块的 tokens 副本即剥除(本站全新建站,理论上无遗留;守卫按 de-linker `uninstall()` 平移,防 GUI 通道回流);LC partial 内嵌的 tokens style 块同法剥除(`detach_from_partials()`)。
3. **单源纪律**:tokens.css 只此一份;编译器对声明的保留已在 de-linker 对 Tailwind 4.3 验证(F7 注),本站 WindPress 3.2.92 实现票复核一次。
4. 验收断言:`just windpress` 编译后,`--md-gold:#c9a962` 等锚声明在编译产物中(§5.4)。

## 5. D4 WindPress 扫描面

### 5.1 provider 注册(官方过滤器)

两个自研插件各注册一个 provider(模式 = picostrap/de-linker,F3/F8):

| provider id | 注册者 | 扫描面 |
|---|---|---|
| `md-site` | md-site | `content/livecanvas/` 全部 25 模板 + 2 partials(读源文件,不渲染)+ `includes/Presentation/` 打印类名的 PHP(`[md_pdp_*]` 短代码等) |
| `md-inquiry` | md-inquiry | 输出表单/CTA HTML 的 PHP(全站唯一 `<form>` 的宿主,输出 `md-` 语义类) |

过滤器:`f!windpress/core/cache:compile.providers`,形状 `{id, name, description, callback, enabled}`(de-linker `providers()` 同构)。

### 5.2 `render_raw` 守卫(必装,不可省)

md-site 同时挂 `f!windpress/integration/livecanvas/compile:get_contents.render`,对 `lc_dynamic_template` 类型的 post 返回 false(F8:LC 自家 provider 按 `do_shortcode()` 渲染扫描动态模板,产品上下文外 Woo 模板 tag 会把自身标记打进扫描 REST 应答、毁掉 JSON、整条编译停摆——de-linker 生产缺陷实录)。模板的类名在模板源文件里,由 md-site 自己的 provider 供给(5.1)。

### 5.3 扫描边界

- 卷文件(`content/windpress/*.css`)是**编译输入**(经 main.css import),不在扫描面——扫描面只服务 Tailwind 工具类的发现(HTML/PHP 里用了什么类,缓存里就要生成什么工具类)。
- 旧站渲染 HTML 迁入模板时新增的 Tailwind 类(#19 T2)自动进扫描面(它们在模板源文件里);生成器若输出 picostrap 专有 Bootstrap 类,由 picostrap 自家 provider(F3)覆盖。

### 5.4 部署时缓存强制重建

- 本地/评审:`just windpress`(#14 §5 已立项)= 经后台触发编译 + 断言 `uploads/windpress/cache/tailwind.css` mtime 变新 + 锚类在产物中(F9 机制)。
- **部署 runbook(未来上线票)硬性步骤**:原子切换后、健康检查通过前,跑同一编译与断言;锚类集 = 25 模板根类 + `md-header`/`md-footer` + 9 组件根类(#19 §5.3 命名)在产物中,加 tokens 锚声明(§4.4.4)。陈旧缓存缺陷由此消除(de-linker 裁定原文)。本票不动生产,只把该步骤写进部署 runbook 的输入要求。

## 6. D5/D6 职责边界:分工表

### 6.1 分工表

| 关注面 | 归属 | 依据/备注 |
|---|---|---|
| 文档级行为(html 根类、color-scheme、父主题前端资产策略) | **themes/md-child/functions.php** | §6.2 边界判定;首期清单 §6.3 |
| LC 25 模板 + 2 partials、36 卷、present/drift/DT 戳、`[md_pdp_material]`/`[md_pdp_design_intent]` 短代码 | **plugins/md-site**(D6) | #19 IA3 定名;短代码零设计(AGENTS §5) |
| 询盘表单(唯一 `<form>`)、通知、出站队列、WhatsApp 路由 | **plugins/md-inquiry**(D6) | #14 §4 草图、#7 链路 |
| 本地隔离(邮件落盘、外联 allowlist、CSP/noindex) | tools/local/mu/ → 实例 mu-plugins | #14 §1.4;永不部署 |
| 生产守卫(安全头、REST 守卫)、部署期 opcode 重置 | 生产 mu-plugins(部署票立项) | de-linker `linker-site-guards`/`linker-deploy-reset` 先例 |
| 结构化数据、meta、sitemap | SEOPress/SEOPress PRO(原生) | AGENTS §2 技术栈 |
| 产品/归档/字段数据 | WooCommerce + ACF Pro(原生) | #10 |
| 一切页面样式 | WindPress 36 卷经单缓存编译 | #19 D5;CHECK-3 |
| 页面组成 | LC 动态模板 + partials | #19 D2;PHP 禁页面模板(AGENTS §5) |
| 翻译引擎(二期) | 插槽:未来自研窄插件 + Polylang/WPML 当期裁定 | AGENTS §2;首期无 |

### 6.2 functions.php 边界判定(一条规则)

**「换掉父主题这段代码就失去意义」→ functions.php;否则进插件。** 只允许两类行为:作用于文档根/`language_attributes` 的标记;对父主题资产队列的增删。模板路由(LC 管)、样式(卷管)、短代码与数据装配(插件管)、业务链(插件管)、守卫(mu 管)一律不进。de-linker 的 functions.php 40 行就是这个形状(F2),md-child 同构。

### 6.3 首期清单(md-child/functions.php)

| 行为 | 状态 |
|---|---|
| 父主题前端资产策略(§2.4:实测 enqueue → 移除构成第二条 CSS 外链/白名单外脚本的句柄) | 定稿 |
| html 根 `js` 类(de-linker 同款渐进增强旗标) | 条件项:实现票实测 picostrap 编译产物是否依赖;依赖即加,不依赖即不加(避免无人消费的死代码) |
| 主题 supports 声明、菜单注册、小工具区 | 不加:父主题已声明;本站 chrome 是 LC partial 静态标记(#19 §5.1),无 WP 菜单/小工具面 |
| 其他一切 | 无。functions.php 若超过 ~60 行即视为边界破口,评审拦下 |

### 6.4 插件切分(D6)理由

呈现(md-site)与询盘(md-inquiry)**分体**:①#14 目录草图与 #7 询盘链票已按 `plugins/md-inquiry/` 立项,改动面隔离(询盘链迭代不触碰模板与卷);②de-linker 先例按关注面分插件(commerce/i18n/tools);③两者 provider、CI、测试各自独立;④合并成单插件没有对应收益。短代码宿主 = md-site(#19 IA3 原文确认),`md-inquiry-cta` 组件的**样式**在卷(组件卷)、**链接目标**由 #7 链路定,两层互不越界。

## 7. 验收(实现票过关线)

1. **基座**:`just up dev` 后 picostrap5(档案版)+ md-child(符号链接)激活,站点可渲染;md-child 目录仅 functions.php(F2 同构;若 WP 7.1.3 主题装载器实测要求 style.css 头,允许加一个仅含头部注释的空 style.css,评审记一行)。
2. **资产面**:curl 任一 LC 接管页,外链 CSS 恰 1 条(WindPress 缓存,CHECK-3)、外链脚本全在 #8 白名单(CHECK-4);md-child 资产策略行为有实测记录(picostrap 激活态 enqueue 清单 → 处置逐条)。
3. **承接面**:25 模板 + 2 partials 经 `just present` 装入,`drift = 0`(#19 G1);series-archive 赋值键在 dev 实例核名并有记录(§3.1.1)。
4. **令牌卷**:tokens.css 装入 `uploads/windpress/data/` 且 main.css 有 import;编译产物含 `--md-gold:#c9a962`、`--md-font-display` 等锚声明与两族 @font-face;Additional CSS 与 partial 内均无 tokens 副本(守卫幂等重跑)。
5. **扫描面**:`md-site`/`md-inquiry` 两 provider 在 WindPress 编译器列表可见;`render_raw` 守卫使 LC 自家 provider 不渲染 md-site 模板(F8 场景:对 `lc_dynamic_template` 返回 false 的单测);`just windpress` 编译后锚类集(§5.4)在产物中、缓存 mtime 变新;幂等重跑通过。
6. **分工**:functions.php 行数与内容对照 §6.3;全仓 grep 无页面模板类 PHP、无第二条 Tailwind 构建(CHECK-6 同源)。
7. **无密钥**:公开仓库红线,规格与代码零密钥(AGENTS §4)。

## 8. 对 #19 接口假设的回应与后续票输入

| #19 假设 | 本文落定 |
|---|---|
| IA1(picostrap5-child + partials + tokens 路径) | 确认(§2/§3/§4);T2 类名映射表加「Tailwind 原生直迁 / picostrap 专有标注」两行来源标注(§2.4) |
| IA2(卷目录 content/windpress/,de-linker 平移) | 确认;编译入口 = main.css import + 2 provider 扫描面,无第二条构建(§5) |
| IA3(md-site 宿主 + 插件切分归 #17) | 确认 md-site;切分定稿 = md-site + md-inquiry 分体(§6.4) |

- 本地栈实现票输入:§2.1/§2.4(主题安装与链接)、§4.4/§5.4(present/windpress 配方细化)。
- 每页型实现票输入:§3 清单即 #19 §3,无第二份。
- 部署 runbook(未来)输入:§5.4 缓存强制重建步骤。
- OPEN:无业主否决点(全部裁定由 #14/#19/#8 既有裁定与 de-linker 生产证据推出;业主否决上游任一行时本文对应行联动改)。
