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
