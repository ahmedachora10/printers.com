<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function receiptBranchService(int $branchId): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create([
        'branch_id' => $branchId,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ]);

    return BranchService::where('branch_id', $branchId)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

function receiptPayload(int $serviceId, array $overrides = []): array
{
    return array_merge([
        'payment_method_id' => paymentMethodId(),
        'status' => 'due',
        'lines' => [
            ['branch_service_id' => $serviceId, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
        ],
    ], $overrides);
}

describe('Invoice payment receipt', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->service = receiptBranchService($this->branch->id);

        $this->transferMethod = PaymentMethod::factory()->requiresAttachment()->create();
        $this->cashMethod = PaymentMethod::factory()->create(['requires_attachment' => false]);
    });

    it('requires a receipt when the method mandates an attachment', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->transferMethod->id,
        ]))->assertSessionHasErrors('receipt');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('stores the receipt as media when a transfer method is used', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->transferMethod->id,
            'receipt' => UploadedFile::fake()->image('transfer.jpg'),
        ]))->assertRedirect(route('pos.service.create'));

        $invoice = ServiceInvoice::firstOrFail();
        expect($invoice->hasReceipt())->toBeTrue()
            ->and($invoice->getFirstMedia('receipt')->disk)->toBe('local');
    });

    it('does not require a receipt for a non-attachment method', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->cashMethod->id,
        ]))->assertSessionDoesntHaveErrors('receipt');

        expect(ServiceInvoice::firstOrFail()->hasReceipt())->toBeFalse();
    });

    it('rejects a receipt with a disallowed mime type', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->transferMethod->id,
            'receipt' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
        ]))->assertSessionHasErrors('receipt');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('serves the stored receipt to the owning employee', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->transferMethod->id,
            'receipt' => UploadedFile::fake()->image('transfer.jpg'),
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->get(route('invoices.receipt', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk();
    });

    it('forbids an employee from another branch from viewing the receipt', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->transferMethod->id,
            'receipt' => UploadedFile::fake()->image('transfer.jpg'),
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $otherBranch = Branch::factory()->create();
        $intruder = User::factory()->create(['branch_id' => $otherBranch->id]);
        $intruder->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($intruder);

        $this->get(route('invoices.receipt', ['type' => 'service', 'id' => $invoice->id]))
            ->assertForbidden();
    });

    it('lets a reviewer attach a receipt during review', function () {
        // Employee raises a DUE invoice with a cash method (no receipt yet).
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->cashMethod->id,
        ]));

        $invoice = ServiceInvoice::firstOrFail();
        expect($invoice->hasReceipt())->toBeFalse();

        $this->actingAs($this->branchAdmin)
            ->post(route('invoices.service.receipt', $invoice), [
                'receipt' => UploadedFile::fake()->image('transfer.png'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect($invoice->refresh()->hasReceipt())->toBeTrue();
    });

    /**
     * تبديل طريقة الدفع كان بابَ التفاف على قاعدة الإيصال: يُختار «تحويل بنكي»
     * من شاشة المراجعة بلا مرفق، ثم تُعتمد الفاتورة. الطلب يفرضه الآن، والاعتماد
     * يبقى شبكة أمان لما سبقه من فواتير.
     */
    it('refuses to switch to an attachment method without a receipt', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->cashMethod->id,
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->branchAdmin)
            ->patch(route('invoices.service.update-payment-method', $invoice), [
                'payment_method_id' => $this->transferMethod->id,
            ])
            ->assertSessionHasErrors('receipt');

        expect($invoice->refresh()->payment_method_id)->toBe($this->cashMethod->id);
    });

    it('switches the method and stores the receipt in one request', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->cashMethod->id,
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->branchAdmin)
            ->patch(route('invoices.service.update-payment-method', $invoice), [
                'payment_method_id' => $this->transferMethod->id,
                'receipt' => UploadedFile::fake()->image('transfer.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $invoice->refresh();

        expect($invoice->payment_method_id)->toBe($this->transferMethod->id)
            ->and($invoice->hasReceipt())->toBeTrue();
    });

    /**
     * صيغة الواجهة نفسها: رفع ملف عبر PATCH لا يمرّ في multipart، فالنافذة تُرسل
     * POST مع `_method`. الاختبار يحرس هذا السلك لا المنطق وحده.
     */
    it('accepts the browser method-spoofed multipart request', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->cashMethod->id,
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->branchAdmin)
            ->post(route('invoices.service.update-payment-method', $invoice), [
                '_method' => 'patch',
                'payment_method_id' => $this->transferMethod->id,
                'receipt' => UploadedFile::fake()->image('transfer.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $invoice->refresh();

        expect($invoice->payment_method_id)->toBe($this->transferMethod->id)
            ->and($invoice->hasReceipt())->toBeTrue();
    });

    it('does not ask again for a receipt the invoice already carries', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->transferMethod->id,
            'receipt' => UploadedFile::fake()->image('transfer.jpg'),
        ]));

        $invoice = ServiceInvoice::firstOrFail();
        $other = PaymentMethod::factory()->requiresAttachment()->create();

        $this->actingAs($this->branchAdmin)
            ->patch(route('invoices.service.update-payment-method', $invoice), [
                'payment_method_id' => $other->id,
            ])
            ->assertSessionHasNoErrors();

        expect($invoice->refresh()->payment_method_id)->toBe($other->id);
    });

    it('blocks approval of a transfer invoice that carries no receipt', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->cashMethod->id,
        ]));

        // فاتورة سبقت تفعيل العَلَم: طريقتها صارت تشترط مرفقاً بأثر رجعي.
        $invoice = ServiceInvoice::firstOrFail();
        $this->cashMethod->update(['requires_attachment' => true]);

        $this->actingAs($this->branchAdmin)
            ->patch(route('invoices.service.pay', $invoice))
            ->assertSessionHasErrors('receipt');

        expect($invoice->refresh()->status->value)->toBe('due');
    });

    it('approves once the receipt is attached', function () {
        $this->post(route('pos.service.store'), receiptPayload($this->service->id, [
            'payment_method_id' => $this->transferMethod->id,
            'receipt' => UploadedFile::fake()->image('transfer.jpg'),
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->branchAdmin)
            ->patch(route('invoices.service.pay', $invoice))
            ->assertSessionHasNoErrors();

        expect($invoice->refresh()->status->value)->toBe('paid');
    });
});
