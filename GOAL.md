# metal-divider 全站目标(GOAL)

来源:业主在参考对话中的原话指令(2026-10-24/25),持续到全部完成。行为约束见 AGENTS.md §0.1(收敛规则)。

## 总目标(原话)

> 全方位,全面,每个页面、模板,全链路,都走一遍,所有细节和 bug,都对每一页做考虑和优化。

> 优化页面加载和功能完善,使得页面加载速度大大优化、功能彻底完善。

> 不要我说什么你就看什么方向,真的要从整体全局的视角去细细看待。

## 覆盖矩阵

- **页面**:首页、8 产品族页、枢纽、系列归档×9、画廊、工艺、About、FAQs、感谢页、privacy、contact/inquiry、博客四面、PDP×146(单模板,抽全形态代表品+极值品)
- **视口**:1440 桌面、390 手机、**320 窄屏**;相邻断点边界(如 359/360、560/561)
- **状态**:hover、键盘焦点(焦点框可见且非白底白字)、禁用 JavaScript、未登录访客
- **链路**:浏览 → PDP → 询盘表单(校验/Turnstile/单图案上下文)→ WhatsApp 直达 → 感谢页;后台通知链
- **维度**:视觉细节(间距、对齐、按钮设计、暗底暗字、可点击暗示、hover 变色、堆叠、排版)、功能完善(交互反馈、误导性元素)、加载性能(首屏体积、图片、缓存)

## 已知待查清单(从参考对话归纳的同款问题面)

- [ ] 视觉误导:看起来能点的东西必须能点;能点的东西要有点的样子
- [ ] 间距:贴太近的区块、文字与边缘
- [ ] 按钮设计缺失处、暗底暗字对比
- [ ] hover 状态缺失(文字/按钮 hover 应变色)
- [ ] WooCommerce 原生界面(表单提示、消息、按钮)是否需要样式化,否则评估后保持原生
- [ ] 320px 窄屏溢出(元素伸出屏幕、点击区 <44px)
- [ ] 键盘焦点可见性
- [ ] 禁用 JS 时的布局退化
- [ ] 首屏加载体积与速度基线(测,再优化,不许拍脑袋)

### SEO 验证不通过项(2026-10-25 生产实测,证据 docs/deploy/seo-verification.md)

- [x] **`/inquiry/thank-you/` 404** — 生产已返回 200(2026-10-25 复核);dev 亦 200
- [x] **`/privacy/` 404** — 生产已返回 200(2026-10-25 复核);dev 亦 200
- [x] **FAQPage JSON-LD 缺失** — 根因二连:页面查找仍用 `/pages/faqs`(实际 `/faqs/`),且抽取正则绑定已被改写的 `openFaq` 表达式。两处已修;dev 与**生产**均已输出 FAQPage(2026-10-26 复核:生产 `https://metal-divider.com/faqs/` 200,JSON-LD 内 **40** 问)
- [ ] **GSC 属性未建**:Hostinger API 写 TXT 的路径已验通(PUT 追加模式),等 GSC 建 URL 前缀属性拿 token 后一条写入;顺带:hPanel 区无 SPF/_dmarc TXT(邮件三防,归 T1 邮件面)

## 工作法(每轮)

1. 真 Chrome 截图审计(复用 /tmp/md-share-fetch 的 playwright+真 Chrome 方式)——每页型×视口
2. 发现的问题全部入本文件的清单(带截图路径),按页型分批修复
3. 修复走:分支→PR→CI→评审→合并→部署→**线上读回**(真浏览器验证)
4. 局部修复只验证受影响路径(§0.1:通过即停)
5. 每轮记录:修了什么/线上状态/剩什么(以交付计进度)

## 进度记录

(每轮追加,最新在上)

### 2026-10-26 GSC 载体就位与三项阻塞的取证(业主指示:先去 de-linker 找)

业主指出 de-linker 已提供账号与连接方式,应先去那里找。照做后逐项取证,结论如下。

**1. GSC —— 载体已装好,授权卡在 Google 登录**

沿用 de-linker 已验证的路径(`docs/specs/145-search-console.md`:Site Kit 建资源与验证,本仓库不写验证代码)。生产已装并激活 `google-site-kit` 1.190.0,实测落地:每页 `<head>` 有 `generator` meta 与 `googletagmanager` dns-prefetch、**零外部 script**、零控制台错误、零溢出(5 页)。`googlesitekit_search-console_settings` = `propertyID ''` / `ownerID ''`,`active_modules` = `false`——载体就位、未授权。

`docs/deploy/gsc-migration.md` 整份重写:原文的 T3 地址变更、T4 双前缀监控、T5 域名长期持有与 301 不中断全部建立"旧站 301 + Shopify 转出解约"之上,而该前提已被 2026-10-25 裁定作废。现版本只保留"建新 URL 前缀属性并验证"。

**2. Turnstile —— de-linker 的密钥实测不可用(错误码 110200)**

`~/.config/de-linker/turnstile.env` 有真实密钥对(`HOSTNAME=de-linker.com`, mode=managed)。用该 sitekey 在 metal-divider.com 渲染返回 **`110200`**——Turnstile 密钥按域名绑定,复用是错误路径。要为本站配 Turnstile 须在 Cloudflare Dashboard 新建 widget,而 Dashboard 登录态本机没有。

**3. 媒体变体偏小 —— 源素材本身就不够大,非代码可修**

`tools/media` 的档位是 480/768/1024/1536/2048、`tier_widths()` 只产出 ≤ 源宽的档。查实:`hotel-lobby-decorative-stainless-steel-screen-divider` 源文件 **790×788**、`kirra-balcony-screen-design` **1000×750**,仓库内 `data/` 与生产 `uploads/` 均无更大原件。所以"最大变体只有 790–1024w"是素材上限,不是管线缺陷;提升需更高分辨率的源文件。

### 2026-10-26 可点击暗示与未登录访客验收(PR #127,生产已部署)

**可点击暗示(GOAL.md 明列"看起来能点的东西必须能点")**:About 页 5 张旗舰项目卡带 `cursor-pointer`、`group` 类与**完整悬停动效**(遮罩淡入、图片缩放),点击却什么都不发生——纯 `<div>`,无链接、无处理函数、不在 Tab 序列。652×408,是全站最大的假可点击区域。改为指向 `/project-gallery/` 的锚点;`block` 保持布局,`aria-label` 给 5 个同类链接可区分的名字。验证:5 张全是带 `href` 的 `<a>`、不动点扫描报 0、点击正确跳转。

**误报(已实证)**:玻璃混合页的 3 个手风琴行被同一扫描标记,但它们带 Alpine 的 `@click`,扫描不读框架指令。实测点击后标题正确变为激活金色。

**未登录访客状态验收**:16 页全部 200、无 admin bar、无 `/wp-admin/` 链接泄漏、无编辑链接、零溢出。

**生产对比度读回**:#126 部署后 WindPress 未重编译,补编译 `1793026282 → 1793027414` 后,9 个页面复测 12 处 → **2 处**,8 个含这些元素的页面全部清零。剩 2 处是已查明的测量假象(关闭态移动抽屉内元素报告白色外壳为背景,实际坐在 `#111` 面板)。

**生产验收**:28 项全过,零控制台错误、零 4xx。

### 2026-10-26 文本对比度与生产焦点读回(PR #126,生产已部署)

**WhatsApp 按钮白字 1.98:1**:白字在品牌绿 `#25D366` 上只有 1.98:1,低于 14px 文字所需的 4.5:1,连大字号 3:1 都不到。出现在悬浮按钮与 **9 个页面**的所有 "Chat Now"/"Chat on WhatsApp"。绿色保留(识别线索),文字改 `#0a2e14`(同底色 8.6:1);页面内按钮带 Tailwind `text-white`,覆盖需 `!important`。

**质证书 10px 标签 3.85:1**:`#8a7a4e` 在纸色 `#f7f4ee` 上 3.85:1,10px 小字需 4.5:1。改 `#6f6238`(同底色 5.7:1),保留哑金调。

**复测**:dev 9 个页面,canvas 逐像素测量,修前 12 处 → 修后 8 个含这些元素的页面全为 0。剩 2 处在落地页,经查是测量假象(关闭态移动抽屉内元素报告白色外壳为背景,实际坐在 `#111` 面板上)。

**生产焦点读回**:#125 部署后 WindPress 未重编译,补编译 `1793023800 → 1793026282` 后,生产真实 Tab 走查 6 页 **102 个焦点站全部有可见双色环**,零缺陷;鼠标点击仍不画环。

**方法学(三次测量坑,均已记录)**:① `oklab()` 归一化值被当成 0–255 RGB(误报 1314 处);② `linear-gradient` 金底按钮被当作无背景,回退到祖先深色(把金底黑字误判成黑底黑字);③ 停在白色祖先而漏掉内层深底(移动抽屉内的元素)。最终版:canvas 原生解析颜色、遇渐变跳过而非猜测、只判定能可靠判定的节点。

### 2026-10-26 键盘焦点地板与断点边界验收(PR #125,生产已部署)

**焦点框可见(GOAL.md 明确验收项)**:焦点样式一直是逐个组件零散加的——20 多个体积各在自己的 `:focus`/`:focus-visible` 里写 `outline: none`,有的补了替代指示、有的没有。脚本遍历每页 45 个焦点位,找出的空档:枢纽页 `View Patterns`/`Send Reference`、每个 `.spec-map__card`、博客搜索框、目录的搜索框/排序下拉/分页链接。

修法要 `!important`:那 21 处 reset 是组件自己的、携带组件优先级,低优先级兜底会直接输给它们——这正是缺口能存在这么久的原因。必须双色:第一版用站点金色,在白色页头上只有 **2.1:1**,低于 WCAG 1.4.11 的 3:1,**恰好在最需要它的地方失效**;改成白色内环 + `#131313` 外环后,黑对白 17.8:1、白对深色面 18.5:1。

验证用**真实 Tab 键**而非脚本 `.focus()`——后者会被浏览器判定为鼠标焦点、`:focus-visible` 不匹配,这是我第一轮误报 3 个"无指示"的来源。8 页 82 个控件逐站检查:每站画出双色环、每环对实测背景 ≥3:1;鼠标点击仍不画环。守卫实证可失败(改回金色即失败)。

**断点边界验收**:359/360/560/561/767/768 六档 × 17 页,页面级溢出全部为 0。脚本报的 82 个"伸出视口元素"逐一核查后**全部不是缺陷**:可横滑的轮播/标签条(`scrollable:true`),以及 `expanded-marquee-track`(无限循环动画 + `width:max-content`,伸出正是其工作原理)。

### 2026-10-26 守卫补强与生产读回(PR #124,生产已部署)

**页脚 logo 守卫**:#123 只修了值,没防回归。补一条断言"页脚 logo 必须带独立 `src`"。

这里踩到一个值得记录的坑:第一版用 `\bsrc=`,而 **`xo-src=` 也满足它**——模板完全没有 `src` 时测试仍然通过,是假守卫。改成 `(?<![\w-])src=` 后,移除属性即失败并给出正确信息,恢复后通过。守卫必须实证能失败,否则等于没有。

**部署事故(第二次,已完整处置)**:`just deploy prod --write` 在切换**完成后**、`wp md-site present` 阶段被对端关闭 SSH(`Connection closed by 46.202.156.7 port 65002`),批次留在 `pending`。代码已上线但 presentation 未跑完——这正是当时测到生产 logo 仍为空的原因。处置:补跑 `present`(partials 更新 1 个)与 `seo`,手工 WindPress 重编译 `1793023038 → 1793023800`,删一次性登录插件、清缓存、reset OPcache,最后 `resume-deploy` 归档(5 个 URL 200、缓存 hit、备份只留最近一份)。

**生产读回**:无 JS 下 4 个页面 `broken=0`、logo `nw=280`、零溢出;28 项验收全过,零控制台错误、零 4xx。

### 2026-10-26 图片变体与无 JS 退化(PR #122/#123,生产已部署)

**卡片图放大 3.38 倍**:`card-catalog.html` 是 `/shop/` 与 9 个系列归档共用的网格,却是唯一没带 `[md_pattern_image_srcset]` 的图案卡。生产 390 实测 `…-100w.webp` 放进 338px 槽位。补上 srcset,`sizes` 按实测槽宽(338/330/340/286)而非视口公式,六档断点比值 1.00–1.02。

**守卫漏洞(同一件事里更值得修的部分)**:全程 `just php-test` 是绿的,因为 `ImageSizesTest` 的两条扫描按具体上传文件名匹配,而模板写的是 `[md_pattern_image_url]` 占位符——没有 `/wp-content/uploads/` 前缀、没有 `-NNNw.webp` 后缀,被当作"图标或 logo"静默跳过。新增检查"凡读 URL 占位符的 `<img>` 必须同时读 srcset 占位符",并实证它在移除 srcset 时失败并指名文件。

**无 JS 时页脚 logo 是空洞**:页脚 logo 用 `xo-src` 靠 JS 搬进 `src`,禁用 JS 下 `src` 恒空。13 个页面逐页实测 `javaScriptEnabled:false`,**每页都报一张破图**,全是它。`src` 与 `xo-src` 并存后,13 页 `brokenImg` 全部归零,JS 开/关两态 logo 都正常加载。页头 7 处 mega 面板图保留 `xo-src` 单一写法——那些面板本身靠脚本打开,加 `src` 只会让所有访客提前下载 7 张大图。

**补齐全站无 JS 审计**:13 个页面在禁用 JS 下全部 200、零横向溢出、正文完整可读、零破图。

**判定为误报、未改代码**:家族页那批 `naturalWidth=288/273/205` 的"放大"——单张加载时 Chrome 正确报告 430/480,文件头字节也确认 430×430、480×480。页面上下文里的读数是测量时序假象。

**性能**:冷加载 FCP 1.2–5.1s 波动,但暖加载两帧渲染仅约 30ms,大布局渲染开销可忽略;波动来自网络 RTT。字节数全面下降(shop 1.15MB→458KB)。

**生产读回**:部署 `20261026T134618841588Z-0aeed7f`(`switch 0.011s`、opcache reset、present/seed-series/seed-tiles/seo 全执行、5 个 URL 200、缓存 miss→hit);WindPress 重编译 `1793020521 → 1793023038`;28 项验收全过,零控制台错误、零 4xx;生产卡片图四档比值 1.00–1.02。

### 2026-10-26 间距尺度根因与全站控件修复(PR #113/#115/#116/#117/#119/#120/#121,生产已部署并读回)

**根因**:旧站 `html{font-size:62.5%}` 被原样继承,而 Tailwind 的 spacing/radius/container 尺度是 rem。于是每个工具类只渲染出自身声明的 0.625 倍(`px-4` 10px 对 16px、`h-11` 27.5px 对 44px、`max-w-7xl` 800px 对 1280px)。#66 当年发现过同一问题但只修了字号尺度。此前所有"点击目标太小""内容离边 10px"的报告测的都是这一件事,当时在逐个选择器打补丁。

**修法**:守住 10px 根(旧站搬来的 234 处 rem 因此保住原视觉),在 `@theme static` 里把三个尺度钉成 px;体积内 425 处 literal rem 工具类定义同步转 px(它们是同一批工具类的第二套定义,位置更靠后)。`md-volume-check` 新增"裸工具类不得声明 rem"的门,落地时立刻抓出手工转换漏掉的 12 处负值偏移。

**同时修复**:询盘弹窗 1440 提交按钮落到折叠线下、psd 详情图 srcset 不换导致点了图不变、FAQ 搜索后分类高亮不同步、`hidden` 类与属性冲突导致面板点了不出现、ppr 卡片死交互、expanded-mesh 旧场景背景永不清除(多次滑动叠影)、抽屉触发器 aria 不更新、落地页残留触控目标。

**流程缺陷**:`just py-test` 长期红在"体积文件必须等于生成器输出"——博客移动端样式被手工写进体积。已搬进 `assets/blog-index.append.css` 并让 `fork_volumes` 也追加 appendice。

**生产读回**(真 Chrome,干净 profile):`px-4` 16px、`py-3` 12px、`h-11` 44px、`gap-4` 16px、`mb-8` 32px、`rounded-xl` 12px、`max-w-7xl` 1280px、`max-w-3xl` 768px;`1rem` 仍 10px、`text-sm` 仍 14px。28 项验收全过(尺度 9 项、交互 12 项、390/320 触控各 1 项、运行时内核 1 项),零控制台错误、零 4xx。部署 `20261026T130446316828Z-96a81b1`,WindPress 重编译 `1793007203 → 1793020521`(572444B)。

**部署事故**:`just deploy prod --write` 在切换后卡在验证阶段(无子进程、CPU 近 0、8 分钟无进展),已停止。核实代码 md5 与本地一致、批次 `state=archived`、旧文件已归档,再手工补 WindPress 重编译与独立验收,以实测替代被中断的自动验证。

**仍未完成**:Turnstile `not_configured`(需 Cloudflare 登录或密钥);GSC 属性未建;部分媒体最大变体仅 500–1024w,宽容器会放大。

### 2026-10-26 移动端 App 化与全站交互(PR #84/#85/#90,生产已部署)

**设计裁定**:旧站只提供设计语言,允许独立现代化设计,不设像素通过线。移动端以 App 质感为目标:44–54px 触控、16px 输入、全宽抽屉、底部筛选/询盘 sheet、卡片与统计层次、安全区和真实内容优先;悬浮 WhatsApp/回顶的突出层次保留,不机械遮挡治理。

**实现与证据**:expanded mesh 场景与 FAQ、九类失效控件、定制页内联 `onclick` 全部接到现有运行时;证书/对比样式、重复 class 清理。移动全宽菜单、分类筛选、PDP 询盘条、表单、卡片、tab、制造长滚动与证书控制均已真 Chrome 实测 390/320。部署 `20261026T092337501384Z-9da89ab`,WindPress 重编译 568834B,anchors 全中,缓存 miss→miss→hit。生产移动截图与控制实测:抽屉关闭、筛选 10 系列、证书 EN 10204 切换、首页 hero 图片恢复。

**仍未完成**:Turnstile 当前 `not_configured`(需 Cloudflare 登录或密钥);GSC 与 CookieYes 处置待完成;全站不能称全部验收完成。

### 2026-10-26 Woo 唯一资料源与全站交互整合(PR #71–#75,生产已部署并读回)

**本批交付**:提交 `3a75a10`,分支 `integration/visual-parity`,PR #71。商品、系列与编辑精选只从当前 Woo 数据读取;PDP 两段文案、系列描述/缩略图、首页 featured 卡、定制页 `menu_order` 卡、计数和上下架联动已接通。卡片与瓦片 markup 落到仓库管理的 LiveCanvas partial,PHP 只做数据;部署依次执行 present → seed-series → seed-tiles → seo,后续 Woo 后台修改与有意移除不被 seed 回滚。

**实测修复**:mega-menu 与移动抽屉、业主批准的 sticky header、键盘焦点与弹层 inert/回焦、画廊 64 图、tab、目录搜索/排序/分页及空态、PDP 图槽 solo、博客 root 尺度、FAQ/材质/报价语义、canonical/noindex、permalink 与 WindPress anchors、dev 视频 Range。多轮独立 Standards/Spec 评审发现的问题均已在本批处理并复验。

**门禁**:`just check` 全绿;PHP 288 + 70 + 6 + 21 tests 通过;Python 51 + 36 + 46(+1 skip)+ 93 + 67 tests 通过;`just migrate reconcile` **13/13 PASS**,零待写。两个可重建 pytest 探测实例及数据库已回收,dev 保留且 HTTP 200。

**生产读回**:PR #71–#75 全部 CI 通过并合并;最终部署 `20261026T004838168731Z-77e2440` 已验证归档。SEO drift 0,anchors 正常,seed-tiles 18/18 与 seed-series 幂等;products/media dry-run 各 146 identical。真 Chrome 读回首页 9 卡、hub 9 图、定制页 18 卡、画廊 64 图、PDP 两段 Woo 文案、材质/报价禁词 0、关键页 390 溢出 0;OPcache reset 两次 done/attempt 1,缓存 miss→hit。完整备份 `backup-20261026-0008` 共 28693 files,数据写入前完成。

**尚未完成**:全站像素门明确未通过,Gradient 只退出空内容基线对照而不做 301;性能优化、完整询盘线上实走及 GOAL 全矩阵仍是持续 goal,不以本批局部验收冒充全站完成。

### 2026-10-25 阶段二·第二轮:交互层与目录真身修复(dev 全验 → 生产已部署并读回)

**方法**:先读生产线上实物(curl/SSH/真 Chrome),不采信上一轮的记录与注释;发现与记录不符即追到根因再改。所有修复在 dev 实例用真 Chrome + HTTP 实测复验。

**推翻的上一轮结论(证据)**:
1. “logo 资产不存在,只能文字兜底” **假**——旧站 CDN 的 `metal-divider-primary-logo.webp`(937×414 真字标)与 `metal-divider-icon-logo.webp` 可下载;已按最终像素管线的 WebP 参数重编码,入库 `plugins/md-site/assets/brand/`。页脚旧图 `stainless-steel-screen-logo.png`(旧站实物)同样取自旧站。
2. “字体只是占位,等以后再补” **当时为真、已补**——`tokens.css` 指向的 9 个 woff2 全 404;现为 Google Fonts 官方 latin 可变字体(Inter wght 100–900、Playfair 400–900,SIL OFL),自托管于 `assets/fonts/`,两文件替代九份重复拷贝。
3. “WindPress 任意值类修好就只剩样式微调” **假**——站点根本没有交互运行时:转换把 `<script>` 开标签剥掉、留了 JS 正文(shop/series 两页整段 JS 当文本渲染,`</script>` 悬空,`</div></main></section>` 三处闭合丢失),且全站依赖的 Alpine 与 xo-webcomponents 均未加载。现已:自托管 Alpine 3.17.5 CSP 构建 + collapse/focus(安全基线无 unsafe-eval,不能用标准构建),自研 `md-chrome.js` 重实现 xo-modal/xo-tabs/xo-lazyload/xo-sticky 与 8 个 CSP 无法解析的内联组件;dev 实测菜单开合、首页 tab、FAQ 手风琴、报价弹窗、跑马灯均 0 控制台错误。
4. “PDP 只剩样式问题” **假**——`md_pdp_style()` 声明在插件命名空间内,`[lc_wp_func]` 按全局名解析失败,每个 PDP 把 PHP 错误直接印给访客。已改为全局函数并补单测。
5. “商品目录已迁完” **假**——shop/series 的卡片是写死的 Agave,`[lc_loop]` 套在 `<article>` 内,每页只渲染 1 个卡片 + 空态框;无分页、搜索 POST `/search`(WP 无此路由)、排序 POST Shopify 参数。现由 `[md_pattern_card]` 逐品装配 + `[md_pattern_pagination]` + `[md_pattern_results_summary]` + `pre_get_posts` 承接搜索/排序;dev 实测 /shop/ 16 卡、10 页、搜索 apiary→1、Z–A 与 Newest 排序生效。
6. “询盘表单只差触达高度” **假**——`inquiry.css` 整段按原型类名(`.field`/`.btn-primary`)写,插件实际输出 `md-inquiry-*`,所以输入框 21px、提交键无样式、honeypot 对用户可见。已按真实 markup 重写选择器,honeypot 移出视口。
7. “视频/联系方式已迁” **假**——41 个 `<source>` 仍指 `//metalpartition.com/cdn/...`,8 个 poster 指向 `.mp4`;模板内 WhatsApp 34 处中 31 处是旧双号或占位号。已全部改本地 uploads 与 `+85264731687`,邮箱统一 `info@metal-divider.com`;页脚电话/邮箱补齐。

**本轮已改并在 dev 复验**:上述 1–7;另含 `windpress-check` 缺斜杠(#55 根因)、windpress-compile 的假前置守卫与 `npx playwright install chromium`(违反真 Chrome 铁律)、FAQPage 抽取与 `/faqs/` 路径、移动触达(页脚链 57px、首页 chips 44px、表单 45px、头部报价键 44px+)、页脚 logo 1024px 原图造成的 634px 横向溢出(两个视口 7 页复测 overflow=0)、桌面 header 113px 与旧站一致、生产 `md-volumes.css` 外挂(H 级漂移:684KB 旧 CSS 由不在仓库的 mu-plugin 注入,桌面下拉全靠它)已由仓库内导航样式替代。

**生产部署(业主 2026-10-25 明确授权后执行)**:`just deploy prod --write` 四次成功(deploy id `20261025T133354099649Z-fe9effa`、`20261025T140158580405Z-bbf141c`、`20261025T141628830055Z-5f4d8a7`、`20261025T142709646574Z-25c4560`;含 821c1d3 起全部修复),present/seo/verify 全绿,DB 快照留在本机。生产 WindPress 经一次性登录链接在真 Chrome 重编译(464,835 B,mtime 1792939056;`windpress-check` 读回一致);登录用的临时 mu-plugin 已删除并读回确认。外挂 CSS 已退役:`md-volumes.css`(684KB)与 `mu-plugins/md-volume-css.php` 先备份到 `~/metal-divider-local/retired/2026-10-25-md-volumes-css/`(sha256 记录),再删除,URL 现 404;桌面下拉改由仓库 `chrome.css` 承担。WindPress 由默认 hybrid 改 cached(数据设置,同时写进 `wp md-site present` 幂等),HTML 从 1.1–1.3MB 降到约 250KB,页面不再注入 600KB 内联 VFS;WooCommerce 前台样式/脚本与 Hostinger Reach 按 CHECK-3/4 出队,控制台 CSP 报错 7 页面 0。favicon 补齐(32/180/512 PNG)。

**客户视角终检(clean profile,2026-10-25)**:7 页型 × 390/320 两视口,HTTP 全 200、**横向溢出全 0**、控制台错误全 0;触达目标从每页 19–30 项降到 5–16 项(余下为正文内联链接、轮播圆点等 WCAG 2.5.8 豁免项,以及 honeypot 的 1×1 离屏输入)。首页 20 条图片"溢出"均为 overflow 轨道内的轮播图,非缺陷。

**仍待办**:GSC 属性;CookieYes 尚未安装(栈内项;当前零非必要 cookie,严格必要类是否上线需按合规口径定)。

**已收口(2026-10-26 读回,原列于上一条"仍待办"的 `windpress-anchors`)**:命令已实现并接进部署检查——`plugins/md-site/includes/Presentation/WindPressAnchors.php`(锚集:模板静态 `class` 里的任意值类 + `tokens.css` 的 `--md-*` 声明)、`includes/Cli/WindPressAnchorsCommand.php`(在 `Plugin.php` 注册),`tools/deploy` 的 `windpress-check` 按 `wp md-site windpress-anchors --format=json` 解析其输出。dev 实测(2026-10-26 重跑):`--format=json` → `[]`;表格模式 → `windpress anchors: 508 present in the compiled cache`(锚集构成 493 任意值类 + 15 token,来自 15 个文件);`just deploy windpress-check --target dev` → `anchor classes present`。**这个数字跟着仓库模板走**,不是常量:锚集由 `template_files()` 扫 `content/livecanvas/*.html` 与 `partials/*.html` 得到,模板增删类即变(此前记为 505,是更早那次读回的值;当前实测 508)。同理缓存 mtime 每次编译都变,只作"编译确实落盘且比 `--before` 新"的判据,不写死。单测 `phpunit --filter WindPressAnchors` → 18 项全过(`Cli/WindPressAnchorsCommandTest` + `WindPressAnchorsTest`)。**生产尚未带此命令**:`just deploy windpress-check --target prod` 只读到 `windpress cache mtime: 1792939056`,锚类断言按设计报"需要 md-site 自己的检查器且它没有回答";SSH 直读该实例给出同一事实的正面证据——`Error: 'windpress-anchors' is not a registered subcommand of 'md-site'`(生产插件是上一次部署的版本)。随下一次代码部署生效,不是实现缺口。

### 2026-10-25 移动端视觉审计(阶段二·第一轮:390×844 / 320×568)

**范围**:7 页 × 2 视口 = 14 组合(首页、/shop/、/collections/pattern-abstract、/pattern/apiary-architectural-metal-screen-pattern、/faqs、/about-us、/inquiry/),全部 HTTP 200。真 Chrome(channel:'chrome' + ~/metal-divider-local/md-admin-profile),程序化检测(溢出/字号/触达/图片)+ 截图视觉复核。截图:`data/pixel-reports/mobile-audit/`(14 张全页 + 14 张菜单开态 + 26 张区块图,`<页>-<视口>[-menu|-sN].png` 命名)。**除标注外,以下布局类问题均待 #58(WindPress 任意值类未编译)修复后复验。**

**P0(阻断)**

1. **Woo 商店页对匿名访客返回 coming-soon 占位页**:/shop/、/collections/pattern-abstract、/pattern/apiary-… 三页渲染完全相同的 "Great things are on the horizon" 占位页(Woo 9 默认文案);匿名 curl 无 UA 依赖同样命中,桌面同病——非移动端特有。核心链路(图案浏览→PDP→询盘)对真实访客不可达。若非刻意的 pre-launch 状态即为最高级缺陷。证据:`shop-320x568.png`、`pattern-390x844.png`(两文件 MD5 相同)。
2. **移动菜单闭态裸渲染(全页×两视口)**:`xo-menu-hamburger-style1__modal` 以 `display:inline`、无遮罩无定位直接渲染进页面流——闭态即可见一串默认蓝色下划线菜单链接+被截断的跑马灯 "…ion expe";开态无面板、无关闭按钮,链接行仅 ~18-24px 高。320 下 `.xo-header-actions`(330px)/`.mobile-menu-trigger`(170px)造成**全站统一 57px 水平溢出**;390 下不溢出但同样裸露。证据:`home-320x568-menu.png`、`home-390x844-menu.png`、`inquiry-320x568.png` 顶部。

**P1(严重)**

3. **头部+底部 logo 全 404**:`metal-divider-primary-logo--120w.webp`、`metal-divider-icon-logo--80w.webp`、`stainless-steel-screen-logo--100w.png` 均 404(naturalWidth=0,断图+alt 文字裸露);`--Nw` 后缀疑为 srcset 文件名生成错误。与视口无关。证据:`inquiry-320x568.png`。
4. **询盘表单字段高仅 21px**(320 下输入框 153×21、提交按钮 90×21、菜单内搜索框 21px,行距 8-14px)——远低于 44px 触达标准,疑 `min-h-[44px]` 类未编译(#58)。390 同测 21px。证据:`inquiry-320x568.png`。
5. **询盘页 honeypot 可见**:"Leave this field empty" 标签+输入框在 320 截图中对用户可见,反爬失效风险(390 待复验)。证据:`inquiry-320x568.png`。
6. **FAQ 手风琴分类行 26px 高**(<44px),8 个分类,两视口同测。

**P2(一般)**

7. 首页 proof 轮播分类 chips(`.emt-nav-btn`)38×16px;进度点 35×6px(轮播点属常见例外,列观察)。证据:`home-320x568-s2.png` 区域。
8. 头部 "Request a Quote" 按钮(`.xo-btn-quote`)30px 高,全页两视口。
9. 页脚内容缺陷:"Phone:" 值为空;邮箱用旧域 `projects@metalpartition.com`(客户面应为 `info@metal-divider.com`);页脚链接默认蓝下划线(#58)。
10. 观察:首页 hero 首屏(320)无可见 CTA;轮播卡组无边缘 peek 提示。

**通过项**:无 <12px 计算字号;图片无拉伸变形(首页"溢出"图片均位于 overflow-hidden 轮播轨道内,非缺陷);about/faqs 主体区块 320 下布局干净;390 无页面级水平溢出。

### 2026-10-25 SEO 上线验证(GOAL 阶段二)

生产 https://metal-divider.com 九项一手核查,原始输出 [docs/deploy/seo-verification.md](docs/deploy/seo-verification.md)。通过 6 项:robots.txt(七行+Sitemap 逐字)、sitemap(147 产品+1063 图、/sitemap.xml 301→/sitemaps.xml)、首页/PDP meta+canonical+OG、noindex 面(搜索/作者/附件)、llms.txt、hreflang 零输出、301 六抽全绿(含 /products/→/pattern/ regex、/pages/contact→/inquiry/)。不通过 4 项已入上方清单(thank-you 404、/privacy/ 404、FAQPage schema 缺、GSC 待建)。附:Hostinger OAuth token 过期已刷新回写 ~/.config/hostinger-mcp。
