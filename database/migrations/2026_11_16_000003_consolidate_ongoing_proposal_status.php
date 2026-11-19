<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('proposals')->where('status', 'Ongoing Evaluation')->update(['status' => 'Ongoing']);
    }

    public function down(): void
    {
        // The merged labels cannot be separated reliably; retain the normalized status.
    }
};
