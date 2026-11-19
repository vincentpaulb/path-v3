<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('proposal_acquisitions')) {
            return;
        }

        Schema::table('proposal_acquisitions', function (Blueprint $table) {
            if (! Schema::hasColumn('proposal_acquisitions', 'specifications_liquidated')) {
                $table->text('specifications_liquidated')->nullable()->after('actual_item_name');
            }
            if (! Schema::hasColumn('proposal_acquisitions', 'unit')) {
                $table->string('unit', 80)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('proposal_acquisitions', 'realigned_amount')) {
                $table->decimal('realigned_amount', 15, 2)->nullable()->after('unit_cost');
            }
            if (! Schema::hasColumn('proposal_acquisitions', 'date_acquired')) {
                $table->date('date_acquired')->nullable()->after('actual_cost');
            }
            if (! Schema::hasColumn('proposal_acquisitions', 'date_liquidated')) {
                $table->date('date_liquidated')->nullable()->after('date_acquired');
            }
        });

        // Preserve useful dates already encoded through the first implementation.
        DB::table('proposal_acquisitions')
            ->whereNull('date_acquired')
            ->update(['date_acquired' => DB::raw('COALESCE(delivery_date, purchase_date)')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('proposal_acquisitions')) {
            return;
        }

        Schema::table('proposal_acquisitions', function (Blueprint $table) {
            foreach (['date_liquidated', 'date_acquired', 'realigned_amount', 'unit', 'specifications_liquidated'] as $column) {
                if (Schema::hasColumn('proposal_acquisitions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
