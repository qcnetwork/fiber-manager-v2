<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per fiber core of a cable, plus how many cores each tube holds (for the color code).
     */
    public function up(): void
    {
        Schema::table('cables', function (Blueprint $table) {
            $table->unsignedSmallInteger('tube_size')->default(12)->after('cores');
        });

        Schema::create('cable_cores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cable_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('status', 20)->default('kosong');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['cable_id', 'number']);
        });

        // Existing cables: small cables are one tube; every cable gets its core rows.
        DB::table('cables')->where('cores', '<', 12)->update(['tube_size' => DB::raw('cores')]);
        $now = now();
        DB::table('cables')->orderBy('id')->select('id', 'cores')->each(function ($cable) use ($now) {
            DB::table('cable_cores')->insert(array_map(fn (int $n) => [
                'cable_id' => $cable->id, 'number' => $n, 'status' => 'kosong', 'created_at' => $now, 'updated_at' => $now,
            ], range(1, max(1, $cable->cores))));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cable_cores');
        Schema::table('cables', fn (Blueprint $table) => $table->dropColumn('tube_size'));
    }
};
