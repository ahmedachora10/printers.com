<?php

namespace App\Providers;

use App\Models\City;
use App\Policies\CityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Sheet;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Sheet::listen(AfterSheet::class, function ($event) {
            $event->sheet->getDelegate()->setRightToLeft(true);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (DB::connection() instanceof SQLiteConnection) {
            DB::statement('PRAGMA journal_mode=WAL;');
            DB::statement('PRAGMA busy_timeout=5000;');
        }

        $this->configureDefaults();
        $this->configureGates();
    }

    private function configureGates(): void
    {
        Gate::policy(City::class, CityPolicy::class);
    }

    protected function configureDefaults(): void
    {
        JsonResource::withoutWrapping();

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
