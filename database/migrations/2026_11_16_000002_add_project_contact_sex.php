<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('proposals', fn (Blueprint $table) => $table->string('owner_contact_sex', 30)->nullable()); }
    public function down(): void { Schema::table('proposals', fn (Blueprint $table) => $table->dropColumn('owner_contact_sex')); }
};
