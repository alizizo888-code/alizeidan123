(() => {
  'use strict';

  const app = document.getElementById('app');
  const toastEl = document.getElementById('toast');
  const pairDialog = document.getElementById('pair');
  const state = { csrf: null, timer: null, pairId: null, view: 'sites', preselect: null };

  // Sections of the panel. A new section is one line here plus its render function.
  // Side menu: operations first, then sites, then one settings page. Bulk and general settings live inside it.
  const NAV = [
    ['العمليات', [['ops', 'غرفة العمليات'], ['management', 'المشرفون والفنيون'], ['accounts', 'الحسابات والعمولات'], ['warranties', 'الضمانات']]],
    ['المواقع', [['sites', 'المواقع'], ['articles', 'المقالات']]],
    ['النظام', [['settings', 'الإعدادات']]],
  ];
  const SETTINGS_SECTIONS = [
    ['chat', 'الشات والواتساب', 'بيانات الشات اللي بيستخدمها الموقع: الاسم والتعليمات والخدمات وطرق الدفع. أرقام واتساب والتوكنات ما بتتخزنش هنا.'],
    ['dispatch', 'التوزيع والاستقبال', 'طريقة توزيع الطلبات الجديدة على الفنيين، وإيقاف استقبالها من المواقع، ونسبة الفني الافتراضية، والضمان الافتراضي للطلبات المكتملة.'],
    ['general', 'الإعدادات العامة', 'بيانات الشركة اللي تظهر في الفواتير والرسائل: الاسم، الرقم الضريبي، العنوان، ساعات العمل. بتتحفظ على الخادم فقط.'],
    ['bulk', 'الإعدادات الجماعية', 'تغيير اسم المواقع أو وصفها أو عدد المقالات في الصفحة على عدة مواقع مرة واحدة. بتبدأ بمعاينة، ومتتطبقش إلا بتأكيدك.'],
    ['faces', 'إعدادات الواجهات', 'كل أقسام إعدادات الموقع (التسعير، الضمانات، البوت، طرق الدفع…) بتتقرا وتتكتب من هنا عبر الجسر الموقّع. محتاج إضافة متقن 3 على الموقع.'],
    ['sites', 'ربط المواقع', 'كل موقع بيتربط مرة واحدة بالمفتاح من لوحة الإضافة على الموقع. الحالة ومتى اتفحص آخر مرة بتظهر في قايمة المواقع.'],
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
    not_open: 'الطلب مش مفتوح للإسناد',
    bad_email: 'البريد غير صحيح',
    unknown_section: 'القسم ده مش معروف',
    config_too_big: 'الإعدادات كبيرة أوي',
    config_too_deep: 'الإعدادات متداخلة أكتر من اللازم',
    bad_config_key: 'اسم الحقل فيه رموز مش مسموحة',
    bad_config_value: 'قيمة غير مسموحة في الإعدادات',
    face_unpaired: 'الموقع مش مربوط. اربطه الأول من «ربط المواقع».',
    face_unreachable: 'مفيش وصول للموقع دلوقتي',
    face_plugin_old: 'الموقع عليه نسخة قديمة من الإضافة. ارفع متقن 3.',
    face_refused: 'الموقع رفض الطلب',
    no_technician: 'مفيش فني مناسب لهذا الطلب',
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
    bad_share: 'النسبة لازم تكون من 0 لـ 100',
    not_technician: 'النسبة تتحدد للفنيين بس',
    bad_mode: 'الاختيار غير صحيح',
    bad_warranty: 'مدة الضمان لازم تكون من 0 لـ 730 يوم',
    insufficient_balance: 'المبلغ أكبر من رصيد الفني',
    note_required: 'اكتب سبب التسوية',
    bad_kind: 'نوع الحركة غير صحيح',
    bad_skill: 'تخصص غير معروف',
    too_many_skills: 'الحد الأقصى 10 تخصصات للفني',
    bad_amount: 'المبلغ غير صحيح',
    bad_note: 'السبب غير صالح، ومن غير علامات < أو >',
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
    const inSettings = ['settings', 'general', 'bulk', 'dispatch', 'chat', 'faces'].includes(state.view);
    const groups = NAV.map(([title, items]) => `
      <div class="nav-group">
        <div class="nav-title">${title}</div>
        ${items.map(([view, label]) => {
          const on = view === 'settings' ? inSettings : state.view === view;
          return `<button class="tab${on ? ' on' : ''}" data-view="${view}" aria-current="${on ? 'page' : 'false'}">${label}</button>`;
        }).join('')}
      </div>`).join('');
    return `
      <header class="top">
        <div class="brand"><span class="logo" aria-hidden="true">${LOGO}</span><span class="label">مركز التحكم</span></div>
        <button class="btn ghost" data-act="logout" aria-label="خروج">خروج</button>
      </header>
      <div class="frame">
        <nav class="side" aria-label="الأقسام">${groups}</nav>
        <div class="content">${body}</div>
      </div>`;
  }

  /** The settings page: each group is explained, so the owner knows what it changes before opening it. */
  function renderSettingsHub() {
    app.innerHTML = shell(`
      <section class="panel">
        <h2>الإعدادات</h2>
        <p class="muted">إعدادات نادراً ما بتتغير. كل قسم بيشرح نفسه، واللي بيغيّر المواقع بيبدأ بمعاينة.</p>
        ${SETTINGS_SECTIONS.map(([view, title, note]) => `
          <div class="pick">
            <button class="btn ghost sm" data-view="${view}">فتح</button>
            <span><b>${esc(title)}</b><small>${esc(note)}</small></span>
          </div>`).join('')}
      </section>`);
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
    const { people, skill_list: skillList = [] } = await api(`management/people?role=${encodeURIComponent(role)}`);
    const isTech = role === 'technician';
    const skillsCell = (p) => isTech
      ? `<td><details><summary>${(p.skills || []).length} تخصص</summary>
          <div class="chips" style="margin:8px 0">${skillList.map((k) => `<label class="lbl check"><input type="checkbox" data-skill-for="${esc(p.id)}" value="${esc(k)}"${(p.skills || []).includes(k) ? ' checked' : ''}> ${esc(k)}</label>`).join('')}</div>
          <button class="btn ghost sm" data-act="skills-save" data-id="${esc(p.id)}">حفظ التخصصات</button></details></td>`
      : '';
    const dispatch = isTech ? await api('settings/dispatch') : null;
    const shareCell = (p) => isTech
      ? `<td class="share-cell"><input class="field" type="number" min="0" max="100" dir="ltr" data-share-for="${esc(p.id)}" value="${esc(dispatch.shares[p.id] ?? '')}" placeholder="${dispatch.values.default_share}"><button class="btn ghost sm" data-act="share-save" data-id="${esc(p.id)}">حفظ</button></td>`
      : '';
    const rows = people.map((p) => `<tr>
        <td>${esc(p.name)}</td>
        <td class="ltr">${esc(p.phone || '—')}</td>
        <td>${esc(AREAS[p.area] || '—')}</td>
        <td><span class="pill ${p.active ? 'ok' : 'idle'}">${p.active ? 'نشط' : 'موقوف'}</span></td>
        ${skillsCell(p)}
        ${shareCell(p)}
        <td><button class="btn ghost sm" data-act="person-toggle" data-id="${esc(p.id)}" data-next="${p.active ? '0' : '1'}">${p.active ? 'إيقاف' : 'تفعيل'}</button></td>
      </tr>`).join('') || `<tr><td colspan="${isTech ? 7 : 5}" class="muted">${block.empty}</td></tr>`;

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
          <thead><tr><th>الاسم</th><th>الجوال</th><th>المنطقة</th><th>الحالة</th>${isTech ? '<th>التخصصات</th><th>نسبته من السعر (%)</th>' : ''}<th></th></tr></thead>
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
    const [custs, ords, dispatch] = await Promise.all([api('customers'), api('orders'), api('settings/dispatch')]);
    const thread = state.thread ? await api(`orders/${encodeURIComponent(state.thread)}/messages`).catch(() => null) : null;
    const threadOrder = state.thread ? ords.orders.find((o) => o.id === state.thread) : null;
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
      ${opsStrip()}
      ${dispatchCard(dispatch)}
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
      ${threadOrder && thread ? threadPanel(threadOrder, thread.messages) : ''}
      <section class="panel">
        <h2>الخريطة الميدانية والحية</h2>
        <p class="muted">مناطق التغطية (مكة، جدة) والفنيين اللي عندهم موقع مسجّل. الموقع بيتحدّث لما الفني يبعته من تطبيقه، أو لما تدخله يدوياً هنا.</p>
        <div class="chips" style="margin-bottom:10px">
          <label class="lbl">التخصص<select class="field" data-change="map-filter" data-key="skill"><option value="">الكل</option>${SKILL_LIST.map((k) => `<option value="${esc(k)}"${mapFilter().skill === k ? ' selected' : ''}>${esc(k)}</option>`).join('')}</select></label>
          <label class="lbl">الحالة<select class="field" data-change="map-filter" data-key="status">${Object.entries(TECH_STATUS).map(([k, v]) => `<option value="${k}"${mapFilter().status === k ? ' selected' : ''}>${esc(v[0])}</option>`).join('')}</select></label>
          <label class="lbl check"><input type="checkbox" data-change="map-filter" data-key="technicians"${mapFilter().technicians ? ' checked' : ''}> الفنيون</label>
          <label class="lbl check"><input type="checkbox" data-change="map-filter" data-key="customers"${mapFilter().customers ? ' checked' : ''}> العملاء</label>
          <label class="lbl">ترشيح فنيين لطلب<select class="field" data-change="map-order"><option value="">— بدون —</option>${state.orders.filter((o) => o.active && !o.technician_id).map((o) => `<option value="${esc(o.id)}"${state.mapOrder === o.id ? ' selected' : ''}>${esc(o.service || o.id)}</option>`).join('')}</select></label>
        </div>
        <div class="map-layout" style="margin-bottom:12px">
          <div id="ops-map" style="height:420px;border-radius:12px"></div>
          <aside class="map-side" id="map-side"><p class="muted">جارٍ تحميل الفنيين…</p></aside>
        </div>
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
  /** Must match Skills::LIST on the server. */
  const SKILL_LIST = ['تكييف', 'كهرباء', 'سباكة', 'أقفال ذكية', 'أجهزة منزلية', 'ساتلايت', 'دهان وديكور', 'تبليط وترميم', 'كاميرات', 'تبريد', 'تنظيف', 'مكافحة حشرات', 'خزانات', 'نقل أثاث', 'طاقة شمسية', 'صيانة عامة', 'مقاولات'];
  /** Technician status on the map: [label, marker colour]. */
  const TECH_STATUS = { '': ['الكل', ''], available: ['متاح', '#22c55e'], busy: ['مشغول', '#f59e0b'], stale: ['موقع قديم', '#64748b'], offline: ['بدون موقع', '#ef4444'] };
  const DEFAULT_MAP_FILTER = { skill: '', status: '', technicians: true, customers: true };
  function mapFilter() { return { ...DEFAULT_MAP_FILTER, ...(state.mapFilter || {}) }; }

  /** Side panel: status legend, technicians with their specialties, and the ranked candidates for the chosen order. */
  function renderMapSide(data, filter, cand) {
    const el = document.getElementById('map-side');
    if (!el) return;
    const colourOf = (s) => (TECH_STATUS[s] || TECH_STATUS.offline)[1];
    const legend = Object.entries(TECH_STATUS).filter(([k]) => k).map(([k, v]) =>
      `<span class="legend-item"><span class="legend-dot" style="background:${v[1]}"></span>${esc(v[0])}</span>`).join('');
    const techs = (data.technicians || []).filter((t) =>
      (!filter.status || t.status === filter.status) && (!filter.skill || (t.skills || []).includes(filter.skill)));
    const techRows = techs.map((t) => `<div class="tech-row">
        <span class="legend-dot" style="background:${colourOf(t.status)}"></span>
        <div class="tech-meta"><b>${esc(t.name)}</b><small>${esc((t.skills || []).join('، ') || 'بدون تخصص')}</small></div>
        <span class="pill">${esc((TECH_STATUS[t.status] || TECH_STATUS.offline)[0])}</span>
      </div>`).join('') || '<p class="muted">لا يوجد فنيون بهذا الفلتر.</p>';
    let candBlock = '';
    if (state.mapOrder) {
      if (!cand || cand.error) {
        candBlock = '<p class="muted">تعذّر تحميل المرشحين لهذا الطلب (ربما لم يعد مفتوحاً).</p>';
      } else {
        const rows = cand.candidates.map((c) => `<div class="tech-row">
            <span class="legend-dot" style="background:${colourOf(c.status)}"></span>
            <div class="tech-meta"><b>${esc(c.name)}</b><small>${c.km === null ? 'بدون موقع' : c.km + ' كم · ~' + c.minutes + ' دقيقة (تقدير)'}${c.matches ? ' · تخصص مطابق' : ''}</small></div>
            <span class="pill ${c.matches ? 'ok' : ''}">${esc((TECH_STATUS[c.status] || TECH_STATUS.offline)[0])}</span>
            <button class="btn ghost sm" data-act="assign-candidate" data-id="${esc(cand.order_id)}" data-tech="${esc(c.id)}">إسناد</button>
          </div>`).join('') || '<p class="muted">لا يوجد فنيون.</p>';
        candBlock = `<h3>المرشحون (الأقرب أولاً)</h3>${rows}`;
      }
    }
    el.innerHTML = `<div class="legend">${legend}</div><h3>الفنيون</h3>${techRows}${candBlock}`;
  }

  async function drawOpsMap() {
    const el = document.getElementById('ops-map');
    if (!el) return;
    let data;
    try {
      data = await api('map/live');
    } catch (error) {
      el.innerHTML = '<p class="muted" style="padding:16px">تعذّر تحميل الخريطة. القائمة والإحصائيات تشتغل بدونها.</p>';
      return;
    }
    const filter = mapFilter();
    const cand = state.mapOrder
      ? await api(`orders/${encodeURIComponent(state.mapOrder)}/candidates`).catch(() => ({ error: true }))
      : null;
    renderMapSide(data, filter, cand);
    try {
      const L = await loadLeaflet();
      if (state.map) { state.map.remove(); state.map = null; }
      const map = L.map(el).setView([21.45, 39.6], 9);
      state.map = map;
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap',
      }).addTo(map);
      const bounds = [];
      for (const area of Object.values(data.areas)) {
        L.circle([area.lat, area.lng], { radius: area.radius, color: '#0f6fb5', weight: 1, fillOpacity: 0.06 }).addTo(map).bindTooltip(area.label);
        bounds.push([area.lat, area.lng]);
      }
      for (const c of (filter.customers ? data.customers : [])) {
        const tags = [c.active_orders ? 'طلب نشط' : null, c.under_warranty ? 'ضمان ساري' : null, c.done_orders ? 'تعامل سابق' : null].filter(Boolean).join(' · ') || 'عميل جديد';
        const wa = c.phone ? `https://wa.me/${c.phone}?text=${encodeURIComponent('موقع العميل ' + c.name + ': https://maps.google.com/?q=' + c.lat + ',' + c.lng)}` : '';
        const marker = L.circleMarker([c.lat, c.lng], { radius: 8, color: c.active_orders ? '#1f8a4c' : '#6b7280', fillOpacity: 0.85 }).addTo(map);
        marker.bindPopup(`<b>${esc(c.name)}</b><br>${esc(tags)}<br>الطبقة: ${esc(c.tier_label)}${c.last_price ? '<br>آخر سعر: ' + c.last_price : ''}${c.rating ? '<br>التقييم: ' + c.rating + '/5' : ''}${wa ? `<br><a href="${esc(wa)}" target="_blank" rel="noopener">إرسال الموقع على واتساب</a>` : ''}`);
        bounds.push([c.lat, c.lng]);
      }
      for (const t of (filter.technicians ? data.technicians : [])) {
        if (t.lat === null) continue;
        if (filter.status && t.status !== filter.status) continue;
        if (filter.skill && !(t.skills || []).includes(filter.skill)) continue;
        const when = t.updated ? new Date(t.updated * 1000).toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' }) : '—';
        const skills = (t.skills || []).join('، ') || 'بدون تخصص';
        const colour = (TECH_STATUS[t.status] || TECH_STATUS.offline)[1];
        L.circleMarker([t.lat, t.lng], { radius: 9, color: colour, weight: 2, fillColor: colour, fillOpacity: 0.7 }).addTo(map)
          .bindPopup(`<b>${esc(t.name)}</b><br>${esc(TECH_STATUS[t.status][0])} · طلبات مفتوحة: ${t.busy_orders}<br>التخصص: ${esc(skills)}<br>آخر موقع: ${esc(when)}`);
        bounds.push([t.lat, t.lng]);
      }
      if (bounds.length > 1) map.fitBounds(bounds, { padding: [24, 24], maxZoom: 12 });
    } catch (error) {
      el.innerHTML = '<p class="muted" style="padding:16px">تعذّر تحميل الخريطة. القائمة والإحصائيات تشتغل بدونها.</p>';
    }
  }

  const DISTRIBUTE_MODES = [['nearest', 'أقرب فني'], ['available', 'متاح'], ['idle', 'متفرغ ونشط']];

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
  const ORDER_FILTERS = [
    ['all', 'الكل'],
    ['unassigned', 'بانتظار الإسناد'],
    ['assigned', 'مُسندة'],
    ['in_progress', 'جارية'],
  ];

  function ordersPanel() {
    const custOpts = state.customers.map((c) => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');
    const techOpts = (sel) => `<option value="">بدون فني</option>` + state.technicians.map((t) => `<option value="${esc(t.id)}"${t.id === sel ? ' selected' : ''}>${esc(t.name)}</option>`).join('');
    const statusOpts = (sel) => Object.entries(state.statuses).map(([k, v]) => `<option value="${k}"${k === sel ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const filter = state.orderFilter || 'all';
    const matches = {
      all: () => true,
      unassigned: (o) => !o.technician_id,
      assigned: (o) => o.status === 'assigned',
      in_progress: (o) => o.status === 'in_progress',
    };
    const open = state.orders.filter((o) => o.active && matches[filter](o));
    const chips = ORDER_FILTERS.map(([k, l]) => `<button class="btn ${k === filter ? 'primary' : 'ghost'} sm" data-act="order-filter" data-id="${k}">${l}</button>`).join(' ');
    const rows = open.map((o) => `<tr>
        <td>${esc(o.customer_name)}</td>
        <td class="ltr" dir="ltr">${esc(o.code || 'يدوي')}<small>${o.source === 'chat' ? 'من الشات' : o.source === 'form' ? 'من النموذج' : ''}</small></td>
        <td>${esc(o.service || '—')}</td>
        <td><select class="field" data-change="order-status" data-id="${esc(o.id)}">${statusOpts(o.status)}</select></td>
        <td><select class="field" data-change="order-tech" data-id="${esc(o.id)}">${techOpts(o.technician_id)}</select></td>
        <td class="ltr">${o.price ?? '—'}${o.technician_amount != null ? `<small>نصيب الفني ${o.technician_amount} (${o.technician_share_pct}%)</small>` : ''}</td>
        <td>${o.technician_id ? '' : DISTRIBUTE_MODES.map(([m, l]) => `<button class="btn ghost sm" data-act="distribute" data-id="${esc(o.id)}" data-mode="${m}">${l}</button>`).join(' ')}</td>
        <td><button class="btn ghost sm" data-act="thread" data-id="${esc(o.id)}">المحادثة</button></td>
      </tr>`).join('') || '<tr><td colspan="8" class="muted">لا توجد طلبات نشطة.</td></tr>';
    return `
      <section class="panel">
        <h2>الطلبات النشطة</h2>
        <div class="chips" style="margin-bottom:10px">${chips}</div>
        <form class="form-row" data-form="order-add">
          <label class="lbl">العميل<select class="field" name="customer_id" required>${custOpts || '<option value="">أضف عميل أولاً</option>'}</select></label>
          <label class="lbl">الخدمة<input class="field" name="service" maxlength="80" autocomplete="off"></label>
          <label class="lbl">السعر<input class="field" name="price" dir="ltr" inputmode="numeric" maxlength="7"></label>
          <label class="lbl">الفني<select class="field" name="technician_id">${techOpts('')}</select></label>
          <button class="btn primary" type="submit">إنشاء الطلب</button>
          <p class="err" role="alert"></p>
        </form>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>العميل</th><th>الكود</th><th>الخدمة</th><th>الحالة</th><th>الفني</th><th>السعر</th><th>توزيع تلقائي</th><th>المحادثة</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </section>`;
  }

  /** The thread of one order: messages between the owner, the supervisor, the technician and the customer. */
  function threadPanel(order, messages) {
    const rows = messages.map((m) => `<div class="pick"><span class="pill ${m.sender === 'customer' ? 'idle' : 'ok'}">${esc(m.sender_label)}</span><span>${esc(m.body)}</span><small class="ltr" dir="ltr">${new Date(m.created * 1000).toLocaleString('ar-SA')}</small></div>`).join('') || '<p class="muted">لا توجد رسائل بعد.</p>';
    return `
      <section class="panel">
        <h2>محادثة الطلب: ${esc(order.customer_name)} ${order.code ? `<small class="ltr" dir="ltr">${esc(order.code)}</small>` : ''}</h2>
        <div class="bulk-form">${rows}</div>
        <form class="form-row" data-form="order-message">
          <input type="hidden" name="order_id" value="${esc(order.id)}">
          <label class="lbl">رسالة<input class="field" name="body" required maxlength="500" autocomplete="off"></label>
          <button class="btn primary" type="submit">إرسال</button>
          <p class="err" role="alert"></p>
        </form>
      </section>`;
  }

  /** Automatic distribution for an open order that has no technician yet. */
  /** Status counts across the orders, the way the operations room reads them at a glance. */
  function opsStrip() {
    const startOfDay = new Date(); startOfDay.setHours(0, 0, 0, 0);
    const since = Math.floor(startOfDay.getTime() / 1000);
    const busy = new Set(state.orders.filter((o) => o.active && o.technician_id).map((o) => o.technician_id));
    const items = [
      ['بانتظار الإسناد', state.orders.filter((o) => o.active && !o.technician_id).length, 'warn', '⏳'],
      ['مُسندة', state.orders.filter((o) => o.status === 'assigned').length, 'accent', '📌'],
      ['جارية', state.orders.filter((o) => o.status === 'in_progress').length, 'ok', '🛠️'],
      ['تمت اليوم', state.orders.filter((o) => o.status === 'done' && o.updated >= since).length, 'ok', '✅'],
      ['فنيون متاحون', state.technicians.filter((t) => !busy.has(t.id)).length, 'accent', '👷'],
      ['فنيون مشغولون', state.technicians.filter((t) => busy.has(t.id)).length, 'idle', '🔧'],
    ];
    return `<section class="ops-strip">${items.map(([label, n, tone, icon]) =>
      `<div class="ops-card ${tone}"><span class="ico">${icon}</span><b>${n}</b><span>${label}</span></div>`).join('')}</section>`;
  }

  /** Distribution and intake controls: how new orders get a technician, and whether faces may send orders. */
  function dispatchCard(d) {
    const unassigned = state.orders.filter((o) => o.status === 'new' && !o.technician_id).length;
    const modeBtn = (k, l) => `<button class="btn sm ${d.values.mode === k ? 'primary' : 'ghost'}" data-act="dispatch-mode" data-id="${k}">${l}</button>`;
    const ruleBtn = (k, l) => `<button class="btn sm ${d.values.rule === k ? 'primary' : 'ghost'}" data-act="dispatch-rule" data-id="${k}">${l}</button>`;
    return `
      <section class="panel dispatch">
        <h2>أسلوب التوزيع واستقبال الطلبات</h2>
        <div class="chips">${modeBtn('manual', 'يدوي – المشرف يوزع')}${modeBtn('auto', 'آلي – فور الوصول')}</div>
        <div class="chips" style="margin-top:8px">${DISTRIBUTE_MODES.map(([m, l]) => ruleBtn(m, l)).join('')}</div>
        <div class="chips" style="margin-top:12px">
          <button class="btn primary" data-act="dispatch-run" ${unassigned ? '' : 'disabled'}>توزيع الطلبات الجديدة الآن (${unassigned})</button>
          <label class="lbl check"><input type="checkbox" data-change="intake-open"${d.values.intake_open ? ' checked' : ''}> استقبال الطلبات من المواقع</label>
        </div>
        <p class="muted">القاعدة بتنطبق على الطلبات الجديدة. الطلب اللي ما يلاقيش فني بيفضل بانتظار الإسناد.</p>
      </section>`;
  }

  /** Saves one dispatch setting, then redraws the room. Failures show their message, never a silent no-op. */
  async function saveDispatch(body, done) {
    try {
      await api('settings/dispatch', { method: 'POST', body });
      if (done) toast(done);
      return await renderOps();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر الحفظ');
    }
  }

  /** Distributes every new order that has no technician, one by one, with the current rule. */
  async function runDispatch() {
    const rule = (await api('settings/dispatch')).values.rule;
    const pending = state.orders.filter((o) => o.status === 'new' && !o.technician_id);
    let ok = 0;
    for (const o of pending) {
      try { await api(`orders/${encodeURIComponent(o.id)}/distribute`, { method: 'POST', body: { mode: rule } }); ok += 1; }
      catch (error) { if (error.code === 'no_session') return renderLogin(ERRORS.no_session); }
    }
    toast(`تم توزيع ${ok} من ${pending.length} طلب`);
    return await renderOps();
  }

  async function distributeOrder(id, mode) {
    try {
      await api(`orders/${encodeURIComponent(id)}/distribute`, { method: 'POST', body: { mode } });
      toast('تم الإسناد');
      await renderOps();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر الإسناد');
    }
  }

  /** Assigns the order to one candidate picked from the map side panel. */
  async function assignCandidate(orderId, techId) {
    try {
      await api(`orders/${encodeURIComponent(orderId)}`, { method: 'POST', body: { technician_id: techId, status: 'assigned' } });
      state.mapOrder = null;
      toast('تم الإسناد');
      await renderOps();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر الإسناد');
    }
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

  /** Technician accounts: finished jobs, earnings at their share, balance, and the ledger with deductions and payments. */
  async function renderAccounts() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const { technicians } = await api('accounts');
    const selected = state.ledgerPerson && technicians.some((t) => t.id === state.ledgerPerson)
      ? state.ledgerPerson : (technicians[0] ? technicians[0].id : '');
    state.ledgerPerson = selected;
    const ledger = selected ? await api(`ledger?person=${encodeURIComponent(selected)}`) : { balance: 0, rows: [] };
    const personOpts = technicians.map((t) => `<option value="${esc(t.id)}"${t.id === selected ? ' selected' : ''}>${esc(t.name)}</option>`).join('');
    const kindOpts = [['deduction', 'خصم'], ['payment', 'صرف'], ['adjustment', 'تسوية']]
      .map(([k, l]) => `<option value="${k}">${l}</option>`).join('');
    const ledgerRows = ledger.rows.map((r) => `<tr>
        <td>${esc(r.kind_label)}</td>
        <td class="ltr"><b>${r.amount > 0 ? '+' : ''}${r.amount}</b></td>
        <td>${esc(r.note || (r.order_id ? 'طلب مكتمل' : '—'))}</td>
        <td class="ltr">${new Date(r.created * 1000).toISOString().slice(0, 16).replace('T', ' ')}</td>
      </tr>`).join('') || '<tr><td colspan="4" class="muted">لا توجد حركات لهذا الفني بعد.</td></tr>';
    const totalEarned = technicians.reduce((sum, t) => sum + t.earned, 0);
    const totalDone = technicians.reduce((sum, t) => sum + t.done_count, 0);
    const rows = technicians.map((t) => `<tr>
        <td>${esc(t.name)}${t.active ? '' : ' <small class="muted">(موقوف)</small>'}</td>
        <td>${t.done_count}</td>
        <td class="ltr">${t.done_value}</td>
        <td>${t.share_pct}%</td>
        <td class="ltr"><b>${t.earned}</b></td>
        <td class="ltr"><b>${t.balance}</b></td>
        <td>${t.open_count}</td>
      </tr>`).join('') || '<tr><td colspan="7" class="muted">لا يوجد فنيون بعد.</td></tr>';
    app.innerHTML = shell(`
      <section class="stats">
        <div class="stat"><b>${totalDone}</b><span>طلب مكتمل</span></div>
        <div class="stat"><b>${totalEarned}</b><span>إجمالي نصيب الفنيين (ر.س)</span></div>
      </section>
      <section class="panel">
        <h2>الحسابات والعمولات</h2>
        <p class="muted">النصيب = سعر الطلبات المكتملة × نسبة الفني. النسبة بتتحدد من صفحة المشرفين والفنيين، أو الافتراضية من التوزيع.</p>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>الفني</th><th>مكتمل</th><th>قيمة المكتمل</th><th>النسبة</th><th>نصيبه</th><th>الرصيد</th><th>مفتوح</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </section>
      <section class="panel">
        <h2>حركات الرصيد</h2>
        <p class="muted">الخصم والصرف والتسوية بتتسجل هنا ومابتتعدلش بعد كده. الصرف مايعديش الرصيد، والتسوية لازم يكون ليها سبب.</p>
        <label class="lbl">الفني<select class="field" data-change="ledger-person">${personOpts || '<option value="">لا يوجد فنيون</option>'}</select></label>
        <p class="kpi">الرصيد الحالي: <b class="ltr">${ledger.balance}</b> ر.س</p>
        <form class="form-row" data-form="ledger">
          <input type="hidden" name="person_id" value="${esc(selected)}">
          <label class="lbl">نوع الحركة<select class="field" name="kind">${kindOpts}</select></label>
          <label class="lbl">المبلغ (ر.س)<input class="field" name="amount" dir="ltr" inputmode="numeric" maxlength="7" required></label>
          <label class="lbl">السبب<input class="field" name="note" maxlength="200" autocomplete="off"></label>
          <button class="btn primary" type="submit"${selected ? '' : ' disabled'}>تسجيل الحركة</button>
          <p class="err" role="alert"></p>
        </form>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>النوع</th><th>المبلغ</th><th>السبب</th><th>التاريخ</th></tr></thead>
          <tbody>${ledgerRows}</tbody>
        </table></div>
      </section>`);
  }

  /** Warranties: finished jobs with a warranty end date, active first, soonest end first. Read-only. */
  async function renderWarranties() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const { warranties } = await api('warranties');
    const active = warranties.filter((w) => w.active).length;
    const rows = warranties.map((w) => `<tr>
        <td>${esc(w.customer_name)}</td>
        <td>${esc(w.service || '—')}</td>
        <td>${esc(w.technician_name || '—')}</td>
        <td class="ltr">${new Date(w.warranty_until * 1000).toISOString().slice(0, 10)}</td>
        <td><span class="pill ${w.active ? 'ok' : 'idle'}">${w.active ? `ساري (${w.days_left} يوم)` : 'منتهي'}</span></td>
      </tr>`).join('') || '<tr><td colspan="5" class="muted">لا توجد ضمانات مسجلة بعد. الضمان بيتسجل لما الطلب يتقفل بمدة ضمان.</td></tr>';
    app.innerHTML = shell(`
      <section class="stats">
        <div class="stat"><b>${active}</b><span>ضمان ساري</span></div>
        <div class="stat"><b>${warranties.length - active}</b><span>ضمان منتهي</span></div>
      </section>
      <section class="panel">
        <h2>الضمانات</h2>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>العميل</th><th>الخدمة</th><th>الفني</th><th>ينتهي</th><th>الحالة</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </section>`);
  }

  /** Chat and WhatsApp: the business details, instructions, services and payment methods the site's assistant uses. */
  /**
   * A face's settings sections. A section travels as one document, so a new field on the site needs
   * no release here: the fields are drawn from whatever the face actually holds, by their type.
   */
  async function renderFaces() {
    const [overview, cat] = await Promise.all([api('overview'), api('config/sections')]);
    const faces = overview.sites.filter((s) => s.paired);
    const siteId = state.faceSite && faces.some((s) => s.id === state.faceSite) ? state.faceSite : (faces[0] || {}).id;
    const section = state.faceSection || Object.keys(cat.sections)[0];

    let body = '';
    if (!siteId) {
      body = '<p class="muted">مفيش موقع مربوط. اربط موقع من «ربط المواقع» الأول.</p>';
    } else if (state.faceDoc === undefined) {
      body = '<p class="muted">اضغط «اقرأ من الموقع» عشان تجيب القسم ده من الموقع.</p>';
    } else if (state.faceDoc === null) {
      body = `<p class="err">${esc(state.faceError || 'تعذّر القراءة من الموقع')}</p>`;
    } else {
      const entries = Object.entries(state.faceDoc);
      body = entries.length === 0
        ? '<p class="muted">القسم ده فاضي على الموقع. ضيف حقل بالأسفل.</p>'
        : `<table class="rows"><tbody>${entries.map(([key, value]) => `<tr>
              <td><code dir="ltr">${esc(key)}</code></td>
              <td>${faceInput(key, value)}</td>
              <td><button class="btn ghost sm" data-act="face-drop" data-id="${esc(key)}">حذف</button></td>
            </tr>`).join('')}</tbody></table>`;
      body += `<form class="form-row" data-form="face-add">
          <label class="lbl">حقل جديد<input class="field" name="key" dir="ltr" maxlength="64" placeholder="discount_pct" required></label>
          <label class="lbl">النوع<select class="field" name="type"><option value="text">نص</option><option value="number">رقم</option><option value="bool">مفتاح</option></select></label>
          <button class="btn" type="submit">إضافة</button>
          <p class="err" role="alert"></p>
        </form>
        <button class="btn primary" data-act="face-save">حفظ على الموقع</button>`;
    }

    app.innerHTML = shell(`
      <section class="panel">
        <h2>إعدادات الواجهات</h2>
        <p class="muted">الأقسام دي هي نفس أقسام الإعدادات اللي على الموقع. القراءة والكتابة بتمر على الجسر الموقّع، فمفيش تسجيل دخول ومفيش أخطاء صلاحيات.</p>
        <div class="chips" style="margin-bottom:12px">
          <label class="lbl">الموقع<select class="field" data-change="face-site">${faces.map((s) => `<option value="${esc(s.id)}"${s.id === siteId ? ' selected' : ''}>${esc(s.name)}</option>`).join('') || '<option value="">لا يوجد</option>'}</select></label>
          <label class="lbl">القسم<select class="field" data-change="face-section">${Object.entries(cat.sections).map(([k, label]) => `<option value="${esc(k)}"${k === section ? ' selected' : ''}>${esc(label)}</option>`).join('')}</select></label>
          <button class="btn" data-act="face-read">اقرأ من الموقع</button>
        </div>
        ${body}
      </section>`);
    state.faceSite = siteId;
    state.faceSection = section;
  }

  /** One field, drawn by what its value is rather than by a list written here. */
  function faceInput(key, value) {
    const id = `data-face-key="${esc(key)}"`;
    if (typeof value === 'boolean') return `<label class="lbl check"><input type="checkbox" ${id} ${value ? 'checked' : ''}> مفعّل</label>`;
    if (typeof value === 'number') return `<input class="field" type="number" ${id} value="${esc(String(value))}" dir="ltr">`;
    if (Array.isArray(value)) return `<textarea class="field" ${id} data-face-list="1" rows="3">${esc(value.join('\n'))}</textarea>`;
    if (value && typeof value === 'object') return `<textarea class="field" ${id} data-face-json="1" rows="4" dir="ltr">${esc(JSON.stringify(value, null, 2))}</textarea>`;
    // A field that holds no value must still hold no value after a save it was not part of:
    // sending "" instead would quietly change its type on the face.
    const wasNull = value === null ? ' data-face-null="1"' : '';
    return `<input class="field" type="text" ${id}${wasNull} value="${esc(value === null ? '' : String(value))}" maxlength="4000">`;
  }

  async function readFace() {
    state.faceDoc = undefined;
    try {
      const r = await api(`config/${encodeURIComponent(state.faceSite)}/${encodeURIComponent(state.faceSection)}`);
      state.faceDoc = r.config || {};
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      state.faceDoc = null;
      state.faceError = ERRORS[error.code] || 'تعذّر القراءة من الموقع';
    }
    return redrawFaces();
  }

  /**
   * Redraws the screen and never leaves it frozen. Without this, a failure while redrawing (the
   * catalogue request, say) rejects into nothing and the owner sees the old screen with no word why.
   */
  async function redrawFaces() {
    try {
      return await renderFaces();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر تحديث الشاشة');
    }
  }

  /** Collects the fields back into a document, keeping each one's type. */
  function collectFace() {
    const out = {};
    document.querySelectorAll('[data-face-key]').forEach((el) => {
      const key = el.dataset.faceKey;
      if (el.type === 'checkbox') { out[key] = el.checked; return; }
      if (el.dataset.faceList) { out[key] = el.value.split('\n').map((l) => l.trim()).filter(Boolean); return; }
      if (el.dataset.faceJson) {
        try { out[key] = JSON.parse(el.value); } catch (e) { throw new Error(`الحقل ${key} مش JSON صحيح`); }
        return;
      }
      if (el.type === 'number') {
        // An empty box is "no value", not zero: writing 0 would set a real price or limit nobody typed.
        out[key] = el.value.trim() === '' ? null : Number(el.value);
        return;
      }
      out[key] = (el.dataset.faceNull && el.value === '') ? null : el.value;
    });
    return out;
  }

  async function saveFace() {
    let config;
    try { config = collectFace(); } catch (error) { toast(error.message); return; }
    try {
      const r = await api(`config/${encodeURIComponent(state.faceSite)}/${encodeURIComponent(state.faceSection)}`, { method: 'POST', body: { config } });
      state.faceDoc = r.config || config;
      toast(r.changed ? 'تم الحفظ على الموقع' : 'مفيش تغيير');
      return redrawFaces();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر الحفظ على الموقع');
    }
  }

  async function renderChat() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const { values: v } = await api('settings/chat');
    const single = [
      ['company_name', 'اسم المؤسسة', 120], ['bot_name', 'اسم المساعد', 40], ['phone', 'الجوال الرئيسي', 20],
      ['whatsapp', 'رقم واتساب (بالمفتاح الدولي بدون +)', 20], ['email', 'البريد الإلكتروني', 120],
      ['city', 'نطاق الخدمة', 120], ['banner_text', 'نص البانر', 200],
    ].map(([key, label, max]) => `
        <label class="lbl">${esc(label)}
          <input class="field" name="${key}" maxlength="${max}" value="${esc(v[key] || '')}" autocomplete="off"${key.includes('phone') || key === 'whatsapp' || key === 'email' ? ' dir="ltr"' : ''}>
        </label>`).join('');

    app.innerHTML = shell(`
      <section class="panel">
        <h2>الشات والواتساب</h2>
        <p class="muted">القيم دي بتتحفظ على الديسك. الموقع بيقراها منه، وأي تعديل هنا بيوصل للمواقع المربوطة.</p>
        <form class="settings-form" data-form="chat" novalidate>
          <label class="lbl check"><input type="checkbox" name="enabled"${v.enabled ? ' checked' : ''}> الشات مفعّل على المواقع</label>
          ${single}
          <label class="lbl">تعليمات المساعد
            <textarea class="field" name="instructions" rows="12" maxlength="4000">${esc(v.instructions || '')}</textarea>
          </label>
          <label class="lbl">الخدمات (سطر لكل خدمة)
            <textarea class="field" name="services" rows="8">${esc((v.services || []).join('\n'))}</textarea>
          </label>
          <label class="lbl">طرق الدفع (سطر لكل طريقة)
            <textarea class="field" name="payment_methods" rows="4">${esc((v.payment_methods || []).join('\n'))}</textarea>
          </label>
          <div class="row end"><button class="btn primary" type="submit">حفظ الشات والواتساب</button></div>
          <p class="err" role="alert"></p>
        </form>
      </section>`);
  }

  /** Dispatch and intake: how new orders get a technician, pause for faces, default share and warranty. */
  async function renderDispatch() {
    app.innerHTML = shell('<p class="boot">جارٍ التحميل…</p>');
    const { values } = await api('settings/dispatch');
    const modeOpts = [['manual', 'يدوي: المشرف يوزع بنفسه'], ['auto', 'آلي: الطلب يتوزع فور وصوله']]
      .map(([k, l]) => `<option value="${k}"${values.mode === k ? ' selected' : ''}>${esc(l)}</option>`).join('');
    const ruleOpts = [['nearest', 'أقرب فني بموقع مسجّل'], ['available', 'أول فني متاح'], ['idle', 'متفرغ ونشط']]
      .map(([k, l]) => `<option value="${k}"${values.rule === k ? ' selected' : ''}>${esc(l)}</option>`).join('');

    app.innerHTML = shell(`
      <section class="panel">
        <h2>التوزيع والاستقبال</h2>
        <p class="muted">بيتطبق على الطلبات الجديدة فقط. الطلب اللي ما يلاقيش فني بيفضل "جديد" للمشرف، ومايضيعش.</p>
        <form class="settings-form" data-form="dispatch" novalidate>
          <label class="lbl">طريقة التوزيع<select class="field" name="mode">${modeOpts}</select></label>
          <label class="lbl">قاعدة التوزيع الآلي<select class="field" name="rule">${ruleOpts}</select></label>
          <label class="lbl">نسبة الفني الافتراضية من سعر الخدمة (%)
            <input class="field" name="default_share" type="number" min="0" max="100" dir="ltr" value="${esc(values.default_share)}">
          </label>
          <label class="lbl">الضمان الافتراضي للطلب المكتمل (يوم، 0 = بدون)
            <input class="field" name="default_warranty_days" type="number" min="0" max="730" dir="ltr" value="${esc(values.default_warranty_days)}">
          </label>
          <label class="lbl check"><input type="checkbox" name="intake_open"${values.intake_open ? ' checked' : ''}> استقبال الطلبات من المواقع مفتوح</label>
          <div class="row end"><button class="btn primary" type="submit">حفظ</button></div>
          <p class="err" role="alert"></p>
        </form>
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
      const pick = `<label class="pick"><input type="checkbox" name="use" value="${esc(key)}"><span>${esc(f.label)}</span></label>`;
      let input;
      if (f.type === 'bool') {
        input = `<label class="lbl check"><input type="checkbox" name="val:${esc(key)}" aria-label="${esc(f.label)}"> مفعّل</label>`;
      } else if (f.type === 'list') {
        input = `<textarea class="field" name="val:${esc(key)}" rows="4" aria-label="${esc(f.label)}" placeholder="خدمة في كل سطر"></textarea>`;
      } else if (f.type === 'int') {
        input = `<input class="field" type="number" inputmode="numeric" min="${f.min}" max="${f.max}" name="val:${esc(key)}" autocomplete="off" aria-label="${esc(f.label)}">`;
      } else {
        const mode = f.type === 'phone' ? ' inputmode="tel" dir="ltr"' : (f.type === 'email' ? ' inputmode="email" dir="ltr"' : '');
        input = `<input class="field" type="text" maxlength="${f.max}" name="val:${esc(key)}"${mode} autocomplete="off" aria-label="${esc(f.label)}">`;
      }
      return `<div class="bulk-field">${pick}${input}</div>`;
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
      const key = c.value;
      const type = bulkFields[key].type;
      const el = form.querySelector(`[name="val:${key}"]`);
      if (type === 'bool') { values[key] = el.checked; return; }
      const raw = el.value.trim();
      if (type === 'list') {
        // One per line, blanks dropped: that is how the owner types a list.
        values[key] = raw.split('\n').map((line) => line.trim()).filter(Boolean);
        return;
      }
      values[key] = type === 'int' ? Number(raw) : raw;
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
      if (view === 'settings') return renderSettingsHub();
      if (view === 'general') return await renderSettings();
      if (view === 'dispatch') return await renderDispatch();
      if (view === 'chat') return await renderChat();
      if (view === 'faces') return await renderFaces();
      if (view === 'accounts') return await renderAccounts();
      if (view === 'warranties') return await renderWarranties();
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

  /** Saves one technician's share. An empty box clears it, so the default from settings applies again. */
  /** Saves the specialties ticked for one technician. */
  async function saveSkills(id) {
    const picked = [...app.querySelectorAll(`input[data-skill-for="${CSS.escape(id)}"]:checked`)].map((i) => i.value);
    try {
      await api(`management/people/${encodeURIComponent(id)}/skills`, { method: 'POST', body: { skills: picked } });
      toast('تم حفظ التخصصات');
      return await renderManagement();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر الحفظ');
    }
  }

  async function saveShare(id) {
    const input = app.querySelector(`input[data-share-for="${CSS.escape(id)}"]`);
    const raw = input ? input.value.trim() : '';
    try {
      await api(`management/people/${encodeURIComponent(id)}/share`, { method: 'POST', body: { share_pct: raw === '' ? null : Number(raw) } });
      toast('تم حفظ النسبة');
      return await renderManagement();
    } catch (error) {
      if (error.code === 'no_session') return renderLogin(ERRORS.no_session);
      toast(ERRORS[error.code] || 'تعذّر الحفظ');
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
      if (form.dataset.form === 'order-message') {
        await api(`orders/${encodeURIComponent(values.order_id)}/messages`, { method: 'POST', body: { body: values.body } });
        toast('تم إرسال الرسالة');
        return await renderOps();
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
      if (form.dataset.form === 'face-add') {
        const key = form.key.value.trim();
        if (!/^[A-Za-z0-9_.-]{1,64}$/.test(key)) { form.querySelector('.err').textContent = 'اسم الحقل: حروف وأرقام و _ . - فقط'; return; }
        const blank = { text: '', number: 0, bool: false }[form.type.value];
        state.faceDoc = { ...collectFace(), [key]: blank };
        return redrawFaces();
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
      if (form.dataset.form === 'ledger') {
        const body = {
          person_id: form.elements.person_id.value,
          kind: form.elements.kind.value,
          amount: form.elements.amount.value,
          note: form.elements.note.value,
        };
        await api('ledger', { method: 'POST', body });
        toast('تم تسجيل الحركة');
        state.ledgerPerson = body.person_id;
        return await renderAccounts();
      }
      if (form.dataset.form === 'chat') {
        const body = {
          enabled: form.elements.enabled.checked,
          instructions: form.elements.instructions.value,
          services: form.elements.services.value,
          payment_methods: form.elements.payment_methods.value,
        };
        for (const key of ['company_name', 'bot_name', 'phone', 'whatsapp', 'email', 'city', 'banner_text']) body[key] = form.elements[key].value;
        await api('settings/chat', { method: 'POST', body });
        toast('تم حفظ الشات والواتساب');
        return await renderChat();
      }
      if (form.dataset.form === 'dispatch') {
        const body = {
          mode: form.elements.mode.value,
          rule: form.elements.rule.value,
          intake_open: form.elements.intake_open.checked,
          default_share: Number(form.elements.default_share.value),
          default_warranty_days: Number(form.elements.default_warranty_days.value),
        };
        await api('settings/dispatch', { method: 'POST', body });
        toast('تم حفظ التوزيع والاستقبال');
        return await renderDispatch();
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
    if (e.target.dataset.change === 'map-filter') {
      const key = e.target.dataset.key;
      state.mapFilter = { ...mapFilter(), [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value };
      return drawOpsMap();
    }
    if (e.target.dataset.change === 'face-site') { state.faceSite = e.target.value; state.faceDoc = undefined; return redrawFaces(); }
    if (e.target.dataset.change === 'face-section') { state.faceSection = e.target.value; state.faceDoc = undefined; return redrawFaces(); }
    if (e.target.dataset.change === 'map-order') { state.mapOrder = e.target.value || null; return drawOpsMap(); }
    if (e.target.dataset.change === 'ledger-person') { state.ledgerPerson = e.target.value; return renderAccounts(); }
    if (e.target.dataset.change === 'intake-open') return saveDispatch({ intake_open: e.target.checked }, e.target.checked ? 'الاستقبال مفتوح' : 'تم إيقاف الاستقبال مؤقتاً');
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
    if (act === 'share-save') return saveShare(id);
    if (act === 'skills-save') return saveSkills(id);
    if (act === 'order-filter') { state.orderFilter = id; return renderOps(); }
    if (act === 'dispatch-mode') return saveDispatch({ mode: id }, 'تم حفظ أسلوب التوزيع');
    if (act === 'dispatch-rule') return saveDispatch({ rule: id }, 'تم حفظ قاعدة التوزيع');
    if (act === 'dispatch-run') return runDispatch();
    if (act === 'distribute') return distributeOrder(id, btn.dataset.mode);
    if (act === 'assign-candidate') return assignCandidate(id, btn.dataset.tech);
    if (act === 'face-read') return readFace();
    if (act === 'face-save') return saveFace();
    if (act === 'face-drop') {
      // Collect what we can; a field the owner is still typing must not block removing another one.
      let current;
      try { current = collectFace(); } catch (error) { current = { ...state.faceDoc }; }
      delete current[id];
      state.faceDoc = current;
      return redrawFaces();
    }
    if (act === 'thread') { state.thread = id; return renderOps(); }
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
