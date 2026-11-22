<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Free-tier AI gateway.
 *
 * Tries the configured providers in order (Groq, Gemini, Cloudflare Workers AI, OpenRouter). A provider that
 * answers 429 / quota errors is put in a short "cooldown" and the next one is used, so one provider's daily
 * free limit never stops the chat while another still has room. Keys live only in a WordPress option on the
 * server; the browser never sees them. Phone numbers and e-mails are masked before text leaves the server,
 * because free tiers may let humans review prompts.
 */
final class Mutqan_AI {

    const HOUSE_FEATURES = ['chat', 'site_chat', 'wa', 'wa_hub', 'wa_reception', 'negotiate', 'sales'];

    /** The official price sheet, from the owner's own tables (the same numbers the order page and the hub use). */
    public static function price_sheet() {
        $L = [];
        if (class_exists('Mutqan_Policy')) {
            $c = Mutqan_Policy::ac_table(); $f = function ($v) { return (string) (int) round((float) $v); };
            $L[] = '- غسيل مكيف سبليت (داخلي وخارجي): 1 = ' . $f($c['split1']) . '، 2 = ' . $f($c['split2']) . '، 3 = ' . $f($c['split3']) . ' ر.س، وما زاد بثلث سعر الثلاثة لكل وحدة. داخلي فقط أرخص بـ ' . $f($c['innerOff1']) . '/' . $f($c['innerOff2']) . '/' . $f($c['innerOff3']) . '.';
            $L[] = '- غسيل مكيف شباك: 1 = ' . $f($c['win1']) . '، 2 = ' . $f($c['win2']) . '، 3 = ' . $f($c['win3']) . ' ر.س (+' . $f($c['winExtra']) . ' لكل وحدة بعدها). باقي الأنواع (مخفي، كاسيت، دولابي، مركزي): ' . $f($c['other1']) . ' للوحدة.';
            $L[] = '- ضمان 15 يوم مجاني. ضريبة القيمة المضافة ' . $f($c['vatPct']) . '% لو طلب فاتورة ضريبية. أقصى خصم مسموح ' . $f($c['maxDiscountPct']) . '%.';
            $labels = class_exists('Mutqan_Rest') ? Mutqan_Rest::SPEC_LABELS : [];
            $other = [];
            foreach (Mutqan_Policy::price_bases() as $id => $b) {
                if ($id === 'ac') { continue; }
                $row = class_exists('Mutqan_Store') ? (Mutqan_Store::get_raw('pricing', $id) ?: []) : [];
                $base = isset($row['base']) && is_numeric($row['base']) ? (int) $row['base'] : (int) $b;
                $other[] = ($labels[$id] ?? $id) . ' من ' . $base;
            }
            $L[] = '- باقي الخدمات (زيارة/أول وحدة، والنهائي بعد المعاينة): ' . implode('، ', $other) . ' ر.س.';
            $L[] = '- التركيب والإصلاح: يتسعّر بعد الفحص؛ الكشف مجاني لحد مكيفين.';
        }
        if (class_exists('Mutqan_Hub')) { $L[] = Mutqan_Hub::parts_for_prompt(); }
        return implode("\n", $L);
    }

    /** Appointment rules for the hour it is now (Mutqan_Deal::booking). */
    public static function booking_rules() {
        if (!class_exists('Mutqan_Deal')) { return '- الافتراضي «اليوم — أقرب وقت ممكن».'; }
        $b = Mutqan_Deal::booking();
        return $b['rule'] . "\n- لو العميل ما حددش وقت سجّل في الطلب: «" . $b['defaultTime'] . "».";
    }

    /** The house policy every customer conversation follows (the site's assistant and WhatsApp alike). */
    public static function house_rules() {
        $now = class_exists('Mutqan_Sales') ? Mutqan_Sales::local()->format('Y-m-d H:i') : gmdate('Y-m-d H:i');
        return "## سياسة الشركة الملزمة (تتقدم على أي تعليمات قبلها)\n"
            . "الوقت الآن في مكة: {$now}.\n"
            . "### الأسعار الرسمية — المصدر الوحيد لأي رقم\n" . self::price_sheet() . "\n"
            . "ممنوع أي رقم من عندك، وممنوع خصم أكبر من المسموح. لو الخدمة مش في الجدول قل إن سعرها بعد المعاينة.\n"
            . "### المواعيد\n" . self::booking_rules() . "\n"
            . (class_exists('Mutqan_Kit') ? Mutqan_Kit::prompt() . "\n" : '')
            . "### الأسلوب\n"
            . "- راجل سعودي في خدمة العملاء، بلهجة سعودية بيضاء ثابتة، ودود ومختصر (جملتين بالكتير)، وسؤال واحد بس في كل رد.\n"
            . "- اقرأ المحادثة كلها قبل ما ترد، وما تسألش عن حاجة العميل قالها قبل كده، وما تسألش أسئلة مالهاش لازمة للطلب.\n"
            . "- الخطوات: الخدمة ← النوع والعدد ← السعر من الجدول ← الموعد (حسب قواعد المواعيد فوق) ← تأكيد وقفل الطلب (الموقع بيتحدد من الجهاز/الواتساب). أول ما تعرف الخدمة والعدد وسعرها: اقفل الطلب وسجّله على طول من غير لف.\n"
            . "- الفصال: انزل بالتدريج وكل تنازل مقابل حاجة (يحجز دلوقتي، عدد أكبر). العدد الكبير (4 مكيفات وطالع) ليه سعر باقة حسب تعليمات العقل التجاري. ما تذكرش أبدًا إن فيه حد أدنى ولا رقمه.\n"
            . "- لو العميل فاصل لحد آخر سعر ممكن وما وافقش: اعتذر بلطف واعرض القيمة (ضمان، فاتورة، فني معتمد) أو تقليل العدد، وما تقفلش الباب.\n"
            . "- لو سأل «طلبي فين/الفني فين»: جاوب من بيانات طلباته المرفقة بس، وما تخترعش حالة ولا وقت وصول. لو عايز يلغي أو يأجل: النظام بيتصرف وبيبلغ العمليات — قوله إنه اتبلغ."
            . (class_exists('Mutqan_Command') && ($r = Mutqan_Command::intake_rule()) !== '' ? "\n" . $r : '');
    }
    const OPT = 'mutqan_ai_settings';
    const MAX_PROMPT_CHARS = 30000;

    public static function defaults() {
        return [
            'order' => ['groq', 'cerebras', 'github', 'gemini', 'mistral', 'sambanova', 'cloudflare', 'nvidia', 'together', 'cohere', 'openrouter',
                'deepseek', 'zhipu', 'huggingface', 'deepinfra', 'fireworks', 'hyperbolic', 'novita', 'qwen', 'moonshot', 'xai', 'openai', 'custom', 'custom2'],
            'providers' => [
                'groq'       => ['enabled' => 0, 'key' => '', 'model' => 'openai/gpt-oss-120b'],
                'gemini'     => ['enabled' => 0, 'key' => '', 'model' => 'gemini-3.1-flash-lite'],
                'cloudflare' => ['enabled' => 0, 'key' => '', 'account' => '', 'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
                'openrouter' => ['enabled' => 0, 'key' => '', 'model' => 'meta-llama/llama-3.3-70b-instruct:free'],
                // All free tiers, all speaking the OpenAI chat shape, so each one costs a line here and
                // nothing else. The more of them that are set up, the longer the assistant keeps talking
                // on a day somebody's daily limit runs out: the chain moves on and the customer never
                // learns there was a limit. 'github' is a GitHub token, not a paid key.
                'cerebras'   => ['enabled' => 0, 'key' => '', 'model' => 'llama-3.3-70b'],
                'github'     => ['enabled' => 0, 'key' => '', 'model' => 'openai/gpt-4.1-mini'],
                'mistral'    => ['enabled' => 0, 'key' => '', 'model' => 'mistral-small-latest'],
                'nvidia'     => ['enabled' => 0, 'key' => '', 'model' => 'meta/llama-3.3-70b-instruct'],
                'together'   => ['enabled' => 0, 'key' => '', 'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo-Free'],
                'cohere'     => ['enabled' => 0, 'key' => '', 'model' => 'command-r-08-2024'],
                // More doors to the same OpenAI chat shape. Free tiers first (SambaNova, Zhipu GLM-Flash, Hugging Face
                // router), then low-cost pay-as-you-go ones that keep the chat alive on a day every free quota is spent.
                // 'custom' / 'custom2' take any OpenAI-compatible URL the owner pastes (a self-hosted model, a reseller…).
                'sambanova'  => ['enabled' => 0, 'key' => '', 'model' => 'Meta-Llama-3.3-70B-Instruct'],
                'deepseek'   => ['enabled' => 0, 'key' => '', 'model' => 'deepseek-chat'],
                'zhipu'      => ['enabled' => 0, 'key' => '', 'model' => 'glm-4-flash'],
                'huggingface' => ['enabled' => 0, 'key' => '', 'model' => 'meta-llama/Llama-3.3-70B-Instruct'],
                'deepinfra'  => ['enabled' => 0, 'key' => '', 'model' => 'meta-llama/Llama-3.3-70B-Instruct'],
                'fireworks'  => ['enabled' => 0, 'key' => '', 'model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct'],
                'hyperbolic' => ['enabled' => 0, 'key' => '', 'model' => 'meta-llama/Llama-3.3-70B-Instruct'],
                'novita'     => ['enabled' => 0, 'key' => '', 'model' => 'meta-llama/llama-3.3-70b-instruct'],
                'qwen'       => ['enabled' => 0, 'key' => '', 'model' => 'qwen-plus'],
                'moonshot'   => ['enabled' => 0, 'key' => '', 'model' => 'moonshot-v1-8k'],
                'xai'        => ['enabled' => 0, 'key' => '', 'model' => 'grok-3-mini'],
                'openai'     => ['enabled' => 0, 'key' => '', 'model' => 'gpt-4.1-mini'],
                'custom'     => ['enabled' => 0, 'key' => '', 'model' => '', 'url' => ''],
                'custom2'    => ['enabled' => 0, 'key' => '', 'model' => '', 'url' => ''],
                // Paid, used only for the higher tiers (see tier_model()); the free providers above keep serving the 'low' tier.
                'anthropic'  => ['enabled' => 0, 'key' => '', 'model' => 'claude-haiku-5-5'],
            ],
            // Workload tiers (owner's policy): low = customer chat on the free providers; mid = customer service, orders,
            // transfers; routine = daily articles, tracking, routine jobs; high = owner/operations commands, edits, design.
            'tiers' => ['mid' => 'claude-haiku-5-5', 'routine' => 'claude-sonnet-5-5', 'high' => 'claude-opus-5-5'],
            // Scenario routing: a feature can ask for a stronger tier than the caller passed (still capped by role in
            // allowed_tier). Empty by default = everything stays on the free chain unless the owner opts in, e.g.
            // ['negotiate' => 'mid'] sends price talks to Claude Haiku when it is configured.
            'feature_tiers' => [],
            'per_ip_10min' => 30,
            'per_ip_day' => 250,
            'mask_pii' => 1,
            'status' => [],
        ];
    }

    public static function settings() {
        $s = (array) get_option(self::OPT, []);
        $d = self::defaults();
        $out = array_merge($d, $s);
        foreach ($d['providers'] as $id => $def) {
            $out['providers'][$id] = array_merge($def, (array) ($s['providers'][$id] ?? []));
        }
        // An order saved before a provider existed must not hide it: anything missing goes to the end of the chain.
        $out['order'] = array_values(array_intersect((array) $out['order'], array_keys($d['providers'])));
        foreach (array_keys($d['providers']) as $id) { if ($id !== 'anthropic' && !in_array($id, $out['order'], true)) { $out['order'][] = $id; } }
        $out['feature_tiers'] = array_filter((array) ($out['feature_tiers'] ?? []), function ($t) { return in_array($t, self::TIERS, true); });
        return $out;
    }

    public static function labels() {
        return ['groq' => 'Groq', 'cerebras' => 'Cerebras', 'github' => 'GitHub Models', 'gemini' => 'Google Gemini',
            'mistral' => 'Mistral AI', 'cloudflare' => 'Cloudflare Workers AI', 'nvidia' => 'NVIDIA NIM',
            'together' => 'Together AI', 'cohere' => 'Cohere', 'openrouter' => 'OpenRouter', 'sambanova' => 'SambaNova', 'deepseek' => 'DeepSeek',
            'zhipu' => 'Zhipu GLM', 'huggingface' => 'Hugging Face', 'deepinfra' => 'DeepInfra', 'fireworks' => 'Fireworks AI', 'hyperbolic' => 'Hyperbolic',
            'novita' => 'Novita AI', 'qwen' => 'Qwen (Alibaba)', 'moonshot' => 'Moonshot Kimi', 'xai' => 'xAI Grok', 'openai' => 'OpenAI',
            'custom' => 'مزود مخصص 1 (OpenAI-compatible)', 'custom2' => 'مزود مخصص 2 (OpenAI-compatible)', 'anthropic' => 'Claude (Anthropic)'];
    }

    /** Endpoints of every provider that speaks the OpenAI chat shape (one line each — adding a door costs nothing). */
    public static function endpoints() {
        return [
            'groq' => 'https://api.groq.com/openai/v1/chat/completions', 'cerebras' => 'https://api.cerebras.ai/v1/chat/completions',
            'github' => 'https://models.github.ai/inference/chat/completions', 'mistral' => 'https://api.mistral.ai/v1/chat/completions',
            'nvidia' => 'https://integrate.api.nvidia.com/v1/chat/completions', 'together' => 'https://api.together.xyz/v1/chat/completions',
            'cohere' => 'https://api.cohere.ai/compatibility/v1/chat/completions', 'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'sambanova' => 'https://api.sambanova.ai/v1/chat/completions', 'deepseek' => 'https://api.deepseek.com/chat/completions',
            'zhipu' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions', 'huggingface' => 'https://router.huggingface.co/v1/chat/completions',
            'deepinfra' => 'https://api.deepinfra.com/v1/openai/chat/completions', 'fireworks' => 'https://api.fireworks.ai/inference/v1/chat/completions',
            'hyperbolic' => 'https://api.hyperbolic.xyz/v1/chat/completions', 'novita' => 'https://api.novita.ai/v3/openai/chat/completions',
            'qwen' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions', 'moonshot' => 'https://api.moonshot.ai/v1/chat/completions',
            'xai' => 'https://api.x.ai/v1/chat/completions', 'openai' => 'https://api.openai.com/v1/chat/completions',
        ];
    }

    /** A custom endpoint must be https and a public host name — never an address inside the server's own network. */
    public static function custom_url_ok($url) {
        $u = wp_parse_url((string) $url);
        if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) { return false; }
        $h = strtolower($u['host']);
        if (filter_var($h, FILTER_VALIDATE_IP) || $h === 'localhost' || substr($h, -6) === '.local' || strpos($h, '.') === false) { return false; }
        return true;
    }

    public static function any_enabled($s = null) {
        $s = $s ?: self::settings();
        foreach ($s['providers'] as $id => $c) { if (self::usable($id, $c)) { return true; } }
        return false;
    }

    private static function usable($id, $c) {
        if (empty($c['enabled']) || $c['key'] === '') { return false; }
        if ($id === 'custom' || $id === 'custom2') { return ($c['model'] ?? '') !== '' && self::custom_url_ok($c['url'] ?? ''); }
        return $id !== 'cloudflare' || $c['account'] !== '';
    }

    /* ------------------------------------------------------------------ workload tiers */

    const TIERS = ['low', 'mid', 'routine', 'high'];

    /** The tier a caller may use: customers at most 'mid', staff at most 'routine', owner/operations 'high'. */
    public static function allowed_tier($want, $p) {
        $want = in_array($want, self::TIERS, true) ? $want : 'low';
        $role = $p['role'] ?? null;
        $cap = $role === 'owner' || $role === 'operations' ? 'high' : (Mutqan_Policy::is_staff($p) ? 'routine' : 'mid');
        return array_search($want, self::TIERS, true) <= array_search($cap, self::TIERS, true) ? $want : $cap;
    }

    /** Claude model for a tier, or '' when the tier runs on the free providers / Claude is not configured. */
    public static function tier_model($tier, $s = null) {
        $s = $s ?: self::settings();
        $c = $s['providers']['anthropic'] ?? null;
        if ($tier === 'low' || !$c || !self::usable('anthropic', $c)) { return ''; }
        $t = array_merge(self::defaults()['tiers'], (array) ($s['tiers'] ?? []));
        return $tier === 'mid' ? ($c['model'] ?: $t['mid']) : (string) ($t[$tier] ?? '');
    }

    public static function anthropic_key() { $s = self::settings(); $c = $s['providers']['anthropic']; return self::usable('anthropic', $c) ? $c['key'] : ''; }

    /* ------------------------------------------------------------------ public entry */

    public static function mask($text) {
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[بريد]', $text);
        $text = preg_replace('/(?<![\d.])\+?\d[\d\s().-]{6,}\d(?![\d.])/u', '[رقم]', Mutqan_Auth::fold_digits($text));
        return $text;
    }

    /** @return array ['text' => string, 'json' => ?array, 'provider' => string] @throws Mutqan_Error */
    /** Output size and patience for one call. Normal answers stay short and fast (900 tokens, seconds); a long job (an
     *  article rewrite of 700+ words) asks for room with opts max_tokens + long — the old fixed 900 tokens cut every
     *  rewrite in half, so the uniqueness gate held every new article as a draft (2026-11-21). */
    private static $out = 0;
    private static $long = false;
    private static function mt($def) { return self::$out > 0 ? self::$out : $def; }

    public static function run($prompt, $opts, $p) {
        $prev = [self::$out, self::$long];
        self::$out = isset($opts['max_tokens']) ? max(256, min(8000, (int) $opts['max_tokens'])) : 0;
        self::$long = !empty($opts['long']);
        try { return self::run_inner($prompt, $opts, $p); }
        finally { list(self::$out, self::$long) = $prev; }
    }

    private static function run_inner($prompt, $opts, $p) {
        $prompt = (string) $prompt;
        if ($prompt === '') { throw new Mutqan_Error('empty_prompt', 'الرسالة فارغة.', 400); }
        if (mb_strlen($prompt) > self::MAX_PROMPT_CHARS) { throw new Mutqan_Error('prompt_too_large', 'الطلب كبير جدًا.', 413); }
        $s = self::settings();
        if (!self::any_enabled($s)) { throw new Mutqan_Error('ai_unavailable', 'خدمة الذكاء الاصطناعي غير مفعّلة.', 503); }

        if (!Mutqan_Policy::is_staff($p)) {
            Mutqan_Limiter::hit('ai10', (int) $s['per_ip_10min'], 600);
            Mutqan_Limiter::hit('aiday', (int) $s['per_ip_day'], 86400);
        }
        if (!empty($s['mask_pii'])) { $prompt = self::mask($prompt); }
        // The owner's facts go in after the mask, never before: masking would strike out the company's
        // own number in its own answer. They go in here, at the one door every feature passes through,
        // so a fact he writes once reaches the chat, the WhatsApp bot and the agent together.
        if (class_exists('Mutqan_KB')) { $prompt = Mutqan_KB::inject($prompt, $opts, $p); }
        // The sales brain goes in at the same door: who the customer is, what the day looks like, the plan for
        // this reply. Only customer conversations get one (see Mutqan_Sales::inject).
        if (class_exists('Mutqan_Sales')) { $prompt = Mutqan_Sales::inject($prompt, $opts, $p); }
        // The service encyclopedia: what a seasoned agent knows about the customer's own last message (Mutqan_Lore).
        if (class_exists('Mutqan_Lore')) { $prompt = Mutqan_Lore::inject($prompt, $opts, $p); }
        // One customer brain for WhatsApp and the site: his orders, the official price of what he asks, the live offers.
        if (class_exists('Mutqan_Concierge')) { $prompt = Mutqan_Concierge::inject($prompt, $opts, $p); }
        // One house policy for every customer conversation — the site's assistant and the WhatsApp lines alike: the
        // same official prices, the same «today, as soon as possible», the same way of closing an order. Last, so it wins.
        if (in_array((string) ($opts['feature'] ?? ''), self::HOUSE_FEATURES, true)) { $prompt .= "\n\n" . self::house_rules(); }
        $wantJson = !empty($opts['json']);
        $temp = isset($opts['temperature']) && is_numeric($opts['temperature']) ? max(0, min(1.2, (float) $opts['temperature'])) : 0.4;

        // Who the answer is for (owner, 2026-11-20): customers ride the free providers and only then the cheapest Claude;
        // technicians the next one up; the owner and operations get the strong model first, for thinking and planning.
        // Customer features count as «customer» even when the hub calls in as the system account.
        $aud = self::audience($opts, $p);
        $want = $opts['tier'] ?? ($aud === 'manager' ? 'high' : 'low');
        $ft = (array) ($s['feature_tiers'] ?? []);
        $f = (string) ($opts['feature'] ?? '');
        if ($f !== '' && isset($ft[$f]) && array_search($ft[$f], self::TIERS, true) > array_search(in_array($want, self::TIERS, true) ? $want : 'low', self::TIERS, true)) { $want = $ft[$f]; }
        $tier = self::allowed_tier($want, $p);
        $model = self::tier_model($tier, $s);
        if ($model !== '' && class_exists('Mutqan_Points') && !Mutqan_Points::available()) { $model = ''; }   // wallet empty: free chain
        if ($model !== '' && !self::cooling('anthropic')) {
            self::$cap = (int) max(6, min(self::$long ? 90 : 25, (float) ($opts['budget'] ?? 20)));
            $r = self::call_anthropic(array_merge($s['providers']['anthropic'], ['model' => $model]), $prompt, $wantJson, $temp);
            self::$cap = 0;
            if ($r['ok']) {
                $text = trim($r['text']); $json = $wantJson ? self::extract_json($text) : null;
                if (!$wantJson || $json !== null) { self::note('anthropic', 'ok'); $out = ['text' => $text, 'json' => $json, 'provider' => 'anthropic', 'tier' => $tier]; self::teach($prompt, $out, $opts, $p); return $out; }
            } else {
                self::note('anthropic', $r['error']);
                if ($r['cooldown']) { self::set_cooldown('anthropic', $r['cooldown']); }
            }
        }

        $tried = 0; $lastText = ''; $lastProvider = '';
        // The team gets every door; a customer waits for five at most.
        $max = Mutqan_Policy::is_staff($p) ? 12 : 5;
        $chain = [];
        foreach ($s['order'] as $id) { if ($id !== 'anthropic' && isset($s['providers'][$id]) && self::usable($id, $s['providers'][$id])) { $chain[] = $id; } }
        $fresh = array_values(array_filter($chain, function ($id) { return !self::cooling($id); }));
        // Every provider cooling down is the moment the old code said «busy» without asking anybody. A cooldown is a
        // guess about a quota, not a fact: when nobody is left, the two oldest-cooled doors are tried once more anyway.
        if (!$fresh) { $fresh = array_slice($chain, 0, 2); }
        // One answer has a budget in seconds, whatever the chain: a WhatsApp reply that waits a minute is a bot that hangs.
        $deadline = microtime(true) + max(6, min(self::$long ? 180 : 40, (float) ($opts['budget'] ?? 20)));
        foreach ($fresh as $id) {
            $c = $s['providers'][$id];
            if ($tried >= $max) { break; }
            $left = $deadline - microtime(true);
            if ($tried > 0 && $left < 4) { break; }
            self::$cap = (int) max(4, min(self::$long ? 90 : 14, floor($left)));
            $tried++;
            $r = self::call($id, $c, $prompt, $wantJson, $temp);
            self::$cap = 0;
            if ($r['ok']) {
                delete_transient('mq_cool_' . $id);
                $text = trim($r['text']);
                $json = $wantJson ? self::extract_json($text) : null;
                if ($wantJson && $json === null) { $lastText = $text; $lastProvider = $id; self::note($id, 'JSON غير صالح'); continue; }
                self::note($id, 'ok');
                $out = ['text' => $text, 'json' => $json, 'provider' => $id];
                self::teach($prompt, $out, $opts, $p);
                return $out;
            }
            self::note($id, $r['error']);
            if ($r['cooldown']) { self::set_cooldown($id, $r['cooldown']); }
        }
        // Every free door is shut: the paid fallback for this audience (customers → the cheapest Claude, technicians → the
        // next, owner/operations → the strong one), once, still under the wallet and the cooldown.
        $fb = self::fallback_model($aud, $s);
        if ($fb !== '' && $fb !== $model && !self::cooling('anthropic') && (!class_exists('Mutqan_Points') || Mutqan_Points::available())) {
            self::$cap = (int) max(6, min(self::$long ? 90 : 20, $deadline - microtime(true) + 8));
            $r = self::call_anthropic(array_merge($s['providers']['anthropic'], ['model' => $fb]), $prompt, $wantJson, $temp);
            self::$cap = 0;
            if ($r['ok']) {
                $text = trim($r['text']); $json = $wantJson ? self::extract_json($text) : null;
                if (!$wantJson || $json !== null) { self::note('anthropic', 'ok'); $out = ['text' => $text, 'json' => $json, 'provider' => 'anthropic', 'tier' => 'fallback-' . $aud]; self::teach($prompt, $out, $opts, $p); return $out; }
            } else {
                self::note('anthropic', $r['error']);
                if ($r['cooldown']) { self::set_cooldown('anthropic', $r['cooldown']); }
            }
        }
        if ($lastText !== '') { return ['text' => $lastText, 'json' => null, 'provider' => $lastProvider]; }
        throw new Mutqan_Error('ai_unavailable', 'خدمة الذكاء الاصطناعي مشغولة الآن.', 503);
    }

    /** customer | technician | system | manager — whom this answer serves, for choosing the paid model. */
    public static function audience($opts, $p) {
        $f = (string) ($opts['feature'] ?? '');
        if (in_array($f, self::HOUSE_FEATURES, true) || !Mutqan_Policy::is_staff($p)) { return 'customer'; }
        // Background jobs run as the system account: articles, reports, routine work — the middle model, never the top one.
        if ((string) ($p['id'] ?? '') === 'system') { return 'system'; }
        $role = (string) ($p['role'] ?? '');
        return ($role === 'owner' || $role === 'operations') ? 'manager' : 'technician';
    }

    /** The Claude model that backs an audience once the free providers are exhausted; '' when Claude is not set up. */
    public static function fallback_model($aud, $s = null) {
        $s = $s ?: self::settings();
        $c = $s['providers']['anthropic'] ?? null;
        if (!$c || !self::usable('anthropic', $c)) { return ''; }
        $t = array_merge(self::defaults()['tiers'], (array) ($s['tiers'] ?? []));
        if ($aud === 'customer') { return (string) ($t['mid'] ?: ($c['model'] ?? '')); }
        if ($aud === 'technician' || $aud === 'system') { return (string) $t['routine']; }
        return (string) $t['high'];
    }

    /**
     * Hands a finished answer to the rule bot to learn from.
     *
     * Called from the one place every answer passes through, so the bot gathers what the model
     * knows whichever screen asked — and has something to say on the day no provider answers.
     * Only plain answers to customers: a JSON reply is machinery, and staff tools are not a
     * script anybody should repeat to a customer.
     */
    private static function teach($prompt, $out, $opts, $p) {
        if (!class_exists('Mutqan_Brain') || !empty($opts['json'])) { return; }
        if (class_exists('Mutqan_Policy') && Mutqan_Policy::is_staff($p)) { return; }
        try { Mutqan_Brain::remember($prompt, (string) ($out['text'] ?? ''), (string) ($opts['feature'] ?? '')); }
        catch (Throwable $e) { error_log('[mutqan] teach ' . $e->getMessage()); }
    }

    /* ------------------------------------------------------------------ cooldown + status */

    private static function cooling($id) { return (bool) get_transient('mq_cool_' . $id); }
    private static function set_cooldown($id, $secs) { set_transient('mq_cool_' . $id, 1, max(30, min(10800, (int) $secs))); }

    private static function note($id, $msg) {
        $s = (array) get_option(self::OPT, []);
        $st = isset($s['status']) && is_array($s['status']) ? $s['status'] : [];
        $st[$id] = ['at' => Mutqan_Store::now(), 'msg' => mb_substr((string) $msg, 0, 160)];
        $s['status'] = $st;
        update_option(self::OPT, $s, false);
    }

    /** The providers that are configured AND not in a cooldown: who could answer this second. */
    public static function live_providers($s = null) {
        $s = $s ?: self::settings();
        $out = [];
        foreach ((array) ($s['providers'] ?? []) as $id => $c) {
            if (self::usable($id, $c) && !self::cooling($id)) { $out[] = $id; }
        }
        return $out;
    }

    public static function cooldowns() {
        $out = [];
        foreach (array_keys(self::labels()) as $id) { $out[$id] = self::cooling($id); }
        return $out;
    }

    /* ------------------------------------------------------------------ providers */

    private static $cap = 0;   // seconds one provider may take inside a budgeted answer (0 = the caller's own timeout: the advisor's long tool turns)
    private static function http($url, $headers, $body, $timeout = 22) {
        if (self::$long) { $timeout = max((int) $timeout, 90); }
        if (self::$cap > 0) { $timeout = min((int) $timeout, self::$cap); }
        $res = wp_remote_post($url, ['timeout' => $timeout, 'headers' => $headers, 'body' => wp_json_encode($body)]);
        if (is_wp_error($res)) { return [0, null, $res->get_error_message(), '']; }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        return [$code, json_decode($raw, true), $raw, (string) wp_remote_retrieve_header($res, 'retry-after')];
    }

    private static function classify($code, $data, $raw, $retry) {
        $msg = is_array($data) ? ($data['error']['message'] ?? ($data['errors'][0]['message'] ?? '')) : '';
        $msg = 'HTTP ' . $code . ' ' . mb_substr((string) ($msg ?: $raw), 0, 120);
        $cool = 0;
        if ($code === 429 || $code === 402 || $code === 403 || ($code === 503 && stripos($raw, 'quota') !== false)) {
            $cool = (int) $retry > 0 ? (int) $retry : 90;
            if (preg_match('/per day|daily|RPD|quota|neurons|exceeded your current/i', $raw)) { $cool = max($cool, 3600); }
        } elseif ($code === 0 || $code >= 500) { $cool = 30; }
        elseif ($code === 401) { $cool = 600; }
        return ['ok' => false, 'error' => $msg, 'cooldown' => $cool];
    }

    private static function call($id, $c, $prompt, $json, $temp) {
        $eps = self::endpoints();
        if ($id === 'custom' || $id === 'custom2') {
            if (!self::custom_url_ok($c['url'] ?? '')) { return ['ok' => false, 'error' => 'رابط المزود المخصص غير صالح (https وعنوان عام فقط)', 'cooldown' => 600]; }
            return self::call_openai_compat($c['url'], $c, $prompt, $json, $temp, []);
        }
        if (isset($eps[$id])) { return self::call_openai_compat($eps[$id], $c, $prompt, $json, $temp, $id === 'openrouter' ? ['X-Title' => 'Mutqan'] : []); }
        switch ($id) {
            case 'gemini': return self::call_gemini($c, $prompt, $json, $temp);
            case 'cloudflare': return self::call_cloudflare($c, $prompt, $temp);
            case 'anthropic': return self::call_anthropic($c, $prompt, $json, $temp);
        }
        return ['ok' => false, 'error' => 'unknown provider', 'cooldown' => 0];
    }

    private static function call_openai_compat($url, $c, $prompt, $json, $temp, $extra_headers) {
        $body = ['model' => $c['model'], 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => $temp, 'max_tokens' => self::mt(900)];
        if ($json) { $body['response_format'] = ['type' => 'json_object']; }
        $h = array_merge(['Authorization' => 'Bearer ' . $c['key'], 'Content-Type' => 'application/json'], $extra_headers);
        list($code, $data, $raw, $retry) = self::http($url, $h, $body);
        if ($code === 400 && $json) {            // some models reject response_format; retry once as plain text
            unset($body['response_format']);
            list($code, $data, $raw, $retry) = self::http($url, $h, $body);
        }
        if ($code >= 200 && $code < 300 && isset($data['choices'][0]['message']['content'])) {
            return ['ok' => true, 'text' => (string) $data['choices'][0]['message']['content']];
        }
        return self::classify($code, $data, $raw, $retry);
    }

    /** The assistant's one voice: a Saudi man, always the same — whatever the page or the request asks for. */
    const VOICE = 'Charon';
    const VOICE_STYLE = 'You are the voice of a customer-service man from Saudi Arabia. Read the Arabic text below aloud EXACTLY as written, word for word: do not add, drop, translate or change any word. Always the same adult male voice, Saudi (Hijazi) accent, calm warm tone, medium-low pitch, steady natural pace, no singing, no emotion changes. Text:';

    /** Gemini text-to-speech (one fixed male voice). @return array ['ok'=>true,'wav'=>base64] or ['ok'=>false] */
    public static function tts($text, $voice = 'Charon', $style = null) {
        $voice = self::VOICE;   // fixed: never another voice, never a woman's
        $s = self::settings(); $c = $s['providers']['gemini'];
        $text = trim(mb_substr(preg_replace('/[*_#`]/u', '', (string) $text), 0, 700));
        if ($text === '') { return ['ok' => false, 'error' => 'empty']; }
        // The same sentence is spoken the same way every time, and a repeated one costs no quota.
        $dir = ''; $file = '';
        if (function_exists('wp_upload_dir')) { $u = wp_upload_dir(); $dir = rtrim((string) ($u['basedir'] ?? ''), '/') . '/mutqan-tts'; $file = $dir . '/' . md5($voice . '|' . $text . ($style ? '|' . $style : '')) . '.wav'; }
        if ($file !== '' && is_readable($file)) { return ['ok' => true, 'wav' => base64_encode((string) file_get_contents($file)), 'cached' => true]; }
        if (!self::usable('gemini', $c)) { return ['ok' => false, 'error' => 'no_gemini']; }
        $why = '';
        foreach (['gemini-2.5-flash-preview-tts', 'gemini-2.5-pro-preview-tts'] as $m) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $m . ':generateContent';
            list($code, $data, $raw) = self::http($url, ['x-goog-api-key' => $c['key'], 'Content-Type' => 'application/json'], [
                'contents' => [['role' => 'user', 'parts' => [['text' => ($style ?: self::VOICE_STYLE) . "\n" . $text]]]],
                'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]]],
            ], 45);
            $part = $data['candidates'][0]['content']['parts'][0] ?? [];
            $b64 = $part['inlineData']['data'] ?? ($part['inline_data']['data'] ?? '');
            if ($code >= 200 && $code < 300 && $b64 !== '') { break; }
            $why = $m . ' HTTP ' . $code . ' ' . ($data['candidates'][0]['finishReason'] ?? '') . ' ' . mb_substr((string) ($data['error']['message'] ?? ''), 0, 120) . (($code === 200 && empty($data['candidates'])) ? ' ' . mb_substr((string) $raw, 0, 160) : '');
            $b64 = '';
        }
        if ($b64 === '') { return ['ok' => false, 'error' => $why]; }
        $pcm = base64_decode($b64); $n = strlen($pcm);
        $wav = 'RIFF' . pack('V', 36 + $n) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', $n) . $pcm;
        if ($file !== '' && (is_dir($dir) || @wp_mkdir_p($dir))) { @file_put_contents($file, $wav); }
        return ['ok' => true, 'wav' => base64_encode($wav)];
    }

    private static function call_gemini($c, $prompt, $json, $temp) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($c['model']) . ':generateContent';
        $gen = ['temperature' => $temp, 'maxOutputTokens' => self::mt(900)];
        if ($json) { $gen['responseMimeType'] = 'application/json'; }
        list($code, $data, $raw, $retry) = self::http($url, ['x-goog-api-key' => $c['key'], 'Content-Type' => 'application/json'], [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => $gen,
        ]);
        if ($code >= 200 && $code < 300 && isset($data['candidates'][0]['content']['parts'])) {
            $t = '';
            foreach ($data['candidates'][0]['content']['parts'] as $part) { $t .= (string) ($part['text'] ?? ''); }
            if ($t !== '') { return ['ok' => true, 'text' => $t]; }
        }
        return self::classify($code, $data, $raw, $retry);
    }

    /**
     * Reads a document (an image or a PDF) with Gemini — the free provider that sees files — and answers $prompt about
     * it. Used to lift a company's data from its commercial registration, VAT certificate or national address.
     * @return array ['ok' => bool, 'text' => string, 'error' => string]
     */
    public static function vision($b64, $mime, $prompt, $json = true) {
        $s = self::settings(); $c = $s['providers']['gemini'] ?? [];
        if (!self::usable('gemini', $c)) { return ['ok' => false, 'text' => '', 'error' => 'no_gemini']; }
        $gen = ['temperature' => 0.1, 'maxOutputTokens' => 1500];
        if ($json) { $gen['responseMimeType'] = 'application/json'; }
        $models = array_values(array_unique([(string) $c['model'], 'gemini-2.5-flash', 'gemini-2.0-flash']));
        $last = '';
        foreach ($models as $m) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($m) . ':generateContent';
            list($code, $data, $raw) = self::http($url, ['x-goog-api-key' => $c['key'], 'Content-Type' => 'application/json'], [
                'contents' => [['role' => 'user', 'parts' => [['inline_data' => ['mime_type' => $mime, 'data' => $b64]], ['text' => $prompt]]]], 'generationConfig' => $gen,
            ], 60);
            if ($code >= 200 && $code < 300 && isset($data['candidates'][0]['content']['parts'])) {
                $t = ''; foreach ($data['candidates'][0]['content']['parts'] as $part) { $t .= (string) ($part['text'] ?? ''); }
                if ($t !== '') { self::usage('gemini', $m, $data['usageMetadata'] ?? [], 'doc_extract'); return ['ok' => true, 'text' => $t, 'error' => '']; }
            }
            $last = 'http_' . $code;
        }
        return ['ok' => false, 'text' => '', 'error' => $last];
    }

    /** Raw Messages API call (used by run() and by the owner agent with tools). @return array [code, data, raw, retry] */
    public static function anthropic_request($body, $key = null) {
        $key = $key ?: self::anthropic_key();
        return self::http('https://api.anthropic.com/v1/messages', ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json'], $body, 90);
    }

    private static function call_anthropic($c, $prompt, $json, $temp) {
        $body = ['model' => $c['model'], 'max_tokens' => self::mt(1200), 'temperature' => $temp, 'messages' => [['role' => 'user', 'content' => $prompt]]];
        if ($json) { $body['system'] = 'Reply with one JSON object only.'; }
        list($code, $data, $raw, $retry) = self::anthropic_request($body, $c['key']);
        if ($code >= 200 && $code < 300 && isset($data['content'])) {
            $t = ''; foreach ($data['content'] as $b) { if (($b['type'] ?? '') === 'text') { $t .= $b['text']; } }
            self::usage('anthropic', $c['model'], $data['usage'] ?? [], 'chat');
            if ($t !== '') { return ['ok' => true, 'text' => $t]; }
        }
        return self::classify($code, $data, $raw, $retry);
    }

    /** One row per paid call in the shared _ai_usage collection (owner AI dashboard reads it). */
    public static function usage($provider, $model, $u, $feature) {
        try {
            $sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
            Mutqan_Store::insert('_ai_usage', ['at' => gmdate('c'), 'date' => gmdate('Y-m-d'), 'kind' => $provider, 'model' => $model, 'feature' => $feature,
                'tokensIn' => (int) ($u['input_tokens'] ?? 0), 'tokensOut' => (int) ($u['output_tokens'] ?? 0),
                'points' => class_exists('Mutqan_Points') && $provider === 'anthropic' ? Mutqan_Points::spend($model, $u['input_tokens'] ?? 0, $u['output_tokens'] ?? 0) : 0], null, [], $sys);
        } catch (Throwable $e) { /* usage logging never blocks the answer */ }
    }

    private static function call_cloudflare($c, $prompt, $temp) {
        $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($c['account']) . '/ai/run/' . $c['model'];
        list($code, $data, $raw, $retry) = self::http($url, ['Authorization' => 'Bearer ' . $c['key'], 'Content-Type' => 'application/json'], [
            'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => self::mt(900), 'temperature' => $temp,
        ]);
        if ($code >= 200 && $code < 300 && isset($data['result']['response']) && is_string($data['result']['response'])) {
            return ['ok' => true, 'text' => $data['result']['response']];
        }
        return self::classify($code, $data, $raw, $retry);
    }

    /* ------------------------------------------------------------------ JSON extraction */

    public static function extract_json($text) {
        $t = trim((string) $text);
        $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
        $d = json_decode($t, true);
        if (is_array($d)) { return $d; }
        $a = strpos($t, '{'); $b = strrpos($t, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $d = json_decode(substr($t, $a, $b - $a + 1), true);
            if (is_array($d)) { return $d; }
        }
        return null;
    }

    /** Sends one tiny request to every configured provider (admin "test" button). */
    public static function test_all() {
        $s = self::settings(); $out = [];
        foreach ($s['order'] as $id) {
            $c = $s['providers'][$id] ?? null;
            if (!$c || !self::usable($id, $c)) { $out[$id] = 'غير مفعّل'; continue; }
            $r = self::call($id, $c, 'أجب بكلمة واحدة فقط: تم', false, 0.0);
            $out[$id] = $r['ok'] ? 'يعمل ✓' : ('فشل: ' . $r['error']);
            if (!$r['ok'] && $r['cooldown']) { self::set_cooldown($id, $r['cooldown']); }
            if ($r['ok']) { delete_transient('mq_cool_' . $id); }
        }
        return $out;
    }
}
