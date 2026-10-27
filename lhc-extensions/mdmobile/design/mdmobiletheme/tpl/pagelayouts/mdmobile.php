<?php
$mdLang = 'en';
$mdDir = 'ltr';
$mdCfg = erConfigClassLhConfig::getInstance();
$mdDirValue = $mdCfg->getOverrideValue('site', 'dir_language');
if ($mdDirValue === 'rtl' || $mdDirValue === 'ltr') {
    $mdDir = $mdDirValue;
}

$mdDocTitle = 'Visitor';
if (isset($Result['title']) && is_string($Result['title']) && $Result['title'] !== '') {
    $mdDocTitle = $Result['title'];
}

$mdCss = erLhcoreClassDesign::design('css/mdmobile.css', false);
$mdCssFile = erLhcoreClassDesign::design('css/mdmobile.css', true);
if (is_string($mdCss) && $mdCss !== '' && is_string($mdCssFile) && is_file($mdCssFile)) {
    $mdCss .= '?' . filemtime($mdCssFile);
}
$mdJs = erLhcoreClassDesign::design('js/mdmobile.js', false);
$mdJsFile = erLhcoreClassDesign::design('js/mdmobile.js', true);
if (is_string($mdJs) && $mdJs !== '' && is_string($mdJsFile) && is_file($mdJsFile)) {
    $mdJs .= '?' . filemtime($mdJsFile);
}
?><!DOCTYPE html>
<html class="md-mobile-root" lang="<?php echo htmlspecialchars($mdLang, ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo htmlspecialchars($mdDir, ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f7f8f8">
<title><?php echo htmlspecialchars($mdDocTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<?php if (is_string($mdCss) && $mdCss !== '') : ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($mdCss, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
</head>
<body class="md-mobile">
<?php if (isset($Result['content']) && is_string($Result['content']) && $Result['content'] !== '') : ?>
<?php echo $Result['content']; ?>
<?php else : ?>
<?php
    if (isset($Result['mdmobile']) && is_array($Result['mdmobile'])) {
        $md = $Result['mdmobile'];
    }
    include erLhcoreClassDesign::designtpl('lhmdmobile/chat.tpl.php');
?>
<?php endif; ?>
<?php if (is_string($mdJs) && $mdJs !== '') : ?>
<script src="<?php echo htmlspecialchars($mdJs, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
</body>
</html>
