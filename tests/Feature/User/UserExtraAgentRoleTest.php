<?php

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A staff member may also be an agent (مندوب): the staff role stays the main one. */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);
    $this->branch = Branch::factory()->create();
    $this->actingAs($this->superAdmin);
});

function staffPayload(User $user, array $overrides = []): array
{
    return [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'role' => Roles::EMPLOYEE->value,
        'branch_id' => $user->branch_id,
        'is_active' => true,
        ...$overrides,
    ];
}

it('creates an employee who is also an agent, keeping employee as the main role', function () {
    $this->post(route('users.store'), [
        'name' => 'أحمد', 'username' => 'ahmed', 'email' => 'ahmed@example.com',
        'password' => 'Password1!', 'password_confirmation' => 'Password1!',
        'role' => Roles::EMPLOYEE->value, 'is_agent' => true, 'branch_id' => $this->branch->id,
    ])->assertSessionHasNoErrors();

    $user = User::where('username', 'ahmed')->first();

    expect($user->hasRole(Roles::EMPLOYEE->value))->toBeTrue()
        ->and($user->isAgent())->toBeTrue()
        ->and($user->roleName)->toBe(Roles::EMPLOYEE)
        ->and(Agent::whereKey($user->id)->exists())->toBeTrue()
        // Still a staff member who raises invoices.
        ->and(User::invoiceRaiserOptions($this->branch->id)->pluck('id'))->toContain($user->id);
});

it('adds and removes the agent role on update; the status toggle keeps it', function () {
    $user = User::factory()->create(['branch_id' => $this->branch->id]);
    $user->addRole(Roles::EMPLOYEE->value);

    $this->put(route('users.update', $user), staffPayload($user, ['is_agent' => true]))->assertSessionHasNoErrors();
    expect($user->fresh()->isAgent())->toBeTrue();

    $this->patch(route('users.toggle-status', $user));
    expect($user->fresh()->isAgent())->toBeTrue();

    $this->put(route('users.update', $user), staffPayload($user, ['is_agent' => false]))->assertSessionHasNoErrors();
    expect($user->fresh()->isAgent())->toBeFalse()
        ->and($user->fresh()->roleName)->toBe(Roles::EMPLOYEE);
});

it('deleting the agent keeps the employee account and drops only the agent role', function () {
    $user = User::factory()->create(['branch_id' => $this->branch->id]);
    $user->addRoles([Roles::AGENT->value, Roles::EMPLOYEE->value]);

    $this->delete(route('agents.destroy', $user->id));

    $user = $user->fresh();
    expect($user)->not->toBeNull()
        ->and($user->trashed())->toBeFalse()
        ->and($user->isAgent())->toBeFalse()
        ->and($user->roleName)->toBe(Roles::EMPLOYEE);
});

it('shows the agent portal in the sidebar but logs the dual user into staff screens', function () {
    $user = User::factory()->create(['branch_id' => $this->branch->id, 'password' => 'Password1!']);
    $user->addRoles([Roles::AGENT->value, Roles::EMPLOYEE->value]);
    auth()->logout();

    $this->post(route('login'), ['username' => $user->username, 'password' => 'Password1!'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('agent-portal.index'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.role', Roles::EMPLOYEE->value)
            ->where('auth.sidebarItems', fn ($groups) => collect($groups)->flatMap(fn ($g) => $g['items'])
                ->pluck('url')->contains(route('agent-portal.index'))));
});

it('never lets the invoice owner be its own agent', function () {
    $ahmed = User::factory()->create(['branch_id' => $this->branch->id]);
    $ahmed->addRoles([Roles::EMPLOYEE->value, Roles::AGENT->value]);
    $ahmed->agentBranches()->attach($this->branch->id, ['discount_mode' => 'discount']);

    $other = User::factory()->create(['branch_id' => $this->branch->id]);
    $other->addRole(Roles::AGENT->value);
    $other->agentBranches()->attach($this->branch->id, ['discount_mode' => 'discount']);

    $this->actingAs($ahmed);

    // Hidden from his own POS agent list…
    $this->get(route('pos.service.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('agents', fn ($agents) => ($ids = collect($agents)->pluck('id'))->contains($other->id)
            && ! $ids->contains($ahmed->id)));

    // …and refused if posted anyway, as invoice agent or as a line's commission owner.
    $this->post(route('pos.service.store'), [
        'status' => 'due',
        'agent_ids' => [$ahmed->id],
        'lines' => [['branch_service_id' => 1, 'qty' => 1, 'unit_price' => 10, 'agent_id' => $ahmed->id]],
    ])->assertSessionHasErrors(['agent_ids.0', 'lines.0.agent_id']);

    // Another user's invoice may still name Ahmed as its agent.
    $this->actingAs($other)->post(route('pos.service.store'), ['agent_ids' => [$ahmed->id]])
        ->assertSessionDoesntHaveErrors(['agent_ids.0']);
});
