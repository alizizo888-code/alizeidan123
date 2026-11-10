<?php
declare(strict_types=1);

/**
 * The technician's own way in: one link the owner sends them, tapped once on their phone.
 *
 * A technician is out on a job with one hand free. They will not type a password, and they must
 * never be handed the owner's PIN, which opens the whole desk. The desk has no SMS or WhatsApp
 * gateway of its own, so inventing a code-by-text flow here would only invent a way for it to fail.
 * Instead the owner — who is already talking to the technician — presses a button, gets a link, and
 * sends it however they already talk. The technician taps it and that phone stays signed in.
 *
 * What a technician session can NEVER do, by construction rather than by a check someone might
 * forget: see another technician's jobs, see a customer who is not on one of their jobs, change a
 * price, or reach any screen the owner uses. The desk's own Auth is never consulted for these routes.
 */
final class Tech
{
    public const COOKIE = 'oxy_t';

    /** A working phone should not sign out in the middle of a job. */
    public const SESSION_SECONDS = 7776000;   // 90 days

    /** An unused invitation stops working. A link forwarded by mistake should not stay live for ever. */
    public const INVITE_SECONDS = 172800;     // 48 hours

    /**
     * The steps a technician moves a job through, in order, each the next thing they would actually
     * say out loud. These are the chain's own states, so the customer's screen, the operations room
     * and the technician's phone always agree on where the job is.
     */
    public const STEPS = [
        'accepted' => 'قبلت الطلب',
        'en_route' => 'في الطريق',
        'arrived' => 'وصلت',
        'in_progress' => 'بدأت الشغل',
        'completed' => 'خلصت',
    ];

    /** The jobs a technician is shown: the ones still in their hands. */
    public const OPEN_STATUSES = Workflow::WITH_TECHNICIAN;

    /**
     * A technician signing themselves in, the first time, with the number the owner already wrote down.
     *
     * The owner adds a technician by name and phone before that person opens anything, so the number
     * on the list is already the owner saying this person works here. Matching it once, on one phone,
     * is enough — and asking for more is how every earlier attempt ended with people locked out of
     * their own work.
     *
     * It works ONCE per person. Once a phone is registered the number alone opens nothing, so anyone
     * who learns it later finds a door already shut. Every registration is in the audit, and the
     * owner can take a phone off at any time, which opens the door again for a genuine replacement.
     */
    public static function register(string $phone): array
    {
        $phone = self::normalise($phone);
        if (!preg_match('/^\d{9,15}$/', $phone)) {
            throw new OxyHttpError(400, 'bad_phone');
        }
        $person = self::findByPhone($phone);
        if ($person === null) {
            // Logged without the full number, and answered the same way whoever asks, so this cannot
            // be used to find out which numbers belong to technicians.
            Audit::log('tech_register_unknown', substr($phone, -4), '');
            throw new OxyHttpError(403, 'not_on_the_list');
        }
        if (self::hasDevice($person['id'])) {
            throw new OxyHttpError(409, 'already_registered');
        }
        $token = bin2hex(random_bytes(32));
        oxy_pdo()->prepare('INSERT INTO tech_sessions (token_hash, person_id, created, expires) VALUES (?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $person['id'], time(), time() + self::SESSION_SECONDS]);
        self::setCookie($token, time() + self::SESSION_SECONDS);
        Audit::log('tech_register', $person['id'], $person['name']);
        return ['ok' => true, 'technician' => ['id' => $person['id'], 'name' => $person['name']]];
    }

    /** Whether this technician already has a phone on the system, so the owner can see who does not. */
    public static function hasDevice(string $personId): bool
    {
        $stmt = oxy_pdo()->prepare('SELECT COUNT(*) FROM tech_sessions WHERE person_id = ?');
        $stmt->execute([$personId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** The technician who owns this number, matched on the last nine digits so the format never matters. */
    private static function findByPhone(string $phone): ?array
    {
        $tail = substr($phone, -9);
        if (strlen($tail) < 9) {
            return null;
        }
        foreach (People::list('technician') as $p) {
            $digits = preg_replace('/\D/', '', (string) ($p['phone'] ?? '')) ?? '';
            if ($digits !== '' && substr($digits, -9) === $tail) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Makes a one-time sign-in link for a technician. Owner only.
     *
     * The secret is returned once and never stored: only its hash is kept, so a copy of the database
     * is not a pile of working invitations. Issuing a new one cancels any earlier unused invitation,
     * which is what an owner expects when they press the button a second time.
     */
    public static function invite(string $personId): array
    {
        $person = People::find($personId);
        if ($person['role'] !== 'technician') {
            throw new OxyHttpError(400, 'not_technician');
        }
        $pdo = oxy_pdo();
        $pdo->prepare('DELETE FROM tech_invites WHERE person_id = ?')->execute([$personId]);
        $secret = bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO tech_invites (token_hash, person_id, created, expires) VALUES (?, ?, ?, ?)')
            ->execute([hash('sha256', $secret), $personId, time(), time() + self::INVITE_SECONDS]);
        Audit::log('tech_invite', $personId, $person['name']);
        return [
            'person_id' => $personId,
            'name' => $person['name'],
            'path' => '/t/#' . $secret,
            'expires_in_hours' => (int) (self::INVITE_SECONDS / 3600),
        ];
    }

    /** Turns a tapped invitation into a session on that phone. The invitation is spent either way. */
    public static function claim(string $secret): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $secret)) {
            throw new OxyHttpError(401, 'bad_invite');
        }
        $pdo = oxy_pdo();
        $stmt = $pdo->prepare('SELECT person_id FROM tech_invites WHERE token_hash = ? AND expires > ?');
        $stmt->execute([hash('sha256', $secret), time()]);
        $personId = $stmt->fetchColumn();
        if ($personId === false) {
            throw new OxyHttpError(401, 'bad_invite');
        }
        $pdo->prepare('DELETE FROM tech_invites WHERE token_hash = ?')->execute([hash('sha256', $secret)]);

        $person = People::find((string) $personId);
        if ($person['role'] !== 'technician') {
            throw new OxyHttpError(403, 'not_technician');
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO tech_sessions (token_hash, person_id, created, expires) VALUES (?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $person['id'], time(), time() + self::SESSION_SECONDS]);
        self::setCookie($token, time() + self::SESSION_SECONDS);
        Audit::log('tech_signin', $person['id'], '');
        return ['ok' => true, 'technician' => ['id' => $person['id'], 'name' => $person['name']]];
    }

    /** The technician this request belongs to. */
    public static function current(): array
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || $token === '') {
            throw new OxyHttpError(401, 'no_tech_session');
        }
        $stmt = oxy_pdo()->prepare('SELECT person_id FROM tech_sessions WHERE token_hash = ? AND expires > ?');
        $stmt->execute([hash('sha256', $token), time()]);
        $personId = $stmt->fetchColumn();
        if ($personId === false) {
            throw new OxyHttpError(401, 'no_tech_session');
        }
        $person = People::find((string) $personId);
        // Someone whose job here ended keeps no way in, even with a live session on their phone.
        if ($person['role'] !== 'technician') {
            throw new OxyHttpError(403, 'not_technician');
        }
        return $person;
    }

    public static function signOut(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($token) && $token !== '') {
            oxy_pdo()->prepare('DELETE FROM tech_sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        }
        self::setCookie('', time() - 3600);
    }

    /** Ends every session a technician has, on every phone. For when one is lost or someone leaves. */
    public static function revoke(string $personId): array
    {
        $pdo = oxy_pdo();
        $pdo->prepare('DELETE FROM tech_sessions WHERE person_id = ?')->execute([$personId]);
        $pdo->prepare('DELETE FROM tech_invites WHERE person_id = ?')->execute([$personId]);
        Audit::log('tech_revoke', $personId, '');
        return ['ok' => true];
    }

    /**
     * This technician's own jobs, and only those. The person comes from the session, never from the
     * request, so there is no id for anyone to tamper with.
     */
    public static function myJobs(string $personId): array
    {
        $in = implode(',', array_map(static fn (string $s): string => oxy_pdo()->quote($s), self::OPEN_STATUSES));
        $stmt = oxy_pdo()->prepare(
            // Where the job is lives on the customer, not the order: one customer, one address.
            'SELECT o.id, o.service, o.status, o.price, o.created,
                    c.name AS customer_name, c.phone AS customer_phone,
                    c.address AS address, c.lat AS lat, c.lng AS lng
               FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
              WHERE o.technician_id = ? AND o.status IN (' . $in . ')
              ORDER BY o.created ASC'
        );
        $stmt->execute([$personId]);
        $jobs = [];
        foreach ($stmt->fetchAll() as $row) {
            $jobs[] = [
                'id' => $row['id'],
                'service' => $row['service'],
                'status' => $row['status'],
                'status_label' => Orders::STATUSES[$row['status']] ?? $row['status'],
                'price' => $row['price'] === null ? null : (int) $row['price'],
                'address' => $row['address'] ?? '',
                'lat' => $row['lat'] === null ? null : (float) $row['lat'],
                'lng' => $row['lng'] === null ? null : (float) $row['lng'],
                // The customer's number appears on the job this technician is actually doing, nowhere else.
                'customer' => ['name' => $row['customer_name'] ?? '', 'phone' => $row['customer_phone'] ?? ''],
                'created' => (int) $row['created'],
            ];
        }
        return $jobs;
    }

    /**
     * Moves one job along. A technician may only touch their own, and only forward: a finished job
     * cannot be reopened from a phone, because the earning that follows it has already been written.
     */
    public static function step(string $personId, string $orderId, string $step): array
    {
        if (!isset(self::STEPS[$step])) {
            throw new OxyHttpError(400, 'bad_step');
        }
        $order = Orders::find($orderId);
        if ((string) ($order['technician_id'] ?? '') !== $personId) {
            throw new OxyHttpError(403, 'not_your_job');
        }
        if (in_array($order['status'], ['completed', 'cancelled'], true)) {
            throw new OxyHttpError(409, 'job_closed');
        }
        $updated = Orders::update($orderId, ['status' => $step]);
        Audit::log('tech_step', $orderId, $personId . ':' . $step);
        return $updated;
    }

    /** Where the technician is now, so the live map and the nearest-first rule mean something. */
    public static function whereIAm(string $personId, mixed $lat, mixed $lng): array
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            throw new OxyHttpError(400, 'bad_location');
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new OxyHttpError(400, 'bad_location');
        }
        Locations::record($personId, $lat, $lng);
        return ['ok' => true];
    }

    /**
     * Digits only, with the Saudi local form folded in, so one person is one number however they
     * type it: 05xxxxxxxx, 9665xxxxxxxx and +966 5xx xxx xxx are all the same technician.
     */
    private static function normalise(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (preg_match('/^05\d{8}$/', $d)) {
            return '966' . substr($d, 1);
        }
        if (preg_match('/^5\d{8}$/', $d)) {
            return '966' . $d;
        }
        return $d;
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
