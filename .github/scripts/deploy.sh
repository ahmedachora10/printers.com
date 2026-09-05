#!/usr/bin/env bash
# يستدعي مسار /deploy على خادم الإنتاج ويترجم نتيجته إلى رمز خروج.
#
# الخادم يبدأ البثّ قبل أن يبدأ النشر، فترويسة 200 لا تعني نجاحاً. الحكم
# للعلامة الختامية التي يكتبها app:deploy في آخر سطر.

set -uo pipefail

: "${DEPLOY_URL:?لم يُضبط السر DEPLOY_URL}"
: "${DEPLOY_TOKEN:?لم يُضبط السر DEPLOY_TOKEN}"
: "${ASSETS_URL:?لم يُمرَّر رابط الأصول}"

url="${DEPLOY_URL%/}/deploy"
log="$RUNNER_TEMP/deploy.log"

echo "النشر إلى $url"
echo "الأصول: $ASSETS_URL"

status=$(
    curl --silent --show-error --location \
        --request POST "$url" \
        --header "X-Deploy-Token: $DEPLOY_TOKEN" \
        --header 'Accept: text/plain' \
        --data-urlencode "branch=main" \
        --data-urlencode "assets=$ASSETS_URL" \
        --max-time 1800 \
        --output "$log" \
        --write-out '%{http_code}'
) || {
    echo "::error::انقطع الاتصال بالخادم. النشر قد يكون ماضياً هناك — راجع storage/logs قبل إعادة المحاولة."
    [ -f "$log" ] && cat "$log"
    exit 1
}

echo "---------- مخرجات الخادم ----------"
cat "$log"
echo "-----------------------------------"

if [ "$status" != "200" ]; then
    case "$status" in
        403) echo "::error::مفتاح النشر مرفوض (403) — راجع DEPLOY_TOKEN على الخادم وفي أسرار المستودع." ;;
        404) echo "::error::المسار مغلق (404) — DEPLOY_TOKEN فارغ أو DEPLOY_ENABLED=false على الخادم." ;;
        409) echo "::error::هناك نشرٌ قيد التنفيذ الآن على الخادم (409)." ;;
        422) echo "::error::رفض الخادم أحد المعطيات (422) — راجع الفرع ورابط الأصول." ;;
        429) echo "::error::تجاوزت حدّ المحاولات (429) — انتظر دقيقة ثم أعد التشغيل." ;;
        *) echo "::error::ردّ الخادم برمزٍ غير متوقّع: $status." ;;
    esac
    exit 1
fi

# العلامة في آخر المخرجات، فلا نفتّش المخرجات كلّها كي لا يخدعنا نصٌّ مقتبس.
tail=$(tail -c 512 "$log")

if [[ "$tail" == *"== اكتمل النشر =="* ]]; then
    echo "اكتمل النشر على ${DEPLOY_URL%/}."
    exit 0
fi

if [[ "$tail" == *"== فشل النشر =="* ]]; then
    echo "::error::فشل النشر على الخادم — الرسالة في المخرجات أعلاه. الموقع أُعيد فتحه وأُرجعت الأصول."
    exit 1
fi

echo "::error::انتهت المخرجات دون علامةٍ ختامية — تحقّق من حال الموقع بنفسك."
exit 1
