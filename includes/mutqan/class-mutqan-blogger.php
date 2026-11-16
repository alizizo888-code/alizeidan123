<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Blogger API (v3) through the owner's own Google OAuth client — free, no paid API.
 *
 * The owner pastes the client ID/secret of his Google Cloud OAuth client in «تطبيق أكسجين» and presses «ربط بلوجر»; Google
 * sends him back to /wp-json/mutqan/v1/blogger-oauth with a code, which is exchanged once for a refresh token kept in a
 * non-autoloaded option. Publishing then goes straight to the API (no browser), used by the control centre and the
 * scheduled publishing passes through the admin-only /control route.
 */
final class Mutqan_Blogger {
    const OPT = 'mutqan_blogger_oauth';
    const SCOPE = 'https://www.googleapis.com/auth/blogger';

    public static function boot() {
        add_action('rest_api_init', function () {
            register_rest_route('mutqan/v1', '/blogger-oauth', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [__CLASS__, 'callback']]);
            // The article publisher on GitHub's servers borrows a one-hour key from here (see oidc_token): no secret is copied anywhere.
            register_rest_route('mutqan/v1', '/blogger-token', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($req) {
                try {
                    $auth = (string) $req->get_header('authorization');
                    $jwt = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : '';
                    return new WP_REST_Response(['data' => self::oidc_token($jwt)], 200);
                } catch (Mutqan_Error $e) { return new WP_REST_Response(['error' => ['code' => $e->slug, 'message' => $e->getMessage()]], $e->status); }
                catch (Throwable $e) { return new WP_REST_Response(['error' => ['code' => 'blogger', 'message' => $e->getMessage()]], 503); }
            }]);
        });
        add_action('admin_post_mutqan_blogger_save', [__CLASS__, 'save']);
        add_action('admin_post_mutqan_blogger_connect', [__CLASS__, 'connect']);
    }

    public static function cfg() { return array_merge(['client_id' => '', 'client_secret' => '', 'refresh_token' => '', 'connected_at' => ''], (array) get_option(self::OPT, [])); }
    public static function connected() { $c = self::cfg(); return $c['refresh_token'] !== ''; }
    public static function redirect_uri() { return rest_url('mutqan/v1/blogger-oauth'); }

    public static function save() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_blogger_save');
        $c = self::cfg();
        $id = trim(sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')));
        $sec = trim(sanitize_text_field(wp_unslash($_POST['client_secret'] ?? '')));
        if ($id !== '') { $c['client_id'] = $id; }
        if ($sec !== '') { $c['client_secret'] = $sec; }
        if (!empty($_POST['disconnect'])) { $c['refresh_token'] = ''; $c['connected_at'] = ''; delete_transient('mq_blogger_at'); }
        update_option(self::OPT, $c, false);
        self::back('اتحفظت بيانات بلوجر.');
    }

    public static function connect() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_blogger_connect');
        $c = self::cfg();
        if ($c['client_id'] === '' || $c['client_secret'] === '') { self::back('احفظ Client ID و Client Secret الأول.', 'error'); }
        $state = wp_generate_password(24, false);
        set_transient('mq_blogger_state_' . $state, get_current_user_id(), 900);
        wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $c['client_id'], 'redirect_uri' => self::redirect_uri(), 'response_type' => 'code', 'scope' => self::SCOPE,
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
        ]));
        exit;
    }

    /** Google sends the owner back here. The state must match one this site issued in the last 15 minutes. */
    public static function callback($req) {
        $state = preg_replace('/[^A-Za-z0-9]/', '', (string) $req->get_param('state'));
        if ($state === '' || !get_transient('mq_blogger_state_' . $state)) { return self::html('الرابط ده انتهى أو مش صادر من الموقع. ارجع لصفحة تطبيق أكسجين واضغط «ربط بلوجر» تاني.'); }
        delete_transient('mq_blogger_state_' . $state);
        if ($req->get_param('error')) { return self::html('اتلغى الربط من صفحة جوجل.'); }
        $c = self::cfg();
        $res = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 20, 'body' => [
            'code' => (string) $req->get_param('code'), 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
            'redirect_uri' => self::redirect_uri(), 'grant_type' => 'authorization_code',
        ]]);
        $d = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($d['refresh_token'])) { return self::html('جوجل ما رجّعش مفتاح دائم: ' . esc_html($d['error_description'] ?? $d['error'] ?? 'خطأ غير معروف')); }
        $c['refresh_token'] = $d['refresh_token']; $c['connected_at'] = gmdate('c');
        update_option(self::OPT, $c, false);
        set_transient('mq_blogger_at', $d['access_token'], max(60, (int) ($d['expires_in'] ?? 3600) - 120));
        wp_safe_redirect(admin_url('admin.php?page=mutqan-admin&mutqan_msg=' . rawurlencode('اتربط بلوجر بنجاح.')));
        exit;
    }

    private static function html($msg) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><body dir="rtl" style="font-family:sans-serif;padding:24px">' . esc_html($msg) . '</body>';
        exit;
    }

    private static function back($msg, $kind = 'success') {
        wp_safe_redirect(add_query_arg(['mutqan_msg' => rawurlencode($msg), 'mutqan_kind' => $kind], admin_url('admin.php?page=mutqan-admin')));
        exit;
    }

    public static function token() {
        $t = get_transient('mq_blogger_at');
        if ($t) { return $t; }
        $c = self::cfg();
        if ($c['refresh_token'] === '') { throw new Exception('بلوجر مش مربوط — اربطه من صفحة تطبيق أكسجين.'); }
        $res = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 20, 'body' => [
            'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'refresh_token' => $c['refresh_token'], 'grant_type' => 'refresh_token',
        ]]);
        $d = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($d['access_token'])) { throw new Exception('تجديد مفتاح بلوجر فشل: ' . ($d['error'] ?? 'غير معروف')); }
        set_transient('mq_blogger_at', $d['access_token'], max(60, (int) ($d['expires_in'] ?? 3600) - 120));
        return $d['access_token'];
    }

    /* ------------------------------------------------------------------ the GitHub publisher's one-hour key */

    const OIDC_ISS = 'https://token.actions.githubusercontent.com';
    const OIDC_AUD = 'oxygen11-blogger';
    const OIDC_REPOS = ['alizizo888-code/mutaqin-oxygen-app', 'alizizo888-code/alizeidan123'];

    /**
     * GitHub gives every workflow run a signed identity token (OIDC). This checks that the token is GitHub's, is for this
     * purpose (audience), comes from one of the owner's two repositories and its Blogger workflow, and has not expired —
     * then hands back a Blogger access token that lives one hour. The refresh token never leaves this site, and there is
     * no secret to paste into GitHub: the articles keep going out with nobody's computer on.
     */
    public static function oidc_token($jwt) {
        Mutqan_Limiter::hit('blogger_oidc', 60, 3600);
        $c = self::claims($jwt);
        if (!self::connected()) { throw new Mutqan_Error('not_connected', 'بلوجر مش مربوط على الموقع لسه — اربطه مرة واحدة من «تطبيق أكسجين».', 409); }
        return ['access_token' => self::token(), 'expires_in' => 3300, 'repository' => $c['repository']];
    }

    /** The verified claims of a GitHub Actions OIDC token, or a 403. */
    public static function claims($jwt, $jwks = null, $now = null) {
        $now = $now ?: time();
        $parts = explode('.', (string) $jwt);
        if (count($parts) !== 3) { throw new Mutqan_Error('forbidden', 'توكن غير صالح.', 403); }
        $b64 = function ($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); };
        $h = json_decode($b64($parts[0]), true); $c = json_decode($b64($parts[1]), true);
        if (!is_array($h) || !is_array($c) || ($h['alg'] ?? '') !== 'RS256' || empty($h['kid'])) { throw new Mutqan_Error('forbidden', 'توكن غير صالح.', 403); }
        $jwks = $jwks ?: self::jwks();
        $key = null;
        foreach ((array) ($jwks['keys'] ?? []) as $k) { if (($k['kid'] ?? '') === $h['kid'] && ($k['kty'] ?? '') === 'RSA') { $key = $k; break; } }
        if (!$key) { delete_transient('mq_gh_jwks'); throw new Mutqan_Error('forbidden', 'مفتاح التوقيع غير معروف.', 403); }
        $pem = self::jwk_pem($b64($key['n']), $b64($key['e']));
        if (openssl_verify($parts[0] . '.' . $parts[1], $b64($parts[2]), $pem, OPENSSL_ALGO_SHA256) !== 1) { throw new Mutqan_Error('forbidden', 'التوقيع غير صحيح.', 403); }
        $aud = (array) ($c['aud'] ?? []);
        $wf = (string) ($c['workflow_ref'] ?? ($c['job_workflow_ref'] ?? ''));
        if (($c['iss'] ?? '') !== self::OIDC_ISS || !in_array(self::OIDC_AUD, $aud, true)
            || !in_array((string) ($c['repository'] ?? ''), self::OIDC_REPOS, true)
            || strpos($wf, '/.github/workflows/blogger-') === false
            || (int) ($c['exp'] ?? 0) < $now || (int) ($c['nbf'] ?? ($c['iat'] ?? 0)) > $now + 60) {
            throw new Mutqan_Error('forbidden', 'التوكن مش من مستودع الشركة أو انتهى.', 403);
        }
        return $c;
    }

    private static function jwks() {
        $j = get_transient('mq_gh_jwks');
        if (is_array($j)) { return $j; }
        $res = wp_remote_get(self::OIDC_ISS . '/.well-known/jwks', ['timeout' => 10]);
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($j) || empty($j['keys'])) { throw new Mutqan_Error('jwks', 'تعذر قراءة مفاتيح GitHub.', 503); }
        set_transient('mq_gh_jwks', $j, 6 * HOUR_IN_SECONDS);
        return $j;
    }

    /** RSA public key (modulus, exponent) → PEM, for openssl_verify. */
    public static function jwk_pem($n, $e) {
        $len = function ($l) { if ($l < 128) { return chr($l); } $b = ltrim(pack('N', $l), "\0"); return chr(0x80 | strlen($b)) . $b; };
        $int = function ($x) use ($len) { if (ord($x[0]) > 0x7f) { $x = "\0" . $x; } return "\x02" . $len(strlen($x)) . $x; };
        $rsa = $int($n) . $int($e); $rsa = "\x30" . $len(strlen($rsa)) . $rsa;
        $bits = "\x03" . $len(strlen($rsa) + 1) . "\0" . $rsa;
        $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = "\x30" . $len(strlen($alg . $bits)) . $alg . $bits;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function api($method, $path, $body = null) {
        $args = ['method' => $method, 'timeout' => 30, 'headers' => ['Authorization' => 'Bearer ' . self::token(), 'Content-Type' => 'application/json']];
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $res = wp_remote_request('https://www.googleapis.com/blogger/v3' . $path, $args);
        if (is_wp_error($res)) { throw new Exception($res->get_error_message()); }
        $code = (int) wp_remote_retrieve_response_code($res);
        $d = json_decode(wp_remote_retrieve_body($res), true);
        if ($code === 429 || $code === 403 && stripos((string) wp_json_encode($d), 'rate') !== false) { throw new Exception('BLOCKED: بلوجر وقف النشر مؤقتًا (حد المعدل).'); }
        if ($code < 200 || $code >= 300) { throw new Exception('Blogger ' . $code . ': ' . ($d['error']['message'] ?? '')); }
        return $d;
    }

    /** blog id for a network brand id (or a raw numeric blog id). */
    public static function blog_id($brand) {
        if (preg_match('/^\d{6,25}$/', (string) $brand)) { return (string) $brand; }
        $f = __DIR__ . '/data/blogger-network.json';
        foreach ((array) json_decode((string) @file_get_contents($f), true) as $b) { if (($b['id'] ?? '') === $brand && !empty($b['blog_id'])) { return (string) $b['blog_id']; } }
        throw new Exception('المدونة دي لسه مش متعمّلة على بلوجر.');
    }

    public static function publish($brand, $title, $html, $labels = []) {
        $labels = is_array($labels) ? $labels : array_filter(array_map('trim', explode(',', (string) $labels)));
        $d = self::api('POST', '/blogs/' . self::blog_id($brand) . '/posts/', ['kind' => 'blogger#post', 'title' => wp_strip_all_tags((string) $title), 'content' => (string) $html, 'labels' => array_values(array_slice($labels, 0, 10))]);
        return ['id' => $d['id'] ?? '', 'url' => $d['url'] ?? '', 'title' => $d['title'] ?? ''];
    }

    public static function update_title($brand, $post_id, $title) {
        $d = self::api('PATCH', '/blogs/' . self::blog_id($brand) . '/posts/' . rawurlencode((string) $post_id), ['title' => wp_strip_all_tags((string) $title)]);
        return ['id' => $d['id'] ?? '', 'url' => $d['url'] ?? '', 'title' => $d['title'] ?? ''];
    }

    public static function posts($brand, $limit = 20) {
        $d = self::api('GET', '/blogs/' . self::blog_id($brand) . '/posts?fetchBodies=false&maxResults=' . max(1, min(50, (int) $limit)));
        return array_map(function ($p) { return ['id' => $p['id'], 'title' => $p['title'], 'url' => $p['url'], 'published' => $p['published'] ?? '']; }, (array) ($d['items'] ?? []));
    }

    public static function admin_section() {
        $c = self::cfg(); ?>
        <h2>ربط بلوجر (Google OAuth — مجاني)</h2>
        <p>انسخ Client ID و Client Secret من عميل OAuth في Google Cloud (مشروع My First Project ← Clients ← Ads Panel Web)، واحفظهم، وبعدين اضغط «ربط بلوجر» ووافق بحساب جوجل اللي عليه المدونات. عنوان الرجوع المسجّل في جوجل: <code dir="ltr"><?php echo esc_html(self::redirect_uri()); ?></code></p>
        <p>الحالة: <strong><?php echo self::connected() ? 'مربوط منذ ' . esc_html($c['connected_at']) : 'مش مربوط'; ?></strong></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
            <input type="hidden" name="action" value="mutqan_blogger_save"><?php wp_nonce_field('mutqan_blogger_save'); ?>
            <table class="form-table">
                <tr><th><label for="bg_id">Client ID</label></th><td><input id="bg_id" name="client_id" dir="ltr" class="large-text" value="<?php echo esc_attr($c['client_id']); ?>"></td></tr>
                <tr><th><label for="bg_sec">Client Secret</label></th><td><input type="password" id="bg_sec" name="client_secret" dir="ltr" class="regular-text" autocomplete="new-password" placeholder="<?php echo $c['client_secret'] !== '' ? 'محفوظ — اتركه فارغًا للإبقاء عليه' : 'الصقه هنا'; ?>">
                    <?php if (self::connected()): ?><label><input type="checkbox" name="disconnect" value="1"> فصل الربط</label><?php endif; ?></td></tr>
            </table>
            <?php submit_button('حفظ', 'secondary'); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="mutqan_blogger_connect"><?php wp_nonce_field('mutqan_blogger_connect'); ?>
            <?php submit_button(self::connected() ? 'إعادة ربط بلوجر' : 'ربط بلوجر', 'primary'); ?>
        </form>
        <?php
    }
}
Mutqan_Blogger::boot();
