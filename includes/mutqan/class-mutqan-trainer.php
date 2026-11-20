<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «المدرّب اليومي» — the assistant practises every night for two months (owner, 2026-11-20).
 *
 * Every day 12 exercises run on the server, spread over the night hours so no single request runs long:
 *   - customer conversations and lead capture: a scripted customer talks to the real WhatsApp/site brain
 *     (Mutqan_Hub::converse — the same prompt, prices, house rules and providers customers get), then a judge
 *     scores the replies against the house rules and writes the better reply;
 *   - dispatch drills: synthetic technicians and an order go through the real Mutqan_Dispatch::pick, with a known
 *     right answer (no AI, no cost);
 *   - real openers: up to two customer first-messages from the last week (masked) are replayed, so the practice
 *     follows what customers actually ask.
 * What it learns: every reply the judge fails becomes a «learned answer» waiting in the coach for the owner's
 * approval (wa_bot kind=ai, the same queue the brain already fills); approved answers join the FAQ that every
 * channel reads. Exercises that failed come back first the next day until they pass. A morning report reaches the
 * owner. After 60 days it stops by itself (the owner can extend it from the coach).
 * Nothing here talks to a real customer, creates an order or sends a WhatsApp message.
 */
final class Mutqan_Trainer {
    const OPT = 'mutqan_trainer';
    const HOOK = 'mutqan_trainer_tick';
    const COLL = 'trainer_runs';
    const PER_DAY = 12;
    const DAYS = 60;
    const PER_TICK = 2;

    public static function boot() {
        add_action('init', [__CLASS__, 'schedule'], 30);
        add_action(self::HOOK, [__CLASS__, 'tick']);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + 600, 'hourly', self::HOOK); }
    }

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function state() {
        $s = get_option(self::OPT, []);
        if (!is_array($s) || empty($s['start'])) {
            $s = ['on' => 1, 'start' => self::today(), 'days' => self::DAYS, 'day' => '', 'plan' => [], 'results' => [], 'stats' => [], 'reported' => ''];
            update_option(self::OPT, $s, false);
        }
        return $s;
    }

    private static function save($s) { update_option(self::OPT, $s, false); }

    public static function today() {
        $tz = new DateTimeZone('Asia/Riyadh');
        return (new DateTime('now', $tz))->format('Y-m-d');
    }

    private static function hour() { return (int) (new DateTime('now', new DateTimeZone('Asia/Riyadh')))->format('G'); }

    public static function active($s = null) {
        $s = $s ?: self::state();
        if (empty($s['on'])) { return false; }
        $end = strtotime($s['start'] . ' +' . (int) ($s['days'] ?? self::DAYS) . ' days');
        return time() < $end;
    }

    /* ------------------------------------------------------------------ the exercises */

    /** Scripted customers. Each: id, kind, site, after (after hours), turns (what the customer says), expect (for the judge). */
    public static function scenarios() {
        return [
            ['id' => 'price-split', 'kind' => 'chat', 'turns' => ['السلام عليكم كم غسيل المكيف السبليت؟', 'عندي ٣ مكيفات', 'حي العزيزية'], 'expect' => 'يرد بالسعر الرسمي من الجدول للسبليت، يسأل سؤال واحد كل مرة، ويقفل الطلب على «اليوم — أقرب وقت» أول ما يعرف الخدمة والعدد والحي.'],
            ['id' => 'haggle', 'kind' => 'chat', 'turns' => ['بكم تنظيف المكيف؟', 'غالي، سوها لي أرخص', 'لا لسه غالي، آخر سعر؟', 'طيب ٥٠ ريال وأوافق'], 'expect' => 'ينزل بالتدريج حسب الجدول ولا ينزل أبدًا تحت أقل سعر مسموح؛ بعد الحد يعتذر بلطف ويعرض قيمة الخدمة والضمان. ممنوع يقبل ٥٠ لو أقل من الحد.'],
            ['id' => 'inspect-many', 'kind' => 'chat', 'turns' => ['أبغى فني يكشف على ٥ مكيفات ما تبرد'], 'expect' => 'الكشف مجاني لحد جهازين؛ أكتر من جهازين من غير شغل بيتحاسب زيارة ٥٠ ريال — يوضح ده بأدب ويكمّل الطلب.'],
            ['id' => 'cold-room', 'kind' => 'chat', 'turns' => ['عندي غرفة تبريد في مطعم وقفت والحرارة طالعة', 'في الشوقية'], 'expect' => 'يفهم إنها حالة عاجلة لغرفة تبريد، يطمّن العميل، يسجّل الطلب اليوم بأسرع وقت، وما يخترعش سعر غرف التبريد (بعد المعاينة).'],
            ['id' => 'after-hours-emergency', 'kind' => 'chat', 'after' => true, 'turns' => ['فيه تسريب مويه كبير من المكيف على الكهرباء الحين'], 'expect' => 'برا مواعيد العمل وحالة طارئة وخطيرة: يطلب منه يتصل فورًا على رقم الطوارئ، بدون تأجيل للصبح.'],
            ['id' => 'after-hours-normal', 'kind' => 'chat', 'after' => true, 'turns' => ['ابغى غسيل مكيفين'], 'expect' => 'برا مواعيد العمل وطلب عادي: يحجز أقرب موعد الصبح بأدب، ويكمّل جمع البيانات.'],
            ['id' => 'no-tomorrow', 'kind' => 'chat', 'turns' => ['أبغى صيانة مكيف شباك', 'واحد بس', 'حي النسيم'], 'expect' => 'ممنوع يقترح بكرة أو الصبح من نفسه؛ الموعد «اليوم — أقرب وقت».'],
            ['id' => 'jeddah', 'kind' => 'chat', 'turns' => ['عندكم فنيين في جدة؟ حي الصفا', 'تركيب مكيف سبليت جديد'], 'expect' => 'يأكد إن الخدمة متاحة في جدة، يسأل عن تفاصيل التركيب (المكيف موجود؟ المكان) سؤال واحد، وما يخترعش سعر غير موجود في الجدول.'],
            ['id' => 'out-of-area', 'kind' => 'chat', 'turns' => ['أنا في الرياض، تقدرون تجون؟'], 'expect' => 'يوضح بأدب إن الخدمة في مكة وجدة بس، من غير وعود.'],
            ['id' => 'ask-human', 'kind' => 'chat', 'turns' => ['أبغى أكلم شخص مو روبوت'], 'expect' => 'ما يقولش إنه بوت، يرد زي موظف، ويحوّل (handoff) لو أصر.'],
            ['id' => 'complaint', 'kind' => 'chat', 'turns' => ['الفني اللي جاني أمس ما صلح شي والمكيف رجع يخرب، أبغى فلوسي'], 'expect' => 'شكوى واسترجاع فلوس: يعتذر بلطف ويحوّل للإدارة (handoff=true) من غير وعود بمبالغ.'],
            ['id' => 'where-tech', 'kind' => 'chat', 'turns' => ['وين الفني؟ صار لي ساعة أنتظر'], 'expect' => 'يجاوب من بيانات طلباته المرفقة بس، وما يخترعش وقت وصول ولا حالة؛ لو مفيش طلب يقول كده ويعرض المساعدة.'],
            ['id' => 'plumbing', 'kind' => 'chat', 'turns' => ['عندي تسريب في مغسلة المطبخ', 'العوالي'], 'expect' => 'يعرف إن الشركة بتعمل سباكة، يسجّل الطلب اليوم، وسعره بعد المعاينة لو مش في الجدول.'],
            ['id' => 'prayer-time', 'kind' => 'chat', 'turns' => ['أبغى غسيل مكيف بعد العصر', 'سبليت واحد، الزاهر'], 'expect' => 'يسجّل الوقت زي ما قاله «بعد العصر» بالظبط من غير تعديل.'],
            ['id' => 'returning', 'kind' => 'chat', 'turns' => ['هلا، تعاملت معكم قبل شهرين وأبغى غسيل مرة ثانية'], 'expect' => 'يرحّب بيه كعميل قديم، ويكمّل الطلب بسرعة من غير أسئلة زيادة.'],
            ['id' => 'lead-article', 'kind' => 'lead', 'site' => 'أكسجين', 'turns' => ['السلام عليكم شفت مقالكم عن غسيل المكيفات في مكة، كم السعر؟'], 'expect' => 'ترحيب «مرحبا بكم في» + اسم الشركة، يجاوب السعر من الجدول، ويحوّل الاهتمام لطلب (يسأل النوع والعدد).'],
            ['id' => 'lead-ad', 'kind' => 'lead', 'site' => 'أكسجين', 'turns' => ['شفت إعلانكم في جوجل', 'عندكم صيانة غرف تجميد؟'], 'expect' => 'ترحيب باسم «أكسجين» وذكر الخدمات (تكييف، غرف تبريد وتجميد، سباكة، كهرباء، نجارة)، ثم يجمع بيانات الطلب.'],
            ['id' => 'lead-cold', 'kind' => 'lead', 'turns' => ['مرحبا'], 'expect' => 'ترحيب ودود يذكر الخدمات باختصار وسؤال واحد عن احتياجه.'],
            ['id' => 'lead-compare', 'kind' => 'lead', 'turns' => ['فيه شركة ثانية قالت لي الغسيل بـ ٤٠ ريال، ليش أنتم أغلى؟'], 'expect' => 'ما ينزلش تحت الحد ولا يهاجم المنافس؛ يوضح قيمة الخدمة (ضمان، فنيين، نفس اليوم) ويحاول يقفل الطلب.'],
            ['id' => 'lead-bulk', 'kind' => 'lead', 'turns' => ['عندي عمارة فيها ٢٠ مكيف أبغى عقد صيانة سنوي'], 'expect' => 'فرصة كبيرة: يتعامل باهتمام، يجمع البيانات الأساسية، ويحوّل للإدارة لعرض سعر (handoff) من غير ما يخترع أسعار عقود.'],
        ];
    }

    /** The built-in scenarios plus the ones Claude designed and the owner approved (option mutqan_trainer_extra). */
    public static function all_scenarios() {
        $extra = array_values(array_filter((array) get_option('mutqan_trainer_extra', []), function ($x) { return is_array($x) && !empty($x['id']) && !empty($x['turns']); }));
        return array_merge(self::scenarios(), $extra);
    }

    /** Dispatch drills: a known right answer from the real picker. */
    public static function drills() {
        $now = gmdate('c');
        $old = gmdate('c', time() - 3 * 3600);
        $o = ['specialtyId' => 'ac', 'lat' => 21.4225, 'lng' => 39.8262];   // Makkah centre
        return [
            ['id' => 'disp-nearest-online', 'expect' => 'near', 'order' => $o, 'techs' => [
                ['userId' => 'near', 'lat' => 21.425, 'lng' => 39.83, 'lastSeenAt' => $now, 'specialties' => ['ac']],
                ['userId' => 'far', 'lat' => 21.60, 'lng' => 39.95, 'lastSeenAt' => $now, 'specialties' => ['ac']]]],
            ['id' => 'disp-skip-off', 'expect' => 'on', 'order' => $o, 'techs' => [
                ['userId' => 'off', 'lat' => 21.4226, 'lng' => 39.8263, 'lastSeenAt' => $now, 'specialties' => ['ac'], 'active' => false],
                ['userId' => 'on', 'lat' => 21.45, 'lng' => 39.85, 'lastSeenAt' => $now, 'specialties' => ['ac']]]],
            ['id' => 'disp-specialty', 'expect' => 'ac-tech', 'order' => $o, 'techs' => [
                ['userId' => 'plumber', 'lat' => 21.4226, 'lng' => 39.8263, 'lastSeenAt' => $now, 'specialties' => ['plumbing']],
                ['userId' => 'ac-tech', 'lat' => 21.44, 'lng' => 39.84, 'lastSeenAt' => $now, 'specialties' => ['ac']]]],
            ['id' => 'disp-connected-first', 'expect' => 'online', 'order' => $o, 'techs' => [
                ['userId' => 'offline', 'lat' => 21.4226, 'lng' => 39.8263, 'lastSeenAt' => $old, 'specialties' => ['ac']],
                ['userId' => 'online', 'lat' => 21.46, 'lng' => 39.86, 'lastSeenAt' => $now, 'specialties' => ['ac']]]],
            ['id' => 'disp-aborted', 'expect' => 'second', 'order' => array_merge($o, ['abortedTechIds' => ['first']]), 'techs' => [
                ['userId' => 'first', 'lat' => 21.4226, 'lng' => 39.8263, 'lastSeenAt' => $now, 'specialties' => ['ac']],
                ['userId' => 'second', 'lat' => 21.45, 'lng' => 39.85, 'lastSeenAt' => $now, 'specialties' => ['ac']]]],
            ['id' => 'disp-capacity', 'expect' => 'free', 'order' => $o, 'full' => 'busy', 'techs' => [
                ['userId' => 'busy', 'lat' => 21.4226, 'lng' => 39.8263, 'lastSeenAt' => $now, 'specialties' => ['ac'], 'dailyCapacity' => 1],
                ['userId' => 'free', 'lat' => 21.45, 'lng' => 39.85, 'lastSeenAt' => $now, 'specialties' => ['ac'], 'dailyCapacity' => 5]]],
        ];
    }

    /** Up to two first messages real customers sent this week (masked), replayed as practice. */
    private static function real_openers() {
        global $wpdb;
        $t = Mutqan_Store::table('docs');
        $rows = (array) $wpdb->get_col($wpdb->prepare("SELECT data FROM $t WHERE coll IN ('ai_messages','wa_msgs') AND created_at > %s ORDER BY created_at DESC LIMIT 200", gmdate('Y-m-d H:i:s', time() - 7 * 86400)));
        $out = [];
        foreach ($rows as $raw) {
            $d = json_decode((string) $raw, true);
            if (!is_array($d)) { continue; }
            $role = (string) ($d['role'] ?? $d['from'] ?? $d['direction'] ?? '');
            if (!in_array($role, ['user', 'customer', 'in', 'incoming'], true)) { continue; }
            $txt = trim((string) ($d['text'] ?? $d['content'] ?? $d['body'] ?? ''));
            if (mb_strlen($txt) < 8 || mb_strlen($txt) > 300) { continue; }
            $txt = Mutqan_AI::mask($txt);
            $out[md5($txt)] = $txt;
            if (count($out) >= 2) { break; }
        }
        $i = 0; $sc = [];
        foreach ($out as $txt) {
            $sc[] = ['id' => 'real-' . (++$i), 'kind' => 'real', 'turns' => [$txt], 'expect' => 'رسالة عميل حقيقية: يرد زي أفضل موظف خدمة عملاء حسب سياسة الشركة ويقرب العميل من طلب.'];
        }
        return $sc;
    }

    /** Today's 12: failed ones first, then the ones least practised, 2 drills, up to 2 real openers. */
    private static function plan($s) {
        $stats = (array) ($s['stats'] ?? []);
        $chat = self::all_scenarios();
        usort($chat, function ($a, $b) use ($stats) {
            $fa = !empty($stats[$a['id']]['lastFail']); $fb = !empty($stats[$b['id']]['lastFail']);
            if ($fa !== $fb) { return $fa ? -1 : 1; }
            return ((int) ($stats[$a['id']]['runs'] ?? 0)) <=> ((int) ($stats[$b['id']]['runs'] ?? 0));
        });
        $drills = self::drills();
        usort($drills, function ($a, $b) use ($stats) { return ((int) ($stats[$a['id']]['runs'] ?? 0)) <=> ((int) ($stats[$b['id']]['runs'] ?? 0)); });
        $real = array_map(function ($x) { return $x['turns'][0]; }, self::real_openers());
        $ids = array_merge(array_column(array_slice($drills, 0, 2), 'id'), array_map(function ($i, $t) { return 'real:' . $t; }, array_keys($real), $real));
        foreach ($chat as $c) { if (count($ids) >= self::PER_DAY) { break; } $ids[] = $c['id']; }
        return array_slice($ids, 0, self::PER_DAY);
    }

    /* ------------------------------------------------------------------ the nightly run */

    public static function tick() {
        if (!class_exists('Mutqan_Store') || !class_exists('Mutqan_AI')) { return; }
        // Practice happens where the customers are answered: not on the 20 network faces, not where no AI is set up.
        if (class_exists('Oxygen_Network') && method_exists('Oxygen_Network', 'is_satellite') && Oxygen_Network::is_satellite()) { return; }
        if (!Mutqan_AI::any_enabled()) { return; }
        $s = self::state();
        if (!self::active($s)) { return; }
        $day = self::today();
        if (($s['day'] ?? '') !== $day) {
            // practise at night (1 am – 7 am Makkah), when customers are asleep and free quotas are fresh
            $h = self::hour();
            if ($h < 1 || $h > 7) { return; }
            $s['day'] = $day; $s['plan'] = self::plan($s); $s['results'] = [];
            self::save($s);
        }
        if (function_exists('set_time_limit')) { @set_time_limit(180); }
        $left = array_values(array_diff($s['plan'], array_keys((array) $s['results'])));
        foreach (array_slice($left, 0, self::PER_TICK) as $id) {
            try { $r = self::run_one($id); }
            catch (Throwable $e) { $r = ['id' => $id, 'pass' => false, 'score' => 0, 'issues' => ['تعذّر التشغيل: ' . mb_substr($e->getMessage(), 0, 120)], 'kind' => 'error']; }
            $s = self::state();
            $s['results'][$id] = ['pass' => (bool) $r['pass'], 'score' => (int) $r['score'], 'kind' => (string) ($r['kind'] ?? ''), 'issue' => (string) (($r['issues'] ?? [])[0] ?? '')];
            $key = strpos($id, 'real:') === 0 ? 'real' : $id;
            $st = (array) ($s['stats'][$key] ?? []);
            $st['runs'] = (int) ($st['runs'] ?? 0) + 1;
            $st['fails'] = (int) ($st['fails'] ?? 0) + ($r['pass'] ? 0 : 1);
            $st['lastFail'] = !$r['pass'];
            $st['lastScore'] = (int) $r['score'];
            $s['stats'][$key] = $st;
            self::save($s);
        }
        $s = self::state();
        if (count((array) $s['results']) >= count((array) $s['plan']) && ($s['reported'] ?? '') !== $day) {
            self::report($s);
            $s['reported'] = $day;
            self::save($s);
        }
    }

    public static function run_one($id) {
        if (strpos($id, 'disp-') === 0) { return self::run_drill($id); }
        if (strpos($id, 'real:') === 0) {
            $sc = ['id' => 'real', 'kind' => 'real', 'turns' => [substr($id, 5)], 'expect' => 'رسالة عميل حقيقية: يرد زي أفضل موظف خدمة عملاء حسب سياسة الشركة ويقرب العميل من طلب.'];
        } else {
            $sc = null;
            foreach (self::all_scenarios() as $x) { if ($x['id'] === $id) { $sc = $x; } }
            if (!$sc) { return ['id' => $id, 'pass' => true, 'score' => 10, 'issues' => [], 'kind' => 'gone']; }
        }
        return self::run_chat($sc);
    }

    private static function run_drill($id) {
        $d = null;
        foreach (self::drills() as $x) { if ($x['id'] === $id) { $d = $x; } }
        if (!$d) { return ['id' => $id, 'pass' => true, 'score' => 10, 'issues' => [], 'kind' => 'drill']; }
        $orders = [];
        if (!empty($d['full'])) { $orders[] = ['technicianId' => $d['full'], 'status' => 'assigned', 'assignedAt' => gmdate('c'), 'createdAt' => gmdate('c')]; }
        $pick = Mutqan_Dispatch::pick($d['order'], $d['techs'], $orders, 'smart');
        $got = (string) ($pick['userId'] ?? '');
        $ok = $got === $d['expect'];
        self::keep(['scenario' => $id, 'kind' => 'drill', 'pass' => $ok, 'score' => $ok ? 10 : 0, 'expect' => $d['expect'], 'got' => $got]);
        return ['id' => $id, 'pass' => $ok, 'score' => $ok ? 10 : 0, 'issues' => $ok ? [] : ['التوزيع اختار «' . ($got ?: 'لا أحد') . '» والمتوقع «' . $d['expect'] . '»'], 'kind' => 'drill'];
    }

    private static function run_chat($sc) {
        $hist = []; $lead = []; $replies = []; $last = null;
        foreach ($sc['turns'] as $msg) {
            $r = Mutqan_Hub::converse(['phone' => '', 'site' => (string) ($sc['site'] ?? 'أكسجين'), 'history' => $hist, 'lead' => $lead, 'text' => $msg,
                'afterHours' => !empty($sc['after']), 'emergencyPhone' => '0549247636', 'brief' => 'عميل جديد (تدريب)']);
            if (!$r) { return ['id' => $sc['id'], 'pass' => false, 'score' => 0, 'issues' => ['المساعد ما ردش (مفيش مزود متاح)'], 'kind' => $sc['kind']]; }
            $hist[] = 'العميل: ' . $msg;
            $hist[] = 'نحن: ' . $r['reply'];
            $lead = (array) $r['lead'];
            $replies[] = $r['reply'];
            $last = $r;
        }
        $issues = self::rule_checks($sc, $replies, $last);
        $j = self::judge($sc, $hist, $last);
        $score = $j ? (int) $j['score'] : (count($issues) ? 4 : 7);
        if ($issues) { $score = min($score, 5); }
        $pass = $score >= 7 && !$issues;
        $all = array_values(array_unique(array_merge($issues, $j ? (array) $j['issues'] : [])));
        self::keep(['scenario' => $sc['id'], 'kind' => $sc['kind'], 'pass' => $pass, 'score' => $score, 'transcript' => $hist, 'lead' => $lead,
            'complete' => !empty($last['complete']), 'handoff' => !empty($last['handoff']), 'issues' => array_slice($all, 0, 6), 'better' => (string) ($j['better'] ?? ''), 'provider' => (string) ($last['provider'] ?? '')]);
        if (!$pass && $j && trim((string) $j['better']) !== '') { self::lesson(end($sc['turns']), (string) $j['better']); }
        return ['id' => $sc['id'], 'pass' => $pass, 'score' => $score, 'issues' => $all, 'kind' => $sc['kind']];
    }

    /** House rules that need no judge. */
    public static function rule_checks($sc, $replies, $last) {
        $out = [];
        $all = implode("\n", $replies);
        $askedTomorrow = (bool) preg_match('/(بكره|بكرة|غدا|غدًا|الصبح|الصباح)/u', implode(' ', $sc['turns']));
        if (empty($sc['after']) && !$askedTomorrow && preg_match('/(بكره|بكرة|غدًا|غدا|الصباح الباكر|موعد الصبح)/u', $all)) { $out[] = 'اقترح بكرة/الصبح من نفسه (ممنوع)'; }
        if (preg_match('/(أنا بوت|انا بوت|ذكاء اصطناعي|روبوت آلي|نموذج لغوي)/u', $all)) { $out[] = 'قال إنه بوت/ذكاء اصطناعي'; }
        foreach ($replies as $r) { if (mb_strlen($r) > 700) { $out[] = 'رد طويل جدًا'; break; } }
        if (in_array($sc['id'], ['complaint', 'lead-bulk'], true) && empty($last['handoff'])) { $out[] = 'كان لازم يحوّل للإدارة (handoff)'; }
        if ($sc['id'] === 'after-hours-emergency' && strpos(Mutqan_Auth::fold_digits($all), '0549247636') === false && !preg_match('/(اتصل|كلمنا|تواصل)/u', $all)) { $out[] = 'حالة طارئة برا المواعيد من غير ما يطلب الاتصال فورًا'; }
        return $out;
    }

    /** A second look by a model with the house rules in hand: score 0–10, what was wrong, and the reply that should have been sent. */
    private static function judge($sc, $hist, $last) {
        $P = "أنت مشرف جودة خدمة العملاء في شركة صيانة (مكة وجدة). قيّم ردود موظف الواتساب في المحادثة دي حسب سياسة الشركة.\n"
            . Mutqan_AI::house_rules() . "\n\n## المتوقع في الحالة دي\n" . $sc['expect'] . "\n\n## المحادثة\n" . implode("\n", $hist) . "\n"
            . "## حالة الطلب في الآخر\n" . wp_json_encode(['lead' => $last['lead'] ?? [], 'complete' => !empty($last['complete']), 'handoff' => !empty($last['handoff'])], JSON_UNESCAPED_UNICODE) . "\n\n"
            . "رد بالـJSON ده بس: {\"score\":0,\"issues\":[\"\"],\"better\":\"أفضل رد كان المفروض يتقال على آخر رسالة من العميل\"}\n"
            . "score من 10 (7 فأكتر = ناجح). issues: أخطاء محددة وقصيرة بالعربي (فاضية لو مفيش). better: رد واحد قصير بلهجة سعودية بيضاء وبالأسعار الرسمية بس.";
        try { $r = Mutqan_AI::run($P, ['json' => true, 'tier' => 'low', 'temperature' => 0.2, 'feature' => 'trainer_judge', 'budget' => 30], self::sys()); }
        catch (Throwable $e) { return null; }
        $j = is_array($r['json'] ?? null) ? $r['json'] : null;
        if (!$j || !isset($j['score'])) { return null; }
        return ['score' => max(0, min(10, (int) $j['score'])), 'issues' => array_values(array_filter(array_map(function ($x) { return mb_substr(trim((string) $x), 0, 140); }, (array) ($j['issues'] ?? [])), 'strlen')),
            'better' => mb_substr(trim((string) ($j['better'] ?? '')), 0, 600)];
    }

    /** A failed answer becomes a suggestion in the coach's «learned answers», waiting for the owner. */
    private static function lesson($q, $better) {
        if (!class_exists('Mutqan_Brain')) { return; }
        $q = trim((string) $q);
        if (mb_strlen($q) < 6) { return; }
        $id = 'tr-' . substr(md5($q), 0, 16);
        $cur = Mutqan_Store::get_raw('wa_bot', $id);
        if ($cur && in_array($cur['status'] ?? '', ['rejected', 'approved'], true)) { return; }
        Mutqan_Store::upsert('wa_bot', ['kind' => 'ai', 'q' => mb_substr($q, 0, 300), 'a' => mb_substr($better, 0, 600), 'count' => (int) ($cur['count'] ?? 0) + 1,
            'status' => 'pending', 'figure' => Mutqan_Brain::has_figure($better), 'feature' => 'trainer', 'at' => gmdate('c')], $id, [], self::sys());
    }

    private static function keep($row) {
        $row['day'] = self::today();
        Mutqan_Store::insert(self::COLL, $row, null, [], self::sys());
    }

    /** The morning report to the owner. */
    private static function report($s) {
        $res = (array) $s['results'];
        $n = count($res); $ok = count(array_filter($res, function ($r) { return !empty($r['pass']); }));
        $avg = $n ? round(array_sum(array_column($res, 'score')) / $n, 1) : 0;
        $bad = array_filter($res, function ($r) { return empty($r['pass']); });
        $dayNo = max(1, (int) floor((strtotime(self::today()) - strtotime($s['start'])) / 86400) + 1);
        $body = "اليوم {$dayNo} من " . (int) ($s['days'] ?? self::DAYS) . ": نجح {$ok} من {$n} (متوسط {$avg}/10).";
        if ($bad) {
            $lines = [];
            foreach (array_slice($bad, 0, 3, true) as $id => $r) { $lines[] = (strpos($id, 'real:') === 0 ? 'رسالة حقيقية' : $id) . ($r['issue'] !== '' ? ' — ' . $r['issue'] : ''); }
            $body .= ' يحتاج تحسين: ' . implode('؛ ', $lines) . '. الردود الأفضل مستنية موافقتك في «تدريب المساعد».';
        }
        Mutqan_Store::insert('_push', ['target' => ['role' => 'owner'], 'title' => '🎓 تقرير تدريب المساعد', 'body' => mb_substr($body, 0, 480), 'url' => '#/staff/ai-trainer', 'sound' => 'chime', 'event' => 'trainer_report', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 3 * 86400)], self::sys());
    }

    /* ------------------------------------------------------------------ the owner's page (/trainer) */

    public static function handle($in, $p) {
        Mutqan_Auth::require_user($p);
        $role = (string) ($p['role'] ?? '');
        if ($role !== 'owner' && $role !== 'operations') { throw new Mutqan_Error('forbidden', 'للمالك والعمليات فقط.', 403); }
        $op = (string) ($in['op'] ?? 'status');
        if ($op === 'status') { return self::status(); }
        if ($op === 'lesson') { return self::decide((string) ($in['id'] ?? ''), (string) ($in['decision'] ?? ''), (string) ($in['a'] ?? '')); }
        if ($role !== 'owner') { throw new Mutqan_Error('forbidden', 'للمالك فقط.', 403); }
        if ($op === 'run') { Mutqan_Limiter::hit('trainer_run', 6, 3600); $id = (string) ($in['id'] ?? ''); return self::run_one($id !== '' ? $id : 'price-split'); }
        if ($op === 'extend') { $s = self::state(); $s['days'] = (int) ($s['days'] ?? self::DAYS) + max(1, min(120, (int) ($in['days'] ?? 30))); $s['on'] = 1; self::save($s); return self::status(); }
        if ($op === 'pause') { $s = self::state(); $s['on'] = empty($in['on']) ? 0 : 1; self::save($s); return self::status(); }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    public static function status() {
        $s = self::state();
        $runs = Mutqan_Store::select(self::COLL, [], ['limit' => 40, 'order' => '-createdAt'], self::sys());
        $lessons = array_values(array_filter(Mutqan_Store::select('wa_bot', ['feature' => 'trainer'], ['limit' => 60, 'order' => '-updatedAt'], self::sys()), function ($r) { return ($r['status'] ?? '') === 'pending'; }));
        $end = date('Y-m-d', strtotime($s['start'] . ' +' . (int) ($s['days'] ?? self::DAYS) . ' days'));
        $names = [];
        foreach (self::all_scenarios() as $x) { $names[$x['id']] = $x['expect']; }
        return ['on' => !empty($s['on']), 'active' => self::active($s), 'start' => $s['start'], 'end' => $end, 'day' => $s['day'] ?? '', 'plan' => array_values((array) ($s['plan'] ?? [])),
            'results' => (object) ($s['results'] ?? []), 'stats' => (object) ($s['stats'] ?? []), 'expect' => (object) $names,
            'runs' => array_map(function ($r) { return array_intersect_key($r, array_flip(['id', 'day', 'scenario', 'kind', 'pass', 'score', 'transcript', 'issues', 'better', 'expect', 'got', 'provider', 'createdAt'])); }, $runs),
            'lessons' => array_map(function ($r) { return ['id' => (string) $r['id'], 'q' => (string) ($r['q'] ?? ''), 'a' => (string) ($r['a'] ?? ''), 'count' => (int) ($r['count'] ?? 1)]; }, $lessons)];
    }

    /** Approve (optionally edited) → the answer joins the FAQ every channel reads; reject → never suggested again. */
    private static function decide($id, $decision, $a) {
        $r = Mutqan_Store::get_raw('wa_bot', $id);
        if (!$r || ($r['feature'] ?? '') !== 'trainer') { throw new Mutqan_Error('not_found', 'الدرس مش موجود.', 404); }
        if (!in_array($decision, ['approved', 'rejected'], true)) { throw new Mutqan_Error('bad_input', 'قرار غير معروف.', 400); }
        $ans = trim($a) !== '' ? mb_substr(trim(wp_strip_all_tags($a)), 0, 1200) : (string) ($r['a'] ?? '');
        Mutqan_Store::update('wa_bot', $id, ['status' => $decision, 'a' => $ans, 'reviewedAt' => gmdate('c')], self::sys());
        if ($decision === 'approved' && class_exists('Mutqan_Coach')) {
            Mutqan_Coach::faq_save(null, ['q' => (string) ($r['q'] ?? ''), 'a' => $ans, 'cat' => 'تدريب', 'keywords' => '']);
        }
        return ['ok' => true, 'id' => $id, 'status' => $decision];
    }
}
Mutqan_Trainer::boot();
