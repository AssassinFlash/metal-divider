# metal-divider

metal-divider.com:Metal Divider 的全球 B2B 建筑金属屏风询盘站。从 Shopify(metalpartition.com)重建,技术栈与方法论承自 [de-linker](https://github.com/AssassinFlash/de-linker)。

## 一句话架构

WordPress + WooCommerce(图案目录,无结账)+ ACF Pro(产品规格字段)+ Polylang(9 语种)+ LiveCanvas/WindPress(页面与样式)+ SEOPress Pro(SEO)+ yansir-md(Markdown 博客)+ 自研询盘插件(图案清单 + 表单 + WhatsApp,无账户)。

## 工作规则

见 `AGENTS.md`;用词见 `GLOSSARY.md`;工程票在 GitHub Issues,约定见 `docs/agents/issue-tracker.md`。当前规划见 [Wayfinder map](../../issues) 上的 `wayfinder:map` 议题。

## 目录(随建设生长)

| 路径 | 内容 |
|---|---|
| `plugins/` | 自研插件(询盘链、i18n 管线等) |
| `mu-plugins/` | 生产 must-use 插件(安全层、错误记录等) |
| `themes/` | 子主题(LiveCanvas/WindPress 承接呈现) |
| `tools/` | 本地栈、迁移、媒体终稿管线等 just 模块与脚本 |
| `docs/` | 规格、研究证据、验收记录 |
| `data/` | 导出与媒体原始数据(不入库的部分在 `.gitignore`) |
