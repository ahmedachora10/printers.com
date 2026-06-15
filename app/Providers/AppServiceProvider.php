<?php

namespace App\Providers;

use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\City;
use App\Models\CommissionPayment;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Policies\AgentPaymentPolicy;
use App\Policies\AgentPolicy;
use App\Policies\BranchPolicy;
use App\Policies\BranchServicePolicy;
use App\Policies\CityPolicy;
use App\Policies\CommissionPaymentPolicy;
use App\Policies\CouponPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\ExpenseCategoryPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\PaymentMethodPolicy;
use App\Policies\ProductCategoryPolicy;
use App\Policies\ProductInvoicePolicy;
use App\Policies\ProductPolicy;
use App\Policies\RefundPolicy;
use App\Policies\ServiceInvoicePolicy;
use App\Policies\ServiceTemplatePolicy;
use App\Policies\SettingPolicy;
use App\Policies\StockMovementPolicy;
use App\Policies\UserPolicy;
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
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(ServiceTemplate::class, ServiceTemplatePolicy::class);
        Gate::policy(BranchService::class, BranchServicePolicy::class);
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(ExpenseCategory::class, ExpenseCategoryPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(Agent::class, AgentPolicy::class);
        Gate::policy(AgentPayment::class, AgentPaymentPolicy::class);
        Gate::policy(Coupon::class, CouponPolicy::class);
        Gate::policy(CommissionPayment::class, CommissionPaymentPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductInvoice::class, ProductInvoicePolicy::class);
        Gate::policy(ServiceInvoice::class, ServiceInvoicePolicy::class);
        Gate::policy(Refund::class, RefundPolicy::class);
        Gate::policy(StockMovement::class, StockMovementPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(PaymentMethod::class, PaymentMethodPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
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
