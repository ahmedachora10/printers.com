<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// الجوال بلا مسافات: السعودي 05XXXXXXXX والدولي +رمز الدولة؛ يُوحَّد عند الإدخال.
describe('Customer phone', function () {
    it('normalizes every way a phone gets typed', function (?string $input, ?string $expected) {
        expect(Customer::normalizePhone($input))->toBe($expected);
    })->with([
        'spaces + 966' => ['+966 55 172 8069', '0551728069'],
        '00966' => ['00966551728069', '0551728069'],
        '966' => ['966551728069', '0551728069'],
        'no leading zero' => ['551728069', '0551728069'],
        'spaces and dashes' => ['055-172 8069', '0551728069'],
        'arabic digits' => ['٠٥٥١٧٢٨٠٦٩', '0551728069'],
        'pasted RTL mark' => ["\u{200F}0551728069", '0551728069'],
        'partial search term' => ['055 172', '055172'],
        'foreign with spaces' => ['+92 302 9216123', '+923029216123'],
        'foreign with 00' => ['0092 302 9216123', '+923029216123'],
        'saudi landline international' => ['+966 11 234 5678', '+966112345678'],
        'local landline untouched' => ['011 234 5678', '0112345678'],
        'empty' => ['', null],
        'null' => [null, null],
    ]);

    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
    });

    it('stores phones without spaces, accepts foreign numbers, rejects numbers without a country code', function () {
        $payload = ['full_name' => 'عميل', 'customer_type' => 'individual'];

        $this->actingAs($this->admin)
            ->post(route('customers.store'), [...$payload, 'phone' => '+966 55 172 8069'])
            ->assertSessionHasNoErrors();
        expect(Customer::sole()->phone)->toBe('0551728069');

        // نفس الرقم بصيغة أخرى مكرَّرٌ لا عميلٌ جديد.
        $this->post(route('customers.store'), [...$payload, 'phone' => '055 172 8069'])
            ->assertSessionHasErrors('phone');

        $this->post(route('customers.store'), [...$payload, 'phone' => '+92 302 9216123'])
            ->assertSessionHasNoErrors();
        expect(Customer::where('phone', '+923029216123')->exists())->toBeTrue();

        // رقمٌ ليس جوالاً سعودياً وبلا رمز دولة: لا يُعرف لأي بلد هو.
        foreach (['0112345678', '923029216123'] as $phone) {
            $this->post(route('customers.store'), [...$payload, 'phone' => $phone])
                ->assertSessionHasErrors('phone');
        }
    });

    it('normalizes existing phones and leaves branch collisions for a manual merge', function () {
        $other = Branch::factory()->create();
        $insert = fn (int $branchId, string $phone) => DB::table('customers')->insertGetId([
            'full_name' => 'عميل', 'phone' => $phone, 'branch_id' => $branchId,
            'customer_type' => 'individual', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $spaced = $insert($this->branch->id, '+966 56 179 9635');
        $dupA = $insert($this->branch->id, '+966 55 172 8069');
        $dupB = $insert($this->branch->id, '0551728069');
        $otherBranch = $insert($other->id, '055 172 8069');
        $foreign = $insert($this->branch->id, '+92 302 9216123');

        Log::spy();
        (require database_path('migrations/2026_10_13_120000_normalize_customer_phones.php'))->up();

        $phone = fn (int $id) => DB::table('customers')->where('id', $id)->value('phone');
        expect($phone($spaced))->toBe('0561799635')
            ->and($phone($otherBranch))->toBe('0551728069')
            ->and($phone($foreign))->toBe('+923029216123')
            ->and($phone($dupA))->toBe('+966 55 172 8069')
            ->and($phone($dupB))->toBe('0551728069');

        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn ($msg, $ctx) => $ctx['customer_ids'] === [$dupA, $dupB]);
    });
});
