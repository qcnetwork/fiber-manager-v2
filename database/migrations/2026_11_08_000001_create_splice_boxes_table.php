<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Joint boxes and rosettes: points on the route where cables are spliced together.
     */
    public function up(): void
    {
        Schema::create('splice_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('joint_box');
            $table->string('code', 100)->unique();
            $table->string('name')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address')->nullable();
            $table->string('pole_number', 50)->nullable();
            // How many splices (cores) the trays hold; optional.
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 20)->default('aktif');
            $table->date('installed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['lat', 'lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('splice_boxes');
    }
};
