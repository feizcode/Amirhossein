<?php
declare(strict_types=1);
namespace Kashf;
use DateTimeImmutable;
use DateTimeZone;
use IntlCalendar;
use IntlDateFormatter;
use InvalidArgumentException;

final class Calendar
{
    public static function today(): string { return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d'); }
    public static function latin(string $s): string { return str_replace(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789'), $s); }
    public static function jalali(string $iso): string
    {
        $fmt = new IntlDateFormatter('en_US@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
        return (string) $fmt->format(new DateTimeImmutable($iso.' 12:00:00', new DateTimeZone('Asia/Tehran')));
    }
    public static function parse(string $value, bool $future = false, ?string $today = null): string
    {
        $v = self::latin(trim($value));
        if (preg_match('~^(1[2345]\d{2})/(\d{1,2})/(\d{1,2})$~', $v, $m)) {
            $cal = IntlCalendar::createInstance(new DateTimeZone('Asia/Tehran'), 'en_US@calendar=persian');
            $cal->setLenient(false); $cal->clear();
            foreach ([IntlCalendar::FIELD_YEAR => (int)$m[1], IntlCalendar::FIELD_MONTH => (int)$m[2]-1, IntlCalendar::FIELD_DAY_OF_MONTH => (int)$m[3], IntlCalendar::FIELD_HOUR_OF_DAY => 12, IntlCalendar::FIELD_MINUTE => 0, IntlCalendar::FIELD_SECOND => 0] as $field => $value) { $cal->set($field, $value); }
            $time = $cal->getTime();
            if ($time === false) { throw new InvalidArgumentException('تاریخ شمسی معتبر نیست.'); }
            $v = (new DateTimeImmutable('@'.(int)($time/1000)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
            if (self::jalali($v) !== sprintf('%04d/%02d/%02d', (int)$m[1], (int)$m[2], (int)$m[3])) { throw new InvalidArgumentException('تاریخ شمسی معتبر نیست.'); }
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4))) {
            throw new InvalidArgumentException('تاریخ را به شکل ۱۴۰۵/۰۷/۲۸ وارد کن.');
        }
        if ($v < '2020-01-01' || $v > '2100-01-01' || (!$future && $v > ($today ?? self::today()))) { throw new InvalidArgumentException('تاریخ انجام باید امروز یا روزهای قبل باشد.'); }
        return $v;
    }
    public static function range(string $period, ?string $today = null): array
    {
        $d = new DateTimeImmutable(($today ?? self::today()).' 12:00:00', new DateTimeZone('Asia/Tehran'));
        if ($period === 'current_week') {
            $start = $d->modify('-'.(((int)$d->format('w')+1)%7).' days'); $end = $start->modify('+6 days');
        } elseif ($period === 'week') {
            $start = $d->modify('-'.(((int)$d->format('w')+1)%7+7).' days'); $end = $start->modify('+6 days');
        } elseif ($period === 'month') {
            $day = (int)substr(self::jalali($d->format('Y-m-d')),8,2);
            $end = $d->modify('-'.$day.' days');
            $start = new DateTimeImmutable(self::parse(substr(self::jalali($end->format('Y-m-d')),0,8).'01', true).' 12:00:00', new DateTimeZone('Asia/Tehran'));
        } else { throw new InvalidArgumentException('بازهٔ گزارش معتبر نیست.'); }
        return ['start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'label'=>self::jalali($start->format('Y-m-d')).' تا '.self::jalali($end->format('Y-m-d'))];
    }
}
