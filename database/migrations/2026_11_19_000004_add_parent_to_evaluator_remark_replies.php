<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluator_remark_replies', function (Blueprint $table) {
            $table->foreignId('parent_reply_id')->nullable()->constrained('evaluator_remark_replies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evaluator_remark_replies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_reply_id');
        });
    }
};
