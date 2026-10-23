# 09 · 域名、DNS 与邮箱供给(定稿)

票 #9(Grilling)。本文件为查证后定稿:现状核实表、每域名 DNS 目标态、邮箱与三防(SPF/DKIM/DMARC)记录、切换顺序草案(喂给将来的上线 runbook 票)。本票**不做任何真实 DNS/注册商变更**。

- 查证日期:**2026-10-24**(所有外部结论的查询日期均为当日,来源见 §7)
- 核实手段:DNS over HTTPS 双解析器(dns.google 与 cloudflare-dns.com,结果交叉一致)+ Verisign RDAP(注册局权威数据)。本机有 TUN 代理,未使用本地 dig(会拿到 fake-IP)。
- 备用凭据备忘(仅记录存在):hPanel 浏览器登录凭据备用在本地 `~/Downloads/wp-admin.txt`(内容永不入库;本票未做任何交互式浏览器登录)。SSH 参照 `~/.ssh/config` 的 `hostinger-de-linker`(同 Hostinger 账号另一站点,de-linker)。

## 0. 结论速览

| 事项 | 结论 |
|---|---|
| metal-divider.com 注册商 | 保持 Hostinger(业主自有账号),只把 **NS 迁 Cloudflare**(免费档 Full Setup) |
| metalpartition.com 注册商 | **Tucows(Shopify 代管)**;转出到 **Hostinger**(与主域同账号),再 NS → Cloudflare |
| metalpartition.com 60 天锁 | 注册日 2026-08-13,ICANN 锁已于 **2026-10-12 到期**,当前处于可转出窗口 |
| 转出时的 NS 处置 | Hostinger 转入结账时选 **Keep existing nameservers**(转出期间 ≤7 天 NS 被锁),完成后再在 hPanel 切 Cloudflare NS |
| 邮箱 | info@metal-divider.com 在 Hostinger 建箱(托管套餐免费邮箱额度内,免费期 1 年);SPF/DKIM/DMARC 三条齐上 |
| 迁出 → 解约最小间隔 | **无额外锁定期**;唯一硬耗时是转出流程本身(≤7 天)+ NS 切换传播(≤24h)。60 天锁已清零 |
| 切换顺序 | 建站 → 评审 → DNS 切 metal-divider.com → 301 矩阵验证(GSC)→ metalpartition.com 转出(保 NS)→ NS 切 Cloudflare → 解约 |

## 1. 现状核实(2026-10-24 实测)

### metal-divider.com

| 项 | 实测值 | 说明 |
|---|---|---|
| 注册商(RDAP) | HOSTINGER operations, UAB | 业主自有 Hostinger 账号购入(§2 业主事实),RDAP 一致 |
| 注册/到期 | 2026-06-30 / 2027-06-30 | RDAP events;续费在 Hostinger |
| 状态 | clientTransferProhibited | 常规注册商锁,不影响本票(不转注册商) |
| NS | nova.dns-parking.com / cosmos.dns-parking.com | Hostinger 默认(parking)NS;SOA 主机 dns.hostinger.com,serial 2026102304(区仍在 Hostinger 侧活跃管理) |
| A | 2.57.91.91 | Hostinger hcdn 边缘;HTTP 301 → **https://metaldivider.com** |
| www | **NXDOMAIN(无记录)** | 上线前必须补 |
| MX | 5 mx1.hostinger.com / 10 mx2.hostinger.com | Hostinger 邮箱路由已就位 |
| TXT(SPF) | **无任何 TXT 记录** | 三防记录为零,目标态必须补齐 |
| DS(DNSSEC) | 无 | 迁 Cloudflare 无 DNSSEC 拆除前置 |

**异常发现:当前 301 指向 metaldivider.com(无连字符变体),而该域名当前 NXDOMAIN(未注册/未委派;dns.google 与 cloudflare-dns.com 双证)。**即主域现在 301 到一个死域名——应是 hPanel 里配置域名跳转时的手误。切 NS 后此跳转随旧区退役,记入 runbook 待清理项;新 A 记录直接指向建站主机,不保留该 301。metaldivider.com 本身未注册;是否注册它做品牌保护是业主的事,超出本票范围。

### metalpartition.com

| 项 | 实测值 | 说明 |
|---|---|---|
| 注册商(RDAP) | **Tucows Domains Inc.** | Shopify 域名的后端注册商;「域名在 Shopify」= Shopify 代管(Tucows 落名) |
| 注册/到期 | 2026-08-13 / 2027-08-13 | RDAP;转出到 Hostinger 后 .com 加 1 年 → 2028-08-13 |
| 状态 | clientTransferProhibited + clientUpdateProhibited | Shopify 侧锁;发起 Transfer to another provider 时自动解锁(官方文档) |
| NS | ns-cloud-b1..b4.googledomains.com | Google Cloud DNS,Shopify 代管域名的 DNS 承载 |
| A | 23.227.38.67 | Shopify 店面 IP(Shopify 文档:末段不同的自动生成变体均有效) |
| www | CNAME → shops.myshopify.com(→ 23.227.38.74) | Shopify 标准配置 |
| MX / TXT | **均无** | 该域名当前不收邮件;**projects@metalpartition.com 在 DNS 层无收信通道,无邮箱迁移负担**(裁定也早已改为 info@metal-divider.com) |
| DS(DNSSEC) | 无 | 无 DNSSEC 拆除前置(Hostinger 转入亦要求 DNSSEC 关闭,已满足) |

### 参照系:sunriverinverter.com(de-linker,同一 Hostinger 账号)

NS = ns1/ns2.dns-parking.com;MX = 5 mx1.hostinger.com / 10 mx2.hostinger.com;TXT = `v=spf1 include:_spf.mlsend.com include:_spf.mail.hostinger.com ~all`。metal-divider.com 的 MX 与其完全同款;SPF 差异只是去掉 MailerLite(`_spf.mlsend.com`),新站发信只走 Hostinger。

## 2. 目标态

### 2.1 metal-divider.com(canonical)

- **注册商:Hostinger 不动**。购买、续费、WHOIS 都留在业主账号;改 NS 不改变注册商关系与续费——Hostinger 官方 NS 修改文档明说「Changing nameservers will transfer your DNS Zone management to the new service provider」,且官方另有专文指引用户把 DNS 区放到 Cloudflare 管理(见 §7),属受支持用法。
- **DNS:Cloudflare 免费档 Full Setup**(免费/Pro 计划唯一可用模式,官方文档)。Cloudflare 分配两个 NS,在 hPanel 域名的 DNS/Nameservers → Change Nameservers 里替换 nova/cosmos.dns-parking.com(可选「Change nameservers」输入最多 4 条),传播最长 24 小时。
- 目标记录(Cloudflare 区内,建站 IP 记为 `<HOST_IP>`,由建站票产出):

| 类型 | 名称 | 值 | 代理 | 备注 |
|---|---|---|---|---|
| A | @ | `<HOST_IP>` | Proxied(橙云) | Hostinger 源站套 Cloudflare;CDN/SSL/Turnstile 全在此层 |
| CNAME | www | @(或 A 同值) | Proxied | 现状缺失,必须补;www → 裸域 301 由源站规则或 Cloudflare Redirect Rule 做(canonical 定为裸域 metal-divider.com) |
| MX | @ | 5 mx1.hostinger.com | DNS only | 照抄现行区;以 hPanel Emails → Domain settings 生成值为准 |
| MX | @ | 10 mx2.hostinger.com | DNS only | 同上 |
| TXT | @ | `v=spf1 include:_spf.mail.hostinger.com ~all` | — | 见 §4.3 |
| CNAME | (hPanel 生成的 DKIM 名称) | (hPanel 生成的目标) | DNS only | 默认 DKIM 为 CNAME 类型(可多条),值以面板生成为准,见 §4.3 |
| TXT | _dmarc | 见 §4.3 推荐值 | — | 分阶段收紧 |

- 顺序铁则:**先在 Cloudflare 区内建齐全部记录(含 MX/SPF/DKIM/DMARC),后改 NS**;否则传播期邮箱与站点同时断。

### 2.2 metalpartition.com(全站 301 并入)

- **注册商:Shopify(Tucows)→ Hostinger**。理由:与主域同账号集中管理;Hostinger 支持 NS 修改(→ Cloudflare);.com 转入 +1 年(2027-08-13 → 2028-08-13)。不走 Cloudflare Registrar——它要求域名先以 Cloudflare NS 激活,而 Shopify 代管域名的 NS 改不了(官方可编辑记录类型仅 A/CNAME/MX/TXT/AAAA/SRV,不含 NS;官方另明确不支持 Cloudflare 代理),鸡生蛋无解。
- **转入时的 NS 选择:Keep existing nameservers**(Google Cloud DNS 继续服务,零中断),转入完成后再在 hPanel 切到该域在 Cloudflare 的分配 NS(与主域同一 Cloudflare 账号,两个独立 zone)。
- 目标记录(转入后、切 NS 前,先在 Cloudflare 区内建好):

| 类型 | 名称 | 值 | 备注 |
|---|---|---|---|
| A | @ | `<HOST_IP>` | 与主域同源站;301 → metal-divider.com 由源站规则或 Proxied + Cloudflare Redirect Rule 承载 |
| CNAME | www | @(或同 IP) | www 链路同样 301 到 canonical |
| TXT | _dmarc | `v=DMARC1; p=reject;` | 不发信的域名,直接最严(见 §4.3) |

- **收信:目标态无 MX、无 SPF 发信权**——该域名只做 301,不承载邮箱;DMARC 直接 `p=reject` 防伪造。

## 3. Cloudflare 接入方案与顺序

1. 建一个 Cloudflare 免费账号(或用业主现有账号,由 runbook 票定;两域进同一账号、两个独立 zone)。
2. 先接 **metal-divider.com**:Domains → Onboard a domain → 输入裸域 → 计划选 **Free** → Cloudflare 自动扫描既有记录,**人工核对补齐**(官方明说扫描不保证全;现状 www 缺失、TXT 全无,扫描必然漏)。
3. 在 hPanel 把 NS 替换为 Cloudflare 分配的两个(官方流程:Remove 旧 NS → Add 新 NS,名称逐字一致),最长 24h 生效;域名状态 Active 时 Cloudflare 发邮件确认。
4. **metalpartition.com 的 Cloudflare 区在转入完成后建**(§6 步骤 8);提前建区无意义——NS 改不了,区无法激活。转出前该域的 DNS 变更全部在 Shopify 后台做(官方支持编辑 A/CNAME/MX/TXT/AAAA/SRV;A 记录可改不可删;变更最长 24h 生效)。
5. NS 迁移对 Hostinger 的影响面(官方口径):注册、续费、到期、WHOIS、邮箱服务都不随 NS 走;**唯一变化是 DNS 解析管理入口从 hPanel 换到 Cloudflare dashboard**(Hostinger NS 文档原文:DNS 区管理移至新服务商,后续 DNS 变更在该处做)。邮箱 MX/SPF/DKIM 记录照抄进 Cloudflare 区(§4.3)。
6. DNSSEC:两域当前均无 DS 记录(实测),无「先关再改」前置;Hostinger 转入要求 DNSSEC 关闭,已满足。迁入 Cloudflare 激活后可反向开启 Cloudflare DNSSEC(可选,runbook 票定)。

## 4. 邮箱供给

### 4.1 建箱(Hostinger,免费额度内)

- 路径:hPanel → Emails → 选 metal-divider.com → 建箱 `info@`;MX(mx1/mx2.hostinger.com)已在现行区,开箱即收。SPF/DKIM 的面板入口:Emails → Mailboxes → **Domain settings**(官方文档指定),NS 在 Cloudflare 后按 §4.3 手动建记录(官方:域名 NS 不在 Hostinger 时需手动加)。
- 额度(官方两份限额文档,查询 2026-10-24):
  - 免费邮箱 = **Business Starter 免费试用,随托管套餐送 1 年**:每箱 **1 GB** 存储、**100 封/日出站**(入/出各 100,分开计)。
  - 套餐邮箱数按购买日期版本:2025-12-17 起的 v3 套餐 Single=1 箱 / Premium=2 箱 / Business=5 箱;**Unlimited(2026-07 起替代 Business 在售)在免费期内不限箱数**。
  - 本站只需 1 个 `info@`,任何 v3 套餐(含 Single)都够;1 年后免费期结束,续用需升级 Business Email(约 $1/箱/月量级,在 $20/月预算内;具体价目下单时看)。
  - 100 封/日出站对询盘通知 + 出站队列足够(通知是低频事件流);若未来日发送逼近 100,升级即解,不动架构。
- sales@sunriverinverter.com 在 de-linker 侧继续用,不动。
- 测试邮件纪律照 AGENTS.md §5:一次一封、只发指定测试地址。

### 4.2 发信通道(PHP mail() → Hostinger 出站)

wp_mail 走 PHP mail()(de-linker 同款),信由 Hostinger 邮件服务器投递,信封域 = metal-divider.com,与 §4.3 三防记录的同域假设一致;不引入外部 SMTP。

### 4.3 三防记录(推荐值)

| 记录 | 值 | 依据/说明 |
|---|---|---|
| SPF(TXT @) | `v=spf1 include:_spf.mail.hostinger.com ~all` | **Hostinger 官方现行默认值**(SPF 文档原文给出);一域只许一条 TXT SPF;参照系 sunriverinverter.com 同源(其多出的 `_spf.mlsend.com` 是 MailerLite,新站不用) |
| DKIM | hPanel → Emails → Mailboxes → Domain settings 启用后**按生成值照抄 CNAME**(默认为 CNAME 类型,可能多条) | 官方 DKIM 文档:默认记录为 CNAME,值在面板生成、未在文档正文硬编码;NS 在 Cloudflare,记录手动建在 Cloudflare 区,**DNS only** |
| DMARC(TXT _dmarc) | 起步 `v=DMARC1; p=none; rua=mailto:info@metal-divider.com; adkim=s; aspf=s` → 验证 2–4 周报告干净 → `p=quarantine` → `p=reject` | 站点与邮箱同域发信,严格对齐(adkim=s/aspf=s)安全;rua 落在自有 info@(报告量大可再开专用箱,quota 内暂时不必)。Cloudflare 官方 DNS 文档示例即 p=reject 形态;分阶段是本票推荐,runbook 票可按上线时点直接定档 |
| metalpartition.com DMARC | `v=DMARC1; p=reject;` | 零发信域名直接最严;无 SPF(无发信授权)、无 MX |

## 5. metalpartition.com 迁出 Shopify 流程(官方文档口径)

前置事实(实测 + RDAP):注册商 Tucows、Shopify 代管;注册 2026-08-13;**ICANN 60 天锁(购入/转入/改联系人后 60 天)已于 2026-10-12 到期**——只要不再动注册人联系信息,随时可转。这是解约链上唯一的日历约束,而它已经清零。Hostinger 转入另有两条前置检查:距上次续费 ≥45 天(避免续费回退;该域 2026-08-13 注册后未续费,72 天 > 45 天,满足)、距到期 ≥7 天(到期 2027-08-13,满足)、DNSSEC 关闭(已满足)。

1. **转出前、域名仍在 Shopify 时**,在 Shopify 后台 Settings → Domains → 域名 → DNS 设置,把 A(@)从 Shopify IP 改为 `<HOST_IP>`、www CNAME 同步改,让 301 矩阵先在 Shopify DNS 上生效(§6 步骤 4–5)。A 记录可改不可删;变更最长 24h 生效。**不要动注册人联系信息**(改联系人触发新的 60 天锁,Shopify 与 ICANN 政策均列此条)。
2. Settings → Domains → 域名 → Domain settings → **Transfer to another provider** → Confirm。发起即自动解锁,**授权码(auth/EPP code)直接显示在域名信息页**(不等邮件)。可选:顺手关掉该域自动续费,防转出期间扣款。
3. 到 Hostinger **Transfer Domain** 页(非新购):输入 metalpartition.com → 付款(≈续费价)→ 录入 EPP 码 → **NS 选项选 Keep existing nameservers** → 下单后确认转出邮件。刚解锁就录 EPP 若被拒,官方建议等几小时重试(锁状态生效有延迟)。
4. 时长:Shopify 与 Hostinger 双方官方口径均为**最长 7 天**完成;Shopify 侧 **30 天内未完成会重新上锁**,须取消重来——发起后立刻在 Hostinger 完成录入。转入期间 NS 被锁不可改(Keep existing 下 Google Cloud DNS 继续应答,服务零中断)。
5. 转入完成 → 在 hPanel 检查该域 NS 仍为 ns-cloud-b*(Keep existing 的效果)→ 建 Cloudflare 区(§2.2 记录)→ Change Nameservers 切到 Cloudflare 分配 NS(≤24h 生效)。
6. HSTS 注记:Shopify 域名带 90 天 HSTS;目标平台(Hostinger + Cloudflare)全程 HTTPS,官方口径无需处理。
7. 例外通道(仅记录):店铺已停用/冻结时仍可经 admin 的 `/access_account/domains` 限页转出——安全网,不进主流程(「转出完成前不解约」的兜底)。

## 6. 切换顺序草案(喂上线 runbook 票)

```
1. 建站票:评审站就绪,<HOST_IP> 产出
2. 评审票:parity 验收(§5「先证后删」的「证」从这步开始积累)
3. Cloudflare 建 metal-divider.com 区(§2.1 全记录)→ hPanel 改 NS → Active
   (清理项:旧区 301→metaldivider.com 随切 NS 退役;www 记录补齐)
4. 301 矩阵在源站/Cloudflare 配好(metalpartition.com 全 URL → metal-divider.com)
5. Shopify 后台改 metalpartition.com 的 A/www → <HOST_IP>(变更 ≤24h 生效)
6. GSC:验证 301 矩阵生效、旧 URL 逐步并入 canonical
   ——AGENTS.md §5 硬门:此步实证通过前,禁止转出与解约
7. Shopify 发起 Transfer to another provider,取授权码 → Hostinger 转入(Keep existing NS;≤7 天,30 天硬限)
8. 转入完成 → Cloudflare 建 metalpartition.com 区 → hPanel 切 NS → Active
9. 抽查 301 矩阵在新链路(Cloudflare)下仍全量生效
10. 解约 Shopify 订阅
```

- **迁出与解约的最小间隔 = 0 个自然日(无强制等待)**:60 天锁已过(2026-10-12),唯一硬依赖是「转出完成才解约」,即步骤 7–8 自身时长(≤7 天 + ≤24h NS 传播)。步骤 9 同日做完再解约是稳健性选择,不是规则要求。
- 日历约束反着记:**任何原因改了注册人联系信息 → 60 天锁重启**,转出窗口重置——runbook 把「不碰 WHOIS 联系人」写成显式禁令;同理,转出发起后 30 天硬限,发起即完成 Hostinger 侧录入。
- 1 年后免费邮箱到期:info@ 续用需升级 Business Email(§4.1),记入运营日历(runbook 票带一句即可)。

## 7. 来源清单(全部查询于 2026-10-24)

| # | 来源 | URL | 用于 |
|---|---|---|---|
| 1 | DNS 实测 | `https://dns.google/resolve?name=<域>&type=<NS/A/MX/TXT/DS>`(另以 cloudflare-dns.com 交叉) | §1 全部记录现状;metaldivider.com NXDOMAIN |
| 2 | Verisign RDAP | `https://rdap.verisign.com/com/v1/domain/<域>` | 注册商、注册/到期日、状态锁、NS |
| 3 | Shopify 官方:Transferring Shopify-managed domains | https://help.shopify.com/en/manual/domains/managing-domain-ownership/transferring-shopify-domains | §5 步骤 2–4(解锁、授权码在域名页、≤7 天、30 天重新上锁、60 天锁三触发、HSTS 90 天、停用店铺通道) |
| 4 | Shopify 官方:Editing DNS settings | https://help.shopify.com/en/manual/domains/managing-domains/edit-dns-settings | 可编辑记录类型(不含 NS)、A 可改不可删、≤24h 生效 |
| 5 | Shopify 官方:Troubleshooting domains | https://help.shopify.com/en/manual/domains/troubleshoot-issues-with-domains | Shopify A 记录变体有效、不支持 Cloudflare 代理 |
| 6 | Cloudflare 官方:Change your nameservers (Full setup) | https://developers.cloudflare.com/dns/zone-setups/full-setup/setup/ | 免费档 Full Setup、扫描不保证全、分配 NS、≤24h、DNSSEC 前置、DMARC/邮件记录示例 |
| 7 | Cloudflare 官方:Update nameservers | https://developers.cloudflare.com/dns/nameservers/update-nameservers/ | 注册商处改 NS 通用流程 |
| 8 | Hostinger 官方:How to change nameservers | https://www.hostinger.com/support/1696789-how-to-change-nameservers-at-hostinger/ | hPanel 改 NS 路径、「DNS 区管理随之移交」、≤24h |
| 9 | Hostinger 官方:How to manage the DNS zone at Cloudflare | https://www.hostinger.com/support/4410549-how-to-manage-the-dns-zone-at-cloudflare/ | Hostinger 官方支持 DNS 放 Cloudflare 的用法 |
| 10 | Hostinger 官方:How to transfer a domain to Hostinger | https://www.hostinger.com/support/1583436-how-to-transfer-a-domain-to-hostinger/ | §5 步骤 3–5(EPP、Keep existing NS、转入期 NS 锁 ≤7 天、60/45/7 天前置、EPP 拒后重试、.com +1 年) |
| 11 | Hostinger 官方:What is the SPF record for Hostinger Email | https://www.hostinger.com/support/1583673-what-is-the-spf-record-for-hostinger-email/ | §4.3 SPF 精确值、单条规则、合并格式、Domain settings 入口、NS 在外部时手动加 |
| 12 | Hostinger 官方:What are the DKIM records for Hostinger Email | https://www.hostinger.com/support/4456413-what-are-the-dkim-records-for-hostinger-email/ | §4.3 DKIM 为 CNAME、面板生成、NS 在外部时手动加 |
| 13 | Hostinger 官方:Parameters and limits of Hostinger Mail | https://www.hostinger.com/support/4625828-parameters-and-limits-of-hostinger-email/ | §4.1 免费试用(1 年/1 GB/100 封日)、30 天域名试用、付费档参数、升级路径 |
| 14 | Hostinger 官方:New web and cloud hosting limits | https://www.hostinger.com/support/10717644-new-web-and-cloud-hosting-limits-at-hostinger/ | §4.1 v3 套餐邮箱数(1/2/5)、Unlimited 免费期不限箱、免费邮箱 1 年 |
