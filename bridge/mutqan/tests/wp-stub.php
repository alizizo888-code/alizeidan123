<?php
/**
 * Just enough WordPress to run the plugin's own logic here, with no WordPress installed.
 *
 * The point is to prove the plugin works before it is ever put on a live face: the signing, the
 * validation, the queue and the refusals are all plain PHP and can be checked here. Anything that
 * genuinely needs WordPress (the admin screen's markup, cron firing) is left to the live check.
 */

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['wp_options'] = array();
$GLOBALS['wp_transients'] = array();
$GLOBALS['wp_routes'] = array();
$GLOBALS['wp_actions'] = array();
$GLOBALS['wp_shortcodes'] = array();
$GLOBALS['wp_http_log'] = array();
/** The reply the next wp_remote_post should give. Tests set this. */
$GLOBALS['wp_http_next'] = array('code' => 201, 'body' => '{"ok":true,"code":"site-ref","order_id":"x","duplicate":false}');

class WP_Error
{
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = array())
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
    public function get_status() { return isset($this->data['status']) ? (int) $this->data['status'] : 0; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

class WP_REST_Request
{
    private $method;
    private $route;
    private $body;
    private $headers = array();
    public function __construct($method = 'GET', $route = '', $body = '', array $headers = array())
    {
        $this->method = $method;
        $this->route = $route;
        $this->body = $body;
        foreach ($headers as $key => $value) {
            $this->headers[strtolower($key)] = $value;
        }
    }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_body() { return $this->body; }
    private $params = array();
    public function get_param($name) { return isset($this->params[$name]) ? $this->params[$name] : null; }
    public function set_param($name, $value) { $this->params[$name] = $value; }
    public function get_header($name) { $name = strtolower($name); return isset($this->headers[$name]) ? $this->headers[$name] : null; }
    public function set_header($name, $value) { $this->headers[strtolower($name)] = $value; }
}

class WP_REST_Response
{
    public $data;
    public $status;
    public function __construct($data = null, $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$name] : $default;
}
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function add_option($name, $value, $x = '', $autoload = null) { if (!array_key_exists($name, $GLOBALS['wp_options'])) { $GLOBALS['wp_options'][$name] = $value; } return true; }
function delete_option($name) { unset($GLOBALS['wp_options'][$name]); return true; }

function get_transient($key) { return isset($GLOBALS['wp_transients'][$key]) ? $GLOBALS['wp_transients'][$key] : false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['wp_transients'][$key] = $value; return true; }

function register_rest_route($ns, $path, $args) { $GLOBALS['wp_routes'][$ns . $path][] = $args; return true; }
function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['wp_actions'][$hook][] = $cb; return true; }
function add_filter($hook, $cb, $priority = 10, $args = 1) { return true; }
function has_action($hook) { return isset($GLOBALS['wp_actions'][$hook]); }
function do_action($hook) { return true; }
function add_shortcode($tag, $cb) { $GLOBALS['wp_shortcodes'][$tag] = $cb; return true; }
function register_activation_hook($file, $cb) { return true; }
function register_deactivation_hook($file, $cb) { return true; }
function add_menu_page() { return true; }
function wp_next_scheduled($hook) { return false; }
function wp_schedule_event($ts, $rec, $hook) { return true; }
function wp_unschedule_event($ts, $hook) { return true; }

function plugin_dir_path($file) { return dirname($file) . '/'; }
function home_url($path = '/') { return 'https://oxygen11.com' . $path; }
function admin_url($path = '') { return 'https://oxygen11.com/wp-admin/' . $path; }
function rest_url($path = '') { return 'https://oxygen11.com/wp-json/' . ltrim($path, '/'); }
function get_bloginfo($what) { return $what === 'version' ? '6.5' : 'أكسجين'; }
function wp_count_posts($type) { return (object) array('publish' => 12); }
function get_posts($args) { return array(); }
function get_permalink($id) { return 'https://oxygen11.com/?p=' . (int) $id; }
function wp_cache_delete($key, $group) { return true; }
function nocache_headers() { return true; }

function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function trailingslashit($s) { return untrailingslashit($s) . '/'; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }

function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return (string) $s; }
function esc_url_raw($s) { return (string) $s; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_create_nonce($action) { return 'nonce-' . substr(md5((string) $action), 0, 10); }
function wp_rand($min = 0, $max = 0) { return random_int($min, $max); }
function esc_js($s) { return addslashes((string) $s); }
function current_user_can($cap) { return !empty($GLOBALS['wp_is_admin']); }
function wp_nonce_field($action) { echo ''; }
function submit_button() { echo ''; }
function wp_safe_redirect($url) { $GLOBALS['wp_redirect'] = $url; }
function wp_die($msg) { throw new RuntimeException('wp_die:' . $msg); }

/** The fake desk. Records what it was sent and answers with whatever the test queued. */
function wp_remote_post($url, $args)
{
    $GLOBALS['wp_http_log'][] = array('url' => $url, 'args' => $args);
    $next = $GLOBALS['wp_http_next'];
    if ($next instanceof WP_Error) {
        return $next;
    }
    return array('response' => array('code' => $next['code']), 'body' => $next['body']);
}
function wp_remote_request($url, $args) { return wp_remote_post($url, $args); }
function wp_remote_retrieve_response_code($r) { return is_array($r) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body($r) { return is_array($r) ? $r['body'] : ''; }
