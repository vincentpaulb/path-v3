<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('evaluator_proposal', function (Blueprint $table) {
            $table->string('evaluation_status', 50)->default('Assigned')->after('proposal_id');
        });
    }

    public function down(): void
    {
        Schema::table('evaluator_proposal', function (Blueprint $table) {
            $table->dropColumn('evaluation_status');
        });
    }
};
