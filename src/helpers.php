<?php

declare(strict_types=1);

/** Hodnota z konfigurace (config/app.php, případně přepsaná config/local.php). */
function config(string $key, mixed $default = null): mixed
{
    return CONFIG[$key] ?? $default;
}

function is_dev(): bool
{
    return config('env') === 'dev';
}

/** Escapování textu pro HTML obsah i atributy. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Absolutní adresa stránky pro canonical a sdílení. */
function absolute_url(string $path): string
{
    return rtrim((string) config('base_url'), '/') . '/' . ltrim($path, '/');
}

/**
 * Adresa souboru v public/assets s verzí (kvůli dlouhé cache prohlížeče).
 * V produkčním balíčku je verze otisk obsahu z config/asset-versions.php (vytváří
 * tools/balicek.py); při vývoji se použije čas změny souboru.
 */
function asset(string $path): string
{
    static $versions = null;
    $versions ??= is_file(APP_ROOT . '/config/asset-versions.php')
        ? require APP_ROOT . '/config/asset-versions.php'
        : [];

    $path = ltrim($path, '/');
    $file = PUBLIC_ROOT . '/assets/' . $path;
    $version = $versions[$path] ?? (is_file($file) ? (string) filemtime($file) : '');

    return '/assets/' . $path . ($version !== '' ? '?v=' . $version : '');
}

/** Vloží šablonu ze src/templates; proměnné z $vars jsou v ní dostupné. */
function template(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP_ROOT . '/src/templates/' . $name . '.php';
}

/**
 * Začátek stránky: <head>, hlavička webu a otevření <main>.
 *
 * Klíče $page:
 *   key          identifikátor stránky pro zvýraznění v navigaci ('home', 'o-mne', …)
 *   path         cesta stránky, např. '/projekty/' (canonical, og:url); null = bez nich
 *   title        název stránky; na úvodní stránce null
 *   description  popis pro vyhledávače
 *   noindex      true = stránka se nemá indexovat
 *   styles       další CSS soubory z public/assets
 *   type         Open Graph typ: 'website' nebo 'article' (poznámky)
 */
function page_start(array $page): void
{
    $page += [
        'key'         => '',
        'path'        => '/',
        'title'       => null,
        'description' => '',
        'noindex'     => false,
        'styles'      => [],
        'type'        => 'website',
    ];

    template('page-start', ['page' => $page]);
}

function page_end(): void
{
    template('page-end');
}

/** Odpoví stránkou 404 a ukončí zpracování. */
function not_found(): void
{
    http_response_code(404);

    page_start([
        'key'     => '404',
        'path'    => null,
        'title'   => 'Stránka nenalezena',
        'noindex' => true,
    ]);
    template('not-found');
    page_end();

    exit;
}

/**
 * Hlavičky posílané s každou stránkou. Nastavují se tady, ne v konfiguraci
 * webserveru, aby platily stejně na WEDOS i na domácím serveru.
 * HSTS se nastaví až v Cloudflare, jakmile bude HTTPS spolehlivě fungovat (viz docs).
 */
function send_default_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('Content-Type: text/html; charset=utf-8');
    // Všechno jen z vlastní domény; bez pluginů, cizích formulářů a vkládání do iframe.
    header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');   // pro starší prohlížeče bez frame-ancestors
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    // HTML se může uložit, ale před použitím se vždy ověří – změny obsahu jsou vidět hned.
    header('Cache-Control: no-cache');
}

/**
 * Escapuje text a za jednopísmenné předložky a spojky vloží nezlomitelnou mezeru,
 * aby nezůstávaly na konci řádku (česká typografie). Pro texty z content/.
 */
function e_cs(?string $value): string
{
    return (string) preg_replace('/(?<=^|[\s(])([ksvzouaiKSVZOUAI]) /u', '$1&nbsp;', e($value));
}
