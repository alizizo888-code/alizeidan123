<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The owner's control assistant ("مساعد التحكم").
 *
 * A chat that runs on Claude (tier 'high') with a closed list of tools. Every tool works ONLY on this business:
 * the Mutqan orders/WhatsApp store, this WordPress site, the oxygen11.com DNS zone on Hostinger and the network's
 * public Blogger feeds. There is no generic HTTP, shell or file tool, so the assistant cannot act anywhere else.
 *
 * Changes that matter (published content, WhatsApp messages, DNS) are not executed by the model: they come back to
 * the app as a pending action and run only when the owner presses "تنفيذ". Everything is written to _ai_audit.
 */
final class Mutqan_Agent {
    public static function boot() { add_action('rest_api_init', [__CLASS__, 'routes']); }
    const OPT_HOSTINGER = 'mutqan_hostinger_token';
    const ZONE = 'oxygen11.com';
    const MAX_STEPS = 8;
    /** Settings the control centre and the assistant may read/change (same collections the app's settings screens use). */
    const SETTINGS = [
        'pricing' => 'المنشأة والتسعير والتواصل', 'ai_settings' => 'تعليمات وإعدادات البوت', 'payment_methods' => 'طرق الدفع',
        'notify_settings' => 'الإشعارات', 'schedule_cfg' => 'المواعيد', 'warranty_policy' => 'الضمان', 'assignment_settings' => 'توزيع الطلبات',
        'wa_numbers' => 'أرقام الواتساب', 'offers' => 'العروض',
    ];
    const PROTECTED_DNS = ['@', 'www', 'ftp', 'mail', 'autodiscover', 'autoconfig', '_dmarc', 'mutqan', 'desk', 'ads'];

    /* ------------------------------------------------------------------ entry (POST /agent) */

    public static function handle($in, $p) {
        Mutqan_Auth::require_user($p);
        if (!in_array($p['role'] ?? '', ['owner', 'operations'], true)) { throw new Mutqan_Error('forbidden', 'مساعد التحكم للمالك ومشرف العمليات فقط.', 403); }
        if (!empty($in['confirm'])) { return self::confirm((string) $in['confirm'], $p); }
        if (!empty($in['cancel'])) { delete_transient('mq_agent_' . preg_replace('/[^a-z0-9]/', '', (string) $in['cancel'])); return ['text' => 'اتلغى الأمر.']; }
        $msgs = self::clean_messages($in['messages'] ?? []);
        if (Mutqan_AI::anthropic_key() === '') { return self::free_turn($msgs, $p); }
        if (!$msgs) { throw new Mutqan_Error('empty_prompt', 'اكتب طلبك.', 400); }
        Mutqan_Limiter::hit('agent', 120, 3600);
        return self::loop($msgs, $p);
    }

    /**
     * No paid Claude key: the site's own AI engine (free providers, the same one the bot uses) picks ONE tool from the same
     * closed list as JSON, the server runs it (changes still wait for «تنفيذ»), then the engine words the answer.
     */
    private static function free_turn($msgs, $p) {
        if (!Mutqan_AI::any_enabled()) {
            return ['text' => 'محرك الذكاء اللي جوه الموقع لسه مش متفعّل: فعّل مزود مجاني (Groq أو Gemini) من «إعدادات البوت» وحط مفتاحه. لحد ما يتفعّل، استخدم «مركز تحكم أكسجين» في Claude — بيشتغل باشتراكك ومربوط بنفس الأدوات.', 'steps' => [], 'pending' => null];
        }
        $last = ''; foreach (array_reverse($msgs) as $m) { if ($m['role'] === 'user') { $last = $m['content']; break; } }
        $list = implode("\n", array_map(function ($t) { return '- ' . $t['name'] . ': ' . $t['description'] . ' مدخلات: ' . implode(',', array_keys((array) $t['input_schema']['properties'])); }, self::tools()));
        $prompt = "أنت مساعد تحكم لشركة صيانة. اختار أداة واحدة من القائمة تنفذ طلب المستخدم، أو لا أداة لو مجرد كلام.\nالأدوات:\n$list\n\nطلب المستخدم: $last\n\nرد بـ JSON فقط بالشكل {\"tool\":\"اسم الأداة أو none\",\"input\":{...},\"reply\":\"رد قصير بالعامية المصرية لو مفيش أداة\"}";
        $r = Mutqan_AI::run($prompt, ['json' => true, 'temperature' => 0.1, 'tier' => 'low'], $p);
        $j = is_array($r['json'] ?? null) ? $r['json'] : [];
        $tool = (string) ($j['tool'] ?? 'none'); $input = is_array($j['input'] ?? null) ? $j['input'] : [];
        if ($tool === 'none' || !in_array($tool, array_column(self::tools(), 'name'), true)) { return ['text' => (string) ($j['reply'] ?? ($r['text'] ?? '')), 'steps' => [], 'pending' => null]; }
        if (self::needs_confirm($tool, $input)) { $pend = self::park($tool, $input, $p); return ['text' => 'جاهز — راجع وأكّد.', 'steps' => [['tool' => $tool, 'status' => 'pending']], 'pending' => $pend]; }
        try { $out = self::run_tool($tool, $input, $p); self::audit($tool, $input, true, $p); }
        catch (Throwable $e) { self::audit($tool, $input, false, $p); return ['text' => 'ما اتنفذش: ' . $e->getMessage(), 'steps' => [['tool' => $tool, 'status' => 'error']], 'pending' => null]; }
        $data = mb_substr(wp_json_encode($out, JSON_UNESCAPED_UNICODE), 0, 6000);
        try { $w = Mutqan_AI::run("طلب المستخدم: $last\nنتيجة النظام (JSON): $data\nلخّص النتيجة للمستخدم بالعامية المصرية في سطور قصيرة واضحة بالأرقام. من غير JSON.", ['temperature' => 0.3, 'tier' => 'low'], $p); $text = trim((string) ($w['text'] ?? '')); }
        catch (Throwable $e) { $text = $data; }
        return ['text' => $text, 'steps' => [['tool' => $tool, 'status' => 'ok']], 'pending' => null];
    }

    private static function clean_messages($m) {
        $out = [];
        foreach (array_slice(is_array($m) ? $m : [], -24) as $x) {
            $role = ($x['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = mb_substr(trim((string) ($x['text'] ?? '')), 0, 8000);
            if ($text === '') { continue; }
            if ($out && end($out)['role'] === $role) { $out[count($out) - 1]['content'] .= "\n\n" . $text; continue; }
            $out[] = ['role' => $role, 'content' => $text];
        }
        while ($out && $out[0]['role'] !== 'user') { array_shift($out); }
        return $out;
    }

    private static function system($p) {
        $who = ($p['role'] ?? '') === 'owner' ? 'المالك' : 'مشرف العمليات';
        return "أنت مساعد التحكم الداخلي لشركة أكسجين/متقن للصيانة والمقاولات العامة (مكة وجدة). تتكلم مع {$who} بالعربية المصرية المختصرة.\n"
            . "شغلك فقط على أنظمة الشركة عبر الأدوات المتاحة: الطلبات، الواتساب، موقع ووردبريس، DNS نطاق oxygen11.com على هوستنجر، ومدونات الشبكة على بلوجر (قراءة).\n"
            . "لا تعد بتنفيذ شيء خارج هذه الأدوات، وارفض أي طلب لا يخص الشركة. قبل أي تعديل اقرأ الحالة الحالية بأداة قراءة.\n"
            . "الأدوات المعلَّمة (تحتاج تأكيد) لا تُنفَّذ فورًا: تُعرض على المستخدم ليضغط تنفيذ؛ لا تقل إنها تمت.\n"
            . 'التاريخ الآن ' . wp_date('Y-m-d H:i') . ' بتوقيت السعودية.';
    }

    /* ------------------------------------------------------------------ tool list */

    public static function tools() {
        $s = function ($props, $req = []) { return ['type' => 'object', 'properties' => (object) $props, 'required' => $req]; };
        $str = ['type' => 'string']; $int = ['type' => 'integer'];
        return [
            ['name' => 'orders_list', 'description' => 'قائمة الطلبات الأحدث مع فلتر الحالة أو البراند.', 'input_schema' => $s(['status' => $str, 'brand' => $str, 'limit' => $int])],
            ['name' => 'order_get', 'description' => 'تفاصيل طلب واحد.', 'input_schema' => $s(['id' => $str], ['id'])],
            ['name' => 'order_update', 'description' => 'تعديل حالة طلب أو موعده أو ملاحظة داخلية أو الفني المكلّف.', 'input_schema' => $s(['id' => $str, 'status' => $str, 'appointmentAt' => $str, 'staffNote' => $str, 'assignedTo' => $str], ['id'])],
            ['name' => 'stats_summary', 'description' => 'ملخص أرقام الطلبات (اليوم/الأسبوع، حسب الحالة والبراند).', 'input_schema' => $s(['days' => $int])],
            ['name' => 'wa_recent', 'description' => 'آخر محادثات الواتساب الواردة.', 'input_schema' => $s(['limit' => $int])],
            ['name' => 'wa_send', 'description' => 'إرسال رسالة واتساب لعميل من رقم الشركة. (تحتاج تأكيد)', 'input_schema' => $s(['phone' => $str, 'text' => $str], ['phone', 'text'])],
            ['name' => 'wp_search', 'description' => 'بحث في صفحات ومقالات موقع ووردبريس.', 'input_schema' => $s(['q' => $str, 'type' => ['type' => 'string', 'enum' => ['post', 'page', 'any']], 'limit' => $int])],
            ['name' => 'wp_get', 'description' => 'قراءة صفحة أو مقال من الموقع بالمعرّف.', 'input_schema' => $s(['id' => $int], ['id'])],
            ['name' => 'wp_draft', 'description' => 'إنشاء مسودة مقال أو صفحة جديدة (لا تُنشر).', 'input_schema' => $s(['title' => $str, 'content' => $str, 'type' => ['type' => 'string', 'enum' => ['post', 'page']]], ['title', 'content'])],
            ['name' => 'wp_update', 'description' => 'تعديل عنوان أو محتوى أو حالة صفحة/مقال موجود (النشر والتعديل على المنشور يحتاج تأكيد).', 'input_schema' => $s(['id' => $int, 'title' => $str, 'content' => $str, 'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'private']]], ['id'])],
            ['name' => 'dns_list', 'description' => 'سجلات DNS لنطاق oxygen11.com على هوستنجر.', 'input_schema' => $s(['filter' => $str])],
            ['name' => 'dns_set', 'description' => 'إضافة أو تعديل سجل DNS (CNAME/A/TXT) في oxygen11.com. (تحتاج تأكيد)', 'input_schema' => $s(['name' => $str, 'type' => ['type' => 'string', 'enum' => ['CNAME', 'A', 'TXT']], 'content' => $str, 'ttl' => $int], ['name', 'type', 'content'])],
            ['name' => 'network_list', 'description' => 'قائمة مواقع ومدونات الشبكة وروابطها.', 'input_schema' => $s([])],
            ['name' => 'techs_list', 'description' => 'الفنيون: الاسم والتخصص والحالة وآخر ظهور وعدد الطلبات الشغالة.', 'input_schema' => $s([])],
            ['name' => 'tech_create', 'description' => 'إصدار حساب فني جديد (يرجع كلمة مرور مؤقتة): name, handle (إنجليزي/أرقام)، اختياري phone, rating 0-5, creditBalance, discountPct, specialties, password. (تحتاج تأكيد)', 'input_schema' => $s(['name' => $str, 'handle' => $str, 'phone' => $str, 'password' => $str, 'rating' => ['type' => 'number'], 'creditBalance' => ['type' => 'number'], 'discountPct' => ['type' => 'number']], ['name', 'handle'])],
            ['name' => 'tech_update', 'description' => 'تعديل فني: rating, creditBalance أو addCredit (زيادة رصيد), discountPct, available, phone, dailyCapacity. (تحتاج تأكيد)', 'input_schema' => $s(['techId' => $str, 'rating' => ['type' => 'number'], 'creditBalance' => ['type' => 'number'], 'addCredit' => ['type' => 'number'], 'discountPct' => ['type' => 'number'], 'available' => ['type' => 'boolean']], ['techId'])],
            ['name' => 'order_assign', 'description' => 'إسناد طلب لفني (techId من techs_list).', 'input_schema' => $s(['id' => $str, 'techId' => $str], ['id', 'techId'])],
            ['name' => 'customers_list', 'description' => 'العملاء مجمّعين من الطلبات: جديد/متكرر/قديم، عدد الطلبات وآخر تعامل. q للبحث بالاسم أو الجوال.', 'input_schema' => $s(['q' => $str, 'kind' => ['type' => 'string', 'enum' => ['new', 'returning', 'old', 'all']], 'limit' => $int])],
            ['name' => 'shops_list', 'description' => 'محلات الصيانة المسجّلة على الخريطة الميدانية.', 'input_schema' => $s([])],
            ['name' => 'settings_get', 'description' => 'قراءة إعدادات النظام: ' . implode('، ', array_keys(self::SETTINGS)) . '.', 'input_schema' => $s(['key' => ['type' => 'string', 'enum' => array_keys(self::SETTINGS)]], ['key'])],
            ['name' => 'settings_set', 'description' => 'تعديل حقول في مستند إعدادات (patch يدمج مع الموجود). (تحتاج تأكيد)', 'input_schema' => $s(['key' => ['type' => 'string', 'enum' => array_keys(self::SETTINGS)], 'doc_id' => $str, 'patch' => ['type' => 'object']], ['key', 'doc_id', 'patch'])],
            ['name' => 'ai_engine', 'description' => 'حالة الذكاء الاصطناعي اللي جوه الموقع (البوت): المزودين المفعّلين والموديلات ونتيجة الاختبار.', 'input_schema' => $s(['test' => ['type' => 'boolean']])],
            ['name' => 'ai_engine_set', 'description' => 'تعديل إعدادات محرك الذكاء اللي جوه الموقع (ترتيب المزودين، تفعيل/إيقاف، الموديل، الحدود). المفاتيح بيدخلها المالك بنفسه من التطبيق. (تحتاج تأكيد)', 'input_schema' => $s(['order' => ['type' => 'array', 'items' => $str], 'providers' => ['type' => 'object'], 'per_ip_10min' => $int, 'per_ip_day' => $int, 'mask_pii' => ['type' => 'boolean']])],
            ['name' => 'ai_ask', 'description' => 'اسأل الذكاء الاصطناعي اللي جوه الموقع نفسه (نفس محرك البوت) وخد رده.', 'input_schema' => $s(['prompt' => $str], ['prompt'])],
            ['name' => 'blogger_posts', 'description' => 'آخر المقالات المنشورة على مدونة من الشبكة (من الخلاصة العامة).', 'input_schema' => $s(['brand' => $str, 'limit' => $int], ['brand'])],
        ];
    }

    /** Which calls wait for the owner's "تنفيذ" (policy: confirm what matters only). */
    public static function needs_confirm($name, $input) {
        if (in_array($name, ['wa_send', 'dns_set', 'settings_set', 'ai_engine_set', 'tech_create', 'tech_update'], true)) { return true; }
        if ($name === 'wp_update') {
            $post = get_post((int) ($input['id'] ?? 0));
            return ($input['status'] ?? '') === 'publish' || ($post && $post->post_status === 'publish');
        }
        return false;
    }

    /* ------------------------------------------------------------------ the loop */

    private static function loop($msgs, $p) {
        $model = Mutqan_AI::tier_model('high') ?: 'claude-opus-5-5';
        $steps = []; $pending = null; $text = '';
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            list($code, $data, $raw) = Mutqan_AI::anthropic_request(['model' => $model, 'max_tokens' => 2500, 'system' => self::system($p), 'tools' => self::tools(), 'messages' => $msgs]);
            if ($code < 200 || $code >= 300 || !isset($data['content'])) {
                error_log('[mutqan] agent ' . $code . ' ' . substr((string) $raw, 0, 300));
                throw new Mutqan_Error('ai_unavailable', 'المساعد مش متاح دلوقتي (' . $code . ').', 503);
            }
            Mutqan_AI::usage('anthropic', $model, $data['usage'] ?? [], 'agent');
            $msgs[] = ['role' => 'assistant', 'content' => $data['content']];
            $results = [];
            foreach ($data['content'] as $b) {
                if (($b['type'] ?? '') === 'text') { $text .= ($text !== '' ? "\n" : '') . $b['text']; }
                if (($b['type'] ?? '') !== 'tool_use') { continue; }
                $name = (string) $b['name']; $input = (array) ($b['input'] ?? []);
                if (self::needs_confirm($name, $input)) {
                    $pending = self::park($name, $input, $p);
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => 'بانتظار تأكيد المستخدم؛ لم يُنفَّذ بعد.'];
                    $steps[] = ['tool' => $name, 'status' => 'pending', 'summary' => $pending['summary']];
                    continue;
                }
                try { $out = self::run_tool($name, $input, $p); $ok = true; }
                catch (Throwable $e) { $out = ['error' => $e->getMessage()]; $ok = false; }
                self::audit($name, $input, $ok, $p);
                $steps[] = ['tool' => $name, 'status' => $ok ? 'done' : 'error'];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => mb_substr(wp_json_encode($out, JSON_UNESCAPED_UNICODE), 0, 12000), 'is_error' => !$ok];
            }
            if (($data['stop_reason'] ?? '') !== 'tool_use' || $pending) {
                if ($pending && $results) {           // let the model phrase the request for confirmation
                    $msgs[] = ['role' => 'user', 'content' => $results];
                    list($c2, $d2) = Mutqan_AI::anthropic_request(['model' => $model, 'max_tokens' => 600, 'system' => self::system($p), 'tools' => self::tools(), 'messages' => $msgs]);
                    if ($c2 >= 200 && $c2 < 300) { foreach ((array) ($d2['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') { $text .= "\n" . $b['text']; } } }
                }
                break;
            }
            $msgs[] = ['role' => 'user', 'content' => $results];
        }
        return ['text' => trim($text) ?: 'تم.', 'steps' => $steps, 'pending' => $pending];
    }

    /* ------------------------------------------------------------------ confirmation */

    private static function park($name, $input, $p) {
        $id = strtolower(wp_generate_password(16, false));
        set_transient('mq_agent_' . $id, ['name' => $name, 'input' => $input, 'by' => $p['id'] ?? ''], 1800);
        return ['id' => $id, 'tool' => $name, 'summary' => self::summary($name, $input)];
    }

    public static function summary($name, $in) {
        switch ($name) {
            case 'wa_send': return 'إرسال واتساب إلى ' . ($in['phone'] ?? '') . ': «' . mb_substr((string) ($in['text'] ?? ''), 0, 160) . '»';
            case 'dns_set': return 'سجل DNS ' . ($in['type'] ?? '') . ' ' . ($in['name'] ?? '') . '.' . self::ZONE . ' ← ' . ($in['content'] ?? '');
            case 'ai_engine_set': return 'تعديل إعدادات محرك الذكاء في الموقع: ' . mb_substr(wp_json_encode($in, JSON_UNESCAPED_UNICODE), 0, 200);
            case 'settings_set': return 'تعديل إعدادات «' . (self::SETTINGS[$in['key'] ?? ''] ?? '') . '»: ' . mb_substr(wp_json_encode($in['patch'] ?? [], JSON_UNESCAPED_UNICODE), 0, 200);
            case 'wp_update': return 'تعديل المحتوى رقم ' . ($in['id'] ?? '') . (isset($in['status']) ? ' (الحالة: ' . $in['status'] . ')' : '') . (isset($in['title']) ? ' — العنوان: ' . $in['title'] : '');
        }
        return $name;
    }

    private static function confirm($id, $p) {
        $key = 'mq_agent_' . preg_replace('/[^a-z0-9]/', '', $id);
        $a = get_transient($key);
        if (!$a) { throw new Mutqan_Error('expired', 'الأمر انتهت صلاحيته، اطلبه من جديد.', 410); }
        if (($a['by'] ?? '') !== ($p['id'] ?? '')) { throw new Mutqan_Error('forbidden', 'الأمر ده مطلوب من حساب تاني.', 403); }
        delete_transient($key);
        try { $out = self::run_tool($a['name'], $a['input'], $p); $ok = true; }
        catch (Throwable $e) { $out = ['error' => $e->getMessage()]; $ok = false; }
        self::audit($a['name'], $a['input'], $ok, $p, true);
        return ['text' => $ok ? 'اتنفذ: ' . self::summary($a['name'], $a['input']) : 'ما اتنفذش: ' . $out['error'], 'ok' => $ok, 'result' => $out];
    }

    private static function audit($name, $input, $ok, $p, $confirmed = false) {
        try {
            $sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
            Mutqan_Store::insert('_ai_audit', ['at' => gmdate('c'), 'action' => 'agent:' . $name, 'by' => (string) ($p['id'] ?? ''), 'ok' => $ok,
                'confirmed' => $confirmed, 'detail' => mb_substr(self::summary($name, $input) . ' ' . wp_json_encode($input, JSON_UNESCAPED_UNICODE), 0, 600)], null, [], $sys);
        } catch (Throwable $e) { /* never block */ }
    }

    /* ------------------------------------------------------------------ tools (all scoped to the business) */

    public static function run_tool($name, $in, $p) {
        switch ($name) {
            case 'orders_list':
                $f = [];
                if (!empty($in['status'])) { $f['status'] = (string) $in['status']; }
                if (!empty($in['brand'])) { $f['brand'] = (string) $in['brand']; }
                $rows = Mutqan_Store::select('orders', $f, ['order' => '-createdAt', 'limit' => max(1, min(50, (int) ($in['limit'] ?? 15)))], $p);
                return array_map([__CLASS__, 'order_brief'], $rows);
            case 'order_get':
                $o = Mutqan_Store::get_raw('orders', (string) $in['id']);
                if (!$o) { throw new Exception('الطلب غير موجود.'); }
                return $o;
            case 'order_assign':
                $t = (string) ($in['techId'] ?? '');
                if ($t === '') { throw new Exception('اختار الفني.'); }
                return self::order_brief(Mutqan_Store::update('orders', (string) $in['id'], ['technicianId' => $t, 'status' => 'assigned', 'assignedAt' => gmdate('c')], $p));
            case 'techs_list':
                $techs = Mutqan_Store::select('tech_profiles', [], ['limit' => 300], $p);
                $active = Mutqan_Store::select('orders', ['status' => ['in' => ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress']]], ['limit' => 500], $p);
                $load = []; foreach ($active as $o) { $k = (string) ($o['technicianId'] ?? ''); if ($k !== '') { $load[$k] = ($load[$k] ?? 0) + 1; } }
                return array_map(function ($t) use ($load) { $id = (string) ($t['userId'] ?? $t['id']); return ['id' => $id, 'name' => $t['displayName'] ?? ($t['name'] ?? 'فني'), 'phone' => $t['phone'] ?? '', 'specialties' => $t['specialties'] ?? '', 'available' => ($t['available'] ?? true) !== false, 'lastSeenAt' => $t['lastSeenAt'] ?? '', 'active' => $load[$id] ?? 0, 'rating' => $t['rating'] ?? null, 'creditBalance' => $t['creditBalance'] ?? null, 'discountPct' => $t['discountPct'] ?? null]; }, $techs);
            case 'customers_list':
                $rows = Mutqan_Store::select('orders', [], ['order' => '-createdAt', 'limit' => 2000], $p);
                $by = [];
                foreach ($rows as $o) {
                    $ph = preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? ''));
                    $k = $ph !== '' ? 'p' . substr($ph, -9) : (!empty($o['userId']) ? 'u' . $o['userId'] : 'o' . $o['id']);
                    $t = strtotime((string) ($o['createdAt'] ?? '')) ?: 0;
                    if (!isset($by[$k])) { $by[$k] = ['key' => $k, 'name' => $o['clientName'] ?? '', 'phone' => $o['clientPhone'] ?? '', 'brand' => $o['brand'] ?? '', 'address' => $o['address'] ?? '', 'orders' => 0, 'first' => $t, 'last' => $t, 'lastOrder' => $o['id']]; }
                    $c = &$by[$k]; $c['orders']++; if ($t < $c['first']) { $c['first'] = $t; } unset($c);
                }
                $q = trim((string) ($in['q'] ?? '')); $kind = (string) ($in['kind'] ?? 'all'); $now = time(); $out = [];
                foreach ($by as $c) {
                    $c['kind'] = $c['orders'] > 1 ? ($now - $c['last'] > 90 * 86400 ? 'old' : 'returning') : ($now - $c['first'] < 30 * 86400 ? 'new' : 'old');
                    if ($kind !== 'all' && $kind !== '' && $c['kind'] !== $kind) { continue; }
                    if ($q !== '' && mb_strpos($c['name'] . ' ' . $c['phone'], $q) === false) { continue; }
                    $c['first'] = gmdate('c', $c['first']); $c['last'] = gmdate('c', $c['last']); $out[] = $c;
                }
                return array_slice($out, 0, max(1, min(500, (int) ($in['limit'] ?? 200))));
            case 'shops_list':
                return array_map(function ($x) { return array_intersect_key($x, array_flip(['id', 'name', 'phone', 'available', 'specialties', 'lat', 'lng', 'lastSeenAt'])); }, Mutqan_Store::select('shops', [], ['limit' => 300], $p));
            case 'ai_engine':
                $st = Mutqan_AI::settings(); $pv = [];
                foreach ($st['providers'] as $id => $c) { $pv[$id] = ['label' => Mutqan_AI::labels()[$id] ?? $id, 'enabled' => !empty($c['enabled']), 'hasKey' => ($c['key'] ?? '') !== '', 'model' => $c['model'] ?? '']; }
                return ['available' => Mutqan_AI::any_enabled($st), 'order' => $st['order'], 'providers' => $pv, 'tiers' => $st['tiers'], 'per_ip_10min' => $st['per_ip_10min'], 'per_ip_day' => $st['per_ip_day'], 'mask_pii' => !empty($st['mask_pii']), 'test' => !empty($in['test']) ? Mutqan_AI::test_all() : null, 'settingsUrl' => home_url('/app/#/staff/ai-settings')];
            case 'ai_engine_set':
                $st = (array) get_option(Mutqan_AI::OPT, []); $def = Mutqan_AI::defaults();
                if (isset($in['order']) && is_array($in['order'])) { $st['order'] = array_values(array_intersect(array_map('strval', $in['order']), array_keys($def['providers']))); }
                foreach ((array) ($in['providers'] ?? []) as $id => $c) {
                    if (!isset($def['providers'][$id]) || !is_array($c)) { continue; }
                    $cur = (array) ($st['providers'][$id] ?? []);
                    if (array_key_exists('enabled', $c)) { $cur['enabled'] = !empty($c['enabled']) ? 1 : 0; }
                    if (isset($c['model']) && preg_match('#^[A-Za-z0-9@/._:-]{2,120}$#', (string) $c['model'])) { $cur['model'] = (string) $c['model']; }
                    $st['providers'][$id] = $cur;
                }
                foreach (['per_ip_10min' => [1, 1000], 'per_ip_day' => [1, 20000]] as $k => $r) { if (isset($in[$k])) { $st[$k] = max($r[0], min($r[1], (int) $in[$k])); } }
                if (isset($in['mask_pii'])) { $st['mask_pii'] = !empty($in['mask_pii']) ? 1 : 0; }
                update_option(Mutqan_AI::OPT, $st);
                return self::run_tool('ai_engine', [], $p);
            case 'ai_ask':
                $r = Mutqan_AI::run(mb_substr((string) ($in['prompt'] ?? ''), 0, 4000), ['tier' => 'low'], $p);
                return ['text' => is_array($r) ? ($r['text'] ?? '') : (string) $r, 'provider' => is_array($r) ? ($r['provider'] ?? '') : ''];
            case 'settings_get':
                $key = (string) ($in['key'] ?? '');
                if (!isset(self::SETTINGS[$key])) { throw new Exception('إعداد غير معروف.'); }
                return array_map([__CLASS__, 'strip_secrets'], Mutqan_Store::select($key, [], ['limit' => 20], $p));
            case 'settings_set':
                $key = (string) ($in['key'] ?? '');
                if (!isset(self::SETTINGS[$key])) { throw new Exception('إعداد غير معروف.'); }
                $patch = (array) ($in['patch'] ?? []);
                foreach (array_keys($patch) as $f) { if (preg_match('/key|secret|token|pass|pin|otp/i', (string) $f) || in_array($f, ['id', 'createdAt', 'createdBy', 'groupId'], true)) { throw new Exception('الحقل «' . $f . '» مش بيتعدل من هنا.'); } }
                if (!$patch) { throw new Exception('مفيش حقول للتعديل.'); }
                return self::strip_secrets(Mutqan_Store::update($key, (string) $in['doc_id'], $patch, $p));
            case 'tech_create': {
                $handle = strtolower(trim((string) ($in['handle'] ?? '')));
                $name = trim((string) ($in['name'] ?? ''));
                if ($handle === '' || $name === '') { throw new Exception('محتاج اسم الفني واسم المستخدم (إنجليزي أو أرقام).'); }
                $m = Mutqan_Auth::team_op('createMember', ['handle' => $handle, 'displayName' => $name, 'role' => 'technician', 'password' => (string) ($in['password'] ?? '')], $p);
                $prof = ['userId' => $m['userId'], 'displayName' => $name, 'phone' => preg_replace('/\D/', '', Mutqan_Auth::fold_digits((string) ($in['phone'] ?? ''))), 'dailyCapacity' => 10, 'scope' => 'wash_maintenance_install', 'active' => true, 'available' => true, 'specialties' => (array) ($in['specialties'] ?? []), 'level' => 'expert', 'homeLat' => null, 'homeLng' => null];
                foreach (['rating' => [0, 5], 'creditBalance' => [0, 1000000], 'discountPct' => [0, 90]] as $f => $r) { if (isset($in[$f]) && is_numeric($in[$f])) { $prof[$f] = max($r[0], min($r[1], (float) $in[$f])); } }
                Mutqan_Store::insert('tech_profiles', $prof, $m['userId'], ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'creator-and-admin'], $p);
                return ['created' => true, 'techId' => $m['userId'], 'handle' => $m['handle'], 'tempPassword' => $m['tempPassword'], 'rating' => $prof['rating'] ?? null, 'creditBalance' => $prof['creditBalance'] ?? null, 'discountPct' => $prof['discountPct'] ?? null, 'loginUrl' => $m['url']];
            }
            case 'tech_update': {
                $id = (string) ($in['techId'] ?? '');
                if ($id === '' || !Mutqan_Store::peek_doc('tech_profiles', $id)) { throw new Exception('الفني مش موجود (استخدم techs_list).'); }
                $patch = [];
                foreach (['rating' => [0, 5], 'creditBalance' => [0, 1000000], 'discountPct' => [0, 90], 'dailyCapacity' => [1, 100]] as $f => $r) { if (isset($in[$f]) && is_numeric($in[$f])) { $patch[$f] = max($r[0], min($r[1], (float) $in[$f])); } }
                if (isset($in['addCredit']) && is_numeric($in['addCredit'])) { $cur = Mutqan_Store::peek_doc('tech_profiles', $id); $patch['creditBalance'] = max(0, (float) ($cur['creditBalance'] ?? 0) + (float) $in['addCredit']); }
                foreach (['available', 'active'] as $f) { if (isset($in[$f])) { $patch[$f] = (bool) $in[$f]; } }
                if (isset($in['phone'])) { $patch['phone'] = preg_replace('/\D/', '', Mutqan_Auth::fold_digits((string) $in['phone'])); }
                if (isset($in['specialties'])) { $patch['specialties'] = (array) $in['specialties']; }
                if (!$patch) { throw new Exception('مفيش حقول للتعديل.'); }
                Mutqan_Store::update('tech_profiles', $id, $patch, $p);
                return ['updated' => true, 'techId' => $id] + $patch;
            }
            case 'order_update':
                if (isset($in['assignedTo']) && !isset($in['technicianId'])) { $in['technicianId'] = $in['assignedTo']; }
                $patch = array_intersect_key((array) $in, array_flip(['status', 'appointmentAt', 'staffNote', 'technicianId']));
                if (!$patch) { throw new Exception('مفيش حقول للتعديل.'); }
                return self::order_brief(Mutqan_Store::update('orders', (string) $in['id'], $patch, $p));
            case 'stats_summary':
                $days = max(1, min(90, (int) ($in['days'] ?? 7)));
                $since = gmdate('c', time() - $days * 86400);
                $rows = Mutqan_Store::select('orders', ['createdAt' => ['gte' => $since]], ['limit' => 1000], $p);
                $by = ['status' => [], 'brand' => [], 'specialty' => []];
                foreach ($rows as $o) {
                    foreach (['status' => 'status', 'brand' => 'brand', 'specialty' => 'specialtyId'] as $k => $f) { $v = (string) ($o[$f] ?? '—'); $by[$k][$v] = ($by[$k][$v] ?? 0) + 1; }
                }
                return ['days' => $days, 'orders' => count($rows), 'by' => $by];
            case 'wa_recent':
                $rows = Mutqan_Store::select('wa_threads', [], ['order' => '-updatedAt', 'limit' => max(1, min(30, (int) ($in['limit'] ?? 10)))], $p);
                return array_map(function ($t) { return array_intersect_key($t, array_flip(['id', 'phone', 'name', 'lastText', 'updatedAt', 'unread'])); }, $rows);
            case 'wa_send':
                $acct = Mutqan_WA::open_account();
                if ($acct === '') { throw new Exception('رقم الواتساب مش متصل.'); }
                $phone = preg_replace('/\D/', '', Mutqan_Auth::fold_digits((string) $in['phone']));
                if (strlen($phone) < 9) { throw new Exception('رقم غير صحيح.'); }
                return Mutqan_WA::send($acct, $phone, mb_substr((string) $in['text'], 0, 2000), 'agent', (string) ($p['id'] ?? ''));
            case 'wp_search':
                $q = new WP_Query(['s' => (string) ($in['q'] ?? ''), 'post_type' => ($in['type'] ?? 'any') === 'any' ? ['post', 'page'] : $in['type'], 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => max(1, min(30, (int) ($in['limit'] ?? 10))), 'no_found_rows' => true]);
                return array_map(function ($x) { return ['id' => $x->ID, 'type' => $x->post_type, 'status' => $x->post_status, 'title' => $x->post_title, 'url' => get_permalink($x)]; }, $q->posts);
            case 'wp_get':
                $x = get_post((int) $in['id']);
                if (!$x || !in_array($x->post_type, ['post', 'page'], true)) { throw new Exception('غير موجود.'); }
                return ['id' => $x->ID, 'type' => $x->post_type, 'status' => $x->post_status, 'title' => $x->post_title, 'url' => get_permalink($x), 'content' => mb_substr($x->post_content, 0, 10000)];
            case 'wp_draft':
                $id = wp_insert_post(['post_type' => ($in['type'] ?? 'post') === 'page' ? 'page' : 'post', 'post_status' => 'draft', 'post_title' => wp_strip_all_tags((string) $in['title']), 'post_content' => wp_kses_post((string) $in['content'])], true);
                if (is_wp_error($id)) { throw new Exception($id->get_error_message()); }
                return ['id' => $id, 'status' => 'draft', 'edit' => admin_url('post.php?post=' . $id . '&action=edit')];
            case 'wp_update':
                $x = get_post((int) $in['id']);
                if (!$x || !in_array($x->post_type, ['post', 'page'], true)) { throw new Exception('غير موجود.'); }
                $u = ['ID' => $x->ID];
                if (isset($in['title'])) { $u['post_title'] = wp_strip_all_tags((string) $in['title']); }
                if (isset($in['content'])) { $u['post_content'] = wp_kses_post((string) $in['content']); }
                if (isset($in['status']) && in_array($in['status'], ['draft', 'publish', 'private'], true)) { $u['post_status'] = $in['status']; }
                $r = wp_update_post($u, true);
                if (is_wp_error($r)) { throw new Exception($r->get_error_message()); }
                return ['id' => $x->ID, 'url' => get_permalink($x->ID), 'status' => get_post_status($x->ID)];
            case 'dns_list':
                $rows = self::hostinger('GET');
                $f = strtolower((string) ($in['filter'] ?? ''));
                return array_values(array_filter(array_map(function ($r) { return ['name' => $r['name'] ?? '', 'type' => $r['type'] ?? '', 'content' => implode(', ', array_column((array) ($r['records'] ?? []), 'content'))]; }, (array) $rows), function ($r) use ($f) { return $f === '' || strpos(strtolower($r['name'] . ' ' . $r['content']), $f) !== false; }));
            case 'dns_set':
                $n = strtolower(trim((string) $in['name']));
                if (!preg_match('/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?(\.[a-z0-9_-]+)*$/', $n) || in_array($n, self::PROTECTED_DNS, true)) { throw new Exception('اسم السجل ده محمي أو غير صالح.'); }
                $type = in_array($in['type'], ['CNAME', 'A', 'TXT'], true) ? $in['type'] : 'CNAME';
                $content = trim((string) $in['content']);
                if ($type === 'CNAME' && substr($content, -1) !== '.') { $content .= '.'; }
                self::hostinger('PUT', ['overwrite' => true, 'zone' => [['name' => $n, 'type' => $type, 'ttl' => max(300, min(86400, (int) ($in['ttl'] ?? 3600))), 'records' => [['content' => $content]]]]]);
                return ['ok' => true, 'record' => $n . '.' . self::ZONE];
            case 'network_list':
                $net = self::network();
                return array_map(function ($b) { return ['id' => $b['id'], 'name' => $b['name'], 'blog' => $b['live_url'] ?? ('https://' . $b['host'] . '/'), 'live' => !empty($b['blog_id']), 'spec' => $b['spec']]; }, $net);
            case 'blogger_posts':
                $b = null; foreach (self::network() as $x) { if ($x['id'] === ($in['brand'] ?? '')) { $b = $x; } }
                if (!$b || empty($b['blog_id'])) { throw new Exception('المدونة دي لسه مش شغالة.'); }
                $res = wp_remote_get('https://www.blogger.com/feeds/' . rawurlencode($b['blog_id']) . '/posts/default?alt=json&max-results=' . max(1, min(50, (int) ($in['limit'] ?? 10))), ['timeout' => 15]);
                $d = json_decode(wp_remote_retrieve_body($res), true);
                return array_map(function ($e) { $u = ''; foreach ($e['link'] as $l) { if ($l['rel'] === 'alternate') { $u = $l['href']; } } return ['title' => $e['title']['$t'] ?? '', 'url' => $u, 'published' => $e['published']['$t'] ?? '']; }, (array) ($d['feed']['entry'] ?? []));
            case 'blogger_publish':
                return Mutqan_Blogger::publish((string) ($in['brand'] ?? ''), (string) ($in['title'] ?? ''), (string) ($in['html'] ?? ''), $in['labels'] ?? []);
            case 'blogger_title':
                return Mutqan_Blogger::update_title((string) ($in['brand'] ?? ''), (string) ($in['post_id'] ?? ''), (string) ($in['title'] ?? ''));
            case 'blogger_list':
                return Mutqan_Blogger::posts((string) ($in['brand'] ?? ''), (int) ($in['limit'] ?? 20));
        }
        throw new Exception('أداة غير معروفة.');
    }

    private static function order_brief($o) {
        return array_intersect_key((array) $o, array_flip(['id', 'status', 'brand', 'specialtyId', 'clientName', 'clientPhone', 'address', 'appointmentAt', 'technicianId', 'createdAt', 'total', 'price', 'lat', 'lng']));
    }

    /** Never hand API keys, tokens or passwords to the assistant or the control centre. */
    private static function strip_secrets($d) {
        if (!is_array($d)) { return $d; }
        $out = [];
        foreach ($d as $k => $v) { if (is_string($k) && preg_match('/key|secret|token|pass|pin|otp/i', $k)) { $out[$k] = $v ? '••• محفوظ' : ''; continue; } $out[$k] = is_array($v) ? self::strip_secrets($v) : $v; }
        return $out;
    }

    /** The Blogger network list ships with the plugin build (blogger/network.json copied to data/). */
    private static function network() {
        $f = __DIR__ . '/data/blogger-network.json';
        $d = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($d) ? $d : [];
    }

    /** Hostinger DNS API, locked to the company zone. The token is entered by the owner in the admin page. */
    private static function hostinger($method, $body = null) {
        $tok = (string) get_option(self::OPT_HOSTINGER, '');
        if ($tok === '') { throw new Exception('أضف مفتاح Hostinger API من صفحة إعدادات متقن.'); }
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json', 'Accept' => 'application/json']];
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $res = wp_remote_request('https://developers.hostinger.com/api/dns/v1/zones/' . self::ZONE, $args);
        if (is_wp_error($res)) { throw new Exception($res->get_error_message()); }
        $code = (int) wp_remote_retrieve_response_code($res);
        $d = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) { throw new Exception('Hostinger: ' . ($d['message'] ?? $code)); }
        return $d;
    }

    /* ------------------------------------------------------------------ /control: same tools for a WordPress admin (the owner's control centre via the WPVibe connector) */

    public static function routes() {
        $perm = function () { return current_user_can('manage_options'); };
        register_rest_route('mutqan/v1', '/control', [
            ['methods' => 'GET', 'permission_callback' => $perm, 'callback' => [__CLASS__, 'control_get']],
            ['methods' => 'POST', 'permission_callback' => $perm, 'callback' => [__CLASS__, 'control_post']],
        ]);
    }

    private static function admin_principal() {
        return ['id' => 'wp-admin-' . get_current_user_id(), 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'wp-admin', 'handle' => ''];
    }

    /** GET ?tool=orders_list|order_get|stats_summary|wa_recent|wp_search|network_list&...args (read tools only). */
    public static function control_get($req) {
        $tool = (string) $req->get_param('tool');
        if (!in_array($tool, ['orders_list', 'order_get', 'stats_summary', 'wa_recent', 'wp_search', 'wp_get', 'network_list', 'blogger_list', 'techs_list', 'customers_list', 'shops_list', 'settings_get', 'dns_list', 'ai_engine'], true)) { return new WP_REST_Response(['error' => 'unknown tool'], 400); }
        try { return new WP_REST_Response(['data' => self::run_tool($tool, (array) $req->get_params(), self::admin_principal())], 200); }
        catch (Throwable $e) { return new WP_REST_Response(['error' => $e->getMessage()], 422); }
    }

    /** POST {tool, input}: write tools (order_update, wa_send, wp_draft, wp_update); the control centre asks the owner before sending. */
    public static function control_post($req) {
        $b = (array) $req->get_json_params();
        $tool = (string) ($b['tool'] ?? '');
        if (!in_array($tool, ['order_update', 'order_assign', 'settings_set', 'ai_engine_set', 'ai_ask', 'wa_send', 'wp_draft', 'wp_update', 'blogger_publish', 'blogger_title', 'tech_create', 'tech_update'], true)) { return new WP_REST_Response(['error' => 'unknown tool'], 400); }
        $p = self::admin_principal(); $in = (array) ($b['input'] ?? []);
        try { $out = self::run_tool($tool, $in, $p); self::audit($tool, $in, true, $p, true); return new WP_REST_Response(['data' => $out], 200); }
        catch (Throwable $e) { self::audit($tool, $in, false, $p, true); return new WP_REST_Response(['error' => $e->getMessage()], 422); }
    }
}
Mutqan_Agent::boot();
