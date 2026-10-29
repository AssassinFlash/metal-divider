# AI 客服面验收包(2026-10-29,Batch 0–4 汇总)

适用范围:metal-divider.com 客服面(LHC 聊天 + 企微通知 + 知识接线 + 自助删除/邮箱留存),
站点面仅涉及与客服共享的工件(guards/launcher)。证据=本轮各批次的实测记录,单批细节见
GOAL.md 对应小节;本文是签字用的汇总视图。

## 一、线上终态(2026-10-29 18:00 BJT 实测)

| 面 | 状态 | 证据 |
|---|---|---|
| 挂件入口(桌面胶囊+移动全幅) | ✅ 三视口(390/768/1440)胶囊可见、开态无破版 | /tmp/mdw6-*.png + zai 视觉四点 |
| AI 接地回答 | ✅ "Based on: Pattern Agave" 实走 | W1 演练(prod,#34) |
| 转人工→企微卡 | ✅ 卡即发、业主点 Open 接入 | W1 演练(05:53 UTC) |
| 操作员回访客端实时可见+身份 | ✅ 金边+姓名标签+头部切名 | Batch 3 staging DOM/样式/zai;prod CSS 12 处命中 |
| 离线留资(窗外) | ✅ 落库+卡即发(业主确认 3/3) | W2 演练(staging #27/prod #33) |
| 自助删除/邮箱留存 | ✅ REST 路由+失败限流(429 实测)+UI 按钮 | Batch 1 V-R1;W1 界面在场 |
| CSP 挂件链 | ✅ frame-src 'self' 在位(Batch 0 事故→修复→合同化) | chat-verify 6/6(含 CSP 探针) |
| rapls 内联挂件(第二入口) | ✅ 已按 B 方案隐藏(纯配置可逆) | W2 收尾读回零残留 |

## 二、风险与修复对照(评审团→落地)

| 评审编号 | 内容 | 处置 |
|---|---|---|
| P0 CSP | guards 被站点线部署覆盖回退 | Batch 0 热修+Batch 1 合并统一+chat-verify 第 6 探针合同化 |
| V-R1 | delete/email 失败无限流 | Batch 1 落地,staging 实测 429 |
| V-R3 | kb history 无上限 | Batch 1 mb_substr 2000 |
| V-R4 | 种子消毒 hex 假设 | Batch 1 改宿主值比对,Batch 3 v3 重写 |
| D-R8 | 坐席密码未轮换 | 业主裁定:不轮换(2026-10-28) |
| K16 | 回复时限三口径 | Batch 0 统一 within 24 hours(15 处) |
| W1 UX | 真人=AI 同款 | Batch 3 D 修复(金边+姓名) |
| secret_hash | 导出打空未重生成 | Batch 3 A:chat-push 自动重生成 |
| pending 批卡死 | 10-26 手工 archive | Batch 3 A:`just deploy forget` 合规通道 |

## 三、常态化机制(已上线)

- `just deploy chat-verify --target <t>`:6 探针(含 CSP),部署后必跑。
- `wp md-chat kb-gaps`:知识缺口读回(staging 实测 37 条真问题)——KB 内容补写的待办来源。
- chat-seed manifest 钉 LHC 版本(497),push 比对。
- WP 版本合同(pin=7.1.4,部署门禁)。
- runbook Future changes/Rollback 两节(docs/agents/chat-production-runbook.md)。

## 四、营业语义(实测修正)

- 部门营业窗 10:00–13:00 UTC(=18:00–21:00 BJT,站时区 UTC)。
- 窗内:AI 托底+可转人工(卡即发,不依赖操作员在场);窗外:离线留资表单。
- 转人工后业主接入才会话激活(status=1);访客端实时收消息。

## 五、签字与遗留

- [x] 走查(2026-10-29 18:13–18:20 BJT,窗内,代理代操):访客问 Agave 海岸适用→AI 接地答
      (无源部分诚实回退"checked with the team",该问已入 kb-gaps 日志)→"talk to a person"
      →**企微卡即发**(sends=1793268925)→操作员经连接器同款 autologin 深链进 mdmobile→回复
      →访客端实时到达:金边 rgb(201,169,98) solid 1px+姓名标签+头部切 "Metal Divider Support"+
      chip "Metal Divider team",zai 视觉 4/4(截图 /tmp/mdw7/mdw8-walkthrough-*.png);演练会话
      #35 含 7 条消息删净读回零。
- [ ] 隐私条款并入 privacy 页(草稿 docs/legal/privacy-wecom-clause-draft.md;并入时同步修
      "does not use local storage" 矛盾句;腾讯 SCC 表述留业主)。
- [ ] KB 内容补写节奏(按 kb-gaps 清单,业主定)。
- [ ] 63 个历史未并分支的内容评估(非客服面,站点线)。

**评注**:本面无已知未解决问题(评审团 K1–K16 全闭环或裁定);走查签字后本节可勾。
