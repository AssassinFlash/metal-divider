# 询盘链规格(票 #7)

日期:2026-10-24。执行会话定稿(业主 2026-10-24 裁定:grilling 票按执行会话推荐定稿,业主保留否决)。实现票:#12。

本文锁定询盘链的行为与数据规格。页面视觉方向归原型票,不在本文;复刻像素门只适用于复刻页,本文涉面均为新功能面。

## 0. 依据事实(已定,不再研究)

| # | 事实 | 来源 |
|---|---|---|
| F1 | 无账户、无结账、无支付;146 图案以产品承载,价格恒 $0 展示态;链路唯一:图案浏览 → 图案清单 → 询盘/WhatsApp,之后线下 | AGENTS.md §1 |
| F2 | 早前裁定「双号码与 projects@metalpartition.com 平移」——**已被 F12 覆盖**:双号码与 projects@ 均不迁移;「轻量清单 + 表单 + WhatsApp、不过度复杂」仍有效 | 业主 2026-10-24(AGENTS.md §2);覆盖见 F12 |
| F3 | 旧站对外联系(仅作历史事实):mailto projects@metalpartition.com;tel +8613068660047;WhatsApp 8613068660047 / 8613312892178 | 旧站联系数据;不迁移(F12) |
| F4 | 店铺账号邮箱 martinfu127@gmail.com(shop.json `email`,Shopify 通知兜底);对客联系/发件邮箱 sales@sunriverinverter.com(`shop.contactEmail` 与 `customer_email`,跨域邮箱);Admin API 不暴露 Staff 通知收件人 | #10 导出 `data/shopify-admin-export/notifications.json` |
| F5 | 旧站 contact 链接带三段参数 `/pages/contact?source_page=X&source_module=Y&source_button=Z`;wa.me 链接文本预填「Hi, I'm interested in a custom metal panel project」且错误地把 source 参数追加为 wa.me 的查询参数(WhatsApp 忽略之) | 旧主题模板 grep 实测(2026-10-24) |
| F6 | 旧站词汇实测:source_page=页面 slug(home/about/pattern-pdp/产品族 slug…);source_module=模块名(hero/cta/contact/faq/process/project-showcase/specify/techniques…);source_button=动作(request_quote/whatsapp/start_project/send_project_context/primary/secondary/view_project…) | 同上 |
| F7 | 旧站两张页面均 published:`/pages/contact`(Contact Us)与 `/pages/custom-metal-divider-screens-thank-you` | #10 导出 pages.json |
| F8 | wa.me click-to-chat 官方格式:`https://wa.me/<国际格式号码,不带+括号横线>?text=<URL编码文本>`;预填文本出现在聊天输入框(用户仍需自行发送);手机与 WhatsApp Web 均支持;官方未文档化文本长度上限 | WhatsApp 官方 FAQ「How to use click to chat」,Apify website-content-crawler 抓取 2026-10-24 |
| F9 | Turnstile:token 300 秒有效、单次使用;siteverify `POST https://challenges.cloudflare.com/turnstile/v0/siteverify`,字段 secret/response/remoteip/idempotency_key;错误码 missing-input-secret / invalid-input-secret / missing-input-response / invalid-input-response / bad-request / timeout-or-duplicate / internal-error;客户端 callback/error-callback/expired-callback/timeout-callback、retry:auto、appearance=always/execute/interaction-only、implicit(cf-turnstile class)与 explicit 两种渲染 | developers.cloudflare.com/turnstile,2026-10-24 直读 |
| F10 | 栈:WP + WooCommerce + ACF Pro + LiveCanvas + WindPress + Turnstile + CookieYes;首期 EN 单语;呈现层规则与「自定义代码最后手段 + 穷尽证明」;插件零 CSS;测试邮件一封一址 + 队列断言 | AGENTS.md §2/§5 |
| F11 | pattern_code 为产品 metafield 覆盖面最广的 key 之一(2934 条 metafields 里的高频 key) | #10 导出 products.json |
| F12 | **业主 2026-10-24 深夜供给(决定性,覆盖任务书旧假设,故置于表末)**:① WhatsApp 对客单号 **+85264731687**(业主自有);tel 链接同号;旧双号码不迁移。② 内部通知收件人:**sales@sunriverinverter.com(主)+ info@metal-divider.com(备)**,均业主自有。③ 通知机制**原生实现**:自研插件直接走 WP 邮件管线(wp_mail + 出站队列 + 重试,参照 de-linker 的 Hostinger mail() 模式),不引入 form-data 类第三方中转。④ 对客 mailto = **info@metal-divider.com**(projects@metalpartition.com 不迁移) | 业主 2026-10-24 深夜,经协调会话转达 |

## 1. 决策总表

| # | 决策 | 结论 | OPEN |
|---|---|---|---|
| D1 | 图案清单交互与存续 | localStorage(`md_pattern_list_v1`),上限 20 条;入口=图案 PDP + 所有图案卡片 + 全站页头清单图标/抽屉;不用 WooCommerce 购物车 | — |
| D2 | 询盘表单 | 单页单步 `/inquiry/`;字段:姓名*、邮箱*、公司(选填)、留言(清单为空时必填);清单自动附带;source 三段参数平移并扩展 | — |
| D3 | WhatsApp 接入 | 纯 wa.me 链接(零第三方挂件脚本);全站浮动按钮 + 表单内「改走 WhatsApp」;**单号 85264731687**(旧双号码不迁移);清单以「名称+pattern_code」进 text 参数,来源作 Ref 行进文本 | — |
| D4 | 邮件路由 | 内部通知→ sales@sunriverinverter.com(主)+ info@metal-divider.com(备);客户确认 From **info@metal-divider.com**(推荐、域名对齐、可切换);原生 wp_mail + 出站队列 + 重试,不用第三方表单/SMTP 中转;对客 mailto = info@ | — |
| D5 | 提交后体验 | 303 跳转 `/inquiry/thank-you/`(noindex);旧感谢页 URL 与旧 /pages/contact 均 301;成功即清空清单;WhatsApp 路径不清空 | — |
| D6 | 后台可见性 | CPT `md_inquiry` 入库(系统记录源),ACF Pro 字段组呈现;邮件只是通知不是档案;明确拒绝「邮件-only」与「WooCommerce 订单」 | — |
| D7 | Turnstile | 唯一接入点=询盘表单;managed 模式;服务端 siteverify 必过才落库发信;fail-closed;归 CookieYes 严格必要类 | — |

## 2. D1 图案清单

### 决定

**存续:localStorage,键 `md_pattern_list_v1`,JSON 数组。**

- 条目结构:`{ "id": <产品ID>, "title": <图案名>, "code": <pattern_code,可空>, "handle": <slug>, "thumb": <缩略图URL>, "addedAt": <ISO时间>, "source": { "page": …, "module": …, "button": … } }`。读取时 schema 校验,损坏条目静默丢弃。
- **数量上限 20。** 达到上限时加入被阻止并 toast「清单已满(20),请先移除一项」;不做 FIFO 静默淘汰(不吞用户意图)。
- 重复加入:幂等,toast「已在清单中」,不产生第二条。

**拒绝 cookie 的理由**:RFC 6265 每cookie 仅须支持 4096 字节,20 条目 JSON 逼近上限;cookie 随每个请求往返(全站噪音);需要过期管理。localStorage 每源约 5MB,免请求开销,天然跨会话(B2B 研究周期以天计;sessionStorage 被拒因不跨会话)。localStorage 属严格功能存储(用户主动请求的服务,ePrivacy 功能豁免),不经 CookieYes 同意门槛;CookieYes 只拦脚本,不拦第一方功能性存储,二者无耦合。

**拒绝 WooCommerce 购物车当清单(穷尽证明,AGENTS.md §5 呈现层义务)**:购物车是交易语义(F1 裁定无交易;GLOSSARY 禁「订单」用词,购物车页满屏 Add-to-cart/Checkout 语义);其持久化走 cookie/session,同样过不了存储判断;把结账页改造成询盘表单等于与 WooCommerce 结账流对抗,复杂度远超一张自持表单。LiveCanvas/WooCommerce 原生 tag 无跨页客户端收集组件。故清单逻辑为功能件 JS(自定义代码最后手段,此处已穷尽原生替代),视觉仍全部落 LiveCanvas 模板 + WindPress 编译样式,插件输出语义 HTML 零 CSS(AGENTS.md §5)。

**入口与 UI 规格:**

| 页型 | 入口 | 形态 |
|---|---|---|
| 图案 PDP | 主操作区「加入图案清单」按钮 | 与「询盘此图案」并排;已在清单时切换为「已在清单 ✓(点击移除)」 |
| 图案卡片(系列页、产品族页、首页精选、搜索结果——凡渲染图案卡片处) | 卡片角部快速加入 | hover 显示、触屏常显的图标按钮;已加入态有标记 |
| 全站所有页型 | 页头清单图标 + 数量徽标 | 点击开右侧抽屉 |

**抽屉内容**:条目列表(缩略图、名称、pattern_code、加入来源页、移除 ×)、「清空全部」(二次确认)、「提交询盘」CTA(→ `/inquiry/` 带 source 参数)、「改走 WhatsApp」次级链接(见 D3)。

### 验收要点

- 加满 20 条后第 21 条被拒且 toast 出现;移除一条后可再加。
- 同一图案重复点击不产生重复条目。
- 关浏览器重开,清单与页头徽标数保持。
- 清单为空时抽屉显示空态 + 去浏览图案的引导,CTA 禁用。
- 抽屉与按钮的样式来自 WindPress 编译产物;禁用插件注入 CSS(AGENTS.md §5)。
- localStorage 被手动写坏后页面不崩,坏条目被丢弃。

## 3. D2 询盘表单

### 决定

**单页单步,URL `/inquiry/`(新 WP 页,LiveCanvas 模板)。旧 `/pages/contact` 301 到 `/inquiry/`。** 四个字段 + 清单,一屏放下,不分步(分步只在字段多到装不下时才有收益;此处无)。

| 字段 | 必填 | 约束 |
|---|---|---|
| 姓名 name | 是 | ≤120 字符 |
| 邮箱 email | 是 | email 格式校验,≤200 |
| 公司 company | 否 | ≤160 |
| 留言 message | 条件 | ≤5000;**清单非空时可空,清单为空时必填**(至少表达一个询盘意图) |
| (隐藏)source_page / source_module / source_button | — | 从 URL 透传,服务端白名单校验 |
| (隐藏)locale | — | 首期恒 `en` |
| (隐藏)清单 | — | 客户端传条目数组;服务端按产品 ID 反查重建(标题/编码以服务端为准,不信任客户端文本) |

**清单自动附带**:表单上方渲染清单条目行(缩略图、名称、编码、× 移除);在表单里移除同步写回 localStorage。不设电话字段:回复走邮箱,实时走 WhatsApp(业主「不过度复杂」裁定;字段集止于此)。

**来源追踪(承接 F5/F6 三段式并扩展)**:

- 沿用参数名与取值法:`source_page`=页面 slug、`source_module`=模块名、`source_button`=动作,全小写连字符。
- 新增取值(旧词汇保持语义,不改动):`source_button=add_to_list`(PDP/卡片的加入动作)、`source_module=list-drawer`(抽屉内 CTA)、`source_button=send_list`(抽屉「提交询盘」)。完整词汇表见附录 C。
- 传播路径:CTA 链接 → `/inquiry/` URL → 隐藏字段 → CPT meta(见 D6)→ 内部通知邮件正文;每个清单条目在加入时各自记录 source(见 D1 schema),「加入来源」与「提交来源」分开存。
- WhatsApp 路径的来源以 Ref 行进消息文本(见 D3),不重复旧站「把 source 追加为 wa.me 查询参数」的无效做法(F5)。

### 验收要点

- 三个 source 参数从任意 CTA 到内部通知邮件全程可见、无丢失。
- 清单为空:留言必填生效;清单非空:留言可空提交。
- 表单内移除条目后,页头徽标数同步减少。
- 无效 source 值(不在白名单)被服务端归一为空,不报错不落库。

## 4. D3 WhatsApp 接入

### 决定

**形态:纯 `<a>` 链接,零第三方脚本。** 不装 WhatsApp 官方聊天挂件(挂件=第三方脚本+cookie+同意门槛+性能开销);wa.me 链接是官方 click-to-chat 通道(F8),无需任何脚本,与 CookieYes 无耦合。浮动按钮不设每页弹窗催逼,只做常驻入口。

1. **全站浮动按钮**(右下角,移动/桌面同):wa.me 到 85264731687,预填通用问候(沿用旧站口吻,见附录 B 模板 W1)。
2. **表单内「改走 WhatsApp」**:次级按钮,客户端把当前表单内容(姓名、公司、留言)与清单实时拼进 text 参数(模板 W2),点击即走,不经过服务端提交。

**号码:对客单号唯一 = 85264731687(业主自有,+85264731687;F12)。** 旧站双号码(8613068660047 / 8613312892178)不迁移,新站任何页面不出现;原「双号码分工」决策项作废——单号即全部分工。tel 链接若保留,同号:`tel:+85264731687`。wa.me 格式:`https://wa.me/85264731687?text=…`——国际格式、不带 +(F8;旧站模板同样做了 `replace: '+'` 清洗,F5)。

**清单怎么进消息文本**:每图案一行「序号. 名称 (pattern_code)」。不含 URL——20 条 URL 编码后逼近浏览器与重定向链的安全边界,而编码+名称已足够客服在 WP 后台检索到图案(pattern_code 是 #10 导出中覆盖面最广的 metafield 之一,F11);完整 URL 走邮件路径。20 条 × ~45 字符 + 首尾模板 ≈ 1.2K 字符,URL 编码后 ~1.7K,处于安全区(官方无上限文档,F8,但按查询串实践保守设计)。

**双号码分工:作废。** 对客单号唯一(F12),浮动按钮、表单直达、tel 全用 85264731687;不存在分流维度,亦无 OPEN。

### 验收要点

- 浮动按钮在全部页型常驻;点击在移动端唤起 App、桌面进 WhatsApp Web,预填文本出现在输入框(F8 行为)。
- 表单内 WhatsApp 按钮的文本包含:姓名/公司(若填)、每图案「名称 (code)」行、留言(若填)、Ref 行。
- 清单 20 条满载时链接仍可打开,文本完整。
- 号码链接均为不带 + 的 wa.me 格式;tel 链接保留 `+` 前缀(电话协议要求)。
- WhatsApp 路径不清空清单(预填 ≠ 已发送,F8)。

## 5. D4 邮件路由

### 决定

| 邮件 | To | From | Reply-To | 触发与内容 |
|---|---|---|---|---|
| 内部通知 | sales@sunriverinverter.com(主);info@metal-divider.com(备) | info@metal-divider.com | — | CPT 落库后发;含全部字段、三段 source、清单(名称+编码+后台编辑链接) |
| 客户确认 | 客户所填邮箱 | **info@metal-divider.com(推荐,可切换)** | sales@sunriverinverter.com | 同批触发;EN 文案走对抗评审(AGENTS.md §5):致谢、清单回执、下一步(人工回复时限承诺)、WhatsApp(+85264731687)与 mailto(info@)联系方式 |

**理由**:

- **收件人与号码均为业主深夜定案(F12),路由不再是 OPEN。** sales@sunriverinverter.com 从「旧站对客邮箱」(F4)转任内部主收件——业主自有信箱,收件无域对齐问题;info@metal-divider.com 同为业主自有,任备用收件人兼站点域发件人。martinfu127@gmail.com(Shopify 账号邮箱,F4)不再进入询盘路由——业主已穷举收件人清单。
- **From=info@metal-divider.com(推荐,单一插件常量可切换)**:发件域必须与站点域对齐,否则 SPF/DKIM 不过、确认邮件进垃圾箱。info@ 同时是全站对客 mailto(F12),对客身份统一。若日后要隔离退信,可切 no-reply@metal-divider.com——同域,策略不变,故此条写为「推荐+可切换」。
- **客户确认 Reply-To=sales@sunriverinverter.com**:客户回信直达业主主销售信箱;Reply-To 不受域对齐约束。
- **承运=原生 WP 邮件管线(F12 业主明示):自研插件直接 wp_mail + 出站队列 + 重试,参照 de-linker 的 Hostinger mail() 模式。** 不引入 WP Mail SMTP、FluentSMTP 或任何 form-data 类第三方中转——业主原则「能原生做到就不用它」;de-linker 已在同栈实证该模式。队列即 AGENTS.md §5「队列收件人数据断言」的落点:每封出站信在队列表中留收件人/状态/重试计数,断言据此做,不做多封实发。
- **mail() 模式的送达前提**:metal-divider.com 的 SPF/DKIM 记录须覆盖 Hostinger 发信服务器——列入验收实测(发一封到指定测试地址并检查头),不作为 OPEN。

**拒绝的替代**:第三方表单/SMTP 中转插件——被业主原则直接排除(F12);wp_mail 裸发无队列——丢信无感知、无法断言,被 de-linker 模式(队列+重试)取代。

**测试纪律(照 AGENTS.md §5,验收即按此执行)**:任何测试场景只发一封、只发指定测试地址;双收件与客户确认的路由正确性以出站队列的收件人数据断言,不做多封实发。

### 验收要点

- 提交成功后:队列断言内部通知收件人=[sales@sunriverinverter.com, info@metal-divider.com]、确认邮件收件人=[客户邮箱]、From/Reply-To 如表;实发只发一封到指定测试地址,信头 SPF/DKIM 通过。
- 队列重试实测:模拟单次发信失败,队列状态留痕并按退避重试成功。
- 确认邮件经对抗评审留档(AGENTS.md §5)。
- 对客 mailto 全站统一 info@metal-divider.com;projects@metalpartition.com 与旧双号码在新站零出现。

## 6. D5 提交后体验

### 决定

- **提交成功 → 303 跳转 `/inquiry/thank-you/`**(新 WP 页,LiveCanvas 模板,`noindex`)。跳转而非 AJAX 内联成功:给出可回访 URL、刷新不重交、双提交天然截断。
- **301 承接**:`/pages/custom-metal-divider-screens-thank-you` → `/inquiry/thank-you/`(F7);`/pages/contact` → `/inquiry/`(D2)。归入 redirect map 票。
- 感谢页内容:致谢 + 「接下来会发生什么」(人工回复时限)+ WhatsApp(+85264731687)、mailto(info@metal-divider.com)联系方式 + 帮业主帮自己的「报价前可准备」提示(尺寸、数量、安装照片)。
- **清单清空时机:服务端确认成功后、跳转前**(客户端收到成功响应即清 `md_pattern_list_v1` 并跳转);失败(Turnstile 拒绝、校验失败、网络错误)绝不清空。WhatsApp 路径不清空(D3)。

### 验收要点

- 提交后浏览器落在 `/inquiry/thank-you/`,回退到表单页清单为空、徽标归零。
- 人为制造提交失败(错误 Turnstile token),清单原样保留。
- 两个旧 URL 301 实测生效;感谢页在索引里为 noindex。

## 7. D6 后台可见性

### 决定

**CPT `md_inquiry` 为询盘的系统记录源,非 public;ACF Pro 字段组呈现;邮件只是通知,不是档案。**

- 注册于仓库自有插件(功能件;禁 Code Snippets 通道,AGENTS.md §5);supports 仅 title(自动填「姓名 — 公司 — 日期」)/date。
- 后台列表列:提交时间、姓名、邮箱、公司、图案数、source(三段合成一列)、状态。状态 = 自定义 post status:`new`(默认)→ `contacted` → `closed`,列表可按状态筛选。
- ACF 字段组承载:全部字段、source 三项、清单表(每行:名称、pattern_code、指向产品编辑屏的链接)。清单条目同时落**快照**(title/code),不只存产品 ID——产品日后删除,询盘记录仍完整可读。
- 写入顺序:siteverify 通过 → 建 CPT → 发两封通知(D4)→ 返回成功。CPT 建库失败则整体失败、不发信、不清清单(宁可让客户重交,不留无记录的询盘)。

**拒绝的替代**:
- *邮件-only*:邮件送达无保证,且无结构化检索/状态流转;丢一封=丢一条线索。被拒。
- *WooCommerce 订单承载*:订单=交易语义,F1 无交易、GLOSSARY 禁「订单」;订单流强绑支付与地址语义,削足适履。被拒。
- *表单插件(Fluent Forms/CF7 等)*:Turnstile 各家都有集成,但清单 localStorage 联动、source 白名单、CPT+ACF 字段组、WhatsApp 文本拼装全要自写代码,插件只买到渲染层——而渲染层本就该落 LiveCanvas 模板(AGENTS.md §5)。净增一个插件面,无净收益。被拒。

### 验收要点

- 后台列表可见全部列,状态可改、可筛;清单行链接跳到产品编辑屏。
- 删除某图案产品后,旧询盘记录的清单快照仍完整显示。
- 落库失败模拟下无邮件发出、客户端收到失败态。

## 8. D7 Turnstile

### 决定

- **唯一接入点:询盘表单。** 后台有登录墙、浮动按钮是纯链接、清单是本地行为——均不需要。博客评论若二期开放,届时另议。
- **组件:managed 模式,appearance=interaction-only,implicit 渲染**(`cf-turnstile` class + data-* 属性,静态页够用,F9);`retry` 保持默认 auto;theme 跟随站点。
- **回调接线**:callback→解锁提交;expired-callback→禁用提交 + 提示「验证已过期,点击刷新」(token 300 秒/单次,F9);error-callback→可操作错误提示;timeout-callback→reset。同一错误多次回调只提示一次(F9 官方告警)。
- **服务端必验**:每次提交尝试生成 UUID 作 idempotency_key(F9),POST siteverify(secret 出 wp-config 常量,sitekey 绑定 metal-divider.com + 评审站子域 hostname)。任一失败码 → 拒绝:不建 CPT、不发信、不清清单,前端显示「验证失败,请刷新重试」。
- **fail-closed**:Cloudflare 全局故障时表单不可提交(B2B 容忍度优于放垃圾询盘进信箱);页面的 WhatsApp 与 mailto 通道天然是故障期备用路(F3/F8)。不做无 JS 回退——Turnstile 本身需 JS。
- **CookieYes 归类**:严格必要(安全/反垃圾),不经同意门槛;清单 localStorage 同属严格功能存储(D1)。

### 验收要点

- 无 token / 伪造 token / 已用 token(重放)三种提交全部被服务端拒绝,零落库零发信零清空。
- 挂机超 300 秒后提交,出现过期提示,刷新后可交。
- 站外域名挂 sitekey 不生效(hostname 绑定)。
- 拦截 Cloudflare challenges 域名模拟故障:提交被拒、页面 WhatsApp/mailto 可用。

## 9. OPEN 项

**当前:零条。** 业主 2026-10-24 深夜供给(F12)已覆盖执行会话此前拟出的全部待业主确认项:

| 原拟 OPEN | 处置 |
|---|---|
| 双号码分工(旧 8613068660047/8613312892178) | 作废——对客单号唯一 85264731687,旧双号码不迁移(D3) |
| 邮件基础设施(托管、SMTP、no-reply@ 建箱) | 定案——原生 wp_mail + 出站队列 + 重试(de-linker Hostinger mail() 模式),From 推荐 info@metal-divider.com(D4);SPF/DKIM 覆盖发信服务器转入验收实测 |
| martinfu127@gmail.com 是否保留 BCC | 定案——不保留;内部收件人=sales@(主)+info@(备),业主已穷举(D4) |
| sales@sunriverinverter.com 处置 | 定案——转任内部通知主收件人(D4) |

## 附录 A:localStorage schema

```json
// key: md_pattern_list_v1  (localStorage, JSON array, ≤20 项)
[
  {
    "id": 12345,
    "title": "Laser Cut Geometric Panel No.7",
    "code": "MD-0107",
    "handle": "laser-cut-geometric-panel-7",
    "thumb": "https://metal-divider.com/…/thumb.webp",
    "addedAt": "2026-10-24T01:23:45Z",
    "source": { "page": "pattern-pdp", "module": "pattern-actions", "button": "add_to_list" }
  }
]
```

## 附录 B:WhatsApp 文本模板(EN,定稿过对抗评审)

- **W1 浮动按钮**:`Hi, I'm interested in a custom metal divider screen project.`(承旧站口吻,F5)
- **W2 表单/抽屉直达**:

```
Hi, I'm {name}{ (company)}.
I'd like a quote for these patterns:
1. {title} ({code})
2. …
{message}
Ref: {source_page}/{source_module}/{source_button}
```

(编码+名称行,无 URL;Ref 行取代旧站无效的查询参数追加,F5。)

## 附录 C:来源参数词汇表

| 参数 | 取值(旧,保持) | 取值(新,本规格增) |
|---|---|---|
| source_page | home / about / pattern-pdp / 产品族 slug(如 stainless-steel-screens、laser-cut-geometric-metal-screens)/ manufacturing / interior-room-partitions / perforated-panels…(=页面 slug) | inquiry / inquiry-thank-you(表单自身页) |
| source_module | hero / cta / contact / faq / process / specify / techniques / project-showcase / home-projects… | list-drawer(清单抽屉)/ pattern-actions(PDP 主操作区)/ pattern-card(网格卡片) |
| source_button | request_quote / whatsapp / start_project / send_project_context / primary / secondary / view_project / view_project_photos… | add_to_list(加入清单)/ send_list(清单提交询盘) |
