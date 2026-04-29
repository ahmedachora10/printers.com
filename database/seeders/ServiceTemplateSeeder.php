<?php

namespace Database\Seeders;

use App\Models\ServiceTemplate;
use Illuminate\Database\Seeder;

class ServiceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            'بحوث',
            'طباعة',
            'اسكنر',
            'ترجمة',
            'طباعة تحاضير',
            'تغليف',
            'تصوير',
            'تصوير فوتوغرافي',
            'تصوير 3D',
        ];
        
        foreach ($templates as $template) {
            ServiceTemplate::factory()->create([
                'name' => $template,
            ]);
        }
    }
}
