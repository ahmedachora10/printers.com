<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'branch_id',
    ];

    public static function get(string $key, ?int $branchId = null, mixed $default = null): mixed
    {
        return static::query()
            ->where('key', $key)
            ->where('branch_id', $branchId)
            ->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value, ?int $branchId = null): void
    {
        static::updateOrCreate(
            ['key' => $key, 'branch_id' => $branchId],
            ['value' => $value]
        );
    }

    /** @return BelongsTo<Branch, self> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
