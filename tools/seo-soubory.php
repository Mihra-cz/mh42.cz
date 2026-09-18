<?php

declare(strict_types=1);

// Vygeneruje public/robots.txt, public/sitemap.xml a public/feed.atom z config/app.php
// a content/notes.json, aby produkční adresa (base_url) i metadata poznámek byly jen
// na jednom místě.
// Sitemap = úvodní stránka + položky hlavní navigace + $extraPages + všechny poznámky.
// Feed = Atom 1.0 (RFC 4287) se souhrny poznámek.
//
// Spuštění: php tools/seo-soubory.php

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/notes.php';

// Veřejné stránky, které nejsou v navigaci ani mezi poznámkami.
$extraPages = [];

$x = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$notes = load_notes();

// ---------- sitemap.xml ----------
$pages = array_values(array_unique(array_merge(['/'], array_column(config('nav', []), 'href'), $extraPages)));

$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<!-- Generováno nástrojem tools/seo-soubory.php z config/app.php a content/notes.json. Neupravovat ručně. -->\n"
    . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($pages as $path) {
    $xml .= '  <url><loc>' . $x(absolute_url($path)) . "</loc></url>\n";
}
foreach ($notes as $note) {
    $xml .= '  <url><loc>' . $x(absolute_url(note_url($note))) . '</loc><lastmod>' . $x($note['updated']) . "</lastmod></url>\n";
}
$xml .= "</urlset>\n";

// ---------- robots.txt ----------
$robots = "User-agent: *\nAllow: /\n\nSitemap: " . absolute_url('/sitemap.xml') . "\n";

// ---------- feed.atom ----------
$updated = $notes === [] ? note_datetime(date('Y-m-d')) : note_datetime(max(array_column($notes, 'updated')));

$feed = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
    . "<!-- Generováno nástrojem tools/seo-soubory.php z content/notes.json. Neupravovat ručně. -->\n"
    . "<feed xmlns=\"http://www.w3.org/2005/Atom\" xml:lang=\"cs\">\n"
    . "  <title>mh42.cz – Poznámky</title>\n"
    . '  <id>' . $x(absolute_url('/poznamky/')) . "</id>\n"
    . '  <updated>' . $x($updated) . "</updated>\n"
    . '  <link rel="self" type="application/atom+xml" href="' . $x(absolute_url('/feed.atom')) . "\"/>\n"
    . '  <link rel="alternate" type="text/html" href="' . $x(absolute_url('/poznamky/')) . "\"/>\n"
    . '  <author><name>' . $x((string) config('site_name')) . '</name><uri>' . $x(absolute_url('/')) . "</uri></author>\n";
foreach ($notes as $note) {
    $url = absolute_url(note_url($note));
    $feed .= "  <entry>\n"
        . '    <title>' . $x($note['title']) . "</title>\n"
        . '    <id>' . $x($url) . "</id>\n"
        . '    <published>' . $x(note_datetime($note['published'])) . "</published>\n"
        . '    <updated>' . $x(note_datetime($note['updated'])) . "</updated>\n"
        . '    <link rel="alternate" type="text/html" href="' . $x($url) . "\"/>\n"
        . '    <summary>' . $x($note['summary']) . "</summary>\n"
        . "  </entry>\n";
}
$feed .= "</feed>\n";

file_put_contents(PUBLIC_ROOT . '/sitemap.xml', $xml);
file_put_contents(PUBLIC_ROOT . '/robots.txt', $robots);
file_put_contents(PUBLIC_ROOT . '/feed.atom', $feed);

echo 'public/sitemap.xml: ' . (count($pages) + count($notes)) . " adres\n"
    . 'public/robots.txt: Sitemap ' . absolute_url('/sitemap.xml') . "\n"
    . 'public/feed.atom: ' . count($notes) . " poznámek, updated {$updated}\n";
