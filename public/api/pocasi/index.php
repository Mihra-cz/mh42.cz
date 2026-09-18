<?php

declare(strict_types=1);

// POST /api/pocasi/  {"lat": 49.22, "lon": 15.88}
// Počasí pro místo zvolené návštěvníkem (public/assets/js/weather.js). Vrací vykreslenou
// část sekce Počasí – stejnou šablonu jako úvodní stránka. Souřadnice se zaokrouhlí
// na 2 desetinná místa a nikam se neukládají; cache je jen pod jejich otiskem.

require dirname(__DIR__, 2) . '/_app.php';
require APP_ROOT . '/src/api.php';
require APP_ROOT . '/src/weather.php';

$cfg = weather_config();
if ($cfg === null) {
    api_respond(404, ['error' => 'disabled']);
}

$input = api_request();
$coords = weather_coords($input['lat'] ?? null, $input['lon'] ?? null);
if ($coords === null) {
    api_respond(400, ['error' => 'coordinates']);
}

try {
    $weather = weather_get($cfg, $coords[0], $coords[1]);
} catch (Throwable $e) {
    error_log('mh42: počasí se nepodařilo připravit: ' . $e->getMessage());
    $weather = null;
}
if ($weather === null) {
    api_respond(503, ['error' => 'unavailable']);
}

ob_start();
template('weather-data', ['weather' => $weather]);
$html = (string) ob_get_clean();

api_respond(200, [
    'html'    => $html,
    'stale'   => $weather['stale'],
    'updated' => $weather['updated_at']->format(DATE_ATOM),
]);
