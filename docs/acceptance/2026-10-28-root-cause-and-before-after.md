# 2026-10-28 · 根因修复与前后对照

业主判定上一轮「验收成功」不成立，理由是页面大面积空白、下拉样式异常、表单空带、空卡槽、
搜索弹层空白、字贴边、可视范围过窄、复刻不全。本轮不再逐处打补丁，先找共同根因。

## 一、根因：一条 `!important` 把每段正文压成 40em

```css
/* plugins/md-site/content/windpress/base.css，修复前 */
main p[class*="max-w-"],
main p[class*="text-"],
main p:not([class*="max-w-"]) {
  max-width: 40em !important;
}
```

它在 `components` 层、带 `!important`。模板上的 `max-w-[980px]`、`max-w-[620px]` 是
`utilities` 层、普通优先级。**层叠规则是「赢的那条替换另一条」，不是取较小值**，所以：

| 位置 | 意图 | 修复前实际 | 占 1440 |
|---|---|---|---|
| 正文段落 16px，模板写 `max-w-[980px]` | 980px | **640px** | 44.4% |
| 英雄导语 18px，模板写 `max-w-[620px]` | 620px | **720px**（被反向撑宽） | 50% |
| 小字说明 14px | 依模板 | **560px** | 38.9% |

这正是业主反复说的「可视范围过窄过窄」。此前每轮只调单个间距，所以怎么调都不对：
间距不是原因，行长才是。

`base.css` 里那句注释「更窄的上限会保留，因为 max-width 只会变窄」是错的前提，
它让这条规则看起来安全。已在修复时一并改掉。

修复后：

```css
main p:not([class*="max-w-"]) {
  max-width: 980px;
}
```

无宽度上限的段落有 980px 兜底，带模板上限的段落由模板自己决定，不带 `!important`。

## 二、同一个形态在六类缺陷里重复出现

「能力已经存在，但没有接上」——不是缺功能，是缺连接。

| 缺陷 | 已有的东西 | 没接上的地方 |
|---|---|---|
| 搜索弹层一整屏空底 | `max-height` 滚动方案 | 手机上写的是 `min-height: 100dvh`，内容只有一条输入框 |
| 搜索不显示图 | Woo Store API 已返回 `images[0]` | 脚本查的是 `wp/v2/product`，只取 `title,link,slug` |
| 系列卡 4:3 空矩形 | `.cph__media-fallback` 样式早就写好 | 全库 HTML 和 PHP 都没有用这个类 |
| 相关图案空图盒 | 同文件 `editorial()`/`catalog()` 都有无图守卫 | `related()` 没有这道守卫 |
| 表单 96px / 88px 空带 | 弹层里已经用 `.md-quote-modal__card .md-inquiry` 清过 | `.quote-panel .md-inquiry` 没清 |
| 导航下拉叠层 | `closeAll()` 函数存在 | 只挂在「面板已有子节点」的项上，空面板直接 return |

## 三、生产基线（修复前，可证伪）

`https://metal-divider.com/`，`tailwind.css?ver=1793136205`，624,407 字节：

| 标记 | 出现次数 | 含义 |
|---|---|---|
| `40em` | 1 | 压死正文的那条规则 |
| `min-height:100dvh` | 1 | 搜索弹层一整屏空底 |
| `object-fit:contain` | 1 | 下拉方图缩在灰底中间 |
| `280px` | 1 | 目录侧栏，挤掉图案列宽度 |
| `gap:14px` | 1 | 卡片内部间距贴死 |

部署后这五项必须全部归零或改变，否则说明改动没有真正上线。

## 四、内容搬运核对（产品一条不缺）

| 层面 | 旧站 | 新站 | 结论 |
|---|---|---|---|
| 产品 slug | 146 | 146 | 双向差集为空 |
| 集合 | 9 个 `pattern-*` | 9 个 `pattern-*` | 完全一致 |
| 业务页面 | 16 | 16 | 一一对应 |
| 旧站合规页 | 8 张 Consentmo 残留 | 1 张正经隐私政策 | 业主已裁定不迁移 |
| 博客文章 | 5 篇 `test blog N` | 0 | **旧站那 5 篇正文 56 词、标题即 `test blog N`、作者 `fumartin`，是测试垃圾，不搬** |

`/pages/metal-divider` 与 `/pages/custom-metal-divider-screens` 是**同一套落地页模板的两个实例**
（13 个 section 类型一一对应，10 个 h2 相同，只差 hero 标题与主视觉文案）。新站
`/custom-metal-divider-screens/` 已承载全部 10 个区块。旧 URL 301 到首页是已记录的跳转选择，
不需要再复制一页。

唯一不在落地页上的具名图案是 Kasbah：站内没有该商品，两张图已在首页图案库。
这是「Woo 是唯一商品资料源」裁定的结果，不是漏搬。

## 五、验证方式（不靠口头）

- `just php-test`：403 / 77 / 6 / 26 项，零失败。
- `just check`：38 个 CSS 卷平衡，PHP、Python 静态检查无错。
- 部署后读回生产 CSS 与 HTML，逐项核对上文「三」的五个标记。
- 真 Chrome 1440 与 390 截图，同一页面同一视口做前后对照。

## 六、业主点名六类的逐项落点

| 业主反馈 | 真实根因 | 落点 |
|---|---|---|
| 页面大面积空白 | 搜索弹层 `min-height:100dvh` 只装一条输入框；系列卡缺图留 4:3 近黑矩形而 fallback 样式没人用 | `chrome.css`、`hub-series-card.html`、`hub.css` |
| 导航下拉样式异常 | 同一 `<a>` 叠了 Shopify 与新版两套类：`height:215px` 压掉 `aspect-ratio:16/10`，`contain`+`padding` 把方图缩在灰底；`closeAll` 只在有内容的面板上触发，空面板不关旧面板 | `header.html`、`chrome.css`、`md-chrome.js` |
| 表单区域大块空白 | `.md-inquiry` 被页面 `<section>` 与表单 `<div>` 各套一次，`padding:56px 0 88px` 落在卡片内部 | `inquiry.css`、`Form.php` |
| 卡片列表空卡槽 | `Cards::related()` 缺无图守卫，输出 `src=""` 占满 1:1 槽 | `Cards.php`、`pdp.html` |
| 搜索弹层大面积空白 | 旧站白卡片移植，与本站深色体系不符；手机整屏空底；接口不带图 | `header.html`、`chrome.css`、`md-chrome.js` |
| 字体贴边 / 间距贴死 | 规格表 `0.55rem` 实际只渲染 5.5px；卡片 `gap:14px`；折行大标题行高 1.05 | `spec-table.css`、`faq.css`、`pattern-grid.css`、`shop.css` |
| 可视范围过窄 | `main p { max-width:40em !important }` 覆盖所有模板上限 | `base.css` |
| 复刻不完全 | 产品 146/146 已对齐；`/blog/` 只有 45 词是唯一真内容缺口（旧站那 5 篇是测试垃圾） | `blog-index.html` 空态、导航入口 |

## 七、本轮自我纠正

1. **起初报「`/shop/` 缺 1520 词」是错的**：旧站 `/pages/metal-divider` 是营销落地页，
   `/shop/` 是产品归档，两者不是同一页。旧 URL 实测 301 到**首页**，落地页内容由
   `/custom-metal-divider-screens/` 承载，10 个 h2 全在。已撤回该判断。
2. **起初报「`/pages/metal-divider` 有 122 张图待搬」是把 `srcset` 宽度档当成了照片数**：
   实际 74 个不同文件，非图案库的照片在新站对应位置都在。
3. **检测器第一版用裸属性值当标记**（`object-fit: contain`、`gap: 14px`），在修复前的
   CSS 上就命中 16 处与 11 处，全部是博客封面、页头工具栏等合法用法。已改为按选择器
   定位，并在修复前的 CSS 上验证每个标记唯一命中目标后才使用。

## 八、前后对照的作品方式

**基线不是事后补拍的。** 修复前那 66 张截图由走查代理在 2026-10-28 08:43–08:55 拍下，
覆盖 19 个页面的 1440 与 390 两档，另含导航逐项展开、搜索弹层、报价弹层、FAQ 展开等状态图。
拍摄脚本留在 `/tmp/visual-audit/run_audit.py`。

修复后原样复用同一脚本（仅改输出目录与会话名），因此两组的差异只来自站点本身，
不来自参数、窗口尺寸或等待时间。每个页面拍完同时记录 DOM 探针数据
（`innerWidth`、空容器数、卡片数、表单数），像素之外还有结构证据。

对照表按页型逐项给出，见下节。
