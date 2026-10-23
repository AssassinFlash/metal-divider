# metal-divider · 工作规则

这里没有硬规则。每一条都可以被证据推翻;推翻时写明是哪条证据,并改这份文件。本文件只写做事的依据,不写历史、不写流程仪式。

## 0. 本地存储纪律

- 本地备份、快照、实例和工作树必须有保留边界,不能无限增长。已确认过时、重复或已被接替的副本由执行者直接滚动淘汰,不逐项请示;每次新增成功后、任务结束前完成淘汰。
- 先复用,再新增;任务结束回收运行副本。新增隔离实例或工作树须记录实际用途与回收条件。
- 磁盘告警时暂停非必要的新副本与构建,优先释放空间。

## 1. 这是什么

metal-divider.com:Metal Divider 的全球 B2B 建筑金属屏风询盘站,WordPress + WooCommerce。图案(pattern)以"产品"承载,共 146 个;客户是建筑师、承包商、经销商。链路只有一条:图案浏览 → 图案清单 → 询盘/WhatsApp,之后全部线下。**无账户、无结账、无支付**;价格为展示态($0),不提供交易。

站点双域名:canonical 是 **metal-divider.com**,metalpartition.com 全站 301 并入。旧 Shopify 站在切换前内容冻结。

## 2. 业主裁定(事实,按日期)

- 2026-10-24:目的地与技术栈定版,见 wayfinder map。技术栈:WordPress + WooCommerce(目录)+ ACF Pro 6.3.1 + Polylang(版本由选型票定)+ LiveCanvas + WindPress + SEOPress Pro + yansir-md + Cloudflare(免费档)+ Turnstile + CookieYes + LiteSpeed/Redis + DeepSeek 翻译 API。明确不用:Elementor 等第二构建器、WP Rocket、Wordfence、备份插件、reCAPTCHA、Complianz。
- 分工不设默认规则:每张票由业主手动指派执行者,不预设"Claude 出规格、Codex 实现"之类的固定搭配。
- 订阅/付费预算上限 $20/月;免费方案优先。
- 上线标准:一上来就完美,再基于完美优化;**没有最小可用版路线**。
- 视觉复刻为先,允许升级:升级的目的是修复积压已久的 HTML/CSS/JS 编码问题;升级与复刻的边界由"内容改写边界"票钉死,逐页型记账。
- 多语言首发 9 种:EN(基座)、ZH、ES、PT-BR、DE、FR、AR、ID、VI;后续按询盘地理分布增减。加语言 = 配置 + 翻译,不锁死语言数。
- 图片走本地终稿管线:上传到服务器的文件必须是处理完成的(尺寸变体、格式、压缩),服务器不做运行时转换,也不装运行时图片优化插件。
- 度量(GA4、Clarity 等)不在本工程内;网站彻底做好后另行立项。
- 旧站的 8 张合规页(gdpr/lgpd/popia/pdpa/appi/apa-nzpa/canadian/us-laws-compliance)是 Shopify Consentmo 残留,不迁移;新站另做一张正经隐私政策。
- 博客重做:信息架构不继承旧站三分类,由研究票提案;内容工作流是业主写 Markdown → yansir-md 粘贴 → LiveCanvas 动态模板呈现。
- 询盘链不要过度复杂:轻量图案清单 + 表单 + WhatsApp(现站双号码 8613068660047 / 8613312892178 与 projects@metalpartition.com 平移,收件邮箱在询盘链票里定)。

业主只回答事实(市场、邮箱、号码、取舍偏好)。技术实现路径按证据与本文件决定;决定只因新证据改变,改变时写明证据。

## 3. 用词

见 `GLOSSARY.md`。说"图案"不说"款式/花型";说"询盘"不说"订单"。

## 4. 公开仓库纪律

本仓库是公开仓库。**密钥、令牌、凭据、客户个人信息、内部报价与商业敏感数据永不进仓库与 CI 日志**;密钥经环境变量与 SSH,不入文件。站上本来就公开的信息(邮箱、WhatsApp 号码)不受此限。

## 5. 交付

- 管线:本地 → 评审站 → 线上。线上不是任何功能第一次被看到的地方。
- 生产写入:先备份、后读回;改动与回滚方法记在票上。超出票计划的不可逆操作单独请示。
- 测试邮件纪律:任何测试场景,邮件只发一封、只发指定测试地址;多收件路由的正确性以队列收件人数据断言验证,不做多封实发。
- 客户可见文案(含事务邮件主题与正文)走对抗评审:初稿 → 评审意见 → 定稿留档。EN 先行,其余语种经翻译管线,不做人工逐句重写。
- 本轮发现的问题本轮修,不开 follow-up,不留 TODO。
- 旧 Shopify 主题代码只作"线上现状/旧行为"的中性证据,改述使用,不作为规则依据。

## 6. 模型路由

- 翻译管线:deepseek-flash,仅经官方 api.deepseek.com。
- 禁止临时起意用其他模型替代;任何模型改动 = 业主裁定。
- 失败必须显性暴露(重试 + 报错),禁止静默降级。

## 7. 研究与核实

外部事实只走一手来源(官方文档、实测数据);每个数字与阈值必须有来源注释。能看、能试、能查的先做再说;只问业主只有业主知道的事。代码、注释、文档里的数字都不是事实,追到业主裁定、一手证据或平台原生能力三者之一为止。

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues (`gh` CLI); the engineering skills read and write there. See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context: one `GLOSSARY.md` at the root, ADRs under `docs/adr/` (create when first needed).
