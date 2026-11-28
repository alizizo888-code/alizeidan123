<?php
// «التنبيه الصوتي» (owner, 2026-11-22): every alert has its sentence; the clip is made loud; the app and the site agree.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-voice-' . getmypid());
require __DIR__ . '/wpshim.php';
if (!class_exists('Mutqan_Voice')) { require_once dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-voice.php'; }
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "voice: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$K = 'Mutqan_Voice';
$cases = [
    [['new_order', '🆕 طلب جديد'], 'new_order'], [['order_bot', 'طلب من الواتساب'], 'new_order'],
    [['rejected', 'الفني رفض المهمة'], 'tech_rejected'], [['order_status', 'العميل رفض الطلب'], 'cust_rejected'],
    [['accepted', 'قبل الطلب'], 'accepted'], [['order_nudge', 'الفني اتأخر على العميل'], 'late'],
    [['paid', 'تم الدفع'], 'paid'], [['transfer', 'تم استلام تحويل'], 'transfer'],
    [['order_assigned', 'تم الإسناد الآلي للفني علي'], 'auto_assigned'], [['order_assigned', '🔧 مهمة جديدة ليك'], 'new_task'],
    [['hotline_call', 'مكالمة'], 'call'], [['wa_handoff', 'عميل محتاج موظف'], 'handoff'], [['', 'تقرير'], 'alert'],
    [['order_status', 'العميل ألغى الطلب'], 'cust_cancelled'], [['completed', 'تم'], 'completed'], [['order_msg', 'رسالة'], 'message'],
];
foreach ($cases as [$in, $want]) { $got = $K::key($in[0], $in[1]); $t("«{$in[1]}» ({$in[0]}) → $want", $got === $want, $got); }
$t('every key has a sentence', !array_diff(array_map(function ($c) { return $c[1]; }, $cases), array_keys($K::KEYS)));
// the app's copy of the sentences is the same list
$js = file_get_contents(dirname(__DIR__, 2) . '/mutqan-app/src/lib/voiceAlert.js');
preg_match_all("/(\\w+): '([^']+)'/u", substr($js, strpos($js, 'VOICE_TEXT'), strpos($js, 'const MAP') - strpos($js, 'VOICE_TEXT')), $m);
$t('the app says the same sentences as the site', array_combine($m[1], $m[2]) == $K::KEYS, array_diff_assoc(array_combine($m[1], $m[2]), $K::KEYS));
// louder: a quiet clip comes out near full scale, never clipped
$pcm = ''; for ($i = 0; $i < 24000; $i++) { $pcm .= pack('s', (int) (3000 * sin($i / 8))); }
$wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
$out = $K::louder($wav); $s = unpack('s*', substr($out, 44)); $peak = max(array_map('abs', $s));
$t('a quiet clip is made loud (peak ≈ full scale) and never clips', $peak > 30000 && $peak <= 32767 && strlen($out) === strlen($wav), $peak);
$t('anything that is not a WAV is left alone', $K::louder('nope') === 'nope');
// «التنبيه يقول نفسه»: the sentence says what happened; the tone follows how urgent it is
$m1 = ['event' => 'accepted', 'title' => '✅ الفني أحمد قبل الطلب #1004', 'body' => 'تكييف سبليت · العزيزية https://x.y/z', 'priority' => 'normal'];
$s1 = $K::sentence($m1);
$t('the sentence names who and what — names only, no numbers', mb_strpos($s1, 'الفني أحمد قبل الطلب') !== false && !preg_match('/\d/', $s1) && mb_strpos($s1, 'العزيزية') !== false && mb_strpos($s1, 'http') === false && mb_strpos($s1, '✅') === false, $s1);
$t('accepted is a normal alert', $K::tier($m1) === 'normal', $K::tier($m1));
$m2 = ['event' => 'rejected', 'title' => 'الفني رفض المهمة', 'body' => 'طلب رقم 1005', 'priority' => 'high'];
$t('a rejection is urgent and opens with «انتباه»', $K::tier($m2) === 'urgent' && mb_strpos($K::sentence($m2), 'انتباه') === 0, $K::sentence($m2));
$m3 = ['event' => 'article_report', 'title' => '📊 تقرير نشر المقالات — أكسجين', 'body' => '14 عملية: 12 مقال اتنشر'];
$t('the articles report is calm info and says it is the publishing report', $K::tier($m3) === 'info' && mb_strpos($K::sentence($m3), 'تقرير نشر المقالات') !== false, [$K::tier($m3), $K::sentence($m3)]);
$m4 = ['event' => 'order_msg', 'title' => 'إشعار', 'body' => 'العميل بيسأل الفني هيوصل امتى'];
$t('a generic title is replaced by what kind of alert it is, and the message is read', mb_strpos($K::sentence($m4), 'رسالة') !== false && mb_strpos($K::sentence($m4), 'هيوصل امتى') !== false, $K::sentence($m4));
$t('«ر.س» is said as ريال', mb_strpos($K::clean('المبلغ 250 ر.س'), '250 ريال') !== false, $K::clean('المبلغ 250 ر.س'));
$t('long text is cut short enough to say', count(preg_split('/\s+/u', $K::sentence(['title' => 'تنبيه', 'body' => str_repeat('كلمة ', 120)]))) <= 40);
$t('each urgency has its own voice', count(array_unique(array_column($K::TIERS, 'voice'))) === 3);
$c = $K::clean('💳 تم الدفع من العميل سعيد · طلب #1004 · 250 ر.س · جوال 0549247636 · مرجع AB12CD34');
$t('names from the data, the phone digit by digit, a code letter by letter', mb_strpos($c, 'العميل سعيد، طلب رقم 1004، 250 ريال') !== false && mb_strpos($c, '0 5 4 9 2 4 7 6 3 6') !== false && mb_strpos($c, 'A B 1 2 C D 3 4') !== false, $c);
$t('a plain amount or order number is not spelled out', mb_strpos($K::clean('طلب #1004 بمبلغ 250'), 'رقم 1004 بمبلغ 250') !== false);
$t('the payment alert is a normal (not urgent) one', $K::tier(['event' => 'paid', 'title' => '💳 تم الدفع من العميل سعيد', 'priority' => 'normal']) === 'normal');
$p = $K::sentence(['event' => 'paid', 'title' => '💳 تم الدفع من العميل سعيد', 'body' => 'طلب رقم 1004، مبلغ 250 ر.س، عن طريق STC Pay، رقم العملية AB12CD34']);
$t('payment said with names and the way, no numbers', mb_strpos($p, 'تم الدفع من العميل سعيد') !== false && mb_strpos($p, 'عن طريق STC Pay') !== false && !preg_match('/[\d٠-٩]/u', $p), $p);
$h = $K::sentence(['event' => 'hotline_call', 'title' => '📞 مكالمة على الخط الساخن من عميل جديد اسمه سامي', 'body' => "الاسم: سامي\nالجوال: 0549247636", 'priority' => 'high']);
$t('a hotline call says who is calling and never the phone number', mb_strpos($h, 'من عميل جديد اسمه سامي') !== false && !preg_match('/\d/', $h), $h);
$t('an unknown caller with no name is «عميل جديد»', $K::caller('', '') === 'من عميل جديد');
$t('the technician on the way: no distance figure', !preg_match('/\d/', $K::sentence(['event' => 'nearby', 'title' => 'الفني علي قرّب من العميل سعيد', 'body' => 'على بُعد 2 كم، طلب رقم 77'])));
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
