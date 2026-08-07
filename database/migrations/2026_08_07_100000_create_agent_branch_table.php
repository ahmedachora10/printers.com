<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links an agent (مندوب) to several branches at once. Until now an agent lived
 * in exactly one branch via users.branch_id, so the same person dealing with two
 * branches had to be created twice under two accounts — splitting their dues and
 * their reports.
 *
 * Each pivot row carries the terms negotiated with that branch, so the rate and
 * the discount mode may differ per branch. users.branch_id stays as the agent's
 * primary branch (every reader of it keeps working); this table is what decides
 * where the agent is actually available and on what terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('agent_branch', function (Blueprint $table) use ($sqlite) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            // Terms per branch. Mirrors agent_profiles column-for-column so a
            // value valid in one is valid in the other.
            $table->string('discount_mode', 20);
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('rate', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'branch_id']);
            $table->index('branch_id');

            if (! $sqlite) {
                $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            }
        });

        // Backfill: every existing agent keeps exactly its current behaviour by
        // getting one row for its primary branch with its current profile terms.
        $agentRoleId = DB::table('roles')->where('name', 'agent')->value('id');

        if ($agentRoleId === null) {
            return;
        }

        DB::table('agent_profiles')
            ->join('users', 'users.id', '=', 'agent_profiles.user_id')
            ->join('role_user', function ($join) use ($agentRoleId) {
                $join->on('role_user.user_id', '=', 'users.id')
                    ->where('role_user.role_id', '=', $agentRoleId);
            })
            ->whereNotNull('users.branch_id')
            ->orderBy('agent_profiles.id')
            ->select([
                'agent_profiles.id',
                'agent_profiles.user_id',
                'agent_profiles.discount_mode',
                'agent_profiles.discount_type',
                'agent_profiles.rate',
            ])
            ->addSelect('users.branch_id')
            ->chunkById(500, function ($rows) {
                DB::table('agent_branch')->insert($rows->map(fn ($row) => [
                    'agent_id' => $row->user_id,
                    'branch_id' => $row->branch_id,
                    'discount_mode' => $row->discount_mode ?? 'discount',
                    'discount_type' => $row->discount_type ?? 'percentage',
                    'rate' => $row->rate ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            }, 'agent_profiles.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_branch');
    }
};
