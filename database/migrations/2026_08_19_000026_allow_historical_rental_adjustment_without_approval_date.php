<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_schedule_adjustments')
            && Schema::hasColumn('rental_schedule_adjustments', 'approved_date')) {
            Schema::table('rental_schedule_adjustments', function (Blueprint $table) {
                // Historical spreadsheets sometimes preserve the approved
                // schedule change but not the exact approval date. Manual PATH
                // adjustments still require a date; bulk historical imports may
                // intentionally leave it unknown instead of inventing one.
                $table->date('approved_date')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_schedule_adjustments')
            && Schema::hasColumn('rental_schedule_adjustments', 'approved_date')) {
            // Preserve reversibility without failing when historical imports
            // contain a null approval date.
            \DB::table('rental_schedule_adjustments')
                ->whereNull('approved_date')
                ->update(['approved_date' => \DB::raw('effective_month')]);

            Schema::table('rental_schedule_adjustments', function (Blueprint $table) {
                $table->date('approved_date')->nullable(false)->change();
            });
        }
    }
};
