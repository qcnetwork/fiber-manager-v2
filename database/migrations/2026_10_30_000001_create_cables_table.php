<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->nullable()->unique();
            $table->string('type', 20);
            $table->unsignedSmallInteger('cores')->default(12);
            $table->string('status', 20)->default('aktif');
            $table->nullableMorphs('from');
            $table->nullableMorphs('to');
            // Vertices as [[lat, lng], ...]; the bounding box lets the map query cables that cross the viewport.
            $table->json('path');
            $table->decimal('min_lat', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('min_lng', 10, 7);
            $table->decimal('max_lng', 10, 7);
            $table->unsignedInteger('length_m')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['min_lat', 'max_lat', 'min_lng', 'max_lng']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cables');
    }
};
