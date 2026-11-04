(() => {
  'use strict';

  const app = document.getElementById('app');
  const toastEl = document.getElementById('toast');
  const pairDialog = document.getElementById('pair');
  const state = { csrf: null, timer: null, pairId: null, view: 'sites', preselect: null };

  // Sections of the panel. A new section is one line here plus its render function.
  const VIEWS = [
    ['sites', 'المواقع'],
    ['management', 'الإدارة'],
    ['settings', 'الإعدادات العامة'],
    ['bulk', 'الإعدادات الجماعية'],
    ['articles', 'المقالات'],
    ['ops', 'غرفة العمليات'],
  ];
  const AREAS = { makkah: 'مكة', jeddah: 'جدة', other: 'أخرى' };
  // [key, label, max length] for the general settings form. Must match General::FIELDS on the server.
  const GENERAL_FIELDS = [
    ['company.name', 'اسم الشركة', 120],
    ['company.tax_number', 'الرقم الضريبي', 30],
    ['company.phone', 'رقم التواصل الرئيسي', 20],
    ['company.service_area', 'نطاق الخدمة', 200],
    ['company.hours', 'ساعات العمل', 200],
  ];

  const STATUS = {
    online: ['متصل', 'ok'],
    down: ['لا يستجيب', 'bad'],
    unpaired: ['غير مربوط', 'warn'],
    unknown: ['بانتظار الفحص', 'idle'],
  };
  const ERRORS = {
    bad_pin: 'الرمز غير صحيح',
    locked: 'الدخول مقفل مؤقتاً. حاول بعد 15 دقيقة',
    no_pin: 'لم يتم ضبط الرمز بعد',
    no_session: 'انتهت الجلسة، أدخل الرمز من جديد',
    csrf: 'انتهت صلاحية الطلب، أعد المحاولة',
    forbidden: 'رمز الإعداد غير صحيح',
    pin_already_set: 'تم ضبط الرمز من قبل',
    pin_format: 'الرمز يتكون من 6 أرقام',
    secret_format: 'المفتاح يجب أن يكون 64 حرفاً ورقماً',
    network: 'تعذّر الاتصال بالخادم',
    bad_role: 'الدور غير معروف',
    bad_name: 'الاسم يجب أن يكون من حرفين إلى 80 حرفاً',
    bad_phone: 'رقم الجوال غير صحيح',
    bad_area: 'المنطقة غير معروفة',
    bad_value: 'قيمة غير مقبولة',
    too_long: 'النص أطول من المسموح',
    nothing_to_update: 'لا يوجد ما يُحفظ',
    not_found: 'العنصر غير موجود',
    unknown_setting: 'هذا الإعداد غير متاح',
    unknown_op: 'هذه العملية غير معروفة',
    bad_batch: 'اختر من 1 إلى 5 مواقع في الدفعة',
  };

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const LOGO = '<svg viewBox="0 0 24 24" fill="none" stroke="#04121e" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg>';

  async function api(path, { method = 'GET', body, headers = {} } = {}) {
    const init = { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', ...headers } };
    if (body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    if (method !== 'GET' && state.csrf) init.headers['X-CSRF'] = state.csrf;
    let res;
    try {
      res = await fetch('api/' + path, init);
    } catch {
      throw Object.assign(new Error('network'), { code: 'network' });
    }
    const data = await res.json().catch(() => ({ ok: false, error: 'bad_response' }));
    if (!res.ok || data.ok === false) {
      throw Object.assign(new Error(data.error || 'error'), { code: data.error || 'error', status: res.status, data });
    }
    return data;
  }

  function toast(message) {
    toastEl.textContent = message;
    toastEl.classList.add('show');
    clearTimeout(toast.t);
    toast.t = setTimeout(() => toastEl.classList.remove('show'), 3800);
  }

  function ago(ts) {
    if (!ts) return 'لم يُفحص بعد';
    const s = Math.max(0, Math.round(Date.now() / 1000 - ts));
    if (s < 60) return 'منذ لحظات';
    if (s < 3600) return `منذ ${Math.round(s / 60)} دقيقة`;
    if (s < 86400) return `منذ ${Math.round(s / 3600)} ساعة`;
    return `منذ ${Math.round(s / 86400)} يوم`;
  }

  function stop() {
    clearInterval(state.timer);
    state.timer = null;
  }

  function schedule() {
    stop();
    state.timer = setInterval(() => {
      // Refresh the sites view only. Other sections are not overwritten while the owner is typing.
      if (document.visibilityState === 'visible' && state.view === 'sites') loadDashboard().catch(() => {});
    }, 20000);
  }

  function renderSetup(message = '') {
    stop();
    app.innerHTML = `
      <section class="login">
        <div class="mark" aria-hidden="true">${LOGO}</div>
        <h1>الإعداد الأول</h1>
        <p class="muted">أدخل رمز الإعداد من ملف الإعدادات على الخادم، ثم اختر رمز الدخول.</p>
        <form id="setup" novalidate>
          <input id="setup-token" class="field" type="password" autocomplete="off" dir="ltr" placeholder="رمز الإعداد" required>
          <input id="setup-pin" class="pin" type="password" inputmode="numeric" maxlength="6" placeholder="••••••" required>
          <button class="btn primary wide" type="submit">تفعيل</button>
          <p id="err" class="err" role="alert">${esc(message)}</p>
        </form>
      </section>`;
    document.getElementById('setup').addEventListener('submit', async (e) => {
      e.preventDefault();
      const token = document.getElementById('setup-token').value.trim();
      const pin = document.getElementById('setup-pin').value.trim();
      const err = document.getElementById('err');
      if (!/^\d{6}$/.test(pin)) { err.textContent = ERRORS.pin_format; return; }
      try {
        const headers = { 'X-Setup-Token': token };
        await api('install', { method: 'POST', headers });
        await api('setup-pin', { method: 'POST', headers, body: { pin } });
        renderLogin('تم ضبط الرمز، أدخله للدخول');
      } catch (error) {
        err.textContent = ERRORS[error.code] || 'تعذّر الإعداد';
      }
    });
  }

  function renderLogin(message = '') {
    stop();
    state.view = 'sites';
    app.innerHTML = `
      <section class="login">
        <div class="mark" aria-hidden="true">${LOGO}</div>
        <h1>مركز التحكم</h1>
        <p class="muted">أدخل الرمز السري المكوّن من 6 أرقام</p>
        <form id="login" novalidate>
          <input id="pin" class="pin" type="password" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" required>
          <button class="btn primary wide" type="submit">دخول</button>
          <p id="err" class="err" role="alert">${esc(message)}</p>
        </form>
      </section>`;
    const pinInput = document.getElementById('pin');
    pinInput.focus();
    document.getElementById('login').addEventListener('submit', async (e) => {
      e.preventDefault();
      const pin = pinInput.value.trim();
      const err = document.getElementById('err');
      if (!/^\d{6}$/.test(pin)) { err.textContent = ERRORS.pin_format; return; }
      try {
        const session = await api('login', { method: 'POST', body: { pin } });
        state.csrf = session.csrf;
        await loadDashboard();
      } catch (error) {
        err.textContent = ERRORS[error.code] || 'تعذّر الدخول';
        pinInput.value = '';
        pinInput.focus();
      }
    });
  }

  async function loadDashboard() {
    const data = await api('overview');
    state.csrf = data.csrf;
    if (state.view !== 'sites') return;
    render(data);
    schedule();
  }

  /** Common frame for every section: brand, section tabs, then the section body. */
  function shell(body) {
    const tabs = VIEWS.map(([view, label]) => {
      const on = state.view === view;
      return `<button class="tab${on ? ' on' : ''}" data-view="${view}" aria-current="${on ? 'page' : 'false'}">${label}</button>`;
    }).join('');
    return `
      <header class="top">
        <div class="brand"><span class="logo" aria-hidden="true">${LOGO}</span><span class="label">مركز التحكم</span></div>
        <button class="btn ghost" data-act="logout" aria-label="خروج">خروج</button>
      </header>
      <nav class="tabs" aria-label="الأقسام">${tabs}</nav>
      ${body}`;
  }

  function render(d) {
    const counts = { online: 0, down: 0, unpaired: 0, unknown: 0 };
    d.sites.forEach((s) => { counts[s.status] = (counts[s.status] || 0) + 1; });

    const cards = d.sites.map((s) => {
      const [label, tone] = STATUS[s.status] || ['—', 'idle'];
      const info = s.info || {};
      const kpi = info.published_posts !== undefined
        ? `<div class="kpi">${Number(info.published_posts)} مقالة منشورة · WordPress ${esc(info.wordpress)}</div>` : '';
      const err = s.last_error ? `<div class="errline" dir="ltr">${esc(s.last_error)}</div>` : '';
      const actions = s.paired
        ? `<button class="btn ghost sm" data-act="health" data-id="${esc(s.id)}">فحص</button>
           <button class="btn ghost sm" data-act="purge" data-id="${esc(s.id)}">تنظيف الكاش</button>
           <button class="btn ghost sm" data-act="edit-site" data-id="${esc(s.id)}">تعديل الإعدادات</button>`
        : `<button class="btn primary sm" data-act="pair" data-id="${esc(s.id)}" data-name="${esc(s.name)}">ربط المفتاح</button>`;
      return `<article class="card" data-tone="${tone}">
        <header><span class="dot" aria-hidden="true"></span><div><h3>${esc(s.name)}</h3><small dir="ltr">${esc(s.host)}</small></div></header>
        <div class="meta"><span class="pill ${tone}">${label}</span><span class="muted">${ago(s.last_checked)}</span></div>
        ${kpi}${err}
        <footer>${actions}</footer>
      </article>`;
    }).join('');

    const jobs = d.jobs.map((j) => `<tr>
        <td>${esc(j.type)}</td>
        <td class="ltr">${esc(j.site_id || 'الكل')}</td>
        <td class="status-${esc(j.status)}">${esc(j.status)}</td>
        <td>${esc(j.attempts)}</td>
        <td>${ago(j.finished || j.created)}</td>
      </tr>`).join('') || '<tr><td colspan="5" class="muted">لا توجد مهام بعد</td></tr>';

    const audit = d.audit.map((a) => `<tr>
        <td>${ago(a.ts)}</td>
        <td>${esc(a.action)}</td>
        <td class="ltr">${esc(a.target || '')}</td>
      </tr>`).join('') || '<tr><td colspan="3" class="muted">لا يوجد سجل بعد</td></tr>';

    app.innerHTML = shell(`
      <div class="toolbar">
        <button class="btn primary" data-act="health_all">فحص الكل</button>
        <button class="btn" data-act="purge_all">تنظيف الكاش للكل</button>
      </div>
      <section class="stats" aria-label="ملخص">
        <div class="stat"><b>${counts.online}</b><span>متصل</span></div>
        <div class="stat"><b>${counts.down}</b><span>لا يستجيب</span></div>
        <div class="stat"><b>${counts.unpaired}</b><span>غير مربوط</span></div>
        <div class="stat"><b>${d.queue.queued + d.queue.running}</b><span>في الطابور</span></div>
      </section>
      <section class="grid" aria-label="المواقع">${cards}</section>
      <section class="panels">
        <div class="panel"><h2>المهام الأخيرة</h2>
          <div style="overflow-x:auto"><table><thead><tr><th>النوع</th><th>الموقع</th><th>الحالة</th><th>المحاولات</th><th>الوقت</th></tr></thead><tbody>${jobs}</tbody></table></div>
        </div>
        <div class="panel"><h2>سجل العمليات</h2>
          <div style="overflow-x:auto"><table><thead><tr><th>الوقت</th><th>العملية</th><th>الهدف</th></tr></thead><tbody>${audit}</tbody></table></div>
        </div>
      </section>`);
  }

  /** Management: supervisors list, add form, and activate/deactivate. */
  /** Each kind of person has its own block: a short note, an add form, and the list with on/off. */
  const ROLE_BLOCKS = {
    supervisor: { title: 'مشرفو العمليات', note: 'المشرف يستقبل الطلبات ويوزعها على الفنيين. الإيقاف يخفيه من العمليات، ولا يُحذف سجله.', add: 'إضافة المشرف', empty: 'لا يوجد مشرفون بعد. أضف أول مشرف من النموذج أعلاه.', done: 'مشرف' },
    technician: { title: 'الفنيون', note: 'الفني ينفذ الطلبات المسندة له، وتظهر حالته (متاح أو مشغول) في غرفة العمليات. الإيقاف يخفيه من التوزيع.', add: 'إضافة الفني', empty: 'لا يوجد فنيون بعد. أضف أول فني من النموذج أعلاه.', done: 'فني' },
  };

  async function peopleBlock(role) {
    const block = ROLE_BLOCKS[role];
    const { people } = await api(`management/people?role=${encodeURIComponent(role)}`);
    const rows = people.map((p) => `<tr>
        <td>${esc(p.name)}</td>
        <td class="ltr">${esc(p.phone || '—')}</td>
        <td>${esc(AREAS[p.area] || '—')}</td>
        <td><span class="pill ${p.active ? 'ok' : 'idle'}">${p.active ? 'نشط' : 'موقوف'}</span></td>
        <td><button class="btn ghost sm" data-act="person-toggle" data-id="${esc(p.id)}" data-next="${p.active ? '0' : '1'}">${p.active ? 'إيقاف' : 'تفعيل'}</button></td>
      </tr>`).join('') || `<tr><td colspan="5" class="muted">${block.empty}</td></tr>`;

    return `
      <section class="panel">
        <h2>${block.title}</h2>
        <p class="muted">${block.note}</p>
        <form class="form-row" data-form="person-add">
          <input type="hidden" name="role" value="${role}">
          <label class="lbl">الاسم<input class="field" name="name" required minlength="2" maxlength="80" autocomplete="off"></label>
          <label class="lbl">الجوال<input class="field" name="phone" dir="ltr" inputmode="tel" maxlength="20" autocomplete="off" placeholder="05XXXXXXXX"></label>
          <label class="lbl">المنطقة<select class="field" name="area">
            <option value="">غير محددة</option>
            <option value="makkah">مكة</option>
            <option value="jeddah">جدة</option>
            <option value="other">أخرى</option>
          </select></label>
          <button class="btn primary" type="submit">${block.add}</button>
          <p class="err" role="alert"></p>
        </form>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>الاسم</th><th>الجوال</th><th>المنطقة</th><th>الحالة</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </section>`;
  }

  /** Who can do what. Read-only for now: the rules live in the server, this shows them to the owner. */
  const ROLE_RIGHTS = [
    ['المالك', 'كل شيء: المواقع، الإعدادات الجماعية، الإدارة، غرفة العمليات'],
    ['المشرف', 'استقبال الطلبات وإسنادها للفنيين، ومتابعة غرفة العمليات'],
    ['الفني', 'تنفيذ الطلبات المسندة له وتحديث حالته (متاح أو مشغول)'],
  ];

  async function renderManagement() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const blocks = await Promise.all([peopleBlock('supervisor'), peopleBlock('technician')]);
    const rights = ROLE_RIGHTS.map(([who, what]) => `<tr><td>${esc(who)}</td><td>${esc(what)}</td></tr>`).join('');

    app.innerHTML = shell(`
      ${blocks.join('')}
      <section class="panel">
        <h2>الأدوار والصلاحيات</h2>
        <p class="muted">الصلاحيات الفعلية محددة على الخادم. الجدول ده للعرض والمراجعة.</p>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>الدور</th><th>ما يقدر يعمله</th></tr></thead>
          <tbody>${rights}</tbody>
        </table></div>
      </section>`);
  }

  /** General settings: plain business details, saved on the server. */
  /**
   * Operations room, first version: the state of every face in one place, with what needs attention.
   * Built only from the face list the desk already has. Orders and calls are not here yet: they wait
   * for the decision on where orders are stored.
   */
  async function renderOps() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const overview = await api('overview');
    state.csrf = overview.csrf;
    const sites = overview.sites;
    const count = (key) => sites.filter((s) => s.status === key).length;
    const attention = sites.filter((s) => s.status === 'down' || s.status === 'unpaired');
    const [techs, sups] = await Promise.all([api('management/people?role=technician'), api('management/people?role=supervisor')]);
    const activeTechs = techs.people.filter((p) => p.active).length;
    const activeSups = sups.people.filter((p) => p.active).length;
    const modules = OPS_MODULES;
    const [custs, ords] = await Promise.all([api('customers'), api('orders')]);
    state.customers = custs.customers;
    state.tiers = custs.tiers;
    state.orders = ords.orders;
    state.statuses = ords.statuses;
    state.technicians = techs.people.filter((p) => p.active);
    const list = attention.length
      ? attention.map((s) => {
          const [label, tone] = STATUS[s.status] || ['—', 'idle'];
          const reason = s.last_error ? `<div class="errline" dir="ltr">${esc(s.last_error)}</div>` : '';
          return `<div class="pick"><span class="pill ${tone}">${label}</span><span>${esc(s.name)}</span><small dir="ltr">${esc(s.host)}</small>${reason}</div>`;
        }).join('')
      : '<p class="muted">كل المواقع المربوطة شغالة، ومفيش حاجة محتاجة متابعة.</p>';

    app.innerHTML = shell(`
      <section class="stats">
        <div class="stat"><b>${sites.length}</b><span>موقع</span></div>
        <div class="stat"><b>${count('online')}</b><span>متصل</span></div>
        <div class="stat"><b>${count('down')}</b><span>لا يستجيب</span></div>
        <div class="stat"><b>${count('unpaired')}</b><span>غير مربوط</span></div>
      </section>
      <section class="panel">
        <h2>يحتاج متابعة</h2>
        <div class="bulk-form">${list}</div>
      </section>
      <section class="stats">
        <div class="stat"><b>${activeTechs}</b><span>فني نشط</span></div>
        <div class="stat"><b>${activeSups}</b><span>مشرف نشط</span></div>
      </section>
      ${customersPanel()}
      ${ordersPanel()}
      <section class="panel">
        <h2>الخريطة الميدانية والحية</h2>
        <p class="muted">مناطق التغطية (مكة، جدة) والفنيين اللي عندهم موقع مسجّل. الموقع بيتحدّث لما الفني يبعته من تطبيقه، أو لما تدخله يدوياً هنا.</p>
        <div id="ops-map" style="height:380px;border-radius:12px;margin-bottom:12px"></div>
        <form class="form-row" data-form="map-location">
          <label class="lbl">الفني<select class="field" name="person_id">${techs.people.filter((p) => p.active).map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('') || '<option value="">لا يوجد فنيون نشطون</option>'}</select></label>
          <label class="lbl">خط العرض<input class="field" name="lat" dir="ltr" inputmode="decimal" placeholder="21.4225" maxlength="12"></label>
          <label class="lbl">خط الطول<input class="field" name="lng" dir="ltr" inputmode="decimal" placeholder="39.8262" maxlength="12"></label>
          <button class="btn primary" type="submit">حفظ الموقع يدوياً</button>
          <p class="err" role="alert"></p>
        </form>
      </section>
      <section class="panel">
        <h2>وحدات غرفة العمليات</h2>
        <p class="muted">كل وحدة تظهر هنا بحالتها. الوحدات اللي "بانتظار قرار" تستنى إجابتك على القرار المحدد فقط.</p>
        <div class="bulk-form">${modules.map(([label, state, note]) => `<div class="pick"><span class="pill ${state === 'جاهزة' ? 'ok' : 'idle'}">${esc(state)}</span><span>${esc(label)}</span><small>${esc(note)}</small></div>`).join('')}</div>
      </section>`);
    drawOpsMap();
  }

  /** Leaflet on OpenStreetMap tiles, loaded once from unpkg when the map is first needed. */
  function loadLeaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (!loadLeaflet.p) {
      loadLeaflet.p = new Promise((resolve, reject) => {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(css);
        const js = document.createElement('script');
        js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        js.onload = () => resolve(window.L);
        js.onerror = () => reject(new Error('leaflet'));
        document.head.appendChild(js);
      });
    }
    return loadLeaflet.p;
  }

  /** Draws the service areas and the technicians' last known positions. A failure leaves the list working. */
  async function drawOpsMap() {
    const el = document.getElementById('ops-map');
    if (!el) return;
    try {
      const [L, data] = await Promise.all([loadLeaflet(), api('map/live')]);
      const map = L.map(el).setView([21.45, 39.6], 9);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap',
      }).addTo(map);
      const bounds = [];
      for (const area of Object.values(data.areas)) {
        L.circle([area.lat, area.lng], { radius: area.radius, color: '#0f6fb5', weight: 1, fillOpacity: 0.06 }).addTo(map).bindTooltip(area.label);
        bounds.push([area.lat, area.lng]);
      }
      for (const c of data.customers) {
        const tags = [c.active_orders ? 'طلب نشط' : null, c.under_warranty ? 'ضمان ساري' : null, c.done_orders ? 'تعامل سابق' : null].filter(Boolean).join(' · ') || 'عميل جديد';
        const wa = c.phone ? `https://wa.me/${c.phone}?text=${encodeURIComponent('موقع العميل ' + c.name + ': https://maps.google.com/?q=' + c.lat + ',' + c.lng)}` : '';
        const marker = L.circleMarker([c.lat, c.lng], { radius: 8, color: c.active_orders ? '#1f8a4c' : '#6b7280', fillOpacity: 0.85 }).addTo(map);
        marker.bindPopup(`<b>${esc(c.name)}</b><br>${esc(tags)}<br>الطبقة: ${esc(c.tier_label)}${c.last_price ? '<br>آخر سعر: ' + c.last_price : ''}${c.rating ? '<br>التقييم: ' + c.rating + '/5' : ''}${wa ? `<br><a href="${esc(wa)}" target="_blank" rel="noopener">إرسال الموقع على واتساب</a>` : ''}`);
        bounds.push([c.lat, c.lng]);
      }
      for (const t of data.technicians) {
        if (t.lat === null) continue;
        const when = new Date(t.updated * 1000).toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' });
        L.marker([t.lat, t.lng]).addTo(map).bindPopup(`<b>${esc(t.name)}</b><br>آخر موقع: ${esc(when)}`);
        bounds.push([t.lat, t.lng]);
      }
      if (bounds.length > 1) map.fitBounds(bounds, { padding: [24, 24], maxZoom: 12 });
    } catch (error) {
      el.innerHTML = '<p class="muted" style="padding:16px">تعذّر تحميل الخريطة. القائمة والإحصائيات تشتغل بدونها.</p>';
    }
  }

  /** Customers: flags come from their orders. Tier, rating and last price are the owner's, changed inline. */
  function customersPanel() {
    const tierOpts = (sel) => Object.entries(state.tiers).map(([k, v]) => `<option value="${k}"${k === sel ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const rows = state.customers.map((c) => {
      const flags = [
        c.active_orders ? '<span class="pill ok">طلب نشط</span>' : '',
        c.under_warranty ? '<span class="pill">ضمان ساري</span>' : '',
        c.done_orders ? `<span class="pill idle">تعامل سابق (${c.done_orders})</span>` : '',
      ].join(' ') || '<span class="pill idle">عميل جديد</span>';
      const rating = `<select class="field" data-change="customer-rating" data-id="${esc(c.id)}"><option value="">—</option>${[1, 2, 3, 4, 5].map((n) => `<option value="${n}"${c.rating === n ? ' selected' : ''}>${n}</option>`).join('')}</select>`;
      return `<tr>
        <td>${esc(c.name)}<small class="ltr" dir="ltr">${esc(c.phone || '')}</small></td>
        <td><select class="field" data-change="customer-tier" data-id="${esc(c.id)}">${tierOpts(c.tier)}</select></td>
        <td>${flags}</td>
        <td class="ltr">${c.last_price ?? '—'}</td>
        <td>${rating}</td>
      </tr>`;
    }).join('') || '<tr><td colspan="5" class="muted">لا يوجد عملاء بعد. أضف أول عميل من النموذج أعلاه.</td></tr>';
    return `
      <section class="panel">
        <h2>العملاء</h2>
        <p class="muted">الطبقة توجّه السعر في المرات الجاية. الأعلام بتتحدد من الطلبات تلقائياً.</p>
        <form class="form-row" data-form="customer-add">
          <label class="lbl">الاسم<input class="field" name="name" required minlength="2" maxlength="120" autocomplete="off"></label>
          <label class="lbl">الجوال<input class="field" name="phone" dir="ltr" inputmode="tel" maxlength="20" placeholder="05XXXXXXXX"></label>
          <label class="lbl">العنوان<input class="field" name="address" maxlength="200" autocomplete="off"></label>
          <label class="lbl">خط العرض<input class="field" name="lat" dir="ltr" inputmode="decimal" maxlength="12"></label>
          <label class="lbl">خط الطول<input class="field" name="lng" dir="ltr" inputmode="decimal" maxlength="12"></label>
          <label class="lbl">الطبقة<select class="field" name="tier">${tierOpts('medium')}</select></label>
          <button class="btn primary" type="submit">إضافة العميل</button>
          <p class="err" role="alert"></p>
        </form>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>العميل</th><th>الطبقة</th><th>الحالة</th><th>آخر سعر</th><th>التقييم</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </section>`;
  }

  /** Orders still open, with status and technician editable in place, and a form for a new one. */
  function ordersPanel() {
    const custOpts = state.customers.map((c) => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');
    const techOpts = (sel) => `<option value="">بدون فني</option>` + state.technicians.map((t) => `<option value="${esc(t.id)}"${t.id === sel ? ' selected' : ''}>${esc(t.name)}</option>`).join('');
    const statusOpts = (sel) => Object.entries(state.statuses).map(([k, v]) => `<option value="${k}"${k === sel ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const open = state.orders.filter((o) => o.active);
    const rows = open.map((o) => `<tr>
        <td>${esc(o.customer_name)}</td>
        <td>${esc(o.service || '—')}</td>
        <td><select class="field" data-change="order-status" data-id="${esc(o.id)}">${statusOpts(o.status)}</select></td>
        <td><select class="field" data-change="order-tech" data-id="${esc(o.id)}">${techOpts(o.technician_id)}</select></td>
        <td class="ltr">${o.price ?? '—'}</td>
      </tr>`).join('') || '<tr><td colspan="5" class="muted">لا توجد طلبات نشطة.</td></tr>';
    return `
      <section class="panel">
        <h2>الطلبات النشطة</h2>
        <form class="form-row" data-form="order-add">
          <label class="lbl">العميل<select class="field" name="customer_id" required>${custOpts || '<option value="">أضف عميل أولاً</option>'}</select></label>
          <label class="lbl">الخدمة<input class="field" name="service" maxlength="80" autocomplete="off"></label>
          <label class="lbl">السعر<input class="field" name="price" dir="ltr" inputmode="numeric" maxlength="7"></label>
          <label class="lbl">الفني<select class="field" name="technician_id">${techOpts('')}</select></label>
          <button class="btn primary" type="submit">إنشاء الطلب</button>
          <p class="err" role="alert"></p>
        </form>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>العميل</th><th>الخدمة</th><th>الحالة</th><th>الفني</th><th>السعر</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </section>`;
  }

  /** Inline edits from the lists: tier, rating, order status and technician. */
  async function changeField(el) {
    const id = el.dataset.id;
    const value = el.value === '' ? null : el.value;
    try {
      if (el.dataset.change === 'customer-tier') await api(`customers/${encodeURIComponent(id)}`, { method: 'POST', body: { tier: value } });
      if (el.dataset.change === 'customer-rating') await api(`customers/${encodeURIComponent(id)}`, { method: 'POST', body: { rating: value === null ? null : Number(value) } });
      if (el.dataset.change === 'order-status') await api(`orders/${encodeURIComponent(id)}`, { method: 'POST', body: { status: value } });
      if (el.dataset.change === 'order-tech') await api(`orders/${encodeURIComponent(id)}`, { method: 'POST', body: { technician_id: value } });
      toast('تم الحفظ');
      await renderOps();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر الحفظ');
    }
  }

  /** Operations modules and where each one stands. The note says what it waits for. */
  const OPS_MODULES = [
    ['الإسناد اليدوي والتوزيع', 'جاهزة', 'المشرف يسند الطلب لفني من الوحدات الجاهزة'],
    ['الخريطة الميدانية وتتبع الفنيين', 'بانتظار قرار', 'مزود الخرائط وموافقة الفني على تتبع الموقع'],
    ['تتبع خط سير الطلب', 'بانتظار قرار', 'مرتبطة بالخريطة وبمكان تخزين الطلبات'],
    ['الإسناد التلقائي', 'بانتظار قرار', 'قواعد الإسناد (المنطقة، الحالة، التقييم)'],
    ['استقبال الطلبات (واتساب، إعلانات، الموقع)', 'بانتظار قرار', 'مكان تخزين الطلبات ومصادر الاستقبال'],
    ['لوحة توزيع المهام', 'بانتظار قرار', 'بعد استقرار الطلبات والإسناد'],
    ['تسجيل الدخول برقم الهاتف (OTP)', 'بانتظار قرار', 'مزود رسائل SMS'],
    ['لوحة الشات بوت', 'جاهزة', 'قواعد بدون ذكاء: التسعير، البيانات، العروض، حد التفاوض'],
    ['لوحة الذكاء الاصطناعي', 'بانتظار قرار', 'اختيار المزود المجاني أو بوت القواعد فقط'],
    ['نشر التطبيق وتحديثه', 'جاهزة', 'تحديث الـ PWA وروابط التحميل'],
  ];

  const POST_STATUS = { publish: 'منشورة', draft: 'مسودة', future: 'مجدولة', pending: 'قيد المراجعة', private: 'خاصة' };

  /** Articles of one face, picked from the list of paired faces. Each face is read live through its bridge. */
  async function renderArticles(siteId) {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const overview = await api('overview');
    state.csrf = overview.csrf;
    const paired = overview.sites.filter((s) => s.paired);
    const chosen = paired.find((s) => s.id === siteId) ? siteId : paired[0]?.id;
    const options = paired.map((s) => `<option value="${esc(s.id)}"${s.id === chosen ? ' selected' : ''}>${esc(s.name)}</option>`).join('');

    let body = '<p class="muted">لا توجد مواقع مربوطة بعد.</p>';
    if (chosen) {
      // api() throws on ok:false, but a failed face still carries error and fix: read them from the thrown reply.
      const r = await api(`sites/${encodeURIComponent(chosen)}/posts`).catch((e) => e.data || { ok: false, error: e.code || 'error' });
      if (!r.ok) {
        const whoActs = { desk: 'من الديسك', site: 'من الموقع نفسه', retry: 'أعد المحاولة لاحقاً', review: 'يحتاج مراجعة' };
        body = `<p class="err">${esc(r.error)} <small>(${esc(whoActs[r.fix] || r.fix || '')})</small></p>`;
      } else {
        const rows = r.posts.map((p) => `<tr>
            <td>${esc(p.title || '(بدون عنوان)')}</td>
            <td><span class="pill">${esc(POST_STATUS[p.status] || p.status)}</span></td>
            <td class="ltr">${esc(p.date)}</td>
            <td><a href="${esc(p.url)}" target="_blank" rel="noopener">فتح</a></td>
          </tr>`).join('');
        body = r.posts.length
          ? `<p class="muted">${esc(r.count)} مقالة</p><div style="overflow-x:auto"><table><thead><tr><th>العنوان</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
          : '<p class="muted">لا توجد مقالات على هذا الموقع.</p>';
      }
    }

    app.innerHTML = shell(`
      <section class="panel">
        <h2>المقالات</h2>
        <p class="muted">مقالات الموقع المختار: منشورة ومسودات ومجدولة، تُقرأ مباشرة من الموقع.</p>
        <label class="lbl">الموقع <select class="field" id="articles-site">${options}</select></label>
        <div style="margin-top:12px">${body}</div>
      </section>`);
  }

  async function renderSettings() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const { values } = await api('settings/general');
    const fields = GENERAL_FIELDS.map(([key, label, max]) => `
        <label class="lbl">${esc(label)}
          <input class="field" name="${esc(key)}" maxlength="${max}" value="${esc(values[key] || '')}" autocomplete="off">
        </label>`).join('');

    app.innerHTML = shell(`
      <section class="panel">
        <h2>الإعدادات العامة</h2>
        <p class="muted">بيانات الشركة اللي تظهر في الفواتير والرسائل. تُحفظ على الخادم فقط.</p>
        <form class="settings-form" data-form="settings" novalidate>
          ${fields}
          <div class="row end"><button class="btn primary" type="submit">حفظ الإعدادات</button></div>
          <p class="err" role="alert"></p>
        </form>
      </section>`);
  }

  /**
   * Bulk settings: pick faces and fields, preview, then apply. Faces are called in batches (the server caps a batch),
   * and each face reports its own result, so one failing face never hides the rest.
   */
  let bulkFields = {};
  let bulkOps = {};
  let bulkBatch = 5;

  async function renderBulk() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const [meta, overview, ops] = await Promise.all([api('bulk/settings'), api('overview'), api('bulk/operations')]);
    bulkFields = meta.fields;
    bulkOps = ops.operations;
    bulkBatch = meta.max_batch;
    state.csrf = overview.csrf;

    const preselect = state.preselect;
    state.preselect = null;
    const sites = overview.sites.map((s) => `<label class="pick${s.paired ? '' : ' off'}">
          <input type="checkbox" name="site" value="${esc(s.id)}"${s.paired ? '' : ' disabled'}${s.paired && s.id === preselect ? ' checked' : ''}>
          <span>${esc(s.name)}</span><small dir="ltr">${esc(s.host)}</small>
          ${s.paired ? '' : '<em>غير مربوط</em>'}
        </label>`).join('');
    const fields = Object.entries(bulkFields).map(([key, f]) => {
      const input = f.type === 'int'
        ? `type="number" inputmode="numeric" min="${f.min}" max="${f.max}"`
        : `type="text" maxlength="${f.max}"`;
      return `<div class="bulk-field">
          <label class="pick"><input type="checkbox" name="use" value="${esc(key)}"><span>${esc(f.label)}</span></label>
          <input class="field" ${input} name="val:${esc(key)}" autocomplete="off" aria-label="${esc(f.label)}">
        </div>`;
    }).join('');
    const opChoices = Object.entries(bulkOps).map(([key, o]) => `<label class="pick">
          <input type="radio" name="op" value="${esc(key)}"${key === 'health' ? ' checked' : ''}>
          <span>${esc(o.label)}</span>${o.writes ? '<em>يغيّر الموقع</em>' : ''}
        </label>`).join('');
    const opSites = overview.sites.map((s) => `<label class="pick${s.paired ? '' : ' off'}">
          <input type="checkbox" name="opsite" value="${esc(s.id)}"${s.paired ? '' : ' disabled'}>
          <span>${esc(s.name)}</span><small dir="ltr">${esc(s.host)}</small>
          ${s.paired ? '' : '<em>غير مربوط</em>'}
        </label>`).join('');

    app.innerHTML = shell(`
      <section class="panel">
        <h2>الإعدادات الجماعية</h2>
        <p class="muted">اختر المواقع والإعدادات، وبعدين اعمل معاينة قبل التطبيق. كل موقع بيتحدّث لوحده، ولو موقع فشل الباقي بيكمل.</p>
        <form class="bulk-form" data-form="bulk">
          <fieldset class="bulk-sites">
            <legend>المواقع (${esc(bulkBatch)} في كل دفعة)</legend>
            <div class="row">
              <button type="button" class="btn ghost sm" data-act="bulk-all" data-target="site">تحديد المربوطة</button>
              <button type="button" class="btn ghost sm" data-act="bulk-none" data-target="site">إلغاء التحديد</button>
            </div>
            <div class="pick-grid">${sites}</div>
          </fieldset>
          <fieldset class="bulk-sites">
            <legend>الإعدادات</legend>
            ${fields}
          </fieldset>
          <div class="row end">
            <button class="btn" type="submit" data-mode="preview">معاينة بدون تغيير</button>
            <button class="btn primary" type="submit" data-mode="apply">تطبيق</button>
          </div>
          <p id="bulk-progress" class="muted" aria-live="polite"></p>
          <p class="err" role="alert"></p>
        </form>
        <div id="bulk-out"></div>
      </section>
      <section class="panel">
        <h2>العمليات الجماعية</h2>
        <p class="muted">اختر العملية والمواقع، وبعدين اعمل معاينة. العملية اللي بتغيّر الموقع (مسح الكاش) مش بتتنفذ إلا لما تضغط تنفيذ.</p>
        <form class="bulk-form" data-form="bulkops">
          <fieldset class="bulk-sites">
            <legend>العملية</legend>
            <div class="pick-grid">${opChoices}</div>
          </fieldset>
          <fieldset class="bulk-sites">
            <legend>المواقع (${esc(bulkBatch)} في كل دفعة)</legend>
            <div class="row">
              <button type="button" class="btn ghost sm" data-act="bulk-all" data-target="opsite">تحديد المربوطة</button>
              <button type="button" class="btn ghost sm" data-act="bulk-none" data-target="opsite">إلغاء التحديد</button>
            </div>
            <div class="pick-grid">${opSites}</div>
          </fieldset>
          <div class="row end">
            <button class="btn" type="submit" data-mode="preview">معاينة</button>
            <button class="btn primary" type="submit" data-mode="apply">تنفيذ</button>
          </div>
          <p id="bulkops-progress" class="muted" aria-live="polite"></p>
          <p class="err" role="alert"></p>
        </form>
        <div id="bulkops-out"></div>
      </section>`);
  }

  /** Runs one operation over the selected faces in batches. Same rules as settings: preview first, confirm before writing. */
  async function runBulkOps(form, dryRun) {
    const err = form.querySelector('.err');
    const progress = document.getElementById('bulkops-progress');
    const out = document.getElementById('bulkops-out');
    err.textContent = '';
    out.innerHTML = '';

    const op = form.querySelector('input[name="op"]:checked')?.value;
    const siteIds = [...form.querySelectorAll('input[name="opsite"]:checked')].map((c) => c.value);
    if (!op) { err.textContent = 'اختر عملية'; return; }
    if (siteIds.length === 0) { err.textContent = 'اختر موقعاً واحداً على الأقل'; return; }
    if (!dryRun && bulkOps[op]?.writes && !window.confirm(`سيتم تنفيذ "${bulkOps[op].label}" على ${siteIds.length} موقع. متأكد؟`)) return;

    const results = [];
    try {
      for (let i = 0; i < siteIds.length; i += bulkBatch) {
        const batch = siteIds.slice(i, i + bulkBatch);
        progress.textContent = `جارٍ: ${Math.min(i + bulkBatch, siteIds.length)} من ${siteIds.length}`;
        const r = await api('bulk/operations', { method: 'POST', body: { op, site_ids: batch, dry_run: dryRun } });
        results.push(...r.results);
      }
      progress.textContent = '';
      out.innerHTML = opsReport(op, results, dryRun);
      toast(dryRun ? 'تمت المعاينة' : 'تم التنفيذ');
    } catch (error) {
      progress.textContent = '';
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      err.textContent = ERRORS[error.code] || 'تعذّر التنفيذ';
    }
  }

  /** The owner sees, for each face, the outcome and who has to act if it failed. */
  function opsReport(op, results, dryRun) {
    const okCount = results.filter((r) => r.ok).length;
    const whoActs = { desk: 'من الديسك', site: 'من الموقع نفسه', retry: 'أعد المحاولة لاحقاً', review: 'يحتاج مراجعة' };
    const rows = results.map((r) => {
      if (!r.ok) {
        const who = r.fix ? ` <small>(${esc(whoActs[r.fix] || r.fix)})</small>` : '';
        return `<tr><td>${esc(r.name)}</td><td class="status-failed">${esc(r.error)}${who}</td></tr>`;
      }
      const text = dryRun && r.preview ? esc(r.detail) : esc(r.detail || 'تم');
      return `<tr><td>${esc(r.name)}</td><td class="status-done">${text}</td></tr>`;
    }).join('');
    return `<div class="panel bulk-report">
        <h3>${dryRun ? 'نتيجة المعاينة' : 'نتيجة التنفيذ'} (${esc(bulkOps[op]?.label || op)}): نجح ${okCount} من ${results.length}</h3>
        <div style="overflow-x:auto"><table><thead><tr><th>الموقع</th><th>النتيجة</th></tr></thead><tbody>${rows}</tbody></table></div>
      </div>`;
  }

  async function runBulk(form, dryRun) {
    const err = form.querySelector('.err');
    const progress = document.getElementById('bulk-progress');
    const out = document.getElementById('bulk-out');
    err.textContent = '';
    out.innerHTML = '';

    const siteIds = [...form.querySelectorAll('input[name="site"]:checked')].map((c) => c.value);
    const values = {};
    form.querySelectorAll('input[name="use"]:checked').forEach((c) => {
      const raw = form.querySelector(`input[name="val:${c.value}"]`).value.trim();
      values[c.value] = bulkFields[c.value].type === 'int' ? Number(raw) : raw;
    });
    if (siteIds.length === 0) { err.textContent = 'اختر موقعاً واحداً على الأقل'; return; }
    if (Object.keys(values).length === 0) { err.textContent = 'اختر إعداداً واحداً على الأقل'; return; }
    if (!dryRun && !window.confirm(`سيتم تطبيق التغيير على ${siteIds.length} موقع. متأكد؟`)) return;

    const results = [];
    try {
      for (let i = 0; i < siteIds.length; i += bulkBatch) {
        const batch = siteIds.slice(i, i + bulkBatch);
        progress.textContent = `جارٍ ${dryRun ? 'المعاينة' : 'التطبيق'}: ${Math.min(i + bulkBatch, siteIds.length)} من ${siteIds.length}`;
        const r = await api('bulk/settings', { method: 'POST', body: { site_ids: batch, values, dry_run: dryRun } });
        results.push(...r.results);
      }
      progress.textContent = '';
      out.innerHTML = bulkReport(results, dryRun);
      toast(dryRun ? 'تمت المعاينة' : 'تم التطبيق على المواقع');
    } catch (error) {
      progress.textContent = '';
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      err.textContent = ERRORS[error.code] || 'تعذّر التنفيذ';
    }
  }

  function bulkReport(results, dryRun) {
    const okCount = results.filter((r) => r.ok).length;
    const label = (key) => esc(bulkFields[key]?.label || key);
    const rows = results.map((r) => {
      if (!r.ok) return `<tr><td>${esc(r.name)}</td><td class="status-failed">${esc(r.error)}</td></tr>`;
      if (dryRun) {
        const lines = Object.entries(r.changes).map(([key, c]) => (c.same
          ? `${label(key)}: بدون تغيير`
          : `${label(key)}: <span class="ltr">${esc(c.from)}</span> ← <span class="ltr">${esc(c.to)}</span>`)).join('<br>');
        return `<tr><td>${esc(r.name)}</td><td>${lines}</td></tr>`;
      }
      const changed = r.changed.length ? r.changed.map(label).join('، ') : 'لا تغيير';
      return `<tr><td>${esc(r.name)}</td><td class="status-done">تم: ${changed}</td></tr>`;
    }).join('');
    return `<div class="panel bulk-report">
        <h3>${dryRun ? 'نتيجة المعاينة' : 'نتيجة التطبيق'}: نجح ${okCount} من ${results.length}</h3>
        <div style="overflow-x:auto"><table><thead><tr><th>الموقع</th><th>التفاصيل</th></tr></thead><tbody>${rows}</tbody></table></div>
      </div>`;
  }

  /** Switches section. The sites view keeps refreshing itself; the others load once when opened. */
  async function show(view) {
    state.view = view;
    stop();
    try {
      if (view === 'management') return await renderManagement();
      if (view === 'settings') return await renderSettings();
      if (view === 'bulk') return await renderBulk();
      if (view === 'articles') return await renderArticles();
      if (view === 'ops') return await renderOps();
      return await loadDashboard();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر التحميل');
    }
  }

  async function run(type, siteId) {
    toast('جارٍ الجدولة والتنفيذ…');
    try {
      const body = siteId ? { type, site_id: siteId } : { type };
      const r = await api('jobs', { method: 'POST', body });
      const b = r.batch || {};
      toast(`جدولة ${r.queued} · نُفّذ ${b.done || 0} · تخطّي ${b.skipped || 0} · إعادة ${b.retry || 0}`);
      await loadDashboard();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر التنفيذ');
    }
  }

  async function togglePerson(id, active) {
    try {
      await api(`management/people/${encodeURIComponent(id)}`, { method: 'POST', body: { active } });
      toast(active ? 'تم التفعيل' : 'تم الإيقاف');
      await renderManagement();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر الحفظ');
    }
  }

  function openPair(id, name) {
    state.pairId = id;
    document.getElementById('pair-title').textContent = `ربط: ${name}`;
    document.getElementById('pair-secret').value = '';
    document.getElementById('pair-err').textContent = '';
    if (typeof pairDialog.showModal === 'function') pairDialog.showModal(); else pairDialog.setAttribute('open', '');
    document.getElementById('pair-secret').focus();
  }

  document.getElementById('pair-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const secret = document.getElementById('pair-secret').value.trim();
    const err = document.getElementById('pair-err');
    try {
      await api(`sites/${encodeURIComponent(state.pairId)}/pair`, { method: 'POST', body: { secret } });
      pairDialog.close();
      toast('تم الربط، جارٍ الفحص…');
      await run('health', state.pairId);
    } catch (error) {
      if (error.code === 'no_session') { pairDialog.close(); return renderLogin(ERRORS.no_session); }
      err.textContent = ERRORS[error.code] || 'تعذّر الربط';
    }
  });
  pairDialog.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) pairDialog.close(); });

  // Forms inside the sections. Delegated, because the sections re-render their own forms.
  app.addEventListener('submit', async (e) => {
    const form = e.target.closest('form[data-form]');
    if (!form) return;
    e.preventDefault();
    const errEl = form.querySelector('.err');
    if (errEl) errEl.textContent = '';
    const values = Object.fromEntries(new FormData(form).entries());
    try {
      if (form.dataset.form === 'person-add') {
        await api('management/people', { method: 'POST', body: values });
        toast('تمت الإضافة');
        return await renderManagement();
      }
      if (form.dataset.form === 'customer-add') {
        await api('customers', { method: 'POST', body: values });
        toast('تمت إضافة العميل');
        return await renderOps();
      }
      if (form.dataset.form === 'order-add') {
        await api('orders', { method: 'POST', body: values });
        toast('تم إنشاء الطلب');
        return await renderOps();
      }
      if (form.dataset.form === 'map-location') {
        await api('map/location', { method: 'POST', body: values });
        toast('تم حفظ الموقع');
        return await renderOps();
      }
      if (form.dataset.form === 'bulk') {
        return await runBulk(form, e.submitter?.dataset.mode !== 'apply');
      }
      if (form.dataset.form === 'bulkops') {
        return await runBulkOps(form, e.submitter?.dataset.mode !== 'apply');
      }
      if (form.dataset.form === 'settings') {
        await api('settings/general', { method: 'POST', body: values });
        toast('تم حفظ الإعدادات');
        return await renderSettings();
      }
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      if (errEl) errEl.textContent = ERRORS[error.code] || 'تعذّر الحفظ';
    }
  });

  app.addEventListener('change', (e) => {
    if (e.target.id === 'articles-site') return renderArticles(e.target.value);
    if (e.target.dataset.change) return changeField(e.target);
  });

  app.addEventListener('click', async (e) => {
    const tab = e.target.closest('[data-view]');
    if (tab) return show(tab.dataset.view);
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const { act, id, name, next } = btn.dataset;
    if (act === 'pair') return openPair(id, name);
    if (act === 'logout') {
      try { await api('logout', { method: 'POST' }); } catch { /* session may already be gone */ }
      state.csrf = null;
      return renderLogin('تم الخروج');
    }
    if (act === 'health_all') return run('health_all');
    if (act === 'purge_all') return run('purge_all');
    if (act === 'health') return run('health', id);
    if (act === 'edit-site') { state.preselect = id; return show('bulk'); }
    if (act === 'purge') return run('purge', id);
    if (act === 'person-toggle') return togglePerson(id, next === '1');
    if (act === 'bulk-all' || act === 'bulk-none') {
      const target = btn.dataset.target === 'opsite' ? 'opsite' : 'site';
      app.querySelectorAll(`input[name="${target}"]:not(:disabled)`).forEach((c) => { c.checked = act === 'bulk-all'; });
      return;
    }
  });

  async function boot() {
    try {
      const health = await api('health');
      if (!health.installed || !health.pin_set) return renderSetup();
      await loadDashboard();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin();
      if (error.code === 'network') { app.innerHTML = '<p class="boot">تعذّر الاتصال بالخادم. حاول لاحقاً.</p>'; return; }
      renderLogin(ERRORS[error.code] || '');
    }
  }

  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
  boot();
})();
