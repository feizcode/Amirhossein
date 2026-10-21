import {createSchedule} from './schedule.js';
import {reportView} from './report-view.js';

export async function startJournal(root, hooks) {
  let subjects = [], catalogs = {}, TODAY = '', state = {role: 'child', page: 'diary', subject: 'math', source: 'school', period: 'week', resources: [], logs: [], extras: [], challenges: [], tasks: [], completions: []}, form = null, notice = '', busy = false, entryLimit = 10, backupFiles = [];
  let vibe = 'high';
  try { vibe = sessionStorage.getItem('kashf-vibe') || 'high'; } catch {}

  const fa = n => new Intl.NumberFormat('fa-IR').format(n);
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const icon = n => `<i data-lucide="${n}" aria-hidden="true"></i>`;
  const csrf = document.querySelector('meta[name=csrf-token]').content;

  function applyTheme() {
    try {
      const t = localStorage.getItem('kashf-theme') || 'light';
      if (t === 'dark') root.setAttribute('data-theme', 'dark');
      else root.removeAttribute('data-theme');
    } catch {}
  }
  applyTheme();

  function calcStreak() {
    const dates = new Set([...state.logs.map(x => x.date), ...state.extras.map(x => x.date)]);
    if (dates.size === 0) return 1;
    let streak = 0;
    let cur = new Date((TODAY || new Date().toISOString().slice(0, 10)) + 'T12:00:00Z');
    for (let i = 0; i < 30; i++) {
      const dStr = cur.toISOString().slice(0, 10);
      if (dates.has(dStr)) {
        streak++;
        cur.setUTCDate(cur.getUTCDate() - 1);
      } else if (streak === 0 && i === 0) {
        cur.setUTCDate(cur.getUTCDate() - 1);
      } else {
        break;
      }
    }
    return Math.max(1, streak);
  }

  async function api(path, method = 'GET', data) {
    const response = await fetch('/api/v1' + path, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(method !== 'GET' ? {'Content-Type': 'application/json', 'X-CSRF-Token': csrf} : {})
      },
      ...(data ? {body: JSON.stringify(data)} : {})
    });
    if (response.status === 401) {
      location.assign('/login');
      throw new Error('برای ادامه وارد شو.');
    }
    let value;
    try { value = await response.json(); } catch { throw new Error('پاسخ سرور کامل نیست؛ دوباره امتحان کن.'); }
    if (!response.ok) throw new Error(value.error || 'درخواست انجام نشد.');
    return value;
  }

  const name = s => subjects.find(x => x.id === s)?.name || 'روزمره';
  const topics = s => (catalogs[s] || []).flatMap(g => g.topics);
  const sources = s => state.resources.filter(r => r.subject === s && !r.archived);
  const sourceName = (s, id) => state.resources.find(r => r.subject === s && r.id === id)?.name || 'تکلیف معلم';
  const sourceTopics = (s, r) => state.resources.find(x => x.subject === s && x.id === r)?.topics || [];
  const isChild = () => state.role === 'child';
  const date = d => new Intl.DateTimeFormat('fa-IR', {day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Tehran'}).format(new Date(d + 'T12:00:00Z'));
  const jdate = d => /^\d{4}-\d{2}-\d{2}$/.test(d || '') ? new Intl.DateTimeFormat('en-US-u-ca-persian', {year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Tehran'}).formatToParts(new Date(d + 'T12:00:00Z')).reduce((o, p) => (o[p.type] = p.value, o), {}) : null;
  function jalali(d) { const p = jdate(d); return p ? `${p.year}/${p.month}/${p.day}` : String(d || ''); }

  const schedule = createSchedule({api, esc, date, jalali, name, isChild, today: () => TODAY});
  function count(s, r) { return state.completions.filter(x => x.subject === s && x.source === r).length; }
  function save() {
    try {
      sessionStorage.setItem('kashf-view-' + state.role, JSON.stringify({page: state.page, subject: state.subject, source: state.source, period: state.period}));
    } catch {}
  }
  function hydrate(data, view) {
    state = {...data, ...(view || {})};
    subjects = data.subjects;
    catalogs = data.catalogs;
    TODAY = data.today;
  }
  async function sync() {
    const view = {page: state.page, subject: state.subject, source: state.source, period: state.period};
    hydrate(await api('/state'), view);
    render();
  }

  function progress(s, r) {
    const n = count(s, r), total = sourceTopics(s, r).length;
    return `<div class="m-source">
      <div><span>${esc(sourceName(s, r))}</span><strong>${fa(total ? Math.round(n / total * 100) : 0)}٪</strong></div>
      <div class="m-track" role="progressbar" aria-label="پیشرفت انجام ${esc(sourceName(s, r))}" aria-valuenow="${n}" aria-valuemin="0" aria-valuemax="${total}">
        <span style="width:${total ? n / total * 100 : 0}%"></span>
      </div>
      <small class="m-progress-caption">${fa(n)} از ${fa(total)} بخش</small>
    </div>`;
  }

  function hero(kicker, title, sub, action = '') {
    return `<div class="m-between m-hero ${isChild() && state.page === 'diary' ? 'm-welcome' : ''}">
      <div>
        <div class="m-kicker">${kicker}</div>
        <h1>${title}</h1>
        <p>${sub}</p>
      </div>
      ${action || `<span class="m-date">${date(TODAY)}</span>`}
    </div>`;
  }

  function vibeBar() {
    return `<div class="m-vibe-bar" role="group" aria-label="مود امروز">
      <span>مود امروزت:</span>
      <button type="button" class="m-vibe-pill ${vibe === 'high' ? 'active' : ''}" data-vibe="high" aria-pressed="${vibe === 'high'}">⚡ پرانرژی</button>
      <button type="button" class="m-vibe-pill ${vibe === 'mid' ? 'active' : ''}" data-vibe="mid" aria-pressed="${vibe === 'mid'}">✌️ روال</button>
      <button type="button" class="m-vibe-pill ${vibe === 'low' ? 'active' : ''}" data-vibe="low" aria-pressed="${vibe === 'low'}">😴 خسته</button>
    </div>`;
  }

  function urgent() {
    const t = state.tasks.find(x => !x.done && x.due <= TODAY) || state.tasks.find(x => !x.done);
    return t ? `<section class="m-urgent" aria-label="تکلیف پیش رو">
      <div class="m-urgent-title">
        ${icon('flag')}
        <div>
          <div class="m-small">${t.due < TODAY ? 'موعد گذشته' : t.due === TODAY ? 'برای امروز' : date(t.due)} · ${esc(name(t.subject))}</div>
          <h3>${esc(t.title)}</h3>
        </div>
      </div>
      ${isChild() ? `<button class="cursor-interaction" type="button" data-m-task="${t.id}">ثبت انجام تکلیف ${icon('arrow-up-left')}</button>` : `<span class="m-small">در انتظار انجام</span>`}
    </section>` : '';
  }

  function storyEntry(l, type) {
    const extra = type === 'extra';
    return `<article class="m-entry ${extra ? 'm-entry-extra' : ''}">
      <div class="m-entry-label">
        <span class="m-small">${date(l.date)} · ${esc(name(l.subject))}</span>
        <span class="m-pill">${extra ? icon('sprout') + ' کار اضافی' : esc(sourceName(l.subject, l.source))}</span>
      </div>
      <div class="m-story">
        <h3>${esc(extra ? l.title : l.topic)}</h3>
        <p>${esc(extra ? l.report : l.note || 'فعالیت در دفتر ثبت شد.')}</p>
        ${extra && l.reference ? `<p class="m-small">منبع مطالعه: ${esc(l.reference)}</p>` : ''}
        ${!extra ? `<div class="m-row" style="margin-top:8px">${isChild() && !state.resources.find(r => r.subject === l.subject && r.id === l.source)?.archived ? `<button type="button" data-edit-log="${l.id}">${icon('pencil')} ویرایش فعالیت</button>` : ''}<button type="button" class="m-danger-link" data-delete-log="${l.id}">${icon('trash-2')} حذف</button></div>` : ''}
        ${extra ? `<div class="m-story-foot">
          <span class="m-tiny">${icon('sprout')} از روی کنجکاوی</span>
          ${isChild() ? `<button type="button" data-m-edit-extra="${l.id}">ویرایش گزارش</button>` : ''}
        </div>` : ''}
      </div>
    </article>`;
  }

  function diary() {
    const entries = [...state.logs.map(x => ({...x, type: 'log'})), ...state.extras.map(x => ({...x, type: 'extra'}))].sort((a, b) => b.date.localeCompare(a.date)).slice(0, entryLimit);
    return hero('دفتر امیرحسین', 'هر روز، یک کشف تازه.', 'کارهای امروزت را ثبت کن؛ سؤال‌های خوبت را هم نگه دار.', `<div class="m-row"><button type="button" class="m-primary cursor-interaction" data-m-open="log">${icon('plus')} ثبت فعالیت</button><button type="button" class="m-secondary cursor-interaction" data-m-open="challenge">${icon('message-circle-question')} ثبت چالش</button></div>`)
      + vibeBar()
      + urgent()
      + taskList()
      + `<div class="m-columns">
        <section>
          <div class="m-between m-sectionhead">
            <h2>ردّ یادگیری من</h2>
            <button type="button" data-m-open="extra" class="cursor-interaction">${icon('sprout')} کار اضافی</button>
          </div>
          <div class="m-diary">
            ${entries.map(x => storyEntry(x, x.type)).join('') || '<p class="m-inline-empty">دفترت منتظر اولین قدم توست؛ امروز چه چیزی یاد گرفتی؟</p>'}
          </div>
          ${state.logs.length + state.extras.length > entryLimit ? '<button type="button" data-more>دیدن ثبت‌های بیشتر</button>' : ''}
          
        </section>
        ${aside()}
      </div>`;
  }

  function aside() {
    return `<aside>
      <section class="m-notebook" data-m-future>
        <span class="m-soon">${icon('sparkles')} روبو-کاشف</span>
        <div class="m-coachmark" style="margin-top:15px">${icon('sparkles')}</div>
        <h3>یک همراه برای فکر کردن</h3>
        <p>اینجا مربی هوش مصنوعی می‌تواند برای قدم بعدی کمک کند.</p>
        <div class="m-quote">«اگر یک و نیم سیب داشته باشی، چطور آن را با یک کسر نشان می‌دهی؟»</div>
        <p class="m-tiny" style="margin-top:9px">نمونهٔ نمایشی؛ مربی متصل نیست.</p>
      </section>
      <section class="m-aside-section">
        <div class="m-between">
          <h3>درس‌های من</h3>
          <button type="button" data-m-page="lessons" aria-label="دیدن همهٔ درس‌ها">${icon('arrow-up-left')}</button>
        </div>
        ${subjects.slice(0, 3).map(s => `<div class="m-mini-lesson"><button type="button" data-m-subject="${s.id}">${icon(s.icon)} ${s.name}</button><span>${fa(count(s.id, sources(s.id)[0]?.id))} از ${fa(sourceTopics(s.id, sources(s.id)[0]?.id).length)}</span></div>`).join('')}
      </section>
      <section class="m-aside-section" data-m-future>
        <div class="m-row">
          <h3>کلکسیون کشف‌ها</h3>
          <span class="m-soon">${icon('trophy')} نشان‌ها</span>
        </div>
        <div class="m-collection" aria-label="نمونهٔ نشان‌های آینده">
          <span class="m-stamp" title="استریک پیوسته">${icon('flame')}</span>
          <span class="m-stamp" title="کاوشگر کنجکاو">${icon('telescope')}</span>
          <span class="m-stamp" title="جرقه ایده نو">${icon('lightbulb')}</span>
        </div>
        <p class="m-tiny" style="margin-top:8px">برای تلاش، پرسیدن و بیشتر دانستن</p>
      </section>
    </aside>`;
  }

  function courses(parent = false) {
    return hero(
      parent ? 'همراه مسیر رشد' : `${fa(subjects.length)} درس، مسیرهای تازه`,
      parent ? 'تصویر کلی یادگیری' : 'درس‌های من',
      parent ? 'پیشرفت انجام منابع؛ فعالیت و تسلط دو شاخص متفاوت‌اند.' : 'در هر درس، منابع جداگانه پیش می‌روند.',
      parent ? `<div class="m-row"><button type="button" class="m-primary" data-m-open="task">${icon('plus')} تکلیف</button><button type="button" class="m-secondary" data-m-open="source">${icon('book-plus')} منبع</button></div>` : ''
    )
    + (parent ? urgent() : '')
    + `<div class="m-course-grid">
      ${subjects.map(s => `<section class="m-course">
        <div class="m-course-head">
          <span class="m-course-icon">${icon(s.icon)}</span>
          <h3>${s.name}</h3>
        </div>
        ${sources(s.id).map(r => progress(s.id, r.id)).join('')}
        <button type="button" data-m-subject="${s.id}">دیدن مسیر درس ${icon('arrow-up-left')}</button>
      </section>`).join('')}
    </div>`
    + (parent ? `<section class="m-tasklist" style="margin-top:32px">
      <div class="m-between"><h2>تکلیف‌های پیش رو</h2><span class="m-small">${fa(state.tasks.filter(t => !t.done).length)} تکلیف باز</span></div>
      ${state.tasks.map(taskRow).join('') || '<p class="m-inline-empty">هنوز تکلیفی ثبت نشده است.</p>'}
    </section>${recentRecords()}` : '');
  }

  function recentRecords() {
    const list = [...state.logs.map(x => ({...x, type: 'log'})), ...state.extras.map(x => ({...x, type: 'extra'}))].sort((a, b) => b.date.localeCompare(a.date)).slice(0, 6);
    return list.length ? `<section class="m-report-section"><h2>آخرین نوشته‌های امیرحسین</h2>${list.map(x => storyEntry(x, x.type)).join('')}</section>` : '';
  }

  function taskRow(t) {
    return `<div class="m-taskrow">
      <div>
        <h3>${esc(t.title)}</h3>
        <span class="m-small">${esc(name(t.subject))} · ${date(t.due)}</span>
      </div>
      <div class="m-row">
        <span class="m-pill">${t.done ? icon('check') + ' انجام شد' : 'در انتظار انجام'}</span>
        ${!isChild() ? `<button type="button" data-edit-task="${t.id}">${icon('pencil')} ویرایش</button><button type="button" class="m-danger-link" data-delete-task="${t.id}">${icon('trash-2')} حذف</button>` : ''}
      </div>
    </div>`;
  }

  function subjectView() {
    const s = state.subject, r = sources(s).some(x => x.id === state.source) ? state.source : sources(s)[0]?.id, n = count(s, r);
    state.source = r;
    return `<button type="button" class="m-back" data-m-page="${isChild() ? 'lessons' : 'overview'}">${icon('arrow-right')} همهٔ درس‌ها</button>`
      + hero('مسیر یادگیری', esc(name(s)), 'منابع این درس، با پیشرفت مستقل.', isChild() ? `<div class="m-row"><button type="button" class="m-primary" data-m-open="log">${icon('plus')} ثبت فعالیت</button><button type="button" class="m-extra-highlight-btn" data-m-open="extra"><span class="m-extra-emoji">🌱</span><span>کار اضافی این درس</span><span class="m-extra-star">✨</span></button></div>` : `<button type="button" class="m-primary" data-m-open="source">${icon('plus')} منبع جدید</button>`)
      + `<div class="m-subject-tabs" role="group" aria-label="منبع">
        ${sources(s).map(x => `<button type="button" data-m-source="${x.id}" aria-pressed="${x.id === r}">${esc(x.name)}</button>`).join('')}
      </div>
      <div class="m-story">
        <div class="m-between">
          <h2>${esc(sourceName(s, r))}</h2>
          <span class="m-pill">${fa(n)} از ${fa(sourceTopics(s, r).length)} بخش</span>
        </div>
        <p>پیشرفت انجام فعالیت</p>
        <div class="m-topic-list">
          ${!['school', 'khs', 'ai'].includes(r) ? sourceTopics(s, r).map((t, i) => topicRow(t, i, s, r)).join('') : catalogs[s].map((g, idx) => `<details ${idx === 0 ? 'open' : ''}><summary>${esc(g.name)}</summary>${g.topics.map(t => topicRow(t, topics(s).findIndex(x => x.id === t.id), s, r)).join('')}</details>`).join('')}
        </div>
      </div>
      ${!isChild() ? `<div class="m-row" style="margin-top:18px"><button type="button" class="m-secondary" data-edit-resource="${state.resources.find(x => x.subject === s && x.id === r)?.database_id}">${icon('pencil')} ویرایش این منبع</button><button type="button" class="m-danger-link" data-archive="${state.resources.find(x => x.subject === s && x.id === r)?.database_id}">بایگانی این منبع</button></div>` : ''}
      ${isChild() ? `<div class="m-row" style="margin-top:18px"><button type="button" class="m-secondary" data-m-open="extra">${icon('sprout')} کار اضافی این درس</button><button type="button" data-m-open="challenge">${icon('message-circle-question')} ثبت چالش</button></div>` : ''}`;
  }

  function topicRow(t, i, s, r) {
    const done = state.completions.some(x => x.subject === s && x.source === r && x.topic === t.id);
    const log = done ? state.logs.find(x => x.subject === s && x.source === r && x.topic_id === t.id && x.completed) : null;
    return `<div class="m-topic">
      <span>${esc(t.name)}</span>
      ${done ? `<div class="m-row"><span class="m-pill">${icon('check')} انجام شده</span>${isChild() && log ? `<button type="button" class="m-undo-topic-btn" data-delete-log="${log.id}" title="برگرداندن از حالت انجام شده">${icon('rotate-ccw')} انجام نشده</button>` : ''}</div>` : isChild() ? `<button type="button" data-m-topic="${t.id}">ثبت فعالیت ${icon('plus')}</button>` : '<span class="m-small">در مسیر</span>'}
    </div>`;
  }

  function extrasView() {
    return hero('از روی کنجکاوی', 'بیشتر از کتاب.', 'چیزی که خودت دنبالش رفتی، ارزش ثبت کردن دارد.', `<button type="button" class="m-primary" data-m-open="extra">${icon('plus')} گزارش کار اضافی</button>`)
      + `<div class="m-columns">
        <section class="m-diary">
          ${[...state.extras].sort((a, b) => b.date.localeCompare(a.date)).map(x => storyEntry(x, 'extra')).join('') || '<p class="m-inline-empty">اولین کشفت را در دفتر بنویس.</p>'}
        </section>
        ${aside()}
      </div>`;
  }

  function challengeView() {
    return hero(
      isChild() ? 'سؤال‌های من' : 'گزارش جداگانهٔ چالش‌ها',
      isChild() ? 'گیر کردن هم بخشی از مسیر است.' : 'کجا به همراهی نیاز دارد؟',
      isChild() ? 'نوشتن چالش اختیاری است؛ از هر درس یا اتفاق روزانه.' : 'چالش‌ها با نوشتهٔ خود امیرحسین نمایش داده می‌شوند.',
      isChild() ? `<button type="button" class="m-primary" data-m-open="challenge">${icon('plus')} ثبت چالش</button>` : ''
    )
    + `<div class="m-challenge-list">
      ${[...state.challenges].sort((a, b) => b.date.localeCompare(a.date)).map(x => `<article class="m-story" style="margin-bottom:14px">
        <div class="m-between">
          <span class="m-pill">${esc(name(x.subject))}</span>
          <span class="m-small">${date(x.date)}</span>
        </div>
        <h3 style="margin-top:12px">${esc(x.title)}</h3>
        <p>${esc(x.report)}</p>
        <div class="m-row" style="margin-top:14px"><button type="button" data-edit-challenge="${x.id}">${icon('pencil')} ویرایش چالش</button><button type="button" class="m-danger-link" data-delete-challenge="${x.id}">${icon('trash-2')} حذف</button></div>
      </article>`).join('') || '<p class="m-inline-empty">هنوز چالشی ثبت نشده است.</p>'}
    </div>`;
  }

  function reportsView() {
    return reportView(state, {hero, esc, fa, name, date, storyEntry, icon});
  }

  const subjectOptions = (selected, general = false) => (general ? `<option value="general" ${selected === 'general' ? 'selected' : ''}>روزمره / عمومی</option>` : '') + subjects.map(s => `<option value="${s.id}" ${s.id === selected ? 'selected' : ''}>${s.name}</option>`).join('');

  function taskList() {
    return `<section class="m-tasklist">
      <div class="m-between"><h2>تکلیف‌های من</h2><span class="m-small">${fa(state.tasks.filter(x => !x.done).length)} تکلیف باز</span></div>
      ${state.tasks.map(t => `<div class="m-taskrow">
        <div>
          <h3>${esc(t.title)}</h3>
          <span class="m-small">${esc(name(t.subject))} · ${date(t.due)}</span>
          ${t.description ? `<p class="m-small">${esc(t.description)}</p>` : ''}
        </div>
        <button type="button" class="m-secondary" data-complete="${esc(t.id)}" data-done="${!t.done}">
          ${icon(t.done ? 'rotate-ccw' : 'check')}
          ${t.done ? 'انجام شده؛ برگرداندن' : 'انجام دادم'}
        </button>
      </div>`).join('') || '<p class="m-inline-empty">هنوز تکلیفی ثبت نشده؛ می‌توانی از منابع درس‌ها ادامه بدهی.</p>'}
    </section>`;
  }

  function backupView() {
    return hero('پشتیبان دفتر', 'نسخه‌های پشتیبان', 'نسخهٔ روزانه در اولین استفادهٔ آن روز ساخته می‌شود و هفت نسخهٔ آخر می‌مانند.')
      + `<section class="m-story">
        ${backupFiles.map(f => `<div class="m-taskrow">
          <div>
            <h3>${date(f.date)}</h3>
            <span class="m-small">${fa(Math.ceil(f.bytes / 1024))} کیلوبایت · JSON فشرده</span>
          </div>
          <a class="download" href="/api/v1/backups/${encodeURIComponent(f.name)}" download>${icon('database-backup')} دانلود پشتیبان</a>
        </div>`).join('') || '<p>نسخه‌ای آماده نیست.</p>'}
        <p class="m-small" style="margin-top:14px">فایل‌ها را در جای خصوصی نگه دارید. راهنمای بازیابی همراه سورس پروژه است.</p>
      </section>`;
  }

  function openForm(type, options = {}) {
    if (isChild() !== ['log', 'extra', 'challenge'].includes(type)) return;
    const task = state.tasks.find(x => x.id === options.task);
    const editTask = state.tasks.find(x => x.id === options.editTask);
    const extra = state.extras.find(x => x.id === options.extra);
    const log = state.logs.find(x => x.id === options.log);
    const challenge = state.challenges.find(x => x.id === options.challenge);
    const editRes = options.editResource ? state.resources.find(x => x.database_id === Number(options.editResource) || x.id === options.editResource) : null;
    const edit = extra || log || challenge || editTask || editRes;
    const s = edit?.subject || task?.subject || state.subject;
    const r = log?.source || (editRes ? editRes.id : (task ? 'school' : state.source));
    const available = sources(s);
    const source = available.some(x => x.id === r) ? r : available[0]?.id;
    form = {
      type,
      subject: s,
      source,
      topic: log?.topic_id || options.topic || sourceTopics(s, source)[0]?.id,
      date: edit?.date || TODAY,
      note: log?.note || '',
      kind: log?.kind || 'practice',
      complete: type === 'log' ? true : (log?.completed || false),
      title: editRes ? editRes.name : (edit?.title || ''),
      report: edit?.report || '',
      reference: extra?.reference || '',
      description: editTask?.description || '',
      id: editRes ? editRes.database_id : edit?.id,
      task: task?.id,
      due: editTask?.due || TODAY,
      shared: editRes ? ['school', 'khs', 'ai'].includes(editRes.id) : true,
      topics: editRes ? (editRes.topics || []).map(t => t.name).join('\n') : ''
    };
    notice = '';
    render();
  }

  function formView() {
    const f = form;
    const titles = {log: 'فعالیت من', extra: 'گزارش مطالعهٔ بیشتر', challenge: 'چالش روزانهٔ من', task: 'تکلیف جدید', source: f.id ? 'ویرایش منبع' : 'منبع جدید'};
    const task = state.tasks.find(x => x.id === f.task);
    const field = (label, input) => `<label class="m-field">${label}${input}</label>`;
    const day = (n, val) => field(n === 'due' ? 'موعد انجام؛ شمسی' : 'تاریخ انجام؛ شمسی', `<input name="${n}" inputmode="numeric" value="${esc(jalali(val))}" placeholder="۱۴۰۵/۰۷/۲۸" required maxlength="10" ${f.id && ['extra', 'challenge'].includes(f.type) ? 'readonly' : ''} aria-describedby="date-help">`);

    let content = `<div class="m-form-grid">
      ${field('درس', `<select name="subject" ${task || (f.id && f.type === 'source') ? 'disabled' : ''}>${subjectOptions(f.subject, f.type === 'challenge')}</select>`)}
      ${f.type === 'log' ? field('منبع', `<select name="source">${sources(f.subject).map(s => `<option value="${esc(s.id)}" ${s.id === f.source ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select>`) : f.type === 'task' ? day('due', f.due) : f.type !== 'source' ? day('date', f.date) : ''}
    </div>`;

    if (f.type === 'log') {
      content += field('موضوع', `<select name="topic">${sourceTopics(f.subject, f.source).map(t => `<option value="${esc(t.id)}" ${t.id === f.topic ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>`)
        + day('date', f.date)
        + field('یادداشت؛ اختیاری', `<textarea name="note" maxlength="1600" placeholder="اگر دوست داشتی دربارهٔ کارت بنویس…">${esc(f.note)}</textarea>`);
    } else if (f.type === 'source') {
      content += field('نام منبع', `<input name="title" value="${esc(f.title)}" maxlength="190" required>`)
        + `<label class="m-check"><input name="shared" type="checkbox" ${f.shared ? 'checked' : ''}>سرفصل‌های کتاب مدرسه را داشته باشد</label>`
        + (!f.shared ? field('موضوع‌ها؛ هر کدام در یک خط', `<textarea name="topics" required maxlength="12000" rows="8">${esc(f.topics)}</textarea>`) : '');
    } else {
      content += field(f.type === 'task' ? 'عنوان تکلیف' : 'عنوان', `<input name="title" value="${esc(f.title)}" required maxlength="190">`)
        + field(f.type === 'task' ? 'توضیح تکلیف؛ اختیاری' : 'گزارش من', `<textarea name="${f.type === 'task' ? 'description' : 'report'}" ${f.type === 'task' ? '' : 'required'} maxlength="${f.type === 'task' ? '1600' : '4000'}">${esc(f.type === 'task' ? f.description : f.report)}</textarea>`)
        + (f.type === 'extra' ? field('منبع مطالعه؛ اختیاری', `<input name="reference" value="${esc(f.reference)}" maxlength="255">`) : '');
    }

    return `<button type="button" class="m-back" data-m-cancel>${icon('arrow-right')} بازگشت</button>
    <form class="m-form" data-m-form="${f.type}">
      <header>
        <div class="m-kicker">${isChild() ? 'دفتر امیرحسین' : 'همراه مسیر رشد'}</div>
        <h2>${f.id && f.type !== 'source' ? 'ویرایش: ' : ''}${titles[f.type]}</h2>
        ${f.type === 'challenge' ? '<p class="m-small">نوشتن این گزارش اختیاری است؛ با زبان خودت بنویس.</p>' : ''}
        ${task ? `<p class="m-notice">${esc(task.title)}</p>` : ''}
      </header>
      ${content}
      ${f.type !== 'source' ? '<p class="m-tiny" id="date-help">تاریخ را مثل ۱۴۰۵/۰۷/۲۸ وارد کنید.</p>' : ''}
      <div class="m-error" role="alert" data-form-error></div>
      <div class="m-row" style="margin-top:20px">
        <button type="submit" class="m-primary">${icon('check')} ${f.id ? 'ذخیرهٔ تغییرات' : 'ثبت'}</button>
        <button type="button" class="m-secondary" data-m-cancel>انصراف</button>
        ${f.id && f.type === 'log' ? `<button type="button" class="m-danger" data-delete-log="${f.id}">${icon('trash-2')} حذف این فعالیت</button>` : ''}
        ${f.id && f.type === 'challenge' ? `<button type="button" class="m-danger" data-delete-challenge="${f.id}">${icon('trash-2')} حذف این چالش</button>` : ''}
        ${f.id && f.type === 'task' ? `<button type="button" class="m-danger" data-delete-task="${f.id}">${icon('trash-2')} حذف این تکلیف</button>` : ''}
      </div>
    </form>`;
  }

  function capture() {
    const el = root.querySelector('[data-m-form]');
    if (!el || !form) return;
    for (const key of ['subject', 'source', 'topic', 'date', 'due', 'note', 'kind', 'title', 'report', 'reference', 'description', 'topics']) {
      if (el.elements[key] && !el.elements[key].disabled) form[key] = el.elements[key].value;
    }
    for (const key of ['complete', 'shared']) {
      if (el.elements[key]) form[key] = el.elements[key].checked;
    }
    if (form.type === 'log') form.complete = true;
  }

  async function submitForm(el) {
    if (busy || !form || !el.reportValidity()) return;
    capture();
    const f = form;
    if (isChild() !== ['log', 'extra', 'challenge'].includes(f.type)) return;
    const endpoints = {log: 'activities', extra: 'extra-studies', challenge: 'challenges', task: 'assignments', source: 'resources'};
    busy = true;
    el.querySelector('button[type=submit]').disabled = true;
    try {
      await api('/' + endpoints[f.type] + (f.id ? '/' + f.id : ''), f.id ? 'PUT' : 'POST', f);
      form = null;
      notice = 'ثبت شد؛ دفترت به‌روز است.';
      if (!f.id && window.shootConfetti) window.shootConfetti();
      await sync();
      save();
    } catch (e) {
      el.querySelector('[data-form-error]').textContent = e.message;
      el.querySelector('button[type=submit]').disabled = false;
    } finally {
      busy = false;
    }
  }

  function render() {
    const nav = isChild() ? [
      ['schedule', 'برنامهٔ من', 'sun'],
      ['diary', 'دفتر من', 'notebook-pen'],
      ['lessons', 'درس‌ها', 'library'],
      ['extras', 'کشف‌های من', 'sprout'],
      ['challenges', 'چالش‌های من', 'message-circle-question']
    ] : [
      ['schedule', 'برنامهٔ هفتگی', 'sun'],
      ['overview', 'نمای کلی', 'layout-dashboard'],
      ['reports', 'گزارش‌ها', 'chart-no-axes-combined'],
      ['challenges', 'چالش‌ها', 'message-circle-question'],
      ['backups', 'پشتیبان‌ها', 'database-backup']
    ];

    if (![...nav.map(x => x[0]), 'subject'].includes(state.page)) {
      state.page = isChild() ? 'diary' : 'overview';
    }

    root.querySelectorAll('[data-role]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.role === state.role)));
    root.querySelector('[data-compose]').setAttribute('aria-label', isChild() ? 'ثبت فعالیت' : 'افزودن تکلیف');
    root.querySelector('[data-compose-label]').textContent = isChild() ? 'ثبت فعالیت' : 'افزودن تکلیف';

    const navEl = root.querySelector('[data-nav]');
    if (!isChild()) navEl.setAttribute('data-parent', 'true');
    else navEl.removeAttribute('data-parent');

    navEl.innerHTML = nav.map(([id, label, i]) => `<button type="button" data-m-page="${id}" ${state.page === id ? 'aria-current="page"' : ''}><span class="m-nav-icon">${icon(i)}</span><span>${label}</span></button>`).join('');

    root.querySelector('[data-notice]').innerHTML = (notice ? `<p class="m-notice">${esc(notice)}</p>` : '') + (!isChild() && state.backupWarning ? '<p class="m-error">پشتیبان روزانه ساخته نشده؛ فضای دیسک و دسترسی پوشهٔ پشتیبان را بررسی کنید.</p>' : '');
    root.querySelector('[data-content]').innerHTML = form ? formView() : state.page === 'schedule' ? schedule.render() : state.page === 'subject' ? subjectView() : state.page === 'lessons' ? courses() : state.page === 'overview' ? courses(true) : state.page === 'reports' ? reportsView() : state.page === 'challenges' ? challengeView() : state.page === 'extras' ? extrasView() : state.page === 'backups' ? backupView() : diary();

    // Update streak badge in header
    const streak = calcStreak();
    const streakCountEl = root.querySelector('[data-streak-count]');
    if (streakCountEl) streakCountEl.textContent = `${fa(streak)} روز`;
    const streakBadge = root.querySelector('[data-streak-badge]');
    if (streakBadge) streakBadge.style.display = isChild() ? 'inline-flex' : 'none';
    const extraTopBtn = root.querySelector('[data-extra-top-btn]');
    if (extraTopBtn) extraTopBtn.style.display = isChild() ? 'inline-flex' : 'none';

    window.lucide?.createIcons({attrs: {width: 20, height: 20}});
  }

  root.addEventListener('click', async e => {
    const b = e.target.closest('button');
    if (!b || !root.contains(b) || busy) return;
    try {
      if (b.closest('[data-theme-toggle]')) {
        const isDark = root.getAttribute('data-theme') === 'dark';
        const next = isDark ? 'light' : 'dark';
        if (next === 'dark') root.setAttribute('data-theme', 'dark');
        else root.removeAttribute('data-theme');
        try { localStorage.setItem('kashf-theme', next); } catch {}
        return;
      }
      if (b.dataset.vibe) {
        vibe = b.dataset.vibe;
        try { sessionStorage.setItem('kashf-vibe', vibe); } catch {}
        render();
        return;
      }
      if (b.dataset.sch) {
        await schedule.click(b);
        render();
        return;
      }
      if (b.dataset.role) {
        schedule.reset();
        hooks.busy(true);
        hydrate(await api('/context', 'POST', {role: b.dataset.role}));
        let view;
        try { view = JSON.parse(sessionStorage.getItem('kashf-view-' + state.role)); } catch {}
        if (view) Object.assign(state, view);
        form = null;
        notice = '';
        if (state.page === 'schedule') await schedule.load();
        if (state.page === 'backups') backupFiles = (await api('/backups')).files;
        render();
        return;
      }
      if (b.hasAttribute('data-compose')) {
        openForm(isChild() ? 'log' : 'task');
        return;
      }
      if (b.hasAttribute('data-m-cancel')) {
        form = null;
        render();
        return;
      }
      if (b.hasAttribute('data-more')) {
        entryLimit += 10;
        render();
        return;
      }
      if (b.dataset.editLog) {
        openForm('log', {log: b.dataset.editLog});
        return;
      }
      if (b.dataset.editTask) {
        openForm('task', {editTask: b.dataset.editTask});
        return;
      }
      if (b.dataset.editChallenge) {
        openForm('challenge', {challenge: b.dataset.editChallenge});
        return;
      }
      if (b.dataset.deleteLog) {
        if (!confirm('آیا از حذف این فعالیت مطمئن هستید؟')) return;
        await api('/activities/' + b.dataset.deleteLog, 'DELETE');
        form = null;
        notice = 'فعالیت حذف شد.';
        await sync();
        return;
      }
      if (b.dataset.deleteTask) {
        if (!confirm('آیا از حذف این تکلیف مطمئن هستید؟')) return;
        await api('/assignments/' + b.dataset.deleteTask, 'DELETE');
        form = null;
        notice = 'تکلیف حذف شد.';
        await sync();
        return;
      }
      if (b.dataset.deleteChallenge) {
        if (!confirm('آیا از حذف این چالش مطمئن هستید؟')) return;
        await api('/challenges/' + b.dataset.deleteChallenge, 'DELETE');
        form = null;
        notice = 'چالش حذف شد.';
        await sync();
        return;
      }
      if (b.dataset.editResource) {
        openForm('source', {editResource: b.dataset.editResource});
        return;
      }
      if (b.dataset.archive) {
        await api('/resources/' + b.dataset.archive + '/archive', 'POST', {});
        notice = 'منبع بایگانی شد؛ سابقه‌ها حفظ شده‌اند.';
        await sync();
        return;
      }
      if (b.dataset.complete) {
        const markingDone = b.dataset.done === 'true';
        await api('/assignments/' + b.dataset.complete + '/complete', 'POST', {complete: markingDone});
        notice = 'وضعیت تکلیف به‌روز شد.';
        if (markingDone && window.shootConfetti) window.shootConfetti();
        await sync();
        return;
      }
      if (b.dataset.mPage) {
        const allowed = isChild() ? ['schedule', 'diary', 'lessons', 'extras', 'challenges'] : ['schedule', 'overview', 'reports', 'challenges', 'backups'];
        if (!allowed.includes(b.dataset.mPage)) return;
        state.page = b.dataset.mPage;
        form = null;
        notice = '';
        if (state.page === 'schedule') await schedule.load();
        if (state.page === 'backups') backupFiles = (await api('/backups')).files;
        render();
        save();
        return;
      }
      if (b.dataset.mOpen) {
        openForm(b.dataset.mOpen);
        return;
      }
      if (b.dataset.mTask) {
        openForm('log', {task: b.dataset.mTask});
        return;
      }
      if (b.dataset.mEditExtra) {
        openForm('extra', {extra: b.dataset.mEditExtra});
        return;
      }
      if (b.dataset.mSubject) {
        state.subject = b.dataset.mSubject;
        state.source = sources(state.subject)[0]?.id;
        state.page = 'subject';
        form = null;
        notice = '';
        render();
        save();
        return;
      }
      if (b.dataset.mSource) {
        state.source = b.dataset.mSource;
        render();
        save();
        return;
      }
      if (b.dataset.mTopic) {
        openForm('log', {topic: b.dataset.mTopic});
        return;
      }
      if (b.dataset.mPeriod) {
        state.period = b.dataset.mPeriod;
        render();
        save();
      }
    } catch (err) {
      notice = err.message;
      render();
    } finally {
      hooks.busy(false);
    }
  });

  root.addEventListener('change', e => {
    if (!form) return;
    capture();
    if (e.target.name === 'subject') {
      form.source = sources(form.subject)[0]?.id;
      form.topic = sourceTopics(form.subject, form.source)[0]?.id;
      render();
    } else if (e.target.name === 'source') {
      form.topic = sourceTopics(form.subject, form.source)[0]?.id;
      render();
    } else if (e.target.name === 'shared') {
      render();
    }
  });

  root.addEventListener('submit', e => {
    if (e.target.matches('[data-sch-form],[data-sch-seed]')) {
      e.preventDefault();
      schedule.submit(e.target).then(render);
      return;
    }
    if (e.target.matches('[data-m-form]')) {
      e.preventDefault();
      submitForm(e.target);
    }
  });

  try {
    const data = await api('/state');
    hydrate(data);
    let view;
    try { view = JSON.parse(sessionStorage.getItem('kashf-view-' + state.role)); } catch {}
    if (view) Object.assign(state, view);
    if (state.page === 'schedule') await schedule.load();
    if (state.page === 'backups') backupFiles = (await api('/backups')).files;
    render();
  } finally {
    hooks.busy(false);
  }
}
