<?php
if (!defined('ABSPATH')) { exit; }

/**
 * What the installed Android apps do beyond showing the site.
 *
 *   - /apk?f=oxygen-desktech.apk — one address per app that never changes. It sends the phone straight to the public
 *     release file (GitHub's CDN: fast, resumable, no load on this server). The old links pointed at files that were
 *     never on desk.oxygen11.com, which is the 404 page people were getting.
 *   - /native op=notifs — WhatsApp (and WhatsApp Business) notifications a team phone saw, sent by the app's
 *     notification reader once the person turned it on. They land in «phone_inbox» for the operations room, a message
 *     that looks like an order or an emergency raises an alert, and a team member's own command is answered.
 *   - /native op=config — what the app needs to run in the background: where to send positions and notifications,
 *     how often, and which navigation apps to offer.
 */
final class Mutqan_Native {
    const RELEASE = 'https://github.com/alizizo888-code/alizeidan123/releases/download/apps-latest/';
    const APKS = ['oxygen-app.apk', 'oxygen-ops.apk', 'oxygen-deskops.apk', 'oxygen-deskctl.apk', 'oxygen-desktech.apk'];
    const WA_APPS = ['com.whatsapp', 'com.whatsapp.w4b'];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    /** The stable download address of an app (served by this site, redirected to the release). */
    public static function apk_url($file) { return rest_url(Mutqan_Rest::NS . '/apk?f=' . rawurlencode($file)); }

    /** Where /apk sends the phone. Filterable, so the files can move (own hosting, a CDN) without touching any link. */
    public static function apk_target($file) {
        $file = (string) $file;
        if (!in_array($file, self::APKS, true)) { return ''; }
        $base = (string) apply_filters('mutqan_apk_base', get_option('mutqan_apk_base', self::RELEASE));
        return rtrim($base, '/') . '/' . $file;
    }

    public static function handle($in, $p) {
        Mutqan_Auth::require_user($p);
        $op = (string) ($in['op'] ?? 'config');
        if ($op === 'config') { return self::config($p); }
        if ($op === 'notifs') { return self::notifs((array) ($in['items'] ?? []), $p, (string) ($in['device'] ?? '')); }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    public static function config($p) {
        $role = (string) ($p['role'] ?? '');
        $field = in_array($role, ['technician', 'supervisor', 'operations'], true);
        return [
            'gps' => $field ? ['every' => $role === 'technician' ? 30 : 120, 'minMeters' => 25, 'path' => $role === 'technician' ? '/track' : '/ops', 'background' => true] : null,
            'readWhatsApp' => Mutqan_Policy::is_staff($p),
            'nav' => ['waze', 'google', 'geo'],
            'tools' => ['termux' => 'com.termux', 'whatsapp' => 'com.whatsapp', 'whatsappBusiness' => 'com.whatsapp.w4b', 'waze' => 'com.waze', 'maps' => 'com.google.android.apps.maps'],
        ];
    }

    /** WhatsApp notifications a team phone saw. Kept short, deduplicated, and only from WhatsApp. */
    public static function notifs($items, $p, $device = '') {
        if (!Mutqan_Policy::is_staff($p)) { throw new Mutqan_Error('forbidden', 'للفريق فقط.', 403); }
        Mutqan_Limiter::hit('native_notifs', 600, 3600);
        $saved = 0; $alerts = 0; $answers = [];
        foreach (array_slice($items, 0, 50) as $it) {
            if (!is_array($it)) { continue; }
            $app = (string) ($it['app'] ?? '');
            if (!in_array($app, self::WA_APPS, true)) { continue; }
            $title = mb_substr(trim(wp_strip_all_tags((string) ($it['title'] ?? ''))), 0, 80);
            $text = mb_substr(trim(wp_strip_all_tags((string) ($it['text'] ?? ''))), 0, 600);
            if ($text === '' || preg_match('/^\d+ (رسائل|رسالة|new messages?)/u', $text)) { continue; }   // summary lines, not messages
            $at = is_numeric($it['at'] ?? null) ? gmdate('c', (int) ((float) $it['at'] > 1e12 ? $it['at'] / 1000 : $it['at'])) : gmdate('c');
            $id = 'n' . substr(md5(($p['id'] ?? '') . '|' . $app . '|' . $title . '|' . $text), 0, 22);
            if (Mutqan_Store::get_raw('phone_inbox', $id)) { continue; }
            $kind = self::kind_of($text);
            Mutqan_Store::upsert('phone_inbox', ['app' => $app, 'from' => $title, 'text' => $text, 'at' => $at, 'kind' => $kind,
                'by' => (string) ($p['id'] ?? ''), 'byName' => (string) ($p['name'] ?? ''), 'device' => mb_substr($device, 0, 40)], $id, ['expiresAt' => gmdate('c', time() + 14 * 86400)], self::sys());
            $saved++;
            if ($kind === 'emergency' || $kind === 'order') {
                $alerts++;
                Mutqan_Store::insert('_push', ['target' => ['role' => 'operations'], 'title' => ($kind === 'emergency' ? '🚨 طوارئ على واتساب: ' : '🛎️ طلب على واتساب: ') . $title,
                    'body' => mb_substr($text, 0, 200), 'url' => '#/staff/wa-inbox', 'sound' => 'bell', 'priority' => 'high', 'event' => 'phone_wa', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 86400)], self::sys());
            }
        }
        return ['ok' => true, 'saved' => $saved, 'alerts' => $alerts, 'answers' => $answers];
    }

    public static function kind_of($text) {
        $t = (string) $text;
        if (preg_match('/طوار[ئي]|عاجل|حريق|شرار|ماس|تسريب|دخان|انفجار/u', $t)) { return 'emergency'; }
        if (preg_match('/مكيف|تكييف|صيانة|تنظيف|فريون|سعر|كم\s*السعر|عطل|ابغى|أبغى|ابي|محتاج|موعد|فني/u', $t)) { return 'order'; }
        return 'message';
    }
}
