<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The network as the owner and operations see it: all 22 sites (and any linked later) in one list, with how much
 * work each one brings into the shared operations room, and — for the owner — linking a new site or unlinking one.
 */
final class Mutqan_Network {
    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }
    const OPEN = ['new', 'forced', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    public static function handle($in, $p) {
        Mutqan_Auth::require_user($p);
        $op = (string) ($in['op'] ?? 'list');
        if ($op === 'brands') { return ['sites' => self::brands()]; }       // names/colours for any signed-in team screen
        if (!in_array($p['role'] ?? '', ['owner', 'operations', 'supervisor'], true)) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات فقط.', 403); }
        if ($op === 'list') { return ['sites' => self::sites(), 'hub' => Oxygen_Network::HUB_HOST]; }
        Mutqan_Auth::require_owner($p);
        try {
            if ($op === 'add') { return ['site' => Oxygen_Network::add_extra($in['name'] ?? '', $in['host'] ?? '', $in['color'] ?? ''), 'steps' => self::steps()]; }
            if ($op === 'remove') {
                $id = (string) ($in['id'] ?? '');
                if (empty(Oxygen_Network::registry()[$id]['extra'])) { throw new InvalidArgumentException('مواقع الشبكة الأساسية ما بتتشالش من هنا.'); }
                return ['ok' => Oxygen_Network::remove_extra($id)];
            }
        } catch (InvalidArgumentException $e) { throw new Mutqan_Error('bad_site', $e->getMessage(), 422); }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    public static function brands() {
        $out = [];
        foreach (Oxygen_Network::registry() as $id => $b) { $out[] = ['id' => $id, 'name' => $b['name'], 'color' => $b['color'], 'host' => Oxygen_Network::host_of($id), 'core' => !empty($b['core']), 'extra' => !empty($b['extra'])]; }
        return $out;
    }

    /** Every site with its numbers in the shared room: orders in the last 30 days, open now, today, last order. */
    public static function sites() {
        $since = gmdate('c', time() - 30 * 86400); $today = substr(gmdate('c'), 0, 10); $by = [];
        foreach (Mutqan_Store::select('orders', ['createdAt' => ['gte' => $since]], ['order' => '-createdAt', 'limit' => 3000], self::sys()) as $o) {
            $k = (string) ($o['brand'] ?? '') ?: 'oxygen';
            $r = $by[$k] ?? ['month' => 0, 'open' => 0, 'today' => 0, 'last' => ''];
            $r['month']++;
            if (in_array($o['status'] ?? '', self::OPEN, true)) { $r['open']++; }
            if (substr((string) ($o['createdAt'] ?? ''), 0, 10) === $today) { $r['today']++; }
            if ($r['last'] === '') { $r['last'] = (string) ($o['createdAt'] ?? ''); }
            $by[$k] = $r;
        }
        $out = [];
        foreach (self::brands() as $b) { $out[] = $b + ($by[$b['id']] ?? ['month' => 0, 'open' => 0, 'today' => 0, 'last' => '']); }
        // Orders tagged with a site that is not (or no longer) in the list still show, so nothing gets lost.
        foreach ($by as $k => $r) { if (!Oxygen_Network::valid($k)) { $out[] = ['id' => $k, 'name' => $k, 'color' => '#64748b', 'host' => '', 'core' => false, 'extra' => false, 'unknown' => true] + $r; } }
        return $out;
    }

    /** What the owner does after linking, in order. */
    public static function steps() {
        return [
            'وجّه الدومين للاستضافة على هوستنجر (DNS) وثبّت ووردبريس عليه.',
            'ثبّت إضافة أكسجين نفسها (نفس نسخة المواقع الـ22) وفعّلها.',
            'افتح الموقع: هيعرف اسمه ولونه من المركز خلال دقائق، وكل طلباته ومحادثاته هتدخل غرفة العمليات دي.',
            'لو عايز رقم تواصل خاص بيه غيّره من إعدادات الموقع، وإلا هيستخدم أرقام الشركة.',
        ];
    }
}
