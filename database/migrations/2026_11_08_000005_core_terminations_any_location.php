<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cores can now end on an ODC as well as an ODP: the termination points at its location by morph alias
     * instead of an odp_id. Existing rows become location "odp".
     */
    public function up(): void
    {
        Schema::table('core_terminations', function (Blueprint $table) {
            $table->string('location_type', 20)->default('odp')->after('core_id');
            $table->unsignedBigInteger('location_id')->nullable()->after('location_type');
        });

        DB::table('core_terminations')->update(['location_id' => DB::raw('odp_id')]);

        Schema::table('core_terminations', function (Blueprint $table) {
            $table->dropForeign(['odp_id']);
            $table->dropUnique(['odp_id', 'kind', 'port']);
            $table->dropColumn('odp_id');
        });

        Schema::table('core_terminations', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->string('location_type', 20)->default(null)->change();
            $table->unique(['location_type', 'location_id', 'kind', 'port']);
        });
    }

    public function down(): void
    {
        DB::table('core_terminations')->where('location_type', '!=', 'odp')->delete();

        Schema::table('core_terminations', function (Blueprint $table) {
            $table->dropUnique(['location_type', 'location_id', 'kind', 'port']);
            $table->foreignId('odp_id')->nullable()->after('core_id');
        });
        DB::table('core_terminations')->update(['odp_id' => DB::raw('location_id')]);
        Schema::table('core_terminations', function (Blueprint $table) {
            $table->dropColumn(['location_type', 'location_id']);
            $table->foreign('odp_id')->references('id')->on('odps')->cascadeOnDelete();
            $table->unique(['odp_id', 'kind', 'port']);
        });
    }
};
