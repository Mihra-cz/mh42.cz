#!/usr/bin/env python3
"""Technická kontrola nasazeného webu přes HTTP(S) – stavy, hlavičky, assety, cache, přesměrování.

Počasí se kontroluje bez vlastního dotazu na Open-Meteo: úvodní stránka a /api/pocasi/ pro
výchozí místo berou data z cache serveru; neplatné vstupy endpoint odmítne dřív, než by se ptal API.

Cache-Control se vyhodnocuje ze všech výskytů hlavičky (tools/hlavicky.py) – WEDOS
k dynamickým odpovědím přidává vlastní „max-age=300“, rozhoduje význam direktiv, ne pořadí.

Spuštění:
  python3 tools/overeni-nasazeni.py                      # produkce https://mh42.cz
  python3 tools/overeni-nasazeni.py http://localhost:8080 --lokalne   # bez HTTPS/www/Cloudflare kontrol
"""
import hashlib, json, re, secrets, struct, sys, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path

from hlavicky import api_cache_ok, cache_text, html_cache_ok, static_cache_ok
import xml.etree.ElementTree as ET
from datetime import datetime
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


def fetch(url, data=None, headers=None):
    req = urllib.request.Request(url, data=data, headers={'User-Agent': 'mh42-overeni/1.0', **(headers or {})})
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
pages = {'/': 'Osobní web o', '/o-mne/': 'O&nbsp;mně', '/projekty/': 'Projekty', '/poznamky/': 'Poznámky'}
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
for path in ('/src/', '/src/bootstrap.php', '/src/helpers.php', '/src/templates/page-start.php', '/src/weather.php',
             '/config/', '/config/app.php', '/config/asset-versions.php', '/content/', '/content/projects.json',
             '/var/', '/var/.htaccess', '/var/weather/', '/var/weather/budget.json', '/api/',
             '/.htaccess', '/public/', '/public/index.php'):
    status, _, body = fetch(BASE + path)
    leak = any(s in body for s in (b'<?php', b'base_url', b'"projects"', b'ErrorDocument', b'"fetched_at"', b'"minute"'))
    report(status in (403, 404) and not leak, f'{path} → {status}' + (' – OBSAH UNIKÁ' if leak else ''))

# ---------- hlavičky ----------
section('Bezpečnostní hlavičky a cache HTML (/)')
csp = home_headers.get('Content-Security-Policy', '')
report("default-src 'self'" in csp and "frame-ancestors 'none'" in csp and "object-src 'none'" in csp, f'CSP: {csp}')
report(home_headers.get('X-Content-Type-Options') == 'nosniff', 'X-Content-Type-Options: nosniff')
report(home_headers.get('Referrer-Policy') == 'strict-origin-when-cross-origin', f'Referrer-Policy: {home_headers.get("Referrer-Policy")}')
report(home_headers.get('Permissions-Policy') == 'camera=(), microphone=(), geolocation=(self), payment=(), usb=()',
       f'Permissions-Policy: {home_headers.get("Permissions-Policy")}')
report(home_headers.get('X-Frame-Options') == 'DENY', 'X-Frame-Options: DENY')
report(home_headers.get('X-Powered-By') is None, f'X-Powered-By: {home_headers.get("X-Powered-By") or "neposílá se"}')
report(html_cache_ok(home_headers), f'HTML Cache-Control: {cache_text(home_headers)} (vyžaduje no-cache, přidaný max-age nevadí)')
if home_headers.get('Expires'):
    report(True, f'Expires (přidává hosting): {home_headers.get("Expires")}', info=True)
hsts = home_headers.get('Strict-Transport-Security')
report(not hsts or 'includesubdomains' not in hsts.lower(), f'HSTS: {hsts or "zatím není (záměrně)"}')

# ---------- assety ----------
section('Assety (verze v adrese = otisk obsahu, typ, cache)')
TYPES = {'css': 'text/css', 'js': 'javascript', 'woff2': 'font/woff2', 'svg': 'image/svg+xml', 'png': 'image/png', 'ico': 'icon'}
report('rel="manifest" href="/manifest.webmanifest"' in html_home, 'odkaz na manifest v <head>')
report(f'type="application/atom+xml" title="mh42.cz – Poznámky" href="{PROD}/feed.atom"' in html_home, 'Atom autodiscovery v <head>')
LONG = ('css', 'js', 'woff2')


def check_asset(url, version_len=None):
    status, headers, body = fetch(url)
    ext = urllib.parse.urlparse(url).path.rsplit('.', 1)[-1]
    ctype = headers.get('Content-Type', '')
    cache = cache_text(headers)
    ok = status == 200 and TYPES.get(ext, '') in ctype
    ok = ok and (static_cache_ok(headers, 31536000, immutable=True) if ext in LONG else static_cache_ok(headers, 604800))
    note = ''
    query = urllib.parse.parse_qs(urllib.parse.urlparse(url).query).get('v', [''])[0]
    if version_len and query:
        same = query == hashlib.sha1(body).hexdigest()[:version_len]
        ok = ok and same
        note = ', verze sedí s obsahem' if same else ', VERZE NESEDÍ S OBSAHEM'
    report(ok, f'{urllib.parse.urlparse(url).path} → {status}, {ctype}, {cache}{note}')
    return body


# manifest se kontroluje zvlášť níže
refs = [r for r in re.findall(r'<(?:link|script)[^>]+(?:href|src)="(/[^"]+)"', html_home) if r != '/manifest.webmanifest']
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

section('Atom feed (/feed.atom)')
status, headers, body = fetch(BASE + '/feed.atom')
report(status == 200 and 'application/atom+xml' in headers.get('Content-Type', '') and static_cache_ok(headers, 3600),
       f'/feed.atom → {status}, {headers.get("Content-Type")}, {cache_text(headers)}')
ATOM = '{http://www.w3.org/2005/Atom}'
try:
    root = ET.fromstring(body)
    feed_ok = root.tag == ATOM + 'feed'
except ET.ParseError as exc:
    root, feed_ok = None, False
    report(False, f'feed.atom není platné XML: {exc}')
if feed_ok:
    def rfc3339(value):
        try:
            return datetime.fromisoformat(value.replace('Z', '+00:00')).tzinfo is not None
        except ValueError:
            return False
    one = lambda parent, tag: parent.findall(ATOM + tag)
    self_links = [l.get('href') for l in one(root, 'link') if l.get('rel') == 'self']
    alt_links = [l.get('href') for l in one(root, 'link') if l.get('rel') == 'alternate']
    report(len(one(root, 'id')) == 1 and len(one(root, 'title')) == 1 and len(one(root, 'updated')) == 1
           and rfc3339(one(root, 'updated')[0].text) and len(one(root, 'author')) >= 1,
           'feed: právě jedno id, title a updated (RFC 3339), author')
    report(self_links == [PROD + '/feed.atom'] and alt_links == [PROD + '/poznamky/'], f'feed: self {self_links}, alternate {alt_links}')
    entries = one(root, 'entry')
    entry_ids = [e.findtext(ATOM + 'id') for e in entries]
    report(entries and len(set(entry_ids)) == len(entry_ids), f'feed: {len(entries)} položek, unikátní id')
    for e in entries:
        href = next((l.get('href') for l in e.findall(ATOM + 'link') if l.get('rel') == 'alternate'), '')
        ok = all(e.findtext(ATOM + t) for t in ('title', 'id', 'summary')) and rfc3339(e.findtext(ATOM + 'published') or '') \
            and rfc3339(e.findtext(ATOM + 'updated') or '') and href.startswith(PROD + '/poznamky/')
        s, _, _ = fetch(href.replace(PROD, BASE) if LOCAL else href)
        report(ok and s == 200, f'  {e.findtext(ATOM + "title")} → {s}')

section('Web app manifest (/manifest.webmanifest)')
status, headers, body = fetch(BASE + '/manifest.webmanifest')
report(status == 200 and 'application/manifest+json' in headers.get('Content-Type', ''), f'/manifest.webmanifest → {status}, {headers.get("Content-Type")}')
try:
    manifest = json.loads(body)
except ValueError as exc:
    manifest = {}
    report(False, f'manifest není platný JSON: {exc}')
report(all(manifest.get(k) for k in ('id', 'name', 'short_name', 'start_url', 'scope', 'display', 'lang', 'theme_color', 'background_color', 'icons')),
       f'manifest: {manifest.get("short_name")}, display {manifest.get("display")}, lang {manifest.get("lang")}')
report(manifest.get('id') == '/' and manifest.get('start_url') == '/' and manifest.get('scope') == '/',
       f'manifest: id {manifest.get("id")!r}, start_url {manifest.get("start_url")!r}, scope {manifest.get("scope")!r}')
for item in manifest.get('icons', []):
    s, h, b = fetch(BASE + item['src'])
    size = struct.unpack('>II', b[16:24]) if b[:8] == b'\x89PNG\r\n\x1a\n' else (0, 0)
    purpose = item.get('purpose', 'any')
    opaque = len(b) > 25 and b[25] in (0, 2)   # PNG bez alfa kanálu
    report(s == 200 and f'{size[0]}x{size[1]}' == item['sizes'] and 'image/png' in h.get('Content-Type', '')
           and (purpose != 'maskable' or opaque),
           f'  {item["src"]} ({purpose}) → {s}, {size[0]}x{size[1]}' + (', neprůhledná' if opaque else ''))

section('Počasí (bez vlastního dotazu na Open-Meteo)')
config = (Path(__file__).resolve().parent.parent / 'config' / 'app.php').read_text(encoding='utf-8')
weather_block = re.search(r"'weather'\s*=>\s*\[(.*?)\n    \],", config, re.S)
weather_on = bool(weather_block and re.search(r"'enabled'\s*=>\s*true", weather_block.group(1)))
lat = float(re.search(r"'latitude'\s*=>\s*([-0-9.]+)", weather_block.group(1)).group(1)) if weather_block else 0.0
lon = float(re.search(r"'longitude'\s*=>\s*([-0-9.]+)", weather_block.group(1)).group(1)) if weather_block else 0.0
tags = re.findall(r'<(?:script|link)\b[^>]*>', html_home)
loaded = [re.search(r'(?:src|href)="([^"]+)"', t).group(1) for t in tags
          if re.search(r'<script|rel="(?:stylesheet|icon|apple-touch-icon|manifest|preload|modulepreload)"', t)]
report(loaded and all(u.startswith('/') for u in loaded), f'prohlížeč načítá jen z vlastního webu ({len(loaded)} souborů z <head>)')
report(html_home.count('open-meteo') == html_home.count('href="https://open-meteo.com/"'), 'Open-Meteo je v HTML jen jako odkaz atribuce')
if weather_on:
    section_ok = 'data-weather>' in html_home and '<h2 id="pocasi" class="section__title">Počasí</h2>' in html_home
    report(section_ok, 'úvodní stránka: sekce Počasí' + ('' if section_ok else ' CHYBÍ – server nemá data (curl/odchozí HTTPS, var/)'))
    report('Data: <a class="ext" href="https://open-meteo.com/">Open-Meteo</a>' in html_home, 'atribuce „Data: Open-Meteo“ s odkazem')
    coords = {f'{lat:.3f}', f'{lon:.3f}', f'{lat:.2f}', f'{lon:.2f}', str(lat), str(lon)}
    report(not any(c in html_home for c in coords), 'v HTML nejsou souřadnice výchozího místa')
    report(re.search(r'<script src="/assets/js/weather\.js\?v=[0-9a-f]+" defer>', html_home) is not None, 'weather.js (defer, verze v adrese)')
    stamps = []
    for _ in range(2):
        _, _, body = fetch(BASE + '/')
        m = re.search(r'<p class="weather__updated">(aktualizováno|data z) <time datetime="([^"]+)">([^<]+)</time>', body.decode('utf-8', 'replace'))
        stamps.append(m.groups() if m else None)
        time.sleep(2)
    report(stamps[0] and stamps[0] == stamps[1], 'cache počasí funguje (dvě načtení → stejný čas dat, var/ je zapisovatelná)'
           if stamps[0] and stamps[0] == stamps[1] else f'CACHE NEFUNGUJE – zkontroluj oprávnění var/ ({stamps})')
    report(bool(stamps[0]) and stamps[0][0] == 'aktualizováno', f'čerstvá data: {" ".join(stamps[0][::2]) if stamps[0] else "—"}'
           + ('' if stamps[0] and stamps[0][0] == 'aktualizováno' else ' – Open-Meteo teď neodpovídá serveru'))

    api, json_type = BASE + '/api/pocasi/', {'Content-Type': 'application/json'}
    status, headers, _ = fetch(api)
    report(status == 405 and headers.get('Allow') == 'POST', f'GET /api/pocasi/ → {status}')
    status, _, _ = fetch(api, b'{"lat":49.22,"lon":15.88}', {'Content-Type': 'text/plain'})
    report(status == 415, f'POST text/plain → {status}')
    status, headers, body = fetch(api, b'{"lat":999,"lon":0}', json_type)
    report(status == 400 and api_cache_ok(headers) and 'application/json' in headers.get('Content-Type', ''),
           f'neplatné souřadnice → {status}, {headers.get("Content-Type")}, {cache_text(headers)}')
    status, _, _ = fetch(api, b'{"lat":49.22,"lon":15.88}', {**json_type, 'Sec-Fetch-Site': 'cross-site'})
    report(status == 403, f'požadavek z cizího webu → {status}')
    status, headers, body = fetch(api, json.dumps({'lat': lat, 'lon': lon}).encode(), json_type)
    reply = json.loads(body) if status == 200 else {}
    report(status == 200 and '°C' in reply.get('html', '') and api_cache_ok(headers),
           f'výchozí místo přes /api/pocasi/ (z cache) → {status}, {cache_text(headers)}')
    status, _, _ = fetch(BASE + '/api/mista/')
    report(status == 405, f'GET /api/mista/ → {status}')
    status, _, _ = fetch(BASE + '/api/mista/', b'{"q":"B"}', json_type)
    report(status == 400, f'příliš krátký dotaz na místo → {status} (na API se neposílá)')
else:
    report('data-weather' not in html_home and 'weather.js' not in html_home, 'počasí vypnuté: úvodní stránka bez sekce a bez skriptu')

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
