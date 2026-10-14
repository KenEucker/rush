<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_inputs', fn (Blueprint $table) => $table->unique(['id', 'organization_id']));
        Schema::create('schedule_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('planning_input_id');
            $table->foreign(['planning_input_id', 'organization_id'])->references(['id', 'organization_id'])->on('planning_inputs')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('state')->default('draft');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['id', 'organization_id']);
        });
        Schema::create('draft_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('draft_id');
            $table->uuid('membership_id');
            $table->foreign(['draft_id', 'organization_id'])->references(['id', 'organization_id'])->on('schedule_drafts')->restrictOnDelete();
            $table->foreign(['membership_id', 'organization_id'])->references(['id', 'organization_id'])->on('organization_memberships')->restrictOnDelete();
            $table->string('opportunity_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->json('context');
            $table->string('override_reason', 500)->nullable();
            $table->json('evaluation');
            $table->unique(['draft_id', 'membership_id', 'opportunity_id']);
        });
        Schema::create('draft_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('draft_id')->constrained('schedule_drafts')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('reason', 500);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestampTz('occurred_at');
            $table->unique(['draft_id', 'revision']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE draft_assignments ADD CONSTRAINT draft_interval CHECK (ends_at > starts_at)');
            DB::statement("ALTER TABLE schedule_drafts ADD CONSTRAINT draft_state CHECK (state = 'draft' AND revision > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_changes');
        Schema::dropIfExists('draft_assignments');
        Schema::dropIfExists('schedule_drafts');
        Schema::table('planning_inputs', fn (Blueprint $table) => $table->dropUnique(['id', 'organization_id']));
    }
};
