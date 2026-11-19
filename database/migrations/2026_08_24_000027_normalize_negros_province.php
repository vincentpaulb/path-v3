<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['proposals', 'users'] as $table) {
            DB::table($table)
                ->whereRaw('LOWER(TRIM(province)) = ?', ['negros oriental'])
                ->update(['province' => 'Negros']);
        }
    }

    public function down(): void
    {
        // Canonicalizing a province alias is intentionally not reversed because
        // both labels represent the same province and the original distinction
        // cannot be reconstructed reliably.
    }
};
