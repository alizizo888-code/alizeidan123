(() => {
  'use strict';

  const app = document.getElementById('app');
  const toastEl = document.getElementById('toast');
  const pairDialog = document.getElementById('pair');
  const state = { csrf: null, timer: null, pairId: null, view: 'sites' };

  // Sections of the panel. A new section is one line here plus its render function.
  const VIEWS = [
    ['sites', 'المواقع'],
    ['management', 'الإدارة'],
    ['settings', 'الإعدادات العامة'],
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
      throw Object.assign(new Error(data.error || 'error'), { code: data.error || 'error', status: res.status });
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
           <button class="btn ghost sm" data-act="purge" data-id="${esc(s.id)}">تنظيف الكاش</button>`
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
  async function renderManagement() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const { people } = await api('management/people?role=supervisor');
    const rows = people.map((p) => `<tr>
        <td>${esc(p.name)}</td>
        <td class="ltr">${esc(p.phone || '—')}</td>
        <td>${esc(AREAS[p.area] || '—')}</td>
        <td><span class="pill ${p.active ? 'ok' : 'idle'}">${p.active ? 'نشط' : 'موقوف'}</span></td>
        <td><button class="btn ghost sm" data-act="person-toggle" data-id="${esc(p.id)}" data-next="${p.active ? '0' : '1'}">${p.active ? 'إيقاف' : 'تفعيل'}</button></td>
      </tr>`).join('') || '<tr><td colspan="5" class="muted">لا يوجد مشرفون بعد. أضف أول مشرف من النموذج أعلاه.</td></tr>';

    app.innerHTML = shell(`
      <section class="panel">
        <h2>مشرفو العمليات</h2>
        <p class="muted">المشرف يستقبل الطلبات ويوزعها على الفنيين. الإيقاف يخفيه من العمليات، ولا يُحذف سجله.</p>
        <form class="form-row" data-form="person-add">
          <input type="hidden" name="role" value="supervisor">
          <label class="lbl">الاسم<input class="field" name="name" required minlength="2" maxlength="80" autocomplete="off"></label>
          <label class="lbl">الجوال<input class="field" name="phone" dir="ltr" inputmode="tel" maxlength="20" autocomplete="off" placeholder="05XXXXXXXX"></label>
          <label class="lbl">المنطقة<select class="field" name="area">
            <option value="">غير محددة</option>
            <option value="makkah">مكة</option>
            <option value="jeddah">جدة</option>
            <option value="other">أخرى</option>
          </select></label>
          <button class="btn primary" type="submit">إضافة المشرف</button>
          <p class="err" role="alert"></p>
        </form>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>الاسم</th><th>الجوال</th><th>المنطقة</th><th>الحالة</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </section>`);
  }

  /** General settings: plain business details, saved on the server. */
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

  /** Switches section. The sites view keeps refreshing itself; the others load once when opened. */
  async function show(view) {
    state.view = view;
    stop();
    try {
      if (view === 'management') return await renderManagement();
      if (view === 'settings') return await renderSettings();
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
      toast(active ? 'تم تفعيل المشرف' : 'تم إيقاف المشرف');
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
        toast('تمت إضافة المشرف');
        return await renderManagement();
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
    if (act === 'purge') return run('purge', id);
    if (act === 'person-toggle') return togglePerson(id, next === '1');
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
