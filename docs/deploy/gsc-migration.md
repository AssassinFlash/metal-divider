# GSC 迁移(metalpartition.com → metal-divider.com)

执行票:#36(cutover runbook 与切换执行)。本文只钉时序与口径,不执行、不碰生产。

依据:spec 22 `docs/specs/22-seo-execution.md` §6(分支 research/spec-seo,定稿 2026-10-24)+ spec 09 `docs/specs/09-domain-dns-mail.md` §6 十步(分支 research/spec-domain-dns)。两文冲突时以 22 的 GSC 专项为准(D5:两属性均 URL 前缀,域名属性不被地址变更工具支持)。

## 属性形态(一次性,开工前定死)

| 项 | 值 | 依据 |
|---|---|---|
| 旧属性 | `https://metalpartition.com`,**URL 前缀** | 地址变更工具不支持域名属性(spec 22 F1) |
| 新属性 | `https://metal-divider.com`,**URL 前缀** | 同上 |
| 验证方式 | DNS TXT(两域 NS 均在 Cloudflare 后,加记录即验) | spec 09 §2 |
| 地址变更 | 旧属性 → 设置 → 地址变更 → 选新属性 | 仅 URL 前缀属性可选 |

## 时序(嵌 spec 09 十步)

```
T0  切换前(评审站 noindex 期;与 #9 步骤 1–4 并行,零生产写入)
    ├─ 两个 GSC 属性按上表建好并验证
    ├─ 旧属性导出「索引覆盖率」与「效果」报告存档(≥近 16 个月),
    │  作 T4 衰减对照基线(spec 22 §6 T0)
    └─ redirect-map 的 18 行 PENDING(博客 16 + 致谢页 2)在对应票
       落地后回填,再随全量导入 Cloudflare(博客/致谢页就绪是 T1 前置)

T1  DNS 与 301 上线(= #9 步骤 3–5)
    ├─ 主域 NS → Cloudflare Active;301 矩阵在源站/CF 配好
    └─ Shopify 后台 metalpartition.com A/www → <HOST_IP>(≤24h 生效)

T2  301 生效验证(= #9 步骤 6;AGENTS.md §5 硬门:不过,禁转出/解约)
    ├─ 逐规则抽测全绿:产品(/products/<slug> → /pattern/<slug>)、
    │  类目(9 个 pattern-* 原位保留、无跳转——gradient 亦然 #25;旧站跳转的已删测试类目 → /shop/)、page、zh 镜像、
    │  带参 URL(CF 边缘剥参后落净 URL)、legacy 3 条源路径、
    │  404 policies 维持 404(不软跳)
    ├─ 新站 sitemap 提交(新属性,https://metal-divider.com/sitemaps.xml,
    │  SEOPress 原生端点;/sitemap.xml 恒 301 至它)
    └─ 新属性 URL 检查抽检 10 个重点页:可索引、canonical 自指新域

T3  地址变更工具(T2 全绿后立即;Google 要求 301 已生效才提交)
    └─ 旧属性 → 设置 → 地址变更:选新属性 → 确认 301 已全量生效 → 提交

T4  双前缀监控期(≥30 天;与 #9 步骤 7–9 并行,不阻塞转出/解约)
    ├─ 新属性:收录量上升曲线;重点 URL(首页、8 类目、头部产品)
    │  canonical 已指新域
    ├─ 旧属性:索引衰减曲线正常(被 301 替代;对照 T0 基线)
    └─ 新属性 404 监控:无「本应 301 的旧路径」漏网;旧域 301 抽测仍全绿

T5  长期
    └─ metalpartition.com 域名长期持有、301 与证书不中断;
       T4 指标转月度例行查看(运营日历)
```

与 #9 的对齐声明:#9 步骤 6 即本文 T2;#9 步骤 7–10(转出 → 建区 → 复查 → 解约)在 T3 之后按其自身顺序执行,不依赖 T4 监控期完成。本文不新增任何 DNS/注册商动作。

## 抽测矩阵(T2 的「全绿」清单)

从 data/redirect-map.csv(closed #4 权威)+ SEOPress PRO redirections(新站侧 28 条)各取样本,每类至少 1 条,记录 HTTP 状态与 Location:

| 类 | 样本 | 期望 |
|---|---|---|
| PDP | `/products/akira-architectural-metal-screen-pattern` | 301 → `/pattern/akira-architectural-metal-screen-pattern` |
| 系列类目 | `/collections/pattern-organic` | 200(系列存在,不跳) |
| 删除类目 | `/collections/other-example-products` | 301 → `/shop/` |
| 合并类目 | `/collections/all` | 301 → `/shop/` |
| 内容页 | `/pages/gdpr` | 301 → `/privacy/` |
| 退休页 | `/pages/contact` | 301 → `/inquiry/` |
| zh 镜像 | `/zh/products/<slug>` | 301 → `/pattern/<slug>` |
| 带参 | `/products/<slug>?variant=…` | 301 → 净 URL(CF 剥参,单跳) |
| legacy | redirect map 中 3 条预存 301 源 | 301 → 对应新 URL |
| 政策页 | `/policies/refund-policy` | 404 维持(不软跳) |

## 回退

- T3 之后发现 301 断链:先修 CF 规则再重抽 T2 矩阵;地址变更已提交的不可撤,但 Google 以实际 301 为准,修复后曲线自愈。
- T4 期间旧域索引不降反升:检查 canonical 与 301 是否被缓存层(CF/LiteSpeed)吞掉,对照 spec 20 §4 部署窗口协议。
