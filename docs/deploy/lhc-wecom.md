# LHC 与企微连接器:配置、部署与边界

值班员在微信里收到新会话卡片,点开直接落到 mdmobile 工作台。整条链路由四件事拼成:mdmobile 扩展渲染工作台、WordPress mu-plugin 发卡、LHC 的 REST API 动作承载 payload、LHC 的 webhook 行决定何时触发。缺一件都不会有卡。

本文只写"要配成什么样"和"改完怎么生效";具体业务规则(何时该发卡)见研究文档 `docs/research/2026-10-27-mobile-wecom-workflow.md` 的 §7/§8。

## 组成与职责

| 组成 | 位置 | 职责 |
| --- | --- | --- |
| mdmobile 扩展 | `lhc-extensions/mdmobile` → `chat/extension/mdmobile` | 移动工作台与桌面三栏,发送/关闭/快捷回复/私密备注 |
| 连接器 | `mu-plugins/md-wecom-notify.php` | 校验 bearer、按会话状态门控、签发深链、发企业微信 textcard、记账 |
| REST API 动作 | LHC 后台 `genericbot/restapi` | 把 LHC 事件渲染成 JSON POST 到连接器 |
| webhook 行 | LHC 表 `lh_webhook` | 决定哪些 LHC 事件触发上面那条 REST 动作 |

## 部署顺序(改扩展或连接器时照做)

1. 扩展:`rsync` 到 `<LHC 根>/extension/mdmobile/`(不带 `tests/`)。
2. 连接器:`scp` 到 `wp-content/mu-plugins/md-wecom-notify.php`。
3. **清编译缓存**(漏了这步,改动的 PHP 与模板会被静默忽略):
   ```
   rm -f <LHC 根>/cache/cacheconfig/moduleCache_*.cache.php \
         <LHC 根>/cache/cacheconfig/templateCache.cache.php
   ```
   下一次请求会重新编译,不需要重启 PHP。
4. 读回验证:页面标记(如 `#md-quick`、`data-close-url`)+ 一次真实发送。

REST 动作的 body 模板是**数据库配置**,不在 git 里;连接器与模板必须成对部署,否则字段缺失会被门控丢掉。

## LHC 侧配置(staging 现值)

REST 动作:`lh_generic_bot_rest_api` id=1,method `temp1793044775072`,POST,raw,content-type `application/json`,suburl `/wp-json/md/v1/wecom/notify`,Bearer 授权在后台填(值不入仓库)。body 模板(337 字节,`md5 df92578388699073e698427b10abab4d`):

```json
{"event":{{args.lhc_event}},"is_online":{{args.is_online}},"command":{{args.action.content.command}},"chat":{"id":{{chat_id}},"status":{{lhc.status}},"pnd_time":{{lhc.pnd_time}},"last_op_msg_time":{{lhc.last_op_msg_time}},"last_user_msg_time":{{lhc.last_user_msg_time}},"dep_id":{{lhc.dep_id}},"nick":{{lhc.nick}}},"msg":{"msg":{{msg}}}}
```

占位符语义(源码:`lib/core/lhgenericbot/actionTypes/lhgenericbotactionrestapi.php` raw 分支先替换再整体发送,值经 json_encode):

- `{{args.lhc_event}}` / `{{args.is_online}}`:事件名与在线判定,由事件派发注入(`lhchateventdispatcher.php` 写 `lhc_event`)。`chat.addmsguser` 没有 `is_online`,渲染成 `null`,门控只认布尔,因此按 `null` 跳过在线检查。
- `{{args.action.content.command}}`:转人工命令。bot `stopchat` 渲染为 `"stopchat"`,bot `closechat` 渲染为 `"closechat"`;其他事件没有该参数,渲染成 `null`。连接器据此拒绝 closechat 的卡,不会把即将关闭的会话推给值班员。
- `{{chat_id}}`、`{{msg}}`:内置占位符(聊天 id、消息原文)。
- `{{lhc.<列名>}}`:`lh_chat` 的任意列,数字列输出数字、字符串列输出带引号字符串。

webhook 行(`lh_webhook`,全部 trigger_id=1、bot_id=1、disabled=0):

| id | event | 用途 |
| --- | --- | --- |
| 1 | `chat.genericbot_chat_command_transfer` | bot/命令转人工(带 is_online) |
| 2 | `chat.chat_transfered` | 坐席把会话转给其他坐席(立即发卡) |
| 3 | `chat.addmsguser` | 访客新消息(进行中窗口跟进卡) |

## 值班配置

LHC 的"在线"只看两类信号:坐席活动(`lh_userdep.last_activity` 在 `online_timeout` 内,或 `always_on=1`)和部门时段(`lh_departament_custom_work_hours`,否则 `lh_departament` 的星期列)。判定函数 `erLhcoreClassChat::isOnline()`(`lib/core/lhchat/lhchat.php:993`,SQL 在 1036,时段回退 1055-1103)。

staging 现值:部门 1 `online_hours_active=1`,七天 `start_hour=100/end_hour=1300`。站时区 `settings/settings.ini.php` 为 `UTC`,而时段用 `(int)(date('G').date('i'))` 比较,所以 **HHMM 是站时区**:100–1300 UTC = 北京 09:00–21:00。改站时区或改时段都要同步换算,否则值班窗口会整体平移。

坐席侧不要依赖 `always_on` 表达"今天有人":员工菜单的 Always online(`/chat/index.php/user/setalwaysonline/true|false`)与"分配部门"是两件事;公共端点 `restapi/isonlinechat/<id>?exclude_bot=true` 不数 `dep_id=0` 的行,只分配 `dep_id=0` 时不代表该部门有人。要临时离线用原生 `hide_online=1`(`/chat/index.php/user/edit/<id>`),它对 `isOnline()` 直接生效。

## 连接器门控(与模板字段一一对应)

- 判断顺序:status 缺失 → bot closechat 命令(`command`)→ transfer 事件的 `is_online`(缺失/非布尔/离线)→ status 是否 0/1 → 访客消息的窗口。closechat 即使 is_online 为 true 也在第一步跳过(`closing`)。
- 转人工/转接事件立即发卡(待处理与进行中都一样):一次转交意味着新的接收人,不是访客下一条消息。`chat.addmsguser` 才受窗口约束。
- 待处理窗口 600s 按 `pnd_time`(会话进入待处理的时刻)算,只对"已经成功发过卡"的会话生效:600s 内访客再来消息不发;如果第一张卡因频控或上游失败**没有记账**,下一条访客消息立即重试,不会被 600s 窗口静默吃掉。进行中窗口 180s 按 `last_op_msg_time`(坐席最后发言)算:坐席刚回过话的 3 分钟内,访客消息不重复发卡。这两个哨兵为 0 时按缺失处理,不发卡。
- 时间戳晚于服务器时钟(差值<0)单独返回 `timestamp_in_future`,不并入 `not_due`,避免时钟漂移静默丢消息。
- 每会话冷却按"上一次成功发卡时间"算,窗口同上;跳过一律发生在写"已发卡"记录之前,所以离线不发不会占静音名额;失败(深链或企业微信报错)也不记账,下一事件可重试。所有跳过原因都写 `error_log`(前缀 `[md-wecom-notify]`),排查时先看它。
- 每张卡重新 `generateautologin`(`t=3600`),不复用旧链。
- 频控 30 条/60s、1000 条/3600s;超限只记 `error_log`,不阻塞 LHC。
- 卡片:textcard,title `Live chat #<id> — <nick>`,description 截 120 字,按钮 `Open`(企业微信限 4 字)。

## 已知边界(不要承诺超出这些的能力)

- 访客转人工(`modules/lhchat/transfertohuman.php`)的 `is_online` 是上游**硬编码 true**,不查 `isOnline()`;widget 离线时根本不显示该按钮,所以离线漏发卡只可能来自伪造请求。这是上游行为,不改核心。
- 企业微信 textcard 没有更新接口:卡片发出后内容不会变,过期只能再发一张(过期页文案 `Autologin hash has expired`)。
- 关闭会话走原生 `chat/changestatus`;该路由本身要求 `lhchat/canchangechatstatus`,所以工作台只在同时具备读、写、该函数与(本会话 owner 或 `allowcloseremote`)时才显示关闭入口。`erLhcoreClassChatHelper::changeStatus` 在无权限时静默跳过但仍返回 `{"error":"false"}`,所以前端按"渲染时有权限"来展示,撤销权限后可能出现一次误显。
- 关闭后访客再发消息是否重新变待处理,本票未实走,不承诺。

## staging 验收记录(2026-10-27)

真 Chrome + 真企业微信:卡点开落在 `mdmobile/chat/<id>` 已登录态(实测卡标题 `Live chat #1` 打开 `Visitor #1`);旧卡 3600s 后点开是官方过期页;在线转人工 → 待处理 + 收卡 + 记账;待处理/进行中窗口内外都按规则发或不发;离开窗口后访客消息触发新卡;原生关闭 → 状态 2、列表显示 Closed、不影响另一会话;操作员在待处理会话发送成功后 pill 由 Pending 变 Active。

2026-10-27 20:32 追加:新模板带 `command` 的真实转人工 payload 经抓包验证为 `{"event":"chat.genericbot_chat_command_transfer","is_online":true,"command":"stopchat",...}`;企业微信同一张卡点开落在 `Visitor #1`;部门 `dep_offline=1` 时真实派发 stopchat 事件(`is_online=false`)不写"已发卡"记录,恢复后访客消息仍会收到卡。
