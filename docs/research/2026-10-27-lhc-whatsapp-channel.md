# WhatsApp 作为 LHC 人工客服通知与回复通道

- 票:[研究:WhatsApp 作为 LHC 人工客服通知与回复通道](https://github.com/AssassinFlash/metal-divider/issues/133)
- 父地图:[Wayfinder map:自建 WordPress AI 客服(Knocket 功能对标)](https://github.com/AssassinFlash/metal-divider/issues/86)(本文件不改地图)
- 日期:2026-10-27
- 分支:`research/lhc-whatsapp-channel`(自基线 `941b496` 拉出,不合并、不开 PR)
- 工作树:`.scratch/research-133-lhc-whatsapp`(本票唯一临时工作副本)
- 用途:本票研究文档写作与分支推送
- 负责人:本票研究会话(GitHub assignee AssassinFlash)
- 回收条件:分支推送成功且票关闭后删除该工作树;不删除分支,不删除本文件

只研究「LHC 转人工 → 业主在常用工具收到通知、打开完整聊天、回复网站访客、历史持续归 LHC」这一小步,业主已排除 Telegram,WhatsApp 第一、微信第二,其他小型开源连接器开放。不做 UI、不扩多渠道平台、不做 Woo 功能。研究期间未注册 Meta 应用、未发送任何消息、未改任何号码、未碰生产。

## 证据等级

沿用 #87/#132 的分级:实测(直接读到的公开页面、tag 级源码、下载解析的费率表)/ 官方宣称(官方文档句子,未实走)/ 合理推断 / 无法确认。

源码与文档基准(均为 2026-10-27 抓取/检出):

- Meta WhatsApp Business Platform 文档(新 URL 体系 `developers.facebook.com/documentation/business-messaging/whatsapp/`),含 `.md` 直出版
- USD 费率表(2026-10-01 生效,xlsx,自官方定价页下载解析)
- Live Helper Chat core:tag `4.97v`,commit `016f486`(与 #132 同一 commit),https://github.com/LiveHelperChat/livehelperchat
- LHC 官方 Meta 渠道扩展:repo `LiveHelperChat/fbmessenger`,master 最新 commit `f05f08c`(2026-07-13)
- WhatsApp Terms of Service(生效日期 2021-01-04)https://www.whatsapp.com/legal/terms-of-service
- GitHub API `licenseInfo`(2026-10-27 读回)

## 结论速览

1. **WhatsApp 官方通道唯一 = Meta Cloud API(按消息计费,不再有免费对话额度);直发模板 Direct Send 需 10 万级消息限额,小账户不可用,排除。** 2025-07-01 起按单条消息计费,只收商家→用户方向;2026-10-01 起服务消息(24h 窗口内商家回复)恢复收费,每号码每月 1,000 条免费额度。**「零新增支出」在 WhatsApp 官方通道上严格不成立**:哪怕只给业主自己发通知,每条冷启动模板也要 $0.0113–0.026/条(HK/CN 费率),且需要绑定支付方式。
2. **现有 +85264731687(WhatsApp Business App)与 Cloud API 的共存:直接注册=必须先删 App 账号(聊天历史丢失,App 内不能再用于该号);唯一官方双端并存路径是"经支持 Business App 号码接入的合作伙伴做 Embedded Signup"**(App 继续免费 1:1 收发、最近 6 个月消息双端镜像、双用号码限 20mps)。全部为官方文档实测级。
3. **LHC 官方 WhatsApp 集成存在且走的就是 Cloud API:`LiveHelperChat/fbmessenger`(Messenger/WhatsApp/Instagram 三合一),2026-07 仍在维护,但仓库无 LICENSE 文件(licenseInfo=null),与 Telegram 扩展同病,按仓库纪律不能现在采用。** LHC core 4.97v 源码中 "whatsapp" 零命中(PHP),core 本身没有 WhatsApp 能力。
4. **开源网关全部不合格或不必要**:Evolution API(Apache-2.0+附加条件,常驻 Node+Postgres+Redis)、WAHA(Apache-2.0,2026.6 起全功能免费,但引擎全是非官方协议+需 Docker)、Baileys(MIT,库)、whatsapp-web.js(Apache-2.0)——后三者协议层违反 WhatsApp ToS「禁止自动化/非授权接入」条款,封号风险,不作生产默认。且 VPS 无 Docker、共享主机无 Node,常驻依赖也不满足。
5. **推荐最小路径(只通知档,零自定义代码)**:LHC core webhook(转人工/新消息事件)→ 触发器 Rest API 动作(Bearer token + RAW JSON,core 原生支持,`lhgenericbotactionrestapi.php:1189-1212`)直调 Cloud API `POST /<PHONE_NUMBER_ID>/messages` 发 utility 模板,正文带 LHC 后台直达链接 → 业主在自己 WhatsApp 点链接 → LHC 后台行内回复访客 → 历史天然落 `lh_chat`/`lh_msg`。与 #132 已验证的 Telegram 纯通知配置同一条机制,共享主机可跑、零常驻进程。
6. **双向回复(在 WhatsApp 里直接回复访客)没有现成合格件**:fbmessenger 扩展解决的是"访客用 WhatsApp 找站"(入站渠道),不是"业主在自己 WhatsApp 里回网站访客";自建最小连接器范围已写明(第 7 节),本票不开发。

## 1. Meta Cloud API 官方事实(接入、费用、24h 窗口、限额)

来源:官方定价页、平台概览、消息限额页、Get Started(全部 2026-10-27 抓取,费率表 2026-10-01 生效版)。等级:实测(页面与表格原文),政策效果未实走。

### 1.1 计费模型(2026-10-01 生效版)

- 2025-07-01 起按**单条消息**计费;Meta 只收商家→用户方向,用户→商家永远免费。
- 消息五类:模板(营销 / 交易相关 utility / 身份验证 authentication,可随时发)+ 非模板(服务 service / Meta Business Agent,只能在 24h 客服窗口内发)。
- **2026-10-01 起,服务消息恢复收费**:每业务电话号码每月前 1,000 条服务消息免费(不结转、按月重置),第 1,001 条起按市场费率收;**未绑定支付方式的消息账户,免费额度用完后服务消息直接停发**(不欠费,但断流)。
- 免费场景仅剩:FEP 窗口(用户点 CTWA 广告进来的 72h–7 天会话,本站无 WhatsApp 广告,不适用)。此前"utility 模板在开放客服窗口内免费(2025-07-01 起)"的表述仍在文档中,但同一页"生效日期:2026-10-01"一节写明该场景 webhook 定价类型已从 `free_customer_service` 改为 `regular`——**是否仍免费文档自相矛盾,标记为无法确认**,实施票须实发一条并读 webhook `pricing.billable` 定论。
- 定价日历:Meta 只能在每季度第一天调价,最多每年 4 次;费率表按 47 个市场公布。

### 1.2 USD 费率表(2026-10-01 生效,xlsx 原文解析)

| 市场(收件人号码国别) | Marketing | Utility | Authentication | Service |
| --- | --- | --- | --- | --- |
| **Hong Kong(HK,852)** | $0.0732 | **$0.026** | $0.026 | $0.026 |
| **Rest of Asia Pacific(含中国 CN,86)** | $0.0842 | **$0.0113** | $0.0113 | $0.0113 |
| North America(US/CA) | $0.025 | $0.0034 | $0.0034 | $0.0034 |

费率由**收件人**号码国别决定。业主个人号是 +852 还是 +86,直接决定通知单价($0.026 vs $0.0113)——业主事实,实施票确认。

### 1.3 24 小时客服窗口(CSW)

- 用户向商家发消息(或来电)即开启 24h 计时,用户再次消息重置;窗口内商家可发非模板(服务)消息;窗口外只能发模板。
- 通知场景的含义:**给业主的每条"转人工了"通知,若业主 24h 内没给业务号发过消息,就是冷启动模板消息,按 utility 单价收费**;业主回复(用户→商家方向)永远免费,且回复本身开启 24h 窗口,窗口内后续服务消息走 1,000 条/月免费额度。

### 1.4 接入前提与限额

- 需要业务资产组合(Business Portfolio)+ Business 类型应用 + WhatsApp 账号(WAAC)+ 消息账户 + 业务电话号码 + system user 访问令牌。全部可由代理经浏览器代操(业主零点击前提兼容)。
- 消息限额(窗口外商家发起、24h 滚动去重用户数):新资产组合默认 **250/天**,企业验证只是扩容到 2,000 的路径之一(另两条:合作伙伴代验、30 天高质量 2,000 条送达)——**企业验证不是启动前置**,250/天对本站规模绰绰有余。
- 吞吐:每号码默认 80mps,双端并用号码固定 20mps——对通知场景无影响。
- 测试资源:Get Started 自动创建测试 WAAC+测试号,限额宽松、无需支付方式即可发模板,但**只能用于测试**,正式发信要加真实号码与支付方式。
- opt-in(2024-11 政策):商家发起消息前必须获得收件人同意,方式灵活(SMS/网站/电话/当面或纸质均可)——业主对自己号码属自我同意,留一行书面记录即可合规。

### 1.5 Direct Send(免模板直发)排除

官方 Direct Send 免预建模板、`category:"utility"` 直发,但**仅对消息限额已达 100,000 或无限额的资产组合开放**(premium,分批放量)。新 WABA 从 250 起步,本站量级永远够不到,排除。

## 2. 现有 +85264731687 号码与 Cloud API 共存(官方证据)

来源:《Migrate an existing WhatsApp number to a business account》与《Onboard WhatsApp Business app users》两页,均为实测级。

- **直接注册路径**:用现有 Business App 号码上 Cloud API,必须先在该 App 内"删除我的账号"(等 3 分钟释放号码),再作为新号码注册;**现有聊天历史全部丢失,且除非从 Cloud API 注销,该号不能再回到 Business App**。Messenger 个人号同样要先删号。
- **Embedded Signup 路径(双端并存)**:经支持"Business App 号码接入"的合作伙伴完成 Embedded Signup 后——
  - **可以同时用 WhatsApp Business App 和 API 发消息**;App 内 1:1 收发**继续免费**,API 方向按 Cloud API 计费;
  - 最近 6 个月消息在 App 与 API 间**镜像同步**;接入后可一次性同步历史(24h 内完成,否则需重来);
  - App 消息不受 24h 窗口约束,也不开启/延长 API 侧窗口;
  - 前提:App 版本 ≥2.24.17,验证码确认,可选择共享聊天记录;接入会解绑全部已链接设备(可重链)。
- **对本站的含义**:+85264731687 是 PDP/挂件上对客 WhatsApp CTA 的落点,当前跑在 Business App 上。删号迁移=对客入口变成无人值守 API 机器人+业主 App 历史丢失,**不可接受**。要走官方双端并存,须由"合作伙伴"(即实现了 Embedded Signup 的应用,例如 LHC fbmessenger 扩展正是这类实现)承接——见第 3 节许可证问题。
- **更省的绕行(推荐)**:通知通道**不占用对客号码**。给 Cloud API 单独注册一个第二号码(须能收 SMS/语音验证、未在 WhatsApp 注册过;业主若有闲置 HK/CN 号码即零成本,SIM 是否有闲置=业主事实),对客 +85264731687 原样留在 Business App。业务主从个人 WhatsApp 收到来自第二号码的通知,点链接回 LHC 后台处理。

## 3. LHC 现成件盘点

### 3.1 LHC core 4.97v(commit `016f486`)

- `grep -ril whatsapp lhc_web --include='*.php'` **零命中**(仅字体二进制与 CHANGELOG 提及);core 无任何 WhatsApp 能力,与 #132 结论一致(core 通知面=邮件/Web Push/webhook/移动 App)。
- **Rest API 动作原生支持本票需要的全部出站形态**(`lhc_web/lib/core/lhgenericbot/actionTypes/lhgenericbotactionrestapi.php`):
  - 自定义 HTTP header(任意 key:value,支持变量替换):`:1187-1193`;
  - **Bearer 授权**(`Authorization: Bearer <token>`):`:1204-1205`;
  - RAW JSON body + 占位符替换(`body_raw`,含 `{{lhc.*}}` 变量、chat variables 注入):`:1214-1229`、`:1249-1258`;
  - 超时/轮询/失败条件可配(`:1172-1173`、`:133-149`)。
  - 即 **LHC webhook 事件 → 触发器 Rest API 动作 → 任意带鉴权的 JSON POST**,与 #118 实测调 WP 知识端点、#132 提议直调 Telegram Bot API 是同一条机制。事件 webhooks 默认关闭(`settings/settings.ini.default.php:96`),开 `settings.ini` 即用(#132 已核)。

### 3.2 官方扩展 `LiveHelperChat/fbmessenger`(master `f05f08c`,2026-07-13)

- **这是 LHC 官方的 WhatsApp 集成**,官方文档明示"要集成 business WhatsApp 请用官方集成 fbmessenger(支持 Messenger/WhatsApp/Instagram)"。README 与实现确认走 **Cloud API**(OAuth scopes 含 `whatsapp_business_management`/`whatsapp_business_messaging`;后台有 "Register in Cloud-API" 按钮;webhook 载荷按 Cloud API 字段 `changes.0.value.*` 解析;2026-06 跟进了 BSUID 改版)。
- 功能:三渠道入站(访客用 WhatsApp 找站→进 LHC 收件箱)、送达状态、表情回应、bot 支持;要求 LHC ≥4.46、扩展 1.8v、`webhooks.enabled`;WhatsApp 须绑定到 Meta 商业资产组合。它实现的就是第 2 节的"合作伙伴 Embedded Signup"路径(README 的 "connected and subscribed" 流程)。
- **许可证:无 LICENSE 文件**(仓库根仅 `.github/.gitignore/README.md/bootstrap/classes/design/doc/modules/pos/providers/settings/translations`;GitHub API licenseInfo=null)。与官方 Telegram 扩展完全同病。按仓库纪律:**不采用**;正确路径是向上游(core 同作者、core 是 Apache-2.0)提 issue 要 Apache-2.0 LICENSE,拿到后再启用。维护度:2026-07-13 最新 commit,近一年有 BSUID/去重/Instagram 等实质更新,活跃度中等。
- **方向性澄清**:fbmessenger 解决的是"访客把 WhatsApp 当联系渠道"(入站);**不解决"业主在自己 WhatsApp 收到网站聊天通知并回复"**。后者的通知档用第 3.1 节机制即可,不需要本扩展。

### 3.3 官方教程中的第三方 WhatsApp 桥(对照,不推荐)

- `doc.livehelperchat.com/docs/integrating/whatsapp`(open-wa/wa-automate,Puppeteer 逆向 Web 协议)与 `.../chat-api-com`(第三方灰色中转服务):协议层均违反 WhatsApp ToS(见第 4 节)或引入第三方明文中转,教程自身也标"open-wa 方式有局限"。**灰色通道,不作为生产默认**,仅列为历史对照。

## 4. 开源网关逐项核查(Twilio 以外)

| 方案 | 许可证 | 维护 | 协议 | 常驻依赖 | 结论 |
| --- | --- | --- | --- | --- | --- |
| **Evolution API**(`EvolutionAPI/evolution-api`,release 2.3.7,2025-12-05;repo 最后推送 2026-07-14) | Apache-2.0 **+附加条款**:不得移除控制台 LOGO/版权;任何项目使用须在系统内向管理员展示使用声明,否则需商业授权 | 活跃度中等 | 双引擎:Baileys(非官方)+ **官方 Cloud API** | 常驻 Node + Postgres + Redis(Docker 部署) | 功能最全但过重:为发一条通知养一套三件套;VPS 无 Docker(#86 实测),共享主机无 Node。**不采用** |
| **WAHA**(`devlikeapro/waha`,2026-10-22 仍有推送) | Apache-2.0;**2026.6.1 起 Plus 并入 Core,全功能免费开源** | 活跃 | WEBJS/WPP(真浏览器 Puppeteer)、NOWEB/GOWS(WebSocket)——**全部非官方协议** | Node.js 应用,官方部署路径=Docker | 引擎层违反 ToS、需 Docker;**不采用** |
| **Baileys**(`WhiskeySockets/Baileys`,2026-10-25 推送) | MIT | 活跃 | 逆向 Signal 协议库(库,非网关) | 自行集成 | 违反 ToS(封号风险),且自己造网关超出本票范围;**不采用** |
| **whatsapp-web.js**(`pedroslopez/whatsapp-web.js`,2026-10-25 推送) | Apache-2.0 | 活跃 | Puppeteer 驱动 WhatsApp Web,非官方 | 常驻 Node + Chromium | 同上;**不采用** |

**ToS 一手依据**(WhatsApp Terms of Service,生效 2021-01-04,"Harm To WhatsApp Or Our Users"):不得"直接或间接、通过自动化或其他手段,以不被允许或未授权的方式访问、使用……我们的服务",并明文禁止逆向工程、以未授权/自动化手段创建账号、制作与 Service 功能实质相同的软件/API 提供给第三方。Baileys/whatsapp-web.js/WAHA 的全部引擎都在此列;Meta 对非官方客户端的历史处置是号码级封禁。**裁定:灰色协议路线不作为生产默认**,与 Telegram 扩展许可证问题同一纪律。

另:Evolution API/WAHA 这类网关的价值在"多渠道统一网关"(给 Callbot/CRM 用),本票目标(单点通知+链接回站)用不到它们的任何额外能力——按「先复用,再新增」,不引入。

## 5. 三档能力区分(只通知 / 能看历史 / 双向回复)

| 档位 | 业主体验 | 依赖 | 历史归属 | 判定 |
| --- | --- | --- | --- | --- |
| **只通知**(=Knocket "Notification only" 的 WhatsApp 版) | WhatsApp 收到模板消息:「新会话 #{id}:访客最后一句 + 后台直达链接(URL 按钮)」;点链接进 LHC 后台看全文、回复 | Cloud API + 第二号码 + utility 模板 ×1 + LHC webhook→RestAPI(**零代码,纯配置**) | 全在 `lh_chat`/`lh_msg`;WhatsApp 侧只有通知副本 | **推荐,最小可行** |
| **能看历史**(通知内直接读全量) | 不做:模板正文限 1024 字符,塞 transcript 既贵又违反"通知不搬运历史"原则;历史入口=链接直达 LHC(邮件档已带 150 条,WhatsApp 档带链接即可) | — | 同上 | 不作为独立档 |
| **双向回复**(在 WhatsApp 里回访客) | 业主直接回复通知消息,连接器把回复写回 LHC 会话,访客挂件收到 | Cloud API webhook 接收端(共享主机 PHP 端点可承接,META 回调需 hub.challenge 验签)+ 回复→`lh_msg` 映射 | 双写:LHC 主记录 + WhatsApp 线程副本 | 无现成合格件;见第 7 节最小范围,**本票不开发** |

## 6. 推荐最小实施路径(只通知档)

前置(全部代理可代操,业主零点击;唯一可能的业主输入见「阻塞」):

1. **第二号码**(非 +85264731687):能收 SMS/语音验证、未注册过 WhatsApp。业主有闲置 HK/CN SIM 即 $0。
2. Meta:建 Business 资产组合 + Business 应用 + WAAC + 消息账户,注册第二号码到 Cloud API,生成 system user 永久令牌,绑支付方式(见费用)。
3. 建 utility 模板(英文,如:`New chat #{{1}} on metal-divider.com. Last message: "{{2}}". Open: {{3}} as URL button`),过模板审核;opt-in 由业主对第二号码回复一次任意消息完成并留档。
4. LHC 评审站:开 `settings.ini` webhooks;建 `chat.genericbot_chat_command_transfer` / `chat.chat_started` / `chat.addmsguser` 事件触发(#132 已核事件名);触发器配 Rest API 动作:host=`https://graph.facebook.com/v23.0/<PHONE_NUMBER_ID>/messages`,Bearer 令牌,body_raw=模板 JSON,变量注入 chat id/最后消息/后台深链(`front/default/(cid)/<id>` 格式,#132 已核深链形态)。
5. 验收(评审站 HTTPS + 真 Chrome):访客转人工 → 业主个人 WhatsApp 收到通知 → 点 URL 按钮直达该聊天 → 行内回复 → 访客挂件收到 → 次日历史可查。每项一次即停。

**费用单列(不隐瞒)**:

- 通知单价 = utility 费率 ×(收件人=业主个人号国别):HK 号 $0.026/条,CN 号 $0.0113/条。50 次转人工/月 ≈ **$1.30(HK)/ $0.57(CN)/月**;营销类误判则 HK $0.0732/条。
- 绑卡:模板消息为付费消息,**消息账户必须有支付方式**才持续可发;不绑卡的后果是服务消息在 1,000 条免费额度用尽后停发、付费模板不可发。业主若无国际卡,这是硬阻塞(业主事实,需确认)。
- 第二号码 SIM 成本(若有闲置则为 $0)。
- 总量级:$20/月预算内绰绰有余,但**严格意义的零支出不存在**——这是与邮件(#132 推荐,真零成本)的核心权衡。

**账号/政策阻塞**:

- 模板审核是人工+自动混合,"给员工的通知"不是典型 utility 用例,存在被改判 marketing(贵 2.9–7.5 倍)或驳回的风险;文案按"客服会话提醒"撰写可降低风险,残余风险留给实施票实测。
- utility-in-CSW 是否仍免费(第 1.1 节矛盾)不影响本档结论(按付费做预算),只影响双向档预算。
- 业主个人号国别、闲置 SIM、国际卡三件均为业主事实,实施票一次性收集。

**与 #132 推荐的关系**:#132 的邮件+Web Push 是零成本默认;本档是"业主要 WhatsApp 提醒"时的加配,同一套 LHC webhook 事件可同时挂邮件与 WhatsApp 两个触发器,互不排斥。

## 7. 双向回复的最小连接器范围(明确,不开发)

若未来要"在 WhatsApp 里直接回访客",现成件不足(fbmessenger 许可证不合格且方向不同;Evolution/WAHA 过重或灰色),最小自建范围:

- **接收端**:站内一个受保护 PHP 端点(共享主机可跑,零常驻),承接 Meta webhook(GET 验 hub.challenge,POST 收消息),校验 `X-Hub-Signature-256`。
- **映射**:通知发出时记录 `wa_message_id → lh_chat_id`(一张小表);业主对该通知消息的回复按映射找到会话,经 LHC REST API 以坐席身份写入(`lh_msg`),再由 Cloud API 回发一条确认(服务消息,占用 1,000 条/月免费额度)。
- **边界**:单会话单挂起(同一时间只有一个待接管会话时映射最简);多会话并发需消息引用/前缀协议,复杂度陡增;业主用错线程回复需兜底提示。
- **主记录不变**:`lh_chat`/`lh_msg` 仍是唯一真相,WhatsApp 线程是投影副本;副本的第三方驻留风险见第 8 节。

## 8. 第三方通知副本风险(单列)

- WhatsApp 通知副本=访客消息摘要+链接经 Meta 基础设施传输与暂存(端到端加密仅覆盖传输会话,Meta 侧仍属第三方处理者);邮件档(#132)同理经 Hostinger/收件服务商。**LHC 主记录地位不因通知渠道改变**;通知文案只带「访客最后一句 + 会话号 + 链接」,不搬运全量 transcript,把副本暴露面压到最低——这条应写进实施票的文案纪律。
- 号码层:第二号码是站方资产,业主个人号仅收通知;业主换个人号只需改收件号码字段,不动链路。

## 9. 证据局限

- 未注册 Meta 应用实走全部流程(票面禁止):模板审核通过率、Embedded Signup 实际界面步骤、webhook 配置面板为官方宣称级;费用按费率表推算,未产生真实账单。
- utility-in-CSW 免费与否的文档矛盾(第 1.1 节)未定论,需实施票实发读 `pricing.billable`。
- fbmessenger 扩展未安装实测(许可证不合格,不值得试);其 "Register in Cloud-API" 双端并存能力为 README 宣称级。
- 业主个人号国别(+852/+86)、闲置 SIM、国际卡三项未确认,影响费用与阻塞判定。
- Meta 文档 URL 体系正在从 `/docs/whatsapp/...` 迁移到 `/documentation/business-messaging/whatsapp/...`,引用均用新体系;`.md` 直出与页面渲染版内容一致(本次对读无差异),但不排除后续再迁移。
- LHC RestAPI 动作的模板占位符对 Cloud API JSON 的兼容性未实发验证(机制与 #118/#132 已实测的同一代码路径,body 为 RAW 字符串替换,风险低)。

## 10. 来源

一手(2026-10-27 抓取/检出):

- Meta 官方文档(新体系,均含 `.md` 直出):
  - 定价(含 2026-10-01 变更、费率表下载、CSW/FEP、1,000 条免费额度):https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing/
  - USD 费率表 xlsx(2026-10-01 生效,定价页下载解析):HK 0.0732/0.026/0.026/0.026;Rest of Asia Pacific 0.0842/0.0113/0.0113/0.0113;North America 0.025/0.0034/0.0034/0.0034
  - 平台概览:https://developers.facebook.com/documentation/business-messaging/whatsapp/about-the-platform/(测试账户、吞吐、账户体系)
  - 消息限额:https://developers.facebook.com/documentation/business-messaging/whatsapp/messaging-limits/(250 默认、扩容路径、企业验证非前置)
  - 号码迁移:https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/migrate-existing-whatsapp-number-to-a-business-account/(删号前置、双端并存条件)
  - Business App 用户接入:https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users/(双端镜像、6 个月同步、20mps、App≥2.24.17、App 消息免费)
  - Get Started:https://developers.facebook.com/documentation/business-messaging/whatsapp/get-started/(测试号、system user 令牌)
  - Direct Send:https://developers.facebook.com/documentation/business-messaging/whatsapp/direct-send/(10 万限额门槛)
  - opt-in:https://developers.facebook.com/documentation/business-messaging/whatsapp/getting-opt-in/
- WhatsApp Terms of Service(2021-01-04 生效):https://www.whatsapp.com/legal/terms-of-service ("Harm To WhatsApp Or Our Users" 禁自动化/未授权接入条款)
- Live Helper Chat core tag 4.97v,commit 016f486:
  - `lhc_web/lib/core/lhgenericbot/actionTypes/lhgenericbotactionrestapi.php:1187-1193`(自定义 header)、`:1204-1205`(Bearer)、`:1214-1229,1249-1258`(RAW JSON body 与变量替换)、`:1172-1173`(超时)
  - `lhc_web/settings/settings.ini.default.php:96`(webhooks 默认关闭,复用 #132)
  - `grep -ril whatsapp lhc_web --include='*.php'` = 0 命中
- https://github.com/LiveHelperChat/fbmessenger(master `f05f08c`,2026-07-13;无 LICENSE 文件,`gh api repos/LiveHelperChat/fbmessenger` licenseInfo=null;README:Cloud API/BSUID/"Register in Cloud-API"/scopes/LHC≥4.46)
- 网关仓库元数据(gh api,2026-10-27):EvolutionAPI/evolution-api(license=NOASSERTION,LICENSE 文件=Apache-2.0+附加条款;release 2.3.7 2025-12-05;README:Baileys+Cloud API 双引擎)、devlikeapro/waha(Apache-2.0;https://waha.devlike.pro/docs/how-to/waha-plus/ 2026.6.1 起 Plus 并入 Core;https://waha.devlike.pro/docs/engines WEBJS/WPP=NOWEB/GOWS=非官方)、WhiskeySockets/Baileys(MIT)、pedroslopez/whatsapp-web.js(Apache-2.0)
- LHC 官方 WhatsApp 教程页(对照):https://doc.livehelperchat.com/docs/integrating/whatsapp(open-wa)、https://doc.livehelperchat.com/docs/integrating/chat-api-com

复用既有研究(不重复采证):

- #132 `docs/research/2026-10-27-lhc-knocket-handoff-parity.md`(分支 research/lhc-knocket-handoff-parity):LHC webhook 事件名、后台深链格式、webhooks 默认关闭、邮件/Web Push 通道、Telegram 扩展无 LICENSE 先例
- #87/#118(分支 research/knocket-capabilities 等):Knocket WhatsApp=外链、LHC 全链路接管已实测段落
