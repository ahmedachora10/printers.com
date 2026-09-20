<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/** تاسك 115: سجلّ حركة العمليات — الشاشة العامّة وسجلّ مستخدمٍ واحد. */
describe('Activity log', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->outsider = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->outsider->addRole(Roles::EMPLOYEE->value);
    });

    it('lists every actor for a super-admin', function () {
        activity('sales')->causedBy($this->employee)->log('اختبار الموظف');
        activity('sales')->causedBy($this->outsider)->log('اختبار الغريب');

        $this->actingAs($this->superAdmin)
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('activity-log/index')
                ->has('activities.data', 2));
    });

    it('hides other branches from a branch-admin', function () {
        activity('sales')->causedBy($this->employee)->log('اختبار الموظف');
        activity('sales')->causedBy($this->outsider)->log('اختبار الغريب');

        $this->actingAs($this->branchAdmin)
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('activities.data', 1)
                ->where('activities.data.0.causerName', $this->employee->name));
    });

    it('keeps an employee out of the log entirely', function () {
        $this->actingAs($this->employee)
            ->get(route('activity-log.index'))
            ->assertForbidden();
    });

    it('filters by section and by date range', function () {
        activity('sales')->causedBy($this->employee)->log('مبيعة');
        activity('expenses')->causedBy($this->employee)->log('مصروف');

        $old = activity('sales')->causedBy($this->employee)->log('قديمة');
        $old->forceFill(['created_at' => now()->subMonths(3)])->save();

        $this->actingAs($this->superAdmin)
            ->get(route('activity-log.index', ['log' => 'expenses']))
            ->assertInertia(fn ($page) => $page->has('activities.data', 1)->where('activities.data.0.logLabel', 'المصروفات'));

        // خارج المدى الافتراضي (آخر 30 يوماً) فلا يظهر ما مضى عليه ثلاثة أشهر.
        $this->get(route('activity-log.index'))
            ->assertInertia(fn ($page) => $page->has('activities.data', 2));
    });

    it('shows a single user timeline to their branch-admin', function () {
        activity('sales')->causedBy($this->employee)->log('مبيعة');
        activity('sales')->causedBy($this->branchAdmin)->log('حركة المدير');

        $this->actingAs($this->branchAdmin)
            ->get(route('users.activity', $this->employee))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('users/activity')
                ->where('subject.name', $this->employee->name)
                ->has('activities.data', 1));
    });

    it('denies a branch-admin the log of another branch', function () {
        $this->actingAs($this->branchAdmin)
            ->get(route('users.activity', $this->outsider))
            ->assertForbidden();
    });

    it('denies a branch-admin the log of a fellow admin', function () {
        $this->actingAs($this->branchAdmin)
            ->get(route('users.activity', $this->superAdmin))
            ->assertForbidden();
    });

    it('reads a status change as an approval', function () {
        $invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-TST-1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'subtotal' => 100,
            'coupon_discount' => 0,
            'agent_discount' => 0,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::DUE,
        ]);

        Activity::query()->delete();

        $this->actingAs($this->branchAdmin);
        $invoice->update(['status' => InvoiceStatusEnum::PAID, 'paid_at' => now()]);

        // سجلّ مدير الفرع لا يقرؤه إلا السوبر أدمن.
        $this->actingAs($this->superAdmin)
            ->get(route('users.activity', $this->branchAdmin))
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.action', 'اعتمد')
                ->where('activities.data.0.subjectLabel', $invoice->invoice_number));
    });

    it('reads the flat old/new pair a manual log writes', function () {
        $cash = PaymentMethod::create(['name' => 'نقد', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $card = PaymentMethod::create(['name' => 'شبكة', 'branch_id' => $this->branch->id, 'is_active' => true]);

        activity('invoices')
            ->causedBy($this->employee)
            ->withProperties(['payment_id' => null, 'old' => $cash->name, 'new' => $card->name])
            ->log('payment method changed');

        $this->actingAs($this->branchAdmin)
            ->get(route('users.activity', $this->employee))
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.changes.0.label', 'طريقة الدفع')
                ->where('activities.data.0.changes.0.old', 'نقد')
                ->where('activities.data.0.changes.0.new', 'شبكة')
                ->where('activities.data.0.isSensitive', true));
    });

    it('reads from_/to_ pairs and keeps the rest as details', function () {
        activity('customers')
            ->causedBy($this->employee)
            ->withProperties([
                'from_tier' => 'silver',
                'to_tier' => 'gold',
                'reason' => 'عميل مميز',
            ])
            ->log('تعديل يدوي لمستوى الولاء');

        $this->actingAs($this->branchAdmin)
            ->get(route('users.activity', $this->employee))
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.changes.0.label', 'فئة الولاء')
                ->where('activities.data.0.changes.0.old', 'silver')
                ->where('activities.data.0.changes.0.new', 'gold')
                ->where('activities.data.0.details.0.label', 'السبب')
                ->where('activities.data.0.details.0.value', 'عميل مميز'));
    });

    it('resolves ids in a model diff to names', function () {
        $method = PaymentMethod::create(['name' => 'تحويل بنكي', 'branch_id' => $this->branch->id, 'is_active' => true]);

        $invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-TST-2',
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'subtotal' => 100,
            'coupon_discount' => 0,
            'agent_discount' => 0,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::DUE,
        ]);

        Activity::query()->delete();

        $this->actingAs($this->employee);
        $invoice->update(['payment_method_id' => $method->id]);

        $this->actingAs($this->branchAdmin)
            ->get(route('users.activity', $this->employee))
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.changes.0.label', 'طريقة الدفع')
                ->where('activities.data.0.changes.0.new', 'تحويل بنكي'));
    });

    it('reads the users table once for all the user fields on a page', function () {
        activity('expenses')->causedBy($this->employee)->withProperties([
            'attributes' => [
                'user_id' => $this->employee->id,
                'approved_by' => $this->branchAdmin->id,
                'delivered_by' => $this->outsider->id,
            ],
        ])->log('اختبار المصدر الواحد');

        $lookups = 0;
        DB::listen(function ($query) use (&$lookups) {
            if (str_starts_with($query->sql, 'select "name", "id" from "users"')) {
                $lookups++;
            }
        });

        $this->actingAs($this->branchAdmin)
            ->get(route('users.activity', $this->employee))
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.changes.0.new', $this->employee->name)
                ->where('activities.data.0.changes.1.new', $this->branchAdmin->name)
                ->where('activities.data.0.changes.2.new', $this->outsider->name));

        expect($lookups)->toBe(1);
    });

    it('does not flag a routine sign in as sensitive', function () {
        activity('security')->causedBy($this->employee)->log('تسجيل الدخول');

        $this->actingAs($this->branchAdmin)
            ->get(route('users.activity', $this->employee))
            ->assertInertia(fn ($page) => $page->where('activities.data.0.isSensitive', false));
    });

    it('logs sign in and sign out', function () {
        $this->post(route('login'), [
            'username' => $this->employee->username,
            'password' => 'password',
        ]);

        expect(Activity::query()->where('description', 'تسجيل الدخول')->where('causer_id', $this->employee->id)->exists())->toBeTrue();

        $this->post(route('logout'));

        expect(Activity::query()->where('description', 'تسجيل الخروج')->where('causer_id', $this->employee->id)->exists())->toBeTrue();
    });
});
