<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->index('date_funded', 'proposals_date_funded_index');
            $table->index('created_at', 'proposals_created_at_index');
        });

        Schema::table('rental_records', function (Blueprint $table) {
            $table->index('or_date', 'rental_records_or_date_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('created_at', 'activity_logs_created_at_index');
        });

        Schema::table('missing_documents', function (Blueprint $table) {
            $table->index('created_at', 'missing_documents_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('missing_documents', function (Blueprint $table) {
            $table->dropIndex('missing_documents_created_at_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_created_at_index');
        });

        Schema::table('rental_records', function (Blueprint $table) {
            $table->dropIndex('rental_records_or_date_index');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropIndex('proposals_date_funded_index');
            $table->dropIndex('proposals_created_at_index');
        });
    }
};
