<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The assistant's hands for each role (owner, 2026-11-20): one assistant, its powers set by who is talking.
 *
 *  owner      — everything: prices up/down, intake (open / book for tomorrow / paused), technicians (send work, stop
 *               work, notify, suspend, activate, role), customers (notify), and the customer bot's standing orders.
 *  operations — the same day-to-day control, without prices, roles and the site/DNS/engine settings.
 *  technician — his own jobs: list, move a job along (on the way → arrived → working → done), report a problem.
 *  customers  — served by the customer bot (site chat + WhatsApp hub), which reads the intake mode and the
 *               adjusted price table from here through the house rules, so an order from the owner reaches every
 *               customer conversation at once.
 * Every change goes through the same store and policy as the screens, and the ones that matter wait for «تنفيذ».
 */
final class Mutqan_Command {
    const INTAKE = 'mutqan_intake';
    const PRICE_BACKUP = 'mutqan_price_backup';
    const OPEN = ['new', 'forced', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }
    private static function role($p) { return (string) ($p['role'] ?? ''); }

    /* ------------------------------------------------------------------ who may use which tool */

    const OWNER_ONLY = ['orders_delete', 'prices_adjust', 'prices_revert', 'tech_role', 'sales_brain_set', 'dns_set', 'ai_engine_set', 'settings_set', 'wp_update', 'tech_create'];
    const TECH_TOOLS = ['my_jobs', 'job_status', 'job_problem'];
    const STAFF_ONLY = ['my_jobs', 'job_status', 'job_problem'];   // the technician's own tools: no use to the office

    public static function allowed($name, $p) {
        $r = self::role($p);
        if ($r === 'owner') { return !in_array($name, self::STAFF_ONLY, true); }
        if ($r === 'operations' || $r === 'supervisor') { return !in_array($name, self::OWNER_ONLY, true) && !in_array($name, self::STAFF_ONLY, true); }
        if ($r === 'technician') { return in_array($name, self::TECH_TOOLS, true); }
        return false;
    }

    public static function needs_confirm($name, $in) {
        if (in_array($name, ['prices_adjust', 'prices_revert', 'tech_role', 'orders_delete'], true)) { return true; }
        if ($name === 'tech_account' && ($in['action'] ?? '') === 'suspend') { return true; }
        if ($name === 'intake_set' && ($in['mode'] ?? '') === 'paused') { return true; }
        return false;
    }

    /* ------------------------------------------------------------------ the tool list */

    public static function tools() {
        $s = function ($props, $req = []) { return ['type' => 'object', 'properties' => (object) $props, 'required' => $req]; };
        $str = ['type' => 'string'];
        return [
            ['name' => 'intake_get', 'description' => 'حالة استقبال الطلبات دلوقتي (مفتوح / بنحجز لبكره / موقوف) وتعديل الأسعار الساري.', 'input_schema' => $s([])],
            ['name' => 'intake_set', 'description' => 'استقبال الطلبات الجديدة: mode = open (عادي) أو tomorrow (السوق زحمة: أي طلب جديد يتحجز لبكره الصبح) أو paused (وقف استقبال الطلبات واعتذر للعميل وخد رقمه). hours = مدة بالساعات (اختياري، بعدها يرجع عادي). note = سبب أو جملة تتقال للعميل. بوت العملاء على الموقع والواتساب بيطبقها فورًا.', 'input_schema' => $s(['mode' => ['type' => 'string', 'enum' => ['open', 'tomorrow', 'paused']], 'hours' => ['type' => 'number'], 'note' => $str], ['mode'])],
            ['name' => 'prices_adjust', 'description' => 'رفع أو تخفيض أسعار غسيل/صيانة المكيفات في الجدول الرسمي كله: percent من -50 لـ +100 (مثلاً 10 = زيادة 10%، -15 = تخفيض 15%)، والأسعار بتتقرّب لأقرب 5 ريال. maxDiscountPct (اختياري) = أقصى خصم يسمح بيه البوت في الفصال. الجدول ده هو اللي التطبيق والبوت بيحسبوا منه. يحتاج تأكيد.', 'input_schema' => $s(['percent' => ['type' => 'number'], 'maxDiscountPct' => ['type' => 'number']], ['percent'])],
            ['name' => 'prices_revert', 'description' => 'رجّع جدول الأسعار زي ما كان قبل آخر prices_adjust. يحتاج تأكيد.', 'input_schema' => $s([])],
            ['name' => 'tech_notify', 'description' => 'ابعت إشعار (بيرن على موبايله) لفني أو لكل الفنيين: techId من techs_list أو all، title، body.', 'input_schema' => $s(['techId' => $str, 'title' => $str, 'body' => $str], ['techId', 'body'])],
            ['name' => 'tech_account', 'description' => 'إيقاف أو تشغيل حساب فني: action = suspend (يوقف دخوله وما ياخدش شغل — يحتاج تأكيد) أو activate (يرجّعه). techId من techs_list.', 'input_schema' => $s(['techId' => $str, 'action' => ['type' => 'string', 'enum' => ['suspend', 'activate']], 'reason' => $str], ['techId', 'action'])],
            ['name' => 'tech_role', 'description' => 'تغيير صلاحية عضو في الفريق: role = technician أو operations. للمالك بس، ويحتاج تأكيد.', 'input_schema' => $s(['techId' => $str, 'role' => ['type' => 'string', 'enum' => ['technician', 'operations']]], ['techId', 'role'])],
            ['name' => 'order_hold', 'description' => 'التحكم في شغل طلب: action = hold (وقّف الشغل عليه وبلّغ الفني) أو resume (رجّعه) أو unassign (اسحبه من الفني ويرجع جديد) أو tomorrow (أجّله لبكره الصبح وبلّغ الفني والعميل). reason سبب قصير.', 'input_schema' => $s(['id' => $str, 'action' => ['type' => 'string', 'enum' => ['hold', 'resume', 'unassign', 'tomorrow']], 'reason' => $str], ['id', 'action'])],
            ['name' => 'customer_notify', 'description' => 'رسالة للعميل على صفحة طلبه + إشعار على موبايله (لو عنده التطبيق): orderId، text. (للواتساب استخدم wa_send).', 'input_schema' => $s(['orderId' => $str, 'text' => $str], ['orderId', 'text'])],
            ['name' => 'find', 'description' => 'بحث في كل حاجة ورجّع روابط تفتحها على طول: طلب (برقمه أو اسم العميل أو جواله أو الحي أو الخدمة)، خدمة وسعرها من الجدول، صفحة في النظام (الأسعار، الخريطة، غرفة العمليات…)، أو أمر. q = كلمة البحث.', 'input_schema' => $s(['q' => $str], ['q'])],
            ['name' => 'order_open', 'description' => 'هات طلب معيّن برقمه (أو آخر أرقام الـ id) مع رابط يفتحه: ref.', 'input_schema' => $s(['ref' => $str], ['ref'])],
            ['name' => 'orders_delete', 'description' => 'مسح طلبات نهائيًا (للمالك بس، ويحتاج تأكيد): numbers = أرقام الطلبات، أو filter = cancelled (الملغية) أو test (التجريبية) أو all (كله).', 'input_schema' => $s(['numbers' => ['type' => 'array', 'items' => $str], 'filter' => ['type' => 'string', 'enum' => ['cancelled', 'test', 'all']]])],
            ['name' => 'cache_clear', 'description' => 'امسح الكاش: all = true لكل المواقع (المركز + الشبكة)، وإلا الموقع الحالي بس.', 'input_schema' => $s(['all' => ['type' => 'boolean']])],
            ['name' => 'my_jobs', 'description' => 'شغلي: الطلبات المسندة ليا اللي لسه مفتوحة، بالعنوان والخدمة والموعد.', 'input_schema' => $s([])],
            ['name' => 'job_status', 'description' => 'حرّك طلب من طلباتي: status = en_route (في الطريق) أو arrived (وصلت) أو in_progress (بشتغل) أو completed (خلصت، ومعاها report وprice لو فيه).', 'input_schema' => $s(['id' => $str, 'status' => ['type' => 'string', 'enum' => ['en_route', 'arrived', 'in_progress', 'completed']], 'report' => $str, 'price' => ['type' => 'number']], ['id', 'status'])],
            ['name' => 'job_problem', 'description' => 'بلّغ العمليات بمشكلة في طلب من طلباتي (قطعة ناقصة، العميل مش موجود، العطل أكبر): id، text. بيوصلهم إشعار فوري.', 'input_schema' => $s(['id' => $str, 'text' => $str], ['id', 'text'])],
        ];
    }

    public static function names() { return array_column(self::tools(), 'name'); }

    /* ------------------------------------------------------------------ intake (open / tomorrow / paused) */

    public static function intake() {
        $s = get_option(self::INTAKE, []);
        if (!is_array($s) || empty($s['mode'])) { return ['mode' => 'open', 'note' => '', 'until' => '']; }
        if (!empty($s['until']) && time() > strtotime($s['until'])) { delete_option(self::INTAKE); return ['mode' => 'open', 'note' => '', 'until' => '']; }
        return $s;
    }

    /** The paragraph the customer bot reads last, so the owner's order wins over the default «today» rule. */
    public static function intake_rule() {
        $s = self::intake();
        $note = trim((string) ($s['note'] ?? ''));
        if ($s['mode'] === 'tomorrow') {
            return "### أمر من الإدارة الآن (يتقدم على قاعدة المواعيد)\n- الشغل النهارده مكتمل: أي طلب جديد يتسجل على «بكره الصبح» بدل «اليوم». قول للعميل بلطف إن مواعيد النهارده اتحجزت كلها، واحجز له أول موعد بكره، إلا لو حالة طارئة وخطيرة (تسريب كبير، ماس، غرفة تبريد فيها بضاعة) — ساعتها سجّلها عاجلة."
                . ($note !== '' ? "\n- ملاحظة الإدارة: {$note}" : '');
        }
        if ($s['mode'] === 'paused') {
            return "### أمر من الإدارة الآن (يتقدم على أي قاعدة)\n- استقبال الطلبات الجديدة موقوف مؤقتًا: ما تسجلش طلب جديد. اعتذر للعميل بلطف، وخد اسمه ونوع الخدمة والحي عشان نتواصل معاه أول ما نفتح (complete=false، handoff=true)."
                . ($note !== '' ? "\n- ملاحظة الإدارة: {$note}" : '');
        }
        return '';
    }

    private static function intake_set($in) {
        $mode = (string) ($in['mode'] ?? 'open');
        if (!in_array($mode, ['open', 'tomorrow', 'paused'], true)) { throw new Exception('الوضع لازم open أو tomorrow أو paused.'); }
        if ($mode === 'open') { delete_option(self::INTAKE); return ['mode' => 'open']; }
        $h = (float) ($in['hours'] ?? 0);
        $s = ['mode' => $mode, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 200), 'until' => $h > 0 ? gmdate('c', time() + (int) round(min(168, $h) * 3600)) : '', 'at' => gmdate('c')];
        update_option(self::INTAKE, $s, false);
        foreach (['operations', 'owner'] as $role) {
            Mutqan_Store::insert('_push', ['target' => ['role' => $role], 'title' => $mode === 'paused' ? '⏸ استقبال الطلبات موقوف' : '📅 الطلبات الجديدة بتتحجز لبكره', 'body' => $s['note'] ?: ($s['until'] ? 'لحد ' . wp_date('H:i', strtotime($s['until'])) : 'لحد ما يتلغى الأمر'), 'url' => '#/staff/operations', 'sound' => 'bell', 'event' => 'intake', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 86400)], self::sys());
        }
        return $s;
    }

    /* ------------------------------------------------------------------ prices */

    const PRICE_KEYS = ['split1', 'split2', 'split3', 'win1', 'win2', 'win3', 'winExtra', 'other1'];

    private static function prices_adjust($in, $p) {
        $pct = (float) ($in['percent'] ?? 0);
        if ($pct < -50 || $pct > 100 || $pct == 0.0) { throw new Exception('النسبة لازم بين -50 و +100 ومش صفر.'); }
        $cur = Mutqan_Policy::ac_table();
        $row = Mutqan_Store::get_raw('pricing', 'ac_calc') ?: [];
        // keep the prices as they were in effect (defaults included), so «رجّع» brings back exactly what customers saw
        update_option(self::PRICE_BACKUP, ['at' => gmdate('c'), 'table' => array_intersect_key($cur, array_flip(array_merge(self::PRICE_KEYS, ['maxDiscountPct'])))], false);
        $patch = [];
        foreach (self::PRICE_KEYS as $k) { $patch[$k] = max(5, (int) (round($cur[$k] * (1 + $pct / 100) / 5) * 5)); }
        if (isset($in['maxDiscountPct']) && is_numeric($in['maxDiscountPct'])) { $patch['maxDiscountPct'] = max(0, min(50, (float) $in['maxDiscountPct'])); }
        Mutqan_Store::upsert('pricing', array_merge($row, $patch), 'ac_calc', ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
        return ['percent' => $pct, 'before' => array_intersect_key($cur, array_flip(self::PRICE_KEYS)), 'after' => $patch];
    }

    private static function prices_revert() {
        $b = get_option(self::PRICE_BACKUP, []);
        if (!is_array($b) || empty($b['table'])) { throw new Exception('مفيش تعديل أسعار قبل كده أرجّعه.'); }
        $row = Mutqan_Store::get_raw('pricing', 'ac_calc') ?: [];
        Mutqan_Store::upsert('pricing', array_merge($row, (array) $b['table']), 'ac_calc', ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
        delete_option(self::PRICE_BACKUP);
        return ['reverted' => true, 'table' => array_intersect_key(Mutqan_Policy::ac_table(), array_flip(self::PRICE_KEYS))];
    }

    /* ------------------------------------------------------------------ people */

    private static function push($target, $title, $body, $url, $event, $sound = 'bell') {
        Mutqan_Store::insert('_push', ['target' => $target, 'title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 400), 'url' => $url, 'sound' => $sound, 'priority' => 'high', 'event' => $event, 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 3 * 86400)], self::sys());
    }

    private static function tech_exists($id) {
        if ($id === '' || !Mutqan_Auth::user_row($id)) { throw new Exception('الفني مش موجود (استخدم techs_list).'); }
    }

    private static function short($id) { return '#' . substr((string) $id, -5); }

    private static function order($id) {
        $o = Mutqan_Store::get_raw('orders', (string) $id);
        if (!$o) { throw new Exception('الطلب مش موجود.'); }
        $o['id'] = (string) $id;
        return $o;
    }

    /* ------------------------------------------------------------------ run */

    public static function run($name, $in, $p) {
        if (!self::allowed($name, $p)) { throw new Exception('الأمر ده مش من صلاحياتك.'); }
        switch ($name) {
            case 'intake_get':
                return ['intake' => self::intake(), 'prices' => array_intersect_key(Mutqan_Policy::ac_table(), array_flip(array_merge(self::PRICE_KEYS, ['maxDiscountPct']))), 'priceChangedAt' => (get_option(self::PRICE_BACKUP, [])['at'] ?? null)];
            case 'intake_set': return self::intake_set($in);
            case 'find': return self::find((string) ($in['q'] ?? ''));
            case 'order_open': {
                $o = self::resolve_order((string) ($in['ref'] ?? ''));
                if (!$o) { throw new Exception('ملقتش طلب بالرقم ده.'); }
                $l = self::order_link($o);
                return ['order' => ['id' => $o['id'], 'number' => $o['number'] ?? '', 'status' => $o['status'] ?? '', 'client' => $o['clientName'] ?? '', 'phone' => $o['clientPhone'] ?? '', 'service' => class_exists('Mutqan_Rest') ? Mutqan_Rest::spec_label($o['specialtyId'] ?? '') : '', 'technician' => $o['technicianName'] ?? '', 'address' => $o['address'] ?? '', 'price' => $o['price'] ?? null], 'links' => [$l]];
            }
            case 'orders_delete': return self::orders_delete($in, $p);
            case 'cache_clear': {
                if (!class_exists('Oxygen_Network')) { throw new Exception('مسح الكاش مش متاح هنا.'); }
                if (!empty($in['all']) && Oxygen_Network::is_hub()) { $r = Oxygen_Network::purge_network(); return ['text' => 'اتمسح الكاش في المركز، واتبعت أمر المسح لـ ' . max(0, (int) $r['count'] - 1) . ' موقع تاني.']; }
                $done = Oxygen_Network::purge_local();
                return ['text' => 'اتمسح كاش الموقع ده' . ($done ? ' (' . implode('، ', $done) . ')' : '') . '.'];
            }
            case 'prices_adjust': return self::prices_adjust($in, $p);
            case 'prices_revert': return self::prices_revert();

            case 'tech_notify': {
                $title = trim((string) ($in['title'] ?? '')) ?: '📣 رسالة من الإدارة';
                $body = trim((string) ($in['body'] ?? ''));
                if ($body === '') { throw new Exception('اكتب نص الإشعار.'); }
                $id = (string) ($in['techId'] ?? '');
                if ($id === 'all') { self::push(['role' => 'technician'], $title, $body, '#/staff', 'admin_note'); return ['sent' => 'all']; }
                self::tech_exists($id);
                self::push(['user' => $id], $title, $body, '#/staff', 'admin_note');
                return ['sent' => $id];
            }
            case 'tech_account': {
                $id = (string) ($in['techId'] ?? ''); self::tech_exists($id);
                $act = (string) ($in['action'] ?? '');
                $owner = self::sys();
                if ($act === 'suspend') {
                    Mutqan_Auth::team_op('disableMember', ['userId' => $id], $owner);
                    if (Mutqan_Store::peek_doc('tech_profiles', $id)) { Mutqan_Store::update('tech_profiles', $id, ['active' => false, 'suspendedAt' => gmdate('c'), 'suspendReason' => mb_substr((string) ($in['reason'] ?? ''), 0, 160)], $owner); }
                    return ['suspended' => $id];
                }
                if ($act === 'activate') {
                    Mutqan_Auth::team_op('enableMember', ['userId' => $id], $owner);
                    if (Mutqan_Store::peek_doc('tech_profiles', $id)) { Mutqan_Store::update('tech_profiles', $id, ['active' => true, 'suspendedAt' => null], $owner); }
                    self::push(['user' => $id], '✅ حسابك اتفعّل', 'تقدر تستقبل شغل تاني.', '#/staff', 'admin_note');
                    return ['activated' => $id];
                }
                throw new Exception('الإجراء لازم suspend أو activate.');
            }
            case 'tech_role': {
                $id = (string) ($in['techId'] ?? ''); self::tech_exists($id);
                $role = (string) ($in['role'] ?? '');
                if (!in_array($role, ['technician', 'operations'], true)) { throw new Exception('الصلاحية لازم technician أو operations.'); }
                return Mutqan_Auth::team_op('setRole', ['userId' => $id, 'role' => $role], self::sys());
            }

            case 'order_hold': {
                $o = self::order($in['id'] ?? '');
                $act = (string) ($in['action'] ?? '');
                $why = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 160);
                $tid = (string) ($o['technicianId'] ?? '');
                $short = self::short($o['id']);
                $url = '#/staff/order/' . $o['id'];
                if ($act === 'hold') {
                    Mutqan_Store::update('orders', $o['id'], ['held' => true, 'heldAt' => gmdate('c'), 'holdReason' => $why], $p);
                    if ($tid !== '') { self::push(['user' => $tid], '⛔ وقّف الشغل على الطلب ' . $short, $why ?: 'استنى تعليمات العمليات.', $url, 'order_hold'); }
                    return ['held' => $o['id']];
                }
                if ($act === 'resume') {
                    Mutqan_Store::update('orders', $o['id'], ['held' => false, 'heldAt' => null, 'holdReason' => ''], $p);
                    if ($tid !== '') { self::push(['user' => $tid], '▶️ كمّل الشغل على الطلب ' . $short, $why ?: 'اتشال الإيقاف.', $url, 'order_hold'); }
                    return ['resumed' => $o['id']];
                }
                if ($act === 'unassign') {
                    Mutqan_Store::update('orders', $o['id'], ['technicianId' => null, 'technicianName' => null, 'status' => 'new', 'held' => false], $p);
                    if ($tid !== '') { self::push(['user' => $tid], '↩️ الطلب ' . $short . ' اتسحب منك', $why ?: 'اتنقل لفني تاني.', '#/staff', 'order_hold'); }
                    return ['unassigned' => $o['id']];
                }
                if ($act === 'tomorrow') {
                    $t = new DateTime('tomorrow 09:00', new DateTimeZone('Asia/Riyadh'));
                    Mutqan_Store::update('orders', $o['id'], ['appointmentAt' => $t->format('c'), 'preferredTime' => 'بكره الصبح', 'held' => false], $p);
                    if ($tid !== '') { self::push(['user' => $tid], '📅 الطلب ' . $short . ' اتأجل لبكره', $why ?: 'الموعد بكره الصبح.', $url, 'order_hold'); }
                    if (!empty($o['clientId'])) { self::push(['user' => (string) $o['clientId']], '⏳ تم تأجيل طلبك لبكره', $why ?: 'موعدك بكره الصبح — هنتواصل معاك.', '#/order/' . $o['id'], 'order_status', 'chime'); }
                    return ['postponed' => $o['id'], 'at' => $t->format('c')];
                }
                throw new Exception('الإجراء لازم hold أو resume أو unassign أو tomorrow.');
            }
            case 'customer_notify': {
                $o = self::order($in['orderId'] ?? '');
                $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 600);
                if ($text === '') { throw new Exception('اكتب الرسالة.'); }
                Mutqan_Store::insert('order_msgs', ['orderId' => $o['id'], 'channel' => 'ops', 'kind' => 'text', 'from' => 'ops', 'fromName' => 'خدمة العملاء', 'text' => $text, 'at' => gmdate('c'),
                    'clientId' => $o['clientId'] ?? null, 'techId' => $o['technicianId'] ?? null], null, ['expiresAt' => gmdate('c', time() + 180 * 86400)], self::sys(), true);
                if (!empty($o['clientId'])) { self::push(['user' => (string) $o['clientId']], '💬 رسالة بخصوص طلبك ' . self::short($o['id']), $text, '#/order/' . $o['id'], 'order_msg', 'chime'); }
                return ['sent' => true, 'toApp' => !empty($o['clientId'])];
            }

            case 'my_jobs': {
                $rows = Mutqan_Store::select('orders', ['technicianId' => (string) ($p['id'] ?? ''), 'status' => ['in' => self::OPEN]], ['limit' => 20, 'order' => '-createdAt'], self::sys());
                return array_map(function ($o) {
                    return ['id' => $o['id'], 'number' => $o['number'] ?? '', 'status' => $o['status'] ?? '', 'service' => Mutqan_Rest::spec_label($o['specialtyId'] ?? ''), 'address' => mb_substr((string) ($o['address'] ?? ''), 0, 120),
                        'time' => $o['preferredTime'] ?? ($o['appointmentAt'] ?? ''), 'held' => !empty($o['held']), 'client' => $o['clientName'] ?? '', 'phone' => $o['clientPhone'] ?? ''];
                }, $rows);
            }
            case 'job_status': {
                $o = self::order($in['id'] ?? '');
                if ((string) ($o['technicianId'] ?? '') !== (string) ($p['id'] ?? '')) { throw new Exception('الطلب ده مش مسند ليك.'); }
                if (!empty($o['held'])) { throw new Exception('الطلب ده موقوف من العمليات — استنى تعليماتهم.'); }
                $st = (string) ($in['status'] ?? '');
                if (!in_array($st, ['en_route', 'arrived', 'in_progress', 'completed'], true)) { throw new Exception('حالة مش معروفة.'); }
                $patch = ['status' => $st];
                if ($st === 'completed') {
                    if (trim((string) ($in['report'] ?? '')) !== '') { $patch['report'] = mb_substr(trim((string) $in['report']), 0, 1000); }
                    if (isset($in['price']) && is_numeric($in['price']) && $in['price'] > 0) { $patch['price'] = round((float) $in['price'], 2); }
                    $patch['completedAt'] = gmdate('c');
                }
                $doc = Mutqan_Store::update('orders', $o['id'], $patch, $p);
                return ['id' => $o['id'], 'status' => $doc['status'] ?? $st];
            }
            case 'job_problem': {
                $o = self::order($in['id'] ?? '');
                if ((string) ($o['technicianId'] ?? '') !== (string) ($p['id'] ?? '')) { throw new Exception('الطلب ده مش مسند ليك.'); }
                $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 600);
                if ($text === '') { throw new Exception('اكتب المشكلة.'); }
                // the technician channel: staff read it, the customer does not
                Mutqan_Store::insert('order_msgs', ['orderId' => $o['id'], 'channel' => 'tech', 'kind' => 'text', 'from' => 'tech', 'fromName' => mb_substr((string) ($p['name'] ?? 'الفني'), 0, 60), 'text' => '⚠️ ' . $text, 'at' => gmdate('c'),
                    'techId' => (string) ($p['id'] ?? '')], null, ['expiresAt' => gmdate('c', time() + 180 * 86400)], self::sys(), true);
                foreach (['operations', 'owner'] as $role) { self::push(['role' => $role], '⚠️ مشكلة من الفني في الطلب ' . self::short($o['id']), $text, '#/staff/order/' . $o['id'], 'tech_problem'); }
                return ['reported' => true];
            }
        }
        throw new Exception('أداة غير معروفة.');
    }


    /* ------------------------------------------------------------------ find / open / delete / cache */

    const PAGES = [
        ['/staff/operations', 'غرفة العمليات', 'العمليات غرفه التشغيل'],
        ['/staff/orders-board', 'لوحة الطلبات', 'لوحه الطلبات كل الطلبات تصدير'],
        ['/staff/map', 'الخريطة الميدانية', 'الخريطه الخريطة الفنيين علي الخريطه'],
        ['/staff/pricing', 'التسعير والخصومات', 'الاسعار الأسعار التسعير الخصومات الخصم'],
        ['/staff/ac', 'أسعار وخدمات التكييف', 'المكيفات التكييف غسيل'],
        ['/staff/reports', 'التقارير', 'تقارير التقرير الإيرادات الايراد'],
        ['/staff/wa-hub', 'مركز الواتساب', 'واتساب الواتساب الرسايل'],
        ['/staff/tech-requests', 'طلبات الفنيين', 'طلبات انضمام فنيين جدد'],
        ['/staff/warranties', 'الضمانات', 'ضمان الضمانات'],
        ['/staff/inventory', 'المخزون', 'مخزن المخزون قطع غيار'],
        ['/staff/accounts', 'الحسابات', 'حسابات العهده المصاريف'],
        ['/staff/payment-methods', 'طرق الدفع', 'الدفع تحويل بنك'],
        ['/staff/ai-settings', 'إعدادات البوت', 'البوت الذكاء الاصطناعي اعدادات المساعد'],
        ['/staff/sales-brain', 'العقل التجاري', 'الفصال العقل التجاري المبيعات'],
        ['/staff/admin', 'الإدارة الشاملة', 'الاعدادات الإعدادات الاداره'],
        ['/staff/network', 'شبكة المواقع', 'المواقع الشبكه الدومينات'],
        ['/staff/apps', 'التطبيقات والأجهزة', 'التطبيقات الابلكيشن apk'],
        ['/staff/schedule', 'المواعيد', 'المواعيد الجدول الحجز'],
        ['/staff/business', 'بيانات الشركة', 'بيانات الشركه الاسم الشعار'],
        ['/staff/security', 'الأمان', 'الامان كلمه السر'],
        ['/staff/wp', 'ووردبريس', 'ووردبريس المقالات الموقع'],
        ['/staff/ops-manager', 'مدير العمليات', 'مدير العمليات'],
        ['/staff/ai-trainer', 'ذاكرة المساعد وتطوره', 'ذاكره المساعد التعلم الردود المقترحه'],
        ['/notifications', 'الإشعارات', 'الاشعارات التنبيهات'],
    ];

    private static function norm($t) { return class_exists('Mutqan_Quick') ? Mutqan_Quick::norm($t) : trim((string) $t); }

    /** A reference the owner types (1024, #1024, the end of an id, a full id) → the order, or null. */
    public static function resolve_order($ref) {
        $ref = trim(Mutqan_Auth::fold_digits((string) $ref), " #\t");
        if ($ref === '') { return null; }
        $o = Mutqan_Store::get_raw('orders', $ref);
        if ($o) { $o['id'] = $ref; return $o; }
        foreach (Mutqan_Store::select('orders', [], ['limit' => 500, 'order' => '-createdAt'], self::sys()) as $x) {
            if ((string) ($x['number'] ?? '') === $ref || ($ref !== '' && strlen($ref) >= 4 && substr((string) $x['id'], -strlen($ref)) === $ref)) { return $x; }
        }
        return null;
    }

    private static function order_link($o) {
        $num = (string) ($o['number'] ?? '') ?: substr((string) $o['id'], -5);
        $st = class_exists('Mutqan_Ops') ? (Mutqan_Ops::STATUS[$o['status'] ?? ''] ?? ($o['status'] ?? '')) : ($o['status'] ?? '');
        return ['type' => 'order', 'label' => 'طلب #' . $num . ' — ' . trim((string) ($o['clientName'] ?? '')) . ' · ' . $st, 'href' => '#/staff/order/' . $o['id']];
    }

    public static function find($q) {
        $q = trim((string) $q);
        if ($q === '') { throw new Exception('اكتب كلمة البحث.'); }
        $n = self::norm($q); $digits = preg_replace('/\D/', '', Mutqan_Auth::fold_digits($q));
        $out = [];
        // orders
        foreach (Mutqan_Store::select('orders', [], ['limit' => 500, 'order' => '-createdAt'], self::sys()) as $o) {
            $hay = self::norm(implode(' ', [$o['number'] ?? '', $o['clientName'] ?? '', $o['address'] ?? '', $o['technicianName'] ?? '', class_exists('Mutqan_Rest') ? Mutqan_Rest::spec_label($o['specialtyId'] ?? '') : '', $o['notes'] ?? '', $o['source'] ?? '']));
            $ph = preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? ''));
            $hit = ($digits !== '' && strlen($digits) >= 3 && ((string) ($o['number'] ?? '') === $digits || ($ph !== '' && strpos($ph, $digits) !== false) || substr((string) $o['id'], -strlen($digits)) === $digits))
                || (mb_strlen($n) >= 2 && mb_strpos($hay, $n) !== false);
            if ($hit) { $out[] = self::order_link($o); }
            if (count($out) >= 8) { break; }
        }
        // services and prices
        if (class_exists('Mutqan_Rest')) {
            foreach (Mutqan_Rest::SPEC_LABELS as $id => $label) {
                if (mb_strlen($n) >= 2 && (mb_strpos(self::norm($label), $n) !== false || mb_strpos($n, self::norm($label)) !== false)) { $out[] = ['type' => 'service', 'label' => 'خدمة: ' . $label, 'href' => '#/staff/pricing']; }
            }
        }
        if (class_exists('Mutqan_AI')) {
            foreach (explode("\n", Mutqan_AI::price_sheet()) as $line) {
                $ln = self::norm($line);
                $words = array_filter(explode(' ', $n), function ($w) { return mb_strlen($w) >= 3 && !in_array($w, ['سعر', 'اسعار', 'بكام', 'تسعير', 'كام'], true); });
                foreach ($words as $w) { if (mb_strpos($ln, $w) !== false) { $out[] = ['type' => 'price', 'label' => trim($line, "- \t"), 'href' => '#/staff/pricing']; break; } }
            }
        }
        // pages
        foreach (self::PAGES as $pg) {
            $hay = self::norm($pg[1] . ' ' . $pg[2]);
            foreach (array_filter(explode(' ', $n), function ($w) { return mb_strlen($w) >= 3; }) as $w) { if (mb_strpos($hay, $w) !== false) { $out[] = ['type' => 'page', 'label' => 'صفحة: ' . $pg[1], 'href' => '#' . $pg[0]]; break; } }
        }
        // commands
        if (class_exists('Mutqan_Quick')) {
            foreach (explode("\n", Mutqan_Quick::help()) as $line) { if (mb_strlen($n) >= 3 && mb_strpos(self::norm($line), $n) !== false) { $out[] = ['type' => 'command', 'label' => 'أمر: ' . trim($line, "• \t"), 'href' => '']; } }
        }
        $seen = []; $out = array_values(array_filter($out, function ($x) use (&$seen) { $k = $x['label'] . '|' . $x['href']; if (isset($seen[$k])) { return false; } $seen[$k] = 1; return true; }));
        return ['q' => $q, 'count' => count($out), 'results' => array_slice($out, 0, 15)];
    }

    private static function orders_delete($in, $p) {
        $ids = [];
        foreach ((array) ($in['numbers'] ?? []) as $ref) { $o = self::resolve_order($ref); if ($o) { $ids[(string) $o['id']] = self::order_link($o)['label']; } }
        $f = (string) ($in['filter'] ?? '');
        if ($f !== '') {
            foreach (Mutqan_Store::select('orders', [], ['limit' => 500, 'order' => '-createdAt'], self::sys()) as $o) {
                $test = (bool) preg_match('/(تجرب|تجربة|اختبار|test)/iu', (string) ($o['clientName'] ?? '') . ' ' . (string) ($o['notes'] ?? '') . ' ' . (string) ($o['source'] ?? ''));
                if ($f === 'all' || ($f === 'cancelled' && ($o['status'] ?? '') === 'cancelled') || ($f === 'test' && $test)) { $ids[(string) $o['id']] = self::order_link($o)['label']; }
            }
        }
        if (!$ids) { throw new Exception('مفيش طلبات بالمواصفات دي.'); }
        $r = Mutqan_Owner::handle(['op' => 'deleteOrders', 'ids' => array_keys($ids)], $p);
        return ['deleted' => (int) ($r['deleted'] ?? 0), 'orders' => array_values($ids), 'links' => [['type' => 'page', 'label' => 'لوحة الطلبات', 'href' => '#/staff/orders-board']]];
    }

    /** Links for the chat: whatever a tool returned that has a page — orders, search results — becomes a tap. */
    public static function links($tool, $out) {
        $L = [];
        $walk = function ($v, $depth) use (&$walk, &$L) {
            if ($depth > 4 || count($L) >= 10 || !is_array($v)) { return; }
            if (isset($v['href']) && is_string($v['href']) && $v['href'] !== '' && isset($v['label'])) { $L[] = ['label' => (string) $v['label'], 'href' => (string) $v['href']]; return; }
            $id = (string) ($v['id'] ?? '');
            if ($id !== '' && preg_match('/^[0-9a-f-]{36}$/i', $id) && (isset($v['status']) || isset($v['clientName']) || isset($v['specialtyId']))) {
                $L[] = ['label' => 'طلب #' . (((string) ($v['number'] ?? '')) ?: substr($id, -5)) . (!empty($v['clientName']) ? ' — ' . $v['clientName'] : ''), 'href' => '#/staff/order/' . $id];
                return;
            }
            foreach ($v as $x) { if (is_array($x)) { $walk($x, $depth + 1); } }
        };
        $walk($out, 0);
        $seen = [];
        return array_values(array_filter($L, function ($x) use (&$seen) { if (isset($seen[$x['href']])) { return false; } $seen[$x['href']] = 1; return true; }));
    }

    /** Plain words for the new tools when the internal engine runs them (no model). */
    public static function say($tool, $o) {
        if ($tool === 'find') {
            if (empty($o['results'])) { return 'ملقتش حاجة عن «' . ($o['q'] ?? '') . '».'; }
            $n = count(self::links('find', $o));
            $cmds = array_values(array_filter($o['results'], function ($x) { return ($x['href'] ?? '') === ''; }));
            $extra = $cmds ? "\n" . implode("\n", array_map(function ($x) { return '• ' . $x['label']; }, array_slice($cmds, 0, 5))) : '';
            return ($n ? 'لقيت ' . ($n === 1 ? 'نتيجة واحدة' : $n . ' نتايج') . ' عن «' . $o['q'] . '» — اضغط تفتحها:' : 'عن «' . $o['q'] . '»:') . $extra;
        }
        if ($tool === 'order_open') {
            $x = (array) ($o['order'] ?? []);
            $st = class_exists('Mutqan_Ops') ? (Mutqan_Ops::STATUS[$x['status'] ?? ''] ?? ($x['status'] ?? '')) : ($x['status'] ?? '');
            $bits = array_filter(['طلب #' . ($x['number'] ?? ''), $x['client'] ?? '', $x['service'] ?? '', $st, !empty($x['technician']) ? 'الفني: ' . $x['technician'] : '', $x['address'] ?? '']);
            return '✅ ' . implode(' · ', $bits) . "\nاضغط تحت تفتحه.";
        }
        if ($tool === 'orders_delete') { return '✅ اتمسح ' . (int) ($o['deleted'] ?? 0) . ' طلب نهائيًا.'; }
        if ($tool === 'cache_clear') { return '✅ ' . ($o['text'] ?? 'اتمسح الكاش.'); }
        return null;
    }

    /** How the assistant introduces itself to each role. */
    public static function role_brief($p) {
        $r = self::role($p);
        if ($r === 'technician') {
            return "أنت «مساعد الفني» في أكسجين/أكسجين. بتكلم فني ميداني: ساعده يشوف شغله، يحرّك الطلب (في الطريق ← وصلت ← بشتغل ← خلصت)، يبلّغ العمليات بأي مشكلة، وجاوب أسئلته الفنية في التكييف وغرف التبريد والسباكة والكهرباء بخطوات عملية آمنة ومختصرة. ما تقدرش تغيّر أسعار ولا تسند طلبات ولا تشوف بيانات غير طلباته.";
        }
        $i = self::intake();
        return "استقبال الطلبات الآن: " . ['open' => 'مفتوح عادي', 'tomorrow' => 'بنحجز لبكره', 'paused' => 'موقوف'][$i['mode']] . ".\n"
            . "تقدر بالأوامر: ترفع/تقلل الأسعار (prices_adjust) وتحدد أقصى خصم، تفتح/توقف الاستقبال أو تخليه يحجز لبكره (intake_set)، تبعت شغل لفني (order_assign) أو توقفه/تسحبه/تأجله (order_hold)، تبعت إشعار لفني (tech_notify)، توقف/تشغل حساب فني (tech_account)، تغيّر صلاحيته (tech_role)، تبعت رسالة لعميل (customer_notify أو wa_send)، وتدي تعليمات ثابتة لبوت العملاء (sales_teach) وتعدّل سياسة الفصال (sales_brain_set).";
    }
}
