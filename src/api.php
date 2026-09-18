<?php

declare(strict_types=1);

// Společné chování JSON endpointů v public/api/ (používá je jen skript tohoto webu):
// jen POST s JSON tělem a bez požadavků z cizích webů, odpověď JSON bez cache.
// Data jsou v těle požadavku, ne v adrese – nedostanou se tak do logů přístupů.

const API_BODY_LIMIT = 1024;   // bajtů

/** Odešle JSON odpověď a ukončí zpracování. */
function api_respond(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Tělo požadavku jako pole. Při jiné metodě, požadavku z cizího webu, jiném typu
 * obsahu, příliš velkém nebo nevalidním těle rovnou odpoví chybou.
 */
function api_request(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        api_respond(405, ['error' => 'method']);
    }

    // Prohlížeče posílají Sec-Fetch-Site; skript z cizího webu tu dotazy posílat nemá.
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin';
    if (!in_array($site, ['same-origin', 'none'], true)) {
        api_respond(403, ['error' => 'origin']);
    }

    $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($type !== 'application/json') {
        api_respond(415, ['error' => 'content_type']);
    }

    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > API_BODY_LIMIT) {
        api_respond(413, ['error' => 'too_large']);
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, API_BODY_LIMIT + 1);
    if (strlen($raw) > API_BODY_LIMIT) {
        api_respond(413, ['error' => 'too_large']);
    }

    try {
        $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        api_respond(400, ['error' => 'json']);
    }
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        api_respond(400, ['error' => 'json']);
    }

    return $data;
}
