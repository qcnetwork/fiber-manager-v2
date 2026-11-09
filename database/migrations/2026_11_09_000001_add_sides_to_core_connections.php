<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which way along its cable a spliced/terminated core continues from the location: towards the cable's start
 * ("from") or its end ("to"). At a cable end only one way exists; where the cable passes through, a core can be
 * cut and each way used for something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_splices', function (Blueprint $table) {
            $table->string('core_a_side', 4)->default('to')->after('core_a_id');
            $table->string('core_b_side', 4)->default('to')->after('core_b_id');
        });
        Schema::table('core_terminations', function (Blueprint $table) {
            $table->string('side', 4)->default('to')->after('core_id');
        });

        // Existing connections: at the cable's end the core can only go back towards its start.
        $side = function (int $coreId, string $type, int $id): string {
            $cable = DB::table('cables')->where('id', DB::table('cable_cores')->where('id', $coreId)->value('cable_id'))->first();

            return $cable && $cable->to_type === $type && (int) $cable->to_id === $id
                && ! ($cable->from_type === $type && (int) $cable->from_id === $id) ? 'from' : 'to';
        };

        foreach (DB::table('core_splices')->get() as $s) {
            DB::table('core_splices')->where('id', $s->id)->update([
                'core_a_side' => $side($s->core_a_id, $s->location_type, $s->location_id),
                'core_b_side' => $side($s->core_b_id, $s->location_type, $s->location_id),
            ]);
        }
        foreach (DB::table('core_terminations')->get() as $t) {
            DB::table('core_terminations')->where('id', $t->id)->update(['side' => $side($t->core_id, $t->location_type, $t->location_id)]);
        }
    }

    public function down(): void
    {
        Schema::table('core_splices', fn (Blueprint $table) => $table->dropColumn(['core_a_side', 'core_b_side']));
        Schema::table('core_terminations', fn (Blueprint $table) => $table->dropColumn('side'));
    }
};
