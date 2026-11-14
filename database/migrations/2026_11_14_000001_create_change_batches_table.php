<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Technicians collect changes as drafts and send them together as one batch; approval is per batch.
 * Requests sent before batches existed each become a batch of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('note', 1000)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->index(['area_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('change_requests', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('id')->constrained('change_batches')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0)->after('batch_id');
        });

        foreach (DB::table('change_requests')->orderBy('id')->get() as $request) {
            $batchId = DB::table('change_batches')->insertGetId([
                'user_id' => $request->user_id,
                'area_id' => $request->area_id,
                'status' => $request->status,
                'submitted_at' => $request->created_at,
                'reviewer_id' => $request->reviewer_id,
                'reviewed_at' => $request->reviewed_at,
                'review_note' => $request->review_note,
                'seen_at' => $request->seen_at,
                'created_at' => $request->created_at,
                'updated_at' => $request->updated_at,
            ]);
            DB::table('change_requests')->where('id', $request->id)->update(['batch_id' => $batchId, 'sequence' => 1]);
        }

        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropColumn(['reviewed_at', 'review_note', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamp('seen_at')->nullable();
        });

        foreach (DB::table('change_batches')->get() as $batch) {
            DB::table('change_requests')->where('batch_id', $batch->id)->update([
                'reviewer_id' => $batch->reviewer_id,
                'reviewed_at' => $batch->reviewed_at,
                'review_note' => $batch->review_note,
                'seen_at' => $batch->seen_at,
            ]);
        }
        DB::table('change_requests')->where('status', 'draft')->delete();

        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn('sequence');
        });
        Schema::dropIfExists('change_batches');
    }
};
