<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('liquidation_records')) {
            return;
        }

        Schema::create('liquidation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('amount_released', 15, 2)->nullable();
            $table->decimal('amount_liquidated', 15, 2)->default(0);
            $table->decimal('reported_unexpended_balance', 15, 2)->default(0);
            $table->decimal('refunded_unexpended_amount', 15, 2)->default(0);
            $table->boolean('proof_of_refund_received')->default(false);
            $table->string('liquidation_report_status', 30)->default('No Report');
            $table->boolean('attachments_complete')->default(false);
            $table->boolean('status_report_submitted')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('liquidation_report_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidation_records');
    }
};
