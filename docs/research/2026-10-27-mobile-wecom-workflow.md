# 研究：移动值班与企业微信通知后的处理闭环（#152）

- 日期：2026-10-27
- 父地图：#148（AI 客服与坐席工作台持续功能优化）。基线地图 #86。前置：#132、#134、#138、#147。
- 范围：通知已经进到个人微信之后，坐席分配、离开/关闭、交回 AI、新消息提醒、离线状态、企业微信双向操作，哪些能安全复用，从而少漏一单、少点一次。不推荐 Telegram，不新购服务，不新做 App。
- 不在本票：收件箱功能清单（#150）、LHC 原生能力总表（#149）、知识运营与交回 AI 的质量（#151）。本票只判断通知之后的处理闭环。
- 方法：只读。未改产品代码，未改生产，未发企业微信消息，未改 staging 在线开关。LHC 行为取官方 tag `4.97v` 源码（`autologin.php` 与 `lhgenericbotactioncommand.php` 的行号已与 GitHub raw 核对）和官方文档。企业微信取开发者中心当场打开的页面。当前连接器与工作台取已推送的 `impl/147-responsive-desk` @ `62ea911`（含 #138 连接器）。

## 0. 结论

现有闭环已经能做「转人工 → 个人微信 textcard → 点开 `mdmobile` 回复」。漏单不在缺一个 App，而在三处已经能指出来的缝：人只在微信里值班时，转人工可能根本不变成待处理；同一会话的第一张卡会把后面 7 天的提醒吃掉；超过 1 小时的旧链接点开是死页。值得做的只有补这三处，外加工作台上一个原生关闭。其余「功能更多」的选项不减少漏单，或在当前个人微信里根本显示不出来。

| 优先级 | 做什么 | 少漏单 / 少点击 | 做法 |
| --- | --- | --- | --- |
| P0 | 值班时让转人工变成待处理 | 少漏单 | 原生配置：坐席 `always_on`，或部门在线时段。先在 staging 读回现在是哪一种，再改 |
| P0 | 改通知规则 | 少漏单 | 现有连接器。不再按 chat_id 静音 7 天。离线分支不发、不记账。人离开后再发一张新的 textcard，链接重新签发 |
| P0 | 工作台关闭 | 少点击，并让提醒停下来 | 原生 `changestatus` → 已关闭。按钮放在已经打开的 `mdmobile` 上 |
| 不做 | 交回 AI 按钮、离开/改回待处理、坐席分配、模板卡片按钮、在微信里直接回复、官方 LHC App、坐席 Web Push、邮件第二收件箱、Telegram | 不成立，或增加一个要看的地方 | 见 §6 |

一句话：继续用个人微信收 textcard，继续在现有工作台里回复。先让「该响的时候响、不该响的时候不记账」，再给已经打开的页面一个关闭。不要为了少开一次浏览器去建第二条回复通道。

## 1. 现在实际发生的事

#138 的连接器只服务两个事件：`chat.genericbot_chat_command_transfer` 和 `chat.chat_transfered`。其他事件返回 ignored，不出站。发出去的是一张 textcard，`url` 是 `generateautologin`，路由 `mdmobile/chat/<id>`，`t` 为 3600 秒。同一 `chat_id` 记入 `md_wecom_notify_chats`，7 天内再来直接 `duplicate`，不再调上游。这是 #138 的设计，测试名就是 `testNotifiesAChatOnceAndOnlyOnce`：交给人之后，后续消息不再打扰。回调路由只实现了 GET 所有权验证，不接收消息。

#147 的工作台能列出有权限的会话、搜索筛选、看未读、进入会话、用原生 `addmsgadmin` 回复、轮询新消息。页面可见时每 3.5 秒拉一次；页面隐藏就停。没有关闭、离开、交回 AI、改在线状态。

所以业主今天的路径是：微信里点卡片 → 一次性登录进该会话 → 打字回复。这条路径对「第一张卡、一小时内、人还在」是够的。漏单和多余点击出在卡片之后。

## 2. 漏单出在哪

### 2.1 离线时，转人工事件不等于待处理会话

`stopchat` 先问 `erLhcoreClassChat::isOnline()`。在线的定义是：部门没被关掉，坐席 `hide_online = 0`，并且 `last_activity` 还在超时窗口内，**或者** `lh_userdep.always_on = 1`；都不满足时，再看部门在线时段。源码：tag `4.97v` `lhc_web/lib/core/lhchat/lhchat.php` 约 993–1055 行；判定点在 `lhgenericbotactioncommand.php:44-45`。

- 在线：会话改成 `STATUS_PENDING_CHAT`，然后派发 `chat.genericbot_chat_command_transfer`，`is_online = true`（同文件 68–132 行）。
- 不在线、且动作配了离线 payload：**不**改成待处理，派发同名事件且 `is_online = false`，接着跑离线 bot 触发器（同文件 50–66 行）。

连接器只看事件名，不看 `is_online`，也不看 `chat.status`。离线分支也会发一张卡，并且把这个 chat_id 记进 7 天静音。人稍后真正在线、再次转人工时，第二张卡被 `duplicate` 吃掉。这是「人只在微信里、后台活动超时」时的漏单，不是企业微信没送到。

官方手机 App 文档把同一件事说得更直：App 一关，后台同步停，LHC 无法维持在线；要保持在线得改成 Always online，或用部门时段，下班再把自己设离线（<https://doc.livehelperchat.com/docs/mobile>，Maintaining Online Status）。本站值班人不坐在 LHC 后台，活动超时就是常态。

`payload_ignore_status` 可以让这次 `stopchat` 跳过在线检查（`lhgenericbotactioncommand.php:45`）。那是把「无论有没有人」都转人工，和 Always online 是同一类取舍，不是新功能。

### 2.2 第一张卡之后的新消息没有第二次提醒

访客新消息的原生事件是 `chat.addmsguser`（`lhchateventdispatcher.php:34`）。官方坐席 Web Push 的发送条件只列了两件：新会话、分配或转接给你。没有「这条会话又来了一条访客消息」（<https://doc.livehelperchat.com/docs/users/operators-notifications>）。官方手机推送的 `new_msg` 要设备 token，而且坐席必须 `hide_online = 0`（`lhmobile.php` 的 `newMessage` / `chatStarted`）。两条原生通道都补不上「人已经离开微信卡片」之后的漏单。

当前连接器把这件事做成了相反的保证：7 天内同一会话不再发。工作台轮询只在页面可见时跑。页面一隐藏，访客再说话，微信不再响。

### 2.3 旧卡片点开是死页

`generateautologin` 的 `t` 过期后，管理站点入口直接 `die('Autologin hash has expired')`，不看浏览器里有没有已经登录的会话。tag `4.97v` `lhc_web/modules/lhuser/autologin.php:78-79`，已与 GitHub raw 核对。连接器把 `t` 设成 3600。超过一小时再点同一张卡，不是少点一次，是点进错误页。

textcard 没有更新接口。`response_code` 只发给按钮交互型、投票选择型、多项选择型，以及带了 `action_menu` 的文本通知型/图文展示型，72 小时、只能用一次（<https://developer.work.weixin.qq.com/document/path/90236>，2025-09-24 版，返回参数表）。跟进必须是一张新卡、一个新链接。

## 3. 能安全复用的原生能力

| 能力 | 原生证据 | 对这条闭环的判断 |
| --- | --- | --- |
| 转人工变成待处理 | `stopchat` 在线分支，见 §2.1 | 复用。靠配置让值班时段走这条分支，不新写分配 |
| 关闭会话 | 桌面状态单选「Closed」；`erLhcoreClassChatHelper::changeStatus()` 在 `STATUS_CLOSED_CHAT` 时写系统消息并收口（`lhchathelper.php:296-323`）；REST `setchatstatus` 走同一函数 | 复用。工作台加一个按钮即可。这是少进桌面后台的那一次点击 |
| 改回待处理 / 离开 | 同一函数把 `user_id` 清 0、状态改 pending（`lhchathelper.php:268-293`） | 有 API，单人值班不值得做。等于把自己的会话扔掉再提醒自己 |
| 改成 Bot | 桌面单选「Bot chat」只改状态并写「has changed chat status to bot」（`lhchathelper.php:331-352`；模板 `changestatus.tpl.php:13`） | **不是交回 AI**。不重启触发器。不能把这个单选搬到手机上冒充交回 |
| 真正交回 bot | 命令 `transfertobot` 把状态改成 bot，并且只有 payload 是数字时才 `processTrigger`（`lhgenericbotactioncommand.php:161-181`） | 有命令，没有现成的坐席一键。没有确认 staging 上那个触发器之前，不做按钮 |
| 在线 / 隐身 | REST `setonlinestatus` 改 `hide_online`；`isOnline()` 认 `always_on` 和部门时段 | 复用配置，不在微信里做在线开关。微信值班时不要用「后台有活动才算在线」 |
| 坐席 Web Push | 官方：要一台开着的 PC 浏览器，当时只测了 Chrome；只在新会话和转接时发；人离线默认不发，除非勾了离线也发（<https://doc.livehelperchat.com/docs/users/operators-notifications>） | 不作为手机值班通道。补不上后续消息，还多一个要看的地方 |
| 官方手机 App | App Store `id1530399116`，Play `com.livehelperchat.chat`。推送走 Firebase；默认向 `https://mobiletoken.livehelperchat.com/` 要 token，或自备 Firebase service account（`lhmobile.php:509-525`；<https://doc.livehelperchat.com/docs/mobile>） | 不采用。官方文档写明：点通知只打开 App，不打开那条会话。App 关闭后在线状态也不维持。这是第二个要装、要看的 App，比现有深链差 |
| 访客 Web Push | <https://doc.livehelperchat.com/docs/chat/web-push-notifications> 写明 iOS 不可用，且是通知访客，不是通知坐席 | 与本票无关 |
| webhook | `webhooks.enabled` 为真时，按事件名找未禁用的 webhook（`lhchatwebhookhttp.php:8`） | 复用。再绑一个 `chat.addmsguser`，指向同一个连接器。不要新进程 |

## 4. 企业微信官方能力，对照个人微信

当场打开的页面：发送应用消息 <https://developer.work.weixin.qq.com/document/path/90236>（最后更新 2025-09-24）；接收消息概述 <https://developer.work.weixin.qq.com/document/path/90238>（最后更新 2024-07-22）。

已经在用、应继续用的：

- textcard：`title` ≤128 字，`description` ≤512 字，`url` 必须带 http/https、≤2048 字节，`btntxt` 默认「详情」、不超过 4 个文字、超出截断。当前按钮文案 `Open chat` 超过 4 个文字。下次改这张卡时改成「打开」即可，不单独开一批。
- 频控：同一成员 30 次/分钟、1000 次/小时；每应用每天不超过账号上限数 × 200 人次；超出丢弃不下发。询盘站单人远低于上限。规则仍要合并同一会话的连发，避免以后把上限当成功能去打满。
- 若应用开了「在微工作台中始终进入主页」，微信端只能收文本，且截成 20 字节，其他类型变成「到企业微信查看」。#138 已能在个人微信收到卡片，说明这个开关当前没开。不要打开。

当前渠道用不上的：

- 模板卡片（文本通知、图文展示、按钮交互）官方写明「微工作台（原企业号）不支持展示模板卡片消息」。markdown、小程序通知同样不支持。业主收通知的地方是个人微信里的微信插件，不是企业微信 App。在卡片上加「关闭 / 交回」按钮，人在微信里看不见。要看见就得再打开企业微信 App，这与「不新做一个要盯的客户端」相反。
- 更新模板卡片因此也不成立：更新接口只服务于上面那些卡片。textcard 不能原地改成「已关闭」。
- 接收消息回调是官方双向通道：用户在应用里发的消息会推到企业后台，响应包可以被动回复。现有回调只做了 GET 验证。帮助页「允许成员在微信插件中接收和回复聊天消息」说的是插件里的**会话**，关掉后出现「无法继续进行聊天」（<https://open.work.weixin.qq.com/help2/pc/18122>）。它没有证明应用消息 textcard 下面有一个能打字、并且会进 90238 的回复框。未证实的回复框上不建连接器。

群机器人、微信客服、公众号、个人号协议，#134 已排除。本票不重开。

## 5. 成熟开源里值得抄的行为，不是值得装的产品

Chatwoot 官方用户指南把闭环说成三件事，不是一张功能表（<https://www.chatwoot.com/hc/user-guide/articles/1677231493-lesson-2-dashboard-basics>，<https://www.chatwoot.com/hc/user-guide/articles/1677141565-chatwoot-glossary>）：

- 推送在仪表盘没打开时也发；新消息、新会话都会推。静音是显式停掉某一条会话的提醒。
- Resolved 离开 Open；客户再说话可以重新打开。
- Snoozed 先放下，客户回复或到点再打开。

官方手机 App 的营销页把同一行为说成「客户一发消息就推送」（<https://www.chatwoot.com/mobile-apps>）。那是行为参照。不安装 Chatwoot App，不部署 Chatwoot，不把「有宏、有内部备注、有多人分配」算进本票。单人值班用不上分配；内部备注和快捷回复归 #150。

对应到现有栈：推送 = 现有 textcard，但触发条件要改成「新的待处理」加「人离开后的新访客消息」。Resolved = 原生关闭。Snoozed 不单独做；人没关、人离开，下一条访客消息再发一张卡，就是这个站点规模下的 snooze。Mute 不单独做；关闭就是停提醒。

## 6. 不值得做，以及为什么

| 选项 | 为什么不做 |
| --- | --- |
| 交回 AI 按钮 | 桌面「Bot chat」只改状态。访客不会因此重新得到 bot 回复，除非另跑 `transfertobot` 且 staging 上有那个数字 payload。假按钮比没有按钮糟。交回质量归 #151 |
| 离开 / 改回待处理 | 单人。关闭加「离开后再提醒」已经覆盖「我稍后回来」 |
| 坐席分配、多人路由 | 现在只有一个 `wecom_to_user`。原生部门分配留到真有第二个人。现在做是新连接层，没有少漏单 |
| 模板卡片按钮、更新卡片、在微信里点完成 | 微工作台不展示模板卡片。要企业微信 App 才看得见 |
| 企业微信双向回复 | 不减少漏单。回复处已经是点一次就到的工作台。回复框在个人微信里是否存在，帮助页没有证实。回调 POST、chat↔userid 映射是新连接层 |
| 官方 LHC App | 点通知不进会话；要装第二个 App；在线状态随 App 关闭失效；推送依赖 livehelperchat.com 的 token 或自备 Firebase |
| 坐席 Web Push、部门邮件当第二条值班通道 | Web Push 是 PC Chrome，且不覆盖后续消息。邮件能当发送失败的日志，不能当第二个必须看的收件箱。多一个收件箱就是多一次点击 |
| Telegram、新购推送服务、自建 App | 业主已排除 Telegram。其余违反本票边界 |

## 7. 缺失的连接层，只有这一层

不新做服务，不新做进程。共享主机上现有的 `md-wecom-notify` 加上 LHC 里再绑一个 webhook。

1. **配置，零代码。** staging 读回值班坐席的 `hide_online`、`always_on`、部门 `online_hours_active`。若现在是「看后台活动」，在值班时段改为 `always_on`，或把部门时段设成真正有人看微信的时段。不要为了让挂件永远显示在线而忽略这个读回：夜里若不应转人工，用时段，不用永远在线。二者取舍以 staging 读回的现状和业主已经在用的值班方式为准，不在本票猜。
2. **连接器规则，替换 7 天静音。**
   - 只在会话已经是待处理或进行中时发卡片。离线分支（事件到了，但状态仍是 bot）不发，也不写入静音记录。
   - 待处理：第一张卡立即发。若仍未接受，且又有访客消息，同一会话最多每 10 分钟再提醒一张。10 分钟不是平台限制，是避免连发；staging 若发现人在这 10 分钟里漏看，再缩短。
   - 进行中：访客消息距 `last_op_msg_time` 超过 3 分钟才再发一张。3 分钟内视为人还在这页上（页面可见时 3.5 秒一拉）。这 3 分钟是询盘站的取舍，不是官方数字：刚回复就锁手机，最多晚 3 分钟才响。若 staging 实测这段漏单不能接受，缩短；若人还在打字就响，加长。不要为这 3 分钟再做一套在线探测。
   - 每张跟进卡重新 `generateautologin`，`t` 仍为 3600。不要开企业微信的 `enable_duplicate_check`：跟进卡的链接不同，不需要它；相同 JSON 的 1800 秒去重会误伤提醒。
   - `chat.addmsguser` 在 LHC 再绑一条 webhook，指向同一个 `POST /wp-json/md/v1/wecom/notify`。连接器今天对这个事件名返回 ignored，要改的是这一处判断，不是新端点。
3. **工作台关闭。** `mdmobile` 增加一个关闭动作，走已经在用的坐席会话和 CSRF，调用原生改状态为已关闭。关闭后这条会话不再满足「待处理或进行中」，跟进卡停止。不要在 PHP 里重写关闭。

访客在关闭后再说话会不会重新变成待处理，本票没有实走。模型注释有「先前关闭、又变回待处理」（`STATUS_SUB_SUB_CLOSED`）。staging 必须先实走这一下，再决定关闭后的消息要不要发卡片。没实走之前，不要在文档或按钮文案里承诺「关闭后客户再说话会再响」。

## 8. staging 验证

只在 staging。不改生产。每个场景只向已经在收卡片的那一个微信账号发必要的一张卡，不连发，不多账号。通过即停，不为此再做全站扫描。

1. **值班不在后台。** 读回在线配置并按 §7.1 设好之后，关掉 LHC 后台，访客触发转人工。个人微信收到一张 textcard；会话在 LHC 里是待处理，不是停在 bot。点开进入 `mdmobile/chat/<id>`，回复出现在访客挂件。页面还开着、3 分钟内访客再说话，不再来第二张卡。
2. **人离开后再说话。** 关掉或隐藏该页，等待超过第 7 节的进行中窗口，访客再发一条。来一张新卡。新链接能打开。把第一张卡留过 3600 秒再点，应看到官方过期文案，而不是白屏或进错会话。这张过期卡不要当成缺陷去改 LHC；跟进卡必须是新链接。
3. **离线分支不占静音。** 暂时取消 `always_on` 且落在部门时段外，触发转人工。不发微信卡。再恢复值班配置，同一会话再次转人工，必须还能收到卡。这一步专门否掉今天的 7 天 `duplicate`。测完把配置设回第 1 步的读回结果。
4. **关闭。** 在工作台点关闭。列表不再把它当进行中。之后访客消息在「关闭是否重新打开」实走清楚之前，不要求响卡；只要求没有误发到别的会话。
5. **不做的事不要出现在验收里。** 不发模板卡片，不要求在微信输入框里回复，不安装 LHC App，不订阅坐席 Web Push，不发第二封邮件或第二张测试卡给别人。

## 9. 证据局限

- 未发企业微信消息，未改 staging 在线开关。textcard 能到个人微信，以 #138 已关闭的验收为准，本票未再实发。
- 微信插件里应用消息有没有回复框，18122 说的是会话，不能外推。因此双向回复保持「官方 API 存在、当前渠道未证实、不建」。
- 关闭后访客再说话是否重新打开，未实走。
- 3 分钟、10 分钟是本票给出的可调窗口，用来在漏单和连发之间取舍，不是企业微信或 LHC 的官方阈值。官方阈值只有频控：30 次/分钟、1000 次/小时。
- 研究期间本地 `4.97v` 源码树被并行任务回收。文中行号以核对过的 GitHub tag `4.97v` 为准：`autologin.php:78-79`、`lhgenericbotactioncommand.php:44-66` 与 `128-132`、`161-166`。其余 LHC 行号来自同一棵树在回收前的读数，文件路径相对 `lhc_web/`。
