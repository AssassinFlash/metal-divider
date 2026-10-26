# 性能基线 — metal-divider.com(修复前)

GOAL.md 阶段二基线。测量于 **2026-10-25 12:27–12:30(本地 +08)**,供后续优化对比。WindPress 任意值类未编译的问题(#58 修复中)包含在内 —— 这就是修复前的真实状态。

## 测试环境

| 项 | 值 |
|---|---|
| 浏览器 | 本机真 Chrome 155.0.8059.40(有头,Playwright 1.64.1 `channel:'chrome'` 驱动) |
| Profile | `~/metal-divider-local/md-admin-profile` 的快照副本(原件被并发的自动化会话锁定,快照保留 cookies) |
| 视口 | 1440×900 |
| 网络 | Wi-Fi(en0 192.168.0.101),Shadowrocket TUN(utun4 默认路由)→ 美国落地节点;HTTP/2 |
| 缓存 | **每次导航前 `Network.clearBrowserCache` 清空浏览器缓存(冷加载)**,3 轮全部冷加载 |
| 每页轮次 | 3 次,表中报中位数(括号内为三次原始值) |

## 主表:页面 × 指标(中位数,单位 ms;字节为网络传输 transferSize)

| 指标 | 首页 `/` | `/shop/` | `/pattern/apiary-architectural-metal-screen-pattern` | `/faqs` |
|---|---|---|---|---|
| FCP | 4,124(4,124 / 7,664 / 1,316) | 1,104(1,104 / 1,316 / 992) | 1,764(1,764 / 2,340 / 1,380) | 1,600(1,600 / 1,572 / 1,828) |
| DCL | 11,500(11,434 / 26,679 / 11,500) | 5,028(6,064 / 5,028 / 4,792) | 5,076(5,076 / 6,242 / 4,951) | 4,761(4,761 / 4,791 / 4,647) |
| Full Load | **12,538**(12,538 / 27,502 / 12,270) | **5,032**(6,068 / 5,032 / 4,796) | **5,081**(5,081 / 6,247 / 4,956) | **4,767**(4,767 / 4,796 / 4,653) |
| TTFB | 329(2,601 / 327 / 329) | 328(340 / 327 / 328) | 882(911 / 882 / 880) | 881(909 / 881 / 881) |
| 资源请求数 | 47 | 39 | 46 | 36 |
| 总传输量 | 2,436 KB | 936 KB | 856 KB | 494 KB |
| `<img>` 已加载/总数(load 后 1s) | 10 / 56 | 0 / 3 | 0 / 3 | 1 / 4 |
| 内联 CSS 总量 | 441,907 B(8 个 `<style>`) | 445,296 B(16 个) | 445,296 B(16 个) | 441,907 B(8 个) |
| └ 其中 WindPress JIT | **426,742 B** | **426,742 B** | **426,742 B** | **426,742 B** |

## 每页最大资源 Top 5(按 transferSize,run 1)

### 首页 `/`
| 传输 | 解码后 | 耗时 | 类型 | 资源 |
|---|---|---|---|---|
| 750 KB | 749 KB | 1,670 ms | img | `/wp-content/uploads/2026/10/ext-pp-hero-perforated-facade-2048w.webp` |
| 550 KB | 550 KB | 5,366 ms | img | `/wp-content/uploads/2026/10/our-park-hyatt-mesh-screen-hero-1536w.webp` |
| 132 KB | 132 KB | 2,316 ms | img | `/wp-content/uploads/2026/10/63ec62970a9a4ffebeaa1befe9ace3c8-thumbnail-…-110` |
| 119 KB | 119 KB | 5,001 ms | img | `/wp-content/uploads/2026/10/9163b183271e41c698d020c9412e2945-thumbnail-…-110` |
| 117 KB | 117 KB | 5,364 ms | img | `/wp-content/uploads/2026/10/ext-pp-deburr-abrasive-rollers-1536w.webp` |

### `/shop/`
| 传输 | 解码后 | 耗时 | 类型 | 资源 |
|---|---|---|---|---|
| 319 KB | 319 KB | 1,502 ms | font | `wp-content/plugins/woocommerce/assets/fonts/Inter-VariableFont_slnt,wght.woff2` |
| 143 KB | 143 KB | 868 ms | font | `wp-content/plugins/woocommerce/assets/fonts/cardo_normal_400.woff2` |
| 111 KB | 111 KB | 779 ms | img | `wp-content/uploads/metal-divider-primary-logo--120w.webp` |
| 111 KB | 111 KB | 1,623 ms | img | `wp-content/uploads/metal-divider-icon-logo--80w.webp` |
| 85 KB | 389 KB | 849 ms | script | `wp-content/plugins/windpress/assets/dist/assets/default-map-DLhLuVie.js` |

### `/pattern/apiary-…`
| 传输 | 解码后 | 耗时 | 类型 | 资源 |
|---|---|---|---|---|
| 319 KB | 319 KB | 1,003 ms | font | `woocommerce/…/Inter-VariableFont_slnt,wght.woff2` |
| 143 KB | 143 KB | 720 ms | font | `woocommerce/…/cardo_normal_400.woff2` |
| 85 KB | 389 KB | 644 ms | script | `windpress/…/default-map-DLhLuVie.js` |
| 79 KB | 286 KB | 680 ms | script | `windpress/…/lib-Dyul4VgH.js` |
| 29 KB | 86 KB | 800 ms | script | `wp-includes/js/jquery/jquery.min.js?ver=3.7.1` |

### `/faqs`
| 传输 | 解码后 | 耗时 | 类型 | 资源 |
|---|---|---|---|---|
| 103 KB | 103 KB | 856 ms | img | `wp-content/uploads/metal-divider-primary-logo--120w.webp` |
| 86 KB | 86 KB | 1,068 ms | img | `wp-content/uploads/2026/10/caliper-measurement-drawing-1536w.webp` |
| 85 KB | 389 KB | 1,005 ms | script | `windpress/…/default-map-DLhLuVie.js` |
| 79 KB | 286 KB | 638 ms | script | `windpress/…/lib-Dyul4VgH.js` |
| 29 KB | 86 KB | 648 ms | script | `wp-includes/js/jquery/jquery.min.js?ver=3.7.1` |

## 资源类型分布(run 1)

| 页面 | script | img | css/font(link+css) | other | 合计传输 |
|---|---|---|---|---|---|
| 首页 | 29 个 / 286 KB | 12 个 / 2,140 KB | 6 个 / 17 KB | 1 / 2 KB | 2,436 KB |
| `/shop/` | 29 个 / 286 KB | 2 个 / 222 KB | 8 个 / 494 KB | — | 936 KB |
| `/pattern/…` | 34 个 / 313 KB | 2 个 / 18 KB | 10 个 / 497 KB | — | 856 KB |
| `/faqs` | 29 个 / 286 KB | 3 个 / 198 KB | 4 个 / 17 KB | — | 494 KB |

(注:font 计入 initiatorType `css`,即经 CSS `@font-face` 触发的请求。)

## 观察(修复前状态,照实记录)

1. **首页是最大瓶颈**:Full Load 中位 12.5s,且方差极大(run2 27.5s;FCP 1.3s–7.7s)。主导因素是 2MB+ 图片传输(hero 750 KB + 550 KB webp,单张耗时 1.7–5.4s)。首页 56 个 `<img>` 在 load+1s 时仅 10 个加载完 —— 懒加载生效,但首屏外的 thumbnail 也在抢带宽。
2. **WindPress 内联 CSS 426,742 B(≈417 KB)** 以 `windpress-cached-inline-css` `<style>` 形式内联在**每个页面**的 HTML 里(#58:任意值类尚未编译,当前体积即为修复前基线)。它是 HTML 文档本身的一部分,不占 resource 条目,但阻塞解析并放大 TTFB→DCL。
3. **字体是 shop/pattern 的最大传输项**:WooCommerce Inter 可变字体 319 KB + Cardo 143 KB = 462 KB,两页合计占传输量一半以上;且 `wp-block-library` 等 CSS 在所有页面重复拉取。
4. **DCL≈Full Load(shop/pattern/faqs Δ<10ms)**:load 事件紧跟 DCL,资源几乎全部在 load 之后才开始/完成 —— 延迟加载把成本移出了 load 指标,但不代表用户可用。
5. **TTFB 分两档**:首页/shop ≈330 ms(疑似页面缓存命中),pattern/faqs ≈880 ms(疑似缓存未命中走 PHP);首页 run1 TTFB 2.6s 为冷启动。
6. 全站 HTTP/2;jQuery 3.7.1 仍在加载。
7. 网络经 Shadowrocket TUN(美国落地),是本机常态链路;绝对值含隧道 RTT,但作为前后对比基线一致有效。

## 复现

```bash
cd /tmp/md-chrome && node perf-baseline.mjs   # 真 Chrome + channel:'chrome',每次导航前清缓存
# 原始数据(performance API 全量 JSON):/tmp/md-chrome/perf-raw.json
```

## 2026-10-26 响应式优化后(匿名真实客户)

同一台 Mac、真 Chrome、1440×900、每次清缓存、每页 3 次,协议与上方一致。使用无登录态 profile,避免 Hostinger AI 后台助手约 9MB 的非客户资源混入。部署 `20261026T051233002100Z-9c9baa2`(PR #78/#80)后的中位数;原始日志 `/tmp/md-perf-pdp.log`。

| 页面 | Full Load 中位 | 传输量中位 | 对比修复前 Full Load | 对比修复前传输量 |
| --- | ---: | ---: | ---: | ---: |
| 首页 | 2.206s | 2.024 MB | 12.538s → -82% | 2.436 MB → -17% |
| /shop/ | 1.817s | 1.151 MB | 5.032s → -64% | 0.936 MB → +23%(真实 24 卡替代旧静态缺陷) |
| PDP apiary | 2.666s | 1.157 MB | 5.081s → -48% | 0.856 MB → +35%(真实图槽与相关卡替代旧静态缺陷) |
| /faqs | 2.313s | 0.598 MB | 4.767s → -51% | 0.494 MB → +21%(完整内容替代旧静态缺陷) |

首页在优化前的同协议复测为 4.232 MB,本轮为 2.024 MB(-52%);PDP 复测 1.630 MB → 1.157 MB(-29%)。内联 CSS 从修复前约 427KB 降至约 14KB。Full Load 受同一美国落地链路的 RTT 波动影响,字节与选图证据分开判断,不把网络波动写成全部优化收益。

**询盘验收(同日生产)**:PDP quote modal 携带当前图案,提交后感谢页返回 200;队列分别含客户确认与内部通知的正确收件人,单次 tick 只发一封到 `sales@sunriverinverter.com`,test mode 已关闭,测试询盘和两条队列记录已删除。Turnstile 当时显示 `not_configured`,真实密钥配置仍是未解决项,不把该次提交称为反机器人验收通过。

## 2026-10-26 复测:间距修复后的图片字节与方形档缺口

间距尺度修复(#119)把 `max-w-7xl` 从 800px 恢复到 1280px,容器变宽 43%,图片实际渲染尺寸随之改变。同时 #122 给目录卡补上了 srcset,卡片图从 3.38 倍放大修复为正比例。

### 字节变化及其原因(已查证,不是退化)

shop 页 webp 总量从 458KB 升到 819–1174KB。原因是**修复本身**:

| | 修前 | 修后 |
| --- | --- | --- |
| 卡片选中的候选 | `-100w`(放大 3.38 倍,模糊) | `-400w`/`-480w`(比值 1.00) |
| 单张字节 | 约 20KB | 80–163KB |

即:清晰的代价。按 DPR 1 的 338px 槽位,400–480w 是正确选择,没有冗余。

### 真正的缺口:方形档

卡片槽是 `aspect-ratio: 1/1`、宽 338px,而选中的是 **480×960 的竖长图**——下载整张长图只显示中间一截方形。服务器上存在 `-300x300` 方形剪裁(1057 张图案卡都有),实测:

| 图 | 像素 | 字节 |
| --- | --- | --- |
| `-500w-300x300.webp` | 300×300 | 44 KB |
| `-480w.webp` | 480×960 | 163 KB |

方形图省 73%。但 WordPress 只登记了 `woocommerce_thumbnail`(300×300)与 `100x100`,**没有中间档**:

- DPR 1 的 338px 槽:300px 略不足(需 338)
- DPR 2 的 338px 槽:需 676px,300px 明显不足

所以两档都不合适。正确修法是在媒体管线里补出方形档(如 400×400 与 800×800),需 `tools/media` 支持剪裁并重跑 1057 张图——独立工程,不在本轮范围。

在此之前保持现状:长图 480w 在 DPR 1 下清晰度正确,只是多传输约 119KB/图。这是一个**已知且已量化的取舍**,不是未发现的问题。

## 2026-10-27 PDP hero 的放大:素材上限,模板无过

`pdp-hero-bg img` 用 `sizes="100vw"` 并选最大档,1440 视口下需要 1440px 宽的候选。实测 146 个图案的图片阶梯上限:

| 阶梯最大宽 | 数量 | 占比 |
| --- | --- | --- |
| <1200(填不满 1440 hero) | **142** | **97%** |
| 1200–1535 | 3 | 2% |
| ≥1536 | 1 | 1% |

所以 1440 视口下 hero 普遍放大 1.41–1.65 倍(抽样 11 页 × 2 视口,7 页命中)。**这是源素材宽度的上限,不是模板缺陷**:模板已经选了最大可用档,再没有更大的可选。

视觉影响有限,原因在设计:hero 上覆盖 `pdp-hero-scrim`(`linear-gradient(90deg, 0.92 → 0.42)`)。截图实测局部对比度——左侧(遮罩 0.92)标准差 54.6,以文字为主;右侧(遮罩 0.42)24.8,细节仍在,没有被糊成一片。

**不改模板的理由**:把 `sizes` 调小只会让浏览器选更小的档,画面更糊;把 hero 改成受限宽度会改变已验收的版式。真正的解法是更高分辨率的源素材(同 Atherton 那类),属素材侧工作。

### 方法学备注(重要)

判断"图片是否被放大"**不能**用 `naturalWidth`:在带 `srcset` 的图片上它返回的是**密度校正后的 CSS 内在尺寸**,不是文件的真实像素宽。实测 `zelda-…-01-400w.webp` 文件是 400×800,而 `naturalWidth` 报 460。正确做法是从该元素的 `srcset` 里读出当前 `currentSrc` 对应的 `w` 描述符,以它作真值。
