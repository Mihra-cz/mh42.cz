<?php

declare(strict_types=1);

// Testy přijímače heartbeatu bez sítě: nastavení, sdílené tajemství, podpis, tělo
// podle kontraktu, odchylka hodin, atomické uložení a pořadí zpráv, souběžné zápisy,
// všech pět stavů, NO DATA, strop požadavků a stránka Projekty v dočasné kopii
// aplikace. Testovací tajemství i stav jsou vždy jen v dočasné složce.
//
// Souběh se testuje skutečnými procesy: tenhle skript se sám spouští v režimu
// --uloz, všechny děti čekají na společný start a pak zapisují do jednoho souboru.
//
//   php tools/test-heartbeat.php
//   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli-alpine php tools/test-heartbeat.php

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/heartbeat.php';

// ---------- režim dítěte pro test souběhu ----------
// --uloz <složka> <ts> <stav> <now> <start v mikrosekundách>
if (($argv[1] ?? '') === '--uloz') {
    [$dir, $ts, $state, $now, $startAt] = array_slice($argv, 2);
    $wait = (int) $startAt - (int) (microtime(true) * 1e6);
    if ($wait > 0) {
        usleep($wait);
    }
    echo heartbeat_store(['state_dir' => $dir], $state, (int) $ts, (int) $now);
    exit(0);
}

// --zapisuj <složka> <počet> <první ts>: nepřetržitá řada zápisů pro test čtenáře
if (($argv[1] ?? '') === '--zapisuj') {
    [$dir, $count, $startTs] = array_slice($argv, 2);
    for ($i = 0; $i < (int) $count; $i++) {
        heartbeat_store(['state_dir' => $dir], HEARTBEAT_STATES[$i % count(HEARTBEAT_STATES)], (int) $startTs + $i, 9000 + $i);
    }
    exit(0);
}

// --drz-zamek <složka> <sekund> <soubor značky>: podrží výhradní zámek
if (($argv[1] ?? '') === '--drz-zamek') {
    [$dir, $seconds, $marker] = array_slice($argv, 2);
    $lock = fopen($dir . '/' . HEARTBEAT_LOCK_FILE, 'c');
    flock($lock, LOCK_EX);
    touch($marker);
    usleep((int) ((float) $seconds * 1e6));
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

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

/** Názvy souborů ve složce bez . a .., seřazené. */
function sorted_names(string $dir): array
{
    $names = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    sort($names);

    return $names;
}

function remove_tree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                remove_tree($path . '/' . $name);
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

$tmp = sys_get_temp_dir() . '/mh42-test-heartbeat-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(static fn () => remove_tree($tmp));
ini_set('error_log', $tmp . '/php-error.log');

// Testovací tajemství – nikdy produkční; 48 znaků ASCII bez mezer.
const SECRET = 'test-tajemstvi-heartbeatu-mh42-0123456789abcdef';
$counter = 0;

/** Nové nastavení s vlastní dočasnou složkou stavu a souborem tajemství. */
$newCfg = static function (array $changes = []) use ($tmp, &$counter): ?array {
    $n = ++$counter;
    $dir = $tmp . '/stav-' . $n;
    $secret = $tmp . '/tajemstvi-' . $n;
    file_put_contents($secret, SECRET);

    return heartbeat_config(array_replace([
        'enabled'       => true,
        'secret_file'   => $secret,
        'state_dir'     => $dir,
        'project'       => 'nexusstat',
        'require_https' => true,
    ], $changes));
};

/** Tělo přesně jako ho skládá odesílač (json.dumps se separators (",", ":")). */
function body(string $state, int $ts): string
{
    return '{"v":1,"state":"' . $state . '","ts":' . $ts . '}';
}

function entry_of(array $cfg): ?array
{
    return heartbeat_read($cfg);
}

// ---------- Nastavení ----------

section('Nastavení');
check(heartbeat_config(['enabled' => false]) === null, 'enabled=false: přijímač je vypnutý (null)');
check(heartbeat_config([]) === null, 'bez enabled: vypnutý');
check(heartbeat_config('ano') === null, 'nastavení není pole: vypnutý');
check(heartbeat_config(['enabled' => 1]) === null, 'enabled=1 (ne true): vypnutý');

$cfg = heartbeat_config(['enabled' => true]);
check($cfg !== null && $cfg['secret_file'] === APP_ROOT . '/config/heartbeat.key'
    && $cfg['state_dir'] === APP_ROOT . '/var/heartbeat' && $cfg['require_https'] === true,
    'výchozí hodnoty: config/heartbeat.key, var/heartbeat, require_https');
check($cfg !== null && $cfg['project'] === null, 'bez project: stav se u žádného projektu nezobrazí');

$cfg = heartbeat_config(['enabled' => true, 'secret_file' => '/etc/mh42.key', 'state_dir' => '/srv/stav']);
check($cfg !== null && $cfg['secret_file'] === '/etc/mh42.key' && $cfg['state_dir'] === '/srv/stav',
    'absolutní cesty se nechávají být');

foreach ([
    'prázdný secret_file'   => ['secret_file' => '  '],
    'prázdný state_dir'     => ['state_dir' => ''],
    'secret_file jako číslo' => ['secret_file' => 7],
    'prázdný project'       => ['project' => ' '],
    'require_https jako text' => ['require_https' => 'ano'],
] as $name => $change) {
    check(heartbeat_config(array_replace(['enabled' => true], $change)) === null, "neplatné nastavení odmítnuto: {$name}");
}

// ---------- Sdílené tajemství ----------

section('Sdílené tajemství (bajtově shodné s odesílačem)');
$secretFile = $tmp . '/klic';
$withSecret = static function (string $content) use ($secretFile): ?string {
    file_put_contents($secretFile, $content);

    return heartbeat_secret(['secret_file' => $secretFile]);
};

check($withSecret(SECRET) === SECRET, 'tajemství bez bílých znaků');
check($withSecret(SECRET . "\n") === SECRET, 'soubor končící novým řádkem: stejné bajty');
check($withSecret("\r\n\t " . SECRET . " \t\r\n") === SECRET, 'bílé znaky okolo se odstraní (jako bytes.strip())');
check($withSecret(SECRET . "\x0B\x0C") === SECRET, 'odstraní se i \\x0B a \\x0C – stejná sada jako v Pythonu');
check($withSecret(str_repeat('a', 32)) === str_repeat('a', 32), 'hranice: 32 znaků projde');
check($withSecret(str_repeat('a', 31)) === null, '31 znaků odmítnuto');
check($withSecret('') === null, 'prázdný soubor odmítnut');
check($withSecret(str_repeat('a', 20) . ' ' . str_repeat('b', 20)) === null, 'mezera uvnitř odmítnuta');
check($withSecret(str_repeat('a', 31) . 'é') === null, 'znak mimo ASCII odmítnut');
check($withSecret(str_repeat('a', 31) . "\x7F") === null, 'řídicí znak 0x7F odmítnut');
check($withSecret(str_repeat('a', 31) . "\0") === null, 'nulový bajt odmítnut');
check($withSecret(str_repeat('a', HEARTBEAT_SECRET_FILE_MAX + 1)) === null, 'soubor nad 4096 B odmítnut');
check(heartbeat_secret(['secret_file' => $tmp . '/neexistuje']) === null, 'chybějící soubor odmítnut');

$link = $tmp . '/klic-odkaz';
file_put_contents($secretFile, SECRET);
@symlink($secretFile, $link);
check(!is_link($link) || heartbeat_secret(['secret_file' => $link]) === null, 'symbolický odkaz odmítnut');

// ---------- Podpis ----------

section('Podpis HMAC-SHA256 nad přesnými bajty těla');
$raw = body('OK', 1793000000);
$sig = heartbeat_sign(SECRET, $raw);
check(preg_match('/^v1=[0-9a-f]{64}$/', $sig) === 1, 'tvar hlavičky: v1=<64 hex malými písmeny>');
check($sig === 'v1=' . hash_hmac('sha256', $raw, SECRET), 'podpis je HMAC-SHA256 nad tělem');
check(heartbeat_signature_valid(SECRET, $raw, $sig), 'správný podpis projde');
check(!heartbeat_signature_valid(SECRET, $raw, null), 'chybějící hlavička neprojde');
check(!heartbeat_signature_valid(SECRET, $raw, ''), 'prázdná hlavička neprojde');
check(!heartbeat_signature_valid(SECRET, $raw, strtoupper($sig)), 'velká písmena v hexu neprojdou (bajtově přesné)');
check(!heartbeat_signature_valid(SECRET, $raw, substr($sig, 3)), 'podpis bez předpony v1= neprojde');
check(!heartbeat_signature_valid(SECRET, $raw, $sig . '0'), 'podpis o znak delší neprojde');
check(!heartbeat_signature_valid(SECRET, $raw, substr($sig, 0, -1)), 'zkrácený podpis neprojde');
check(!heartbeat_signature_valid(SECRET, str_replace('OK', 'ERROR', $raw), $sig), 'změněné tělo neprojde');
check(!heartbeat_signature_valid(SECRET, $raw . ' ', $sig), 'mezera navíc v těle neprojde');
check(!heartbeat_signature_valid(SECRET . 'x', $raw, $sig), 'jiné tajemství neprojde');
check(heartbeat_sign(SECRET, body('OK', 1793000000)) !== heartbeat_sign(SECRET, body('OK', 1793000001)),
    'jiné ts = jiný podpis');

// ---------- Tělo podle kontraktu ----------

section('Tělo podle kontraktu (jen v, state a ts)');
foreach (HEARTBEAT_STATES as $state) {
    $parsed = heartbeat_parse_body(body($state, 1793000000));
    check($parsed === ['state' => $state, 'ts' => 1793000000], "stav {$state} přijat");
}
check(heartbeat_parse_body('{"ts":1793000000,"state":"OK","v":1}') === ['state' => 'OK', 'ts' => 1793000000],
    'jiné pořadí klíčů je v pořádku');

foreach ([
    'klíč navíc'              => '{"v":1,"state":"OK","ts":1793000000,"host":"nexus"}',
    'chybí ts'                => '{"v":1,"state":"OK"}',
    'chybí state'            => '{"v":1,"ts":1793000000}',
    'chybí v'                => '{"state":"OK","ts":1793000000}',
    'v=2'                    => '{"v":2,"state":"OK","ts":1793000000}',
    'v jako text'            => '{"v":"1","state":"OK","ts":1793000000}',
    'v=true'                 => '{"v":true,"state":"OK","ts":1793000000}',
    'stav malými písmeny'    => '{"v":1,"state":"ok","ts":1793000000}',
    'stav NO DATA'           => '{"v":1,"state":"NO DATA","ts":1793000000}',
    'stav INFO'              => '{"v":1,"state":"INFO","ts":1793000000}',
    'stav null'              => '{"v":1,"state":null,"ts":1793000000}',
    'ts jako text'           => '{"v":1,"state":"OK","ts":"1793000000"}',
    'ts desetinné'           => '{"v":1,"state":"OK","ts":1793000000.5}',
    'ts desetinné celé'      => '{"v":1,"state":"OK","ts":1793000000.0}',
    'ts nula'                => '{"v":1,"state":"OK","ts":0}',
    'ts záporné'             => '{"v":1,"state":"OK","ts":-1}',
    'ts true'                => '{"v":1,"state":"OK","ts":true}',
    'nevalidní JSON'         => '{"v":1,"state":"OK"',
    'pole místo objektu'     => '[1,"OK",1793000000]',
    'vnořený objekt'         => '{"v":1,"state":{"x":"OK"},"ts":1793000000}',
    'jen text'               => '"OK"',
    'prázdné tělo'           => '',
    'jen mezera'             => ' ',
] as $name => $invalid) {
    check(heartbeat_parse_body($invalid) === null, "odmítnuto: {$name}");
}

$long = '{"v":1,"state":"OK","ts":1793000000,"x":"' . str_repeat('a', 300) . '"}';
check(strlen($long) > HEARTBEAT_BODY_LIMIT && heartbeat_parse_body($long) === null, 'tělo nad 256 B odmítnuto');
check(strlen(body('CRITICAL', 9999999999)) <= HEARTBEAT_BODY_LIMIT, 'platné tělo se vejde do 256 B');

// ---------- Odchylka hodin ----------

section('Odchylka hodin (nejvýš 300 s)');
$now = 1793000000;
foreach ([0 => true, 300 => true, -300 => true, 301 => false, -301 => false, 100000 => false] as $offset => $ok) {
    check(heartbeat_clock_ok($now + $offset, $now) === $ok, sprintf('ts %+d s: %s', $offset, $ok ? 'přijato' : 'odmítnuto'));
}

// ---------- Uložení a pořadí ----------

section('Atomické uložení a pořadí zpráv');
$cfg = $newCfg();
check(heartbeat_store($cfg, 'OK', 1000, 5000) === 'stored', 'první hlášení se uloží');
check(entry_of($cfg) === ['state' => 'OK', 'ts' => 1000, 'received_at' => 5000], 'uložený stav odpovídá hlášení');

$stored = json_decode((string) file_get_contents($cfg['state_dir'] . '/state.json'), true);
check(array_keys($stored) === ['version', 'state', 'ts', 'received_at'],
    'soubor nese jen version, state, ts a received_at');
$fileText = (string) file_get_contents($cfg['state_dir'] . '/state.json');
check(!str_contains($fileText, SECRET) && !str_contains($fileText, 'v1=') && !str_contains($fileText, '127.0.0.1'),
    'v souboru není tajemství, podpis ani adresa odesílatele');

check(heartbeat_store($cfg, 'ERROR', 1000, 5001) === 'stale', 'stejné ts: odmítnuto jako opakované');
check(entry_of($cfg)['state'] === 'OK', 'opakované hlášení stav nezměnilo');
check(heartbeat_store($cfg, 'ERROR', 999, 5002) === 'stale', 'starší ts: odmítnuto');
check(entry_of($cfg) === ['state' => 'OK', 'ts' => 1000, 'received_at' => 5000], 'starší hlášení nepřepsalo novější');
check(heartbeat_store($cfg, 'WARNING', 1001, 5003) === 'stored', 'novější ts: uloží se');
check(entry_of($cfg) === ['state' => 'WARNING', 'ts' => 1001, 'received_at' => 5003], 'nový stav i čas přijetí');

$cfg2 = $newCfg();
mkdir($cfg2['state_dir'], 0700, true);
file_put_contents($cfg2['state_dir'] . '/state.json', '{"version":1,"state":"OK"');
check(entry_of($cfg2) === null, 'poškozený soubor se nečte jako platný stav');
check(heartbeat_store($cfg2, 'OK', 2000, 6000) === 'stored', 'poškozený soubor nebrání dalšímu hlášení');

$cfg3 = $newCfg();
mkdir($cfg3['state_dir'], 0700, true);
file_put_contents($cfg3['state_dir'] . '/state.json', (string) json_encode(['version' => 2, 'state' => 'OK', 'ts' => 9000, 'received_at' => 9000]));
check(entry_of($cfg3) === null, 'jiná verze souboru stavu se ignoruje');
check(heartbeat_store($cfg3, 'OK', 100, 6000) === 'stored', 'neznámá verze se přepíše');

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $cfg4 = $newCfg();
    mkdir($cfg4['state_dir'], 0500, true);
    check(heartbeat_store($cfg4, 'OK', 1000, 7000) === 'error', 'nezapisovatelná složka: chyba, ne tichý úspěch');
    chmod($cfg4['state_dir'], 0700);
} else {
    check(true, 'nezapisovatelná složka: přeskočeno (běží jako root)');
}

// ---------- Nahrazení stavu a stabilní zámek ----------

section('Nahrazení stavu rename() a stabilní zámek');
$cfg = $newCfg();
mkdir($cfg['state_dir'], 0700, true);
$statePath = $cfg['state_dir'] . '/' . HEARTBEAT_STATE_FILE;
$lockPath = $cfg['state_dir'] . '/' . HEARTBEAT_LOCK_FILE;
$tmpPath = $cfg['state_dir'] . '/' . HEARTBEAT_TMP_FILE;

check(heartbeat_store($cfg, 'OK', 5000, 9000) === 'stored', 'první hlášení uloženo');
check(is_file($lockPath), 'zámek je samostatný soubor, ne zamykaný state.json');
clearstatcache();
$lockInode = fileinode($lockPath);
$stateInode = fileinode($statePath);

// Obsah zámku je lhostejný, ale otevření ho nesmí zkrátit (režim 'c', ne 'w').
file_put_contents($lockPath, 'znacka');
check(heartbeat_store($cfg, 'WARNING', 5001, 9001) === 'stored', 'druhé hlášení uloženo');
clearstatcache();
check(fileinode($lockPath) === $lockInode, 'zámek se při požadavku nemaže ani nevytváří znovu');
check(file_get_contents($lockPath) === 'znacka', 'otevření zámku nezkrátí jeho obsah před zamčením');
check(fileinode($statePath) !== $stateInode, 'stav se nahrazuje rename(), ne přepisem na místě');
check(!is_file($tmpPath), 'dočasný soubor po úspěšném zápisu nezůstává');

// Zapomenutý dočasný soubor z přerušeného zápisu nesmí nic rozbít ani být brán jako stav.
file_put_contents($tmpPath, '{"version":1,"state":"CRITICAL"');
check(heartbeat_read($cfg)['state'] === 'WARNING', 'rozepsaný dočasný soubor se nečte jako stav');
check(heartbeat_store($cfg, 'ERROR', 5002, 9002) === 'stored', 'zapomenutý dočasný soubor nebrání dalšímu zápisu');
check(!is_file($tmpPath) && heartbeat_read($cfg)['state'] === 'ERROR', 'dočasný soubor se přepsal a přesunul na místo');

check(sorted_names($cfg['state_dir']) === ['.lock', 'state.json'],
    've složce stavu jsou jen state.json a zámek: ' . implode(', ', sorted_names($cfg['state_dir'])));

// Selhání zápisu nesmí obětovat předchozí platný stav.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $before = (string) file_get_contents($statePath);
    chmod($cfg['state_dir'], 0500);
    $result = heartbeat_store($cfg, 'OK', 5003, 9003);
    chmod($cfg['state_dir'], 0700);
    check($result === 'error', 'neúspěšný zápis vrací error, ne tichý úspěch');
    check((string) file_get_contents($statePath) === $before, 'předchozí platný stav zůstal po selhání nedotčený');
    check(heartbeat_read($cfg)['state'] === 'ERROR', 'po selhání se dál zobrazuje poslední platný stav');
} else {
    check(true, 'selhání zápisu: přeskočeno (běží jako root)');
    check(true, 'selhání zápisu: přeskočeno (běží jako root)');
    check(true, 'selhání zápisu: přeskočeno (běží jako root)');
}

// Čtenář nesmí nikdy dostat neúplný JSON, ani když se vedle něj bez přestávky zapisuje.
$cfg = $newCfg();
mkdir($cfg['state_dir'], 0700, true);
heartbeat_store($cfg, 'OK', 6000, 9000);
$writer = proc_open([PHP_BINARY, '-d', 'error_log=', __FILE__, '--zapisuj', $cfg['state_dir'], '600', '6001'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$reads = $invalid = 0;
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline && (proc_get_status($writer)['running'] ?? false) && $reads < 200000) {
    $reads++;
    $invalid += heartbeat_read($cfg) === null ? 1 : 0;
}
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($writer);
check($reads > 100, "čtenář stihl během zápisů {$reads} čtení");
check($invalid === 0, "žádné čtení nevrátilo neúplný nebo neplatný stav (neplatných: {$invalid})");
check(heartbeat_read($cfg) !== null, 'po sérii zápisů je stav platný');

// Zámek drží zapisovatele, ale stránku nikdy nebrzdí.
$marker = $cfg['state_dir'] . '/zamek-drzen';
$holder = proc_open([PHP_BINARY, '-d', 'error_log=', __FILE__, '--drz-zamek', $cfg['state_dir'], '1.5', $marker],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$waited = microtime(true);
while (!is_file($marker) && microtime(true) - $waited < 5) {
    usleep(5000);
    clearstatcache();
}
check(is_file($marker), 'druhý proces drží výhradní zámek');

$start = microtime(true);
$entry = heartbeat_read($cfg);
$readTime = microtime(true) - $start;
check($entry !== null && $readTime < 0.5, sprintf('čtení stavu nečeká na probíhající zápis (%.3f s)', $readTime));

$start = microtime(true);
heartbeat_store($cfg, 'OK', 7000, 9500);
$storeTime = microtime(true) - $start;
check($storeTime > 0.3, sprintf('zápis na zámek skutečně počká (%.2f s)', $storeTime));
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($holder);
@unlink($marker);

// ---------- Souběžné zápisy ----------

section('Souběžné zápisy (skutečné procesy, společný start)');

/** Spustí $tsList dětí naráz nad jednou složkou a vrátí jejich výsledky. */
function store_concurrently(string $dir, array $tsList, int $now): array
{
    $startAt = (int) (microtime(true) * 1e6) + 400000;   // 0,4 s na start všech dětí
    $procs = [];
    foreach ($tsList as $ts) {
        $cmd = [PHP_BINARY, '-d', 'error_log=', __FILE__, '--uloz', $dir, (string) $ts, 'OK', (string) $now, (string) $startAt];
        $procs[$ts] = [proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    $out = [];
    foreach ($procs as $ts => [$proc, $pipes]) {
        $out[$ts] = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    return $out;
}

$cfg = $newCfg();
mkdir($cfg['state_dir'], 0700, true);
$tsList = [1010, 1004, 1008, 1001, 1007, 1003, 1009, 1002, 1006, 1005];
$out = store_concurrently($cfg['state_dir'], $tsList, 8000);
$entry = entry_of($cfg);
check($entry !== null && $entry['ts'] === max($tsList), 'po 10 souběžných zápisech je uložené nejvyšší ts (' . ($entry['ts'] ?? '—') . ')');
check(count(array_filter($out, static fn (string $r): bool => $r === 'stored')) >= 1
    && array_diff(array_values($out), ['stored', 'stale']) === [],
    'každý proces skončil jako stored nebo stale, žádná chyba');
check($out[max($tsList)] === 'stored', 'nejvyšší ts se uložilo');
check(json_decode((string) file_get_contents($cfg['state_dir'] . '/state.json'), true) !== null,
    'soubor stavu je po souběhu platný JSON (žádný rozepsaný zápis)');

// Vzestupná řada: jakmile se uloží vyšší ts, nižší už nesmí nikdy přepsat.
$cfg = $newCfg();
mkdir($cfg['state_dir'], 0700, true);
$ok = true;
for ($round = 0; $round < 3; $round++) {
    $base = 2000 + $round * 100;
    $list = range($base, $base + 7);
    shuffle($list);
    store_concurrently($cfg['state_dir'], $list, 8000);
    $ok = $ok && entry_of($cfg)['ts'] === max($list);
}
check($ok, '3 kola po 8 souběžných zápisech: vždy zůstalo nejvyšší ts');

// ---------- Zobrazení ----------

section('Veřejné zobrazení');
$show = static function (array $entry, int $age) use ($tmp, &$counter): string {
    $dir = $tmp . '/zobrazeni-' . ++$counter;
    mkdir($dir, 0700, true);
    $now = 1793000000;
    file_put_contents($dir . '/state.json', (string) json_encode($entry + ['received_at' => $now - $age]));

    return heartbeat_display(['state_dir' => $dir], $now);
};
$valid = ['version' => HEARTBEAT_STATE_VERSION, 'ts' => 1793000000];

foreach (HEARTBEAT_STATES as $state) {
    check($show(['state' => $state] + $valid, 60) === $state, "čerstvé hlášení se zobrazí jako {$state}");
}
check($show(['state' => 'OK'] + $valid, HEARTBEAT_MAX_AGE) === 'OK', 'hranice: 900 s staré hlášení se ještě zobrazí');
check($show(['state' => 'OK'] + $valid, HEARTBEAT_MAX_AGE + 1) === HEARTBEAT_NO_DATA, 'po 900 s je NO DATA, ne zelené OK');
check($show(['state' => 'CRITICAL'] + $valid, 3600) === HEARTBEAT_NO_DATA, 'starý CRITICAL je taky NO DATA');
check($show(['state' => 'OK'] + $valid, -HEARTBEAT_CLOCK_SKEW) === 'OK', 'čas přijetí 300 s v budoucnosti se toleruje');
check($show(['state' => 'OK'] + $valid, -HEARTBEAT_CLOCK_SKEW - 1) === HEARTBEAT_NO_DATA, 'čas přijetí daleko v budoucnosti je NO DATA');
check($show(['state' => 'ok'] + $valid, 60) === HEARTBEAT_NO_DATA, 'neznámý stav v souboru je NO DATA');
check($show(['state' => 'OK', 'version' => 1, 'ts' => 0], 60) === HEARTBEAT_NO_DATA, 'ts 0 v souboru je NO DATA');

$empty = $tmp . '/zobrazeni-prazdne';
mkdir($empty, 0700, true);
check(heartbeat_display(['state_dir' => $empty], 1793000000) === HEARTBEAT_NO_DATA, 'chybějící soubor: NO DATA');
file_put_contents($empty . '/state.json', '');
check(heartbeat_display(['state_dir' => $empty], 1793000000) === HEARTBEAT_NO_DATA, 'prázdný soubor: NO DATA');
file_put_contents($empty . '/state.json', '{"version":1,"state":"OK","ts":17930');
check(heartbeat_display(['state_dir' => $empty], 1793000000) === HEARTBEAT_NO_DATA, 'rozepsaný soubor: NO DATA');
check(heartbeat_display(['state_dir' => $tmp . '/vubec-neexistuje'], 1793000000) === HEARTBEAT_NO_DATA, 'chybějící složka: NO DATA');
check(heartbeat_state_for_page(['state_dir' => $tmp . '/vubec-neexistuje']) === HEARTBEAT_NO_DATA, 'stav pro stránku při chybě: NO DATA');

check(heartbeat_state_class('OK') === 'project__state', 'OK bez zvýraznění');
foreach (['WARNING', 'ERROR', 'CRITICAL'] as $state) {
    check(heartbeat_state_class($state) === 'project__state project__state--problem', "{$state} akcentem");
}
foreach (['UNKNOWN', HEARTBEAT_NO_DATA] as $state) {
    check(heartbeat_state_class($state) === 'project__state project__state--unknown', "{$state} tlumeně");
}

// ---------- Strop požadavků ----------

section('Strop požadavků');
$cfg = $newCfg();
$now = 1793000000;   // celá minuta
$outcomes = [];
for ($i = 0; $i < HEARTBEAT_BUDGET_MINUTE + 5; $i++) {
    $outcomes[] = heartbeat_budget_take($cfg, $now);
}
$passed = count(array_filter($outcomes, static fn (string $r): bool => $r === 'ok'));
check($passed === HEARTBEAT_BUDGET_MINUTE, 'za minutu projde nejvýš ' . HEARTBEAT_BUDGET_MINUTE . ' požadavků (prošlo ' . $passed . ')');
check(array_slice($outcomes, HEARTBEAT_BUDGET_MINUTE) === array_fill(0, 5, 'limit'), 'další požadavky hlásí vyčerpaný strop');
check(heartbeat_budget_take($cfg, $now + 60) === 'ok', 'v další minutě se počítadlo nuluje');
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $broken = $tmp . '/strop-nezapisovatelny';
    mkdir($broken, 0500, true);
    check(heartbeat_budget_take(['state_dir' => $broken], $now) === 'error', 'nezapisovatelná složka: chyba, ne vyčerpaný strop');
    chmod($broken, 0700);
} else {
    check(true, 'nezapisovatelné počítadlo: přeskočeno (běží jako root)');
}
$budget = json_decode((string) file_get_contents($cfg['state_dir'] . '/budget.json'), true);
check(array_keys($budget) === ['minute', 'n'], 'budget.json nese jen minute a n (žádné adresy ani těla)');

// ---------- Stránka Projekty ----------

section('Stránka Projekty (dočasná kopie aplikace, config/local.php)');

function render_projects(string $tmp, string $name, array $heartbeat, ?callable $prepare = null): array
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
    @mkdir($root . '/public/projekty', 0700, true);
    copy(APP_ROOT . '/config/app.php', $root . '/config/app.php');
    copy(APP_ROOT . '/public/_app.php', $root . '/public/_app.php');
    copy(APP_ROOT . '/public/projekty/index.php', $root . '/public/projekty/index.php');
    file_put_contents($root . '/config/local.php', '<?php return ' . var_export(['heartbeat' => $heartbeat], true) . ';');
    if ($prepare) {
        $prepare($root);
    }

    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $root . '/public/projekty/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'html' => (string) $out, 'stderr' => (string) $err];
}

$baseHb = ['enabled' => true, 'project' => 'nexusstat', 'require_https' => true];
/** Zapíše stav do dočasné aplikace tak, jak by ho zapsal přijímač. */
$withState = static function (string $state, int $age) {
    return static function (string $root) use ($state, $age): void {
        @mkdir($root . '/var/heartbeat', 0700, true);
        file_put_contents($root . '/var/heartbeat/state.json', (string) json_encode([
            'version' => HEARTBEAT_STATE_VERSION, 'state' => $state, 'ts' => time() - $age, 'received_at' => time() - $age,
        ]));
    };
};

$page = render_projects($tmp, 'vypnuto', ['enabled' => false]);
check($page['code'] === 0 && str_contains($page['html'], 'NexusSTAT') && !str_contains($page['html'], '<dt>stav Nexusu</dt>'),
    'vypnutý heartbeat: stránka funguje a stav se vůbec nezobrazí');

foreach (['OK' => 'project__state"', 'WARNING' => 'project__state--problem', 'CRITICAL' => 'project__state--problem',
          'UNKNOWN' => 'project__state--unknown'] as $state => $class) {
    $page = render_projects($tmp, 'stav-' . strtolower($state), array_replace($baseHb, ['state_dir' => 'var/heartbeat']), $withState($state, 60));
    check($page['code'] === 0 && str_contains($page['html'], '<dt>stav Nexusu</dt>') && str_contains($page['html'], ">{$state}</dd>")
        && str_contains($page['html'], $class), "stav {$state} se zobrazí u NexusSTATu");
}

$page = render_projects($tmp, 'nodata', array_replace($baseHb, ['state_dir' => 'var/heartbeat']), $withState('OK', 901));
check(str_contains($page['html'], '>NO DATA</dd>') && !str_contains($page['html'], '>OK</dd>'),
    'hlášení starší než 900 s: NO DATA, nikde zelené OK');

$page = render_projects($tmp, 'bez-souboru', array_replace($baseHb, ['state_dir' => 'var/heartbeat']));
check(str_contains($page['html'], '>NO DATA</dd>'), 'chybějící soubor stavu: NO DATA');

$page = render_projects($tmp, 'u-jineho', array_replace($baseHb, ['state_dir' => 'var/heartbeat', 'project' => 'homelab']), $withState('OK', 60));
check(substr_count($page['html'], '<dt>stav Nexusu</dt>') === 1
    && strpos($page['html'], 'stav Nexusu') > strpos($page['html'], 'id="homelab"')
    && strpos($page['html'], 'stav Nexusu') < strpos($page['html'], 'id="nexusstat"'),
    'stav se zobrazí jen u projektu z nastavení');

section('Legenda stavů');
$page = render_projects($tmp, 'legenda', array_replace($baseHb, ['state_dir' => 'var/heartbeat']), $withState('WARNING', 60));
check(str_contains($page['html'], 'id="stav-nexusu"') && str_contains($page['html'], '<dl class="legend">'),
    'legenda používá stávající komponentu legendy');
$missing = [];
foreach (HEARTBEAT_LEGEND as $state => $description) {
    // e_cs() doplňuje nezlomitelné mezery za jednopísmenné předložky – porovnává se vykreslený tvar.
    if (!str_contains($page['html'], '<dt>' . e($state) . '</dt><dd>' . e_cs($description) . '</dd>')) {
        $missing[] = $state;
    }
}
check($missing === [], 'legenda vysvětluje všech šest hodnot' . ($missing ? ' – chybí ' . implode(', ', $missing) : ''));
check(substr_count($page['html'], 'id="stav-nexusu"') === 1 && strpos($page['html'], 'id="pristup"') < strpos($page['html'], 'id="stav-nexusu"'),
    'legenda je jednou, za sekcí Přístup');
check(!preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $page['html']) && !str_contains($page['html'], 'heartbeat.key')
    && !str_contains($page['html'], 'var/'),
    'legenda nepřidává žádnou diagnostiku');

$page = render_projects($tmp, 'legenda-vypnuto', ['enabled' => false]);
check(!str_contains($page['html'], 'id="stav-nexusu"') && str_contains($page['html'], 'id="pristup"'),
    's vypnutým heartbeatem se legenda nevykreslí');

section('Stránka Projekty – ostatní');
$page = render_projects($tmp, 'bez-udaju', array_replace($baseHb, ['state_dir' => 'var/heartbeat']), $withState('ERROR', 60));
check(!preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $page['html']) && !str_contains($page['html'], 'v1=')
    && !str_contains($page['html'], SECRET) && !str_contains($page['html'], 'received_at')
    && !str_contains($page['html'], 'heartbeat.key'),
    'v HTML není IP adresa, podpis, tajemství ani vnitřní údaj');
check(!str_contains($page['html'], 'Warning') && !str_contains($page['html'], 'Fatal'),
    'návštěvník nevidí varování ani chyby PHP');

// ---------- Log serveru ----------

section('Log serveru');
$log = (string) @file_get_contents($tmp . '/php-error.log');
check(!str_contains($log, SECRET) && !str_contains($log, 'v1='), 'v logu není tajemství ani podpis');
check($log === '' || str_contains($log, 'mh42: heartbeat – '), 'záznamy v logu mají jen důvod, ne obsah');

echo "\nVýsledek: {$results['ok']} ok, {$results['chyba']} chyb\n";
exit($results['chyba'] ? 1 : 0);
