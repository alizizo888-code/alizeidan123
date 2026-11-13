<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Hotline voice relay through this site, for when the two phones cannot reach each other directly.
 *
 * A hotline call is WebRTC: the voice goes phone-to-phone. On most mobile networks (carrier NAT) that only works
 * with a TURN server, and there is no TURN server that is free and needs no sign-up. So when the direct path does
 * not come up, both sides fall back to sending their voice here in short self-contained clips (about a second each)
 * and fetching the other side's clips. It is a little behind real time, but it works on any network, costs nothing
 * and needs nothing outside this hosting.
 *
 * Only the two people on the call can use it: the customer who placed the call (its creator) and the operations
 * team. Clips live ten minutes and are never kept.
 */
final class Mutqan_Relay {
    const COLL = '_relay';
    const MAX_B64 = 90000;      // ~65 KB of audio: a second of opus is ~4 KB, so this is plenty
    const TTL = 600;

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    /** 'c' for the caller (customer), 's' for staff; throws for anybody else. */
    private static function side($callId, $p) {
        if (($p['kind'] ?? 'guest') === 'guest') { throw new Mutqan_Error('unauthorized', 'افتح التطبيق من جديد.', 401); }
        if (!preg_match('/^[A-Za-z0-9_.:@-]{1,64}$/', $callId)) { throw new Mutqan_Error('bad_request', 'مكالمة غير صالحة.', 400); }
        $call = Mutqan_Store::get_raw('calls', $callId);
        if (!$call) { throw new Mutqan_Error('not_found', 'المكالمة غير موجودة.', 404); }
        if (in_array($call['status'] ?? '', ['ended', 'missed', 'declined'], true)) { throw new Mutqan_Error('ended', 'انتهت المكالمة.', 410); }
        if (Mutqan_Policy::is_staff($p) && ($p['role'] ?? '') !== 'technician') { return 's'; }
        if (Mutqan_Store::owner_of('calls', $callId) === ($p['id'] ?? '#')) { return 'c'; }
        throw new Mutqan_Error('forbidden', 'غير مسموح.', 403);
    }

    public static function handle($in, $p) {
        $op = (string) ($in['op'] ?? '');
        $id = (string) ($in['id'] ?? '');
        $me = self::side($id, $p);
        Mutqan_Limiter::hit('relay', 4000, 3600);
        if ($op === 'put') {
            $seq = (int) ($in['seq'] ?? -1);
            $b = (string) ($in['b'] ?? '');
            $mime = (string) ($in['mime'] ?? '');
            if ($seq < 0 || $seq > 100000 || $b === '' || strlen($b) > self::MAX_B64 || !preg_match('#^[A-Za-z0-9+/=]+$#', $b)) { throw new Mutqan_Error('bad_request', 'مقطع غير صالح.', 400); }
            if (!preg_match('#^audio/[a-z0-9.+-]+(;\s*codecs=[a-z0-9.,"\s-]+)?$#i', $mime)) { $mime = 'audio/webm'; }
            $doc = ['callId' => $id, 'side' => $me, 'seq' => $seq, 'mime' => $mime, 'b' => $b];
            $rid = 'r:' . substr(md5($id), 0, 16) . ':' . $me . ':' . $seq;
            try { Mutqan_Store::insert(self::COLL, $doc, $rid, ['expiresAt' => gmdate('c', time() + self::TTL)], self::sys(), true); }
            catch (Mutqan_Error $e) { if ($e->slug !== 'exists') { throw $e; } }   // a retried upload of the same clip
            return ['ok' => true];
        }
        if ($op === 'get') {
            $after = (int) ($in['after'] ?? -1);
            $them = $me === 'c' ? 's' : 'c';
            // Arrival order from the database (a JSON number sorts as text there), then by number here.
            $rows = Mutqan_Store::select(self::COLL, ['callId' => $id, 'side' => $them, 'seq' => ['gt' => $after]], ['limit' => 8, 'order' => 'createdAt'], self::sys());
            usort($rows, function ($a, $b) { return (int) $a['seq'] <=> (int) $b['seq']; });
            return ['clips' => array_map(function ($r) { return ['seq' => (int) $r['seq'], 'mime' => (string) $r['mime'], 'b' => (string) $r['b']]; }, $rows)];
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }
}
