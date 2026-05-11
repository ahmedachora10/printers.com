<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'قرطاسية',
            'أحبار',
            'مستلزمات طباعة',
        ];

        foreach ($categories as $category) {
            ProductCategory::factory()->create([
                'name' => $category,
            ]);
        }
    }
}
