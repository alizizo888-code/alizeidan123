#!/usr/bin/env python3
"""Adds the Oxygen native layer to a freshly generated Capacitor Android project (run from native/<app>).

  - MqNative plugin + WhatsApp notification reader (java/), registered in MainActivity
  - permissions: background location, foreground service, notifications, battery, boot, audio, camera
  - <queries> so Android 11+ lets the app open Waze, Google Maps, Termux and WhatsApp
"""
import glob, os, re, shutil, sys

root = 'android/app/src/main'
pkg_dir = os.path.join(root, 'java', 'com', 'oxygen11', 'mqnative')
os.makedirs(pkg_dir, exist_ok=True)
here = os.path.dirname(os.path.abspath(__file__))
for f in glob.glob(os.path.join(here, 'java', '*.java')):
    shutil.copy(f, pkg_dir)
# Alert tones (tools/gen_tones.py): one per kind of alert, used by the notification channels.
raw_dir = os.path.join(root, 'res', 'raw')
os.makedirs(raw_dir, exist_ok=True)
for f in glob.glob(os.path.join(here, 'raw', '*.wav')):
    shutil.copy(f, raw_dir)

# MainActivity: register the plugin (local plugins are not picked up by `cap sync`).
main = glob.glob(os.path.join(root, 'java', '**', 'MainActivity.java'), recursive=True)
if not main:
    sys.exit('MainActivity.java not found')
m = main[0]
src = open(m, encoding='utf-8').read()
if 'MqNativePlugin' not in src:
    pkg = re.search(r'^package\s+([\w.]+);', src, re.M).group(1)
    src = ('package %s;\n\nimport android.os.Bundle;\nimport com.getcapacitor.BridgeActivity;\nimport com.oxygen11.mqnative.MqNativePlugin;\n\n'
           'public class MainActivity extends BridgeActivity {\n'
           '    @Override\n    public void onCreate(Bundle savedInstanceState) {\n'
           '        registerPlugin(MqNativePlugin.class);\n        super.onCreate(savedInstanceState);\n    }\n}\n') % pkg
    open(m, 'w', encoding='utf-8').write(src)

man = os.path.join(root, 'AndroidManifest.xml')
x = open(man, encoding='utf-8').read()
perms = ['INTERNET', 'RECORD_AUDIO', 'MODIFY_AUDIO_SETTINGS', 'ACCESS_FINE_LOCATION', 'ACCESS_COARSE_LOCATION',
         'ACCESS_BACKGROUND_LOCATION', 'FOREGROUND_SERVICE', 'FOREGROUND_SERVICE_LOCATION', 'WAKE_LOCK',
         'POST_NOTIFICATIONS', 'CAMERA', 'VIBRATE', 'RECEIVE_BOOT_COMPLETED', 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS',
         'FOREGROUND_SERVICE_REMOTE_MESSAGING', 'USE_FULL_SCREEN_INTENT', 'ACCESS_NOTIFICATION_POLICY']
for p in perms:
    if 'android.permission.%s"' % p not in x:
        x = x.replace('<application', '<uses-permission android:name="android.permission.%s" />\n    <application' % p, 1)
if '<queries>' not in x:
    q = '<queries>\n'
    for p in ['com.waze', 'com.google.android.apps.maps', 'com.termux', 'com.whatsapp', 'com.whatsapp.w4b']:
        q += '        <package android:name="%s" />\n' % p
    q += ('        <intent><action android:name="android.intent.action.VIEW" /><data android:scheme="waze" /></intent>\n'
          '        <intent><action android:name="android.intent.action.VIEW" /><data android:scheme="google.navigation" /></intent>\n'
          '        <intent><action android:name="android.intent.action.VIEW" /><data android:scheme="geo" /></intent>\n'
          '    </queries>\n    <application')
    x = x.replace('<application', q, 1)
if 'MqPushService' not in x:
    x = x.replace('</application>', '''
        <service android:name="com.oxygen11.mqnative.MqPushService" android:exported="false" android:foregroundServiceType="remoteMessaging" />
        <receiver android:name="com.oxygen11.mqnative.MqBootReceiver" android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.BOOT_COMPLETED" />
                <action android:name="android.intent.action.MY_PACKAGE_REPLACED" />
            </intent-filter>
        </receiver>
    </application>''', 1)
# Calls light up and show over the lock screen when the app is opened from the full-screen notification.
if 'showWhenLocked' not in x:
    x = re.sub(r'(<activity\b[^>]*android:name="\.?MainActivity")', r'\1 android:showWhenLocked="true" android:turnScreenOn="true"', x, count=1)
if 'MqNotifyService' not in x:
    svc = ('\n        <service android:name="com.oxygen11.mqnative.MqNotifyService" android:exported="false"\n'
           '            android:label="قراءة إشعارات الواتساب" android:permission="android.permission.BIND_NOTIFICATION_LISTENER_SERVICE">\n'
           '            <intent-filter><action android:name="android.service.notification.NotificationListenerService" /></intent-filter>\n'
           '        </service>\n    </application>')
    x = x.replace('</application>', svc, 1)
open(man, 'w', encoding='utf-8').write(x)
print('native layer applied:', m)
