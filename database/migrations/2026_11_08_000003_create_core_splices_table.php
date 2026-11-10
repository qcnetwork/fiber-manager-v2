<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A core of one cable spliced to a core of another, at a joint box/rosette, ODC, ODP or POP both cables reach.
     */
    public function up(): void
    {
        Schema::create('core_splices', function (Blueprint $table) {
            $table->id();
            $table->morphs('location');
            $table->foreignId('core_a_id')->constrained('cable_cores')->cascadeOnDelete();
            $table->foreignId('core_b_id')->constrained('cable_cores')->cascadeOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('core_b_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_splices');
    }
};
