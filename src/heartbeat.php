<?php

declare(strict_types=1);

// Přijímač veřejného heartbeatu z NexusSTATu.
//
// Kontrakt je daný odesílačem (NexusSTAT, src/nexusstat/heartbeat.py, sekce
// „Kontrakt přijímače heartbeatu“ v jeho docs/REQUIREMENTS.md):
// POST přes HTTPS, Content-Type: application/json, tělo nejvýš 256 B a přesně
// {"v":1,"state":"…","ts":…}, podpis HMAC-SHA256 nad přesnými bajty těla
// v hlavičce X-NexusSTAT-Signature: v1=<hex>. Úspěch je 204 bez těla.
//
// Veřejně se ukazuje jedině celkový stav. Ukládá se jen poslední stav, jeho ts
// a čas přijetí – žádná adresa odesílatele, podpis, historie ani diagnostika.
// Chybějící, poškozený nebo starý stav se nikdy nezobrazí jako OK: starší než
// HEARTBEAT_MAX_AGE je NO DATA.
//
// Souběh: celý úsek „přečti – porovnej ts – zapiš“ drží výhradní zámek souboru,
// takže se dva požadavky nemohou prostřídat a starší hlášení nikdy nepřepíše
// novější. Čtení pro stránku bere sdílený zámek, takže nikdy nevidí půl souboru.

const HEARTBEAT_CONTRACT_VERSION = 1;      // hodnota "v" v těle
const HEARTBEAT_STATE_VERSION = 1;         // verze vlastního souboru stavu
const HEARTBEAT_BODY_LIMIT = 256;          // bajtů (kontrakt)
const HEARTBEAT_CLOCK_SKEW = 300;          // sekund – tolerance odchylky hodin
const HEARTBEAT_MAX_AGE = 900;             // sekund – pak se zobrazí NO DATA
const HEARTBEAT_STATE_LIMIT = 1024;        // bajtů – strop čtení souboru stavu
const HEARTBEAT_SECRET_MIN = 32;           // znaků (stejně jako odesílač)
const HEARTBEAT_SECRET_FILE_MAX = 4096;    // bajtů souboru s tajemstvím
const HEARTBEAT_BUDGET_MINUTE = 30;        // požadavků za minutu (odesílač potřebuje 1 za 5 min)

// Soubory ve <state_dir>. Zámek je vlastní stabilní soubor: nikdy se nemaže ani
// nenahrazuje, takže všechny procesy zamykají pořád tentýž a rename() stavu o něj
// nepřipraví. Stav se nahrazuje přes dočasný soubor ve stejné složce.
const HEARTBEAT_STATE_FILE = 'state.json';
const HEARTBEAT_LOCK_FILE = '.lock';
const HEARTBEAT_TMP_FILE = '.state.tmp';
const HEARTBEAT_BUDGET_FILE = 'budget.json';

// Stavy podle kontraktu. NO DATA není stav NexusSTATu, ale údaj naší stránky,
// že žádné dost čerstvé hlášení nemáme.
const HEARTBEAT_STATES = ['OK', 'WARNING', 'ERROR', 'CRITICAL', 'UNKNOWN'];
const HEARTBEAT_NO_DATA = 'NO DATA';

// Krátké vysvětlení stavů pro návštěvníka (legenda na stránce Projekty).
// Jen co která hodnota znamená – žádná diagnostika, příčiny ani postupy.
const HEARTBEAT_LEGEND = [
    'OK'              => 'Sledované hodnoty jsou v pořádku.',
    'WARNING'         => 'Upozornění.',
    'ERROR'           => 'Závada.',
    'CRITICAL'        => 'Závažná závada.',
    'UNKNOWN'         => 'Stav nelze spolehlivě určit.',
    HEARTBEAT_NO_DATA => 'Nepřišlo aktuální hlášení.',
];

/**
 * Nastavení heartbeatu z config/app.php, nebo null, když je vypnutý nebo neúplný
 * (endpoint pak vrací 404 a na webu se stav vůbec neukazuje).
 *
 * @return array{secret_file: string, state_dir: string, project: ?string, require_https: bool}|null
 */
function heartbeat_config(mixed $raw = null): ?array
{
    $raw = func_num_args() > 0 ? $raw : config('heartbeat');
    if (!is_array($raw) || ($raw['enabled'] ?? false) !== true) {
        return null;
    }

    $secretFile = $raw['secret_file'] ?? 'config/heartbeat.key';
    $stateDir = $raw['state_dir'] ?? 'var/heartbeat';
    $project = $raw['project'] ?? null;
    $requireHttps = $raw['require_https'] ?? true;

    if (!is_string($secretFile) || trim($secretFile) === ''
        || !is_string($stateDir) || trim($stateDir) === ''
        || ($project !== null && (!is_string($project) || trim($project) === ''))
        || !is_bool($requireHttps)) {
        return null;
    }

    return [
        'secret_file'   => heartbeat_path($secretFile),
        'state_dir'     => heartbeat_path($stateDir),
        'project'       => $project,
        'require_https' => $requireHttps,
    ];
}

/** Cesta z konfigurace: relativní se bere od kořene aplikace (vedle public/). */
function heartbeat_path(string $path): string
{
    return str_starts_with($path, '/') ? $path : APP_ROOT . '/' . $path;
}

/** Do logu serveru jen důvod, nikdy tajemství, podpis ani tělo požadavku. */
function heartbeat_log(string $reason): void
{
    error_log('mh42: heartbeat – ' . $reason);
}

/**
 * Sdílené tajemství ze souboru správce (mimo veřejný adresář i Git), nebo null.
 *
 * Trimují se stejné bajty jako v Pythonu (bytes.strip()) a platí stejná pravidla
 * jako v odesílači: aspoň 32 znaků ASCII bez mezer. Tajemství je tím na obou
 * stranách bajtově shodné i u souboru, který končí novým řádkem.
 */
function heartbeat_secret(array $cfg): ?string
{
    $file = $cfg['secret_file'];
    if (is_link($file) || !is_file($file)) {
        heartbeat_log('soubor se sdíleným tajemstvím chybí nebo není obyčejný soubor');
        return null;
    }

    $raw = @file_get_contents($file, false, null, 0, HEARTBEAT_SECRET_FILE_MAX + 1);
    if ($raw === false) {
        heartbeat_log('soubor se sdíleným tajemstvím nejde přečíst – zkontroluj oprávnění');
        return null;
    }

    $secret = trim($raw, " \t\n\r\x0B\x0C");
    if (strlen($raw) > HEARTBEAT_SECRET_FILE_MAX || strlen($secret) < HEARTBEAT_SECRET_MIN
        || preg_match('/^[\x21-\x7E]+$/', $secret) !== 1) {
        heartbeat_log('sdílené tajemství musí být aspoň ' . HEARTBEAT_SECRET_MIN . ' znaků ASCII bez mezer');
        return null;
    }

    return $secret;
}

/** Hodnota hlavičky X-NexusSTAT-Signature pro tyhle přesné bajty těla. */
function heartbeat_sign(string $secret, string $body): string
{
    return 'v1=' . hash_hmac('sha256', $body, $secret);
}

/** Porovnání podpisu v konstantním čase; chybějící hlavička není výjimka, jen nesouhlas. */
function heartbeat_signature_valid(string $secret, string $body, ?string $header): bool
{
    return hash_equals(heartbeat_sign($secret, $body), (string) $header);
}

/**
 * Tělo požadavku → ['state' => …, 'ts' => …], nebo null při jakékoli odchylce
 * od kontraktu. Přijímají se jen klíče v, state a ts – nic navíc.
 */
function heartbeat_parse_body(string $raw): ?array
{
    if ($raw === '' || strlen($raw) > HEARTBEAT_BODY_LIMIT) {
        return null;
    }

    try {
        $data = json_decode($raw, true, 3, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    if (!is_array($data) || count($data) !== 3
        || ($data['v'] ?? null) !== HEARTBEAT_CONTRACT_VERSION
        || !in_array($data['state'] ?? null, HEARTBEAT_STATES, true)
        || !is_int($data['ts'] ?? null) || $data['ts'] <= 0) {
        return null;
    }

    return ['state' => $data['state'], 'ts' => $data['ts']];
}

/** Hodiny odesílatele se smí lišit nejvýš o HEARTBEAT_CLOCK_SKEW sekund. */
function heartbeat_clock_ok(int $ts, int $now): bool
{
    return abs($ts - $now) <= HEARTBEAT_CLOCK_SKEW;
}

/** Spojení je HTTPS (za Cloudflare ve variantě Full strict je šifrované i k hostingu). */
function heartbeat_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

    return ($https !== '' && $https !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/**
 * Uložený stav, nebo null, když chybí, nejde přečíst nebo nemá očekávaný tvar.
 *
 * @return array{state: string, ts: int, received_at: int}|null
 */
function heartbeat_valid_entry(mixed $entry): ?array
{
    if (!is_array($entry) || ($entry['version'] ?? null) !== HEARTBEAT_STATE_VERSION
        || !in_array($entry['state'] ?? null, HEARTBEAT_STATES, true)
        || !is_int($entry['ts'] ?? null) || $entry['ts'] <= 0
        || !is_int($entry['received_at'] ?? null) || $entry['received_at'] <= 0) {
        return null;
    }

    return ['state' => $entry['state'], 'ts' => $entry['ts'], 'received_at' => $entry['received_at']];
}

/**
 * Poslední uložený stav, nebo null.
 *
 * Bez zámku: stav se nahrazuje jediným rename(), takže čtenář vždy dostane celý
 * starý, nebo celý nový soubor – nikdy rozepsaný. Stránka proto nikdy nečeká na
 * probíhající zápis a zaseknutý zapisovatel ji nemůže zdržet.
 *
 * @return array{state: string, ts: int, received_at: int}|null
 */
function heartbeat_read(array $cfg): ?array
{
    $raw = @file_get_contents($cfg['state_dir'] . '/' . HEARTBEAT_STATE_FILE, false, null, 0, HEARTBEAT_STATE_LIMIT);

    return $raw === false ? null : heartbeat_valid_entry(json_decode($raw, true));
}

/**
 * Uloží hlášení jako poslední platný stav. Vrací 'stored', 'stale' (ts není větší
 * než u posledního přijatého – opakovaná nebo starší zpráva) nebo 'error'.
 *
 * Výhradní zámek drží celý úsek „přečti – porovnej – zapiš“: dva souběžné
 * požadavky se tak nemohou prostřídat a starší hlášení nikdy nepřepíše novější.
 * Zamyká se samostatný stabilní soubor (HEARTBEAT_LOCK_FILE), ne nahrazovaný
 * state.json – ten rename() vymění za nový, takže zámek na něm by platil pro
 * soubor, který už nikdo nevidí. Zámek se otevírá režimem 'c' (nezkracuje obsah)
 * a nikdy se nemaže.
 *
 * Nový stav se nejdřív zapíše celý do dočasného souboru ve stejné složce a teprve
 * pak se jediným rename() přesune na místo. Když zápis selže nebo se přeruší,
 * zůstane předchozí platný stav nedotčený a čtenář nikdy nevidí rozepsaný soubor.
 */
function heartbeat_store(array $cfg, string $state, int $ts, int $now): string
{
    $dir = $cfg['state_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        heartbeat_log('složku pro stav nejde vytvořit – zkontroluj oprávnění var/');
        return 'error';
    }

    $lock = @fopen($dir . '/' . HEARTBEAT_LOCK_FILE, 'c');
    if ($lock === false) {
        heartbeat_log('zámek stavu nejde otevřít – zkontroluj oprávnění var/');
        return 'error';
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            heartbeat_log('zámek stavu nejde získat');
            return 'error';
        }

        $last = heartbeat_read($cfg);
        if ($last !== null && $ts <= $last['ts']) {
            return 'stale';
        }

        // Jen poslední stav, jeho ts a čas přijetí – nic o odesílateli ani o podpisu.
        $json = (string) json_encode([
            'version'     => HEARTBEAT_STATE_VERSION,
            'state'       => $state,
            'ts'          => $ts,
            'received_at' => $now,
        ]) . "\n";

        $tmp = $dir . '/' . HEARTBEAT_TMP_FILE;
        if (@file_put_contents($tmp, $json) !== strlen($json)
            || !@rename($tmp, $dir . '/' . HEARTBEAT_STATE_FILE)) {
            @unlink($tmp);   // nedokončený zápis po sobě neuklidí nikdo jiný
            heartbeat_log('stav nejde zapsat – zkontroluj oprávnění var/');
            return 'error';
        }

        return 'stored';
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Společný strop požadavků na endpoint: HEARTBEAT_BUDGET_MINUTE za minutu.
 * Chrání hosting před tím, aby kdokoli vyvolával ověřování podpisu a zápisy do
 * var/ bez omezení. Odesílač potřebuje jeden požadavek za 5 minut.
 *
 * Stav je jediný soubor <state_dir>/budget.json, který se při každém požadavku
 * přepíše: {"minute": <číslo minuty od epochy>, "n": <požadavků v ní>}.
 * Žádné IP adresy, těla ani podpisy.
 *
 * Vrací 'ok', 'limit' (strop vyčerpaný) nebo 'error' (počítadlo nejde vést – pak se
 * požadavek nepovolí, ale je to chyba provozu, ne klienta, a zapíše se do logu).
 */
function heartbeat_budget_take(array $cfg, int $now): string
{
    $dir = $cfg['state_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        heartbeat_log('složku pro stav nejde vytvořit – zkontroluj oprávnění var/');
        return 'error';
    }

    $handle = @fopen($dir . '/' . HEARTBEAT_BUDGET_FILE, 'c+');
    if ($handle === false) {
        heartbeat_log('počítadlo požadavků nejde otevřít – zkontroluj oprávnění var/');
        return 'error';
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            heartbeat_log('počítadlo požadavků nejde uzamknout');
            return 'error';
        }
        $state = json_decode((string) stream_get_contents($handle, HEARTBEAT_STATE_LIMIT), true);
        $minute = intdiv($now, 60);
        $count = is_array($state) && ($state['minute'] ?? null) === $minute ? (int) ($state['n'] ?? 0) : 0;
        if ($count >= HEARTBEAT_BUDGET_MINUTE) {
            return 'limit';
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode(['minute' => $minute, 'n' => $count + 1]));
        fflush($handle);

        return 'ok';
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Co se má na webu zobrazit: jeden ze stavů kontraktu, nebo NO DATA.
 * Chybějící, poškozený, příliš starý i „z budoucnosti“ stav je NO DATA – nikdy OK.
 */
function heartbeat_display(array $cfg, int $now): string
{
    $entry = heartbeat_read($cfg);
    if ($entry === null) {
        return HEARTBEAT_NO_DATA;
    }

    $age = $now - $entry['received_at'];

    return $age > HEARTBEAT_MAX_AGE || $age < -HEARTBEAT_CLOCK_SKEW ? HEARTBEAT_NO_DATA : $entry['state'];
}

/** Stav pro stránku: nikdy nevyhodí výjimku a při chybě nikdy neukáže OK. */
function heartbeat_state_for_page(array $cfg, array $options = []): string
{
    try {
        return heartbeat_display($cfg, $options['now'] ?? time());
    } catch (Throwable $e) {
        heartbeat_log('stav se nepodařilo načíst: ' . $e->getMessage());
        return HEARTBEAT_NO_DATA;
    }
}

/** Zvýraznění hodnoty: závada accentem, neznámý stav tlumeně, OK obyčejně. */
function heartbeat_state_class(string $state): string
{
    return match ($state) {
        'WARNING', 'ERROR', 'CRITICAL' => 'project__state project__state--problem',
        'UNKNOWN', HEARTBEAT_NO_DATA   => 'project__state project__state--unknown',
        default                        => 'project__state',
    };
}

/**
 * Stránka se stavem se nesmí cachovat: ani prohlížeč, ani Cloudflare nesmí ukázat
 * zastaralé zelené OK. Výchozí „no-cache“ by revalidaci vynutilo, ale „no-store“
 * je jistota i proti hostingu, který k odpovědi přidává vlastní max-age.
 */
function heartbeat_no_store(): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
}
