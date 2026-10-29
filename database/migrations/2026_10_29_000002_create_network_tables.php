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
        Schema::create('olts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->unsignedSmallInteger('pon_ports')->default(16);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('odcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('olt_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('olt_pon_port')->nullable();
            $table->string('code', 50)->unique();
            $table->string('name')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address')->nullable();
            $table->unsignedSmallInteger('capacity')->default(48);
            $table->string('splitter_ratio', 10)->nullable();
            $table->string('status', 20)->default('aktif');
            $table->date('installed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['lat', 'lng']);
        });

        Schema::create('odps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('odc_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('odc_port')->nullable();
            $table->string('code', 50)->unique();
            $table->string('name')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address')->nullable();
            $table->string('pole_number', 50)->nullable();
            $table->unsignedTinyInteger('capacity')->default(8);
            $table->string('splitter_ratio', 10)->nullable();
            $table->string('status', 20)->default('aktif');
            $table->date('installed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['lat', 'lng']);
            $table->unique(['odc_id', 'odc_port']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('customer_code', 50)->nullable()->unique();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('package')->nullable();
            $table->string('ont_sn', 50)->nullable();
            $table->string('status', 20)->default('aktif');
            $table->timestamps();
        });

        Schema::create('odp_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('odp_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->string('status', 20)->default('kosong');
            $table->foreignId('customer_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['odp_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('odp_ports');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('odps');
        Schema::dropIfExists('odcs');
        Schema::dropIfExists('olts');
    }
};
