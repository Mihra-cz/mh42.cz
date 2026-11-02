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

    // Veřejný heartbeat z NexusSTATu (src/heartbeat.php, endpoint /api/heartbeat/).
    // Na webu se ukazuje jedině celkový stav u projektu 'project' na stránce Projekty.
    // enabled false = endpoint vrací 404 a stav se nikde nezobrazuje.
    // Sdílené tajemství je soubor správce mimo veřejný adresář i Git (chmod 600);
    // relativní cesty se berou od kořene aplikace. Postup je v docs/nasazeni.md.
    'heartbeat' => [
        'enabled'       => false,
        'secret_file'   => 'config/heartbeat.key',
        'state_dir'     => 'var/heartbeat',
        'project'       => 'nexusstat',
        'require_https' => true,
    ],

    // Počasí na úvodní stránce – data z Open-Meteo načítá server (src/weather.php).
    // Výchozí místo je veřejné centrum města, ne adresa bydliště. Když je enabled false
    // nebo chybí název či souřadnice, sekce se vůbec nevykreslí.
    'weather' => [
        'enabled'   => true,
        'default'   => [
            'label'     => 'Třebíč',
            'latitude'  => 49.216,
            'longitude' => 15.880,
        ],
        'timezone'  => 'Europe/Prague',
        'cache_ttl' => 900,   // sekund
    ],
];
