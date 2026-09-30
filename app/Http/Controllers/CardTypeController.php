<?php

namespace App\Http\Controllers;

use App\Http\Requests\CardType\SaveCardTypeRequest;
use App\Models\CardType;
use Illuminate\Http\RedirectResponse;

/**
 * تاسك 146 — أنواع البطاقات (تبويب أجهزة الشبكة في الإعدادات)، للمدير العام وحده (ميدلوير المسار). كتابة صفٍّ واحد
 * بلا منطق مالي فلا Action. الحذف ناعم: صفوف المطابقة تبقى تشير إليه.
 */
class CardTypeController extends Controller
{
    public function store(SaveCardTypeRequest $request): RedirectResponse
    {
        CardType::create($request->validated());

        return back()->with('success', 'تم إضافة نوع البطاقة');
    }

    public function update(SaveCardTypeRequest $request, CardType $cardType): RedirectResponse
    {
        $cardType->update($request->validated());

        return back()->with('success', 'تم تحديث نوع البطاقة');
    }

    public function destroy(CardType $cardType): RedirectResponse
    {
        $cardType->delete();

        return back()->with('success', 'تم حذف نوع البطاقة');
    }
}
