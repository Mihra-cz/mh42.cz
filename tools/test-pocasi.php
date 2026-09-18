<?php

declare(strict_types=1);

// Testy počasí bez sítě: nastavení, souřadnice, parser Open-Meteo, převod kódů WMO,
// formátování, cache a výpadky API (podvržený HTTP klient), hledání místa, šablony
// a úvodní stránka (v dočasné kopii aplikace s vlastním config/local.php).
// Open-Meteo se nevolá – odpovědi jsou v tools/testdata/open-meteo/.
//
//   php tools/test-pocasi.php
//   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli-alpine php tools/test-pocasi.php

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/weather.php';

$results = ['ok' => 0, 'chyba' => 0];

function check(bool $ok, string $name): void
{
    global $results;
    $results[$ok ? 'ok' : 'chyba']++;
    echo ($ok ? '  ok     ' : '  CHYBA  ') . $name . "\n";
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

function remove_tree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                remove_tree($path . '/' . $name);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

function fixture(string $name): string
{
    return (string) file_get_contents(APP_ROOT . '/tools/testdata/open-meteo/' . $name);
}

/** Úprava fixture odpovědi Open-Meteo (pole se změní přes callback). */
function forecast_with(callable $change): string
{
    $data = json_decode(fixture('forecast-trebic.json'), true);
    $change($data);

    return (string) json_encode($data);
}

/** Podvržený HTTP klient: vrací připravené odpovědi a zapisuje volané adresy. */
final class FakeApi
{
    public array $calls = [];

    public function __construct(public ?string $reply = null)
    {
    }

    public function __invoke(string $url): ?string
    {
        $this->calls[] = $url;

        return $this->reply;
    }
}

function files_in(string $dir): array
{
    return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
}

$tmp = sys_get_temp_dir() . '/mh42-test-pocasi-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(static fn () => remove_tree($tmp));
// Log serveru do souboru – na konci se kontroluje, že v něm nejsou souřadnice ani dotazy.
ini_set('error_log', $tmp . '/php-error.log');

$raw = [
    'enabled'       => true,
    'default'       => ['label' => 'Třebíč', 'latitude' => 49.216, 'longitude' => 15.880],
    'timezone'      => 'Europe/Prague',
    'cache_ttl'     => 900,
    'forecast_url'  => 'https://api.test/v1/forecast',
    'geocoding_url' => 'https://geo.test/v1/search',
];
$counter = 0;
$newConfig = static function (array $changes = []) use ($raw, $tmp, &$counter): array {
    return weather_config(array_replace($raw, ['cache_dir' => $tmp . '/cache-' . ++$counter], $changes));
};

// Fixture je skutečná odpověď Open-Meteo z 2026-09-18 20:30 (Třebíč, 49.22 / 15.88).
$now = (new DateTimeImmutable('2026-09-18 20:40:00', new DateTimeZone('Europe/Prague')))->getTimestamp();
$trebic = fixture('forecast-trebic.json');

// ---------- nastavení ----------
section('Nastavení (config/app.php)');
$real = weather_config(config('weather'));
check($real !== null, 'config/app.php: weather je platné a zapnuté');
check($real !== null && $real['label'] === 'Třebíč' && $real['latitude'] === 49.22 && $real['longitude'] === 15.88,
    'výchozí místo Třebíč, souřadnice zaokrouhlené na 49.22 / 15.88');
check($real !== null && $real['timezone'] === 'Europe/Prague' && $real['cache_ttl'] === 900, 'Europe/Prague, cache 900 s');
check($real !== null && $real['forecast_url'] === 'https://api.open-meteo.com/v1/forecast'
    && $real['geocoding_url'] === 'https://geocoding-api.open-meteo.com/v1/search'
    && $real['cache_dir'] === APP_ROOT . '/var/weather', 'produkční adresy API a var/weather/');

$invalid = [
    'enabled false'           => ['enabled' => false],
    'enabled jako text'       => ['enabled' => 'true'],
    'bez názvu'               => ['default' => ['label' => null, 'latitude' => 49.2, 'longitude' => 15.9]],
    'prázdný název'           => ['default' => ['label' => '  ', 'latitude' => 49.2, 'longitude' => 15.9]],
    'bez latitude'            => ['default' => ['label' => 'X', 'longitude' => 15.9]],
    'longitude null'          => ['default' => ['label' => 'X', 'latitude' => 49.2, 'longitude' => null]],
    'souřadnice jako text'    => ['default' => ['label' => 'X', 'latitude' => '49.2', 'longitude' => 15.9]],
    'latitude mimo rozsah'    => ['default' => ['label' => 'X', 'latitude' => 91, 'longitude' => 15.9]],
    'neplatné časové pásmo'   => ['timezone' => 'Europe/Trebic'],
    'příliš krátká cache'     => ['cache_ttl' => 10],
];
foreach ($invalid as $name => $change) {
    check(weather_config(array_replace($raw, $change)) === null, "neplatné nastavení ({$name}) → sekce se nevykreslí");
}
check(weather_config(null) === null && weather_config([]) === null, 'chybějící nastavení → sekce se nevykreslí');

// ---------- souřadnice ----------
section('Souřadnice');
check(weather_coords(49.2244444, 15.8766666) === [49.22, 15.88], '49.2244444 / 15.8766666 → 49.22 / 15.88');
check(weather_coords(-33.8688, 151.2093) === [-33.87, 151.21], 'záporné souřadnice → -33.87 / 151.21');
check(weather_coords(90, -180) === [90.0, -180.0], 'hranice rozsahu 90 / -180 platí');
check(weather_coords(-0.001, 0.004) === [0.0, 0.0] && weather_coord_text(weather_coords(-0.001, 0.0)[0]) === '0.00', 'záporná nula → 0.00');
foreach ([[90.01, 0], [0, 180.5], [-91, 0], ['49.2', 15.8], [49.2, null], [NAN, 0], [INF, 0], [true, 1], [[49], 15]] as $bad) {
    check(weather_coords($bad[0], $bad[1]) === null, 'odmítnuto: ' . json_encode($bad, JSON_PARTIAL_OUTPUT_ON_ERROR));
}

// ---------- adresy API ----------
section('Adresy API (sestavené přes http_build_query)');
$cfg = $newConfig();
check(weather_forecast_url($cfg, 49.22, 15.88) === 'https://api.test/v1/forecast?latitude=49.22&longitude=15.88'
    . '&current=temperature_2m%2Crelative_humidity_2m%2Cweather_code%2Cwind_speed_10m'
    . '&daily=weather_code%2Ctemperature_2m_min%2Ctemperature_2m_max'
    . '&timezone=Europe%2FPrague&temperature_unit=celsius&wind_speed_unit=kmh&forecast_days=3',
    'předpověď pro výchozí místo: jen zobrazované údaje, Europe/Prague, °C, km/h, 3 dny');
check(str_contains(weather_forecast_url($cfg, 49.2, 16.61), 'latitude=49.20&longitude=16.61&') && str_contains(weather_forecast_url($cfg, 49.2, 16.61), '&timezone=auto&'),
    'jiné místo: pevná přesnost souřadnic, časové pásmo auto');
check(place_search_url($cfg, "Nové Město na Moravě") === 'https://geo.test/v1/search?name=Nov%C3%A9%20M%C4%9Bsto%20na%20Morav%C4%9B&count=5&language=cs&format=json',
    'hledání: name zakódované, count=5, language=cs, format=json');

// ---------- parser ----------
section('Parser odpovědi Open-Meteo');
$parsed = weather_parse_forecast($trebic);
check($parsed !== null, 'skutečná odpověď Open-Meteo projde');
check($parsed !== null && $parsed['current'] === ['temperature' => 16.1, 'humidity' => 63, 'weather_code' => 3, 'wind_speed' => 4.2]
    && $parsed['timezone'] === 'Europe/Prague', 'aktuální stav: 16.1 °C, 63 %, kód 3, 4.2 km/h');
check($parsed !== null && array_column($parsed['days'], 'date') === ['2026-09-18', '2026-09-19', '2026-09-20']
    && $parsed['days'][2] === ['date' => '2026-09-20', 'weather_code' => 61, 'min' => 10.0, 'max' => 22.2], 'tři dny s kódem, min a max');
check($parsed !== null && !array_key_exists('latitude', $parsed) && !str_contains((string) json_encode($parsed), '49.2'),
    'model neobsahuje souřadnice ani nadmořskou výšku');

$broken = [
    'nevalidní JSON'            => '{"current": {',
    'prázdná odpověď'           => '',
    'JSON pole místo objektu'   => '[1,2,3]',
    'chybí current'             => forecast_with(static function (&$d) { unset($d['current']); }),
    'chybí daily'               => forecast_with(static function (&$d) { unset($d['daily']); }),
    'chybí teplota'             => forecast_with(static function (&$d) { unset($d['current']['temperature_2m']); }),
    'teplota jako text'         => forecast_with(static function (&$d) { $d['current']['temperature_2m'] = '16.1'; }),
    'teplota null'              => forecast_with(static function (&$d) { $d['current']['temperature_2m'] = null; }),
    'vlhkost 120 %'             => forecast_with(static function (&$d) { $d['current']['relative_humidity_2m'] = 120; }),
    'kód počasí 100'            => forecast_with(static function (&$d) { $d['current']['weather_code'] = 100; }),
    'kód počasí jako číslo 3.5' => forecast_with(static function (&$d) { $d['current']['weather_code'] = 3.5; }),
    'teplota ve °F'             => forecast_with(static function (&$d) { $d['current_units']['temperature_2m'] = '°F'; }),
    'vítr v m/s'                => forecast_with(static function (&$d) { $d['current_units']['wind_speed_10m'] = 'm/s'; }),
    'různě dlouhé řady dní'     => forecast_with(static function (&$d) { array_pop($d['daily']['temperature_2m_max']); }),
    'null v denních datech'     => forecast_with(static function (&$d) { $d['daily']['temperature_2m_min'][1] = null; }),
    'prázdné denní řady'        => forecast_with(static function (&$d) { $d['daily'] = ['time' => [], 'weather_code' => [], 'temperature_2m_min' => [], 'temperature_2m_max' => []]; }),
    'neplatné datum'            => forecast_with(static function (&$d) { $d['daily']['time'][0] = '2026-02-30'; }),
    'min větší než max'         => forecast_with(static function (&$d) { $d['daily']['temperature_2m_min'][0] = 30; }),
    'neplatné časové pásmo'     => forecast_with(static function (&$d) { $d['timezone'] = 'Mars/Olympus'; }),
];
foreach ($broken as $name => $body) {
    check(weather_parse_forecast($body) === null, "odmítnuto: {$name}");
}
$unknown = weather_parse_forecast(forecast_with(static function (&$d) { $d['current']['weather_code'] = 4; }));
check($unknown !== null && weather_label($unknown['current']['weather_code']) === 'počasí', 'neznámý kód (4) není chyba → popis „počasí“');

// ---------- kódy WMO ----------
section('Kódy počasí WMO → čeština');
$expected = [
    0 => 'jasno', 1 => 'převážně jasno', 2 => 'polojasno', 3 => 'zataženo', 45 => 'mlha', 48 => 'mlha',
    51 => 'mrholení', 53 => 'mrholení', 55 => 'mrholení', 56 => 'mrznoucí mrholení', 57 => 'mrznoucí mrholení',
    61 => 'slabý déšť', 63 => 'déšť', 65 => 'silný déšť', 66 => 'mrznoucí déšť', 67 => 'mrznoucí déšť',
    71 => 'sněžení', 73 => 'sněžení', 75 => 'sněžení', 77 => 'sněžení',
    80 => 'dešťové přeháňky', 81 => 'dešťové přeháňky', 82 => 'dešťové přeháňky',
    85 => 'sněhové přeháňky', 86 => 'sněhové přeháňky', 95 => 'bouřka', 96 => 'bouřka s krupobitím', 99 => 'bouřka s krupobitím',
];
$wrong = array_filter($expected, static fn (string $label, int $code) => weather_label($code) !== $label, ARRAY_FILTER_USE_BOTH);
check($wrong === [], count($expected) . ' kódů podle dokumentace Open-Meteo' . ($wrong ? ': ' . json_encode($wrong, JSON_UNESCAPED_UNICODE) : ''));
check(weather_label(4) === 'počasí' && weather_label(98) === 'počasí', 'neznámé kódy → „počasí“');

// ---------- formátování ----------
section('Formátování');
$nbsp = "\u{00A0}";
check(weather_temperature(14.8, 1) === "14,8{$nbsp}°C", '14.8 → „14,8 °C“ (desetinná čárka, nezlomitelná mezera)');
check(weather_temperature(16.0, 1) === "16,0{$nbsp}°C", '16 → „16,0 °C“ (vždy jedno desetinné místo)');
check(weather_temperature(-3.25, 1) === "\u{2212}3,3{$nbsp}°C", '-3.25 → „−3,3 °C“ (znaménko minus)');
check(weather_temperature(-0.04, 1) === "0,0{$nbsp}°C" && weather_number(-0.4, 0) === '0', 'bez „−0“');
check(weather_temperature(17.4, 0) === "17{$nbsp}°C" && weather_number(8.6, 0) === '9', 'min/max na celé stupně');
check(weather_number(4.2, 0) === '4' && weather_number(63, 0) === '63', 'vítr a vlhkost na celá čísla');
check([weather_day_name('2026-09-18', '2026-09-18'), weather_day_name('2026-09-19', '2026-09-18'), weather_day_name('2026-09-20', '2026-09-18'), weather_day_name('2026-09-21', '2026-09-18')]
    === ['dnes', 'zítra', 'neděle', 'pondělí'], 'dny: dnes, zítra, neděle, pondělí');
$at = new DateTimeImmutable('2026-09-18 20:15:00', new DateTimeZone('Europe/Prague'));
check(weather_time($at, $now) === '20:15', 'čas aktualizace dnes → „20:15“');
check(weather_time($at, $now + 86400) === "18.{$nbsp}9.{$nbsp}20:15", 'čas z jiného dne → „18. 9. 20:15“');

// ---------- cache a výpadky ----------
section('Cache a výpadky API (podvržený HTTP klient)');
$cfg = $newConfig();
$base = weather_cache_base($cfg, 49.22, 15.88);
$api = new FakeApi($trebic);

$w = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]);
check($w !== null && !$w['stale'] && count($api->calls) === 1 && $w['current']['temperature'] === 16.1, 'úspěšná odpověď: 1 dotaz na API, čerstvá data');
check($w !== null && $w['updated_time'] === '20:40' && $w['current']['weather_label'] === 'zataženo', 'model: čas aktualizace a český popis');
check($w !== null && array_column($w['days'], 'name') === ['dnes', 'zítra', 'neděle'] && array_column($w['days'], 'weather_label') === ['mlha', 'mlha', 'slabý déšť'],
    'model: dny s názvy a popisy');
check(basename($base) === hash('sha256', '49.22,15.88') && is_file($base . '.json'), 'cache: var/weather/sha256("49.22,15.88").json');
$entry = json_decode((string) file_get_contents($base . '.json'), true);
check(($entry['version'] ?? null) === 1 && ($entry['fetched_at'] ?? null) === '2026-09-18T20:40:00+02:00' && ($entry['data'] ?? null) === $parsed,
    'cache: version, fetched_at a normalizovaná data (ne celá odpověď API)');
check(!str_contains((string) file_get_contents($base . '.json'), '15.88') && !str_contains((string) file_get_contents($base . '.json'), 'generationtime'),
    'cache neobsahuje souřadnice ani zbytek odpovědi API');
$before = (string) file_get_contents($base . '.json');

$w = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now + 14 * 60]);
check($w !== null && !$w['stale'] && count($api->calls) === 1, 'platná cache (14 min): žádný dotaz na API');

$api->reply = null;
$w = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now + 16 * 60]);
check($w !== null && $w['stale'] && count($api->calls) === 2 && $w['updated_time'] === '20:40', 'prošlá cache + chyba API: stará data označená jako stale');
check(file_get_contents($base . '.json') === $before && is_file($base . '.fail'), 'chyba API: poslední dobrá cache beze změny, značka chyby');

$w = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now + 17 * 60]);
check($w !== null && $w['stale'] && count($api->calls) === 2, 'hned po chybě se API znovu nevolá (odklad 2 min)');

foreach (['nevalidní JSON' => '{"current":', 'chybí current' => forecast_with(static function (&$d) { unset($d['current']); }),
          'chybí daily' => forecast_with(static function (&$d) { unset($d['daily']); })] as $name => $reply) {
    @unlink($base . '.fail');
    $api->reply = $reply;
    $w = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now + 20 * 60]);
    check($w !== null && $w['stale'] && file_get_contents($base . '.json') === $before, "{$name}: chyba API, dobrá cache se nepřepíše");
}

@unlink($base . '.fail');
$api->reply = forecast_with(static function (&$d) { $d['current']['temperature_2m'] = 12.3; });
$w = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now + 25 * 60]);
check($w !== null && !$w['stale'] && $w['current']['temperature'] === 12.3 && $w['updated_time'] === '21:05', 'prošlá cache + úspěch: nová data');
check(str_contains((string) file_get_contents($base . '.json'), '"fetched_at": "2026-09-18T21:05:00+02:00"') && !is_file($base . '.fail'),
    'nová data v cache, značka chyby smazaná');

$api->reply = null;
check(weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now + 25 * 60 + 6 * 3600 + 60]) === null, 'data starší než 6 h se po chybě API nezobrazí');

$empty = $newConfig();
$api = new FakeApi(null);
check(weather_get($empty, 49.22, 15.88, ['fetch' => $api, 'now' => $now]) === null && count($api->calls) === 1, 'žádná cache + chyba API → nic (sekce se nevykreslí)');

$cfg = $newConfig();
$api = new FakeApi('{"current":');
check(weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]) === null && !is_file(weather_cache_base($cfg, 49.22, 15.88) . '.json'),
    'žádná cache + nevalidní JSON → nic, žádná cache nevznikne');

$cfg = $newConfig();
mkdir($cfg['cache_dir']);
file_put_contents(weather_cache_base($cfg, 49.22, 15.88) . '.json', 'poškozený soubor');
$api = new FakeApi($trebic);
$w = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]);
check($w !== null && count($api->calls) === 1 && weather_cache_read(weather_cache_base($cfg, 49.22, 15.88) . '.json') !== null,
    'poškozená cache se ignoruje a nahradí');

$cfg = $newConfig();
$api = new FakeApi(forecast_with(static function (&$d) { $d['current']['weather_code'] = 42; $d['daily']['weather_code'][1] = 5; }));
$w = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]);
check($w !== null && $w['current']['weather_label'] === 'počasí' && $w['days'][1]['weather_label'] === 'počasí', 'neznámý kód v odpovědi: stránka funguje, popis „počasí“');

section('Atomický zápis cache');
$cfg = $newConfig();
$file = weather_cache_base($cfg, 49.22, 15.88) . '.json';
check(weather_cache_write($file, $parsed, $now, 'Europe/Prague'), 'zápis se povede (složka se vytvoří)');
check(files_in($cfg['cache_dir']) === [basename($file)], 'po zápisu ve složce jen cílový soubor, žádný dočasný');
check(weather_cache_read($file) !== null, 'zapsaný soubor je celý a platný');
$good = (string) file_get_contents($file);
$blocked = weather_cache_base($cfg, 1.0, 1.0) . '.json';
mkdir($blocked);   // cílem je složka → přejmenování selže
check(!@weather_cache_write($blocked, $parsed, $now, 'Europe/Prague') && is_dir($blocked), 'když přejmenování selže, zápis vrátí false');
check(!array_filter(files_in($cfg['cache_dir']), static fn ($n) => str_starts_with($n, '.tmp-')), 'po neúspěšném zápisu nezůstane dočasný soubor');
check(file_get_contents($file) === $good, 'jiná cache zůstává beze změny');
$src = (string) file_get_contents(APP_ROOT . '/src/weather.php');
check(str_contains($src, "\$tmp = \$dir . '/.tmp-'") && str_contains($src, '@rename($tmp, $file)'), 'zápis = dočasný soubor ve stejné složce + rename()');
$cfg = $newConfig(['cache_dir' => $file . '/pod-souborem']);
$api = new FakeApi($trebic);
$w = @weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]);
check($w !== null && !$w['stale'], 'nezapisovatelná cache: web dál zobrazí čerstvá data (jen bez cache)');

section('Cache oddělená podle místa');
$cfg = $newConfig();
$api = new FakeApi($trebic);
weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]);
$brno = weather_coords(49.19522, 16.60796);
$api->reply = forecast_with(static function (&$d) { $d['current']['temperature_2m'] = 18.9; });
$wb = weather_get($cfg, $brno[0], $brno[1], ['fetch' => $api, 'now' => $now]);
$wt = weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]);
check($wb['current']['temperature'] === 18.9 && $wt['current']['temperature'] === 16.1 && count($api->calls) === 2, 'Třebíč a Brno mají vlastní data');
$names = array_values(array_filter(files_in($cfg['cache_dir']), static fn ($n) => str_ends_with($n, '.json') && $n !== 'budget.json'));
sort($names);
$expectNames = [hash('sha256', '49.20,16.61') . '.json', hash('sha256', '49.22,15.88') . '.json'];
sort($expectNames);
check($names === $expectNames, 'dva soubory: sha256("49.20,16.61") a sha256("49.22,15.88")');
check(str_contains($api->calls[1], 'latitude=49.20&longitude=16.61') && str_contains($api->calls[1], 'timezone=auto'), 'dotaz pro Brno: zaokrouhlené souřadnice, timezone=auto');
check(!array_filter(files_in($cfg['cache_dir']), static fn ($n) => !preg_match('/^([0-9a-f]{64}\.(json|fail)|budget\.json)$/', $n)),
    'názvy souborů jsou jen otisky (žádný text od návštěvníka)');

section('Strop dotazů a úklid');
$cfg = $newConfig();
$api = new FakeApi(null);
for ($i = 0; $i < WEATHER_BUDGET_MINUTE + 5; $i++) {
    weather_get($cfg, 10.0 + $i, 10.0, ['fetch' => $api, 'now' => $now]);
}
check(count($api->calls) === WEATHER_BUDGET_MINUTE, 'vlastní místa: nejvýš ' . WEATHER_BUDGET_MINUTE . ' dotazů na API za minutu');
$api->reply = $trebic;
check(weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]) !== null, 'výchozí místo se do stropu nepočítá');
check(place_search($cfg, 'Brno', ['fetch' => $api, 'now' => $now]) === null, 'hledání po vyčerpání stropu → nedostupné');
check(weather_get($cfg, 50.0, 10.0, ['fetch' => $api, 'now' => $now + 60]) !== null, 'další minutu se strop obnoví');
$budget = json_decode((string) file_get_contents($cfg['cache_dir'] . '/budget.json'), true);
check(array_keys($budget) === ['minute', 'm', 'day', 'd'] && $budget['m'] === 1 && $budget['d'] === WEATHER_BUDGET_MINUTE + 1,
    'stav stropu: jen čítače minuty a dne ' . json_encode($budget) . ' – žádné IP adresy, místa ani historie');
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
weather_get($cfg, 51.0, 10.0, ['fetch' => $api, 'now' => $now + 61]);
$stored = implode('', array_map(static fn ($n) => (string) file_get_contents($cfg['cache_dir'] . '/' . $n), files_in($cfg['cache_dir'])));
check(!str_contains($stored, '203.0.113.7'), 'IP adresa návštěvníka se do var/weather/ nezapisuje');
check(WEATHER_BUDGET_MINUTE === 30 && WEATHER_BUDGET_DAY === 1500, 'strop 30 dotazů za minutu a 1 500 za den');
check(WEATHER_CACHE_MAX_AGE === 7 * 86400 && WEATHER_STALE_MAX === 6 * 3600 && WEATHER_CACHE_MAX_FILES === 500 && $real['cache_ttl'] === 900,
    'meze: platnost 15 min, stará data nejvýš 6 h, soubory 7 dní, nejvýš 500 souborů');

section('Omezení velikosti cache');
$dataFiles = static fn (string $dir): array => array_values(array_filter(files_in($dir), static fn ($n) => preg_match('/^[0-9a-f]{64}\.json$/', $n)));
$cfg = $newConfig();
$api = new FakeApi($trebic);
$brno = weather_coords(49.19522, 16.60796);
weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]);
weather_get($cfg, $brno[0], $brno[1], ['fetch' => $api, 'now' => $now]);
$before = $dataFiles($cfg['cache_dir']);
weather_get($cfg, $brno[0], $brno[1], ['fetch' => $api, 'now' => $now + 16 * 60]);
weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now + 16 * 60]);
check(count($api->calls) === 4 && $dataFiles($cfg['cache_dir']) === $before && count($before) === 2,
    'obnova stejného místa soubor přepíše – nový nepřibude (2 místa = 2 soubory)');

$cfg = $newConfig();
$api = new FakeApi($trebic);
weather_get($cfg, 49.22, 15.88, ['fetch' => $api, 'now' => $now]);
$dir = $cfg['cache_dir'];
$ages = ['8 dní' => 8 * 86400, '6 dní' => 6 * 86400];
foreach ($ages as $label => $age) {
    file_put_contents($dir . '/' . hash('sha256', $label) . '.json', '{}');
    touch($dir . '/' . hash('sha256', $label) . '.json', $now - $age);
}
file_put_contents($dir . '/' . hash('sha256', 'stara-chyba') . '.fail', '');
touch($dir . '/' . hash('sha256', 'stara-chyba') . '.fail', $now - 3 * 60);
file_put_contents($dir . '/' . hash('sha256', 'nova-chyba') . '.fail', '');
touch($dir . '/' . hash('sha256', 'nova-chyba') . '.fail', $now - 30);
file_put_contents($dir . '/.tmp-0123456789ab', '');
touch($dir . '/.tmp-0123456789ab', $now - 2 * 3600);
file_put_contents($dir . '/budget.json', '{}');
touch($dir . '/budget.json', $now - 30 * 86400);
weather_get($cfg, 50.0, 14.0, ['fetch' => $api, 'now' => $now]);   // nové místo → zápis → úklid
check(!is_file($dir . '/' . hash('sha256', '8 dní') . '.json'), 'soubor s daty starší než 7 dní se smaže');
check(is_file($dir . '/' . hash('sha256', '6 dní') . '.json'), 'soubor mladší než 7 dní zůstane');
check(!is_file($dir . '/' . hash('sha256', 'stara-chyba') . '.fail') && is_file($dir . '/' . hash('sha256', 'nova-chyba') . '.fail'),
    'značka chyby se smaže po uplynutí odkladu (2 min), čerstvá zůstane');
check(!is_file($dir . '/.tmp-0123456789ab') && is_file($dir . '/budget.json'), 'zapomenutý dočasný soubor se smaže, stav stropu zůstane');

// Strop 500 souborů: 520 cizích míst + výchozí místo (nejstarší ze všech) + nové místo.
$cfg = $newConfig();
$dir = $cfg['cache_dir'];
mkdir($dir);
$fake = [];
for ($i = 0; $i < 520; $i++) {
    $name = hash('sha256', 'misto-' . $i) . '.json';
    file_put_contents($dir . '/' . $name, '{}');
    touch($dir . '/' . $name, $now - 3600 - (520 - $i) * 60);   // misto-0 je nejstarší
    $fake[] = $name;
}
$default = basename(weather_cache_base($cfg, 49.22, 15.88)) . '.json';
weather_cache_write($dir . '/' . $default, $parsed, $now, 'Europe/Prague');
touch($dir . '/' . $default, $now - 6 * 86400);
$api = new FakeApi($trebic);
$started = hrtime(true);
$w = weather_get($cfg, 48.0, 17.0, ['fetch' => $api, 'now' => $now]);
$ms = (hrtime(true) - $started) / 1e6;
$new = basename(weather_cache_base($cfg, 48.0, 17.0)) . '.json';
$left = $dataFiles($dir);
check(count($left) === WEATHER_CACHE_MAX_FILES, 'strop: po zápisu nového místa zůstane přesně ' . WEATHER_CACHE_MAX_FILES . ' souborů (bylo 522)');
check($w !== null && in_array($new, $left, true), 'právě zapsaný soubor nového místa zůstane');
check(in_array($default, $left, true), 'soubor výchozího místa zůstane, i když je nejstarší');
check(array_diff($fake, $left) === array_slice($fake, 0, 22), 'smazalo se 22 nejstarších souborů ostatních míst');
check($ms < 100, sprintf('zápis nového místa včetně úklidu 522 souborů: %.1f ms', $ms));

$dir = $newConfig()['cache_dir'];
mkdir($dir);
for ($i = 0; $i < WEATHER_CACHE_MAX_FILES + 3; $i++) {
    $name = hash('sha256', 'x' . $i) . '.json';
    file_put_contents($dir . '/' . $name, '{}');
    touch($dir . '/' . $name, $now - 10 * 86400 + $i);   // všechny starší než 7 dní
}
$protected = hash('sha256', 'x0') . '.json';   // nejstarší ze všech
weather_cache_cleanup($dir, $now, [$protected]);
check($dataFiles($dir) === [$protected], 'úklid nesmaže chráněný (právě používaný) soubor, i když je nejstarší a prošlý');

$model = weather_model($newConfig(), ['fetched_at' => $now, 'data' => $parsed], true, $now + 5 * 3600);
check(array_column($model['days'], 'date') === ['2026-09-19', '2026-09-20'] && $model['days'][0]['name'] === 'dnes' && $model['updated_time'] === "18.{$nbsp}9.{$nbsp}20:40",
    'starší data po půlnoci: včerejšek vypadne, čas aktualizace s datem');

// ---------- hledání místa ----------
section('Hledání místa (Geocoding)');
check(place_query('Brno') === 'Brno' && place_query("  Nové \t Město  na Moravě ") === 'Nové Město na Moravě', 'dotaz: oříznutí a sloučení mezer');
foreach (['Ústí nad Labem', "O'Neill", 'Saint-Étienne', 'Frýdek-Místek', '674 01', 'Tokio', 'Zürich', 'Kraków'] as $ok) {
    check(place_query($ok) === $ok, "platný dotaz: {$ok}");
}
foreach (['B', ' ', str_repeat('a', 61), 'Brno&count=100', '<script>', 'a/b', "Br\x00no", "\xff\xfe", 'https://x.cz', 'Brno;DROP', 42, null, ['Brno']] as $bad) {
    check(place_query($bad) === null, 'odmítnutý dotaz: ' . json_encode($bad, JSON_INVALID_UTF8_SUBSTITUTE));
}

$places = place_parse(fixture('geocoding-brno.json'));
check(is_array($places) && count($places) === 5, 'Brno: 5 kandidátů');
check(($places[0] ?? null) === ['label' => 'Brno', 'detail' => 'Jihomoravský · Česko', 'latitude' => 49.2, 'longitude' => 16.61],
    'kandidát: název, kraj · země, souřadnice na 2 desetinná místa');
check(place_parse(fixture('geocoding-nic.json')) === [], 'žádný výsledek (bez klíče results) → prázdný seznam');
check(place_parse('{"results":') === null && place_parse('{"results":"x"}') === null, 'nevalidní odpověď → chyba');
check(place_parse('{"results":[{"name":"A","latitude":"x","longitude":1},{"name":"","latitude":1,"longitude":1},{"name":"B","latitude":1,"longitude":2},{"name":"B","latitude":1,"longitude":2}]}')
    === [['label' => 'B', 'detail' => '', 'latitude' => 1.0, 'longitude' => 2.0]], 'neplatné kandidáty a duplicity se vynechají');
$cfg = $newConfig();
$api = new FakeApi(null);
check(place_search($cfg, 'Brno', ['fetch' => $api, 'now' => $now]) === null && count($api->calls) === 1, 'chyba Geocoding API → nedostupné');
$api->reply = fixture('geocoding-brno.json');
check(count(place_search($cfg, 'Brno', ['fetch' => $api, 'now' => $now]) ?? []) === 5 && str_contains($api->calls[1], 'name=Brno&count=5&language=cs&format=json'),
    'úspěšné hledání přes podvržené API');

// ---------- šablony ----------
section('Šablona sekce Počasí');
$cfg = $newConfig();
$w = weather_get($cfg, 49.22, 15.88, ['fetch' => new FakeApi($trebic), 'now' => $now]) + ['location' => 'Třebíč'];
ob_start();
template('weather', ['weather' => $w]);
$html = (string) ob_get_clean();
foreach ([
    'nadpis sekce'            => '<h2 id="pocasi" class="section__title">Počasí</h2>',
    'místo'                   => '<p class="weather__location" data-weather-location>Třebíč</p>',
    'aktuální teplota'        => "<span class=\"weather__temp\">16,1{$nbsp}°C</span> <span class=\"weather__desc\">zataženo</span>",
    'vlhkost'                 => '<dt>vlhkost</dt><dd>63&nbsp;%</dd>',
    'vítr'                    => '<dt>vítr</dt><dd>4&nbsp;km/h</dd>',
    'dnes'                    => '<time datetime="2026-09-18">dnes</time>',
    'neděle'                  => '<time datetime="2026-09-20">neděle</time>',
    'rozsah teplot'           => "<span class=\"visually-hidden\">od </span>8<span aria-hidden=\"true\"> / </span><span class=\"visually-hidden\"> do </span>21{$nbsp}°C",
    'aktualizováno'           => 'aktualizováno <time datetime="2026-09-18T20:40:00+02:00">20:40</time>',
    'atribuce Open-Meteo'     => 'Data: <a class="ext" href="https://open-meteo.com/">Open-Meteo</a>',
    'ovládání skryté bez JS'  => '<p class="weather__controls" data-weather-controls hidden>',
    'formulář skrytý bez JS'  => 'data-weather-form hidden>',
    'návrat na výchozí místo' => '>zpět na Třebíč</button>',
] as $name => $needle) {
    check(str_contains($html, $needle), "šablona: {$name}");
}
check(!preg_match('/49[.,]2|15[.,]8[0-9]|latitude|longitude/', $html), 'v HTML nejsou souřadnice');
$w['stale'] = true;
ob_start();
template('weather-data', ['weather' => $w]);
check(str_contains((string) ob_get_clean(), 'data z <time'), 'stará data po výpadku: „data z …“ místo „aktualizováno“');

// ---------- úvodní stránka ----------
section('Úvodní stránka (dočasná kopie aplikace, config/local.php)');

function render_home(string $tmp, string $name, array $weather, ?callable $prepare = null): array
{
    $root = $tmp . '/app-' . $name;
    foreach (['src', 'content'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $target = $root . '/' . $dir . '/' . substr($file->getPathname(), strlen(APP_ROOT . '/' . $dir) + 1);
            @mkdir(dirname($target), 0700, true);
            copy($file->getPathname(), $target);
        }
    }
    @mkdir($root . '/config', 0700, true);
    @mkdir($root . '/public', 0700, true);
    copy(APP_ROOT . '/config/app.php', $root . '/config/app.php');
    foreach (['_app.php', 'index.php'] as $file) {
        copy(APP_ROOT . '/public/' . $file, $root . '/public/' . $file);
    }
    file_put_contents($root . '/config/local.php', '<?php return ' . var_export(['weather' => $weather], true) . ';');
    if ($prepare) {
        $prepare($root);
    }

    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $root . '/public/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'html' => (string) $out, 'stderr' => (string) $err];
}

$offline = ['forecast_url' => 'http://127.0.0.1:9/v1/forecast', 'geocoding_url' => 'http://127.0.0.1:9/v1/search'];   // port 9: spojení odmítnuto
$page = render_home($tmp, 'vypnuto', array_replace($raw, ['enabled' => false]));
check($page['code'] === 0 && str_contains($page['html'], 'Osobní web o') && str_contains($page['html'], '</html>'), 'enabled=false: úvodní stránka funguje');
check(!str_contains($page['html'], 'id="pocasi"') && !str_contains($page['html'], 'weather.js') && !str_contains($page['html'], 'Open-Meteo'),
    'enabled=false: žádná sekce, žádný skript, žádný placeholder');

$page = render_home($tmp, 'neuplne', array_replace($raw, ['default' => ['label' => null, 'latitude' => null, 'longitude' => null]]));
check($page['code'] === 0 && !str_contains($page['html'], 'id="pocasi"'), 'neúplné nastavení: stránka bez sekce');

$withCache = static function (int $age) use ($raw, $parsed): callable {
    return static function (string $root) use ($age, $raw, $parsed): void {
        $cfg = weather_config(array_replace($raw, ['cache_dir' => $root . '/var/weather']));
        weather_cache_write(weather_cache_base($cfg, 49.22, 15.88) . '.json', $parsed, time() - $age, 'Europe/Prague');
    };
};
$page = render_home($tmp, 'cache', array_replace($raw, $offline, ['cache_dir' => $tmp . '/app-cache/var/weather']), $withCache(60));
check($page['code'] === 0 && str_contains($page['html'], '<section class="section weather" aria-labelledby="pocasi" data-weather>')
    && str_contains($page['html'], "16,1{$nbsp}°C") && str_contains($page['html'], 'aktualizováno <time'), 'enabled=true + cache s fixture: sekce se vykreslí');
check(str_contains($page['html'], '/assets/js/weather.js') && str_contains($page['html'], ' defer></script>'), 'enabled=true: načte se weather.js (defer)');
check(strpos($page['html'], 'id="poznamky"') < strpos($page['html'], 'id="pocasi"') && strpos($page['html'], 'id="pocasi"') < strpos($page['html'], 'site-footer'),
    'pořadí: … Poznámky → Počasí → patička');
check(!preg_match('/49[.,]2|15[.,]8[0-9]/', $page['html']), 'v HTML úvodní stránky nejsou souřadnice');

$page = render_home($tmp, 'stara', array_replace($raw, $offline, ['cache_dir' => $tmp . '/app-stara/var/weather']), $withCache(20 * 60));
check($page['code'] === 0 && str_contains($page['html'], 'data z <time') && !str_contains($page['html'], 'Open-Meteo nevrátilo'),
    'prošlá cache + nedostupné API: stará data, bez chybové hlášky');

$page = render_home($tmp, 'bez-dat', array_replace($raw, $offline, ['cache_dir' => $tmp . '/app-bez-dat/var/weather']));
check($page['code'] === 0 && str_contains($page['html'], '</html>') && !str_contains($page['html'], 'id="pocasi"'), 'žádná cache + nedostupné API: stránka bez sekce');
check(!str_contains($page['html'], 'Warning') && !str_contains($page['html'], 'Fatal') && !str_contains($page['html'], '127.0.0.1'),
    'návštěvník nevidí varování PHP ani detail chyby');
check(str_contains($page['stderr'], 'mh42: 127.0.0.1 nevrátil použitelnou odpověď') && !preg_match('/49[.,]2|15[.,]8|latitude/', $page['stderr']),
    'log serveru: jen hostitel a důvod, bez souřadnic');

section('Log serveru');
$log = (string) @file_get_contents($tmp . '/php-error.log');
check($log !== '' && !preg_match('/49[.,]2|15[.,]8|16[.,]61|Brno|latitude|name=/', $log), 'log neobsahuje souřadnice ani hledaný text');

echo "\nVýsledek: {$results['ok']} ok, {$results['chyba']} chyb\n";
exit($results['chyba'] ? 1 : 0);
