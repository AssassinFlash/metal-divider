# 开源收件箱坐席工作流：单坐席现在值得接的只有两件

- 票：[研究：成熟开源收件箱的高价值坐席工作流对照](https://github.com/AssassinFlash/metal-divider/issues/150)
- 父地图：[Wayfinder map：AI 客服与坐席工作台持续功能优化](https://github.com/AssassinFlash/metal-divider/issues/148)（本文件不改地图）
- 日期：2026-10-27
- 分支：`research/open-inbox-workflows`（从 `origin/main` 的 `b272a22` 拉出，不合并，不开 PR）
- 工作树：`/Users/chesterjohn/Documents/metal-divider/.scratch/research-150-open-inbox`
- 用途：本票研究文档的唯一临时工作副本
- 负责人：认领本票的研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树，并删除 `/tmp/cw-4191` 与 `/tmp/lhc-497` 的只读摘录；不删除远程分支，不删除本文件

本文件只回答：成熟开源收件箱里，哪些坐席工作流能明显改善 Metal Divider 当前单坐席、以及以后两三个人的小团队。排序依据是当前真实收益除以接到现有 LHC 工作台的复用成本，不是功能数量。

不讨论重新部署 Chatwoot。主机、许可证、Captain、WordPress 插件和 Dify 连接以已关闭的 [#91](https://github.com/AssassinFlash/metal-divider/issues/91) 为准，这里只增量核对版本和与坐席工作流有关的旗标。LHC 原生能力的完整盘点归 [#149](https://github.com/AssassinFlash/metal-divider/issues/149)；本文只核对要分类的那几项，不代替那张票。

未安装 Chatwoot，未打开 Chatwoot 或 LHC 仪表盘，未在 staging 点过这些控件。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 源码 | 2026-10-27 从官方标签读出的文件。Chatwoot 标签 `v4.19.1`，提交经附注标签 `c5c325a0bbc949339dd688d31e3f73cf0b272906` 指向 [#91](https://github.com/AssassinFlash/metal-divider/issues/91) 已记录的 `caab07a8f00032d5205b5a5d7e2eb0ba2f3d2d82`。LHC 标签 `4.97v`，提交 `016f48629b053e4eb314500d3a99efd7aba0fbd9` |
| 官方文档 | 官方帮助中心或文档站上的句子。Chatwoot 文章用功能旗标里的 `help_url` 或帮助中心导航链到的 `.md`。未用账号点过控制台 |
| 现有工作台 | 已关闭的 [#147](https://github.com/AssassinFlash/metal-divider/issues/147) 实现，远程 `impl/147-responsive-desk` @ `62ea911`。这是代码事实，不是 staging 点击证据 |
| 无法确认 | 需要登录、付费旗标打开后的界面，或帮助文只有图片没有文字 |

星数、营销插图和“功能列表很长”不作为值得做的证据。

## 结论

1. **现在只值得做两件，而且都不必新造存储。** 把 LHC 已有的 canned message 填进工作台输入框，发送前可改；把工作台已经显示的 remarks 改成可写。这两件单坐席每天都用得上，复用的是 4.97 的原生存储和权限。
2. **做完这两件就停。** 不要把 Chatwoot 的命令面板、宏、批量、轮询分配、容量策略、联系人 CRM 或高级搜索接进来。它们在成熟收件箱里是真功能，对当前这条低量、单人、手机加企业微信值班的询盘会话不是收益。
3. **第二个人类坐席出现之前，不要做分配。** Chatwoot 的轮询只在有人在线时把未分配会话摊开；没人在线就留在 Unassigned。一个人没有可摊的对象。LHC 已有转给其他操作员或部门的接口，那是以后的手动移交，不是现在要写的分配引擎。
4. **未读、状态、昵称/编号搜索、只读备注、按访客或邮箱的历史，工作台已经有。** 不要为了“对照表上有这一列”再写一遍。
5. **付费边界不要抄。** `advanced_assignment`、`advanced_search`、`conversation_monitors` 在 v4.19.1 仍是 `premium: true` 且默认关。容量均衡、Elasticsearch 搜索、会话监视不进社区可复用清单，也不该在 LHC 上自写一份。

## 和 #91 的增量核对

2026-10-27 GitHub latest release 仍是 `v4.19.1`，发布于 2026-10-24 04:48 UTC。与 #91 钉的版本相同，部署选型不重做。

与本票有关的旗标，`config/features.yml` @ `v4.19.1`：

| 旗标 | 默认 | premium | 本票用法 |
| --- | --- | --- | --- |
| `canned_responses` | 开 | 否 | 快捷回复在社区版 |
| `macros` | 开 | 否 | 宏在社区版，但现在不值得接 |
| `labels` | 开 | 否 | 标签在社区版 |
| `team_management` | 开 | 否 | 团队在社区版 |
| `crm` | 开 | 否 | 联系人在社区版 |
| `assignment_v2` | 开 | 否 | 社区轮询走这条，不是付费容量 |
| `advanced_assignment` | 关 | 是 | 容量/均衡选择器在 `enterprise/` |
| `advanced_search` | 关 | 是 | 消息全文的 Elasticsearch 路径 |
| `conversation_monitors` | 关 | 是 | 不要当成“未读”或“正在输入” |
| `conversation_unread_counts` | 关，且 `chatwoot_internal` | 否 | 这是内部计数旗标，不是“没有未读” |

`assignment_v2` 的开关是 `Inbox#auto_assignment_v2_enabled?`，即账号打开了 `assignment_v2`。社区版 `member_ids_with_assignment_capacity` 就是收件箱成员 id，没有容量过滤。容量过滤和 `balanced` 选择器在 `Enterprise::AutoAssignment::AssignmentService`，并且要求 `advanced_assignment` 旗标。这与 #91 的付费边界一致，没有新缝。

未再核对 Captain、价目、WordPress 插件、Dify 连接器、主机规格。那些结论仍以 #91 为准。

## 当前工作台已经承担的

证据是 [#147](https://github.com/AssassinFlash/metal-divider/issues/147) 的 `erlhcoreclassmdmobile.php` @ `62ea911`，不是这次新写的。

| 已有 | 代码事实 | 因此不要再做 |
| --- | --- | --- |
| 未读 | 列表用 `has_unread_messages`。打开时，拥有者、未分配（`user_id` 0）或已关闭的会话会把未读清掉 | 不要再造未读计数 |
| 状态筛选 | `pending` / `active` / `closed` / `chatbox` / `operators` / `bot` | 不要再造一套状态机 |
| 搜索 | 昵称 `LIKE` 或会话 id。不搜消息正文 | 正文搜索不是当前缺口 |
| 备注 | 访客栏显示 `remarks`，截断到 1000 字，只读 | 缺的是写入，不是再显示一遍 |
| 历史 | 先按 `online_user_id`，没有则按邮箱；查询最多 50，可见 5 条；排除当前会话 | 不要建联系人 CRM 来换这 5 条 |
| 发送 | 复用原生 `addmsgadmin`。本文件不重读发送实现，#147 票面已写明 | 耳语若要做，是这条发送上的一个旗标，不是第二套消息库 |

工作台注释写明：状态不是猜出来的 Online。这是对的，不要加一枚“在线”徽章。

## 排序

收益按当前单坐席计。小团队指以后有第二个会回复会话的人，不是报表、角色或 SLA 意义上的“团队功能”。

### 1. 快捷回复：填进输入框，改完再发

**收益。** 材质只有 Stainless Steel (304 / 316)，镂空率是 On request，询盘在图案页发起。这些句子会反复发。单坐席在手机上最亏的是每次重打，不是找不到会话。Chatwoot、LHC、Rocket.Chat、Zammad 都把“预写句子”当成日常动作，而不是报表功能。这是对照里唯一一项四边一致、且当前就用得上的工作流。

**可直接借鉴的行为。**

- Chatwoot 官方帮助：在编辑器输入 `/`，按短码或列表选中，Enter 后**填进输入框**，发送前可以改。短码账户内唯一，最短 2 字符。全账户坐席共用。支持 `{{contact.first_name}}`、`{{agent.first_name}}` 等，空值换成空白。图片不在模板里，发送前再附。见 [Canned Responses](https://chatwoot.help/hc/user-guide/articles/1677501325-how-to-create-saved-reply-templates-with-canned-responses.md)。模型只有 `content` 和 `short_code`，按账户唯一（[canned_response.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/canned_response.rb)）。
- LHC 官方文档同一目的：预写常见回答。后台在 System configuration → Live help configuration → Canned messages。聊天窗底部可搜标题、正文和说明。标签用 `#tag` 或单独的 `#` 调出，方向键选择。变量是 `{nick}`、`{operator}`、`{email}`、`{phone}` 和自定义字段标识。可按部门限制；不选部门则全部会话可见。个人模板要 `lhuser` / `personalcannedmsg`，使用要 `lhcannedmsg` / `use`。见 [How to use canned messages?](https://doc.livehelperchat.com/docs/canned)。
- Rocket.Chat 8.9.0（2026-10-05）的 Omnichannel 文档：在消息框输入以 `!` 开头的 shortcut。范围是 Public、Department、Private。可用 `{{agent.name}}` 这类占位符。见 [Canned Responses](https://docs.rocket.chat/docs/canned-responses)。
- Zammad 管理文档把句子和“改工单”拆开。Text modules 是句子，可限组，文章和聊天都能用，用关键词加方向键找。见 [Text Modules](https://admin-docs.zammad.org/en/latest/manage/text-modules.html)。仓库最新非 alpha 标签是 `7.2.2`；本次没有把文档逐段对到该标签。

**原生复用。** 用 LHC，不用 Chatwoot 的表。4.97 模型已有 `msg`、`title`、`explain`、`department_id`、`user_id`、`fallback_msg`、`disabled`（[erlhcoreclassmodelcannedmsg.php](https://github.com/LiveHelperChat/livehelperchat/blob/4.97v/lhc_web/lib/models/lhchat/erlhcoreclassmodelcannedmsg.php)）。按当前会话部门和当前操作员取列表的接口是 `getcannedfiltered`，读权限不够就返回 `no permission`。发送侧 `addmsgadmin` 已接受 `canned_id`，用于统计，不是另一套发送。

**缺的连接层。** 工作台输入框没有调用 `getcannedfiltered`，也没有把选中的 `msg` 填进现有回复框。连接层只做这三步：按权限取列表、填进输入框、人改完后走现有 `addmsgadmin`。不要新建 canned 表，不要把 Chatwoot 的 `/` 短码或 Rocket.Chat 的 `!` 当成必须复刻的语法。LHC 已经用 `#`。工作台可以做成按钮加 `#` 过滤，两种都能在手机上点。

**不要一起搬的。**

- `auto_send`：官方文档写明，接受会话时自动发给访客，多条只发第一条，且只对 web client。这会在人还没看内容时发出去。工作台路径不要开。
- `html_snippet`：官方文档写明，这段 HTML 会发给访客，例子包括在对方浏览器执行 JavaScript。工作台只插入 `msg` 文本，不传这个字段。
- Chatwoot 的自定义属性变量和账户级唯一短码。LHC 的部门/个人范围更适合以后的小团队，不要改成全账户一份。
- 宏。见下文。一条句子不是一个动作序列。

**staging 验证。** 先列出后台已有 canned，不要为了测试先造一堆。然后：

1. 准备三条：材质口径、镂空率 On request、如何在图案页询盘。部门与当前坐席权限要能在 `getcannedfiltered` 里看到。
2. 桌面和手机各打开一条真实访客会话。用 `#` 或按钮插入，改一个字，再发送。
3. 访客挂件只看到改后的那一条，看不到模板标题，看不到第二条系统消息。
4. 没有 `lhcannedmsg` / `use` 的账号看不到列表，接口不是 200 加空壳。
5. 不启用 auto-send。接受一条新会话，访客不应收到未经点击的模板。

### 2. 可写的会话备注：人走了还能看见，访客看不见

**收益。** 企业微信只带通知和深链，不带“这位要 316，周二再跟，图案是哪一页”。单坐席离开再回来，亏的是这句上下文。第二个人出现以后，同一句也够交接，不必先做 @提及。

**可直接借鉴的行为。** Chatwoot 的 private note 是消息上的 `private` 布尔，不是另一张表（[message.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/message.rb)）。渠道发送在 `invalid_message?` 里直接跳过 private，注释写明 private notes aren't send to the channels（[send_on_channel_service.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/services/base/send_on_channel_service.rb)）。宏也可以插一条 private note，那是宏的一个动作，不是备注本身的理由。编辑器有 `Context::PrivateNote`，只说明后台有这个写作上下文。本次没有找到一篇独立的“如何写内部备注”帮助文，这条以源码为准。

LHC 有两种，不要混成一个需求：

- **remarks**：每个会话一个文本字段。官方聊天窗文档写 “You can set chat remarks for future reference.”（[Chat window](https://doc.livehelperchat.com/docs/chat/chat)）。写入是 `saveremarks.php`：校验 CSRF，要读和写权限，事务里只更新 `remarks`。
- **whisper**：一条写进消息流的操作员私语。4.97 的 `addmsgadmin.php` 要求 `lhchat` / `whispermode`。访客消息行遇到 `meta_msg.content.whisper` 就 `skip`（[user_msg_row.tpl.php](https://github.com/LiveHelperChat/livehelperchat/blob/4.97v/lhc_web/design/defaulttheme/tpl/lhchat/lists/user_msg_row.tpl.php)）。REST `addmsgadmin` 接受 `sender=whisper`。复制会话时默认可以去掉 whisper。这是以后要的消息轨迹，不是现在缺的那一块。

**原生复用。** 工作台已经把 `remarks` 放进访客栏，只是只读。存储、权限和 CSRF 都在。第一批只把这一格改成可写，调用现有 `saveremarks`。不要把 Chatwoot 的 private message 表再实现一遍。

**缺的连接层。** 一个保存动作：工作台把文本 POST 到 `saveremarks`，成功后读回同一字段。失败时保留输入，不要清空。长度沿用工作台已有的 1000 字上限，除非 staging 证明 LHC 列更短；本次没有读 `lh_chat.remarks` 的列定义，所以不要在连接层自行加长。

**不要一起搬的。**

- 现在不要做 whisper。它要额外权限，还要在现有消息列表里区分访客不可见。remarks 已经覆盖单坐席的回访。第二个人需要“谁在何时写了哪句”时，再在现有 `addmsgadmin` 上加 whisper 旗标，仍然不建新表。
- 不要做 @提及。Chatwoot 有 `mentions` 表，写入后发 `CONVERSATION_MENTIONED`。一个人没有可提及的对象。本次没有核对提及是否只来自私密消息。
- 不要把备注同步进企业微信正文。通知票的边界是深链，不是第二本记录。

**staging 验证。**

1. 手机上给一条会话写备注，刷新，备注还在。
2. 同一会话的访客挂件和访客邮件里都没有这句。
3. 另一个有读权限的操作员能看见。没有写权限的保存被拒绝，原备注不变。
4. CSRF 缺失时保存失败，不写出半截。
5. 公开回复仍走现有发送，不被备注保存带出去。

### 做完 1 和 2 就停

这两件上了 staging 并按上面的场景读回之后，用真实会话看一周再决定下一批。不要在同一批把下面的“以后”一起做完。功能一起上线，就无法知道哪件真的被用了。

### 以后，而且要有触发条件

| 工作流 | 触发条件 | 落点 | 为什么现在不做 |
| --- | --- | --- | --- |
| 主题筛选 | 跟进会话开始在列表里找不到，昵称/编号不够用 | LHC subjects。官方文档：给会话打主题，列表可按主题搜。设置主题要权限，管理主题定义是 `lhchat` / `administratesubject`。接口 `subject.php` 按 CSRF 添加或去掉 `subject_id` | 现在的量用状态加未读就够。不要做 Chatwoot 那种 13 个字段的 AND/OR 筛选器 |
| Whisper 轨迹 | 第二个会回复的人需要逐条内部记录，单字段备注不够 | 现有 `addmsgadmin` 加 `whisper`，权限 `whispermode`。访客行已跳过 | 单坐席的回访用 remarks 够了 |
| 手动移交 | 第二个会回复的人真的要接一条进行中的会话 | LHC `transferuser.php`：`change_owner` 要 `lhchat` / `changeowner`，`change_dep` 要 `changedepartment`。官方文档还有在线超时，不在线的人收不到移交通知 | 没有第二个人可移交。不要先写轮询 |
| 坐席之间的“正在输入” | 两个人会同时打开同一条会话 | Chatwoot 帮助文把防碰撞写成两件事：指定 assignee，以及看到对方正在输入。见 [Preventing Agent Collision](https://chatwoot.help/hc/user-guide/articles/1732243644-preventing-agent-collision.md) | 一个人不会和自己撞。`conversation_monitors` 是另一条付费旗标，默认关，不要做成“谁在看这条会话” |

主题的 staging，等真的做时：给一条会话打上 follow-up，列表只显示它，去掉后消失；访客界面没有这个词。移交的 staging：移给第二坐席后，第一坐席不再是拥有者，访客不收到一篇系统说明文，未读不会被非拥有者打开就清掉。这些都不是本批。

### 不值得做

下面每件都在某个成熟收件箱里是真的。不值得，是因为接到当前栈的收益接近零，或者会制造错误发送。

**轮询、团队、容量。** Chatwoot 帮助文：有可用坐席时把会话均匀分下去；都不可用就留在 Unassigned；收件箱默认开启自动分配（[round-robin](https://chatwoot.help/hc/user-guide/articles/1677696868-assigning-conversations-in-a-round_robin-fashion.md)）。源码更窄：只把状态为 `online` 的收件箱成员算进 `available_agents`，`busy` 和 `offline` 不算（[inbox_agent_availability.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/concerns/inbox_agent_availability.rb)、[agent_assignment_service.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/services/auto_assignment/agent_assignment_service.rb)）。团队是账户级、管理员才能改；团队上的 auto-assign 关掉时，会话留在团队队列等人领（[Adding teams](https://www.chatwoot.com/hc/user-guide/articles/1677492970-adding-teams.md)）。容量和均衡选择器要 `advanced_assignment`，在企业目录。一个人不需要摊派。小团队的第一形态是手动移交，不是轮询，更不是每小时公平上限。社区代码里的 `RateLimiter` 在没有策略时默认 5 分钟 5 次，那是 Chatwoot 自己的分配器细节，不要移植。

**宏。** Chatwoot 社区版默认开。帮助文的例子是：分配团队、发标准句、加标签、snooze，一次跑完（[Macros](https://chatwoot.help/hc/user-guide/articles/1679978392-how-to-use-macros.md)）。源码动作包括 `assign_agent`、`add_private_note`、`send_message`、附件和 webhook（[execution_service.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/services/macros/execution_service.rb)）。Zammad 把宏定义成一组工单变更，并且明确可以在概览里对多张工单批量执行（[Macros](https://admin-docs.zammad.org/en/latest/manage/macros.html)）。这是工单台的重复点击压缩。当前重复的是句子，不是“改五个字段”。句子由第 1 项覆盖。做宏会把标签、分配、自动发送绑在一个按钮上，单坐席点错的代价大于省下的点击。

**批量。** Chatwoot `BulkActionsJob` 按 display id 改状态、坐席、团队、标签和 snooze，上限 `Limits::BULK_ACTIONS_LIMIT = 100`。联系人批量还可以删除。Zammad 把批量写进宏文档。这条询盘线不是工单洪水。批量解决或批量改派没有对象。批量删除联系人是伤害。不要做。

**键盘和命令面板。** Chatwoot 帮助文：`⌘K` / `Ctrl+K` 打开命令面板，可跳页、解决、snooze、分配、加标签（[Command bar](https://chatwoot.help/hc/user-guide/articles/1677697180-working-with-command-bar.md)）。`⌘/` 或 `Win+/` 打开快捷键列表，但列表是图片，本次没有从源码抽出完整键位表，不编造 Alt 组合。源码里的命令动作包括解决、重新打开、静音、snooze、分配、标签、优先级、执行宏，以及 OpenAI 改写项（[actions.js](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/javascript/dashboard/helper/commandbar/actions.js)、[useConversationHotKeys.js](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/javascript/dashboard/composables/commands/useConversationHotKeys.js)）。当前值班是手机加企业微信。命令面板是桌面熟练工的导航，不是这条链路的收益。LHC 聊天窗文档只把 Enter 发送、Shift+Enter 换行写成可见句子。工作台若以后要一个键，那是“插入 canned”，不是一套跳页系统。OpenAI 改写项不要借命令面板带进来；那和 #91 的 Captain 边界是另一件事。

**联系人 CRM、自定义属性、公司、导入。** Chatwoot `crm` 默认开。帮助文包括手工建联系人、CSV 导入、预聊天表单、联系人备注、按标签分组（[Understanding Contacts](https://chatwoot.help/hc/user-guide/articles/1677498364-understanding-contacts.md)）。工作台已经有邮箱、电话、页面、部门和按访客或邮箱的历史。图案上下文在页面 URL 里，如果 referrer 是 PDP。不要建第二本客户册，不要做自定义属性。历史可见条数若真的不够，是把 5 调高，不是换一个 CRM。本次没有打开 staging 核对历史是否已经按邮箱串起来；那是 #147 的既有合同，验证场景写在下面，不构成新功能。

**全文搜索和高级筛选。** 社区版 `SearchService` 能按联系人姓名、邮箱、电话、会话 display id 搜会话；消息正文在没有 `advanced_search` 时用 `ILIKE`，窗口默认 `SEARCH_TIME_RANGE_LIMIT_DAYS` 为 90 天（[search_service.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/services/search_service.rb)、[limits.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/lib/limits.rb)）。`advanced_search` 才走 Elasticsearch，默认关。Chatwoot 筛选器还有状态、坐席、团队、标签、活动时间、语言、国家、referrer（[Conversation filters](https://chatwoot.help/hc/user-guide/articles/1677688192-how-to-use-conversation-filters.md)）。当前工作台搜昵称和 id。在昵称/编号/邮箱历史不够用之前，不要做正文搜索，也不要做筛选器。邮箱若以后要搜，先搜会话上已有的 email 字段。

**在线状态。** Chatwoot 坐席可用性是 `online` / `offline` / `busy`（[account_user.rb](https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/account_user.rb)）。它服务于分配和“有没有人可接”，不是列表装饰。访客是否在线，工作台已经拒绝猜。坐席在线与否如何影响 AI 接管，归人机协同那张研究票，不在本批工作台里加一盏灯。

**优先级。** Chatwoot 有 Low / Medium / High / Urgent，可从侧栏或命令面板设（[Priority](https://chatwoot.help/hc/user-guide/articles/1682679737-how-to-assign-a-priority.md)）。当前未读加状态已经是排序。不要加第四个维度。

**碰撞监视器、SLA、自定义角色、报表、活动、CSAT。** 不在本票的收益排序里。`conversation_monitors` 付费且默认关。其余要么付费，要么是另一张票的运营闭环。不要因为帮助中心导航里有这些标题就列入工作台批次。

## 缺失的连接层

只有两处，都是工作台调用 LHC 已有入口，不新增表，不新增消息状态。

| 连接 | 读 | 写 | 明确不做 |
| --- | --- | --- | --- |
| 快捷回复 | `getcannedfiltered`，受 `lhcannedmsg` / `use` 和部门范围约束 | 把选中的 `msg` 填进现有输入框；发送仍走现有 `addmsgadmin` | 不传 `html_snippet`，不开 `auto_send`，不新建模板表 |
| 备注 | 已有的 `remarks` 字段 | `saveremarks`，CSRF，读+写权限，成功后读回 | 不把备注发到访客，不同步进企业微信正文，本批不做 whisper |

LHC 原生能力是否还要配置开关，由 #149 在 staging 上核对。本文件的判断是：这两件的存储和权限在 4.97 源码里，工作台代码没有调用它们。若 #149 发现 staging 没开 canned 模块或 remarks 列不可写，先修配置，仍然不要自写存储。

## staging 验证

本票不部署、不改 staging。下面是下一张实现票的最小场景。通过即停，不要加截图矩阵。

共用前置：用现有 staging 的一个测试坐席和一个测试访客。不新开 Chatwoot。不把生产当第一次验收。

1. **快捷回复。** 插入、修改、发送。访客只看到修改后的正文。无权限账号看不到列表。接受新会话不会自动吐出模板。
2. **备注。** 手机写入，刷新仍在。访客挂件没有这句。无写权限保存失败且原值不变。
3. **回归，不是新功能。** 打开一条未读，只有这一条的未读消失。另一条仍未读。离开后再来一条访客消息，未读回来。同一邮箱或同一 online user 的上一条出现在历史里。这些已在 #147，本批不得改坏。
4. **明确的失败。** 访客看到模板标题、HTML、未发送的 auto-send、或备注正文，本批失败。

不验证命令面板、批量、轮询、宏、优先级、全文搜索。没做的东西没有通过标准。

## 无法确认

- staging 后台是否已经有 canned 行，以及当前坐席是否有 `lhcannedmsg` / `use` 和 `whispermode`。没有登录。
- `lh_chat.remarks` 的数据库列长度。工作台截断是 1000，列可能更短或更长。
- Chatwoot 快捷键模态里的完整键位。帮助文是图片。
- 提及是否只在 private note 里创建。只确认模型会发提及事件。
- 正在输入的实现是否完全在 MIT 目录。帮助文描述了它；`conversation_monitors` 是另一条付费旗标。没有把两者的源码路径逐文件对完。
- Rocket.Chat 的分配算法。官方 canned 页可读，猜测的 assignment URL 返回 404，没有审它的源码。
- Zammad 文档页与标签 `7.2.2` 是否逐段一致。补充对照只用了管理文档上的句子。
- 没有在浏览器里走通 Chatwoot 或 LHC 的坐席界面。源码和官方文档不一致时，以本文件引用的那一版源码为准。

## 来源

Chatwoot 版本与旗标：

- <https://github.com/chatwoot/chatwoot/releases/tag/v4.19.1>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/config/features.yml>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/inbox.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/concerns/auto_assignment_handler.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/models/concerns/inbox_agent_availability.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/services/auto_assignment/assignment_service.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/services/auto_assignment/agent_assignment_service.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/enterprise/app/services/enterprise/auto_assignment/assignment_service.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/services/base/send_on_channel_service.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/jobs/bulk_actions_job.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/lib/limits.rb>
- <https://github.com/chatwoot/chatwoot/blob/v4.19.1/app/services/search_service.rb>

Chatwoot 帮助文（由 `features.yml` 的 `help_url` 或帮助中心导航解析，2026-10-27 读取 `.md`）：

- <https://chatwoot.help/hc/user-guide/articles/1677501325-how-to-create-saved-reply-templates-with-canned-responses.md>
- <https://chatwoot.help/hc/user-guide/articles/1677696868-assigning-conversations-in-a-round_robin-fashion.md>
- <https://www.chatwoot.com/hc/user-guide/articles/1677492970-adding-teams.md>
- <https://www.chatwoot.com/hc/user-guide/articles/1677496066-how-to-add-labels.md>
- <https://chatwoot.help/hc/user-guide/articles/1679978392-how-to-use-macros.md>
- <https://chatwoot.help/hc/user-guide/articles/1677697180-working-with-command-bar.md>
- <https://chatwoot.help/hc/user-guide/articles/1677698168-working-with-keyboard-shortcuts.md>
- <https://chatwoot.help/hc/user-guide/articles/1732243644-preventing-agent-collision.md>
- <https://chatwoot.help/hc/user-guide/articles/1677498364-understanding-contacts.md>
- <https://chatwoot.help/hc/user-guide/articles/1677688192-how-to-use-conversation-filters.md>
- <https://chatwoot.help/hc/user-guide/articles/1682679737-how-to-assign-a-priority.md>

LHC 4.97 与官方文档：

- <https://github.com/LiveHelperChat/livehelperchat/releases/tag/4.97v>
- <https://doc.livehelperchat.com/docs/canned>
- <https://doc.livehelperchat.com/docs/chat/chat>
- <https://doc.livehelperchat.com/docs/chat/subject>
- <https://doc.livehelperchat.com/docs/chat/transferring-chat>
- <https://github.com/LiveHelperChat/livehelperchat/blob/4.97v/lhc_web/modules/lhchat/getcannedfiltered.php>
- <https://github.com/LiveHelperChat/livehelperchat/blob/4.97v/lhc_web/modules/lhchat/saveremarks.php>
- <https://github.com/LiveHelperChat/livehelperchat/blob/4.97v/lhc_web/modules/lhchat/addmsgadmin.php>
- <https://github.com/LiveHelperChat/livehelperchat/blob/4.97v/lhc_web/modules/lhchat/transferuser.php>
- <https://github.com/LiveHelperChat/livehelperchat/blob/4.97v/lhc_web/design/defaulttheme/tpl/lhchat/lists/user_msg_row.tpl.php>

补充收件箱：

- Rocket.Chat `8.9.0`，2026-10-05：<https://github.com/RocketChat/Rocket.Chat/releases/tag/8.9.0>，<https://docs.rocket.chat/docs/canned-responses>
- Zammad 管理文档，2026-10-27 读取；仓库最新非 alpha 标签 `7.2.2`：<https://admin-docs.zammad.org/en/latest/manage/text-modules.html>，<https://admin-docs.zammad.org/en/latest/manage/macros.html>

现有工作台：

- <https://github.com/AssassinFlash/metal-divider/blob/62ea911960515b14cb25f59fc7020deb23ebc944/lhc-extensions/mdmobile/lib/erlhcoreclassmdmobile.php>

LHC 官方文档入口由 Apify `apify/google-search-scraper` 一次查询定位（run `4guNBQlVuMX7WdRFz`），产品句子以打开后的官方页面为准，不以搜索摘要为准。
