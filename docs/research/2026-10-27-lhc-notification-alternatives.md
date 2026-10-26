# Telegram 之外的 LHC 备用通知与回复渠道

- 票:[研究:Telegram 之外的 LHC 备用通知与回复渠道](https://github.com/AssassinFlash/metal-divider/issues/135)
- 父地图:[Wayfinder map:自建 WordPress AI 客服(Knocket 功能对标)](https://github.com/AssassinFlash/metal-divider/issues/86)(本文件不改地图)
- 日期:2026-10-27
- 分支:`research/lhc-notification-alternatives`(自基线 `941b496` 拉出,不合并、不开 PR)
- 工作树:`.scratch/research-135-lhc-notify`(本票唯一临时工作副本)
- 用途:本票研究文档写作与分支推送
- 负责人:本票研究会话(GitHub assignee AssassinFlash)
- 回收条件:分支推送成功且票关闭后删除该工作树;不删除分支,不删除本文件

任务边界:Telegram 已被业主裁定排除(2026-10-27,#86 通知渠道裁定);WhatsApp(#133)与微信/企业微信(#134)是前两路,本票只找**前两路被账号/费用/审核阻塞时的兜底**。目标不变:转人工后真人及时收到通知 → 打开完整 LHC 对话 → 回复送达访客 → 历史持续归 LHC。不扩 UI、不扩多渠道平台、不扩 Woo;不改生产、不注册账号、不发消息——研究期间未创建任何账号、未发送任何消息、未改任何环境。

## 证据等级

沿用 #87/#132 的分级:实测 / 官方宣称 / 合理推断 / 无法确认。

源码证据基准:

- Live Helper Chat core:tag `4.97v`,commit `016f486`(与 #132 同基准),https://github.com/LiveHelperChat/livehelperchat — 下文 file:line 均指 `lhc_web/` 内路径在该 commit 的行号。
- ntfy:仓库 `binwiederhier/ntfy`,license Apache-2.0(GitHub API),main 分支 commit `87d20d5`(2026-10-26);docs.ntfy.sh 各页 2026-10-27 抓取。
- 其余见 §9 来源。

## 结论速览

1. **所有 HTTP 类候选共享同一个"零代码桥"**:LHC 后台的 Rest API 触发动作(GUI 原生有 Method 下拉、Headers 标签、Body 标签,`design/defaulttheme/tpl/lhgenericbot/form_rest_api.tpl.php:108-171`)发出模板化 JSON POST,由 `chat.chat_started` / `chat.addmsguser` / `chat.messages_added_passive` 等事件驱动(`lib/core/lhchat/lhchateventdispatcher.php:32-37,49-62`)。也就是说,**候选之间的差异全部在渠道侧**(账号、费用、常驻依赖、第三方副本、业主是否常用),LHC 侧永远是纯配置。
2. **主备选 = ntfy 自托管在既有 VPS `linker-services` 上**(Apache-2.0,活跃维护;单二进制 + systemd 单元,~1 个常驻进程;iOS 经 ntfy.sh 只回传消息 ID 不回传正文;Android WebSocket 直连自托管服务器)。通知-only:点通知里的 `click` URL 深链直达 LHC 后台该聊天,回复在后台完成,历史天然落 `lh_chat`/`lh_msg`。零新增支出。
3. **更省的次选 = ntfy.sh 公共实例**(免注册,topic 即密码;免费档每访客日 250 条消息、60 请求突发后 5 秒 1 条补满——转人工通知这种低频场景绰绰有余)。代价:消息正文与缓存(默认 12h)在第三方服务器上。
4. **LHC 原生邮件 + operator Web Push 不是"备选"而是"已核底线"**(#132 已核实,纯配置、零常驻、零第三方推送依赖),任何兜底方案都必须先把它配好,再叠加手机推送。iOS 上的 Web Push 仅对"添加到主屏幕"的 Web App 生效(iOS 16.4+,WebKit 官方),业主若用 iPhone,Web Push 需多一步安装动作——这正是 ntfy 兜底的价值。
5. **不推荐**:Discord/Slack incoming webhook(技术上零代码可行,但要求业主新开账号并活在 Discord/Slack 客户端里,与"常用工具=WhatsApp/微信"不符,消息正文过第三方);Matrix(Synapse AGPL-3.0 + Python/Twisted 常驻,对 1 vCPU VPS 偏重,官方 homeserver 仓库已归档);Mattermost(源码已改 AGPL/商业双许可,整套团队聊天服务器过重);Rocket.Chat(核心 MIT 但 `ee/` 目录商业许可,同样过重);Gotify(自托管可行,但 click-URL 通知点击仅官方 Android 客户端支持,iOS 只有第三方助手,iOS 体验弱于 ntfy)。
6. **双向接管(在通知渠道里直接回复访客)在本票所有候选中均不存在现成件**。唯一实现过它的是官方 Telegram 扩展(无 LICENSE,#132 已否)且 Telegram 已被排除。正确路径是通知-only + 回复在 LHC 后台——这也是业主目标的字面要求("打开完整聊天、回复访客、历史归 LHC")。
7. **不需要写任何连接器代码**。现成件(LHC 触发器 + ntfy HTTP API)已完整覆盖目标;最小连接器范围(§7)仅在"未来想在渠道内直接回复"时才成立,本票不开发。

## 1. 与 #132 的关系:哪些不用重查

#132(`docs/research/2026-10-27-lhc-knocket-handoff-parity.md`,分支 `research/lhc-knocket-handoff-parity`)已核实并可直接复用:

- LHC 部门邮件通知:模板 4/7/9,正文含最近 150 条消息全文 + `{url_accept}` 接管链接;触发点与配置点(`lhdepartment/form.tpl.php:356-378` 等)。
- operator Web Push(VAPID):payload 含最近 4 条消息预览 + 直达该聊天的后台深链;`notifications_settings_op` 配置 + 坐席浏览器订阅一次。
- 事件 webhooks 机制与 `settings.ini 'webhooks'.enabled` 默认 false。
- 官方 Telegram 扩展功能等价 Knocket Full 档但**无 LICENSE 文件**——不采用。

本票新增的变化:**业主 2026-10-27 裁定 Telegram 整体排除**,#132 推荐三件套中的第③项(core webhook → `api.telegram.org sendMessage`)随之作废,其职能由本票的兜底渠道接管。其余两项(邮件、Web Push)不受影响。

## 2. 统一桥:LHC → 任意 HTTP 渠道,纯配置(本票源码实测)

LHC 后台 System → Webhooks / bot 触发器里的 Rest API 动作,源码级确认:

| 能力 | 源码证据(016f486) | 说明 |
| --- | --- | --- |
| 事件驱动 | `lib/core/lhchat/lhchateventdispatcher.php:32-37`(五个 chat 事件注册)、`:49-62`(`webhooks.enabled` 门控,默认 false) | 开 `settings.ini` 后,任意 chat 事件可触发 |
| HTTP 方法 | `lib/core/lhgenericbot/actionTypes/lhgenericbotactionrestapi.php:1183-1184`(PUT/DELETE)、`:1195-1196`(POST),默认 GET | ntfy/Discord/Slack 只需 POST;Matrix 的 `PUT /rooms/{roomId}/send/...` 技术上也可 |
| 自定义请求头 | `lhgenericbotactionrestapi.php:1189-1193`(`methodSettings['header']` 数组逐条拼进 `CURLOPT_HTTPHEADER`,支持 `{{...}}` 模板变量) | Gotify `X-Gotify-Key`、ntfy `Title`/`Click`、Matrix `Authorization: Bearer` 都能配 |
| RAW JSON body | `lhgenericbotactionrestapi.php:1300-1332`(模板化 `$bodyPOST`,`Content-Type: application/json` 自动附加) | 与 #118 实测的 WP 端点调用同机制 |
| GUI 可配置 | `design/defaulttheme/tpl/lhgenericbot/form_rest_api.tpl.php:108-109`(Method 下拉)、`:152-153`(Headers/Body 标签页)、`:201`("You can always just define custom header...") | 全部在后台表单里,无代码 |
| SSRF 防护 | `lhgenericbotactionrestapi.php:1500-1560`(私网/保留 IP 与 DNS rebinding 拦截,返回 `Blocked: private/reserved IP`) | 目标渠道必须是**公网** HTTPS 地址——自托管 ntfy 必须挂公网域名+TLS,不能写内网 IP |
| 超时 | `lhgenericbotactionrestapi.php:1172`(默认 10s,可配 1-360s) | 出站失败显性暴露在触发器执行日志里,不静默降级 |

推论(合理推断):Discord webhook(`{"content":...}`)、Slack webhook(`{"text":...}`)、ntfy(JSON 发布 `{"topic","message","click"}`)、Gotify(`POST /message?token=...` + `extras`)全部能被这一个动作覆盖,零胶水代码。

## 3. 候选逐项

### 3.1 LHC 原生邮件 + Web Push(底线,复用 #132,零新依赖)

| 维度 | 邮件 | operator Web Push(VAPID) |
| --- | --- | --- |
| 及时性 | 取决于手机邮件客户端的收信推送(通常秒-分钟) | 浏览器级推送,秒级;**iOS 上仅对"添加到主屏幕"的 Web App 生效**(iOS 16.4+,WebKit 官方博客:https://webkit.org/blog/13966/webkit-features-in-safari-16-4/) |
| 通知内容 | 最近 150 条消息全文 + accept 链接 | 最近 4 条消息预览 + 后台深链 |
| 回复路径 | 点链接进 LHC 后台回复 | 点通知直达聊天行内回复 |
| 新增依赖 | 无(SMTP 出站即发) | 无(`minishlink/web-push` MIT 已在 core) |
| 第三方副本 | 邮件经 Hostinger 出站邮件链路 | 推送经 Mozilla/Google 推送端点(payload 加密) |
| 历史 | 全在 `lh_chat`/`lh_msg` | 同左 |

结论:两者都是纯配置、零账号、零费用,#132 已给出具体配置点;任何兜底叠加之前先把这两个配好。

### 3.2 ntfy(主备选)

**是什么**:HTTP pub-sub 通知服务,发消息 = 一个 PUT/POST;手机装官方 App(Google Play / App Store,免费,见 https://ntfy.sh 首页)订阅 topic 即收推送。许可证 Apache-2.0(GitHub `binwiederhier/ntfy`,main `87d20d5`,2026-10-26 仍在推送代码,35k stars)。

**LHC 接入(纯配置)**:Rest API 动作 POST 到 ntfy 服务器根路径,JSON 发布格式 `{"topic":"<随机长topic>","title":"新询盘转人工 #{{chat_id}}","message":"<访客消息预览>","click":"<LHC 后台该聊天深链>"}`(JSON 发布与 `click` 字段:docs.ntfy.sh/publish/#publish-as-json;`click` 亦可作 `X-Click` 头)。通知点击 → 深链直达该聊天 → 后台回复。**通知-only**,这正是目标链路。

**两种部署**:

| 维度 | 自托管(VPS `linker-services`) | ntfy.sh 公共实例 |
| --- | --- | --- |
| 费用 | 0(既有 VPS,不新购) | 0(免注册免登录) |
| 常驻依赖 | 1 个 Go 单二进制进程 + systemd 单元(install 文档提供 deb/二进制与 systemd 安装步骤,当前版本 v2.34.0:https://docs.ntfy.sh/install/) | 无 |
| 限额 | 全部可配(默认 60 请求突发 + 5 秒 1 条补满) | **每日 250 条消息/访客**,附件 2MB、每日带宽 200MB(官方仓库 `docs/publish.md` Limitations 表,`87d20d5`)——转人工通知低频场景足够 |
| TLS | 需公网域名 + TLS(LHC SSRF 防护禁内网;`behind-proxy` 反代模式见 config 文档) | 自带 |
| 认证 | 可开 `auth-default-access: deny-all` + access token(config 文档) | topic 名即密码(官方原话,发布文档) |
| 第三方副本 | 消息正文只存本方服务器;iOS 的 poll_request 只含消息 ID(`X-Poll-ID` 头)+ topic URL 的 SHA256 校验和(config 文档 "iOS instant notifications" 节) | 消息正文与 12h 缓存(默认)在 ntfy.sh 服务器上 |

**iOS 细节(官方文档实测原文)**:iOS 后台限制使自托管服务器无法脱离中央推送;设 `upstream-base-url: "https://ntfy.sh"` 后,自托管服务器向 ntfy.sh 发 poll_request(**只含消息 ID 与 topic URL 的 SHA256,不含正文**),ntfy.sh 经 Firebase→APNs 唤醒 iOS App,iOS 再回源自托管服务器取正文。不设则投递可能延迟数小时(手机活跃时约 20-30 分钟)。Android 无此问题,App 与自托管服务器保持 WebSocket 直连。

**隐私立场(官方宣称)**:ntfy.sh 隐私政策(https://docs.ntfy.sh/privacy/)写明"永不变现或出售你的信息",并建议敏感消息自托管。

**维护风险**:单一维护者(binwiederhier)主导。属常见单人开源项目风险,与 Gotify 同级;ntfy 社区规模(35k stars、活跃文档与付费托管业务)显著更大。

### 3.3 Gotify(可行但 iOS 弱)

- 许可证:MIT(LICENSE 文件实文核实,© 2018 jmattheis;GitHub API 标 NOASSERTION 系识别偏差)。
- 形态:Go 单二进制常驻服务 + 官方 Android App;iOS **无官方客户端**,社区方案 `iGotify-Notification-Assistent`(需另跑辅助服务,`gotify/contrib` README 收录)。
- 通知点击打开 URL:`extras.client::notification.click.url`,官方文档明确标注支持矩阵仅 `gotify/android v2.0.10+`(https://gotify.net/docs/msgextras)——iOS 缺位。
- 消息在自托管服务器上持久保存(收件箱式),数据不出本方;但对我们"历史归 LHC"的要求而言,Gotify 侧的持久副本是冗余而非资产。
- 结论:若业主只用 Android,Gotify 与 ntfy 打平;涉及 iOS 则全面弱于 ntfy。**不作主备选**。

### 3.4 Discord / Slack incoming webhook(可行但不匹配业主工具)

- Discord:incoming webhook 免 bot 免鉴权,POST `{"content":...}`(≤2000 字符);创建需服务器 `MANAGE_WEBHOOKS` 权限(https://discord.com/developers/docs/resources/webhook)。
- Slack:建 app → 启用 incoming webhooks → 拿 `hooks.slack.com` URL → POST `{"text":...}`(https://api.slack.com/messaging/webhooks);免费版 $0 永久、消息历史 90 天(https://slack.com/pricing,通知-only 场景历史限制无实际影响)。
- 共同问题:①业主要**新开账号并常驻对应客户端**,与其常用工具(WhatsApp/微信)不匹配,通知容易沉底;②访客消息正文经并存储于第三方(Discord Inc. / Slack Technologies),内容副本风险高于 ntfy 自托管;③同样是通知-only,相比 ntfy 无任何能力增量。
- 结论:**末位备选**,仅当"业主愿意装并常看 Discord/Slack"这一前提成立时才考虑。

### 3.5 Matrix(过重,不推荐)

- 客户端-服务器 API 发消息:`PUT /_matrix/client/v3/rooms/{roomId}/send/{eventType}/{txnId}` + access token(https://spec.matrix.org/latest/client-server-api/)。LHC 的 PUT + 自定义头技术上能配,但 room_id/txn_id 的取值管理让它不再是"纯配置"。
- 服务器:参考实现 Synapse(`matrix-org/synapse`)已归档(archived:true);活跃版 `element-hq/synapse`(AGPL-3.0,Python/Twisted + Rust,v1.164.0rc3,2026-10-23),对 1 vCPU / 3.8GiB 且已跑 de-linker Genie 的 VPS 是显著负担;轻量替代(conduwuit)2026-05 已归档,Conduit 主仓库下落不明。
- 客户端 Element 全家 AGPL-3.0(自由使用无碍);用公共 matrix.org homeserver 则等于第三方托管全部消息副本。
- 结论:**不推荐**。为"通知-only"引入一整套联邦聊天基础设施,复杂度与收益完全不成比例。

### 3.6 Mattermost / Rocket.Chat(过重,不推荐)

- Mattermost:源码许可已改为 "AGPL v3.0 或商业许可" 二选一(仓库 `LICENSE.txt` 原文;编译产物 MIT),整套团队聊天服务器(Postgres/MySQL + 常驻)对通知场景过重。
- Rocket.Chat:核心 MIT,但 `ee/` 与 `apps/meteor/ee/` 目录商业许可(仓库 LICENSE 原文);同样是完整聊天平台,资源与维护面远超所需。
- 结论:**不推荐**,理由与 Matrix 相同——它们解决的是"团队协作",不是"个人及时收到提醒"。

## 4. 对比总表

| 方案 | 只通知/能双向 | 业主账号要求 | 费用 | 常驻依赖 | 正文过第三方 | 许可证 | 维护 | 评价 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| LHC 邮件(#132 已核) | 通知(+后台回复) | 无 | 0 | 无 | 邮件链路(Hostinger) | core Apache-2.0 | core | 底线,必配 |
| LHC Web Push(#132 已核) | 通知(+后台回复) | 无 | 0 | 无 | 推送端点(payload 加密) | core Apache-2.0 | core | 底线,必配;iOS 需加主屏 |
| **ntfy 自托管(VPS)** | 通知(+后台回复) | 无(免注册) | 0 | VPS 1 个 systemd 进程 | 否(iOS 仅消息 ID 经 ntfy.sh) | Apache-2.0 | 活跃(单人主导) | **主备选** |
| ntfy.sh 公共实例 | 通知(+后台回复) | 无 | 0(付费档 $5-20/月) | 无 | 是(正文+12h 缓存) | Apache-2.0 | 同上 | 次选,零部署 |
| Gotify 自托管 | 通知(+后台回复) | 无 | 0 | VPS 1 个进程 | 否 | MIT | 活跃 | iOS 客户端弱 |
| Discord webhook | 通知-only | Discord 账号+服务器 | 0 | 无 | 是 | — | 平台 | 末位,不匹配常用工具 |
| Slack webhook | 通知-only | Slack 账号+workspace | 0(免费版 90 天历史) | 无 | 是 | — | 平台 | 末位,同上 |
| Matrix(自托管 Synapse) | 可双向但需自研 bot | Matrix 账号 | 0 | Python 常驻(重) | 否(自托管) | AGPL-3.0/Synapse | 参考实现已归档 | 过重,不推荐 |
| Mattermost | 可双向但需 bot 集成 | 账号 | 0(社区功能受限) | 常驻(重) | 否(自托管) | AGPL/商业双许可 | 活跃 | 过重,不推荐 |
| Rocket.Chat | 可双向但需 bot 集成 | 账号 | 社区版 0 | 常驻(重) | 否(自托管) | 核心 MIT + ee/ 商业 | 活跃 | 过重,不推荐 |

注:"能看历史"一列并入"历史归 LHC"统一表述——**所有推荐路径下,聊天历史的唯一主记录都是 LHC 的 `lh_chat`/`lh_msg`**,通知渠道里看到的至多是单条预览或冗余副本(风险见 §6)。

## 5. 主备选与最短实施路径

**主备选:ntfy 自托管于 `linker-services` VPS(+ iOS `upstream-base-url` 经 ntfy.sh 唤醒,只回传消息 ID)。**

最短实施路径(全部配置,零代码;给实施票,本票未动环境):

1. **底线先行**:按 #132 §4 配好 LHC 部门邮件通知 + operator Web Push(评审站 HTTPS + 业主真 Chrome)。
2. **VPS 侧**:安装 ntfy deb(官方 install 文档,当前 v2.34.0),`/etc/ntfy/server.yml` 设 `base-url`(公网子域,如 ntfy.metal-divider.com)、`behind-proxy: true` + TLS 反代、`upstream-base-url: "https://ntfy.sh"`(iOS 唤醒)、可选 `auth-default-access: deny-all` + 为 LHC 发一个 publish 权限 token。systemd 单元随包提供。按 AGENTS.md §0 服务纪律:装前核版本、装后读回实际版本与监听。
3. **业主手机**:官方 ntfy App(Google Play / App Store,免费)订阅 `https://<域名>/<随机长 topic>`;topic 名即密码,选不可猜的(官方发布文档原话)。
4. **LHC 侧**:`settings.ini` 开 `webhooks.enabled`;建触发器:事件 `chat.chat_started` + `chat.addmsguser`(+ `chat.messages_added_passive`)→ Rest API 动作 POST `<ntfy 域名>/`,Header 视认证方案加 token,Body(RAW JSON)模板拼 `topic/title(含 chat id)/message(访客消息预览,注意 ntfy 单条 4096 字节上限)/click(LHC 后台该聊天深链)`。
5. **验收**(评审站,真 Chrome 铁律;每项一次即停):访客转人工 → 业主手机 ntfy 弹通知 → 点通知深链直达该聊天 → 后台回复 → 访客挂件收到 → 次日后台历史完整可查。失败显性暴露:Rest API 触发器执行日志(`lh_generic_bot` 触发器日志)与 ntfy 服务器日志可对账。

**若实施时 VPS 不可用或临时不愿加进程**:退一档用 ntfy.sh 公共实例,同一 LHC 触发器只改 URL(体面降级,不动 LHC 配置结构);日 250 条限额对转人工通知绰绰有余。隐私取舍见 §6。

**主备选之外不选谁、为什么**(对应"不推荐"义务):Discord/Slack(账号+常用工具不匹配+正文过第三方)、Matrix(基础设施过重、参考服务器归档)、Mattermost/Rocket.Chat(团队聊天平台,重量与许可证复杂度均超标)、Gotify(iOS 客户端缺位)。灰色账号协议(如 WhatsApp 非官方网关)不属于本票范围,且按"不推荐灰色账号协议为生产默认"纪律在任何情况下不作生产默认——WhatsApp 正路归 #133。

## 6. 第三方通知副本风险(单列)

原则:**自有数据主记录永远在 LHC**(`lh_chat`/`lh_msg`,本站数据库);任何通知渠道里的内容都只是派生副本,渠道丢失/封禁不影响主记录。

| 方案 | 第三方拿到的内容 | 缓解 |
| --- | --- | --- |
| ntfy 自托管 | iOS 唤醒链路:ntfy.sh 只见消息 ID + topic URL 的 SHA256;Apple/Google 的 APNs/FCM 只见加密推送信封 | 正文留在本方;topic 名不可猜即访问控制 |
| ntfy.sh 公共实例 | **正文全文 + 12h 缓存**(默认) | 触发器模板里可只发"新转人工 #id"不含正文,正文回 LHC 看;或直接上自托管 |
| Discord / Slack | 正文全文,按其平台数据政策存储 | 不选为主备选;若用,模板只发 id+链接 |
| Gotify 自托管 | 无(全在本方) | — |
| LHC 邮件 | 正文全文经出站邮件链路(Hostinger) | 复用询盘链既有邮件通道,与 md-inquiry 通知同等级风险 |
| Matrix 公共 homeserver / Mattermost Cloud 等 | 全部消息 | 不采用 |

另:24h 服务窗口类限制(如 WhatsApp 客户服务窗口)只存在于 IM 渠道(#133 范围);本票候选(ntfy/Gotify/Discord/Slack/Web Push/邮件)均无此类窗口。ntfy 的"12h"是缓存保留参数(可配),不是服务窗口。

## 7. 最小连接器范围(仅记录,不开发)

现成件已覆盖本票目标,**无需开发任何连接器**。仅当未来提出"在通知渠道里直接回复访客"(双向接管)时,才存在最小连接器,范围如下(供未来票评估,非本票交付):

- 形态:VPS 上一个常驻小服务,持有渠道凭证;ntfy 无下行 API,**不适合**双向;Discord/Slack bot API 可行但要求业主常驻对应客户端;技术上最顺的双向载体其实是 #134 的企业微信自建应用回调或 #133 的 WhatsApp Business API——即双向能力应并入前两路票,不另起第三条双向通道。
- 功能面:收到渠道上行消息 → 调 LHC REST API 以坐席身份写入对应 chat;消息→chat 映射表;渠道凭证与 LHC API 凭证管理;失败重试与显性报错。
- 明确不做:多渠道抽象层、独立收件箱 UI、历史第二存储(历史只在 LHC)。

## 8. 证据局限

- LHC Rest API 动作的 PUT/自定义头路径未实际发请求验证(票面禁止发消息);POST+JSON body 机制与 #118 实测同源,风险低。ntfy JSON 发布格式的 `click` 字段未实测(文档官方宣称级)。
- ntfy 自托管在 `linker-services` 上的实际内存占用未实测(Go 单二进制量级为几十 MB,合理推断;实施票读回)。
- ntfy.sh 免费档 250 条/日为官方仓库文档数字,但**公平使用的执行细则**(如按源 IP 限速的共享出口误伤,社区有 429 案例)属运营行为,无 SLA;生产依赖公共实例时需接受此不确定性。
- 业主手机 OS(Android/iPhone)未确认——iOS 与 Android 的最优路径不同(Web Push 需加主屏 vs ntfy 直连),实施票先问一句或两种都验。
- ntfy 隐私政策"永不出售信息"为官方宣称,无第三方审计。
- Discord/Slack webhook 的企业/审核门槛(如新账号手机验证)未逐一采证——因两方案本就不作主备选,不影响结论。
- Element 客户端、Synapse 的资源占用数字未实测;Matrix 出局基于架构重量与参考实现归档的定性证据。

## 9. 来源

一手(2026-10-27 抓取/检出):

- Live Helper Chat core tag 4.97v,commit 016f486:
  - `lhc_web/lib/core/lhgenericbot/actionTypes/lhgenericbotactionrestapi.php:1170-1196,1189-1193,1300-1332,1500-1560`
  - `lhc_web/design/defaulttheme/tpl/lhgenericbot/form_rest_api.tpl.php:108-109,152-153,201`
  - `lhc_web/lib/core/lhchat/lhchateventdispatcher.php:32-37,49-62`
- ntfy:
  - 仓库 https://github.com/binwiederhier/ntfy(Apache-2.0;main `87d20d5`,2026-10-26)
  - 发布与限额:https://docs.ntfy.sh/publish/ 及仓库 `docs/publish.md` §Limitations(`87d20d5`:ntfy.sh 日 250 条、4096 字节、60 突发+5s 补满、附件 2MB/20MB、带宽 200MB/日)
  - 自托管与 iOS 链路:https://docs.ntfy.sh/config/ §"iOS instant notifications"(poll_request 仅消息 ID + SHA256;不设则可延迟数小时)
  - 安装:https://docs.ntfy.sh/install/(v2.34.0 二进制/deb/systemd)
  - 隐私:https://docs.ntfy.sh/privacy/
  - 定价页:https://ntfy.sh(免费免注册;付费 $5/$10/$20 月档;官方 Android/iOS App)
- Gotify:
  - 仓库 https://github.com/gotify/server(LICENSE = MIT,© 2018 jmattheis;README:REST 发送 + WebSocket 接收)
  - 消息 extras 与客户端支持矩阵:https://gotify.net/docs/msgextras(`client::notification.click.url` 仅 gotify/android v2.0.10+)
  - iOS 社区方案:https://github.com/gotify/contrib(iGotify-Notification-Assistent)
- Discord:https://discord.com/developers/docs/resources/webhook(incoming webhook 类型、`{"content"}` ≤2000、`MANAGE_WEBHOOKS`)
- Slack:https://api.slack.com/messaging/webhooks(app→webhook URL→`{"text"}`);https://slack.com/pricing(免费版 $0、90 天历史)
- Matrix:https://spec.matrix.org/latest/client-server-api/(`PUT /_matrix/client/v3/rooms/{roomId}/send/{eventType}/{txnId}`);仓库状态 `matrix-org/synapse`(archived)、`element-hq/synapse`(AGPL-3.0,v1.164.0rc3)、`girlbossceo/conduwuit`(archived)、Element 客户端(AGPL-3.0)
- Mattermost:仓库 `LICENSE.txt`(源码 AGPL-3.0 或商业许可;编译产物 MIT)
- Rocket.Chat:仓库 `LICENSE`(核心 MIT;`ee/`、`apps/meteor/ee/` 商业许可)
- iOS Web Push 约束:https://webkit.org/blog/13966/webkit-features-in-safari-16-4/(Home Screen Web App 才有 Push,16.4+)

复用既有研究(不重复采证):

- #132 `docs/research/2026-10-27-lhc-knocket-handoff-parity.md`(分支 research/lhc-knocket-handoff-parity):LHC 邮件/Web Push 触发点与配置点、Telegram 扩展无 LICENSE、Knocket 机制对照
- #86 父地图:业主 2026-10-27 通知渠道裁定(Telegram 排除;WhatsApp 第一、微信第二;其他方式与小型开源连接器开放);VPS `linker-services` 规格(Ubuntu 24.04,1 vCPU/3.8GiB,无 Docker,systemd)
