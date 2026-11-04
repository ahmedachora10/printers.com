<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * العميل نفسه في أكثر من فرع (نفس الجوال) صار هويةً واحدة: تُوحَّد حقول الهوية
 * (Customer::IDENTITY_FIELDS) على صفوفه من صفٍّ مرجعي — اسمٌ غير «عميل عابر» أولاً،
 * ثم الأحدث تعديلاً. معاملات كل فرع ونقاطه ومستواه لا تُمسّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('customers')
            ->whereNotNull('phone')
            ->whereNull('deleted_at')
            ->get(['id', 'updated_at', ...Customer::IDENTITY_FIELDS])
            ->groupBy('phone')
            ->filter(fn ($group) => $group->count() > 1);

        $groups->each(function ($group) {
            $reference = $group
                ->sortByDesc(fn ($c) => [$c->full_name !== Customer::WALK_IN_NAME, (string) $c->updated_at])
                ->first();

            DB::table('customers')
                ->whereIn('id', $group->pluck('id')->reject(fn ($id) => $id === $reference->id))
                ->update(collect($reference)->only(Customer::IDENTITY_FIELDS)->all());
        });

        Log::info('sync_customer_identity_across_branches', ['groups' => $groups->count()]);
    }
};
