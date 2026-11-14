<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The internal engine: owner and operations commands that need no model at all.
 *
 * The usual commands ("اسند طلب 1024 لمحمود", "نضف المواقع كلها من الكاش", "الطلبات الجديدة", "فين الفنيين")
 * are recognised by plain rules here and run straight away through the same closed tool list the assistant uses.
 * No provider, no queue, no daily limit and no points, so they answer in a fraction of a second even on the day
 * every AI provider is busy. Anything the rules do not recognise goes on to the model as before.
 *
 * Changes that the assistant would ask to confirm (WhatsApp messages, DNS, settings…) still wait for «تنفيذ».
 */
final class Mutqan_Quick {

    public static function norm($s) {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function has($n, $words) { foreach ((array) $words as $w) { if (mb_strpos($n, $w) !== false) { return true; } } return false; }

    /** What the rules understood: ['kind' => 'agent'|'ops'|'self', 'tool' => …, 'input' => […]] or null. */
    public static function match($text) {
        $n = self::norm($text);
        if ($n === '' || mb_strlen($n) > 300) { return null; }

        if (preg_match('/^(مساعده|الاوامر|اوامر|help|\?|ايه الاوامر|تقدر تعمل ايه)$/u', $n)) { return ['kind' => 'self', 'tool' => 'help', 'input' => []]; }

        if (self::has($n, ['كاش', 'cache', 'الكاشات']) && self::has($n, ['نضف', 'نظف', 'امسح', 'مسح', 'فضي', 'فرغ', 'clear', 'purge', 'شيل', 'تنظيف'])) {
            $all = self::has($n, ['كل', 'المواقع', 'الشبكه', 'all']);
            return ['kind' => 'self', 'tool' => 'cache_purge', 'input' => ['all' => $all]];
        }

        if (self::has($n, ['نقاط', 'رصيد التوكن', 'التوكن', 'توكنز'])) { return ['kind' => 'self', 'tool' => 'points', 'input' => []]; }

        if (self::has($n, ['حاله الذكاء', 'المزودين', 'اختبر الذكاء', 'صحي الذكاء', 'شغل الذكاء', 'الذكاء واقف', 'جرب المزودين'])) {
            return ['kind' => 'agent', 'tool' => 'ai_engine', 'input' => ['test' => true]];
        }

        // اسند / اسناد / حوّل / ادّي طلب 1024 لمحمود
        if (preg_match('/(اسند|اسناد|سند|حول|ادي|اعطي|وزع|ابعت)\s*(ال)?(طلب)?\s*(رقم)?\s*#?(\d{1,10})\s*(ل|الي|علي|لل|لـ)\s*(الفني|فني)?\s*(.{2,40})$/u', $n, $m)) {
            return ['kind' => 'self', 'tool' => 'assign', 'input' => ['number' => $m[5], 'tech' => trim($m[8])]];
        }

        // طلب 1024 / تفاصيل طلب 1024 / قصة الطلب 1024
        if (preg_match('/^(تفاصيل|قصه|فين|حاله|اعرض|وريني)?\s*(ال)?طلب\s*(رقم)?\s*#?(\d{1,10})$/u', $n, $m)) {
            return ['kind' => 'ops', 'tool' => 'order_story', 'input' => ['number' => $m[4]]];
        }

        // خط سير محمود / مهام على طريق سامي / تتبع ف-03 / الخريطة الحية
        if (self::has($n, ['خط سير', 'مسار', 'تتبع', 'علي طريق', 'في طريق'])) {
            $who = trim(preg_replace('/^.*?(خط سير|مسار|تتبع|علي طريق|في طريق)\s*(الفني)?\s*(ل|لـ)?\s*/u', '', $n));
            return ['kind' => 'self', 'tool' => 'route', 'input' => ['name' => $who]];
        }
        if (self::has($n, ['الخريطه الحيه', 'مين علي الطريق', 'مين ماشي', 'فين الفنيين', 'الفنيين فين', 'مكان الفنيين', 'اماكن الفنيين', 'مواقع الفنيين'])) { return ['kind' => 'self', 'tool' => 'route', 'input' => ['name' => '']]; }
        if (self::has($n, ['فين المشرفين', 'المشرفين فين'])) { return ['kind' => 'ops', 'tool' => 'where_supervisors', 'input' => []]; }
        if (self::has($n, ['تنبيهات', 'التنبيهات', 'المشاكل', 'متاخر', 'المتاخره'])) { return ['kind' => 'ops', 'tool' => 'alerts', 'input' => []]; }
        if (self::has($n, ['فلوس', 'الايراد', 'ايرادات', 'المصاريف', 'الربح', 'الخزنه', 'الحسابات'])) {
            $days = self::has($n, ['الشهر', 'شهر']) ? 30 : (self::has($n, ['الاسبوع', 'اسبوع']) ? 7 : 1);
            return ['kind' => 'ops', 'tool' => 'money_report', 'input' => ['days' => $days]];
        }
        if (self::has($n, ['ايه اللي حصل', 'سجل', 'النشاط', 'activity'])) { return ['kind' => 'ops', 'tool' => 'activity_log', 'input' => ['hours' => 24, 'limit' => 25]]; }
        if (self::has($n, ['الوضع', 'ملخص', 'نظره عامه', 'الدنيا عامله', 'اخبار الشغل', 'تقرير اليوم'])) { return ['kind' => 'ops', 'tool' => 'ops_overview', 'input' => []]; }

        if (self::has($n, ['الطلبات', 'طلبات'])) {
            if (self::has($n, ['جديد', 'مش متوزع', 'من غير فني', 'منتظر'])) { return ['kind' => 'agent', 'tool' => 'orders_list', 'input' => ['status' => 'new', 'limit' => 15]]; }
            if (self::has($n, ['مفتوح', 'شغاله', 'الحاليه'])) { return ['kind' => 'ops', 'tool' => 'ops_overview', 'input' => []]; }
            return ['kind' => 'agent', 'tool' => 'orders_list', 'input' => ['limit' => 15]];
        }
        if (self::has($n, ['الفنيين', 'فنيين', 'الفنين'])) { return ['kind' => 'agent', 'tool' => 'techs_list', 'input' => []]; }
        if (self::has($n, ['واتساب', 'رسايل', 'رسائل', 'المحادثات'])) { return ['kind' => 'agent', 'tool' => 'wa_recent', 'input' => ['limit' => 10]]; }
        if (self::has($n, ['احصائيات', 'الارقام', 'ارقام الطلبات'])) { return ['kind' => 'agent', 'tool' => 'stats_summary', 'input' => ['days' => self::has($n, ['شهر']) ? 30 : 7]]; }
        return null;
    }

    /**
     * Runs a recognised command for the owner / operations. Returns the assistant's usual reply shape, or null when the
     * rules did not recognise the text (the caller then asks the model).
     */
    public static function try_run($text, $p, $from = 'agent') {
        $m = self::match($text);
        if (!$m) { return null; }
        $role = (string) ($p['role'] ?? '');
        if (!in_array($role, ['owner', 'operations', 'supervisor'], true)) { return null; }
        // The operations manager tab is read-only: anything that changes something stays on the control assistant.
        if ($from === 'ops' && ($m['kind'] === 'agent' && Mutqan_Agent::needs_confirm($m['tool'], $m['input']) || in_array($m['tool'], ['assign', 'cache_purge'], true))) {
            return ['text' => 'ده أمر تنفيذ — اكتبه في تبويب «أوامر» وهيتنفذ على طول.', 'steps' => [], 'pending' => null, 'engine' => 'internal'];
        }
        if ($role === 'supervisor' && $m['kind'] !== 'ops' && $m['tool'] !== 'route') { return null; }
        try {
            $out = self::run($m, $p);
        } catch (Throwable $e) {
            return ['text' => 'ما اتنفذش: ' . $e->getMessage(), 'steps' => [['tool' => $m['tool'], 'status' => 'error']], 'pending' => null, 'engine' => 'internal'];
        }
        if (class_exists('Mutqan_Points')) { Mutqan_Points::internal(); }
        return $out + ['engine' => 'internal'];
    }

    private static function run($m, $p) {
        $tool = $m['tool']; $in = $m['input'];
        if ($m['kind'] === 'agent') {
            if (Mutqan_Agent::needs_confirm($tool, $in)) {
                return ['text' => 'جاهز — راجع وأكّد.', 'steps' => [['tool' => $tool, 'status' => 'pending']], 'pending' => Mutqan_Agent::park_public($tool, $in, $p)];
            }
            $out = Mutqan_Agent::run_tool($tool, $in, $p);
            Mutqan_Agent::audit_public($tool, $in, true, $p);
            return ['text' => self::say($tool, $out), 'steps' => [['tool' => $tool, 'status' => 'done']], 'pending' => null];
        }
        if ($m['kind'] === 'ops') {
            $out = Mutqan_Ops::run_tool($tool, $in);
            return ['text' => self::say($tool, $out), 'steps' => [['tool' => $tool, 'status' => 'done']], 'pending' => null];
        }
        switch ($tool) {
            case 'help': return ['text' => self::help(), 'steps' => [], 'pending' => null];
            case 'route': {
                $name = (string) ($in['name'] ?? '');
                if (preg_match('/^ف\s*-?\s*(\d{1,3})$/u', $name, $mm)) { $name = 'ف-' . str_pad($mm[1], 2, '0', STR_PAD_LEFT); }
                return ['text' => Mutqan_Route::brief(Mutqan_Route::fleet(), $name), 'steps' => [['tool' => 'fleet', 'status' => 'done']], 'pending' => null];
            }
            case 'points': {
                $s = Mutqan_Points::state();
                $t = $s['unlimited'] ? 'النقاط: بدون حد شهري.' : 'رصيد النقاط: ' . number_format($s['balance']) . ' من ' . number_format((int) $s['monthly'] + (int) $s['topup']) . ' الشهر ده.';
                return ['text' => $t . "\nاتصرف: " . number_format((int) $s['used']) . ' نقطة في ' . (int) $s['calls'] . ' طلب لـ Claude، و' . (int) $s['internal'] . ' أمر اتنفذ داخلي ببلاش.', 'steps' => [], 'pending' => null, 'points' => $s];
            }
            case 'cache_purge': {
                if (($p['role'] ?? '') !== 'owner' && ($p['role'] ?? '') !== 'operations') { throw new Exception('للمالك والعمليات فقط.'); }
                if (!empty($in['all']) && Oxygen_Network::is_hub()) {
                    $r = Oxygen_Network::purge_network();
                    Mutqan_Agent::audit_public('cache_purge', $in, true, $p);
                    return ['text' => 'اتمسح الكاش في المركز، واتبعت أمر المسح لـ ' . ($r['count'] - 1) . ' موقع تاني — كل موقع بيتأكد من المركز وبيمسح كاشه خلال ثواني.', 'steps' => [['tool' => 'cache_purge', 'status' => 'done']], 'pending' => null];
                }
                $done = Oxygen_Network::purge_local();
                Mutqan_Agent::audit_public('cache_purge', $in, true, $p);
                return ['text' => 'اتمسح كاش الموقع ده' . ($done ? ' (' . implode('، ', $done) . ')' : '') . '.', 'steps' => [['tool' => 'cache_purge', 'status' => 'done']], 'pending' => null];
            }
            case 'assign': {
                $o = self::find_order($in['number']);
                if (!$o) { throw new Exception('مفيش طلب رقم ' . $in['number'] . '.'); }
                $techs = Mutqan_Agent::run_tool('techs_list', [], $p);
                $want = self::norm($in['tech']); $hits = [];
                foreach ($techs as $t) { $nm = self::norm($t['name']); if ($nm !== '' && (mb_strpos($nm, $want) !== false || mb_strpos($want, $nm) !== false)) { $hits[] = $t; } }
                if (!$hits) { throw new Exception('مفيش فني اسمه «' . $in['tech'] . '». الفنيين: ' . implode('، ', array_slice(array_column($techs, 'name'), 0, 12))); }
                if (count($hits) > 1) { throw new Exception('فيه أكتر من فني بالاسم ده: ' . implode('، ', array_column($hits, 'name')) . ' — اكتب الاسم كامل.'); }
                $t = $hits[0];
                Mutqan_Agent::run_tool('order_assign', ['id' => (string) $o['id'], 'techId' => $t['id']], $p);
                Mutqan_Agent::audit_public('order_assign', ['id' => $o['id'], 'techId' => $t['id']], true, $p);
                return ['text' => 'تم ✓ الطلب #' . ($o['number'] ?? $in['number']) . ' (' . ($o['clientName'] ?? 'عميل') . ') اتسند لـ ' . $t['name'] . ($t['active'] ? ' — معاه ' . $t['active'] . ' شغل مفتوح.' : '.'), 'steps' => [['tool' => 'order_assign', 'status' => 'done']], 'pending' => null];
            }
        }
        throw new Exception('أمر غير معروف.');
    }

    public static function find_order($num) {
        $num = (string) $num;
        $sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
        $r = Mutqan_Store::select('orders', ['number' => $num], ['limit' => 1], $sys);
        if ($r) { return $r[0]; }
        if (is_numeric($num)) { $r = Mutqan_Store::select('orders', ['number' => (int) $num], ['limit' => 1], $sys); if ($r) { return $r[0]; } }
        $o = Mutqan_Store::get_raw('orders', $num);
        if ($o) { return $o; }
        foreach (Mutqan_Store::select('orders', [], ['order' => '-createdAt', 'limit' => 400], $sys) as $x) { if (substr((string) $x['id'], -strlen($num)) === $num) { return $x; } }
        return null;
    }

    public static function help() {
        return "أوامر بتتنفذ فورًا من غير ذكاء اصطناعي:\n"
            . "• اسند طلب 1024 لمحمود\n• طلب 1024 (قصة الطلب كاملة)\n• الطلبات الجديدة / الطلبات\n• الوضع (ملخص العمليات)\n• التنبيهات\n"
            . "• فين الفنيين / خط سير محمود / تتبع ف-03 / مهام على طريق سامي\n• الفنيين\n• فلوس النهارده / فلوس الأسبوع / فلوس الشهر\n• رسايل الواتساب\n• ايه اللي حصل (سجل 24 ساعة)\n"
            . "• نضف المواقع كلها من الكاش\n• حالة الذكاء (اختبار المزودين)\n• النقاط (رصيد توكن Claude)\n"
            . "أي حاجة تانية بتروح للذكاء الاصطناعي.";
    }

    /* ------------------------------------------------------------------ wording without a model */

    private static function ago($iso) {
        $t = is_numeric($iso) ? (int) $iso : strtotime((string) $iso);
        if (!$t) { return ''; }
        $d = time() - $t;
        if ($d < 60) { return 'من لحظات'; }
        if ($d < 3600) { return 'من ' . floor($d / 60) . ' دقيقة'; }
        if ($d < 86400) { return 'من ' . floor($d / 3600) . ' ساعة'; }
        return 'من ' . floor($d / 86400) . ' يوم';
    }

    public static function say($tool, $o) {
        $o = is_array($o) ? $o : [];
        switch ($tool) {
            case 'orders_list':
                if (!$o) { return 'مفيش طلبات.'; }
                return "آخر الطلبات (" . count($o) . "):\n" . implode("\n", array_map(function ($x) {
                    return '• #' . ($x['number'] ?? substr((string) ($x['id'] ?? ''), -6)) . ' ' . ($x['clientName'] ?? '') . ' — ' . (Mutqan_Ops::STATUS[$x['status'] ?? ''] ?? ($x['status'] ?? '')) . (($x['technicianId'] ?? '') !== '' ? '' : ' (من غير فني)') . ' · ' . self::ago($x['createdAt'] ?? '');
                }, $o));
            case 'techs_list':
                if (!$o) { return 'مفيش فنيين مسجلين.'; }
                return "الفنيين (" . count($o) . "):\n" . implode("\n", array_map(function ($t) {
                    return '• ' . $t['name'] . ($t['available'] ? ' ✓ متاح' : ' ✕ مش متاح') . ' · شغل مفتوح: ' . (int) $t['active'] . ($t['lastSeenAt'] ? ' · آخر ظهور ' . self::ago($t['lastSeenAt']) : '');
                }, $o));
            case 'wa_recent':
                if (!$o) { return 'مفيش محادثات واتساب.'; }
                return "آخر محادثات الواتساب:\n" . implode("\n", array_map(function ($t) {
                    return '• ' . (($t['name'] ?? '') ?: ($t['phone'] ?? '')) . (!empty($t['unread']) ? ' (' . (int) $t['unread'] . ' جديدة)' : '') . ': ' . mb_substr((string) ($t['lastText'] ?? ''), 0, 70) . ' · ' . self::ago($t['updatedAt'] ?? '');
                }, $o));
            case 'stats_summary':
                $st = []; foreach ((array) ($o['by']['status'] ?? []) as $k => $v) { $st[] = $k . ': ' . $v; }
                return 'آخر ' . (int) ($o['days'] ?? 7) . ' أيام: ' . (int) ($o['orders'] ?? 0) . " طلب\n" . implode(' · ', $st);
            case 'ai_engine':
                $lines = [];
                foreach ((array) ($o['test'] ?? []) as $id => $r) { if ($r !== 'غير مفعّل') { $lines[] = '• ' . ($o['providers'][$id]['label'] ?? $id) . ': ' . $r; } }
                return ($o['available'] ? 'محرك الذكاء مفعّل.' : 'مفيش مزود ذكاء مفعّل — حط مفتاح مجاني (Groq أو Gemini) من «إعدادات البوت».') . ($lines ? "\nنتيجة الاختبار:\n" . implode("\n", $lines) : '') . "\nالمزودين اللي نجحوا اتشالت عنهم فترة التهدئة.";
            case 'ops_overview':
                $st = []; foreach ((array) ($o['openByStatus'] ?? []) as $k => $v) { $st[] = $k . ' ' . $v; }
                $u = array_map(function ($x) { return '• #' . ($x['number'] ?? '') . ' ' . ($x['client'] ?? '') . ' مستني من ' . (int) ($x['waitingMin'] ?? 0) . ' دقيقة'; }, array_slice((array) ($o['unassigned'] ?? []), 0, 8));
                return 'الوضع ' . ($o['now'] ?? '') . ":\nالنهارده: " . (int) ($o['todayCreated'] ?? 0) . ' طلب جديد، ' . (int) ($o['todayDone'] ?? 0) . ' خلص، إيراد ' . (float) ($o['todayRevenue'] ?? 0) . " ر.س\n"
                    . 'المفتوح: ' . ($st ? implode(' · ', $st) : 'مفيش') . "\nالفنيين المتاحين: " . (int) ($o['availableTechs'] ?? 0) . ' من ' . (int) ($o['totalTechs'] ?? 0) . ' · تنبيهات: ' . (int) ($o['alertsCount'] ?? 0)
                    . ($u ? "\nمن غير فني:\n" . implode("\n", $u) : '');
            case 'alerts':
                if (!$o) { return 'مفيش تنبيهات دلوقتي ✓'; }
                $ic = ['high' => '🔴', 'mid' => '🟠', 'low' => '🟡'];
                return "التنبيهات (" . count($o) . "):\n" . implode("\n", array_map(function ($a) use ($ic) { return ($ic[$a['level'] ?? ''] ?? '•') . ' ' . ($a['text'] ?? ''); }, array_slice($o, 0, 20)));
            case 'where_techs':
                if (!$o) { return 'مفيش فنيين.'; }
                return implode("\n", array_map(function ($t) {
                    $pos = $t['position'] ?? null;
                    return '• ' . $t['name'] . ': ' . ($pos ? 'آخر موقع من ' . $pos['minutesAgo'] . ' دقيقة ' . $pos['map'] : ($t['positionNote'] ?? '')) . (!empty($t['openJobs']) ? ' · شغل: ' . implode('، ', array_map(function ($j) { return '#' . $j['number'] . ' ' . $j['status']; }, $t['openJobs'])) : '');
                }, $o));
            case 'where_supervisors':
                if (isset($o['note'])) { return $o['note']; }
                return implode("\n", array_map(function ($x) { return '• ' . $x['name'] . ' (' . $x['role'] . '): من ' . $x['minutesAgo'] . ' دقيقة ' . $x['map']; }, $o));
            case 'money_report':
                $pay = []; foreach ((array) ($o['byPayment'] ?? []) as $k => $v) { $pay[] = $k . ' ' . $v; }
                return 'الفلوس من ' . ($o['from'] ?? '') . ' لـ ' . ($o['to'] ?? '') . ":\nطلبات خلصت: " . (int) ($o['doneOrders'] ?? 0) . ' · الإيراد: ' . ($o['revenue'] ?? 0) . ' ر.س (منه مش مدفوع ' . ($o['unpaidOfDone'] ?? 0) . ")\n"
                    . 'عمولة الفنيين: ' . ($o['techCommission'] ?? 0) . ' · المصاريف: ' . ($o['expensesTotal'] ?? 0) . ' · الصافي: ' . ($o['net'] ?? 0) . ' ر.س' . ($pay ? "\nحسب الدفع: " . implode(' · ', $pay) : '') . (!empty($o['note']) ? "\n" . $o['note'] : '');
            case 'activity_log':
                $ev = $o;
                if (!$ev) { return 'مفيش نشاط في آخر 24 ساعة.'; }
                return implode("\n", array_map(function ($e) { return '• ' . ($e['at'] ?? '') . ' ' . ($e['order'] ?? '') . ' ' . ($e['what'] ?? '') . (($e['by'] ?? '') !== '' ? ' — ' . $e['by'] : ''); }, array_slice((array) $ev, 0, 25)));
            case 'order_story':
                $lines = ['طلب #' . ($o['number'] ?? '') . ' — ' . ($o['client'] ?? ($o['clientName'] ?? '')) . ' · ' . ($o['status'] ?? '')];
                foreach (['service' => 'الخدمة', 'amount' => 'المبلغ', 'tech' => 'الفني', 'address' => 'العنوان', 'payment' => 'الدفع', 'created' => 'اتسجل', 'appointment' => 'الموعد'] as $k => $l) { if (isset($o[$k]) && !is_array($o[$k]) && $o[$k] !== '') { $lines[] = $l . ': ' . $o[$k]; } }
                if (isset($o['cost']['net'])) { $lines[] = 'الصافي بعد العمولة والمصاريف: ' . $o['cost']['net'] . ' ر.س'; }
                foreach (array_slice((array) ($o['steps'] ?? []), 0, 15) as $s) { $lines[] = '• ' . ($s['at'] ?? '') . ' ' . ($s['what'] ?? '') . (($s['by'] ?? '') !== '' ? ' — ' . $s['by'] : '') . (isset($s['afterMin']) ? ' (بعد ' . $s['afterMin'] . ' دقيقة)' : ''); }
                return implode("\n", $lines);
        }
        return mb_substr(wp_json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, 3000);
    }
}
