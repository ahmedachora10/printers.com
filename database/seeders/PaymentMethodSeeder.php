<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = ['نقد', 'بطاقة بنكية', 'تحويل بنكي', 'مدى'];

        Branch::all()->each(function (Branch $branch) use ($defaults) {
            foreach ($defaults as $name) {
                PaymentMethod::firstOrCreate(
                    ['name' => $name, 'branch_id' => $branch->id],
                    ['is_active' => true]
                );
            }
        });
    }
}
