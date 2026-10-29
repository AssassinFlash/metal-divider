# metal-divider

metal-divider.com:Metal Divider 的全球 B2B 建筑金属屏风询盘站。从 Shopify(metalpartition.com)重建,技术栈与方法论承自 [de-linker](https://github.com/AssassinFlash/de-linker)。

## 一句话架构

WordPress + WooCommerce(图案目录,无结账)+ ACF 免费版(产品规格字段)+ LiveCanvas/WindPress(页面与样式)+ SEOPress + SEOPress Pro(SEO)+ yansir-md(Markdown 博客)+ 自研询盘插件(PDP 直接询盘:表单 + WhatsApp,无账户)。Polylang(多语言)是二期插槽,首期不装。

## 工作规则

见 `AGENTS.md`;用词见 `GLOSSARY.md`;工程票在 GitHub Issues,约定见 `docs/agents/issue-tracker.md`。当前规划见 [Wayfinder map](../../issues) 上的 `wayfinder:map` 议题。

## 目录(随建设生长)

| 路径 | 内容 |
|---|---|
| `plugins/` | 自研插件(询盘链、i18n 管线等) |
| `mu-plugins/` | 生产 must-use 插件(安全层、错误记录等) |
| `themes/` | 子主题(LiveCanvas/WindPress 承接呈现) |
| `tools/local/` | 本地 WordPress 实例栈(uv 项目 `md-local`) |
| `tools/deploy/` | 部署管线(uv 项目 `md-deploy` + `deploy.just`):dev/staging/prod 三 target,rename 原子切换 + rollback + DB 快照 + present + 媒体分块推送(#35);部署时人工核对表在 `docs/deploy/checklist.md` |
| `tools/pixel-baseline/` | 旧站像素门基准采集脚本与采集清单(#21;截图本体在 `data/pixel-baseline/`,不入库) |
| `tools/pixel-check/` | 像素门比对工具:实例截图 vs 基准逐页型 diff + 掩膜清单(#30) |
| `tools/template-convert/` | 旧站渲染 HTML → LiveCanvas 模板与 WindPress 卷的转换管线(uv 项目 `md-template-convert`,#32;`just templates` / `just blog-fork`) |
| `tools/php/` | 各 PHP 项目共享的 PHPCS/PHPStan 规则(PHP 8.3 锁 platform) |
| `tools/` | 其余 just 模块与脚本(迁移、媒体终稿管线等,随建设生长) |
| `docs/` | 规格、研究证据、验收记录 |
| `data/` | 导出与媒体原始数据(不入库的部分在 `.gitignore`) |

## 工具链(2026-10-24 本机实测,CI 钉同版)

| 工具 | 版本 | 说明 |
|---|---|---|
| PHP | php@8.3 8.3.35 | 实例与 WP-CLI 全在 8.3 上(`brew install php@8.3`) |
| WP-CLI | 2.12.0 | `brew install wp-cli && brew pin wp-cli` |
| MySQL | 26.10.0 | Homebrew,socket 免密 root 管理 |
| just | 1.59.0 | `brew install just` |
| uv | 0.13.4 | `brew install uv` |
| Composer | 2.10.3 | `brew install composer` |

## 本地栈

一个实例 `dev`,端口从 9890 起;数据根 `~/metal-divider-local/`(实例、wp.org 下载缓存、付费件档案),永不进仓库。规格见 `research/spec-local-stack` 分支 `docs/specs/14-local-stack.md`。

```sh
just up        # 建实例(首次)或刷新并(重)启动:WP 7.1.4 + Woo 11.2.1 + 插件面 + 许可注入
just login     # 一次性登录链接(10 分钟一用,默认用户 local-admin)
just status    # 列实例
just wp dev plugin list   # 对实例跑 WP-CLI
just down      # 停服务器(数据保留)
just reset     # 删库删目录,原样重建
just check && just test   # 静态检查与单元测试(CI 跑同一组)
just pixel-check dev      # 像素门:实例按基准口径截图,逐页型 diff(掩膜外差异>0 则非零退出)
```

首次使用前需要两样本机资产(都不进仓库):

1. **付费件档案**——LiveCanvas、SEOPress PRO、yansir-md、picostrap5(基础主题)的 zip 收进 `~/metal-divider-local/archives/`,SHA-256 记在 `archives.toml`。业主既有许可与主题的收档命令(`de-linker` 数据根下的档案或快照目录作源):
   ```sh
   just archive livecanvas ~/de-linker-data/livecanvas-4.9.5
   just archive wp-seopress-pro ~/de-linker-data/20261022-2309/plugins/wp-seopress-pro
   just archive yansir-md ~/Downloads/yansir-md-1.0.1.zip
   just archive picostrap5 ~/de-linker-data/20261022-2309/themes/picostrap5
   ```
2. **许可键**——`~/.config/metal-divider/livecanvas.env`(`LIVECANVAS_LICENSE_KEY=…`)与 `seopress.env`(`SEOPRESS_LICENSE_KEY=…`),600 权限;缺失时 `up` 照常完成,但后台会留各自的激活提示。

## 部署管线

`just deploy …`(配方与三 target 见 `tools/deploy/deploy.just`;规格 `research/spec-deploy` 分支 `docs/specs/20-staging-deploy.md`;人工核对表 `docs/deploy/checklist.md`):

```sh
just deploy drill --instance dev   # 本地实例排练:部署→回滚→再部署(每个窗口的前置)
just deploy prod                   # 干跑;--write 才真部署(快照→构建→原子切换→present→verify)
just deploy staging --write        # 评审站(带评审站专属 mu 件;verify 需 MD_DEPLOY_STAGING_AUTH)
just deploy rollback --target prod --write   # rename 回上一版,零写库
just deploy media --target staging --write --source <终稿目录>   # 媒体分块推送(前置完整备份)
```

远端 target 依赖主机侧建站票交付的 ssh 别名 `hostinger-metal-divider`(参照 de-linker 连接;凭据不入仓库)。
