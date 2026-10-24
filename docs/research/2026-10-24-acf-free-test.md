# ACF 免费版实测:能否承载 #6 字段组(wayfinder 票 #24)

日期:2026-10-24。执行:票 #24 实测会话。方法:一次性本地 WP 实例全链路实测,非文档推断。

## 0. 结论

**ACF 免费版(wp.org 版 6.8.10)够用,#6 的全部字段组(A/B/C/D)都在免费档能力内;不需要 ACF Pro。** 技术栈中的 "ACF Pro 6.3.1"(AGENTS.md §2 2026-10-24 栈定版)可以降为免费版,省下预算上限内的一项开支。Pro-only 能力(repeater / flexible content / gallery / clone / options pages / ACF Blocks)在 #6+#17+#19 的规格里 **一个都不用**(逐项对照见 §4)。

## 1. 实测环境

| 组件 | 版本 | 来源 |
|---|---|---|
| WordPress | 7.1.3 | `wp core download`(wp.org 最新) |
| ACF(免费版,wp.org slug `advanced-custom-fields`) | 6.8.10 | 实装读回 `ACF_VERSION`;插件文件头无 PRO |
| WooCommerce | 11.2.1 | 实装 `wp plugin list` |
| PHP | 8.3.35(Homebrew php@8.3) | `php -v` |
| MySQL | 9.6.0(Homebrew,root) | `SELECT VERSION()` |
| 实例 | md-acftest,`http://127.0.0.1:9899`,DB `md_acftest`,目录 `/tmp/md-acftest-wp` | 按票面规格;php 内置服务器 + de-linker router.php 模式;用完即毁(§5) |

环境备注(与 ACF 无关):本机经 TUN 时 `api.wordpress.org` 插件 API 返回空(`wp plugin install <slug>` 报 "A valid URL was not provided"),但 `downloads.wordpress.org` 直下正常——两个 zip 用 curl 直下后 `wp plugin install ./acf.zip --activate` 装入。这是本机网络事实,不是 ACF 行为。

字段组注册方式:mu-plugins `md-acftest-fields.php`,`acf/init` 钩子内 `acf_add_local_field_group` 注册 #6 §2.2/§4 的 4 个组(A `pattern_spec` 6 字段、B `pattern_copy` 2 字段、C `pattern_provenance` 5 字段、D `series_meta` 4 字段挂 `product_cat`),A/B/D `show_in_rest=1`,C `show_in_rest=0`(对齐 #6 §10.7 溯源隔离)。这与生产注册路径一致(代码进 mu-plugins,符合 AGENTS §5 "代码只进仓库";ACF 后台图形界面建组在免费/Pro 同样可用,本测试未依赖)。

## 2. 验证清单与证据

### 2.1 字段组在 product 编辑页出现 ✅

`wp eval` 按位置规则取组(这是编辑屏渲染的判定路径):

```
PRODUCT edit screen field groups:
  - 图案文案 pattern_copy (key=group_md_pattern_copy, show_in_rest=1)
  - 图案规格 pattern_spec (key=group_md_pattern_spec, show_in_rest=1)
  - 来源与许可 pattern_provenance (key=group_md_pattern_provenance, show_in_rest=0)
  - Group A field count: 6
PRODUCT_CAT term edit field groups:
  - 系列属性 series_meta
```

真实编辑页(curl 模拟登录后抓 `wp-admin/post.php?post=11&action=edit`,432,734 字节 HTML):三个 metabox 均渲染(`id="acf-group_md_pattern_spec|pattern_copy|pattern_provenance"`),字段标签逐一在页面中(`镂空率 Open area`、`视觉密度 Visual density`、`主题词 Motifs`、`来源 manifest (JSON)`、`推断置信度`)。

### 2.2 逐字段填值保存(两条写入路径都测) ✅

**路径 1:后台编辑表单真实 POST**(人工编辑路径)。POST `wp-admin/post.php` 全部 13 个 `acf[field_*]` 输入 + `_wpnonce` + **`_acf_nonce`**(ACF 自己的 metabox nonce;实测缺它时 WP 保存文章但 ACF 静默跳过字段,这是坑,记录给 #18/#19 之外的 HTTP 层测试)。302 成功后读回:

```
open_area => '42.5'   visual_density => 'open'   repeat_behavior => 'flowing'
motifs => array('geometric','lattice')   best_fit => 'decorative-screening'
cad_status => 'missing'   summary/inspiration/source_* 全部 = 表单提交值
```

**路径 2:`update_field`(迁移器 #18 的写入路径)**,13 字段 + 4 term 字段全部写入成功(返回 meta ID 或 true)。

### 2.3 `get_field` 读回全部字段 ✅

```
open_area => '35.48'(string)  visual_density => 'balanced'
motifs => array('abstract','lattice','leaf')   —— checkbox 正确回数组
source_manifest => '{"source":"parasoleil","fetched":"2026-10-01"}'  —— JSON 原文无损
openness_confidence => '0.75'
```

product_cat term(组 D,`get_field($f, 'product_cat_16')`):`accent_color='#c9a962'`、`difficulty_level='moderate'`、`typical_applications=...`、`bottom_content='<h3>FAQ</h3>...'`(wysiwyg HTML 无损)。

**给 #18/#19 的两个数据形态注意点**(免费/Pro 行为相同,非档位差异,但要防呆):
- number 字段 `get_field` 返回**字符串**(`'42.5'`),REST 输出才是 JSON number(42.5);模板层数值比较/计算先 cast。
- `update_field('open_area', null, $id)` 会**删除 meta 行**,`get_field` 返回 `null`——#6 §6 的 16 个 "Project set" 留空语义成立(NULL 而非 0/空串),模板判空用 `empty()` 或 `null ===` 均安全。

### 2.4 WP REST 可读(免费版行为实测) ✅

公开(未认证)`GET /wp-json/wp/v2/product/11` 响应含 `acf` 对象,恰好是 `show_in_rest=1` 的 A/B 组 8 个字段,`motifs` 为数组、`open_area` 为 number:

```
acf: { summary, inspiration, open_area: 35.48, visual_density: "balanced",
       repeat_behavior: "repeating", motifs: ["abstract","lattice","leaf"],
       best_fit: "architectural-screening", cad_status: "gated" }
```

**组 C 溯源字段在公开 REST 中零输出**(source_site/source_slug/source_manifest/openness_inferred_from/openness_confidence 全部不在响应里)——#6 §10.7 的溯源隔离靠 `show_in_rest=0` 在免费版即成立。隔离逻辑在免费版源码 `includes/rest-api/class-acf-rest-api.php:702`(`if ( empty( $field_group['show_in_rest'] ) ) continue;`),与请求 context 无关。

term REST `GET /wp-json/wp/v2/product_cat/16` 同样含 `acf` 对象(组 D 4 字段,含 wysiwyg HTML 原文)。

(附注:application-password + `context=edit` 请求被 WooCommerce 以 `rest_forbidden_context` 401 挡下,是 Woo 对 wp/v2 product 的权限行为,与 ACF 无关;#18 迁移器写入走 PHP `update_field`,不依赖 REST 认证写。)

### 2.5 后台筛选/排序

票面不要求,未测。(#6 §3 已裁定:镂空率筛选用 `meta_query` 数字比较,不依赖 ACF 任何档位能力。)

## 3. 免费版实测可用性来源(一手)

免费版 6.8.10 安装包内 `includes/fields.php` 的 `acf_get_registered_field_types`(可用字段)与 `acf_get_pro_field_types()`(:423–470,Pro 字段目录,每项 `'pro' => true`);Pro 能力清单在 `includes/admin/views/acf-field-group/pro-features.php:38`(插件自己的升级页文案):"features like ACF Blocks and Options Pages, and sophisticated field types like Repeater, Flexible Content, Clone, and Gallery"。`acf_add_options_page` 在免费版中**未定义**(grep 实证);repeater/flexible_content/gallery/clone 无字段类文件(fields/ 目录实证)。

## 4. Pro-only 对照表:本站规格用到没有

| ACF Pro-only 能力 | #6 / #17 / #19 中的使用 | 是否需要 |
|---|---|---|
| Repeater 字段 | #6 17 个保留字段无一重复结构;`motif_tags` 是 checkbox 定长词表,`source_manifest` 是 JSON 文本域 | 否 |
| Flexible Content 字段 | 无规格消费 | 否 |
| Gallery 字段 | #6 §5 明确"图片全走 Woo 原生 featured + gallery,ACF 零图组"(1063 张图不重复挂载的裁定) | 否 |
| Clone 字段 | 无字段复用需求 | 否 |
| Options Pages | #6 常量(材质清单等)裁定进"模板层常量",不建选项页;无站点级 ACF 设置 | 否 |
| ACF Blocks | 呈现层是 #17/#19 的 LiveCanvas 动态模板;AGENTS §5 禁第二构建器,ACF Blocks 不在栈里 | 否 |
| 更多位置规则 | 本站只用 post_type=product 与 taxonomy=product_cat 两条,免费版均支持(实测) | 否 |
| ACF REST API(历史上曾是 Pro 卖点) | 免费版 6.8.10 已含完整 `includes/rest-api/`,`show_in_rest` 实测可用(§2.4) | 否 |

## 5. 实例回收

按 AGENTS §0:php server 停止、`DROP DATABASE md_acftest`、`/tmp/md-acftest-wp` 与工作克隆 `/tmp/md-r-acftest`(分支 push 后)删除,删除后读回验证。已执行,见提交本 doc 的会话记录。

## 6. 给下游的影响

- **#1 地图/栈定版**:ACF Pro 6.3.1 → ACF(免费版,wp.org `advanced-custom-fields`);$20/月预算省一项。业主若坚持 Pro(如未来二期要用 options pages)也兼容——免费版与 Pro 同 schema,升级无损。
- **#18 迁移器**:写入路径 `update_field`(实证);"Project set" 16 品写 `null`(实证删行、读回 null);number 读回是字符串。
- **#19 PDP 模板**:`get_field` / REST `acf` 对象均可作为数据源(实证);组 C 的 `show_in_rest=0` 由注册代码保证,模板永不读取。
