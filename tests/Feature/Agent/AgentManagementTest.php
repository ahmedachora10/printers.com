<?php

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

describe('Agent Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin);
    });

    /** @return array<string, mixed> */
    function validAgentPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'مؤسسة الطباعة الذهبية',
            'username' => 'gold_agent',
            'email' => 'gold@example.com',
            'phone' => '0501112222',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agent_type' => 'company',
            'discount_mode' => 'rebate',
            'rate' => 12.5,
            'commercial_reg_no' => '1234567890',
        ], $overrides);
    }

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows branch-admin to view agent list', function () {
        Agent::factory()->count(3)->create(['branch_id' => $this->branch->id]);

        $this->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('agents/index')
                ->has('items.data', 3));
    });

    it('keeps the accountant out of the agent register', function () {
        // تاسك 40: بيانات المندوب لمدير الفرع وحده. المحاسب يصرف العمولة
        // (تاسك 41) ولا يمسّ السجلّ نفسه — تبادلٌ صريح لا سهو.
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('agents.index'))->assertForbidden();
    });

    it('prevents employee from viewing agent list', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->get(route('agents.index'))->assertForbidden();
    });

    it('only lists agent-role users from the current branch', function () {
        $mine = Agent::factory()->create(['branch_id' => $this->branch->id]);

        // An agent in another branch — must not appear.
        $otherBranch = Branch::factory()->create();
        Agent::factory()->create(['branch_id' => $otherBranch->id]);

        // A non-agent user in the same branch — must not appear (global scope).
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->get(route('agents.index'))
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $mine->id));
    });

    it('shows an agent created through the store endpoint in the list', function () {
        // Regression: agents are created as User (store endpoint), so their role
        // pivot is saved under the User morph type. The list queries via the Agent
        // model, which must report the same morph type or the row is filtered out.
        $this->post(route('agents.store'), validAgentPayload())
            ->assertRedirect(route('agents.index'));

        $this->get(route('agents.index'))
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.username', 'gold_agent'));
    });

    it('lets super-admin filter the agent list by branch', function () {
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $this->actingAs($superAdmin);

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $inA = Agent::factory()->create(['branch_id' => $branchA->id]);
        Agent::factory()->create(['branch_id' => $branchB->id]);

        // No filter: super-admin sees agents from every branch.
        $this->get(route('agents.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 2));

        // Filtered to branch A.
        $this->get(route('agents.index', ['branch_id' => $branchA->id]))
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $inA->id));
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates an agent user with the agent role and a profile', function () {
        $this->post(route('agents.store'), validAgentPayload())
            ->assertRedirect(route('agents.index'));

        $user = User::where('username', 'gold_agent')->first();

        expect($user)->not->toBeNull()
            ->and($user->hasRole(Roles::AGENT->value))->toBeTrue()
            ->and(Hash::check('password123', $user->password))->toBeTrue();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'مؤسسة الطباعة الذهبية',
            'branch_id' => $this->branch->id,
        ]);

        $this->assertDatabaseHas('agent_profiles', [
            'user_id' => $user->id,
            'agent_type' => 'company',
            'discount_mode' => 'rebate',
            'rate' => 12.5,
            'commercial_reg_no' => '1234567890',
        ]);
    });

    it('fails to create an agent without required fields', function () {
        $this->post(route('agents.store'), [])
            ->assertSessionHasErrors(['name', 'username', 'email', 'password', 'agent_type', 'discount_mode', 'rate']);
    });

    it('fails to create an agent with an invalid discount mode', function () {
        $this->post(route('agents.store'), validAgentPayload(['discount_mode' => 'invalid']))
            ->assertSessionHasErrors(['discount_mode']);
    });

    it('rejects a rate above 100', function () {
        $this->post(route('agents.store'), validAgentPayload(['rate' => 150]))
            ->assertSessionHasErrors(['rate']);
    });

    it('defaults the discount type to percentage when omitted', function () {
        $this->post(route('agents.store'), validAgentPayload())
            ->assertRedirect(route('agents.index'));

        $this->assertDatabaseHas('agent_profiles', [
            'user_id' => User::where('username', 'gold_agent')->value('id'),
            'discount_type' => 'percentage',
        ]);
    });

    it('creates an agent with a fixed discount type', function () {
        $this->post(route('agents.store'), validAgentPayload([
            'discount_type' => 'fixed',
            'rate' => 50,
        ]))->assertRedirect(route('agents.index'));

        $this->assertDatabaseHas('agent_profiles', [
            'user_id' => User::where('username', 'gold_agent')->value('id'),
            'discount_type' => 'fixed',
            'rate' => 50,
        ]);
    });

    it('allows a fixed rate above 100', function () {
        // A fixed SAR amount is not bound by the percentage 0-100 ceiling.
        $this->post(route('agents.store'), validAgentPayload([
            'discount_type' => 'fixed',
            'rate' => 500,
        ]))->assertRedirect(route('agents.index'));

        $this->assertDatabaseHas('agent_profiles', [
            'user_id' => User::where('username', 'gold_agent')->value('id'),
            'rate' => 500,
        ]);
    });

    it('rejects an invalid discount type', function () {
        $this->post(route('agents.store'), validAgentPayload(['discount_type' => 'nonsense']))
            ->assertSessionHasErrors(['discount_type']);
    });

    it('enforces a unique username', function () {
        User::factory()->create(['username' => 'gold_agent']);

        $this->post(route('agents.store'), validAgentPayload(['username' => 'gold_agent']))
            ->assertSessionHasErrors(['username']);
    });

    it('enforces a unique email', function () {
        User::factory()->create(['email' => 'gold@example.com']);

        $this->post(route('agents.store'), validAgentPayload(['email' => 'gold@example.com']))
            ->assertSessionHasErrors(['email']);
    });

    it('forces a branch-admin created agent into the admin own branch', function () {
        // branch_id is not submitted; it must resolve to the admin's branch.
        $this->post(route('agents.store'), validAgentPayload())
            ->assertRedirect(route('agents.index'));

        $this->assertDatabaseHas('users', [
            'username' => 'gold_agent',
            'branch_id' => $this->branch->id,
        ]);
    });

    it('allows super-admin to create an agent for a chosen branch', function () {
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $this->actingAs($superAdmin);

        $this->post(route('agents.store'), validAgentPayload(['branch_id' => $this->branch->id]))
            ->assertRedirect(route('agents.index'));

        $this->assertDatabaseHas('users', [
            'username' => 'gold_agent',
            'branch_id' => $this->branch->id,
        ]);
    });

    it('fails when super-admin creates an agent without a branch', function () {
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $this->actingAs($superAdmin);

        $this->post(route('agents.store'), validAgentPayload(['branch_id' => null]))
            ->assertSessionHasErrors(['branch_id']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates an agent and its profile', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);

        $this->put(route('agents.update', $agent), [
            'name' => 'اسم محدث',
            'username' => $agent->username,
            'email' => $agent->email,
            'phone' => $agent->phone,
            'agent_type' => 'company',
            'discount_mode' => 'rebate',
            'rate' => 20,
        ])->assertRedirect(route('agents.index'));

        $this->assertDatabaseHas('users', ['id' => $agent->id, 'name' => 'اسم محدث']);
        $this->assertDatabaseHas('agent_profiles', [
            'user_id' => $agent->id,
            'discount_mode' => 'rebate',
            'rate' => 20,
        ]);
    });

    it('keeps the existing password when none is provided on update', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        $originalHash = $agent->password;

        $this->put(route('agents.update', $agent), [
            'name' => $agent->name,
            'username' => $agent->username,
            'email' => $agent->email,
            'agent_type' => 'individual',
            'discount_mode' => 'discount',
            'rate' => 5,
            'password' => '',
        ])->assertRedirect(route('agents.index'));

        expect($agent->fresh()->password)->toBe($originalHash);
    });

    it('prevents a branch-admin from updating an agent in another branch', function () {
        $otherBranch = Branch::factory()->create();
        $agent = Agent::factory()->create(['branch_id' => $otherBranch->id]);

        $this->put(route('agents.update', $agent), [
            'name' => 'محاولة',
            'username' => $agent->username,
            'email' => $agent->email,
            'agent_type' => 'individual',
            'discount_mode' => 'discount',
            'rate' => 5,
        ])->assertForbidden();
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('soft deletes an agent user', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);

        $this->delete(route('agents.destroy', $agent))
            ->assertRedirect(route('agents.index'));

        $this->assertSoftDeleted('users', ['id' => $agent->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents employee from creating agents', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->post(route('agents.store'), validAgentPayload())
            ->assertForbidden();
    });
});
