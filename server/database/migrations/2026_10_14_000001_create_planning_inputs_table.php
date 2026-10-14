<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('season_id');
            $table->foreign(['season_id', 'organization_id'])->references(['id', 'organization_id'])->on('seasons')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->char('fingerprint', 64);
            $table->json('inputs');
            $table->timestampTz('created_at');
            $table->index(['season_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_inputs');
    }
};
