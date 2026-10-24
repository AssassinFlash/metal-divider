# 切换 Runbook:metal-divider.com 上线与 Shopify 退役(#36 / IMP-11)

状态:**剧本,落库时尚未执行任何一步**。每一步开工前业主过目;T5(解约 Shopify)仅凭业主明示。AGENTS.md §5「先证后删」是全文硬门:**T2(301 矩阵全绿)实证通过之前,禁止域名转出、禁止解约、禁止 GSC 地址变更**。执行时在 `docs/deploy/cutover-log.md`(执行开始时创建)逐步落证据,本文每节的「验收」列即该步的日志条目要求。

| 输入 | 权威内容 | 本文用法 |
|---|---|---|
| AGENTS.md §2/§5 | 全部裁定;交付纪律(像素门、先证后删、询盘实走、测试邮件纪律) | 硬门与验收标准 |
| spec 09(research/spec-domain-dns)`docs/specs/09-domain-dns-mail.md` | DNS 现状与目标态、三防记录、迁出流程、十步切换顺序 | T1/T2/T4 的机制依据 |
| spec 20(research/spec-deploy)`docs/specs/20-staging-deploy.md` | 评审站形态、部署物三面、窗口序列、缓存与发信 | T0 部署序列依据 |
| spec 22(research/spec-seo)`docs/specs/22-seo-execution.md` §6 | GSC 迁移 T0–T5 | T0/T3 的 GSC 依据 |
| [checklist.md](checklist.md) | 主机侧人工步骤、缓存配置、窗口序列、备份纪律 | T0 逐项对勾,不复制 |
| [gsc-migration.md](gsc-migration.md) | GSC 属性形态、T0–T5 时序、抽测矩阵 | T2 抽测矩阵与 T3 动作直接引用 |
| `tools/migrate/data/redirect-map.csv` | 301 映射权威(385 行) | T2 验证对象 |

## 0. 起点:当前实态(2026-10-24)与缺口

**已就绪**(main 分支实态):

- dev 实例全量:146 图案、1063 媒体终稿、13 内容页、SEO 配置面(IMP-9 验收记录)、LC 模板 25 面、WindPress 卷全量。
- `tools/deploy` 全套(dev/staging/prod 三 target、drill、verify、contract、media、backup、rollback、maintenance、windpress-compile/check);`tools/migrate` 五阶段 + reconcile(支持 `--wp` 经 SSH 打任意实例);`tools/pixel-check`(url-map 182 URL,掩膜台账制)。
- `docs/deploy/checklist.md`、`docs/deploy/gsc-migration.md` 已在 main。

**尚不存在、由本 runbook 逐步创建**:主机侧两站点与库、SSH 别名、Cloudflare 账号与两区、GSC 两属性、info@ 邮箱与三防记录、旧域 301 的源站承载、域名迁出、解约。

## 1. 总览:时序、间隔与并行

```
建站(内容面)───────────────────────────────────────┐
T0-1 主机与账号开通 ──(与建站并行,可即刻)           │
T0-2 评审站部署+全量导入 ←─ dev 验收绿 ──────────────┤
T0-3 像素门 + 询盘实走(评审站)←─ 导入完成            │
T0-4 评审签字 → 生产复刻(预览域 verify)              │
T1  DNS 切换 metal-divider.com(NS→CF;旧域 A→源站)←─ T0 全部绿 + 18 行 PENDING 回填
T2  301 矩阵全绿(读)←─ T1 各记录生效(≤24h 后即验,无最短等待)
T3  GSC 地址变更 ←─ T2 全绿(同日立即)
T4  metalpartition.com 迁出 Shopify(≤7 天)→ CF 区 → NS(≤24h)→ 301 复验 ←─ T2 全绿(与 T3 同日可发起)
T5  解约 Shopify ←─ 域名已迁出 + 301 新链路全绿 + 业主明示(最短间隔 0 天)
gsc T4 监控期(≥30 天)── 与 T4/T5 并行,不阻塞解约
```

| 边界 | 最短间隔 | 硬依赖 |
|---|---|---|
| T0-1 → T0-2 | 0(SSH 通即可) | checklist §1 主机侧逐项 + dev 实例验收绿 |
| T0-3 → T0-4 | 0 | 像素门全绿 + 业主评审通过 |
| T0-4 → T1 | 0(同日可) | 生产 verify 全绿;redirect-map 18 行 PENDING(博客 16 + 致谢页 2)已回填;CF 区记录建齐 |
| T1 → T2 | 0;各记录生效 ≤24h,双解析器确认后即验 | NS Active + 旧域 A 生效 |
| T2 → T3 | 0(全绿后立即) | T2 全绿(Google 要求 301 已生效才提交) |
| T2/T3 → T4 | 0(T3 提交后同日可发起) | T2 全绿(AGENTS §5 硬门) |
| T4 内部 | 发起→当日录入 EPP;转出 ≤7 天(30 天硬限);完成→NS ≤24h→复验同日 | 转出完成才有 T4-b |
| T4 → T5 | **0 个自然日**(60 天锁已清零,spec 09 §6) | 前置清单全绿 + 业主明示 |

**可与建站并行的步骤**:T0-1 全部(附加站、库、SSH、SSL、cron、防护、info@ 建箱与三防入 hPanel 区、Cloudflare 账号、GSC 属性与基线导出)。T0-2 起需要内容面就绪。

**业主在场的节点(★,共 5 次到场 + 2 次线上)**:① T0-1 主机与账号(面板批量);② T0-3/T0-4 评审与签字;③ T1 NS 与 Shopify DNS(业主账号面板)+ 正式域询盘实走;④ T4 迁出双面板(Shopify+Hostinger)+ NS;⑤ T5 解约;线上:T3 地址变更、gsc T4 月度查看。

## 2. T0 预检(全部可与建站并行;T0-2 起需内容就绪)

### T0-1 主机与账号开通 ★业主在场(一次到场批量做完)

业主在场的动作集中在此节:所有面板(hPanel、Cloudflare、Google、Shopify)都是业主账号;代理在旁操作或引导,SSH 别名与本地工具由代理独立完成。

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | hPanel 把 `metal-divider.com` 添加为订阅 u388076051 附加网站;`staging.metal-divider.com` 为第二附加站(自有子域) | ★业主 | 两站在 hPanel 站点列表;经预览域可见 | hPanel 删除站点(尚无公开流量,零风险) |
| 2 | hPanel DNS 编辑器(parking 区):建 `staging` A → 订阅主机 IP(记为 `<HOST_IP>`,hPanel 站点详情读数;parking 区现值 2.57.91.91 为参照) | ★业主 | `dig A staging.metal-divider.com` 双解析器(dns.google + cloudflare-dns.com,本机 TUN 勿用本地 dig)= `<HOST_IP>` | 删该记录 |
| 3 | PHP 8.3;两库(独立 DB 用户,凭据入 `~/.config/metal-divider/` 600);开 SSH;两站免费 SSL(staging 先证书后开评审);hPanel 每分钟 cron `wp cron event run --due-now`(`--path` 各指各根)——checklist §1 逐项 | ★业主 | `just deploy contract --target staging` 能读到 8.3;SSH 能登;证书有效 | 面板反向操作 |
| 4 | `~/.ssh/config` 加 `hostinger-metal-divider` 别名(参照 `hostinger-de-linker` 写法) | 代理 | `ssh hostinger-metal-divider 'php -v'` 返回 8.3.x | 删别名行 |
| 5 | 评审站防护:口令保护目录、`blog_public=0`(mu 件随首部署自动)、lscwp 页面缓存 OFF——checklist §2 逐项 | ★业主(hPanel)+ 代理(WP 侧) | 匿名访问 staging 弹口令;响应带 `X-Robots-Tag: noindex`;无页面缓存头 | 面板/开关反向 |
| 6 | hPanel → Emails 建箱 `info@metal-divider.com`(MX 已在 parking 区,开箱即收);Domain settings 生成 DKIM CNAME;三防记录进 **hPanel 区**(SPF `v=spf1 include:_spf.mail.hostinger.com ~all`、DKIM 照抄、`_dmarc` 起步 `p=none; rua=mailto:info@…; adkim=s; aspf=s`) | ★业主 | 收一封外部测试信到 info@(一封、指定地址);dig TXT 双解析器见三条 | 删箱/删记录 |
| 7 | Cloudflare 免费账号(或业主现有);给代理建 API token(两 zone 的 DNS 编辑权);Turnstile 挂件 hostnames 加入 `staging.metal-divider.com`、生产裸域+www、hPanel 预览域 | ★业主 | token 可列 zone;Turnstile 配置页四主机名在列 | 撤 token/改回 |
| 8 | GSC:两属性均按【URL 前缀】建 `https://metal-divider.com` 与 `https://metalpartition.com`,验证 TXT 加进**当前权威区**(新域=hPanel 区;旧域=Shopify DNS 编辑器,TXT 可编辑);旧属性导出覆盖率/效果报告(≥16 个月)存档 | ★业主 | 两属性已验证;基线导出文件入 `docs/deploy/cutover-log.md` 引用位 | 删属性(无生产影响) |
| 9 | redirect-map 的 18 行 PENDING(博客 16 + 致谢页 2)在对应内容票落地后回填——**T1 进入条件**,不回填不开切 | 代理 | `redirect-map.csv` 无 `target=PENDING` 行 | — |

### T0-2 评审站部署与全量导入(代理;checklist §6 窗口序列)

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | `just deploy drill --instance dev` 全绿(窗口前置,spec 20 §3.3 步骤 0) | 代理 | drill 报告切换→回滚→再切换全绿 | — |
| 2 | `just deploy staging`(dry-run)人工读一遍输出 → `--write` 真实部署 | 代理 | dry-run 与真实输出一致;原子切换回执、DB 快照落 `~/metal-divider-local/snapshots/` | `just deploy rollback --target staging --write` |
| 3 | WindPress 编译(admin → Performance → Generate)+ `just deploy windpress-check --target staging` | 代理 | 缓存 mtime 变新 + 锚类断言绿 | 重跑编译 |
| 4 | 完整备份 → `just deploy media --target staging`(分块,每块独立连接) | 代理 | 1063 终稿数与 dev 一致;reconcile 媒体审计过 | 重灌(评审站无独有数据,可随时从本地重置) |
| 5 | `just migrate apply --stage {terms,products,media,pages,redirects} --write`,`--wp` 经 SSH 打评审站 | 代理 | `just migrate reconcile` 13 项审计全过;146 品 SKU=pattern_code;`/pattern/` 基座生效 | 评审站从本地重灌(§1.4:评审站只收部署,不是部署源) |
| 6 | present + seo(部署自动执行)幂等核对:`--check` 双命令 drift = 0 行 | 代理 | drift 0 | 重跑幂等命令 |
| 7 | `just deploy verify --target staging`(带 `MD_DEPLOY_STAGING_AUTH=user:pass`) | 代理 | 端点全 200 + 关键内容断言绿 | 修复后重跑 |

### T0-3 验收三件 ★业主在场看结果

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | **像素门全跑**:`just pixel-check dev`(dev 为权威跑——评审站在口令保护后 check.mjs 无 httpCredentials 支持;评审站的视觉等价性由「同一部署产物 + 同一导入管线」+ probe 内容断言承载。如需评审站直跑,先给 check.mjs 加凭据支持,单列小改,默认不做) | 代理 | 182 URL × 双视口,**掩膜外差异数 = 0**;掩膜(M1 动态/M2 修复/M3 升级)逐项台账行**报业主过目**;报告存档 | N/A(读);fail 即修复重跑 |
| 2 | **询盘链实走(测试模式,评审站)**:浏览 → 图案清单 → 询盘提交 → 通知与确认 → WhatsApp 路由(AGENTS §5 路径) | ★业主(按业主路径)+ 代理 | 表单提交 303;评审站 wp_mail 落盘零真发;通知路由断言 = 队列收件人数据(sales@sunriverinverter.com 主 / info@ 备),**不多封实发**;WhatsApp 链接 = +85264731687 | N/A(评审站零真信) |
| 3 | 评审站内容走查(业主人眼过全部页型) | ★业主 | 业主认可 | 修内容后重走 |

### T0-4 评审签字 → 生产复刻(代理;签字是门)

生产在 DNS 切换前完整建成——T1 落地即命中成品,「一上来就完美」。

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | **业主明示评审通过**(票上/聊天记录) | ★业主 | 记录入 cutover-log | — |
| 2 | 生产复刻 = T0-2 同款序列换 `--target prod`(首部署无 CF 边缘流量,Dev Mode 步骤跳过;verify 经 hPanel 预览域) | 代理 | 生产 verify 全绿(预览域);DB 快照 + 完整备份落位;`just deploy contract --target prod` 全绿 | `rollback-deploy` + `restore-snapshot`;预览域仅内部可见,回滚零公开影响——**全程最低风险的生产部署窗口** |
| 3 | 生产测试询盘一封(收件人重写开关,只发指定测试地址;Turnstile 预览域 hostname 已在 T0-1#7 加入) | 代理 | 队列数据断言收件人正确;一封、一次 | — |

**T1 进入条件(全绿才开切)**:T0 全部验收绿;18 行 PENDING 已回填;生产完整备份与快照在位;业主在场。

## 3. T1 DNS 切换 metal-divider.com(NS→Cloudflare;旧域 A→源站)

目标:新域正式可达、邮箱零中断、旧域开始 301。**顺序铁则(spec 09 §2.1):先在 Cloudflare 区建齐全部记录,后改 NS——否则传播期邮箱与站点同时断。**

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | Cloudflare 建 `metal-divider.com` 区(Free,Full Setup);扫描后**人工补齐**(扫描必漏):A `@`→`<HOST_IP>` 橙云;CNAME `www`→`@` 橙云;A `staging`→`<HOST_IP>` **灰云**(自 hPanel 区重建);GSC 验证 TXT 照抄;MX 5/10 hostinger(DNS only);SPF TXT;DKIM CNAME(hPanel 生成值照抄,DNS only);`_dmarc` TXT;www→裸域 301(CF Redirect Rule 或源站);参数剥离规则(F1:utm/variant 等 301 至净 URL) | 代理(API token) | CF dashboard 记录表与 spec 09 §2.1 目标态逐行对上;staging 灰云 | 记录可改;区可删 |
| 2 | hPanel:Change Nameservers,`nova/cosmos.dns-parking.com` → CF 分配两 NS(逐字) | ★业主 | CF 域名状态 Active(确认邮件);双解析器 NS 已切 | hPanel 改回 parking NS(≤24h;旧区记录仍在,**邮箱 MX 两区同值不断**) |
| 3 | 旧区退役核对:parking 区的手误 301→metaldivider.com(死域)随切 NS 消亡 | 代理 | `curl -I http://metal-divider.com` 不再 Location 到 metaldivider.com,而是新站/规范化跳转 | N/A(清理项,无回滚需求) |
| 4 | hPanel 把 `metalpartition.com` 挂为 metal-divider.com 站点的**停放/别名域**(仅作 301 入口),源站 301 规则上线(Host 换到裸域、保路径;`/products/*`→`/pattern/*` 等路径映射由 SEOPress 落地——窗口期两跳可接受,单跳终态待 T4-b 由 CF Redirect Rule 承载,F1) | ★业主(面板)+ 代理(规则) | 本机绑 en0 直连源站 IP 带 Host 头测试:任意旧路径 301 链最终落 `https://metal-divider.com/…` 正确路径 | hPanel 摘别名域 |
| 5 | Shopify 后台:metalpartition.com DNS 把 A `@` → `<HOST_IP>`、www CNAME 同步改(**先完成 #4 再改此条**,否则旧域流量误路由;A 可改不可删,≤24h 生效;**不动注册人联系信息**——改 WHOIS 触发新 60 天锁,显式禁令) | ★业主 | 旧域解析双解析器确认已指 `<HOST_IP>`;旧店停服、301 开始服务 | Shopify 改回 23.227.38.67 / `shops.myshopify.com`(≤24h;解约前旧店可恢复) |
| 6 | 邮箱切换核对:info@ 收发各一封测试(纪律:一封、指定测试地址) | 代理+★业主 | 收发均通;SPF/DKIM/DMARC 在 CF 区双解析器可见 | NS 回滚路径同 #2;MX 两区同值,邮件不中断 |
| 7 | **正式域询盘链实走(测试模式)**——AGENTS §5「线上实走」定档于此 | ★业主(业主路径) | 正式域浏览→清单→提交→通知与确认(队列断言+一封测试)→WhatsApp 路由全通 | 修复后重走 |
| 8 | 起用 CF 部署窗口协议(此后每次部署 Dev Mode ON,checklist §4) | 代理 | 下次部署按窗口序列执行 | — |

## 4. T2 301 矩阵全绿(GSC 地址变更与 Shopify 解约的**共同前置**)

纯读验证,代理执行;证据表落 cutover-log(每条:HTTP 状态 + Location 逐跳)。

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | 抽测矩阵全绿:**按 [gsc-migration.md](gsc-migration.md) 抽测矩阵表逐条执行**(PDP `/products/<slug>`→`/pattern/<slug>`、系列类目 200、删除/合并类目→`/shop/`、合规页→`/privacy/`、退休页→`/inquiry/`、zh 镜像、带参剥参、legacy 3 条、政策页维持 404),每类至少 1 条;跳数记录(窗口期源站承载 ≤2 跳且最终落点正确) | 代理 | 矩阵全绿;记录表完整 | N/A(读);fail → 修 CF 规则/SEOPress/源站规则后重跑全矩阵 |
| 2 | sitemap 提交(新属性,`https://metal-divider.com/sitemaps.xml`);robots.txt Sitemap 行生效 | 代理 | GSC 提交成功;sitemap 可抓取 | 重提交 |
| 3 | 新属性 URL 检查抽检 10 个重点页 | 代理 | 可索引、canonical 自指新域 | — |

**门**:此节全绿 = GSC 地址变更(T3)、域名转出(T4)、解约(T5)三者共同解锁(AGENTS §5:301 生效实证之后才解约 Shopify)。

## 5. T3 GSC 地址变更(对齐 gsc-migration.md T0–T5)

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | 旧属性 → 设置 → 地址变更 → 选新属性 → 确认 301 已全量生效(T2 即证据)→ 提交 | ★业主(线上,代理引导) | GSC 显示变更受理;提交记录截图入 cutover-log | **不可撤**;Google 以实际 301 为准,T3 后发现断链先修再重抽 T2 矩阵,曲线自愈(gsc-migration 回退节) |

gsc T0–T5 的 T0(属性/基线)与 T2(验证)已分别嵌在本文 T0-1#8 与 §4;T4(≥30 天双前缀监控)与 T5(长期持有旧域)与本文 T4/T5 并行,不阻塞解约;指标转月度例行(运营日历,连同 info@ 免费期 1 年到期升级、DMARC 收紧节奏一起记)。

## 6. T4 metalpartition.com 迁出 Shopify(→ Hostinger → Cloudflare)

### 前置核对(发起前逐项,spec 09 §5)

- [ ] 60 天锁已过(2026-10-12 起,窗口开着;RDAP 复核当日状态)
- [ ] 距上次续费 ≥45 天(注册后未续费,满足);距到期 ≥7 天(2027-08-13,满足);DNSSEC 关闭(满足)
- [ ] T2 全绿已归档(AGENTS §5 硬门)
- [ ] **显式禁令:全程不碰注册人联系信息**(改 WHOIS = 60 天锁重启,窗口作废)

### T4-a 发起与转入 ★双面板业主在场

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | Shopify:Settings → Domains → 域名 → Domain settings → Transfer to another provider → Confirm(自动解锁,EPP 码显示在域名信息页);顺手关该域自动续费 | ★业主 | EPP 码到手(只口头/安全通道传递,不入库) | 发起后撤销难——**发起前是唯一决策点**;未在 Hostinger 录入则 Shopify 侧域名仍在 |
| 2 | Hostinger:Transfer Domain(非新购)→ 输入 metalpartition.com → 付款(≈续费价,.com +1 年)→ 录 EPP → **NS 选 Keep existing nameservers** → 确认转出邮件。**发起当日完成录入**(Shopify 30 天未完成会重新上锁);刚解锁录 EPP 被拒 → 数小时后重试 | ★业主 | Hostinger 显示转出处理中;确认邮件已点 | Hostinger 侧取消转出(未完成前);EPP 可重新取 |
| 3 | 等待转出完成(双方官方口径 ≤7 天;期间 NS 被锁 = Google Cloud DNS 继续应答已改的 A 记录,**服务零中断**) | 代理(每日 RDAP 复核) | RDAP 注册商 = Hostinger;hPanel 域名入列,NS 仍 ns-cloud-b*(Keep existing 的效果) | 30 天硬限内未完成 → 取消重来(回 T4-a#1) |

### T4-b 完成后:Cloudflare 区与 NS

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | CF 建 `metalpartition.com` 区:A `@`→`<HOST_IP>` 橙云、CNAME `www`、`_dmarc` `v=DMARC1; p=reject;`(零发信域直接最严,无 MX 无 SPF);**301 全 URL 单跳 Redirect Rule**(redirect-map 全量转换:`/products/*`→`/pattern/$1` 等 + 剥参 + host 换裸域)——F1 单跳终态在此落成 | 代理(API token) | 规则条数与 redirect-map 行数对账;CF 模拟测试通过 | 记录/规则可改可删 |
| 2 | hPanel:该域 Change Nameservers → CF 分配 NS(≤24h) | ★业主 | NS 双解析器已切;CF 区 Active | hPanel 改回 ns-cloud-b*(Google Cloud DNS 记录仍在) |
| 3 | **301 矩阵复验**(同 §4 矩阵,此时应全单跳) | 代理 | 矩阵全绿且单跳;此记录是 T5 前置证据 | 修规则重跑 |

HSTS 注记:Shopify 域带 90 天 HSTS;目标平台全程 HTTPS,无需处理(spec 09 §5#6)。

## 7. T5 解约 Shopify(仅凭业主明示)

**不可逆**。前置清单逐项绿 + 业主明示(文字记录入 cutover-log),缺一不开:

- [ ] 域名已迁出:RDAP 注册商 = Hostinger,状态无异常锁(T4-b 完成)
- [ ] 301 已在新域名侧全绿:T2 首验 + T4-b#3 复验两份记录在档
- [ ] 邮箱不再依赖 Shopify:事实层早已成立(projects@ 无 DNS 通道弃用、info@ 全程在 Hostinger);断言 = CF 区 MX/SPF/DKIM/DMARC 记录在位 + info@ 收发实测过(T1#6)
- [ ] Shopify 数据导出完成(询盘/客户/内容,业主决定范围;解约后 admin 关闭,含停用店的 `/access_account/domains` 例外通道也随账号而去)
- [ ] GSC 地址变更已提交(T3)、旧域 301 抽测仍绿
- [ ] **业主明示解约**(非默认流程推进)

| # | 动作 | 执行者 | 验收标准 | 回滚方法 |
|---|---|---|---|---|
| 1 | Shopify:Settings → Plan → 暂停或取消订阅(按业主选择) | ★业主 | 订阅结束确认邮件;cutover-log 收尾条目 | **无回滚**(重新订阅 = 从零);唯一安全网 = 前置清单本身 |

## 8. 备份与回滚总表(AGENTS §5;首次生产写入的备份/回滚记录即本节 + checklist §7)

| 阶段 | 回滚锚点 | 生效时长 |
|---|---|---|
| T0-2/T0-4 部署 | `just deploy rollback --target <t> --write`(rename 回上一版,零写库)+ `restore-snapshot`(库同回退);生产首部署在预览域期执行,公开影响为零 | 分钟级 |
| T0 导入 | 评审站/生产从本地重灌(评审站无独有数据;生产有快照+完整备份) | 小时级 |
| T1 NS | hPanel 改回 parking NS;邮箱 MX 两区同值不断 | ≤24h |
| T1 旧域 A | Shopify 改回 Shopify IP(解约前旧店可恢复服务) | ≤24h |
| T3 地址变更 | 不可撤;以实际 301 为准自愈 | — |
| T4 转出 | 发起前 = 唯一决策点;录入后 Hostinger 侧可取消;NS 可切回 Google Cloud DNS | ≤24h |
| T5 解约 | 无(不可逆);前置清单即安全网 | — |

备份纪律:代码部署自带 DB 快照(流回本机,留 20 份);媒体/导入等数据写入前完整备份(`just deploy backup --write`);每周一次;执行日志超 7 天提醒。
