<?php
/** @var array $page */

$siteName = (string) config('site_name');
$title = $page['title'] !== null
    ? $page['title'] . ' · ' . $siteName
    : $siteName . ' · ' . config('site_tagline');
$url = $page['path'] !== null ? absolute_url($page['path']) : null;
$styles = array_merge(
    ['css/fonts.css', 'css/tokens.css', 'css/base.css', 'css/layout.css', 'css/components.css', 'css/background.css'],
    $page['styles']
);
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($page['description'] !== ''): ?>
<meta name="description" content="<?= e($page['description']) ?>">
<?php endif; ?>
<?php if ($page['noindex']): ?>
<meta name="robots" content="noindex">
<?php endif; ?>
<?php if ($url !== null): ?>
<link rel="canonical" href="<?= e($url) ?>">
<?php endif; ?>

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:locale" content="cs_CZ">
<meta property="og:title" content="<?= e($title) ?>">
<?php if ($page['description'] !== ''): ?>
<meta property="og:description" content="<?= e($page['description']) ?>">
<?php endif; ?>
<?php if ($url !== null): ?>
<meta property="og:url" content="<?= e($url) ?>">
<?php endif; ?>
<meta property="og:image" content="<?= e(absolute_url('/assets/img/og-image.png')) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="mh42 – osobní technický portál">
<meta name="twitter:card" content="summary_large_image">

<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f5f1" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121314" media="(prefers-color-scheme: dark)">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<?php foreach ($styles as $style): ?>
<link rel="stylesheet" href="<?= e(asset($style)) ?>">
<?php endforeach; ?>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
</head>
<body>

<a class="skip-link" href="#obsah">Přeskočit na obsah</a>

<?php template('site-header', ['page' => $page]); ?>

<main id="obsah" class="wrap">
