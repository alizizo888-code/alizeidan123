<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Shared content-development layer for WordPress and Blogger.
 * It enriches each generated article with a relevant, non-boilerplate practical section,
 * compares it with the recent local publishing corpus, and records Blogger publications
 * so both publishing routes learn from the same history. It never suppresses a post.
 */
final class Oxygen_Content_Diversity {
    const HISTORY = 'oxygen_content_diversity_external_v1';
    private static $corpus = null;

    private static function e($value) { return esc_html((string) $value); }

    private static function banks() {
        return [
            'ac' => [
                ['عنوان الدليل الفني: قياس فرق الحرارة', 'قبل الحكم على كفاءة التبريد، تُقاس حرارة الهواء عند الرجوع وعند الدفع بعد استقرار التشغيل، مع تسجيل درجة حرارة الغرفة والجو الخارجي. فرق الحرارة وحده لا يثبت سلامة الشحنة؛ ويُقرأ مع ضغط التشغيل وحالة الفلاتر وتدفق الهواء.', 'إذا كان الفرق ضعيفاً، يبدأ التشخيص بتنظيف الفلتر وفحص سرعة المروحة وانسداد المبخر، ثم ينتقل الفني إلى قياسات دائرة التبريد وفق مواصفات الجهاز.'],
                ['عنوان الدليل الفني: مسار الهواء', 'انسداد الفلتر أو اتساخ المبخر أو انغلاق فتحات الدفع قد يجعل المكيف يبدو ضعيفاً رغم أن دائرة التبريد تعمل. افحص توزيع الهواء في أكثر من فتحة، ولا تعتمد على الإحساس عند فتحة واحدة.', 'في الأنظمة المخفية والمركزية تُراجع موازنة الهواء والدامبرز والعزل وتسربات الدكت، لأن الخلل قد يكون في التوزيع لا في وحدة التبريد نفسها.'],
                ['عنوان الدليل الفني: تصريف مياه التكثيف', 'تجمع الماء حول الوحدة الداخلية يستدعي فحص ميل حوض التصريف وسلامة الخرطوم وخلوه من الانسداد، مع التأكد من عدم وجود كسر أو انفصال في الوصلات.', 'يُجرى اختبار تصريف بالماء بعد التنظيف، وتُعالج أسباب رجوع الماء قبل إعادة التشغيل لتجنب تلف الجدار أو السقف.'],
                ['عنوان الدليل الفني: متى يلزم فحص دائرة التبريد؟', 'تكرار ضعف التبريد لا يعني تلقائياً نقص غاز التبريد. قد يكون السبب تسرباً أو ضعف تدفق الهواء أو اتساخ المبادلات أو خللاً في الحساسات.', 'الفحص الصحيح يبدأ بتوثيق الأعراض وقراءة القيم التشغيلية وفحص أماكن التسرب، ولا تتم إضافة وسيط التبريد قبل تحديد السبب وإصلاحه.'],
                ['عنوان الدليل الفني: حماية الوحدة الخارجية', 'يجب ترك مسافة تهوية مناسبة حول الوحدة الخارجية وإزالة العوائق التي تعيد الهواء الساخن إلى ملف المكثف. تراكم الغبار أو انسداد الزعانف يرفع ضغط التشغيل ويقلل الكفاءة.', 'يُستخدم أسلوب تنظيف مناسب للزعانف مع فصل الكهرباء وحماية المكونات، وتُفحص قواعد التثبيت والاهتزازات بعد الانتهاء.']
            ],
            'fridge' => [
                ['عنوان الدليل الفني: قراءة نمط التبريد', 'يُسجل الفني درجة الحرارة داخل الثلاجة والفريزر ومدة عمل الضاغط وعدد مرات الفصل، ثم يقارنها بإعدادات الشركة المصنعة. القياس بعد فتح الباب مباشرة لا يعطي صورة دقيقة.', 'تُراجع سلامة جلدة الباب وتدفق الهواء بين الحجرات ومكان الجهاز بعيداً عن مصادر الحرارة قبل الانتقال إلى فحص المكونات الكهربائية.'],
                ['عنوان الدليل الفني: الثلج وتدفق الهواء', 'تراكم الثلج غير الطبيعي قد يرتبط بفتح الباب المتكرر أو تلف الجلدة أو خلل دورة إزالة الثلج أو انسداد مجرى الهواء.', 'يُفحص سخان الإذابة والحساس والمؤقت أو لوحة التحكم وفق تصميم الطراز، ولا يُزال الثلج بأداة حادة حتى لا تتضرر مواسير المبخر.']
            ],
            'electrical' => [
                ['عنوان الدليل الفني: السلامة قبل القياس', 'تبدأ معاينة العطل الكهربائي بفصل مصدر التغذية وتأمينه، ثم التحقق من غياب الجهد بأداة قياس مناسبة قبل لمس الموصلات. لا تُجرى اختبارات حية إلا بواسطة فني مؤهل وبإجراءات حماية.', 'تُراجع سعة القاطع ومقطع الكابل ونقاط التوصيل وآثار السخونة، ويجب أن يتوافق أي استبدال مع الحمل الفعلي والكود المحلي.']
            ],
            'plumbing' => [
                ['عنوان الدليل الفني: تحديد مصدر التسرب', 'يُحدد نطاق البلل أولاً ثم تُفحص الوصلات والمحابس ونقاط التغذية والصرف بالتتابع. ظهور الماء في مكان لا يعني أن مصدره خلف الموضع نفسه؛ فقد ينتقل داخل الجدار أو البلاطة.', 'يساعد اختبار الضغط أو اختبار الصرف المناسب على عزل القطاع المتسبب، ويُوثق موضع الإصلاح قبل إغلاق الجدار أو الأرضية.']
            ],
            'paint' => [
                ['عنوان الدليل الفني: تجهيز السطح', 'نجاح الدهان يعتمد على ثبات السطح وجفافه ونظافته أكثر من اعتماده على عدد الطبقات. تزال الأجزاء المفككة وتعالج الشقوق ويُحدد سبب الرطوبة قبل وضع المعجون أو البرايمر.', 'يُختبر توافق المواد مع السطح وتُراعى أزمنة الجفاف ودرجة الحرارة والتهوية حسب تعليمات الشركة المصنعة.']
            ],
            'general' => [
                ['عنوان الدليل العملي: توثيق المعاينة', 'تُسجل حالة الموقع قبل التنفيذ بالصور والمقاسات ووصف العطل، ثم تُحدد الأعمال المطلوبة والمواد والوقت المتوقع. هذا يقلل اختلاف التوقعات ويساعد على مقارنة العرض بالتنفيذ الفعلي.', 'بعد الإنجاز تُوثق نتيجة الاختبار والتنظيف وأي ملاحظات متبقية، مع توضيح ما يحتاج متابعة لاحقة بدلاً من الاكتفاء بعبارة «تم الإصلاح».'],
                ['عنوان الدليل العملي: اختيار الحل المناسب', 'تختلف المعالجة بحسب عمر الجهاز وحالته وتوفر قطع الغيار وكلفة الإصلاح مقارنة بالاستبدال. ينبغي شرح البدائل للعميل مع بيان مزايا كل خيار وحدوده.', 'يُبنى القرار على فحص قابل للتحقق وبيانات الطراز، لا على التخمين أو استبدال أجزاء سليمة لمجرد تجربة الحل.']
            ]
        ];
    }

    private static function domain($text) {
        $t = mb_strtolower(wp_strip_all_tags((string) $text), 'UTF-8');
        if (preg_match('/ثلاج|فريزر|تبريد تجار|غرفة تبريد/u', $t)) return 'fridge';
        if (preg_match('/كهرب|قاطع|تمديد|إنارة|أسلاك/u', $t)) return 'electrical';
        if (preg_match('/سباك|تسرب|مواسير|صرف|مياه/u', $t)) return 'plumbing';
        if (preg_match('/دهان|طلاء|معجون|ديكور/u', $t)) return 'paint';
        if (preg_match('/مكيف|تكييف|فريون|دكت|تبريد/u', $t)) return 'ac';
        return 'general';
    }

    private static function tokens($text) {
        $text = mb_strtolower(wp_strip_all_tags((string) $text), 'UTF-8');
        $text = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}]+/u', ' ', $text);
        $stop = ['في','من','على','الى','إلى','عن','مع','هذا','هذه','ذلك','التي','الذي','هو','هي','و','أو','ثم','بعد','قبل','عند','كل','ما','لا','لم','لن','تم','شركة','أفضل','افضل','خدمة','خدمات'];
        $out = [];
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            if (mb_strlen($word, 'UTF-8') >= 3 && !in_array($word, $stop, true)) $out[$word] = true;
        }
        return array_keys($out);
    }

    private static function similarity($a, $b) {
        $aa = array_fill_keys(self::tokens($a), true);
        $bb = array_fill_keys(self::tokens($b), true);
        if (!$aa || !$bb) return 0;
        $intersection = count(array_intersect_key($aa, $bb));
        return $intersection / max(1, count($aa) + count($bb) - $intersection);
    }

    private static function corpus() {
        if (self::$corpus !== null) return self::$corpus;
        self::$corpus = [];
        $posts = get_posts(['post_type' => 'post', 'post_status' => ['publish','draft','pending','future'], 'numberposts' => 80, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true]);
        foreach ((array) $posts as $post) {
            self::$corpus[] = get_the_title($post) . ' ' . wp_trim_words(wp_strip_all_tags($post->post_content), 180, '');
        }
        $history = get_option(self::HISTORY, []);
        foreach (array_slice((array) $history, 0, 80) as $row) {
            self::$corpus[] = (string) ($row['title'] ?? '') . ' ' . (string) ($row['text'] ?? '');
        }
        return self::$corpus;
    }

    public static function enrich($title, $html, $source = 'wordpress') {
        $title = wp_strip_all_tags((string) $title);
        $html = (string) $html;
        $plain = wp_strip_all_tags($html);
        $max_similarity = 0;
        foreach (self::corpus() as $existing) {
            $max_similarity = max($max_similarity, self::similarity($title . ' ' . $plain, $existing));
        }

        $bank = self::banks();
        $domain = self::domain($title . ' ' . $plain);
        $items = $bank[$domain] ?? $bank['general'];
        if ($domain !== 'general') { $items = array_merge($items, $bank['general']); }
        $seed = abs(crc32($title . '|' . $source . '|' . get_option('blogname')));
        $pick = $items[$seed % count($items)];
        // A high-overlap article receives a second, different practical angle instead of being discarded.
        $html .= '<section class="oxy-originality-enrichment" data-content-source="' . esc_attr($source) . '">';
        $html .= '<h2>' . self::e($pick[0]) . '</h2><p>' . self::e($pick[1]) . '</p><p>' . self::e($pick[2]) . '</p>';
        $second = $items[($seed + 1) % count($items)];
        if ($second[0] !== $pick[0] && strpos($html, self::e($second[0])) === false) {
            $html .= '<h2>' . self::e($second[0]) . '</h2><p>' . self::e($second[1]) . '</p><p>' . self::e($second[2]) . '</p>';
        }
        // Where the recent corpus shows substantial overlap, add a third complementary angle
        // to deepen the explanation rather than blocking or hiding the article.
        if ($max_similarity >= 0.24 && count($items) > 2) {
            $third = $items[($seed + 2) % count($items)];
            if ($third[0] !== $pick[0] && $third[0] !== $second[0] && strpos($html, self::e($third[0])) === false) {
                $html .= '<h2>' . self::e($third[0]) . '</h2><p>' . self::e($third[1]) . '</p><p>' . self::e($third[2]) . '</p>';
            }
        }
        $html .= '</section>';
        return ['html' => $html, 'similarity' => round($max_similarity, 3), 'domain' => $domain];
    }

    public static function record_external($title, $html, $url = '') {
        $rows = get_option(self::HISTORY, []);
        $rows = is_array($rows) ? $rows : [];
        array_unshift($rows, ['time' => time(), 'title' => wp_strip_all_tags((string) $title), 'text' => mb_substr(wp_strip_all_tags((string) $html), 0, 3000, 'UTF-8'), 'url' => esc_url_raw((string) $url)]);
        update_option(self::HISTORY, array_slice($rows, 0, 300), false);
        self::$corpus = null;
    }
}
