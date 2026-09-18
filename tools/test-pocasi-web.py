#!/usr/bin/env python3
"""Integrační test počasí: endpointy přes HTTP, HTTP klient a prohlížeč (headless Chromium).

Skutečné Open-Meteo se nevolá. Místo něj běží lokální náhrada (127.0.0.1:8791), která
vrací odpovědi z tools/testdata/open-meteo/ a zapisuje, na co se server ptal. Web běží
v dočasné kopii (PHP 8.3 v Dockeru, síť hostitele) s config/local.php, který mění jen
adresy API – repozitář ani jeho var/ se nemění.

Kontroluje: výchozí Třebíč bez JavaScriptu, hledání (víc výsledků, jeden, žádný, chyba API),
výběr, uložení volby v localStorage, návrat na Třebíč, polohu zařízení (povolená, odmítnutá,
nedostupná, timeout, nepodporovaná), zaokrouhlení souřadnic před odesláním, validaci
endpointů, oddělenou cache pro místa, Permissions-Policy a to, že prohlížeč komunikuje
jen se stejným původem. Snímky obrazovky ukládá do zadané složky.

Potřebuje: docker (php:8.3-cli-alpine), chromium, Python s balíčkem websockets.

  python3 tools/test-pocasi-web.py [--snimky SLOŽKA]
  python3 tools/test-pocasi-web.py --nahled     # jen spustí web s náhradou Open-Meteo na
                                                # http://127.0.0.1:8092 (ukončí Ctrl+C)
"""
import asyncio, base64, copy, hashlib, itertools, json, shutil, signal, subprocess, sys, tempfile, threading, time
import urllib.error, urllib.parse, urllib.request
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from zoneinfo import ZoneInfo

import websockets

sys.path.insert(0, str(Path(__file__).resolve().parent))
from hlavicky import api_cache_ok   # Cache-Control ze všech výskytů hlavičky, ne jen z první

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / 'tools' / 'testdata' / 'open-meteo'
STUB_PORT, WEB_PORT, CDP_PORT = 8791, 8092, 9224
STUB = f'http://127.0.0.1:{STUB_PORT}'
WEB = f'http://127.0.0.1:{WEB_PORT}'
CONTAINER = 'mh42-test-pocasi'
SHOTS = Path(sys.argv[sys.argv.index('--snimky') + 1]) if '--snimky' in sys.argv else None

results = {'ok': 0, 'chyba': 0}


def check(ok, name):
    results['ok' if ok else 'chyba'] += 1
    print(('  ok     ' if ok else '  CHYBA  ') + name)
    return ok


def section(title):
    print(f'\n{title}')


# ---------- náhrada Open-Meteo ----------

stub_log = []   # (cesta, dotaz) – na co se ptal server
FORECAST = json.loads((DATA / 'forecast-trebic.json').read_text(encoding='utf-8'))
TEMPERATURES = {'49.22,15.88': 16.1, '49.20,16.61': 18.9, '49.40,15.59': 11.4, '50.09,14.42': 13.7}
PLACES = {
    'Brno': json.loads((DATA / 'geocoding-brno.json').read_text(encoding='utf-8')),
    'Jihlava': {'results': [{'name': 'Jihlava', 'latitude': 49.3961, 'longitude': 15.59124, 'country': 'Česko', 'admin1': 'Vysočina'}]},
}


def forecast_for(key):
    """Fixture s dny od dneška (pražský čas) a teplotou podle místa."""
    data = copy.deepcopy(FORECAST)
    today = datetime.now(ZoneInfo('Europe/Prague')).date()
    data['daily']['time'] = [(today + timedelta(days=i)).isoformat() for i in range(3)]
    data['current']['temperature_2m'] = TEMPERATURES[key]
    return data


class StubHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        url = urllib.parse.urlparse(self.path)
        query = urllib.parse.parse_qs(url.query)
        stub_log.append((url.path, {k: v[0] for k, v in query.items()}))
        if url.path == '/v1/forecast':
            key = f"{query['latitude'][0]},{query['longitude'][0]}"
            if key not in TEMPERATURES:
                return self.reply(500, b'{"error":true,"reason":"test"}')
            return self.reply(200, json.dumps(forecast_for(key)).encode())
        if url.path == '/v1/search':
            name = query.get('name', [''])[0]
            if name == 'Chyba':
                return self.reply(500, b'{"error":true}')
            body = PLACES.get(name, json.loads((DATA / 'geocoding-nic.json').read_text(encoding='utf-8')))
            return self.reply(200, json.dumps(body, ensure_ascii=False).encode())
        # pro test HTTP klienta
        if url.path == '/test/ok':
            return self.reply(200, b'{"ok":true}')
        if url.path == '/test/500':
            return self.reply(500, b'{"ok":false}')
        if url.path == '/test/big':
            return self.reply(200, b'x' * 200_000)
        if url.path == '/test/redirect':
            self.send_response(302)
            self.send_header('Location', '/test/ok')
            self.send_header('Content-Length', '0')
            self.end_headers()
            return None
        if url.path == '/test/slow':
            time.sleep(8)
            return self.reply(200, b'{"ok":true}')
        return self.reply(404, b'{}')

    def reply(self, status, body):
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except BrokenPipeError:
            pass   # test timeoutu: klient spojení už zavřel

    def log_message(self, *args):
        pass


def forecast_calls():
    return [q for path, q in stub_log if path == '/v1/forecast']


# ---------- web v dočasné kopii ----------

def prepare_app(tmp, enabled=True):
    app = Path(tmp) / ('app' if enabled else 'app-vypnuto')
    shutil.copytree(ROOT / 'public', app / 'public', ignore=shutil.ignore_patterns('styleguide'))
    shutil.copytree(ROOT / 'src', app / 'src')
    shutil.copytree(ROOT / 'content', app / 'content')
    (app / 'config').mkdir()
    shutil.copy2(ROOT / 'config' / 'app.php', app / 'config' / 'app.php')
    (app / 'tools').mkdir()
    shutil.copy2(ROOT / 'tools' / 'dev-router.php', app / 'tools' / 'dev-router.php')
    # Stejné nastavení jako produkce, jen adresy API vedou na lokální náhradu.
    (app / 'config' / 'local.php').write_text(f"""<?php
$weather = (require __DIR__ . '/app.php')['weather'];
$weather['forecast_url'] = '{STUB}/v1/forecast';
$weather['geocoding_url'] = '{STUB}/v1/search';
$weather['enabled'] = {'true' if enabled else 'false'};
return ['weather' => $weather];
""", encoding='utf-8')
    return app


def start_web(app):
    subprocess.run(['docker', 'rm', '-f', CONTAINER], capture_output=True)
    uid = f'{subprocess.check_output(["id", "-u"]).decode().strip()}:{subprocess.check_output(["id", "-g"]).decode().strip()}'
    subprocess.run(['docker', 'run', '-d', '--rm', '--name', CONTAINER, '--network', 'host', '--user', uid,
                    '-e', 'PHP_CLI_SERVER_WORKERS=4', '-v', f'{app}:/app', '-w', '/app', 'php:8.3-cli-alpine',
                    'php', '-S', f'127.0.0.1:{WEB_PORT}', '-t', 'public', 'tools/dev-router.php'], check=True, capture_output=True)
    for _ in range(100):
        try:
            urllib.request.urlopen(WEB + '/robots.txt', timeout=1)
            return
        except Exception:
            time.sleep(0.1)
    raise RuntimeError('PHP server se nespustil')


def stop_web():
    subprocess.run(['docker', 'rm', '-f', CONTAINER], capture_output=True)


def request(path, method='GET', body=None, headers=None):
    data = body if isinstance(body, bytes) or body is None else json.dumps(body).encode()
    req = urllib.request.Request(WEB + path, data=data, method=method, headers=headers or {})
    try:
        resp = urllib.request.urlopen(req, timeout=20)
        return resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as err:
        return err.code, err.headers, err.read()


def post_json(path, body, **headers):
    return request(path, 'POST', body, {'Content-Type': 'application/json', **headers})


def cache_names(app):
    folder = app / 'var' / 'weather'
    return sorted(p.name for p in folder.iterdir()) if folder.is_dir() else []


def sha(text):
    return hashlib.sha256(text.encode()).hexdigest()


# ---------- HTTP testy ----------

def http_tests(app):
    section('Úvodní stránka a hlavičky (HTTP)')
    status, headers, body = request('/')
    html = body.decode()
    check(status == 200 and 'id="pocasi"' in html and 'Třebíč' in html and '16,1\u00a0°C' in html, 'úvodní stránka: sekce Počasí pro Třebíč')
    check(headers.get('Permissions-Policy') == 'camera=(), microphone=(), geolocation=(self), payment=(), usb=()',
          f'Permissions-Policy: {headers.get("Permissions-Policy")}')
    check('Data: <a class="ext" href="https://open-meteo.com/">Open-Meteo</a>' in html, 'atribuce „Data: Open-Meteo“ s odkazem')
    check('49.2' not in html and '15.8' not in html and '49,2' not in html, 'v HTML nejsou souřadnice')
    check(not headers.get_all('Set-Cookie'), 'žádné cookies')
    calls = forecast_calls()
    check(len(calls) == 1 and calls[0] == {
        'latitude': '49.22', 'longitude': '15.88',
        'current': 'temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m',
        'daily': 'weather_code,temperature_2m_min,temperature_2m_max',
        'timezone': 'Europe/Prague', 'temperature_unit': 'celsius', 'wind_speed_unit': 'kmh', 'forecast_days': '3'},
        'server se Open-Meteo zeptal jednou a jen na zobrazované údaje')
    request('/')
    check(len(forecast_calls()) == 1, 'druhé načtení: z cache, bez dotazu na API')
    status, _, body = request('/o-mne/')
    check(status == 200 and 'Počasí na úvodní stránce načítá server z&nbsp;Open-Meteo a&nbsp;drží ho 15&nbsp;minut v&nbsp;cache; '
          'prohlížeč komunikuje jen s&nbsp;mh42.cz.' in body.decode(), 'O mně: věta o počasí v „Jak tenhle web běží“')

    section('POST /api/pocasi/ – validace')
    status, headers, body = request('/api/pocasi/')
    check(status == 405 and headers.get('Allow') == 'POST' and 'application/json' in headers.get('Content-Type', ''), f'GET → {status}, Allow: POST')
    for name, raw, ctype, expect in (
            ('text/plain', b'{"lat":49.22,"lon":15.88}', 'text/plain', 415),
            ('formulář', b'lat=49.22&lon=15.88', 'application/x-www-form-urlencoded', 415),
            ('nevalidní JSON', b'{"lat":', 'application/json', 400),
            ('JSON pole', b'[49.22, 15.88]', 'application/json', 400),
            ('příliš velké tělo', b'{"lat":49.22,"lon":15.88,"x":"' + b'a' * 2000 + b'"}', 'application/json', 413)):
        status, _, _ = request('/api/pocasi/', 'POST', raw, {'Content-Type': ctype})
        check(status == expect, f'{name} → {status}')
    for body in ({'lat': 91, 'lon': 15}, {'lat': 49.2, 'lon': -180.5}, {'lat': '49.22', 'lon': '15.88'}, {'lat': None, 'lon': 15},
                 {}, {'lat': 49.22}, {'lat': True, 'lon': 1}, {'lat': [49], 'lon': 15}):
        status, headers, raw = post_json('/api/pocasi/', body)
        check(status == 400 and json.loads(raw) == {'error': 'coordinates'} and api_cache_ok(headers),
              f'neplatné souřadnice {json.dumps(body)} → {status}')
    status, _, _ = request('/api/pocasi/', 'POST', b'{"lat":1e999,"lon":15}', {'Content-Type': 'application/json'})
    check(status == 400, f'nekonečno (1e999) → {status}')
    status, _, _ = post_json('/api/pocasi/', {'lat': 49.22, 'lon': 15.88}, **{'Sec-Fetch-Site': 'cross-site'})
    check(status == 403, f'požadavek z cizího webu (Sec-Fetch-Site: cross-site) → {status}')

    section('POST /api/pocasi/ – data a cache')
    before = len(forecast_calls())
    status, headers, raw = post_json('/api/pocasi/', {'lat': 49.22, 'lon': 15.88})
    reply = json.loads(raw)
    check(status == 200 and '16,1\u00a0°C' in reply['html'] and reply['stale'] is False and len(forecast_calls()) == before,
          'Třebíč: data ze společné cache s úvodní stránkou')
    check(headers.get('Content-Type') == 'application/json; charset=utf-8' and api_cache_ok(headers)
          and headers.get('X-Robots-Tag') == 'noindex' and not headers.get_all('Set-Cookie'), 'JSON, no-store, noindex, bez cookies')
    status, _, raw = post_json('/api/pocasi/', {'lat': 49.19522, 'lon': 16.60796})
    check(status == 200 and '18,9\u00a0°C' in json.loads(raw)['html'], 'Brno (nezaokrouhlené souřadnice) → vlastní data')
    last = forecast_calls()[-1]
    check(last['latitude'] == '49.20' and last['longitude'] == '16.61' and last['timezone'] == 'auto',
          'server poslal Open-Meteo jen 49.20 / 16.61 (zaokrouhleno), timezone=auto')
    post_json('/api/pocasi/', {'lat': 49.2, 'lon': 16.61})
    check(forecast_calls()[-1] is last and len(forecast_calls()) == before + 1, '49.2 / 16.61 = stejné místo → z cache')
    status, _, raw = post_json('/api/pocasi/', {'lat': 10, 'lon': 10})
    check(status == 503 and json.loads(raw) == {'error': 'unavailable'}, 'chyba API a žádná cache → 503 unavailable')
    names = cache_names(app)
    check(sha('49.22,15.88') + '.json' in names and sha('49.20,16.61') + '.json' in names and sha('10.00,10.00') + '.fail' in names,
          'var/weather: oddělené soubory pro Třebíč a Brno, značka chyby pro 10/10')
    check(all(n == 'budget.json' or len(n.split('.')[0]) == 64 for n in names), 'názvy souborů jsou jen otisky souřadnic')
    texts = ''.join((app / 'var' / 'weather' / n).read_text(encoding='utf-8') for n in names)
    check('49.2' not in texts and '16.61' not in texts and '15.88' not in texts, 'cache neobsahuje souřadnice')

    section('POST /api/mista/')
    status, headers, raw = post_json('/api/mista/', {'q': 'Brno'})
    reply = json.loads(raw)
    check(status == 200 and len(reply['results']) == 5 and reply['results'][0] == {
        'label': 'Brno', 'detail': 'Jihomoravský · Česko', 'latitude': 49.2, 'longitude': 16.61}, 'Brno → 5 kandidátů, souřadnice na 2 desetinná místa')
    last = [q for p, q in stub_log if p == '/v1/search'][-1]
    check(last == {'name': 'Brno', 'count': '5', 'language': 'cs', 'format': 'json'}, 'Geocoding: name, count=5, language=cs, format=json (bez omezení na ČR)')
    check(api_cache_ok(headers), 'odpověď no-store')
    status, _, raw = post_json('/api/mista/', {'q': 'Xqzvyk'})
    check(status == 200 and json.loads(raw) == {'results': []}, 'nic nenalezeno → prázdný seznam')
    status, _, _ = post_json('/api/mista/', {'q': 'Chyba'})
    check(status == 503, f'chyba Geocoding API → {status}')
    for q in ('B', 'a' * 61, '<script>', 'Brno&count=100', 'http://x', 5, None):
        status, _, _ = post_json('/api/mista/', {'q': q})
        check(status == 400, f'neplatný dotaz {json.dumps(q)} → {status}')
    check(not [p for p, q in stub_log if p == '/v1/search' and q['name'] in ('B', '<script>')], 'neplatné dotazy se na API vůbec neposílají')


def client_tests(app):
    section('HTTP klient (curl i záložní PHP streamy, proti lokální náhradě)')
    script = f"""
define('APP_ROOT', '/app'); require '/app/src/http.php'; ini_set('error_log', '/dev/null');
$out = [];
foreach (['curl' => 'remote_get_curl', 'stream' => 'remote_get_stream'] as $name => $fn) {{
    foreach (['ok', '500', 'big', 'redirect', 'slow'] as $case) {{
        $t = microtime(true);
        $r = $fn('{STUB}/test/' . $case, 65536);
        $out[$name][$case] = [$r === null ? null : strlen($r), round(microtime(true) - $t, 1)];
    }}
}}
echo json_encode($out);
"""
    out = subprocess.run(['docker', 'run', '--rm', '--network', 'host', '-v', f'{app}:/app:ro', 'php:8.3-cli-alpine', 'php', '-r', script],
                         capture_output=True, text=True, timeout=90)
    data = json.loads(out.stdout or '{}')
    for name in ('curl', 'stream'):
        r = data.get(name, {})
        check(r.get('ok', [None])[0] == 11, f'{name}: 200 → tělo odpovědi')
        check(r.get('500', [0])[0] is None, f'{name}: HTTP 500 → null')
        check(r.get('big', [0])[0] is None, f'{name}: odpověď delší než limit → null')
        check(r.get('redirect', [0])[0] is None, f'{name}: přesměrování se nesleduje → null')
        slow = r.get('slow', [0, 99])
        check(slow[0] is None and slow[1] <= 6.5, f'{name}: pomalá odpověď → timeout za {slow[1]} s')


# ---------- prohlížeč (Chrome DevTools Protocol) ----------

class Page:
    def __init__(self, ws):
        self.ws, self.ids, self.pending = ws, itertools.count(1), {}
        self.loaded = asyncio.Event()
        self.handlers = [self.on_load]

    def on_load(self, msg):
        if msg['method'] == 'Page.loadEventFired':
            self.loaded.set()

    async def listen(self):
        async for raw in self.ws:
            msg = json.loads(raw)
            if 'id' in msg and msg['id'] in self.pending:
                self.pending.pop(msg['id']).set_result(msg)
            elif 'method' in msg:
                for handler in self.handlers:
                    handler(msg)

    async def send(self, method, **params):
        i = next(self.ids)
        future = asyncio.get_running_loop().create_future()
        self.pending[i] = future
        await self.ws.send(json.dumps({'id': i, 'method': method, 'params': params}))
        msg = await asyncio.wait_for(future, 30)
        if 'error' in msg:
            raise RuntimeError(f'{method}: {msg["error"]}')
        return msg.get('result', {})

    async def eval(self, expr):
        r = await self.send('Runtime.evaluate', expression=expr, awaitPromise=True, returnByValue=True)
        if 'exceptionDetails' in r:
            raise RuntimeError(r['exceptionDetails'].get('exception', {}).get('description', r['exceptionDetails']))
        return r['result'].get('value')

    async def goto(self, url):
        self.loaded.clear()
        await self.send('Page.navigate', url=url)
        await asyncio.wait_for(self.loaded.wait(), 15)
        await self.eval('document.fonts.ready.then(() => true)')
        await asyncio.sleep(0.2)

    async def until(self, expr, timeout=8):
        end = time.monotonic() + timeout
        while time.monotonic() < end:
            try:
                if await self.eval(expr):
                    return True
            except RuntimeError:
                pass
            await asyncio.sleep(0.1)
        return False

    async def shot(self, name):
        if not SHOTS:
            return
        m = await self.send('Page.getLayoutMetrics')
        size = m['cssContentSize']
        data = (await self.send('Page.captureScreenshot', format='png', captureBeyondViewport=True,
                                clip={'x': 0, 'y': 0, 'width': size['width'], 'height': min(size['height'], 4000), 'scale': 1}))['data']
        SHOTS.mkdir(parents=True, exist_ok=True)
        (SHOTS / f'{name}.png').write_bytes(base64.b64decode(data))


# Počítadlo volání getCurrentPosition – ověří, že se o polohu nežádá při načtení stránky.
GEO_COUNTER = """(() => {
  window.__geoCalls = 0;
  if (!navigator.geolocation) return;
  const original = navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);
  navigator.geolocation.getCurrentPosition = function (ok, error, options) {
    window.__geoCalls++;
    return original(ok, error, options);
  };
})();"""

STATE = """(() => {
  const q = s => document.querySelector(s);
  return {
    location: q('[data-weather-location]')?.textContent,
    temp: q('.weather__temp')?.textContent,
    controls: !!q('[data-weather-controls]') && !q('[data-weather-controls]').hidden,
    formOpen: !!q('[data-weather-form]') && !q('[data-weather-form]').hidden,
    reset: !!q('[data-weather-reset]') && !q('[data-weather-reset]').hidden,
    geo: !!q('[data-weather-geo]') && !q('[data-weather-geo]').hidden,
    geoDisabled: !!q('[data-weather-geo]')?.disabled,
    status: q('[data-weather-status]')?.textContent,
    results: [...document.querySelectorAll('.weather__result')].map(b => b.textContent),
    stored: localStorage.getItem('mh42-pocasi'),
    busy: q('[data-weather]')?.getAttribute('aria-busy'),
    geoCalls: window.__geoCalls,
    focus: document.activeElement?.getAttribute('data-weather-toggle') !== null ? 'toggle' : document.activeElement?.id,
    expanded: q('[data-weather-toggle]')?.getAttribute('aria-expanded'),
    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
  };
})()"""


async def browser_tests(tmp):
    profile = Path(tmp) / 'chromium'
    proc = subprocess.Popen(['chromium', '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                             '--hide-scrollbars', f'--remote-debugging-port={CDP_PORT}', f'--user-data-dir={profile}', 'about:blank'],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            try:
                targets = json.load(urllib.request.urlopen(f'http://127.0.0.1:{CDP_PORT}/json/list'))
                browser_ws = json.load(urllib.request.urlopen(f'http://127.0.0.1:{CDP_PORT}/json/version'))['webSocketDebuggerUrl']
                page_ws = next(t['webSocketDebuggerUrl'] for t in targets if t['type'] == 'page')
                break
            except Exception:
                time.sleep(0.1)
        else:
            raise RuntimeError('Chromium se nespustil')
        async with websockets.connect(page_ws, max_size=64 * 1024 * 1024) as ws, websockets.connect(browser_ws) as bws:
            page, browser = Page(ws), Page(bws)
            listeners = [asyncio.create_task(page.listen()), asyncio.create_task(browser.listen())]
            await run_browser(page, browser)
            for task in listeners:
                task.cancel()
    finally:
        proc.terminate()
        proc.wait(timeout=10)


async def run_browser(page, browser):
    requests, console = [], []

    def on_event(msg):
        method, p = msg['method'], msg['params']
        if method == 'Network.requestWillBeSent':
            requests.append({'url': p['request']['url'], 'method': p['request']['method'], 'body': p['request'].get('postData')})
        elif method == 'Runtime.consoleAPICalled' and p['type'] in ('error', 'warning'):
            console.append(' '.join(str(a.get('value', a.get('description', ''))) for a in p['args']))
        elif method == 'Runtime.exceptionThrown':
            console.append('výjimka: ' + p['exceptionDetails'].get('text', ''))
        elif method == 'Log.entryAdded' and p['entry']['level'] in ('error', 'warning'):
            console.append(p['entry']['text'] + ' ' + p['entry'].get('url', ''))
    page.handlers.append(on_event)

    for domain in ('Page', 'Runtime', 'Network', 'Log'):
        await page.send(f'{domain}.enable')
    await page.send('Page.addScriptToEvaluateOnNewDocument', source=GEO_COUNTER)
    await page.send('Emulation.setDeviceMetricsOverride', width=1280, height=900, deviceScaleFactor=1, mobile=False)

    def api_posts(path):
        return [r for r in requests if r['url'] == WEB + path and r['method'] == 'POST']

    async def state():
        return await page.eval(STATE)

    async def fresh(clear=True):
        await page.goto(WEB + '/')
        if clear:
            await page.eval("localStorage.clear()")
            await page.goto(WEB + '/')

    async def search(text):
        await page.eval(f"""(() => {{
          const form = document.querySelector('[data-weather-form]');
          if (form.hidden) document.querySelector('[data-weather-toggle]').click();
          document.querySelector('[data-weather-input]').value = {json.dumps(text)};
          form.requestSubmit();
        }})()""")

    section('Prohlížeč: bez JavaScriptu')
    await page.send('Emulation.setScriptExecutionDisabled', value=True)
    await page.goto(WEB + '/')
    s = await state()
    check(s['location'] == 'Třebíč' and s['temp'] == '16,1\u00a0°C', 'bez JS: počasí pro Třebíč z prvního načtení')
    check(not s['controls'] and not s['formOpen'], 'bez JS: ovládání i formulář skryté (žádné nefunkční prvky)')
    await page.shot('bez-js-1280')
    await page.send('Emulation.setScriptExecutionDisabled', value=False)

    section('Prohlížeč: výchozí stav s JavaScriptem')
    await fresh()
    s = await state()
    check(s['location'] == 'Třebíč' and s['controls'] and not s['reset'] and not s['formOpen'], 'Třebíč, „změnit místo“, bez „zpět na Třebíč“')
    check(s['geo'], 'geolokace podporovaná → tlačítko „Použít moji polohu“ je k dispozici')
    check(s['geoCalls'] == 0, 'při načtení se o polohu nežádá (0 volání getCurrentPosition)')
    check(not api_posts('/api/pocasi/'), 'bez uložené volby se počasí znovu nenačítá (jen serverem vykreslená Třebíč)')
    await page.shot('vychozi-1280-svetly')

    section('Prohlížeč: hledání města')
    await page.eval("document.querySelector('[data-weather-toggle]').click()")
    s = await state()
    check(s['formOpen'] and s['expanded'] == 'true' and s['focus'] == 'pocasi-misto', '„změnit místo“ otevře formulář a přesune fokus do pole')
    await search('Brno')
    await page.until("document.querySelectorAll('.weather__result').length > 0")
    s = await state()
    check(len(s['results']) == 5 and s['results'][0] == 'Brno · Jihomoravský · Česko', f'víc výsledků → seznam 5 kandidátů ({s["results"][:2]}…)')
    check(s['status'].startswith('Nalezená místa: 5'), f'hlášení: {s["status"]}')
    await page.shot('hledani-vysledky-1280')
    await page.eval("document.querySelector('.weather__result').click()")
    await page.until("document.querySelector('[data-weather-location]').textContent === 'Brno'")
    s = await state()
    check(s['location'] == 'Brno' and s['temp'] == '18,9\u00a0°C' and not s['formOpen'] and s['reset'], 'výběr: počasí pro Brno bez reloadu, formulář se zavře')
    check(json.loads(api_posts('/api/pocasi/')[-1]['body']) == {'lat': 49.2, 'lon': 16.61}, 'na server šly jen zaokrouhlené souřadnice kandidáta')
    check(json.loads(s['stored'] or 'null') == {'label': 'Brno', 'latitude': 49.2, 'longitude': 16.61}, f'localStorage: {s["stored"]}')
    check(s['focus'] == 'toggle', 'fokus se vrátí na „změnit místo“')
    await page.shot('brno-1280')

    count = len(api_posts('/api/pocasi/'))
    await page.goto(WEB + '/')
    await page.until("document.querySelector('[data-weather-location]').textContent === 'Brno'")
    s = await state()
    check(s['location'] == 'Brno' and len(api_posts('/api/pocasi/')) == count + 1, 'po načtení stránky se použije uložená volba (Brno)')

    await page.eval("document.querySelector('[data-weather-reset]').click()")
    s = await state()
    check(s['location'] == 'Třebíč' and s['temp'] == '16,1\u00a0°C' and s['stored'] is None and not s['reset'], '„zpět na Třebíč“: Třebíč, volba smazaná')
    count = len(api_posts('/api/pocasi/'))
    await page.goto(WEB + '/')
    s = await state()
    check(s['location'] == 'Třebíč' and len(api_posts('/api/pocasi/')) == count, 'po návratu se Třebíč načítá jen ze serveru')

    await search('Xqzvyk')
    await page.until("document.querySelector('[data-weather-status]').textContent.includes('nenašlo')")
    s = await state()
    check(s['status'] == 'Místo „Xqzvyk“ se nenašlo.' and not s['results'] and s['location'] == 'Třebíč', 'žádný výsledek → neutrální hlášení')
    await search('Chyba')
    await page.until("document.querySelector('[data-weather-status]').textContent.includes('nefunguje')")
    s = await state()
    check(s['status'] == 'Hledání teď nefunguje. Zkuste to později.' and s['location'] == 'Třebíč', 'chyba Geocoding API → neutrální hlášení, místo beze změny')
    searches = len(api_posts('/api/mista/'))
    await search('B')
    s = await state()
    check(s['status'] == 'Zadejte aspoň dva znaky.' and len(api_posts('/api/mista/')) == searches, 'příliš krátký dotaz se neodešle')
    await search('Brno<')
    await page.until("document.querySelector('[data-weather-status]').textContent.includes('nejde')")
    check((await state())['status'] == 'Takový název nejde vyhledat.', 'dotaz se zakázanými znaky → server 400 → hlášení')
    # hledání z klávesnice: text do pole a Enter (skutečné události klávesnice)
    await page.eval("document.querySelector('[data-weather-input]').value = ''; document.querySelector('[data-weather-input]').focus()")
    await page.send('Input.insertText', text='Jihlava')
    await page.send('Input.dispatchKeyEvent', type='keyDown', key='Enter', code='Enter', windowsVirtualKeyCode=13, text='\r')
    await page.send('Input.dispatchKeyEvent', type='keyUp', key='Enter', code='Enter', windowsVirtualKeyCode=13)
    await page.until("document.querySelector('[data-weather-location]').textContent === 'Jihlava'")
    s = await state()
    check(s['location'] == 'Jihlava' and s['temp'] == '11,4\u00a0°C', 'Enter v poli odešle hledání; jediný výsledek se vybere rovnou')
    await page.eval("document.querySelector('[data-weather-toggle]').click()")
    await page.send('Input.dispatchKeyEvent', type='keyDown', key='Escape', code='Escape', windowsVirtualKeyCode=27)
    await page.send('Input.dispatchKeyEvent', type='keyUp', key='Escape', code='Escape', windowsVirtualKeyCode=27)
    s = await state()
    check(not s['formOpen'] and s['focus'] == 'toggle' and s['expanded'] == 'false', 'Escape zavře formulář a vrátí fokus')
    await page.eval("document.querySelector('[data-weather-reset]').click()")

    section('Prohlížeč: poloha zařízení')
    origin = WEB
    await browser.send('Browser.grantPermissions', origin=origin, permissions=['geolocation'])
    await page.send('Emulation.setGeolocationOverride', latitude=49.2244444, longitude=15.8766666, accuracy=15)
    await fresh()
    await page.eval("document.querySelector('[data-weather-toggle]').click(); document.querySelector('[data-weather-geo]').click()")
    await page.until("document.querySelector('[data-weather-location]').textContent === 'Moje poloha'")
    s = await state()
    body = api_posts('/api/pocasi/')[-1]['body']
    check(s['location'] == 'Moje poloha' and s['temp'] == '16,1\u00a0°C' and s['geoCalls'] == 1, 'povolená poloha → „Moje poloha“ (1 dotaz na polohu po kliknutí)')
    check(json.loads(body) == {'lat': 49.22, 'lon': 15.88}, f'49.2244444 / 15.8766666 → odesláno {body}')
    check(json.loads(s['stored'] or 'null') == {'label': 'Moje poloha', 'latitude': 49.22, 'longitude': 15.88}, f'localStorage jen zaokrouhleně: {s["stored"]}')
    check(not any('49.2244' in (r['body'] or '') + r['url'] or '15.8766' in (r['body'] or '') + r['url'] for r in requests),
          'přesná poloha neodešla v žádném požadavku')
    await page.goto(WEB + '/')
    await page.until("document.querySelector('[data-weather-location]').textContent === 'Moje poloha'")
    s = await state()
    check(s['location'] == 'Moje poloha' and s['geoCalls'] == 0, 'uložená poloha se po načtení použije bez nového dotazu na polohu')
    await page.eval("document.querySelector('[data-weather-reset]').click()")

    await page.send('Emulation.setGeolocationOverride', latitude=50.0888888, longitude=14.4222222, accuracy=15)
    await page.eval("document.querySelector('[data-weather-toggle]').click(); document.querySelector('[data-weather-geo]').click()")
    await page.until("document.querySelector('.weather__temp').textContent.startsWith('13,7')")
    check(json.loads(api_posts('/api/pocasi/')[-1]['body']) == {'lat': 50.09, 'lon': 14.42}, '50.0888888 / 14.4222222 → 50.09 / 14.42')
    await page.eval("document.querySelector('[data-weather-reset]').click()")

    await browser.send('Browser.setPermission', permission={'name': 'geolocation'}, setting='denied', origin=origin)
    await fresh()
    posts = len(api_posts('/api/pocasi/'))
    await page.eval("document.querySelector('[data-weather-toggle]').click(); document.querySelector('[data-weather-geo]').click()")
    await page.until("document.querySelector('[data-weather-status]').textContent.includes('není povolený')")
    s = await state()
    check(s['status'] == 'Přístup k poloze není povolený. Místo zůstává beze změny.' and s['location'] == 'Třebíč',
          'odmítnutá poloha → neutrální hlášení, Třebíč zůstává')
    check(s['geoDisabled'] and s['geoCalls'] == 1 and len(api_posts('/api/pocasi/')) == posts, 'po odmítnutí je tlačítko vypnuté (žádné opakované žádosti)')
    await page.shot('poloha-odmitnuta-1280')

    await browser.send('Browser.grantPermissions', origin=origin, permissions=['geolocation'])
    await page.send('Emulation.setGeolocationOverride')   # bez souřadnic = poloha nedostupná
    await fresh()
    await page.eval("document.querySelector('[data-weather-toggle]').click(); document.querySelector('[data-weather-geo]').click()")
    await page.until("document.querySelector('[data-weather-status]').textContent.includes('nepodařilo zjistit')")
    s = await state()
    check(s['status'] == 'Polohu se nepodařilo zjistit. Místo zůstává beze změny.' and s['location'] == 'Třebíč' and not s['geoDisabled'],
          'nedostupná poloha → hlášení, tlačítko zůstane použitelné')

    await page.eval("navigator.geolocation.getCurrentPosition = (ok, error) => { window.__geoCalls++; setTimeout(() => error({ code: 3, message: 'Timeout expired' }), 20); }")
    await page.eval("document.querySelector('[data-weather-status]').textContent = ''; document.querySelector('[data-weather-geo]').click()")
    await page.until("document.querySelector('[data-weather-status]').textContent.includes('nepodařilo zjistit')")
    s = await state()
    check(s['status'] == 'Polohu se nepodařilo zjistit. Místo zůstává beze změny.' and s['location'] == 'Třebíč', 'timeout zjišťování polohy → hlášení, Třebíč zůstává')
    await page.send('Emulation.clearGeolocationOverride')
    await browser.send('Browser.resetPermissions')

    script = (await page.send('Page.addScriptToEvaluateOnNewDocument', source='delete Navigator.prototype.geolocation;'))['identifier']
    await fresh()
    s = await state()
    check(not s['geo'] and s['controls'], 'nepodporovaná geolokace → tlačítko se nezobrazí, hledání zůstává')
    await page.send('Page.removeScriptToEvaluateOnNewDocument', identifier=script)

    section('Prohlížeč: uložené místo nejde načíst')
    await fresh()
    await page.eval("localStorage.setItem('mh42-pocasi', JSON.stringify({ label: 'Porucha', latitude: 10, longitude: 10 }))")
    await page.goto(WEB + '/')
    await page.until("document.querySelector('[data-weather-status]').textContent !== ''")
    s = await state()
    check(s['location'] == 'Třebíč' and s['status'] == 'Počasí pro místo Porucha se teď nepodařilo načíst.' and s['reset'],
          'chyba → zůstane Třebíč, neutrální hlášení, nabídka „zpět na Třebíč“')
    await page.eval("localStorage.setItem('mh42-pocasi', '{\"label\":\"X\",\"latitude\":\"49\",\"longitude\":999}')")
    posts = len(api_posts('/api/pocasi/'))
    await page.goto(WEB + '/')
    s = await state()
    check(s['location'] == 'Třebíč' and len(api_posts('/api/pocasi/')) == posts, 'poškozená uložená volba se ignoruje')
    await page.eval('localStorage.clear()')

    section('Prohlížeč: vzhled a přístupnost')
    for width, theme in ((1280, 'dark'), (375, 'light'), (375, 'dark'), (320, 'light')):
        await page.send('Emulation.setDeviceMetricsOverride', width=width, height=900, deviceScaleFactor=1, mobile=width < 600)
        await page.send('Emulation.setEmulatedMedia', media='', features=[{'name': 'prefers-color-scheme', 'value': theme}])
        await fresh(clear=False)
        await search('Brno')
        await page.until("document.querySelectorAll('.weather__result').length > 0")
        s = await state()
        check(not s['overflow'], f'{width} px {theme}: bez vodorovného posouvání (otevřený formulář a výsledky)')
        await page.eval("document.getElementById('pocasi').scrollIntoView()")
        await page.shot(f'formular-{width}-{theme}')
    await page.send('Emulation.setDeviceMetricsOverride', width=1280, height=900, deviceScaleFactor=1, mobile=False)
    await page.send('Emulation.setEmulatedMedia', media='', features=[{'name': 'forced-colors', 'value': 'active'}])
    await fresh(clear=False)
    text = await page.eval("document.querySelector('[data-weather]').innerText")
    check('Třebíč' in text and '16,1' in text and 'vlhkost' in text and 'Open-Meteo' in text, 'forced colors: celý obsah čitelný jako text')
    await page.shot('forced-colors-1280')
    await page.send('Emulation.setEmulatedMedia', media='print', features=[])
    visible = await page.eval("""(() => ['[data-weather-controls]', '[data-weather-form]'].map(s => getComputedStyle(document.querySelector(s)).display))()""")
    text = await page.eval("document.querySelector('[data-weather]').innerText")
    check(visible == ['none', 'none'] and '16,1' in text and 'aktualizováno' in text, 'tisk: bez ovládání, údaje a čas aktualizace zůstanou')
    await page.shot('tisk')
    await page.send('Emulation.setEmulatedMedia', media='', features=[])
    await page.eval("document.querySelectorAll('link[rel=stylesheet]').forEach(l => l.disabled = true)")
    text = await page.eval("document.querySelector('[data-weather]').innerText")
    check('Třebíč' in text and '16,1' in text and 'dnes' in text and 'Data: Open-Meteo' in text, 'bez CSS: údaje čitelné, bez ovládání navíc')
    await page.shot('bez-css')

    section('Prohlížeč: soukromí')
    foreign = sorted({r['url'] for r in requests if not r['url'].startswith(WEB + '/') and not r['url'].startswith('data:')})
    check(not foreign, 'prohlížeč komunikuje jen se stejným původem' + (f': {foreign}' if foreign else ''))
    check(not any('open-meteo' in r['url'] or str(STUB_PORT) in r['url'] for r in requests), 'žádný požadavek na Open-Meteo (ani na jeho náhradu)')
    fetches = {(r['method'], r['url'].replace(WEB, '')) for r in requests if r['method'] != 'GET'}
    check(fetches <= {('POST', '/api/pocasi/'), ('POST', '/api/mista/')}, f'dynamické požadavky jen POST /api/pocasi/ a /api/mista/: {sorted(fetches)}')
    cookies = (await page.send('Network.getAllCookies'))['cookies']
    check(not cookies, 'žádné cookies')
    unexpected = [c for c in console if not ('status of 503' in c or 'status of 400' in c)]
    check(not unexpected, 'konzole bez chyb (kromě očekávaných 400/503 z testů chyb)' + (f': {unexpected}' if unexpected else ''))


def preview():
    """Web s náhradou Open-Meteo pro ruční prohlížení (Brno, Jihlava, Xqzvyk, Chyba …)."""
    server = ThreadingHTTPServer(('127.0.0.1', STUB_PORT), StubHandler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
    with tempfile.TemporaryDirectory(prefix='mh42-nahled-pocasi-') as tmp:
        try:
            start_web(prepare_app(tmp))
            print(f'Náhled: {WEB}/  (Open-Meteo nahrazené lokálně, ukončí Ctrl+C)', flush=True)
            while True:
                time.sleep(3600)
        except KeyboardInterrupt:
            pass
        finally:
            stop_web()
            server.shutdown()


def main():
    if '--nahled' in sys.argv:
        return preview()
    server = ThreadingHTTPServer(('127.0.0.1', STUB_PORT), StubHandler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    with tempfile.TemporaryDirectory(prefix='mh42-test-pocasi-') as tmp:
        try:
            app = prepare_app(tmp)
            start_web(app)
            http_tests(app)
            client_tests(app)
            asyncio.run(browser_tests(tmp))

            section('Vypnuté počasí (enabled = false)')
            stop_web()
            app_off = prepare_app(tmp, enabled=False)
            start_web(app_off)
            calls = len(stub_log)
            status, _, body = request('/')
            html = body.decode()
            check(status == 200 and 'Osobní web o' in html and 'id="pocasi"' not in html and 'weather.js' not in html, 'úvodní stránka bez sekce a bez skriptu')
            status, _, _ = post_json('/api/pocasi/', {'lat': 49.22, 'lon': 15.88})
            check(status == 404, f'/api/pocasi/ → {status}')
            status, _, _ = post_json('/api/mista/', {'q': 'Brno'})
            check(status == 404 and len(stub_log) == calls, f'/api/mista/ → {status}, na API nic neodešlo')
        finally:
            stop_web()
            server.shutdown()
    print(f'\nVýsledek: {results["ok"]} ok, {results["chyba"]} chyb' + (f' (snímky: {SHOTS})' if SHOTS else ''))
    sys.exit(1 if results['chyba'] else 0)


if __name__ == '__main__':
    main()
