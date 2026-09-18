#!/usr/bin/env python3
"""Technická kontrola nasazeného webu přes HTTP(S) – stavy, hlavičky, assety, cache, přesměrování.

Spuštění:
  python3 tools/overeni-nasazeni.py                      # produkce https://mh42.cz
  python3 tools/overeni-nasazeni.py http://localhost:8080 --lokalne   # bez HTTPS/www/Cloudflare kontrol
"""
import hashlib, re, secrets, struct, sys, urllib.error, urllib.parse, urllib.request
from xml.dom import minidom

BASE = next((a for a in sys.argv[1:] if not a.startswith('--')), 'https://mh42.cz').rstrip('/')
LOCAL = '--lokalne' in sys.argv
PROD = 'https://mh42.cz'
NOT_FOUND_MARK = 'Tahle stránka tu není'
results = {'ok': 0, 'chyba': 0}
cookies = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def fetch(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'mh42-overeni/1.0'})
    try:
        resp = OPENER.open(req, timeout=20)
        status, headers, body = resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as err:
        status, headers, body = err.code, err.headers, err.read()
    cookies.extend(f'{url}: {c}' for c in headers.get_all('Set-Cookie') or [])
    return status, headers, body


def report(ok, message, info=False):
    if info:
        print(f'  info   {message}')
        return
    results['ok' if ok else 'chyba'] += 1
    print(f'  {"ok    " if ok else "CHYBA "} {message}')


def section(title):
    print(f'\n{title}')


# ---------- stránky ----------
section('Stránky')
pages = {'/': 'Osobní web o', '/o-mne/': 'O&nbsp;mně', '/projekty/': 'Projekty'}
html_home = ''
for path, mark in pages.items():
    status, headers, body = fetch(BASE + path)
    text = body.decode('utf-8', 'replace')
    report(status == 200 and mark in text and 'lang="cs"' in text,
           f'{path} → {status}, {headers.get("Content-Type")}')
    if path == '/':
        html_home, home_headers = text, headers

# ---------- 404 a interní soubory ----------
section('Neexistující adresy → 404 (vlastní stránka)')
rnd = secrets.token_hex(4)
for path in (f'/neexistuje-{rnd}/', f'/neexistuje-{rnd}.php', f'/neexistuje-{rnd}.txt', '/styleguide/',
             '/assets/', '/assets/fonts/', '/_app.php'):
    status, _, body = fetch(BASE + path)
    report(status == 404 and NOT_FOUND_MARK in body.decode('utf-8', 'replace'), f'{path} → {status}')

section('Interní soubory nesmí být dostupné')
for path in ('/src/', '/src/bootstrap.php', '/src/helpers.php', '/src/templates/page-start.php',
             '/config/', '/config/app.php', '/config/asset-versions.php', '/content/', '/content/projects.json',
             '/.htaccess', '/public/', '/public/index.php'):
    status, _, body = fetch(BASE + path)
    leak = any(s in body for s in (b'<?php', b'base_url', b'"projects"', b'ErrorDocument'))
    report(status in (403, 404) and not leak, f'{path} → {status}' + (' – OBSAH UNIKÁ' if leak else ''))

# ---------- hlavičky ----------
section('Bezpečnostní hlavičky a cache HTML (/)')
csp = home_headers.get('Content-Security-Policy', '')
report("default-src 'self'" in csp and "frame-ancestors 'none'" in csp and "object-src 'none'" in csp, f'CSP: {csp}')
report(home_headers.get('X-Content-Type-Options') == 'nosniff', 'X-Content-Type-Options: nosniff')
report(home_headers.get('Referrer-Policy') == 'strict-origin-when-cross-origin', f'Referrer-Policy: {home_headers.get("Referrer-Policy")}')
report(bool(home_headers.get('Permissions-Policy')), f'Permissions-Policy: {home_headers.get("Permissions-Policy")}')
report(home_headers.get('X-Frame-Options') == 'DENY', 'X-Frame-Options: DENY')
report(home_headers.get('X-Powered-By') is None, f'X-Powered-By: {home_headers.get("X-Powered-By") or "neposílá se"}')
cc = home_headers.get('Cache-Control', '')
report('no-cache' in cc and 'immutable' not in cc, f'HTML Cache-Control: {cc}')
hsts = home_headers.get('Strict-Transport-Security')
report(not hsts or 'includesubdomains' not in hsts.lower(), f'HSTS: {hsts or "zatím není (záměrně)"}')

# ---------- assety ----------
section('Assety (verze v adrese = otisk obsahu, typ, cache)')
TYPES = {'css': 'text/css', 'js': 'javascript', 'woff2': 'font/woff2', 'svg': 'image/svg+xml', 'png': 'image/png', 'ico': 'icon'}
LONG = ('css', 'js', 'woff2')


def check_asset(url, version_len=None):
    status, headers, body = fetch(url)
    ext = urllib.parse.urlparse(url).path.rsplit('.', 1)[-1]
    ctype = headers.get('Content-Type', '')
    cache = headers.get('Cache-Control', '')
    ok = status == 200 and TYPES.get(ext, '') in ctype
    ok = ok and (('max-age=31536000' in cache and 'immutable' in cache) if ext in LONG else 'max-age=604800' in cache)
    note = ''
    query = urllib.parse.parse_qs(urllib.parse.urlparse(url).query).get('v', [''])[0]
    if version_len and query:
        same = query == hashlib.sha1(body).hexdigest()[:version_len]
        ok = ok and same
        note = ', verze sedí s obsahem' if same else ', VERZE NESEDÍ S OBSAHEM'
    report(ok, f'{urllib.parse.urlparse(url).path} → {status}, {ctype}, {cache}{note}')
    return body


refs = re.findall(r'<(?:link|script)[^>]+(?:href|src)="(/[^"]+)"', html_home)
for ref in refs:
    body = check_asset(BASE + ref, 10 if '?v=' in ref else None)
    css_dir = ref.split('?')[0].rsplit('/', 1)[0]
    if ref.split('?')[0].endswith('.css'):
        for inner in re.findall(r'url\("?([^")]+)"?\)', body.decode('utf-8', 'replace')):
            if inner.startswith('data:'):
                continue
            path = urllib.parse.urljoin(BASE + css_dir + '/', inner)
            check_asset(path, 8 if 'pozadi/' in inner else None)

section('Náhled odkazu, robots.txt, sitemap.xml')
og = re.search(r'property="og:image" content="([^"]+)"', html_home).group(1)
og_url = og.replace(PROD, BASE) if LOCAL else og
status, headers, body = fetch(og_url)
size = struct.unpack('>II', body[16:24]) if body[:8] == b'\x89PNG\r\n\x1a\n' else None
report(status == 200 and size == (1200, 630) and og.startswith(PROD + '/'), f'og:image {og} → {status}, {size}')
status, _, body = fetch(BASE + '/robots.txt')
report(status == 200 and f'Sitemap: {PROD}/sitemap.xml' in body.decode(), f'/robots.txt → {status}')
status, _, body = fetch(BASE + '/sitemap.xml')
locs = [n.firstChild.data for n in minidom.parseString(body).getElementsByTagName('loc')] if status == 200 else []
report(status == 200 and all(u.startswith(PROD + '/') for u in locs) and locs, f'/sitemap.xml → {status}, {len(locs)} adres')
for loc in locs:
    s, _, _ = fetch(loc.replace(PROD, BASE) if LOCAL else loc)
    report(s == 200, f'  {loc} → {s}')

section('Odkazy a přesměrování')
report('href="https://github.com/Mihra-cz"' in html_home and 'target="_blank"' not in html_home,
       'GitHub odkaz https://github.com/Mihra-cz, bez target="_blank"')
status, headers, _ = fetch(BASE + '/o-mne')
loc = headers.get('Location', '')
report(status in (301, 308) and loc.endswith('/o-mne/') and '/public/' not in loc, f'/o-mne → {status} {loc}')
if not LOCAL:
    for url in ('http://mh42.cz/', 'https://www.mh42.cz/', 'http://www.mh42.cz/'):
        chain, current = [], url
        for _ in range(4):
            status, headers, _ = fetch(current)
            chain.append(f'{status}')
            if status in (301, 302, 307, 308) and headers.get('Location'):
                current = urllib.parse.urljoin(current, headers['Location'])
            else:
                break
        report(current == PROD + '/' and status == 200 and chain[0] in ('301', '308'),
               f'{url} → {" → ".join(chain)} → {current}')
    _, headers, _ = fetch(BASE + '/')
    report(True, f'server: {headers.get("Server")}, cf-cache-status HTML: {headers.get("cf-cache-status")}', info=True)

section('Cookies')
report(not cookies, 'žádné Set-Cookie' if not cookies else 'NEČEKANÉ COOKIES: ' + '; '.join(cookies))

print(f'\nVýsledek: {results["ok"]} ok, {results["chyba"]} chyb')
sys.exit(1 if results['chyba'] else 0)
