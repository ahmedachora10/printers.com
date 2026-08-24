<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Every page pays for the shared Inertia layout, so anything the layout already
 * fetched must not be fetched a second time by the page itself. The service POS
 * used to load its branch twice — once for the header, once for VAT and payment
 * methods — and to re-query `role_user` on each role check.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);
    $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->employee->addRole(Roles::EMPLOYEE->value);
});

/**
 * Queries run by one GET, counted per SQL statement. The request is issued
 * twice and only the second is measured: the first warms the role caches, as
 * the first hit after a deploy would, and re-resolving the user in between
 * stops the per-request branch memo leaking across the two.
 *
 * @return array<string, int>
 */
function posCreateQueries(User $employee): array
{
    test()->actingAs($employee)->get(route('pos.service.create'))->assertOk();

    Auth::forgetGuards();
    test()->actingAs(User::findOrFail($employee->id));

    $tally = [];
    DB::listen(function (QueryExecuted $query) use (&$tally) {
        $tally[$query->sql] = ($tally[$query->sql] ?? 0) + 1;
    });

    test()->get(route('pos.service.create'))->assertOk();

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $tally;
}

it('loads the employee branch once, not once per consumer', function () {
    $tally = posCreateQueries($this->employee);

    $branchRow = collect($tally)
        ->filter(fn ($count, $sql) => str_contains($sql, 'from "branches" where "branches"."id" ='))
        ->sum();

    expect($branchRow)->toBe(1);
});

it('answers role checks from the cache instead of re-reading role_user', function () {
    $tally = posCreateQueries($this->employee);

    // The agent lookup legitimately joins role_user (agents are users holding
    // the agent role); a role *check* must add nothing on top of it.
    $roleChecks = collect($tally)
        ->filter(fn ($count, $sql) => str_contains($sql, '"role_user"') && ! str_contains($sql, 'agent_branch'))
        ->sum();

    expect($roleChecks)->toBe(0);
});

it('runs no duplicate query at all on the service POS page', function () {
    $duplicates = collect(posCreateQueries($this->employee))->filter(fn ($count) => $count > 1);

    expect($duplicates)->toBeEmpty($duplicates->keys()->implode("\n"));
});
