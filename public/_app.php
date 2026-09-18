<?php

declare(strict_types=1);

// Jediné místo, které ví, kde leží aplikace (src/, config/, content/) vůči kořeni webu.
// Lokálně a v preferované variantě nasazení je aplikace o úroveň výš, vedle public/.
// Při jiném rozložení na hostingu se mění jen cesta v require níže.

define('PUBLIC_ROOT', __DIR__);

require dirname(__DIR__) . '/src/bootstrap.php';

// Soubor sám není stránka – při přímém otevření se tváří jako neexistující.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    not_found();
}
