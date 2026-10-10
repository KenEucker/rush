<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unavailabilities', function (Blueprint $table) {
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
            DB::statement('ALTER TABLE unavailabilities ADD CONSTRAINT unavailability_interval CHECK (ends_at > starts_at AND revision > 0)');
        }
        Schema::create('unavailability_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unavailability_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestampTz('occurred_at');
            $table->unique(['unavailability_id', 'revision']);
        });
        Schema::create('sync_unavailabilities', function (Blueprint $table) {
            $table->uuid('membership_id');
            $table->uuid('record_id');
            $table->json('value');
            $table->primary(['membership_id', 'record_id']);
            $table->foreign('membership_id')->references('membership_id')->on('sync_streams')->cascadeOnDelete();
        });
        Schema::table('sync_changes', fn (Blueprint $table) => $table->string('record_type')->default('member_profile'));
    }

    public function down(): void
    {
        DB::table('sync_changes')->where('record_type', 'unavailability')->delete();
        Schema::table('sync_changes', fn (Blueprint $table) => $table->dropColumn('record_type'));
        Schema::dropIfExists('sync_unavailabilities');
        Schema::dropIfExists('unavailability_changes');
        Schema::dropIfExists('unavailabilities');
    }
};
