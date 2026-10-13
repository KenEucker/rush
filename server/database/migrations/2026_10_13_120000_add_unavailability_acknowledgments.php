<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unavailability_changes', fn (Blueprint $table) => $table->string('reason', 500)->nullable());
        Schema::create('unavailability_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('unavailability_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('acknowledged_at');
            $table->unique(['unavailability_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unavailability_acknowledgments');
        Schema::table('unavailability_changes', fn (Blueprint $table) => $table->dropColumn('reason'));
    }
};
