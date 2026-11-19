<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('location_source', 20)->nullable()->after('longitude');
            $table->timestamp('geocoded_at')->nullable()->after('location_source');
            $table->index(['latitude', 'longitude'], 'proposals_location_index');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropIndex('proposals_location_index');
            $table->dropColumn(['latitude', 'longitude', 'location_source', 'geocoded_at']);
        });
    }
};
