<?php
/**
 * One admin page: the key to paste into the desk, the desk address, and what the queue is doing.
 *
 * This is the only part of the plugin that asks WordPress who the user is, and the only part that
 * should: it is a human at a screen, not a machine. Everything the desk touches is signed instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

function mutqan_admin_menu()
{
    add_menu_page(
        'متقن',
        'متقن',
        'manage_options',
        'mutqan',
        'mutqan_admin_page',
        'dashicons-admin-links',
        58
    );
}

function mutqan_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $secret = (string) get_option(MUTQAN_SECRET_OPTION, '');
    $desk = mutqan_desk_url();
    $queue = mutqan_outbox_read();
    $values = mutqan_all();

    echo '<div class="wrap" dir="rtl"><h1>متقن ' . esc_html(MUTQAN_VERSION) . '</h1>';

    if (isset($_GET['saved'])) {
        echo '<div class="notice notice-success"><p>تم الحفظ.</p></div>';
    }
    if (isset($_GET['rotated'])) {
        echo '<div class="notice notice-warning"><p>تم تدوير المفتاح. الصق المفتاح الجديد في الدسك، وإلا يتوقف الربط.</p></div>';
    }

    $conflicts = mutqan_conflicts();
    if ($conflicts !== array()) {
        echo '<div class="notice notice-error inline"><p><strong>تعارض لازم يتحل قبل الاعتماد على الإضافة:</strong></p><ul style="list-style:disc;padding-right:22px">';
        foreach ($conflicts as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul></div>';
    }

    echo '<h2>المفتاح</h2>';
    echo '<p>انسخه مرة واحدة والصقه في الدسك عند ربط هذا الموقع. لا تشاركه مع أي شخص.</p>';
    echo '<input type="text" readonly value="' . esc_attr($secret) . '" style="width:100%;max-width:680px;font-family:monospace" onclick="this.select()">';

    echo '<h2>عنوان الدسك</h2>';
    echo '<p>الطلبات بتتبعت للعنوان ده. بيتظبط من هنا بس، عشان مفيش جهة خارجية تقدر تحوّل الطلبات لمكان تاني.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('mutqan_save');
    echo '<input type="hidden" name="action" value="mutqan_save">';
    echo '<input type="url" name="desk_url" value="' . esc_attr($desk) . '" placeholder="https://desk.example.com/api" style="width:100%;max-width:680px" dir="ltr">';
    echo '<p><label><input type="checkbox" name="intake_enabled" value="1"' . ($values['intake_enabled'] ? ' checked' : '') . '> استقبال الطلبات من الموقع مفتوح</label></p>';
    submit_button('حفظ');
    echo '</form>';

    echo '<h2>حالة الربط</h2><table class="widefat striped" style="max-width:680px"><tbody>';
    echo '<tr><td>المفتاح</td><td>' . (strlen($secret) >= 64 ? 'موجود' : '<strong>ناقص</strong>') . '</td></tr>';
    echo '<tr><td>عنوان الدسك</td><td>' . ($desk === '' ? '<strong>غير مظبوط</strong>' : esc_html($desk)) . '</td></tr>';
    echo '<tr><td>طلبات في الانتظار</td><td>' . count($queue) . '</td></tr>';
    echo '</tbody></table>';

    if ($queue !== array()) {
        echo '<p>الطلبات اللي لسه ما وصلتش الدسك بتتحاول كل ساعة تلقائياً.</p>';
        echo '<table class="widefat striped" style="max-width:680px"><thead><tr><th>الخدمة</th><th>محاولات</th><th>آخر خطأ</th></tr></thead><tbody>';
        foreach ($queue as $item) {
            $service = isset($item['order']['service']) ? (string) $item['order']['service'] : '—';
            echo '<tr><td>' . esc_html($service) . '</td><td>' . (int) $item['attempts'] . '</td><td><code>' . esc_html((string) $item['last_error']) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h2>الإعدادات القادمة من الدسك</h2>';
    echo '<p>دي بتتظبط من الدسك، وبتظهر هنا للمراجعة بس.</p>';
    echo '<table class="widefat striped" style="max-width:680px"><tbody>';
    foreach ($values as $key => $value) {
        if (is_bool($value)) {
            $shown = $value ? 'نعم' : 'لا';
        } elseif (is_array($value)) {
            $shown = $value === array() ? '—' : implode('، ', $value);
        } else {
            $shown = (string) $value === '' ? '—' : (string) $value;
        }
        echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . esc_html($shown) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<h2>نموذج الطلب</h2>';
    echo '<p>حط الكود ده في أي صفحة: <code>[mutqan_order_form]</code></p>';

    echo '<h2>تدوير المفتاح</h2>';
    echo '<p>التدوير يبطل المفتاح القديم فوراً. الربط يتوقف لحد ما تلصق الجديد في الدسك.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('mutqan_rotate');
    echo '<input type="hidden" name="action" value="mutqan_rotate">';
    submit_button('تدوير المفتاح', 'secondary', 'submit', false);
    echo '</form>';

    echo '</div>';
}

function mutqan_admin_save()
{
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('mutqan_save');

    $url = trim((string) (isset($_POST['desk_url']) ? wp_unslash($_POST['desk_url']) : ''));
    if ($url !== '') {
        $url = esc_url_raw($url);
        // Orders carry customer details, so the desk must be reached over HTTPS and nowhere else.
        if (strpos($url, 'https://') !== 0) {
            wp_safe_redirect(admin_url('admin.php?page=mutqan&error=scheme'));
            exit;
        }
    }
    update_option(MUTQAN_DESK_OPTION, untrailingslashit($url), false);
    update_option(mutqan_option_name('intake_enabled'), empty($_POST['intake_enabled']) ? '0' : '1', false);

    wp_safe_redirect(admin_url('admin.php?page=mutqan&saved=1'));
    exit;
}

function mutqan_admin_rotate()
{
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('mutqan_rotate');
    update_option(MUTQAN_SECRET_OPTION, bin2hex(random_bytes(32)), false);
    wp_safe_redirect(admin_url('admin.php?page=mutqan&rotated=1'));
    exit;
}

/**
 * Two plugins answering the same routes is a silent failure: whichever registers last wins, and which
 * one that is depends on load order, so the face works until it quietly does not. The same goes for a
 * second plugin that also pushes orders to the desk — the customer's order arrives twice.
 *
 * Nothing is disabled automatically: turning off another plugin is the owner's decision, not ours.
 * @return list<string> one line per conflict found, empty when there is none.
 */
function mutqan_conflicts()
{
    $found = array();
    if (function_exists('oxy_bridge_routes') || defined('OXY_BRIDGE_VERSION')) {
        $found[] = 'إضافة «Oxygen Bridge» القديمة شغالة، وبتسجّل نفس المسارات. عطّلها: المسارات بتتضارب، واللي بيكسب بيعتمد على ترتيب التحميل.';
    }
    if (class_exists('Oxygen_Orders_Chat') && method_exists('Oxygen_Orders_Chat', 'push_to_desk')) {
        $found[] = 'إضافة «Oxygen AC Automation» فيها إرسال طلبات للدسك كمان. وقّف الإرسال من هناك، وإلا الطلب الواحد ممكن يوصل مرتين.';
    }
    return $found;
}

add_action('admin_notices', 'mutqan_conflict_notice');
function mutqan_conflict_notice()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $conflicts = mutqan_conflicts();
    if ($conflicts === array()) {
        return;
    }
    echo '<div class="notice notice-error" dir="rtl"><p><strong>متقن: فيه تعارض لازم تحله.</strong></p><ul style="list-style:disc;padding-right:22px">';
    foreach ($conflicts as $line) {
        echo '<li>' . esc_html($line) . '</li>';
    }
    echo '</ul></div>';
}
