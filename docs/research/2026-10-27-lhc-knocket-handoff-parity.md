# Knocket 真人通知与完整对话机制在 LHC 的最小复刻

- 票:[研究:Knocket 真人通知与完整对话机制在 LHC 的最小复刻](https://github.com/AssassinFlash/metal-divider/issues/132)
- 父地图:[Wayfinder map:自建 WordPress AI 客服(Knocket 功能对标)](https://github.com/AssassinFlash/metal-divider/issues/86)(本文件不改地图)
- 日期:2026-10-27
- 分支:`research/lhc-knocket-handoff-parity`(自基线 `941b496` 拉出,不合并、不开 PR)
- 工作树:`.scratch/research-132-handoff`(本票唯一临时工作副本)
- 用途:本票研究文档写作与分支推送
- 负责人:本票研究会话(GitHub assignee AssassinFlash)
- 回收条件:分支推送成功且票关闭后删除该工作树;不删除分支,不删除本文件

只研究「访客转人工 → 真人收到实际通知 → 打开完整聊天 → 回复回访客 → 历史持续可查」这一小步。不做 UI、不扩多渠道、不改生产、不改当前 MVP。研究期间未创建 Telegram bot、未发送任何消息、未改任何环境。

## 证据等级

沿用 #87 的分级:

| 等级 | 含义 |
| --- | --- |
| 实测 | 本次或 #87/#118 会话直接读到的公开页面、源码(tag 级 commit)、HTTP 头 |
| 官方宣称 | 官方教程/文档/README 的句子,未实走 |
| 合理推断 | 由实测材料推出,无内部实现证据 |
| 无法确认 | 外部材料没写,或材料互相矛盾 |

源码证据基准:

- Live Helper Chat core:tag `4.97v`,commit `016f486`(2026-10-22),https://github.com/LiveHelperChat/livehelperchat — 下文 file:line 均指 `lhc_web/` 内路径在该 commit 的行号。
- 官方 Telegram 扩展:repo `LiveHelperChat/telegram`,默认分支 master,最新 commit `31a2560`(2026-10-26,merge PR #104)。
- Knocket:官方教程页 2026-10-27 全文抓取(62,288 字节 HTML);控制台截图结论复用 #87(2026-10-26)。

## 结论速览

1. **Knocket 的六项机制全部是「Telegram + 自有控制台」组合,不用 WhatsApp 做通道**。WhatsApp/Instagram/Messenger 只是挂件上的外链图标;真正进收件箱的只有网站/联系页/移动挂件、Telegram bot 和 Email。通知双通道 = 控制台浏览器推送 + Telegram(Full=正文+引用回复 / Notification-only=只有提醒)。全部为官方宣称级,未见文档的是历史保留期限与去重规则。
2. **当前 LHC 4.97v 实例"只改状态没通知"不是缺陷,是三处配置全默认关闭**:部门邮件通知(`inform_options`)未开、operator Web Push(`notifications_settings_op`)未配置未订阅、事件 webhooks(`settings.ini 'webhooks'.enabled`)默认 false;bot 转人工时实例上也无人在线(`botTransfer` 只在 `is_online=true` 时推送)。#118 验收走的正是这条"无人在线"路径,所以只有状态变化。
3. **LHC core 已经内置业主要的整条闭环,且零常驻进程**:事件 `chat.genericbot_chat_command_transfer` → `erLhcoreClassLHCMobile::botTransfer` → operator VAPID Web Push(自带 `minishlink/web-push`,payload 含最近 4 条消息预览 + 直达该聊天的后台 URL);部门选项 `mail` → 新聊天/未读消息邮件(最近 150 条消息 + accept 链接);全部在触发访客消息的同一个 HTTP 请求里同步完成,共享主机可跑。
4. **官方 Telegram 扩展(`LiveHelperChat/telegram`)功能上是 Knocket Full 模式的完整等价物**(站长在 Telegram 群按客户分 topic 收消息、直接在 Telegram 回复、回复送达访客挂件),活跃维护(2026-10-26 仍有安全修复),**但仓库无 LICENSE 文件,GitHub licenseInfo=NO-LICENSE,许可证不合格,不推荐采用**。
5. **最省替代(推荐的最小下一步)**:纯配置三件套——①部门邮件通知(部门邮箱=内部通知地址)②operator Web Push(VAPID 密钥对 + 业主真 Chrome 订阅一次)③可选:core webhook→Rest API 直调 `api.telegram.org sendMessage` 做 Telegram 纯通知(零自定义代码、零许可证风险)。真人回复统一在 LHC 后台完成,历史天然落 `lh_chat`/`lh_msg` 持续可查。不需要写任何胶水代码。

## 1. Knocket 六项机制逐项核实

来源:[Get Started with Knocket — The Complete Guide](https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/)(2026-10-27 全文抓取);控制台截图结论引自 #87 研究文档。分级均为**官方宣称**(教程文字),内部去重/投递实现**不可见**。

| 机制 | 实际工作方式(官方教程原文要点) | 证据等级 | 不可见部分 |
| --- | --- | --- | --- |
| 转人工 | 两层:①短语库——47 种语言"转人工"类短语的确定性词典,命中即转、不调模型,可自加短语;②模型意图——`transfer_to_human` 内置工具,由模型判断情绪低落/重复失败/超出知识范围时调用。低置信线程带着 AI 草稿置顶进 Human 队列 | 官方宣称(短语库页);#87 已实测挂件内置 `waiting_human`/`human_handling`/`handoff_offline` 文案 | 短语库完整清单、置信度阈值、置顶排序规则 |
| Telegram 通知 | Notifications 模块双通道并行:控制台**浏览器推送**(标签页后台也能响)+ **Telegram bot**(Integrations 里贴 BotFather token 接入)。Telegram 再分两档:**Full**=直接看到访客消息正文,可引用回复/转发/点进收件箱;**Notification only**=只提醒有新消息,不显示内容,回复必须回控制台 | 官方宣称 | 推送延迟、去重 |
| 引用回复 | Full 档下对 bot 消息 quote-reply,回复"落回访客原来所在渠道的屏幕"(widget/联系页/移动挂件);"Notifications, reads, and AI drafts all sync — a message answered from Telegram closes the thread in the console too" | 官方宣称 | 与控制台状态同步的实现 |
| 统一收件箱 | 三入口(网站挂件、`knocket.ai/<slug>` 联系页、App WebView)+ Telegram + Email 全部汇成同一线程;可按 All / AI Resolved / Human 过滤;详情面板有 User ID、渠道、状态、首次响应、处理时长、AI 两句摘要 | 官方宣称 + #87 截图实测(侧栏 Inbox/AI Agent 等) | 多操作员/角色(#87 证实教程无此内容) |
| 完整历史 | 线程留在收件箱持续可查;营销博客称"无历史锁定";#87 已核实**界面与条款均无导出功能**,条款写明站长自负备份责任、终止后腾讯无义务交还 User Data | 官方宣称 + #87 条款实测 | 历史保留期限、区域(#87 已列为无法确认) |
| 离线留言 | 访客气表单(姓名/邮箱/电话,可标必填)收集时机 Off / Before conversation / When Offline;离线留言进**同一线程** | #87 截图实测 | 无 |
| Resume AI | 任意线程详情面板按 Resume AI,把对话交回 AI 继续答(对应 LHC 已实现的"交回 bot") | 官方宣称 | 无 |

**是否 WhatsApp:不是。** 教程原话:挂件可加 WhatsApp、Instagram、Telegram、Messenger 四个图标,"The widget renders those links as **alternative contact channels**"——即点击后跳出站外,消息不进收件箱(#87 已核实)。进收件箱的通道只有:网站挂件/联系页/移动挂件、**Telegram bot**、**Email**(Knocket 别名或自有域 MX)。Knocket 通知与回复通道用的是 **Telegram**,不是 WhatsApp。

## 2. 当前 LHC 4.97v 为什么"只改状态、没通知真人"

#118 验收路径:bot 输出 `action=transfer_operator` → 触发器把会话置 Pending → agent118 手动在 dashboard 接管。整个链路没有任何一处会主动把消息推到不看后台的人。对照 core 源码,缺口是四条,全部是配置/状态问题,不是代码缺陷:

1. **部门邮件通知未配置。** 邮件通知由部门编辑页的 `inform_options[]` 复选框控制(`design/defaulttheme/tpl/lhdepartment/form.tpl.php:356-378`,含 `mail`、`mail_accepted`、`xmp` 等)。触发点:访客发消息且会话仍 Pending 时,`modules/lhchat/checkchatstatus.php:52-61` 检查 `nc_cb_executed == 0 && status == PENDING_CHAT`,并要求会话已存在超过 `$department->inform_delay` 秒才调 `newChatInformWorkflow`;后续未读消息走 `modules/lhchat/syncuser.php:38` → `unreadInformWorkflow`。收件人取部门 `email` 字段,其次模板默认收件人,再次库里的第一个操作员(`lib/core/lhchat/lhchatmail.php:472-482`)。#118 报告从未提及设置部门邮箱或 `inform_options`;本地 `php -S` 实例也无 SMTP。**默认状态下一封都不会发。**
2. **operator Web Push 未配置、未订阅。** 推送开关在 `notifications_settings_op`(模块配置,`modules/lhnotifications/opsettings.php:5`,字段 `enabled/public_key/private_key/http_host/icon/...`),默认关闭;需要管理员贴 VAPID 密钥对、坐席在浏览器里订阅一次(`modules/lhnotifications/subscribeop.php:19-48` 存 `OperatorSubscriber`)。本地实例是 HTTP,service worker 根本无法注册(`design/defaulttheme/js/lhc.notifications.js:6` 要求 `'serviceWorker' in navigator`,浏览器只在安全上下文放行)。
3. **事件 webhooks 默认关闭。** `settings/settings.ini.default.php:96-100` `'webhooks' => array('enabled' => false, ...)`;`lib/core/lhchat/lhchateventdispatcher.php:49-66` 里 `enabled=false` 直接 return,`lh_webhook` 表里的事件→触发器映射(通知类扩展的标准挂点)全部不跑。
4. **转人工时无人"在线"。** bot 转人工的事件链是 `lib/core/lhgenericbot/actionTypes/lhgenericbotactioncommand.php:52/128/168` dispatch `chat.genericbot_chat_command_transfer`,`lib/core/lhmobile/lhmobile.php:276-280 botTransfer` 只有在 `$params['is_online'] == true` 时才继续推(`lhgenericbotactioncommand.php:128` 一处传 true,`:52` 一处传 false)。#118 验收时业主/坐席并未以 Visible 状态在线,正好落在不推送的分支。另 `chatStarted`(`lhmobile.php:288`)只对 `status == STATUS_PENDING_CHAT` 的会话推送,且推送对象筛选要求坐席未隐藏在线(`lhmobile.php:363-378`:`hide_online == 0` 或 `sn_off==1`)。

浏览器标签页内通知(每坐席"Show browser notification for new messages"开关,`design/defaulttheme/tpl/lhchat/syncamdsoundesetting.tpl.php:38`)是第五条路,但要求后台标签页开着,解决不了"人不在电脑前"。

**一句话:四个开关全关 + 无人在线,LHC 按设计静默;把配置补上,闭环已经在 core 里。**

## 3. 现成集成盘点(许可证 / 维护 / 共享主机 / 常驻依赖 / 回复与历史)

### 3.1 LHC core 自带(Apache-2.0,仓库根 LICENSE;tag 4.97v `016f486`)

| 能力 | 触发 | 通知内容 | 回复路径 | 历史 | 常驻依赖 | 共享主机 |
| --- | --- | --- | --- | --- | --- | --- |
| **部门邮件通知** | Pending 会话新聊天/未读消息(上节触发点);离线表单 `informFormFilled`(`lhchatmail.php:591`);会话关闭可选抄送(`informChatClosed`,`lhchatmail.php:724`,受部门 `inform_close*` 字段控制) | 模板 4/7/9(新聊天/未读/已接管),含**最近 150 条消息全文**与 `{url_accept}` 一键接管链接(`lhchatmail.php:440-511`) | 邮件是单向通知;点链接进后台回复 | 全在 `lh_chat`/`lh_msg` | 无;SMTP 出站即发 | 适配(Hostinger SMTP/de-linker mail() 模式) |
| **operator Web Push(VAPID)** | 新会话/转人工/bot 转人工(`lhchateventdispatcher.php:32-37` 五个事件;`lhmobile.php:363+` web-push 分支) | 标题"New chat #id [部门]",正文**最近 4 条消息**预览,`data.url` = 直达该聊天的后台深链 `front/default/(cid)/<id>/#!#chat-id-<id>`(`lib/core/lhnotifications/lhnotifications.php:112-165`) | 点通知打开后台完整聊天,行内回复 | 同上 | 无;库 `minishlink/web-push` ^7.0(MIT,core `composer.json:115`)随请求同步发送;装了 php-resque 才改走队列(可选) | 适配(出站 HTTPS 到 Mozilla/Google 推送端点);**要求站点 HTTPS + 坐席浏览器订阅一次** |
| **浏览器标签页通知** | 后台标签页开着的坐席 | 新聊天/新消息桌面通知(不开标签页则无) | 后台回复 | 同上 | 无 | 适配 |
| **事件 webhooks(事件→bot 触发器)** | 任意 chat 事件(`lhchatwebhookhttp.php:8-46`,worker=http) | 触发器里可用 Rest API 动作对任意 URL 发**模板化 JSON POST**(#118 已实测此机制调 WP 端点) | 取决于触发器目标 | 同上 | 无(worker=http 同步) | 适配 |

### 3.2 官方 Telegram 扩展 `LiveHelperChat/telegram`(master `31a2560`,2026-10-26)

- **功能**:两个方向。①入站渠道:Telegram 用户找 bot 聊天 → 进 LHC 收件箱,坐席回复送达 Telegram。②**「Telegram as a Support Client」(= Knocket Full 模式等价物)**:站长建 Telegram 群开 topics,bot 拉进群当 admin;`providers/TelegramLiveHelperChatOperator.php:17-25` 监听 `chat.chat_started`/`chat.addmsguser`/`chat.messages_added_passive`,**网站访客的每条消息实时转投到群里该客户的 topic**;站长在 topic 里直接打字,`classes/Commands/GenericmessageCommand.php` 等命令把回复写回 LHC 会话 → 访客挂件收到。README 明示文件也支持、同访客续聊进同一 topic、可自动删 topic、在线条件可设 Always online。
- **维护**:活跃。2026-10-24/26 刚合入 webhook secret token 校验(PR #101)与 operator topic HTML 转义(PR #104)两个安全修复。
- **许可证**:**无 LICENSE 文件**(仓库根只有 `.gitignore/README.md/bootstrap/classes/design/doc/modules/pos/providers/settings`;GitHub API `licenseInfo` = NO-LICENSE)。core 是 Apache-2.0,但扩展未随附任何授权 → 按仓库纪律视为不合格。这是与 #110 结论一致的既有灰区,本次复核仍未消除。
- **常驻依赖**:无。入站是 Telegram → LHC webhook(`modules/lhtelegram/callback.php:14-17` 验 secret token 后由 `longman/php-telegram-bot`(MIT)处理);出站是 PHP 直调 Bot API。需 `composer install` 一次并开 `settings.ini` 的 `webhooks.enabled`。共享 PHP 主机可跑。
- **结论**:**不推荐现在采用**——许可证不合格;功能缺口为零但法律灰区非必要不背。

### 3.3 其他开源连接器

| 方案 | 核查结果 | 结论 |
| --- | --- | --- |
| ntfy / Gotify 自托管接收端 | 均需常驻服务进程 + 独立端口,共享 PHP 主机跑不了;ntfy.sh 公有云又引入外部依赖 | 不采用 |
| FreeScout / UVdesk | #110 已排除(实时聊天付费/无挂件/OSL-3.0) | 已裁定 |
| 自写 mu-plugin 直调 Bot API | #110 提议过;本次发现 core webhook→Rest API 已能直调 `https://api.telegram.org/bot<token>/sendMessage`,body 是模板化 RAW JSON(#118 实测同机制),**连 mu-plugin 都不用写** | 被更省方案覆盖 |
| LHC 移动 App 推送 | core 自带(`lhmobile.php` FCM 路径,MIT+免费 APK,#110 已核);App 装机对业主是额外一步 | 备选,不作最小闭环必选 |

## 4. 推荐最小下一步(唯一目标:一个最小闭环)

**纯配置三件套,零自定义代码,先在评审站(staging,HTTPS)落地:**

1. **部门邮件通知**(主通道,兼容"人不在手机旁"):部门 `email` = 内部通知地址(生产=sales@sunriverinverter.com 主/info@ 备,按 AGENTS.md;测试只发单一指定测试地址);勾选 `inform_options: mail + mail_accepted`;`inform_delay` 设小值(如 30-60 秒);SMTP 走 Hostinger 出站。配置点:`lhdepartment/form.tpl.php` 表单(后台 System → Departments)。
2. **operator Web Push**(实时通道):`notifications_settings_op` enabled + VAPID 密钥对(现场生成,私钥只进 LHC 配置)+ `http_host`;业主在自己真 Chrome 登录评审站后台订阅一次。此后 bot 转人工 → 业主手机/电脑 Chrome 弹通知(带消息预览),点击直达该聊天。
3. **(可选,业主想要手机 Telegram 提醒时)Telegram 纯通知**:core webhook 建 `chat.chat_started`/`chat.addmsguser` → 触发器 Rest API POST `api.telegram.org/bot<token>/sendMessage`,body `{"chat_id":"<业主TG_ID>","text":"..."}` 模板里拼消息与后台链接。等价 Knocket "Notification only" 档;回复仍回 LHC 后台。需要业主先经 BotFather 拿 token(本票未创建,留待实施票业主提供或授权代操)。

**回复与历史**:真人在 LHC 后台(通知里的链接直达)行内回复 → 访客挂件收到(#118 已实测此段);全部消息落 `lh_chat`/`lh_msg`,后台列表与归档持续可查——这两段 #118 已验收,无需重做。

**现成 vs 配置 vs 胶水:**

| 环节 | 状态 |
| --- | --- |
| 访客转人工(bot 判定+触发器) | 现成,#118 已实测 |
| 真人收到通知(邮件/Web Push/Telegram) | **全部现成,纯配置** |
| 通知带完整聊天入口 | 现成(邮件 accept 链接 / 推送深链 / Telegram 通知文案里拼链接) |
| 打开完整聊天 + 回复访客 | 现成,#118 已实测 |
| 历史持续可查 | 现成(核心数据模型) |
| 自定义胶水代码 | **零** |

**验收方式(给实施票,本票未动环境):** 评审站 HTTPS + 真 Chrome(浏览器铁律)。①访客挂件问知识外问题 → bot 转人工 → 会话 Pending;②业主 Chrome 收到 Web Push(含预览),点击直达该聊天;③指定测试地址收到一封通知邮件(全文 transcript + accept 链接)——按测试邮件纪律只发一封、只发测试地址;③(若配了 Telegram)业主 Telegram 收到一条提醒;④业主后台回复 → 访客挂件收到;⑤次日重登后台,该会话历史完整可查。每项通知验证一次即停,不做多渠道矩阵。

## 5. Telegram 扩展许可证不合格时的最省替代

即第 4 节方案 3:**core webhook → Rest API 直调 Bot API sendMessage**,纯配置、零代码、零许可证风险(Apache-2.0 core + Telegram 公开 HTTP API)。代价:只有通知没有"在 Telegram 里回复"(等价 Knocket Notification-only 档)。若未来想要 Knocket Full 档(引用回复直达访客),正确路径是**先向 `LiveHelperChat/telegram` 上游提 issue 请求补 Apache-2.0 LICENSE**(core 同款,作者同一人,大概率愿意),拿到明确授权后再启用——而不是现在自写一套 Telegram 双向网关。

## 6. 证据局限

- Knocket 全部机制均为官方教程/截图级,**未注册账号实走**;历史保留期限、Telegram 投递去重规则、多操作员角色外部不可见(#87 已列)。
- lhc118 本地实例在本次研究开始时仍在,中途被并行会话回收,未能现场读取其 `inform_options`/`notifications_settings_op` 实际值;"当前缺口"按 LHC 4.97v 默认值 + #118 验收报告(全程未提及任何通知配置)推断,等级=合理推断。
- core webhook→Rest API 直调 sendMessage 的 body 模板占位符(`{{msg}}`、chat URL 拼接)未实际发请求验证(票面禁止),机制本身与 #118 实测的 WP 端点调用同源,风险低。
- Web Push 端到端效果(Chrome 弹出、点击深链打开)依赖 HTTPS 环境与真实订阅,留待实施票在评审站验收。
- LHC 邮件模板 4/7/9 的默认文案未逐字审阅,上线前需按"客户可见文案对抗评审"纪律定稿。

## 7. 来源

一手(2026-10-27 抓取/检出):

- https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/ (Integrations / Notifications / AI Agent / Telegram reply flow 各节全文)
- Live Helper Chat core tag 4.97v,commit 016f486:
  - `lhc_web/lib/core/lhchat/lhchateventdispatcher.php:32-37,49-66`
  - `lhc_web/lib/core/lhmobile/lhmobile.php:276-300,363-410`
  - `lhc_web/lib/core/lhnotifications/lhnotifications.php:112-165`
  - `lhc_web/lib/core/lhchat/lhchatmail.php:440-482,591,649,724`
  - `lhc_web/lib/core/lhchat/lhchatworkflow.php:159-225`
  - `lhc_web/lib/core/lhchat/lhchatwebhookhttp.php:8-46`
  - `lhc_web/modules/lhchat/checkchatstatus.php:52-61`、`modules/lhchat/syncuser.php:38`
  - `lhc_web/design/defaulttheme/tpl/lhdepartment/form.tpl.php:356-378`
  - `lhc_web/design/defaulttheme/tpl/lhchat/syncamdsoundsettings.tpl.php:38`
  - `lhc_web/design/defaulttheme/js/lhc.notifications.js:6,34-37`
  - `lhc_web/modules/lhnotifications/opsettings.php:5`、`subscribeop.php:19-48`
  - `lhc_web/settings/settings.ini.default.php:96-100`、`composer.json:115`
  - `lhc_web/lib/core/lhgenericbot/actionTypes/lhgenericbotactioncommand.php:52,128,168`
- https://github.com/LiveHelperChat/telegram (README;master 31a2560;无 LICENSE 文件,`gh api repos/LiveHelperChat/telegram` licenseInfo=NO-LICENSE;providers/TelegramLiveHelperChatOperator.php:17-25;modules/lhtelegram/callback.php:14-17)

复用既有研究(不重复采证):

- #87 `docs/research/2026-10-26-knocket-capabilities.md`(分支 research/knocket-capabilities):Knocket 截图实测、条款/隐私/导出边界、WhatsApp=外链结论
- #110 `docs/research/2026-10-26-shared-host-live-chat.md`(分支 research/shared-host-live-chat):LHC 共享主机适配、Apache-2.0、扩展仓库无 LICENSE、FreeScout/UVdesk 排除
- #118 验收记录(issue comments):本地 lhc118 实例全链路已验段落、SSRF 白名单、测试邮件纪律
