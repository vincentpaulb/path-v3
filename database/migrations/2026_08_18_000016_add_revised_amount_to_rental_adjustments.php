<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_schedule_adjustments')) {
            Schema::table('rental_schedule_adjustments', function (Blueprint $table) {
                if (! Schema::hasColumn('rental_schedule_adjustments', 'previous_amount_due')) {
                    $table->decimal('previous_amount_due', 12, 2)->nullable()->after('outstanding_balance');
                }
                if (! Schema::hasColumn('rental_schedule_adjustments', 'revised_amount_due')) {
                    $table->decimal('revised_amount_due', 12, 2)->nullable()->after('previous_amount_due');
                }
                if (! Schema::hasColumn('rental_schedule_adjustments', 'previous_remaining_installments')) {
                    $table->unsignedSmallInteger('previous_remaining_installments')->nullable()->after('revised_amount_due');
                }
                if (! Schema::hasColumn('rental_schedule_adjustments', 'revised_remaining_installments')) {
                    $table->unsignedSmallInteger('revised_remaining_installments')->nullable()->after('previous_remaining_installments');
                }
                if (! Schema::hasColumn('rental_schedule_adjustments', 'revised_final_payment_month')) {
                    $table->date('revised_final_payment_month')->nullable()->after('revised_remaining_installments');
                }
            });
        }

        // A revised monthly amount may extend the remaining schedule beyond the
        // original 36 rows. Widen the installment number while preserving the
        // existing proposal/installment unique key.
        if (Schema::hasTable('rental_records') && Schema::hasColumn('rental_records', 'installment_no')) {
            Schema::table('rental_records', function (Blueprint $table) {
                $table->unsignedSmallInteger('installment_no')->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_schedule_adjustments')) {
            Schema::table('rental_schedule_adjustments', function (Blueprint $table) {
                $columns = [
                    'previous_amount_due',
                    'revised_amount_due',
                    'previous_remaining_installments',
                    'revised_remaining_installments',
                    'revised_final_payment_month',
                ];

                foreach ($columns as $column) {
                    if (Schema::hasColumn('rental_schedule_adjustments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('rental_records') && Schema::hasColumn('rental_records', 'installment_no')) {
            Schema::table('rental_records', function (Blueprint $table) {
                $table->unsignedTinyInteger('installment_no')->change();
            });
        }
    }
};
