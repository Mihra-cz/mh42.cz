#!/usr/bin/env python3
"""Regresní test čistoty produkčního balíčku (bez sítě).

Produkční balíček je neměnný artefakt pro ruční upload. Když se nad ním přímo spustí
aplikace – typicky produkční Apache test – zapíše do něj běhová data (cache počasí,
budget.json, značky chyb). Takový balíček se nesmí nasadit a rozbíjí i porovnání
balíčků, ze kterého se odvozuje delta nasazení.

Testuje se:
  - pravidla čistoty (co je běhový soubor a co jen podobné jméno),
  - čistě sestavený balíček projde,
  - kontaminovaný balíček neprojde – ve složce i v ZIPu, v obou variantách,
  - izolace: aplikace spuštěná nad dočasnou kopií zapíše běhová data do kopie
    a původní balíček zůstane čistý,
  - dočasná kopie se po testu odstraní.

Spuštění:
  python3 tools/test-balicek.py                     # regresní testy (bez sítě)
  python3 tools/test-balicek.py --apache [SLOŽKA]   # produkční Apache nad dočasnou
                                                    # kopií balíčku + overeni-nasazeni.py
"""
import os, shutil, subprocess, sys, tempfile, time, urllib.request, zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import balicek
from balicek import (cesty_v_zipu, cesty_ve_slozce, kontrola_cistoty, nalezy_ve_slozce,
                     nejnovejsi_balicek, runtime_duvod, runtime_nalezy, varianty, zip_dir)

ROOT = Path(__file__).resolve().parent.parent
results = {'ok': 0, 'chyba': 0}


def section(name):
    print(f'\n{name}')


def check(ok, message):
    results['ok' if ok else 'chyba'] += 1
    print(('  ok     ' if ok else '  CHYBA ') + message)


def poznamka(text):
    print(f'  pozor  {text}')


# ---------- pomocné: syntetický balíček ----------

def synteticky_balicek(base):
    """Minimální balíček se stejným rozložením jako skutečný (obě varianty + ZIPy)."""
    out = base / 'mh42-test'
    for name, var_prefix in (('A-oddeleny-koren', ''), ('B-jeden-adresar', '')):
        v = out / name
        (v / 'src' / 'templates').mkdir(parents=True)
        (v / 'config').mkdir(parents=True)
        (v / 'var').mkdir(parents=True)
        (v / 'src' / 'weather.php').write_text('<?php // ne běhový soubor\n', encoding='utf-8')
        (v / 'src' / 'templates' / 'weather-data.php').write_text('<?php\n', encoding='utf-8')
        (v / 'config' / 'app.php').write_text('<?php return [];\n', encoding='utf-8')
        (v / 'var' / '.htaccess').write_text(balicek.DENY_HTACCESS, encoding='utf-8')
        web = v / 'public' if name.startswith('A') else v
        (web / 'assets' / 'js').mkdir(parents=True, exist_ok=True)
        (web / 'assets' / 'js' / 'weather.js').write_text('// ne běhový soubor\n', encoding='utf-8')
    for v in varianty(out):
        zip_dir(v, out / f'{v.name}.zip')
    return out


def zanes_runtime(variant):
    """Co aplikace zapíše za provozu: cache počasí, strop dotazů, značka chyby, dočasný soubor."""
    w = variant / 'var' / 'weather'
    w.mkdir(parents=True, exist_ok=True)
    (w / ('a' * 64 + '.json')).write_text('{"fetched_at":1}', encoding='utf-8')
    (w / 'budget.json').write_text('{"minute":1,"m":1,"day":"2026-10-20","d":1}', encoding='utf-8')
    (w / ('b' * 64 + '.fail')).write_text('', encoding='utf-8')
    (w / '.tmp-0123456789ab').write_text('', encoding='utf-8')


# Dočasné kopie, které tenhle nástroj vyrábí přes mkdtemp. Jen takovou složku smí uklidit.
TEMP_PREFIXY = ('mh42-apache-', 'mh42-kopie-')


def je_vlastni_kopie(cesta):
    """Je to dočasná kopie, kterou jsme sami vyrobili přes mkdtemp?

    Úmyslně úzké: absolutní cesta, není symlink, leží přímo v systémovém tempu,
    má náš prefix a není uvnitř repozitáře. Všechno ostatní – balíček v dist/,
    pracovní strom, cokoli uživatelova – sem nikdy nesmí projít.
    """
    cesta = Path(cesta)
    if not cesta.is_absolute() or cesta.is_symlink():
        return False
    temp = Path(tempfile.gettempdir()).resolve()
    skutecna = cesta.resolve()
    return (skutecna != temp and skutecna.parent == temp
            and cesta.name.startswith(TEMP_PREFIXY)
            and skutecna != ROOT and ROOT not in skutecna.parents)


def bezpecne_smaz(cesta):
    """Odstraní dočasnou kopii i to, co do ní zapsal Apache běžící v kontejneru jako root.

    Nepřijme libovolnou cestu – viz je_vlastni_kopie(). Docker se pouští jen kvůli
    vlastníkovi, mountuje se výhradně tahle složka a chown nenásleduje symbolické
    odkazy (-h); kdyby v kopii nějaký byl, chown se vůbec nespustí.
    """
    cesta = Path(cesta)
    if not je_vlastni_kopie(cesta):
        raise ValueError(f'bezpecne_smaz() uklízí jen vlastní dočasnou kopii, ne {cesta}')
    if not cesta.exists():
        return True

    shutil.rmtree(cesta, ignore_errors=True)
    if cesta.exists():
        symlinky = [p.relative_to(cesta).as_posix() for p in cesta.rglob('*') if p.is_symlink()]
        if symlinky:
            poznamka(f'v dočasné kopii jsou symbolické odkazy, chown se nespustí: {symlinky[:3]}')
            return False
        subprocess.run(['docker', 'run', '--rm', '--network', 'none', '-v', f'{cesta}:/kopie',
                        'php:8.3-cli-alpine', 'chown', '-h', '-R', f'{os.getuid()}:{os.getgid()}', '/kopie'],
                       capture_output=True)
        shutil.rmtree(cesta, ignore_errors=True)
    return not cesta.exists()


def docker_php(workdir, code, timeout=120):
    cmd = ['docker', 'run', '--rm', '--network', 'none', '--user', f'{os.getuid()}:{os.getgid()}',
           '-v', f'{workdir}:/app', '-w', '/app', 'php:8.3-cli-alpine', 'php', '-r', code]
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)


# ---------- 1. pravidla čistoty ----------

def test_pravidla():
    section('Pravidla čistoty (co je běhový soubor)')
    bezny = [
        'var/.htaccess',
        'src/weather.php',
        'src/templates/weather-data.php',
        'public/assets/js/weather.js',
        'public/assets/css/background.css',
        'config/asset-versions.php',
        'content/notes.json',
        'public/poznamky/nexus-domaci-server/index.php',
    ]
    for rel in bezny:
        check(runtime_duvod(rel) is None, f'povoleno: {rel}')

    runtime = [
        ('var/weather/' + 'a' * 64 + '.json', 'cache počasí'),
        ('var/weather/budget.json', 'strop dotazů'),
        ('var/weather/' + 'b' * 64 + '.fail', 'značka chyby'),
        ('var/weather/.tmp-0123456789ab', 'dočasný soubor'),
        ('var/neco-jineho.txt', 'cokoli jiného ve var/'),
        ('public/.DS_Store', 'systémový soubor'),
        ('src/__pycache__/x.pyc', 'běhová složka'),
        ('public/debug.log', 'log'),
    ]
    for rel, popis in runtime:
        check(runtime_duvod(rel) is not None, f'zachyceno ({popis}): {rel}')


# ---------- 2. čistý balíček ----------

def test_cisty(base):
    section('Čistě sestavený balíček')
    out = synteticky_balicek(base)
    check(len(varianty(out)) == 2, f'rozpoznány obě varianty ({[v.name for v in varianty(out)]})')
    for v in varianty(out):
        check(runtime_nalezy(cesty_ve_slozce(v)) == [], f'{v.name}/: bez nálezu')
        check(runtime_nalezy(cesty_v_zipu(out / f'{v.name}.zip')) == [], f'{v.name}.zip: bez nálezu')
    check(kontrola_cistoty_tise(out) == 0, 'kontrola_cistoty(): 0 nálezů')
    return out


def kontrola_cistoty_tise(out):
    """kontrola_cistoty() bez výpisu – ať test nezahltí vlastní výstup."""
    import io, contextlib
    pred = len(balicek.errors)
    with contextlib.redirect_stdout(io.StringIO()):
        n = kontrola_cistoty(out)
    del balicek.errors[pred:]
    return n


# ---------- 3. kontaminovaný balíček ----------

def test_kontaminovany(base):
    section('Kontaminovaný balíček se musí odhalit před nasazením')
    out = synteticky_balicek(base / 'kontaminovany')
    a, b = varianty(out)

    zanes_runtime(a)
    nalezy = runtime_nalezy(cesty_ve_slozce(a))
    check(len(nalezy) == 4, f'varianta A: {len(nalezy)} nálezů (čekáno 4)')
    check(all('var/weather' in rel for rel, _ in nalezy), 'nálezy jsou jen ve var/weather/')
    check(runtime_nalezy(cesty_ve_slozce(b)) == [], 'varianta B zůstala čistá (nález je lokální)')

    # ZIP se po kontaminaci nezměnil, ale kontrola musí umět odhalit i runtime data v archivu
    zip_dir(a, out / 'A-oddeleny-koren.zip')
    check(runtime_nalezy(cesty_v_zipu(out / 'A-oddeleny-koren.zip')) != [], 'ZIP s běhovými daty: nález')

    check(kontrola_cistoty_tise(out) >= 8, 'kontrola_cistoty(): nálezy ve složce i v ZIPu')

    # druhá varianta samostatně – var/ jako podsložka aplikace
    out2 = synteticky_balicek(base / 'kontaminovany-b')
    zanes_runtime(varianty(out2)[1])
    check(kontrola_cistoty_tise(out2) > 0, 'varianta B s běhovými daty: nález')

    # a totéž přes CLI, jak to uvidí člověk před nasazením
    run = subprocess.run([sys.executable, str(ROOT / 'tools' / 'balicek.py'), '--kontrola', str(out)],
                         capture_output=True, text=True, timeout=120)
    check(run.returncode != 0, f'balicek.py --kontrola: nenulový exit kód ({run.returncode})')
    vystup = run.stdout + run.stderr
    check('var/weather/budget.json' in vystup, 'výstup jmenuje konkrétní nalezený soubor')
    check('test-balicek.py --apache' in vystup, 'výstup říká, jak test pustit nad kopií')
    check('nemaže' in vystup, 'výstup říká, že nic nemaže automaticky')

    cisty = synteticky_balicek(base / 'cisty-kontrola')
    run = subprocess.run([sys.executable, str(ROOT / 'tools' / 'balicek.py'), '--kontrola', str(cisty)],
                         capture_output=True, text=True, timeout=120)
    check(run.returncode == 0, f'balicek.py --kontrola nad čistým balíčkem: exit 0 ({run.returncode})')


# ---------- 4. izolace: aplikace běží nad dočasnou kopií ----------

def test_izolace(base):
    section('Izolace: aplikace nad dočasnou kopií nesmí zašpinit balíček')
    # Skutečné rozložení varianty A, sestavené stejným kódem jako produkční balíček.
    out = base / 'mh42-real' / 'A-oddeleny-koren'
    balicek.copy_public(out / 'public')
    balicek.copy_app(out)
    balicek.write_versions(out / 'config')
    check(runtime_nalezy(cesty_ve_slozce(out)) == [], 'čerstvě sestavená varianta A je čistá')
    pred = sorted(cesty_ve_slozce(out))

    kopie = Path(tempfile.mkdtemp(prefix='mh42-kopie-'))
    try:
        cil = kopie / 'A-oddeleny-koren'
        shutil.copytree(out, cil)
        check(sorted(cesty_ve_slozce(cil)) == pred, 'dočasná kopie je shodná s balíčkem')

        # Aplikace nad KOPIÍ: nedosažitelné API, místo mimo výchozí → vznikne budget.json
        # i značka chyby. Kontejner běží bez sítě, takže se nikam nevolá.
        code = ("require '/app/src/bootstrap.php'; require '/app/src/weather.php';"
                "$cfg = weather_config(array_replace(config('weather'),"
                "  ['forecast_url' => 'http://127.0.0.1:1/forecast']));"
                "weather_get($cfg, 50.09, 14.42, ['fetch' => fn($u) => null]);"
                "echo is_dir('/app/var/weather') ? 'VAR-OK' : 'VAR-PRAZDNA';")
        try:
            run = docker_php(cil, code)
        except (OSError, subprocess.TimeoutExpired) as exc:
            poznamka(f'aplikaci se nepodařilo spustit ({exc}); test izolace přeskočen')
            return
        if run.returncode != 0:
            poznamka(f'aplikace skončila chybou: {(run.stderr or run.stdout).strip()[:200]}')
            check(False, 'aplikace nad kopií se spustila')
            return

        po = runtime_nalezy(cesty_ve_slozce(cil))
        check(po != [], f'v kopii vznikla běhová data ({[r for r, _ in po]})')
        check(any(r.endswith('budget.json') for r, _ in po), 'mezi nimi je var/weather/budget.json')

        # Jádro regrese: originál se nesmí hnout.
        check(sorted(cesty_ve_slozce(out)) == pred, 'PŮVODNÍ balíček má stejný seznam souborů')
        check(runtime_nalezy(cesty_ve_slozce(out)) == [], 'PŮVODNÍ balíček zůstal čistý')
        check(not (out / 'var' / 'weather').exists(), 'v původním balíčku nevznikla var/weather/')
    finally:
        uklizeno = bezpecne_smaz(kopie)
    check(uklizeno, 'dočasná kopie je po testu odstraněná')


# ---------- 5. bezpečnost úklidu ----------

def test_bezpecnost_uklidu(base):
    section('Bezpečnost úklidu dočasné kopie')

    # Co se NIKDY nesmí uklidit
    balicek_v_dist = nejnovejsi_balicek()
    odmitnout = [
        (ROOT, 'pracovní repozitář'),
        (ROOT / 'dist', 'celá dist/'),
        (balicek_v_dist, 'produkční balíček v dist/') if balicek_v_dist else (ROOT / 'public', 'public/'),
        (Path(tempfile.gettempdir()), 'systémový temp jako celek'),
        (Path(tempfile.gettempdir()) / 'neco-ciziho', 'cizí složka v tempu bez našeho prefixu'),
        (Path.home(), 'domovský adresář'),
        (Path('mh42-apache-relativni'), 'relativní cesta s naším prefixem'),
        (base / 'mh42-apache-mimo-temp', 'náš prefix, ale mimo temp'),
    ]
    for cesta, popis in odmitnout:
        check(not je_vlastni_kopie(cesta), f'odmítnuto ({popis}): {cesta}')
        try:
            bezpecne_smaz(cesta)
            check(False, f'bezpecne_smaz() mělo vyhodit ValueError pro {popis}')
        except ValueError:
            check(True, f'bezpecne_smaz() vyhodilo ValueError ({popis})')

    # Symlink s naším prefixem v tempu nesmí projít – jinak by se mazal jeho cíl
    obet = base / 'dulezita-data'
    obet.mkdir(parents=True, exist_ok=True)
    (obet / 'soubor.txt').write_text('nesmí zmizet', encoding='utf-8')
    odkaz = Path(tempfile.gettempdir()) / f'mh42-apache-symlink-test-{os.getpid()}'
    odkaz.symlink_to(obet, target_is_directory=True)
    try:
        check(not je_vlastni_kopie(odkaz), 'odmítnuto: symlink s naším prefixem v tempu')
        try:
            bezpecne_smaz(odkaz)
            check(False, 'bezpecne_smaz() mělo symlink odmítnout')
        except ValueError:
            check(True, 'bezpecne_smaz() vyhodilo ValueError (symlink)')
        check((obet / 'soubor.txt').is_file(), 'cíl symlinku zůstal nedotčený')
    finally:
        odkaz.unlink(missing_ok=True)

    # Co se uklidit MÁ
    kopie = Path(tempfile.mkdtemp(prefix='mh42-kopie-'))
    (kopie / 'podslozka').mkdir()
    (kopie / 'podslozka' / 'x.txt').write_text('x', encoding='utf-8')
    check(je_vlastni_kopie(kopie), f'přijato: vlastní mkdtemp kopie ({kopie.name})')
    check(bezpecne_smaz(kopie) and not kopie.exists(), 'vlastní kopie se odstranila')
    check(bezpecne_smaz(Path(tempfile.gettempdir()) / f'mh42-kopie-neexistuje-{os.getpid()}') is True,
          'neexistující kopie: úklid hlásí úspěch bez chyby')

    # Symlink uvnitř balíčku je nález (rglob do něj nezabíhá – jinak slepý bod)
    out = synteticky_balicek(base / 'symlink-v-balicku')
    a = varianty(out)[0]
    (a / 'var' / 'weather').mkdir(parents=True)
    (a / 'var' / 'weather' / 'budget.json').write_text('{}', encoding='utf-8')
    (a / 'odkaz-ven').symlink_to(a / 'var' / 'weather', target_is_directory=True)
    nalezy = nalezy_ve_slozce(a)
    check(any(duvod == 'symbolický odkaz' for _, duvod in nalezy), 'symlink v balíčku: nález')
    check(any('budget.json' in rel for rel, _ in nalezy), 'běhová data za symlinkem se neztratí')


# ---------- --apache: produkční test nad dočasnou kopií ----------

def apache_nad_kopii(balicek_dir):
    """Produkční Apache + tools/overeni-nasazeni.py nad DOČASNOU KOPIÍ balíčku."""
    print(f'Balíček: {balicek_dir}')
    if kontrola_cistoty(balicek_dir):
        sys.exit(balicek.RUNTIME_VYSVETLENI)

    zdroj = balicek_dir / 'A-oddeleny-koren'
    if not zdroj.is_dir():
        sys.exit(f'Varianta A nenalezena: {zdroj}')

    pred = sorted(cesty_ve_slozce(zdroj))
    kopie = Path(tempfile.mkdtemp(prefix='mh42-apache-'))
    jmeno = 'mh42-test-' + str(os.getpid())
    try:
        cil = kopie / 'A-oddeleny-koren'
        shutil.copytree(zdroj, cil)
        (cil / 'var').chmod(0o777)   # jen v kopii; balíčku se to netýká
        print(f'\nDočasná kopie: {cil}')

        start = subprocess.run(['docker', 'run', '-d', '--rm', '--name', jmeno, '-p', '8080:80',
                                '-v', f'{cil}:/var/www', '-v', f'{cil / "public"}:/var/www/html',
                                'php:8.3-apache', 'sh', '-c',
                                'a2enmod headers rewrite && apache2-foreground'],
                               capture_output=True, text=True)
        if start.returncode != 0:
            # Nejčastěji obsazený port 8080. Úklid zařídí finally.
            sys.exit('Apache se nepodařilo spustit (port 8080 obsazený?):\n'
                     + (start.stderr or start.stdout).strip()[:300])
        for _ in range(40):
            try:
                if urllib.request.urlopen('http://localhost:8080/', timeout=2).status == 200:
                    break
            except Exception:
                time.sleep(1)
        else:
            sys.exit('Apache nad kopií nenaběhl.')
        print('Apache nad kopií: HTTP 200\n')

        run = subprocess.run([sys.executable, str(ROOT / 'tools' / 'overeni-nasazeni.py'),
                              'http://localhost:8080', '--lokalne'], text=True)
        kod = run.returncode
    finally:
        subprocess.run(['docker', 'rm', '-f', jmeno], capture_output=True)
        uklizeno = bezpecne_smaz(kopie)

    po = runtime_nalezy(cesty_ve_slozce(zdroj))
    beze_zmeny = sorted(cesty_ve_slozce(zdroj)) == pred
    print(f'\nDočasná kopie odstraněna: {uklizeno}')
    print(f'Původní balíček beze změny: {beze_zmeny}')
    print(f'Původní balíček bez běhových dat: {po == []}')
    if po or not beze_zmeny:
        sys.exit('\nBalíček se během testu změnil – to je regrese, test měl běžet nad kopií.')
    if not uklizeno:
        sys.exit(f'\nDočasnou kopii se nepodařilo odstranit: {kopie}')
    sys.exit(kod)


if __name__ == '__main__':
    sys.stdout.reconfigure(line_buffering=True)   # ať se výpis nepromíchá s podprocesy

    if '--apache' in sys.argv:
        args = [a for a in sys.argv[1:] if not a.startswith('--')]
        cil = Path(args[0]) if args else nejnovejsi_balicek()
        if cil is None or not cil.is_dir():
            sys.exit(f'Balíček nenalezen: {args[0] if args else "dist/mh42-*"}')
        try:
            apache_nad_kopii(cil)
        except KeyboardInterrupt:
            # finally v apache_nad_kopii() už kontejner zastavilo a kopii uklidilo.
            sys.exit('\nPřerušeno – kontejner zastaven a dočasná kopie uklizena.')

    with tempfile.TemporaryDirectory(prefix='mh42-test-balicek-') as tmp:
        base = Path(tmp)
        test_pravidla()
        test_cisty(base / 'cisty')
        test_kontaminovany(base)
        test_izolace(base / 'izolace')
        test_bezpecnost_uklidu(base / 'bezpecnost')

    print(f'\nVýsledek: {results["ok"]} ok, {results["chyba"]} chyb')
    sys.exit(1 if results['chyba'] else 0)
