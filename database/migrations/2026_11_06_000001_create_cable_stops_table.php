<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * POPs/ODCs/ODPs a cable passes through between its two ends, each pinned to one vertex of the path.
     */
    public function up(): void
    {
        Schema::create('cable_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cable_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('vertex'); // index into cables.path
            $table->morphs('asset');
            $table->timestamps();

            $table->unique(['cable_id', 'vertex']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cable_stops');
    }
};
