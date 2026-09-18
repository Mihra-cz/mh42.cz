#!/usr/bin/env python3
"""Diagnostika hostingu před prvním nasazením – zjistí skutečné možnosti, nic nepřepisuje.

  python3 tools/wedos-diagnostika.py pripravit
      vytvoří dist/wedos-diagnostika/: složku mh42-diag-<náhodné>/ k nahrání do současného
      kořene webu (vedle provizorní stránky) a volitelnou složku mh42-diag-mimo/ k nahrání
      o úroveň výš, pokud to FTP dovolí.

  python3 tools/wedos-diagnostika.py vyhodnotit [https://mh42.cz]
      projde testy přes HTTP(S) a vypíše, co hosting umí.

Složku mh42-diag-* po vyhodnocení z hostingu smazat.
"""
import json, secrets, sys, urllib.error, urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'dist' / 'wedos-diagnostika'
SETTINGS = OUT / 'nastaveni.json'

INFO_PHP = r"""<?php
// Diagnostika mh42 – po vyhodnocení smazat.
if (($_GET['k'] ?? '') !== '__TOKEN__') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$webroot = dirname(__DIR__);
$parent = dirname($webroot);
$outside = $parent . '/mh42-diag-mimo/test.txt';
$htaccess = $webroot . '/.htaccess';
echo json_encode([
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'webroot' => $webroot,
    'webroot_obsah' => @scandir($webroot) ?: null,
    'webroot_htaccess' => is_file($htaccess) ? substr((string) @file_get_contents($htaccess), 0, 2000) : null,
    'nadrazeny_adresar' => $parent,
    'nadrazeny_obsah' => @scandir($parent) ?: null,
    'open_basedir' => ini_get('open_basedir'),
    'php_cte_mimo_web' => is_readable($outside) ? trim((string) file_get_contents($outside)) : false,
    'apache_moduly' => function_exists('apache_get_modules') ? apache_get_modules() : null,
    'expose_php' => ini_get('expose_php'),
    'https' => $_SERVER['HTTPS'] ?? null,
    'x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
    'pres_cloudflare' => isset($_SERVER['HTTP_CF_CONNECTING_IP']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
"""

HEADERS_PHP = r"""<?php
// Stejné hlavičky jako produkční web (src/helpers.php) – ověří, jak projdou přes Cloudflare.
header_remove('X-Powered-By');
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(self), payment=(), usb=()');
header('Cache-Control: no-cache');
echo "mh42-hlavicky-ok\n";
"""

DENY = """<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
"""


def files(d):
    """Obsah diagnostické složky: cesta → text. __DIR__ = veřejná cesta ke složce."""
    return {
        'info.php': INFO_PHP,
        'hlavicky.php': HEADERS_PHP,
        # .htaccess se čte a ErrorDocument funguje?
        't-errordoc/.htaccess': f'ErrorDocument 404 /{d}/t-errordoc/nf.txt\n',
        't-errordoc/nf.txt': 'mh42-errordoc-ok\n',
        # vlastní 404 přes PHP i pro neexistující *.php a složky
        't-php404/.htaccess': f'ErrorDocument 404 /{d}/t-php404/404.php\n',
        't-php404/404.php': "<?php http_response_code(404); echo 'mh42-php404-ok';\n",
        # Options -Indexes povolené?
        't-options/.htaccess': 'Options -Indexes\n',
        't-options/a.txt': 'a\n',
        # zakázaný výpis (403) → naše 404
        't-403/.htaccess': f'Options -Indexes\nErrorDocument 403 /{d}/t-php404/404.php\n',
        't-403/a.txt': 'a\n',
        # náhrada za Options: DirectoryIndex s adresou 404 stránky
        't-dirindex/.htaccess': f'DirectoryIndex index.php /{d}/t-php404/404.php\n',
        't-dirindex/a.txt': 'a\n',
        # mod_headers a cache hlavička pro statický soubor
        't-headers/.htaccess': '<IfModule mod_headers.c>\n  Header set X-Mh42-Test "headers-ok"\n'
                               '  <FilesMatch "\\.css$">\n    Header set Cache-Control "public, max-age=31536000, immutable"\n'
                               '  </FilesMatch>\n</IfModule>\n',
        't-headers/a.css': 'body{}\n',
        # MIME typ woff2 s AddType a bez něj
        't-mime/.htaccess': 'AddType font/woff2 .woff2\n',
        't-mime/x.woff2': 'wOF2',
        't-mime-bez/x.woff2': 'wOF2',
        # zákaz přístupu ke složce (varianta B)
        't-deny/.htaccess': DENY,
        't-deny/tajne.txt': 'mh42-tajne\n',
        # mod_rewrite (jen informativně)
        't-rewrite/.htaccess': 'RewriteEngine On\nRewriteRule ^x$ y.txt [L]\n',
        't-rewrite/y.txt': 'mh42-rewrite-ok\n',
    }


def prepare():
    d = f'mh42-diag-{secrets.token_hex(3)}'
    token = secrets.token_hex(12)
    OUT.mkdir(parents=True, exist_ok=True)
    for rel, content in files(d).items():
        path = OUT / d / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content.replace('__TOKEN__', token), encoding='utf-8')
    (OUT / 'mh42-diag-mimo').mkdir(exist_ok=True)
    (OUT / 'mh42-diag-mimo' / 'test.txt').write_text('mh42-mimo-ok\n', encoding='utf-8')
    SETTINGS.write_text(json.dumps({'dir': d, 'token': token}, indent=1), encoding='utf-8')
    print(f'Připraveno v {OUT.relative_to(ROOT)}:')
    print(f'  {d}/          → nahrát do současného kořene webu (vedle provizorní stránky)')
    print('  mh42-diag-mimo/  → pokud FTP dovolí, nahrát o úroveň VÝŠ než kořen webu (volitelné)')


def get(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'mh42-diagnostika/1.0'})
    try:
        r = urllib.request.urlopen(req, timeout=20)
        return r.status, r.headers, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode('utf-8', 'replace')


def evaluate(base):
    s = json.loads(SETTINGS.read_text(encoding='utf-8'))
    d, u = s['dir'], f"{base.rstrip('/')}/{s['dir']}"

    status, _, body = get(f"{u}/info.php?k={s['token']}")
    print(f'info.php → {status}')
    if status == 200:
        print(body)

    def line(label, verdict):
        print(f'  {label:44} {verdict}')

    print('\nSchopnosti hostingu:')
    st, _, b = get(f'{u}/t-errordoc/neexistuje')
    line('.htaccess se čte + ErrorDocument', 'ANO' if st == 404 and 'mh42-errordoc-ok' in b else f'NE ({st})')
    for path in ('neexistuje.php', 'neexistuje/'):
        st, _, b = get(f'{u}/t-php404/{path}')
        line(f'vlastní 404 přes PHP pro /{path}', 'ANO' if st == 404 and 'mh42-php404-ok' in b else f'NE ({st}: {b[:60]!r})')
    st, _, b = get(f'{u}/t-options/')
    line('Options -Indexes', {403: 'ANO (výpis zakázán)', 500: 'NE – HTTP 500, použít náhradní .htaccess'}.get(
        st, f'NEJASNÉ ({st}' + (', vypisuje obsah složky' if 'a.txt' in b else '') + ')'))
    st, _, b = get(f'{u}/t-403/')
    line('zakázaný výpis → naše 404', 'ANO' if st == 404 and 'mh42-php404-ok' in b else f'NE ({st})')
    st, _, b = get(f'{u}/t-dirindex/')
    line('DirectoryIndex s adresou 404 (náhrada Options)', 'ANO' if st == 404 and 'mh42-php404-ok' in b else f'NE ({st})')
    st, h, _ = get(f'{u}/t-headers/a.css')
    line('mod_headers', 'ANO' if h.get('X-Mh42-Test') == 'headers-ok' else 'NE (hlavička nedorazila)')
    line('  Cache-Control statického CSS', f'{h.get("Cache-Control")} (cf-cache-status: {h.get("cf-cache-status")})')
    st, h, _ = get(f'{u}/t-mime/x.woff2')
    line('AddType font/woff2', h.get('Content-Type'))
    st, h, _ = get(f'{u}/t-mime-bez/x.woff2')
    line('  woff2 bez AddType (výchozí serveru)', h.get('Content-Type'))
    st, _, b = get(f'{u}/t-deny/tajne.txt')
    line('zákaz přístupu ke složce (varianta B)', 'ANO' if st != 200 and 'mh42-tajne' not in b else 'NE – SOUBOR JE DOSTUPNÝ')
    st, _, b = get(f'{u}/t-rewrite/x')
    line('mod_rewrite (informativně)', 'ANO' if 'mh42-rewrite-ok' in b else f'NE ({st})')
    st, h, b = get(f'{u}/hlavicky.php')
    print('\nHlavičky z PHP po průchodu Cloudflare:')
    for name in ('Content-Security-Policy', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy',
                 'Permissions-Policy', 'Cache-Control', 'X-Powered-By', 'Set-Cookie', 'Strict-Transport-Security',
                 'Server', 'cf-cache-status'):
        line(name, h.get(name) or '—')


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'pripravit':
        prepare()
    elif cmd == 'vyhodnotit':
        evaluate(sys.argv[2] if len(sys.argv) > 2 else 'https://mh42.cz')
    else:
        sys.exit(__doc__)
