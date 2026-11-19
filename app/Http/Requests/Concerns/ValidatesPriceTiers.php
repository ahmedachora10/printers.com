<?php

namespace App\Http\Requests\Concerns;

use App\Support\PriceTiers;
use Illuminate\Validation\Validator;

/**
 * تاسك 241 — شرائح التسعير في طلبات الخدمة والمنتج: قاعدةٌ واحدة لا نسختان.
 */
trait ValidatesPriceTiers
{
    protected function normalizePriceTiers(): void
    {
        if ($this->has('price_tiers')) {
            $this->merge(['price_tiers' => PriceTiers::normalize($this->input('price_tiers'))]);
        }
    }

    /** @return array<string, mixed> */
    protected function priceTierRules(): array
    {
        return [
            'price_tiers' => ['nullable', 'array', 'max:50'],
            'price_tiers.*.to' => ['nullable', 'numeric', 'gt:0'],
            'price_tiers.*.price' => ['required', 'numeric', 'gt:0'],
            'price_tiers.*.min_price' => ['nullable', 'numeric', 'min:0'],
            'price_tiers.*.max_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * «إلى» لا تتكرّر والفارغة للأخيرة وحدها، وسعر كل شريحة داخل حدّيها — حدّاها إن
     * كُتبا، وإلا حدّا الخدمة ($min/$max)؛ وإلا صارت الشريحة غير قابلة للبيع بسعرها.
     */
    protected function priceTierCheck(Validator $validator, ?float $min = null, ?float $max = null): void
    {
        $tiers = $this->input('price_tiers');

        if (! is_array($tiers) || $validator->errors()->has('price_tiers.*')) {
            return;
        }

        // مرتّبة بـ«إلى» والفارغة آخراً (normalize)، فيكفي ألّا تتكرّر وألّا تكون فارغتان.
        $tos = array_column($tiers, 'to');
        $finite = array_filter($tos, fn ($to) => $to !== null);

        if (count($tos) - count($finite) > 1 || count($finite) !== count(array_unique($finite))) {
            $validator->errors()->add('price_tiers', 'كل شريحة تنتهي عند كمية مختلفة، والشريحة المفتوحة («أكثر من») واحدةٌ في الآخر.');

            return;
        }

        foreach ($tiers as $i => $tier) {
            $low = $tier['min_price'] ?? ($min > 0 ? $min : null);
            $high = $tier['max_price'] ?? ($max > 0 ? $max : null);
            $price = (float) $tier['price'];

            if (($low !== null && $price < $low) || ($high !== null && $price > $high)) {
                $validator->errors()->add("price_tiers.{$i}.price", 'سعر الشريحة '.($i + 1).' خارج حدّيها (الأقل والأعلى).');
            }
        }
    }
}
