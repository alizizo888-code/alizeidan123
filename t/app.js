(function () {
  'use strict';
  var API = '../api/';
  var view = document.getElementById('view');
  var msg = document.getElementById('msg');
  var who = document.getElementById('who');
  var out = document.getElementById('out');
  var aroundBtn = document.getElementById('around');
  var showingAround = false;
  var steps = {};
  var replies = [];
  var openChat = null;
  var lastSeen = {};

  function say(text, good) {
    msg.textContent = text;
    msg.className = 'msg show ' + (good ? 'good' : 'bad');
    if (good) { setTimeout(function () { msg.className = 'msg'; }, 2500); }
  }
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  async function api(path, body) {
    var res = await fetch(API + path, {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined
    });
    var data = await res.json().catch(function () { return {}; });
    if (!res.ok || data.ok === false) {
      var e = new Error(data.error || 'error');
      e.code = data.error;
      throw e;
    }
    return data;
  }

  var SAYS = {
    bad_invite: 'الرابط ده مش شغال أو انتهت مدته. اطلب واحد جديد.',
    not_technician: 'الحساب ده مش حساب فني.',
    bad_phone: 'اكتب رقم جوالك صح.',
    not_on_the_list: 'رقمك مش مسجّل عندنا كفني. كلّم المشرف يضيفك الأول.',
    already_registered: 'رقمك مسجّل على جهاز تاني. كلّم المشرف يفصله عشان تسجّل من هنا.',
    not_your_job: 'الطلب ده مش بتاعك.',
    job_closed: 'الطلب ده خلص خلاص.',
    bad_step: 'الخطوة دي مش معروفة.',
    bad_location: 'تعذّر تحديد موقعك.',
    bad_reply: 'الرد ده مش معروف.',
    note_required: 'لازم تكتب التفاصيل.',
    bad_note: 'التفاصيل فيها رموز مش مسموحة.',
    bad_message: 'الرسالة فاضية أو طويلة أوي.',
    job_closed: 'الطلب ده خلص خلاص.',
    not_your_job: 'الطلب ده مش بتاعك.'
  };

  /**
   * A short sound, made in the browser rather than fetched, so a new job is heard in a van with the
   * radio on and nothing has to be downloaded first.
   */
  function ring() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) { return; }
      var ctx = new Ctx();
      [0, 0.18].forEach(function (at) {
        var o = ctx.createOscillator();
        var g = ctx.createGain();
        o.type = 'sine';
        o.frequency.value = 880;
        g.gain.setValueAtTime(0.0001, ctx.currentTime + at);
        g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + at + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.14);
        o.connect(g); g.connect(ctx.destination);
        o.start(ctx.currentTime + at); o.stop(ctx.currentTime + at + 0.16);
      });
      if (navigator.vibrate) { navigator.vibrate([120, 60, 120]); }
    } catch (e) { /* a phone that will not make a sound is not a reason to fail */ }
  }

  function replyRow(j) {
    if (!replies.length) { return ''; }
    return '<details class="replies"><summary>قول حاجة</summary><div class="row">'
      + replies.map(function (r) {
          return '<button class="btn ghost" data-reply="' + esc(r.id) + '" data-job="' + esc(j.id) + '"'
            + (r.needs_note ? ' data-note="1"' : '') + '>' + esc(r.label) + '</button>';
        }).join('')
      + '</div></details>';
  }

  function chatRow(j) {
    var open = openChat === j.id;
    return '<details class="chat"' + (open ? ' open' : '') + ' data-chat="' + esc(j.id) + '">'
      + '<summary>المحادثة مع العمليات</summary>'
      + '<div class="thread" id="thread-' + esc(j.id) + '">' + (open ? 'جارٍ التحميل…' : '') + '</div>'
      + '<div class="row"><input class="field" id="say-' + esc(j.id) + '" maxlength="500" placeholder="اكتب رسالة">'
      + '<button class="btn" data-send="' + esc(j.id) + '">إرسال</button></div></details>';
  }

  function jobCard(j) {
    var phone = j.customer.phone ? String(j.customer.phone).replace(/[^\d+]/g, '') : '';
    var map = (j.lat !== null && j.lng !== null)
      ? '<a class="btn ghost" href="https://maps.google.com/?q=' + j.lat + ',' + j.lng + '" target="_blank" rel="noopener">الموقع على الخريطة</a>' : '';
    var call = phone ? '<a class="btn ghost" href="tel:' + esc(phone) + '">اتصال بالعميل</a>' : '';
    var next = j.status === 'assigned'
      ? '<button class="btn" data-step="in_progress" data-id="' + esc(j.id) + '">بدأت الشغل</button>'
      : '<button class="btn ok" data-step="done" data-id="' + esc(j.id) + '">خلصت</button>';
    return '<div class="job">'
      + '<h2>' + esc(j.service) + '</h2>'
      + '<div class="meta">' + esc(j.customer.name) + (j.address ? ' — ' + esc(j.address) : '') + '</div>'
      + '<div class="tags"><span class="pill">' + esc(j.status_label) + '</span>'
      + (j.price !== null ? ' <span class="pill">' + j.price + ' ر.س</span>' : '') + '</div>'
      + '<div class="row">' + next + call + map + '</div>'
      + replyRow(j) + chatRow(j) + '</div>';
  }

  /**
   * The first-time screen. A technician's number is already on the owner's list before they ever
   * open this, so the number is the whole sign-in: nothing to wait for and nothing to be sent.
   */
  function signInScreen() {
    out.hidden = true;
    who.textContent = 'شغلي';
    view.innerHTML = '<div class="job">'
      + '<h2>سجّل دخولك</h2>'
      + '<div class="meta">اكتب رقم جوالك اللي عند المشرف. مرة واحدة بس، وبعدها الجهاز ده يفضل داخل.</div>'
      + '<input class="field" id="phone" type="tel" inputmode="tel" dir="ltr" autocomplete="tel" placeholder="05xxxxxxxx" maxlength="18">'
      + '<div class="row"><button class="btn" id="go">دخول</button></div>'
      + '</div>';
  }

  async function show() {
    try {
      var me = await api('tech/me');
      steps = me.steps || {};
      if (!replies.length) {
        try { replies = (await api('tech/replies')).replies || []; } catch (e) { replies = []; }
      }
      who.textContent = 'شغل ' + me.technician.name;
      out.hidden = false;
      aroundBtn.hidden = false;
      if (showingAround) { return drawAround(); }
      var data = await api('tech/jobs');
      // A job that was not there a minute ago is worth hearing about, not just seeing.
      var ids = data.jobs.map(function (j) { return j.id; });
      var fresh = ids.filter(function (id) { return !lastSeen[id]; });
      if (Object.keys(lastSeen).length && fresh.length) { ring(); }
      lastSeen = {};
      ids.forEach(function (id) { lastSeen[id] = true; });
      view.innerHTML = data.jobs.length
        ? data.jobs.map(jobCard).join('')
        : '<p class="empty">مفيش شغل مفتوح عندك دلوقتي.<br>هيوصلك هنا أول ما المشرف يسندلك طلب.</p>';
      sendWhereIAm();
      if (openChat) { loadChat(openChat); }
    } catch (e) {
      if (e.code === 'no_tech_session' || e.code === 'not_technician') {
        signInScreen();
        return;
      }
      out.hidden = true;
      view.innerHTML = '<p class="empty">تعذّر الاتصال. جرّب تاني.</p>';
    }
  }

  /** Where the technician is, so the map and nearest-first mean something. Silent either way. */
  function sendWhereIAm() {
    if (!navigator.geolocation) { return; }
    navigator.geolocation.getCurrentPosition(function (p) {
      api('tech/where', { lat: p.coords.latitude, lng: p.coords.longitude }).catch(function () {});
    }, function () {}, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
  }

  /**
   * What is around the technician right now.
   *
   * A technician whose compressor has failed should not be ringing the operations room to ask where
   * to buy one, and one whose job turns out to be somebody else's speciality should be able to see
   * who is free nearby. Distances are straight-line: enough to choose between two shops, never a
   * promise about the drive.
   */
  async function drawAround() {
    showingAround = true;
    try {
      var d = await api('tech/around');
      var mine = d.jobs.map(function (j) {
        return row('🔧', j.service + (j.address ? ' — ' + j.address : ''), j.status_label, j.lat, j.lng);
      }).join('');
      var mates = d.mates.map(function (m) {
        return row('👷', m.name, (m.km === null ? '' : m.km + ' كم · ') + statusWord(m.status), m.lat, m.lng);
      }).join('');
      var shops = d.places.map(function (p) {
        return row('🛠', p.name + (p.phone ? ' · ' + p.phone : ''), (p.km === null ? p.kind_label : p.km + ' كم'), p.lat, p.lng, p.phone);
      }).join('');
      view.innerHTML = '<div class="job"><h2>شغلي</h2>' + (mine || '<div class="meta">مفيش شغل مفتوح.</div>') + '</div>'
        + '<div class="job"><h2>فنيين حواليك</h2>' + (mates || '<div class="meta">مفيش فنيين بموقع محدّث.</div>') + '</div>'
        + '<div class="job"><h2>أقرب محلات قطع غيار</h2>' + (shops || '<div class="meta">العمليات لسه ما ضافتش محلات.</div>') + '</div>';
    } catch (e) {
      view.innerHTML = '<p class="empty">تعذّر تحميل اللي حواليك.</p>';
    }
  }

  function statusWord(s) {
    return { busy: 'مشغول', available: 'فاضي', stale: 'موقعه قديم', offline: 'غير متصل' }[s] || s;
  }

  /** One line on the around-me screen: what it is, where, and the two things worth tapping. */
  function row(icon, title, note, lat, lng, phone) {
    var map = (lat !== null && lat !== undefined)
      ? '<a class="btn ghost sm" href="https://maps.google.com/?q=' + lat + ',' + lng + '" target="_blank" rel="noopener">الطريق</a>' : '';
    var call = phone ? '<a class="btn ghost sm" href="tel:' + esc(String(phone).replace(/[^\d+]/g, '')) + '">اتصال</a>' : '';
    return '<div class="around"><div class="around-what">' + icon + ' <b>' + esc(title) + '</b>'
      + (note ? '<small>' + esc(note) + '</small>' : '') + '</div>' + map + call + '</div>';
  }

  /** The conversation on one job: operations and the technician read and write the same thread. */
  async function loadChat(jobId) {
    var box = document.getElementById('thread-' + jobId);
    if (!box) { return; }
    try {
      var data = await api('tech/' + encodeURIComponent(jobId) + '/chat');
      box.innerHTML = data.messages.length
        ? data.messages.map(function (m) {
            return '<div class="line ' + (m.sender === 'technician' ? 'mine' : '') + '">'
              + '<b>' + esc(m.sender_label) + '</b> ' + esc(m.body) + '</div>';
          }).join('')
        : '<p class="meta">مفيش رسائل لسه.</p>';
      box.scrollTop = box.scrollHeight;
    } catch (e) {
      box.innerHTML = '<p class="meta">تعذّر تحميل المحادثة.</p>';
    }
  }

  document.addEventListener('toggle', function (ev) {
    var d = ev.target;
    if (d && d.tagName === 'DETAILS' && d.dataset.chat) {
      openChat = d.open ? d.dataset.chat : null;
      if (d.open) { loadChat(d.dataset.chat); }
    }
  }, true);

  document.addEventListener('click', async function (ev) {
    var r = ev.target.closest('[data-reply]');
    if (r) {
      var note = '';
      if (r.dataset.note) {
        note = (window.prompt('اكتب التفاصيل:') || '').trim();
        if (!note) { return; }
      }
      r.disabled = true;
      try {
        var res = await api('tech/say/' + encodeURIComponent(r.dataset.job), { reply: r.dataset.reply, note: note });
        say(res.handed_back ? 'اتبعت للعمليات.' : 'اتسجل.', true);
        await show();
      } catch (e) {
        r.disabled = false;
        say(SAYS[e.code] || 'ما قدرناش نبعتها. جرّب تاني.');
      }
      return;
    }
    var sendBtn = ev.target.closest('[data-send]');
    if (sendBtn) {
      var jobId = sendBtn.dataset.send;
      var box = document.getElementById('say-' + jobId);
      var text = ((box && box.value) || '').trim();
      if (!text) { return; }
      sendBtn.disabled = true;
      try {
        await api('tech/' + encodeURIComponent(jobId) + '/chat', { body: text });
        if (box) { box.value = ''; }
        await loadChat(jobId);
      } catch (e) {
        say(SAYS[e.code] || 'الرسالة ما اتبعتتش.');
      }
      sendBtn.disabled = false;
      return;
    }
    var b = ev.target.closest('[data-step]');
    if (b) {
      b.disabled = true;
      try {
        await api('tech/job/' + encodeURIComponent(b.dataset.id), { step: b.dataset.step });
        say(b.dataset.step === 'done' ? 'تمام، اتسجل إنك خلصت.' : 'اتسجل إنك بدأت.', true);
        await show();
      } catch (e) {
        b.disabled = false;
        say(SAYS[e.code] || 'ما قدرناش نسجل ده. جرّب تاني.');
      }
      return;
    }
    if (ev.target.id === 'go') {
      var box = document.getElementById('phone');
      var phone = ((box && box.value) || '').trim();
      ev.target.disabled = true;
      try {
        await api('tech/register', { phone: phone });
        await show();
      } catch (e) {
        ev.target.disabled = false;
        say(SAYS[e.code] || 'تعذّر الدخول. جرّب تاني.');
      }
      return;
    }
    if (ev.target === aroundBtn) {
      if (showingAround) { showingAround = false; return show(); }
      return drawAround();
    }
    if (ev.target === out) {
      await api('tech/signout', {}).catch(function () {});
      location.hash = '';
      location.reload();
    }
  });

  // A tapped invitation arrives in the fragment, which browsers never send to a server or a log.
  // It is spent at once and wiped from the address bar so the link cannot be reused from history.
  (async function start() {
    var invite = (location.hash || '').replace(/^#/, '').trim();
    if (/^[a-f0-9]{48}$/.test(invite)) {
      history.replaceState(null, '', location.pathname);
      try { await api('tech/claim', { invite: invite }); }
      catch (e) { view.innerHTML = '<p class="empty">' + esc(SAYS[e.code] || 'الرابط مش شغال.') + '</p>'; return; }
    }
    show();
    setInterval(show, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { show(); } });
  })();
})();
