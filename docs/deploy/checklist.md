# 部署核对表(人工步骤与配置面)

依据:docs/specs/20-staging-deploy.md(research/spec-deploy 分支)+ 业主 2026-10-24 晨间修订(评审站与生产都挂现有 Hostinger 订阅作附加站点,不购新订阅)。部署工具是 `tools/deploy`(`just deploy …`,配方见 `tools/deploy/deploy.just`);本清单只列工具做不了、需要人在面板/浏览器里做的事,部署窗口逐项打勾。

## 1. 首次部署前(主机侧,一次性;需要业主在场)

**订阅与站点**(修订后:全部挂 de-linker 同款现有订阅 u388076051)

- [ ] hPanel 把 `metal-divider.com` 添加为该订阅的附加网站(此时 NS 仍在 Shopify/Hostinger parking,站点经 hPanel 预览域可见可验)
- [ ] hPanel 把 `staging.metal-divider.com` 添加为第二个附加网站(自有子域)
- [ ] hPanel DNS 编辑器建 `staging` A 记录 → 订阅主机 IP(DNS 区**永驻 hPanel**,不做 NS 迁移;业主 2026-10-25 终版裁定)
- [ ] PHP 版本选 8.3(平台实测 8.3.35,与本地 php@8.3 完全同版)
- [ ] 建两库(生产/评审站),独立 DB 用户;凭据进本机 `~/.config/metal-divider/`(600)
- [ ] 开 SSH;`~/.ssh/config` 加 `hostinger-metal-divider` 别名(参照 `hostinger-de-linker` 写法,同一账号)。凭据绝不入仓库(AGENTS.md §4)
- [ ] 两站各配免费 SSL(staging 先配证书再开评审——basic auth 走明文 HTTP 会裸奔口令)

**装机**(工具的 `contract` 检查逐项读回比对,不一致即失败)

- [ ] WP 7.1.3 + 插件面全按 `tools/local/src/md_local/versions.py`(wp.org 件 `wp plugin install --version` + `verify-checksums`;付费件从 `~/metal-divider-local/archives/` 收档 SHA-256 先核;picostrap5 父主题同法,一次性装机件)
- [ ] `just deploy contract --target staging` / `just deploy contract --target prod` 全绿
- [ ] wp-config:表前缀生产 `md_`、评审站 `stg_`;`WP_POST_REVISIONS=5`、`DISABLE_WP_CRON`;不写任何 Redis 常量
- [ ] hPanel 每分钟 cron:`wp cron event run --due-now`(`--path` 各指各根);不使用 hPanel 的 LiteSpeed 对象缓存开关(Business+ 的 LSMCD 路径,首期零对象缓存,规格 §2.3)
- [ ] 许可激活(LiveCanvas / SEOPress PRO;真实网络下正规激活)

## 2. 评审站防护(首次部署后即核对)

- [ ] hPanel → Websites → Dashboard → Advanced → Password Protect Directories:选评审站根目录
- [ ] WP 设置「建议搜索引擎不索引本站」(`blog_public=0`)
- [ ] 评审站 mu 件 `md-staging-guards.php` 随部署自动上(仅评审站装配;noindex 头 + robots.txt 兜底 + wp_mail 落盘零真发)——`just deploy verify --target staging` 时带 `MD_DEPLOY_STAGING_AUTH=user:pass` 环境变量过口令保护
- [ ] lscwp 页面缓存开关 OFF(评审站每次都要新鲜)
- [ ] Turnstile 挂件把 `staging.metal-divider.com` 加进允许的 hostname(Cloudflare 的唯一身份;零 DNS 改动)

## 3. 缓存配置(LiteSpeed,生产)

| 配置面 | 值 | 核对 |
|---|---|---|
| 页面缓存 | **开**,默认 TTL | ◻ |
| 登录用户/后台 | 不缓存(lscwp 默认) | ◻ |
| WooCommerce | lscwp 官方兼容默认 | ◻ |
| `/inquiry/`、`/inquiry/thank-you/` | 缓存(不排除;POST 天然不缓存) | ◻ |
| CookieYes | **零特殊配置**;禁止给 cookieyes cookie 加缓存旁路 | ◻ |
| ESI | 不用 | ◐ 默认即关 |
| optm(CSS/JS 优化)/图片压缩/lazyload | **全关**(WindPress 编译产物即终稿,minify 会与它打架) | ◐ 默认即关 |
| 对象缓存模块 | **关**(首期零对象缓存,规格 §2.3) | ◐ |
| quic.cloud / CDN 结对 | 不连(无前置 CDN:**DNS 永驻 hPanel,Cloudflare 仅 Turnstile**,业主 2026-10-25 终版裁定) | ◐ |
| 失效 | 默认保存钩子 + 每次部署步骤 6 `wp litespeed-purge all`(工具自动跑) | ◐ |

验收断言(工具自动,`just deploy verify --target prod`):全清后匿名 GET 首页两次,响应头 `x-litespeed-cache` 首次 miss、二次 hit。

## 4. 前置 CDN(无)

**无前置 CDN。** 业主 2026-10-25 终版裁定:DNS 永驻 hPanel 区直写,任何 NS 迁移不做;**Cloudflare 的唯一身份是 Turnstile 挂件**(账号级,零 DNS)。站点前只有平台自身的 `hcdn` 边缘与 LiteSpeed(生产实测响应头 `server: hcdn`、`x-litespeed-cache`,无 `cf-ray`),由平台默认处理,本项目不配置。

因此本节**没有任何部署窗口动作**:

- 无 Development Mode 开关(没有可旁路的边缘缓存,旧版清单的这一项作废)
- 无缓存规则、无 APO、无手工 purge
- 页面缓存的唯一归属是 LiteSpeed(§3),静态资产的唯一归属是源站(无边缘改写)
- 部署窗口只需下列步骤(§6),WindPress 编译照旧——它的缓存落在源站 uploads 目录,LiteSpeed 与浏览器都在其后

## 5. 发信队列参数(md-inquiry 落地后随实现票核对)

| 参数 | 值 |
|---|---|
| 限速 | **8 封/分钟、80 封/滚动 24h**(平台限额 10/分钟、100/滚动 24h 下留 20% 余量) |
| 重试梯 | **1 分钟、5 分钟、30 分钟、2h、6h、12h、24h**(总跨度超过滚动 24h 窗口) |
| 一次尝试 | To/Bcc 同封同发;重试不重复已送达邮箱 |
| 客户回执(若启用) | 日上限 10 封(独立于队列限速) |
| 测试模式 | 收件人重写开关:一封、只发指定测试地址(AGENTS.md §5 测试邮件纪律) |
| 评审站 | wp_mail 截获落盘,零真发(§2) |

## 6. 部署窗口序列(工具跑,人守着)

`just deploy drill --instance <instance>` 全绿是前置(本地实例 切换→回滚→再切换)。窗口内:

1. `just deploy staging --write`(评审站先收,内容导入与评审在评审站跑全量)
2. 评审通过 → `just deploy prod --write`(同一产物集合:快照 → 构建 → 上传 → 原子 rename 切换 → opcache 重置 → present → LiteSpeed 全清 → verify)
   - 日志里 `opcache-reset=` 必须是 `done`。`unavailable` = PHP 里没有 opcache 可重置,`none` = 15 次尝试没有一次拿到插件答复(2026-10-26 生产实测:`none` 的成因是 web SAPI 没有 `HOME`,已修为按 ABSPATH 定位 token 目录)
3. WindPress 编译:本地 `just deploy windpress-compile dev`;评审站/生产经 admin(WindPress → Settings → Performance → Generate),然后 `just deploy windpress-check --target <target>`
4. verify 全绿(端点 200 + 图案页 + 生产侧 x-litespeed-cache miss→hit)
5. 失败即 `just deploy rollback --target <target> --write`(rename 回上一版,零写库;库需同回退配 `just deploy restore-snapshot <name> --write`)
6. 测试询盘一封(收件人重写开关,只发指定测试地址)

## 7. 备份纪律(AGENTS.md §5)

- 代码部署:自带 DB 快照(流回 `~/metal-divider-local/snapshots/`,留 20 份,主机零落盘)+ 原子切换保留上一版
- 数据写入(导入、媒体管线 `just deploy media --target <site> --write`):先完整备份 `just deploy backup --target <target> --write`(增量 `--link-dest`,存本机 `~/metal-divider-local/backups/`)
- 完整备份每周一次;部署日志会提醒超期
- 首次生产写入前,在票上记录备份与回滚方式
