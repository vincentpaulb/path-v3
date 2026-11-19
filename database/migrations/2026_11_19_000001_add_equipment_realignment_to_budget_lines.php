<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_budget_lines', function (Blueprint $table) {
            $table->decimal('realigned_amount', 15, 2)->nullable();
            $table->boolean('added_by_realignment')->default(false);
            $table->boolean('removed_by_realignment')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('proposal_budget_lines', function (Blueprint $table) {
            $table->dropColumn(['realigned_amount', 'added_by_realignment', 'removed_by_realignment']);
        });
    }
};
