# 23 · 安全层 mu-plugins 移植范围规格

状态:已定稿(Grilling 票,执行会话按推荐定稿,业主保留否决;AGENTS.md §2 2026-10-24)。
蓝本:de-linker 仓库 `mu-plugins/`(只读参考,四件 + 自有 Composer 项目形态:静态检查 + PHPUnit + 对本地实例的冒烟)。本站环境差异:Hostinger 共享主机(同平台,另一账号)、Cloudflare 免费档前置、LiteSpeed 页缓存(#20 在写)、**无账户系统**(wp-admin 只有员工)、询盘表单用 Turnstile、CookieYes 同意横幅、wa.me 点击跳转。

## 0. 结论一览

1. **移植两件**:`linker-site-guards.php` → `mu-plugins/md-site-guards.php`(平移 + 扩展:CSP 上线、XML-RPC 关闭、作者枚举拦截);`linker-error-recorder.php` → `mu-plugins/md-error-recorder.php`(平移改名,参数沿用,日志目录 `wp-content/md-errors/`)。
2. **不迁一件、延后一件**:`linker-engine-pin.php` **确认不迁**(本站无 VPS 服务,无可钉扎对象);`linker-deploy-reset.php` **延后归 #20**(它是部署机制的形状,不是站点属性;接口假设见 §6.2)。
3. **新增守卫**(进 md-site-guards.php):XML-RPC 全关 + 移除 X-Pingback/rsd_link;`?author=N` 与作者归档拦截。**登录保护不写代码**——接口 = Cloudflare 免费档 rate-limit 规则罩 `/wp-login.php`(业主面板侧,#20 记录)。
4. **测试形态沿用**:独立 Composer 项目(phpcs/phpstan/PHPUnit 9.6);单测用 PHP 自建临时目录;冒烟对本地实例跑,进 `just mu-smoke [name]` 配方(补进 #14 配方表);CI `mu-smoke` job(裸实例、零 secrets)随实现票一并启用,不等自研插件。
5. **部署**:两文件进 #20 部署物清单;初建顺序 recorder → guards → 其余;安全头在页缓存命中下必须仍在(#20 实测项,被剥则退 .htaccess,见 §6.3 接口假设)。

---

## 1. 移植清单总表

| de-linker 件 | 结论 | metal-divider 文件 | 配置差异(对 Hostinger + Cloudflare 前置的适配) |
|---|---|---|---|
| linker-site-guards.php | 平移 + 扩展 | `mu-plugins/md-site-guards.php` | 四个既有头原值保留;新增 CSP(白名单见 §2.2);新增三面守卫(§2.3)。头只在源站发,Cloudflare 侧不重复配置 HSTS(单一来源) |
| linker-error-recorder.php | 平移改名 | `mu-plugins/md-error-recorder.php` | 目录 `linker-errors` → `md-errors`;轮转 256 KB/保留一份沿用;首部署读回核验新账号的 `log_errors`/`display_errors`(§3) |
| linker-engine-pin.php | **不迁** | — | 本站无 VPS 服务:engine/Genie/检索是 de-linker 的产品面,#14 已裁掉 serve/index 等配方。钉扎对象(server-to-server 自签证书调用)不存在,没有可钉的证书。伴随文件 `linker-engine-ca.pem` 同不迁;`.gitignore` 已有 `*.pem` 兜底,误带也进不了仓库 |
| linker-deploy-reset.php | **延后归 #20** | (未来 `md-deploy-reset.php`) | 见 §6.2:绑定部署工具形状(de-linker 的 token 路径 `~/.config/terravolt-builder/`),metal-divider 部署工具未定;LiteSpeed LSPHP 的 opcache 行为待新账号实测。#20 定部署机制时按接口移植 |

## 2. md-site-guards.php 定稿

### 2.1 安全头差异表

mu 件的定位沿用蓝本:头与守卫是**站点自身的属性**,必须在普通插件激活/更新/坏掉的间隙仍然成立。钩子沿用蓝本(`send_headers` 优先级 1;该动作只在前端触发,后台不带这些头——蓝本已接受,维持)。

| 头 | de-linker 值 | metal-divider 值 | 理由 |
|---|---|---|---|
| Strict-Transport-Security | `max-age=31536000; includeSubDomains` | **同** | 唯一天然 HTTPS 域。Cloudflare 前置适配:**只在源站发,Cloudflare 面板不开自己的 HSTS**(两个来源必漂移;#20 记录为面板检查项)。`includeSubDomains` 覆盖评审站子域,子域同走 HTTPS 不受影响。不设 preload(首发不自我锁死) |
| X-Frame-Options | `SAMEORIGIN` | **同** | 无任何第三方需要框嵌本站;与 CSP `frame-ancestors 'self'` 双保险(旧浏览器吃 X-FO) |
| X-Content-Type-Options | `nosniff` | **同** | 无环境差异 |
| Referrer-Policy | `strict-origin-when-cross-origin` | **同** | 跨站(含 wa.me 点击)只带 origin,不泄露完整 URL(询盘 URL 带三段 source 参数) |
| **Content-Security-Policy** | (无) | **新增**,值见 §2.2 | 新栈第三方面(Turnstile/CookieYes)显式收口;蓝本站无 CSP 先例,本站补上 |
| **X-Pingback** | (WP 默认发) | **移除**(随 XML-RPC 关闭,§2.3) | 无 pingback 用例(博客不接受回引) |

不进首期的头(无实测需求,记为证据触发):Permissions-Policy、Cross-Origin-Opener-Policy、Cross-Origin-Embedder-Policy、CSP report-uri/report-to(无收集端点;评审期靠浏览器控制台观察,见 §7)。

### 2.2 CSP 白名单表

头值定稿(CSP 一行,此处展开):

```
default-src 'self';
script-src 'self' https://challenges.cloudflare.com https://cdn-cookieyes.com;
style-src 'self' 'unsafe-inline';
img-src 'self' data: https://cdn-cookieyes.com;
font-src 'self';
connect-src 'self' https://*.cookieyes.com https://cdn-cookieyes.com;
frame-src https://challenges.cloudflare.com;
object-src 'none';
base-uri 'self';
form-action 'self';
frame-ancestors 'self'
```

白名单逐面依据:

| 面 | 需要的条目 | 依据(2026-10-24 直读) |
|---|---|---|
| Cloudflare Turnstile(询盘表单,managed 模式 implicit 渲染) | `script-src` + `frame-src`: `https://challenges.cloudflare.com`;`connect-src` 已含 `'self'`(pre-clearance 模式才需要,本站不用,天然满足) | developers.cloudflare.com/turnstile/reference/content-security-policy:script-src 与 frame-src 各加 challenges.cloudflare.com;官方另荐 nonce 方案,但见下"script-src 策略" |
| CookieYes(同意横幅,WordPress 插件) | `script-src`/`img-src`: `https://cdn-cookieyes.com`;`connect-src`: `https://*.cookieyes.com https://cdn-cookieyes.com`;`style-src` 必须 `'unsafe-inline'` | cookieyes.com/documentation/content-security-policy 官方表:script-src 'self' cdn-cookieyes.com;style-src 'self' 'unsafe-inline';connect-src 'self' *.cookieyes.com cdn-cookieyes.com;img-src 'self' cdn-cookieyes.com;object-src 'none' |
| wa.me(WhatsApp 点击跳转) | **零条目**。`<a href="https://wa.me/…">` 是顶层导航,CSP 的 resource 指令不管导航;`form-action 'self'` 只管表单提交,不影响链接 | CSP 是资源加载策略,顶层导航不受限(规范语义);线上站实测 wa.me 均为普通链接(2026-10-24 抓取 metalpartition.com,wa.me/8613…、wa.me/8613…) |
| 站内(LiveCanvas/WindPress/yansir-md/SEOPress/picostrap5 子主题) | 全部 `'self'`:样式与字体经 WindPress 编译成本地文件;图标字体本地。`img-src` 加 `data:`(CSS/LC 的 data URI)。深链图片终稿全部本地(业主裁定:服务器不做运行时转换) | #14 插件面;AGENTS.md §5 呈现层规则 |
| 收口指令 | `object-src 'none'`、`base-uri 'self'`(防 base 注入重写相对 URL)、`form-action 'self'`(询盘表单只提交本站;Turnstile siteverify 是服务端 PHP 调用,不吃浏览器 CSP) | 标准 WP 站面;#07 询盘链 D7 |

**script-src 策略:首发不带 `'unsafe-inline'`**。理由:CSP 的价值集中在 script-src;Turnstile(外链 api.js)与 CookieYes 横幅(外链 cdn-cookieyes.com)都不需要内联脚本,CookieYes 的配置块是 `type="application/json"` 数据块非执行脚本。若评审站实测(CookieYes 当前版本)确有内联脚本需求,唯一回退是 `script-src` 追加 `'unsafe-inline'`——**该回退必须以控制台实测违例记录为证据**,写进评审记录后才允许,不许预防性加入。

### 2.3 新增守卫(蓝本没有、本站要加)

| 面 | 实现 | 结论依据 |
|---|---|---|
| XML-RPC 全关 | `add_filter( 'xmlrpc_enabled', '__return_false' )` + `wp_headers` 过滤器移除 `X-Pingback` + `wp_head` 移除 `rsd_link` | 无任何 XML-RPC 客户端(无 Jetpack、无移动 App、无 pingback 用例);共享主机+Cloudflare 上 `system.multicall` 是爆破放大面。蓝本没关是因为它的面不在此;本站无理由留 |
| REST 用户表守卫 | 蓝本原样平移(`rest_pre_dispatch` 拒绝无 `list_users` 者访问 `/wp/v2/users`) | 本站无客户账户,枚举出来的只有员工登录名——面更小但仍是密码攻击的前半段,保留成本为零 |
| 作者枚举拦截 | 前端 `?author=<数字>` 一律 301 回首页;`is_author()` 归档请求 301 回首页(`template_redirect` 优先级 1) | 登录名枚举的两个入口之一(REST 已堵);无客户账户的站不需要公开作者页(博客 IA 无作者页链接,#19 页面清单无此页型),堵死无副作用 |

### 2.4 明确不做(评估过、否决)

| 面 | 结论 | 理由 |
|---|---|---|
| 登录保护(限次、锁定、Turnstile 上登录页) | **不写代码** | mu 件只收"站点属性";限次/锁定是有状态逻辑,属插件领域(栈已裁定不用 Wordfence)。接口:Cloudflare 免费档的 rate-limiting 规则(免费含 1 条规则)罩 `wp-login.php`,面板侧配置,#20 的部署记录里写清;被实测攻击再升级(Turnstile 上 wp-login 是现成扩展点,md-inquiry 的 Turnstile 封装可复用) |
| 询盘 CPT 的 REST 面 | **不加守卫** | `md_inquiry` 是非 public CPT、不注册 REST 控制器(#07 D6),`/wp-json/wp/v2/md_inquiry` 天然 404。冒烟加一条回归锚:断言该路由 404,防止未来注册参数漂移(见 §5) |
| WooCommerce 产品 REST | 不动 | Store API(`/wc/store/v1/*`)是目录数据的公开设计面,内容本来就公开;写入面要认证。收口只会弄坏前台 |
| Permissions-Policy / COOP / COEP | 不进首期 | 无实测需求、蓝本无先例;出现证据(浏览器特性被滥用、embed 需求)再进 |

## 3. md-error-recorder.php 定稿

| 参数 | de-linker | metal-divider | 理由 |
|---|---|---|---|
| 日志目录/文件 | `wp-content/linker-errors/php.log` | `wp-content/md-errors/php.log` | 前缀改 md- |
| 轮转 | 256 KB 触发,保留一份 `php.1.log` | **同** | 共享主机实证参数;Hostinger 同平台 |
| ini 动作 | `log_errors=1`、`display_errors=0`、`error_log` 指自身 | **同** | 前提"主机 `log_errors` 关"在 de-linker 账号实测;**新账号属同平台默认相同,首部署时读回核验**(§7 验收),不是盲信 |
| 防护 | 目录内 deny-all `.htaccess` + 空 `index.html` | **同** | LiteSpeed 原生读 .htaccess(蓝本就跑在 Hostinger LiteSpeed 上);`wp-content` 可 web 访问,堆栈不能公开 |
| 零 WP 依赖 | 不碰数据库/选项/HTTP/自动加载,WP_CONTENT_DIR 未定义则静默不动 | **同** | 记录件必须能在"WP 自己坏掉"时工作——蓝本论证整段照搬 |

**日志与 gitignore 的结论:零新增条目**。日志只存在于两处——生产 `wp-content/md-errors/`(主机上,不在仓库)与本地实例数据根(`~/metal-divider-local/<name>/`,#14:数据根永不进仓库)。仓库里不存在会产出日志的路径;`.gitignore` 已有的 `vendor/`、`*.pem` 覆盖 mu-plugins Composer 项目的构建产物与误带证书的兜底。不需要为日志加规则。

## 4. 命名与项目形态

- 仓库目录 `mu-plugins/`,独立 Composer 项目(同蓝本形态):`require: php >=8.3` + 平台钉 8.3(#14 §2.3 版本合同);dev 依赖 php-stubs、phpstan + szepeviktor/phpstan-wordpress、phpunit 9.6、wpcs 3 + woocommerce-sniffs、phpcompatibility-wp。
- 包名 `md/mu-plugins`;命名空间 `Md\Mu`(类 `Md\Mu\ErrorRecorder`,对齐蓝本 `Linker\Mu`);函数前缀 `md_site_guards_*`;phpcs `PrefixAllGlobals` 前缀 `Md\Mu`、`md_site_guards`;文件名按用途命名(非类名),`FileName.InvalidClassFileName` 排除模式同蓝本。
- phpcs/phpstan/phpunit 配置一项目一份(蓝本同;PHPUnit 9.6 配置间不能共享根元素属性)。静态检查规则集引用 `tools/php/`(蓝本引用 `tools/php/phpcs.xml` 的对应面;#14 实现票建 `tools/` 时对齐,本票先按蓝本路径写,实现时若 #14 落了别的路径以 #14 为准)。

## 5. 测试形态与 just/CI 接口(#14 配方表补一行)

沿用蓝本的三层:

1. **单测(`tests/php/Unit/`,纯 PHP 临时目录)**:记录件轮转(256 KB 边界、保留一份、防护文件生成)、守卫函数的头串与拦截逻辑。CI 无实例也全跑。
2. **集成冒烟(`tests/php/Integration/`,对真实实例)**:按蓝本 SiteGuardsSmokeTest 形态——把文件按部署名装进实例 `wp-content/mu-plugins/`,真实 HTTP 请求断言,测完还原:
   - 匿名首页:五个头在(HSTS/X-FO/nosniff/Referrer/CSP)+ `X-Pingback` 不在;
   - 匿名 `/wp-json/wp/v2/users` → 403 `rest_forbidden`;
   - `/xmlrpc.php` POST `system.listMethods` → 拒绝响应,不返回方法清单;
   - `/?author=1` → 301 首页;`/author/<任意>` → 301 首页;
   - `/wp-json/wp/v2/md_inquiry` → 404(CPT 无 REST 面,回归锚);
   - 记录件:触发一条真实 PHP 错误(WP-CLI eval),读回 `wp-content/md-errors/php.log` 有记录、目录带 `.htaccess` + `index.html`;
   - 环境变量 `MD_MU_WP`(对齐蓝本 `LINKER_MU_WP`;命令词表形式,接 `just wp <name>`),未设则 skip。
3. **just 配方(#14 §5 配方表追加)**:

| 配方 | 作用 |
|---|---|
| `just mu-smoke [name]` | `cd mu-plugins && MD_MU_WP="just wp <name>" vendor/bin/phpunit --testsuite integration`;默认 `dev`;`mu-plugins` 同时进 #14 的 `php_projects` 列表(`just check`/`just test` 覆盖) |

4. **CI(#14 §6.2 的 smoke 槽位,启用时点前移)**:蓝本 mu-smoke job 先例——runner 自带 MySQL/Redis 上建**裸实例**跑同一配方,零 secrets 不破(mu-plugins 无付费档案依赖)。**启用时点 = mu-plugins 实现票本身**,不等自研询盘插件;裸实例模式(md-local `up --bare` 或等效)是实现票与 #14 实现的交接面。CookieYes/Turnstile 的**端到端 CSP 验证不在本地冒烟**——两者不在 #14 首期插件面,发生在评审站(§7)。

## 6. 部署接口(#20)

### 6.1 部署物与写入顺序

- 部署物清单追加两项:`mu-plugins/md-site-guards.php`、`mu-plugins/md-error-recorder.php` → 生产 `wp-content/mu-plugins/`(部署只拷 `*.php`;无 pem)。
- **初建顺序**(第一次把站装出来、尚无原子切换时):**先 md-error-recorder**(它一就位,后续每一步的 PHP 错误都有记录)→ **次 md-site-guards**(此后每个公开响应带安全头)→ 其余代码 → 内容 → 开门前 Turnstile/CookieYes 配置。
- **原子切换部署**(release 切换):mu-plugins 在代码树里,整树一起换,顺序无意义;`rollback` 同树回滚,天然覆盖。mu-plugins 不经任何"插件激活"步骤,到位即生效——这正是蓝本用 mu 形态的理由。

### 6.2 deploy-reset 延后的接口假设

de-linker 的 deploy-reset 解决"原子切换后 LSPHP opcache 陈旧窗口"(蓝本实测:validate_timestamps=1、revalidate 2 秒,切换秒出过两次 fatal)。**接口假设**:#20 若采用原子切换部署(蓝本 present/rollback 平移裁剪的方向),必须处理新账号上同一窗口——方案 = 移植改名 `md-deploy-reset.php`(token 路径改 `~/.config/metal-divider/`,机制整段照搬,90 行机械移植),随 #20 部署票实现、部署冒烟里实测(切换后 2 秒窗口内无 fatal)。#20 若选择非原子部署(逐文件覆盖),该件不需要,写明取舍即可。

### 6.3 缓存与 Cloudflare 交互(接口假设,#20 实测)

| 面 | 接口假设 |
|---|---|
| LiteSpeed 页缓存 vs 安全头 | 头由 PHP 响应带出,页缓存应连同响应头一起回放(LiteSpeed 文档行为:缓存页带原响应头)。**#20 验证项:缓存命中(第 2 次请求)仍带全部五个头**;若 LiteSpeed 实测剥头,回退 = 五个头全部改 `.htaccess` 恒发(LiteSpeed 原生读),guards 头段留空壳或移除——取舍写回本票更新 |
| Cloudflare HSTS | 面板**不开**(源站单一来源,§2.1);SSL 模式 Full (strict) |
| Cloudflare Rocket Loader | **OFF**:改写 `<script>` 标签并注入内联加载脚本,与严格 script-src 冲突;本地资产 + LiteSpeed 已够,它没有存在理由 |
| Cloudflare Scrape Shield → Email Obfuscation | **OFF**(免费档默认开!):混淆 mailto 会注入内联脚本,同样撞 script-src;info@metal-divider.com 本来就是公开信息(AGENTS.md §4),无需混淆 |
| Cloudflare Polish / 缓存 | 不冲突:Polish 经同主机名 `/cdn-cgi/` 回图,`img-src 'self'` 天然覆盖 |
| Cloudflare 自有安全头 | 面板可配的 HSTS/CSP 一律不开,避免双源漂移 |

## 7. 验收(实现票过关线)

1. **静态与单测**:`just check` 对 mu-plugins 全绿(phpcs + phpstan);`just test` 单测全绿(轮转边界、防护文件、头串、拦截逻辑)。CI:`check.yml` 全绿且零 secrets;`mu-smoke` job 在裸实例上绿。
2. **本地冒烟**(`just up dev && just mu-smoke dev`):§5.2 清单逐条过(五头在、pingback 无、users 403、xmlrpc 拒、author 双路 301、md_inquiry 404、错误落 `md-errors/php.log` 且目录双防护文件在)。
3. **生产读回(首部署,§3 的核验)**:SSH 读回新账号 `log_errors`/`display_errors`/`error_log` 生效值与预期一致;首个真实错误进 `wp-content/md-errors/php.log`。
4. **评审站 CSP 实证(#20 交接)**:真 sitekey 的 Turnstile 与真 CookieYes 横幅在 CSP 下工作——浏览器控制台五个页型(首页、图案页、图案清单、询盘页、博客首页/文章页)零 CSP 违例;若 CookieYes 触发 script-src 违例,按 §2.2 的证据规则决定是否回退 `'unsafe-inline'`,记录在案。
5. **缓存命中带头**(评审站):同 URL 第 2 次请求(LiteSpeed 命中)五个头仍在;被剥则按 §6.3 回退并更新本规格。
6. **Cloudflare 面板项**:Rocket Loader、Email Obfuscation 已关;HSTS 未在面板开;三项截图/读回记进 #20 部署记录。
7. **不迁确认落盘**:生产 `wp-content/mu-plugins/` 无 engine-pin、无 pem;仓库 mu-plugins 树只含两件 + 测试。

## 8. 证据清单(核实日期均为 2026-10-24)

| 事实 | 来源 |
|---|---|
| Turnstile CSP:script-src + frame-src `https://challenges.cloudflare.com`;connect-src 'self' 仅 pre-clearance 需要;官方另荐 nonce 方案 | developers.cloudflare.com/turnstile/reference/content-security-policy 直读 |
| CookieYes CSP:script-src/img-src `cdn-cookieyes.com`,connect-src `*.cookieyes.com` + `cdn-cookieyes.com`,style-src 必须 `'unsafe-inline'`,object-src 'none' | cookieyes.com/documentation/content-security-policy 直读 |
| wa.me 为顶层链接导航、不受 CSP 资源指令约束 | 线上站抓取(wa.me/8613068660047、wa.me/8613312892178 均为 `<a href>`);CSP 规范导航语义 |
| 询盘 CPT 非 public、无 REST 控制器;Turnstile managed/implicit、siteverify 服务端调用 | #07 询盘链规格(docs/specs/07-inquiry-chain.md,F9/D6/D7) |
| 蓝本四件的行为、参数、测试形态、CI mu-smoke job 先例 | de-linker 仓库(只读):mu-plugins/ 四件源码与 tests/、justfile mu-smoke 配方、README §目录表 |
| 蓝本 deploy-reset 的动机与实测(opcache revalidate 2 秒、切换秒 fatal) | linker-deploy-reset.php 文件头说明(de-linker 实测记录) |
| 本地栈形态、配方表、CI 零 secrets、smoke 槽位、数据根不进仓库 | #14 本地栈规格(docs/specs/14-local-stack.md) |
| `.gitignore` 已含 `vendor/`、`*.pem`、`*.env` | metal-divider main 分支 .gitignore 直读 |
| Hostinger 共享主机、Cloudflare 免费档、无账户系统、Turnstile/CookieYes 入栈、info@ 公开 | AGENTS.md §1/§2/§4 |
