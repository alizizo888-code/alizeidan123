package com.oxygen11.mqnative;

import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Build;
import android.os.PowerManager;
import android.provider.Settings;
import android.text.TextUtils;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * «MqNative»: what the Oxygen apps can do on the phone beyond the web page.
 *   configure       — where the WhatsApp-notification reader sends what it sees (the site + the signed-in token)
 *   listenerEnabled / openListenerSettings — the «قراءة إشعارات الواتساب» switch Android keeps for itself
 *   ignoreBattery   — ask Android not to put the app to sleep (background GPS and notifications keep working)
 *   openApp / openUrl — Waze, Google Maps, Termux, WhatsApp… by package name or deep link
 */
@CapacitorPlugin(name = "MqNative")
public class MqNativePlugin extends Plugin {
    static final String PREFS = "mq_native";

    @PluginMethod
    public void configure(PluginCall call) {
        SharedPreferences.Editor e = getContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit();
        e.putString("endpoint", call.getString("endpoint", ""));
        e.putString("token", call.getString("token", ""));
        e.putString("device", call.getString("device", Build.MODEL));
        e.putString("role", call.getString("role", ""));   // picks the tone set: a customer hears «طلبي», staff hear orders / technicians / operations
        e.putBoolean("readWhatsApp", Boolean.TRUE.equals(call.getBoolean("readWhatsApp", true)));
        String anon = call.getString("anon", "");
        if (anon != null) e.putString("anon", anon);
        e.apply();
        // The alert service: notifications, ringtones and calls with the screen off.
        if (Boolean.TRUE.equals(call.getBoolean("alerts", true))) MqPushService.start(getContext());
        call.resolve(status());
    }

    @PluginMethod
    public void listenerEnabled(PluginCall call) { call.resolve(status()); }

    /** Signed out: forget the session and stop the alert service. */
    @PluginMethod
    public void reset(PluginCall call) {
        getContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply();
        getContext().stopService(new Intent(getContext(), MqPushService.class));
        call.resolve(status());
    }

    /** The page a tapped notification asked for (once), so the app opens on the order / call itself. */
    @PluginMethod
    public void takeLaunchUrl(PluginCall call) {
        JSObject r = new JSObject();
        r.put("url", launchUrl == null ? "" : launchUrl);
        launchUrl = null;
        call.resolve(r);
    }

    static String launchUrl = null;

    @Override
    public void load() {
        try { String u = getActivity().getIntent().getStringExtra("mq_url"); if (u != null && !u.isEmpty()) launchUrl = u; } catch (Exception ex) { /* ignore */ }
    }

    @Override
    protected void handleOnNewIntent(Intent intent) {
        super.handleOnNewIntent(intent);
        String u = intent.getStringExtra("mq_url");
        if (u != null && !u.isEmpty()) { launchUrl = u; JSObject d = new JSObject(); d.put("url", u); notifyListeners("open", d); }
    }

    @Override
    protected void handleOnResume() { super.handleOnResume(); MqPushService.appVisible = true; }

    @Override
    protected void handleOnPause() { super.handleOnPause(); MqPushService.appVisible = false; }

    @PluginMethod
    public void openListenerSettings(PluginCall call) {
        Intent i = new Intent("android.settings.ACTION_NOTIFICATION_LISTENER_SETTINGS");
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        getContext().startActivity(i);
        call.resolve();
    }

    /** Android 14+: calls may light up the whole screen only after the person allows it once. */
    @PluginMethod
    public void openFullScreenSettings(PluginCall call) {
        if (Build.VERSION.SDK_INT >= 34) {
            Intent i = new Intent("android.settings.MANAGE_APP_USE_FULL_SCREEN_INTENT", Uri.parse("package:" + getContext().getPackageName()));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            try { getContext().startActivity(i); } catch (Exception ex) { /* ignore */ }
        }
        call.resolve(status());
    }

    @PluginMethod
    public void ignoreBattery(PluginCall call) {
        Context c = getContext();
        PowerManager pm = (PowerManager) c.getSystemService(Context.POWER_SERVICE);
        if (pm != null && !pm.isIgnoringBatteryOptimizations(c.getPackageName())) {
            Intent i = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + c.getPackageName()));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            try { c.startActivity(i); } catch (Exception ex) { /* some phones hide it */ }
        }
        call.resolve(status());
    }

    @PluginMethod
    public void openApp(PluginCall call) {
        String pkg = call.getString("package", "");
        Intent i = getContext().getPackageManager().getLaunchIntentForPackage(pkg);
        JSObject r = new JSObject();
        if (i == null) {
            // Not installed: the store page instead.
            Intent s = new Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=" + pkg));
            s.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            try { getContext().startActivity(s); } catch (Exception ex) { /* no store */ }
            r.put("installed", false);
        } else {
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(i);
            r.put("installed", true);
        }
        call.resolve(r);
    }

    @PluginMethod
    public void openUrl(PluginCall call) {
        JSObject r = new JSObject();
        try {
            Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse(call.getString("url", "")));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(i);
            r.put("ok", true);
        } catch (Exception ex) { r.put("ok", false); }
        call.resolve(r);
    }

    private JSObject status() {
        Context c = getContext();
        String flat = Settings.Secure.getString(c.getContentResolver(), "enabled_notification_listeners");
        ComponentName me = new ComponentName(c, MqNotifyService.class);
        PowerManager pm = (PowerManager) c.getSystemService(Context.POWER_SERVICE);
        JSObject r = new JSObject();
        r.put("listener", !TextUtils.isEmpty(flat) && flat.contains(me.flattenToString()));
        r.put("battery", pm != null && pm.isIgnoringBatteryOptimizations(c.getPackageName()));
        r.put("alerts", MqPushService.running);
        boolean fs = true;
        if (Build.VERSION.SDK_INT >= 34) {
            android.app.NotificationManager nm = (android.app.NotificationManager) c.getSystemService(Context.NOTIFICATION_SERVICE);
            fs = nm != null && nm.canUseFullScreenIntent();
        }
        r.put("fullScreen", fs);
        r.put("configured", !TextUtils.isEmpty(c.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString("token", "")));
        return r;
    }
}
