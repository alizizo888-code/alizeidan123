<?php
declare(strict_types=1);

/**
 * Site registry and the server-to-server bridge client.
 * Browsers never talk to WordPress directly: this class runs on the master server only,
 * so there is no CORS and no cross-site cookie involved.
 */
final class Sites
{
    public static function seed(): int
    {
        $pdo = oxy_pdo();
        $seed = json_decode((string) file_get_contents(OXY_ROOT . '/seed/sites.json'), true, 512, JSON_THROW_ON_ERROR);
        $added = 0;
        foreach ($seed as $site) {
            $check = $pdo->prepare('SELECT id FROM sites WHERE id = ?');
            $check->execute([$site['id']]);
            if ($check->fetchColumn() === false) {
                $pdo->prepare('INSERT INTO sites (id, name, host, status) VALUES (?, ?, ?, ?)')
                    ->execute([$site['id'], $site['name'], $site['host'], 'unpaired']);
                $added++;
            }
        }
        return $added;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return oxy_pdo()->query(
            'SELECT id, name, host, status, last_checked, last_info, last_error, secret_enc IS NOT NULL AS paired FROM sites ORDER BY id'
        )->fetchAll();
    }

    /** The face with this host name, or a 401 so an unknown host learns nothing. */
    public static function byHost(string $host): array
    {
        $stmt = oxy_pdo()->prepare('SELECT id FROM sites WHERE host = ?');
        $stmt->execute([$host]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new OxyHttpError(401, 'bad_signature');
        }
        return self::find((string) $row['id']);
    }

    public static function find(string $id): array
    {
        $stmt = oxy_pdo()->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new OxyHttpError(404, 'site_not_found');
        }
        return $row;
    }

    public static function pair(string $id, string $secret): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $secret)) {
            throw new OxyHttpError(400, 'secret_format');
        }
        self::find($id);
        oxy_pdo()->prepare('UPDATE sites SET secret_enc = ?, status = ?, last_error = NULL WHERE id = ?')
            ->execute([Vault::seal($secret), 'unknown', $id]);
        Audit::log('site_paired', $id);
    }

    /** Signed request to the site's bridge. Returns a uniform result array, never throws on HTTP errors. */
    public static function call(array $site, string $method, string $route, ?array $body = null): array
    {
        if (empty($site['secret_enc'])) {
            return ['ok' => false, 'code' => 0, 'error' => 'unpaired'];
        }
        $secret = Vault::open((string) $site['secret_enc']);
        $payload = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sig = Hmac::sign($secret, $method, $route, $ts, $nonce, $payload);

        $ch = curl_init('https://' . $site['host'] . '/wp-json' . $route);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Oxy-Timestamp: ' . $ts,
                'X-Oxy-Nonce: ' . $nonce,
                'X-Oxy-Signature: ' . $sig,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'OxygenMaster/1.0',
        ];
        if ($payload !== '') {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'code' => 0, 'error' => 'network:' . substr($err, 0, 160)];
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            // A WAF or a maintenance page answers with HTML: that is the signal to tell apart from a WordPress 403.
            return ['ok' => false, 'code' => $code, 'error' => 'non_json:' . $code];
        }
        $good = $code >= 200 && $code < 300 && ($json['ok'] ?? false) === true;
        return [
            'ok' => $good,
            'code' => $code,
            'data' => $json,
            'error' => $good ? null : ('http_' . $code . ':' . (string) ($json['code'] ?? 'unknown')),
        ];
    }

    public static function health(string $id): array
    {
        $site = self::find($id);
        if (empty($site['secret_enc'])) {
            self::mark($id, 'unpaired', null, 'unpaired');
            return ['ok' => false, 'error' => 'unpaired'];
        }
        $result = self::call($site, 'GET', '/oxy-bridge/v1/status');
        if ($result['ok']) {
            self::mark($id, 'online', (string) json_encode($result['data'], JSON_UNESCAPED_UNICODE), null);
        } else {
            self::mark($id, 'down', null, substr((string) $result['error'], 0, 250));
        }
        return $result;
    }

    public static function purge(string $id): array
    {
        $site = self::find($id);
        $result = self::call($site, 'POST', '/oxy-bridge/v1/purge', ['scope' => 'all']);
        if ($result['ok']) {
            Audit::log('purge_ok', $id, implode(',', $result['data']['purged'] ?? []));
        }
        return $result;
    }

    private static function mark(string $id, string $status, ?string $info, ?string $error): void
    {
        oxy_pdo()->prepare('UPDATE sites SET status = ?, last_checked = ?, last_info = COALESCE(?, last_info), last_error = ? WHERE id = ?')
            ->execute([$status, time(), $info, $error, $id]);
    }
}
