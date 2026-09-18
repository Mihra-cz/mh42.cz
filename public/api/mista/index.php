<?php

declare(strict_types=1);

// POST /api/mista/  {"q": "Brno"}
// Hledání místa pro počasí (public/assets/js/weather.js) přes Open-Meteo Geocoding.
// Vrací nejvýš 5 míst {label, detail, latitude, longitude}; dotaz se nikam neukládá.

require dirname(__DIR__, 2) . '/_app.php';
require APP_ROOT . '/src/api.php';
require APP_ROOT . '/src/weather.php';

$cfg = weather_config();
if ($cfg === null) {
    api_respond(404, ['error' => 'disabled']);
}

$input = api_request();
$query = place_query($input['q'] ?? null);
if ($query === null) {
    api_respond(400, ['error' => 'query']);
}

try {
    $places = place_search($cfg, $query);
} catch (Throwable $e) {
    error_log('mh42: hledání místa selhalo: ' . $e->getMessage());
    $places = null;
}
if ($places === null) {
    api_respond(503, ['error' => 'unavailable']);
}

api_respond(200, ['results' => $places]);
