#!/usr/bin/env python3
"""Writes the Android app's alert tones (native/shared/raw/*.wav), synthesized here — no sound files from anywhere else.
Same shapes as the web app's tones (mutqan-app/src/lib/alert.js TONES), one per kind of alert."""
import math, os, struct, wave

RATE = 22050
TONES = {   # [freq, start, dur, wave]
    'mq_order': [[523, 0, .12, 'tri'], [659, .12, .12, 'tri'], [784, .24, .12, 'tri'], [1046, .36, .3, 'tri'], [784, .7, .1, 'tri'], [1046, .82, .35, 'tri']],
    'mq_msg_in': [[1175, 0, .07, 'sin'], [880, .08, .09, 'sin'], [1318, .18, .12, 'sin']],
    'mq_tech': [[330, 0, .09, 'sq'], [330, .16, .09, 'sq'], [660, .34, .16, 'sin']],
    'mq_ops': [[1046, 0, .1, 'sin'], [1046, .25, .1, 'sin'], [1568, .5, .22, 'sin']],
    'mq_customer': [[523, 0, .1, 'sin'], [659, .1, .1, 'sin'], [784, .2, .1, 'sin'], [1046, .3, .2, 'sin']],
}

def osc(kind, ph):
    if kind == 'sq': return 1.0 if math.sin(ph) >= 0 else -1.0
    if kind == 'tri': return 2 / math.pi * math.asin(math.sin(ph))
    return math.sin(ph)

def render(notes, reps=2, gap=.25):
    span = max(s + d for _, s, d, _ in notes) + gap
    n = int(RATE * (span * reps + .1))
    buf = [0.0] * n
    for r in range(reps):
        for f, s, d, w in notes:
            a = int(RATE * (r * span + s)); L = int(RATE * d)
            for i in range(L):
                t = i / RATE
                env = min(1, t / .015) * math.exp(-4.5 * t / d)
                buf[a + i] += .32 * env * osc(w, 2 * math.pi * f * t) * (.55 if w == 'sq' else 1)
    return buf

out = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'native', 'shared', 'raw')
os.makedirs(out, exist_ok=True)
for name, notes in TONES.items():
    data = render(notes)
    with wave.open(os.path.join(out, name + '.wav'), 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(RATE)
        w.writeframes(b''.join(struct.pack('<h', int(max(-1, min(1, x)) * 32000)) for x in data))
    print(name, len(data) / RATE, 's')
