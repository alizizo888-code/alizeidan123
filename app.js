(() => {
  'use strict';

  const app = document.getElementById('app');
  const toastEl = document.getElementById('toast');
  const pairDialog = document.getElementById('pair');
  const state = { csrf: null, timer: null, pairId: null };

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
      if (document.visibilityState === 'visible') loadDashboard().catch(() => {});
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
    render(data);
    schedule();
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

    app.innerHTML = `
      <header class="top">
        <div class="brand"><span class="logo" aria-hidden="true">${LOGO}</span><span class="label">مركز التحكم</span></div>
        <div class="actions">
          <button class="btn primary" data-act="health_all">فحص الكل</button>
          <button class="btn" data-act="purge_all">تنظيف الكاش للكل</button>
          <button class="btn ghost" data-act="logout" aria-label="خروج">خروج</button>
        </div>
      </header>
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
      </section>`;
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

  app.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const { act, id, name } = btn.dataset;
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
