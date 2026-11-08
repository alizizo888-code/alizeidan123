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
    no_tech_session: 'محتاج الرابط اللي بعتهولك المشرف عشان تدخل.',
    bad_invite: 'الرابط ده مش شغال أو انتهت مدته. اطلب رابط جديد.',
    not_technician: 'الحساب ده مش حساب فني.',
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
      out.hidden = true;
      if (e.code === 'no_tech_session' || e.code === 'bad_invite' || e.code === 'not_technician') {
        view.innerHTML = '<p class="empty">' + esc(SAYS[e.code] || 'محتاج تسجيل دخول.') + '</p>';
        return;
      }
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
