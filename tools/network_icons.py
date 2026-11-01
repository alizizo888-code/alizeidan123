#!/usr/bin/env python3
"""Draw the installable-app icons for the 10 satellite brands (name on a brand-colour tile). Output: oxygen-ac-automation/assets/network/<id>/
usage: tools/network_icons.py        (needs Pillow with raqm for Arabic shaping; the PNGs are committed, run only when the registry changes)"""
import os, re, sys
from PIL import Image, ImageDraw, ImageFont
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
src = open(os.path.join(ROOT, 'oxygen-ac-automation/includes/class-oxygen-network.php'), encoding='utf-8').read()
BR = re.findall(r"'(\w+)'\s*=>\s*\['name' => '([^']+)',\s*'label' => '([^']+)',\s*'color' => '(#[0-9a-f]{6})'\]", src)
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'

def shade(h, k):
    r, g, b = (int(h[i:i + 2], 16) for i in (1, 3, 5))
    return tuple(max(0, min(255, int(c * k))) for c in (r, g, b))

def tile(size, color, name, maskable):
    S = size * 2
    im = Image.new('RGBA', (S, S), (0, 0, 0, 0)); d = ImageDraw.Draw(im)
    top, bot = shade(color, 1.18), shade(color, 0.72)
    g = Image.new('RGB', (1, S))
    for y in range(S):
        t = y / (S - 1); g.putpixel((0, y), tuple(int(top[i] * (1 - t) + bot[i] * t) for i in range(3)))
    g = g.resize((S, S))
    mask = Image.new('L', (S, S), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, S - 1, S - 1), radius=0 if maskable else int(S * 0.22), fill=255)
    im.paste(g, (0, 0), mask)
    words = name.split(' ')
    pad = S * (0.26 if maskable else 0.14)
    lines = words if len(words) > 1 else [name]
    fs = int(S * 0.34)
    while fs > 20:
        f = ImageFont.truetype(FONT, fs, layout_engine=ImageFont.Layout.RAQM)
        w = max(d.textlength(l, font=f, direction='rtl', language='ar') for l in lines)
        if w <= S - 2 * pad and fs * 1.25 * len(lines) <= S - 2 * pad: break
        fs -= 4
    lh = fs * 1.3; y0 = (S - lh * len(lines)) / 2 + lh * 0.04
    for i, l in enumerate(lines):
        w = d.textlength(l, font=f, direction='rtl', language='ar')
        d.text(((S - w) / 2, y0 + i * lh), l, font=f, fill='white', direction='rtl', language='ar')
    return im.resize((size, size), Image.LANCZOS)

n = 0
for id_, name, label, color in BR:
    if id_ in ('oxygen', 'mutqan'): continue
    out = os.path.join(ROOT, 'oxygen-ac-automation/assets/network', id_); os.makedirs(out, exist_ok=True)
    for fn, size, mk in (('icon-192.png', 192, False), ('icon-512.png', 512, False), ('icon-maskable-512.png', 512, True), ('apple-touch-icon.png', 180, False)):
        tile(size, color, name, mk).save(os.path.join(out, fn)); n += 1
print(f'{n} icons for {len(BR)} brands')
