<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('proposals', fn (Blueprint $table) => $table->string('project_code', 100)->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('proposals', fn (Blueprint $table) => $table->dropColumn('project_code'));
    }
};
