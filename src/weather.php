<?php

declare(strict_types=1);

// Počasí z Open-Meteo (https://open-meteo.com/, data pod licencí CC BY 4.0).
//
// Data načítá jen server – prohlížeč návštěvníka komunikuje výhradně s mh42.cz.
// Odpověď API se převede na vlastní jednoduchý model a uloží do
// var/weather/<sha256 souřadnic>.json na cache_ttl (15 minut). Když API selže, použije se
// poslední dobrá data (nejvýš WEATHER_STALE_MAX stará), jinak se sekce nevykreslí.
// Cache má pevné meze: jedno místo = jeden soubor, soubory starší než 7 dní se mažou
// a souborů s daty je nejvýš 500 (viz weather_cache_cleanup).
// Šablony znají jen model z weather_get(), ne strukturu odpovědi Open-Meteo.
//
// Souřadnice se všude zaokrouhlují na 2 desetinná místa (~1 km): stačí to pro počasí
// a server nikdy nepracuje s přesnější polohou. Nikam se neukládají ani nelogují –
// název souboru cache je jen jejich otisk.

require_once APP_ROOT . '/src/http.php';

const WEATHER_STALE_MAX = 6 * 3600;     // starší data se nezobrazí ani po výpadku API
const WEATHER_RETRY_AFTER = 120;        // po chybě API se další pokus pro stejné místo odloží
const WEATHER_CACHE_MAX_AGE = 7 * 86400;   // soubory cache starší než 7 dní se smažou
const WEATHER_CACHE_MAX_FILES = 500;       // nejvýš 500 souborů s daty; navíc se smažou nejstarší
const WEATHER_BUDGET_MINUTE = 30;       // dotazy na Open-Meteo vyvolané návštěvníky (vlastní místa
const WEATHER_BUDGET_DAY = 1500;        // a hledání); výchozí místo se nepočítá
const WEATHER_RESPONSE_LIMIT = 65536;   // bajtů
const WEATHER_DAYS = 3;
const WEATHER_CACHE_VERSION = 1;

const WEATHER_FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
const WEATHER_GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';

// Kódy počasí WMO podle dokumentace Open-Meteo. U deště se rozlišuje intenzita,
// jinde stačí jeden popis na skupinu.
const WEATHER_LABELS = [
    0 => 'jasno', 1 => 'převážně jasno', 2 => 'polojasno', 3 => 'zataženo',
    45 => 'mlha', 48 => 'mlha',
    51 => 'mrholení', 53 => 'mrholení', 55 => 'mrholení',
    56 => 'mrznoucí mrholení', 57 => 'mrznoucí mrholení',
    61 => 'slabý déšť', 63 => 'déšť', 65 => 'silný déšť',
    66 => 'mrznoucí déšť', 67 => 'mrznoucí déšť',
    71 => 'sněžení', 73 => 'sněžení', 75 => 'sněžení', 77 => 'sněžení',
    80 => 'dešťové přeháňky', 81 => 'dešťové přeháňky', 82 => 'dešťové přeháňky',
    85 => 'sněhové přeháňky', 86 => 'sněhové přeháňky',
    95 => 'bouřka', 96 => 'bouřka s krupobitím', 99 => 'bouřka s krupobitím',
];

const WEATHER_WEEKDAYS = [1 => 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];

// ---------- Nastavení a souřadnice ----------

/**
 * Nastavení počasí z config/app.php, nebo null, když je vypnuté nebo neúplné
 * (sekce se pak nevykreslí). Klíče forecast_url, geocoding_url a cache_dir jsou
 * volitelné – mění se jen v testech.
 */
function weather_config(mixed $raw = null): ?array
{
    $raw = func_num_args() > 0 ? $raw : config('weather');
    if (!is_array($raw) || ($raw['enabled'] ?? false) !== true || !is_array($raw['default'] ?? null)) {
        return null;
    }

    $default = $raw['default'];
    $label = is_string($default['label'] ?? null) ? trim($default['label']) : '';
    $coords = weather_coords($default['latitude'] ?? null, $default['longitude'] ?? null);
    $timezone = $raw['timezone'] ?? 'Europe/Prague';
    $ttl = $raw['cache_ttl'] ?? 900;

    if ($label === '' || $coords === null || !is_int($ttl) || $ttl < 60
        || !is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
        return null;
    }

    return [
        'label'         => $label,
        'latitude'      => $coords[0],
        'longitude'     => $coords[1],
        'timezone'      => $timezone,
        'cache_ttl'     => $ttl,
        'forecast_url'  => (string) ($raw['forecast_url'] ?? WEATHER_FORECAST_URL),
        'geocoding_url' => (string) ($raw['geocoding_url'] ?? WEATHER_GEOCODING_URL),
        'cache_dir'     => (string) ($raw['cache_dir'] ?? APP_ROOT . '/var/weather'),
    ];
}

/**
 * Souřadnice v platném rozsahu zaokrouhlené na 2 desetinná místa, jinak null.
 * Přijímá jen čísla (ne řetězce).
 *
 * @return array{0: float, 1: float}|null
 */
function weather_coords(mixed $lat, mixed $lon): ?array
{
    if (!(is_int($lat) || is_float($lat)) || !(is_int($lon) || is_float($lon))) {
        return null;
    }
    $lat = (float) $lat;
    $lon = (float) $lon;
    if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) {
        return null;
    }

    // + 0.0 převede zápornou nulu na kladnou (stejný klíč cache pro -0.001 i 0.001)
    return [round($lat, 2) + 0.0, round($lon, 2) + 0.0];
}

/** Souřadnice jako text s pevnou přesností – pro adresu API a klíč cache. */
function weather_coord_text(float $value): string
{
    return sprintf('%.2F', $value);
}

function weather_is_default(array $cfg, float $lat, float $lon): bool
{
    return $lat === $cfg['latitude'] && $lon === $cfg['longitude'];
}

// ---------- Open-Meteo ----------

/**
 * Adresa dotazu na předpověď – jen údaje, které web zobrazuje.
 * Výchozí místo používá časové pásmo z konfigurace, ostatní místa 'auto'
 * (dny předpovědi podle místního času daného místa).
 */
function weather_forecast_url(array $cfg, float $lat, float $lon): string
{
    return $cfg['forecast_url'] . '?' . http_build_query([
        'latitude'         => weather_coord_text($lat),
        'longitude'        => weather_coord_text($lon),
        'current'          => 'temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m',
        'daily'            => 'weather_code,temperature_2m_min,temperature_2m_max',
        'timezone'         => weather_is_default($cfg, $lat, $lon) ? $cfg['timezone'] : 'auto',
        'temperature_unit' => 'celsius',
        'wind_speed_unit'  => 'kmh',
        'forecast_days'    => WEATHER_DAYS,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Odpověď Open-Meteo → vlastní model, nebo null při jakékoli nesrovnalosti
 * (nevalidní JSON, chybějící nebo nečíselné hodnoty, jiné jednotky).
 */
function weather_parse_forecast(string $body): ?array
{
    try {
        $response = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($response) || !is_array($response['current'] ?? null) || !is_array($response['daily'] ?? null)) {
        return null;
    }

    // Jednotky: pokud je API uvádí, musí odpovídat tomu, co web zobrazuje.
    $units = [
        'current_units' => ['temperature_2m' => '°C', 'relative_humidity_2m' => '%', 'wind_speed_10m' => 'km/h'],
        'daily_units'   => ['temperature_2m_min' => '°C', 'temperature_2m_max' => '°C'],
    ];
    foreach ($units as $group => $expected) {
        foreach ($expected as $key => $unit) {
            if (($response[$group][$key] ?? $unit) !== $unit) {
                return null;
            }
        }
    }

    $current = weather_valid_current([
        'temperature'  => $response['current']['temperature_2m'] ?? null,
        'humidity'     => $response['current']['relative_humidity_2m'] ?? null,
        'weather_code' => $response['current']['weather_code'] ?? null,
        'wind_speed'   => $response['current']['wind_speed_10m'] ?? null,
    ]);

    $daily = $response['daily'];
    $days = [];
    foreach (['time', 'weather_code', 'temperature_2m_min', 'temperature_2m_max'] as $key) {
        if (!is_array($daily[$key] ?? null) || !array_is_list($daily[$key])) {
            return null;
        }
    }
    foreach ($daily['time'] as $i => $date) {
        $days[] = [
            'date'         => $date,
            'weather_code' => $daily['weather_code'][$i] ?? null,
            'min'          => $daily['temperature_2m_min'][$i] ?? null,
            'max'          => $daily['temperature_2m_max'][$i] ?? null,
        ];
    }
    $sameLength = count($daily['time']) === count($daily['weather_code'])
        && count($daily['time']) === count($daily['temperature_2m_min'])
        && count($daily['time']) === count($daily['temperature_2m_max']);

    $data = [
        'timezone' => $response['timezone'] ?? null,
        'current'  => $current,
        'days'     => $days,
    ];

    return $sameLength ? weather_valid_data($data) : null;
}

/** Kontrola modelu – stejná pro odpověď API i pro obsah cache. */
function weather_valid_data(mixed $data): ?array
{
    if (!is_array($data) || !is_string($data['timezone'] ?? null)
        || !in_array($data['timezone'], timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
        return null;
    }

    $current = weather_valid_current($data['current'] ?? null);
    if ($current === null || !is_array($data['days'] ?? null) || $data['days'] === [] || count($data['days']) > 16) {
        return null;
    }

    $days = [];
    foreach ($data['days'] as $day) {
        $date = is_array($day) ? ($day['date'] ?? null) : null;
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || !weather_is_code($day['weather_code'] ?? null)
            || !weather_in_range($day['min'] ?? null, -90, 60) || !weather_in_range($day['max'] ?? null, -90, 60)
            || $day['min'] > $day['max']) {
            return null;
        }
        $days[] = ['date' => $date, 'weather_code' => $day['weather_code'], 'min' => (float) $day['min'], 'max' => (float) $day['max']];
    }

    return ['timezone' => $data['timezone'], 'current' => $current, 'days' => $days];
}

function weather_valid_current(mixed $current): ?array
{
    if (!is_array($current)
        || !weather_in_range($current['temperature'] ?? null, -90, 60)
        || !weather_in_range($current['humidity'] ?? null, 0, 100)
        || !weather_is_code($current['weather_code'] ?? null)
        || !weather_in_range($current['wind_speed'] ?? null, 0, 500)) {
        return null;
    }

    return [
        'temperature'  => (float) $current['temperature'],
        'humidity'     => (int) round($current['humidity']),
        'weather_code' => $current['weather_code'],
        'wind_speed'   => (float) $current['wind_speed'],
    ];
}

function weather_in_range(mixed $value, float $min, float $max): bool
{
    return (is_int($value) || is_float($value)) && $value >= $min && $value <= $max;
}

function weather_is_code(mixed $code): bool
{
    return is_int($code) && $code >= 0 && $code <= 99;
}

/** Český popis kódu počasí WMO. Neznámý kód není chyba – popis je pak obecný. */
function weather_label(int $code): string
{
    return WEATHER_LABELS[$code] ?? 'počasí';
}

// ---------- Cache (var/weather/) ----------

/** Cesta k souborům místa bez přípony: <cache_dir>/<sha256("49.22,15.88")>. */
function weather_cache_base(array $cfg, float $lat, float $lon): string
{
    return $cfg['cache_dir'] . '/' . hash('sha256', weather_coord_text($lat) . ',' . weather_coord_text($lon));
}

/** @return array{fetched_at: int, data: array}|null */
function weather_cache_read(string $file): ?array
{
    $raw = is_file($file) ? @file_get_contents($file) : false;
    if ($raw === false) {
        return null;
    }
    try {
        $entry = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($entry) || ($entry['version'] ?? null) !== WEATHER_CACHE_VERSION || !is_string($entry['fetched_at'] ?? null)) {
        return null;
    }
    $fetched = DateTimeImmutable::createFromFormat(DATE_ATOM, $entry['fetched_at']);
    $data = weather_valid_data($entry['data'] ?? null);

    return $fetched !== false && $data !== null ? ['fetched_at' => $fetched->getTimestamp(), 'data' => $data] : null;
}

/**
 * Atomický zápis: data se zapíšou do dočasného souboru ve stejné složce a ten se
 * přejmenuje na cílový. Čtenář tak vždy vidí buď starý, nebo celý nový soubor.
 */
function weather_cache_write(string $file, array $data, int $now, string $timezone): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('mh42: složku pro cache počasí nejde vytvořit – zkontroluj oprávnění var/');
        return false;
    }

    $json = json_encode([
        'version'    => WEATHER_CACHE_VERSION,
        'fetched_at' => (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($timezone))->format(DATE_ATOM),
        'data'       => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT) . "\n";

    $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $file)) {
        @unlink($tmp);
        error_log('mh42: cache počasí nejde zapsat – zkontroluj oprávnění var/');
        return false;
    }

    return true;
}

/**
 * Úklid cache, aby nerostla bez omezení. Spouští se při zápisu nové cache (žádný cron):
 * smaže soubory s daty starší než 7 dní, značky chyb po uplynutí odkladu a zapomenuté
 * dočasné soubory. Když je souborů s daty víc než 500, smažou se nejstarší. Soubory
 * v $keep (právě zapsaný a výchozí místo) se nemažou nikdy. Jde jen o jeden výpis
 * složky a stat souborů – běžný požadavek to nezdrží.
 *
 * @param list<string> $keep názvy souborů, které úklid nesmí smazat
 */
function weather_cache_cleanup(string $dir, int $now, array $keep = []): void
{
    $keep = array_values(array_unique($keep));
    $data = [];
    foreach (@scandir($dir) ?: [] as $name) {
        if (!preg_match('/^(?:[0-9a-f]{64}\.(json|fail)|\.tmp-[0-9a-f]{12})$/', $name, $m) || in_array($name, $keep, true)) {
            continue;
        }
        $mtime = @filemtime($dir . '/' . $name);
        if ($mtime === false) {
            continue;
        }
        $maxAge = match ($m[1] ?? '') {
            'json'  => WEATHER_CACHE_MAX_AGE,
            'fail'  => WEATHER_RETRY_AFTER,
            default => 3600,   // dočasný soubor po nedokončeném zápisu
        };
        if ($mtime < $now - $maxAge) {
            @unlink($dir . '/' . $name);
        } elseif (($m[1] ?? '') === 'json') {
            $data[$name] = $mtime;
        }
    }

    // Strop počtu: chráněné soubory se počítají, ale nemažou; mažou se nejstarší ostatní.
    $excess = count($data) + count(array_filter($keep, static fn (string $name): bool => str_ends_with($name, '.json') && is_file($dir . '/' . $name)))
        - WEATHER_CACHE_MAX_FILES;
    if ($excess > 0) {
        asort($data);
        foreach (array_slice(array_keys($data), 0, $excess) as $name) {
            @unlink($dir . '/' . $name);
        }
    }
}

/**
 * Společný strop dotazů na Open-Meteo, které vyvolají návštěvníci (vlastní místa
 * a hledání): 30 za minutu a 1 500 za den. Chrání naše endpointy před zneužitím jako
 * neomezené proxy – nejde o kopii limitů Open-Meteo. Výchozí místo se nepočítá, jeho
 * dotazy omezuje už 15minutová cache. Když stav nejde zapsat, dotaz se nepovolí.
 *
 * Stav je jediný soubor var/weather/budget.json, který se při každém dotazu přepíše:
 * {"minute": <číslo aktuální minuty od epochy>, "m": <dotazů v ní>, "day": "<datum UTC>", "d": <dotazů za den>}.
 * Jsou v něm jen dva čítače – žádné IP adresy, místa ani dotazy, žádná historie.
 */
function weather_budget_take(array $cfg, int $now): bool
{
    $dir = $cfg['cache_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $handle = @fopen($dir . '/budget.json', 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }
        $state = json_decode((string) stream_get_contents($handle), true);
        $state = is_array($state) ? $state : [];
        $minute = intdiv($now, 60);
        $day = gmdate('Y-m-d', $now);
        $perMinute = ($state['minute'] ?? null) === $minute ? (int) ($state['m'] ?? 0) : 0;
        $perDay = ($state['day'] ?? null) === $day ? (int) ($state['d'] ?? 0) : 0;
        if ($perMinute >= WEATHER_BUDGET_MINUTE || $perDay >= WEATHER_BUDGET_DAY) {
            return false;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode(['minute' => $minute, 'm' => $perMinute + 1, 'day' => $day, 'd' => $perDay + 1]));
        fflush($handle);

        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// ---------- Načtení počasí ----------

/**
 * Počasí pro souřadnice (už zaokrouhlené přes weather_coords): z platné cache, jinak
 * čerstvě z Open-Meteo. Vrací model pro šablony, nebo null, když nejsou použitelná data.
 *
 * $options (kvůli testům):
 *   fetch  callable(string $url): ?string – náhrada HTTP klienta
 *   now    int – aktuální čas
 */
function weather_get(array $cfg, float $lat, float $lon, array $options = []): ?array
{
    $now = $options['now'] ?? time();
    $fetch = $options['fetch'] ?? static fn (string $url): ?string => remote_get($url, WEATHER_RESPONSE_LIMIT);
    $base = weather_cache_base($cfg, $lat, $lon);
    $cached = weather_cache_read($base . '.json');

    // 1. platná cache – žádný dotaz na API
    if ($cached !== null && $now - $cached['fetched_at'] < $cfg['cache_ttl']) {
        return weather_model($cfg, $cached, false, $now);
    }

    // 2. prošlá nebo chybějící cache – zkusit API (ne hned po nedávné chybě a ne nad strop)
    $failedAt = @filemtime($base . '.fail');
    $data = null;
    if (($failedAt === false || $now - $failedAt >= WEATHER_RETRY_AFTER)
        && (weather_is_default($cfg, $lat, $lon) || weather_budget_take($cfg, $now))) {
        $body = $fetch(weather_forecast_url($cfg, $lat, $lon));
        $data = is_string($body) ? weather_parse_forecast($body) : null;
        if ($data === null) {
            // 5. nevalidní odpověď je chyba API – poslední dobrá cache zůstává beze změny
            @touch($base . '.fail', $now);
            if (is_string($body)) {
                error_log('mh42: Open-Meteo vrátilo odpověď v nečekaném tvaru');
            }
        }
    }

    if ($data !== null) {
        // Jedno místo = jeden soubor: obnova ho přepíše, nový vzniká jen pro nové místo.
        if (weather_cache_write($base . '.json', $data, $now, $cfg['timezone'])) {
            @unlink($base . '.fail');
            weather_cache_cleanup($cfg['cache_dir'], $now, [
                basename($base) . '.json',
                basename(weather_cache_base($cfg, $cfg['latitude'], $cfg['longitude'])) . '.json',
            ]);
        }
        return weather_model($cfg, ['fetched_at' => $now, 'data' => $data], false, $now);
    }

    // 3. API selhalo – poslední dobrá data, pokud nejsou příliš stará; 4. jinak nic
    if ($cached !== null && $now - $cached['fetched_at'] < WEATHER_STALE_MAX) {
        return weather_model($cfg, $cached, true, $now);
    }

    return null;
}

/**
 * Model pro šablony: aktuální stav a dny od dneška (ve starších datech může být
 * po půlnoci ještě včerejšek – ten se vynechá), s českými popisky.
 */
function weather_model(array $cfg, array $entry, bool $stale, int $now): array
{
    $data = $entry['data'];
    $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($data['timezone']))->format('Y-m-d');

    $days = [];
    foreach ($data['days'] as $day) {
        if ($day['date'] >= $today && count($days) < WEATHER_DAYS) {
            $days[] = $day + ['name' => weather_day_name($day['date'], $today), 'weather_label' => weather_label($day['weather_code'])];
        }
    }

    $updated = (new DateTimeImmutable('@' . $entry['fetched_at']))->setTimezone(new DateTimeZone($cfg['timezone']));

    return [
        'updated_at'   => $updated,
        'updated_time' => weather_time($updated, $now),
        'stale'        => $stale,
        'current'      => $data['current'] + ['weather_label' => weather_label($data['current']['weather_code'])],
        'days'         => $days,
    ];
}

/**
 * Počasí pro výchozí místo na úvodní stránce, nebo null – pak se sekce nevykreslí.
 * Žádná chyba počasí nesmí shodit stránku.
 */
function weather_home(array $options = []): ?array
{
    try {
        $cfg = weather_config();
        if ($cfg === null) {
            return null;
        }
        $weather = weather_get($cfg, $cfg['latitude'], $cfg['longitude'], $options);

        return $weather === null ? null : $weather + ['location' => $cfg['label']];
    } catch (Throwable $e) {
        error_log('mh42: počasí se nepodařilo připravit: ' . $e->getMessage());
        return null;
    }
}

// ---------- Hledání místa (Open-Meteo Geocoding) ----------

/**
 * Dotaz na místo: 2–60 znaků – písmena, číslice, mezery a běžná interpunkce názvů.
 * Jinak null. Víc mezer za sebou se sloučí.
 */
function place_query(mixed $query): ?string
{
    if (!is_string($query) || strlen($query) > 240) {
        return null;
    }
    $query = trim((string) preg_replace('/\s+/u', ' ', $query));

    return preg_match("/^[\\p{L}\\p{M}\\p{N} .,'’()\\-]{2,60}$/u", $query) ? $query : null;
}

function place_search_url(array $cfg, string $query): string
{
    return $cfg['geocoding_url'] . '?' . http_build_query([
        'name'     => $query,
        'count'    => 5,
        'language' => 'cs',
        'format'   => 'json',
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Odpověď Geocoding API → nejvýš 5 míst {label, detail, latitude, longitude}.
 * Bez klíče results (nic nenalezeno) je výsledek prázdný seznam, nevalidní odpověď null.
 */
function place_parse(string $body): ?array
{
    try {
        $response = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($response) || (isset($response['results']) && !is_array($response['results']))) {
        return null;
    }

    $places = [];
    foreach ($response['results'] ?? [] as $result) {
        $name = is_array($result) && is_string($result['name'] ?? null) ? trim($result['name']) : '';
        $coords = is_array($result) ? weather_coords($result['latitude'] ?? null, $result['longitude'] ?? null) : null;
        if (!preg_match('/^.{1,100}$/u', $name) || $coords === null) {
            continue;
        }
        $detail = [];
        foreach (['admin1', 'country'] as $key) {
            $part = is_string($result[$key] ?? null) ? trim($result[$key]) : '';
            if ($part !== '' && $part !== $name && !in_array($part, $detail, true)) {
                $detail[] = $part;
            }
        }
        $place = ['label' => $name, 'detail' => implode(' · ', $detail), 'latitude' => $coords[0], 'longitude' => $coords[1]];
        if (!in_array($place, $places, true)) {
            $places[] = $place;
        }
        if (count($places) === 5) {
            break;
        }
    }

    return $places;
}

/** Místa pro dotaz, nebo null, když hledání teď nejde (chyba API, strop dotazů). */
function place_search(array $cfg, string $query, array $options = []): ?array
{
    $now = $options['now'] ?? time();
    $fetch = $options['fetch'] ?? static fn (string $url): ?string => remote_get($url, WEATHER_RESPONSE_LIMIT);
    if (!weather_budget_take($cfg, $now)) {
        return null;
    }
    $body = $fetch(place_search_url($cfg, $query));

    return is_string($body) ? place_parse($body) : null;
}

// ---------- Formátování pro šablony ----------

/** Číslo s desetinnou čárkou a skutečným znaménkem minus; bez „-0“. */
function weather_number(float|int $value, int $decimals): string
{
    $text = number_format((float) $value, $decimals, ',', '');
    if (preg_match('/^-0(?:,0+)?$/', $text)) {
        $text = substr($text, 1);
    }

    return str_replace('-', "\u{2212}", $text);
}

function weather_temperature(float $value, int $decimals): string
{
    return weather_number($value, $decimals) . "\u{00A0}°C";
}

/** dnes, zítra, jinak český název dne v týdnu. */
function weather_day_name(string $date, string $today): string
{
    $utc = new DateTimeZone('UTC');
    $diff = (new DateTimeImmutable($today, $utc))->diff(new DateTimeImmutable($date, $utc));
    $days = $diff->invert ? -$diff->days : $diff->days;

    return match ($days) {
        0       => 'dnes',
        1       => 'zítra',
        default => WEATHER_WEEKDAYS[(int) (new DateTimeImmutable($date, $utc))->format('N')],
    };
}

/** Čas aktualizace: „20:15“, u dat z jiného dne „17. 9. 23:40“. */
function weather_time(DateTimeImmutable $at, int $now): string
{
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($at->getTimezone())->format('Y-m-d');

    return $at->format('Y-m-d') === $today ? $at->format('H:i') : $at->format("j.\u{00A0}n.\u{00A0}H:i");
}
