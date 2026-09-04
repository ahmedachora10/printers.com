<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('User notes', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->branch = Branch::factory()->create();

        $this->actingAs($this->superAdmin);
    });

    // ── PERSISTENCE ────────────────────────────────────────────────

    it('stores the notes written when creating a user', function () {
        $this->post(route('users.store'), [
            'name' => 'موظف جديد',
            'username' => 'newstaff',
            'email' => 'newstaff@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => Roles::EMPLOYEE->value,
            'branch_id' => $this->branch->id,
            'notes' => '<p>موظف <strong>ممتاز</strong></p>',
            'is_active' => true,
        ])->assertRedirect(route('users.index'));

        expect(User::where('username', 'newstaff')->first()->notes)
            ->toBe('<p>موظف <strong>ممتاز</strong></p>');
    });

    it('updates the notes on an existing user', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'notes' => '<p>قديم</p>']);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->put(route('users.update', $user), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => Roles::EMPLOYEE->value,
            'branch_id' => $this->branch->id,
            'notes' => '<p>ملاحظة محدّثة</p>',
            'is_active' => true,
        ])->assertRedirect(route('users.index'));

        expect($user->refresh()->notes)->toBe('<p>ملاحظة محدّثة</p>');
    });

    it('clears the notes when the editor is emptied', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'notes' => '<p>قديم</p>']);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->put(route('users.update', $user), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => Roles::EMPLOYEE->value,
            'branch_id' => $this->branch->id,
            // Tiptap emits an empty paragraph for a document with no content.
            'notes' => '<p></p>',
            'is_active' => true,
        ]);

        expect($user->refresh()->notes)->toBeNull();
    });

    // ── SANITISATION ───────────────────────────────────────────────

    it('strips scripts and event handlers from notes on save', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->put(route('users.update', $user), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => Roles::EMPLOYEE->value,
            'branch_id' => $this->branch->id,
            'notes' => '<p onclick="steal()">مرحبا</p><script>alert(1)</script><img src=x onerror="alert(1)">',
            'is_active' => true,
        ]);

        $notes = $user->refresh()->notes;

        expect($notes)->toBe('<p>مرحبا</p>')
            ->and($notes)->not->toContain('script')
            ->and($notes)->not->toContain('onclick');
    });

    // ── EXPOSURE ───────────────────────────────────────────────────

    it('exposes the notes and a plain-text excerpt on the user list', function () {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'notes' => '<p>ملاحظة <strong>مهمة</strong></p>',
        ]);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->get(route('users.index'))
            ->assertInertia(fn ($page) => $page->where('users.data', function ($data) use ($user) {
                $row = collect($data)->firstWhere('id', $user->id);

                return $row['notes'] === '<p>ملاحظة <strong>مهمة</strong></p>'
                    && $row['notesExcerpt'] === 'ملاحظة مهمة';
            }));
    });

    it('reports a null excerpt when there are no notes', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'notes' => null]);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->get(route('users.show', $user))
            ->assertInertia(fn ($page) => $page
                ->where('user.notes', null)
                ->where('user.notesExcerpt', null)
                ->etc());
    });
});

describe('HtmlSanitizer', function () {
    it('keeps the tags the editor can produce', function () {
        $html = '<p><strong>ع</strong><em>م</em><u>خ</u><s>ش</s></p><ul><li>أ</li></ul>'
            .'<ol><li>ب</li></ol><h3>عنوان</h3><blockquote><p>اقتباس</p></blockquote>';

        expect(HtmlSanitizer::clean($html))->toBe($html);
    });

    it('unwraps unknown tags but keeps their text', function () {
        expect(HtmlSanitizer::clean('<p><span class="x">نص</span> باقي</p>'))
            ->toBe('<p>نص باقي</p>');
    });

    it('drops javascript hrefs and hardens the links it keeps', function () {
        expect(HtmlSanitizer::clean('<p><a href="javascript:alert(1)">اضغط</a></p>'))
            ->toBe('<p><a>اضغط</a></p>');

        expect(HtmlSanitizer::clean('<p><a href="https://example.com">اضغط</a></p>'))
            ->toBe('<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">اضغط</a></p>');
    });

    it('treats blank markup as no content', function () {
        expect(HtmlSanitizer::clean('<p></p>'))->toBeNull()
            ->and(HtmlSanitizer::clean('<p>   </p><p><br></p>'))->toBeNull()
            ->and(HtmlSanitizer::clean(''))->toBeNull()
            ->and(HtmlSanitizer::clean(null))->toBeNull();
    });

    it('reduces markup to readable plain text', function () {
        expect(HtmlSanitizer::toPlainText('<p>سطر أول</p><ul><li>بند</li><li>آخر</li></ul>'))
            ->toBe('سطر أول بند آخر');
    });
});
