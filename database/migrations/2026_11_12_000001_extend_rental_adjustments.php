<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_schedule_adjustments', function (Blueprint $table) {
            $table->string('mode', 20)->default('pause');
            $table->decimal('unexpended_amount', 12, 2)->default(0);
            $table->string('repayment_option', 20)->nullable();
        });
        Schema::table('proposals', function (Blueprint $table) {
            $table->boolean('rental_schedule_customized')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('rental_schedule_adjustments', fn (Blueprint $table) => $table->dropColumn(['mode', 'unexpended_amount', 'repayment_option']));
        Schema::table('proposals', fn (Blueprint $table) => $table->dropColumn('rental_schedule_customized'));
    }
};
