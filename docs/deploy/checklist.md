# 部署核对表(人工步骤与配置面)

依据:docs/specs/20-staging-deploy.md(research/spec-deploy 分支)+ 业主 2026-10-24 晨间修订(评审站与生产都挂现有 Hostinger 订阅作附加站点,不购新订阅)。部署工具是 `tools/deploy`(`just deploy …`,配方见 `tools/deploy/deploy.just`);本清单只列工具做不了、需要人在面板/浏览器里做的事,部署窗口逐项打勾。

## 1. 首次部署前(主机侧,一次性;需要业主在场)

**订阅与站点**(修订后:全部挂 de-linker 同款现有订阅 u388076051)

- [ ] hPanel 把 `metal-divider.com` 添加为该订阅的附加网站(此时 NS 仍在 Shopify/Hostinger parking,站点经 hPanel 预览域可见可验)
- [ ] hPanel 把 `staging.metal-divider.com` 添加为第二个附加网站(自有子域)
- [ ] hPanel DNS 编辑器建 `staging` A 记录 → 订阅主机 IP(NS 切换前 DNS 区还在 parking;#9 步骤 3 切 NS 后在 Cloudflare 区内重建,灰云)
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
- [ ] Cloudflare:`staging` 记录灰云(DNS only)

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
| quic.cloud / CDN 结对 | 不连(Cloudflare 承担 CDN 面) | ◐ |
| 失效 | 默认保存钩子 + 每次部署步骤 6 `wp litespeed-purge all`(工具自动跑) | ◐ |

验收断言(工具自动,`just deploy verify --target prod`):全清后匿名 GET 首页两次,响应头 `x-litespeed-cache` 首次 miss、二次 hit。

## 4. 缓存配置(Cloudflare 免费档)

| 配置面 | 值 | 核对 |
|---|---|---|
| DNS | `@`/`www` 橙云(Proxied);`staging` 灰云 | ◻ |
| SSL/TLS | Full (strict) | ◐ 一次性 |
| **APO** | **禁开**(LiteSpeed 官方兼容表:不用 APO 才兼容;双重缓存冲突) | ◐ 一次性,禁改 |
| Cache Rules / Cache Everything | **不建任何规则**:免费档默认只按扩展名缓存静态资产、HTML/JSON 不缓存、尊重源站 Cache-Control——HTML 缓存唯一归属 LiteSpeed | ◐ 一次性,保持空 |
| **部署窗口协议** | 部署前 **Development Mode ON**(免费档,旁路边缘缓存 3 小时自动过期,不会忘关);部署 + WindPress 编译 + verify 在窗口内完;Dev Mode 过期后 CF 自动回源取新 `tailwind.css` | ◑ 每次部署 |
| purge(备用) | 仅 Dev Mode 不可用时手工 purge by URL(免费档 5 次/分钟)清 `tailwind.css` 单文件 | |

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

1. Cloudflare Development Mode ON(§4)
2. `just deploy staging --write`(评审站先收,内容导入与评审在评审站跑全量)
3. 评审通过 → `just deploy prod --write`(同一产物集合:快照 → 构建 → 上传 → 原子 rename 切换 → opcache 重置 → present → LiteSpeed 全清 → verify)
4. WindPress 编译:本地 `just deploy windpress-compile dev`;评审站/生产经 admin(WindPress → Settings → Performance → Generate),然后 `just deploy windpress-check --target <target>`
5. verify 全绿(端点 200 + 图案页 + 生产侧 x-litespeed-cache miss→hit)
6. 失败即 `just deploy rollback --target <target> --write`(rename 回上一版,零写库;库需同回退配 `just deploy restore-snapshot <name> --write`)
7. 测试询盘一封(收件人重写开关,只发指定测试地址)

## 7. 备份纪律(AGENTS.md §5)

- 代码部署:自带 DB 快照(流回 `~/metal-divider-local/snapshots/`,留 20 份,主机零落盘)+ 原子切换保留上一版
- 数据写入(导入、媒体管线 `just deploy media --target <site> --write`):先完整备份 `just deploy backup --target <target> --write`(增量 `--link-dest`,存本机 `~/metal-divider-local/backups/`)
- 完整备份每周一次;部署日志会提醒超期
- 首次生产写入前,在票上记录备份与回滚方式
