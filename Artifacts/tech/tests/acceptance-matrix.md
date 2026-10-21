# ماتریس ردیابی نیازمندی، طراحی و آزمون

`E01` تا `E05` به منابع [evidence.md](evidence.md) ارجاع دارند. «گزارش PASS» یعنی نتیجهٔ ثبت‌شدهٔ قبلی؛ تأیید اجرای دوباره در این کار نیست. شناسهٔ نیازمندی، بخش متناظر در [PRD](../../product/PRD.md) است.

| نیاز | طراحی | شاهد کد / آزمون | وضعیت و شکاف مهم |
|---|---|---|---|
| REQ-01 دسترسی | WF-01 / UI-01 | Application؛ browser: anonymous، CSRF، logout، throttle؛ E01,E02 | موجود؛ PASS گزارش‌شده؛ ممیزی مستقل ندارد |
| REQ-02 بخش‌ها | WF-02,05 / UI-03,07 | context و guard؛ browser: عملیات بخش نامناسب؛ E02 | موجود؛ مجوز اشخاص مستقل نیست |
| REQ-03 catalog خالی | WF-03 / UI-02,04 | Database::install؛ run: Six subjects/Initial sources/No demo data؛ E01,E04 | موجود؛ PASS گزارش‌شده |
| REQ-04 فعالیت | WF-04 / UI-06,11 | Learning::activity؛ browser: ذخیره و متن امن؛ E01,E02 | موجود؛ idempotency شبکه ندارد |
| REQ-05 پیشرفت | WF-03 / UI-04,05 | state/progress؛ run: duplicate/edit/AI independent؛ E01 | موجود؛ PASS گزارش‌شده؛ فهم کاربر سنجیده نشده |
| REQ-06 تکلیف | WF-02,05 / UI-03,07 | assignment/complete؛ run: استقلال تکلیف؛ browser: ثبت و انجام؛ E01,E02 | موجود؛ حذف در دامنه نیست |
| REQ-07 مطالعهٔ بیشتر | WF-02 / UI-03 | record(extra)؛ run/browser: حفظ تاریخ ویرایش؛ E01,E02 | موجود؛ PASS گزارش‌شده |
| REQ-08 چالش | WF-02,06 / UI-03,08 | record(challenge)، challengeView؛ run/browser: ثبت مستقل و گزارش؛ E01,E02 | موجود؛ ویرایش در API، دکمهٔ UI ندارد؛ TASK-07 |
| REQ-09 منابع | WF-03,05 / UI-04,05,07 | resource/archive؛ run/browser: shared/custom/archive؛ E01,E02 | موجود؛ تمام مرزهای ورودی در آزمون خودکار حاضر پوشش ندارند |
| REQ-10 گزارش | WF-06 / UI-08,12 | Calendar و report-view؛ run: ماه قبل/کبیسه/هفته؛ browser: بازه‌ها؛ E01,E02 | موجود؛ PASS گزارش‌شده |
| REQ-11 بکاپ | WF-07 / UI-09 | Backup؛ run: retention/restore؛ browser: download؛ E01,E02,E04 | موجود؛ بازیابی MariaDB گزارش‌شده؛ RTO و نسخهٔ خارج از هاست نامعلوم |
| REQ-12 RTL/موبایل | همه / UI-10,11,12 | CSS؛ browser: عرض ۳۲۰/۳۹۰ و طرح روشن؛ E02 | PASS گزارش‌شده؛ دسترس‌پذیری جامع باز؛ TASK-06 |
| REQ-13 ماندگاری/حریم | WF-02 / UI-03 | PDO، escape، state؛ browser: نشست دوم/HTML/private paths؛ E02,E04 | موجود؛ تعارض هم‌زمان و export/delete policy باز |
| REQ-14 استقرار | WF-07 / UI-09 | .htaccess و package؛ verification/manifest؛ E03,E04,E05 | انتشار گزارش‌شده؛ تمدید TLS تأیید نشده؛ TASK-01 |

## دروازهٔ انتشار بعدی

| دروازه | شاهد لازم برای نسخهٔ بعد | وضعیت فعلی مجموعه |
|---|---|---|
| منطق و مرورگر | خروجی تازهٔ tests برای همان تغییر/نسخه | تنها شواهد قبلی ثبت شده |
| بسته | SHA-256، نبود راز و دادهٔ آزمون | manifest نسخهٔ 1.0.0 موجود |
| محیط واقعی | PHP/DB/TLS/redirect و عدم دانلود خصوصی | برای نسخهٔ قبلی گزارش شده |
| بازیابی | تطبیق snapshot با DB جدا | آزمون قبلی MariaDB گزارش شده |
| عملیات پایدار | مسئول تمدید TLS و فرآیند backup مورد توافق | باز؛ TASK-01 و TASK-03 |
| اعتبار محصول | نتیجهٔ آزمایش‌های H01 تا H06 | اجرا نشده؛ TASK-05 |
