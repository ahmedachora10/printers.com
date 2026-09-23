<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * مطابقة الحسابات ليومٍ وفرع (تاسكا 121 و122). اليوم: حاملُ ملف موازنة الشبكة.
 */
class AccountReconciliation extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes;

    /** تاسك 122 — ملف موازنة أجهزة الشبكة، على القرص الخاص. */
    public const SETTLEMENT_FILE = 'settlement_file';

    // `date` بلا cast عمداً: يبقى نصَّ Y-m-d، فيطابق firstOrCreate الصفَّ القائم
    // على SQLite (cast التاريخ يكتب وقتاً فيكسر القيد الفريد).
    protected $fillable = ['branch_id', 'date', 'created_by'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::SETTLEMENT_FILE)
            ->singleFile()
            ->useDisk('local');
    }

    public function settlementFile(): ?Media
    {
        return $this->getFirstMedia(self::SETTLEMENT_FILE);
    }
}
