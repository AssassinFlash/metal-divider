# 开源 AI 客服的人机协同与知识运营闭环

- 票：[研究：开源 AI 客服的人机协同与知识运营闭环](https://github.com/AssassinFlash/metal-divider/issues/151)
- 父地图：[Wayfinder map：AI 客服与坐席工作台持续功能优化](https://github.com/AssassinFlash/metal-divider/issues/148)（本文件不改地图）
- 日期：2026-10-27
- 分支：`research/ai-human-handoff`（自 `origin/main` @ `b272a22` 拉出，不合并、不开 PR）
- 工作树：`.scratch/research-151-handoff`（本票唯一临时工作副本）
- 用途：本票研究文档写作与分支推送
- 负责人：本票研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树；不删除分支，不删除本文件

只回答：相对 Knocket、ChatterMate、Chatwoot 生态及其他一手实现，哪些人机切换、建议回复、答案来源、未知问题与知识缺口机制值得接到现有 LHC 4.97v + Rapls 1.21.4 + DeepSeek。不从零设计客服栈，不把“功能更多”当成“值得做”。

不重复：

- [#102](https://github.com/AssassinFlash/metal-divider/issues/102) 的 Woo 索引设计。这里只标哪些更新必须等它，不另写一套同步方案。
- [#114](https://github.com/AssassinFlash/metal-divider/issues/114) / [#118](https://github.com/AssassinFlash/metal-divider/issues/118) 已交付的三类知识、端点契约、转人工、接管、交回 AI。
- [#132](https://github.com/AssassinFlash/metal-divider/issues/132) 已结论的通知闭环。
- [#149](https://github.com/AssassinFlash/metal-divider/issues/149) 的 LHC 工作台能力目录、[#150](https://github.com/AssassinFlash/metal-divider/issues/150) 的收件箱工作流、[#152](https://github.com/AssassinFlash/metal-divider/issues/152) 的企业微信值班。快捷回复、内部备注、评价开关若只是工作台配置，归它们，不在这里再编一份清单。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 本次读到的官方页面、官方包或 tag 级源码 |
| 官方宣称 | 官方教程或帮助文，未注册账号实走 |
| 合理推断 | 由已读材料推出，没有内部实现证据 |
| 无法确认 | 材料没写，或两份官方材料互相矛盾 |

源码基准：

- Live Helper Chat core tag `4.97v`，commit `016f486`（轻量 tag，指向该 commit）。下文 LHC file:line 均指该 tag 的 `lhc_web/`。
- Rapls 免费包 `rapls-ai-chatbot` 1.21.4，2026-10-27 从 `https://downloads.wordpress.org/plugin/rapls-ai-chatbot.zip` 下载解包。插件头 `Version: 1.21.4`。
- 现有端点：`impl/118-kb-endpoint` @ `c035240` 的 `mu-plugins/md-kb-endpoint.php`。本分支不包含该文件。
- ChatterMate tag `v1.0.27`（2026-10-19）。
- Chatwoot tag `v4.19.1`（2026-10-24）。

## 结论速览

1. **收益不在再装一套客服。** Knocket 的交回 AI、ChatterMate 的转人工、Chatwoot 的 Agent Bot 状态机，LHC 已经有对等物，而且 #118 已实测。再部署 ChatterMate、Chatwoot Captain 或 Dify，买到的是同一条环，外加共享主机跑不了的进程模型，或吃掉 $20/月上限的付费席位。路线 A 仍暂停。
2. **原生可复用的知识环已经在 Rapls 后台，没有接到 LHC。** 免费包有未回答问题列表（最多 50 条、可计数）、一键「Add to knowledge」、FAQ 精确匹配。写入只发生在 Rapls 自己的聊天 REST 里。当前端点直接调检索和 DeepSeek，不写这个列表。坐席因此看不到“访客反复问了什么、知识里没有什么”。
3. **缺失的连接层只有这一层，不是新后台。** 端点在“知识不足而转人工”时，把问题写入 Rapls 已有的 `raplsaich_unanswered_log`。显式要人的句子不要记成知识缺口。Woo 图案规格不要经「Add to knowledge」抄成第二条 FAQ，那件事等 #102。
4. **建议回复值得当假设，不值得当第一批功能。** Knocket 官方指南写低置信线程带着 AI 草稿进人工队列，人可以发送、修改或丢弃。我们现在把固定句直接发给访客，草稿不留给坐席。LHC 官方 REST 文档把机器人返回值写成“给访客的消息或一条工具指令”，本次没有读到坐席草稿槽。先在服务端记下未使用的草稿，用真实转人工样本判断有没有用；多数不可用就停，不建 Copilot。
5. **评价、访客引用来源、短语库、自动写知识，当前都不是闭环。** 它们或是低量指标，或和已定稿的访客文案冲突，或会制造第二资料源。功能存在不等于值得接到这条链上。

优先级只此一条实现假设，外加一条只记日志的验证：

| 优先级 | 假设 | 为什么现在 | 不做会怎样 |
| --- | --- | --- | --- |
| 现在值得做 | 知识不足的转人工写入 Rapls 未回答列表 | 后台已有，连接是一次 option 写入 | 缺口只存在聊天记录里，没人能按重复次数补知识 |
| 先验证再决定 | 转人工时另存一份坐席可见草稿和检索标题 | Knocket 有此宣称；我们没有证据说明草稿比坐席自己写更有用 | 过早做进回复框，会把模型的不确定句子变成默认回复 |
| 不做 | 会话评分、访客引用卡片、218 条短语库、Captain/ChatterMate/Dify、从对话自动发布知识 | 不改善“答不出 → 人补一条可复用答案”，或破坏单一资料源 | 无 |

## 1. 对照的是机制，不是再选平台

### 1.1 Knocket（官方教程，未实走）

产品面以两页为准，不采用营销博客。

[AI Agent 教程](https://knocket.trtc.io/tutorials/ai-agent/)（页眉 Updated Sep 7, 2026；本次抓取）写：代理引用自己的知识库；答不出或访客要人时静默转人工；短语命中则模型调用之前就转；Usage 有 AI Requests、Handoffs、平均响应时间。知识仍是 FAQ / URL / File 各 10 条。教程正文没有坐席改稿、会话评分或未知问题收件箱。

[完整指南](https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/)（2026-10-27 抓取正文）写了本票要的三段：

> Handoff — low-confidence threads bubble to the top of the human queue with the AI's draft attached, so you can send it, edit it, or discard it.

> Resume AI — from any thread's details panel, hit Resume AI to hand the conversation back after you've replied.

> Summary — the details panel shows a two-sentence recap written by the same agent so you skim before replying.

同一页把转人工分成短语库（指南写 47 种语言，命中不调模型）和模型工具 `transfer_to_human`（挫败、重复失败、超出范围）。[短语页](https://knocket.trtc.io/tutorials/ai_agent_handoff_phrases/) 同日抓取却写 50 种语言、218 条短语。两页都是官方页面，数量互相矛盾，**无法确认**运行时到底是 47 还是 50。这不影响结论：短语库是确定性快路径，不是知识运营。

[博客《Fails on Edge Cases》](https://knocket.trtc.io/blog/ai-agent-customer-support-exception-failures/)（2026-10-20）提倡 draft-then-approve、限制知识范围、用重复联系率而不是 deflection。同一文又写 “Knocket does not provide AI automatic replies in this design”。这是意见和销售，不是控制台功能。不把它当成 Knocket 已交付的改稿器。

官方教程没有会话评分，也没有“未知问题列表 → 写回知识”的产品页。Usage 只计 handoff 次数，不列出问句。

### 1.2 ChatterMate v1.0.27（源码，不部署）

公开核有转人工和引用，没有交回 AI，也没有坐席建议回复。

- `backend/app/agents/transfer_agent.py`：模型生成给访客的转人工说明，返回 `transfer_to_human`。超时也照样转。这是访客文案，不是留给坐席的草稿。
- `backend/app/tools/knowledge_search_byagent.py:66-68`：检索工具收集 `collected_sources`，形状是 `name` + `type`。这是代理侧引用，不是访客卡片。
- `backend/app/services/ticket_csat.py:18-25`：会话评分存在，关闭工单时经挂件评分提示收集，再链到 ticket。注释写明没有会话的工单不能评。评分不写知识库。
- 对 `Resume AI` / `resume_ai` 和 `suggested` / `copilot` 的 GitHub code search 均为 0。不能据此证明仓库绝对没有同义实现；能证明的是公开符号里没有 Knocket 那种交回按钮，也没有 Copilot。

#93 已结论它要 Docker、Postgres、Redis，不能装进当前共享 PHP 主机。本次不重审部署。它的引用形状（名称 + 类型）和我们端点已返回的 `knowledge#id 标题` 是同一类审计信息，不构成换栈理由。

### 1.3 Chatwoot v4.19.1（机制参照，不部署）

社区版收件箱的交回 AI 已在 #114 的姊妹研究里核对，本次只补建议回复、评分和知识缺口。

- [Captain 介绍](https://www.chatwoot.com/hc/user-guide/articles/1738101283-captain-_-introduction)（2026-10-27 抓取）：Copilot 是坐席摘要、起草回复、查找；另有编辑器里的 Suggest reply，以及管理员每周看的 FAQ suggestions 和 Knowledge coverage。Hacker 免费计划不含 Captain。自托管写明只在 Premium / Enterprise，且要自带 OpenAI key。
- `enterprise/config/premium_features.yml`：`captain_integration`、`captain_document_auto_sync`、`copilot_workflows`、`csat_review_notes` 都在付费旗标里。
- [如何开启 CSAT](https://www.chatwoot.com/hc/user-guide/articles/1677503828-how-to-enable-csat-surveys)：按收件箱打开，会话 resolve 后发给访客，默认关。`app/models/inbox.rb` 的 schema 注释有 `csat_survey_enabled`，默认 `FALSE`，文件在社区 `app/`，不在 `enterprise/`。评分备注 `csat_review_notes` 才是付费旗标。CSAT 不写知识库。
- FAQ suggestions 在 Captain 帮助文里，属于付费助手的知识覆盖，不是社区收件箱的免费闭环。

因此：Chatwoot 证明“坐席起草”和“评分”是成熟产品形态；也证明它们可以和知识更新拆开卖。我们不需要为了形态去装 Ruby + Redis + Sidekiq，更不该为 Copilot 付 $19/座席/月。

### 1.4 Dify 标注（模式参照，不部署）

[Annotation System](https://github.com/langgenius/dify-docs/blob/main/en/self-host/use-dify/monitor/annotation-reply.mdx)（2026-10-27 读 raw）：相似问命中标注则返回人工写好的答案，跳过生成；可从对话日志把回复改成标注；未命中的问题被写成覆盖缺口。这和 Rapls 的 FAQ 精确匹配 + 未回答列表是同一环。Dify 作为引擎已在既有研究中排除出共享主机，路线 A 暂停。不部署，只确认我们不必再造一个标注库。

### 1.5 不另审的候选

MaxKB、FastGPT、RAGFlow、Tiledesk 的引用开关或标注入库，已有研究结论是另一套主机。本次没有重读它们的行号，也不把它们的功能表当作待办。适合当前栈的，是能接到 LHC 触发器和 Rapls 后台的机制。

## 2. 现有栈：什么已经在，什么没接上

### 2.1 人机切换：原生已有，不重做

LHC `4.97v` `lhgenericbotupdateactions.php`：`transferToOperatorAction()`（`:5-21`）把会话置 `STATUS_PENDING_CHAT` 并写一条 `user_id = -2` 的系统消息；`transferToBotAction()`（`:24-38`）置回 `STATUS_BOT_CHAT`。同 tag 的 `lhgenericbotactioncommand.php:161` 是 bot 命令 `transfertobot`，同样把状态改回 bot。#118 已在隔离实例实测：转人工 → 坐席回复 → 接管后 bot 静默 → Change status→Bot 交回 → bot 恢复。这就是 Knocket 的 Resume AI。通知仍按 #132，不在本票。

端点契约（`c035240`，`md-kb-endpoint.php:125` 与 `:224-225`）：模型返回 `{answer, handoff}`，映射为 `{msg, action}`。知识不足时访客句被钉成 `I don't have that information — our team can help.`，`handoff` 为 true。系统提示禁止向访客提及 knowledge、documents、context。

### 2.2 知识缺口：Rapls 有后台，LHC 路径不写

`includes/api/class-rest-controller.php:3769` `log_unanswered_question()` 是 private。只在 Rapls 自己的聊天处理里调用：

- `:1279`：上下文为空，原因 `no_context`
- `:1347`：打开 `grounding_strict_enabled` 且未过接地阈值，原因 `grounding`

存储是 option `raplsaich_unanswered_log`，不是新表。同问合并计数，最多 50 条，字段 `q` / `t` / `n` / `reason`。可用过滤器 `raplsaich_log_unanswered` 关掉，但过滤器在 private 方法内部，外部调用进不去。

`templates/admin/dashboard.php:301-358` 已渲染列表、次数、原因、**Add to knowledge**（把问句预填进知识表单）。另有 “Generate FAQ draft from questions”。readme 与 changelog 的“一键入库”指的就是这个按钮，不是自动发布。

当前端点不经过这段 REST。所以 LHC 里真实发生的“我没有这条信息”不会出现在 Rapls 仪表盘。这是缺失的连接层，不是缺一个产品。

### 2.3 建议回复：没有坐席草稿槽

Knocket 指南宣称草稿挂在人工队列上，人再发送、修改或丢弃。我们的触发器把 `msg` 发给访客。知识不足时访客看到的是固定句，模型若产生过更长草稿，契约里没有第二字段留住它。

LHC 官方 [Bot Rest API](https://doc.livehelperchat.com/docs/bot/rest-api) 开篇写：服务返回给访客的消息，或一条要执行的工具指令。本次没有在该页读到“只给坐席的草稿”字段。`lhgenericbotactioncommand.php:702` 的 `metamsg` 是往已有消息的 `meta_msg` 写 JSON，不是扣住不发的草稿。#149 若证实工作台已有内部备注或可预填的回复框，草稿才有落点；在那之前不做新编辑器。

ChatterMate 的 transfer 文案是发给访客的。Chatwoot Copilot 是付费编辑器功能。两者都不提供一个可以搬进 LHC 触发器的免费草稿字段。

### 2.4 来源：审计行已有，访客不该看到“知识库”字样

端点 `md_kb_endpoint_sources()`（`c035240` 约 `:198-213`）把命中收成 `knowledge#<id> <title>` 或 `<post_type>#<post_id> <title>`。LHC 触发器今日只取 `msg` 和 `action`，`sources` 被丢掉。

Rapls 自己的挂件会画 “Reference pages”（`assets/js/chatbot.js:1864` 一带），后台有 `sources_display_mode`（`templates/admin/crawler.php:229`）。那是 Rapls 挂件，不是 LHC 挂件。打开它等于旁边再挂一套聊天，和 #118 的唯一挂件冲突。

已定稿提示词禁止向访客提 knowledge / documents / context。所以不能把 Rapls 的 Reference pages 原样贴进 LHC。已发布图案页的 permalink 可以从 `product#id` 推出来，不需要 #102；未发布或已下架的 id 不得变成公开链接，那一步受 Woo 发布状态约束，等 #102。

### 2.5 评价：有三套，都不更新知识

| 实现 | 实际做什么 | 会不会变成下一条知识 |
| --- | --- | --- |
| Rapls 挂件赞/踩 | `submit_feedback()`（`:3656`）写入自己的消息行。负例经 `get_negative_feedback_examples()`（`:1245`）注入 **Rapls 聊天** 的系统提示 | 不写入未回答列表。LHC 会话不在这张消息表里 |
| LHC survey | tag `4.97v` 有 `modules/lhsurvey/fillinline.php`，填完派发 `survey.filled`。官方模块页写 “Documentation for the Questionary module is currently in progress.” | 本次未读到它写知识库。开关归 #149，不在这里当知识环 |
| Chatwoot CSAT | 收件箱设置，resolve 后询问，社区模型字段默认关 | 报表，不写知识 |
| ChatterMate | 会话评分可链到 ticket | 不写知识 |

评价要有量才有统计意义。当前站点没有这个量。把赞踩接进 LHC，只会制造第二份会话存储，换不来“哪条知识该补”。

### 2.6 知识更新：两条路，只有一条现在安全

安全的路：非目录事实（运输、流程、已发布页面上没有的政策）由人在 Rapls 点 Add to knowledge，保存为 FAQ。Rapls 精确匹配会在下次优先用这条答案。站内已发布页的改动仍走 Rapls `save_post` 爬虫，#114 已核对，不重做。

不安全的路：把图案材质、镂空率、尺寸从聊天抄进 FAQ。那是第二份商品资料。下架或改规格后，FAQ 不会跟着 Woo 失效。#102 未关闭之前，这条路不做。Generate FAQ draft 会让模型起草整页，同样可能编造规格，不进第一批。

从对话自动发布知识，Knocket、ChatterMate、Chatwoot 社区版都没有把它当成默认行为。Dify 也是人从日志改完再存成标注。自动写不值得做。

## 3. 依赖 #102 的，和现在就能做的

| 动作 | 现在 | 必须等 #102 |
| --- | --- | --- |
| 记录“问了但知识不足” | 能。只记问句和原因 | 否 |
| 非目录 FAQ（人确认后保存） | 能。用现成知识表单 | 否 |
| 已发布页被爬虫重新索引 | 已有，不新做 | 否 |
| 把图案规格写进 FAQ 或草稿页 | 不做 | 是。否则下架后 AI 仍可能背旧 FAQ |
| 给访客未发布图案的链接 | 不做 | 是。公开链接必须跟 Woo 发布状态一起失效 |
| 坐席看见已发布页的标题或 permalink | 能，用端点已有的 post id | 否 |

#102 仍是开放的设计票。本文件不替它选择增量还是全量、拒答还是用旧值。

## 4. 不值得做（功能多 ≠ 值得做）

- **换或并列 ChatterMate / Chatwoot / Dify / MaxKB。** 交回、引用、标注入库的形状我们已经有或差一层写入。主机、许可证或付费边界没有变。
- **会话星级或 CSAT。** 不产生要补的问句。LHC survey 文档未完成，即使 #149 发现可以纯配置打开，也不是这条知识环的验收项。
- **把 Rapls 负反馈例注入 LHC 提示词。** 要先把 LHC 对话复制进 `raplsaich` 消息表。两套历史，收益是提示词里多两条例子。
- **218 条多语言短语库。** 英语站已由模型判断 “human / agent”，并有固定知识不足句。短语库减少的是误分类，不是知识缺口。数量官方自相矛盾，不值得先移植。
- **访客 Reference pages / “根据知识库”。** 和定稿提示词冲突。图案链接若要做，是文案票，且只链已发布页。
- **Usage 仪表盘、两句摘要、Telegram 引用回复。** 摘要也许让坐席少读几句，但 #132 的通知还没在评审站配置；摘要不能代替那一步。引用回复属于不合格许可证的 Telegram 扩展，#132 已排除。
- **自动把未回答问题生成并发布 FAQ。** Rapls 按钮存在。第一批最多让人看见问句；生成草稿容易把缺口填成编造的规格。

## 5. 可验证假设与 staging

不改生产。复用已有隔离实例或评审站，不新建第二套 LHC。真实模型若无密钥，不得用 mock 把假设说成已验证；mock 只能证明写入发生，不能证明问句分类正确。

### 假设 A（值得做的那一条）

知识不足而转人工时，Rapls 仪表盘出现该问句；显式要人不出现；重复提问只加次数。

最小连接：端点在决定 `transfer_operator` 且访客句是定稿的知识不足句时，按 Rapls 已有形状写入 `raplsaich_unanswered_log`（`q` 截断 200、`t`、`n`、`reason=no_context` 或 `grounding`、上限 50、同问合并）。不调用 private 方法，也不改 Rapls。若以后上游提供公开函数，再换成那个函数。显式要人（模型 handoff 但答案不是那句固定句）不写。

staging 读回：

1. 访客问一条知识外问题 → 仍看到固定句，会话仍 Pending，bot 行为与 #118 相同。
2. Rapls 仪表盘 Unanswered Questions 有该问句，次数 1，原因是 `no_context` 或 `grounding`。
3. 再问同一句，次数变 2，不新增行。
4. 访客只说要人工，列表不新增。
5. 人对一条**非图案规格**的缺口点 Add to knowledge 并保存后，再问得到 FAQ 答案且不再转人工。
6. 人对一条图案规格**不**点 Add to knowledge。下架或未发布的图案不会因为这次聊天多出一条 FAQ。此条用来证明没有制造第二资料源。

通过即停。不为此再扫全站，不发测试邮件。

### 假设 B（只记日志，不改回复框）

转人工时，服务端另记模型若不被钉成固定句时会说的草稿，以及当时的 `sources` 标题。10 条真实转人工之后，由坐席判断有几条会直接发送或小改后发送。

判据：可用少于一半，就停，不把草稿送进 LHC 回复框。可用过半，才允许下一张实施票把草稿写成坐席可见、访客不可见的备注——而且落点必须是 #149 证实已存在的原生备注或系统消息，不新做面板。本票不实现 B。

### 回归（各一次）

假设 A 落地后只复测 #118 的一条路径：转人工、坐席回复、接管后 bot 静默、交回后 bot 恢复。通知配置不在本票。

## 6. 证据局限

- Knocket 未注册实走。草稿是“挂在队列上、可发送/修改/丢弃”，内部是否先发给访客，教程没写。短语数量两页不一致。
- ChatterMate、Chatwoot、Dify 未运行。code search 为 0 只说明公开符号未命中，不是形式化证明不存在。
- LHC survey 官方文档是占位页。模块源码存在，是否已在 staging 打开归 #149。
- 端点行号来自 `impl/118-kb-endpoint` @ `c035240`，不在本分支。
- Rapls 结论限 1.21.4 免费包。private 方法与 option 形状是当前包的事实，下个版本可能改。连接必须把这种耦合写进实施票，而不是复制整份插件。
- 假设 B 的“一半可用”是停止线，不是质量标准。没有 10 条真实转人工之前，不得声称草稿值得做。

## 7. 来源

一手（2026-10-27 抓取或检出）：

- https://knocket.trtc.io/tutorials/ai-agent/
- https://knocket.trtc.io/tutorials/add-ai-assistant-to-website/ （Handoff draft、Resume AI、Summary、`transfer_to_human`）
- https://knocket.trtc.io/tutorials/ai_agent_handoff_phrases/ （50 languages / 218 phrases，与指南的 47 不一致）
- https://knocket.trtc.io/blog/ai-agent-customer-support-exception-failures/ （意见，非产品功能；文内写 Knocket 在该设计中不提供 AI 自动回复）
- https://downloads.wordpress.org/plugin/rapls-ai-chatbot.zip （1.21.4）
  - `includes/api/class-rest-controller.php:1279,1347,3656,3769`
  - `templates/admin/dashboard.php:301-358`
  - `templates/admin/crawler.php:229`
  - `assets/js/chatbot.js:1864,2102`
- Live Helper Chat tag 4.97v `016f486`
  - `lhc_web/lib/core/lhgenericbot/actionsChat/lhgenericbotupdateactions.php:5-38`
  - `lhc_web/lib/core/lhgenericbot/actionTypes/lhgenericbotactioncommand.php:161,702`
  - `lhc_web/modules/lhsurvey/fillinline.php`
  - https://doc.livehelperchat.com/docs/bot/rest-api
  - https://doc.livehelperchat.com/docs/modules/questionary （文档占位）
- ChatterMate tag v1.0.27
  - `backend/app/agents/transfer_agent.py`
  - `backend/app/tools/knowledge_search_byagent.py:66-68`
  - `backend/app/services/ticket_csat.py:18-25`
- Chatwoot tag v4.19.1
  - `enterprise/config/premium_features.yml`（`captain_integration`、`copilot_workflows`、`csat_review_notes`）
  - `app/models/inbox.rb` schema 注释 `csat_survey_enabled` 默认 false
  - https://www.chatwoot.com/hc/user-guide/articles/1738101283-captain-_-introduction
  - https://www.chatwoot.com/hc/user-guide/articles/1677503828-how-to-enable-csat-surveys
- https://github.com/langgenius/dify-docs/blob/main/en/self-host/use-dify/monitor/annotation-reply.mdx
- 端点 `impl/118-kb-endpoint` @ `c035240`：`mu-plugins/md-kb-endpoint.php` 系统提示、`action`/`sources` 映射

复用既有结论，不重复采证：#114 三类知识与端点规格；#118 转人工与交回实测；#132 通知不是本票范围；#93 ChatterMate 不能装进共享主机；#102 仍是 Woo 索引的设计票。
