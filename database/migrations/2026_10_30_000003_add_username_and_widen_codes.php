<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts from the previous app sign in with a username and may have no email;
     * some of its ODP names are longer than 50 characters.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
            $table->string('email')->nullable()->change();
        });

        foreach (['odcs', 'odps', 'cables'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('code', 100)->nullable($name === 'cables')->change());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['odcs', 'odps', 'cables'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('code', 50)->nullable($name === 'cables')->change());
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
