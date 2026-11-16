<?php

namespace App\Http\Controllers;

use App\Http\Requests\CardType\SaveCardTypeRequest;
use App\Models\CardType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * تاسك 146 — أنواع البطاقات (تبويب أجهزة الشبكة في الإعدادات). تاسك 243: مدير الفرع
 * يدير أنواع فرعه، والسوبر أدمن الكل. الحذف ناعم: صفوف المطابقة تبقى تشير إليه.
 */
class CardTypeController extends Controller
{
    public function store(SaveCardTypeRequest $request): RedirectResponse
    {
        Gate::authorize('create', CardType::class);

        $this->save(new CardType, $request->validated());

        return back()->with('success', 'تم إضافة نوع البطاقة');
    }

    public function update(SaveCardTypeRequest $request, CardType $cardType): RedirectResponse
    {
        Gate::authorize('update', $cardType);

        $this->save($cardType, $request->validated());

        return back()->with('success', 'تم تحديث نوع البطاقة');
    }

    public function destroy(CardType $cardType): RedirectResponse
    {
        Gate::authorize('delete', $cardType);

        $cardType->delete();

        return back()->with('success', 'تم حذف نوع البطاقة');
    }

    /** @param  array<string, mixed>  $data */
    private function save(CardType $cardType, array $data): void
    {
        DB::transaction(function () use ($cardType, $data) {
            $cardType->fill(Arr::except($data, 'branch_ids'))->save();
            $cardType->branches()->sync($data['branch_ids']);
        });
    }
}
