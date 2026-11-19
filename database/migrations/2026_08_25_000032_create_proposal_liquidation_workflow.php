<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('proposal_budget_lines')) {
            Schema::create('proposal_budget_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->string('budget_code', 80)->nullable();
                $table->string('category', 120)->default('Equipment / Technology');
                $table->string('item_name', 500);
                $table->text('description')->nullable();
                $table->decimal('quantity', 12, 2)->default(1);
                $table->string('unit', 80)->nullable();
                $table->decimal('approved_unit_cost', 15, 2)->default(0);
                $table->decimal('approved_amount', 15, 2)->default(0);
                $table->string('funding_source', 180)->nullable();
                $table->string('fund_account', 180)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['proposal_id', 'sort_order']);
                $table->index(['proposal_id', 'category']);
            });
        }

        if (! Schema::hasTable('proposal_acquisitions')) {
            Schema::create('proposal_acquisitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('budget_line_id')->nullable()->constrained('proposal_budget_lines')->nullOnDelete();
                $table->string('supplier_name', 300)->nullable();
                $table->string('actual_item_name', 500);
                $table->string('brand', 180)->nullable();
                $table->string('model', 180)->nullable();
                $table->string('serial_number', 180)->nullable();
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('actual_cost', 15, 2)->default(0);
                $table->date('purchase_date')->nullable();
                $table->date('delivery_date')->nullable();
                $table->date('inspection_date')->nullable();
                $table->date('installation_date')->nullable();
                $table->string('po_number', 180)->nullable();
                $table->string('invoice_number', 180)->nullable();
                $table->string('property_number', 180)->nullable();
                $table->string('location', 500)->nullable();
                $table->string('status', 80)->default('Planned');
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['proposal_id', 'status']);
            });
        }

        if (! Schema::hasTable('liquidation_transactions')) {
            Schema::create('liquidation_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('budget_line_id')->nullable()->constrained('proposal_budget_lines')->nullOnDelete();
                $table->foreignId('acquisition_id')->nullable()->constrained('proposal_acquisitions')->nullOnDelete();
                $table->date('transaction_date');
                $table->string('entry_number', 120)->nullable();
                $table->string('reference_type', 80)->nullable();
                $table->string('reference_number', 180)->nullable();
                $table->string('payee', 300)->nullable();
                $table->text('particulars');
                $table->string('transaction_type', 80);
                $table->decimal('obligation_amount', 15, 2)->default(0);
                $table->decimal('disbursement_amount', 15, 2)->default(0);
                $table->decimal('adjustment_amount', 15, 2)->default(0);
                $table->decimal('refund_amount', 15, 2)->default(0);
                $table->string('funding_source', 180)->nullable();
                $table->string('fund_account', 180)->nullable();
                $table->string('status', 50)->default('Posted');
                $table->text('remarks')->nullable();
                $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['proposal_id', 'transaction_date']);
                $table->index(['proposal_id', 'transaction_type']);
            });
        }

        if (! Schema::hasTable('liquidation_documents')) {
            Schema::create('liquidation_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('acquisition_id')->nullable()->constrained('proposal_acquisitions')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('liquidation_transactions')->nullOnDelete();
                $table->string('document_type', 160);
                $table->string('name', 500);
                $table->string('path', 1000);
                $table->string('mime_type', 180)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['proposal_id', 'document_type']);
            });
        }

        if (! Schema::hasTable('liquidation_findings')) {
            Schema::create('liquidation_findings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('acquisition_id')->nullable()->constrained('proposal_acquisitions')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('liquidation_transactions')->nullOnDelete();
                $table->text('finding');
                $table->string('status', 50)->default('Open');
                $table->text('resolution_notes')->nullable();
                $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['proposal_id', 'status']);
            });
        }

        if (Schema::hasTable('liquidation_records')) {
            Schema::table('liquidation_records', function (Blueprint $table) {
                if (! Schema::hasColumn('liquidation_records', 'workflow_status')) {
                    $table->string('workflow_status', 50)->default('Draft')->after('liquidation_report_status');
                }
                if (! Schema::hasColumn('liquidation_records', 'submitted_at')) {
                    $table->timestamp('submitted_at')->nullable()->after('workflow_status');
                }
                if (! Schema::hasColumn('liquidation_records', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
                }
                if (! Schema::hasColumn('liquidation_records', 'reviewed_by')) {
                    $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('liquidation_records')) {
            Schema::table('liquidation_records', function (Blueprint $table) {
                if (Schema::hasColumn('liquidation_records', 'reviewed_by')) {
                    try { $table->dropForeign(['reviewed_by']); } catch (\Throwable) {}
                    $table->dropColumn('reviewed_by');
                }
                foreach (['reviewed_at', 'submitted_at', 'workflow_status'] as $column) {
                    if (Schema::hasColumn('liquidation_records', $column)) $table->dropColumn($column);
                }
            });
        }

        Schema::dropIfExists('liquidation_findings');
        Schema::dropIfExists('liquidation_documents');
        Schema::dropIfExists('liquidation_transactions');
        Schema::dropIfExists('proposal_acquisitions');
        Schema::dropIfExists('proposal_budget_lines');
    }
};
