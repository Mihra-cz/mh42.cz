#!/usr/bin/env python3
"""Motiv pozadí inspirovaný elektronikou.

Generuje:
  public/assets/img/pozadi/schema.svg   dlaždice pro světlý motiv (varianta B – schéma)
  public/assets/img/pozadi/pcb.svg      dlaždice pro tmavý motiv (varianta B – layout desky)
  prototyp/pozadi-pcb.html              prototyp variant A / B / C nad schváleným prototypem homepage

Na webu je varianta B, varianta A zůstává v prototypu jako jemnější záloha.
Dlaždice počítají s barvami pozadí z tokens.css (prokovy mají „díru“ v barvě tmavého pozadí).

Spuštění: python3 tools/pozadi-pcb.py
"""
import hashlib
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / 'prototyp' / 'homepage.html'
OUT = ROOT / 'prototyp' / 'pozadi-pcb.html'
EXPORT = ROOT / 'public' / 'assets' / 'img' / 'pozadi'

BG_LIGHT = '#f6f5f1'
BG_DARK = '#121314'

# ---------- barvy variant ----------
COL = {
    # světlý režim: schéma ve světle červené
    'a_light': {'line': '#efdcd6'},
    'b_light': {'line': '#e8cdc5'},
    'c_light': {'dot': '#e7cec7', 'line': '#eed9d3'},
    # tmavý režim: cesty, pady a prokovy v jemně zelené, potisk neutrální
    'a_dark': {'top': '#1a261e', 'bottom': '#161c18', 'pad': '#1d2b22', 'silk': '#1b1d1f'},
    'b_dark': {'top': '#1e3027', 'bottom': '#18221c', 'pad': '#22352a', 'silk': '#222427'},
    'c_dark': {'ring': '#18201b', 'pad': '#213227', 'trace': '#1e2d23', 'silk': '#1d1f21'},
}


def lum(h):
    h = h.lstrip('#')
    r, g, b = [int(h[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    f = lambda c: c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)


def contrast(a, b):
    la, lb = sorted([lum(a), lum(b)], reverse=True)
    return (la + 0.05) / (lb + 0.05)


def svg(size, body):
    return f"<svg xmlns='http://www.w3.org/2000/svg' width='{size}' height='{size}' viewBox='0 0 {size} {size}'>{body}</svg>"


# ---------- světlý režim: schéma ----------
# Motiv je složený z uzavřených „ostrůvků“ obvodů; žádný vodič nevede přes okraj
# dlaždice, takže na stránce nevznikají nekonečné čáry podobné dělicím linkám sekcí.
def schematic(c, extra=False):
    s = c['line']
    parts = [
        f"<g fill='none' stroke='{s}' stroke-width='1'>",
        # ostrůvek 1: svorka, rezistor, uzel, kondenzátor do země, odbočka s rezistorem
        "<circle cx='40.5' cy='90.5' r='3'/>",
        "<path d='M43.5 90.5H120.5M160.5 90.5H290.5V140.5M278.5 140.5H302.5M278.5 147.5H302.5M290.5 147.5V190.5'/>",
        "<rect x='120.5' y='84.5' width='40' height='12'/>",
        "<path d='M278.5 190.5H302.5M283.5 195.5H297.5M287.5 200.5H293.5'/>",
        "<path d='M220.5 90.5V150.5M220.5 190.5V240.5'/>",
        "<rect x='214.5' y='150.5' width='12' height='40'/>",
        "<circle cx='220.5' cy='243.5' r='3'/>",
        # ostrůvek 2: integrovaný obvod
        "<rect x='380.5' y='330.5' width='70' height='48'/>",
        "<path d='M409.5 330.5a6 6 0 0 0 12 0'/>",
        "<path d='M370.5 342.5H380.5M370.5 366.5H380.5M450.5 354.5H460.5M450.5 366.5H460.5'/>",
        "<path d='M380.5 354.5H330.5V280.5'/>",
        "<circle cx='330.5' cy='277.5' r='3'/>",
        "<path d='M450.5 342.5H510.5V430.5M498.5 430.5H522.5M502.5 435.5H518.5M506.5 440.5H514.5'/>",
        # ostrůvek 3: svorka, rezistor, uzel do země
        "<circle cx='70.5' cy='480.5' r='3'/>",
        "<path d='M73.5 480.5H130.5M170.5 480.5H260.5'/>",
        "<rect x='130.5' y='474.5' width='40' height='12'/>",
        "<circle cx='263.5' cy='480.5' r='3'/>",
        "<path d='M230.5 480.5V510.5M218.5 510.5H242.5M222.5 515.5H238.5M226.5 520.5H234.5'/>",
        # registrační křížky
        "<path d='M86.5 330.5h8M90.5 326.5v8M496.5 60.5h8M500.5 56.5v8'/>",
    ]
    if extra:
        parts += [
            # ohraničení funkčního bloku kolem obvodu
            "<rect x='316.5' y='262.5' width='220' height='190' stroke-dasharray='4 4'/>",
            # dioda na vývodu obvodu
            "<path d='M370.5 366.5H352.5M338.5 366.5H300.5V420.5'/>",
            "<path d='M352.5 360.5L338.5 366.5L352.5 372.5Z M338.5 360.5V372.5'/>",
            "<circle cx='300.5' cy='423.5' r='3'/>",
            # řetězec vpravo nahoře
            "<circle cx='377.5' cy='200.5' r='3'/>",
            "<path d='M380.5 200.5H420.5M460.5 200.5H520.5V225.5M508.5 225.5H532.5M512.5 230.5H528.5M516.5 235.5H524.5'/>",
            "<rect x='420.5' y='194.5' width='40' height='12'/>",
        ]
    parts.append('</g>')
    dots = [(220.5, 90.5), (230.5, 480.5)]
    parts.append(f"<g fill='{s}'>" + ''.join(f"<circle cx='{x}' cy='{y}' r='2.5'/>" for x, y in dots) + '</g>')
    return svg(560, ''.join(parts))


# ---------- tmavý režim: layout desky ----------
def via(x, y, r, pad, hole=1.8):
    return f"<circle cx='{x}' cy='{y}' r='{r}' fill='{pad}'/><circle cx='{x}' cy='{y}' r='{hole}' fill='{BG_DARK}'/>"


def pcb(c, extra=False):
    top, bottom, pad, silk = c['top'], c['bottom'], c['pad'], c['silk']
    parts = [
        "<g fill='none' stroke-linecap='round' stroke-linejoin='round' stroke-width='2.5'>",
        f"<g stroke='{top}'>",
        # krátká sběrnice tří cest mezi dvěma řadami prokovů
        "<path d='M40 110H150L190 150H300'/>",
        "<path d='M40 122H145L185 162H300'/>",
        "<path d='M40 134H140L180 174H300'/>",
        # vývody pouzdra SOIC-8
        "<path d='M300 315H250L220 285V230'/>",
        "<path d='M300 327H240L200 367H90'/>",
        "<path d='M360 315H400L430 285V200'/>",
        "<path d='M360 351H470'/>",
        # svislá cesta s pasivní součástkou
        "<path d='M90 230V440'/>",
        "<path d='M90 283H110M131 283H160L180 263'/>",
    ]
    if extra:
        parts += [
            "<path d='M360 339H380V414'/>",
            "<path d='M520 230V420M534 230V420'/>",
        ]
    parts.append('</g>')
    # spodní vrstva – tlumenější
    parts.append(f"<g stroke='{bottom}'><path d='M90 440V490L120 520H260'/>")
    if extra:
        parts.append("<path d='M430 200V130L470 90H520'/>")
        parts.append("<path d='M220 230V204L250 174H300'/>")
    parts.append('</g></g>')

    pads = ''.join(f"<rect x='{x}' y='{y}' width='12' height='6' rx='1'/>"
                   for x in (300, 348) for y in (312, 324, 336, 348))
    pads += "<rect x='110' y='279.5' width='7' height='7' rx='1'/><rect x='124' y='279.5' width='7' height='7' rx='1'/>"
    parts.append(f"<g fill='{pad}'>{pads}</g>")

    vias = [(40, 110), (40, 122), (40, 134), (300, 150), (300, 162), (300, 174),
            (220, 230), (430, 200), (470, 351), (180, 263), (90, 230), (90, 440), (260, 520)]
    for x, y in vias:
        parts.append(via(x, y, 4.5, pad))
    for x in (150, 165, 180):
        parts.append(via(x, 440, 3.5, pad, 1.4))
    parts.append(via(490, 490, 10, pad, 6))
    if extra:
        parts.append(f"<circle cx='380' cy='420' r='6' fill='{pad}'/>")
        for x, y in ((520, 230), (534, 230), (520, 420), (534, 420), (520, 90)):
            parts.append(via(x, y, 4, pad, 1.6))

    # potisk: obrys pouzdra a značka vývodu 1
    parts.append(f"<rect x='316.5' y='305.5' width='27' height='51' fill='none' stroke='{silk}' stroke-width='1'/>")
    parts.append(f"<circle cx='321' cy='311' r='1.3' fill='{silk}'/>")
    if extra:
        parts.append(f"<rect x='104.5' y='274.5' width='32' height='17' fill='none' stroke='{silk}' stroke-width='1'/>")
    return svg(560, ''.join(parts))


# ---------- varianta C: univerzální deska (rastr) ----------
def grid_points(step=32, size=512, offset=16.0):
    n = size // step
    return [(offset + step * i, offset + step * j) for i in range(n) for j in range(n)]


def dotboard_light(c):
    dot, s = c['dot'], c['line']
    parts = [f"<g fill='{dot}'>" + ''.join(f"<circle cx='{x + .5}' cy='{y + .5}' r='1.1'/>" for x, y in grid_points()) + '</g>']
    parts += [
        f"<g fill='none' stroke='{s}' stroke-width='1'>",
        # vodič s rezistorem a kondenzátorem
        "<path d='M48.5 112.5H128.5M160.5 112.5H240.5V156.5M228.5 156.5H252.5M228.5 162.5H252.5M240.5 162.5V208.5'/>",
        "<rect x='128.5' y='106.5' width='32' height='12'/>",
        # obvod na rastru
        "<rect x='346.5' y='166.5' width='76' height='84'/>",
        "<path d='M336.5 176.5H346.5M336.5 208.5H346.5M336.5 240.5H346.5M422.5 176.5H432.5M422.5 208.5H432.5M422.5 240.5H432.5'/>",
        # vodič do země
        "<path d='M304.5 368.5H464.5V448.5M452.5 448.5H476.5M456.5 453.5H472.5M460.5 458.5H468.5'/>",
        '</g>',
        f"<g fill='{s}'>",
        "<circle cx='48.5' cy='112.5' r='2.4'/><circle cx='240.5' cy='208.5' r='2.4'/><circle cx='304.5' cy='368.5' r='2.4'/>",
        '</g>',
    ]
    return svg(512, ''.join(parts))


def dotboard_dark(c):
    ring, pad, trace, silk = c['ring'], c['pad'], c['trace'], c['silk']
    parts = [f"<g fill='none' stroke='{ring}' stroke-width='1.2'>" +
             ''.join(f"<circle cx='{x}' cy='{y}' r='3.2'/>" for x, y in grid_points()) + '</g>']
    parts += [
        f"<g fill='none' stroke='{trace}' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'>",
        "<path d='M48 112H112M208 112H240V208'/>",
        "<path d='M432 176H464V304H304V368H464V464'/>",
        '</g>',
        # osazené pady
        f"<g fill='{pad}'>" + ''.join(
            f"<circle cx='{x}' cy='{y}' r='3.6'/>" for x, y in
            [(48, 112), (112, 112), (208, 112), (240, 208),
             (336, 176), (336, 208), (336, 240), (336, 272),
             (432, 176), (432, 208), (432, 240), (432, 272), (464, 464)]) + '</g>',
        # potisk: rezistor a pouzdro DIP-8
        f"<g fill='none' stroke='{silk}' stroke-width='1'>",
        "<rect x='124.5' y='105.5' width='72' height='13' rx='2'/>",
        "<path d='M348.5 162.5H378.5A5.5 5.5 0 0 0 389.5 162.5H419.5V285.5H348.5Z'/>",
        '</g>',
    ]
    return svg(512, ''.join(parts))


def data_uri(s):
    s = re.sub(r'\s+', ' ', s)
    return "url(\"data:image/svg+xml," + s.replace('%', '%25').replace('#', '%23').replace('<', '%3C').replace('>', '%3E').replace('"', "'") + "\")"


tiles = {
    'a_light': schematic(COL['a_light']),
    'b_light': schematic(COL['b_light'], extra=True),
    'c_light': dotboard_light(COL['c_light']),
    'a_dark': pcb(COL['a_dark']),
    'b_dark': pcb(COL['b_dark'], extra=True),
    'c_dark': dotboard_dark(COL['c_dark']),
}

# ---------- kontrasty ----------
print('Kontrast motivu vůči pozadí (text má 14,8–15,7 : 1):')
for name, cols in COL.items():
    bg = BG_LIGHT if name.endswith('light') else BG_DARK
    print(f'  {name:8}', ', '.join(f'{k} {v} {contrast(v, bg):.2f}' for k, v in cols.items()))

light_vars = '\n'.join(f"  --pcb-{v}: {data_uri(tiles[v + '_light'])};" for v in 'abc')
dark_vars = '\n'.join(f"  --pcb-{v}: {data_uri(tiles[v + '_dark'])};" for v in 'abc')

css = f"""
/* ---------- PROTOTYP POZADÍ (jen pro výběr varianty) ---------- */

:root {{
{light_vars}
}}
@media (prefers-color-scheme: dark) {{
  :root:not([data-theme="light"]) {{
{dark_vars}
  }}
}}
:root[data-theme="dark"] {{
{dark_vars}
}}

body {{ position: relative; isolation: isolate; min-height: 100vh; }}
body::before {{
  content: "";
  position: absolute;
  inset: 0;
  z-index: -1;
  pointer-events: none;
  background-position: center top;
  background-repeat: repeat;
}}
:root[data-bg="a"] body::before {{ background-image: var(--pcb-a); }}
:root[data-bg="b"] body::before {{
  background-image: var(--pcb-b);
  /* pod textovým sloupcem motiv zeslábne na 65 % */
  -webkit-mask-image: linear-gradient(90deg, #000 calc(50% - 38rem), rgb(0 0 0 / 0.65) calc(50% - 27rem), rgb(0 0 0 / 0.65) calc(50% + 27rem), #000 calc(50% + 38rem));
  mask-image: linear-gradient(90deg, #000 calc(50% - 38rem), rgb(0 0 0 / 0.65) calc(50% - 27rem), rgb(0 0 0 / 0.65) calc(50% + 27rem), #000 calc(50% + 38rem));
}}
:root[data-bg="c"] body::before {{ background-image: var(--pcb-c); }}

.bg-panel {{
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  z-index: 20;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem;
  padding: 0.375rem 0.5rem;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 2px;
  font-family: var(--font-mono);
  font-size: var(--text-label);
  color: var(--muted);
}}
.bg-panel__label {{ padding-inline: 0.25rem; }}
.bg-panel__sep {{ width: 1px; height: 1.25rem; margin-inline: 0.25rem; background: var(--line); }}
.bg-panel button {{
  min-width: 2.25rem;
  min-height: 2.25rem;
  padding: 0 0.5rem;
  border: 0;
  border-radius: 2px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  cursor: pointer;
}}
.bg-panel button:hover {{ color: var(--text); background: var(--bg); }}
.bg-panel button[aria-pressed="true"] {{
  color: var(--text);
  background: var(--bg);
  box-shadow: inset 0 -2px 0 var(--accent);
}}
@media (max-width: 40rem) {{
  .bg-panel {{ left: 0.5rem; right: 0.5rem; bottom: 0.5rem; justify-content: center; }}
  .site-footer__inner {{ padding-bottom: 6rem; }}
}}
"""

panel = """
<div class="bg-panel" role="group" aria-label="Volba pozadí (jen prototyp)">
  <span class="bg-panel__label">pozadí</span>
  <button type="button" data-set-bg="none">bez</button>
  <button type="button" data-set-bg="a">A</button>
  <button type="button" data-set-bg="b">B</button>
  <button type="button" data-set-bg="c">C</button>
  <span class="bg-panel__sep" aria-hidden="true"></span>
  <button type="button" data-set-theme="light">světlý</button>
  <button type="button" data-set-theme="dark">tmavý</button>
</div>

<script>
  // Přepínání variant pozadí a motivu (jen prototyp). Stav je i v adrese, např. #b-dark.
  (function () {
    var root = document.documentElement;
    var media = window.matchMedia('(prefers-color-scheme: dark)');

    function theme() { return root.getAttribute('data-theme') || (media.matches ? 'dark' : 'light'); }

    function sync() {
      var bg = root.getAttribute('data-bg') || 'none';
      document.querySelectorAll('[data-set-bg]').forEach(function (b) {
        b.setAttribute('aria-pressed', b.dataset.setBg === bg ? 'true' : 'false');
      });
      document.querySelectorAll('[data-set-theme]').forEach(function (b) {
        b.setAttribute('aria-pressed', b.dataset.setTheme === theme() ? 'true' : 'false');
      });
      history.replaceState(null, '', '#' + bg + '-' + theme());
    }

    document.querySelectorAll('[data-set-bg]').forEach(function (b) {
      b.addEventListener('click', function () { root.setAttribute('data-bg', b.dataset.setBg); sync(); });
    });
    document.querySelectorAll('[data-set-theme]').forEach(function (b) {
      b.addEventListener('click', function () { root.setAttribute('data-theme', b.dataset.setTheme); sync(); });
    });

    var m = /^#(none|a|b|c)-(light|dark)$/.exec(location.hash);
    if (m) { root.setAttribute('data-bg', m[1]); root.setAttribute('data-theme', m[2]); }
    else root.setAttribute('data-bg', 'a');

    new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['data-theme'] });
    media.addEventListener('change', sync);
    sync();
  })();
</script>
"""

html = SRC.read_text(encoding='utf-8')
html = html.replace('<title>mh42 · prototyp homepage</title>', '<title>mh42 · prototyp pozadí PCB</title>')
html = html.replace('statický prototyp homepage (vizuální koncept „technický list“).',
                    'prototyp variant pozadí PCB nad schváleným prototypem homepage.')
html = html.replace(
    '<p class="wrap"><strong>Prototyp homepage</strong> · všechny texty jsou pracovní obsah, ne finální znění · odkazy zatím nikam nevedou</p>',
    '<p class="wrap"><strong>Prototyp pozadí PCB</strong> · varianty A / B / C a motiv se přepínají vpravo dole · texty jsou pracovní obsah</p>')
html = html.replace('</style>', css + '</style>', 1)
html = html.replace('</body>', panel + '</body>', 1)
OUT.write_text(html, encoding='utf-8')
print('zapsáno', OUT, f'{OUT.stat().st_size / 1024:.1f} kB')

# ---------- dlaždice pro web (varianta B) ----------
EXPORT.mkdir(parents=True, exist_ok=True)
note = '<!-- Generováno nástrojem tools/pozadi-pcb.py (varianta B). Neupravovat ručně. -->\n'
tokens_css = ROOT / 'public' / 'assets' / 'css' / 'tokens.css'
tokens = tokens_css.read_text(encoding='utf-8')
for name, key in (('schema.svg', 'b_light'), ('pcb.svg', 'b_dark')):
    target = EXPORT / name
    content = note + tiles[key] + '\n'
    target.write_text(content, encoding='utf-8')
    # verze v adrese podle obsahu – po změně dlaždice prohlížeč nepoužije starou z cache
    version = hashlib.sha1(content.encode('utf-8')).hexdigest()[:8]
    tokens = re.sub(r'url\("\.\./img/pozadi/' + re.escape(name) + r'(\?v=[0-9a-f]+)?"\)',
                    f'url("../img/pozadi/{name}?v={version}")', tokens)
    print('zapsáno', target.relative_to(ROOT), f'{target.stat().st_size / 1024:.1f} kB, verze {version}')
tokens_css.write_text(tokens, encoding='utf-8')
