package com.oxygen11.mqnative;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.ServiceInfo;
import android.media.AudioAttributes;
import android.media.MediaPlayer;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.IBinder;
import android.os.PowerManager;
import android.util.Base64;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/**
 * Keeps alerts coming when the phone is idle or the screen is off.
 *
 * Inside the app the page itself polls for alerts, but Android freezes the page as soon as the screen goes off, so a
 * new order, a message or a hotline call made no sound until somebody opened the app. This service runs on its own
 * (a quiet permanent notification «متقن متصل»), asks the site's alert inbox every 15 seconds, and posts each alert as
 * a real Android notification: orders and messages with sound and vibration on a high-priority channel, calls with
 * the phone's ringtone, full-screen, ringing until answered (up to 45 s), and the screen switched on. While the app is
 * open on screen it stays silent — the page shows and rings itself, so nothing is doubled.
 * Restarts after reboot (MqBootReceiver). No Firebase, no third party: the same inbox the page uses.
 */
public class MqPushService extends Service {
    static final String CH_ALERTS = "mq_alerts2";
    static final String CH_CALLS = "mq_calls2";
    static final String CH_SERVICE = "mq_service";
    // «التنبيه الصوتي» (owner, 2026-11-22): the alert is SAID by the app (a loud male voice, the site's clip), so its
    // notification comes on a channel with vibration and no tone of its own — one sound, the sentence.
    static final String CH_VOICE = "mq_voice";
    // One channel per kind of alert, each with its own tone (res/raw, made by tools/gen_tones.py), so the ear knows what
    // came: a new order, a message, the technicians' moves, the operations room, the customer's own order.
    static final String[][] KINDS = {
        {"order", "mq_k_order", "طلب جديد", "mq_order"},
        {"msg_in", "mq_k_msg", "رسالة واردة", "mq_msg_in"},
        {"tech", "mq_k_tech", "حركة الفنيين", "mq_tech"},
        {"ops", "mq_k_ops", "تنبيهات العمليات", "mq_ops"},
        {"customer", "mq_k_cust", "تحديثات طلبي", "mq_customer"},
    };
    static final long EVERY_MS = 15000;
    static volatile boolean appVisible = false;
    static volatile boolean running = false;
    private HandlerThread thread;
    private Handler handler;
    private PowerManager.WakeLock cpu;
    private int nextId = 1000;

    public static void start(Context c) {
        SharedPreferences p = c.getSharedPreferences(MqNativePlugin.PREFS, Context.MODE_PRIVATE);
        if (p.getString("endpoint", "").isEmpty()) return;
        Intent i = new Intent(c, MqPushService.class);
        try {
            if (Build.VERSION.SDK_INT >= 26) c.startForegroundService(i); else c.startService(i);
        } catch (Exception e) { /* not allowed right now (background start); the app starts it when opened */ }
    }

    @Override
    public void onCreate() {
        super.onCreate();
        channels(this);
        Notification n = base(this, CH_SERVICE).setContentTitle("متقن متصل").setContentText("التنبيهات والمكالمات شغالة والشاشة مقفولة")
            .setSmallIcon(android.R.drawable.stat_notify_sync_noanim).setOngoing(true).setPriority(Notification.PRIORITY_MIN).build();
        if (Build.VERSION.SDK_INT >= 34) startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING);
        else startForeground(1, n);
        PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
        cpu = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "mutqan:inbox");
        thread = new HandlerThread("mq-inbox");
        thread.start();
        handler = new Handler(thread.getLooper());
        running = true;
        handler.post(tick);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) { return START_STICKY; }

    @Override
    public IBinder onBind(Intent intent) { return null; }

    @Override
    public void onDestroy() {
        running = false;
        if (handler != null) handler.removeCallbacksAndMessages(null);
        if (thread != null) thread.quitSafely();
        try { if (cpu != null && cpu.isHeld()) cpu.release(); } catch (Exception e) { /* ignore */ }
        super.onDestroy();
    }

    // The CPU is kept awake between polls: a sleeping CPU does not count time, and a 15-second wait would become hours.
    private final Runnable tick = new Runnable() {
        @Override public void run() {
            try { cpu.acquire(EVERY_MS + 20000); } catch (Exception e) { /* ignore */ }
            try { poll(); } catch (Exception e) { /* offline: next time */ }
            if (running) handler.postDelayed(this, EVERY_MS);
        }
    };

    private void poll() throws Exception {
        SharedPreferences p = getSharedPreferences(MqNativePlugin.PREFS, Context.MODE_PRIVATE);
        String endpoint = p.getString("endpoint", "");
        if (endpoint.isEmpty()) return;
        JSONObject body = new JSONObject();
        body.put("op", "inbox");
        String since = p.getString("since", "");
        if (!since.isEmpty()) body.put("since", since);
        HttpURLConnection c = (HttpURLConnection) new URL(endpoint + "/push").openConnection();
        c.setRequestMethod("POST");
        c.setConnectTimeout(10000);
        c.setReadTimeout(15000);
        c.setDoOutput(true);
        c.setRequestProperty("Content-Type", "application/json");
        String token = p.getString("token", ""), anon = p.getString("anon", "");
        if (!token.isEmpty()) c.setRequestProperty("X-Mutqan-Token", token);
        if (!anon.isEmpty()) c.setRequestProperty("X-Mutqan-Anon", anon);
        try (OutputStream os = c.getOutputStream()) { os.write(body.toString().getBytes(StandardCharsets.UTF_8)); }
        if (c.getResponseCode() != 200) { c.disconnect(); return; }
        StringBuilder sb = new StringBuilder();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(c.getInputStream(), StandardCharsets.UTF_8))) { String l; while ((l = r.readLine()) != null) sb.append(l); }
        c.disconnect();
        JSONObject res = new JSONObject(sb.toString());
        JSONObject data = res.has("data") ? res.optJSONObject("data") : res;
        if (data == null) return;
        JSONArray items = data.optJSONArray("items");
        String seen = p.getString("seen", "");
        StringBuilder keep = new StringBuilder(seen);
        boolean first = since.isEmpty();
        if (items != null) {
            for (int i = 0; i < items.length(); i++) {
                JSONObject m = items.optJSONObject(i);
                if (m == null) continue;
                String id = m.optString("id");
                if (id.isEmpty() || seen.contains("|" + id + "|")) continue;
                keep.append("|").append(id).append("|");
                if (!first && !appVisible) show(m);
            }
        }
        String k = keep.toString();
        if (k.length() > 6000) k = k.substring(k.length() - 6000);
        p.edit().putString("seen", k).putString("since", data.optString("now", since)).apply();
    }

    /** Which kind (and so which channel / tone) an alert is. A customer hears his own order's tone for every status. */
    static String kindOf(JSONObject m, String role) {
        String k = m.optString("kind", "");
        if (!k.isEmpty()) return k;
        String ev = m.optString("event", "");
        if (ev.contains("call")) return "call";
        if (ev.matches(".*(msg|message|chat|phone_wa).*")) return "msg_in";
        boolean staff = role != null && !role.isEmpty() && !"client".equals(role);
        if (!staff) return "customer";
        if (ev.matches(".*(new_order|order_bot|wa_handoff|warranty_claim|test).*")) return "order";
        if (ev.matches(".*(assigned|accepted|rejected|en_route|nearby|arrived|started|progress|completed|contact|distance|order_status|tech_application).*")) return "tech";
        return "high".equals(m.optString("priority")) && ev.isEmpty() ? "order" : "ops";
    }

    static String channelOf(String kind) {
        for (String[] k : KINDS) if (k[0].equals(kind)) return k[1];
        return CH_ALERTS;
    }

    /** The spoken clip for a key, from the site once and then from the phone (refreshed every two weeks); null if none. */
    private File voiceFile(String key) {
        if (key == null || !key.matches("[a-z_]{2,30}")) return null;
        File dir = new File(getFilesDir(), "voice");
        File f = new File(dir, key + ".wav");
        if (f.exists() && f.length() > 2000 && System.currentTimeMillis() - f.lastModified() < 14L * 86400000L) return f;
        String endpoint = getSharedPreferences(MqNativePlugin.PREFS, Context.MODE_PRIVATE).getString("endpoint", "");
        if (endpoint.isEmpty()) return f.exists() ? f : null;
        try {
            dir.mkdirs();
            HttpURLConnection c = (HttpURLConnection) new URL(endpoint + "/voice?k=" + key).openConnection();
            c.setConnectTimeout(8000); c.setReadTimeout(15000);
            if (c.getResponseCode() != 200) { c.disconnect(); return f.exists() ? f : null; }
            File tmp = new File(dir, key + ".part");
            try (InputStream in = c.getInputStream(); FileOutputStream out = new FileOutputStream(tmp)) {
                byte[] b = new byte[8192]; int n; while ((n = in.read(b)) > 0) out.write(b, 0, n);
            }
            c.disconnect();
            if (tmp.length() > 2000 && tmp.renameTo(f)) return f;
        } catch (Exception e) { /* offline: the tone channel instead */ }
        return f.exists() ? f : null;
    }

    /** Plays the sentence loud, on the alarm stream (heard with the phone on silent), once or twice. */
    private synchronized void say(File clip, int times) {
        try {
            long wait = busyUntil - System.currentTimeMillis();   // runs on the poll thread: the next alert waits for this one
            if (wait > 0 && wait < 30000) Thread.sleep(wait + 400);
            final int[] left = {Math.max(1, Math.min(3, times))};
            MediaPlayer mp = new MediaPlayer();
            mp.setAudioAttributes(new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build());
            mp.setDataSource(clip.getAbsolutePath());
            mp.setVolume(1f, 1f);
            mp.setOnCompletionListener(p -> { if (--left[0] > 0) { try { p.seekTo(0); p.start(); } catch (Exception e) { p.release(); } } else p.release(); });
            mp.setOnErrorListener((p, w, x) -> { p.release(); return true; });
            mp.prepare();
            busyUntil = System.currentTimeMillis() + (long) mp.getDuration() * Math.max(1, times) + 300;
            mp.start();
        } catch (Exception e) { /* the notification still shows */ }
    }

    // «التنبيه يقول نفسه» (owner, 2026-11-22): the spoken sentence of THIS alert (what happened, who, which order, the
    // message's text, which report), in its urgency's voice — made by the site (POST /push {op:'say'}). Offline or
    // while the site's voice is busy: the short clip for its kind, then the phone's own Arabic voice reads the details.
    private String lastSaid = ""; private long lastSaidAt = 0; private long busyUntil = 0;
    /** {file, tier, text} for this alert from the site; file null when the site has no clip right now. */
    private Object[] sentenceFile(JSONObject m) {
        String id = m.optString("id", ""), tier = m.optString("tier", "normal"), text = m.optString("speak", "");
        if (id.isEmpty() || !id.matches("[A-Za-z0-9_\\-]{3,80}")) return new Object[]{null, tier, text};
        File dir = new File(getFilesDir(), "voice/say"); dir.mkdirs();
        File f = new File(dir, id + ".wav");
        if (f.exists() && f.length() > 2000) return new Object[]{f, tier, text};
        SharedPreferences p = getSharedPreferences(MqNativePlugin.PREFS, Context.MODE_PRIVATE);
        String endpoint = p.getString("endpoint", ""), token = p.getString("token", "");
        if (endpoint.isEmpty() || token.isEmpty()) return new Object[]{null, tier, text};
        try {
            HttpURLConnection c = (HttpURLConnection) new URL(endpoint + "/push").openConnection();
            c.setRequestMethod("POST"); c.setConnectTimeout(8000); c.setReadTimeout(30000); c.setDoOutput(true);
            c.setRequestProperty("Content-Type", "application/json"); c.setRequestProperty("X-Mutqan-Token", token);
            JSONObject body = new JSONObject(); body.put("op", "say"); body.put("id", id);
            try (OutputStream os = c.getOutputStream()) { os.write(body.toString().getBytes(StandardCharsets.UTF_8)); }
            if (c.getResponseCode() != 200) { c.disconnect(); return new Object[]{null, tier, text}; }
            StringBuilder sb = new StringBuilder();
            try (BufferedReader r = new BufferedReader(new InputStreamReader(c.getInputStream(), StandardCharsets.UTF_8))) { String l; while ((l = r.readLine()) != null) sb.append(l); }
            c.disconnect();
            JSONObject res = new JSONObject(sb.toString()); JSONObject d = res.has("data") ? res.optJSONObject("data") : res;
            if (d == null) return new Object[]{null, tier, text};
            tier = d.optString("tier", tier); text = d.optString("text", text);
            String wav = d.optString("wav", "");
            if (wav.isEmpty()) return new Object[]{null, tier, text};
            byte[] bytes = Base64.decode(wav, Base64.DEFAULT);
            if (bytes.length < 2000) return new Object[]{null, tier, text};
            try (FileOutputStream out = new FileOutputStream(f)) { out.write(bytes); }
            File[] old = dir.listFiles();   // keep the last few days only
            if (old != null) for (File o : old) if (System.currentTimeMillis() - o.lastModified() > 3L * 86400000L) o.delete();
            return new Object[]{f, tier, text};
        } catch (Exception e) { return new Object[]{null, tier, text}; }
    }

    private void show(JSONObject m) {
        String ev = m.optString("event", "");
        String role = getSharedPreferences(MqNativePlugin.PREFS, Context.MODE_PRIVATE).getString("role", "");
        boolean staff = role != null && !role.isEmpty() && !"client".equals(role);
        File clip = staff ? voiceFile(m.optString("voice", "")) : null;
        String kind = kindOf(m, getSharedPreferences(MqNativePlugin.PREFS, Context.MODE_PRIVATE).getString("role", ""));
        boolean call = ev.contains("call") || "call".equals(kind);
        boolean high = call || "high".equals(m.optString("priority"));
        Intent open = getPackageManager().getLaunchIntentForPackage(getPackageName());
        if (open == null) return;
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        open.putExtra("mq_url", m.optString("url", ""));
        int id = nextId++;
        PendingIntent pi = PendingIntent.getActivity(this, id, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification.Builder b = base(this, call ? CH_CALLS : clip != null ? CH_VOICE : channelOf(kind))
            .setContentTitle(m.optString("title", "تنبيه"))
            .setContentText(m.optString("body", ""))
            .setStyle(new Notification.BigTextStyle().bigText(m.optString("body", "")))
            .setSmallIcon(call ? android.R.drawable.stat_sys_phone_call : android.R.drawable.stat_notify_chat)
            .setContentIntent(pi).setAutoCancel(true)
            .setVisibility(Notification.VISIBILITY_PUBLIC)
            .setCategory(call ? Notification.CATEGORY_CALL : Notification.CATEGORY_MESSAGE)
            .setPriority(high ? Notification.PRIORITY_MAX : Notification.PRIORITY_HIGH);
        if (call) {
            b.setFullScreenIntent(pi, true);
            if (Build.VERSION.SDK_INT >= 26) b.setTimeoutAfter(45000);
        }
        Notification n = b.build();
        if (call) n.flags |= Notification.FLAG_INSISTENT;   // keeps ringing until opened or 45 s
        if (Build.VERSION.SDK_INT < 26) { n.defaults |= Notification.DEFAULT_VIBRATE; n.sound = RingtoneManager.getDefaultUri(call ? RingtoneManager.TYPE_RINGTONE : RingtoneManager.TYPE_NOTIFICATION); }
        ((NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE)).notify(id, n);
        if (high) wakeScreen();
        // ONE male voice, once, one alert after the other (owner: «الأصوات بتتكرر … صوت نسائي … أصوات فوق بعض»): no
        // repeats, no phone reader (it is a woman's voice on most phones), and the
        // same sentence twice within two minutes is said once.
        if (staff) {   // a call too: «مكالمة على الخط الساخن من الفني فلان / من العميل فلان / من عميل جديد»
            Object[] sf = sentenceFile(m);
            File f = sf[0] != null ? (File) sf[0] : clip;
            String said = String.valueOf(sf[2]);
            long now = System.currentTimeMillis();
            if (f != null && !(said.equals(lastSaid) && now - lastSaidAt < 120000)) { lastSaid = said; lastSaidAt = now; say(f, 1); }
        }
    }

    @SuppressWarnings("deprecation")
    private void wakeScreen() {
        try {
            PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
            if (pm.isInteractive()) return;
            PowerManager.WakeLock w = pm.newWakeLock(PowerManager.SCREEN_BRIGHT_WAKE_LOCK | PowerManager.ACQUIRE_CAUSES_WAKEUP | PowerManager.ON_AFTER_RELEASE, "mutqan:screen");
            w.acquire(8000);
        } catch (Exception e) { /* ignore */ }
    }

    @SuppressWarnings("deprecation")
    static Notification.Builder base(Context c, String ch) {
        return Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(c, ch) : new Notification.Builder(c);
    }

    static void channels(Context c) {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationManager nm = (NotificationManager) c.getSystemService(Context.NOTIFICATION_SERVICE);
        NotificationChannel a = new NotificationChannel(CH_ALERTS, "الطلبات والرسايل", NotificationManager.IMPORTANCE_HIGH);
        a.setDescription("طلب جديد · رسالة · تنبيه متابعة");
        a.enableVibration(true); a.setVibrationPattern(new long[]{0, 400, 200, 400});
        a.enableLights(true); a.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        a.setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION),
            new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
        a.setBypassDnd(true);
        NotificationChannel k = new NotificationChannel(CH_CALLS, "المكالمات والخط الساخن", NotificationManager.IMPORTANCE_HIGH);
        k.setDescription("مكالمة واردة — بترن زي التليفون");
        k.enableVibration(true); k.setVibrationPattern(new long[]{0, 1000, 600, 1000, 600, 1000});
        k.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        Uri ring = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE);
        k.setSound(ring, new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
        k.setBypassDnd(true);
        NotificationChannel v = new NotificationChannel(CH_VOICE, "التنبيهات الصوتية", NotificationManager.IMPORTANCE_HIGH);
        v.setDescription("التطبيق بيقول التنبيه بصوت: عندك طلب جديد، تم الدفع…");
        v.enableVibration(true); v.setVibrationPattern(new long[]{0, 500, 150, 500});
        v.enableLights(true); v.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        v.setSound(null, null); v.setBypassDnd(true);
        nm.createNotificationChannel(v);
        NotificationChannel s = new NotificationChannel(CH_SERVICE, "اتصال متقن", NotificationManager.IMPORTANCE_MIN);
        s.setShowBadge(false);
        nm.createNotificationChannel(a); nm.createNotificationChannel(k); nm.createNotificationChannel(s);
        AudioAttributes na = new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build();
        for (String[] kd : KINDS) {
            NotificationChannel ch = new NotificationChannel(kd[1], kd[2], NotificationManager.IMPORTANCE_HIGH);
            ch.setDescription("نغمة مخصوصة: " + kd[2]);
            ch.enableVibration(true);
            ch.setVibrationPattern("order".equals(kd[0]) ? new long[]{0, 500, 150, 500, 150, 900} : "tech".equals(kd[0]) ? new long[]{0, 120, 80, 120, 80, 300} : new long[]{0, 220, 120, 220});
            ch.enableLights(true); ch.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
            int res = c.getResources().getIdentifier(kd[3], "raw", c.getPackageName());
            ch.setSound(res != 0 ? Uri.parse("android.resource://" + c.getPackageName() + "/" + res) : RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION), na);
            ch.setBypassDnd(true);
            nm.createNotificationChannel(ch);
        }
    }
}
