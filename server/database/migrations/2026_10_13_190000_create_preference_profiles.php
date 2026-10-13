<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preference_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('organization_membership_id');
            $table->string('profile_key', 48);
            $table->foreignUuid('season_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('week_starts_on')->nullable();
            $table->json('preferred_shift_ids')->nullable();
            $table->string('hours_mode', 16)->nullable();
            $table->unsignedInteger('desired_weekly_minutes')->nullable();
            $table->string('days_off', 16)->nullable();
            $table->unsignedInteger('revision');
            $table->timestampsTz();
            $table->foreign(['organization_membership_id', 'organization_id'])->references(['id', 'organization_id'])->on('organization_memberships')->restrictOnDelete();
            $table->unique(['organization_membership_id', 'profile_key']);
        });
        Schema::create('preference_profile_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('preference_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestampTz('occurred_at');
            $table->unique(['preference_profile_id', 'revision']);
        });
        foreach (['sync_preferences', 'sync_preference_catalogs'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->uuid('membership_id');
                $table->uuid('record_id');
                $table->json('value');
                $table->primary(['membership_id', 'record_id']);
                $table->foreign('membership_id')->references('membership_id')->on('sync_streams')->cascadeOnDelete();
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE preference_profiles ADD CHECK (revision > 0 AND ((season_id IS NULL AND week_starts_on IS NULL AND profile_key = 'default' AND preferred_shift_ids IS NOT NULL AND hours_mode IS NOT NULL AND days_off IS NOT NULL) OR (season_id IS NOT NULL AND week_starts_on IS NOT NULL AND profile_key = season_id::text || ':' || week_starts_on::text)))");
            DB::statement("ALTER TABLE preference_profiles ADD CHECK ((hours_mode IS NULL AND desired_weekly_minutes IS NULL) OR (hours_mode IN ('none', 'maximum') AND desired_weekly_minutes IS NULL) OR (hours_mode = 'target' AND desired_weekly_minutes IS NOT NULL AND desired_weekly_minutes BETWEEN 0 AND 10080))");
            DB::statement("ALTER TABLE preference_profiles ADD CHECK (days_off IS NULL OR days_off IN ('none', 'consecutive', 'separated'))");
        }
    }

    public function down(): void
    {
        DB::table('sync_changes')->whereIn('record_type', ['preference_profile', 'preference_catalog'])->delete();
        foreach (['sync_preference_catalogs', 'sync_preferences', 'preference_profile_changes', 'preference_profiles'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
