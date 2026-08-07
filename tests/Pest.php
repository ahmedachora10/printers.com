<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Set the terms an agent (مندوب) works on inside one branch.
 *
 * An agent may be linked to several branches on different terms, so the rate and
 * mode live on the `agent_branch` link — `agent_profiles` only holds the defaults
 * an operator sees pre-filled. Every calculator reads the link, so this is what a
 * test must set to control an invoice's agent discount or rebate.
 *
 * @param  array<string, mixed>  $terms  discount_mode / discount_type / rate
 */
function setAgentBranchTerms(User $agent, int $branchId, array $terms): void
{
    $agent->agentBranches()->syncWithoutDetaching([
        $branchId => [
            'discount_mode' => $terms['discount_mode'] ?? $agent->agentProfile->discount_mode->value,
            'discount_type' => $terms['discount_type'] ?? $agent->agentProfile->discount_type->value,
            'rate' => $terms['rate'] ?? $agent->agentProfile->rate,
        ],
    ]);

    $agent->unsetRelation('agentBranches');
}
