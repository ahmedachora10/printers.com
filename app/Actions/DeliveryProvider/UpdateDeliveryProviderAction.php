<?php

namespace App\Actions\DeliveryProvider;

use App\Models\DeliveryProvider;
use Illuminate\Support\Facades\DB;

class UpdateDeliveryProviderAction
{
    /** @param array<string, mixed> $data */
    public function handle(DeliveryProvider $provider, array $data): DeliveryProvider
    {
        return DB::transaction(function () use ($provider, $data) {
            $provider->update($data);

            return $provider;
        });
    }
}
