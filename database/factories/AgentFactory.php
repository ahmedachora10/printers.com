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

            AgentProfile::factory()->create(['user_id' => $agent->id]);
            // Refresh so the freshly created profile is available on the instance
            // (reading the relation before creating it would cache a null).
            $agent->load('agentProfile');

            Cache::forget('user_role_'.$agent->id);
        });
    }
}
