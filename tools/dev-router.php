<?php

declare(strict_types=1);

// Router pro vestavěný server PHP – jen pro vývoj:
//   php -S localhost:8000 -t public tools/dev-router.php
// Existující soubory a složky s index.php obslouží server sám,
// ostatní adresy dostanou stránku 404 stejně jako na produkci.

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$file = dirname(__DIR__) . '/public' . $path;

if (is_file($file) || is_file(rtrim($file, '/') . '/index.php')) {
    return false;
}

require dirname(__DIR__) . '/public/404.php';
