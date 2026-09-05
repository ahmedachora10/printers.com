<?php

use App\Models\Branch;
use App\Models\City;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * تاسك 86: مرفقات ملفّ الموظف — على القرص الخاص خلف مسارٍ محميّ بسياسة، فالسيرة
 * الذاتية بيانٌ شخصي لا يُخدم من رابطٍ مفتوح.
 */
describe('user attachments', function () {
    beforeEach(function () {
        $this->withoutVite();
        // القرص الخاص يُزيَّف كي لا يخلّف الاختبار ملفات في storage/app/private.
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->city = City::factory()->create();

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole('branch-admin');

        $this->branch = Branch::factory()->create([
            'city_id' => $this->city->id,
            'owner_id' => $this->branchAdmin->id,
        ]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole('employee');

        $this->pdf = fn (string $name = 'cv.pdf', int $kb = 100) => UploadedFile::fake()->create($name, $kb, 'application/pdf');
    });

    it('stores an uploaded file on the private disk', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]])
            ->assertRedirect();

        $media = $this->employee->refresh()->getMedia(User::ATTACHMENTS_COLLECTION);

        expect($media)->toHaveCount(1)
            ->and($media->first()->disk)->toBe('local')
            ->and($media->first()->file_name)->toBe('cv.pdf');

        // القرص الخاص هو storage/app/private — لا public/ ولا رابطٌ مباشر.
        Storage::disk('local')->assertExists($media->first()->id.'/cv.pdf');
    });

    it('accepts several files in one upload', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), [
                'files' => [($this->pdf)('cv.pdf'), ($this->pdf)('contract.pdf')],
            ])
            ->assertRedirect();

        expect($this->employee->refresh()->getMedia(User::ATTACHMENTS_COLLECTION))->toHaveCount(2);
    });

    it('rejects a file over the size limit in Arabic', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), [
                'files' => [($this->pdf)('huge.pdf', 10 * 1024 + 1)],
            ])
            ->assertSessionHasErrors(['files.0' => 'حجم الملف يجب ألا يتجاوز 10 ميجابايت.']);

        expect($this->employee->refresh()->getMedia(User::ATTACHMENTS_COLLECTION))->toHaveCount(0);
    });

    it('rejects a forbidden file type in Arabic', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), [
                'files' => [UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream')],
            ])
            ->assertSessionHasErrors(['files.0' => 'الملفات المسموحة: PDF أو صورة (jpg, png, webp) أو مستند Office.']);
    });

    it('lets an authorized manager download the file', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]]);

        $media = $this->employee->refresh()->getFirstMedia(User::ATTACHMENTS_COLLECTION);

        $this->actingAs($this->branchAdmin)
            ->get(route('users.attachments.download', [$this->employee->id, $media->id]))
            ->assertOk();
    });

    it('lets the employee read their own file but not delete it', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]]);

        $media = $this->employee->refresh()->getFirstMedia(User::ATTACHMENTS_COLLECTION);

        $this->actingAs($this->employee)
            ->get(route('users.attachments.download', [$this->employee->id, $media->id]))
            ->assertOk();

        $this->actingAs($this->employee)
            ->delete(route('users.attachments.destroy', [$this->employee->id, $media->id]))
            ->assertForbidden();

        expect($this->employee->refresh()->getMedia(User::ATTACHMENTS_COLLECTION))->toHaveCount(1);
    });

    it('forbids a colleague from reading someone else attachments', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]]);

        $media = $this->employee->refresh()->getFirstMedia(User::ATTACHMENTS_COLLECTION);

        $colleague = User::factory()->create(['branch_id' => $this->branch->id]);
        $colleague->addRole('employee');

        $this->actingAs($colleague)
            ->get(route('users.attachments.download', [$this->employee->id, $media->id]))
            ->assertForbidden();
    });

    it('forbids the admin of another branch', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]]);

        $media = $this->employee->refresh()->getFirstMedia(User::ATTACHMENTS_COLLECTION);

        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole('branch-admin');
        $otherBranch = Branch::factory()->create(['city_id' => $this->city->id, 'owner_id' => $otherAdmin->id]);
        $otherAdmin->update(['branch_id' => $otherBranch->id]);

        $this->actingAs($otherAdmin)
            ->get(route('users.attachments.download', [$this->employee->id, $media->id]))
            ->assertForbidden();

        $this->actingAs($otherAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]])
            ->assertForbidden();
    });

    it('refuses a media id that belongs to another user', function () {
        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole('employee');

        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $other), ['files' => [($this->pdf)('secret.pdf')]]);

        $media = $other->refresh()->getFirstMedia(User::ATTACHMENTS_COLLECTION);

        // المستدعي يملك صلاحية الاطّلاع على الموظف الأول — والمعرّف وحده لا يكفي.
        $this->actingAs($this->branchAdmin)
            ->get(route('users.attachments.download', [$this->employee->id, $media->id]))
            ->assertNotFound();
    });

    it('lists attachments as json for the modal', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]]);

        $this->actingAs($this->branchAdmin)
            ->getJson(route('users.attachments.index', $this->employee))
            ->assertOk()
            ->assertJsonPath('attachments.0.name', 'cv.pdf')
            ->assertJsonStructure(['attachments' => [['id', 'name', 'size', 'mimeType', 'uploadedAt', 'downloadUrl']]]);
    });

    it('deletes an attachment and its file', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]]);

        $media = $this->employee->refresh()->getFirstMedia(User::ATTACHMENTS_COLLECTION);
        $path = $media->id.'/cv.pdf';

        $this->actingAs($this->branchAdmin)
            ->delete(route('users.attachments.destroy', [$this->employee->id, $media->id]))
            ->assertRedirect();

        expect($this->employee->refresh()->getMedia(User::ATTACHMENTS_COLLECTION))->toHaveCount(0);
        Storage::disk('local')->assertMissing($path);
    });

    it('leaves no orphan files when the user is finally removed', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.attachments.store', $this->employee), ['files' => [($this->pdf)()]]);

        $media = $this->employee->refresh()->getFirstMedia(User::ATTACHMENTS_COLLECTION);
        $path = $media->id.'/cv.pdf';

        // الحذف اللين يُبقي الملف مع صاحبه (فالاستعادة تعيده كاملاً)…
        $this->employee->delete();
        Storage::disk('local')->assertExists($path);

        // …والحذف النهائي وحده يمحو الملف من القرص.
        $this->employee->forceDelete();
        Storage::disk('local')->assertMissing($path);
    });
});
