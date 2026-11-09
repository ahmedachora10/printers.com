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
    // تاسك 208 — الرسالة الخاصة لمرسلها ومن أُرسلت إليه وحدهما، ولو كان الناظر مدير الفرع.
    it('shows a private message to its sender and recipient only', function () {
        Notification::fake();

        ($this->post)($this->owner, ['body' => 'عامة'])->assertRedirect();
        ($this->post)($this->accountant, ['body' => 'خاصة للمدير', 'private_to' => 'role:branch-admin'])->assertRedirect();
        $private = InvoiceMessage::latest('id')->first();

        Notification::assertSentTo($this->branchAdmin, InvoiceMessagePostedNotification::class);
        Notification::assertNotSentTo($this->owner, InvoiceMessagePostedNotification::class);

        $bodies = fn (User $user) => ($this->show)($user)->assertInertia(fn ($page) => $page
            ->loadDeferredProps('thread', fn ($page) => $page->has('thread.messages', $user->is($this->owner) ? 1 : 2)));
        $bodies($this->accountant);
        $bodies($this->branchAdmin);
        $bodies($this->owner);

        ($this->show)($this->accountant)->assertInertia(fn ($page) => $page
            ->loadDeferredProps('thread', fn ($page) => $page->where('thread.messages.1.privateTo', 'مدير الفرع')));

        // لا يُعدّ غير مقروء عند من لا يراها، ولا يُفرز به، ولا يُدار من خارجها.
        $this->actingAs($this->owner)->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.unreadMessages', 0));
        InvoiceMessage::whereKeyNot($private->id)->delete();
        $this->actingAs($this->owner)->get(route('invoices.index', ['messages' => 'any']))
            ->assertInertia(fn ($page) => $page->has('items.data', 0));
        $this->actingAs($this->owner)->patch(route('invoices.messages.toggle-actioned', $private))->assertNotFound();
    });

    it('lets a private message go to one employee, out of the invoice owner sight', function () {
        ($this->post)($this->branchAdmin, ['body' => 'لك وحدك', 'private_to' => "user:{$this->otherEmployee->id}"])->assertRedirect();

        // المستلم يدخل الخيط مشاركاً فيراها؛ صاحب الفاتورة يرى الخيط بلا هذه الرسالة.
        ($this->show)($this->otherEmployee)->assertInertia(fn ($page) => $page
            ->loadDeferredProps('thread', fn ($page) => $page->has('thread.messages', 1)
                ->where('thread.messages.0.body', 'لك وحدك')));
        ($this->show)($this->owner)->assertInertia(fn ($page) => $page
            ->loadDeferredProps('thread', fn ($page) => $page->has('thread.messages', 0)));

        // قرار 30/10: مدير الفرع يرى كل الرسائل، والخاصة بين غيره كذلك.
        ($this->post)($this->accountant, ['body' => 'بين المحاسب والموظف', 'private_to' => "user:{$this->otherEmployee->id}"]);
        ($this->show)($this->branchAdmin)->assertInertia(fn ($page) => $page
            ->loadDeferredProps('thread', fn ($page) => $page->has('thread.messages', 2)));
    });

    // تاسك 209 — قائمة الملاحظات وحدها، بقاعدة الرؤية نفسها.
    it('lists the notes on their own, newest first, as each viewer may see them', function () {
        $colleague = threadInvoice($this->branch->id, $this->otherEmployee->id);
        ($this->post)($this->owner, ['body' => 'على فاتورتي']);
        $this->actingAs($this->otherEmployee)->post(route('invoices.messages.store', $colleague), ['body' => 'على فاتورة زميل']);
        ($this->post)($this->accountant, ['body' => 'سرّ', 'private_to' => 'role:branch-admin']);
        $foreign = threadInvoice($this->elsewhere->id, $this->foreignAccountant->id);
        InvoiceMessage::create(['service_invoice_id' => $foreign->id, 'user_id' => $this->foreignAccountant->id, 'body' => 'فرع آخر']);

        // تاسك 223: صفٌّ لكل فاتورة، وآخر رسالة فيها هي المعروضة.
        $this->actingAs($this->branchAdmin)->get(route('invoices.messages.index'))
            ->assertInertia(fn ($page) => $page->component('invoices/messages')
                ->has('messages.data', 2)
                ->where('messages.data.0.body', 'سرّ')
                ->where('messages.data.0.privateTo', 'مدير الفرع')
                ->where('messages.data.0.messageCount', 2)
                ->where('messages.data.0.invoiceNumber', $this->invoice->invoice_number));

        // الموظف يرى خيوط فواتيره وحدها، وبلا الخاصة التي ليست له — لا في العدد ولا آخرَ رسالة.
        $this->actingAs($this->owner)->get(route('invoices.messages.index'))
            ->assertInertia(fn ($page) => $page->has('messages.data', 1)
                ->where('messages.data.0.body', 'على فاتورتي')
                ->where('messages.data.0.messageCount', 1));

        $this->actingAs(threadStaff(Roles::AUDITOR, $this->branch->id))->get(route('invoices.messages.index'))->assertForbidden();
    });

    // تاسك 220 — الفرز: الكاتب، القراءة للناظر، الإغلاق، التاريخ.
    it('filters the notes list by author, read state, closed state and date', function () {
        $other = threadInvoice($this->branch->id, $this->owner->id, ['messages_closed_at' => now()]);
        ($this->post)($this->accountant, ['body' => 'سؤال المحاسب']);
        ($this->post)($this->owner, ['body' => 'ردّ الموظف']);
        InvoiceMessage::create(['service_invoice_id' => $other->id, 'user_id' => $this->owner->id, 'body' => 'قديمة'])
            ->forceFill(['created_at' => now()->subMonth()])->save();
        $list = fn (array $filters) => $this->actingAs($this->accountant)->get(route('invoices.messages.index', $filters));

        $list(['author' => $this->accountant->id])->assertInertia(fn ($page) => $page->has('messages.data', 1)
            ->where('messages.data.0.invoiceId', $this->invoice->id)
            ->where('messages.data.0.messageCount', 2));
        // رسالة المحاسب نفسه ليست غير مقروءة عليه؛ ردّ الموظف بعدها كذلك.
        $list(['read' => 'unread'])->assertInertia(fn ($page) => $page->has('messages.data', 2)
            ->where('messages.data.1.invoiceId', $this->invoice->id)
            ->where('messages.data.1.unreadCount', 1));
        $list(['closed' => '1'])->assertInertia(fn ($page) => $page->has('messages.data', 1)
            ->where('messages.data.0.invoiceId', $other->id)
            ->where('messages.data.0.closedAt', fn ($v) => $v !== null));
        $list(['from' => now()->subDay()->toDateString()])->assertInertia(fn ($page) => $page->has('messages.data', 1)
            ->where('messages.data.0.invoiceId', $this->invoice->id));

        ($this->show)($this->accountant)->assertInertia(fn ($page) => $page->loadDeferredProps('thread', fn ($page) => $page));
        $list(['read' => 'read'])->assertInertia(fn ($page) => $page->has('messages.data', 1)
            ->where('messages.data.0.invoiceId', $this->invoice->id));
        $list(['read' => 'unread'])->assertInertia(fn ($page) => $page->has('messages.data', 1)
            ->where('messages.data.0.invoiceId', $other->id));
    });

    // تاسك 221 — إغلاق المحادثة من القائمة، بصلاحيته في شاشة الفاتورة.
    it('lets the branch admin close a thread from the notes list', function () {
        ($this->post)($this->owner, ['body' => 'ملاحظة']);

        $this->actingAs($this->accountant)->get(route('invoices.messages.index'))
            ->assertInertia(fn ($page) => $page->where('messages.data.0.canClose', false));
        $this->actingAs($this->branchAdmin)->get(route('invoices.messages.index'))
            ->assertInertia(fn ($page) => $page->where('messages.data.0.canClose', true));

        $this->patch(route('invoices.messages.toggle-closed', $this->invoice))->assertRedirect();
        expect($this->invoice->refresh()->messages_closed_at)->not->toBeNull();
    });

    // تاسك 219 — من قائمة الملاحظات يتنقّل على خيوطها بفلاترها، لا على الفواتير.
    it('navigates between the notes list threads when opened from it', function () {
        $older = threadInvoice($this->branch->id, $this->owner->id);
        InvoiceMessage::create(['service_invoice_id' => $older->id, 'user_id' => $this->owner->id, 'body' => 'أقدم']);
        ($this->post)($this->owner, ['body' => 'أحدث']);
        $newest = threadInvoice($this->branch->id, $this->owner->id);
        InvoiceMessage::create(['service_invoice_id' => $newest->id, 'user_id' => $this->accountant->id, 'body' => 'الأحدث']);

        $this->actingAs($this->branchAdmin)->get(route('invoices.messages.index'));
        $this->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id, 'from' => 'messages']))
            ->assertInertia(fn ($page) => $page
                ->where('neighbours.prevId', $newest->id)
                ->where('neighbours.nextId', $older->id)
                ->where('neighbours.fromMessages', true));

        // الفلتر المحفوظ يحكم: بكاتبٍ واحد لا جار للفاتورة إلا خيوطه.
        $this->get(route('invoices.messages.index', ['author' => $this->accountant->id]));
        $this->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id, 'from' => 'messages']))
            ->assertInertia(fn ($page) => $page->where('neighbours.prevId', $newest->id)->where('neighbours.nextId', null));

        // بلا from يبقى تنقّل الفواتير.
        $this->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertInertia(fn ($page) => $page->missing('neighbours.fromMessages'));
    });

    // تاسك 222 — عدّاد الخيوط غير المقروءة على «الملاحظات الداخلية».
    it('counts unread threads in the sidebar for whoever sees the notes list', function () {
        $counts = fn (User $user) => $this->actingAs($user)->get(route('dashboard'))->viewData('page')['props']['pendingCounts'];

        ($this->post)($this->accountant, ['body' => 'للموظف']);
        ($this->post)($this->accountant, ['body' => 'خاصة', 'private_to' => 'role:branch-admin']);

        expect($counts($this->owner)['unreadMessages'])->toBe(1)
            ->and($counts($this->otherEmployee)['unreadMessages'])->toBe(0)
            ->and($counts($this->accountant)['unreadMessages'])->toBe(0);

        ($this->show)($this->owner)->assertInertia(fn ($page) => $page->loadDeferredProps('thread', fn ($page) => $page));
        expect($counts($this->owner)['unreadMessages'])->toBe(0);
    });
});
