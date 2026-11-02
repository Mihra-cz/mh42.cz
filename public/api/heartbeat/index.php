<?php

declare(strict_types=1);

// POST /api/heartbeat/  – přijímač veřejného heartbeatu z NexusSTATu.
//
// Tělo je přesně {"v":1,"state":"…","ts":…} (nejvýš 256 B) a hlavička
// X-NexusSTAT-Signature: v1=<hex> je HMAC-SHA256 nad jeho přesnými bajty.
// Úspěch je 204 bez těla; každé odmítnutí je prázdná odpověď 4xx/5xx bez
// podrobností, aby se z ní nedalo nic odvodit. Jak to funguje: src/heartbeat.php.
//
// Pořadí kontrol jde od nejlevnějších: co se dá odmítnout z hlaviček, se odmítne
// bez čtení těla, strop požadavků platí dřív než ověřování podpisu a zápis do var/.

require dirname(__DIR__, 2) . '/_app.php';
require APP_ROOT . '/src/heartbeat.php';

/** Prázdná odpověď bez cache a bez indexace; 204 nesmí mít tělo ani Content-Type. */
function heartbeat_respond(int $status): never
{
    http_response_code($status);
    header_remove('Content-Type');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    exit;
}

$cfg = heartbeat_config();
if ($cfg === null) {
    // Vypnutý přijímač se navenek chová jako neexistující adresa.
    heartbeat_respond(404);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    heartbeat_respond(405);
}

if ($cfg['require_https'] && !heartbeat_is_https()) {
    heartbeat_respond(400);
}

$type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($type !== 'application/json') {
    heartbeat_respond(415);
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > HEARTBEAT_BODY_LIMIT) {
    heartbeat_respond(413);
}

// O bajt víc, než kontrakt dovoluje – delší tělo se pozná a nečte se celé.
$raw = (string) file_get_contents('php://input', false, null, 0, HEARTBEAT_BODY_LIMIT + 1);
if (strlen($raw) > HEARTBEAT_BODY_LIMIT) {
    heartbeat_respond(413);
}

$now = time();
$budget = heartbeat_budget_take($cfg, $now);
if ($budget !== 'ok') {
    // Vyčerpaný strop je chyba klienta, nevedené počítadlo chyba serveru.
    heartbeat_respond($budget === 'limit' ? 429 : 503);
}

$secret = heartbeat_secret($cfg);
if ($secret === null) {
    // Chybějící nebo nepoužitelné tajemství je chyba provozu, ne požadavku.
    heartbeat_respond(503);
}

if (!heartbeat_signature_valid($secret, $raw, $_SERVER['HTTP_X_NEXUSSTAT_SIGNATURE'] ?? null)) {
    heartbeat_respond(401);
}

$report = heartbeat_parse_body($raw);
if ($report === null) {
    heartbeat_respond(400);
}

if (!heartbeat_clock_ok($report['ts'], $now)) {
    heartbeat_respond(400);
}

heartbeat_respond(match (heartbeat_store($cfg, $report['state'], $report['ts'], $now)) {
    'stored' => 204,          // jediná úspěšná odpověď podle kontraktu
    'stale'  => 409,          // opakovaná nebo starší zpráva: ts není větší než poslední přijaté
    default  => 503,
});
