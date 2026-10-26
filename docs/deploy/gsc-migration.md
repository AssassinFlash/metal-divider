# GSC 接入(metal-divider.com 独立新属性)

执行票:#36。本文只钉当前有效的时序与口径;历史版本的地址变更方案已作废。

## 裁定来源(按日期,后者推翻前者)

| 日期 | 裁定 |
| --- | --- |
| 2026-10-25 | **Shopify 侧为绝对禁区**:metalpartition.com 的域名、DNS、商店、订阅一概不动,不解约、不迁移、不做旧域 301 |
| 2026-10-25 | **DNS 永驻原处**:任何 NS/DNS 迁移一律不做;无前置 CDN/Cloudflare。Cloudflare 唯一身份是 Turnstile 挂件(账号级,零 DNS) |
| 2026-10-25 | **GSC 只建新属性,无地址变更**。旧属性、T3 地址变更工具、T4 双前缀监控期、T5 域名长期持有与 301 不中断——整段作废 |

因此本文只剩一件事:**为 `https://metal-divider.com/` 建一个新的 URL 前缀属性并验证**。旧站继续原样运行,与其并存,彼此无操作关系。

## 属性形态

| 项 | 值 |
| --- | --- |
| 属性 | `https://metal-divider.com/`,**URL 前缀**(非域名属性) |
| 验证方式 | DNS TXT,写入 hPanel 区(Hostinger) |
| 地址变更 | 不做 |
| 站点地图 | `https://metal-divider.com/sitemaps.xml`(SEOPress 原生端点;`/sitemap.xml` 恒 301 至它) |

## 载体:Site Kit by Google

沿用 de-linker 已验证的路径(`docs/specs/145-search-console.md`):Site Kit 建资源、验证并连接 Search Console,**本仓库不为验证写代码**。

2026-10-26 已装并激活 `google-site-kit` 1.190.0 于生产。落地状态实测:

- 每页 `<head>` 有 `<meta name="generator" content="Site Kit by Google 1.190.0">` 与 `googletagmanager.com` 的 dns-prefetch
- **零外部 script**、零控制台错误、零横向溢出(5 个页面实测)
- `googlesitekit_search-console_settings` = `propertyID: ''`,`ownerID: ''`(未授权)
- `googlesitekit_active_modules` = `false`(未开模块)

也就是说:载体就位,授权待办。**不做 GA4、不开匿名使用数据**;GA4 属度量立项,不在本站工程内。

## 剩余步骤(需 Google 账号浏览器登录)

1. 后台 Site Kit → Sign in with Google(业主账号)
2. 建 `https://metal-divider.com/` URL 前缀属性并完成验证
3. 只连 Search Console,不连 Analytics
4. 提交 `sitemaps.xml`
5. 回读:`googlesitekit_search-console_settings.propertyID` 应等于 `https://metal-divider.com/` 且 `ownerID` 非空;连接者有验证文件令牌;`/google<令牌>.html` 不带 cache-buster 返回 200 且含令牌

第 5 步是可机器验证的,`just deploy` 的 verify 段将来可接。**不得删除连接者那个后台用户,不得在 Site Kit 里 Reset / Disconnect。**

## 为什么这一步不能代理

GSC 的验证 token 由 Google 在**建属性时**生成,而建属性要求已登录的 Google 会话。本机没有该会话:

- `~/.config/de-linker/turnstile.env` 只有 de-linker 自己的 Turnstile 密钥(`HOSTNAME=de-linker.com`),与 Google 无关
- `~/.config/`、钥匙串、`~/Downloads/` 均无 Cloudflare 或 Google 账号凭据

替代路径也已核过并排除:

- **DNS TXT(hPanel)**:写入能力已实测确认——`PUT /api/dns/v1/zones/{domain}`,`overwrite:false` 追加不动存量,执行路径 = SSH 到 `hostinger-metal-divider` 跑 curl(见 `seo-verification.md`)。**但 token 本身要 GSC 先建属性才有**,所以 API 在手也开不了这一步。
- **Site Kit 自动验证**:同样要求先登录 Google。

## Turnstile 的独立说明

Turnstile 与 GSC 无关,但同属"需要 Cloudflare 账号"的一类,一并记在此以免混淆。

de-linker 的 `~/.config/de-linker/turnstile.env` 有一对真实密钥,已实测**不可用于本站**:用该 sitekey 在 metal-divider.com 页面渲染返回 **`110200`**(该密钥未授权此域名)。Turnstile 密钥按域名绑定,复用是错误路径,不是保守。

要为 metal-divider 配 Turnstile,须在 Cloudflare Dashboard 新建 widget(domain = metal-divider.com, mode = managed),而 Dashboard 登录态本机同样没有。
