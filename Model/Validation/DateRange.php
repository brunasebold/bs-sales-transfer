<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Validation;

final class DateRange
{
    public static function isIsoDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }

    public static function isOrdered(?string $from, ?string $to): bool
    {
        if ($from === null || $from === '' || $to === null || $to === '') {
            return true;
        }

        return $from <= $to;
    }
}
