<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

/** Routes: [METHOD, path] => handler. Every write requires an owner session with a CSRF token,
 *  except setup (guarded by the setup token) and tick (guarded by the cron token). */
function oxy_dispatch(string $method, string $route): void
{
    $parts = explode('/', $route);

    if ($method === 'GET' && $route === 'health') {
        // Before the first install there are no tables yet: report that instead of failing.
        try {
            $installed = Settings::get('installed') === '1';
            $pinSet = Auth::pinIsSet();
        } catch (PDOException $e) {
            $installed = false;
            $pinSet = false;
        }
        oxy_json(['ok' => true, 'installed' => $installed, 'pin_set' => $pinSet]);
        return;
    }

    if ($method === 'POST' && $route === 'install') {
        oxy_require_token('X-Setup-Token', (string) oxy_config()['setup_token']);
        oxy_schema();
        Settings::set('installed', '1');
        oxy_json(['ok' => true, 'sites_added' => Sites::seed()]);
        return;
    }

    if ($method === 'POST' && $route === 'setup-pin') {
        oxy_require_token('X-Setup-Token', (string) oxy_config()['setup_token']);
        if (Auth::pinIsSet()) {
            throw new OxyHttpError(409, 'pin_already_set');
        }
        Auth::setPin((string) (oxy_body()['pin'] ?? ''));
        oxy_json(['ok' => true]);
        return;
    }

    if ($method === 'POST' && $route === 'login') {
        $session = Auth::login((string) (oxy_body()['pin'] ?? ''));
        oxy_json(['ok' => true] + $session);
        return;
    }

    if ($method === 'POST' && $route === 'logout') {
        Auth::logout();
        oxy_json(['ok' => true]);
        return;
    }

    if ($method === 'GET' && $route === 'session') {
        oxy_json(['ok' => true, 'csrf' => Auth::current()]);
        return;
    }

    if ($method === 'GET' && $route === 'overview') {
        $csrf = Auth::current();
        $sites = array_map(static function (array $s): array {
            $s['info'] = $s['last_info'] ? json_decode((string) $s['last_info'], true) : null;
            unset($s['last_info']);
            $s['paired'] = (bool) $s['paired'];
            return $s;
        }, Sites::all());
        oxy_json([
            'ok' => true,
            'csrf' => $csrf,
            'sites' => $sites,
            'queue' => Jobs::counts(),
            'jobs' => Jobs::recent(30),
            'audit' => Audit::recent(40),
        ]);
        return;
    }

    if ($method === 'POST' && $route === 'jobs') {
        Auth::requireCsrf();
        $body = oxy_body();
        $type = (string) ($body['type'] ?? '');
        $queued = 0;
        if ($type === 'health_all' || $type === 'purge_all') {
            $queued = Jobs::enqueueForAll($type === 'health_all' ? 'health' : 'purge');
        } elseif (in_array($type, ['health', 'purge'], true)) {
            $siteId = (string) ($body['site_id'] ?? '');
            Sites::find($siteId);
            Jobs::enqueue($type, $siteId);
            $queued = 1;
        } else {
            throw new OxyHttpError(400, 'bad_job_type');
        }
        Audit::log('jobs_' . $type, (string) ($body['site_id'] ?? 'all'), 'queued=' . $queued);
        // Run a small batch right away so the owner sees progress without waiting for cron.
        $summary = Jobs::tick(5);
        oxy_json(['ok' => true, 'queued' => $queued, 'batch' => $summary]);
        return;
    }

    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'sites' && $parts[2] === 'pair') {
        Auth::requireCsrf();
        Sites::pair($parts[1], (string) (oxy_body()['secret'] ?? ''));
        oxy_json(['ok' => true]);
        return;
    }

    if ($method === 'POST' && $route === 'tick') {
        oxy_require_token('X-Cron-Token', (string) oxy_config()['cron_token']);
        oxy_json(['ok' => true] + Jobs::tick(10));
        return;
    }

    throw new OxyHttpError(404, 'not_found');
}

function oxy_require_token(string $header, string $expected): void
{
    $sent = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '';
    if (strlen($expected) < 32 || !hash_equals($expected, (string) $sent)) {
        throw new OxyHttpError(403, 'forbidden');
    }
}

function oxy_schema(): void
{
    $sql = (string) file_get_contents(OXY_ROOT . '/sql/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql) ?? '';
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        oxy_pdo()->exec($statement);
    }
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    oxy_dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', trim((string) ($_GET['r'] ?? ''), '/'));
} catch (OxyHttpError $e) {
    oxy_json(['ok' => false, 'error' => $e->errCode], $e->status);
} catch (InvalidArgumentException $e) {
    oxy_json(['ok' => false, 'error' => 'bad_request'], 400);
} catch (Throwable $e) {
    error_log('oxy-master: ' . $e->getMessage());
    oxy_json(['ok' => false, 'error' => 'server_error'], 500);
}
