<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('organization_membership_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->foreign(['organization_membership_id', 'organization_id'])->references(['id', 'organization_id'])->on('organization_memberships')->restrictOnDelete();
            $table->index(['organization_id', 'organization_membership_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE official_assignments ADD CONSTRAINT official_assignment_interval CHECK (ends_at > starts_at AND revision > 0)');
        }
        Schema::create('official_assignment_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('official_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('reason', 500);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestampTz('occurred_at');
            $table->unique(['official_assignment_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_assignment_changes');
        Schema::dropIfExists('official_assignments');
    }
};
