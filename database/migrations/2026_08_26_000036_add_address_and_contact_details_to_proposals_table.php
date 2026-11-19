<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->text('address')->nullable()->after('proponent');
            $table->string('contact_person', 255)->nullable()->after('address');
            $table->string('contact_phone', 100)->nullable()->after('contact_person');
            $table->string('contact_email', 255)->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['address', 'contact_person', 'contact_phone', 'contact_email']);
        });
    }
};
