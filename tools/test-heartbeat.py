#!/usr/bin/env python3
"""Přijímač heartbeatu přes skutečné HTTP + kompatibilita podpisu s NexusSTATem.

Běží nad dočasnou kopií aplikace (vlastní config/local.php, testovací tajemství
a prázdné var/) a web obsluhuje vestavěný server PHP v Dockeru se čtyřmi procesy,
aby šlo testovat i souběžné požadavky. Nic se neposílá do internetu a produkční
projekt se nemění.

Podpis se nepočítá znovu podle kontraktu: tělo i hlavičku skládá přímo
`nexusstat.heartbeat` z vedlejšího projektu (encode_report, sign, load_secret),
takže test ověřuje i přesné bajty sdíleného tajemství včetně trimování souboru,
který končí novým řádkem.

Potřebuje: docker (php:8.3-cli-alpine) a zdrojové soubory NexusSTATu.

Spuštění:
  python3 tools/test-heartbeat.py
  python3 tools/test-heartbeat.py --nexusstat ../nexusstat
"""
import json, os, secrets, shutil, stat, subprocess, sys, tempfile, threading, time
import urllib.error, urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PORT = 8093
WEB = f'http://127.0.0.1:{PORT}'
CONTAINER = 'mh42-test-heartbeat'
NEXUSSTAT = Path(sys.argv[sys.argv.index('--nexusstat') + 1]).expanduser() if '--nexusstat' in sys.argv \
    else ROOT.parent / 'nexusstat'

# Testovací tajemství, nikdy produkční. 47 znaků ASCII bez mezer.
SECRET_TEXT = 'test-heartbeat-' + secrets.token_hex(16)

results = {'ok': 0, 'chyba': 0}


def check(ok, name):
    results['ok' if ok else 'chyba'] += 1
    print(('  ok     ' if ok else '  CHYBA  ') + name)
    return ok


def section(title):
    print(f'\n{title}')


# ---------- odesílač z NexusSTATu ----------

def load_sender():
    """Skutečný modul odesílače. Bez něj nemá smysl pokračovat – podpis by se jen opsal."""
    src = NEXUSSTAT / 'src'
    if not (src / 'nexusstat' / 'heartbeat.py').is_file():
        print(f'CHYBA: nenalezen {src}/nexusstat/heartbeat.py – zadej cestu přes --nexusstat')
        sys.exit(2)
    sys.path.insert(0, str(src))
    from nexusstat import heartbeat

    return heartbeat


# ---------- dočasná kopie aplikace a web ----------

def uid():
    return f'{os.getuid()}:{os.getgid()}'


def prepare_app(tmp, enabled=True):
    app = Path(tmp) / 'app'
    if app.exists():
        shutil.rmtree(app)
    shutil.copytree(ROOT / 'public', app / 'public', ignore=shutil.ignore_patterns('styleguide'))
    shutil.copytree(ROOT / 'src', app / 'src')
    shutil.copytree(ROOT / 'content', app / 'content')
    (app / 'config').mkdir()
    shutil.copy2(ROOT / 'config' / 'app.php', app / 'config' / 'app.php')
    (app / 'tools').mkdir()
    shutil.copy2(ROOT / 'tools' / 'dev-router.php', app / 'tools' / 'dev-router.php')

    # Soubor s tajemstvím končí novým řádkem – obě strany ho musí utnout stejně.
    key = app / 'config' / 'heartbeat.key'
    key.write_text(SECRET_TEXT + '\n', encoding='ascii')
    key.chmod(0o600)

    (app / 'config' / 'local.php').write_text(f"""<?php return ['heartbeat' => [
    'enabled'       => {'true' if enabled else 'false'},
    'secret_file'   => 'config/heartbeat.key',
    'state_dir'     => 'var/heartbeat',
    'project'       => 'nexusstat',
    'require_https' => false,
]];
""", encoding='utf-8')
    return app


def start_web(app):
    stop_web()
    subprocess.run(['docker', 'run', '-d', '--rm', '--name', CONTAINER, '--network', 'host', '--user', uid(),
                    '-e', 'PHP_CLI_SERVER_WORKERS=4', '-v', f'{app}:/app', '-w', '/app', 'php:8.3-cli-alpine',
                    'php', '-S', f'127.0.0.1:{PORT}', '-t', 'public', 'tools/dev-router.php'],
                   check=True, capture_output=True)
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
    req = urllib.request.Request(WEB + path, data=body, method=method, headers=headers or {})
    try:
        resp = urllib.request.urlopen(req, timeout=20)
        return resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as err:
        return err.code, err.headers, err.read()


def send(body, signature=None, content_type='application/json', method='POST', extra=None):
    """Požadavek ve stejném tvaru, jaký posílá odesílač v NexusSTATu."""
    headers = {'Connection': 'close'}
    if content_type is not None:
        headers['Content-Type'] = content_type
    if signature is not None:
        headers[hb.SIGNATURE_HEADER] = signature
    headers.update(extra or {})
    return request('/api/heartbeat/', method, body, headers)


def heartbeat_post(state, ts):
    """Tělo i podpis skládá skutečný odesílač NexusSTATu."""
    body = hb.encode_report(state, ts)
    return send(body, hb.sign(SECRET, body)), body


def state_file(app):
    path = app / 'var' / 'heartbeat' / 'state.json'
    return json.loads(path.read_text(encoding='utf-8')) if path.is_file() else None


def reset_budget(app):
    """Strop požadavků je 30 za minutu; mezi skupinami testů se počítadlo vynuluje."""
    budget = app / 'var' / 'heartbeat' / 'budget.json'
    if budget.is_file():
        budget.unlink()


def php_signature(app, body):
    """Podpis spočítaný naší PHP stranou nad stejnými bajty těla."""
    script = ('require "/app/src/bootstrap.php"; require "/app/src/heartbeat.php";'
              '$s = heartbeat_secret(["secret_file" => "/app/config/heartbeat.key"]);'
              'echo $s === null ? "CHYBA" : heartbeat_sign($s, (string) getenv("BODY"));')
    out = subprocess.run(['docker', 'run', '--rm', '--user', uid(), '-v', f'{app}:/app', '-w', '/app',
                          '-e', 'BODY=' + body.decode('ascii'), 'php:8.3-cli-alpine', 'php', '-r', script],
                         capture_output=True)
    return out.stdout.decode().strip()


def no_store(headers):
    values = headers.get_all('Cache-Control') or []
    return any('no-store' in v.lower() for v in values)


# ---------- testy ----------

def compatibility_tests(app):
    section('Kompatibilita s odesílačem NexusSTATu (skutečný heartbeat.py)')
    commit = subprocess.run(['git', '-C', str(NEXUSSTAT), 'rev-parse', 'HEAD'], capture_output=True)
    print(f'  NexusSTAT: {NEXUSSTAT}  commit {commit.stdout.decode().strip() or "—"}')

    check(SECRET == SECRET_TEXT.encode('ascii'),
          'load_secret() utne nový řádek a vrátí přesné bajty tajemství')
    body = hb.encode_report('OK', 1793000000)
    check(body == b'{"v":1,"state":"OK","ts":1793000000}', f'tělo odesílače: {body.decode()}')
    check(php_signature(app, body) == hb.sign(SECRET, body),
          'PHP i Python spočítají nad stejnými bajty stejný podpis')
    check(php_signature(app, b'{"v":1,"state":"ERROR","ts":1}') == hb.sign(SECRET, b'{"v":1,"state":"ERROR","ts":1}'),
          'stejný podpis i pro jiné tělo')
    check(len(hb.sign(SECRET, body)) == 67 and hb.sign(SECRET, body).startswith('v1='),
          'hlavička má tvar v1=<64 hex>')


def accept_tests(app):
    section('Přijatá hlášení (všech pět stavů)')
    reset_budget(app)
    ts = int(time.time()) - 60
    for i, state in enumerate(['UNKNOWN', 'OK', 'WARNING', 'ERROR', 'CRITICAL']):
        (status, headers, body), raw = heartbeat_post(state, ts + i)
        stored = state_file(app)
        check(status == 204 and body == b'' and stored['state'] == state and stored['ts'] == ts + i,
              f'{state}: odpověď 204 bez těla a uložený stav')
        if i == 0:
            check(no_store(headers), f'odpověď má Cache-Control: no-store ({headers.get("Cache-Control")})')
            check(headers.get('X-Robots-Tag') == 'noindex', 'odpověď má X-Robots-Tag: noindex')

    stored = state_file(app)
    check(sorted(stored) == ['received_at', 'state', 'ts', 'version'],
          'soubor stavu nese jen version, state, ts a received_at')
    text = (app / 'var' / 'heartbeat' / 'state.json').read_text(encoding='utf-8')
    check(SECRET_TEXT not in text and 'v1=' not in text and '127.0.0.1' not in text,
          'v souboru stavu není tajemství, podpis ani adresa odesílatele')
    check(abs(stored['received_at'] - int(time.time())) < 30, 'uložený čas přijetí je čas serveru')


def reject_tests(app):
    section('Odmítnutá hlášení')
    reset_budget(app)
    now = int(time.time())
    before = state_file(app)

    body = hb.encode_report('OK', now)
    cases = [
        ('GET místo POST', send(None, None, None, 'GET'), 405),
        ('PUT místo POST', send(body, hb.sign(SECRET, body), method='PUT'), 405),
        ('bez Content-Type', send(body, hb.sign(SECRET, body), content_type=None), 415),
        ('Content-Type: text/plain', send(body, hb.sign(SECRET, body), content_type='text/plain'), 415),
        ('bez podpisu', send(body, None), 401),
        ('prázdný podpis', send(body, ''), 401),
        ('chybný podpis', send(body, 'v1=' + '0' * 64), 401),
        ('podpis jiného těla', send(body, hb.sign(SECRET, hb.encode_report('ERROR', now))), 401),
        ('podpis jiným tajemstvím', send(body, hb.sign(b'x' * 48, body)), 401),
        ('podpis velkými písmeny', send(body, hb.sign(SECRET, body).upper()), 401),
        ('upravené tělo pod platným podpisem', send(body.replace(b'OK', b'ER'), hb.sign(SECRET, body)), 401),
    ]
    for name, (status, headers, raw), expected in cases:
        check(status == expected and raw == b'', f'{name}: {status} bez těla')

    # Podepsané, ale podle kontraktu neplatné tělo – podpis je platný, odmítnout musí kontrola těla.
    for name, text, expected in [
        ('klíč navíc', '{"v":1,"state":"OK","ts":%d,"host":"nexus"}' % now, 400),
        ('chybí v', '{"state":"OK","ts":%d}' % now, 400),
        ('v=2', '{"v":2,"state":"OK","ts":%d}' % now, 400),
        ('neznámý stav', '{"v":1,"state":"INFO","ts":%d}' % now, 400),
        ('stav malými písmeny', '{"v":1,"state":"ok","ts":%d}' % now, 400),
        ('ts jako text', '{"v":1,"state":"OK","ts":"%d"}' % now, 400),
        ('nevalidní JSON', '{"v":1,"state":"OK"', 400),
        ('prázdné tělo', '', 400),
        ('hodiny o 301 s vpřed', '{"v":1,"state":"OK","ts":%d}' % (now + 301), 400),
        ('hodiny o 301 s vzad', '{"v":1,"state":"OK","ts":%d}' % (now - 301), 400),
        ('tělo nad 256 B', '{"v":1,"state":"OK","ts":%d,"x":"%s"}' % (now, 'a' * 300), 413),
    ]:
        raw = text.encode()
        status, headers, out = send(raw, hb.sign(SECRET, raw))
        check(status == expected and out == b'', f'{name}: {status} bez těla')

    check(state_file(app) == before, 'žádné odmítnuté hlášení uložený stav nezměnilo')
    check(all(no_store(send(body, None)[1]) for _ in range(1)), 'i odmítnutí má Cache-Control: no-store')


def order_tests(app):
    section('Pořadí zpráv (opakované a starší hlášení)')
    reset_budget(app)
    now = int(time.time())
    (status, _, _), _ = heartbeat_post('OK', now)
    check(status == 204 and state_file(app)['ts'] == now, 'referenční hlášení uloženo')

    (status, _, raw), _ = heartbeat_post('CRITICAL', now)
    check(status == 409 and raw == b'' and state_file(app)['state'] == 'OK',
          'stejné ts (opakovaná zpráva): 409 a stav se nezmění')
    (status, _, _), _ = heartbeat_post('CRITICAL', now - 1)
    check(status == 409 and state_file(app)['state'] == 'OK', 'starší ts: 409 a stav se nezmění')
    (status, _, _), _ = heartbeat_post('WARNING', now + 1)
    check(status == 204 and state_file(app)['state'] == 'WARNING', 'novější ts: 204 a stav se změní')


def concurrency_tests(app):
    section('Souběžné požadavky (4 procesy PHP)')
    reset_budget(app)
    base = int(time.time()) + 10
    order = [base + i for i in (5, 0, 9, 3, 7, 1, 8, 2, 6, 4)]
    barrier = threading.Barrier(len(order))
    out = {}

    def one(ts):
        barrier.wait()
        out[ts] = heartbeat_post('OK', ts)[0][0]

    with ThreadPoolExecutor(max_workers=len(order)) as pool:
        list(pool.map(one, order))

    stored = state_file(app)
    check(stored['ts'] == max(order), f'po 10 souběžných požadavcích je uložené nejvyšší ts (+{stored["ts"] - base})')
    check(out[max(order)] == 204, 'nejvyšší ts dostalo 204')
    check(set(out.values()) <= {204, 409}, f'všechny odpovědi jsou 204 nebo 409: {sorted(set(out.values()))}')
    check(json.dumps(stored) and sorted(stored) == ['received_at', 'state', 'ts', 'version'],
          'soubor stavu je po souběhu celý a platný')

    # Starší hlášení přijaté až po novějším nesmí stav vrátit zpět.
    (status, _, _), _ = heartbeat_post('CRITICAL', base + 4)
    check(status == 409 and state_file(app)['ts'] == max(order), 'opožděné starší hlášení nepřepsalo novější')


def page_tests(app):
    section('Veřejné zobrazení na /projekty/')
    reset_budget(app)
    # Souběžné testy uložily ts několik sekund dopředu; další hlášení musí být novější.
    stored = state_file(app)
    ts = max(int(time.time()), (stored['ts'] + 1) if stored else 0)

    def publish(state):
        """Pošle hlášení a vrátí HTML stránky Projekty."""
        nonlocal ts
        ts += 1
        status, _, _ = heartbeat_post(state, ts)[0]
        check(status == 204, f'hlášení {state} (ts +{ts - int(time.time())} s) přijato: {status}')
        return request('/projekty/')

    status, headers, body = publish('ERROR')
    html = body.decode()
    check(status == 200 and '<dt>stav Nexusu</dt>' in html and '>ERROR</dd>' in html, 'stav ERROR je na stránce')
    check('project__state--problem' in html, 'závada je zvýrazněná akcentem')
    check(no_store(headers), f'stránka se stavem má Cache-Control: no-store ({headers.get("Cache-Control")})')
    check(SECRET_TEXT not in html and 'v1=' not in html and 'heartbeat.key' not in html,
          'v HTML není tajemství, podpis ani cesta k souborům')
    check('received_at' not in html and 'budget' not in html, 'v HTML nejsou vnitřní údaje o hlášení')

    for state in ['OK', 'WARNING', 'CRITICAL', 'UNKNOWN']:
        html = publish(state)[2].decode()
        check(f'>{state}</dd>' in html, f'stav {state} se zobrazí na stránce')

    # NO DATA: hlášení staršího než 900 s se nikdy nesmí ukázat jako OK.
    # Čas přijetí se počítá od skutečných hodin, ne od ts posledního hlášení.
    path = app / 'var' / 'heartbeat' / 'state.json'
    real = int(time.time())
    path.write_text(json.dumps({'version': 1, 'state': 'OK', 'ts': real - 901, 'received_at': real - 901}), encoding='utf-8')
    html = request('/projekty/')[2].decode()
    check('>NO DATA</dd>' in html and '>OK</dd>' not in html, 'hlášení starší než 900 s: NO DATA, žádné OK')
    check('project__state--unknown' in html, 'NO DATA je tlumené, ne výstražné')
    check('id="stav-nexusu"' in html and html.count('<dl class="legend">') == 2,
          'legenda stavů je na stránce vedle legendy Přístup')

    # Čas se bere až tady – mezitím proběhlo několik požadavků. „NO DATA“ je i v legendě,
    # takže se kontroluje hodnota v řádku stavu, ne výskyt řetězce kdekoli na stránce.
    real = int(time.time())
    path.write_text(json.dumps({'version': 1, 'state': 'OK', 'ts': real - 880, 'received_at': real - 880}), encoding='utf-8')
    html = request('/projekty/')[2].decode()
    check('>OK</dd>' in html and '>NO DATA</dd>' not in html, 'hranice: 880 s staré hlášení se ještě zobrazí jako OK')

    path.write_text('{"version":1,"state":"OK","ts":', encoding='utf-8')
    status, _, body = request('/projekty/')
    html = body.decode()
    check('>NO DATA</dd>' in html and '>OK</dd>' not in html, 'poškozený soubor: NO DATA, žádné OK')
    check(status == 200 and 'Warning' not in html and 'Fatal' not in html, 'stránka nehlásí chybu návštěvníkovi')

    path.unlink()
    html = request('/projekty/')[2].decode()
    check('>NO DATA</dd>' in html, 'chybějící soubor: NO DATA')

    # Ostatní stránky zůstávají beze změny.
    status, headers, body = request('/')
    check(status == 200 and '<dt>stav Nexusu</dt>' not in body.decode(),
          'úvodní stránka se nezměnila (stav je jen na /projekty/)')


def budget_tests(app):
    section('Strop požadavků')
    reset_budget(app)
    body = hb.encode_report('OK', int(time.time()))
    statuses = [send(body, 'v1=' + '0' * 64)[0] for _ in range(31)]
    check(statuses[:30] == [401] * 30, 'prvních 30 požadavků se vyhodnotí (401 u chybného podpisu)')
    check(statuses[30] == 429, f'31. požadavek ve stejné minutě: {statuses[30]} (čekáno 429)')
    reset_budget(app)
    check(send(body, 'v1=' + '0' * 64)[0] == 401, 'po vynulování počítadla se požadavky vyhodnocují dál')


def disabled_tests(tmp):
    section('Vypnutý přijímač')
    app = prepare_app(tmp, enabled=False)
    start_web(app)
    body = hb.encode_report('OK', int(time.time()))
    status, headers, raw = send(body, hb.sign(SECRET, body))
    check(status == 404 and raw == b'', 'endpoint vrací 404 jako neexistující adresa')
    check(not (app / 'var' / 'heartbeat').exists(), 'nic se nezapsalo do var/')
    status, headers, page = request('/projekty/')
    check(status == 200 and '<dt>stav Nexusu</dt>' not in page.decode(), 'na stránce Projekty není žádný stav')
    check(not no_store(headers), f'bez stavu zůstává stránce původní cache ({headers.get("Cache-Control")})')


def https_tests(tmp):
    section('Požadavek jen přes HTTPS')
    app = prepare_app(tmp, enabled=True)
    (app / 'config' / 'local.php').write_text((app / 'config' / 'local.php').read_text()
                                              .replace("'require_https' => false", "'require_https' => true"), encoding='utf-8')
    start_web(app)
    body = hb.encode_report('OK', int(time.time()))
    status, _, raw = send(body, hb.sign(SECRET, body))
    check(status == 400 and raw == b'', 'se zapnutým require_https se platné hlášení po HTTP odmítne')
    check(state_file(app) is None, 'nic se neuložilo')


if __name__ == '__main__':
    hb = load_sender()
    tmp = tempfile.mkdtemp(prefix='mh42-test-heartbeat-')
    try:
        app = prepare_app(tmp)
        SECRET = hb.load_secret(str(app / 'config' / 'heartbeat.key'))
        start_web(app)
        compatibility_tests(app)
        accept_tests(app)
        reject_tests(app)
        order_tests(app)
        concurrency_tests(app)
        page_tests(app)
        budget_tests(app)
        stop_web()
        disabled_tests(tmp)
        stop_web()
        https_tests(tmp)
    finally:
        stop_web()
        shutil.rmtree(tmp, ignore_errors=True)

    print(f"\nVýsledek: {results['ok']} ok, {results['chyba']} chyb")
    sys.exit(1 if results['chyba'] else 0)
