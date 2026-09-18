<?php

declare(strict_types=1);

// Jednoduchý HTTP GET pro volání API ze serveru (Open-Meteo). Bez knihoven: curl,
// a když na hostingu chybí, PHP streamy. Chyby se návštěvníkovi nevypisují, do logu
// serveru jde jen hostitel a stav – nikdy celá adresa (obsahuje souřadnice nebo dotaz).

const HTTP_CONNECT_TIMEOUT = 3;   // sekund
const HTTP_TIMEOUT = 5;           // sekund včetně přenosu
const HTTP_USER_AGENT = 'mh42.cz (+https://mh42.cz)';

/**
 * Tělo odpovědi se stavem 200, jinak null (chyba sítě, timeout, jiný stav, odpověď
 * delší než $limit bajtů). Přesměrování se nesledují.
 */
function remote_get(string $url, int $limit): ?string
{
    return function_exists('curl_init') ? remote_get_curl($url, $limit) : remote_get_stream($url, $limit);
}

function remote_get_curl(string $url, int $limit): ?string
{
    $body = '';
    $tooLarge = false;
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING       => '',   // gzip podle serveru, limit platí pro rozbalená data
        CURLOPT_USERAGENT      => HTTP_USER_AGENT,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        // Delší odpověď se přeruší hned při přenosu, nenačítá se celá do paměti.
        CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$body, &$tooLarge, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);

    if ($ok !== true || $status !== 200) {
        remote_log($url, $tooLarge ? 'odpověď je delší než ' . $limit . ' B' : ($status ?: $error));
        return null;
    }

    return $body;
}

function remote_get_stream(string $url, int $limit): ?string
{
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        remote_log($url, 'chybí curl i allow_url_fopen');
        return null;
    }

    $context = stream_context_create(['http' => [
        'method'          => 'GET',
        'timeout'         => HTTP_TIMEOUT,
        'follow_location' => 0,
        'ignore_errors'   => true,   // tělo i při chybovém stavu – stav se kontroluje níže
        'header'          => "Accept: application/json\r\nUser-Agent: " . HTTP_USER_AGENT . "\r\n",
    ]]);

    // Vlastnost 'timeout' hlídá čtení; navázání spojení řídí default_socket_timeout.
    $previous = ini_set('default_socket_timeout', (string) HTTP_CONNECT_TIMEOUT);
    $body = @file_get_contents($url, false, $context, 0, $limit + 1);
    if ($previous !== false) {
        ini_set('default_socket_timeout', $previous);
    }

    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $status = preg_match('~^HTTP/\S+ (\d{3})~', (string) ($headers[0] ?? ''), $m) ? (int) $m[1] : 0;

    if ($body === false || $status !== 200 || strlen($body) > $limit) {
        remote_log($url, $body !== false && strlen($body) > $limit ? 'odpověď je delší než ' . $limit . ' B' : ($status ?: 'bez odpovědi'));
        return null;
    }

    return $body;
}

function remote_log(string $url, int|string $reason): void
{
    error_log(sprintf('mh42: %s nevrátil použitelnou odpověď (%s)', (string) parse_url($url, PHP_URL_HOST), $reason));
}
