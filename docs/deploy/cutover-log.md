# metal-divider.com 上线执行日志

每步一手证据(命令原始输出摘录)。补建壳页(collections/inquiry/blog)为生产侧手工操作,待回归进迁移器(见遗留)。

## 2026-10-25 生产建站与部署

| 步骤 | 证据 |
|---|---|
| 建站 | POST orders/1009355268/onboardings → 201 status running;轮询 ~30s completed;API websites 三站并列(metal-divider.com addon/wordpress) |
| HTTP 初验 | curl https://metal-divider.com → HTTP/2 200 |
| SSH 别名 | hostinger-metal-divider → php 8.3.35, wp-config 在位 |
| 插件面 | wp.org 钉版安装+激活:ACF 6.8.10/WindPress 3.2.92/SEOPress 10.3+PRO 10.2.1/LiteSpeed 7.9.2/Woo 11.2.1;档案:LiveCanvas 4.9.5/yansir-md 1.0.1/主题 picostrap5 3.5.0;contract 全绿 |
| 代码部署 | just deploy prod --write:5 件构建+原子切换+DB 快照 |
| 许可 | lc_apikey/seopress_pro_license 注入,读回确认 |
| 完整备份 | backup-20261024-1659:20201 文件,dump sha256 66cab73e… |
| 媒体推送 | 修复版批传输(PR #53):readback 4749/4749 文件与本地一致 |
| 迁移 | terms 9 系列;products 146/146 零错;media 146 created 1063 附件(PR #54 远程绑定);pages 13+255 页图(247 created);redirects 28 |
| reconcile | **13/13 PASS**(报告 ~/metal-divider-local/migrate/reports/20261025-041814-reconcile.md) |
| WindPress 编译 | 真 Chrome 实跑:compiled:true,325,855B,114s;SSH 读回 mtime Oct 24 20:21 UTC |
| 壳页补建 | collections(1543)/inquiry(1544)/blog(1542,设 page_for_posts);删 hello-world 与空 Pages 页 |
| deploy verify | /、/collections/、/inquiry/、/blog/、/pattern/apiary…/ 全 200;page cache 第二击 HIT;notes 零 |

## 缺陷与修复(执行中发现)

- PR #53:media 推送路径丢 wp-content 层 + 逐文件 SSH → 批传输(#52)
- PR #54:远程媒体绑定用本地路径 → dev 附件映射 attach_uploads
- #55 立案:windpress-check 读不到远端 mtime(实物在,检查器 bug)
- 壳页三张为手工 wp-cli 操作:**遗留:回归进迁移器 pages 阶段**

## 待办(阶段一尾)

- [ ] pixel-check 对生产(170 页型×双视口,M3 掩膜台账)
- [ ] Turnstile 挂件注册(表单 fail-closed 兜底中)
- [ ] GSC 新属性(TXT 写 hPanel 区)
