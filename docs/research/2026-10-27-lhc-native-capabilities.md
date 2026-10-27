# LHC 4.97 原生可启用能力与响应式工作台边界

- 票:[研究:Live Helper Chat 原生可启用能力与工作台扩展边界](https://github.com/AssassinFlash/metal-divider/issues/149)
- 父地图:[Wayfinder map:自建 WordPress AI 客服(Knocket 功能对标)](https://github.com/AssassinFlash/metal-divider/issues/86)(本文件不改地图)
- 日期:2026-10-27
- 分支:`research/lhc-native-capabilities`(自 `origin/main` `b272a22` 拉出,不合并、不开 PR)
- 工作树:`.scratch/research-149-lhc-native`(本票唯一临时工作副本)
- 用途:本票研究文档写作与分支推送
- 负责人:本票研究会话(GitHub assignee AssassinFlash)
- 回收条件:分支推送成功且票关闭后删除该工作树;不删除分支,不删除本文件。官方包解压目录 `.scratch/lhc-4.97-src` 可由原 tar 重建,任务结束删除。

只回答:会话分配/接管、在线与未读、快捷回复、内部备注、访客资料、历史搜索、输入状态、文件、AI 接管/交回、移动与通知,哪些原生已有且只需配置或直接复用,哪些只有桌面模板或没有适合响应式工作台的 API。功能多不是收益。研究期间未改 staging、未改生产、未发消息、未新建会话。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 本次直接读到的官方包文件,或 staging 只读查询 |
| 官方宣称 | 官方文档句子,未在本实例实走 |
| 合理推断 | 由实测材料推出,没有再跑一遍变更 |

源码基准:本地官方包 `doc/CHANGELOG.txt` 首行 `4.97v`,路径 `/Users/chesterjohn/Documents/metal-divider/.scratch/impl-138-wecom/.scratch/lhc-4.97v.tar.gz`。下文 `file:line` 指该包内路径。官方文档以 2026-10-27 抓取的 [doc.livehelperchat.com](https://doc.livehelperchat.com/docs/chat/chat) 为准;文档与函数不一致时,以函数为准。

staging 只读范围:`hostinger-metal-divider` 上 `domains/staging.metal-divider.com/public_html/chat`。读回版本 `4.97v`,已启用扩展只有 `mdmobile`。不打印密钥、邮箱、数据库名、导出哈希或 webhook 地址。

当前工作台是 `impl/147-responsive-desk` 的 `lhc-extensions/mdmobile`。它已经用 `lh_chat` / `lh_msg`、部门限制和 `hasAccessToRead` 做列表,用原生 `chat/addmsgadmin` 发消息,并显示访客字段与同访客最近会话。本票不改这份代码。

## 结论

**不要为 LHC 已有能力再写一套工作台。** 单坐席值班现在缺的不是分配算法、搜索面板或官方手机 App。回复、未读、访客资料和同访客历史已经接在原生数据上。

按收益只留三件事,其余不做:

1. **不要加「交回 AI」按钮。** `!gotobot` 和状态改成 bot 都只改 `status`,不设置 `gbot_id`,不跑触发器。staging 两个会话 `gbot_id=0`,`lh_msg` 没有 `user_id=-2`。现在按这个按钮,访客会留在没人答的会话里。
2. **关闭是唯一值得以后加的薄按钮,而且必须调 `chat/changestatus`,不能调 `!close`。** 从网页发出的 `!close` 只往 `operation_admin` 写桌面脚本,工作台不执行。当前坐席在 Administrators,有 `canchangechatstatus`。Operators 角色默认没有这个权限。
3. **文件先决定要不要收,再渲染已有标记,不建文件库。** 访客上传关闭,库里 0 个文件。坐席上传接口已经返回 JSON。工作台把消息当纯文本,所以 `[file=…]` 现在不可点。没有图纸进来之前,做选择器或文件管理器没有收益。

快捷回复是 0 条,而且列表接口返回 HTML。输入状态、在线足迹、完整历史搜索、坐席互转、官方 App、Web Push 都不改变「人能不能在手机上答完一通会话」。

## 收益怎么判

收益只看这一条值班链:访客说话 → 坐席在手机上看到未读 → 打开完整会话 → 回复 → 必要时关闭 → 通知仍走已有企业微信。多出来的桌面模块不计入收益。

staging 现实:1 个未停用坐席,在 Administrators,`invisible_mode=0`,`hide_online=0`;1 个部门,部门邮箱空,`inform_options` 空,`bot_configuration` 空;2 个进行中会话,都属这个坐席,备注空,`gbot_id=0`;消息 4 条访客、3 条坐席、3 条系统、0 条 bot。

## 能力矩阵

| 能力 | 原生证据 | 当前是否启用 | 最小复用 | staging 验证 |
| --- | --- | --- | --- | --- |
| 会话分配/接管 | 发普通消息即接管 pending 与 bot:`modules/lhchat/addmsgadmin.php:219-244`、`:294-336`。显式改状态:`modules/lhchat/changestatus.php:24-48` 调 `lib/core/lhchat/lhchathelper.php:241-264`(active 且 `user_id=0` 才写入当前坐席)。`!pending` / `!active` 走同一 helper:`lhchatcommand.php:744-771`。桌面弹窗只是这个 POST 的表单:`design/defaulttheme/tpl/lhchat/changestatus.tpl.php:5-13`。官方文档同一规则:[Changing chat status](https://doc.livehelperchat.com/docs/chat/chat) | 工作台发送已是 `addmsgadmin`(`mdmobile.js` 把原文放进 `msg=`)。两个会话已是 active、属坐席 1。部门未开自动平衡(`active_balancing=0`) | 回复即接管,不要新写 accept。显式接管只在需要「不发消息就接手」时 POST `chat/changestatus`,状态值 1。坐席互转(`transferchat`,权限 `allowtransfer`)在 1 个坐席下没有收益 | 以后用一次性 pending 会话,从工作台发一条非 `!` 消息,读回 `status=1` 且 `user_id` 为当前坐席。本票不新建会话 |
| 在线与未读 | 未读是 `lh_chat.has_unread_messages`。工作台已按 `adminchat` 的前台已读写回清它。访客在线足迹是另一套:`track_online_visitors=1`,但 `track_is_online=0`、`track_footprint=0`、`track_activity=0`。输入中的在线不是这个开关 | 未读列和已读 POST 已在工作台。足迹采集关着。坐席 `hide_online=0` | 继续读原生未读列。不要为了「在线」打开足迹、截图或 cobrowse | 进入未读会话后徽章消失,#147 已实测。本票不重做 |
| 快捷回复 | 模块 `lhcannedmsg`。会话内列表 `chat/getcannedfiltered` 把 HTML 塞进 JSON:`modules/lhchat/getcannedfiltered.php:13-18`,不是数据 API。发送仍走 `addmsgadmin`,可选 `canned_id` 只用于统计 | `lh_canned_msg` 0 行。没有可点的回复 | 先在现有后台写下内容。有内容之后再调模型列表,正文仍 POST 到现有 `addmsgadmin`。不要解析那段 HTML,不要自写一套回复库 | 后台列表仍为 0 时,工作台不出现选择器。本票不插入假回复 |
| 内部备注 | `chat/saveremarks` 是 CSRF JSON,只写 `lh_chat.remarks`:`modules/lhchat/saveremarks.php:20-38`。`!remark` 也写同一列:`lhchatcommand.php:788-796`。工作台已显示 Remarks,不提供编辑。低语是 `addmsgadmin` 的 `whisper` 参数,要 `lhchat/whispermode`,消息不给访客 | 两个会话备注长度 0。当前坐席有 whisper 权限。只有 1 个坐席 | 现有输入框发 `!remark …` 已经能写入,刷新后工作台能看到。单独编辑框只是发现性,若做就 POST `saveremarks`,不新建表。低语在单坐席没有收益 | 一次性会话发 `!remark`,读回 `remarks` 非空且访客窗口没有这条字。本票不写 |
| 访客资料 | 工作台已从 `lh_chat` 读姓名、邮箱、电话、地点、语言、设备、IP、页面、来源、部门、开始时间、转人工时间和备注。桌面多出来的是在线足迹、截图、cobrowse | 上述字段有值就显示。足迹开关关着,`screenshot` 无使用证据(0 个文件) | 保持现有只读字段。不要打开足迹来填满侧栏 | 打开现有会话,侧栏字段与 `lh_chat` 一致即可。不新增采集 |
| 历史搜索 | 桌面 `chat/list` 是几十个筛选的 HTML 页:`modules/lhchat/module.php:317-356`。工作台已按昵称、数字 id 和状态筛 `lh_chat`,并列出同一 `online_user_id` 或邮箱的最近会话 | 2 个会话。工作台搜索已覆盖昵称和 id | 不复制 `chat/list`。消息全文搜索没有当前收益 | 用现有昵称搜索能打开对应会话,#147 已测。不建第二套搜索 |
| 输入状态 | 坐席 `chat/operatortyping/<id>/<true\|false>` 写 `operator_typing`:`modules/lhchat/operatortyping.php:8-23`。访客状态在 `syncadmin` 的 `tp`/`tx`。`ignore_typing=0` | 工作台轮询只取消息,不读也不写输入状态 | 不做。它不改变能否回复 | 无。不要为了对齐桌面加轮询 |
| 文件 | 坐席上传 `file/uploadfileadmin` 返回 JSON,成功时 `msg` 为 `[file=<id>_<hash>]` 或带 `_img`:`modules/lhfile/uploadfileadmin.php:33-41`。访客上传由 `active_user_upload` 控制;单会话可用 `!files` 设 `chat_variables.lhc_fu=1`:`lhchatcommand.php:279-305`。官方命令说明:[Commands](https://doc.livehelperchat.com/docs/commands) | 访客上传 `false`,坐席上传 `true`,上限 2048KB,访客类型含 pdf/doc/图片。`lh_chat_file` 0 行。工作台把 `msg` 当纯文本,不解析 `[file=]` | 未决定收图纸之前不做。决定之后只做两处:渲染已有 `[file=]` 标记;坐席文件 POST 到 `uploadfileadmin`。不建文件列表、不开放截图 | 不全局打开上传。验证时用一次性会话、一个小 pdf,工作台应给出可打开链接而不是原文标记。本票不上传 |
| AI 接管/交回 | 回复 bot 会话会接管并清掉未完成 bot 事件:`addmsgadmin.php:294-336`。交回的桌面命令 `!gotobot` 只写 `status=5` 和时间,不改 `user_id`,不改 `gbot_id`,不跑触发器:`lhchatcommand.php:380-390`。`changestatus` 到 bot 会清坐席并写系统消息,同样不跑触发器:`lhchathelper.php:331-352`。下一条访客消息只有在 `status` 已是 bot 且用 `gbot_id` 找触发器时才会进 bot:`lhgenericbotworkflow.php:183-238`。官方文档把 `!gotobot` 写成「转给 bot」:[Commands](https://doc.livehelperchat.com/docs/commands);函数没有这一步 | 唯一 bot 名是 Metal Divider Handoff,1 个触发器,0 个触发事件,动作类型是 `restapi`,名称是 WeCom handoff notification。两个会话 `gbot_id=0`。没有应答 bot | 接管继续靠现有回复。不要加交回按钮,不要让业主发 `!gotobot`。应答 bot 和 `gbot_id` 不在本工作台范围内 | 禁止对现有两个会话发 `!gotobot`。以后的通过线是 `gbot_id>0` 且下一条访客消息产生 `user_id=-2`。现在不满足 |
| 移动与通知 | `lhmobile` 模块只管理原生 App 会话:`modules/lhmobile/module.php:1-28`。官方 App 要 `lhrestapi/use_direct_logins`,并单独维持在线:[Mobile app setup](https://doc.livehelperchat.com/docs/mobile)。Web Push 订阅表为空。事件 webhook 要 `settings.webhooks.enabled`:[Webhooks](https://doc.livehelperchat.com/docs/development/webhooks);缺键时 `getSetting` 返回 false:`lib/core/lhconfig/lhconfig.php:22-31`,`lhchateventdispatcher.php:49-54` | `settings.webhooks.enabled=true`,`worker=http`。两条未禁用 webhook 监听 `chat.genericbot_chat_command_transfer` 和 `chat.chat_transfered`,都指向上述 handoff 触发器。operator 与访客 push 订阅都是 0。`disable_mobile=false` 只是没关移动探测,不是装了 App | 工作台不发通知,不装官方 App,不开 Web Push。转接通知已有 webhook→bot REST 这条原生链。覆盖哪些事件归通知闭环,不归本工作台 | 本票不发卡片、不订阅推送。只读确认开关为开。卡片是否仍到达归后续通知票 |

## 已经接上的,不要重写

- 列表、昵称/id 搜索、状态筛选、未读徽章、权限分页:读 `lh_chat`,不是第二套收件箱。
- 发送: `mdmobile.js` 的 `buildSendRequest` 把输入原文 POST 到 `chat/addmsgadmin`。因此 `!pending`、`!active`、`!remark`、`!files` 已经能到达原生命令处理器。这不是新能力。
- 回复即接管: pending 与 bot 的接受逻辑在 `addmsgadmin`,不在工作台。当前坐席非隐身,条件满足。
- 访客侧栏和同访客历史:只读 `lh_chat` 字段。无图、无邮箱时不编造。
- 企业微信深链:站点 mu-plugin 的一次性链接,不是 LHC 手机模块。工作台不改这条链。

## 缺失的连接层

只有这些调用是工作台没有接、而原生已经做成可 POST 的。都不是新业务规则。

| 连接 | 为什么不是桌面模板 | 现在做不做 |
| --- | --- | --- |
| `POST chat/changestatus` 状态 2 | 返回 JSON,真正调用 `changeStatus` 关闭。`!close` 在无 `no_ui_update` 时只写 `lhinst.closeActiveChatDialog(...)`:`lhchatcommand.php:662-666`,工作台丢弃 `operation_admin` | 以后可以加一个关闭按钮。先确认坐席有 `lhchat/canchangechatstatus`。Operators 角色现在没有这项,Administrators 有 |
| 渲染 `[file=id_hash]` | 上传接口已经返回这个标记。工作台只显示文本 | 等确定要收图纸。不要先做 |
| `POST file/uploadfileadmin` | 已是 JSON | 同上,坐席发文件时才接 |
| `POST chat/saveremarks` | 已是 JSON | 可有可无。`!remark` 已经能写 |

`chat/getcannedfiltered`、`chat/list`、`lhmobile`、官方 App、桌面状态弹窗,都不是适合这个工作台的 API。不要包一层去解析它们的 HTML。

## 优先级

1. **不做交回 AI。** 收益是否定的:按钮能点,访客得不到回答。阻塞在应答 bot 和 `gbot_id`,不在界面。
2. **维持现有回复、未读、资料、历史。** 再写一套就是重复。
3. **下一批若只加一个控件,加关闭,并只调 `changestatus`。** 收益是列表不再堆着已结束会话。不是新分配系统。1 个坐席、2 个会话,所以它排在「不要做坏交回」之后,不排在本周必做。
4. **文件渲染排在产品决定收图纸之后。** 现在 0 文件、访客上传关,做了没有对象可验。
5. **快捷回复、输入状态、足迹、完整搜索、互转、官方 App、Web Push:不做。** 快捷回复没有内容;其余不改变能否答完。

## staging 读回(只读)

- 包与目录版本都是 `4.97v`。`settings.site.extensions` 只有 `mdmobile`。
- `settings.webhooks.enabled=true`。第一次查 `settings.site.webhooks` 是空的,那个键不在 site 下;以 `settings.webhooks` 为准。
- 部门:`inform_options` 空,邮箱空,`inform_unread=0`,`bot_configuration` 长度 0,`department_transfer_id=0`。
- 文件配置:`active_user_upload=false`,`active_op_upload=true`,`fs_max=2048`。
- 在线采集:`track_online_visitors=1`,其余足迹/活动/在线探测为 0。`ignore_typing=0`。
- 通知订阅:operator 0,访客 0。
- 权限:坐席 1 在 Administrators(`lh_rolefunction` 为 `*` / `*`)。Operators 角色有 `lhchat/use`、`whispermode`、`allowtransfer`、`lhfile/use_operator`,没有 `canchangechatstatus`。

本票没有发 `!close`、`!gotobot` 或测试文件。那些动作会改两个正在使用的会话。

## 以后的验证,本票未执行

1. 关闭:管理员对一次性会话 POST `chat/changestatus`,状态 2,带现有 CSRF。读回 `status=2` 和系统关闭消息。用 `!close` 当通过标准是错的。
2. 接管:一次性 pending 会话,工作台发普通文本。读回 `status=1`、`user_id` 为该坐席,并有 “accepted the pending chat by sending a message” 系统行。不新写 accept。
3. 交回:只有在应答 bot 已把 `gbot_id` 写入会话之后才测。通过线是访客下一条消息出现 `user_id=-2`。只看到 `status=5` 算失败。
4. 文件:不改全局开关。一次性会话、一个小 pdf。工作台出现可打开链接,而不是 `[file=` 原文。
5. 快捷回复:行数仍为 0 时不得出现选择器。
6. 通知:不新增 push 订阅。转接卡片是否到达不在本票重测。

## 一手来源

- 官方包 `4.97v`:`doc/CHANGELOG.txt:1`;上列 `modules/`、`lib/core/`、`design/defaulttheme/tpl/` 行号。
- 官方文档,2026-10-27:
  - [Chat window / Changing chat status](https://doc.livehelperchat.com/docs/chat/chat)
  - [Commands](https://doc.livehelperchat.com/docs/commands)
  - [Webhooks](https://doc.livehelperchat.com/docs/development/webhooks)
  - [Mobile app setup](https://doc.livehelperchat.com/docs/mobile)
- staging 只读:版本文件、`settings.ini.php` 的非密钥开关、上表计数与权限。查询不写入。
- 当前工作台:`impl/147-responsive-desk` 的 `lhc-extensions/mdmobile/modules/lhmdmobile/module.php` 与 `design/mdmobiletheme/js/mdmobile.js:55-65`。

## 证据局限

- 没有在 staging 实走关闭、接管、交回或上传。源码和当前行足以否定「先写一套」;不足以代替以后那次一次性会话验收。
- `!gotobot` 的官方文档句子比函数多。本票以 `goToBot` 函数为准。
- 企业微信卡片这次没有重发。webhook 开关为开,只说明原生门没有关,不说明卡片今天仍能到达。
- 官方包行号对应该 tar,不另行声称 Git commit。
