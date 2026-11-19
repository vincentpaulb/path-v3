<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_schedule_adjustments')) {
            Schema::create('rental_schedule_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->string('adjustment_type', 40);
                $table->date('effective_month')->index();
                $table->date('resume_month')->index();
                $table->unsignedSmallInteger('suspension_months');
                $table->date('approved_date');
                $table->decimal('outstanding_balance', 12, 2)->default(0);
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['proposal_id', 'effective_month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_schedule_adjustments');
    }
};
