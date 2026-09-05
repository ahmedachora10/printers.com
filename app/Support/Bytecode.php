<?php

namespace App\Support;

/**
 * إبطال الشفرة المُصرَّفة (opcache).
 *
 * على الاستضافات المشتركة — cPanel وLiteSpeed خاصةً — تُضبط
 * opcache.validate_timestamps صفراً أو بفترة مراجعةٍ طويلة، فتبقى النسخة
 * المُصرَّفة من ملفٍ بعينه في ذاكرة الخادم ولو كُتب الملفُ من جديد. وأخطر ما
 * يصيبه ذلك bootstrap/cache/routes.php: يبنيه `optimize` من كودٍ جديد، ويظلّ
 * الخادم يخدم جدولَ المسارات القديم — فمسارٌ أُضيف للتوّ يردّ 404 وكأنه لم
 * يُنشر، بينما أصولُ الواجهة الجديدة تعمل لأنها ملفّاتٌ ساكنة لا شفرة.
 *
 * والنشر هنا يجري داخل طلب ويب (مسار ‎/deploy‎ وشاشة النشر)، فالإبطال من هنا
 * يصيب ذاكرة الخادم التي تخدم الزوّار. أمّا من سطر الأوامر فلكلّ عمليةٍ
 * ذاكرتها المنفصلة، ولا يُغني الإبطالُ حينها عن إعادة تشغيل PHP.
 */
class Bytecode
{
    public static function flush(): bool
    {
        if (! function_exists('opcache_reset') || ! ini_get('opcache.enable')) {
            return false;
        }

        // opcache.restrict_api قد يمنع الاستدعاء، فيُطلق تنبيهاً لا استثناء.
        return @opcache_reset() === true;
    }
}
