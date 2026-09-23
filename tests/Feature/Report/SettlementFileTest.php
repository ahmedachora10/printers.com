<?php

use App\Enums\Roles;
use App\Models\AccountReconciliation;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// تاسك 122 — ملف موازنة الشبكة ليومٍ وفرع، من تقرير المبيعات.
describe('Settlement file', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $this->branch = Branch::factory()->create();
        $this->otherBranch = Branch::factory()->create();
        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->pdf = fn (string $name = 'mada.pdf') => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");
    });

    it('uploads the file to the day of the accountant\'s own branch, whatever branch is sent, and replaces it', function () {
        $day = today()->toDateString();

        foreach (['first.pdf', 'second.pdf'] as $name) {
            $this->actingAs($this->accountant)
                ->post(route('reports.sales.settlement-file.store'), [
                    'branch' => $this->otherBranch->id,
                    'date' => $day,
                    'file' => ($this->pdf)($name),
                ])->assertRedirect();
        }

        $reconciliation = AccountReconciliation::sole();
        expect($reconciliation->branch_id)->toBe($this->branch->id)
            ->and($reconciliation->settlementFile()->file_name)->toBe('second.pdf');

        $this->actingAs($this->accountant)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page
                ->where('settlement.date', $day)
                ->where('settlement.file.name', 'second.pdf'));
    });

    it('offers no upload for a range or for all branches', function () {
        $this->actingAs($this->accountant)
            ->get(route('reports.sales', ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]))
            ->assertInertia(fn ($page) => $page->where('settlement', null));

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('settlement', null));
    });

    it('keeps another branch\'s file from the accountant', function () {
        $this->actingAs($this->superAdmin)->post(route('reports.sales.settlement-file.store'), [
            'branch' => $this->otherBranch->id,
            'date' => today()->toDateString(),
            'file' => ($this->pdf)(),
        ])->assertRedirect();

        $this->actingAs($this->accountant)
            ->get(route('reports.sales.settlement-file.show', AccountReconciliation::sole()))
            ->assertForbidden();
    });
});
