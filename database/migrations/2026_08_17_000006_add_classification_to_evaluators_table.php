<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('evaluators', function (Blueprint $table) {
            $table->string('classification', 20)->default('Internal')->after('sex');
        });
    }

    public function down(): void
    {
        Schema::table('evaluators', function (Blueprint $table) {
            $table->dropColumn('classification');
        });
    }
};
