<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * مطابقة الحسابات ليومٍ وفرع (تاسكا 121 و122).
 *
 * أرقام النظام (system_net، auto_total، auto_breakdown) null حتى الاعتماد —
 * تُحسب حيّةً قبله وتُجمَّد عنده. الحالة مشتقّةٌ من الفرق.
 */
class AccountReconciliation extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes;

    /** تاسك 122 — ملف موازنة أجهزة الشبكة، على القرص الخاص. */
    public const SETTLEMENT_FILE = 'settlement_file';

    // `date` بلا cast عمداً: يبقى نصَّ Y-m-d، فيطابق firstOrCreate الصفَّ القائم
    // على SQLite (cast التاريخ يكتب وقتاً فيكسر القيد الفريد).
    protected $fillable = [
        'branch_id', 'date', 'created_by',
        'system_net', 'auto_total', 'auto_breakdown', 'devices_total', 'notes',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'system_net' => 'decimal:2',
        'auto_total' => 'decimal:2',
        'auto_breakdown' => 'array',
        'devices_total' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(AccountReconciliationDevice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

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
