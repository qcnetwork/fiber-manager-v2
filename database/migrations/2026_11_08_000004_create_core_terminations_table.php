<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A core ending at an ODP: on a splitter input, or straight on an output port (and so on that port's customer).
     */
    public function up(): void
    {
        Schema::create('core_terminations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('core_id')->constrained('cable_cores')->cascadeOnDelete();
            $table->foreignId('odp_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // input | output
            $table->unsignedSmallInteger('port'); // splitter input number, or output port number
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['odp_id', 'kind', 'port']);
            $table->index('core_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_terminations');
    }
};
