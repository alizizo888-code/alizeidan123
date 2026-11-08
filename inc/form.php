<?php
/**
 * The order form on the face, and the one public route it posts to.
 *
 * This route is deliberately the only unsigned one in the plugin, because the customer's browser has no
 * key. Its defences are a honeypot, a per-address rate limit, a length check on every field and a
 * service allow-list. Nothing here can read or change a setting.
 *
 * There is no nonce on purpose. For a visitor who is not logged in, a WordPress nonce is the same value
 * for everyone and is printed in the page, so it stops nobody; worse, a page cache serves it until it
 * expires and then every real customer's order fails. The checks above are what actually hold.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Orders one address may send per hour. */
define('MUTQAN_RATE_LIMIT', 6);

add_action('rest_api_init', 'mutqan_register_public_route');
function mutqan_register_public_route()
{
    register_rest_route('mutqan/v1', '/order', array(
        'methods'             => 'POST',
        'callback'            => 'mutqan_public_order',
        'permission_callback' => '__return_true',
    ));
}

add_shortcode('mutqan_order_form', 'mutqan_order_form');
/** The form itself. Services come from the settings the desk wrote, so one place controls every face. */
function mutqan_order_form()
{
    if (!mutqan_get('intake_enabled')) {
        return '<p class="mutqan-closed">استقبال الطلبات متوقف مؤقتاً. برجاء المحاولة لاحقاً.</p>';
    }
    $services = mutqan_get('services');
    if ($services === array()) {
        $services = array('صيانة مكيف', 'تنظيف مكيف', 'تركيب مكيف', 'كشف أعطال');
    }
    $options = '';
    foreach ($services as $service) {
        $options .= '<option value="' . esc_attr($service) . '">' . esc_html($service) . '</option>';
    }
    $endpoint = esc_url_raw(rest_url('mutqan/v1/order'));
    $id = 'mutqan-form-' . wp_rand(100000, 999999);

    ob_start();
    ?>
<form class="mutqan-form" id="<?php echo esc_attr($id); ?>" data-endpoint="<?php echo esc_attr($endpoint); ?>" dir="rtl">
  <label>الاسم<input name="name" maxlength="120" required></label>
  <label>رقم الجوال<input name="phone" maxlength="20" inputmode="tel" required></label>
  <label>الخدمة<select name="service" required><?php echo $options; ?></select></label>
  <label>العدد<input name="qty" type="number" min="1" max="50" value="1"></label>
  <label>العنوان<input name="address" maxlength="200"></label>
  <label>وصف المشكلة<textarea name="issue" maxlength="300" rows="3"></textarea></label>
  <label>الوقت المناسب<input name="when" maxlength="80" placeholder="مثلاً: بكرة الصبح"></label>
  <label class="mutqan-trap" aria-hidden="true">لا تكتب هنا<input name="website" tabindex="-1" autocomplete="off"></label>
  <button type="submit">إرسال الطلب</button>
  <p class="mutqan-msg" role="status"></p>
</form>
<script>
(function () {
  var form = document.getElementById('<?php echo esc_js($id); ?>');
  if (!form) { return; }
  var msg = form.querySelector('.mutqan-msg');
  var button = form.querySelector('button[type="submit"]');
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    button.disabled = true;
    msg.textContent = 'جارٍ الإرسال…';
    var body = {};
    new FormData(form).forEach(function (value, key) { body[key] = value; });
    fetch(form.dataset.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().then(function (data) { return { status: response.status, data: data }; });
    }).then(function (result) {
      if (result.status === 201 && result.data.ok) {
        form.reset();
        msg.textContent = result.data.message || 'تم استلام طلبك. هنتواصل معاك قريب.';
      } else {
        msg.textContent = result.data.message || 'تعذّر إرسال الطلب. برجاء المحاولة مرة أخرى.';
        button.disabled = false;
      }
    }).catch(function () {
      msg.textContent = 'تعذّر الاتصال. برجاء المحاولة مرة أخرى.';
      button.disabled = false;
    });
  });
})();
</script>
    <?php
    return (string) ob_get_clean();
}

/** One order from a customer's browser. */
function mutqan_public_order(WP_REST_Request $request)
{
    if (!mutqan_get('intake_enabled')) {
        return new WP_REST_Response(array('ok' => false, 'message' => 'استقبال الطلبات متوقف مؤقتاً.'), 503);
    }

    $body = json_decode((string) $request->get_body(), true);
    if (!is_array($body)) {
        return new WP_REST_Response(array('ok' => false, 'message' => 'الطلب غير مكتمل.'), 400);
    }
    // A filled honeypot is a bot. It is answered as if accepted, so the bot learns nothing.
    if (trim((string) (isset($body['website']) ? $body['website'] : '')) !== '') {
        return new WP_REST_Response(array('ok' => true, 'message' => 'تم استلام طلبك.'), 201);
    }
    if (!mutqan_rate_ok()) {
        return new WP_REST_Response(array('ok' => false, 'message' => 'طلبات كثيرة في وقت قصير. جرّب بعد شوية.'), 429);
    }

    $name = mutqan_clip(isset($body['name']) ? $body['name'] : '', 120);
    $phone = preg_replace('/[^\d+]/', '', (string) (isset($body['phone']) ? $body['phone'] : ''));
    $service = mutqan_clip(isset($body['service']) ? $body['service'] : '', 80);

    if ($name === '' || $service === '') {
        return new WP_REST_Response(array('ok' => false, 'message' => 'الاسم والخدمة مطلوبين.'), 400);
    }
    if (!preg_match('/^\+?\d{9,15}$/', (string) $phone)) {
        return new WP_REST_Response(array('ok' => false, 'message' => 'رقم الجوال غير صحيح.'), 400);
    }
    // Only a service the face actually offers, so a crafted request cannot invent one.
    $services = mutqan_get('services');
    if ($services !== array() && !in_array($service, $services, true)) {
        return new WP_REST_Response(array('ok' => false, 'message' => 'الخدمة غير متاحة.'), 400);
    }

    $order = array(
        'site_ref' => substr(hash('sha256', $phone . '|' . $service . '|' . floor(time() / 60)), 0, 32),
        'name'     => $name,
        'phone'    => $phone,
        'service'  => $service,
        'qty'      => max(1, min(50, (int) (isset($body['qty']) ? $body['qty'] : 1))),
        'address'  => mutqan_clip(isset($body['address']) ? $body['address'] : '', 200),
        'issue'    => mutqan_clip(isset($body['issue']) ? $body['issue'] : '', 300),
        'when'     => mutqan_clip(isset($body['when']) ? $body['when'] : '', 80),
        'source'   => 'form',
    );

    $result = mutqan_push_order($order);
    // A queued order is a real order: the customer is told it arrived, because it has, on our side.
    return new WP_REST_Response(array(
        'ok'      => true,
        'queued'  => $result['queued'],
        'code'    => $result['code'],
        'message' => 'تم استلام طلبك. هنتواصل معاك قريب.',
    ), 201);
}

function mutqan_clip($value, $max)
{
    $text = trim((string) $value);
    $text = wp_strip_all_tags($text);
    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

/**
 * The visitor's address as best it can be known. Behind a CDN or a load balancer REMOTE_ADDR is the
 * proxy, so counting on it alone would put every customer in one bucket and close the form for all of
 * them after a few orders. A forwarded header can be forged, which only lets a determined caller past
 * the rate limit; the honeypot, the field checks and the service allow-list are the real defences.
 */
function mutqan_client_ip()
{
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR') as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        $first = trim(explode(',', (string) $_SERVER[$header])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
}

/** A plain per-address count, enough to stop a flood without a database table. */
function mutqan_rate_ok()
{
    $ip = mutqan_client_ip();
    $key = 'mutqan_rate_' . hash('sha256', $ip);
    $count = (int) get_transient($key);
    if ($count >= MUTQAN_RATE_LIMIT) {
        return false;
    }
    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    return true;
}
