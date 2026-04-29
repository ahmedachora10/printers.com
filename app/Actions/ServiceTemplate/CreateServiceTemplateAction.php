<?php

namespace App\Actions\ServiceTemplate;

use App\Models\ServiceTemplate;
use Illuminate\Support\Facades\DB;

class CreateServiceTemplateAction
{
    public function handle(array $data): ServiceTemplate
    {
        return DB::transaction(fn (): ServiceTemplate => ServiceTemplate::create($data));
    }
}
