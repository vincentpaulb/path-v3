<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('proposals', fn (Blueprint $table) => $table->string('funded_project_status', 40)->nullable());
        DB::table('proposals')->whereNotNull('date_funded')->update([
            'funded_project_status' => DB::raw("CASE WHEN status IN ('New','Ongoing','Stopped Payment','Completed','Graduated','Terminated','Withdrawn') THEN status ELSE 'New' END"),
        ]);
        DB::table('proposals')->whereNotNull('date_funded')->update(['status' => 'Approved']);
        DB::table('proposals')->whereNull('date_funded')->whereNotIn('status', ['Approved', 'Disapproved', 'Ongoing Evaluation'])->update(['status' => 'Ongoing Evaluation']);
    }

    public function down(): void
    {
        DB::table('proposals')->whereNotNull('date_funded')->update(['status' => DB::raw('funded_project_status')]);
        Schema::table('proposals', fn (Blueprint $table) => $table->dropColumn('funded_project_status'));
    }
};
