package com.oxygen11.mqnative;

import android.app.Notification;
import android.content.Context;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;

/**
 * Reads WhatsApp / WhatsApp Business notifications on a team phone (only after the person turned it on in
 * Android's own «Notification access» screen) and sends them to the site in small batches, so a message that
 * reached a technician's or a supervisor's own WhatsApp still shows up in the operations room. Nothing from any
 * other app is read. Batches every 5 seconds at most, so a busy group does not mean a request per message.
 */
public class MqNotifyService extends NotificationListenerService {
    private static final List<JSONObject> pending = new ArrayList<>();
    private static long lastSend = 0;
    private static boolean scheduled = false;
    private final Handler handler = new Handler(Looper.getMainLooper());

    @Override
    public void onNotificationPosted(StatusBarNotification sbn) {
        String pkg = sbn.getPackageName();
        if (!"com.whatsapp".equals(pkg) && !"com.whatsapp.w4b".equals(pkg)) return;
        SharedPreferences p = getSharedPreferences(MqNativePlugin.PREFS, Context.MODE_PRIVATE);
        if (!p.getBoolean("readWhatsApp", true) || p.getString("token", "").isEmpty()) return;
        Notification n = sbn.getNotification();
        if (n == null || (n.flags & Notification.FLAG_GROUP_SUMMARY) != 0) return;
        Bundle x = n.extras;
        CharSequence title = x.getCharSequence(Notification.EXTRA_TITLE);
        CharSequence text = x.getCharSequence(Notification.EXTRA_TEXT);
        if (text == null) return;
        try {
            JSONObject o = new JSONObject();
            o.put("app", pkg);
            o.put("title", title == null ? "" : title.toString());
            o.put("text", text.toString());
            o.put("at", sbn.getPostTime());
            synchronized (pending) { pending.add(o); if (pending.size() > 50) pending.remove(0); }
        } catch (Exception e) { return; }
        long now = System.currentTimeMillis();
        if (now - lastSend >= 5000) { lastSend = now; flush(p); }
        else if (!scheduled) {
            scheduled = true;
            handler.postDelayed(() -> { scheduled = false; lastSend = System.currentTimeMillis(); flush(p); }, 5000 - (now - lastSend));
        }
    }

    private void flush(final SharedPreferences p) {
        final JSONArray items = new JSONArray();
        synchronized (pending) { for (JSONObject o : pending) items.put(o); pending.clear(); }
        if (items.length() == 0) return;
        new Thread(() -> {
            HttpURLConnection c = null;
            try {
                JSONObject body = new JSONObject();
                body.put("op", "notifs");
                body.put("items", items);
                body.put("device", p.getString("device", ""));
                c = (HttpURLConnection) new URL(p.getString("endpoint", "") + "/native").openConnection();
                c.setRequestMethod("POST");
                c.setConnectTimeout(10000);
                c.setReadTimeout(15000);
                c.setDoOutput(true);
                c.setRequestProperty("Content-Type", "application/json");
                c.setRequestProperty("X-Mutqan-Token", p.getString("token", ""));
                try (OutputStream os = c.getOutputStream()) { os.write(body.toString().getBytes(StandardCharsets.UTF_8)); }
                c.getResponseCode();
            } catch (Exception e) {
                // offline: keep them for the next notification
                synchronized (pending) { for (int i = 0; i < items.length() && pending.size() < 50; i++) pending.add(items.optJSONObject(i)); }
            } finally { if (c != null) c.disconnect(); }
        }).start();
    }
}
