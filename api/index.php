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
        Settings::set('schema', OXY_SCHEMA_VERSION);
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

    // Articles of one face, read through its bridge. A failure says who has to act, like bulk results.
    if ($method === 'GET' && count($parts) === 3 && $parts[0] === 'sites' && $parts[2] === 'posts') {
        Auth::current();
        $site = Sites::find($parts[1]);
        $result = Sites::call($site, 'GET', '/oxy-bridge/v1/posts');
        if (!$result['ok']) {
            oxy_json(['ok' => false, 'name' => $site['name'], 'error' => Bulk::explain((string) $result['error']), 'fix' => Bulk::fix((string) $result['error'])]);
            return;
        }
        $posts = $result['data']['posts'] ?? [];
        oxy_json(['ok' => true, 'name' => $site['name'], 'count' => count($posts), 'posts' => $posts]);
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

    // Management: supervisors (and technicians when their role is added in People::ROLES).
    if ($method === 'GET' && $route === 'management/people') {
        Auth::current();
        $role = $_GET['role'] ?? 'supervisor';
        oxy_json(['ok' => true, 'people' => People::list(is_string($role) ? $role : '')]);
        return;
    }

    if ($method === 'POST' && $route === 'management/people') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'person' => People::create(oxy_body())], 201);
        return;
    }

    // A technician's own share of the job price. Body: {"share_pct": 0-100} or null to use the default.
    if ($method === 'POST' && count($parts) === 4 && $parts[0] === 'management' && $parts[1] === 'people' && $parts[3] === 'share') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'share' => Dispatch::setShare($parts[2], oxy_body()['share_pct'] ?? null)]);
        return;
    }

    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'management' && $parts[1] === 'people') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'person' => People::update($parts[2], oxy_body())]);
        return;
    }

    // Operations reports: technician accounts and earnings, and warranties on finished jobs.
    if ($method === 'GET' && $route === 'accounts') {
        Auth::current();
        oxy_json(['ok' => true, 'technicians' => Reports::accounts()]);
        return;
    }

    if ($method === 'GET' && $route === 'warranties') {
        Auth::current();
        oxy_json(['ok' => true, 'warranties' => Reports::warranties()]);
        return;
    }

    // Chat and WhatsApp: the assistant's business details, instructions and services (no WhatsApp secrets here).
    if ($method === 'GET' && $route === 'settings/chat') {
        Auth::current();
        oxy_json(['ok' => true, 'values' => Chat::all()]);
        return;
    }

    if ($method === 'POST' && $route === 'settings/chat') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'values' => Chat::save(oxy_body())]);
        return;
    }

    // Dispatch and intake: how new orders get a technician, whether faces may send orders, default share and warranty.
    if ($method === 'GET' && $route === 'settings/dispatch') {
        Auth::current();
        oxy_json(['ok' => true, 'values' => Dispatch::all(), 'shares' => Dispatch::shares()]);
        return;
    }

    if ($method === 'POST' && $route === 'settings/dispatch') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'values' => Dispatch::save(oxy_body())]);
        return;
    }

    // General settings: only the keys listed in General::FIELDS.
    if ($method === 'GET' && $route === 'settings/general') {
        Auth::current();
        oxy_json(['ok' => true, 'values' => General::all()]);
        return;
    }

    if ($method === 'POST' && $route === 'settings/general') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'values' => General::save(oxy_body())]);
        return;
    }

    // Bulk settings: fields the faces accept, then preview or apply for up to Bulk::MAX_BATCH faces.
    if ($method === 'GET' && $route === 'bulk/settings') {
        Auth::current();
        oxy_json(['ok' => true, 'fields' => Bulk::FIELDS, 'max_batch' => Bulk::MAX_BATCH]);
        return;
    }

    if ($method === 'POST' && $route === 'bulk/settings') {
        Auth::requireCsrf();
        // Each face call can take a few seconds; a full batch of faces can run past the default limit.
        set_time_limit(120);
        $body = oxy_body();
        $ids = $body['site_ids'] ?? null;
        $values = $body['values'] ?? null;
        if (!is_array($ids) || !is_array($values)) {
            throw new OxyHttpError(400, 'bad_request');
        }
        $ids = array_map(static fn ($id): string => is_string($id) ? $id : '', $ids);
        // Preview is the default: a change is only applied when the panel sends dry_run=false explicitly.
        $dryRun = ($body['dry_run'] ?? true) !== false;
        $results = Bulk::run(array_values($ids), $values, $dryRun);
        Audit::log($dryRun ? 'bulk_settings_preview' : 'bulk_settings_batch', null, 'faces=' . count($ids));
        oxy_json(['ok' => true, 'dry_run' => $dryRun, 'results' => $results]);
        return;
    }

    // Bulk operations (health check, cache purge): same preview-first rules as bulk settings.
    if ($method === 'GET' && $route === 'bulk/operations') {
        Auth::current();
        oxy_json(['ok' => true, 'operations' => BulkOps::OPS, 'max_batch' => Bulk::MAX_BATCH]);
        return;
    }

    if ($method === 'POST' && $route === 'bulk/operations') {
        Auth::requireCsrf();
        set_time_limit(120);
        $body = oxy_body();
        $ids = $body['site_ids'] ?? null;
        $op = $body['op'] ?? null;
        if (!is_array($ids) || !is_string($op)) {
            throw new OxyHttpError(400, 'bad_request');
        }
        $ids = array_map(static fn ($id): string => is_string($id) ? $id : '', $ids);
        $dryRun = ($body['dry_run'] ?? true) !== false;
        $results = BulkOps::run($op, array_values($ids), $dryRun);
        Audit::log($dryRun ? 'bulk_op_preview' : 'bulk_op_batch', null, $op . ' faces=' . count($ids));
        oxy_json(['ok' => true, 'op' => $op, 'dry_run' => $dryRun, 'results' => $results]);
        return;
    }

    // Customers: who they are, where they are, their tier, rating and last agreed price.
    if ($method === 'GET' && $route === 'customers') {
        Auth::current();
        oxy_json(['ok' => true, 'customers' => Customers::list(), 'tiers' => Customers::TIERS]);
        return;
    }

    if ($method === 'POST' && $route === 'customers') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'customer' => Customers::create(oxy_body())], 201);
        return;
    }

    if ($method === 'POST' && count($parts) === 2 && $parts[0] === 'customers') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'customer' => Customers::update($parts[1], oxy_body())]);
        return;
    }

    // Orders: active ones for the map and the board, or all of them.
    if ($method === 'GET' && $route === 'orders') {
        Auth::current();
        $filter = $_GET['filter'] ?? null;
        oxy_json(['ok' => true, 'orders' => Orders::list(is_string($filter) ? $filter : null), 'statuses' => Orders::STATUSES]);
        return;
    }

    if ($method === 'POST' && $route === 'orders') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'order' => Orders::create(oxy_body())], 201);
        return;
    }

    if ($method === 'POST' && count($parts) === 2 && $parts[0] === 'orders') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'order' => Orders::update($parts[1], oxy_body())]);
        return;
    }

    // Map: last known position of every active technician, and a position report (from the technician's app or by hand).
    if ($method === 'GET' && $route === 'map/live') {
        Auth::current();
        oxy_json([
            'ok' => true,
            'areas' => Locations::AREAS,
            'technicians' => Locations::live(),
            'customers' => array_values(array_filter(Customers::list(), static fn (array $c): bool => $c['lat'] !== null)),
            'orders' => array_values(array_filter(Orders::list('active'), static fn (array $o): bool => $o['lat'] !== null)),
        ]);
        return;
    }

    if ($method === 'POST' && $route === 'map/location') {
        Auth::requireCsrf();
        $body = oxy_body();
        $saved = Locations::record((string) ($body['person_id'] ?? ''), $body['lat'] ?? null, $body['lng'] ?? null);
        oxy_json(['ok' => true, 'location' => $saved]);
        return;
    }

    if ($method === 'GET' && count($parts) === 3 && $parts[0] === 'orders' && $parts[2] === 'messages') {
        Auth::current();
        oxy_json(['ok' => true, 'messages' => Messages::list($parts[1])]);
        return;
    }

    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'orders' && $parts[2] === 'messages') {
        Auth::requireCsrf();
        $body = oxy_body();
        oxy_json(['ok' => true, 'message' => Messages::add($parts[1], $body['body'] ?? null)], 201);
        return;
    }

    if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'orders' && $parts[2] === 'distribute') {
        Auth::requireCsrf();
        oxy_json(['ok' => true, 'order' => Orders::distribute($parts[1], (string) (oxy_body()['mode'] ?? ''))]);
        return;
    }

    // Intake: a face website sends a confirmed order. Signed with the key paired for that face, no owner session.
    if ($method === 'POST' && $route === 'intake/order') {
        $raw = (string) file_get_contents('php://input');
        $host = strtolower(trim((string) ($_SERVER['HTTP_X_OXY_HOST'] ?? '')));
        if (!preg_match('/^[a-z0-9.-]{3,190}$/', $host)) {
            throw new OxyHttpError(401, 'bad_signature');
        }
        $siteId = (string) Sites::byHost($host)['id'];
        Intake::verifySite($siteId, 'POST', '/intake/order', $raw, [
            'X-Oxy-Timestamp' => $_SERVER['HTTP_X_OXY_TIMESTAMP'] ?? '',
            'X-Oxy-Nonce' => $_SERVER['HTTP_X_OXY_NONCE'] ?? '',
            'X-Oxy-Signature' => $_SERVER['HTTP_X_OXY_SIGNATURE'] ?? '',
        ]);
        oxy_json(['ok' => true] + Intake::receive($siteId, oxy_body()), 201);
        return;
    }

    throw new OxyHttpError(404, 'not_found');
}

/** Creates tables added after the first install. Idempotent, and it runs once per schema version. */
function oxy_upgrade(): void
{
    try {
        if (Settings::get('installed') !== '1' || Settings::get('schema') === OXY_SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException $e) {
        return; // Fresh database: the install route creates the tables.
    }
    oxy_schema();
    Settings::set('schema', OXY_SCHEMA_VERSION);
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
    oxy_upgrade();
    oxy_dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', trim((string) ($_GET['r'] ?? ''), '/'));
} catch (OxyHttpError $e) {
    oxy_json(['ok' => false, 'error' => $e->errCode], $e->status);
} catch (InvalidArgumentException $e) {
    oxy_json(['ok' => false, 'error' => 'bad_request'], 400);
} catch (Throwable $e) {
    error_log('oxy-master: ' . $e->getMessage());
    oxy_json(['ok' => false, 'error' => 'server_error'], 500);
}
