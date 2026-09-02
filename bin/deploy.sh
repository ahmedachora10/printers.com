#!/usr/bin/env bash
#
# تشغيل النشر بمُفسِّر PHP الصحيح — دون الاعتماد على كنية (alias).
#
# الكنية في ~/.bashrc لا يقرأها cron ولا سكربتٌ غير تفاعلي، فتقع المهامّ
# المجدولة على `php` الافتراضي في المسار، وهو على cPanel قد يكون أقدم من
# النسخة التي يعمل عليها الموقع. هذا السكربت يبحث عن المُفسِّر بنفسه.
#
# الاستعمال:
#   bin/deploy.sh                     # نشرٌ كامل
#   bin/deploy.sh --dry-run           # عرض الخطوات دون تنفيذ
#   bin/deploy.sh --skip-backup --force
#
# في cron (بمسارٍ مطلق، فـ cron لا يبدأ من مجلّد المشروع):
#   0 3 * * * /home/USER/public_html/bin/deploy.sh --force >> /home/USER/deploy.log 2>&1
#
# ولتثبيت مُفسِّرٍ بعينه دون بحث:
#   DEPLOY_PHP=/opt/cpanel/ea-php83/root/usr/bin/php bin/deploy.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ترتيب البحث نفسه المتّبع في App\Support\PhpBinary: ما ضُبط صراحةً أولاً،
# ثم مواضع ea-php83 المعروفة، ثم ما في المسار.
CANDIDATES=(
    "${DEPLOY_PHP:-}"
    "/opt/cpanel/ea-php83/root/usr/bin/php"
    "/opt/cpanel/ea-php84/root/usr/bin/php"
    "/opt/alt/php83/usr/bin/php"
    "/usr/local/bin/ea-php83"
    "/usr/local/bin/php83"
)

PHP_BIN=""

for candidate in "${CANDIDATES[@]}"; do
    if [ -n "$candidate" ] && [ -x "$candidate" ]; then
        PHP_BIN="$candidate"
        break
    fi
done

if [ -z "$PHP_BIN" ]; then
    for name in php83 ea-php83 php; do
        if command -v "$name" > /dev/null 2>&1; then
            PHP_BIN="$(command -v "$name")"
            break
        fi
    done
fi

if [ -z "$PHP_BIN" ]; then
    echo "لم يُعثر على مُفسِّر PHP. ثبّته بـ DEPLOY_PHP=/path/to/php" >&2
    exit 1
fi

# نسخةٌ أقدم من 8.3 لا تُقلع بها حزم المشروع، فالوقوف هنا أرحم من فشلٍ في
# منتصف هجرة.
VERSION_ID="$("$PHP_BIN" -r 'echo PHP_VERSION_ID;' 2> /dev/null || echo 0)"

if [ "$VERSION_ID" -lt 80300 ]; then
    echo "المُفسِّر $PHP_BIN نسخته $("$PHP_BIN" -r 'echo PHP_VERSION;' 2> /dev/null) والتطبيق يتطلب 8.3 فأعلى." >&2
    echo "ثبّت الصحيح بـ DEPLOY_PHP=/opt/cpanel/ea-php83/root/usr/bin/php" >&2
    exit 1
fi

echo "PHP: $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"

exec "$PHP_BIN" "$ROOT/artisan" app:deploy "$@"
