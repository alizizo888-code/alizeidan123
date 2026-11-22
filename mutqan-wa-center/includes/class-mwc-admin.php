<?php
if (!defined('ABSPATH')) exit;

/** صفحة صغيرة في لوحة ووردبريس: الرقم السري + روابط الربط. باقي الإعدادات كلها من داخل التطبيق. */
class MWC_Admin {
    static function menu() {
        add_menu_page('مركز الواتساب', 'مركز الواتساب', 'manage_options', 'mwc', [__CLASS__, 'page'], 'dashicons-whatsapp', 3);
    }

    static function register() {
        if (!isset($_POST['mwc_save']) || !current_user_can('manage_options')) return;
        check_admin_referer('mwc_save');
        $pin = preg_replace('/\D/', '', (string) ($_POST['mwc_pin'] ?? ''));
        if ($pin !== '') {
            if (strlen($pin) < 4) { add_settings_error('mwc', 'pin', 'الرقم السري لازم 4 أرقام على الأقل'); return; }
            update_option('mwc_pin_hash', wp_hash_password($pin), false);
            if (!empty($_POST['mwc_logout_all'])) { global $wpdb; $wpdb->query("DELETE FROM " . MWC_Store::t('tokens')); }
            add_settings_error('mwc', 'ok', 'تم حفظ الرقم السري ✅', 'updated');
        }
        if (!empty($_POST['mwc_regen'])) {
            update_option('mwc_webhook_secret', wp_generate_password(32, false), false);
            add_settings_error('mwc', 'regen', 'تم تغيير سر الـwebhook — اضغط «ربط» على كل رقم QR من التطبيق عشان يتحدث', 'updated');
        }
    }

    static function page() {
        $app = home_url('/wa-center/');
        $meta = rest_url(MWC_NS . '/webhook/meta');
        $evo = add_query_arg('s', get_option('mwc_webhook_secret'), rest_url(MWC_NS . '/webhook/evolution'));
        settings_errors('mwc'); ?>
        <div class="wrap" dir="rtl" style="max-width:820px">
            <h1>مركز الواتساب</h1>
            <p><a class="button button-primary button-hero" href="<?php echo esc_url($app); ?>" target="_blank">افتح مركز الواتساب ↗</a>
               &nbsp; أو من الموبايل/الآيباد افتح <code><?php echo esc_html($app); ?></code> واختار «إضافة للشاشة الرئيسية».</p>
            <form method="post">
                <?php wp_nonce_field('mwc_save'); ?>
                <h2>الرقم السري للدخول</h2>
                <p><input type="password" name="mwc_pin" inputmode="numeric" placeholder="<?php echo get_option('mwc_pin_hash') ? 'محفوظ — اكتب رقم جديد للتغيير' : 'اكتب رقم سري (4 أرقام أو أكثر)'; ?>" style="width:320px">
                   <label><input type="checkbox" name="mwc_logout_all" value="1"> خروج كل الأجهزة المسجلة</label></p>
                <h2>روابط الربط</h2>
                <table class="form-table">
                    <tr><th>Meta — Callback URL</th><td><code><?php echo esc_html($meta); ?></code></td></tr>
                    <tr><th>Meta — Verify token</th><td><code><?php echo esc_html(get_option('mwc_meta_verify')); ?></code><br><small>في Meta App ← WhatsApp ← Configuration ← Webhook، واشترك في <b>messages</b>.</small></td></tr>
                    <tr><th>السيرفر (Evolution) — Webhook</th><td><code style="word-break:break-all"><?php echo esc_html($evo); ?></code><br><small>بيتظبط تلقائي لما تضغط «ربط» على الرقم من التطبيق.</small>
                        <br><label><input type="checkbox" name="mwc_regen" value="1"> تغيير السر</label></td></tr>
                </table>
                <p><button class="button button-primary" name="mwc_save" value="1">حفظ</button></p>
            </form>
        </div>
    <?php }
}
