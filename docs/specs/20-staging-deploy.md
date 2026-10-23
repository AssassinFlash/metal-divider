# 20 · 评审站与生产部署管线(定稿)

票 #20(Grilling,执行会话按推荐定稿,业主保留否决;AGENTS.md §2 2026-10-24)。本文档定稿五项:评审站形态、生产实例主机侧清单、部署工具平移裁剪、缓存落地配置表、发信队列参数。**本票不做任何真实主机操作**——主机侧动作全部留给执行票;已做的只有只读查证(SSH 只读探查 de-linker 主机 + Apify 文档取证,§7)。

输入:#9 域名规格、#14 本地栈规格、#3 多语言研究缓存节、de-linker 仓库部署纪律与 `tools/cutover/` 实装(只读)、#7 询盘链(URL 面)。

## 0. 结论速览

| # | 事项 | 结论 |
|---|---|---|
| 1 | 评审站形态 | **独立 WP 实例,子域 staging.metal-divider.com**(独立库、独立目录);否决同库子目录,否决 Hostinger 内建 staging 工具(Business+ 专属且方向相反,§1.1) |
| 2 | 主机账号拓扑 | metal-divider 生产+评审站在**新的独立托管订阅**(同业主 Hostinger 账号第二份订阅),与 de-linker 订阅(u388076051)资源池隔离;**v3 Premium 档起步**(3 网站/10 库/20 GB,生产+评审站占 2/3) |
| 3 | 评审站防护 | hPanel 口令保护目录(basic auth)+ noindex 三层 + 评审站零真信(wp_mail 落盘)+ Cloudflare 灰云;页面缓存评审站显式关 |
| 4 | 生产版本合同 | 按 #14 `versions.py` 钉版:WP 7.1.3 / Woo 11.2.1 / PHP 8.3(平台实测 8.3.35 与本地完全同版)+ **litespeed-cache 7.9.2 新增进钉版**;本地钉版=主机合同 |
| 5 | 对象缓存时点 | **首期生产不启用对象缓存**——平台事实:共享托管的对象缓存=LSMCD,Business+ 专属;Premium 无后端(§2.3)。页面缓存承担全部匿名流量;未来启用=升 Business+ 演练,独立运维步 |
| 6 | 部署工具 | de-linker `tools/cutover/` 平移裁剪为 `tools/deploy/`:文件面=自研插件+mu-plugins+子主题(原子 rename 切换+rollback-deploy);LC 模板与 WindPress 卷经 present(DB 面);媒体走独立分块管线(数据面) |
| 7 | 缓存 | LiteSpeed 页面缓存生产开(平台 LSWS 全机队标配;Business 门槛只挡对象缓存不挡页面缓存);Cloudflare 免费档默认只缓存静态扩展、HTML 不缓存、**APO 禁开**、部署窗口用开发模式(3h);CookieYes 纯客户端零缓存交互 |
| 8 | 发信 | 出站队列限速 **8 封/分钟、80 封/滚动 24h**;重试梯 **1/5/30 分钟 + 2/6/12/24 小时**;平台限额 10/分钟、100/滚动 24h 已按现行文档逐字核实(§5.1);实际套餐字段执行票从 hPanel 读数 |

**与目的地草图的显式偏差(业主可否决)**:wayfinder 目的地写「LiteSpeed+Redis」。本票裁定:本地栈 Redis 照 #14(实例对象缓存);**生产侧共享托管无 Redis 后端**(对象缓存后端是 LSMCD,且 Business+ 才有)——首期生产只开 LiteSpeed 页面缓存,Redis Object Cache 插件生产激活但不 enable(零效果、保持插件面一致)。依据是平台官方文档实测口径(§7 来源 5)+ de-linker 生产同样从未启用对象缓存(#222 评审后搁置至今)。升级 Business(≈预算内)即可补上 LSMCD,启用带 §2.3 演练——路径保留,不弃用。

---

## 1. 评审站(决策 1)

### 1.1 形态裁定:独立 WP 实例的子域站

**结论:staging.metal-divider.com 作为独立 WordPress 实例**(独立数据库、独立 `~/domains/staging.metal-divider.com/public_html/` 目录),与本站生产实例同订阅,由同一套部署工具按 target 区分推送。

否决的两条路,各有一组致命理由:

- **同库子目录(如 metal-divider.com/staging/)否决**:评审站必须可随意重置、可跑与生产不同版本的插件/主题而不影响生产。共享数据库意味着评审站的插件激活、选项、试错写入全部落进生产的备份与快照纪律——「代码部署=自带 DB 快照」的分层(AGENTS.md §5)被搅浑,评审站一次试错就能污染生产库。子目录 URL 还继承主域的收录与 canonical 信号,防索引要多打一层补丁。评审站与生产的唯一共享物是订阅级资源,§1.2 处理。
- **Hostinger 内建 staging 工具否决**(官方文档核实,§7 来源 4):
  1. **Business 档及以上专属**——「you can create and manage these environments directly from Hostinger dashboard if you have a Business web hosting plan or higher」;为它买 Business 不值(§2 的订阅裁定是 Premium)。
  2. **方向相反**:它的发布动作是「Push to live」——**用 staging 的文件+数据库整体覆盖生产**("Publishing replaces your live files and database with the staging copy… changes made to your live website after the staging environment was created will be overwritten and lost")。我们的真源在 git 仓库,管线方向是 本地 → 评审站 → 生产,评审站**从来不是部署源**;一个「从评审站整库覆盖生产」的按钮在管线里是纯风险。
  3. 黑盒:克隆/回推不走我们的原子切换、快照、回滚工具;且「creates staging environments for root domains」(子域要手工)。
  我们只需要评审站「能收部署、能被评审」——独立实例天然会做,还更便宜。

### 1.2 主机账号拓扑与资源隔离(同账号防串)

实测事实(2026-10-24 SSH 只读,`ssh hostinger-de-linker`):de-linker 订阅(u388076051)`~/domains/` 下已挂 **de-linker.com 与 sunriver-inverter.com 两域**;家目录存有 2026-07/08 的 MySQL 用量告警文件——共享订阅资源水位是现实问题,不是理论。PHP 8.3.35(CLI,与本机 Homebrew php@8.3 8.3.35_1 同版)、php -m 有 redis+igbinary、WP-CLI 2.12.0(`/usr/local/bin/wp`)、PHP 构建带 `--enable-litespeed`。共享订阅的 CPU/RAM/进程/MySQL 用量**全订阅共享**,一个站拖上限,同订阅所有站一起受害(503)。

**裁定:metal-divider 生产 + 评审站建在新购的第二份托管订阅**(同业主 Hostinger 账号,hPanel 统一管理;SSH 用户、家目录、资源池独立)。理由:① de-linker 生产(在营业务站)不能成为我们的爆炸半径——建站期内容导入、评审站反复重置、部署 rsync 突发都是共享资源风险;② 反向同理,共用量连累时排查界面混乱;③ 成本:v3 Premium 档(2025-12-17 后购买即 v3:**3 网站、10 数据库、20 GB 盘、1 vCPU/2 GB RAM**;§7 来源 3)在 $20/月总预算内,生产+评审站占 2/3 网站位、2/10 库位、媒体终稿+WP 余量充足。

同订阅内生产与评审站的隔离面:

| 面 | 生产 | 评审站 | 防串手段 |
|---|---|---|---|
| 数据库 | 独立库 | 独立库 | 独立库独立凭据;部署工具按 target 定根,预检拒绝错配 |
| 代码目录 | `~/domains/metal-divider.com/public_html/` | `~/domains/staging.metal-divider.com/public_html/` | 独立目录;原子切换的 `.next`/`deploy-prev` 各在各根外 |
| uploads | 各自 `wp-content/uploads/` | 同左 | 代码部署永不触碰 uploads(de-linker 同律);媒体管线按 target 分流 |
| 对象缓存 | 不启用(§2.3) | 不启用 | 无 drop-in 即无共享键问题;未来启用时 `WP_REDIS_PREFIX`/lscwp 前缀按站分 |
| 邮件 | PHP mail() 真发(队列限速 §5) | **mu-plugin 挂 `pre_wp_mail` 截获落盘,零真发** | 评审站的表单试提交不产生真询盘通知;评审站也没有可用的发信理由 |
| cron | hPanel 每分钟 `wp cron event run --due-now`(`--path` 指生产根;`DISABLE_WP_CRON`) | 同左,`--path` 指评审站根 | 按 path 隔离 |
| 索引 | 正常收录 | noindex | §1.3 |
| 发信配额 | 独立订阅=独立 100/滚动 24h PHP mail 配额 | 落盘不发 | 与 de-linker 订阅的配额互不占用(§5.1) |

### 1.3 评审站的访问防护

1. **basic auth:hPanel → Websites → Dashboard → Advanced → Password Protect Directories**,选评审站根目录(官方文档核实存在,§7 来源 2;`.htaccess`/`.htpasswd` 等价手工路径亦可用——面板版免手工,且平台自己管 PWPROTECTID 块)。选服务器层口令而非 PHP 门槛:不依赖 WP 与插件状态,评审站白屏也拦得住。
2. **防索引三层**:① WP 设置「建议搜索引擎不索引本站」(`blog_public=0`,前台 `noindex` meta);② 评审站专属 mu-plugin(`md-staging-guards.php`)补 `X-Robots-Tag: noindex, nofollow` 响应头(与本地栈隔离件同款行为,#14 §1.4);③ 同 mu-plugin 发 robots.txt 兜底 `Disallow: /`。
3. **Cloudflare 灰云**:staging 记录 **DNS only(灰云)**——评审站不要 CDN 介入,缓存行为与生产不同这件事对评审站是特性(评审看内容正确性,不看命中)。
4. **TLS**:staging 子域在 hPanel 配免费 SSL 后再开评审(basic auth 走明文 HTTP 会裸奔口令);hPanel 对站点(含预览域)自动签证书是平台既有行为(§7 来源 6),子域证书在执行票实证。
5. **页面缓存评审站显式关**(lscwp 页面缓存开关 OFF;对象缓存也不启用):basic auth 与页面缓存的交互不必赌服务器行为;评审环境要的是每次请求都新鲜。缓存行为的验收在生产部署的 verify 里做(§4.1)。

### 1.4 评审站的 DNS 两阶段与初始化

- **DNS**:staging.metal-divider.com 的 A 记录指向新订阅主机 IP。NS 切换前该域的 DNS 区还在 Hostinger parking(nova/cosmos)——**第一阶段在 hPanel 的 DNS 编辑器里建这条 A 记录**(建站+评审期即可用);#9 步骤 3 切 NS 时,同一条记录**在 Cloudflare 区内重建(灰云)**,评审 URL 全程不变。(#9 目标态记录表需补这一条,见 §6。)
- 评审站**只收部署,不外发**:`just deploy staging` 推与生产完全相同的部署物;评审通过 → 同一产物集合+同一流程推生产。首次初始化:本地实例装好 → 部署到评审站 → 内容导入管线在评审站跑全量 → 评审。评审站库可随时从本地重灌,无独有数据。

---

## 2. 生产实例(决策 2)

### 2.1 主机侧步骤清单(留执行票;本票只列清单与裁定)

```
1. 业主 Hostinger 账号新购共享托管订阅:v3 Premium 档(3 网站/10 库/20 GB;下单时核对
   版本口径=v3,§7 来源 3)。不需要 Business:内建 staging 与 LSMCD 对象缓存都用不上
   (§1.1 否决、§2.3 裁定);要升级时是 hPanel 就地升档,不动数据
2. hPanel 把 metal-divider.com 添加为该订阅网站(此时 NS 仍在 Hostinger parking,
   站点经 Hostinger 预览域可见可验——平台机制:Websites → ⋮ → Website Details 里的
   preview domain,官方文档 §7 来源 6;正式可达等 #9 的 Cloudflare NS 切换)
3. hPanel 添加 staging.metal-divider.com 为第二网站(2489693:添加网站可选自有子域);
   hPanel DNS 编辑器建 staging A 记录 → 订阅主机 IP(§1.4 第一阶段)
4. hPanel PHP 版本选 8.3(平台实测 8.3.35 与本地 php@8.3 完全同版;#14 §2.3 版本合同)
5. hPanel 建两库(生产/评审站),独立 DB 用户;凭据进本机 ~/.config/metal-divider/(600)
6. hPanel 开 SSH;新 ssh 别名进 ~/.ssh/config(参照 hostinger-de-linker 写法)
7. 两站各配免费 SSL;staging 配目录口令保护 + 评审 mu 件随首次部署上(§1.3)
8. 两站各设 hPanel 每分钟 cron:wp cron event run --due-now(--path 各指各根);
   wp-config 写 DISABLE_WP_CRON;不使用 hPanel「WordPress Overview → LiteSpeed」开关
   (那是 Business+ 的 LSMCD 路径,§2.3;插件按钉版自装)
9. 装机:WP 7.1.3 + 插件面全按 #14 versions.py + litespeed-cache 7.9.2(§2.2);
   wp.org 件 wp plugin install --version 钉版 + verify-checksums;付费件从本机
   ~/metal-divider-local/archives/ 收档(SHA-256 先核)rsync 上传;picostrap5 父主题
   同法(一次性装机件,不随部署走)
10. wp-config 骨架:WP_POST_REVISIONS=5、DISABLE_WP_CRON、表前缀按站分(md_/stg_);
    不写任何 Redis 常量(无 drop-in;本地栈的 WP_REDIS_* 留在本地)
11. 许可激活:真实网络下正规激活 LiveCanvas / SEOPress PRO /(ACF,业主确认后);
    ACF 版本警示(#14 §2.2:zip 是 6.3.1,官方 6.8.10 含安全修复)装机前向业主报备
12. 固定链接:/%postname%/ + Woo 改写基(products/ collections/ pages/ 按 #4 底账);
    /collections/all → /shop/ 重写优先级在评审站实测(#4 遗留点)
13. 首次部署 + present + 媒体管线(§3)→ 评审站全量过 → 生产复刻 → #9 切换序列接管
```

### 2.2 版本对齐(#14 合同的落地面)

- **本地钉版=主机合同**(#14 §2.3):主机逐项对上 `tools/local/src/md_local/versions.py`;装完 `wp core version`、`wp plugin list` 读回比对,不一致即失败。PHP 8.3 平台实测可行(de-linker 生产 WP 7.1.3 + Woo 11.2.1 兼容 8.3,#14 已引)。MySQL 随订阅供给,不钉(de-linker 同策略;mysqldump 流式备份不受小版本影响)。
- **LiteSpeed Cache 插件新增进钉版:7.9.2**(wp.org 现行,2026-10-24 API 读;Requires PHP 7.4 / Tested WP 7.1.3;#14 同法核对)。它不在 #14 首期插件面——本票补进 `versions.py`,本地与主机同样激活:本地天然惰性(页面缓存需要 LiteSpeed 服务器模块,`php -S` 没有),插件面一致、行为按环境分化。
- 上线后合同方向翻回「主机 → 本地」(de-linker 式):主机侧任何升级先在本地实例验证同版本再动。

### 2.3 对象缓存:首期不启用(时点裁定)

**平台事实(修正一条旧猜测)**:de-linker 规格曾写「Hostinger 提供的对象缓存 或 wp plugin install redis-cache + 主机 Redis 套接字,二选一按实测」——按实测=没有二选:Hostinger 共享托管的 WordPress 对象缓存后端是 **LiteSpeed Memcached(LSMCD)**,经 LiteSpeed Cache 插件的对象缓存模块启用,且「This feature is available for Web Business hosting plans or greater」(§7 来源 5);**共享主机没有可连的 Redis 服务**,phpredis 扩展在平台 PHP 里存在不代表有守护进程可达。

**裁定:首期生产零对象缓存。**

- Premium 订阅无 LSMCD → 生产不装对象缓存 drop-in;Redis Object Cache 插件仍按 #14 插件面激活(本地连 Homebrew Redis 用;生产 `wp redis status`=Not enabled,零效果、零风险)。
- 性能由 LiteSpeed 页面缓存承担(§4.1):纯目录站的匿名流量全部命中页面缓存,对象缓存只惠及后台与未命中路径——零流量上线期收益趋零,而它的故障模式(缓存与库不一致后读到旧值,de-linker #222 实锤过)恰在内容高频变动期最容易踩。风险与收益不对齐,就不在上线窗口引入。
- **未来启用=独立运维步**(不为恢复「Redis」字面而买):需要时升 Business 档(hPanel 就地升)→ LSMCD 经 lscwp 启用 → **启用前演练**(de-linker #222 条款):停后端 → 改一篇图案内容 → 前台必须读到新值(不是旧缓存)→ 恢复 → 全清 → 再验。通过才留。此路径记入运营日历候选,不是 backlog 承诺。

---

## 3. 部署工具(决策 3):de-linker present/rollback-deploy 的平移裁剪

### 3.1 蓝图与裁剪总表

蓝本:de-linker `tools/cutover/`(uv 项目 linker-cutover + cutover.just 模块)。平移为 `tools/deploy/`(uv 项目 md-deploy + deploy.just),**同一份代码三个 target:`local:<实例>` / `staging` / `prod`**(target 抽象原样,增一个远端 target;`drill` 在本地实例跑 切换→回滚→再切换,证明的就是要跑的那份代码)。

| de-linker 有 | metal-divider | 理由 |
|---|---|---|
| deploy(原子切换)/ rollback-deploy / snapshot / restore-snapshot / backup(增量完整备份)/ drill / probe / maintenance | **全保**(改名换前缀) | 部署纪律本体(AGENTS.md §5)不变 |
| `deployment_artifacts()` 单一库存函数(mu+主题+插件) | **保,换成本站库存**(§3.2) | 同一库存供发布与回滚 |
| rename 原子切换:同 shell 整批 rename、EXIT/HUP/INT/TERM trap、目标与 `~/deploy-prev` 同文件系统预检、隐藏 `.next` 暂存、manifest.json 站外备份、只留最近一批 | **原样平移** | 选 rename 不选 symlink 的全部理由(`__DIR__`/plugin_basename/opcache 键)对本站同样成立;残余风险窗口收敛到整批 rename 段、计时写回执,同款 |
| 部署第一步 DB 快照(mysqldump 经标准流直传本机、主机零落盘、失败即不切换) | **原样平移**(无排除表——本站无 genie_chunks 级巨表) | `wp db export` 该平台不可用(disable_functions 含 exec,de-linker #81 实证);本机留 20 份 |
| opcache 经 web 请求重置(一次性令牌 mu-plugin) | **原样平移**(`md-deploy-reset.php`) | 主机 `opcache.validate_timestamps=1`、`revalidate_freq=2`(de-linker #308 实测,同平台;CLI 与 web 是两个 opcode 池,只能经 web 请求重置) |
| probe 部署期只读探针 | **保,端点换本站**:首页/图案清单/图案页/询盘页/博客档案;加 `x-litespeed-cache` 头断言(§4.1) | 生产实走验收载体 |
| present(`wp linker-commerce present`:LC 模板+partial+WindPress 卷) | **平移为 md-inquiry 的 present 子命令**(§3.2 DB 面) | 表现层落库面 |
| windpress-compile.mjs(一次性登录→后台编译→mtime+锚类断言) | **原样平移**(锚类来自本站插件) | Tailwind v4 唯一编译路径 |
| 收件人重写开关(测试邮件纪律) | **保** | AGENTS.md §5:一封、只发指定测试地址;多收件路由以队列收件人数据断言 |
| services/engine/genie 发布、VPS、语言包、旧插件删除/snippets 导出、301 抽验、Search Console 段 | **裁** | 本站无 Genie/engine/VPS;首期无 Polylang 无语言包(de-linker 2026-10-15 事故的语言包缺失面不存在);全新装机无旧插件遗产;Search Console 归上线 runbook 票 |
| 快照排除 wp_genie_chunks、德语 URL 回归 | **裁** | 无对应物 |

### 3.2 部署物清单(三面分层)

**文件面(rename 原子切换,单一库存,与 de-linker 完全同构):**

| # | 部署物 | 源(仓库) | 目标(实例) | 说明 |
|---|---|---|---|---|
| 1 | 自研询盘插件 `md-inquiry` | `plugins/md-inquiry/`(composer build 本机完成) | `wp-content/plugins/md-inquiry/` | 清单 JS、表单、出站队列、WP-CLI present |
| 2 | mu-plugins | `mu-plugins/*.php` | `wp-content/mu-plugins/` | `md-deploy-reset.php`(opcache)、`md-error-recorder.php`(平台 log_errors=OFF,错误可见性唯一靠它——de-linker 同平台实证)、`md-staging-guards.php`(noindex 头+robots 兜底+wp_mail 落盘;仅评审站装配,生产库存函数按 target 过滤);平台 mu(hostinger-*)不动 |
| 3 | 子主题 `md-child` | `themes/md-child/` | `wp-content/themes/md-child/` | 父主题 picostrap5=档案装机件(§2.1 步骤 9),不进部署面 |

**DB 面(文件切换后、verify 前;非原子,由部署自带的 DB 快照兜底):**

| # | 部署物 | 机制 | 说明 |
|---|---|---|---|
| 4 | LiveCanvas 动态模板 | `wp md-inquiry present`(幂等安装/刷新) | 图案清单/图案页/博客四面/询盘页/感谢页模板源随仓库走 |
| 5 | WindPress tokens 卷 | 同 present | 设计 tokens 进卷 |
| 6 | WindPress 缓存 | `tools/windpress-compile.mjs`(一次性登录→后台编译) | 每次内容变更后必跑;mtime 变新+锚类在产物断言;跑完接 §4.2 的 Cloudflare 窗口动作 |

**数据面(独立管线,前置完整备份;不在代码部署内):**

| # | 部署物 | 机制 | 说明 |
|---|---|---|---|
| 7 | 媒体终稿 | **分块 rsync 管线**:按 uploads/年月子目录逐块 tar 流、每块独立连接——主机掐长传输(de-linker 实测 2.6 GB/约 8 分钟即断,分块是铁律)+ 附件行导入(导入票) | #2 底账 585 MB 原图的压缩终稿;「上传的必须是处理完成的」(AGENTS.md §2);代码部署永不触碰 uploads |
| 8 | 146 图案 + 17 页内容 | 导入票管线(#10 导出→写库) | 数据写入=先完整备份 |

### 3.3 部署流程(生产;staging 同流程换 target)

```
0. drill:本地实例 切换→回滚→再切换 全绿(首次与库存函数变更后必跑)
1. DB 快照(mysqldump 流回本机 ~/metal-divider-local/snapshots/,留 20 份)
2. 本机构建(composer;无前端构建面)+ 全部部署物上传到各 live 同目录隐藏 .next
3. 单条远端 shell 原子 rename 整批切换(trap 全批恢复);计时行回执
4. opcache 重置(web 令牌请求);wp plugin list 确认激活面
5. present(LC 模板+WindPress 卷)→ windpress-compile(浏览器编译+锚类断言)
6. LiteSpeed 全清 + Cloudflare 部署窗口动作(§4.2)
7. verify:probe 端点全 200 + 关键内容断言 + x-litespeed-cache 头断言;
   测试询盘一封(收件人重写开关,邮件纪律)
8. 通过 → 备份按 rename 移到站外 ~/deploy-prev/<id>/files/,读回 inode,删上一批
   失败 → rollback-deploy(rename 回上一版,零写库;库需同回退时配 restore-snapshot)
```

- rollback-deploy 语义原样:消耗上一版、无 redo、不碰库;manifest/inode 逐项预检,混批缺失即零写入。
- 每次部署在最新完整备份 >7 天时提醒;完整备份只在数据写入前+每周,增量 `--link-dest`(de-linker #305 分层)。

---

## 4. 缓存落地(决策 4)

### 4.1 LiteSpeed Cache(生产开、评审站关)

平台事实:Hostinger 全机队跑 LiteSpeed Web Server(2019 年起替换 Apache,§7 来源 7);页面缓存是 LSWS 内建能力+免费 lscache 插件,无档位门槛——官方把档位门槛给的是**对象缓存**(LSMCD,Business+),不是页面缓存(§7 来源 5)。de-linker 生产从未启用 lscwp(其 13-genie.md 实证)——本站是首次启用,配置自证,不存在「抄现行配置」。

| 配置面 | 值 | 理由 |
|---|---|---|
| 页面缓存(生产) | **开**;默认 TTL;上线首部署即生效 | 「一上来就完美」含性能;匿名流量全靠它 |
| 页面缓存(评审站) | **显式关**(§1.3) | 评审要每次新鲜;auth×缓存交互不赌 |
| 登录用户/后台 | 不缓存(默认) | wp-admin/wp-login 默认排除 |
| WooCommerce | lscwp 官方 Woo 兼容默认(Woo 在确认兼容区,#3 已引) | 本站无购物车/结账(#7 拒绝购物车),cart/checkout 排除面实际不存在;产品归档/单品页可缓存 |
| 询盘表单 `/inquiry/` | **缓存**(不排除) | 表单是静态页;POST 天然不缓存(lscwp 不缓存非 GET);Turnstile 挂件纯客户端 |
| 感谢页 `/inquiry/thank-you/` | 缓存(纯静态文案、无 PII,#7 D5) | noindex 与缓存不冲突 |
| CookieYes | **零特殊配置**;禁止给 cookieyes cookie 加缓存旁路 | lscwp 默认排除表只含登录/评论类 cookie;横幅纯客户端 JS 读自己的 cookie——缓存 HTML+JS 横幅是正确形态;加旁路会把同意过的访客全打出缓存 |
| Polylang(二期) | 浏览器语言检测保持关闭(默认关);子目录 URL 天然独立缓存键 | #3 三坑之二,记入二期配置清单 |
| ESI | 不用 | 公开页面无按人片段 |
| optm(CSS/JS 优化)/图片压缩/lazyload | **全关** | WindPress 编译产物即终稿;服务器零运行时转换(AGENTS.md §2);minify 与 WindPress 产物打架 |
| lscwp 对象缓存模块 | **关** | 对象缓存整体不在首期(§2.3);drop-in 无人管理 |
| quic.cloud / CDN 结对 | 不连 | Cloudflare 承担 CDN 面 |
| 失效 | 默认保存钩子(文章/页面/产品更新清该页与相关列表)+ **每次部署步骤 6 全清** | 部署后旧页=验收红灯 |
| 验收断言 | 部署 verify 里:全清后匿名 GET 首页/图案页,响应头含 `x-litespeed-cache`(命中标记;首次 miss 后二次 hit) | 页面缓存在 Premium 生效的实证(首次部署即测,不等到线上看秒开) |

### 4.2 Cloudflare(免费档;全部按现行官方文档核实,§7 来源 8)

| 配置面 | 值 | 理由 |
|---|---|---|
| DNS 记录 | `@`/`www` **橙云(Proxied)**(#9 目标态);`staging` **灰云** | 生产走 CDN;评审站不要缓存层介入 |
| SSL/TLS 模式 | Full (strict) | 源站有真实证书(hPanel 免费签) |
| **APO** | **禁开** | LiteSpeed 官方兼容表:「Compatible if you are not using Cloudflare APO」(#3 三坑之一,一手来源)——与 LiteSpeed 页面缓存双重缓存冲突 |
| 默认缓存行为 | **不建任何 Cache Rule、不开 Cache Everything**:免费档默认只按文件扩展名缓存(CSS/JS/图片/字体等;官方明言「does not cache HTML or JSON by default」)且尊重源站 Cache-Control(private/no-store 即不缓存) | HTML 缓存唯一归属 LiteSpeed;Cloudflare 只做静态资产 CDN——两层各管一层,零交互推理负担;免费档 10 条 Cache Rules 的额度留着不用 |
| wp-admin | 默认即不缓存(HTML);不加规则 | 少一条规则少一个错配面 |
| WindPress 产物 `/wp-content/windpress/cache/tailwind.css` | **部署窗口协议**:部署前 Cloudflare Development Mode ON——免费档,临时挂起边缘缓存 3 小时自动过期(官方: suspends edge caching for three hours,且是旁路不是清除);部署+编译+verify 在窗口内完;Dev Mode 过期后 CF 重新回源取新 css | 文件名稳定、内容随编译变;默认扩展名缓存(TTL 默认 120m)会在窗口外供旧样式;比养 API token 做精准 purge 轻,自动过期不会忘关 |
| 开发模式 | 仅部署窗口(#9 已裁定同用途) | 同上 |
| purge(备用) | 免费档可用 purge by URL(5 次/分钟);仅在 Dev Mode 不可用时手工清 tailwind.css 单文件 | §7 来源 8 |
| Turnstile | 与缓存无交互(挂件客户端渲染,表单页可缓存) | — |
| 二期 Polylang | 子目录 URL 独立缓存键;不依赖 cookie vary(免费档默认也无) | #3 |

### 4.3 CookieYes 与缓存交互(汇总)

#3 三坑之三的落地:横幅语言在 CookieYes 账号逐语言配置——**首期只有 EN 横幅,无多语言问题**;二期 9 语种时按 #3 落地要点逐语言配。横幅不跟随 URL 语言、与 Polylang 无 API 耦合,对缓存的含义都是「零服务端 vary」:缓存层不需要知道同意状态,JS 自己知道。与 4.1 的零特殊配置互为表里。

---

## 5. 发信参数(决策 5)

### 5.1 平台限额(现行文档逐字核实,2026-10-24)

| 通道 | 限额 | 来源 |
|---|---|---|
| 服务器 PHP mail()(脚本发信) | **10 封/分钟、100 封/天,滚动 24h 窗口**——官方原文:「a fixed limit of 10 emails per minute and up to 100 emails per day… resets on a rolling 24-hour basis」 | Hostinger 计划参数文档(§7 来源 3);与 de-linker 2026-10-09 取证(mail-root-cause.md)一致,现行未变 |
| 邮箱 info@(免费期 Business Starter 1 年档) | 100 封/天(入/出各 100)、1 GB;付费 Business Starter=1000 封/天 | Hostinger 邮箱限额文档(§7 来源 5);本站 wp_mail 走 PHP mail()(#9 §4.2),邮箱档位限额只是参照 |

**标注**:以上是 Hostinger 通用文档值;metal-divider 新订阅的实际套餐字段(PHP mail 限额是否随档位浮动、邮箱档位)由执行票从 hPanel 读数核实后回填,不推测——照 de-linker 的做法(实际套餐字段部署时读 hPanel,spec 11 同款)。独立订阅附带红利:本站 100/24h 配额与 de-linker 订阅互不占用。

### 5.2 队列参数(定稿)

承 de-linker Outbox/Throttle 实装(plugins/linker-commerce/includes/Mail/,平移进 md-inquiry):

| 参数 | 值 | 理由 |
|---|---|---|
| 限速 | **8 封/分钟、80 封/滚动 24h** | de-linker 同值(Throttle 常量 8/80):在 10/100 限额下留 20% 余量,突发毛刺不触顶;本站询盘通知是低频事件流,量级远够 |
| 重试梯 | **1 分钟、5 分钟、30 分钟、2h、6h、12h、24h** | de-linker 同梯(`RETRY_DELAYS` 实值 60/300/1800/7200/21600/43200/86400):总跨度超过滚动 24h 窗口——配额耗尽的拒绝在窗口移走后仍会被再试到 |
| 一次尝试 | To/Bcc 同封同发;重试不重复已送达邮箱 | de-linker Outbox 语义 |
| 客户回执(若启用) | 日上限 10 封(独立于队列限速) | de-linker ReceiptLimit 同值(100/24h 的十分之一);#7 D4 客户确认 From=info@ |
| 测试模式 | 收件人重写开关:一封、只发指定测试地址;多收件路由以队列收件人数据断言,不多封实发 | AGENTS.md §5 测试邮件纪律原文 |
| 评审站 | wp_mail 截获落盘,零真发 | §1.2 |

---

## 6. 与相邻票的接口

- **上线 runbook 票**(map「Not yet specified」):§2.1 清单是其主机侧输入;DNS/301/解约顺序完全按 #9 §6,本票不重写。**#9 目标态记录表补一条**:staging.metal-divider.com A 记录(灰云;两阶段:hPanel 区先建、Cloudflare 区重建)。
- **执行票(建站)**:消费 §2.1 全部步骤 + §3.2 部署物清单 + §3.3 流程。
- **导入票**:消费 §3.2 数据面(媒体分块管线约束 + 完整备份前置)。
- **#14 实现票**:`versions.py` 增 litespeed-cache 7.9.2 钉版;mu-plugins/ 目录名对齐 §3.2;本地 wp-config 的 Redis 常量维持(生产不写)。

## 7. 来源清单(查询/实测日期均为 2026-10-24;除标注外均经 Apify)

| # | 来源 | 用于 |
|---|---|---|
| 1 | de-linker 仓库(只读):AGENTS.md §5、docs/specs/17-cutover.md(原子切换/快照分层 #305/opcache #308)、docs/specs/11-commerce.md(Mail 配额与重试梯)、docs/specs/5-local-stack.md(Redis 前缀/FLUSH 教训)、tools/cutover/(deploy/rollback-deploy/snapshot/target 实装)、tools/windpress-compile.mjs | §3 全部、§5 参数 |
| 2 | Hostinger:Password-protected directories(hostinger.com/support/1583470 + docs.hostinger.com/websites/password-protected-directories) | §1.3 |
| 3 | Hostinger:Parameters and limits of hosting plans(sendmail 10/分钟、100/天滚动 24h 逐字确认)+ New web and cloud hosting limits(v3 档位表:Premium=3 网站/10 库/20 GB/1 vCPU/2 GB) | §1.2、§2.1、§5.1 |
| 4 | Hostinger:How to create a WordPress staging environment(Business+ 专属;Push to live 覆盖生产文件+库;根域 only;外部 NS 手工建 staging A 记录)+ docs.hostinger.com/wordpress/staging | §1.1、§1.4 |
| 5 | Hostinger:How to manage Object Cache for WordPress(LSMCD;Business+ 专属;经 LiteSpeed Cache 插件) | §2.3、§4.1 平台事实 |
| 6 | Hostinger:How to access your website content without a domain(preview domain 机制) | §2.1 步骤 2 |
| 7 | Hostinger blog:Introducing LiteSpeed(2019 起全机队 LSWS 替换 Apache) | §4.1 |
| 8 | Cloudflare 官方:default-cache-behavior(默认按扩展名缓存、HTML/JSON 不缓存、尊重 Cache-Control)、Cache Rules(免费档可用、10 条)、Development Mode(3 小时、旁路非清除)、Purge(免费档 by URL,5 req/min) | §4.2 |
| 9 | LiteSpeed:第三方兼容表(Cloudflare「Compatible if you are not using Cloudflare APO」;Woo 确认区)——#3 已取,本票引用 | §4.1、§4.2 APO |
| 10 | wp.org 插件 API:litespeed-cache 现行 7.9.2(api.wordpress.org,#14 同法) | §2.2 |
| 11 | de-linker 主机 SSH 只读探查(PHP 8.3.35、php -m redis/igbinary、/usr/local/bin/wp→2.12.0、~/domains/ 两域同订阅、--enable-litespeed、MySQL 告警文件、平台 mu 件) | §1.2、§2.2、§4.1 |
| 12 | #9 / #14 / #3 / #7 / #4 / #2 已闭票规格(本仓库 research 分支) | 输入 |
