# mdmobile — 移动值班工作台(Dashboard extension for Live Helper Chat)

面向单坐席、企业微信入口的响应式坐席工作台:手机列表/会话、桌面三栏工作台、WeCom 深度链接、原生快捷回复与可编辑私密备注。

不新建数据表:快捷回复读原生 `lh_canned_msg`(经 `erLhcoreClassModelCannedMsg::getCannedMessages()`/`groupItems()`),备注写原生 `chat/saveremarks`。

## 安装与部署

把本目录整体拷到 LHC 根目录下 `extension/mdmobile/`(即 `<chat_root>/extension/mdmobile/`),不改 LHC 自带的 `chat/index.php`。

## 部署后必须清编译缓存

LHC 4.97v 在 `site/modulecompile` 开启时,会把**模块脚本**和**模板**编译后放进 `cache/compiledtemplates/`,并把映射缓存在 `cache/cacheconfig/`。源码更新不会让这些副本失效——直接部署会出现"文件已替换、页面却仍跑旧代码"的静默旧版本(2026-10-27 在 staging 实测:模块里加的调试 header 从不执行,清缓存后立刻生效)。

每次部署扩展的 PHP 或模板后,在 LHC 根目录执行:

```sh
rm -f cache/cacheconfig/moduleCache_*.cache.php cache/cacheconfig/templateCache.cache.php
```

然后重新加载页面;编译副本会在下一次请求时按需重建,不需要手动删除 `cache/compiledtemplates/` 里的文件。

## 测试

```sh
php /tmp/phpunit.phar --no-configuration --bootstrap tests/bootstrap.php tests
```

随仓库的 `phpunit.xml.dist` 是 PHPUnit 9 时代的配置,新版本用 `--no-configuration` 跑。
