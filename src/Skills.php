<?php
declare(strict_types=1);

/**
 * Specialties of technicians: which kinds of job each one takes. The list is fixed here, so a technician
 * can only be given a known specialty and the map can filter by it.
 */
final class Skills
{
    /** The specialties, the same names the services use on the site. */
    public const LIST = [
        'تكييف', 'كهرباء', 'سباكة', 'أقفال ذكية', 'أجهزة منزلية', 'ساتلايت', 'دهان وديكور', 'تبليط وترميم',
        'كاميرات', 'تبريد', 'تنظيف', 'مكافحة حشرات', 'خزانات', 'نقل أثاث', 'طاقة شمسية', 'صيانة عامة', 'مقاولات',
    ];
    public const MAX_PER_PERSON = 10;

    /** @return array<string,list<string>> specialties per technician id */
    public static function all(): array
    {
        $out = [];
        foreach (oxy_pdo()->query('SELECT person_id, skill FROM technician_skills ORDER BY skill')->fetchAll() as $row) {
            $out[$row['person_id']][] = $row['skill'];
        }
        return $out;
    }

    /** @return list<string> */
    public static function of(string $personId): array
    {
        return self::all()[$personId] ?? [];
    }

    /** Words in a service name that point to a specialty. A service can point to more than one. */
    private const KEYWORDS = [
        'تكييف' => ['تكييف', 'مكيف', 'مكيفات', 'تبريد', 'ثلاجة', 'ثلاجات', 'دكت'],
        'كهرباء' => ['كهرباء', 'كهربائي', 'إنارة', 'لمبة', 'ماس'],
        'سباكة' => ['سباكة', 'سباك', 'تسرب', 'تسربات', 'ماسورة', 'حمام', 'مطبخ'],
        'أقفال ذكية' => ['قفل', 'أقفال'],
        'أجهزة منزلية' => ['أجهزة', 'غسالة', 'غسالات', 'فرن'],
        'ساتلايت' => ['ساتلايت', 'دش', 'رسيفر'],
        'دهان وديكور' => ['دهان', 'دهانات', 'ديكور', 'طلاء'],
        'تبليط وترميم' => ['تبليط', 'ترميم', 'عزل', 'سيراميك'],
        'كاميرات' => ['كاميرا', 'كاميرات', 'مراقبة'],
        'تنظيف' => ['تنظيف', 'تعقيم'],
        'مكافحة حشرات' => ['حشرات', 'صراصير', 'رش'],
        'خزانات' => ['خزان', 'خزانات'],
        'نقل أثاث' => ['نقل', 'أثاث'],
        'طاقة شمسية' => ['شمسية', 'طاقة', 'ألواح'],
        'مقاولات' => ['مقاولات', 'مقاول'],
    ];

    /** The specialties a service needs, from the words in its name. @return list<string> */
    public static function forService(string $service): array
    {
        $text = mb_strtolower($service);
        $found = [];
        foreach (self::KEYWORDS as $skill => $words) {
            foreach ($words as $word) {
                if (mb_strpos($text, $word) !== false) {
                    $found[$skill] = true;
                    break;
                }
            }
        }
        return array_keys($found);
    }

    /** Replaces a technician's specialties with the given list. @param mixed $skills @return list<string> */
    public static function set(string $personId, mixed $skills): array
    {
        $person = People::find($personId);
        if ($person['role'] !== 'technician') {
            throw new OxyHttpError(400, 'not_technician');
        }
        if (!is_array($skills)) {
            throw new OxyHttpError(400, 'bad_skill');
        }
        $clean = [];
        foreach ($skills as $skill) {
            if (!is_string($skill) || !in_array($skill, self::LIST, true)) {
                throw new OxyHttpError(400, 'bad_skill');
            }
            $clean[$skill] = true;
        }
        if (count($clean) > self::MAX_PER_PERSON) {
            throw new OxyHttpError(400, 'too_many_skills');
        }
        $pdo = oxy_pdo();
        $pdo->prepare('DELETE FROM technician_skills WHERE person_id = ?')->execute([$personId]);
        $insert = $pdo->prepare('INSERT INTO technician_skills (person_id, skill) VALUES (?, ?)');
        foreach (array_keys($clean) as $skill) {
            $insert->execute([$personId, $skill]);
        }
        Audit::log('technician_skills', $personId, implode(',', array_keys($clean)));
        return array_keys($clean);
    }
}
