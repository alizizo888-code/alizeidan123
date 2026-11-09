<?php
declare(strict_types=1);

/**
 * What a technician says back, in one tap.
 *
 * A technician is on a ladder, in a hot roof space, or driving. Typing a sentence is not going to
 * happen, so the things they need to say most often are buttons. Each one does three things at
 * once: it writes a line on the order so the history is kept, it sets the order's state where that
 * state follows from what was said, and it hands the order back to operations when the technician
 * is stepping away from it.
 *
 * The wording is the technician's own voice, because the line lands in a conversation the operations
 * room reads, and "معلش اتأخرت" from a person reads differently from "STATUS: DELAYED".
 */
final class Replies
{
    /**
     * Each reply: what the button says, the line it writes, the status it implies (or null to leave
     * the status alone), whether it hands the order back to operations, and whether a note is needed.
     *
     * A reply that takes the technician off the job always goes back to operations: a job nobody is
     * holding is a job that gets forgotten, and the whole point of the chain is that someone has it.
     */
    public const REPLIES = [
        'on_my_way' => [
            'label' => 'في الطريق ليك',
            'says' => 'أنا في الطريق لك.',
            'status' => 'en_route',
            'hand_back' => false,
            'needs_note' => false,
        ],
        'nearby' => [
            'label' => 'قرّبت',
            'says' => 'قرّبت على موقعك.',
            'status' => 'nearby',
            'hand_back' => false,
            'needs_note' => false,
        ],
        'arrived' => [
            'label' => 'وصلت',
            'says' => 'وصلت لموقعك.',
            'status' => 'arrived',
            'hand_back' => false,
            'needs_note' => false,
        ],
        'started' => [
            'label' => 'بدأت الشغل',
            'says' => 'بدأت الشغل.',
            'status' => 'in_progress',
            'hand_back' => false,
            'needs_note' => false,
        ],
        'running_late' => [
            'label' => 'هتأخر شوية',
            'says' => 'هتأخر شوية عن الموعد.',
            'status' => null,
            'hand_back' => false,
            // How long is the whole point of saying it, so the note is not optional.
            'needs_note' => true,
        ],
        'need_parts' => [
            'label' => 'ناقص قطع غيار',
            'says' => 'الشغل محتاج قطع غيار مش معايا.',
            'status' => null,
            'hand_back' => true,
            'needs_note' => true,
        ],
        'postpone' => [
            'label' => 'محتاج تأجيل',
            'says' => 'محتاج أأجّل الزيارة.',
            'status' => null,
            'hand_back' => true,
            'needs_note' => true,
        ],
        'cannot_take' => [
            'label' => 'معتذر عن الطلب',
            'says' => 'معتذر، مش هقدر آخد الطلب ده.',
            'status' => null,
            'hand_back' => true,
            'needs_note' => true,
        ],
        'customer_not_there' => [
            'label' => 'العميل مش موجود',
            'says' => 'جيت ومحدش موجود في الموقع.',
            'status' => null,
            'hand_back' => true,
            'needs_note' => false,
        ],
        'explained' => [
            'label' => 'شرحت للعميل',
            'says' => 'شرحت المشكلة للعميل.',
            'status' => null,
            'hand_back' => false,
            'needs_note' => true,
        ],
        'back_to_ops' => [
            'label' => 'رجّع للعمليات',
            'says' => 'محتاج العمليات تتدخل.',
            'status' => null,
            'hand_back' => true,
            'needs_note' => true,
        ],
    ];

    /** What the technician's phone should offer, with no internals in it. */
    public static function all(): array
    {
        $out = [];
        foreach (self::REPLIES as $id => $r) {
            $out[] = ['id' => $id, 'label' => $r['label'], 'needs_note' => $r['needs_note'], 'hand_back' => $r['hand_back']];
        }
        return $out;
    }

    /**
     * Sends one reply on a job. Everything it does happens together, so the order can never end up
     * with a status that nothing in the conversation explains.
     */
    public static function send(string $personId, string $orderId, string $replyId, string $note = ''): array
    {
        if (!isset(self::REPLIES[$replyId])) {
            throw new OxyHttpError(400, 'bad_reply');
        }
        $reply = self::REPLIES[$replyId];
        $order = Orders::find($orderId);
        if ((string) ($order['technician_id'] ?? '') !== $personId) {
            throw new OxyHttpError(403, 'not_your_job');
        }
        if (in_array($order['status'], ['completed', 'cancelled'], true)) {
            throw new OxyHttpError(409, 'job_closed');
        }
        $note = trim($note);
        if ($reply['needs_note'] && $note === '') {
            throw new OxyHttpError(400, 'note_required');
        }
        if (mb_strlen($note) > 300 || preg_match('/[<>]/', $note) === 1) {
            throw new OxyHttpError(400, 'bad_note');
        }

        $line = $reply['says'] . ($note === '' ? '' : ' — ' . $note);
        Messages::add($orderId, $line, 'technician');

        $changes = [];
        if ($reply['status'] !== null && $order['status'] !== $reply['status']) {
            $changes['status'] = $reply['status'];
        }
        if ($reply['hand_back']) {
            // Stepping away from a job hands it back: a job nobody holds is a job that gets forgotten.
            $changes['handler'] = 'operations';
        }
        $updated = $changes === [] ? $order : Orders::update($orderId, $changes);

        // Operations should hear about the ones that need them, not about every tap.
        if ($reply['hand_back']) {
            Notices::raise('order', $orderId, $reply['label'], $line, true);
        }
        Audit::log('tech_reply', $orderId, $personId . ':' . $replyId);
        return ['order' => $updated, 'said' => $line, 'handed_back' => $reply['hand_back']];
    }
}
