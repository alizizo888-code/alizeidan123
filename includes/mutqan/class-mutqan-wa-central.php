<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «السنترال»: many WhatsApp lines in front, one master WhatsApp in the owner's hand.
 *
 *   1. Every line belongs to a site (or to the whole network). Every conversation gets an identity card the moment the
 *      customer writes: which site/domain, which company name, which line received it. The site comes from the line,
 *      or from the code every website adds to its WhatsApp links (#khabeer), so two sites sharing a line never mix.
 *   2. The cheap path first: the reception rules answer greetings, prices, location for free; the model is used only to
 *      gather the job (service, area, time). (Mutqan_WA_Bot / Mutqan_Order_AI — unchanged, they now read the card.)
 *   3. The master: when a conversation becomes a confirmed order, from any site, one formatted message goes to the
 *      owner's master WhatsApp («أوردر جاهز | من موقع … | العميل | العطل | اللوكيشن»). From the master he commands:
 *      «رد 1234 …» answers the customer FROM THE LINE THE CUSTOMER USED (his own number never shows), «اعتمد 1234»
 *      sends it to the right technician over the technicians' line, plus every command of the internal engine.
 *   4. Lanes never mix: customers' lines, the technicians' line, the reports line (Mutqan_WA_Fleet roles).
 *   5. n8n on the VPS: every event above is also posted, signed, to the owner's n8n; n8n can send back through a signed
 *      endpoint (send a message from a role's line, write to the master). Optional — nothing depends on it.
 */
final class Mutqan_WA_Central {
    const OPT = 'mutqan_wa_central';
    const CRON = 'mutqan_wa_central_daily';

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function boot() {
        add_action('wp_footer', [__CLASS__, 'link_tagger'], 99);
        add_action(self::CRON, [__CLASS__, 'daily']);
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 3600, 'hourly', self::CRON); } }, 5);
    }

    public static function defaults() {
        return ['master' => [], 'digest' => 1, 'dailyHour' => 22, 'n8nUrl' => '', 'n8nSecret' => '', 'events' => ['order_ready' => 1, 'new_conversation' => 1, 'daily' => 1], 'lastDaily' => ''];
    }

    public static function settings() {
        $s = array_merge(self::defaults(), (array) get_option(self::OPT, []));
        if ($s['n8nSecret'] === '') { $s['n8nSecret'] = bin2hex(random_bytes(20)); update_option(self::OPT, $s, false); }
        return $s;
    }

    public static function save($in) {
        $s = self::settings();
        if (isset($in['master'])) {
            $list = is_array($in['master']) ? $in['master'] : preg_split('/[\s,،]+/u', (string) $in['master']);
            $s['master'] = array_values(array_unique(array_filter(array_map([__CLASS__, 'intl'], (array) $list))));
        }
        if (isset($in['digest'])) { $s['digest'] = !empty($in['digest']) ? 1 : 0; }
        if (isset($in['journey']) && class_exists('Mutqan_WA_Journey')) { Mutqan_WA_Journey::set_enabled(!empty($in['journey'])); }
        if (isset($in['dailyHour']) && is_numeric($in['dailyHour'])) { $s['dailyHour'] = max(0, min(23, (int) $in['dailyHour'])); }
        if (isset($in['n8nUrl'])) {
            $u = trim((string) $in['n8nUrl']);
            if ($u !== '' && !preg_match('#^https?://[^\s]+$#i', $u)) { throw new Mutqan_Error('bad_url', 'رابط n8n غير صالح.', 400); }
            $s['n8nUrl'] = $u;
        }
        if (!empty($in['rotateSecret'])) { $s['n8nSecret'] = bin2hex(random_bytes(20)); }
        foreach ((array) ($in['events'] ?? []) as $k => $v) { if (isset($s['events'][$k])) { $s['events'][$k] = !empty($v) ? 1 : 0; } }
        update_option(self::OPT, $s, false);
        return self::view();
    }

    /** What the settings screen shows (the n8n secret only to the owner who asks for it). */
    public static function view() {
        $s = self::settings();
        return ['master' => $s['master'], 'digest' => (int) $s['digest'], 'dailyHour' => (int) $s['dailyHour'], 'n8nUrl' => $s['n8nUrl'], 'n8nSecret' => $s['n8nSecret'],
            'n8nInbound' => rest_url(Mutqan_Rest::NS . '/n8n'), 'events' => $s['events'], 'lines' => self::lines(),
            'journey' => class_exists('Mutqan_WA_Journey') ? (int) Mutqan_WA_Journey::enabled() : 0];
    }

    public static function intl($p) { return class_exists('Mutqan_WA') ? Mutqan_WA::intl((string) $p) : preg_replace('/\D/', '', (string) $p); }

    /* ------------------------------------------------------------------ 1. the identity card */

    /** Every line with its site and role, for the screens. */
    public static function lines() {
        $out = [];
        foreach (Mutqan_WA_Fleet::numbers() as $n) {
            $b = (string) (Mutqan_WA_Fleet::roles()[$n['id']]['brand'] ?? '');
            $out[] = ['id' => $n['id'], 'label' => $n['label'], 'phone' => $n['phone'], 'role' => $n['role'], 'status' => $n['status'], 'brand' => $b, 'site' => $b !== '' ? self::site($b)['name'] : 'كل المواقع'];
        }
        return $out;
    }

    public static function site($brand) {
        $brand = (string) $brand;
        if ($brand === '' || !class_exists('Oxygen_Network') || !Oxygen_Network::valid($brand)) { $brand = 'oxygen'; }
        $b = class_exists('Oxygen_Network') ? (Oxygen_Network::brand($brand) ?: []) : [];
        return ['id' => $brand, 'name' => (string) ($b['name'] ?? $brand), 'domain' => class_exists('Oxygen_Network') ? (string) Oxygen_Network::host_of($brand) : '', 'color' => (string) ($b['color'] ?? '#64748b')];
    }

    /** The site a message belongs to: the code in the customer's words wins, else the line's own site, else none. */
    /** «#khabeer/a123 … بخصوص: عنوان» — the article or page the customer pressed WhatsApp on. */
    public static function source($text) {
        $src = [];
        if (preg_match('/#[a-z][a-z0-9]{1,23}\/([ap])(\d{1,9})/i', (string) $text, $m)) { $src = ['type' => strtolower($m[1]) === 'a' ? 'article' : 'page', 'id' => (int) $m[2]]; }
        if (preg_match('/بخصوص:\s*([^#\n]{2,90})/u', (string) $text, $m)) { $src['title'] = trim($m[1]); }
        return $src;
    }

    public static function detect($acct, $text) {
        if (preg_match_all('/#([a-z][a-z0-9]{1,23})\b/i', (string) $text, $m)) {
            foreach ($m[1] as $code) { $code = strtolower($code); if (class_exists('Oxygen_Network') && Oxygen_Network::valid($code)) { return [$code, 'link']; } }
        }
        $b = (string) (Mutqan_WA_Fleet::roles()[$acct]['brand'] ?? '');
        return $b !== '' ? [$b, 'line'] : ['', ''];
    }

    public static function card($acct, $text) {
        list($brand, $how) = self::detect($acct, $text);
        $line = null;
        foreach (Mutqan_WA_Fleet::numbers() as $n) { if ($n['id'] === $acct) { $line = $n; break; } }
        $site = self::site($brand);
        return ['brand' => $brand !== '' ? $site['id'] : '', 'site' => $brand !== '' ? $site['name'] : 'غير محدد', 'domain' => $brand !== '' ? $site['domain'] : '',
            'color' => $site['color'], 'how' => $how, 'line' => ['id' => (string) $acct, 'label' => (string) ($line['label'] ?? $acct), 'phone' => (string) ($line['phone'] ?? ''), 'role' => (string) ($line['role'] ?? 'general')],
            'source' => self::source($text), 'at' => gmdate('c')];
    }

    /** First message of a conversation: put the card on it (once), tell n8n. Later messages may still fill a missing site. */
    public static function on_incoming($acct, $phone, $text, $tid) {
        $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
        $cur = (array) ($th['card'] ?? []);
        if ($cur && ($cur['brand'] ?? '') !== '') { return $cur; }
        $card = self::card($acct, $text);
        if ($cur && $card['brand'] === '') { return $cur; }
        Mutqan_WA::put_thread($tid, ['card' => $card, 'brand' => $card['brand']]);
        if (!$cur) { self::emit('new_conversation', ['thread' => $tid, 'phone' => $phone, 'card' => $card, 'text' => mb_substr((string) $text, 0, 500)]); }
        return $card;
    }

    /* ------------------------------------------------------------------ 3. the master */

    public static function is_master($phone) {
        $k = substr(preg_replace('/\D/', '', (string) $phone), -9);
        if (strlen($k) < 9) { return false; }
        foreach (self::settings()['master'] as $m) { if (substr($m, -9) === $k) { return true; } }
        return false;
    }

    /** The ready-order message, one look is enough. */
    public static function digest_text($o) {
        $card = (array) ($o['card'] ?? []);
        $site = $card['site'] ?? (self::site((string) ($o['brand'] ?? ''))['name']);
        $n = (string) ($o['number'] ?? '') ?: substr((string) ($o['id'] ?? ''), -6);
        $lines = ['✅ *أوردر جاهز #' . $n . '*', '🌐 من موقع: ' . $site . (!empty($card['domain']) ? ' (' . $card['domain'] . ')' : '')];
        if (!empty($card['line']['label'])) { $lines[] = '📞 الخط: ' . $card['line']['label'] . (!empty($card['line']['phone']) ? ' +' . $card['line']['phone'] : ''); }
        $lines[] = '👤 العميل: ' . ((string) ($o['clientName'] ?? '') ?: '—') . (!empty($o['clientPhone']) ? ' — ' . $o['clientPhone'] : '');
        $svc = (string) ($o['serviceLabel'] ?? ($o['service'] ?? ''));
        if ($svc !== '') { $lines[] = '🛠 العطل/الخدمة: ' . $svc; }
        if (!empty($o['note'])) { $lines[] = '📝 ' . mb_substr((string) $o['note'], 0, 200); }
        if (!empty($o['address'])) { $lines[] = '📍 ' . mb_substr((string) $o['address'], 0, 160); }
        if (is_numeric($o['lat'] ?? null)) { $lines[] = '🗺 https://maps.google.com/?q=' . $o['lat'] . ',' . $o['lng']; }
        if (!empty($o['appointmentAt'])) { $lines[] = '📅 الموعد: ' . wp_date('Y-m-d H:i', strtotime((string) $o['appointmentAt'])); }
        $price = $o['agreedPrice'] ?? ($o['plan']['agreedPrice'] ?? null);
        if (is_numeric($price)) { $lines[] = '💰 المتفق: ' . $price . ' ر.س'; }
        $lines[] = '';
        $lines[] = 'رد عليه من نفس خطه: *رد ' . $n . ' نص الرسالة*';
        $lines[] = 'ابعته لفني: *اعتمد ' . $n . '* — أو *اسند طلب ' . $n . ' لمحمود*';
        return implode("\n", $lines);
    }

    /** Called for every new order (any channel). Sends the master message and the n8n event, never blocks the order. */
    public static function order_ready($doc) {
        try {
            if (!is_array($doc) || empty($doc['id'])) { return; }
            $s = self::settings();
            self::emit('order_ready', ['order' => array_intersect_key($doc, array_flip(['id', 'number', 'brand', 'source', 'status', 'clientName', 'clientPhone', 'service', 'serviceLabel', 'address', 'lat', 'lng', 'appointmentAt', 'agreedPrice', 'card'])), 'text' => self::digest_text($doc)]);
            if (empty($s['digest']) || !$s['master']) { return; }
            self::to_master(self::digest_text($doc), 'order-' . $doc['id'], 'order', $doc);
        } catch (Throwable $e) { error_log('[mutqan] central order_ready ' . $e->getMessage()); }
    }

    const KINDS = [
        'order' => '🆕 طلب جديد', 'confirm' => '✅ تأكيد عميل', 'ops' => '🛠 عمليات', 'review' => '⚠️ راجع بنفسك', 'tech' => '👷 فني',
        'done' => '🏁 إنجاز', 'rating' => '⭐ تقييم', 'cancel' => '❌ إلغاء', 'winback' => '♻️ استرجاع', 'report' => '📊 تقرير', 'alert' => '🔔 تنبيه', 'system' => '🤖 النظام',
    ];

    /**
     * Every message to the team carries the same frame: what kind it is, which site/company it belongs to, when, and
     * the order number — so a number that carries orders, reports, sites and articles at once never mixes them up.
     */
    public static function frame($kind, $body, $o = null) {
        $label = self::KINDS[$kind] ?? self::KINDS['system'];
        $brand = is_array($o) ? (string) ($o['brand'] ?? ($o['card']['brand'] ?? '')) : '';
        $site = '';
        if (is_array($o)) {
            $card = (array) ($o['card'] ?? []);
            $sn = (string) ($card['site'] ?? '') ?: ($brand !== '' ? self::site($brand)['name'] : '');
            $dom = (string) ($card['domain'] ?? '') ?: ($brand !== '' ? self::site($brand)['domain'] : '');
            if ($sn !== '' && $sn !== 'غير محدد') { $site = '🌐 ' . $sn . ($dom !== '' ? ' · ' . $dom : ''); }
            if (!empty($card['source']['title'])) { $site .= "\n📰 المصدر: " . mb_substr((string) $card['source']['title'], 0, 80); }
            if (!empty($card['line']['label'])) { $site .= ($site !== '' ? "\n" : '') . '📞 الخط: ' . $card['line']['label']; }
        }
        $num = is_array($o) ? ((string) ($o['number'] ?? '') ?: substr((string) ($o['id'] ?? ''), -5)) : '';
        $when = wp_date('d/m H:i', time(), function_exists('wp_timezone') ? wp_timezone() : null);
        $L = ['━━━━━━━━━━━━━━', '*' . $label . '*' . ($num !== '' ? ' · #' . $num : '') . ' · 🕐 ' . $when];
        if ($site !== '') { $L[] = $site; }
        $L[] = '━━━━━━━━━━━━━━';
        $L[] = trim((string) $body);
        return implode("\n", $L);
    }

    /** One message to every master phone, from the reports line (never from a customers' line). */
    public static function to_master($text, $key = '', $kind = '', $o = null) {
        $s = self::settings(); $sent = 0;
        if ($kind === '') { $kind = strpos($key, 'order-') === 0 ? 'order' : (strpos($key, 'daily-') === 0 ? 'report' : 'system'); }
        $text = self::frame($kind, $text, $o);
        if (!$s['master']) { return 0; }
        $acct = Mutqan_WA_Fleet::account_for('report');
        if ($acct === '') { $acct = Mutqan_WA::open_account(); }
        if ($acct === '') { return 0; }
        foreach ($s['master'] as $m) {
            if ($key !== '' && get_transient('mq_master_' . md5($key . $m))) { continue; }
            try { Mutqan_WA::send($acct, $m, mb_substr((string) $text, 0, 3500), 'master', 'system'); $sent++; if ($key !== '') { set_transient('mq_master_' . md5($key . $m), 1, DAY_IN_SECONDS); } }
            catch (Throwable $e) { error_log('[mutqan] master send ' . $e->getMessage()); }
        }
        return $sent;
    }

    /**
     * The master's own commands. Returns the reply text, or null when the message is not one of them (the internal
     * engine then gets a turn — see Mutqan_WA_Desk::management).
     */
    public static function master_command($text, $p) {
        $t = trim((string) $text);
        $n = class_exists('Mutqan_Quick') ? Mutqan_Quick::norm($t) : $t;
        // رد 1234 <text> — answer the customer from the line he wrote to
        if (preg_match('/^(رد|ابعت ل|ارسل ل)\s*#?(\d{1,10})\s+(.+)$/su', $t, $m) || preg_match('/^(رد|ابعت ل|ارسل ل)\s*#?(\d{1,10})\s+(.+)$/su', Mutqan_Auth::fold_digits($t), $m)) {
            $o = Mutqan_Quick::find_order($m[2]);
            if (!$o) { return 'مفيش طلب رقم ' . $m[2] . '.'; }
            return self::reply_customer($o, trim($m[3]), $p);
        }
        // الملغي — today's cancellations and their reasons; رجع 1234 — bring a cancelled one back
        if (class_exists('Mutqan_WA_Journey') && preg_match('/^(الملغي|الملغيه|الملغية|الطلبات الملغيه|الطلبات الملغية|الالغاءات)$/u', $n)) { return Mutqan_WA_Journey::cancelled_today(); }
        if (class_exists('Mutqan_WA_Journey') && preg_match('/^(رجع|رجّع|ارجع|استرجع)\s*(الطلب)?\s*#?(\d{1,10})$/u', Mutqan_Auth::fold_digits($n), $m)) {
            $o = Mutqan_Quick::find_order($m[3]);
            if (!$o) { return 'مفيش طلب رقم ' . $m[3] . '.'; }
            if (($o['status'] ?? '') !== 'cancelled') { return 'الطلب #' . $m[3] . ' مش ملغي (' . ($o['status'] ?? '') . ').'; }
            return Mutqan_WA_Journey::owner_reopen($o);
        }
        // اعتمد 1234 — send to the best technician (over the technicians' line)
        if (preg_match('/^(اعتمد|وزع|اعتماد)\s*(الطلب)?\s*#?(\d{1,10})$/u', $n, $m)) {
            $o = Mutqan_Quick::find_order($m[3]);
            if (!$o) { return 'مفيش طلب رقم ' . $m[3] . '.'; }
            if (!in_array($o['status'] ?? '', ['new', 'forced'], true)) { return 'الطلب #' . $m[3] . ' اتسند خلاص (' . ($o['status'] ?? '') . ').'; }
            $after = Mutqan_Dispatch::dispatch($o);
            $tid = (string) ($after['technicianId'] ?? '');
            if ($tid === '') { return 'مفيش فني مناسب متاح دلوقتي للطلب #' . $m[3] . ' — اتحط في المتاح لأول فني يقبله.'; }
            $t2 = Mutqan_Store::get_raw('tech_profiles', $tid) ?: [];
            return '✅ الطلب #' . $m[3] . ' اتسند لـ ' . ($t2['displayName'] ?? 'فني') . ' واتبعتله التفاصيل واللوكيشن.';
        }
        return null;
    }

    /** Text to a customer from the line he used (or a customers' line of his site), never from a private number. */
    public static function reply_customer($o, $text, $p) {
        $phone = self::intl($o['clientPhone'] ?? '');
        if ($phone === '') { return 'الطلب ده من غير رقم عميل.'; }
        $acct = (string) ($o['waAccount'] ?? ($o['card']['line']['id'] ?? ''));
        $open = array_column(array_filter(Mutqan_WA_Fleet::numbers(), function ($x) { return $x['status'] === 'open'; }), 'id');
        if ($acct === '' || !in_array($acct, $open, true)) {
            $brand = (string) ($o['brand'] ?? '');
            foreach (self::lines() as $l) { if ($l['status'] === 'open' && $l['role'] === 'customers' && $l['brand'] === $brand) { $acct = $l['id']; break; } }
            if ($acct === '' || !in_array($acct, $open, true)) { $acct = Mutqan_WA_Fleet::account_for('customer'); }
        }
        if ($acct === '') { return 'مفيش خط عملاء متصل دلوقتي — الرسالة ما اتبعتتش.'; }
        Mutqan_WA::send($acct, $phone, mb_substr($text, 0, 3000), 'master', (string) ($p['name'] ?? 'الإدارة'));
        $tid = Mutqan_WA::tid($acct, $phone);
        $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
        $bot = (array) ($th['bot'] ?? []); $bot['pausedUntil'] = time() + 12 * 3600;      // a human answered: the bot steps back
        Mutqan_WA::put_thread($tid, ['bot' => $bot]);
        return '📤 اتبعت للعميل ' . ((string) ($o['clientName'] ?? '') ?: '+' . $phone) . ' من خط «' . $acct . '».';
    }

    /* ------------------------------------------------------------------ quiet hours for supervisors */

    const NIGHT = 'mutqan_night_digest';

    /**
     * Not urgent, not for now: sales-brain plans, network/article reports, messages the bot is already answering. They
     * wait for the night report instead of ringing a supervisor's phone. Same key twice = one line (the latest).
     */
    public static function defer($title, $body, $key = '', $cat = 'عام') {
        $q = get_option(self::NIGHT, []); $q = is_array($q) ? $q : [];
        $k = $key !== '' ? $key : md5($title . $body);
        $q[$k] = ['t' => mb_substr((string) $title, 0, 120), 'b' => mb_substr((string) $body, 0, 400), 'c' => (string) $cat, 'at' => time(), 'n' => (int) ($q[$k]['n'] ?? 0) + 1];
        if (count($q) > 200) { $q = array_slice($q, -200, null, true); }
        update_option(self::NIGHT, $q, false);
        return true;
    }

    /** The waiting lines, grouped by kind, for the night report; emptied once sent. */
    public static function night_text($clear = false) {
        $q = get_option(self::NIGHT, []); $q = is_array($q) ? $q : [];
        if (!$q) { return ''; }
        $by = [];
        foreach ($q as $x) { $by[$x['c']][] = '• ' . $x['t'] . ($x['n'] > 1 ? ' (×' . $x['n'] . ')' : '') . ($x['b'] !== '' ? ': ' . mb_substr($x['b'], 0, 160) : ''); }
        $out = ["🗂 *اتأجل لتقرير الليلة (" . count($q) . ")*"];
        foreach ($by as $c => $lines) { $out[] = "\n*" . $c . "*\n" . implode("\n", array_slice($lines, 0, 15)) . (count($lines) > 15 ? "\n… و" . (count($lines) - 15) . ' كمان' : ''); }
        if ($clear) { update_option(self::NIGHT, [], false); }
        return implode("\n", $out);
    }

    /* ------------------------------------------------------------------ daily report to the master */

    public static function daily() {
        $s = self::settings();
        $today = wp_date('Y-m-d');
        if ((int) wp_date('G') < (int) $s['dailyHour'] || $s['lastDaily'] === $today) { return false; }
        $s['lastDaily'] = $today; update_option(self::OPT, $s, false);
        $text = "📊 *تقرير اليوم* " . $today . "\n" . Mutqan_Quick::say('ops_overview', Mutqan_Ops::run_tool('ops_overview', [])) . "\n\n" . Mutqan_Quick::say('money_report', Mutqan_Ops::run_tool('money_report', ['days' => 1]));
        $night = self::night_text(true);
        if ($night !== '') { $text .= "\n\n" . $night; }
        // One quiet line in the app too, instead of the day's scattered alerts.
        try { Mutqan_Store::insert('_push', ['target' => ['admins' => true], 'title' => '🌙 تقرير الليلة جاهز', 'body' => 'ملخص اليوم واللي اتأجل من التنبيهات', 'url' => '#/staff/notifications', 'priority' => 'normal', 'event' => 'night_report', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 86400)], self::sys()); } catch (Throwable $e) { /* never block */ }
        if (!empty($s['events']['daily'])) { self::emit('daily', ['text' => $text, 'date' => $today]); }
        return self::to_master($text, 'daily-' . $today);
    }

    /* ------------------------------------------------------------------ 5. n8n */

    public static function sign($body, $secret) { return hash_hmac('sha256', (string) $body, (string) $secret); }

    /** Post an event to n8n, signed, without waiting. */
    public static function emit($event, $data) {
        $s = self::settings();
        if ($s['n8nUrl'] === '' || empty($s['events'][$event] ?? 1)) { return false; }
        $body = wp_json_encode(['event' => $event, 'at' => gmdate('c'), 'site' => home_url('/'), 'data' => $data], JSON_UNESCAPED_UNICODE);
        wp_remote_post($s['n8nUrl'], ['timeout' => 3, 'blocking' => false, 'headers' => ['Content-Type' => 'application/json', 'X-Mutqan-Event' => $event, 'X-Mutqan-Signature' => self::sign($body, $s['n8nSecret'])], 'body' => $body]);
        return true;
    }

    /** n8n → here. Signed with the same secret (header X-Mutqan-Signature = HMAC-SHA256 of the raw body). */
    public static function inbound($raw, $sig) {
        $s = self::settings();
        if (!is_string($sig) || $sig === '' || !hash_equals(self::sign($raw, $s['n8nSecret']), $sig)) { throw new Mutqan_Error('forbidden', 'توقيع غير صحيح.', 403); }
        Mutqan_Limiter::hit('n8n_in', 600, 3600);
        $in = json_decode($raw, true); $in = is_array($in) ? $in : [];
        switch ((string) ($in['op'] ?? '')) {
            case 'master': return ['sent' => self::to_master(mb_substr((string) ($in['text'] ?? ''), 0, 3500))];
            case 'send': {
                $to = self::intl($in['to'] ?? ''); $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 3000);
                if (strlen($to) < 9 || $text === '') { throw new Mutqan_Error('bad_input', 'to و text مطلوبين.', 400); }
                $acct = (string) ($in['line'] ?? '') ?: Mutqan_WA_Fleet::account_for((string) ($in['by'] ?? 'customer'));
                if ($acct === '') { throw new Mutqan_Error('no_line', 'مفيش خط متصل للدور ده.', 409); }
                return Mutqan_WA::send($acct, $to, $text, 'n8n', 'n8n');
            }
            case 'reply': {
                $o = Mutqan_Quick::find_order((string) ($in['order'] ?? ''));
                if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
                return ['text' => self::reply_customer($o, (string) ($in['text'] ?? ''), ['name' => 'n8n'])];
            }
            case 'order': { $o = Mutqan_Quick::find_order((string) ($in['order'] ?? '')); if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); } return $o; }
            case 'command': { $q = Mutqan_Quick::try_run((string) ($in['text'] ?? ''), ['id' => 'n8n', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'n8n'], 'agent'); return $q ?: ['text' => '', 'unknown' => true]; }
            case 'lines': return ['lines' => self::lines()];
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    /* ------------------------------------------------------------------ the websites' WhatsApp links */

    /** On every site: tag WhatsApp links with the site's code, so the first message says where the customer came from. */
    public static function link_tagger() {
        if (is_admin() || !class_exists('Oxygen_Network')) { return; }
        $id = (string) Oxygen_Network::current_id();
        if ($id === '' || !preg_match('/^[a-z][a-z0-9]{1,23}$/', $id)) { return; }
        $b = Oxygen_Network::brand($id) ?: [];
        $tag = '#' . $id;
        $hello = 'مرحبا ' . (string) ($b['name'] ?? '') . ' 👋';
        // The page or article the customer is reading travels with the first message, so the line knows the source.
        if (function_exists('is_singular') && is_singular()) {
            $pid = (int) get_queried_object_id();
            $title = trim(wp_strip_all_tags((string) get_the_title($pid)));
            if ($pid > 0) { $tag .= '/' . (is_singular('post') ? 'a' : 'p') . $pid; }
            if ($title !== '') { $hello .= ' بخصوص: ' . mb_substr($title, 0, 60); }
        }
        echo '<script>(function(){var T=' . wp_json_encode($tag) . ',H=' . wp_json_encode($hello) . ';function f(a){try{var u=new URL(a.href);if(!/(^|\.)wa\.me$|api\.whatsapp\.com$/.test(u.hostname))return;var t=u.searchParams.get("text")||"";if(t.indexOf(T)>-1)return;u.searchParams.set("text",(t||H)+" "+T);a.href=u.toString()}catch(e){}}function run(){var l=document.querySelectorAll(\'a[href*="wa.me"],a[href*="api.whatsapp.com"]\');for(var i=0;i<l.length;i++)f(l[i])}run();document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a");if(a)f(a)},true)})();</script>';
    }
}
