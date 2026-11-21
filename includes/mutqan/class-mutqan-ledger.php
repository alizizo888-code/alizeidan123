<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «سجل العملاء الدائم» — every customer we ever served, where, and what we did there (owner, 2026-11-21).
 *
 * The map used to build its customers from the live orders only: an order deleted, an order without a location, or
 * an order older than the last 500, and the customer was gone. The owner wants the opposite: once a customer, always
 * on the map — to send a technician «next to the house of …», to know a customer came through a technician, and to see
 * at once, when he calls again about a warranty or a new fault, what was done there before.
 *
 * One document per customer in `client_ledger` (id `cl-<last 9 digits>`, or `cl-o<order>` without a phone), written
 * by the server on every order write and never deleted with the order: his name and phone, every place (a new place
 * when it is more than 40 m from the known ones), and a snapshot of each order (number, service, status, technician,
 * price, dates, how he came). Deleting the order marks the snapshot `deleted`, nothing more.
 */
final class Mutqan_Ledger {

    const COLL = 'client_ledger';
    const NEAR_M = 40;

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'سجل العملاء']; }
    private static function sh() { return ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins']; }

    public static function key_of($o) {
        $d = preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? ''));
        if (strlen($d) >= 8) { return substr($d, -9); }
        return 'o' . substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($o['id'] ?? '')), 0, 40);
    }

    public static function meters($a, $b, $c, $d) {
        $R = 6371000; $p1 = deg2rad($a); $p2 = deg2rad($c); $dp = deg2rad($c - $a); $dl = deg2rad($d - $b);
        $x = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $R * atan2(sqrt($x), sqrt(1 - $x));
    }

    private static function has_ll($o) { return isset($o['lat'], $o['lng']) && is_numeric($o['lat']) && is_numeric($o['lng']) && (float) $o['lat'] != 0.0; }

    /** How he came: the site, WhatsApp, a technician who brought him, an import… */
    private static function via($o) {
        if (!empty($o['referredByTech']) || ($o['origin']['channel'] ?? '') === 'tech' || in_array((string) ($o['source'] ?? ''), ['tech', 'technician'], true)) { return 'فني: ' . (string) ($o['referredByTechName'] ?? ($o['technicianName'] ?? '')); }
        $ch = (string) ($o['origin']['channel'] ?? ($o['source'] ?? ''));
        return ['whatsapp' => 'واتساب', 'site' => 'الموقع', 'mutqan_ai' => 'المساعد', 'import' => 'استيراد', 'test' => 'تجربة'][$ch] ?? ($ch ?: 'غير معروف');
    }

    private static function snap($o) {
        return array_filter([
            'id' => (string) $o['id'], 'number' => (string) ($o['number'] ?? ''), 'status' => (string) ($o['status'] ?? ''),
            'service' => mb_substr((string) ($o['service'] ?? ''), 0, 120), 'specialtyId' => (string) ($o['specialtyId'] ?? ''),
            'technicianId' => (string) ($o['technicianId'] ?? ''), 'technicianName' => (string) ($o['technicianName'] ?? ''),
            'agreedPrice' => isset($o['agreedPrice']) && is_numeric($o['agreedPrice']) ? (int) $o['agreedPrice'] : null,
            'createdAt' => (string) ($o['createdAt'] ?? ''), 'completedAt' => (string) ($o['completedAt'] ?? ''),
            'address' => mb_substr((string) ($o['address'] ?? ''), 0, 160), 'lat' => self::has_ll($o) ? (float) $o['lat'] : null, 'lng' => self::has_ll($o) ? (float) $o['lng'] : null,
            'via' => self::via($o), 'brand' => (string) ($o['brand'] ?? ''),
        ], function ($v) { return $v !== null && $v !== ''; });
    }

    /** Write (or refresh) this order into its customer's record. Never throws into the caller. */
    public static function touch($o) {
        try {
            if (!is_array($o) || empty($o['id']) || in_array((string) ($o['source'] ?? ''), ['test'], true)) { return null; }
            $key = self::key_of($o); $id = 'cl-' . $key;
            $cur = Mutqan_Store::get_raw(self::COLL, $id) ?: [];
            $rec = $cur + ['key' => $key, 'name' => '', 'phone' => '', 'places' => [], 'orders' => [], 'firstAt' => (string) ($o['createdAt'] ?? gmdate('c'))];
            if (!empty($o['clientName'])) { $rec['name'] = mb_substr((string) $o['clientName'], 0, 80); }
            if (!empty($o['clientPhone'])) { $rec['phone'] = (string) $o['clientPhone']; }
            // the order's snapshot (replaced if already there)
            $s = self::snap($o); $found = false;
            foreach ($rec['orders'] as $i => $x) { if (($x['id'] ?? '') === $s['id']) { $rec['orders'][$i] = array_merge($x, $s); $found = true; } }
            if (!$found) { $rec['orders'][] = $s; }
            usort($rec['orders'], function ($a, $b) { return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')); });
            $rec['orders'] = array_slice($rec['orders'], 0, 60);
            // the place (a new one when far from every known place)
            if (self::has_ll($o)) {
                $lat = (float) $o['lat']; $lng = (float) $o['lng']; $near = false;
                foreach ($rec['places'] as &$pl) { if (self::meters($pl['lat'], $pl['lng'], $lat, $lng) <= self::NEAR_M) { $near = true; $pl['lastAt'] = (string) ($o['createdAt'] ?? gmdate('c')); if (!empty($o['address'])) { $pl['address'] = mb_substr((string) $o['address'], 0, 160); } } }
                unset($pl);
                if (!$near) { $rec['places'][] = ['lat' => $lat, 'lng' => $lng, 'address' => mb_substr((string) ($o['address'] ?? ''), 0, 160), 'firstAt' => (string) ($o['createdAt'] ?? gmdate('c')), 'lastAt' => (string) ($o['createdAt'] ?? gmdate('c'))]; }
                $rec['places'] = array_slice($rec['places'], -12);
            }
            $live = array_values(array_filter($rec['orders'], function ($x) { return empty($x['deleted']); }));
            $rec['count'] = count($rec['orders']);
            $rec['done'] = count(array_filter($rec['orders'], function ($x) { return in_array($x['status'] ?? '', ['completed', 'closed'], true); }));
            $rec['lastAt'] = (string) ($rec['orders'][0]['createdAt'] ?? gmdate('c'));
            $rec['via'] = array_values(array_unique(array_filter(array_column($rec['orders'], 'via'))));
            $rec['techs'] = array_values(array_unique(array_filter(array_column($rec['orders'], 'technicianName'))));
            $rec['liveCount'] = count($live);
            $p = end($rec['places']);
            if ($p) { $rec['lat'] = $p['lat']; $rec['lng'] = $p['lng']; $rec['address'] = $p['address']; }
            Mutqan_Store::upsert(self::COLL, $rec, $id, self::sh(), self::sys());
            return $rec;
        } catch (Throwable $e) { error_log('[mutqan] ledger ' . $e->getMessage()); return null; }
    }

    /** The order is being deleted: its snapshot stays, marked. */
    public static function on_delete($o) {
        try {
            if (!is_array($o) || empty($o['id'])) { return; }
            $id = 'cl-' . self::key_of($o);
            $rec = Mutqan_Store::get_raw(self::COLL, $id);
            if (!$rec) { self::touch($o); $rec = Mutqan_Store::get_raw(self::COLL, $id); }
            if (!$rec) { return; }
            foreach ($rec['orders'] as &$x) { if (($x['id'] ?? '') === (string) $o['id']) { $x['deleted'] = gmdate('c'); } }
            unset($x);
            Mutqan_Store::upsert(self::COLL, $rec, $id, self::sh(), self::sys());
        } catch (Throwable $e) { error_log('[mutqan] ledger del ' . $e->getMessage()); }
    }

    /**
     * Before this order: was this customer — or this very spot — served already? By phone, or a known place within
     * 40 m (a neighbour, the same building, a technician's referral). ['repeat', 'count', 'last', 'nearby', 'text'] or null.
     */
    public static function history_for($o) {
        $key = self::key_of($o);
        $mine = Mutqan_Store::get_raw(self::COLL, 'cl-' . $key);
        $prev = $mine ? array_values(array_filter((array) $mine['orders'], function ($x) use ($o) { return ($x['id'] ?? '') !== ($o['id'] ?? ''); })) : [];
        $near = null;
        if (self::has_ll($o)) {
            foreach (Mutqan_Store::select(self::COLL, [], ['limit' => 3000], self::sys()) as $r) {
                if (($r['key'] ?? '') === $key) { continue; }
                foreach ((array) ($r['places'] ?? []) as $pl) {
                    $m = self::meters((float) $o['lat'], (float) $o['lng'], (float) $pl['lat'], (float) $pl['lng']);
                    if ($m <= 150 && (!$near || $m < $near['m'])) { $near = ['m' => (int) round($m), 'name' => (string) ($r['name'] ?? ''), 'phone' => (string) ($r['phone'] ?? ''), 'key' => (string) $r['key'], 'last' => (string) ($r['orders'][0]['service'] ?? '')]; }
                }
            }
        }
        if (!$prev && !$near) { return null; }
        $L = [];
        if ($prev) {
            $last = $prev[0];
            $L[] = '🔁 عميل سابق — ' . count($prev) . ' طلب قبل كده، آخرها «' . ($last['service'] ?? '') . '»' . (!empty($last['technicianName']) ? ' مع ' . $last['technicianName'] : '') . (!empty($last['createdAt']) ? ' (' . substr((string) $last['createdAt'], 0, 10) . ')' : '');
        }
        if ($near) { $L[] = '📍 جنب عميل قديم: ' . ($near['name'] ?: $near['phone'] ?: 'عميل') . ' — على بعد ' . $near['m'] . ' م' . ($near['last'] ? ' (اتعمل عنده «' . $near['last'] . '»)' : ''); }
        return ['repeat' => (bool) $prev, 'count' => count($prev), 'last' => $prev[0] ?? null, 'nearby' => $near, 'text' => implode("\n", $L)];
    }

    /** Once: every order already in the system goes into the ledger. */
    public static function backfill($limit = 5000) {
        $n = 0;
        foreach (Mutqan_Store::select('orders', [], ['limit' => $limit, 'order' => 'createdAt'], self::sys()) as $o) { if (self::touch($o)) { $n++; } }
        update_option('mutqan_ledger_filled', gmdate('c'), false);
        return $n;
    }

    public static function boot() {
        add_action('init', function () {
            if (get_option('mutqan_ledger_filled') || get_transient('mutqan_ledger_lock')) { return; }
            set_transient('mutqan_ledger_lock', 1, 600); self::backfill(); delete_transient('mutqan_ledger_lock');
        }, 30);
    }

    /** The map's layer of every customer ever served: one point per place, with what we did there. */
    public static function map_rows($limit = 3000) {
        $out = [];
        foreach (Mutqan_Store::select(self::COLL, [], ['limit' => $limit, 'order' => '-updatedAt'], self::sys()) as $r) {
            $last = $r['orders'][0] ?? [];
            $days = !empty($r['lastAt']) ? (int) floor((time() - strtotime((string) $r['lastAt'])) / 86400) : null;
            foreach ((array) ($r['places'] ?? []) as $i => $pl) {
                $out[] = ['k' => (string) $r['key'] . ':' . $i, 'key' => (string) $r['key'], 'name' => mb_substr((string) ($r['name'] ?? ''), 0, 40), 'phone' => (string) ($r['phone'] ?? ''),
                    'lat' => (float) $pl['lat'], 'lng' => (float) $pl['lng'], 'address' => (string) ($pl['address'] ?? ''), 'n' => (int) ($r['count'] ?? 0), 'done' => (int) ($r['done'] ?? 0),
                    'days' => $days, 'last' => mb_substr((string) ($last['service'] ?? ''), 0, 60), 'lastTech' => (string) ($last['technicianName'] ?? ''), 'lastId' => (string) ($last['id'] ?? ''),
                    'lastDeleted' => !empty($last['deleted']), 'via' => (array) ($r['via'] ?? []), 'techs' => (array) ($r['techs'] ?? [])];
            }
        }
        return $out;
    }

    /** One customer's whole record (orders included), for his card. */
    public static function card($key) {
        $r = Mutqan_Store::get_raw(self::COLL, 'cl-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $key));
        return $r ?: null;
    }

    const PIN_KINDS = ['client' => '👤', 'active_client' => '🟢', 'old_client' => '🔁', 'tech' => '🧰', 'shop' => '🏪', 'other' => '📌'];

    /** A pin the owner drops by hand on the map: a customer, a technician, a shop… kept in `client_pins`. */
    public static function pin_save($in, $p) {
        $lat = $in['lat'] ?? null; $lng = $in['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) { throw new Mutqan_Error('bad_input', 'اضغط على المكان في الخريطة.', 400); }
        $kind = isset(self::PIN_KINDS[$in['kind'] ?? '']) ? (string) $in['kind'] : 'other';
        $doc = ['kind' => $kind, 'emoji' => self::PIN_KINDS[$kind], 'lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6),
            'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80), 'phone' => mb_substr(preg_replace('/[^\d+]/', '', (string) ($in['phone'] ?? '')), 0, 20),
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 300), 'by' => (string) ($p['id'] ?? ''), 'byName' => (string) ($p['name'] ?? '')];
        if (!empty($in['id'])) { return Mutqan_Store::update('client_pins', (string) $in['id'], $doc, $p); }
        return Mutqan_Store::insert('client_pins', $doc + ['at' => gmdate('c')], null, self::sh(), $p);
    }

    public static function pins() {
        return array_map(function ($x) { return ['id' => (string) $x['id'], 'kind' => (string) ($x['kind'] ?? 'other'), 'emoji' => (string) ($x['emoji'] ?? '📌'), 'lat' => (float) $x['lat'], 'lng' => (float) $x['lng'],
            'name' => (string) ($x['name'] ?? ''), 'phone' => (string) ($x['phone'] ?? ''), 'note' => (string) ($x['note'] ?? ''), 'byName' => (string) ($x['byName'] ?? '')]; },
            array_filter(Mutqan_Store::select('client_pins', [], ['limit' => 2000], self::sys()), function ($x) { return is_numeric($x['lat'] ?? null); }));
    }
}
