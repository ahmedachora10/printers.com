<?php

namespace App\Http\Controllers;

use App\Http\Requests\CardType\SaveCardTypeRequest;
use App\Models\CardType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * تاسك 146 — أنواع البطاقات (تبويب أجهزة الشبكة في الإعدادات). كتابة صفٍّ واحد
 * بلا منطق مالي فلا Action. الحذف ناعم: صفوف المطابقة تبقى تشير إليه.
 */
class CardTypeController extends Controller
{
    public function store(SaveCardTypeRequest $request): RedirectResponse
    {
        Gate::authorize('create', CardType::class);

        CardType::create($request->validated());

        return back()->with('success', 'تم إضافة نوع البطاقة');
    }

    public function update(SaveCardTypeRequest $request, CardType $cardType): RedirectResponse
    {
        Gate::authorize('update', $cardType);

        $cardType->update($request->validated());

        return back()->with('success', 'تم تحديث نوع البطاقة');
    }

    public function destroy(CardType $cardType): RedirectResponse
    {
        Gate::authorize('delete', $cardType);

        $cardType->delete();

        return back()->with('success', 'تم حذف نوع البطاقة');
    }
}
