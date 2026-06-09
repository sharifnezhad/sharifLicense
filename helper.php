<?php

/**
 * Jalali (Shamsi) <-> Gregorian date conversion helpers.
 *
 * Based on the well-known public-domain jdf conversion algorithm.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Load and cache the language array from lang/fa.php.
 *
 * @return array The full strings array.
 */
function sharif_lang_load(): array
{
    static $strings = null;

    if ($strings === null) {
        $strings = require __DIR__ . '/lang/fa.php';
    }

    return $strings;
}

/**
 * Retrieve a translated string from the language file.
 *
 * Unknown keys return the key itself so missing translations are easy to spot.
 *
 * @param string $key The string key to look up.
 *
 * @return string The translated string, or the key if not found.
 */
function sharif_lang(string $key): string
{
    return sharif_lang_load()[$key] ?? $key;
}

/**
 * Get the Persian (Jalali) month names indexed 1-12.
 *
 * @return string[] Month names keyed by month number.
 */
function sharif_jalali_months(): array
{
    return sharif_lang_load()['jalali_months'] ?? [];
}

/**
 * Convert a Gregorian date to its Jalali (Shamsi) equivalent.
 *
 * @param int $gregorianYear Gregorian year (e.g. 2026).
 * @param int $gregorianMonth Gregorian month (1-12).
 * @param int $gregorianDay Gregorian day (1-31).
 *
 * @return int[] Array of [jalaliYear, jalaliMonth, jalaliDay].
 */
function sharif_gregorian_to_jalali(int $gregorianYear, int $gregorianMonth, int $gregorianDay): array
{
    $gregorianDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    $adjustedYear = ($gregorianMonth > 2) ? ($gregorianYear + 1) : $gregorianYear;

    $totalDays = 355666
        + (365 * $gregorianYear)
        + ((int)(($adjustedYear + 3) / 4))
        - ((int)(($adjustedYear + 99) / 100))
        + ((int)(($adjustedYear + 399) / 400))
        + $gregorianDay
        + $gregorianDaysInMonth[$gregorianMonth - 1];

    $jalaliYear = -1595 + (33 * ((int)($totalDays / 12053)));
    $totalDays %= 12053;

    $jalaliYear += 4 * ((int)($totalDays / 1461));
    $totalDays %= 1461;

    if ($totalDays > 365) {
        $jalaliYear += (int)(($totalDays - 1) / 365);
        $totalDays = ($totalDays - 1) % 365;
    }

    if ($totalDays < 186) {
        $jalaliMonth = 1 + (int)($totalDays / 31);
        $jalaliDay = 1 + ($totalDays % 31);
    } else {
        $jalaliMonth = 7 + (int)(($totalDays - 186) / 30);
        $jalaliDay = 1 + (($totalDays - 186) % 30);
    }

    return [$jalaliYear, $jalaliMonth, $jalaliDay];
}

/**
 * Convert a Jalali (Shamsi) date to its Gregorian equivalent.
 *
 * @param int $jalaliYear Jalali year (e.g. 1404).
 * @param int $jalaliMonth Jalali month (1-12).
 * @param int $jalaliDay Jalali day (1-31).
 *
 * @return int[] Array of [gregorianYear, gregorianMonth, gregorianDay].
 */
function sharif_jalali_to_gregorian(int $jalaliYear, int $jalaliMonth, int $jalaliDay): array
{
    $jalaliYear += 1595;

    $totalDays = -355668
        + (365 * $jalaliYear)
        + (((int)($jalaliYear / 33)) * 8)
        + ((int)((($jalaliYear % 33) + 3) / 4))
        + $jalaliDay
        + (($jalaliMonth < 7) ? ($jalaliMonth - 1) * 31 : (($jalaliMonth - 7) * 30) + 186);

    $gregorianYear = 400 * ((int)($totalDays / 146097));
    $totalDays %= 146097;

    if ($totalDays > 36524) {
        $gregorianYear += 100 * ((int)(--$totalDays / 36524));
        $totalDays %= 36524;
        if ($totalDays >= 365) {
            $totalDays++;
        }
    }

    $gregorianYear += 4 * ((int)($totalDays / 1461));
    $totalDays %= 1461;

    if ($totalDays > 365) {
        $gregorianYear += (int)(($totalDays - 1) / 365);
        $totalDays = ($totalDays - 1) % 365;
    }

    $gregorianDay = $totalDays + 1;

    $isLeapYear = (($gregorianYear % 4 === 0) && ($gregorianYear % 100 !== 0)) || ($gregorianYear % 400 === 0);
    $gregorianMonthDays = [0, 31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    $gregorianMonth = 0;
    while ($gregorianMonth < 13 && $gregorianDay > $gregorianMonthDays[$gregorianMonth]) {
        $gregorianDay -= $gregorianMonthDays[$gregorianMonth];
        $gregorianMonth++;
    }

    return [$gregorianYear, $gregorianMonth, $gregorianDay];
}

/**
 * Convert Persian/Arabic digit characters in a string to English digits.
 *
 * @param string $value Input string possibly containing Persian/Arabic digits.
 *
 * @return string String with all digits normalized to 0-9.
 */
function sharif_normalize_digits(string $value): string
{
    $persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    $value = str_replace($persianDigits, $englishDigits, $value);
    $value = str_replace($arabicDigits, $englishDigits, $value);

    return $value;
}

/**
 * Parse a Jalali date string (YYYY/MM/DD) and convert it to a Gregorian Y-m-d string.
 *
 * Accepts Persian or English digits and "/" or "-" separators.
 *
 * @param string $jalaliDate Jalali date string, e.g. "1404/06/19".
 *
 * @return string|null Gregorian date as "Y-m-d", or null if the input is invalid.
 */
function sharif_jalali_string_to_gregorian(string $jalaliDate): ?string
{
    $jalaliDate = sharif_normalize_digits(trim($jalaliDate));
    $parts = preg_split('/[\/\-]/', $jalaliDate);

    if (count($parts) !== 3) {
        return null;
    }

    $jalaliYear = (int)$parts[0];
    $jalaliMonth = (int)$parts[1];
    $jalaliDay = (int)$parts[2];

    if ($jalaliYear < 1 || $jalaliMonth < 1 || $jalaliMonth > 12 || $jalaliDay < 1 || $jalaliDay > 31) {
        return null;
    }

    // First 6 months have 31 days, next 5 have 30, last month has 29 (30 in leap years)
    if ($jalaliMonth <= 6 && $jalaliDay > 31) {
        return null;
    }
    if ($jalaliMonth >= 7 && $jalaliMonth <= 11 && $jalaliDay > 30) {
        return null;
    }

    [$gregorianYear, $gregorianMonth, $gregorianDay] = sharif_jalali_to_gregorian($jalaliYear, $jalaliMonth, $jalaliDay);

    return sprintf('%04d-%02d-%02d', $gregorianYear, $gregorianMonth, $gregorianDay);
}

/**
 * Convert a Gregorian Y-m-d string to a Jalali date string (YYYY/MM/DD).
 *
 * @param string $gregorianDate Gregorian date as "Y-m-d".
 *
 * @return string Jalali date string, or empty string if input is invalid.
 */
function sharif_gregorian_string_to_jalali(string $gregorianDate): string
{
    $parts = explode('-', trim($gregorianDate));
    if (count($parts) !== 3) {
        return '';
    }

    [$jalaliYear, $jalaliMonth, $jalaliDay] = sharif_gregorian_to_jalali(
        (int)$parts[0],
        (int)$parts[1],
        (int)$parts[2]
    );

    return sprintf('%04d/%02d/%02d', $jalaliYear, $jalaliMonth, $jalaliDay);
}
