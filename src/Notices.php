<?php
declare(strict_types=1);

/**
 * Things somebody needs to be told, and whether they should hear it.
 *
 * The operations room is a screen nobody stares at. A technician saying "معتذر، مش هقدر آخد الطلب"
 * that only appears as a quietly changed row is a customer left waiting while everyone assumes
 * somebody is on the way. So a notice that matters rings, and the screen that is open plays it.
 *
 * What rings is decided here and not on the screen, because a decision made in one place can be
 * reasoned about, and the same event must not ring on one screen and pass silently on another.
 */
final class Notices
{
    /** How many a screen is handed at once. Older ones are still in the table, just not in the way. */
    public const PAGE = 30;

    /** A notice stops being worth ringing about after this long: a stale alarm is noise. */
    public const RING_WINDOW = 1800;

    /**
     * Raises a notice.
     *
     * @param string $about what kind of thing this is about ('order', 'technician', 'site')
     * @param string $subject the id of that thing, so a screen can open it
     * @param string $title a few words, which is all a person reads before deciding to look
     * @param string $body the sentence under it
     * @param bool $urgent whether this should make a sound rather than wait to be noticed
     */
    public static function raise(string $about, string $subject, string $title, string $body = '', bool $urgent = false): array
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 80) {
            throw new OxyHttpError(400, 'bad_notice');
        }
        if (mb_strlen($body) > 300) {
            throw new OxyHttpError(400, 'bad_notice');
        }
        $id = oxy_id();
        oxy_pdo()->prepare('INSERT INTO notices (id, about, subject, title, body, urgent, created, seen) VALUES (?, ?, ?, ?, ?, ?, ?, 0)')
            ->execute([$id, $about, $subject, $title, $body, $urgent ? 1 : 0, time()]);
        return ['id' => $id, 'about' => $about, 'subject' => $subject, 'title' => $title, 'body' => $body, 'urgent' => $urgent, 'created' => time()];
    }

    /**
     * The notices a screen should show, newest first.
     *
     * 'ring' is the screen's instruction: play a sound for this one. It is true only for an urgent
     * notice that nobody has seen and that is recent enough to still matter — a screen opened in the
     * morning must not play an hour of alarms from the night.
     */
    public static function list(int $limit = self::PAGE): array
    {
        $limit = max(1, min(100, $limit));
        $rows = oxy_pdo()->query('SELECT * FROM notices ORDER BY created DESC, id DESC LIMIT ' . $limit)->fetchAll();
        $cut = time() - self::RING_WINDOW;
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'about' => $r['about'],
            'subject' => $r['subject'],
            'title' => $r['title'],
            'body' => $r['body'],
            'urgent' => (int) $r['urgent'] === 1,
            'seen' => (int) $r['seen'] === 1,
            'created' => (int) $r['created'],
            'ring' => (int) $r['urgent'] === 1 && (int) $r['seen'] === 0 && (int) $r['created'] >= $cut,
        ], $rows);
    }

    public static function unseen(): int
    {
        return (int) oxy_pdo()->query('SELECT COUNT(*) FROM notices WHERE seen = 0')->fetchColumn();
    }

    /** Marks notices as seen, so they stop ringing. */
    public static function markSeen(array $ids = []): array
    {
        $pdo = oxy_pdo();
        if ($ids === []) {
            $pdo->query('UPDATE notices SET seen = 1 WHERE seen = 0');
            return ['ok' => true, 'unseen' => 0];
        }
        $ids = array_slice(array_values(array_filter($ids, 'is_string')), 0, 100);
        if ($ids === []) {
            return ['ok' => true, 'unseen' => self::unseen()];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare('UPDATE notices SET seen = 1 WHERE id IN (' . $marks . ')')->execute($ids);
        return ['ok' => true, 'unseen' => self::unseen()];
    }
}
