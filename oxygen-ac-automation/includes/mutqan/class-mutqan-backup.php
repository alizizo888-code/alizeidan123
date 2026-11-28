<?php
/**
 * «النسخ الاحتياطية» (owner, 2026-11-28): the whole system data (orders, customers, technicians, accounts, settings,
 * users and invitations) in one compressed file, kept in a private folder.
 *
 *  - automatic: one copy every week; the last 4 are kept (4 weeks to go back to), older ones are removed.
 *  - manual: «نسخة الآن» from the owner page (the last 3 kept), and a safety copy before every restore.
 *  - restore: replaces the current data with a copy — a safety copy of the current data is taken first.
 *  - export: download any copy (signed short link). import: upload a copy file to keep it and/or restore it.
 * Owner only. Sessions are never copied (nobody is logged in or out by a restore except by the users table itself).
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Backup {
    const CRON = 'mutqan_backup_weekly';
    const KEEP_AUTO = 4;
    const KEEP_MANUAL = 3;
    const KEEP_SAFETY = 2;
    const TABLES = ['docs', 'users', 'invites', 'kv'];
    const MAX_IMPORT = 60 * 1024 * 1024;

    public static function boot() {
        add_action(self::CRON, [__CLASS__, 'auto']);
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 3600, 'weekly', self::CRON); } }, 5);
        add_action('rest_api_init', function () {
            register_rest_route(Mutqan_Rest::NS, '/backup', ['methods' => 'POST', 'permission_callback' => '__return_true',
                'callback' => function ($req) { return self::rest($req); }]);
            register_rest_route(Mutqan_Rest::NS, '/backup-file', ['methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => function ($req) { self::download((string) $req->get_param('t')); }]);
        });
    }

    public static function dir() {
        $up = wp_upload_dir();
        $d = trailingslashit($up['basedir']) . 'mutqan-private/backups';
        if (!is_dir($d)) { wp_mkdir_p($d); }
        if (!file_exists($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($d . '/index.html', '');
        }
        return $d;
    }
    private static function key() { return hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : 'mq') . '|backup|' . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '')); }
    private static function owner($p) { if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'النسخ الاحتياطية للمالك فقط.', 403); } }
    private static function safe_name($n) { return preg_match('/^mq-(auto|manual|safety|import)-\d{8}-\d{6}-[a-f0-9]{6}\.json\.gz$/', (string) $n) ? (string) $n : ''; }

    /* ------------------------------------------------------------------ REST */

    private static function rest($req) {
        try {
            Mutqan_Auth::reset_memo();
            $p = Mutqan_Auth::principal($req);
            $in = $req->get_json_params() ?: [];
            return new WP_REST_Response(['data' => self::handle($in, $p)], 200);
        } catch (Mutqan_Error $e) {
            return new WP_REST_Response(['error' => ['code' => $e->slug, 'message' => $e->getMessage()]], $e->status);
        } catch (Throwable $e) {
            error_log('[mutqan backup] ' . $e->getMessage());
            return new WP_REST_Response(['error' => ['code' => 'server_error', 'message' => 'تعذّر تنفيذ عملية النسخ الاحتياطي.']], 500);
        }
    }

    /** {op: list | create | restore | link | delete | import} */
    public static function handle($in, $p) {
        self::owner($p);
        $op = (string) ($in['op'] ?? 'list');
        if ($op === 'list') { return ['items' => self::items(), 'next' => (int) (wp_next_scheduled(self::CRON) ?: 0), 'keep' => self::KEEP_AUTO]; }
        if ($op === 'create') { $b = self::make('manual', (string) ($p['name'] ?? 'المالك'), (string) ($in['note'] ?? '')); return ['ok' => true, 'item' => $b, 'items' => self::items()]; }
        $name = self::safe_name($in['name'] ?? '');
        if ($op === 'link') {
            if (!$name || !is_file(self::dir() . '/' . $name)) { throw new Mutqan_Error('not_found', 'النسخة غير موجودة.', 404); }
            $exp = time() + 600; $sig = hash_hmac('sha256', $name . '|' . $exp, self::key());
            return ['url' => rest_url(Mutqan_Rest::NS . '/backup-file') . '?t=' . rawurlencode(base64_encode($name . '|' . $exp . '|' . $sig))];
        }
        if ($op === 'delete') {
            if (!$name) { throw new Mutqan_Error('not_found', 'النسخة غير موجودة.', 404); }
            @unlink(self::dir() . '/' . $name);
            return ['ok' => true, 'items' => self::items()];
        }
        if ($op === 'restore') {
            if (!$name || !is_file(self::dir() . '/' . $name)) { throw new Mutqan_Error('not_found', 'النسخة غير موجودة.', 404); }
            if (($in['confirm'] ?? '') !== 'استرجاع') { throw new Mutqan_Error('confirm', 'اكتب «استرجاع» لتأكيد الاسترجاع.', 400); }
            $safety = self::make('safety', (string) ($p['name'] ?? 'المالك'), 'قبل استرجاع ' . $name);
            $count = self::restore_file(self::dir() . '/' . $name);
            return ['ok' => true, 'restored' => $count, 'safety' => $safety['name'], 'items' => self::items()];
        }
        if ($op === 'import') {
            $raw = base64_decode(preg_replace('/^data:[^,]+,/', '', (string) ($in['file'] ?? '')), true);
            if ($raw === false || $raw === '') { throw new Mutqan_Error('bad_input', 'الملف فاضي.', 400); }
            if (strlen($raw) > self::MAX_IMPORT) { throw new Mutqan_Error('too_large', 'الملف أكبر من المسموح (60 ميجا).', 413); }
            $tmp = self::dir() . '/upload-' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, $raw);
            $head = self::read_head($tmp);
            if (!$head) { @unlink($tmp); throw new Mutqan_Error('bad_file', 'الملف مش نسخة احتياطية من النظام.', 422); }
            $final = self::new_name('import');
            rename($tmp, self::dir() . '/' . $final);
            self::write_meta($final, ['kind' => 'import', 'at' => gmdate('c'), 'by' => (string) ($p['name'] ?? 'المالك'), 'rows' => $head['rows'] ?? [], 'site' => $head['site'] ?? '', 'note' => 'مستوردة — أصلها ' . ($head['at'] ?? '')]);
            return ['ok' => true, 'name' => $final, 'items' => self::items()];
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    /* ------------------------------------------------------------------ making / reading copies */

    public static function auto() { try { self::make('auto', 'تلقائي', 'النسخة الأسبوعية'); } catch (Throwable $e) { error_log('[mutqan backup auto] ' . $e->getMessage()); } }

    private static function new_name($kind) { return 'mq-' . $kind . '-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.json.gz'; }

    public static function make($kind, $by = '', $note = '') {
        global $wpdb;
        @set_time_limit(300);
        $name = self::new_name($kind);
        $path = self::dir() . '/' . $name;
        $gz = gzopen($path . '.part', 'wb6');
        if (!$gz) { throw new Mutqan_Error('server_error', 'تعذّر إنشاء ملف النسخة.', 500); }
        $rows = [];
        gzwrite($gz, wp_json_encode(['mutqan_backup' => 2, 'at' => gmdate('c'), 'site' => home_url('/'), 'tables' => self::TABLES]) . "\n");
        foreach (self::TABLES as $t) {
            $table = Mutqan_Store::table($t); $rows[$t] = 0;
            $order = $t === 'docs' ? 'pk' : ($t === 'users' ? 'id' : ($t === 'invites' ? 'code' : 'k'));
            for ($off = 0; ; $off += 500) {
                $chunk = $wpdb->get_results("SELECT * FROM $table ORDER BY $order LIMIT 500 OFFSET $off", ARRAY_A);
                if (!$chunk) { break; }
                foreach ($chunk as $r) { gzwrite($gz, wp_json_encode(['t' => $t, 'r' => $r], JSON_UNESCAPED_UNICODE) . "\n"); $rows[$t]++; }
                if (count($chunk) < 500) { break; }
            }
        }
        gzwrite($gz, wp_json_encode(['end' => true, 'rows' => $rows]) . "\n");
        gzclose($gz);
        rename($path . '.part', $path);
        $meta = ['kind' => $kind, 'at' => gmdate('c'), 'by' => $by, 'note' => mb_substr($note, 0, 160), 'rows' => $rows, 'site' => home_url('/')];
        self::write_meta($name, $meta);
        self::prune();
        return ['name' => $name] + $meta + ['size' => (int) @filesize($path)];
    }

    private static function write_meta($name, $meta) { @file_put_contents(self::dir() . '/' . $name . '.meta', wp_json_encode($meta, JSON_UNESCAPED_UNICODE)); }

    /** Header + row counts of a copy file, or null when it is not one of ours. */
    public static function read_head($path) {
        $gz = @gzopen($path, 'rb'); if (!$gz) { return null; }
        $first = json_decode((string) gzgets($gz, 1 << 20), true);
        if (!is_array($first) || empty($first['mutqan_backup'])) { gzclose($gz); return null; }
        $rows = null;
        while (!gzeof($gz)) { $l = gzgets($gz, 1 << 22); if ($l !== false && strpos($l, '"end"') !== false) { $e = json_decode($l, true); if (!empty($e['end'])) { $rows = $e['rows'] ?? []; } } }
        gzclose($gz);
        if ($rows === null) { return null; }   // cut-off file: refuse it rather than restore half the data
        return $first + ['rows' => $rows];
    }

    public static function items() {
        $out = [];
        foreach ((array) glob(self::dir() . '/mq-*.json.gz') as $f) {
            $n = basename($f); $m = json_decode((string) @file_get_contents($f . '.meta'), true) ?: [];
            $out[] = ['name' => $n, 'kind' => $m['kind'] ?? 'manual', 'at' => $m['at'] ?? gmdate('c', (int) filemtime($f)), 'by' => $m['by'] ?? '', 'note' => $m['note'] ?? '', 'rows' => $m['rows'] ?? [], 'size' => (int) filesize($f)];
        }
        usort($out, function ($a, $b) { return strcmp($b['at'], $a['at']); });
        return $out;
    }

    private static function prune() {
        $by = [];
        foreach (self::items() as $it) { $by[$it['kind']][] = $it; }
        foreach (['auto' => self::KEEP_AUTO, 'manual' => self::KEEP_MANUAL, 'safety' => self::KEEP_SAFETY, 'import' => self::KEEP_MANUAL] as $k => $keep) {
            foreach (array_slice($by[$k] ?? [], $keep) as $old) { @unlink(self::dir() . '/' . $old['name']); @unlink(self::dir() . '/' . $old['name'] . '.meta'); }
        }
    }

    /* ------------------------------------------------------------------ restore */

    public static function restore_file($path) {
        global $wpdb;
        if (!self::read_head($path)) { throw new Mutqan_Error('bad_file', 'النسخة ناقصة أو تالفة — لم يتم أي تغيير.', 422); }
        @set_time_limit(600);
        $gz = gzopen($path, 'rb');
        gzgets($gz, 1 << 20);
        $wpdb->query(Mutqan_Store::is_sqlite() ? 'BEGIN' : 'START TRANSACTION');
        $count = [];
        try {
            foreach (self::TABLES as $t) { $wpdb->query('DELETE FROM ' . Mutqan_Store::table($t)); $count[$t] = 0; }
            while (!gzeof($gz)) {
                $l = gzgets($gz, 1 << 22); if ($l === false || trim($l) === '') { continue; }
                $j = json_decode($l, true);
                if (!is_array($j) || !empty($j['end'])) { continue; }
                $t = (string) ($j['t'] ?? '');
                if (!in_array($t, self::TABLES, true) || !is_array($j['r'] ?? null)) { continue; }
                if ($wpdb->insert(Mutqan_Store::table($t), $j['r']) === false) { throw new RuntimeException('insert failed in ' . $t); }
                $count[$t]++;
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            gzclose($gz);
            throw new Mutqan_Error('restore_failed', 'تعذّر الاسترجاع ورجعت البيانات زي ما كانت. ' . $e->getMessage(), 500);
        }
        gzclose($gz);
        foreach (['orders', 'users', 'settings'] as $c) { try { Mutqan_Store::touch($c); } catch (Throwable $e) { /* cache stamps only */ } }
        return $count;
    }

    /* ------------------------------------------------------------------ export (signed link) */

    private static function download($t) {
        $parts = explode('|', (string) base64_decode($t, true));
        if (count($parts) !== 3) { status_header(403); exit; }
        [$name, $exp, $sig] = $parts;
        $name = self::safe_name($name);
        if (!$name || (int) $exp < time() || !hash_equals(hash_hmac('sha256', $name . '|' . $exp, self::key()), $sig)) { status_header(403); echo 'الرابط انتهى — اطلب رابط جديد من صفحة النسخ الاحتياطية.'; exit; }
        $f = self::dir() . '/' . $name;
        if (!is_file($f)) { status_header(404); exit; }
        nocache_headers();
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
}
