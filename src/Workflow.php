<?php
declare(strict_types=1);

/**
 * Where an order is, whose hands it is in, and who may move it on.
 *
 * Ported from the rules the business already ran on (workflow.js in the Whacka build) rather than
 * invented here, because those rules are what the owner, the supervisors and the technicians already
 * have in their heads. A system that disagrees with what people expect is wrong even when its own
 * logic is sound.
 *
 * Two ideas carry the whole thing:
 *
 *  - An order always sits with exactly one party. That is its handler, and the customer, the
 *    operations room, the technician and the owner all read the same answer to "who has it now?".
 *  - Moving it is not free. Each role may only hand an order to particular places, so an order
 *    cannot quietly leave the chain or come back to someone who already let it go.
 */
final class Workflow
{
    /** The chain an order travels, in order. */
    public const HANDLERS = [
        'ai' => 'خدمة العملاء الذكية',
        'operations' => 'غرفة العمليات',
        'technician' => 'الفني',
        'supervisor' => 'مشرف الموقع',
        'owner' => 'المالك',
    ];

    /**
     * Every state an order can be in, in the order it moves through them.
     *
     * 'forced' is an order operations pushed onto a technician without waiting for them to accept;
     * 'nearby' is the technician close but not yet at the door, which is what the customer actually
     * wants to know in the last few minutes.
     */
    public const STATUSES = [
        'new' => 'جديد',
        'forced' => 'إجباري',
        'assigned' => 'مُسند',
        'accepted' => 'قَبِل الفني',
        'en_route' => 'في الطريق',
        'nearby' => 'قرّب',
        'arrived' => 'وصل',
        'in_progress' => 'جاري العمل',
        'completed' => 'تم الإنجاز',
        'cancelled' => 'ملغي',
    ];

    /** The statuses that mean the order is live and somebody is waiting on it. */
    public const LIVE = ['new', 'forced', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    /** The statuses that mean the order is in a technician's hands. */
    public const WITH_TECHNICIAN = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    /**
     * Who may hand an order from one party to another.
     *
     * Read as: a person in ROLE, looking at an order currently held by FROM, may pass it to any of
     * the parties listed. An empty list means they may look but not move it. The assistant can only
     * ever hand up to operations — it never gives work to a person directly.
     */
    private const ALLOWED = [
        'ai' => ['ai' => ['operations']],
        'operations' => [
            'operations' => ['supervisor', 'owner'],
            'technician' => ['operations', 'supervisor', 'owner'],
            'supervisor' => ['operations', 'owner'],
            'owner' => [],
        ],
        'technician' => ['technician' => ['operations', 'supervisor']],
        'supervisor' => [
            'supervisor' => ['operations', 'owner'],
            'technician' => ['operations', 'supervisor'],
            'operations' => ['supervisor'],
        ],
        'owner' => [
            'operations' => ['supervisor', 'owner'],
            'technician' => ['operations', 'supervisor', 'owner'],
            'supervisor' => ['operations', 'owner'],
            'owner' => ['operations', 'supervisor'],
        ],
    ];

    /** Why an order was pushed up the chain. */
    public const ESCALATIONS = [
        'pricing' => 'خلاف على السعر',
        'complaint' => 'شكوى عميل',
        'refund' => 'طلب استرداد',
        'legal' => 'مسألة قانونية',
        'payment' => 'مشكلة في الدفع',
        'repeated_failure' => 'تعثّر المساعد المتكرر',
        'human' => 'العميل طلب موظفًا',
        'high_value' => 'عميل عالي القيمة',
        'sensitive' => 'عملية حساسة',
        'no_accept' => 'الفني لم يقبل في الوقت المحدد',
        'tech_late' => 'تأخر الفني',
        'forced_stale' => 'مهمة إجبارية بلا قبول',
        'manual' => 'تصعيد يدوي',
    ];

    /** The ones that should reach a person now rather than wait in a list. */
    public const URGENT = ['complaint', 'refund', 'legal', 'payment', 'sensitive'];

    public const PAYMENT_METHODS = ['cash' => 'نقدًا', 'card' => 'شبكة / مدى', 'transfer' => 'تحويل بنكي'];

    /**
     * Who holds this order right now.
     *
     * A handler written on the order wins, because somebody decided it. Otherwise it is worked out
     * from the facts: a technician holding a live job has it, and anything else sits with operations.
     */
    public static function handler(array $order): string
    {
        $written = (string) ($order['handler'] ?? '');
        if (isset(self::HANDLERS[$written])) {
            return $written;
        }
        if (($order['technician_id'] ?? null) && in_array((string) $order['status'], self::WITH_TECHNICIAN, true)) {
            return 'technician';
        }
        return 'operations';
    }

    /**
     * Where this person may send the order from where it is.
     * A technician may only move a job that is actually theirs.
     * @return list<string>
     */
    public static function allowed(string $role, string $from, bool $isAssignedTech = false): array
    {
        if ($role === 'technician' && !$isAssignedTech) {
            return [];
        }
        return self::ALLOWED[$role][$from] ?? [];
    }

    /** Hands an order to another party, refusing any move the chain does not allow. */
    public static function transfer(string $orderId, string $role, string $to, string $why = ''): array
    {
        if (!isset(self::HANDLERS[$to])) {
            throw new OxyHttpError(400, 'bad_handler');
        }
        $order = Orders::find($orderId);
        $from = self::handler($order);
        if ($from === $to) {
            throw new OxyHttpError(409, 'already_there');
        }
        $isTech = $role === 'technician';
        if (!in_array($to, self::allowed($role, $from, $isTech), true)) {
            throw new OxyHttpError(403, 'transfer_not_allowed');
        }
        if ($why !== '' && !isset(self::ESCALATIONS[$why])) {
            throw new OxyHttpError(400, 'bad_reason');
        }
        $updated = Orders::update($orderId, ['handler' => $to]);
        Audit::log('order_transfer', $orderId, $from . '→' . $to . ($why === '' ? '' : ':' . $why));
        return $updated;
    }

    /**
     * What the customer is told when an order reaches a state.
     *
     * Only the moments a customer actually wants to hear about. Saying something at every internal
     * step would train them to ignore all of it, and the one message that matters would be lost.
     */
    public static function followUp(string $status, string $technicianName = ''): ?string
    {
        return match ($status) {
            'new' => 'تم استلام طلبك.',
            'accepted' => 'تم قبول طلبك بواسطة الفني' . ($technicianName === '' ? '' : ' ' . $technicianName) . '.',
            'en_route' => 'الفني في الطريق.',
            'arrived' => 'وصل الفني إلى موقعك.',
            'completed' => 'هل تمت الخدمة بالشكل المطلوب؟',
            default => null,
        };
    }

    /**
     * What is actually known about where the technician is — distance and how old the reading is,
     * never a time of arrival.
     *
     * Saying "عشر دقائق" turns a guess into a promise the business did not make, and a customer who
     * is told ten minutes twice stops believing the third. Distance and age are facts; the customer
     * can draw their own conclusion from them.
     */
    public static function tracking(array $order, int $staleMinutes = 10): array
    {
        if (!in_array((string) $order['status'], ['en_route', 'nearby'], true)) {
            return ['available' => false, 'reason' => 'status', 'text' => 'لا يوجد تتبع حي لهذا الطلب في حالته الحالية.'];
        }
        $loc = Locations::of((string) ($order['technician_id'] ?? ''));
        if ($loc === null) {
            return ['available' => false, 'reason' => 'no_location', 'text' => 'لا توجد بيانات موقع حية للفني حاليًا.'];
        }
        $ageMin = max(0, (int) round((time() - (int) $loc['updated']) / 60));
        $km = null;
        if (($order['lat'] ?? null) !== null && ($order['lng'] ?? null) !== null) {
            $km = round(Locations::km((float) $loc['lat'], (float) $loc['lng'], (float) $order['lat'], (float) $order['lng']), 1);
        }
        $stale = $ageMin > $staleMinutes;
        $where = $km === null ? 'المسافة غير معروفة' : 'على بُعد نحو ' . $km . ' كم من موقع الخدمة';
        return [
            'available' => true,
            'stale' => $stale,
            'age_minutes' => $ageMin,
            'km' => $km,
            'text' => 'آخر موقع فعلي للفني ' . $where . '، آخر تحديث قبل ' . $ageMin . ' دقيقة'
                . ($stale ? ' (قديم — البيانات غير كافية للتأكيد)' : '') . '.',
        ];
    }
}
