<?php

/**
 * Persian (fa) language strings for the Sharif License plugin.
 *
 * All user-facing messages, errors and labels live here. Retrieve a string with
 * sharif_lang('key'). Messages containing %s are filled in with sprintf() at the call site.
 */

if (!defined('ABSPATH')) {
    exit;
}

return [

    // REST API responses (sent to the WHMCS module)
    'invalid_request_method' => 'روش درخواست نامعتبر است',
    'unauthorized'           => 'دسترسی غیرمجاز',
    'missing_params'         => 'پارامترهای ارسالی ناقص هستند',
    'license_invalid'        => 'لایسنس معتبر نیست',
    'license_expired'        => 'لایسنس منقضی شده است',

    // Admin notices
    'delete_nonce_failed'    => 'خطا در احراز هویت درخواست حذف.',
    'license_deleted'        => 'لایسنس با موفقیت حذف شد.',
    'license_delete_failed'  => 'خطا در حذف لایسنس.',
    'license_added'          => 'لایسنس با موفقیت اضافه شد.',
    'license_updated'        => 'لایسنس با موفقیت ویرایش شد.',
    'save_failed'            => 'خطا در ذخیره لایسنس. لطفاً دوباره تلاش کنید.',

    // Validation errors
    'name_required'          => 'لطفاً نام لایسنس را وارد کنید.',
    'license_key_required'   => 'لطفاً کلید لایسنس را وارد کنید.',
    'license_key_invalid'    => 'کلید لایسنس نباید شامل فاصله یا حروف فارسی باشد.',
    'domain_required'        => 'لطفاً دامنه را وارد کنید.',
    'domain_invalid'         => 'فرمت دامنه نادرست است. مثال صحیح: example.com',
    'domain_taken'           => 'این دامنه قبلاً برای لایسنس دیگری ثبت شده است.',
    'ip_required'            => 'حداقل یک آدرس IP معتبر وارد کنید.',
    'ip_duplicate_input'     => 'آدرس IP تکراری وارد شده است.',
    'ip_invalid'             => 'آدرس IP نادرست است: %s',
    'ip_taken'               => 'این آدرس IP قبلاً ثبت شده است: %s',
    'date_required'          => 'لطفاً تاریخ انقضا را انتخاب کنید.',
    'date_invalid'           => 'تاریخ انقضا نامعتبر است.',

    // Admin page labels
    'copy'                   => 'کپی',
    'verify_url_label'       => 'آدرس احراز لایسنس (Verify URL):',
    'secret_key_label'       => 'Secret Key (هدر X-Secret-Key):',
    'add_license_heading'    => 'افزودن لایسنس جدید',
    'add_license_btn'        => 'افزودن لایسنس',
    'existing_licenses'      => 'لایسنس‌های موجود',
    'name_label'             => 'نام',
    'col_name'               => 'نام',
    'col_ips'                => 'IP ها',
    'col_expire_date'        => 'تاریخ انقضا',
    'col_actions'            => 'عملیات',
    'no_licenses'            => 'هیچ لایسنسی ثبت نشده است.',
    'edit'                   => 'ویرایش',
    'delete'                 => 'حذف',
    'edit_license_heading'   => 'ویرایش لایسنس',
    'close'                  => 'بستن',
    'save_changes'           => 'ذخیره تغییرات',
    'cancel'                 => 'انصراف',
    'domain_hint'            => 'فقط نام دامنه — بدون http:// یا www. هر دامنه فقط یک‌بار قابل ثبت است.',
    'add_ip_btn'             => '+ افزودن IP',
    'ip_hint'                => 'می‌توانید چند IP وارد کنید. هر IP فقط یک‌بار در کل سیستم قابل ثبت است.',
    'expire_date_label'      => 'تاریخ انقضا (شمسی)',
    'select_year'            => 'سال',
    'select_month'           => 'ماه',
    'select_day'             => 'روز',

    // JavaScript-facing strings
    'saving'                 => 'در حال ذخیره...',
    'generic_error'          => 'خطایی رخ داد.',
    'connection_error'       => 'خطا در ارتباط با سرور.',
    'confirm_delete'         => 'آیا از حذف این لایسنس مطمئن هستید؟',

    // Persian (Jalali) month names, indexed 1-12
    'jalali_months'          => [
        1  => 'فروردین',
        2  => 'اردیبهشت',
        3  => 'خرداد',
        4  => 'تیر',
        5  => 'مرداد',
        6  => 'شهریور',
        7  => 'مهر',
        8  => 'آبان',
        9  => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ],
];
