<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = ['نقد', 'بطاقة بنكية', 'تحويل بنكي', 'مدى'];

        foreach ($defaults as $name) {
            PaymentMethod::updateOrCreate(
                ['name' => $name],
                ['is_active' => true, 'requires_attachment' => $name === 'تحويل بنكي']
            );
        }
    }
}
