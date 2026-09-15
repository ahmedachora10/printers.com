<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\InvoicePayment;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** تاسك 106 — تنزيل إيصالات الفترة في ZIP واحد. */
beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
    $this->admin->update(['branch_id' => $this->branch->id]);
    $this->otherBranch = Branch::factory()->create();

    $this->rajhi = PaymentMethod::factory()->create(['name' => 'الراجحي']);
    $this->card = PaymentMethod::factory()->create(['name' => 'شبكة']);

    $this->invoice = function (Branch $branch, PaymentMethod $method, bool $receipt = true): ServiceInvoice {
        $invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-'.fake()->unique()->numberBetween(1, 999999),
            'branch_id' => $branch->id,
            'user_id' => $this->admin->id,
            'payment_method_id' => $method->id,
            'subtotal' => 100, 'vat_pct' => 15, 'vat_amount' => 15, 'total_amount' => 115,
            'employee_commission' => 0,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        if ($receipt) {
            $invoice->addMedia(UploadedFile::fake()->image('r.jpg'))->toMediaCollection(ServiceInvoice::RECEIPT_COLLECTION);
        }

        return $invoice;
    };

    $this->zipNames = function ($response): array {
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();
        sort($names);

        return $names;
    };
});

it('zips only the receipts of the chosen payment method', function () {
    $a = ($this->invoice)($this->branch, $this->rajhi);
    $b = ($this->invoice)($this->branch, $this->rajhi);
    ($this->invoice)($this->branch, $this->card);
    ($this->invoice)($this->branch, $this->rajhi, receipt: false);

    $response = $this->actingAs($this->admin)
        ->get(route('reports.sales.receipts', ['payment_method' => $this->rajhi->id]))
        ->assertOk();

    $names = ($this->zipNames)($response);
    expect($names)->toHaveCount(2)
        ->and(collect($names)->contains(fn ($n) => str_starts_with($n, $a->invoice_number.' - عميل نقدي - الراجحي - ')))->toBeTrue()
        ->and(collect($names)->contains(fn ($n) => str_starts_with($n, $b->invoice_number)))->toBeTrue();
});

it('includes a deposit receipt under its own payment method', function () {
    $invoice = ($this->invoice)($this->branch, $this->card, receipt: false);
    $invoice->update(['status' => 'partially_paid']);
    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'invoice_type' => ServiceInvoice::class,
        'branch_id' => $this->branch->id,
        'payment_method_id' => $this->rajhi->id,
        'amount' => 50,
        'paid_at' => now(),
        'recorded_by' => $this->admin->id,
    ]);
    $payment->addMedia(UploadedFile::fake()->image('deposit.png'))->toMediaCollection(InvoicePayment::RECEIPT_COLLECTION);

    $response = $this->actingAs($this->admin)
        ->get(route('reports.sales.receipts', ['payment_method' => $this->rajhi->id]))
        ->assertOk();

    expect(($this->zipNames)($response))->toHaveCount(1)
        ->and(($this->zipNames)($response)[0])->toEndWith('.png');
});

it('keeps a branch admin out of another branch even when sending branch', function () {
    ($this->invoice)($this->otherBranch, $this->rajhi);

    $this->actingAs($this->admin)
        ->from(route('reports.sales'))
        ->get(route('reports.sales.receipts', ['branch' => $this->otherBranch->id]))
        ->assertRedirect(route('reports.sales'))
        ->assertSessionHas('error', 'لا توجد إيصالات في هذه الفترة');
});
