<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * يوحّد جوالات العملاء القائمة إلى 05XXXXXXXX (Customer::normalizePhone). الجوال
 * فريدٌ لكل فرع (والمحذوف داخلٌ في الفهرس)، فالعملاء الذين تصير جوالاتهم رقماً
 * واحداً في الفرع نفسه لا تُمسّ صفوفهم، بل تُسجَّل في laravel.log لتُدمج يدوياً
 * من شاشة دمج العملاء.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')
            ->whereNotNull('phone')
            ->get(['id', 'branch_id', 'phone'])
            ->groupBy(fn ($c) => $c->branch_id.'|'.Customer::normalizePhone($c->phone))
            ->each(function ($group) {
                $phone = Customer::normalizePhone($group->first()->phone);

                if ($phone === null) {
                    return; // بلا أرقامٍ أصلاً: لا صيغة يُوحَّد إليها، ولا «تكرار».
                }

                if ($group->count() > 1) {
                    Log::warning('normalize_customer_phones: same phone in one branch — merge manually', [
                        'branch_id' => $group->first()->branch_id,
                        'phone' => $phone,
                        'customer_ids' => $group->pluck('id')->all(),
                    ]);

                    return;
                }

                if ($phone !== $group->first()->phone) {
                    DB::table('customers')->where('id', $group->first()->id)->update(['phone' => $phone]);
                }
            });
    }
};
