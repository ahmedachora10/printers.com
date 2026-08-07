<?php

namespace Database\Factories;

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\AgentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Builds a user that holds the `agent` role together with its agent profile.
 *
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'phone' => fake()->unique()->numerify('05########'),
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Agent $agent) {
            $agent->addRole(Roles::AGENT->value);

            $profile = AgentProfile::factory()->create(['user_id' => $agent->id]);
            // Refresh so the freshly created profile is available on the instance
            // (reading the relation before creating it would cache a null).
            $agent->load('agentProfile');

            // Link the primary branch on the terms just generated. Availability
            // is decided by this pivot, so an agent without it would be invisible
            // in every branch — link it here the way the migration backfills it.
            if ($agent->branch_id) {
                $agent->agentBranches()->syncWithoutDetaching([
                    $agent->branch_id => [
                        'discount_mode' => $profile->discount_mode->value,
                        'discount_type' => $profile->discount_type->value,
                        'rate' => $profile->rate,
                    ],
                ]);
            }

            Cache::forget('user_role_'.$agent->id);
        });
    }

    /**
     * Link extra branches beyond the primary one, each on its own terms.
     *
     * @param  array<int, array<string, mixed>>  $terms  branchId => term overrides
     */
    public function inBranches(array $terms): static
    {
        return $this->afterCreating(function (Agent $agent) use ($terms) {
            foreach ($terms as $branchId => $override) {
                $agent->agentBranches()->syncWithoutDetaching([
                    $branchId => [
                        'discount_mode' => $override['discount_mode'] ?? $agent->agentProfile->discount_mode->value,
                        'discount_type' => $override['discount_type'] ?? $agent->agentProfile->discount_type->value,
                        'rate' => $override['rate'] ?? $agent->agentProfile->rate,
                    ],
                ]);
            }
        });
    }
}
