#!/usr/bin/env python3
"""Generuje rastrové obrázky webu z písma IBM Plex (potřebuje Pillow):

  public/favicon.ico                    16, 32 a 48 px pro prohlížeče bez SVG ikon
  public/assets/img/apple-touch-icon.png   180 px pro iOS
  public/assets/img/og-image.png        1200 × 630 px náhled pro sdílení odkazů

Spuštění: python3 tools/obrazky.py
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
FONTS = ROOT / 'public' / 'assets' / 'fonts'
IMG = ROOT / 'public' / 'assets' / 'img'

# barvy ze světlého motivu (public/assets/css/tokens.css)
BG = '#f6f5f1'
TEXT = '#1c1c1a'
MUTED = '#5c5a54'
LINE = '#d9d6cc'
ACCENT = '#a8461a'
SCHEMA = '#e8cdc5'   # linky schématu, varianta B pozadí

SS = 4  # převzorkování kvůli vyhlazení hran


def font(name, size):
    return ImageFont.truetype(str(FONTS / f'{name}.woff2'), size)


def latin1(ch):
    """Patří znak do podmnožiny Latin1? (české znaky s háčky jsou v Latin2)"""
    code = ord(ch)
    return code <= 0x7E or 0xA0 <= code <= 0xFF or code in (0x2013, 0x2014, 0x2026)


def text(draw, xy, value, family, size, fill, align='left'):
    """Vypíše text písmem rozděleným do souborů Latin1/Latin2 – po úsecích podle znakové sady."""
    fonts = {True: font(f'{family}-Latin1', size), False: font(f'{family}-Latin2', size)}
    runs = []
    for ch in value:
        key = latin1(ch)
        if runs and runs[-1][0] == key:
            runs[-1][1] += ch
        else:
            runs.append([key, ch])
    width = sum(draw.textlength(run, font=fonts[key]) for key, run in runs)
    x, y = xy
    if align == 'right':
        x -= width
    for key, run in runs:
        draw.text((x, y), run, font=fonts[key], fill=fill, anchor='ls')
        x += draw.textlength(run, font=fonts[key])


def icon(size, rounded):
    """Čtverec v barvě akcentu s číslicemi 42."""
    big = size * SS
    img = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    radius = round(big * 3 / 32) if rounded else 0
    draw.rounded_rectangle((0, 0, big - 1, big - 1), radius=radius, fill=ACCENT)
    draw.text((big / 2, big / 2 + big * 0.02), '42', font=font('IBMPlexMono-SemiBold-Latin1', round(big * 0.5)),
              fill=BG, anchor='mm')
    return img.resize((size, size), Image.LANCZOS)


def schema(draw, s, tx, ty, width):
    """Výřez schématu z motivu pozadí (obvod s diodou a zemí), přepočtený měřítkem s a posunem."""
    def p(x, y):
        return (tx + x * s, ty + y * s)

    def line(*points):
        draw.line([p(x, y) for x, y in points], fill=SCHEMA, width=width, joint='curve')

    def circle(x, y, r, fill=None):
        (x0, y0), (x1, y1) = p(x - r, y - r), p(x + r, y + r)
        draw.ellipse((x0, y0, x1, y1), outline=SCHEMA, fill=fill, width=width)

    # integrovaný obvod s výřezem
    (x0, y0), (x1, y1) = p(380, 330), p(450, 378)
    draw.rectangle((x0, y0, x1, y1), outline=SCHEMA, width=width)
    (ax0, ay0), (ax1, ay1) = p(409, 324), p(421, 336)
    draw.arc((ax0, ay0, ax1, ay1), 0, 180, fill=SCHEMA, width=width)
    for y in (342, 366):
        line((370, y), (380, y))
    for y in (354, 366):
        line((450, y), (460, y))
    # vodič ke svorce
    line((380, 354), (330, 354), (330, 281))
    circle(330, 277, 3)
    # vodič do země
    line((450, 342), (510, 342), (510, 430))
    line((498, 430), (522, 430))
    line((502, 435), (518, 435))
    line((506, 440), (514, 440))
    # dioda
    line((370, 366), (352, 366))
    draw.polygon([p(352, 360), p(338, 366), p(352, 372)], outline=SCHEMA, width=width)
    line((338, 360), (338, 372))
    line((338, 366), (300, 366), (300, 420))
    circle(300, 424, 3)
    # čárkované ohraničení bloku
    dash, gap = 4, 4
    for (xa, ya), (xb, yb) in (((316, 262), (536, 262)), ((316, 452), (536, 452)),
                               ((316, 262), (316, 452)), ((536, 262), (536, 452))):
        length = max(abs(xb - xa), abs(yb - ya))
        for start in range(0, length, dash + gap):
            end = min(start + dash, length)
            if xa == xb:
                line((xa, ya + start), (xa, ya + end))
            else:
                line((xa + start, ya), (xa + end, ya))


def og_image():
    w, h = 1200 * 2, 630 * 2   # kreslí se ve dvojnásobku a zmenší
    img = Image.new('RGB', (w, h), BG)
    draw = ImageDraw.Draw(img)
    left = 88 * 2

    schema(draw, s=3.2, tx=1480 - 300 * 3.2, ty=90 - 262 * 3.2, width=4)

    wordmark = font('IBMPlexMono-SemiBold-Latin1', 300)
    draw.text((left, 400), 'mh', font=wordmark, fill=TEXT, anchor='ls')
    draw.text((left + draw.textlength('mh', font=wordmark), 400), '42', font=wordmark, fill=ACCENT, anchor='ls')

    text(draw, (left, 560), 'Osobní technický portál', 'IBMPlexSans-Regular', 92, TEXT)

    rule_y = 780
    draw.line((left, rule_y, w - left, rule_y), fill=LINE, width=4)
    draw.line((left, rule_y, left + 96, rule_y), fill=ACCENT, width=8)

    text(draw, (left, rule_y + 120), 'SOFTWARE · ELEKTRONIKA · DOMÁCÍ SERVERY · AUTOMATIZACE',
         'IBMPlexMono-Medium', 44, MUTED)
    text(draw, (w - left, h - 110), 'mh42.cz', 'IBMPlexMono-Regular', 48, MUTED, align='right')

    return img.resize((1200, 630), Image.LANCZOS)


if __name__ == '__main__':
    icon(64, rounded=True).save(ROOT / 'public' / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)])
    icon(180, rounded=False).convert('RGB').save(IMG / 'apple-touch-icon.png', optimize=True)
    og_image().save(IMG / 'og-image.png', optimize=True)
    for path in (ROOT / 'public' / 'favicon.ico', IMG / 'apple-touch-icon.png', IMG / 'og-image.png'):
        print(f'{path.relative_to(ROOT)}  {path.stat().st_size / 1024:.1f} kB')
