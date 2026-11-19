<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_budget_lines', function (Blueprint $table) {
            $table->foreignId('realignment_source_id')->nullable()
                ->constrained('proposal_budget_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_budget_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('realignment_source_id');
        });
    }
};
