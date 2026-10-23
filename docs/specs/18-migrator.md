# 18 · Shopify→WP 迁移器规格

状态:已定稿(Grilling 票 #18,执行会话推荐即定稿,业主保留否决;AGENTS.md §2 2026-10-24)。
蓝本:de-linker 仓库 `tools/migrate/`(uv 项目 `linker-migrate`,`package = false`,脚本以文件运行;每票 `<n>-<name>.py` 驱动器 + 计划 `.json` + `wp eval-file` 执行的 `.php`;`plan` → `apply --write` 两段式;tests/ 单测 + 环境变量门控的真实实例集成测试)——只读参考,按下述裁剪。
数据输入(全部实测计数):#10 `data/shopify-admin-export/`(products.json 146 品 / 2934 metafields、collections.json 9 系列、pages.json 31 页、redirects.json 16 条)+ #2 `data/shopify-public/media-manifest.json`(1420 文件,refs 分布:product 1063 / page 557 / collection 179 / home 46 / article 21 / blog 15)+ #4 `data/redirect-map.csv`(385 行:339 行仅换域名、46 行路径变化,其中若干 PENDING)。字段映射权威 = #6 `docs/specs/06-product-data-model.md` §9 总表;实例与插件面 = #14(WP 7.1.3 + Woo 11.2.1 + ACF Pro 6.3.1 + SEOPress 10.3/PRO + LiveCanvas + WindPress;dev 实例 9890 起)。
下游:实现票照本文建 `tools/migrate/`;#15(媒体终稿管线)与 #19(页面模板)以 §7 接口假设表对接,假设不成立时按表内退路走,不静默改口径。

## 0. 结论一览

1. **形态:tools/migrate,de-linker 同款**(uv 项目 + 计划 JSON + WP-CLI `eval-file`)。纯 WP-CLI 脚本被否(理由 §1)。唯一有意偏差:#18 一票六阶段,取「单驱动器 `18-migrate.py` + 单 WP 侧 `18-wordpress.php` + 分阶段计划文件」,不做六份 1:1:1 拷贝。
2. **七阶段**:0 plan(计划+预检)→ 1 terms → 2 products → 3 media → 4 pages → 5 redirects → 6 reconcile。与票面建议(products→media→terms→…)的唯一顺序差异是 **terms 提到 products 之前**:每品恰属一个系列(#6 §3),建品时即须挂 term,先品后词等于 146 品全部二次写。
3. **幂等 = 自然键 + 差异更新,非跳过**:产品以 `_sku`(=pattern_code,146 唯一实测)、附件以 `_md_source_url`(=manifest master_src)、term 以 slug、页以 post_name 为键;已存在则逐字段比对、只写差异。幂等的可证定义:第二次 apply 的写入计数为 0,且 reconcile 仍全绿。
4. **对账是一个只读阶段**(reconcile),产出 md+json 报告,任一核心项 FAIL 即非零退出;清单见 §4,核心项无 warn-only 档。
5. **失败处理**:per-item try/catch,单项失败入 ledger 不中断同批;`apply --only <key>` 单品重跑;dry-run 两级(`plan` 零接触 WP;`apply` 不带 `--write` 为只读比对)。断点续跑不需要状态机——幂等保证直接重跑同阶段即续。
6. **WP 侧执行面**:eval-file 的 `.php` 进仓库随迁移器版本化;本地 dev 直连 wp-cli,评审站/生产同一 driver 经 SSH 跑 wp-cli;写入只经 Woo CRUD / WP / ACF / SEOPress API,零原生 SQL 写。

## 1. 形态决策:tools/migrate(uv + 计划 JSON + eval-file)

**采用 de-linker 形态,理由:**

1. **同机同业主已验证**:de-linker `tools/migrate/` 以该形态跑过 13+ 次真实迁移,已纳入其 justfile 的 check/test 面;#14 的本地栈(WP-CLI 2.12.0、PHP 8.3、uv 0.13.4)完全兼容。
2. **两段式免费获得 dry-run 与评审面**:计划 JSON 是人可读的"将写什么"清单,apply 前可审;dry-run 需求(§5)由形态本身满足,不另造机制。
3. **数据连接天然在本地侧**:五份导出 + manifest 的连接(35 key 去留映射、alt 词表→角色解析、CDN URL→本地 URL 重写表、#19 页面清单合并)是纯数据变换,Python 侧可单测(ruff/mypy/pytest,de-linker 同套);塞进 PHP 则难单测。
4. **PHP 侧跑在 WP 进程内**:Woo CRUD、ACF `update_field`、SEOPress API 全部可用,无 HTTP 通道、无鉴权面。

**否决「纯 WP-CLI 脚本」**:失去计划文件评审面;数据连接逻辑无处安放或难测;放弃同款形态等于把 de-linker 踩过的坑再踩一遍(eval-file 协议、base64 载荷、单行 JSON 应答都是现成解)。

**有意偏差(1 处,其余照抄)**:de-linker 一票一份 `.py/.json/.php`,因其各票互不相干;#18 是一票六阶段的连贯管线,六份拷贝会复制同一套 base64-JSON 协议、计划哈希与 ledger 机制。取:

```
tools/migrate/
  18-migrate.py        # 驱动器:<stage> plan|apply|reconcile 子命令;--only、--write、--export-dir、--staging-dir、--wp <target>
  18-wordpress.php     # WP 侧唯一文件:按请求 {stage, action, items} 分发,逐项 try/catch
  plans/18-<stage>.json  # 生成物,不入库(gitignore);含输入文件 sha256 指纹
  tests/               # 纯映射逻辑单测(CI 跑);真实实例集成测试读 MD_MIGRATE_TEST_WP,缺则跳过
  pyproject.toml       # uv,package=false,依赖零(标准库),dev 组 ruff/mypy/pytest——同 de-linker
```

PHP 协议照抄 de-linker `8-engineering.php`:请求 JSON base64 塞文件尾部占位参数;应答 = stdout 标记后一行 JSON;失败 = 异常 + 非零退出。运行目录(计划、ledger、报告)在 `~/metal-divider-local/migrate/`,公开仓库零数据(AGENTS §4)。

## 2. 阶段表

| # | 阶段 | 动作(写入面) | 依赖 | 幂等键 |
|---|------|--------------|------|--------|
| 0 | **plan**(含预检) | 生成六阶段计划 JSON + 预检:导出计数核对(146/9/31/16/1420)、#15 staging 完备性(1063 sha256 全在)、ACF 组 A/B/C/D 字段键可解析、SEOPress 激活且 meta/redirection 键名实测入计划(#6 已裁定"以实装 10.3 为准")、Woo 激活。预检 FAIL 零写入 | #15 输出、acf-json | —(只读) |
| 1 | **terms** | 8 个 `product_cat` term(名=#6 组 D `family` 显示名、slug=系列 slug;gradient 不建)+ ACF 组 D term meta 4 字段(accent_color / difficulty_level / typical_applications / bottom_content) | 无 | term slug |
| 2 | **products** | 146 品,Woo CRUD 单次成型:post_title/post_name(按 #4 映射)/post_content(bodyHtml)/post_excerpt(=pattern_summary 双落)/virtual/instock/空价格/SKU/单系列挂载;再写 ACF 组 A(6)+组 B(2)+组 C(5);再写 SEOPress 标题/描述 | 1 | `_sku` |
| 3 | **media** | 1063 产品图从 #15 staging 本地 sideload 为附件 + alt 原文 + `_md_role`(alt 词表解析)+ `_md_source_url` + `_md_sha256`;再绑 featured(`_thumbnail_id`=旧 media[0])与 gallery(原序) | 2、#15 | `_md_source_url` |
| 4 | **pages** | 17 页壳(post_title/slug/status,清单自 #19)+ 原文 `body_html` 存 postmeta `_md_source_body` + 站内 CDN URL→本地附件 URL 机械重写(同一文件多引用时按 sha256 去重导入一次) | #19 清单、3(重写需附件 URL) | post_name |
| 5 | **redirects** | `redirect-map.csv` 中**路径变化且目标非 PENDING** 的行 → SEOPress PRO redirection(插件已在场,零新插件);PENDING 行(thank-you、博客 IA)记 held 不算 FAIL | 4(目标页存在) | source path |
| 6 | **reconcile** | 只读对账,§4 清单,md+json 报告,FAIL 非零退出 | 全部 | —(只读) |

域名级 339 行(仅 metalpartition.com→metal-divider.com、路径不变)的 301 在 DNS/Cloudflare 层,不进 WP——迁移器只碰路径变化行。每阶段独立可重跑;`just migrate <stage> …` 配方挂进 #14 的 justfile(实现票落)。

## 3. 产品写入顺序与幂等机制

**单品的写入顺序(阶段 2 内,每品一次 eval-file 往返内完成):**

```
查键(_sku)→ [无] Woo CRUD 创建 WC_Product_Simple(title/slug/content/excerpt/virtual/instock/空价/SKU/挂系列)
            → [有] 逐字段比对,只写差异
→ ACF 组 A/B/C(get_field 比对 → update_field;丢弃 18 key 一个不写)
→ SEOPress 标题/描述(现值比对 → 写)
```

**幂等机制(全阶段同一套,不是"存在即跳过"):**

| 对象 | 自然键(唯一性依据) | 已存在时的动作 |
|------|--------------------|----------------|
| 产品 | `_sku`=pattern_code | 差异更新(任何字段漂了都拉回计划值) |
| product_cat term | slug | 差异更新(term meta 逐项) |
| 附件 | `_md_source_url`=manifest master_src | 只补缺失 meta(含核对 `_md_sha256`),不重复 sideload |
| featured/gallery | — | 现值 vs 计划值 diff:空/多/少/**序错**都算差异(de-linker `13-media-meta.py` 同法:只写实例缺的绑定,有意漂移的报告不动——本处无"有意漂移"场景,全量拉回) |
| 页 | post_type+post_name | 差异更新 |
| redirect | source path | 差异更新 |

三道保险:① 计划新鲜度——plan JSON 记录全部输入文件 sha256,apply 重算不符即拒绝("sources changed since plan");② ledger 逐项记 `created/updated/identical/error`,第二次 apply 全 `identical` 即写入计数 0——这是幂等的可证定义;③ reconcile(§4)独立复核。 Woo 原生 SKU 唯一性校验(146 唯一实测,#6 §2.2)是产品键的免费防线。

## 4. 对账清单(阶段 6 reconcile,只读)

报告落 `~/metal-divider-local/migrate/reports/<ts>-reconcile.md`(+同内容 json)。**核心项无 warn-only:每一项要么 PASS 要么 FAIL,任一 FAIL 退出非零。**

| # | 检查项 | PASS 条件(实测来源:#6/#10/#2/#4) |
|---|--------|-----------------------------------|
| 1 | 产品数 | `post_type=product` 恰 146;status 全 publish |
| 2 | 系列结构 | product_cat 恰 8 term;**gradient term 不存在**;每品恰 1 系列(146=Σ成员) |
| 3 | SKU | 146 个 `_sku` 非空且唯一,逐个 = pattern_code |
| 4 | 35 key 对账 | #6 §9 总表逐 key:17 个迁移位逐字段抽全量比对(标题/描述/ACF 13 字段/摘要双落);**18 个丢弃 key 在 `wp_postmeta` 零残留**(key 名与序列化值 LIKE 扫描) |
| 5 | 空值纪律 | `_open_area`(ACF open_area)为 NULL 恰 16(非 0 非空串);visual_density=custom 恰 16;溯源组 16/16 有 inferred_from + confidence |
| 6 | 图片绑定 | 每品 featured 已设;gallery 数 = 旧媒体数−1(合计 1063−146=917 个 gallery 槽);附件总数 = 计划数;alt 非空数 = 导出 alt 非空数 |
| 7 | 图片抽样 | 5 品(2 图、8 图、16 图极值 + 2 常态):featured=旧 media[0]、画廊序=旧 media 序、alt 逐字平迁(#6 §10.4) |
| 8 | SEOPress | 146/146 标题与描述非空;抽 10 与旧 `seo.title`/`seo.description` 逐字一致 |
| 9 | 禁购 | 抽 10 品 `_regular_price` 为空串、`is_purchasable()`=false(#6 §7:空价格原生禁购) |
| 10 | 页面 | 17 页存在,slug 与 #19 清单一致,`_md_source_body` 非空 |
| 11 | URL 零残留 | 全部 post_content / `_md_source_body` / postmeta 内 **零 `cdn.shopify.com` URL**(机械重写的验收) |
| 12 | redirects | 导入条数 = 计划条数(路径变化且非 PENDING);held 行逐条列出及所挡票号 |
| 13 | 幂等复核 | 第二次 apply 后 reconcile 重跑仍全 PASS(写入计数 0 见 ledger) |

## 5. 失败、断点与重跑

- **per-item 错误收集**:PHP 侧逐项 try/catch,单项失败记录 `{key, error, phase}`,不中断同批;批次结束汇总,有错即非零退出。错误进 ledger(`~/metal-divider-local/migrate/runs/<ts>-<stage>.json`),仓库外。
- **单品重跑**:`apply --only <sku|handle|term-slug|source-path>` 任意阶段可用,处理集过滤到单项——修一品的字段不必重放 146 品。
- **dry-run 两级**:`plan` 生成计划与预检,零 WP 写;`apply`(不带 `--write`)对实例只读比对计划,报告"将创建/将更新/identical/错误"清单,零写;`--write` 才落库。评审站演练用第二级做无副作用预演。
- **断点**:不做断点状态机。任意阶段中断后直接重跑同阶段:幂等键 + 差异更新保证已完成项零写入,ledger 的 `identical` 计数即续跑证据。sidload 中断的最坏情况是一个附件半拷贝——重跑按 `_md_source_url` 命中已存在附件,只补 meta。

## 6. WP 侧执行面

- **`18-wordpress.php` 放仓库内**(`tools/migrate/`),随迁移器版本化:评审可见、可 diff;CI 至少 `php -l`,PHPCS/PHPStan 挂接点留给 #17 定 lint 面后接入(de-linker 借 commerce 插件规则集,本仓无对应插件时的最小线是语法检查)。
- **本地 dev 实例**:#14 的工具链起 `dev`(9890 起),driver 从 `~/metal-divider-local/instances.toml` 读端口,直接 `wp eval-file`。导入后的 dev 即 #6 §8 裁定的全量共享基线。
- **评审站 / 生产**:同一 `18-migrate.py`、同一 `.php`、同一计划文件;wp-cli 经 SSH 执行(`wp --ssh=<alias> eval-file` 或 SSH 管道,与 de-linker 对 Hostinger 的用法同型)。本地→评审→线上三处唯一差异是 wp 调用包装,迁移代码零分叉。
- **生产前置(AGENTS §5,迁移器不自带备份)**:数据写入前完整 DB 备份,备份只存本机;评审站是生产前的最后一站,线上不是迁移器第一次被看到的地方。执行按「长 shell 后台跑 + 通知」(AGENTS §2),禁阻塞 sleep。
- **写入通道纪律**:写入只经 Woo CRUD / WP 核心 API / ACF / SEOPress API;原生 SQL 仅用于 reconcile 读取(§4 的 LIKE 扫描)。

## 7. 接口假设表(#15 / #19 在写,以下为**显式假设**,不成立走退路、不静默改口径)

| 接口 | 假设内容 | 状态 | 不成立时的退路 |
|------|---------|------|----------------|
| #15 终稿目录 | #15 产出准备目录(位置参数化,约定 `~/metal-divider-local/media-final/`):每 manifest 条目恰一个可上传终稿文件 + sidecar manifest JSON(原 manifest `sha256`/`filename` → 终稿文件名);1063 产品图全在 | **假设** | stage 3 输入格式换成 #15 实际清单;附件自然键从 master_src 换 sidecar 提供的稳定键 |
| #15 不重采样 | 上传文件即终稿;WP 核心导入时生成 subsize 缩略图是原生行为,不违反"服务器零运行时转换"裁定(AGENTS §2) | **假设** | 若 #15 要求连 subsize 都预生成,导入改 `wp_generate_attachment_metadata` 跳过项,键不变 |
| #19 页面清单 | #19 交付 JSON 清单 `{handle, 新 slug, 去向/模板}`;迁移器按单建 17 页壳 | **假设** | 迁移器内置缺省清单:31 − 8 合规页 − 7 测试页 = 16 品 + 新建 /privacy/ = 17(handle 列表由 #10 pages.json 可机械推导),标注"待 #19 覆盖" |
| #19 正文边界 | HTML→LC 模板区块的**语义转换归 #19**;迁移器只交付页壳 + 原文 `_md_source_body` + CDN URL→本地 URL 的**机械**重写(字符串替换表,零语义) | **假设** | 若 #19 直接吃导出而不经 WP,阶段 4 缩为页壳 + 媒体入库,重写层移入 #19 |
| #6 acf-json | ACF 组 A/B/C/D 定义以 acf-json 随仓库走(#17 定落位),阶段 0 预检字段键可解析,缺即 FAIL | **假设** | 迁移器附最小 acf-json 兜底,与 #17 协调后移除 |
| #4 redirects | 源 = research/seo-baseline 分支 `data/redirect-map.csv`(385 行,列 source/target/note 固定) | 已闭票 | 列结构变更时只改 CSV 解析层 |
| SEOPress 键名 | meta 与 redirection 的存储键在阶段 0 预检时对实装 10.3 实测并记入计划(#6 §2.2 已预留此核对) | 待实测 | — |

## 8. 范围外(明确不迁,防止范围漂移)

- **博客全链**:blogs/articles(21+15 refs)——博客全新 IA 与内容工作流(AGENTS §2),blog PENDING redirect 行留 held。
- **首页图 46 refs**:新首页归 #17/原型票。
- **collection 图 179 refs**:#6 组 D 无图字段;系列档案页视觉归 #19/#17,需要时按 term meta 图字段扩展走 #19 变更票。
- **8 张合规页**:不迁移(AGENTS §2),301 → /privacy/(阶段 5)。
- **多语言、旧主题代码、variants/options/vendor/productType/tags 等常量**:分别归二期、AGENTS §5 零行进新站、#6 §2.3/§7 不迁移清单。
- **WordPress 用户、订单、评论**:无账户站,不存在迁移面。

## 9. 验收要点(实现票照此收)

1. 全七阶段在 dev 实例按序 `--write` 跑通,reconcile 13 项全 PASS,报告落档。
2. 幂等实证:同阶段第二次 apply,ledger 全 `identical`(写入计数 0),reconcile 复跑仍全 PASS。
3. dry-run 实证:`apply`(无 `--write`)对已导入实例输出全 identical 且零写入;对空实例输出 146 品将创建。
4. 故意注错(改 1 品 ACF 值、删 1 附件、乱 1 个画廊序)后重跑 apply,三项全部拉回,reconcile 全 PASS。
5. `--only` 单品重跑通路可用;`plan` 对缺 #15 staging、缺 acf-json 两种场景 FAIL 且零写入。
