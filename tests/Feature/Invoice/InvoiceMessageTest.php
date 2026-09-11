<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\InvoiceMessage;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Notifications\InvoiceMessagePostedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * تاسك 100 — المحادثة الداخلية على فاتورة الخدمات، بدل حقل التاسك 95 الذي
 * يُكتب فوقه: رسائل تتراكم، @ لدورٍ أو لشخص، تنبيه، غير مقروء، مرفقات، إغلاق،
 * وتعديل/حذف لمدير الفرع وحده — ولا شيء منها يصل ورقة العميل.
 */
function threadInvoice(int $branchId, int $userId, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-MSG-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => 0,
        'status' => InvoiceStatusEnum::DUE,
    ], $overrides));
}

function threadStaff(Roles $role, ?int $branchId): User
{
    $user = User::factory()->create(['branch_id' => $branchId]);
    $user->addRole($role->value);

    return $user;
}

describe('Invoice internal thread', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = threadStaff(Roles::BRANCH_ADMIN, null);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->owner = threadStaff(Roles::EMPLOYEE, $this->branch->id);
        $this->otherEmployee = threadStaff(Roles::EMPLOYEE, $this->branch->id);
        $this->accountant = threadStaff(Roles::ACCOUNTANT, $this->branch->id);

        $this->elsewhere = Branch::factory()->create();
        $this->foreignAccountant = threadStaff(Roles::ACCOUNTANT, $this->elsewhere->id);

        $this->invoice = threadInvoice($this->branch->id, $this->owner->id);

        $this->post = fn (User $user, array $data) => $this->actingAs($user)
            ->post(route('invoices.messages.store', $this->invoice), $data);
        $this->show = fn (User $user) => $this->actingAs($user)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]));
    });

    it('keeps every message, in order, instead of overwriting', function () {
        ($this->post)($this->owner, ['body' => 'الخامة من الفرع الثاني'])->assertRedirect();
        ($this->post)($this->accountant, ['body' => 'تمام، سأراجعها'])->assertRedirect();

        ($this->show)($this->branchAdmin)->assertInertia(fn ($page) => $page
            ->where('hasThread', true)
            ->missing('thread')
            ->loadDeferredProps('thread', fn ($page) => $page
                ->has('thread.messages', 2)
                ->where('thread.messages.0.body', 'الخامة من الفرع الثاني')
                ->where('thread.messages.0.authorName', $this->owner->name)
                ->where('thread.messages.0.authorRole', 'موظف')
                ->where('thread.messages.1.body', 'تمام، سأراجعها')
                ->where('thread.messages.1.authorRole', 'محاسب')));
    });

    it('moves the task-95 notes into the thread as a first, authorless message', function () {
        $legacy = threadInvoice($this->branch->id, $this->owner->id, ['internal_notes' => 'ملاحظة قديمة']);
        threadInvoice($this->branch->id, $this->owner->id, ['internal_notes' => '']);

        $migration = require database_path('migrations/2026_09_11_100000_create_invoice_messages.php');
        $migration->down();
        $migration->up();

        expect(InvoiceMessage::count())->toBe(1);
        $message = InvoiceMessage::first();
        expect($message->service_invoice_id)->toBe($legacy->id)
            ->and($message->user_id)->toBeNull()
            ->and($message->body)->toBe('ملاحظة قديمة');
    });

    it('keeps an employee out of a colleague\'s thread until they are mentioned', function () {
        ($this->show)($this->otherEmployee)->assertOk()->assertInertia(fn ($page) => $page->where('hasThread', false));
        ($this->post)($this->otherEmployee, ['body' => 'تطفّل'])->assertForbidden();

        ($this->post)($this->accountant, [
            'body' => "@{$this->otherEmployee->name} هل عندك الخامة؟",
            'mentions' => ["user:{$this->otherEmployee->id}"],
        ])->assertRedirect();

        ($this->show)($this->otherEmployee)->assertInertia(fn ($page) => $page->where('hasThread', true));
        ($this->post)($this->otherEmployee, ['body' => 'نعم عندي'])->assertRedirect();
    });

    it('notifies the owner, earlier writers and the mentioned — never the sender', function () {
        Notification::fake();

        ($this->post)($this->owner, ['body' => '@المحاسب راجع السعر', 'mentions' => ['role:accountant']])->assertRedirect();

        // الإشارة إلى الدور تبلغ محاسب فرع الفاتورة وحده.
        Notification::assertSentTo($this->accountant, InvoiceMessagePostedNotification::class);
        Notification::assertNotSentTo($this->foreignAccountant, InvoiceMessagePostedNotification::class);
        Notification::assertNotSentTo($this->owner, InvoiceMessagePostedNotification::class);

        ($this->post)($this->accountant, ['body' => 'تمت المراجعة'])->assertRedirect();

        Notification::assertSentTo($this->owner, InvoiceMessagePostedNotification::class);
        Notification::assertSentToTimes($this->accountant, InvoiceMessagePostedNotification::class, 1);
    });

    it('refuses a mention of someone outside the invoice\'s branch', function () {
        ($this->post)($this->owner, ['body' => 'مرحبا', 'mentions' => ["user:{$this->foreignAccountant->id}"]])
            ->assertSessionHasErrors('mentions.0');

        expect(InvoiceMessage::count())->toBe(0);
    });

    it('counts unread messages in the list until the invoice is opened', function () {
        ($this->post)($this->owner, ['body' => 'الأولى']);
        ($this->post)($this->owner, ['body' => 'الثانية']);

        $this->actingAs($this->accountant)->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.unreadMessages', 2));

        // فتح الفاتورة يطلب الخيط المؤجَّل، وقراءته تقدّم موضع القراءة.
        ($this->show)($this->accountant)->assertInertia(fn ($page) => $page
            ->loadDeferredProps('thread', fn ($page) => $page->where('thread.messages.1.isNew', true)));

        $this->actingAs($this->accountant)->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.unreadMessages', 0));

        // ورسائل المرء نفسه ليست غير مقروءة عليه.
        $this->actingAs($this->owner)->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.unreadMessages', 0));
    });

    it('lets only the branch admin edit or delete a message, keeping the history', function () {
        ($this->post)($this->owner, ['body' => 'سعر خاطئ']);
        $message = InvoiceMessage::first();

        $this->actingAs($this->owner)->patch(route('invoices.messages.update', $message), ['body' => 'مُعدَّل'])->assertForbidden();
        $this->actingAs($this->owner)->delete(route('invoices.messages.destroy', $message))->assertForbidden();
        $this->actingAs($this->accountant)->delete(route('invoices.messages.destroy', $message))->assertForbidden();

        $this->actingAs($this->branchAdmin)->patch(route('invoices.messages.update', $message), ['body' => 'السعر الصحيح 120'])->assertRedirect();

        expect($message->refresh()->body)->toBe('السعر الصحيح 120')
            ->and($message->edited_at)->not->toBeNull()
            ->and(Activity::where('description', InvoiceMessage::LOG_EDITED)->first()->properties['old'])->toBe('سعر خاطئ');

        $this->actingAs($this->branchAdmin)->delete(route('invoices.messages.destroy', $message))->assertRedirect();

        expect(InvoiceMessage::withTrashed()->find($message->id)->trashed())->toBeTrue();

        ($this->show)($this->owner)->assertInertia(fn ($page) => $page
            ->loadDeferredProps('thread', fn ($page) => $page
                ->has('thread.messages', 1)
                ->where('thread.messages.0.body', null)
                ->where('thread.messages.0.deletedByName', $this->branchAdmin->name)));
    });

    it('marks a message actioned by someone other than its sender', function () {
        ($this->post)($this->owner, ['body' => 'أرجو تعديل الخامة']);
        $message = InvoiceMessage::first();

        $this->actingAs($this->owner)->patch(route('invoices.messages.toggle-actioned', $message))->assertForbidden();
        $this->actingAs($this->accountant)->patch(route('invoices.messages.toggle-actioned', $message))->assertRedirect();

        expect($message->refresh()->actioned_by)->toBe($this->accountant->id);
    });

    it('closes the thread to new messages — and only the branch admin may', function () {
        $this->actingAs($this->accountant)->patch(route('invoices.messages.toggle-closed', $this->invoice))->assertForbidden();
        $this->actingAs($this->branchAdmin)->patch(route('invoices.messages.toggle-closed', $this->invoice))->assertRedirect();

        ($this->post)($this->owner, ['body' => 'متأخر'])->assertForbidden();

        $this->actingAs($this->branchAdmin)->patch(route('invoices.messages.toggle-closed', $this->invoice));
        ($this->post)($this->owner, ['body' => 'الآن'])->assertRedirect();
    });

    it('stores an attachment privately and serves it to thread readers only', function () {
        Storage::fake('local');

        ($this->post)($this->owner, ['attachments' => [UploadedFile::fake()->image('proof.png')]])->assertRedirect();
        $message = InvoiceMessage::first();
        $media = $message->getFirstMedia(InvoiceMessage::ATTACHMENTS);

        expect($message->body)->toBeNull()->and($media->disk)->toBe('local');

        $url = route('invoices.messages.attachment', ['message' => $message->id, 'media' => $media->id]);
        $this->actingAs($this->accountant)->get($url)->assertOk();
        $this->actingAs($this->otherEmployee)->get($url)->assertForbidden();

        ($this->post)($this->owner, ['attachments' => [UploadedFile::fake()->create('run.exe', 5, 'application/octet-stream')]])
            ->assertSessionHasErrors('attachments.0');
    });

    it('keeps the thread out of the print payload', function () {
        ($this->post)($this->owner, ['body' => 'سرّي داخلي']);

        $response = $this->actingAs($this->accountant)
            ->get(route('invoices.print', ['type' => 'service', 'id' => $this->invoice->id]));

        $response->assertInertia(fn ($page) => $page->missing('thread'));
        expect($response->getContent())->not->toContain('سرّي داخلي');
    });

    it('turns the POS internal-notes box into messages — the first on create, a new one on edit', function () {
        $template = ServiceTemplate::factory()->create(['name' => 'تغليف']);
        $template->branches()->attach($this->branch->id, ['base_commission_pct' => 0, 'is_active' => true]);
        $service = BranchService::where('branch_id', $this->branch->id)->where('service_template_id', $template->id)->firstOrFail();
        $lines = [['branch_service_id' => $service->id, 'qty' => 1, 'unit_price' => 50, 'discount_pct' => 0]];

        $this->actingAs($this->owner)
            ->post(route('pos.service.store'), ['status' => 'due', 'internal_notes' => 'داخلية', 'lines' => $lines])
            ->assertRedirect();

        $created = ServiceInvoice::latest('id')->first();

        expect($created->internal_notes)->toBeNull()
            ->and($created->messages()->pluck('body')->all())->toBe(['داخلية'])
            ->and($created->messages()->first()->user_id)->toBe($this->owner->id);

        $this->actingAs($this->owner)
            ->put(route('pos.service.update', $created), ['internal_notes' => 'إضافة بعد التعديل', 'lines' => $lines])
            ->assertRedirect();

        expect($created->messages()->pluck('body')->all())->toBe(['داخلية', 'إضافة بعد التعديل']);
    });
});
