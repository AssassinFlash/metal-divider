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

- [ ] **`/inquiry/thank-you/` 404**:生产 page 表无此页(md-inquiry 链路断在成单致谢步;`/pages/custom-metal-divider-screens-thank-you` 的 301 也落进这个 404)
- [ ] **`/privacy/` 404**:privacy-policy 页停留在 draft;页脚法务链与 `/policies/privacy-policy` 301 均落 404
- [ ] **FAQPage JSON-LD 缺失**:`/faqs/` HTML 有 40 对 Q&A,但生产 `seopress_schemas` CPT 只有 Product/BlogPosting 两条(dev 验收 ×3 中的 FAQPage 未落生产)
- [ ] **GSC 属性未建**:Hostinger API 写 TXT 的路径已验通(PUT 追加模式),等 GSC 建 URL 前缀属性拿 token 后一条写入;顺带:hPanel 区无 SPF/_dmarc TXT(邮件三防,归 T1 邮件面)

## 工作法(每轮)

1. 真 Chrome 截图审计(复用 /tmp/md-share-fetch 的 playwright+真 Chrome 方式)——每页型×视口
2. 发现的问题全部入本文件的清单(带截图路径),按页型分批修复
3. 修复走:分支→PR→CI→评审→合并→部署→**线上读回**(真浏览器验证)
4. 局部修复只验证受影响路径(§0.1:通过即停)
5. 每轮记录:修了什么/线上状态/剩什么(以交付计进度)

## 进度记录

(每轮追加,最新在上)

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
