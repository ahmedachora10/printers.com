<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** تاسك 146 — نوع بطاقة (مدى، فيزا، ماستر كارد…): قائمة عامة يديرها المدير العام. */
class CardType extends Model
{
    use SoftDeletes;

    protected $fillable = ['name'];
}
