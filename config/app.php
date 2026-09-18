<?php

declare(strict_types=1);

// Veřejné nastavení webu (je v gitu).
// Lokální nebo tajné hodnoty patří do config/local.php, který git ignoruje
// a který vrací pole s klíči, jež se mají přepsat.

return [
    // 'production' nebo 'dev'. Lze přepsat proměnnou prostředí APP_ENV.
    'env' => 'production',

    'site_name'    => 'mh42',
    'site_tagline' => 'osobní technický portál',   // doplňuje titulek úvodní stránky
    'base_url'     => 'https://mh42.cz',

    // Adresa profilu na GitHubu. Když je null, odkaz se nikde nezobrazí.
    'github_url' => 'https://github.com/Mihra-cz',

    // Hlavní navigace. Domů zastupuje wordmark.
    'nav' => [
        ['key' => 'o-mne',    'label' => 'O mně',    'href' => '/o-mne/'],
        ['key' => 'projekty', 'label' => 'Projekty', 'href' => '/projekty/'],
        ['key' => 'poznamky', 'label' => 'Poznámky', 'href' => '/poznamky/'],
    ],
];
