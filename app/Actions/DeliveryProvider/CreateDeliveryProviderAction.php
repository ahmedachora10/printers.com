<?php

namespace App\Actions\DeliveryProvider;

use App\Models\DeliveryProvider;
use Illuminate\Support\Facades\DB;

class CreateDeliveryProviderAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): DeliveryProvider
    {
        return DB::transaction(fn () => DeliveryProvider::create($data));
    }
}
