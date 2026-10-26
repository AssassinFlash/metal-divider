# 研究：是否存在真正零付费的容器托管，可跑 Chatwoot + Dify

- 票：[#104](https://github.com/AssassinFlash/metal-divider/issues/104)（父地图 #86，未改动）
- 研究分支：`research/free-tier-hosting`（worktree `.scratch/research-105-free-hosting`，自 `origin/main` 建立）
- 核查日期：2026-10-26（本文所有来源均为当日访问的官方一手页面）
- 判定前提（业主裁定，不可放宽）：**不付款、不绑卡**；两个服务常驻 7×24；合计约 8GB 内存（Chatwoot ≈4GB + Dify ≈4GB，票面前提，承 #92 系列知识引擎研究）。试用额度（限时信用/试用机时）不算长期方案。

## 结论（二选一收口：证明不存在）

**在"不付款、不绑卡"前提下，不存在可长期 7×24 运行 8GB 级 Docker 负载的托管选项。**所有候选被三层硬限制之一挡死，无一幸免：

1. **绑卡层**：所有能给足内存并长期常驻的（Oracle Always Free、GCP、AWS、Azure、Fly.io、Northflank）都要求注册时提供支付方式；其中 Oracle 明文不接受 PIN 借记卡、虚拟卡、一次性卡、预付卡——变通路也被条款关死。
2. **休眠/资源层**：真正 $0 且可不绑卡的（Render 免费档、Zeabur Free、Replit Starter、HF 免费 Space、Railway 试用后 $1/月）全部休眠、过期或内存 ≤512MB–1GB；Hugging Face 的 Docker Space 自 2025 年起需要付费计划才能创建；Cloudflare Containers 只在 Workers 付费计划（$5/月）开放。
3. **定位/ToS 层**：GitHub Codespaces 免费档只有 120 core-小时/月（2 核机器 ≈60 小时，无法 7×24），且 GitHub 条款明文禁止把 Codespaces 用作 production-facing application；Gitpod 的定价页已改为企业销售联系页（公司已并入 Ona），无公开自助免费档。

### 汇总表

| 选项 | 要绑卡？ | 真正 $0？ | 可 7×24 长期？ | 内存够 8GB？ | 挡住的硬限制 |
| --- | --- | --- | --- | --- | --- |
| Oracle Always Free | **是**（明拒虚拟/预付卡） | 是（额度内） | 有条件（闲置回收） | 勉强（12GB） | **绑卡**；7 天闲置回收 |
| GCP 免费层 | **是** | 是（e2-micro 内） | 是（e2-micro） | **否**（≈1GB） | 绑卡 + 内存 |
| AWS 免费层 | 多数新客户免卡，可能抽查要卡 | 是（额度内） | **否**（6 个月账号自动关闭） | 试用额度内可租 | **时限** |
| Azure 免费 | **是** | 是（额度内） | 12 个月档到期转付费 | 免费档否 | 绑卡 + 时限 |
| Azure for Students | **否** | 是 | 是（年续，需学生身份） | **否**（$100/年 ≪ 8GB VM ≈$840/年） | **资格** + 额度 |
| Fly.io | **是**（所有组织） | **否**（无免费层） | — | — | 无免费层 + 绑卡 |
| Railway | 试用免卡 | **否**（试用后 $1/月） | 是 | **否**（0.5GB/服务） | 内存 + 付费 |
| Render 免费档 | 否 | 是 | **否**（15 分钟休眠） | **否**（0.1CPU/512MB） | 休眠 + 内存；官方"勿用于生产" |
| Koyeb | 未核（已无 $0 档） | **否**（最低 $29/月） | — | — | 无免费档 |
| Northflank Sandbox | 进卡仅验证 | 是（≤$20/月资源） | 是（不休眠） | **否**（8GB 内存即 ≈$48/月） | 资源上限 |
| Zeabur Free | 否 | 是 | **否**（auto-sleep） | 否（serverless 限额） | 休眠 |
| HF Spaces 免费硬件 | 否 | 是 | **否**（闲置休眠） | 名义 16GB 但需付费计划建 Docker Space | **创建门槛** + 休眠 |
| Cloudflare Containers | 否 | **否**（需 Workers Paid $5/月） | — | — | 付费计划门槛 |
| GitHub Codespaces | 否（超额才要） | 是（120 core-时/月） | **否**（≈60 小时/月 @2核） | 单机够（2核/8GB） | **时限 + ToS 禁生产用途** |
| Gitpod | — | — | — | — | 已无公开免费档（转 Ona 企业销售） |
| Replit Starter | 否 | 是 | **否**（发布 30 天过期） | 否 | 过期 + 内存 |
| Hostinger 现有订阅 | 已付费 | — | — | — | **共享主机，无 Docker、无 Node，无长驻进程** |

## 逐项核查记录

### 1. Oracle Cloud Always Free

- **绑卡**：注册必须提供信用卡/借记卡用于身份验证；官方 FAQ 明文："We accept credit cards and debit cards that function like credit cards. **We do not accept debit cards with a PIN or virtual, single-use, or prepaid cards.**" 来源：[Oracle Cloud Free Tier FAQ](https://www.oracle.com/cloud/free/faq/)（2026-10-26）。
- **ARM 额度现状（2026）**：不再是"4 OCPU/24GB"。现行条款：`VM.Standard.A1.Flex` 每月前 **1,500 OCPU 小时 + 9,000 GB 小时**免费，"For Always Free tenancies, this is equivalent to **2 OCPUs and 12 GB** of memory"。另加 2× AMD E2.1.Micro（各 1GB）。来源：[Always Free Resources — OCI 文档](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm)（2026-10-26）。旧的 4/24 口径已失效，证明该额度可被 Oracle 单方收缩。
- **闲置回收**：连续 7 天内 95 百分位 CPU <20% 且网络 <20% 且（A1 形状）内存 <20% → 实例可能被 Oracle 回收。来源同上。客服系统夜间/低峰长期低利用率是真实风险。
- **账号闲置**：账号闲置 30 天以上可能被视为弃用而停用。来源：[FAQ](https://www.oracle.com/cloud/free/faq/)。
- 判定：**绑卡挡死**。即使接受绑卡，12GB 对 8GB 负载余量偏紧，且受闲置回收与额度收缩（2026 年已实际发生 4/24→2/12）双重风险。

### 2. Google Cloud 免费层

- **绑卡**：Free Trial 注册"must provide a credit card or other payment method"，$0–1 临时预授权。来源：[Free Google Cloud features and trial offer](https://cloud.google.com/free/docs/free-cloud-features)（页面标注更新 2026-10-22，2026-10-26 访问）。
- **试用**：$300/90 天；到期或用尽且不升级 → 所有资源停止，30 天宽限期后永久删除。
- **永久免费计算**：仅 **1× e2-micro**（共享 vCPU，约 1GB 内存），限 us-west1/us-central1/us-east1，30GB 标准盘。来源同上。
- 判定：**绑卡 + 内存（1GB）双挡**。

### 3. AWS 免费层

- **时效（关键变化）**：新客户免费计划为 $100 即时额度 + 可再获最高 $100，"**The account closes on its own 6 months after you open it or when your credits run out, whichever comes first.**"来源：[AWS Free Tier](https://aws.amazon.com/free/)（2026-10-26）。旧"12 个月 EC2 免费档"口径已被该信用额度模式取代，无长期免费计算。
- **绑卡**：新注册流程（Sign up for AWS (new)）对多数新客户**不要求**支付方式，但 AWS 保留抽查索卡权利（$1 预授权验证）；高级注册路径则"You can't proceed … until you add a valid payment method"。来源：[How to Create an AWS Account](https://aws.amazon.com/resources/create-account/)、[Sign up for AWS (advanced)](https://docs.aws.amazon.com/accounts/latest/reference/getting-started.html)（2026-10-26）。
- 判定：即使免卡，**6 个月账号自动关闭**是时间硬墙——属试用额度，按前提排除。

### 4. Azure 免费账号 / 学生

- **免费账号**：注册需信用卡或非预付借记卡（香港/巴西仅信用卡），$1 验证预授权；$200 额度 30 天；部分服务 12 个月免费；到期不转付费则账号与服务停用。来源：[Azure account offer details/FAQ](https://azure.microsoft.com/en-us/pricing/purchase-options/azure-account)（2026-10-26）。
- **学生档（唯一免卡路径）**：无需信用卡，$100 额度 12 个月，年续；但仅限**认证的全日制在校生**（学校邮箱验证，MOOC/营利培训机构不符）。来源：[Azure for Students](https://azure.microsoft.com/en-us/free/students)、[offer details](https://azure.microsoft.com/en-us/pricing/offers/ms-azr-0170p)（2026-10-26）。
- 判定：业主为企业主体，学生资格不成立；且 $100/年 养不起 8GB 常驻 VM（按 Azure 市价 2vCPU/8GB ≈$70/月 ≈$840/年）。

### 5. Fly.io

- 定价页原文："**Is there a free tier? No.** New organizations don't have a free tier"；免费试用仅 2 小时机时或 7 天（取先到），试用后须绑卡；"All organizations (except Linked Organizations) require a credit card on file."最小常驻机 256MB $2.19/月。来源：[Fly.io Resource Pricing](https://fly.io/docs/about/pricing/)（2026-10-26）。
- 判定：**无免费层 + 全员绑卡**。

### 6. Railway

- Free 档 $0/月，但明示"Start with a 30-day free trial with $5 credits, **then $1 per month**"；每服务上限 **1 vCPU / 0.5 GB RAM**，卷存储 0.5GB。来源：[Railway Pricing](https://railway.com/pricing)（2026-10-26）。
- 判定：试用后即收费（非真正 $0），且 0.5GB/服务 ≪ 4GB。

### 7. Render

- 免费实例规格 **0.1 CPU / 512 MB RAM**；15 分钟无入站流量即休眠；750 实例小时/月；文件系统临时（无持久盘）；免费 Postgres 30 天过期即删；官方文档原话："**Do not use them for production applications.**"来源：[Deploy for Free](https://render.com/docs/free)、[Compute Plans](https://render.com/docs/compute-plans)（2026-10-26）。
- 判定：**休眠 + 512MB + 官方禁生产**，三挡。

### 8. Koyeb

- 定价页已无任何 $0 档：最低自助计划 Pro $29/月（含 $10 计算），Scale $299/月。来源：[Koyeb Pricing](https://www.koyeb.com/pricing)（2026-10-26）。
- 判定：无免费层。（局限：未核限时促销活动。）

### 9. Northflank

- 免费 Sandbox：2 服务 + 2 jobs + 1 数据库，Northflank Cloud 上**资源上限 $20/月**，vCPU/内存"Limited"；费率 $0.01667/vCPU/时 + $0.00833/GB/时（≈$6/GB/月）→ 8GB 常驻仅内存即 ≈$48/月，远超 $20 上限。FAQ 称进卡仅验证不扣款（暗示绑卡）。来源：[Northflank Pricing](https://northflank.com/pricing)（2026-10-26）。
- 判定：**资源上限**挡死；绑卡要求未逐条核实（见局限）。

### 10. Zeabur

- Free 档 $0/月，但官方文档明示："Services on the Free Plan **auto-sleep** when idle … **If you need services to run continuously, consider upgrading to the Dev Plan**"（Dev $5/月）；Free 档自定义域名 0。来源：[Zeabur Free Plan](https://zeabur.com/docs/en-US/pricing/free-plan)、[Zeabur Pricing](https://zeabur.com/pricing)（2026-10-26）。
- 判定：**休眠**挡死。

### 11. Hugging Face Spaces

- 关键变化：创建跑计算的 Space（Gradio/Docker）**需要付费计划**（个人 PRO 或组织 Team/Enterprise）；免费个人账号仅可建 Static Space 和至多 2 个 ZeroGPU Gradio Space。免费硬件（CPU Basic，名义 2vCPU/16GB/50GB 非持久盘）会"go to sleep after a period of time if unused"，常驻需升级付费硬件。来源：[Spaces Overview](https://huggingface.co/docs/hub/spaces-overview)（2026-10-26）。
- 判定：**创建门槛（付费计划）+ 休眠**双挡；且 50GB 磁盘非持久，数据库无处安放。

### 12. Cloudflare Containers / Workers

- Containers 文档首行："**Available on Workers Paid plan**"（$5/月）。Workers 免费档无容器能力（100k 请求/日、10ms CPU），跑不了 Chatwoot/Dify 这类常驻进程。来源：[Cloudflare Containers docs](https://developers.cloudflare.com/containers/)（2026-10-26）。
- 判定：**付费计划门槛**。

### 13. GitHub Codespaces

- 免费额度：个人免费账号 120 core-小时/月 + 15GB 存储/月（2 核 8GB 机器 ≈ 60 小时/月 ≈ 8.3% 的 7×24 时长）；无卡可用，超额即封停。来源：[GitHub Codespaces billing](https://docs.github.com/en/billing/concepts/product-billing/github-codespaces)（2026-10-26）。
- ToS：GitHub《Terms for Additional Products and Features》对 Codespaces 明文禁止 "Disproportionate server burden (CDN, serverless application, **production-facing application**)" 与"与仓库软件开发/测试无关的活动"。来源：[GitHub site-policy](https://docs.github.com/en/site-policy/github-terms/terms-for-additional-products-and-features)（条款原文经检索镜像核对，见局限）。
- 判定：**时长不足 + ToS 禁生产用途**。

### 14. Gitpod

- 2026-10-26 访问 [gitpod.io/pricing](https://www.gitpod.io/pricing)：页面已变为"Ona"企业销售联系表单，无公开自助免费档（Gitpod Classic 已停，Flex 面向企业）。来源：该页当日快照。
- 判定：**无公开免费档**；即使有也是开发环境定位，非 7×24 托管。

### 15. Replit

- 官方部署计费文档：Starter（免费）档含 1 个已发布 app，"**The deployment expires after 30 days** but can be re-published"；常驻 Reserved VM 属付费档；免费 Repl 数分钟闲置即休眠。来源：[Replit publishing costs](https://docs.replit.com/billing/deployment-pricing)（2026-10-26）。
- 判定：**30 天过期 + 休眠 + 内存**。

### 16. 现有 Hostinger 订阅（SSH 只读实测，2026-10-26）

经 `ssh hostinger-metal-divider` 只读核对，未做任何改动：

- 主机：`de-fra-web1798.main-hosting.eu`（`main-hosting.eu` = Hostinger 共享主机集群），用户 `u388076051`，内核 EL9。
- **无容器运行时**：`which docker podman` → 不存在。
- **无 Node.js**：`which node` → 不存在；可用运行时仅 Python 3.9.25、Ruby、PHP 8.3.36（lsphp）。
- 进程表只有 lsphp PHP-FPM 工作进程与系统进程；共享主机模式（LiteSpeed）不提供常驻后台进程——即便强行驻留也会被宿主清理。
- `free -h` 显示的 502GB 内存是整机（多租户共享）数值，非本站配额。
- 判定：**现有订阅无任何容器/长驻进程能力**；Hostinger 的容器能力在独立产品线 KVM VPS：KVM 2（2 vCPU/8GB/100GB NVMe）促销价 $8.79/月（24 个月预付摊算，续费 $14.99/月），属新增订阅、新增支出。来源：[Hostinger VPS pricing](https://www.hostinger.com/pricing/vps-hosting)（2026-10-26）。

## 最接近可行的选项及代价

按"离目标最近"排序，全部需要突破前提之一：

1. **Oracle Always Free（突破"不绑卡"）**：2 OCPU/12GB ARM + 200GB 块存储，$0 长期，是唯一"不花钱但内存够"的选项。代价/风险：绑卡（且拒虚拟卡）；7 天闲置回收（需保证 95 百分位 CPU/网络/内存 ≥20%，低流量客服系统需人为制造负载，本身违反"闲置回收"规避精神）；Oracle 已在 2026 年单方把额度从 4/24 砍到 2/12，可持续性无契约保障。
2. **Hostinger KVM 2（突破"零付费"，约 $8.79→14.99/月）**：8GB/100GB NVMe，业主已有 Hostinger 账号、运维栈一致（de-linker 同款经验可复用），金额在项目 $20/月预算上限内。这是工程上最稳的付费路径。
3. **架构替代（保持 $0，但放弃自托管）**：放弃"自托管 Chatwoot + Dify"，改用两者的 SaaS 免费档或更轻量的开源单容器方案。这改变了能力边界，属另一张票的决策，不在本票结论内展开。

## 证据局限

- 定价与条款随时可变：本次核查全部基于 2026-10-26 当日页面；Oracle（4/24→2/12）与 AWS（12 个月→6 个月信用制）都在近期实际变过，任何"长期免费"承诺都可能单方收缩。
- "是否绑卡"以官方定价/FAQ 明文为准，未逐家走注册流程实测；AWS 新注册"多数免卡但保留索卡权"、Northflank Sandbox 的支付信息要求属灰区，但两者的结论已分别被 6 个月时限与 $20 资源上限独立挡死，不依赖灰区判断。
- Koyeb 免费层"移除"的判断基于定价页无 $0 档；未排查区域性/限时促销。
- GitHub Codespaces 禁止生产用途的条款原文通过检索镜像（deepwiki 对 github/site-policy 的转述）核对，官方页面本轮未能直接抓取全文；条文含义无歧义，但引用时建议以官方页为准。
- Chatwoot ≈4GB + Dify ≈4GB 的内存需求沿用票面前提与 #92 系列知识引擎研究（`research/dify-knowledge-engine` 等分支），本票未重新实测；若实际需求显著更低（如 ≤1GB），Railway 之外仍无免卡常驻选项，结论不变。
