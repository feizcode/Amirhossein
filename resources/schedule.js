import './schedule.css';

export function createSchedule({api, esc, date, jalali, name, isChild, today}) {
  let selected = '', view = 'week', data = null, recs = null, editor = null, draft = null, error = '', loading = false, serial = 0;
  let finishedSessionCount = 0;

  const days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
  const subjects = {
    math: 'ریاضی',
    persian: 'فارسی',
    science: 'علوم',
    social: 'مطالعات اجتماعی',
    religion: 'هدیه‌های آسمان',
    quran: 'قرآن'
  };
  const kinds = {
    class: 'کلاس',
    homework: 'وقت تکلیف',
    rest: 'استراحت و غذا',
    personal: 'فعالیت شخصی',
    meeting: 'جلسه و گفتگو'
  };

  const fa = n => String(n ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const shift = (d, n) => {
    const x = new Date(d + 'T12:00:00Z');
    x.setUTCDate(x.getUTCDate() + n);
    return x.toISOString().slice(0, 10);
  };
  const weekday = d => (new Date(d + 'T12:00:00Z').getUTCDay() + 1) % 7;
  const time = n => fa(`${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`);
  const nativeTime = n => `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;

  async function load() {
    selected ||= today();
    const token = ++serial;
    loading = true;
    error = '';
    try {
      const start = shift(selected, -weekday(selected));
      const [a, b] = await Promise.all([
        api(`/schedule?from=${start}&to=${shift(start, 6)}`),
        api(`/recommendations?date=${selected}`)
      ]);
      if (token === serial) {
        data = a;
        recs = b;
      }
    } catch (e) {
      if (token === serial) error = e.message;
    } finally {
      if (token === serial) loading = false;
    }
  }

  const icon = n => `<i data-lucide="${n}" class="ks-icon" aria-hidden="true"></i>`;
  const button = (action, text, extra = '') => `<button type="button" class="m-secondary" data-sch="${action}" ${extra}>${text}</button>`;

  function slot(e) {
    const isOnce = Boolean(e.exception_id);
    const isKangaroo = e.title === 'کانگورو';
    return `<div class="sch-slot" data-kind="${esc(e.kind)}">
      <span class="sch-time" dir="ltr">${time(e.start_minute)}–${time(e.end_minute)}</span>
      <div>
        <button type="button" data-sch="edit" data-key="${esc(e.key)}">
          <span>${esc(e.title)}</span>
          ${!isChild() ? icon('pencil') : ''}
        </button>
        ${isOnce ? `<p class="sch-note">فقط این نوبت</p>` : isKangaroo ? `<p class="sch-note">کلاس مرتبط با ریاضی</p>` : e.subject_id ? `<p class="sch-note">${esc(name(e.subject_id))}</p>` : ''}
      </div>
    </div>`;
  }

  function homeworkWindow(e) {
    return `<div class="sch-homework-window">
      <div>
        <strong>${esc(e.title)}</strong>
        <small>بازهٔ آزاد برای انجام کارها، بدون الزام به پرکردن همهٔ زمان</small>
        ${!isChild() ? `<br><button type="button" data-sch="edit" data-key="${esc(e.key)}">ویرایش بازه</button>` : ''}
      </div>
      <b dir="ltr">${time(e.start_minute)}–${time(e.end_minute)}</b>
    </div>`;
  }

  function recommendations() {
    const tasks = recs?.tasks || [];
    const next = recs?.next;
    const todaySubjects = recs?.today_subjects || [];

    let out = `<section class="sch-recommendations" aria-label="پیشنهاد تکلیف">
      <div class="sch-section-head">
        <h2>از کجا شروع کنم؟</h2>
        <small>بر اساس موعد و کلاس‌ها</small>
      </div>`;

    if (next && next.subjects?.length) {
      const isTomorrow = next.date === shift(selected, 1);
      const label = isTomorrow ? 'آماده برای فردا' : `آماده برای ${days[weekday(next.date)]}`;
      out += `<div class="sch-next">
        <h3>${icon('backpack')} ${label}</h3>
        <div class="sch-chips">
          ${[...new Set(next.subjects)].map(s => `<span class="sch-chip">${esc(name(s))}</span>`).join('')}
        </div>
      </div>`;
    }

    if (finishedSessionCount > 0) {
      out += `<div class="sch-finished">
        ${icon('check-circle-2')} ${fa(finishedSessionCount)} تکلیف در این بخش انجام شد.
      </div>`;
    }

    const card = t => {
      const badges = {
        0: 'موعد گذشته',
        1: 'موعد امروز',
        2: 'موعد فردا',
        3: 'آمادگی کلاس بعد',
        4: 'درس امروز',
        5: 'تکلیف باز'
      };
      const badge = badges[t.rank] || 'تکلیف باز';
      return `<article class="sch-task">
        <div class="sch-task-top">
          <span>${esc(name(t.subject_id))}</span>
          <em>${badge}</em>
        </div>
        <h3>${esc(t.title)}</h3>
        <p class="sch-reason">${icon('lightbulb')} <span>${esc(t.reason)}</span></p>
        <div class="sch-task-actions">
          <small>موعد ${esc(date(t.due_date))}</small>
          ${isChild() ? `<button type="button" class="sch-complete-btn" data-sch="complete" data-id="${t.id}">${icon('check')} انجام دادم</button>` : `<span class="sch-muted">ثبت انجام با امیرحسین</span>`}
        </div>
      </article>`;
    };

    if (tasks.length) {
      out += tasks.slice(0, 4).map(card).join('');
      if (tasks.length > 4) {
        out += `<details class="sch-rule">
          <summary>سایر تکلیف‌های باز (${fa(tasks.length - 4)})</summary>
          ${tasks.slice(4).map(card).join('')}
        </details>`;
      }
    } else {
      out += `<div class="sch-finished">تکلیف بازی نمانده؛ وقت آزاد توست.</div>`;
    }

    if (todaySubjects.length) {
      out += `<div class="sch-optional">
        <h3>اگر خواستی، یک مرور کوتاه</h3>
        <p>${todaySubjects.map(s => esc(name(s))).join('، ')} · پیشنهاد اختیاری است و تکلیف تازه‌ای از طرف معلم ایجاد نمی‌کند.</p>
      </div>`;
    }

    out += `<details class="sch-rule">
      <summary>این پیشنهادها چطور انتخاب می‌شوند؟</summary>
      <p>اول موعدهای گذشته و امروز، بعد موعد فردا، سپس تکلیف درس‌های نزدیک‌ترین روز کلاس و در آخر درس‌های امروز. این پیشنهادها بر پایهٔ برنامه و تکلیف‌های واقعی ثبت‌شده در سامانه انتخاب می‌شوند و نیازی به سرویس هوش مصنوعی بیرونی ندارند.</p>
      <p>حضور در کلاس، پایان زمان تکلیف یا ثبت انجام تکلیف، درصد پیشرفت کتاب را خودکار تغییر نمی‌دهد.</p>
    </details></section>`;

    return out;
  }

  function renderDay() {
    const occurrences = (data?.occurrences || []).filter(e => e.date === selected);
    const morning = occurrences.filter(e => Number(e.start_minute) < 720);
    const afternoon = occurrences.filter(e => Number(e.start_minute) >= 720);
    const dayIdx = weekday(selected);

    let out = `<div class="sch-day-grid">
      <section>
        <div class="sch-section-head">
          <h2>${days[dayIdx]} من</h2>
          <small>${esc(date(selected))}</small>
        </div>
        <div class="sch-agenda">
          <div class="sch-section-label">${icon('sun')} کلاس‌ها و برنامهٔ صبح</div>
          <div class="sch-agenda-list">
            ${morning.length ? morning.map(slot).join('') : '<p class="sch-muted">کلاسی برای صبح ثبت نشده است.</p>'}
          </div>`;

    if (afternoon.length) {
      out += `<div class="sch-evening">
        <div class="sch-section-label">${icon('sunset')} ادامهٔ روز</div>
        ${afternoon.map(e => e.kind === 'homework' ? homeworkWindow(e) : slot(e)).join('')}
      </div>`;
    }

    if (dayIdx >= 5) {
      out += `<div class="sch-homework-window" style="margin-top:16px">
        <div>
          <strong>روز منعطف</strong>
          <small>انجام تکلیف‌ها و یک ساعت بازی کامپیوتری مجاز است.</small>
        </div>
      </div>`;
    }

    out += `</div>`;

    if (!isChild()) {
      out += `<button type="button" class="sch-outline-btn" data-sch="new" data-date="${selected}" style="margin-top:14px">
        ${icon('plus')} برنامهٔ تازه برای ${days[dayIdx]}
      </button>
      <details class="sch-rule">
        <summary>برداشت از تصویر و ارتباط درس‌ها</summary>
        <p>پایان کلاس خصوصی و شروع ناهار حدود ۱۴:۳۰ برداشت شده و قابل اصلاح است. «کانگورو» فقط عنوان کلاس و مرتبط با ریاضی است و منبع جدیدی ایجاد نمی‌کند. نگارش به فارسی مرتبط است و کلاس خصوصی فعلاً درس مشخصی ندارد.</p>
      </details>`;
    }

    out += `</section>${recommendations()}</div>`;
    return out;
  }

  function renderWeek() {
    const start = shift(selected, -weekday(selected));
    const dates = days.map((_, i) => shift(start, i));
    const weekOccurrences = data?.occurrences || [];

    const defaultBounds = [480, 540, 600, 660, 720, 780, 870, 900, 1080, 1200];
    const eventBounds = weekOccurrences.flatMap(e => [Number(e.start_minute), Number(e.end_minute)]);
    const bounds = [...new Set([...defaultBounds, ...eventBounds].map(Number))].sort((a, b) => a - b);
    const rows = bounds.slice(0, -1).map((x, i) => [x, bounds[i + 1]]).filter(([st, en]) => en > st);

    let out = `<section class="sch-week">
      <div class="sch-section-head">
        <h2>${jalali(dates[0])} تا ${jalali(dates[6])}</h2>
        <small>الگوی تکرارشوندهٔ هفته</small>
      </div>`;

    if (!isChild()) {
      out += `<div class="sch-parent-note">
        روی هر برنامه بزنید تا آن را ویرایش کنید؛ تغییر می‌تواند هفتگی یا فقط برای همین روز باشد.
      </div>`;
    }

    // Desktop Table Grid
    out += `<div class="sch-week-desktop">
      <table aria-label="برنامه هفتگی">
        <thead>
          <tr>
            <th>ساعت</th>
            ${days.map((d, i) => `<th ${dates[i] === selected ? 'data-selected="true"' : ''}>${d}</th>`).join('')}
          </tr>
        </thead>
        <tbody>`;

    rows.forEach(([st, en]) => {
      out += `<tr><th scope="row">${time(st)}<br>${time(en)}</th>`;
      for (let d = 0; d < 7; d++) {
        const currentDate = dates[d];
        const matches = weekOccurrences.filter(e => e.date === currentDate && Number(e.start_minute) < en && Number(e.end_minute) > st);
        if (matches.length) {
          const e = matches[0];
          out += `<td data-kind="${esc(e.kind)}">
            <button type="button" data-sch="edit" data-key="${esc(e.key)}" aria-label="${days[d]} ${esc(e.title)} ${time(e.start_minute)} تا ${time(e.end_minute)}">
              <span>${esc(e.title)}</span>
              ${e.exception_id ? '<br><small>فقط این روز</small>' : ''}
            </button>
          </td>`;
        } else {
          out += `<td data-empty="true">
            ${!isChild() ? `<button type="button" data-sch="new" data-weekday="${d}" data-start="${st}" data-end="${en}" data-date="${currentDate}" aria-label="افزودن برنامه ${days[d]} ساعت ${time(st)}">+</button>` : '—'}
          </td>`;
        }
      }
      out += `</tr>`;
    });

    out += `</tbody></table></div>`;

    // Mobile Week Day List (under 820px)
    out += `<div class="sch-week-mobile">
      ${days.map((d, i) => {
        const currentDate = dates[i];
        const dayOccurrences = weekOccurrences.filter(e => e.date === currentDate);
        return `<section class="sch-week-day">
          <h3>
            <span>${d} · ${esc(date(currentDate))}</span>
            <button type="button" data-sch="selectDay" data-date="${currentDate}">دیدن پیشنهادها</button>
          </h3>
          <div>
            ${dayOccurrences.length ? dayOccurrences.map(slot).join('') : '<p class="sch-muted">برنامه‌ای ثبت نشده؛ روز منعطف</p>'}
          </div>
        </section>`;
      }).join('')}
    </div>`;

    // Legend
    out += `<div class="sch-legend">
      <span><b style="background:var(--ks-blue)"></b>کلاس</span>
      <span><b style="background:var(--ks-green)"></b>وقت تکلیف</span>
      <span><b style="background:var(--ks-peach)"></b>فعالیت شخصی</span>
      <span><b style="background:var(--ks-rest)"></b>استراحت</span>
      <span><b style="background:var(--ks-yellow)"></b>جلسه و گفتگو</span>
    </div>`;

    // Week Foot
    out += `<div class="sch-week-foot">
      <small>پنجشنبه و جمعه: انجام تکالیف و یک ساعت بازی کامپیوتری مجاز است.</small>
      <button type="button" class="sch-outline-btn" data-sch="day" data-date="${selected}">
        پیشنهادهای ${days[weekday(selected)]} ${icon('arrow-left')}
      </button>
    </div>`;

    out += `<details class="sch-rule">
      <summary>دربارهٔ ساعت‌های استخراج‌شده</summary>
      <p>ساعت‌های صبح از تصویر اولیه منتقل شده‌اند. پایان کلاس خصوصی و شروع ناهار حدود ۱۴:۳۰ برداشت شده‌اند. جمعه در تصویر اصلی ستون نداشت؛ در اینجا برای برنامه‌ریزی کامل هفته اضافه شده و به‌صورت پیش‌فرض روز بدون برنامهٔ ثابت است. با انتخاب والدین می‌توان در ساعت‌های خالی برنامه افزود.</p>
    </details></section>`;

    return out;
  }

  function form() {
    const f = editor;
    const input = (label, key, val, type = 'text', extra = '') => `<label class="m-field">${label}<input name="${key}" type="${type}" value="${esc(val)}" required ${extra}></label>`;

    return `<form class="sch-form" data-sch-form>
      <div class="sch-section-head">
        <h2>${f.isNew ? 'افزودن برنامه' : 'ویرایش: ' + esc(f.title)}</h2>
        <button type="button" class="sch-outline-btn" data-sch="close">بستن</button>
      </div>
      <p class="sch-form-note">تغییر هفتگی نسخهٔ تازه می‌سازد؛ تاریخ اثر را مشخص کنید. برای جابه‌جایی نوبت، تاریخ مقصد را تغییر دهید.</p>
      <div class="m-form-grid">
        ${input('عنوان برنامه', 'title', f.title, 'text', 'placeholder="مثلاً کلاس ریاضی یا ورزش"')}
        <label class="m-field">نوع برنامه
          <select name="kind">
            ${Object.entries(kinds).map(([k, v]) => `<option value="${k}" ${k === f.kind ? 'selected' : ''}>${v}</option>`).join('')}
          </select>
        </label>
        <label class="m-field">روز هفته
          <select name="weekday">
            ${days.map((d, i) => `<option value="${i}" ${i === Number(f.weekday) ? 'selected' : ''}>${d}</option>`).join('')}
          </select>
        </label>
        <label class="m-field">درس مرتبط
          <select name="subject_id">
            <option value="">بدون درس مرتبط</option>
            ${Object.entries(subjects).map(([k, v]) => `<option value="${k}" ${k === f.subject_id ? 'selected' : ''}>${esc(v)}</option>`).join('')}
          </select>
        </label>
        ${input('از ساعت', 'start', nativeTime(f.start_minute), 'time')}
        ${input('تا ساعت', 'end', nativeTime(f.end_minute), 'time')}
        ${input('تاریخ اثر هفتگی / مقصد نوبت (شمسی)', 'date', jalali(f.date))}
        <label class="m-field">دامنهٔ تغییر
          <select name="scope">
            <option value="once">فقط یک نوبت برای این تاریخ</option>
            ${!f.exception_id ? '<option value="weekly" selected>هر هفته از تاریخ اثر</option>' : ''}
          </select>
        </label>
      </div>
      ${error ? `<p class="m-error" role="alert">${esc(error)}</p>` : ''}
      <div class="sch-form-actions">
        <div class="sch-action-group">
          <button type="submit" class="m-primary">${icon('check')} ذخیرهٔ برنامه</button>
          ${button('close', 'انصراف')}
        </div>
        ${f.entry_id ? `<div class="sch-action-group">
          ${button('cancel', 'لغو فقط این نوبت')}
          ${!f.exception_id ? button('archive', 'پایان الگو از تاریخ واردشده') : ''}
          ${f.exception_id ? button('restore', 'بازگرداندن / حذف استثنا') : ''}
        </div>` : ''}
      </div>
    </form>`;
  }

  function draftView() {
    if (!draft) return '';
    return `<section class="sch-draft-modal m-story">
      <h2>پیش‌نویس استخراج‌شده از تصویر</h2>
      <p>برنامهٔ زیر بر پایهٔ تصویر استخراج شده است. کلاس خصوصی تا حدود ۱۴:۳۰ و ناهار ۱۴:۳۰–۱۵ نیازمند تأیید شماست. جمعه برنامهٔ ثابت ندارد. هیچ تکلیفی به‌صورت خودکار ایجاد نمی‌شود.</p>
      <div class="sch-draft-list">
        ${draft.weekly_entries.map(e => `
          <div class="sch-draft-item">
            <span><strong>${days[e.weekday]}</strong>: ${esc(e.title)}</span>
            <span dir="ltr">${fa(e.start)}–${fa(e.end)}</span>
            ${e.needs_time_confirmation ? '<em class="sch-draft-warn">ساعت تقریبی</em>' : ''}
          </div>
        `).join('')}
      </div>
      <form data-sch-seed class="sch-draft-form">
        <label class="m-field">شروع اثر از تاریخ (شمسی)
          <input name="date" required value="${jalali(selected)}">
        </label>
        <label class="sch-check">
          <input type="checkbox" name="confirmed" required>
          ساعت‌های تقریبی را بررسی و تأیید کردم
        </label>
        <div class="sch-action-group">
          <button class="m-primary" type="submit">${icon('check')} تأیید و ورود پیش‌نویس</button>
          ${button('closeDraft', 'بستن')}
        </div>
      </form>
    </section>`;
  }

  function render() {
    selected ||= today();
    if (editor) return `<section class="sch">${form()}</section>`;

    const start = shift(selected, -weekday(selected));
    const dates = days.map((_, i) => shift(start, i));

    return `<section class="sch">
      <div class="m-hero">
        <div class="m-kicker">جای هر کار در هفته</div>
        <h1>${isChild() ? 'برنامهٔ من' : 'برنامهٔ هفتگی امیرحسین'}</h1>
        <p>کلاس‌ها، وقت تکلیف و یک پیشنهاد برای قدم بعدی.</p>
      </div>

      <div class="sch-toolbar">
        <div class="sch-views" role="group" aria-label="نمای برنامه">
          ${button('day', icon('sun') + ' برنامهٔ روز', `aria-pressed="${view === 'day'}"`)}
          ${button('week', icon('calendar-days') + ' کل هفته', `aria-pressed="${view === 'week'}"`)}
        </div>
        <div class="sch-nav-controls">
          ${button('prev', 'هفتهٔ قبل')}
          ${button('today', 'امروز')}
          ${button('next', 'هفتهٔ بعد')}
        </div>
        ${!isChild() ? `<div class="sch-parent-controls">
          ${button('new', icon('plus') + ' افزودن برنامه')}
          ${button('draft', icon('sparkles') + ' بررسی پیش‌نویس تصویر')}
        </div>` : ''}
      </div>

      <div class="sch-days" role="group" aria-label="انتخاب روز هفته">
        ${dates.map((d, i) => `
          <button type="button" data-sch="select" data-date="${d}" aria-pressed="${selected === d}" ${d === today() ? 'data-today="true"' : ''}>
            <span>${days[i]}</span>
            <small>${jalali(d)}</small>
          </button>
        `).join('')}
      </div>

      ${error ? `<p class="m-error" role="alert">${esc(error)}</p>` : ''}
      ${loading ? '<p role="status" class="sch-loading">در حال خواندن برنامه…</p>' : ''}
      ${draft ? draftView() : ''}

      <div class="sch-content">
        ${view === 'week' ? renderWeek() : renderDay()}
      </div>

      ${!isChild() && data?.exceptions?.length ? `
        <details class="sch-rule" style="margin-top:24px">
          <summary>استثناهای ثبت‌شدهٔ برنامه (${fa(data.exceptions.length)})</summary>
          <div class="sch-exception-list">
            ${data.exceptions.map(x => `
              <div class="sch-exception-item">
                <span>${esc(x.title)} · ${x.action === 'cancel' ? 'لغو شده در' : 'انتقال به'} ${jalali(x.occurrence_date)}</span>
                <button type="button" class="sch-outline-btn" data-sch="remove" data-id="${x.id}">حذف استثنا</button>
              </div>
            `).join('')}
          </div>
        </details>
      ` : ''}
    </section>`;
  }

  async function click(b) {
    const a = b.dataset.sch;
    if (!a) return false;
    error = '';
    try {
      if (a === 'prev') {
        selected = shift(selected, -7);
        await load();
      } else if (a === 'next') {
        selected = shift(selected, 7);
        await load();
      } else if (a === 'today') {
        selected = today();
        await load();
      } else if (a === 'select') {
        selected = b.dataset.date;
        await load();
      } else if (a === 'selectDay') {
        selected = b.dataset.date;
        view = 'day';
        await load();
      } else if (a === 'day' || a === 'week') {
        view = a;
        if (b.dataset.date) selected = b.dataset.date;
      } else if (a === 'close') {
        editor = null;
      } else if (a === 'closeDraft') {
        draft = null;
      } else if (a === 'draft' && !isChild()) {
        draft = await api('/schedule-draft');
      } else if (a === 'new' && !isChild()) {
        const targetDate = b.dataset.date || selected;
        const startMin = b.dataset.start ? Number(b.dataset.start) : 480;
        const endMin = b.dataset.end ? Number(b.dataset.end) : 540;
        const targetWeekday = b.dataset.weekday !== undefined ? Number(b.dataset.weekday) : weekday(targetDate);
        editor = {
          title: '',
          kind: 'class',
          subject_id: '',
          weekday: targetWeekday,
          start_minute: startMin,
          end_minute: endMin,
          date: targetDate,
          isNew: true
        };
      } else if (a === 'edit') {
        if (!isChild()) {
          const e = data.occurrences.find(x => x.key === b.dataset.key);
          if (e) {
            editor = {
              ...e,
              weekday: weekday(e.date),
              isNew: false
            };
          }
        } else {
          // If child clicks an event in day or week view, notify or focus
          const e = data?.occurrences?.find(x => x.key === b.dataset.key);
          if (e && e.date !== selected) {
            selected = e.date;
            view = 'day';
            await load();
          }
        }
      } else if (a === 'complete' && isChild()) {
        await api(`/assignments/${b.dataset.id}/complete`, 'POST', {complete: true});
        finishedSessionCount++;
        await load();
      } else if (a === 'remove' && !isChild()) {
        await api(`/schedule-exceptions/${b.dataset.id}`, 'DELETE', {});
        await load();
      } else if (editor && !isChild() && ['cancel', 'archive', 'restore'].includes(a)) {
        if (a === 'restore') {
          await api(`/schedule-exceptions/${editor.exception_id}`, 'DELETE', {});
        } else if (a === 'archive') {
          const d = b.closest('form').elements.date.value;
          await api(`/schedule-entries/${editor.entry_id}/archive`, 'POST', {effective_from: d});
        } else if (a === 'cancel') {
          await api('/schedule-exceptions' + (editor.exception_id ? '/' + editor.exception_id : ''), editor.exception_id ? 'PUT' : 'POST', {
            ...editor,
            action: 'cancel',
            occurrence_date: editor.original_date || editor.date
          });
        }
        editor = null;
        await load();
      }
    } catch (e) {
      error = e.message;
    }
    return true;
  }

  async function submit(el) {
    if (isChild()) return;
    error = '';
    const d = Object.fromEntries(new FormData(el));
    try {
      if (el.matches('[data-sch-seed]')) {
        await api('/schedule-draft/confirm', 'POST', {
          effective_from: d.date,
          confirmed: d.confirmed === 'on'
        });
        draft = null;
      } else {
        const parseTime = t => {
          const [h, m] = t.split(':').map(Number);
          return h * 60 + m;
        };
        const payload = {
          title: d.title,
          kind: d.kind,
          subject_id: d.subject_id || null,
          weekday: Number(d.weekday),
          start_minute: parseTime(d.start),
          end_minute: parseTime(d.end)
        };
        if (d.scope === 'weekly') {
          await api('/schedule-entries' + (editor.entry_id && !editor.isNew ? '/' + editor.entry_id : ''), editor.entry_id && !editor.isNew ? 'PUT' : 'POST', {
            ...payload,
            effective_from: d.date
          });
        } else {
          await api('/schedule-exceptions' + (editor.exception_id ? '/' + editor.exception_id : ''), editor.exception_id ? 'PUT' : 'POST', {
            ...payload,
            entry_id: editor.entry_id || null,
            original_date: editor.original_date || d.date,
            occurrence_date: d.date,
            action: editor.entry_id ? 'replace' : 'add'
          });
        }
        editor = null;
      }
      await load();
    } catch (e) {
      error = e.message;
    }
  }

  return {
    load,
    render,
    click,
    submit,
    reset() {
      editor = null;
      draft = null;
      data = null;
      recs = null;
      serial++;
    }
  };
}
