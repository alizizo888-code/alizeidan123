#!/usr/bin/env python3
"""Maps leads (owner order 2026-11-21): glue around the free open-source scraper gosom/google-maps-scraper.

  maps_leads.py plan   <config.json> <out_queries.txt> <out_map.json>   pick today's slice of searches
  maps_leads.py merge  <scraper.csv> <map.json> <leads_dir>             clean, tag, merge into leads/*.csv + leads.xlsx
  maps_leads.py push   <new_rows.json>                                  send the new rows to the hub (LEADS_TOKEN)

The config comes from the hub (Settings → عملاء الخرائط) when LEADS_TOKEN is set, else from leads/config.default.json.
Phones are normalised to 9665XXXXXXXX / 9661XXXXXXXX; one row per phone; a phone found again only refreshes its row.
"""
import csv, datetime, json, math, os, re, sys, urllib.request

HUB = os.environ.get('HUB_URL', 'https://oxygen11.com').rstrip('/')
TOKEN = os.environ.get('LEADS_TOKEN', '')
PER_DAY = int(os.environ.get('LEADS_PER_DAY', '60'))
CITY_AR = {'makkah': 'مكة', 'jeddah': 'جدة'}
FIELD_AR = {'ac': 'تكييف', 'cold': 'غرف تبريد'}
COLS = ['phone', 'name', 'city', 'field', 'kind', 'category', 'address', 'website', 'email', 'rating', 'reviews', 'lat', 'lng', 'link', 'query', 'mobile', 'first_seen', 'last_seen']
AR_DIGITS = str.maketrans('٠١٢٣٤٥٦٧٨٩', '0123456789')
# Maintenance shops are competitors, not customers: drop them even when a search for «فندق» brings one up.
COMPETITOR = re.compile(r'صيان|تكييف|مكيف|تبريد|تكيف|سباك|كهربائ|مقاول|ورشة|air ?condition|hvac|refrigerat|maintenance', re.I)


def phone(v):
    d = re.sub(r'\D+', '', str(v or '').translate(AR_DIGITS))
    if d.startswith('00966'):
        d = d[2:]
    if not d.startswith('966'):
        d = '966' + d.lstrip('0')
    return d if re.fullmatch(r'966(5\d{8}|1\d{7,8}|9200\d{5}|800\d{7})', d) else ''


def hub(path, body=None):
    req = urllib.request.Request(HUB + '/wp-json/mutqan/v1' + path, method='POST' if body is not None else 'GET',
                                 data=json.dumps(body).encode() if body is not None else None,
                                 headers={'X-Leads-Token': TOKEN, 'Content-Type': 'application/json', 'User-Agent': 'oxygen-leads/1.0'})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())


def plan(cfg_path, q_out, map_out):
    cfg = None
    if TOKEN:
        try:
            cfg = hub('/leads/config')
            print('config from hub:', len(cfg.get('queries', [])), 'searches')
        except Exception as e:  # the hub is down or the token is wrong: run on the defaults rather than not at all
            print('hub config failed, using defaults:', e)
    if not cfg:
        cfg = json.load(open(cfg_path, encoding='utf-8'))
    if not cfg.get('on', True):
        print('switched off in settings'); open(q_out, 'w').close(); json.dump({}, open(map_out, 'w')); return
    qs = cfg['queries']
    only = os.environ.get('ONLY', '').strip()  # «makkah», «cold», «makkah:cold» from a manual run
    if only:
        parts = only.split(':')
        qs = [q for q in qs if all(p in (q['city'], q['field']) for p in parts)]
    # A slice a day, round the whole list: the free minutes stay low and every search comes round again.
    if not os.environ.get('ALL'):
        n = max(1, math.ceil(len(qs) / PER_DAY))
        day = datetime.date.today().toordinal() % n
        qs = qs[day * PER_DAY:(day + 1) * PER_DAY]
    m = {}
    with open(q_out, 'w', encoding='utf-8') as f:
        for i, q in enumerate(qs):
            qid = f'q{i}'
            m[qid] = q
            m[q['q']] = q
            f.write(f"{q['q']} #!#{qid}\n")
    json.dump({'depth': cfg.get('depth', 6), 'map': m}, open(map_out, 'w', encoding='utf-8'), ensure_ascii=False)
    print('searches today:', len(qs))


def num(v, t=float):
    try:
        return t(str(v).replace(',', '').strip())
    except Exception:
        return ''


def merge(csv_path, map_path, leads_dir):
    mp = json.load(open(map_path, encoding='utf-8')).get('map', {})
    now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d')
    os.makedirs(leads_dir, exist_ok=True)
    books = {}
    for c in CITY_AR:
        for f in FIELD_AR:
            p = os.path.join(leads_dir, f'{c}-{f}.csv')
            rows = {}
            if os.path.exists(p):
                for r in csv.DictReader(open(p, encoding='utf-8-sig')):
                    rows[r['phone']] = r
            books[(c, f)] = rows
    new = []
    touched = []
    seen_any = set()
    raw = list(csv.DictReader(open(csv_path, encoding='utf-8-sig'))) if os.path.exists(csv_path) else []
    for r in raw:
        q = mp.get((r.get('input_id') or '').strip()) or mp.get((r.get('input_id') or '').split('#!#')[0].strip())
        if not q:
            continue
        name = (r.get('title') or '').strip()
        cat = (r.get('category') or '').strip()
        if COMPETITOR.search(name + ' ' + cat):
            continue
        p = phone(r.get('phone'))
        if not p or p in seen_any:
            continue
        seen_any.add(p)
        book = books[(q['city'], q['field'])]
        row = {
            'phone': p, 'name': name, 'city': q['city'], 'field': q['field'], 'kind': q.get('kind', ''), 'category': cat,
            'address': (r.get('address') or '').strip(), 'website': (r.get('website') or '').strip(),
            'email': (r.get('emails') or '').split(',')[0].strip(), 'rating': num(r.get('review_rating')),
            'reviews': num(r.get('review_count'), int), 'lat': num(r.get('latitude')), 'lng': num(r.get('longitude')),
            'link': (r.get('link') or '').strip(), 'query': q['q'], 'mobile': '1' if p.startswith('9665') else '0', 'last_seen': now,
        }
        if p in book:
            row['first_seen'] = book[p].get('first_seen') or now
        else:
            row['first_seen'] = now
            new.append(row)
        book[p] = row
        touched.append(row)
    total = 0
    for (c, f), rows in books.items():
        with open(os.path.join(leads_dir, f'{c}-{f}.csv'), 'w', encoding='utf-8-sig', newline='') as fh:
            w = csv.DictWriter(fh, fieldnames=COLS, extrasaction='ignore')
            w.writeheader()
            for r in sorted(rows.values(), key=lambda x: -(int(x.get('reviews') or 0))):
                w.writerow(r)
        total += len(rows)
    try:
        from openpyxl import Workbook
        wb = Workbook(); wb.remove(wb.active)
        heads = ['الرقم', 'الاسم', 'المدينة', 'المجال', 'نوع العميل', 'التصنيف', 'العنوان', 'الموقع', 'الإيميل', 'التقييم', 'عدد التقييمات', 'lat', 'lng', 'رابط الخريطة', 'البحث', 'جوال', 'أول ظهور', 'آخر ظهور']
        for (c, f), rows in books.items():
            ws = wb.create_sheet(f'{CITY_AR[c]} - {FIELD_AR[f]}')
            ws.sheet_view.rightToLeft = True
            ws.append(heads)
            for r in sorted(rows.values(), key=lambda x: -(int(x.get('reviews') or 0))):
                ws.append([str(r.get(k, '')) if k == 'phone' else r.get(k, '') for k in COLS])
        wb.save(os.path.join(leads_dir, 'leads.xlsx'))
    except ImportError:
        print('openpyxl missing: CSV files only')
    json.dump(touched, open(os.path.join(leads_dir, '.new.json'), 'w', encoding='utf-8'), ensure_ascii=False)
    print(f'scraped {len(raw)} rows, {len(seen_any)} usable, {len(new)} new, {total} in the files')


def push(path):
    rows = json.load(open(path, encoding='utf-8'))
    if not TOKEN:
        print('no LEADS_TOKEN: files only'); return
    run = os.environ.get('GITHUB_RUN_ID', '')
    for i in range(0, len(rows), 300):
        print(hub('/leads/import', {'rows': rows[i:i + 300], 'run': run}))


if __name__ == '__main__':
    cmd, *a = sys.argv[1:]
    {'plan': plan, 'merge': merge, 'push': push}[cmd](*a)
