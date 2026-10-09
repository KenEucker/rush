<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
            $table->unique(['id', 'organization_id']);
        });

        Schema::create('member_profile_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('member_profile_id');
            $table->uuid('organization_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('before');
            $table->json('after');
            $table->timestampTz('occurred_at');
            $table->foreign(['member_profile_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('member_profiles')->restrictOnDelete();
            $table->unique(['member_profile_id', 'revision']);
            $table->index(['organization_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_profile_changes');
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropUnique(['id', 'organization_id']);
            $table->dropColumn('revision');
        });
    }
};
