<?php

declare(strict_types=1);

// Vygeneruje public/robots.txt a public/sitemap.xml z config/app.php,
// aby produkční adresa (base_url) byla nastavená jen na jednom místě.
// Veřejné stránky = úvodní stránka + položky hlavní navigace + $extraPages.
//
// Spuštění: php tools/seo-soubory.php

require dirname(__DIR__) . '/src/bootstrap.php';

// Veřejné stránky, které nejsou v navigaci (např. budoucí podstránky projektů).
$extraPages = [];

$pages = array_values(array_unique(array_merge(['/'], array_column(config('nav', []), 'href'), $extraPages)));

$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<!-- Generováno nástrojem tools/seo-soubory.php z config/app.php. Neupravovat ručně. -->\n"
    . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($pages as $path) {
    $xml .= '  <url><loc>' . htmlspecialchars(absolute_url($path), ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
}
$xml .= "</urlset>\n";

$robots = "User-agent: *\nAllow: /\n\nSitemap: " . absolute_url('/sitemap.xml') . "\n";

file_put_contents(PUBLIC_ROOT . '/sitemap.xml', $xml);
file_put_contents(PUBLIC_ROOT . '/robots.txt', $robots);

echo 'public/sitemap.xml: ' . count($pages) . " stránky\npublic/robots.txt: Sitemap " . absolute_url('/sitemap.xml') . "\n";
