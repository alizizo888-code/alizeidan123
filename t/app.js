(function () {
  'use strict';
  var API = '../api/';
  var view = document.getElementById('view');
  var msg = document.getElementById('msg');
  var who = document.getElementById('who');
  var out = document.getElementById('out');
  var steps = {};

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
    bad_location: 'تعذّر تحديد موقعك.'
  };

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
      + '<div class="row">' + next + call + map + '</div></div>';
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
      who.textContent = 'شغل ' + me.technician.name;
      out.hidden = false;
      var data = await api('tech/jobs');
      view.innerHTML = data.jobs.length
        ? data.jobs.map(jobCard).join('')
        : '<p class="empty">مفيش شغل مفتوح عندك دلوقتي.<br>هيوصلك هنا أول ما المشرف يسندلك طلب.</p>';
      sendWhereIAm();
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

  document.addEventListener('click', async function (ev) {
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
