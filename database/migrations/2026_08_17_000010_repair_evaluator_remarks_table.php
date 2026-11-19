<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Recovery migration for installations where the evaluator feature files
        // were copied before the evaluator_remarks migration was actually run.
        if (! Schema::hasTable('evaluator_remarks')) {
            Schema::create('evaluator_remarks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('evaluator_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('action', 20);
                $table->text('remark');
                $table->timestamps();

                $table->index(['proposal_id', 'evaluator_id', 'created_at'], 'evaluator_remarks_proposal_evaluator_created_index');
            });
        }
    }

    public function down(): void
    {
        // Intentionally left non-destructive. Migration 000009 owns this table.
    }
};
