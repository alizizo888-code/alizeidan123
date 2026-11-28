<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «ملف العملاء» — everything the business knows about one phone number, gathered from the orders and the WhatsApp
 * conversations, and put to work:
 *
 *   - profile(): name, how many orders, the last service and when, the sites he dealt with, an active warranty, his
 *     rating, new / returning / VIP — used to greet him by name, to tell a technician who he is visiting, and by the
 *     follow-ups below.
 *   - welcome(): the first message of a conversation, in the name of the business registered for the site he came
 *     from, mentioning the article he was reading — or his last job when he is an old customer.
 *   - export_csv(): the customer list as a file to import into a WhatsApp sender tool (opt-outs marked).
 *   - followups(): the regular messages — the 3-month A/C maintenance reminder and the warranty-ending reminder —
 *     a few a day, never twice in 30 days, never to anyone who wrote «إيقاف».
 *   - info(): «طرق الدفع» «العروض» «التخصصات» «الضمان» «الصور» answered on WhatsApp for customers, technicians,
 *     operations and the owner (each sees what is his: the discount policy only to the team).
 */
final class Mutqan_Customers {

    const OPTOUT = 'mutqan_wa_optout';
    const SENT = 'mutqan_followup_sent';
    const OPT = 'mutqan_followups';
    const CRON = 'mutqan_followups_tick';
    const STOP_WORDS = ['ايقاف', 'إيقاف', 'وقف', 'الغاء الاشتراك', 'إلغاء الاشتراك', 'stop', 'unsubscribe', 'لا ترسل', 'متبعتليش', 'ما ترسل'];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }
    public static function key($phone) { $d = preg_replace('/\D/', '', (string) $phone); return strlen($d) >= 8 ? substr($d, -9) : ''; }
    private static function norm($s) { return class_exists('Mutqan_Playbook') ? Mutqan_Playbook::norm($s) : mb_strtolower(trim((string) $s)); }

    public static function boot() {
        add_action(self::CRON, [__CLASS__, 'followups']);
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 900, 'hourly', self::CRON); } }, 5);
    }

    public static function settings() {
        $s = get_option(self::OPT, []);
        return array_merge(['enabled' => 1, 'perDay' => 20, 'hour' => 11, 'maintenanceDays' => 90, 'warrantyDays' => 3], is_array($s) ? $s : []);
    }

    /* ------------------------------------------------------------------ the file */

    /** All orders grouped by phone. */
    public static function all($limit = 3000) {
        $rows = Mutqan_Store::select('orders', [], ['order' => '-createdAt', 'limit' => $limit], self::sys());
        $by = [];
        foreach ($rows as $o) {
            $k = self::key($o['clientPhone'] ?? '');
            if ($k === '') { continue; }
            $by[$k][] = $o;
        }
        return $by;
    }

    public static function brand_name($brand) {
        if (class_exists('Mutqan_WA_Central')) { $s = Mutqan_WA_Central::site((string) $brand); return (string) $s['name']; }
        return (string) $brand;
    }

    /** The business registered for a site: its name as the customer sees it on that site. */
    public static function business($brand) {
        $b = class_exists('Oxygen_Network') && (string) $brand !== '' ? (Oxygen_Network::brand((string) $brand) ?: []) : [];
        $name = (string) ($b['name'] ?? '') ?: self::brand_name($brand ?: 'oxygen');
        $full = (string) ($b['legal'] ?? ($b['company'] ?? ''));
        return $full !== '' ? $full : $name;
    }

    public static function profile($phone, $orders = null) {
        $k = self::key($phone);
        if ($k === '') { return null; }
        if ($orders === null) { $orders = self::all()[$k] ?? []; }
        $p = ['phone' => (string) $phone, 'name' => '', 'orders' => count($orders), 'done' => 0, 'last' => null, 'lastService' => '', 'lastAt' => 0, 'brands' => [], 'warranty' => null, 'rating' => null, 'spent' => 0, 'kind' => 'new', 'optout' => self::opted_out($phone)];
        $stars = [];
        foreach ($orders as $o) {
            if ($p['name'] === '' && trim((string) ($o['clientName'] ?? '')) !== '') { $p['name'] = trim((string) $o['clientName']); }
            $t = strtotime((string) ($o['createdAt'] ?? '')) ?: 0;
            if (!empty($o['brand'])) { $p['brands'][(string) $o['brand']] = true; }
            if (($o['status'] ?? '') === 'completed') {
                $p['done']++;
                // When the job was finished, not when the order was opened.
                $t = strtotime((string) ($o['completedAt'] ?? '')) ?: 0;
                if (!$t) { foreach (array_reverse((array) ($o['statusHistory'] ?? [])) as $h) { if (($h['status'] ?? '') === 'completed') { $t = strtotime((string) ($h['at'] ?? '')) ?: 0; break; } } }
                if (!$t) { $t = strtotime((string) ($o['updatedAt'] ?? ($o['createdAt'] ?? ''))) ?: 0; }
                $p['spent'] += class_exists('Mutqan_Sales') ? (int) Mutqan_Sales::amount($o) : 0;
                if ($t >= $p['lastAt']) {
                    $p['lastAt'] = $t; $p['last'] = (string) ($o['id'] ?? '');
                    $p['lastService'] = (string) ($o['serviceLabel'] ?? ($o['service'] ?? '')) ?: (class_exists('Mutqan_Rest') ? Mutqan_Rest::spec_label($o['specialtyId'] ?? '') : '');
                    $p['lastBrand'] = (string) ($o['brand'] ?? '');
                    if (class_exists('Mutqan_OrderBot')) { $p['warranty'] = Mutqan_OrderBot::warranty($o); }
                }
            }
            if (!empty($o['rating']['stars'])) { $stars[] = (int) $o['rating']['stars']; }
        }
        $p['brands'] = array_keys($p['brands']);
        if ($stars) { $p['rating'] = round(array_sum($stars) / count($stars), 1); }
        $p['kind'] = $p['done'] >= 4 || $p['spent'] >= 1500 ? 'vip' : ($p['done'] >= 1 ? 'returning' : ($p['orders'] > 0 ? 'lead' : 'new'));
        return $p;
    }

    public static function kind_label($k) { return ['vip' => '⭐ عميل مميز', 'returning' => '🔁 عميل سابق', 'lead' => '🆕 طلب قبل كده ولسه ما اتنفذش', 'new' => '🆕 عميل جديد'][$k] ?? $k; }

    /** One line about the customer for the team (job card, ready-order message). */
    public static function brief($phone) {
        $p = self::profile($phone);
        if (!$p || $p['kind'] === 'new') { return '🆕 عميل جديد — أول تعامل'; }
        $L = self::kind_label($p['kind']) . ' — ' . $p['done'] . ' طلب منفّذ';
        if ($p['lastService'] !== '' && $p['lastAt']) { $L .= '، آخرها «' . mb_substr($p['lastService'], 0, 40) . '» من ' . max(1, (int) floor((time() - $p['lastAt']) / 86400)) . ' يوم'; }
        if (!empty($p['warranty']['active'])) { $L .= ' · 🛡 ضمان ساري ' . $p['warranty']['left'] . ' يوم'; }
        if ($p['rating'] !== null) { $L .= ' · ⭐ ' . $p['rating']; }
        return $L;
    }

    /* ------------------------------------------------------------------ the first message */

    /** The welcome for a new conversation: the business of the site he came from, his article, or his history. */
    public static function welcome($phone, $card) {
        $card = (array) $card;
        $brand = (string) ($card['brand'] ?? '');
        $biz = $brand !== '' ? self::business($brand) : '';
        $p = self::profile($phone);
        $L = [];
        if ($p && in_array($p['kind'], ['returning', 'vip'], true)) {
            $biz = $biz !== '' ? $biz : self::business($p['lastBrand'] ?? 'oxygen');
            $L[] = 'أهلًا بيك تاني' . ($p['name'] !== '' ? ' يا ' . $p['name'] : '') . ' 👋 نورتنا في *' . $biz . '*.';
            if ($p['lastService'] !== '') { $L[] = 'آخر مرة نفذنالك «' . mb_substr($p['lastService'], 0, 50) . '»' . ($p['lastAt'] ? ' من ' . max(1, (int) floor((time() - $p['lastAt']) / 86400)) . ' يوم' : '') . (!empty($p['warranty']['active']) ? '، وضمانها لسه ساري ' . $p['warranty']['left'] . ' يوم 🛡' : '') . '.'; }
            $L[] = 'تحب نكرر نفس الخدمة، ولا محتاج حاجة جديدة؟';
            return implode("\n", $L);
        }
        $L[] = 'أهلًا بك في *' . ($biz !== '' ? $biz : self::business('oxygen')) . '* 👋';
        if (!empty($card['source']['title'])) { $L[] = 'شايفين إنك كنت بتقرأ عن «' . mb_substr((string) $card['source']['title'], 0, 70) . '» — نقدر نساعدك فيها على طول.'; }
        $L[] = 'أنا المساعد الآلي، قولّي محتاج إيه وموقعك والوقت المناسب، وأنا أجهّز طلبك.';
        return implode("\n", $L);
    }

    /* ------------------------------------------------------------------ opt-out */

    public static function opted_out($phone) { $k = self::key($phone); $o = get_option(self::OPTOUT, []); return $k !== '' && is_array($o) && isset($o[$k]); }
    public static function set_optout($phone, $on = true) {
        $k = self::key($phone); if ($k === '') { return; }
        $o = get_option(self::OPTOUT, []); $o = is_array($o) ? $o : [];
        if ($on) { $o[$k] = time(); } else { unset($o[$k]); }
        update_option(self::OPTOUT, $o, false);
    }
    public static function is_stop($text) {
        $n = trim(self::norm(preg_replace('/[!.،,؟?]+/u', ' ', (string) $text)));
        foreach (self::STOP_WORDS as $w) { if ($n === self::norm($w)) { return true; } }
        return false;
    }

    /* ------------------------------------------------------------------ the file for a WhatsApp sender */

    public static function export_rows($withTechs = true) {
        $rows = [];
        foreach (self::all() as $k => $orders) {
            $p = self::profile($orders[0]['clientPhone'] ?? $k, $orders);
            $intl = class_exists('Mutqan_WA') ? Mutqan_WA::intl($p['phone']) : preg_replace('/\D/', '', $p['phone']);
            $rows[] = ['type' => 'عميل', 'name' => $p['name'], 'phone' => '+' . ltrim($intl, '+'), 'site' => implode(' / ', array_map([__CLASS__, 'brand_name'], $p['brands'])), 'kind' => self::kind_label($p['kind']),
                'orders' => $p['orders'], 'done' => $p['done'], 'last_service' => $p['lastService'], 'last_date' => $p['lastAt'] ? gmdate('Y-m-d', $p['lastAt']) : '',
                'warranty_until' => (string) ($p['warranty']['until'] ?? ''), 'rating' => $p['rating'] ?? '', 'optout' => $p['optout'] ? 'نعم' : ''];
        }
        if ($withTechs) {
            foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 500], self::sys()) as $t) {
                $ph = preg_replace('/\D/', '', (string) ($t['phone'] ?? '')); if ($ph === '') { continue; }
                $intl = class_exists('Mutqan_WA') ? Mutqan_WA::intl($ph) : $ph;
                $rows[] = ['type' => 'فني', 'name' => (string) ($t['displayName'] ?? ''), 'phone' => '+' . ltrim($intl, '+'), 'site' => '', 'kind' => '', 'orders' => '', 'done' => '', 'last_service' => '', 'last_date' => '', 'warranty_until' => '', 'rating' => '', 'optout' => ''];
            }
        }
        return $rows;
    }

    public static function export_csv($withTechs = true) {
        $head = ['النوع', 'الاسم', 'الجوال', 'الموقع', 'التصنيف', 'الطلبات', 'المنفذ', 'آخر خدمة', 'آخر تاريخ', 'الضمان حتى', 'التقييم', 'إيقاف الرسائل'];
        $rows = self::export_rows($withTechs);
        $esc = function ($v) { $v = (string) $v; return preg_match('/[",\n]/u', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v; };
        $out = "\xEF\xBB\xBF" . implode(',', $head) . "\n";
        foreach ($rows as $r) { $out .= implode(',', array_map($esc, array_values($r))) . "\n"; }
        return ['csv' => $out, 'count' => count($rows), 'filename' => 'mutqan-contacts-' . gmdate('Y-m-d') . '.csv'];
    }

    /* ------------------------------------------------------------------ the regular messages */

    public static function followups($now = null) {
        $s = self::settings();
        if (empty($s['enabled']) || !class_exists('Mutqan_WA')) { return 0; }
        $now = $now ?? time();
        $hour = (int) wp_date('G', $now);
        if ($hour !== (int) $s['hour'] && !defined('MQ_FORCE_FOLLOWUPS')) { return 0; }
        $today = wp_date('Y-m-d', $now);
        if (get_option('mutqan_followups_day') === $today && !defined('MQ_FORCE_FOLLOWUPS')) { return 0; }
        update_option('mutqan_followups_day', $today, false);
        $sent = get_option(self::SENT, []); $sent = is_array($sent) ? $sent : [];
        $n = 0;
        foreach (self::all() as $k => $orders) {
            if ($n >= (int) $s['perDay']) { break; }
            if (self::opted_out($k) || (isset($sent[$k]) && $now - (int) $sent[$k] < 30 * 86400)) { continue; }
            $p = self::profile($orders[0]['clientPhone'] ?? '', $orders);
            if (!$p || !$p['lastAt']) { continue; }
            $days = (int) floor(($now - $p['lastAt']) / 86400);
            $biz = self::business($p['lastBrand'] ?? 'oxygen');
            $hi = 'أهلًا' . ($p['name'] !== '' ? ' ' . $p['name'] : '') . ' 🌷';
            $msg = '';
            if (!empty($p['warranty']['active']) && (int) $p['warranty']['left'] <= (int) $s['warrantyDays']) {
                $msg = $hi . "\nضمان «" . mb_substr($p['lastService'], 0, 40) . '» بتاعك بيخلص خلال ' . (int) $p['warranty']['left'] . " يوم.\nلو في أي ملاحظة على الشغل اكتبها هنا ونراجعها مجانًا قبل ما الضمان يخلص.";
            } elseif ($days >= (int) $s['maintenanceDays'] && $days <= (int) $s['maintenanceDays'] + 60 && preg_match('/(مكيف|تكييف|سبليت|غسيل|ac)/iu', $p['lastService'] . ' ' . implode(' ', array_column($orders, 'specialtyId')))) {
                $msg = $hi . "\nعدّى حوالي " . (int) round($days / 30) . " شهور على آخر غسيل/صيانة للمكيفات عندك ❄️\nالغسيل الدوري بيحافظ على التبريد ويوفر الكهرباء. تحب نحجزلك موعد؟ اكتب *نعم* ونرتب كل حاجة.";
            }
            if ($msg === '') { continue; }
            $msg .= "\n\n— *" . $biz . "* 🌷\n(لإيقاف الرسائل اكتب: إيقاف)";
            if (Mutqan_WA::auto_send($p['phone'], $msg, 'order')) { $sent[$k] = $now; $n++; }
        }
        update_option(self::SENT, $sent, false);
        if ($n && class_exists('Mutqan_WA_Central')) { Mutqan_WA_Central::defer('رسائل المتابعة الدورية', 'اتبعت ' . $n . ' رسالة متابعة (صيانة دورية / ضمان) للعملاء النهارده.', 'followups-' . $today, 'العملاء'); }
        return $n;
    }

    /* ------------------------------------------------------------------ quick information on WhatsApp */

    const TOPICS = [
        'pay' => ['طرق الدفع', 'الدفع', 'ادفع ازاي', 'ادفع كيف', 'طريقه الدفع', 'طريقة الدفع', 'الحساب البنكي', 'الايبان', 'تحويل'],
        'offers' => ['العروض', 'عروض', 'الخصومات', 'خصومات', 'فيه عروض', 'في عروض', 'عندكم عروض'],
        'specs' => ['التخصصات', 'الخدمات', 'خدماتكم', 'بتعملوا ايه', 'وش تسوون'],
        'warranty' => ['الضمان', 'ضمان', 'سياسه الضمان', 'سياسة الضمان'],
        'media' => ['الصور', 'صور', 'شغلكم', 'اعمالكم', 'أعمالكم', 'معرض', 'فيديوهات', 'سابقه اعمال', 'سابقة اعمال'],
    ];

    public static function topic($text) {
        $n = trim(self::norm(preg_replace('/[!.،,؟?]+/u', ' ', (string) $text)));
        if ($n === '' || mb_strlen($n) > 30) { return ''; }
        foreach (self::TOPICS as $k => $words) { foreach ($words as $w) { if ($n === self::norm($w)) { return $k; } } }
        return '';
    }

    public static function pay_methods() {
        $rows = Mutqan_Store::select('payment_methods', [], ['limit' => 50], self::sys());
        $byId = [];
        foreach ($rows as $r) { $byId[(string) ($r['id'] ?? '')] = $r; }
        $base = [
            'pm-cash' => ['name' => 'نقدًا للفني', 'instructions' => 'تدفع للفني بعد اكتمال الخدمة.', 'enabled' => true, 'sort' => 1],
            'pm-card' => ['name' => 'شبكة / مدى عند الفني', 'instructions' => 'جهاز الشبكة مع الفني.', 'enabled' => true, 'sort' => 2],
            'pm-transfer' => ['name' => 'تحويل بنكي', 'instructions' => '', 'enabled' => true, 'sort' => 3],
            'pm-online' => ['name' => 'دفع إلكتروني', 'instructions' => 'رابط دفع آمن يوصلك من الفريق.', 'enabled' => true, 'sort' => 4],
        ];
        $all = [];
        foreach ($base as $id => $b) { $all[$id] = array_merge($b, $byId[$id] ?? []); unset($byId[$id]); }
        foreach ($byId as $id => $r) { $all[$id] = $r; }
        $all = array_filter($all, function ($m) { return !empty($m['enabled']) && empty($m['deleted']); });
        uasort($all, function ($a, $b) { return ((int) ($a['sort'] ?? 99)) <=> ((int) ($b['sort'] ?? 99)); });
        return array_values($all);
    }

    /** $who: 'customer' | 'tech' | 'staff'. */
    public static function info($topic, $who = 'customer', $brand = '') {
        $L = [];
        switch ($topic) {
            case 'pay':
                $L[] = '💳 *طرق الدفع*';
                foreach (self::pay_methods() as $m) {
                    $line = '• ' . (string) ($m['name'] ?? '');
                    if (!empty($m['instructions'])) { $line .= ' — ' . $m['instructions']; }
                    if (!empty($m['iban']) || !empty($m['accountNumber']) || !empty($m['walletPhone'])) {
                        $line .= "\n  🏦 " . trim((string) ($m['bankName'] ?? '') . ' · ' . (string) ($m['accountHolder'] ?? ''), ' ·');
                        if (!empty($m['iban'])) { $line .= "\n  IBAN: " . preg_replace('/\s+/', '', (string) $m['iban']); }
                        if (!empty($m['accountNumber'])) { $line .= "\n  رقم الحساب: " . preg_replace('/\s+/', '', (string) $m['accountNumber']); }
                        if (!empty($m['walletPhone'])) { $line .= "\n  الجوال: " . $m['walletPhone']; }
                        $hasAcct = true;
                    }
                    $L[] = $line;
                }
                if (!empty($hasAcct)) { $L[] = '📲 نسخ الأرقام بضغطة ومسح الـQR: ' . home_url('/app/#/pay'); }
                $L[] = $who === 'customer' ? 'الدفع بعد الخدمة بفاتورة، ومتدفعش أي مبلغ برا الطرق دي.' : 'العميل يدفع بالطرق دي بس، وأي مبلغ يتسجل على الطلب.';
                break;
            case 'offers':
                $L[] = '🎁 *العروض والخصومات*';
                $offers = array_filter(Mutqan_Store::select('offers', [], ['limit' => 30], self::sys()), function ($o) { return !isset($o['enabled']) || !empty($o['enabled']); });
                foreach (array_slice($offers, 0, 8) as $o) { $L[] = '• ' . (string) ($o['title'] ?? ($o['name'] ?? 'عرض')) . (!empty($o['description']) ? ' — ' . mb_substr((string) $o['description'], 0, 100) : '') . (!empty($o['price']) ? ' (' . $o['price'] . ' ر.س)' : ''); }
                if (!$offers) { $L[] = '• عرض الغسيل الدوري للمكيفات، وأسعار خاصة للعملاء الدايمين والطلبات المجمّعة.'; }
                if ($who !== 'customer' && class_exists('Mutqan_Sales')) {
                    $P = (array) (Mutqan_Sales::settings()['pricing'] ?? []);
                    $L[] = ''; $L[] = '🔒 *للفريق فقط*: أقصى خصم ' . (int) ($P['maxDiscountPct'] ?? 0) . '% · خصم وقت الهدوء ' . (int) ($P['offPeakPct'] ?? 0) . '% · خصم الولاء ' . (int) ($P['loyalPct'] ?? 0) . '% بعد ' . (int) ($P['loyalAfter'] ?? 0) . ' طلبات.';
                }
                $L[] = '🔗 ' . home_url('/app/#/offers');
                break;
            case 'specs':
                $L[] = '🛠 *التخصصات والخدمات*';
                $specs = class_exists('Mutqan_Rest') ? Mutqan_Rest::SPEC_LABELS : [];
                $L[] = implode(' · ', array_values($specs));
                if ($who === 'tech') { $L[] = ''; $L[] = 'تخصصاتك المسجلة بتحدد الطلبات اللي بتوصلك — كلّم العمليات لو عايز تضيف تخصص.'; }
                break;
            case 'warranty':
                $L[] = '🛡 *الضمان*';
                $L[] = 'كل خدمة ليها ضمان حسب نوعها بيبدأ من يوم الإنجاز، وبيظهر في صفحة الطلب والفاتورة. أي ملاحظة خلال الضمان نراجعها مجانًا.';
                $L[] = '🔗 ' . home_url('/app/#/warranty');
                break;
            case 'media':
                $L[] = '📸 *سابقة أعمالنا*';
                $L[] = 'صور وفيديوهات شغلنا وشرح الغسيل والصيانة:';
                $L[] = '🔗 ' . home_url('/app/#/services');
                break;
            default: return '';
        }
        if ($who === 'customer' && $brand !== '') { $L[] = ''; $L[] = '— *' . self::business($brand) . '* 🌷'; }
        return implode("\n", $L);
    }
}
