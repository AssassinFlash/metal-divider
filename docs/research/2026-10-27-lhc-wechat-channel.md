# 研究：微信/企业微信作为 LHC 人工客服通道（#134）

- 日期：2026-10-27
- 父地图：#86（自建 WordPress AI 客服，Knocket 功能对标）
- 范围：LHC「转人工通知 → 完整历史 → 回复网站访客」用微信/企业微信实现的合规最小路径。不扩 UI、不扩多渠道平台、不扩 Woo 功能。
- 方法：只读研究。未注册企业、未扫码、未发消息、未改生产。LHC 源码取 tag `4.97v`（commit `016f486`，与 #132 核实基线一致）本地逐行核对；外部事实取官方文档/官方帮助中心/GitHub 一手仓库。
- 前置裁定（#86，业主 2026-10-27）：Telegram 排除；优先 WhatsApp，其次微信/企业微信；其他方式与开源/小型连接器开放。本票只管微信侧，WhatsApp 归兄弟票。

## 0. 结论速览

| # | 路径 | 只通知 | 看完整历史 | 双向回复 | 费用/认证 | 结论 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | **企业微信自建应用「应用消息」+ LHC 深链** | ✅ | ✅（点开 LHC） | ✅（在 LHC 里回） | 免费、无需认证 | **推荐（最小可行）** |
| 2 | 同上 + 开启「接收消息」回调 + 小连接器 | ✅ | ✅ | ✅（直接在企微里回，转发回访客） | 免费、无需认证；需公网回调端 | **可选升级（双向档）** |
| 3 | 企业微信「智能机器人」（API 模式，回调或长连接） | ✅ | ✅（深链同 1） | ✅（回复机器人，连接器转发） | 未见认证要求 | 备选（API 较新，见 §3.4 局限） |
| 4 | 企业微信群机器人 webhook | ✅ | ❌（仅链接跳转） | ❌ | 免费 | 仅当"通知到群"需求出现才用 |
| 5 | 微信客服（企业微信应用） | — | — | — | 需企业微信认证（审核费 ¥300/次） | **不适用**：面向访客微信侧，主数据在腾讯 |
| 6 | 微信公众号客服消息接口 | — | — | — | 需认证账号；48h/5 条额度 | **不适用**：访客不在公众号里 |
| 7 | Wechaty / 个人微信协议机器人 | ✅ | — | ✅ | puppet 多为第三方付费 | **不推荐生产默认**：违反《微信个人账号使用规范》1.2.6，封号风险 |
| 8 | Server酱 / WxPusher / wecomchan 现成件 | ✅ | ❌ | ❌ | Server酱免费 5 条/天；内容过第三方 | 仅作免开发兜底，数据面劣于路径 1 |

一句话：**微信侧的合规答案是企业微信自建应用。通知档零开发量可纯靠 LHC webhook + 一段几十行的转发端；双向档加一个小连接器（回调 → LHC `addmsgadmin`）。历史主记录始终在 LHC，企微侧只是派生副本。**

## 1. 目标链路回顾与边界

链路（#86/#132 已定）：访客在网站 LHC 挂件聊天 → AI 转人工 → **业主在常用工具收到通知 → 打开完整聊天 → 回复 → 访客在挂件收到 → 历史持续归 LHC**。访客侧不换渠道：不管业主通知走哪里，访客始终在网站挂件里。因此：

- "外链当收件箱"（类似 Knocket 的 WhatsApp 图标只是跳转外站）不满足目标——本票所有路径的通知里都带**直达该聊天的深链**，回复落回 LHC。
- 第三方（腾讯/Server酱）看到的都是**通知副本**；LHC 的 `lh_chat`/`lh_msg` 是唯一主记录（#118 已实测可查）。副本风险见 §7。

## 2. LHC 侧接缝（源码核实，tag 4.97v @ 016f486）

### 2.1 出站：webhook 事件

- 开关在 `settings.ini` `webhooks.enabled`，默认 false（`lhc_web/settings/settings.ini.default.php:96`；读取与短路逻辑 `lhc_web/lib/core/lhchat/lhchateventdispatcher.php:49-58`，#132 已核）。
- 后台 webhook 配置页提供的事件徽章（`lhc_web/design/defaulttheme/tpl/lhwebhooks/dispatch.tpl.php:12-13`）：`chat.addmsguser`（访客新消息）、`chat.close`、`chat.chat_started`、`chat.web_add_msg_admin`（坐席新消息）、`mail.conversation_started` 等。
- 转发 payload 含 chat 与 msg 对象（`dispatch.tpl.php:34-37`），足够拼出"转人工 + 最近消息 + chat_id"的通知体。
- 触发面：`erLhcoreClassChatEventDispatcher` 全局监听（`chat.chat_started`、`chat.addmsguser`、`chat.genericbot_chat_command_transfer`、`chat.chat_transfered` 等，`lhchateventdispatcher.php:23-32`）——即 bot 转人工事件本身也有 webhook 通路（#132 §通知机制已核）。

### 2.2 入站：以坐席身份向指定 chat 发消息

- REST 端点 `index.php/restapi/addmsgadmin`：POST `chat_id`+`msg`（可选 `operator_name`、`sender`、`status`），要求 REST 用户有 `lhchat,use` 权限（`lhc_web/modules/lhrestapi/addmsgadmin.php:6`）。
- 关键行为（双向回复不用改 LHC 一行代码的原因）：当 `sender == 'operator'` 且 chat 处于 bot 状态时，发送首条消息会**自动接管**——置 `STATUS_ACTIVE_CHAT`、解锁访客输入框、写 accept 系统消息、`chat.accept`/`chat.data_changed` 事件（`addmsgadmin.php:360-425`）。pending chat 同理（`addmsgadmin.php:296-330`）。
- 消息走正常 `erLhcoreClassModelmsg` 落库并派发 `chat.web_add_msg_admin`，访客挂件经既有同步机制收到——与 #118 实测的后台行内回复同一条数据路径。

### 2.3 深链：一次性自动登录直达后台聊天

- REST 端点 `index.php/restapi/generateautologin`：用后台 `autologin_data.secret_hash` 做 HMAC-SHA256，参数含目标 `r`（路由）、用户 `u/l`、一次性 `nonce`、过期时间 `t`（默认 300s，上限 3600s），返回 `site_admin/user/autologin/<hash>/...` 形式的链接（`lhc_web/modules/lhrestapi/generateautologin.php:57-67` 及全文件）。
- 前置：后台开启 autologin 模块并设置 secret；REST 用户有 `lhuser,userautologin` 权限（`generateautologin.php:20-33`）。
- 用途：企微 textcard 的 `url` 字段放该链接 → 业主手机点开即以坐席身份进入该 chat 的后台界面。安全性由一次性 nonce + 短 TTL 兜底。
- 降级方案：不做 autologin 时，直接链 `site_admin` 聊天页并让业主浏览器保持登录（Knocket "Notification only" 档的等价体验，#132 已定义）。

### 2.4 不需要的东西

- LHC「入站 webhook」（`/webhooks/incoming/{identifier}`，`lhc_web/doc/rest_api/webhook.php`）用于把外部来信建成新 chat（Telegram 扩展就是这么做的）。本场景 chat 已存在、访客在网站侧，**不用**。
- 未发现任何现成的 LHC↔微信/企业微信连接器（GitHub/开发者索引检索无结果；LHC 官方扩展仅 Telegram、Slack 等且属另一条线，#132 已排除无 LICENSE 的 Telegram 扩展）。结论：**连接器需自写，但范围很小**（§6）。

## 3. 企业微信官方机制逐项核实

### 3.1 自建应用「应用消息」（路径 1 的通知通道）

官方文档：发送应用消息 <https://developer.work.weixin.qq.com/document/path/90236>

- 调用：`POST https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=...`，凭证为 corpid+应用 secret（获取 access_token <https://developer.work.weixin.qq.com/document/path/91039>）。
- 消息类型含 text、**textcard**（标题+描述+`url` 字段，官方示例即"领奖通知"带跳转）、markdown、模板卡片等——通知带深链直接可用。
- **频控（官方原文）**：每应用不可超过"账号上限数×200 人次/天"；对同一成员 ≤30 次/分钟、1000 次/小时，超出部分丢弃不下发。本站量级（询盘站单坐席）远碰不到。
- 费用：无按条计费；自建应用不要求企业认证（§3.5）。
- **企业可信 IP**：2022 年官方安全升级公告要求"新创建的自建应用，需将本企业服务器的 IP 地址配置到企业可信IP字段"（公告 <https://work.weixin.qq.com/nl/act/p/32d807ad4c554975>）；可信 IP 不支持网段/通配，每应用上限 120 个（服务商帮助 <https://open.work.weixin.qq.com/help2/pc/21711>）。连接器出口 IP 固定即可（共享主机 HOST_IP 或 VPS 72.62.97.95）。
- 在哪里收消息：装企业微信 App，或成员扫码关注**微信插件（原企业号）后在个人微信里收发**（官方指引 <https://work.weixin.qq.com/wework_admin/index/introGuide_qyh>："邀请成员扫码关注微信插件(原企业号)，即可在微信中接收企业通知和使用企业应用"；帮助 <https://open.work.weixin.qq.com/help2/pc/14797>、<https://open.work.weixin.qq.com/help2/pc/18121>）。即**业主可以不装企微 App、在常用微信里收到通知**（需扫码关注一次）。

### 3.2 自建应用「接收消息」回调（路径 2 的双向通道）

官方文档：接收消息与事件·概述 <https://developer.work.weixin.qq.com/document/path/90238>（最后更新 2024/07/22）；回调配置 <https://developer.work.weixin.qq.com/document/path/90930>

- 官方原文："开启接收消息模式后，**用户在应用里发送的消息会推送给企业后台**"；后台可在响应包里带被动回复消息推回给用户。
- 要求：管理后台该应用"接收消息→设置 API 接收"，填 URL、Token、EncodingAESKey。**URL 支持 http 或 https（官方建议 https）**——不强制 HTTPS 证书，但加解密（AES + 签名）强制。GET echostr 验证 URL 有效性，POST 收业务消息。
- 含义：业主在企微应用会话（或微信插件）里直接打字回复 → 企微 POST 给连接器 → 连接器映射回 chat_id → LHC `addmsgadmin` → 访客挂件收到。**双向闭环成立，且访客完全无感。**
- 注意：被动回复仅覆盖"回复刚收到的消息"；主动追发用应用消息接口即可（同一 chat 上下文由连接器维护）。

### 3.3 群机器人 webhook（原"群机器人"）

官方文档：消息推送配置说明 <https://developer.work.weixin.qq.com/document/path/91770>

- `POST https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...`，八种消息类型（text/markdown/news/template_card 等），markdown 支持链接语法。
- **频控：每个机器人 20 条/分钟**；webhook 地址即凭据，官方明确警告防泄漏。
- **只能发、不能收**（无接收 API）→ 纯通知档，回复必须回 LHC。适合"值班群"场景，本站单业主非必需。

### 3.4 智能机器人（API 模式）——较新的官方双向机制

官方文档：概述 <https://developer.work.weixin.qq.com/document/path/101039>（最后更新 2026/04/09）、接收消息 <https://developer.work.weixin.qq.com/document/path/100719>、被动回复 <https://developer.work.weixin.qq.com/document/path/101031>、主动回复 <https://developer.work.weixin.qq.com/document/path/101138>（最后更新 2026/03/24）、长连接 <https://developer.work.weixin.qq.com/document/path/101463>、使用说明 <https://developer.work.weixin.qq.com/document/path/101468>（最后更新 2026/03/13）

- 机制：管理后台"工作台→智能机器人"创建 API 模式机器人；用户**向机器人发消息/点模板卡片**时，企微推送到回调 URL（或**长连接 WebSocket，免公网回调端**），开发者可被动回复、或用 `response_url` 主动回复（每条 response_url 只能调一次、1 小时有效）。
- 对本场景：业主与机器人单聊=通知+回复双通道；长连接模式连公网回调端都省了（对共享主机部署更友好）。
- 局限（如实记录）：文档未写明开通门槛（是否需要认证、客户端版本要求），API 面世较新（文档 2026 年初才成型）且当前主推 MCP/文档工具集成方向；正文部分页面 JS 渲染，未能逐字核对"接收消息"字段表。作为路径 2 的备选实现，不作为首选。

### 3.5 注册、认证与费用

- 注册企业微信：免费，超级管理员手机号即可开通（微信客服帮助 <https://open.work.weixin.qq.com/help2/pc/17961> 侧面印证"企业使用企业微信即可由超级管理员启用"）。**自建应用+应用消息+回调不要求认证**——未认证企业配置回调时官方仅要求"换成公网 ip 或域名"（自建应用可信域名说明 <https://open.work.weixin.qq.com/help2/pc/21316>）。
- 企业微信认证：审核费 **¥300/次**（企业微信认证介绍 <https://open.work.weixin.qq.com/help2/pc/19729>；费用与年审 <https://open.work.weixin.qq.com/help2/pc/19734>）。**本路径不需要认证，零费用。**
- 认证只在这些地方被卡：微信客服（下）、公众号接口（下）。避开即避费。

## 4. 不适用/不推荐项的证据

### 4.1 微信客服（企业微信的「微信客服」应用）

- 官网 <https://work.weixin.qq.com/kf/>：定位是"在微信内、外各场景接入微信客服……客户无需加好友即可在微信里和客服沟通"，开发文档树（概述 <https://developer.work.weixin.qq.com/document/path/94638>，收发消息 <https://developer.work.weixin.qq.com/document/path/94670>/<https://developer.work.weixin.qq.com/document/path/94677>）。
- 开通条件（官方帮助 <https://open.work.weixin.qq.com/help2/pc/17961> 原文）："企业使用企业微信即可由超级管理员在【管理后台->应用管理->微信客服】中启用微信客服应用进行使用。**企业完成企业微信认证即可正常使用微信客服**。"→ 需认证（¥300）。
- 更根本的不匹配：它是**访客侧渠道**——访客到微信里聊，会话主数据在腾讯，LHC 只能靠回调镜像；这与"访客在网站挂件、LHC 是主记录"的既定架构相反，也违反本票"不扩多渠道"。✗

### 4.2 微信公众号客服消息接口

- 官方文档：客服消息介绍 <https://developers.weixin.qq.com/doc/subscription/guide/product/kf/intro.html>（最后编辑 2026-03-08）、发送客服消息 <https://developers.weixin.qq.com/doc/subscription/api/customer/message/api_sendcustommessage.html>。
- 官方额度：触发动作（用户发消息等）后 48 小时窗口内可发，"用户发送消息"场景**仅 5 条/48h**；接口仅认证账号类型可调用（发送页账号类型表）。
- 不匹配：本场景"用户"得是公众号关注者（业主），额度 5 条撑不起通知流；且与访客无关。✗（外链公众号当收件箱同样不成立。）

### 4.3 Wechaty 与个人微信协议机器人

- 仓库 <https://github.com/wechaty/wechaty>：Apache-2.0（许可证本身合规），仓库 2025-12 仍有推送，但**正式 release 停在 v0.56（2021-01-25）**；README 的 puppet 提供方表里，Web 协议（puppeteer/wechat4u）大范围不可用，iPad/Windows 协议走第三方付费 token（PadLocal/Rock/WorkPro 等）。
- 合规红线（一手）：《微信个人账号使用规范》1.2.6 <https://weixin.qq.com/agreement/personal_account> 明确禁止"通过非腾讯开发、授权的第三方软件、插件、外挂、系统等……使用微信软件及服务或**进行自动化操作**"；《企业微信用户账号使用规范》3.1.3 <https://work.weixin.qq.com/norm> 同样把 RPA 模拟操作定义为外挂。→ 账号封禁风险为平台规则明示，非猜测。
- 结论：**不作为生产默认**。要"个人微信直接收发"的合法面只有微信插件（§3.1，企微应用消息在微信里的官方入口）。

### 4.4 现成件（免开发兜底，均只通知）

| 件 | 证据 | 限制 |
| --- | --- | --- |
| Server酱·Turbo <https://sct.ftqq.com/> | 官方页：免费 5 条/天、50 条/分钟；企业微信应用消息通道"无需安装企业微信客户端，可在微信中直接收到消息" | 免费额度小；**消息内容过第三方服务**；无回复 |
| WxPusher <https://wxpusher.zjiecode.com/docs/> | 官方文档（2026-10-10 更新）：免费 HTTP 推送，支持 URL Scheme/点击打开 | 同上，内容过第三方；无回复 |
| wecomchan（WeCom酱）<https://github.com/easychen/wecomchan> | MIT，2024-07 最后推送 | 就是"自建应用消息"的自建封装——用路径 1 等于直接拥有它，无需中转层 |

这三件能给"零开发通知"，但数据面与额度都劣于路径 1，只记录为兜底，不推荐。

## 5. 推荐路径

**推荐（路径 1，最小可行）**：企业微信自建应用「应用消息」通知档。

1. 注册企业微信（免费、不认证；需业主手机验证码一次——属 #86 允许回到业主的"只有业主持有的凭据"类）。
2. 建自建应用，拿 corpid/secret/业主 userid；把连接器出口 IP 配进企业可信 IP（≤120 个）。
3. LHC：开 `webhooks.enabled`，建 webhook（`chat.chat_started`+`chat.addmsguser`+bot 转人工事件），开 autologin 模块+secret。
4. 小转发端（见 §6 范围）：收 webhook → 调 `generateautologin` 生成深链 → 调应用消息 `textcard`（标题=访客/主题，描述=最近消息摘要，url=深链）发给业主 userid。
5. 业主在微信（微信插件）或企微收到卡片 → 点开即进 LHC 该 chat → 在 LHC 回复 → 访客挂件收到（#118 已实测该回复路径）。历史归 LHC。
   - 不做 autologin 时退化为"Notification only"档（链接到后台聊天页，保持登录）。

**可选升级（路径 2，双向档）**：同应用开启「接收消息」回调 → 连接器收业主回复 → 映射 chat_id → `addmsgadmin`（自动接管 bot chat）→ 访客挂件收到。条件与代价：回调 URL（http 可用、建议 https）、官方加解密（有官方 PHP 示例库）、以及 chat_id↔userid 映射状态（可存连接器侧小型 KV，或约定回复时带编号前缀）。**仅当业主明确"不想切浏览器、要在微信里直接回"时才做**——回复上下文（历史、访客信息）在 LHC 界面里完整得多。

**不推荐/不适用**：微信客服（架构相反+认证费）、公众号客服（额度与主体不符）、Wechaty（封号红线）、Server酱/WxPusher（数据过第三方+额度，仅兜底）。

## 6. 最小连接器范围（不开发，仅界定）

现成件不足（§2.4：无现成 LHC↔企微连接器；§4.4：现成件只通知）。若实施，最小范围为**一个无 UI 的转发端**：

- 通知档：单端点接收 LHC webhook（HTTP POST，chat_id+最近消息），出站调两个企微/LHC 接口（`generateautologin`、`message/send`）。无常驻、无状态——可以是共享主机上的一个 PHP 页或 VPS systemd 小服务。
- 双向档追加：企微回调端点（GET 验证+POST 解密）、chat_id↔userid 映射表（LRU 即可）、出站调 `addmsgadmin`。官方加解密用企微官方 PHP/Python 库。
- 部署位：共享主机（PHP 端点，出站 IP=HOST_IP 加可信 IP）或 VPS `linker-services`（固定 IP 72.62.97.95；#106 已裁定 VPS 上新增 md-* 独立单元+TLS，不碰 de-linker 生产文件）。
- 硬边界（承接 #86/#118）：不做多渠道平台、不做企微侧会话存储（企微消息由腾讯留存属副本，见 §7）、LHC 不装第二数据库源。

## 7. 第三方通知副本风险（单列）

- **腾讯侧副本**：应用消息/回调消息经腾讯服务器转发，腾讯依其服务条款留存（企业微信用户协议 <https://work.weixin.qq.com/eula>）。通知体只放"访客名+最近消息摘要+深链"，**不放询盘业务细节全量**；完整历史只在 LHC。深链是一次性 nonce+短 TTL，泄漏面小。
- **Server酱/WxPusher 类**：内容经第三方私服，等价把客户通信明文交给无关第三方——B2B 询盘含报价敏感信息，**不用**。
- **微信插件注意**：个人微信收企微消息时，微信侧重同样存在腾讯侧副本；与直接用企微 App 相同等级，可接受。
- 业主个人设备若换机/丢失，企微/微信侧副本不可远程擦除范围以腾讯产品能力为准——主记录在 LHC，风险仅为副本，不构成数据主权问题。

## 8. 费用与账号阻塞汇总

| 项 | 数值/状态 | 来源 |
| --- | --- | --- |
| 企业微信注册 | 免费 | §3.5 |
| 自建应用+应用消息+回调 | 免费、**无需认证** | §3.1/§3.2/§3.5 |
| 企业微信认证（不需要） | ¥300/次审核费 | open.work.weixin.qq.com/help2/pc/19729 |
| 应用消息频控 | 同一成员 30 次/分钟、1000 次/小时；账号数×200 人次/天 | developer.work.weixin.qq.com/document/path/90236 |
| 群机器人频控 | 20 条/分钟 | path/91770 |
| 业主侧动作 | 手机验证码注册 1 次 + 扫码关注微信插件 1 次（可不装企微 App） | §3.1/§3.5 |
| 智能机器人门槛 | 文档未写明（局限） | §3.4 |

阻塞级风险：无。唯一回到业主的点=注册验证码/扫码（#86 允许的两类之一）。

## 9. 证据局限

1. 未实走：未注册企业、未发消息，所有企微接口行为（textcard 展示、微信插件收消息、回调到达格式）以官方文档为准，未实测。
2. 智能机器人"接收消息/事件"字段表页面 JS 渲染，未逐字核对；其开通门槛（认证/客户端版本）无官方明文，实施前需在管理后台确认入口。
3. "未认证企业可完整使用自建应用+应用消息"由帮助页拼合推断（help2 21316 提及未认证企业的回调限制仅是内网 IP），未用真实未认证企业验证过发信链路。
4. LHC `generateautologin` 的 autologin 模块配置项后台位置未实测（代码已核）；webhook payload 的实际 JSON 形状未在本实例触发过（事件表与字段映射来自模板与 #132）。
5. 微信插件"在微信里收企微应用消息"的当前可用性以官方指引页为准，2026 年是否有功能收缩未复核发布日志。
6. Wechaty 第三方 puppet（PadLocal 等）的现价未查——因结论是不采用，未深入。

## 10. 来源清单

**LHC 源码**（tag `4.97v`，commit `016f486`，github.com/LiveHelperChat/livehelperchat）：
- `lhc_web/modules/lhrestapi/addmsgadmin.php`（REST 坐席发消息；:6 权限、:360-425 自动接管 bot chat）
- `lhc_web/modules/lhrestapi/generateautologin.php`（一次性自动登录深链；:57-67 nonce/TTL）
- `lhc_web/design/defaulttheme/tpl/lhwebhooks/dispatch.tpl.php`（:12-13 事件表，:34-37 payload）
- `lhc_web/lib/core/lhchat/lhchateventdispatcher.php`（:23-32 事件监听，:49-58 webhooks 开关）
- `lhc_web/settings/settings.ini.default.php:96`（webhooks 配置节）
- `lhc_web/doc/rest_api/webhook.php`（入站 webhook，本票未采用）

**企业微信官方**：
- 发送应用消息 path/90236；获取 access_token path/91039；接收消息与事件 path/90238；回调配置 path/90930；群机器人 path/91770；智能机器人 path/101039、100719、101031、101138、101463、101468；微信客服 path/94638、94670、94677、work.weixin.qq.com/kf/
- 帮助/公告：可信 IP 公告 work.weixin.qq.com/nl/act/p/32d807ad4c554975；可信 IP 说明 open.work.weixin.qq.com/help2/pc/21711；可信域名与未认证回调 open.work.weixin.qq.com/help2/pc/21316；微信客服开通 open.work.weixin.qq.com/help2/pc/17961；认证介绍/费用 open.work.weixin.qq.com/help2/pc/19729、19734；微信插件 work.weixin.qq.com/wework_admin/index/introGuide_qyh、open.work.weixin.qq.com/help2/pc/14797、18121；用户账号使用规范 work.weixin.qq.com/norm

**微信官方**：
- 公众号客服消息 developers.weixin.qq.com/doc/subscription/guide/product/kf/intro.html、…/api/customer/message/api_sendcustommessage.html；个人账号使用规范 weixin.qq.com/agreement/personal_account

**开源仓库**：
- wechaty/wechaty（Apache-2.0，release v0.56 @2021-01-25，pushed 2025-12-21）
- easychen/wecomchan（MIT，pushed 2024-07-30）

**现成件服务页**：sct.ftqq.com（Server酱，免费 5 条/天）；wxpusher.zjiecode.com/docs/
