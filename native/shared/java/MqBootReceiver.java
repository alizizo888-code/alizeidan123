package com.oxygen11.mqnative;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** After a reboot or an app update the alert service comes back by itself — nobody has to open the app first. */
public class MqBootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context c, Intent i) { MqPushService.start(c); }
}
