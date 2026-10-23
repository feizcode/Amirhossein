# بررسی ارائه‌دهندگان پنج مدل رایگان — ۲۰۲۶-۱۰-۲۳

این گزارش از بررسی اسناد عمومی است؛ مشاورهٔ حقوقی یا اجازهٔ انتشار نیست. هیچ کلید یا دادهٔ کودک برای این بررسی ارسال نشده است. کیفیت خروجی و مجازبودن استقرار دو موضوع مستقل‌اند.

## انتخاب نامزدها

فهرست زندهٔ `https://openrouter.ai/api/v1/models` وجود پنج شناسه و قیمت صفر را تأیید کرد. توضیحات رسمی کاتالوگ، Inkling و Inkling Small را مدل‌های عمومی استدلال چندوجهی، Ultra را مدل استدلال قوی، و Gemma 4 31B/26B را مدل‌های instruction-tuned معرفی می‌کند. رتبهٔ مصرف در مجموعهٔ رایگان OpenRouter معیار کیفیت نیست. منابع مقایسه‌ای ثالث صرفاً برای نامزدسازی بودند؛ ادعای «پنج مدل قطعاً برتر» نداریم.

منابع نامزدسازی:
- https://openrouter.ai/collections/free-models
- https://costgoat.com/pricing/openrouter-free-models
- https://www.teamday.ai/blog/best-free-ai-models-openrouter-2026

## نتیجهٔ بررسی شرایط

| مسیر دقیق رایگان | میزبان مشاهده‌شده | مسئلهٔ انتشار برای کودک |
|---|---|---|
| nvidia/nemotron-3-ultra-550b-a55b:free | NVIDIA | اعلان ثبت محتوا برای امنیت و بهبود محصولات؛ بندهای دقیق قرارداد trial دربارهٔ کودک/استفادهٔ عملیاتی هنوز تأیید نشده |
| thinkingmachines/inkling:free | Thinking Machines | فقط agentic harness، ثبت ورودی/خروجی برای بهبود؛ مجوز کاربرد کودک روشن نیست |
| thinkingmachines/inkling-small:free | Thinking Machines | همان محدودیت‌ها |
| google/gemma-4-31b-it:free | Google AI Studio | محدودیت صریح برنامهٔ مخاطب زیر ۱۸ سال در شرایط عمومی مرتبط گوگل؛ نیازمند قرارداد/استثنای واقعاً قابل‌اعمال |
| google/gemma-4-26b-a4b-it:free | Google AI Studio | همان محدودیت‌ها |

میزبان‌های مسیر :free از API عمومی endpoints بررسی شدند؛ مسیر پولی یا canonical می‌تواند میزبان و قرارداد دیگری داشته باشد. الگو:
`https://openrouter.ai/api/v1/models/{vendor}/{model}:free/endpoints`

## OpenRouter

https://openrouter.ai/terms

شرایط حداقل ۱۸ سال را برای استفاده از سرویس مقرر می‌کند و مشتری را ملزم به رعایت شرایط مدل‌ها توسط کاربران/مشتریان پایین‌دستی می‌کند. حساب بزرگسال به‌تنهایی اثبات مجوز برنامهٔ کودک نیست؛ برای این کاربرد باید از OpenRouter روشن‌سازی کتبی گرفت.

https://openrouter.ai/privacy

اظهار عدم آموزش OpenRouter به معنی عدم آموزش یا نگهداری داده توسط میزبان مدل نیست.

## میزبان‌ها و تفاوت مجوز وزن با API

- NVIDIA: https://openrouter.ai/nvidia/nemotron-3-ultra-550b-a55b:free
- قرارداد trial: https://assets.ngc.nvidia.com/products/api-catalog/legal/NVIDIA%20API%20Trial%20Terms%20of%20Service.pdf
- Inkling: https://openrouter.ai/thinkingmachines/inkling:free
- Inkling Small: https://openrouter.ai/thinkingmachines/inkling-small:free
- https://thinkingmachines.ai/legal/tml-free-research-api-tier-terms-of-service.pdf
- https://thinkingmachines.ai/legal/tml-free-research-api-tier-privacy-notice.pdf
- شرایط گوگل: https://ai.google.dev/gemini-api/terms
- شرایط Cloud: https://cloud.google.com/terms/service-terms

متن PDF قرارداد NVIDIA در بررسی قابل استخراج نبود؛ PDFهای Thinking Machines پاسخ 403 دادند. بنابراین بند سن، نگهداری و کاربرد عملیاتیِ این قراردادها «نامشخص» است، نه مجاز یا ممنوعِ اثبات‌شده. مجوز باز وزن‌های مدل، قرارداد API میزبانی‌شده را لغو نمی‌کند.

برای Gemma، نمایش عمومی OpenRouter عدم آموزش و نگهداری تا ۵۵ روز را گزارش می‌کند؛ این نمایش، تعهد قراردادی مستقلاً تأییدشده نیست. صفر بودن قیمت OpenRouter به معنی یکسان بودن مسیر با سهمیهٔ unpaid حساب شخصی گوگل نیست.

## تنظیمات حریم خصوصی

https://openrouter.ai/docs/guides/routing/provider-selection

`provider.data_collection=deny` و `zdr=true` فیلتر مسیریابی‌اند، نه تغییر قرارداد یا مجوز سنی. برای میزبان‌های رایگان جمع‌آوری‌کننده ممکن است هیچ مسیر باقی نماند؛ برنامه نباید برای پاسخ گرفتن، فیلتر را مخفیانه شل کند. در آزمون‌های موجود از سؤال ثابت یا محتوای تألیفی بانک استفاده شد، نه اطلاعات یا رفتار واقعی کودک.

## تصمیم فعلی

هیچ مسیر از این پنج مورد برای انتشار مستقیم به کودک تأیید نشده است. حتی اگر آزمون فنی موفق باشد، بررسی شرایط و تأیید محتوا/پایلوت همچنان لازم‌اند. پرداخت هزینه نیز خودبه‌خود این موانع را رفع نمی‌کند؛ قرارداد و میزبان گزینهٔ پولی باید جداگانه بررسی شوند. تا حل این موارد، runtime زنده خاموش می‌ماند و محتوای ذخیره‌شده استفاده می‌شود.
