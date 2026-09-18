<?php

declare(strict_types=1);

// Společný začátek každé stránky: cesty, konfigurace, hlavičky.

define('APP_ROOT', dirname(__DIR__));
if (!defined('PUBLIC_ROOT')) {
    // Stránky ho nastavují v public/_app.php; nástroje v tools/ počítají s public/ vedle src/.
    define('PUBLIC_ROOT', APP_ROOT . '/public');
}

require APP_ROOT . '/src/helpers.php';

(static function (): void {
    $config = require APP_ROOT . '/config/app.php';

    if (is_file(APP_ROOT . '/config/local.php')) {
        $config = array_replace($config, require APP_ROOT . '/config/local.php');
    }

    $env = getenv('APP_ENV');
    if (is_string($env) && $env !== '') {
        $config['env'] = $env;
    }

    define('CONFIG', $config);
})();

send_default_headers();
